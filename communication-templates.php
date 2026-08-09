<?php
/**
 * PEPP Learning ERP - WhatsApp Templates Management & Sync Page.
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('communication');

$active_page = 'communication';
$page_title  = 'WhatsApp Templates';
$page_sub    = 'Synchronize and map Meta-approved WhatsApp Cloud API templates';

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

// Load settings for Meta API connection
$stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$businessId  = $settings['whatsapp_business_id'] ?? '';
$accessToken = $settings['whatsapp_access_token'] ?? '';
$apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';

/* ── POST: Sync templates from Meta Cloud API ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_templates') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } elseif (empty($businessId) || empty($accessToken)) {
        $error_message = 'Please configure Business Account ID and Access Token in settings first.';
    } else {
        $url = "https://graph.facebook.com/{$apiVersion}/{$businessId}/message_templates?limit=100";
        $headers = ["Authorization: Bearer {$accessToken}"];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $error_message = "Meta API Connection Error: " . $err;
        } else {
            $data = json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
                $templates = $data['data'];
                $syncedCount = 0;

                $pdo->beginTransaction();
                try {
                    $stmtUpsert = $pdo->prepare("
                        INSERT INTO communication_templates (channel, template_name, language, status, category, meta_data, updated_at) 
                        VALUES ('whatsapp', ?, ?, ?, ?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE status = VALUES(status), category = VALUES(category), meta_data = VALUES(meta_data), updated_at = NOW()
                    ");

                    foreach ($templates as $tpl) {
                        $name = $tpl['name'] ?? '';
                        $lang = $tpl['language'] ?? 'en';
                        $status = strtolower($tpl['status'] ?? 'approved');
                        $category = $tpl['category'] ?? '';
                        
                        // Extract text body and components metadata
                        $bodyText = '';
                        $headerText = '';
                        $footerText = '';
                        foreach ($tpl['components'] ?? [] as $comp) {
                            if (($comp['type'] ?? '') === 'BODY') {
                                $bodyText = $comp['text'] ?? '';
                            } elseif (($comp['type'] ?? '') === 'HEADER') {
                                $headerText = $comp['text'] ?? '';
                            } elseif (($comp['type'] ?? '') === 'FOOTER') {
                                $footerText = $comp['text'] ?? '';
                            }
                        }

                        $metaData = json_encode([
                            'components' => $tpl['components'] ?? [],
                            'body_text' => $bodyText,
                            'header_text' => $headerText,
                            'footer_text' => $footerText
                        ]);

                        $stmtUpsert->execute([$name, $lang, $status, $category, $metaData]);
                        $syncedCount++;
                    }

                    $pdo->commit();
                    $success_message = "Successfully synchronized {$syncedCount} templates from Meta Cloud Account.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_message = "Database Synchronization failed: " . $e->getMessage();
                }
            } else {
                $details = $data['error']['message'] ?? 'Meta API responded with an error.';
                $error_message = "Meta API Error [{$httpCode}]: " . $details;
            }
        }
    }
}

// Load local synchronized templates
$localTemplates = [];
try {
    $localTemplates = $pdo->query("SELECT * FROM communication_templates WHERE channel = 'whatsapp' ORDER BY template_name ASC")->fetchAll();
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
        <a href="communication-templates.php" class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-layer-group"></i> Meta Templates Sync</a>
        <a href="communication-campaigns.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
    </div>

    <!-- Sync Action Widget -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <h3 style="margin:0; font-size:1.1rem; font-weight:700; color:#1f2937;"><i class="fas fa-sync" style="color:#8b5cf6; margin-right:4px;"></i> Synchronize Approved Meta Templates</h3>
            <p style="margin:4px 0 0; font-size:0.8rem; color:#6b7280;">Downloads and syncs all message templates approved in your Facebook Business account.</p>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="sync_templates">
            <button type="submit" class="btn btn-primary" style="padding:10px 20px; font-weight:700; border-radius:8px;">
                <i class="fas fa-arrow-rotate-forward"></i> Sync WhatsApp Templates
            </button>
        </form>
    </div>

    <!-- Templates Table -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden;">
        <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
            <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-list" style="margin-right:4px;"></i> Synchronized Templates (<?php echo count($localTemplates); ?>)</h3>
        </div>
        
        <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                    <th style="padding:12px; font-weight:600; color:#374151;">Template Name</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Category</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Language</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Meta Status</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Preview / Structure</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($localTemplates)): ?>
                    <tr>
                        <td colspan="5" style="padding:30px; text-align:center; color:#9ca3af;"><i class="fas fa-layer-group" style="font-size:1.8rem; display:block; margin-bottom:8px; opacity:0.5;"></i> No templates synchronized. Click the sync button above to import.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($localTemplates as $tpl): ?>
                        <?php 
                            $meta = json_decode($tpl['meta_data'], true); 
                            $bodyText = $meta['body_text'] ?? '';
                            $headerText = $meta['header_text'] ?? '';
                            $footerText = $meta['footer_text'] ?? '';
                        ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px; font-weight:700; color:#111827;"><?php echo htmlspecialchars($tpl['template_name']); ?></td>
                            <td style="padding:12px;"><span class="badge gray" style="font-size:0.7rem; font-weight:700;"><?php echo strtoupper(str_replace('_', ' ', $tpl['category'])); ?></span></td>
                            <td style="padding:12px; font-weight:600;"><?php echo htmlspecialchars($tpl['language']); ?></td>
                            <td style="padding:12px;">
                                <span class="badge <?php echo $tpl['status'] === 'approved' ? 'green' : 'red'; ?>" style="font-size:0.7rem; font-weight:700;">
                                    <?php echo strtoupper($tpl['status']); ?>
                                </span>
                            </td>
                            <td style="padding:12px;">
                                <button type="button" class="btn btn-sm btn-outline" onclick="openPreviewModal('<?php echo htmlspecialchars($tpl['template_name']); ?>')" style="padding:4px 8px; border-radius:6px; font-size:0.75rem;"><i class="fas fa-eye"></i> View Structure</button>
                                
                                <!-- Hidden Preview Content -->
                                <div id="tpl-preview-<?php echo htmlspecialchars($tpl['template_name']); ?>" style="display:none;">
                                    <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-top:12px; font-family:sans-serif; max-width:400px; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
                                        <?php if ($headerText): ?>
                                            <div style="font-weight:700; font-size:0.9rem; color:#111827; margin-bottom:8px; border-bottom:1px dashed #e5e7eb; padding-bottom:4px;"><?php echo htmlspecialchars($headerText); ?></div>
                                        <?php endif; ?>
                                        <div style="font-size:0.85rem; color:#374151; line-height:1.5; white-space:pre-wrap;"><?php echo htmlspecialchars($bodyText); ?></div>
                                        <?php if ($footerText): ?>
                                            <div style="font-size:0.75rem; color:#9ca3af; margin-top:8px; border-top:1px dashed #e5e7eb; padding-top:4px;"><?php echo htmlspecialchars($footerText); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal container for template preview -->
<div id="preview-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.4); justify-content:center; align-items:center;">
    <div style="background-color:#fff; border-radius:16px; max-width:500px; width:90%; padding:20px; box-shadow:0 10px 30px rgba(0,0,0,0.1); position:relative;">
        <span onclick="closePreviewModal()" style="position:absolute; right:15px; top:12px; cursor:pointer; font-size:1.5rem; color:#9ca3af; font-weight:700;">&times;</span>
        <h4 id="modal-title" style="margin-top:0; margin-bottom:15px; font-weight:700; color:#111827;">Template Preview</h4>
        <div id="modal-body" style="margin-bottom:15px;"></div>
        <div style="text-align:right;">
            <button type="button" class="btn btn-outline" onclick="closePreviewModal()" style="border-radius:8px;">Close</button>
        </div>
    </div>
</div>

<script>
function openPreviewModal(tplName) {
    const previewContent = document.getElementById('tpl-preview-' + tplName).innerHTML;
    document.getElementById('modal-title').innerText = "Structure: " + tplName;
    document.getElementById('modal-body').innerHTML = previewContent;
    document.getElementById('preview-modal').style.display = 'flex';
}

function closePreviewModal() {
    document.getElementById('preview-modal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('preview-modal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
