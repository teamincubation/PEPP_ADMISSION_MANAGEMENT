<?php
/**
 * PEPP Learning ERP - Bulk Communication Campaigns Page (Phase 2).
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('communication');

$active_page = 'communication';
$page_title  = 'Bulk Communication Campaigns';
$page_sub    = 'Broadcast WhatsApp alerts to segmented student and lead lists';

$success_message = '';
$error_message   = '';

// Standard phone cleaning matching PEPP WABA format
function clean_wa_phone($num) {
    $num = preg_replace('/\D/', '', $num);
    if (strlen($num) === 10) {
        $num = '91' . $num;
    }
    return $num;
}

/* ─────────────────────────────────────────────────────────────────────────────
   AJAX APIs
   ───────────────────────────────────────────────────────────────────────────── */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // 1. Fetch Template Metadata Details
    if ($action === 'ajax_get_template') {
        $name = $_GET['template_name'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? AND status='approved' LIMIT 1");
        $stmt->execute([$name]);
        $tpl = $stmt->fetch();
        if ($tpl) {
            $meta = json_decode($tpl['meta_data'], true) ?: [];
            echo json_encode(['success' => true, 'template' => $tpl, 'meta' => $meta]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Template not found or not approved.']);
        }
        exit;
    }

    // 2. AJAX Recipient Preview Calculator
    if ($action === 'ajax_preview_audience') {
        $courses = $_POST['courses'] ?? [];
        $statuses = $_POST['statuses'] ?? [];

        if (empty($courses) || empty($statuses)) {
            echo json_encode([
                'success' => true,
                'total_matching' => 0,
                'eligible_count' => 0,
                'duplicates' => 0,
                'opted_out' => 0,
                'invalid' => 0,
                'recipients' => []
            ]);
            exit;
        }

        // Build target segments queries
        $where = [];
        $params = [];

        $coursePlaceholders = implode(',', array_fill(0, count($courses), '?'));
        $where[] = "interested_course IN ($coursePlaceholders)";
        $params = array_merge($params, $courses);

        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "status IN ($statusPlaceholders)";
        $params = array_merge($params, $statuses);

        $where_sql = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT id, name, whatsapp_number, interested_course, status, is_opted_out, assigned_to FROM leads WHERE $where_sql ORDER BY id ASC");
        $stmt->execute($params);
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalMatching = count($leads);
        $eligibleList = [];
        $duplicates = 0;
        $optedOut = 0;
        $invalid = 0;
        $seenPhones = [];

        foreach ($leads as $l) {
            if ((int)$l['is_opted_out'] === 1) {
                $optedOut++;
                continue;
            }

            $phone = clean_wa_phone($l['whatsapp_number']);
            if (empty($phone) || strlen($phone) < 10) {
                $invalid++;
                continue;
            }

            if (in_array($phone, $seenPhones, true)) {
                $duplicates++;
                continue;
            }

            $seenPhones[] = $phone;
            $eligibleList[] = [
                'id' => $l['id'],
                'name' => $l['name'] ?: 'Unknown',
                'phone' => $phone,
                'raw_phone' => $l['whatsapp_number'],
                'course' => $l['interested_course'] ?: 'None',
                'status' => ucfirst($l['status']),
                'assigned' => $l['assigned_to'] ?: '-'
            ];
        }

        echo json_encode([
            'success' => true,
            'total_matching' => $totalMatching,
            'eligible_count' => count($eligibleList),
            'duplicates' => $duplicates,
            'opted_out' => $optedOut,
            'invalid' => $invalid,
            'recipients' => array_slice($eligibleList, 0, 100) // Limit list size returned to client
        ]);
        exit;
    }

    // 3. Drilldown Details Panel
    if ($action === 'ajax_campaign_details') {
        $id = (int)$_GET['campaign_id'];
        $stmt = $pdo->prepare("SELECT * FROM communication_campaigns WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $camp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$camp) {
            echo json_encode(['success' => false, 'message' => 'Campaign not found.']);
            exit;
        }

        try {
            $stmtRec = $pdo->prepare("
                SELECT r.*, 
                       q.status as queue_status, 
                       q.error_message as queue_error, 
                       q.delivered_at, 
                       q.message_id, 
                       q.retry_count, 
                       q.last_retry_at, 
                       wm.read_at 
                FROM communication_campaign_recipients r
                LEFT JOIN communication_queue q ON r.queue_id = q.id
                LEFT JOIN whatsapp_messages wm ON q.message_id = wm.wa_message_id
                WHERE r.campaign_id = ? 
                ORDER BY r.id ASC
            ");
            $stmtRec->execute([$id]);
            $recipients = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'campaign' => $camp,
            'recipients' => $recipients
        ]);
        exit;
    }

    // 4. AJAX Control Actions: Pause, Resume, Cancel, Retry Failed
    if ($action === 'ajax_campaign_control') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
            exit;
        }

        $id = (int)$_POST['campaign_id'];
        $act = $_POST['control_action'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM communication_campaigns WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $camp = $stmt->fetch();

        if (!$camp) {
            echo json_encode(['success' => false, 'message' => 'Campaign not found.']);
            exit;
        }

        if ($act === 'pause') {
            $stmtUpd = $pdo->prepare("UPDATE communication_campaigns SET status = 'paused', updated_at = NOW() WHERE id = ?");
            $stmtUpd->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Campaign paused. Background dispatches suspended.']);
        } elseif ($act === 'resume') {
            $stmtUpd = $pdo->prepare("UPDATE communication_campaigns SET status = 'active', updated_at = NOW() WHERE id = ?");
            $stmtUpd->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Campaign resumed. Sending in progress.']);
        } elseif ($act === 'cancel') {
            $pdo->beginTransaction();
            try {
                $stmtUpd = $pdo->prepare("UPDATE communication_campaigns SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                $stmtUpd->execute([$id]);

                // Skip pending queue items
                $stmtRec = $pdo->prepare("SELECT queue_id FROM communication_campaign_recipients WHERE campaign_id = ? AND status='pending'");
                $stmtRec->execute([$id]);
                $qIds = $stmtRec->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($qIds)) {
                    $qPlaceholders = implode(',', array_fill(0, count($qIds), '?'));
                    $stmtQ = $pdo->prepare("UPDATE communication_queue SET status='cancelled', error_message='cancelled:campaign_stopped' WHERE id IN ($qPlaceholders)");
                    $stmtQ->execute($qIds);
                }

                $stmtRecUpd = $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed' WHERE campaign_id = ? AND status='pending'");
                $stmtRecUpd->execute([$id]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Campaign cancelled. Pending dispatches aborted.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Cancellation failed: ' . $e->getMessage()]);
            }
        } elseif ($act === 'retry') {
            // Fetch failed recipients
            $stmtFailed = $pdo->prepare("
                SELECT r.*, q.template_name, q.template_data, q.attachments, q.subject, q.body_html, q.body_text 
                FROM communication_campaign_recipients r
                JOIN communication_queue q ON r.queue_id = q.id
                WHERE r.campaign_id = ? AND r.status = 'failed'
            ");
            $stmtFailed->execute([$id]);
            $failedRecipients = $stmtFailed->fetchAll(PDO::FETCH_ASSOC);

            if (empty($failedRecipients)) {
                echo json_encode(['success' => false, 'message' => 'No failed dispatches found to retry.']);
                exit;
            }

            require_once 'includes/communication/CommunicationEngine.php';
            $engine = CommunicationEngine::getInstance($pdo);

            $pdo->beginTransaction();
            try {
                $retriedCount = 0;
                foreach ($failedRecipients as $rec) {
                    $tplData = json_decode($rec['template_data'], true) ?: [];
                    $attData = json_decode($rec['attachments'], true) ?: [];

                    $newQueueId = $engine->queueMessage(
                        'whatsapp',
                        $rec['recipient'],
                        $rec['recipient_name'],
                        $rec['subject'],
                        $rec['body_html'],
                        $rec['body_text'],
                        $attData,
                        $tplData,
                        $admin_username,
                        null,
                        null
                    );

                    $stmtUpdRec = $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'pending', queue_id = ?, sent_at = NULL WHERE id = ?");
                    $stmtUpdRec->execute([$newQueueId, $rec['id']]);
                    $retriedCount++;
                }

                $stmtCampActive = $pdo->prepare("UPDATE communication_campaigns SET status = 'active', updated_at = NOW() WHERE id = ?");
                $stmtCampActive->execute([$id]);

                $pdo->commit();
                try {
                    $engine->triggerCronBackground();
                } catch (Exception $bgEx) {}

                echo json_encode(['success' => true, 'message' => "Successfully re-queued {$retriedCount} failed dispatches."]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Retry failed: ' . $e->getMessage()]);
            }
        }
        exit;
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Campaign Form Submit Logic (POST)
   ───────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_campaign') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $campaignName = trim($_POST['campaign_name'] ?? '');
        $targetAudience = $_POST['target_audience'] ?? 'students';
        $templateName = $_POST['template_name'] ?? '';
        
        $scheduleType = $_POST['schedule_type'] ?? 'now';
        $scheduleDate = $_POST['schedule_date'] ?? '';
        $scheduleTime = $_POST['schedule_time'] ?? '';

        // Media header uploaded file url
        $headerMediaUrl = '';
        if (isset($_FILES['header_media_file']) && $_FILES['header_media_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['header_media_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'mp4', 'pdf'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if (!in_array($ext, $allowedExtensions, true)) {
                $error_message = 'Invalid media header file extension. Allowed: JPG, PNG, MP4, PDF.';
            } elseif ($file['size'] > $maxSize) {
                $error_message = 'Media file is too large (max 5MB).';
            } else {
                $dir = __DIR__ . '/uploads/whatsapp_campaign_media/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '', pathinfo($file['name'], PATHINFO_FILENAME)) . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
                    // Build public URL safely
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $headerMediaUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/whatsapp_campaign_media/' . $safeName;
                } else {
                    $error_message = 'Failed to save uploaded media file.';
                }
            }
        }

        if (empty($error_message)) {
            if (!$campaignName) {
                $error_message = 'Please specify a campaign name.';
            } elseif (!$templateName) {
                $error_message = 'Please select a Meta Template for the WhatsApp campaign.';
            } else {
                // Fetch template metadata to compile mappings
                $stmtTpl = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? AND status='approved' LIMIT 1");
                $stmtTpl->execute([$templateName]);
                $template = $stmtTpl->fetch();

                if (!$template) {
                    $error_message = 'Selected template is missing or not approved.';
                } else {
                    $meta = json_decode($template['meta_data'], true) ?: [];
                    $varMappings = $_POST['vars'] ?? [];
                    $staticVals = $_POST['static_vars'] ?? [];

                    // ── Target segmentation ──
                    $recipients = [];
                    $segmentCriteria = [];

                    if ($targetAudience === 'leads') {
                        $courses = $_POST['target_leads_courses'] ?? [];
                        $statuses = $_POST['target_leads_statuses'] ?? [];

                        if (empty($courses) || empty($statuses)) {
                            $error_message = 'Please select at least one course and one lead status.';
                        } else {
                            $where = [];
                            $params = [];

                            $coursePlaceholders = implode(',', array_fill(0, count($courses), '?'));
                            $where[] = "interested_course IN ($coursePlaceholders)";
                            $params = array_merge($params, $courses);

                            $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
                            $where[] = "status IN ($statusPlaceholders)";
                            $params = array_merge($params, $statuses);

                            $where_sql = implode(' AND ', $where);
                            $stmt = $pdo->prepare("SELECT id, name, whatsapp_number, interested_course, status, is_opted_out FROM leads WHERE $where_sql ORDER BY id ASC");
                            $stmt->execute($params);
                            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            $seenPhones = [];
                            foreach ($leads as $l) {
                                if ((int)$l['is_opted_out'] === 1) continue;
                                $phone = clean_wa_phone($l['whatsapp_number']);
                                if (empty($phone) || strlen($phone) < 10) continue;
                                if (in_array($phone, $seenPhones, true)) continue;

                                $seenPhones[] = $phone;
                                $recipients[] = [
                                    'lead_id' => $l['id'],
                                    'user_id' => null,
                                    'name' => $l['name'] ?: 'Prospect',
                                    'phone' => $phone,
                                    'course' => $l['interested_course'],
                                    'status' => $l['status'],
                                    'raw_lead' => $l
                                ];
                            }
                            $segmentCriteria = [
                                'target_audience' => 'leads',
                                'courses' => $courses,
                                'statuses' => $statuses,
                                'var_mappings' => $varMappings,
                                'static_vals' => $staticVals,
                                'header_media' => $headerMediaUrl
                            ];
                        }
                    } else {
                        // LEGACY Student targeting configuration
                        $targetCourse = $_POST['target_course'] ?? '';
                        $targetStatus = $_POST['target_status'] ?? '';

                        $where = ["whatsapp_number IS NOT NULL AND whatsapp_number <> ''"];
                        $params = [];
                        if ($targetCourse) {
                            $where[] = "pepp_course = ?";
                            $params[] = $targetCourse;
                        }
                        if ($targetStatus) {
                            $where[] = "status = ?";
                            $params[] = $targetStatus;
                        } else {
                            $where[] = "status IN ('approved', 'pending')";
                        }

                        $queryStr = "SELECT user_id, name, whatsapp_country_code, whatsapp_number, pepp_course, status FROM users WHERE " . implode(' AND ', $where);
                        $stmtUsers = $pdo->prepare($queryStr);
                        $stmtUsers->execute($params);
                        $students = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($students as $stu) {
                            $phone = clean_wa_phone($stu['whatsapp_country_code'] . $stu['whatsapp_number']);
                            $recipients[] = [
                                'lead_id' => null,
                                'user_id' => $stu['user_id'],
                                'name' => $stu['name'],
                                'phone' => $phone,
                                'course' => $stu['pepp_course'],
                                'status' => $stu['status'],
                                'raw_student' => $stu
                            ];
                        }
                        $segmentCriteria = [
                            'target_audience' => 'students',
                            'course' => $targetCourse,
                            'status' => $targetStatus,
                            'var_mappings' => $varMappings,
                            'static_vals' => $staticVals,
                            'header_media' => $headerMediaUrl
                        ];
                    }

                    if (empty($recipients)) {
                        $error_message = 'No eligible recipients found matching the target segmentation criteria.';
                    } else {
                        // Calculate scheduling datetime
                        $nextAttempt = 'NOW()';
                        $scheduledAtVal = null;
                        $campaignStatus = 'active';

                        if ($scheduleType === 'schedule' && !empty($scheduleDate) && !empty($scheduleTime)) {
                            $scheduledAtVal = $scheduleDate . ' ' . $scheduleTime . ':00';
                            $nextAttempt = '?' ;
                            $campaignStatus = 'scheduled';
                        }

                        $pdo->beginTransaction();
                        try {
                            // Insert Campaign record
                            $stmtCamp = $pdo->prepare("
                                INSERT INTO communication_campaigns (name, channel, target_audience, template_name, segment_criteria, status, scheduled_at, created_by, created_at, updated_at) 
                                VALUES (?, 'whatsapp', ?, ?, ?, ?, ?, ?, NOW(), NOW())
                            ");
                            $stmtCamp->execute([
                                $campaignName,
                                $targetAudience,
                                $templateName,
                                json_encode($segmentCriteria),
                                $campaignStatus,
                                $scheduledAtVal,
                                $admin_username
                            ]);
                            $campaignId = (int)$pdo->lastInsertId();

                            $stmtRecip = $pdo->prepare("
                                INSERT INTO communication_campaign_recipients (campaign_id, lead_id, recipient, recipient_name, queue_id, status, created_at) 
                                VALUES (?, ?, ?, ?, NULL, 'pending', NOW())
                            ");

                            $queuedCount = 0;
                            foreach ($recipients as $rec) {
                                $stmtRecip->execute([
                                    $campaignId,
                                    $rec['lead_id'],
                                    $rec['phone'],
                                    $rec['name']
                                ]);
                                $queuedCount++;
                            }

                            $pdo->commit();
                            try {
                                require_once 'includes/communication/CommunicationEngine.php';
                                $commEngine = CommunicationEngine::getInstance($pdo);
                                $commEngine->triggerCronBackground();
                            } catch (Exception $bgEx) {}

                            $success_message = "Campaign successfully configured and snapshot saved! Enqueued {$queuedCount} recipients for background dispatch.";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error_message = 'Campaign configuration failed: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

/* ── Load Campaigns and Templates list ── */
$campaigns = [];
try {
    $campaigns = $pdo->query("
        SELECT c.*, 
          COUNT(r.id) as total_recipients,
          SUM(CASE WHEN r.queue_id IS NULL AND r.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
          SUM(CASE WHEN r.queue_id IS NOT NULL AND q.status = 'pending' THEN 1 ELSE 0 END) as queued_count,
          SUM(CASE WHEN q.status = 'processing' THEN 1 ELSE 0 END) as processing_count,
          SUM(CASE WHEN q.status = 'sent' OR r.status = 'sent' THEN 1 ELSE 0 END) as sent_count,
          SUM(CASE WHEN q.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
          SUM(CASE WHEN q.status = 'read' THEN 1 ELSE 0 END) as read_count,
          SUM(CASE WHEN r.status = 'failed' OR q.status = 'failed' THEN 1 ELSE 0 END) as failed_count,
          SUM(CASE WHEN q.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
        FROM communication_campaigns c
        LEFT JOIN communication_campaign_recipients r ON c.id = r.campaign_id
        LEFT JOIN communication_queue q ON r.queue_id = q.id
        GROUP BY c.id
        ORDER BY c.id DESC
    ")->fetchAll();
} catch (Exception $ex) {}

$marketingTemplates = [];
try {
    $marketingTemplates = $pdo->query("
        SELECT * FROM communication_templates 
        WHERE channel='whatsapp' AND status='approved' AND category='MARKETING'
        ORDER BY template_name ASC
    ")->fetchAll();
} catch (Exception $ex) {}

// Query courses available for dropdown segments
$leadCourses = [];
try {
    $leadCourses = $pdo->query("SELECT DISTINCT interested_course FROM leads WHERE interested_course IS NOT NULL AND interested_course <> '' ORDER BY interested_course ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $ex) {}

$studentCourses = [];
try {
    $studentCourses = $pdo->query("SELECT DISTINCT pepp_course FROM users WHERE pepp_course IS NOT NULL AND pepp_course <> '' ORDER BY pepp_course ASC")->fetchAll(PDO::FETCH_COLUMN);
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
        <a href="whatsapp-marketing-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-magic"></i> Marketing Templates</a>
        <a href="communication-campaigns.php" class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
        <a href="whatsapp-inbox.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fab fa-whatsapp"></i> WhatsApp Inbox</a>
    </div>

    <!-- Layout Grid -->
    <div style="display:grid; grid-template-columns: 1.1fr 1.9fr; gap:20px; align-items:start;">
        
        <!-- Left Column: Create Campaign Form -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02);">
            <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
                <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-bullhorn" style="color:#8b5cf6; margin-right:4px;"></i> Create Bulk Campaign</h3>
            </div>
            
            <div style="padding:20px;">
                <!-- Target Audience Switch Tabs -->
                <div style="display:flex; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:16px;">
                    <button type="button" onclick="switchAudience('leads')" id="btn-tab-leads" style="flex:1; padding:10px; border:none; outline:none; font-weight:700; cursor:pointer; font-size:0.8rem; background:#f1f5f9; color:#475569;">Leads Database</button>
                    <button type="button" onclick="switchAudience('students')" id="btn-tab-students" style="flex:1; padding:10px; border:none; outline:none; font-weight:700; cursor:pointer; font-size:0.8rem; background:#fff; color:#64748b; border-left:1px solid #e2e8f0;">Students Database</button>
                </div>

                <form method="POST" id="campaign-create-form" enctype="multipart/form-data" onsubmit="return validateFormSubmit(event)">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_campaign">
                    <input type="hidden" name="target_audience" id="inp-target-audience" value="leads">
                    
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Campaign Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="campaign_name" id="inp-campaign-name" placeholder="e.g. CUET PG August Admission Campaign" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" required>
                    </div>

                    <!-- Leads Target Filters Section -->
                    <div id="section-target-leads" style="display:block; border:1px solid #f1f5f9; padding:12px; border-radius:12px; background:#fafafa; margin-bottom:14px;">
                        <span style="font-size:0.8rem; font-weight:700; color:#1e293b; display:block; margin-bottom:10px;"><i class="fas fa-filter"></i> Target Leads Filters</span>
                        
                        <div style="margin-bottom:10px;">
                            <label style="display:block; font-size:0.75rem; font-weight:700; color:#4b5563; margin-bottom:4px;">Lead Statuses <span style="color:#ef4444;">*</span></label>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px;">
                                <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="checkbox" name="target_leads_statuses[]" value="new" checked class="chk-status" onchange="triggerAudiencePreview()"> New</label>
                                <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="checkbox" name="target_leads_statuses[]" value="contacted" checked class="chk-status" onchange="triggerAudiencePreview()"> Contacted</label>
                                <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="checkbox" name="target_leads_statuses[]" value="interested" checked class="chk-status" onchange="triggerAudiencePreview()"> Interested</label>
                                <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="checkbox" name="target_leads_statuses[]" value="follow_up" checked class="chk-status" onchange="triggerAudiencePreview()"> Follow-up</label>
                            </div>
                        </div>

                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <label style="font-size:0.75rem; font-weight:700; color:#4b5563;">PEPP Course Targets <span style="color:#ef4444;">*</span></label>
                                <div style="display:flex; gap:6px;">
                                    <button type="button" onclick="toggleLeadCheckboxes(true)" style="border:none; background:none; font-size:0.65rem; color:#3b82f6; font-weight:700; cursor:pointer; padding:0;">Select All</button>
                                    <span style="font-size:0.65rem; color:#94a3b8;">|</span>
                                    <button type="button" onclick="toggleLeadCheckboxes(false)" style="border:none; background:none; font-size:0.65rem; color:#ef4444; font-weight:700; cursor:pointer; padding:0;">Clear All</button>
                                </div>
                            </div>
                            <div style="max-height:120px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px; padding:6px; background:#fff;" id="leads-courses-checklist">
                                <?php foreach ($leadCourses as $lc): ?>
                                    <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; margin-bottom:4px; cursor:pointer;"><input type="checkbox" name="target_leads_courses[]" value="<?php echo htmlspecialchars($lc); ?>" class="chk-course" onchange="triggerAudiencePreview()"> <?php echo htmlspecialchars($lc); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Students Target Filters Section -->
                    <div id="section-target-students" style="display:none; border:1px solid #f1f5f9; padding:12px; border-radius:12px; background:#fafafa; margin-bottom:14px;">
                        <span style="font-size:0.8rem; font-weight:700; color:#1e293b; display:block; margin-bottom:10px;"><i class="fas fa-filter"></i> Target Students Filters</span>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="font-size:0.75rem; font-weight:700; color:#4b5563; display:block; margin-bottom:4px;">Enrolled Course</label>
                                <select name="target_course" class="form-control" style="font-size:0.8rem; border-radius:6px;">
                                    <option value="">All Courses</option>
                                    <?php foreach ($studentCourses as $sc): ?>
                                        <option value="<?php echo htmlspecialchars($sc); ?>"><?php echo htmlspecialchars($sc); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:0.75rem; font-weight:700; color:#4b5563; display:block; margin-bottom:4px;">Approval Status</label>
                                <select name="target_status" class="form-control" style="font-size:0.8rem; border-radius:6px;">
                                    <option value="">Approved &amp; Pending</option>
                                    <option value="approved">Approved Only</option>
                                    <option value="pending">Pending Only</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Template Selection -->
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Meta Approved Template <span style="color:#ef4444;">*</span></label>
                        <select name="template_name" id="sel-template-name" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" onchange="loadTemplateDetails(this.value)" required>
                            <option value="">-- Select Marketing Template --</option>
                            <?php foreach ($marketingTemplates as $m_tpl): ?>
                                <option value="<?php echo htmlspecialchars($m_tpl['template_name']); ?>"><?php echo htmlspecialchars($m_tpl['template_name']); ?> (<?php echo $m_tpl['language']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Upload Image Header Block -->
                    <div id="section-media-header" style="display:none; border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#fcfcfc; margin-bottom:14px;">
                        <label style="display:block; font-size:0.75rem; font-weight:700; color:#1e293b; margin-bottom:4px;"><i class="fas fa-file-image"></i> Required Header Media File</label>
                        <input type="file" name="header_media_file" id="inp-media-file" class="form-control" style="font-size:0.75rem;" accept="image/*,video/mp4,application/pdf" onchange="onMediaFileChange(event)">
                        <span style="font-size:0.65rem; color:#94a3b8; display:block; margin-top:2px;">Select JPG, PNG, MP4, or PDF. Max file size: 5MB.</span>
                    </div>

                    <!-- Dynamic Variables Mapping Block -->
                    <div id="section-variable-mapping" style="display:none; border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#f8fafc; margin-bottom:14px;">
                        <span style="font-size:0.75rem; font-weight:700; color:#4b5563; display:block; margin-bottom:8px;"><i class="fas fa-brackets-curly" style="color:#6366f1;"></i> Interpolation Variable Mappings</span>
                        <div id="variable-mappings-inputs" style="display:flex; flex-direction:column; gap:8px;"></div>
                    </div>

                    <!-- Scheduling Options -->
                    <div style="border-top:1px dashed #e2e8f0; padding-top:12px; margin-bottom:16px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;"><i class="fas fa-clock"></i> Schedule Settings</label>
                        <div style="display:flex; gap:10px; margin-bottom:10px;">
                            <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="radio" name="schedule_type" value="now" checked onchange="toggleScheduleBlock(false)"> Send Immediately</label>
                            <label style="font-size:0.75rem; display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="radio" name="schedule_type" value="schedule" onchange="toggleScheduleBlock(true)"> Schedule Launch</label>
                        </div>
                        <div id="section-schedule-datetime" style="display:none; grid-template-columns:1fr 1fr; gap:10px; border:1px solid #f1f5f9; padding:8px; border-radius:8px; background:#fafafa;">
                            <div>
                                <label style="font-size:0.7rem; color:#64748b; font-weight:600;">Date</label>
                                <input type="date" name="schedule_date" id="inp-sched-date" class="form-control" style="font-size:0.75rem;">
                            </div>
                            <div>
                                <label style="font-size:0.7rem; color:#64748b; font-weight:600;">Time</label>
                                <input type="time" name="schedule_time" id="inp-sched-time" class="form-control" style="font-size:0.75rem;">
                            </div>
                        </div>
                    </div>

                    <!-- Calculate Button -->
                    <button type="button" onclick="calculatePreview()" class="btn btn-outline" style="width:100%; border-radius:8px; font-weight:700; margin-bottom:10px; padding:10px;"><i class="fas fa-calculator"></i> Calculate &amp; Preview Recipients</button>

                    <!-- Launch Submit Button -->
                    <button type="submit" class="btn btn-primary" id="btn-submit-campaign" style="width:100%; border-radius:8px; font-weight:700; padding:10px;" disabled><i class="fas fa-rocket"></i> Confirm &amp; Queue Campaign</button>
                </form>
            </div>
        </div>

        <!-- Right Column: Campaigns Dashboard list and dynamic details drilldown -->
        <div>
            <!-- Active Campaigns List -->
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02); margin-bottom:20px;" id="panel-campaigns-list">
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-chart-column" style="margin-right:4px;"></i> Active &amp; Completed Campaigns (<?php echo count($campaigns); ?>)</h3>
                </div>
                
                <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                    <thead>
                        <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                            <th style="padding:12px; font-weight:700; color:#374151;">Campaign Details</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Target</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Audience</th>
                            <th style="padding:12px; font-weight:700; color:#374151;">Progress</th>
                            <th style="padding:12px; font-weight:700; color:#374151; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campaigns)): ?>
                            <tr>
                                <td colspan="5" style="padding:30px; text-align:center; color:#9ca3af;">No bulk campaigns have been created yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($campaigns as $camp): ?>
                                <?php 
                                    $recipCount = (int)$camp['total_recipients'];
                                    $sentCount = (int)$camp['sent_count'];
                                    $failCount = (int)$camp['failed_count'];
                                    $deliveredCount = (int)($camp['delivered_count'] ?? 0);
                                    $readCount = (int)($camp['read_count'] ?? 0);
                                    $cancelledCount = (int)($camp['cancelled_count'] ?? 0);
                                    
                                    $processed = $sentCount + $failCount + $deliveredCount + $readCount + $cancelledCount;
                                    $prog = $recipCount > 0 ? round($processed / $recipCount * 100) : 0;
                                    if ($prog > 100) $prog = 100;
                                    
                                    $statusColor = 'gray';
                                    if ($camp['status'] === 'active') $statusColor = 'blue';
                                    elseif ($camp['status'] === 'completed') $statusColor = 'green';
                                    elseif ($camp['status'] === 'paused') $statusColor = 'orange';
                                    elseif ($camp['status'] === 'cancelled') $statusColor = 'red';
                                ?>
                                <tr style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:12px;">
                                        <div style="font-weight:700; color:#111827;"><?php echo htmlspecialchars($camp['name']); ?></div>
                                        <div style="font-size:0.7rem; color:#9ca3af; margin-top:2px;">Template: <b><?php echo htmlspecialchars($camp['template_name']); ?></b></div>
                                    </td>
                                    <td style="padding:12px;"><span class="badge gray" style="font-size:0.65rem; font-weight:700;"><?php echo strtoupper($camp['target_audience']); ?></span></td>
                                    <td style="padding:12px; font-weight:700;"><?php echo $recipCount; ?></td>
                                    <td style="padding:12px;">
                                        <div style="display:flex; justify-content:space-between; font-size:0.7rem; font-weight:700; color:#475569; margin-bottom:2px;">
                                            <span><?php echo $prog; ?>%</span>
                                            <span>S:<?php echo $sentCount; ?> | D:<?php echo $deliveredCount; ?> | R:<?php echo $readCount; ?> | F:<?php echo $failCount; ?></span>
                                        </div>
                                        <div style="width:100%; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;">
                                            <div style="width:<?php echo $prog; ?>%; height:100%; background:<?php echo ($failCount > 0 ? '#f59e0b' : '#10b981'); ?>;"></div>
                                        </div>
                                    </td>
                                    <td style="padding:12px; text-align:right;">
                                        <button type="button" onclick="loadCampaignDrilldown(<?php echo $camp['id']; ?>)" class="btn btn-sm btn-outline" style="border-radius:6px; font-size:0.75rem;"><i class="fas fa-eye"></i> Details</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Audience Preview and Visual preview block -->
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02); display:none;" id="panel-audience-preview">
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-users-viewfinder" style="margin-right:4px;"></i> Audience Recipient Preview</h3>
                    <button type="button" class="btn btn-sm btn-outline" onclick="calculatePreview()" style="font-size:0.75rem;"><i class="fas fa-rotate"></i> Refresh Audience</button>
                </div>
                
                <div style="padding:16px;">
                    <!-- Metrics grid -->
                    <div style="display:grid; grid-template-columns: repeat(5, 1fr); gap:10px; margin-bottom:20px; text-align:center;">
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fbfbfb;">
                            <div style="font-size:1.1rem; font-weight:800; color:#111827;" id="lbl-matching-leads">0</div>
                            <div style="font-size:0.65rem; color:#64748b; font-weight:700; margin-top:2px;">MATCHING LEADS</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fbfbfb;">
                            <div style="font-size:1.1rem; font-weight:800; color:#f59e0b;" id="lbl-duplicates">0</div>
                            <div style="font-size:0.65rem; color:#64748b; font-weight:700; margin-top:2px;">DUPLICATES EXCLUDED</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fbfbfb;">
                            <div style="font-size:1.1rem; font-weight:800; color:#ef4444;" id="lbl-opted-out">0</div>
                            <div style="font-size:0.65rem; color:#64748b; font-weight:700; margin-top:2px;">OPTED-OUT EXCLUDED</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fbfbfb;">
                            <div style="font-size:1.1rem; font-weight:800; color:#6b7280;" id="lbl-invalid">0</div>
                            <div style="font-size:0.65rem; color:#64748b; font-weight:700; margin-top:2px;">INVALID PHONES</div>
                        </div>
                        <div style="border:1px solid #10b981; border-radius:10px; padding:10px; background:#ecfdf5;">
                            <div style="font-size:1.2rem; font-weight:800; color:#059669;" id="lbl-eligible-recipients">0</div>
                            <div style="font-size:0.65rem; color:#047857; font-weight:800; margin-top:2px;">FINAL RECIPIENTS</div>
                        </div>
                    </div>

                    <!-- Split Layout: Left Table / Right visual preview -->
                    <div style="display:grid; grid-template-columns:1.8fr 1.2fr; gap:16px; align-items:start;">
                        <!-- Preview Table -->
                        <div style="max-height:280px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:10px;">
                            <table style="width:100%; border-collapse:collapse; font-size:0.75rem; text-align:left;">
                                <thead style="background:#f8fafc; position:sticky; top:0; z-index:10; border-bottom:1px solid #cbd5e1;">
                                    <tr>
                                        <th style="padding:8px;">Name</th>
                                        <th style="padding:8px;">Phone</th>
                                        <th style="padding:8px;">Course</th>
                                        <th style="padding:8px;">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="table-body-preview"></tbody>
                            </table>
                        </div>
                        
                        <!-- Visual Chat preview bubble -->
                        <div style="background:#e5ddd5; border:1px solid #cbd5e1; border-radius:12px; overflow:hidden; font-family:sans-serif; background-image:url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-repeat:repeat; padding:10px; min-height:180px; display:flex; flex-direction:column; justify-content:center;">
                            <div style="background:#fff; border-radius:8px 8px 8px 0; max-width:98%; padding:8px; align-self:flex-start; box-shadow:0 1px 2px rgba(0,0,0,0.15); width:100%;">
                                <div id="preview-box-header-media" style="display:none; background:#ece5dd; border-radius:6px; height:80px; align-items:center; justify-content:center; font-size:1.4rem; color:#94a3b8; margin-bottom:6px;">
                                    <i class="fas fa-file-image" id="preview-box-media-icon"></i>
                                </div>
                                <div id="preview-box-header" style="font-weight:700; font-size:0.75rem; color:#111827; margin-bottom:4px; display:none;"></div>
                                <div id="preview-box-body" style="font-size:0.75rem; color:#374151; line-height:1.3; white-space:pre-wrap;"></div>
                                <div id="preview-box-footer" style="font-size:0.65rem; color:#94a3b8; margin-top:4px; display:none; border-top:1px dashed #f1f5f9; padding-top:2px;"></div>
                                <div id="preview-box-bubble-buttons" style="display:none; flex-direction:column; gap:4px; margin-top:6px; border-top:1px solid #f1f5f9; padding-top:4px;"></div>
                            </div>
                            <div id="preview-box-floating-buttons" style="display:none; width:98%; align-self:flex-start; margin-top:4px; flex-direction:column; gap:4px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Campaign Drilldown Details Panel -->
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.02); display:none;" id="panel-campaign-drilldown">
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;" id="drilldown-title">Campaign Drilldown</h3>
                    <button type="button" onclick="closeCampaignDrilldown()" class="btn btn-sm btn-outline" style="font-size:0.75rem;"><i class="fas fa-chevron-left"></i> Back to Dashboard</button>
                </div>
                
                <div style="padding:16px;">
                    <!-- Campaign Control Actions Panel -->
                    <div style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; border-bottom:1px dashed #e2e8f0; padding-bottom:14px;">
                        <input type="hidden" id="drilldown-camp-id">
                        <button type="button" id="btn-ctrl-pause" onclick="triggerCampaignControl('pause')" class="btn btn-sm btn-outline" style="border-radius:6px; border-color:#f59e0b; color:#d97706;"><i class="fas fa-pause"></i> Pause sending</button>
                        <button type="button" id="btn-ctrl-resume" onclick="triggerCampaignControl('resume')" class="btn btn-sm btn-outline" style="border-radius:6px; border-color:#10b981; color:#059669; display:none;"><i class="fas fa-play"></i> Resume sending</button>
                        <button type="button" id="btn-ctrl-cancel" onclick="triggerCampaignControl('cancel')" class="btn btn-sm btn-outline" style="border-radius:6px; border-color:#ef4444; color:#dc2626;"><i class="fas fa-stop"></i> Cancel campaign</button>
                        <button type="button" id="btn-ctrl-retry" onclick="triggerCampaignControl('retry')" class="btn btn-sm btn-success" style="border-radius:6px;"><i class="fas fa-arrow-rotate-forward"></i> Retry Failed dispatches</button>
                    </div>

                    <!-- Statistics Info Grid -->
                    <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-bottom:20px; font-size:0.75rem;" id="drilldown-metrics-grid"></div>

                    <!-- Search Filter Row -->
                    <div style="margin-bottom:12px; display:grid; grid-template-columns: 2fr 1fr; gap:10px;">
                        <input type="text" id="drilldown-search-recip" placeholder="Search by recipient name, phone, or error message..." class="form-control" style="font-size:0.8rem; border-radius:8px;" oninput="filterDrilldownRecipients()">
                        <select id="drilldown-status-filter" class="form-control" style="font-size:0.8rem; border-radius:8px;" onchange="filterDrilldownRecipients()">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="sent">Sent (Meta Dispatched)</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>

                    <!-- Recipients Drilldown Table -->
                    <div style="max-height:320px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:10px;">
                        <table style="width:100%; border-collapse:collapse; font-size:0.78rem; text-align:left;">
                            <thead style="background:#f8fafc; position:sticky; top:0; z-index:10; border-bottom:1px solid #cbd5e1;">
                                <tr>
                                    <th style="padding:10px; font-weight:700;">Recipient Name</th>
                                    <th style="padding:10px; font-weight:700;">WhatsApp Phone</th>
                                    <th style="padding:10px; font-weight:700;">Meta Message ID</th>
                                    <th style="padding:10px; font-weight:700;">Queue Status</th>
                                    <th style="padding:10px; font-weight:700;">Delivery details</th>
                                    <th style="padding:10px; font-weight:700;">Failure Log</th>
                                </tr>
                            </thead>
                            <tbody id="table-body-drilldown-recipients"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Dynamic Preview Confirmation Dialog Overlay -->
<div id="confirm-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5); justify-content:center; align-items:center; backdrop-filter:blur(3px);">
    <div style="background-color:#fff; border-radius:16px; max-width:480px; width:90%; padding:24px; box-shadow:0 10px 40px rgba(0,0,0,0.15); position:relative;">
        <h4 style="margin-top:0; margin-bottom:12px; font-weight:800; color:#1e293b; font-size:1.1rem; border-bottom:1px solid #e2e8f0; padding-bottom:8px;"><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Confirm Campaign Launch</h4>
        
        <p style="font-size:0.82rem; color:#64748b; line-height:1.4; margin-bottom:16px;">You are about to launch a bulk campaign. Messages will be enqueued in the communication queue and processed asynchronously by the background runner.</p>
        
        <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:12px; border-radius:10px; font-size:0.8rem; display:flex; flex-direction:column; gap:6px; margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Campaign:</span><strong id="lbl-confirm-name">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Target Audience:</span><strong id="lbl-confirm-audience">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Total Recipients:</span><strong style="color:#10b981;" id="lbl-confirm-recipients">0</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">WhatsApp Template:</span><strong id="lbl-confirm-template">-</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:#64748b;">Schedule:</span><strong id="lbl-confirm-schedule">-</strong></div>
        </div>

        <div style="display:flex; gap:12px; justify-content:flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeConfirmModal()" style="border-radius:8px;">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitCampaignForm()" style="border-radius:8px; font-weight:700;"><i class="fas fa-rocket"></i> Confirm &amp; Queue Campaign</button>
        </div>
    </div>
</div>

<script>
let currentAudience = 'leads';
let currentTemplateMeta = null;
let eligibleRecipientsList = [];
let calculationValid = false;

// Initialize switcher on page load if query parameter specifies leads target
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('target') === 'leads') {
        switchAudience('leads');
    }
});

function switchAudience(target) {
    currentAudience = target;
    document.getElementById('inp-target-audience').value = target;
    calculationValid = false;
    document.getElementById('btn-submit-campaign').disabled = true;

    if (target === 'leads') {
        document.getElementById('section-target-leads').style.display = 'block';
        document.getElementById('section-target-students').style.display = 'none';
        document.getElementById('btn-tab-leads').style.background = '#f1f5f9';
        document.getElementById('btn-tab-leads').style.color = '#475569';
        document.getElementById('btn-tab-students').style.background = '#fff';
        document.getElementById('btn-tab-students').style.color = '#64748b';
    } else {
        document.getElementById('section-target-leads').style.display = 'none';
        document.getElementById('section-target-students').style.display = 'block';
        document.getElementById('btn-tab-leads').style.background = '#fff';
        document.getElementById('btn-tab-leads').style.color = '#64748b';
        document.getElementById('btn-tab-students').style.background = '#f1f5f9';
        document.getElementById('btn-tab-students').style.color = '#475569';
    }
    triggerAudiencePreview();
}

function toggleLeadCheckboxes(checked) {
    const checkBoxes = document.querySelectorAll('#leads-courses-checklist input[type="checkbox"]');
    checkBoxes.forEach(c => c.checked = checked);
    triggerAudiencePreview();
}

function triggerAudiencePreview() {
    calculationValid = false;
    document.getElementById('btn-submit-campaign').disabled = true;
}

function toggleScheduleBlock(show) {
    document.getElementById('section-schedule-datetime').style.display = show ? 'grid' : 'none';
    document.getElementById('inp-sched-date').required = show;
    document.getElementById('inp-sched-time').required = show;
}

function loadTemplateDetails(tplName) {
    if (!tplName) {
        currentTemplateMeta = null;
        document.getElementById('section-variable-mapping').style.display = 'none';
        document.getElementById('section-media-header').style.display = 'none';
        return;
    }

    fetch(`communication-campaigns.php?action=ajax_get_template&template_name=${encodeURIComponent(tplName)}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                currentTemplateMeta = res.meta;
                renderVariableMappingUI(res.meta);
                renderMediaHeaderUI(res.meta);
                updateVisualCardPreview();
            } else {
                alert(res.message || 'Failed to fetch template.');
            }
        });
}

function renderMediaHeaderUI(meta) {
    const mediaBlock = document.getElementById('section-media-header');
    let headerType = meta.header_type || 'NONE';
    if (headerType === 'NONE' && meta.components) {
        const headerComp = meta.components.find(c => c.type === 'HEADER');
        if (headerComp) {
            headerType = headerComp.format || 'NONE';
        }
    }
    if (headerType !== 'NONE' && headerType !== 'TEXT') {
        mediaBlock.style.display = 'block';
        document.getElementById('inp-media-file').required = true;
    } else {
        mediaBlock.style.display = 'none';
        document.getElementById('inp-media-file').required = false;
        document.getElementById('inp-media-file').value = '';
    }
}

function onMediaFileChange(event) {
    updateVisualCardPreview();
}

function renderVariableMappingUI(meta) {
    const container = document.getElementById('variable-mappings-inputs');
    const panel = document.getElementById('section-variable-mapping');
    container.innerHTML = '';

    // Count variables in template body
    const bodyText = meta.body_text || '';
    const matches = bodyText.match(/\{\{(\d+)\}\}/g);
    const varIndices = matches ? [...new Set(matches.map(m => parseInt(m.replace(/\D/g, ''))))].sort((a,b)=>a-b) : [];

    if (varIndices.length > 0) {
        panel.style.display = 'block';
        varIndices.forEach(idx => {
            const row = document.createElement('div');
            row.style.display = 'grid';
            row.style.gridTemplateColumns = '1.2fr 2fr';
            row.style.gap = '10px';
            row.style.alignItems = 'center';
            row.innerHTML = `
                <span style="font-size:0.75rem; font-weight:700; color:#475569;">Variable {{${idx}}}:</span>
                <div>
                    <select name="vars[${idx}]" class="form-control mapping-select" data-index="${idx}" style="font-size:0.8rem; border-radius:6px; margin-bottom:4px;" onchange="toggleStaticValueInput(${idx}, this.value)" required>
                        <option value="name">Lead/Student Name</option>
                        <option value="interested_course">Course of Interest</option>
                        <option value="whatsapp_number">WhatsApp Phone</option>
                        <option value="last_institute">Last Studied Institute</option>
                        <option value="last_course">Last Studied Course</option>
                        <option value="status">Lead Status</option>
                        <option value="source">Lead Source</option>
                        <option value="assigned_to">Assigned Counselor</option>
                        <option value="static">-- Custom Static Value --</option>
                    </select>
                    <input type="text" name="static_vars[${idx}]" id="inp-static-val-${idx}" class="form-control static-input" placeholder="Enter static text..." style="display:none; font-size:0.75rem; border-radius:6px;" oninput="updateVisualCardPreview()">
                </div>
            `;
            container.appendChild(row);
        });
    } else {
        panel.style.display = 'none';
    }
}

function toggleStaticValueInput(idx, val) {
    const input = document.getElementById(`inp-static-val-${idx}`);
    if (val === 'static') {
        input.style.display = 'block';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
        input.value = '';
    }
    updateVisualCardPreview();
}

function calculatePreview() {
    const formData = new FormData();
    
    if (currentAudience === 'leads') {
        const statuses = Array.from(document.querySelectorAll('.chk-status:checked')).map(c => c.value);
        const courses = Array.from(document.querySelectorAll('.chk-course:checked')).map(c => c.value);

        if (statuses.length === 0 || courses.length === 0) {
            alert('Please select at least one course and status filter.');
            return;
        }

        courses.forEach(c => formData.append('courses[]', c));
        statuses.forEach(s => formData.append('statuses[]', s));
    } else {
        // Handle Students filter defaults
        formData.append('courses[]', document.querySelector('select[name="target_course"]').value);
        formData.append('statuses[]', document.querySelector('select[name="target_status"]').value);
    }

    fetch('communication-campaigns.php?action=ajax_preview_audience', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            eligibleRecipientsList = res.recipients;
            document.getElementById('lbl-matching-leads').innerText = res.total_matching;
            document.getElementById('lbl-duplicates').innerText = res.duplicates;
            document.getElementById('lbl-opted-out').innerText = res.opted_out;
            document.getElementById('lbl-invalid').innerText = res.invalid;
            document.getElementById('lbl-eligible-recipients').innerText = res.eligible_count;

            renderRecipientPreviewTable(res.recipients);
            document.getElementById('panel-audience-preview').style.display = 'block';

            if (res.eligible_count > 0) {
                calculationValid = true;
                document.getElementById('btn-submit-campaign').disabled = false;
            } else {
                calculationValid = false;
                document.getElementById('btn-submit-campaign').disabled = true;
                alert('No eligible recipients match these filters.');
            }
            updateVisualCardPreview();
        }
    });
}

function renderRecipientPreviewTable(recipients) {
    const tbody = document.getElementById('table-body-preview');
    tbody.innerHTML = '';

    if (recipients.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="padding:15px; text-align:center; color:#94a3b8;">No matching leads.</td></tr>`;
        return;
    }

    recipients.forEach(r => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f1f5f9';
        tr.innerHTML = `
            <td style="padding:8px; font-weight:700;">${escapeHtml(r.name)}</td>
            <td style="padding:8px;">${escapeHtml(r.phone)}</td>
            <td style="padding:8px; color:#64748b;">${escapeHtml(r.course)}</td>
            <td style="padding:8px;"><span class="badge gray" style="font-size:0.65rem;">${escapeHtml(r.status)}</span></td>
        `;
        tbody.appendChild(tr);
    });
}

function updateVisualCardPreview() {
    if (!currentTemplateMeta) return;

    // Header Media Block preview
    let hType = currentTemplateMeta.header_type || 'NONE';
    if (hType === 'NONE' && currentTemplateMeta.components) {
        const headerComp = currentTemplateMeta.components.find(c => c.type === 'HEADER');
        if (headerComp) {
            hType = headerComp.format || 'NONE';
        }
    }
    const previewMedia = document.getElementById('preview-box-header-media');
    const previewMediaIcon = document.getElementById('preview-box-media-icon');
    if (hType !== 'NONE' && hType !== 'TEXT') {
        previewMedia.style.display = 'flex';
        if (hType === 'IMAGE') previewMediaIcon.className = 'fas fa-image';
        else if (hType === 'VIDEO') previewMediaIcon.className = 'fas fa-video';
        else if (hType === 'DOCUMENT') previewMediaIcon.className = 'fas fa-file-pdf';
    } else {
        previewMedia.style.display = 'none';
    }

    // Header Text
    const hText = currentTemplateMeta.header_text || '';
    const headerPreview = document.getElementById('preview-box-header');
    if (hType === 'TEXT' && hText.trim()) {
        headerPreview.style.display = 'block';
        headerPreview.innerText = hText;
    } else {
        headerPreview.style.display = 'none';
    }

    // Body text variable mapping resolution
    let bodyText = currentTemplateMeta.body_text || '';
    const sampleLead = eligibleRecipientsList[0] || { name: 'Sample Name', course: 'Psychology' };

    // Resolve mappings
    const selectMappings = document.querySelectorAll('.mapping-select');
    selectMappings.forEach(select => {
        const idx = select.getAttribute('data-index');
        const fieldVal = select.value;
        let finalVal = `{{${idx}}}`;

        if (fieldVal === 'static') {
            finalVal = document.getElementById(`inp-static-val-${idx}`).value || `{{${idx}}}`;
        } else {
            // Mapping from sample lead snapshot
            if (sampleLead) {
                finalVal = sampleLead[fieldVal] || sampleLead.raw_lead?.[fieldVal] || `{{${idx}}}`;
            }
        }
        bodyText = bodyText.split(`{{${idx}}}`).join(finalVal);
    });

    document.getElementById('preview-box-body').innerText = bodyText;

    // Footer Text
    const fText = currentTemplateMeta.footer_text || '';
    const footerPreview = document.getElementById('preview-box-footer');
    if (fText.trim()) {
        footerPreview.style.display = 'block';
        footerPreview.innerText = fText;
    } else {
        footerPreview.style.display = 'none';
    }

    // Buttons Rendering
    const bubbleButtons = document.getElementById('preview-box-bubble-buttons');
    const floatButtons = document.getElementById('preview-box-floating-buttons');
    bubbleButtons.innerHTML = '';
    floatButtons.innerHTML = '';

    const btnType = currentTemplateMeta.button_type || 'NONE';
    if (btnType === 'QUICK_REPLY' && currentTemplateMeta.buttons?.quick_reply) {
        bubbleButtons.style.display = 'flex';
        floatButtons.style.display = 'none';
        Object.values(currentTemplateMeta.buttons.quick_reply).forEach(txt => {
            if (txt) {
                const btn = document.createElement('div');
                btn.style.background = '#f8fafc';
                btn.style.color = '#3b82f6';
                btn.style.padding = '6px';
                btn.style.textAlign = 'center';
                btn.style.borderRadius = '6px';
                btn.style.fontSize = '0.7rem';
                btn.style.fontWeight = '700';
                btn.style.border = '1px solid #e2e8f0';
                let btnText = txt;
                if (typeof txt === 'object' && txt !== null) {
                    btnText = txt.text || '';
                }
                btn.innerText = btnText;
                bubbleButtons.appendChild(btn);
            }
        });
    } else if (btnType === 'CTA' && currentTemplateMeta.buttons) {
        bubbleButtons.style.display = 'none';
        floatButtons.style.display = 'flex';
        const phone = currentTemplateMeta.buttons.phone_text;
        const url = currentTemplateMeta.buttons.url_text;

        if (phone) {
            const btn = document.createElement('div');
            btn.style.background = '#fff';
            btn.style.color = '#00a884';
            btn.style.padding = '8px';
            btn.style.textAlign = 'center';
            btn.style.borderRadius = '6px';
            btn.style.fontSize = '0.7rem';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
            btn.innerHTML = `<i class="fas fa-phone"></i> ${phone}`;
            floatButtons.appendChild(btn);
        }
        if (url) {
            const btn = document.createElement('div');
            btn.style.background = '#fff';
            btn.style.color = '#00a884';
            btn.style.padding = '8px';
            btn.style.textAlign = 'center';
            btn.style.borderRadius = '6px';
            btn.style.fontSize = '0.7rem';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
            btn.innerHTML = `<i class="fas fa-arrow-up-right-from-square"></i> ${url}`;
            floatButtons.appendChild(btn);
        }
    } else {
        bubbleButtons.style.display = 'none';
        floatButtons.style.display = 'none';
    }
}

/* ── Form validations & Confirmation Overlay dialog ── */
function validateFormSubmit(event) {
    event.preventDefault();
    if (!calculationValid) {
        alert('Please calculate and preview your target audience before launching.');
        return false;
    }

    // Modal populate fields
    document.getElementById('lbl-confirm-name').innerText = document.getElementById('inp-campaign-name').value;
    document.getElementById('lbl-confirm-audience').innerText = currentAudience === 'leads' ? 'Leads Segment filters' : 'Students list';
    document.getElementById('lbl-confirm-recipients').innerText = document.getElementById('lbl-eligible-recipients').innerText;
    document.getElementById('lbl-confirm-template').innerText = document.getElementById('sel-template-name').value;

    const isSched = document.querySelector('input[name="schedule_type"]:checked').value === 'schedule';
    if (isSched) {
        document.getElementById('lbl-confirm-schedule').innerText = `${document.getElementById('inp-sched-date').value} at ${document.getElementById('inp-sched-time').value}`;
    } else {
        document.getElementById('lbl-confirm-schedule').innerText = 'Send Immediately (ASAP)';
    }

    document.getElementById('confirm-modal').style.display = 'flex';
    return false;
}

function closeConfirmModal() {
    document.getElementById('confirm-modal').style.display = 'none';
}

function submitCampaignForm() {
    document.getElementById('confirm-modal').style.display = 'none';
    document.getElementById('campaign-create-form').submit();
}

/* ─────────────────────────────────────────────────────────────────────────────
   Campaign Drilldown Statistics details panel
   ───────────────────────────────────────────────────────────────────────────── */
let activeCampaignRecipients = [];

function loadCampaignDrilldown(campId) {
    fetch(`communication-campaigns.php?action=ajax_campaign_details&campaign_id=${campId}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const c = res.campaign;
                activeCampaignRecipients = res.recipients;

                document.getElementById('drilldown-camp-id').value = c.id;
                document.getElementById('drilldown-title').innerText = "Campaign Detail: " + c.name;

                // Adjust Action control buttons
                if (c.status === 'paused') {
                    document.getElementById('btn-ctrl-pause').style.display = 'none';
                    document.getElementById('btn-ctrl-resume').style.display = 'inline-block';
                } else if (c.status === 'completed' || c.status === 'cancelled') {
                    document.getElementById('btn-ctrl-pause').style.display = 'none';
                    document.getElementById('btn-ctrl-resume').style.display = 'none';
                    document.getElementById('btn-ctrl-cancel').style.display = 'none';
                } else {
                    document.getElementById('btn-ctrl-pause').style.display = 'inline-block';
                    document.getElementById('btn-ctrl-resume').style.display = 'none';
                    document.getElementById('btn-ctrl-cancel').style.display = 'inline-block';
                }

                // Render metrics info grid
                const grid = document.getElementById('drilldown-metrics-grid');
                grid.innerHTML = '';

                const total = res.recipients.length;
                const sent = res.recipients.filter(r => r.queue_status === 'sent').length;
                const read = res.recipients.filter(r => r.queue_status === 'read').length;
                const failed = res.recipients.filter(r => r.status === 'failed' || r.queue_status === 'failed').length;

                grid.innerHTML = `
                    <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fafafa; text-align:center;">
                        <strong>${total}</strong><div style="font-size:0.6rem; color:#64748b; margin-top:2px;">AUDIENCE</div>
                    </div>
                    <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fafafa; text-align:center;">
                        <strong style="color:#059669;">${sent}</strong><div style="font-size:0.6rem; color:#64748b; margin-top:2px;">SENT</div>
                    </div>
                    <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fafafa; text-align:center;">
                        <strong style="color:#2563eb;">${read}</strong><div style="font-size:0.6rem; color:#64748b; margin-top:2px;">READ RECEIPTS</div>
                    </div>
                    <div style="border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fafafa; text-align:center;">
                        <strong style="color:#dc2626;">${failed}</strong><div style="font-size:0.6rem; color:#64748b; margin-top:2px;">FAILED</div>
                    </div>
                `;

                // Render recipients table
                renderDrilldownRecipientsTable(activeCampaignRecipients);

                document.getElementById('panel-campaigns-list').style.display = 'none';
                document.getElementById('panel-campaign-drilldown').style.display = 'block';
            } else {
                alert(res.message);
            }
        });
}

function closeCampaignDrilldown() {
    document.getElementById('panel-campaigns-list').style.display = 'block';
    document.getElementById('panel-campaign-drilldown').style.display = 'none';
}

function renderDrilldownRecipientsTable(list) {
    const tbody = document.getElementById('table-body-drilldown-recipients');
    tbody.innerHTML = '';

    if (list.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="padding:15px; text-align:center; color:#94a3b8;">No recipients found.</td></tr>`;
        return;
    }

    list.forEach(r => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f3f4f6';
        
        let qStatusBadge = 'gray';
        if (r.queue_status === 'sent') qStatusBadge = 'green';
        else if (r.queue_status === 'read') qStatusBadge = 'blue';
        else if (r.queue_status === 'failed' || r.status === 'failed') qStatusBadge = 'red';
        else if (r.queue_status === 'processing') qStatusBadge = 'orange';
        else if (r.queue_status === 'delivered') qStatusBadge = 'green';

        tr.innerHTML = `
            <td style="padding:10px; font-weight:700; color:#111827;">${escapeHtml(r.recipient_name)}</td>
            <td style="padding:10px;">${escapeHtml(r.recipient)}</td>
            <td style="padding:10px; color:#64748b; font-size:0.7rem;">
                Msg ID: ${escapeHtml(r.message_id || '-')}<br>
                Queue ID: ${escapeHtml(r.queue_id || '-')}
            </td>
            <td style="padding:10px;">
                <span class="badge ${qStatusBadge}" style="font-size:0.65rem;">${escapeHtml(r.queue_status || r.status || 'pending')}</span><br>
                <small style="color:#64748b;">Retries: ${r.retry_count !== null && r.retry_count !== undefined ? r.retry_count : 0}</small>
            </td>
            <td style="padding:10px; color:#6b7280; font-size:0.7rem;">
                ${r.sent_at ? 'Sent: ' + r.sent_at : ''}
                ${r.delivered_at ? '<br>Delivered: ' + r.delivered_at : ''}
                ${r.read_at ? '<br>Read: ' + r.read_at : ''}
            </td>
            <td style="padding:10px; color:#ef4444; font-size:0.7rem; max-width:200px; word-break:break-all;">
                ${escapeHtml(r.error_message || r.queue_error || '-')}
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function filterDrilldownRecipients() {
    const query = document.getElementById('drilldown-search-recip').value.toLowerCase();
    const statusVal = document.getElementById('drilldown-status-filter').value;

    const filtered = activeCampaignRecipients.filter(r => {
        const matchesQuery = r.recipient_name.toLowerCase().includes(query) || 
                             r.recipient.toLowerCase().includes(query) || 
                             (r.error_message && r.error_message.toLowerCase().includes(query));
        
        const matchesStatus = !statusVal || (r.queue_status === statusVal);
        return matchesQuery && matchesStatus;
    });

    renderDrilldownRecipientsTable(filtered);
}

function triggerCampaignControl(action) {
    const campId = document.getElementById('drilldown-camp-id').value;
    
    if (action === 'cancel' && !confirm('Are you sure you want to cancel all pending messages for this campaign? Already sent messages cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('campaign_id', campId);
    formData.append('control_action', action);
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');

    fetch('communication-campaigns.php?action=ajax_campaign_control', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        alert(res.message);
        if (res.success) {
            loadCampaignDrilldown(campId);
        }
    });
}

// Visual layout helper tools
function escapeHtml(text) {
    if (!text) return '';
    return text
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

<?php include 'includes/admin_footer.php'; ?>
