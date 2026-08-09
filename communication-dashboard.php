<?php
/**
 * PEPP Learning ERP - Communication Dashboard & Configuration Settings Page.
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('communication');

$active_page = 'communication';
$page_title  = 'Communication Engine';
$page_sub    = 'Centralized WhatsApp Cloud API & Unified Communication Hub';

$success_message = '';
$error_message   = '';

// Self-healing database structure initialization
$db_installed = true;
try {
    $has_table = (bool)$pdo->query("SHOW TABLES LIKE 'communication_queue'")->fetchColumn();
    if (!$has_table && file_exists(__DIR__ . '/database-update-16.sql')) {
        $sql = file_get_contents(__DIR__ . '/database-update-16.sql');
        $pdo->exec($sql);
        $success_message = 'Database tables for Communication Engine initialized successfully.';
    }
} catch (Exception $e) {
    $db_installed = false;
    $error_message = 'Self-healing database setup failed. Please execute database-update-16.sql in phpMyAdmin. Error: ' . $e->getMessage();
}

// Load settings from admin_settings
$stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

/* ── POST ACTIONS ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_settings') {
            $pdo->beginTransaction();
            try {
                $keys = [
                    'whatsapp_business_id',
                    'whatsapp_phone_id',
                    'whatsapp_access_token',
                    'whatsapp_app_secret',
                    'whatsapp_webhook_verify_token',
                    'whatsapp_api_version'
                ];
                
                $saveStmt = $pdo->prepare("
                    INSERT INTO admin_settings (setting_name, setting_value, updated_at) 
                    VALUES (?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                
                foreach ($keys as $k) {
                    $val = trim($_POST[$k] ?? '');
                    $saveStmt->execute([$k, $val]);
                }
                
                $pdo->commit();
                $success_message = 'Communication configuration settings saved successfully.';
                
                // Reload settings
                $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
                $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = 'Database error: ' . $e->getMessage();
            }
        } elseif ($action === 'test_send') {
            $testPhone = trim($_POST['test_phone'] ?? '');
            $testMsg = trim($_POST['test_message'] ?? 'PEPP Learning ERP - WhatsApp Connection test successful! ✓');
            
            if (!$testPhone) {
                $error_message = 'Please specify a test phone number.';
            } else {
                try {
                    require_once 'includes/communication/CommunicationEngine.php';
                    $engine = CommunicationEngine::getInstance($pdo);
                    
                    // Directly queue the test message
                    $queueId = $engine->queueMessage(
                        'whatsapp',
                        $testPhone,
                        'Test Recipient',
                        'ERP Connection Test',
                        $testMsg,
                        $testMsg,
                        [],
                        [],
                        $admin_username
                    );
                    
                    // Run it synchronously right now
                    $dispatched = $engine->processQueueItem($queueId);
                    
                    if ($dispatched) {
                        $success_message = 'Test message successfully queued and dispatched via Meta Cloud API!';
                    } else {
                        // Retrieve the error log message
                        $errStmt = $pdo->prepare("SELECT error_message FROM communication_queue WHERE id = ?");
                        $errStmt->execute([$queueId]);
                        $failReason = $errStmt->fetchColumn() ?: 'Unknown error during connection dispatch';
                        $error_message = 'Dispatch failed: ' . $failReason;
                    }
                } catch (Exception $e) {
                    $error_message = 'Execution error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Stats & Metrics Queries
$stats = [
    'pending' => 0, 'processing' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'total' => 0
];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM communication_queue GROUP BY status")->fetchAll() as $row) {
        $stats[$row['status']] = (int)$row['c'];
    }
    $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();
} catch (Exception $ex) {}

// Webhook log count
$webhookCount = 0;
try {
    $webhookCount = (int)$pdo->query("SELECT COUNT(*) FROM communication_webhook_events")->fetchColumn();
} catch (Exception $ex) {}

// Load recent 10 logs
$recentLogs = [];
try {
    $recentLogs = $pdo->query("
        SELECT id, channel, recipient, status, retry_count, next_attempt_at, error_message, updated_at 
        FROM communication_queue 
        ORDER BY id DESC LIMIT 10
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

    <!-- ── KPI METRICS GRID ── -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-size:0.8rem; color:#6b7280; font-weight:600;">Total Enqueued</span>
                <span style="background:rgba(139, 92, 246, 0.1); color:#8b5cf6; padding:4px; border-radius:8px;"><i class="fas fa-paper-plane"></i></span>
            </div>
            <div style="font-size:1.8rem; font-weight:800; color:#111827;"><?php echo number_format($stats['total']); ?></div>
            <div style="font-size:0.75rem; color:#9ca3af; margin-top:2px;">All outgoing channels</div>
        </div>

        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-size:0.8rem; color:#6b7280; font-weight:600;">Pending/Processing</span>
                <span style="background:rgba(245, 158, 11, 0.1); color:#f59e0b; padding:4px; border-radius:8px;"><i class="fas fa-clock"></i></span>
            </div>
            <div style="font-size:1.8rem; font-weight:800; color:#111827;"><?php echo number_format($stats['pending'] + $stats['processing']); ?></div>
            <div style="font-size:0.75rem; color:#9ca3af; margin-top:2px;">Waiting in sending queue</div>
        </div>

        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-size:0.8rem; color:#6b7280; font-weight:600;">Delivered / Read</span>
                <span style="background:rgba(34, 197, 94, 0.1); color:#22c55e; padding:4px; border-radius:8px;"><i class="fas fa-envelope-open"></i></span>
            </div>
            <div style="font-size:1.8rem; font-weight:800; color:#111827;">
                <?php echo number_format($stats['delivered'] + $stats['read']); ?>
            </div>
            <div style="font-size:0.75rem; color:#9ca3af; margin-top:2px;">Confirmed receipts from Meta</div>
        </div>

        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-size:0.8rem; color:#6b7280; font-weight:600;">Failed Dispatches</span>
                <span style="background:rgba(239, 68, 68, 0.1); color:#ef4444; padding:4px; border-radius:8px;"><i class="fas fa-triangle-exclamation"></i></span>
            </div>
            <div style="font-size:1.8rem; font-weight:800; color:#111827;"><?php echo number_format($stats['failed']); ?></div>
            <div style="font-size:0.75rem; color:#ef4444; margin-top:2px; font-weight:600;">Needs verification &amp; retry</div>
        </div>
    </div>

    <!-- ── NAVIGATION TABS ── -->
    <div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid #e5e7eb; padding-bottom:8px;">
        <a href="communication-dashboard.php" class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-gears"></i> API Settings &amp; Queue</a>
        <a href="communication-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-layer-group"></i> Meta Templates Sync</a>
        <a href="communication-campaigns.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
    </div>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px; align-items:start;">
        <!-- Left: API Config Panel -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden;">
            <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fab fa-whatsapp" style="color:#25D366; margin-right:4px;"></i> Meta WhatsApp Cloud API Settings</h3>
                <span class="badge <?php echo (!empty($settings['whatsapp_phone_id']) && !empty($settings['whatsapp_access_token'])) ? 'green' : 'gray'; ?>">
                    <?php echo (!empty($settings['whatsapp_phone_id']) && !empty($settings['whatsapp_access_token'])) ? 'CONFIGURED' : 'UNCONFIGURED'; ?>
                </span>
            </div>
            
            <div style="padding:20px;">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_settings">
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">WhatsApp Phone Number ID</label>
                            <input type="text" name="whatsapp_phone_id" value="<?php echo htmlspecialchars($settings['whatsapp_phone_id'] ?? ''); ?>" placeholder="e.g. 10482939281829" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" required>
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">WhatsApp Business Account ID</label>
                            <input type="text" name="whatsapp_business_id" value="<?php echo htmlspecialchars($settings['whatsapp_business_id'] ?? ''); ?>" placeholder="e.g. 10283928471829" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" required>
                        </div>
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Permanent Access Token</label>
                        <textarea name="whatsapp_access_token" rows="3" placeholder="Enter System User Token..." style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem; font-family:monospace;" required><?php echo htmlspecialchars($settings['whatsapp_access_token'] ?? ''); ?></textarea>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Webhook Verification Token</label>
                            <input type="text" name="whatsapp_webhook_verify_token" value="<?php echo htmlspecialchars($settings['whatsapp_webhook_verify_token'] ?? 'pepp_verify_token_2026'); ?>" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" required>
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Meta App Secret</label>
                            <input type="password" name="whatsapp_app_secret" value="<?php echo htmlspecialchars($settings['whatsapp_app_secret'] ?? ''); ?>" placeholder="Used for payload signature checking" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                        </div>
                    </div>

                    <div style="margin-bottom:20px; width:50%;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Meta Graph API Version</label>
                        <select name="whatsapp_api_version" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                            <option value="v20.0" <?php echo ($settings['whatsapp_api_version'] ?? 'v20.0') === 'v20.0' ? 'selected' : ''; ?>>v20.0 (Recommended)</option>
                            <option value="v19.0" <?php echo ($settings['whatsapp_api_version'] ?? '') === 'v19.0' ? 'selected' : ''; ?>>v19.0</option>
                            <option value="v18.0" <?php echo ($settings['whatsapp_api_version'] ?? '') === 'v18.0' ? 'selected' : ''; ?>>v18.0</option>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #e5e7eb; padding-top:14px;">
                        <span style="font-size:0.8rem; color:#6b7280;"><i class="fas fa-link"></i> Webhook Callback URL: <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-family:monospace; font-size:0.75rem;">https://pepplearning.in/admissions/api/v1/communication/webhook.php</code></span>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save API Config</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right: Diagnostic Connection Check & Quick Test Send -->
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden;">
                <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-circle-nodes" style="color:#8b5cf6;"></i> Quick Connection Check</h3>
                </div>
                <div style="padding:20px;">
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="test_send">
                        
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Recipient Phone Number</label>
                            <input type="text" name="test_phone" placeholder="e.g. 917025381915" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;" required>
                        </div>
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Message Text</label>
                            <textarea name="test_message" rows="2" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">PEPP Learning ERP - Meta WhatsApp Connection test successful! ✓</textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-outline" style="width:100%; border-radius:8px;"><i class="fas fa-paper-plane"></i> Send Test Message</button>
                    </form>
                </div>
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; text-align:center;">
                <div style="font-size:1.1rem; font-weight:700; color:#1f2937; margin-bottom:6px;"><i class="fas fa-satellite-dish" style="color:#22c55e;"></i> Webhook Stats</div>
                <div style="font-size:2.2rem; font-weight:800; color:#1f2937;"><?php echo number_format($webhookCount); ?></div>
                <div style="font-size:0.8rem; color:#6b7280; margin-top:2px;">Events received &amp; logged</div>
            </div>
        </div>
    </div>

    <!-- ── RECENT DISPATCHES QUEUE LOGS ── -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; margin-top:24px; margin-bottom:20px;">
        <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-list-check" style="margin-right:4px;"></i> Recent Dispatches Queue Log (Top 10)</h3>
            <span style="font-size:0.75rem; color:#6b7280;">Cron updates automatically every minute</span>
        </div>
        
        <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                    <th style="padding:12px; font-weight:600; color:#374151;">Queue ID</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Recipient</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Channel</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Updated At</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Retries</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Status</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Meta Delivery Log</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr>
                        <td colspan="7" style="padding:20px; text-align:center; color:#9ca3af;">No messages have been enqueued yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:12px; font-weight:600;">#<?php echo $log['id']; ?></td>
                            <td style="padding:12px; font-weight:600;"><?php echo htmlspecialchars($log['recipient']); ?></td>
                            <td style="padding:12px;"><span class="badge blue" style="font-size:0.7rem; font-weight:700;"><?php echo strtoupper($log['channel']); ?></span></td>
                            <td style="padding:12px; color:#6b7280;"><?php echo date('d M Y, h:i A', strtotime($log['updated_at'])); ?></td>
                            <td style="padding:12px; font-weight:600;"><?php echo $log['retry_count']; ?>/3</td>
                            <td style="padding:12px;">
                                <span class="badge <?php 
                                    echo $log['status'] === 'read' || $log['status'] === 'delivered' || $log['status'] === 'sent' ? 'green' : 
                                         ($log['status'] === 'failed' ? 'red' : 'amber'); 
                                ?>">
                                    <?php echo strtoupper($log['status']); ?>
                                </span>
                            </td>
                            <td style="padding:12px; color:#ef4444; max-width:320px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($log['error_message'] ?? ''); ?>">
                                <?php echo $log['error_message'] ? htmlspecialchars($log['error_message']) : '<span style="color:#9ca3af;">-</span>'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
