<?php
/**
 * PEPP Learning ERP - Bulk Communication Campaigns Page.
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('communication');

$active_page = 'communication';
$page_title  = 'Bulk Communication Campaigns';
$page_sub    = 'Broadcast WhatsApp alerts and emails to segmented student lists';

$success_message = '';
$error_message   = '';

// Self-healing database structure initialization
try {
    $has_table = (bool)$pdo->query("SHOW TABLES LIKE 'communication_queue'")->fetchColumn();
    if (!$has_table && file_exists(__DIR__ . '/database-update-16.sql')) {
        $sql = file_get_contents(__DIR__ . '/database-update-16.sql');
        $pdo->exec($sql);
        $success_message = 'Database tables for Communication Engine initialized successfully.';
    }
} catch (Exception $e) {
    $error_message = 'Self-healing database setup failed. Please execute database-update-16.sql in phpMyAdmin. Error: ' . $e->getMessage();
}

// Load synchronized templates
$templates = [];
try {
    $templates = $pdo->query("SELECT template_name, meta_data FROM communication_templates WHERE status='approved' ORDER BY template_name ASC")->fetchAll();
} catch (Exception $ex) {}

// Load available PEPP courses for target segmentation
$courses = [];
try {
    $courses = $pdo->query("SELECT DISTINCT pepp_course FROM users WHERE pepp_course IS NOT NULL AND pepp_course <> '' ORDER BY pepp_course ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {}

/* ── POST: Process Campaign Creation & Queueing ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_campaign') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $campaignName  = trim($_POST['campaign_name'] ?? '');
        $channel       = $_POST['channel'] ?? 'whatsapp';
        $templateName  = $_POST['template_name'] ?? '';
        $targetCourse  = $_POST['target_course'] ?? '';
        $targetStatus  = $_POST['target_status'] ?? '';
        
        // Extract raw parameter placeholders
        $paramsText    = trim($_POST['template_params'] ?? '');
        $paramsArray   = array_filter(array_map('trim', explode(',', $paramsText)));

        if (!$campaignName) {
            $error_message = 'Please specify a campaign name.';
        } elseif ($channel === 'whatsapp' && !$templateName) {
            $error_message = 'Please select a Meta Template for the WhatsApp campaign.';
        } else {
            // Build query based on segments
            $where = ["whatsapp_number IS NOT NULL AND whatsapp_number <> ''"];
            $queryParams = [];
            
            if ($targetCourse) {
                $where[] = "pepp_course = ?";
                $queryParams[] = $targetCourse;
            }
            
            if ($targetStatus) {
                $where[] = "status = ?";
                $queryParams[] = $targetStatus;
            } else {
                $where[] = "status IN ('approved', 'pending')";
            }

            try {
                $queryStr = "SELECT user_id, name, whatsapp_country_code, whatsapp_number, pepp_course FROM users WHERE " . implode(' AND ', $where);
                $stmtUsers = $pdo->prepare($queryStr);
                $stmtUsers->execute($queryParams);
                $recipients = $stmtUsers->fetchAll();

                if (empty($recipients)) {
                    $error_message = 'No matching students found for the selected segment criteria.';
                } else {
                    $pdo->beginTransaction();
                    
                    // Insert Campaign
                    $stmtCamp = $pdo->prepare("
                        INSERT INTO communication_campaigns (name, channel, template_name, segment_criteria, status, created_by, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, 'active', ?, NOW(), NOW())
                    ");
                    $criteria = json_encode(['course' => $targetCourse, 'status' => $targetStatus, 'params' => $paramsArray]);
                    $stmtCamp->execute([$campaignName, $channel, $templateName ?: null, $criteria, $admin_username]);
                    $campaignId = (int)$pdo->lastInsertId();

                    require_once 'includes/communication/CommunicationEngine.php';
                    $engine = CommunicationEngine::getInstance($pdo);
                    
                    $queuedCount = 0;
                    $stmtRecip = $pdo->prepare("
                        INSERT INTO communication_campaign_recipients (campaign_id, recipient, recipient_name, queue_id, status, created_at) 
                        VALUES (?, ?, ?, ?, 'pending', NOW())
                    ");

                    foreach ($recipients as $user) {
                        $phone = preg_replace('/\D/', '', $user['whatsapp_country_code'] . $user['whatsapp_number']);
                        if (strlen($phone) === 10) $phone = '91' . $phone;

                        // Build dynamic variables replacing {student_name} or {course} if written by admin
                        $resolvedParams = [];
                        foreach ($paramsArray as $p) {
                            if ($p === '{student_name}') {
                                $resolvedParams[] = $user['name'];
                            } elseif ($p === '{course}') {
                                $resolvedParams[] = $user['pepp_course'];
                            } else {
                                $resolvedParams[] = $p;
                            }
                        }

                        $templatePayload = [];
                        if ($templateName) {
                            $templatePayload = [
                                'name' => $templateName,
                                'language' => 'en',
                                'parameters' => $resolvedParams
                            ];
                        }

                        $body = "Campaign: {$campaignName}"; // Fallback text description

                        $queueId = $engine->queueMessage(
                            $channel,
                            $phone,
                            $user['name'],
                            $campaignName,
                            $body,
                            $body,
                            [],
                            $templatePayload,
                            $admin_username,
                            null,
                            $user['user_id']
                        );

                        $stmtRecip->execute([$campaignId, $phone, $user['name'], $queueId]);
                        $queuedCount++;
                    }

                    $pdo->commit();
                    $success_message = "Campaign successfully created! Queued {$queuedCount} messages for dispatch.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_message = 'Campaign creation failed: ' . $e->getMessage();
            }
        }
    }
}

// Load campaigns list with delivery stats
$campaigns = [];
try {
    $campaigns = $pdo->query("
        SELECT c.*, 
          COUNT(r.id) as total_recipients,
          SUM(CASE WHEN r.status = 'sent' THEN 1 ELSE 0 END) as sent_count,
          SUM(CASE WHEN r.status = 'failed' THEN 1 ELSE 0 END) as failed_count
        FROM communication_campaigns c
        LEFT JOIN communication_campaign_recipients r ON c.id = r.campaign_id
        GROUP BY c.id
        ORDER BY c.id DESC
    ")->fetchAll();
} catch (Exception $ex) {}

include 'includes/admin_nav.php';
?>

<div class="container-fluid" style="padding:20px;">
    <?php if ($success_message): ?>
        <div class="alert alert-success" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:12px 18px; border-radius:12px; margin-bottom:20px;">
            <i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger" style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 18px; border-radius:12px; margin-bottom:20px;">
            <i class="fas fa-circle-xmark"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- ── NAVIGATION TABS ── -->
    <div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid #e5e7eb; padding-bottom:8px;">
        <a href="communication-dashboard.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-gears"></i> API Settings &amp; Queue</a>
        <a href="communication-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-layer-group"></i> Meta Templates Sync</a>
        <a href="communication-campaigns.php" class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
        <a href="whatsapp-inbox.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fab fa-whatsapp"></i> WhatsApp Inbox</a>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1.8fr; gap:20px; align-items:start;">
        <!-- Left: Create Campaign Form -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden;">
            <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
                <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-bullhorn" style="color:#8b5cf6; margin-right:4px;"></i> Create Bulk Campaign</h3>
            </div>
            
            <div style="padding:20px;">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_campaign">
                    
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Campaign Name</label>
                        <input type="text" name="campaign_name" placeholder="e.g. Welcome Message Batch 2026" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" required>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Communication Channel</label>
                        <select name="channel" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                            <option value="whatsapp">WhatsApp Cloud API (Template-based)</option>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Select Meta WhatsApp Template</label>
                        <select name="template_name" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" required>
                            <option value="">-- Choose Approved Template --</option>
                            <?php foreach ($templates as $tpl): ?>
                                <option value="<?php echo htmlspecialchars($tpl['template_name']); ?>"><?php echo htmlspecialchars($tpl['template_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Template Parameters (comma separated)</label>
                        <input type="text" name="template_params" placeholder="e.g. {student_name}, {course}, Welcome!" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                        <span style="font-size:0.75rem; color:#9ca3af; display:block; margin-top:4px;">Supported dynamic placeholders: <code style="font-family:monospace;">{student_name}</code>, <code style="font-family:monospace;">{course}</code> or any fixed string values.</span>
                    </div>

                    <!-- Target Segmentation Filters -->
                    <div style="border-top:1px dashed #e5e7eb; margin-top:14px; padding-top:10px; margin-bottom:16px;">
                        <span style="font-size:0.8rem; font-weight:700; color:#111827; display:block; margin-bottom:10px;"><i class="fas fa-filter"></i> Target Audience Segment</span>
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:600; color:#4b5563; margin-bottom:4px;">PEPP Course</label>
                                <select name="target_course" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.8rem;">
                                    <option value="">All Enrolled Courses</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.75rem; font-weight:600; color:#4b5563; margin-bottom:4px;">Student Status</label>
                                <select name="target_status" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.8rem;">
                                    <option value="">Approved &amp; Pending</option>
                                    <option value="approved">Approved Only</option>
                                    <option value="pending">Pending Only</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; border-radius:8px; font-weight:700;"><i class="fas fa-rocket"></i> Launch Campaign</button>
                </form>
            </div>
        </div>

        <!-- Right: Campaigns List -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden;">
            <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
                <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-chart-column" style="margin-right:4px;"></i> Active &amp; Completed Campaigns (<?php echo count($campaigns); ?>)</h3>
            </div>
            
            <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                    <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                        <th style="padding:12px; font-weight:600; color:#374151;">Campaign details</th>
                        <th style="padding:12px; font-weight:600; color:#374151;">Recipients</th>
                        <th style="padding:12px; font-weight:600; color:#374151;">Sent</th>
                        <th style="padding:12px; font-weight:600; color:#374151;">Failed</th>
                        <th style="padding:12px; font-weight:600; color:#374151;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($campaigns)): ?>
                        <tr>
                            <td colspan="5" style="padding:30px; text-align:center; color:#9ca3af;">No bulk campaigns have been created yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($campaigns as $camp): ?>
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:12px;">
                                    <div style="font-weight:700; color:#111827;"><?php echo htmlspecialchars($camp['name']); ?></div>
                                    <div style="font-size:0.75rem; color:#6b7280; margin-top:2px;">Template: <b><?php echo htmlspecialchars($camp['template_name'] ?? '-'); ?></b> · Channel: <b><?php echo strtoupper($camp['channel']); ?></b></div>
                                </td>
                                <td style="padding:12px; font-weight:700;"><?php echo $camp['total_recipients']; ?></td>
                                <td style="padding:12px; font-weight:700; color:#16a34a;"><?php echo $camp['sent_count']; ?></td>
                                <td style="padding:12px; font-weight:700; color:#dc2626;"><?php echo $camp['failed_count']; ?></td>
                                <td style="padding:12px;">
                                    <span class="badge <?php echo $camp['status'] === 'completed' ? 'green' : 'blue'; ?>" style="font-size:0.7rem; font-weight:700;">
                                        <?php echo strtoupper($camp['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
