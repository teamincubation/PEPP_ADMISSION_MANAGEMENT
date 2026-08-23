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

require_once 'includes/communication/CommunicationEngine.php';
$isPaused = CommunicationEngine::getInstance($pdo)->isQueuePaused();

if (empty($settings['whatsapp_cron_worker_key'])) {
    $genKey = bin2hex(random_bytes(16));
    try {
        $insStmt = $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('whatsapp_cron_worker_key', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
        $insStmt->execute([$genKey]);
        $settings['whatsapp_cron_worker_key'] = $genKey;
    } catch (Exception $e) {}
}

/* ── POST ACTIONS ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_settings') {
            $pdo->beginTransaction();
            try {
                $cronKey = trim($_POST['whatsapp_cron_worker_key'] ?? '');
                if (empty($cronKey)) {
                    $cronKey = $settings['whatsapp_cron_worker_key'] ?? bin2hex(random_bytes(16));
                }
                $_POST['whatsapp_cron_worker_key'] = $cronKey;

                $keys = [
                    'whatsapp_business_id',
                    'whatsapp_phone_id',
                    'whatsapp_access_token',
                    'whatsapp_app_secret',
                    'whatsapp_webhook_verify_token',
                    'whatsapp_cron_worker_key',
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
        } elseif ($action === 'switch_whatsapp_mode') {
            $new_mode = $_POST['new_mode'] ?? '';
            if (!in_array($new_mode, ['meta_api', 'manual'], true)) {
                $error_message = 'Invalid mode specified.';
            } else {
                try {
                    $old_mode = whatsapp_outbound_mode($pdo);
                    if ($old_mode === $new_mode) {
                        $error_message = 'Already in ' . strtoupper(str_replace('_', ' ', $new_mode)) . ' mode.';
                    } else {
                        // Transactional mode switch: audit + settings as a single atomic operation
                        $pdo->beginTransaction();

                        // Insert audit record
                        $auditStmt = $pdo->prepare("INSERT INTO whatsapp_mode_audit (old_mode, new_mode, changed_by, changed_at) VALUES (?, ?, ?, NOW())");
                        $auditStmt->execute([$old_mode, $new_mode, $admin_username]);

                        // Update the setting
                        $updateStmt = $pdo->prepare("
                            INSERT INTO admin_settings (setting_name, setting_value, updated_at) 
                            VALUES ('whatsapp_outbound_mode', ?, NOW()) 
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                        ");
                        $updateStmt->execute([$new_mode]);

                        $pdo->commit();

                        // Reset the static cache so the page reflects the new mode
                        $settings['whatsapp_outbound_mode'] = $new_mode;

                        $mode_label = $new_mode === 'meta_api' ? 'META API' : 'MANUAL';
                        $success_message = "Outbound WhatsApp mode switched to {$mode_label}.";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error_message = 'Failed to switch mode: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'pause_queue') {
            require_once 'includes/communication/CommunicationEngine.php';
            CommunicationEngine::getInstance($pdo)->setQueuePaused(true);
            $success_message = "Global WhatsApp communication queue PAUSED successfully.";
            $isPaused = true;
        } elseif ($action === 'resume_queue') {
            require_once 'includes/communication/CommunicationEngine.php';
            $engine = CommunicationEngine::getInstance($pdo);
            $engine->setQueuePaused(false);
            $success_message = "Global WhatsApp communication queue RESUMED successfully.";
            $isPaused = false;
            $engine->triggerCronBackground();
        } elseif ($action === 'pause_queue_item') {
            $queueId = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : 0;
            if ($queueId > 0) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT status FROM communication_queue WHERE id = ? FOR UPDATE");
                    $stmt->execute([$queueId]);
                    $status = $stmt->fetchColumn();
                    if ($status && in_array($status, ['pending', 'scheduled', 'failed'], true)) {
                        $upd = $pdo->prepare("UPDATE communication_queue SET status = 'paused', updated_at = NOW() WHERE id = ?");
                        $upd->execute([$queueId]);
                        
                        try {
                            $updRemStmt = $pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'paused' WHERE queue_id = ?");
                            $updRemStmt->execute([$queueId]);
                        } catch (Exception $remEx) {}

                        $pdo->commit();
                        $success_message = "Queue item #{$queueId} paused successfully.";
                    } else {
                        $pdo->rollBack();
                        $error_message = "Cannot pause queue item #{$queueId} (current status: " . ($status ?: 'unknown') . ").";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error_message = 'Failed to pause item: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'resume_queue_item') {
            $queueId = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : 0;
            if ($queueId > 0) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT status FROM communication_queue WHERE id = ? FOR UPDATE");
                    $stmt->execute([$queueId]);
                    $status = $stmt->fetchColumn();
                    if ($status === 'paused') {
                        $upd = $pdo->prepare("UPDATE communication_queue SET status = 'pending', next_attempt_at = NOW(), updated_at = NOW() WHERE id = ?");
                        $upd->execute([$queueId]);
                        
                        try {
                            $updRemStmt = $pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'queued' WHERE queue_id = ?");
                            $updRemStmt->execute([$queueId]);
                        } catch (Exception $remEx) {}

                        $pdo->commit();
                        $success_message = "Queue item #{$queueId} resumed successfully.";
                        
                        // Trigger background processing instantly
                        require_once 'includes/communication/CommunicationEngine.php';
                        CommunicationEngine::getInstance($pdo)->triggerCronBackground();
                    } else {
                        $pdo->rollBack();
                        $error_message = "Cannot resume queue item #{$queueId} (current status: " . ($status ?: 'unknown') . ").";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error_message = 'Failed to resume item: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'cancel_queue_item') {
            $queueId = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : 0;
            if ($queueId > 0) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT status FROM communication_queue WHERE id = ? FOR UPDATE");
                    $stmt->execute([$queueId]);
                    $status = $stmt->fetchColumn();
                    if ($status && !in_array($status, ['sent', 'delivered', 'read', 'cancelled'], true)) {
                        $reason = trim($_POST['cancel_reason'] ?? 'No longer required');
                        $upd = $pdo->prepare("UPDATE communication_queue SET status = 'cancelled', next_attempt_at = '2038-01-01 00:00:00', error_message = ?, updated_at = NOW() WHERE id = ?");
                        $upd->execute(['Cancelled: ' . $reason, $queueId]);
                        
                        try {
                            $updCampStmt = $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = ? WHERE queue_id = ?");
                            $updCampStmt->execute(['Cancelled: ' . $reason, $queueId]);
                        } catch (Exception $campEx) {}

                        try {
                            $updRemStmt = $pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'failed' WHERE queue_id = ?");
                            $updRemStmt->execute([$queueId]);
                        } catch (Exception $remEx) {}

                        $pdo->commit();
                        $success_message = "Queue item #{$queueId} cancelled successfully.";
                    } else {
                        $pdo->rollBack();
                        $error_message = "Cannot cancel queue item #{$queueId} (current status: " . ($status ?: 'unknown') . ").";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error_message = 'Failed to cancel item: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'retry_queue_item') {
            $queueId = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : 0;
            if ($queueId > 0) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT status FROM communication_queue WHERE id = ? FOR UPDATE");
                    $stmt->execute([$queueId]);
                    $status = $stmt->fetchColumn();
                    if (in_array($status, ['failed', 'cancelled'], true)) {
                        $upd = $pdo->prepare("UPDATE communication_queue SET status = 'pending', retry_count = 0, next_attempt_at = NOW(), error_message = NULL, updated_at = NOW() WHERE id = ?");
                        $upd->execute([$queueId]);
                        
                        try {
                            $updRemStmt = $pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'queued' WHERE queue_id = ?");
                            $updRemStmt->execute([$queueId]);
                        } catch (Exception $remEx) {}

                        $pdo->commit();
                        $success_message = "Queue item #{$queueId} re-queued for retry successfully.";
                        
                        // Trigger background processing instantly
                        require_once 'includes/communication/CommunicationEngine.php';
                        CommunicationEngine::getInstance($pdo)->triggerCronBackground();
                    } else {
                        $pdo->rollBack();
                        $error_message = "Cannot retry queue item #{$queueId} (current status: " . ($status ?: 'unknown') . ").";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error_message = 'Failed to retry item: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'process_queue') {
            require_once 'includes/communication/CommunicationEngine.php';
            $engine = CommunicationEngine::getInstance($pdo);
            if ($engine->isQueuePaused()) {
                $error_message = 'Cannot process queue: The queue is currently paused.';
            } else {
            $currentTime = time();
            $lastProcessed = $_SESSION['last_queue_process_at'] ?? 0;
            if ($currentTime - $lastProcessed < 10) {
                $error_message = 'Rate limit exceeded. Please wait at least 10 seconds between queue processing runs.';
            } else {
                $_SESSION['last_queue_process_at'] = $currentTime;
                try {
                    // Claim and identify the IDs we are about to process
                    $stmtIds = $pdo->prepare("
                        SELECT id FROM communication_queue 
                        WHERE status IN ('pending', 'failed') 
                          AND next_attempt_at <= NOW() 
                          AND retry_count < 3
                        ORDER BY priority DESC, created_at ASC 
                        LIMIT 25
                    ");
                    $stmtIds->execute();
                    $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (empty($ids)) {
                        $success_message = 'No pending queue messages are currently due for dispatch.';
                    } else {
                        $processed = 0;
                        $failed_count = 0;
                        
                        foreach ($ids as $queueId) {
                            $success = $engine->processQueueItem($queueId);
                            if ($success) {
                                $processed++;
                            } else {
                                $failed_count++;
                            }
                        }
                        
                        $success_message = "Queue run complete: Successfully processed {$processed} messages" . ($failed_count > 0 ? ", failed/skipped {$failed_count} messages." : ".");
                    }
                } catch (Exception $e) {
                    $error_message = 'Queue processing failed: ' . $e->getMessage();
                }
            }
            }
        }
    }
}

// Stats & Metrics Queries
$view = $_GET['view'] ?? 'all';
if ($view !== 'installments') $view = 'all';

$stats = [
    'pending' => 0, 'processing' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'total' => 0
];
try {
    if ($view === 'installments') {
        $stmtStats = $pdo->prepare("
            SELECT cq.status, COUNT(*) c 
            FROM communication_queue cq 
            INNER JOIN installment_whatsapp_reminders r ON r.queue_id = cq.id 
            GROUP BY cq.status
        ");
        $stmtStats->execute();
        foreach ($stmtStats->fetchAll() as $row) {
            $stats[$row['status']] = (int)$row['c'];
        }
        $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue cq INNER JOIN installment_whatsapp_reminders r ON r.queue_id = cq.id")->fetchColumn();
    } else {
        foreach ($pdo->query("SELECT status, COUNT(*) c FROM communication_queue GROUP BY status")->fetchAll() as $row) {
            $stats[$row['status']] = (int)$row['c'];
        }
        $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();
    }
} catch (Exception $ex) {}

// Webhook log count
$webhookCount = 0;
try {
    $webhookCount = (int)$pdo->query("SELECT COUNT(*) FROM communication_webhook_events")->fetchColumn();
} catch (Exception $ex) {}

// Search and Filter variables
$filter_status = isset($_GET['queue_status']) ? trim($_GET['queue_status']) : '';
$filter_search = isset($_GET['queue_search']) ? trim($_GET['queue_search']) : '';

$whereClauses = [];
$whereParams = [];

if ($filter_status !== '') {
    $whereClauses[] = "cq.status = :status_filter";
    $whereParams[':status_filter'] = $filter_status;
}

if ($filter_search !== '') {
    if ($view === 'installments') {
        $whereClauses[] = "(cq.recipient LIKE :search_filter OR cq.recipient_name LIKE :search_filter OR u.name LIKE :search_filter OR cq.id = :search_exact_id)";
    } else {
        $whereClauses[] = "(cq.recipient LIKE :search_filter OR cq.recipient_name LIKE :search_filter OR cq.id = :search_exact_id OR cq.event_name LIKE :search_filter)";
    }
    $whereParams[':search_filter'] = '%' . $filter_search . '%';
    $whereParams[':search_exact_id'] = is_numeric($filter_search) ? (int)$filter_search : 0;
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = ' WHERE ' . implode(' AND ', $whereClauses);
}

// Load paginated logs
$perPage = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

try {
    if ($view === 'installments') {
        $countSql = "
            SELECT COUNT(*) 
            FROM communication_queue cq
            INNER JOIN installment_whatsapp_reminders r ON r.queue_id = cq.id
            LEFT JOIN instalment_details inst ON inst.id = r.installment_id
            LEFT JOIN users u ON u.user_id = inst.user_id
            $whereSql
        ";
    } else {
        $countSql = "
            SELECT COUNT(*) 
            FROM communication_queue cq
            $whereSql
        ";
    }
    $countStmt = $pdo->prepare($countSql);
    foreach ($whereParams as $paramKey => $paramVal) {
        if ($paramKey === ':search_exact_id') {
            $countStmt->bindValue($paramKey, $paramVal, PDO::PARAM_INT);
        } else {
            $countStmt->bindValue($paramKey, $paramVal, PDO::PARAM_STR);
        }
    }
    $countStmt->execute();
    $totalRecords = (int)$countStmt->fetchColumn();
} catch (Exception $ex) {
    $totalRecords = 0;
}

$totalPages = ($totalRecords > 0) ? (int)ceil($totalRecords / $perPage) : 1;

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$recentLogs = [];
try {
    if ($view === 'installments') {
        $querySql = "
            SELECT cq.id AS queue_id,
                   cq.recipient,
                   cq.recipient_name,
                   cq.status AS queue_status,
                   cq.retry_count,
                   cq.error_message,
                   cq.updated_at,
                   cq.message_id,
                   r.reminder_stage,
                   r.status AS tracking_status,
                   r.last_attempted_at,
                   inst.instalment_number,
                   inst.amount AS installment_amount,
                   inst.due_date AS installment_due_date,
                   u.name AS student_name,
                   u.user_id AS student_uid
            FROM communication_queue cq
            INNER JOIN installment_whatsapp_reminders r ON r.queue_id = cq.id
            LEFT JOIN instalment_details inst ON inst.id = r.installment_id
            LEFT JOIN users u ON u.user_id = inst.user_id
            $whereSql
            ORDER BY cq.created_at DESC, cq.id DESC
            LIMIT :limit OFFSET :offset
        ";
    } else {
        $querySql = "
            SELECT cq.id, cq.channel, cq.recipient, cq.recipient_name, cq.status, cq.retry_count, cq.next_attempt_at, cq.error_message, cq.updated_at, cq.student_uid, cq.event_name, cq.invoice_id, cq.message_id
            FROM communication_queue cq
            $whereSql
            ORDER BY cq.created_at DESC, cq.id DESC
            LIMIT :limit OFFSET :offset
        ";
    }
    $stmtLogs = $pdo->prepare($querySql);
    foreach ($whereParams as $paramKey => $paramVal) {
        if ($paramKey === ':search_exact_id') {
            $stmtLogs->bindValue($paramKey, $paramVal, PDO::PARAM_INT);
        } else {
            $stmtLogs->bindValue($paramKey, $paramVal, PDO::PARAM_STR);
        }
    }
    $stmtLogs->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
    $stmtLogs->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmtLogs->execute();
    $recentLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {
    error_log("Communication Dashboard Pagination Error: " . $ex->getMessage());
}

// Helper to preserve active query parameters in pagination URLs
function getPageUrl($pageNum) {
    $params = $_GET;
    $params['page'] = $pageNum;
    return '?' . http_build_query($params);
}

// Fetch current outbound mode (re-read from DB to reflect any switch made above)
$current_mode = $settings['whatsapp_outbound_mode'] ?? whatsapp_outbound_mode($pdo);

// Fetch mode audit log (last 5)
$modeAuditLog = [];
try {
    $modeAuditLog = $pdo->query("SELECT * FROM whatsapp_mode_audit ORDER BY id DESC LIMIT 5")->fetchAll();
} catch (Exception $ex) {}

include 'includes/admin_nav.php';
?>

<div class="container-fluid" style="padding:20px;">
    <?php if ($isPaused): ?>
        <div class="alert alert-warning" style="background:#fffbeb; border:1px solid #fde047; color:#854d0e; padding:12px 18px; border-radius:12px; margin-bottom:20px; font-weight:600;">
            <i class="fas fa-pause-circle" style="color:#d97706; margin-right:4px;"></i> WhatsApp Queue is currently PAUSED. No queued messages will be automatically dispatched.
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:12px 18px; border-radius:12px; margin-bottom:20px;">
            <i class="fas fa-circle-check"></i> <?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger" style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 18px; border-radius:12px; margin-bottom:20px;">
            <i class="fas fa-circle-xmark"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- ── OUTBOUND WHATSAPP MODE TOGGLE ── -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; margin-bottom:24px;">
        <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-toggle-on" style="color:#8b5cf6; margin-right:4px;"></i> Outbound WhatsApp Messaging Mode</h3>
            <span class="badge <?php echo $current_mode === 'meta_api' ? 'green' : 'amber'; ?>" style="font-size:0.8rem; font-weight:700;">
                <?php echo $current_mode === 'meta_api' ? 'META API' : 'MANUAL'; ?>
            </span>
        </div>
        <div style="padding:20px;">
            <div style="display:flex; gap:12px; margin-bottom:16px;">
                <!-- META API Option -->
                <form method="POST" style="flex:1;" id="form-switch-meta">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="switch_whatsapp_mode">
                    <input type="hidden" name="new_mode" value="meta_api">
                    <button type="button" onclick="confirmModeSwitch('meta_api')" style="width:100%; padding:16px; border-radius:12px; cursor:pointer; font-weight:700; font-size:0.9rem; transition:all 0.2s;
                        <?php if ($current_mode === 'meta_api'): ?>
                            background:linear-gradient(135deg, #dcfce7, #bbf7d0); border:2px solid #22c55e; color:#166534;
                        <?php else: ?>
                            background:#f9fafb; border:2px solid #e5e7eb; color:#6b7280;
                        <?php endif; ?>">
                        <i class="fas fa-robot" style="margin-right:4px;"></i> META API
                        <?php if ($current_mode === 'meta_api'): ?>
                            <span style="display:block; font-size:0.75rem; font-weight:500; margin-top:4px;">✓ Active — Automated outbound via Meta Cloud API</span>
                        <?php else: ?>
                            <span style="display:block; font-size:0.75rem; font-weight:500; margin-top:4px;">Click to enable automated Meta messaging</span>
                        <?php endif; ?>
                    </button>
                </form>

                <!-- MANUAL Option -->
                <form method="POST" style="flex:1;" id="form-switch-manual">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="switch_whatsapp_mode">
                    <input type="hidden" name="new_mode" value="manual">
                    <button type="button" onclick="confirmModeSwitch('manual')" style="width:100%; padding:16px; border-radius:12px; cursor:pointer; font-weight:700; font-size:0.9rem; transition:all 0.2s;
                        <?php if ($current_mode === 'manual'): ?>
                            background:linear-gradient(135deg, #fef3c7, #fde68a); border:2px solid #f59e0b; color:#92400e;
                        <?php else: ?>
                            background:#f9fafb; border:2px solid #e5e7eb; color:#6b7280;
                        <?php endif; ?>">
                        <i class="fas fa-hand" style="margin-right:4px;"></i> MANUAL
                        <?php if ($current_mode === 'manual'): ?>
                            <span style="display:block; font-size:0.75rem; font-weight:500; margin-top:4px;">✓ Active — Manual wa.me links for outbound messaging</span>
                        <?php else: ?>
                            <span style="display:block; font-size:0.75rem; font-weight:500; margin-top:4px;">Click to switch to manual messaging</span>
                        <?php endif; ?>
                    </button>
                </form>
            </div>

            <div style="background:#f1f5f9; border-radius:8px; padding:10px 14px; font-size:0.78rem; color:#475569; margin-bottom:12px;">
                <i class="fas fa-circle-info" style="color:#3b82f6;"></i>
                <strong>META API</strong>: Automated outbound via CommunicationEngine + Meta Cloud API. Manual wa.me buttons hidden.
                <strong>MANUAL</strong>: Existing manual wa.me workflows. Automated Meta outbound disabled.
                <br><em>Inbound messages, WhatsApp Inbox, webhook, and auto-response CTA are always active regardless of mode.</em>
            </div>

            <?php if (!empty($modeAuditLog)): ?>
            <div style="font-size:0.8rem;">
                <div style="font-weight:700; color:#374151; margin-bottom:6px;"><i class="fas fa-clock-rotate-left"></i> Mode Change History</div>
                <table style="width:100%; border-collapse:collapse; font-size:0.78rem;">
                    <thead><tr style="background:#f9fafb; border-bottom:1px solid #e5e7eb;">
                        <th style="padding:6px 10px; text-align:left; color:#6b7280; font-weight:600;">Date</th>
                        <th style="padding:6px 10px; text-align:left; color:#6b7280; font-weight:600;">Change</th>
                        <th style="padding:6px 10px; text-align:left; color:#6b7280; font-weight:600;">By</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($modeAuditLog as $alog): ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:6px 10px; color:#6b7280;"><?php echo date('d M Y, h:i A', strtotime($alog['changed_at'])); ?></td>
                            <td style="padding:6px 10px;">
                                <span class="badge <?php echo $alog['old_mode'] === 'meta_api' ? 'green' : 'amber'; ?>" style="font-size:0.65rem;"><?php echo strtoupper(str_replace('_', ' ', $alog['old_mode'])); ?></span>
                                <i class="fas fa-arrow-right" style="color:#9ca3af; font-size:0.6rem; margin:0 4px;"></i>
                                <span class="badge <?php echo $alog['new_mode'] === 'meta_api' ? 'green' : 'amber'; ?>" style="font-size:0.65rem;"><?php echo strtoupper(str_replace('_', ' ', $alog['new_mode'])); ?></span>
                            </td>
                            <td style="padding:6px 10px; font-weight:600; color:#374151;"><?php echo htmlspecialchars($alog['changed_by']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function confirmModeSwitch(mode) {
        var currentMode = '<?php echo $current_mode; ?>';
        if (mode === currentMode) { return; }
        var msg = '';
        if (mode === 'manual') {
            msg = 'Switching to MANUAL mode will stop all automated outbound WhatsApp messages through Meta Cloud API.\n\nInbound messages and the WhatsApp Inbox will continue working.\n\nAre you sure?';
        } else {
            msg = 'Switching to META API mode will enable automated outbound WhatsApp through Meta Cloud API.\n\nManual wa.me links will be hidden where automated messaging is available.\n\nAre you sure?';
        }
        if (confirm(msg)) {
            document.getElementById('form-switch-' + mode).submit();
        }
    }
    </script>

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

    <!-- ── CRON HEALTH STATUS CARD ── -->
    <?php
    $lastCron = null;
    if (!empty($settings['whatsapp_last_cron_run'])) {
        $lastCron = json_decode($settings['whatsapp_last_cron_run'], true);
    }
    $isCronActive = false;
    if ($lastCron && isset($lastCron['timestamp'])) {
        if (time() - (int)$lastCron['timestamp'] <= 300) { // 5 minutes
            $isCronActive = true;
        }
    }
    ?>
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:16px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);">
        <div>
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; font-weight:700; color:#4b5563; margin-bottom:4px;">
                Background Queue Worker Status
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="badge <?php echo $isCronActive ? 'green' : 'red'; ?>" style="font-size:0.85rem; font-weight:800; padding:4px 8px;">
                    <?php echo $isCronActive ? 'CRON ACTIVE' : 'CRON NOT RUNNING'; ?>
                </span>
                <span style="font-size:0.85rem; color:#4b5563; font-weight:600;">
                    <?php if ($lastCron): ?>
                        Last run: <strong><?php echo date('d M Y, h:i:s A', $lastCron['timestamp']); ?></strong> (<?php echo htmlspecialchars($lastCron['source'] ?? 'Unknown'); ?>)
                    <?php else: ?>
                        No background cron execution recorded yet.
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php if ($lastCron): ?>
            <div style="display:flex; gap:16px; text-align:right;">
                <div>
                    <div style="font-size:0.7rem; color:#6b7280; font-weight:600;">Status</div>
                    <div style="font-size:0.9rem; font-weight:700; color:<?php echo $lastCron['status'] === 'SUCCESS' ? '#059669' : '#dc2626'; ?>;">
                        <?php echo htmlspecialchars($lastCron['status'] ?? 'N/A'); ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:0.7rem; color:#6b7280; font-weight:600;">Processed</div>
                    <div style="font-size:0.9rem; font-weight:700; color:#111827;"><?php echo (int)($lastCron['processed'] ?? 0); ?></div>
                </div>
                <div>
                    <div style="font-size:0.7rem; color:#6b7280; font-weight:600;">Failed</div>
                    <div style="font-size:0.9rem; font-weight:700; color:#dc2626;"><?php echo (int)($lastCron['failed'] ?? 0); ?></div>
                </div>
                <div>
                    <div style="font-size:0.7rem; color:#6b7280; font-weight:600;">Duration</div>
                    <div style="font-size:0.9rem; font-weight:700; color:#111827;"><?php echo number_format($lastCron['duration'] ?? 0, 2); ?>s</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── NAVIGATION TABS ── -->
    <div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid #e5e7eb; padding-bottom:8px;">
        <a href="communication-dashboard.php" class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-gears"></i> API Settings &amp; Queue</a>
        <a href="communication-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-layer-group"></i> Meta Templates Sync</a>
        <a href="whatsapp-marketing-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-magic"></i> Marketing Templates</a>
        <a href="communication-campaigns.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
        <a href="whatsapp-inbox.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fab fa-whatsapp"></i> WhatsApp Inbox</a>
    </div>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px; align-items:start;">
        <!-- Left: API Config Panel -->
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden;">
            <div style="background:#f8fafc; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; cursor:pointer; user-select:none;" onclick="toggleApiSettings()">
                <div style="display:flex; align-items:center; gap:10px;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fab fa-whatsapp" style="color:#25D366; margin-right:4px;"></i> Meta WhatsApp Cloud API Settings</h3>
                    <span class="badge <?php echo (!empty($settings['whatsapp_phone_id']) && !empty($settings['whatsapp_access_token'])) ? 'green' : 'gray'; ?>">
                        <?php echo (!empty($settings['whatsapp_phone_id']) && !empty($settings['whatsapp_access_token'])) ? 'CONFIGURED' : 'UNCONFIGURED'; ?>
                    </span>
                </div>
                <i class="fas fa-chevron-down" id="api-settings-toggle-icon" style="color:#6b7280; font-size:1rem; transition: transform 0.2s;"></i>
            </div>
            
            <div id="api-settings-body" style="padding:20px; border-top:1px solid #e5e7eb; display:none;">
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
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Cron Worker Key</label>
                            <input type="text" name="whatsapp_cron_worker_key" value="<?php echo htmlspecialchars($settings['whatsapp_cron_worker_key'] ?? ''); ?>" placeholder="Auto-generated if left empty" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                        </div>
                    </div>

                    <div style="margin-bottom:16px; width:50%;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Meta App Secret</label>
                        <input type="password" name="whatsapp_app_secret" value="<?php echo htmlspecialchars($settings['whatsapp_app_secret'] ?? ''); ?>" placeholder="Used for payload signature checking" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                    </div>

                    <div style="margin-bottom:20px; width:50%;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; color:#4b5563; margin-bottom:6px;">Meta Graph API Version</label>
                        <select name="whatsapp_api_version" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85rem;">
                            <option value="v20.0" <?php echo ($settings['whatsapp_api_version'] ?? 'v20.0') === 'v20.0' ? 'selected' : ''; ?>>v20.0 (Recommended)</option>
                            <option value="v19.0" <?php echo ($settings['whatsapp_api_version'] ?? '') === 'v19.0' ? 'selected' : ''; ?>>v19.0</option>
                            <option value="v18.0" <?php echo ($settings['whatsapp_api_version'] ?? '') === 'v18.0' ? 'selected' : ''; ?>>v18.0</option>
                        </select>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #e5e7eb; padding-top:14px; flex-wrap:wrap; gap:12px;">
                        <div style="display:flex; flex-direction:column; gap:4px; font-size:0.8rem; color:#6b7280; text-align:left;">
                            <span><i class="fas fa-link"></i> Webhook Callback URL: <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-family:monospace; font-size:0.75rem;">https://pepplearning.in/admissions/api/v1/communication/webhook.php</code></span>
                            <span><i class="fas fa-clock"></i> Hostinger Cron URL: <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-family:monospace; font-size:0.75rem;">https://pepplearning.in/admissions/cron-queue.php?key=<?php echo htmlspecialchars($settings['whatsapp_cron_worker_key'] ?? ''); ?></code></span>
                        </div>
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

    <!-- View Switcher -->
    <div style="margin-top:20px; display:flex; gap:10px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
        <a href="?view=all" style="padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; <?php echo $view === 'all' ? 'background: #7c3aed; color: #fff; box-shadow: 0 4px 6px -1px rgba(124, 58, 237, 0.2);' : 'background: #fff; color: #4b5563; border: 1px solid #d1d5db;'; ?>">
            <i class="fas fa-list-ul"></i> All Queue Logs
        </a>
        <a href="?view=installments" style="padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; <?php echo $view === 'installments' ? 'background: #7c3aed; color: #fff; box-shadow: 0 4px 6px -1px rgba(124, 58, 237, 0.2);' : 'background: #fff; color: #4b5563; border: 1px solid #d1d5db;'; ?>">
            <i class="fab fa-whatsapp"></i> Installment WhatsApp Reminders
        </a>
    </div>

    <!-- Search and Filter Panel -->
    <form method="GET" style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-top:16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
        
        <!-- Search Input -->
        <div style="flex:1; min-width:240px; position:relative;">
            <i class="fas fa-search" style="position:absolute; left:12px; top:12px; color:#94a3b8; font-size:0.85rem;"></i>
            <input type="text" name="queue_search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Search by recipient name, phone, or queue ID..." style="width:100%; height:38px; padding-left:36px; padding-right:12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.82rem; font-weight:500; color:#1e293b; box-sizing:border-box;" onfocus="this.style.borderColor='#7c3aed';" onblur="this.style.borderColor='#cbd5e1';">
        </div>
        
        <!-- Status Dropdown -->
        <div style="width:200px; min-width:160px;">
            <select name="queue_status" style="width:100%; height:38px; padding:0 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:0.82rem; font-weight:500; color:#1e293b; background-color:#fff; box-sizing:border-box;" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="processing" <?php echo $filter_status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                <option value="sent" <?php echo $filter_status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                <option value="delivered" <?php echo $filter_status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                <option value="read" <?php echo $filter_status === 'read' ? 'selected' : ''; ?>>Read</option>
                <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                <option value="paused" <?php echo $filter_status === 'paused' ? 'selected' : ''; ?>>Paused</option>
                <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        
        <!-- Action Buttons -->
        <div style="display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary" style="height:38px; border-radius:8px; font-size:0.82rem; font-weight:700; padding:0 16px; background:#7c3aed; border-color:#7c3aed; color:#fff; display:flex; align-items:center; gap:6px; cursor:pointer;"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($filter_search !== '' || $filter_status !== ''): ?>
                <a href="?view=<?php echo urlencode($view); ?>" class="btn btn-outline" style="height:38px; border-radius:8px; font-size:0.82rem; font-weight:700; padding:0 16px; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; border:1px solid #cbd5e1; color:#475569; background:#fff; gap:6px;"><i class="fas fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- ── RECENT DISPATCHES QUEUE LOGS ── -->
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; margin-top:20px; margin-bottom:20px;">
        <div style="background:#f8fafc; border-bottom:1px solid #e5e7eb; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1f2937;"><i class="fas fa-list-check" style="margin-right:4px;"></i> <?php echo $view === 'installments' ? 'Installment Reminders Log' : 'Communication Queue'; ?> (<?php echo number_format($totalRecords); ?> total records)</h3>
            <div style="display:flex; align-items:center; gap:12px;">
                <?php if ($isPaused): ?>
                    <span style="font-weight:700; color:#dc2626; font-size:0.85rem;"><i class="fas fa-circle-pause"></i> QUEUE PAUSED</span>
                    <form method="POST" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="resume_queue">
                        <button type="submit" class="btn btn-sm btn-success" style="border-radius:8px; background:#10b981; border:none; color:#fff; font-weight:600; cursor:pointer; padding:6px 12px;"><i class="fas fa-play" style="margin-right:4px;"></i> Resume Queue</button>
                    </form>
                <?php else: ?>
                    <span style="font-weight:700; color:#16a34a; font-size:0.85rem;"><i class="fas fa-circle-check"></i> QUEUE ACTIVE</span>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('WARNING: Pausing the queue will stop all automated campaigns, webhooks, and background reminders immediately.\n\nAre you sure you want to pause the queue?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="pause_queue">
                        <button type="submit" class="btn btn-sm" style="border-radius:8px; border:1px solid #ef4444; color:#ef4444; background:#fff; font-weight:600; cursor:pointer; padding:6px 12px;"><i class="fas fa-pause" style="margin-right:4px;"></i> Pause Queue</button>
                    </form>
                    <span style="font-size:0.75rem; color:#6b7280;">Cron updates automatically every minute</span>
                    <form method="POST" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="process_queue">
                        <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px; background:linear-gradient(135deg, #8b5cf6, #7c3aed); border:none; font-weight:600; cursor:pointer; padding:6px 12px;"><i class="fas fa-play" style="margin-right:4px;"></i> Process Pending Queue</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#f9fafb; text-align:left; border-bottom:1px solid #e5e7eb;">
                <?php if ($view === 'installments'): ?>
                    <th style="padding:12px; font-weight:600; color:#374151;">Queue ID</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Student</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Recipient</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Installment Details</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Amount / Due Date</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Queue Status</th>
                    <th style="padding:12px; font-weight:600; color:#374151; min-width:120px;">Actions</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Reminder Status</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Meta Message ID / Logs</th>
                <?php else: ?>
                    <th style="padding:12px; font-weight:600; color:#374151;">Queue ID</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Recipient</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Channel / Event</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Updated At</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Invoice</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Status</th>
                    <th style="padding:12px; font-weight:600; color:#374151; min-width:120px;">Actions</th>
                    <th style="padding:12px; font-weight:600; color:#374151;">Meta Delivery Log</th>
                <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr>
                        <td colspan="<?php echo $view === 'installments' ? 9 : 8; ?>" style="padding:20px; text-align:center; color:#9ca3af;">No messages have been enqueued yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <?php if ($view === 'installments'): ?>
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:12px; font-weight:600;">#<?php echo $log['queue_id']; ?></td>
                                <td style="padding:12px; font-weight:600;">
                                    <?php if (!empty($log['student_uid'])): ?>
                                        <a href="student-details.php?user_id=<?php echo urlencode($log['student_uid']); ?>" style="color:#2563eb; text-decoration:underline; font-weight:700;" target="_blank">
                                            <?php echo htmlspecialchars($log['student_name'] ?: $log['recipient_name'] ?: $log['student_uid']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($log['recipient_name'] ?: '-'); ?>
                                    <?php endif; ?>
                                    <br>
                                    <span style="font-size:0.75rem; color:#6b7280; font-weight:normal;">UID: <?php echo htmlspecialchars($log['student_uid'] ?: '-'); ?></span>
                                </td>
                                <td style="padding:12px; color:#374151; font-weight:500;">
                                    <?php echo htmlspecialchars($log['recipient']); ?>
                                </td>
                                <td style="padding:12px;">
                                    <span style="font-weight:600; color:#4b5563;">Installment #<?php echo htmlspecialchars($log['instalment_number'] ?: '-'); ?></span>
                                    <br>
                                    <span style="font-size:0.75rem; color:#8b5cf6; font-weight:700;"><?php echo strtoupper(str_replace('_', ' ', $log['reminder_stage'] ?? '')); ?></span>
                                </td>
                                <td style="padding:12px; font-weight:600; color:#374151;">
                                    <?php echo $log['installment_amount'] ? '₹' . number_format((float)$log['installment_amount']) : '-'; ?>
                                    <br>
                                    <span style="font-size:0.75rem; color:#6b7280; font-weight:normal;">Due: <?php echo $log['installment_due_date'] ? date('d M Y', strtotime($log['installment_due_date'])) : '-'; ?></span>
                                </td>
                                <td style="padding:12px;">
                                    <span class="badge <?php 
                                        if ($log['queue_status'] === 'read' || $log['queue_status'] === 'delivered' || $log['queue_status'] === 'sent') {
                                            echo 'green';
                                        } elseif ($log['queue_status'] === 'failed') {
                                            echo ($log['retry_count'] >= 3) ? 'red' : 'amber';
                                        } elseif ($log['queue_status'] === 'cancelled') {
                                            echo 'gray';
                                        } elseif ($log['queue_status'] === 'paused') {
                                            echo 'amber';
                                        } else {
                                            echo 'blue';
                                        }
                                    ?>">
                                        <?php 
                                            if ($log['queue_status'] === 'failed') {
                                                echo ($log['retry_count'] >= 3) ? 'FAILED — PERMANENT' : 'FAILED — RETRYING';
                                            } else {
                                                echo strtoupper($log['queue_status']); 
                                            }
                                        ?>
                                    </span>
                                    <br>
                                    <span style="font-size:0.7rem; color:#6b7280; font-weight:normal;">Retries: <?php echo $log['retry_count']; ?></span>
                                </td>
                                <td style="padding:12px;">
                                    <div style="display:flex; gap:6px; align-items:center;">
                                    <?php if ($log['queue_status'] === 'pending' || $log['queue_status'] === 'scheduled'): ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="pause_queue_item">
                                            <input type="hidden" name="queue_id" value="<?php echo $log['queue_id']; ?>">
                                            <button type="submit" class="btn btn-xs" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; background:#f1f5f9; border:1px solid #cbd5e1; cursor:pointer;" title="Pause message"><i class="fas fa-pause"></i> Pause</button>
                                        </form>
                                        <button type="button" class="btn btn-xs btn-danger" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="openCancelModal(<?php echo $log['queue_id']; ?>, '<?php echo htmlspecialchars($log['recipient']); ?>', '<?php echo htmlspecialchars($log['queue_status']); ?>')" title="Cancel message"><i class="fas fa-xmark"></i> Cancel</button>
                                    <?php elseif ($log['queue_status'] === 'paused'): ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="resume_queue_item">
                                            <input type="hidden" name="queue_id" value="<?php echo $log['queue_id']; ?>">
                                            <button type="submit" class="btn btn-xs btn-success" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; color:#fff; background:#10b981; border:none; cursor:pointer;" title="Resume message"><i class="fas fa-play"></i> Resume</button>
                                        </form>
                                        <button type="button" class="btn btn-xs btn-danger" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="openCancelModal(<?php echo $log['queue_id']; ?>, '<?php echo htmlspecialchars($log['recipient']); ?>', '<?php echo htmlspecialchars($log['queue_status']); ?>')" title="Cancel message"><i class="fas fa-xmark"></i> Cancel</button>
                                    <?php elseif ($log['queue_status'] === 'failed'): ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="retry_queue_item">
                                            <input type="hidden" name="queue_id" value="<?php echo $log['queue_id']; ?>">
                                            <button type="submit" class="btn btn-xs btn-success" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; color:#fff; background:#8b5cf6; border:none; cursor:pointer;" title="Retry message"><i class="fas fa-rotate-right"></i> Retry</button>
                                        </form>
                                        <button type="button" class="btn btn-xs btn-danger" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="openCancelModal(<?php echo $log['queue_id']; ?>, '<?php echo htmlspecialchars($log['recipient']); ?>', '<?php echo htmlspecialchars($log['queue_status']); ?>')" title="Cancel message"><i class="fas fa-xmark"></i> Cancel</button>
                                    <?php elseif ($log['queue_status'] === 'cancelled'): ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="retry_queue_item">
                                            <input type="hidden" name="queue_id" value="<?php echo $log['queue_id']; ?>">
                                            <button type="submit" class="btn btn-xs btn-success" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; color:#fff; background:#8b5cf6; border:none; cursor:pointer;" title="Retry as new manual operation"><i class="fas fa-rotate-right"></i> Retry</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding:12px;">
                                    <span class="badge <?php 
                                        echo $log['tracking_status'] === 'sent' ? 'green' : 
                                             ($log['tracking_status'] === 'failed' ? 'red' : 'amber'); 
                                    ?>">
                                        <?php echo strtoupper($log['tracking_status'] ?? 'UNKNOWN'); ?>
                                    </span>
                                </td>
                                <td style="padding:12px; max-width:320px;" title="<?php echo htmlspecialchars($log['error_message'] ?? ''); ?>">
                                    <?php if ($log['message_id'] && $log['message_id'] !== 'NONE'): ?>
                                        <span style="font-size:0.75rem; font-weight:700; color:#059669; font-family:monospace;"><?php echo htmlspecialchars($log['message_id']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($log['error_message']): ?>
                                        <?php if ($log['message_id'] && $log['message_id'] !== 'NONE') echo '<br>'; ?>
                                        <span style="font-size:0.72rem; color:#ef4444; word-break:break-all;"><?php echo htmlspecialchars(preg_replace('/(Bearer|token|key|secret)[^\s]*/i', '***', $log['error_message'])); ?></span>
                                    <?php elseif (!$log['message_id'] || $log['message_id'] === 'NONE'): ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:12px; font-weight:600;">#<?php echo $log['id']; ?></td>
                                <td style="padding:12px; font-weight:600;">
                                    <?php if (!empty($log['student_uid'])): ?>
                                        <a href="student-details.php?user_id=<?php echo urlencode($log['student_uid']); ?>" style="color:#2563eb; text-decoration:underline; font-weight:700;" target="_blank">
                                            <?php echo htmlspecialchars($log['recipient_name'] ?: $log['student_uid']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($log['recipient_name'] ?: '-'); ?>
                                    <?php endif; ?>
                                    <br>
                                    <span style="font-size:0.75rem; color:#6b7280; font-weight:normal;"><?php echo htmlspecialchars($log['recipient']); ?></span>
                                </td>
                                <td style="padding:12px;">
                                    <span class="badge blue" style="font-size:0.7rem; font-weight:700;"><?php echo strtoupper($log['channel']); ?></span>
                                    <?php if (!empty($log['event_name'])): ?>
                                        <br>
                                        <span style="font-size:0.7rem; color:#4b5563; font-weight:600;">Event: <?php echo htmlspecialchars($log['event_name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px; color:#6b7280;"><?php echo date('d M Y, h:i A', strtotime($log['updated_at'])); ?></td>
                                <td style="padding:12px; font-weight:600;">
                                    <?php if ($log['invoice_id'] && $log['event_name'] === 'payment_receipt'): ?>
                                        <?php 
                                        $inv_hmac = hash_hmac('sha256', (string)$log['invoice_id'], INVOICE_HMAC_SECRET);
                                        $secure_inv_link = "invoice-pdf.php?token=" . urlencode($log['invoice_id'] . '-' . $inv_hmac);
                                        ?>
                                        <a href="<?php echo htmlspecialchars($secure_inv_link); ?>" target="_blank" style="color:#059669; text-decoration:none; font-size:0.8rem; font-weight:700;" title="View Secure PDF Invoice">
                                            <i class="fas fa-file-invoice"></i> Inv #<?php echo $log['invoice_id']; ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px;">
                                    <span class="badge <?php 
                                        if ($log['status'] === 'read' || $log['status'] === 'delivered' || $log['status'] === 'sent') {
                                            echo 'green';
                                        } elseif ($log['status'] === 'failed') {
                                            echo ($log['retry_count'] >= 3) ? 'red' : 'amber';
                                        } elseif ($log['status'] === 'cancelled') {
                                            echo 'gray';
                                        } elseif ($log['status'] === 'paused') {
                                            echo 'amber';
                                        } else {
                                            echo 'blue';
                                        }
                                    ?>">
                                        <?php 
                                            if ($log['status'] === 'failed') {
                                                echo ($log['retry_count'] >= 3) ? 'FAILED — PERMANENT' : 'FAILED — RETRYING';
                                            } else {
                                                echo strtoupper($log['status']); 
                                            }
                                        ?>
                                    </span>
                                </td>
                                <td style="padding:12px;">
                                    <div style="display:flex; gap:6px; align-items:center;">
                                    <?php if ($log['status'] === 'pending' || $log['status'] === 'scheduled'): ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="pause_queue_item">
                                            <input type="hidden" name="queue_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" class="btn btn-xs" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; background:#f1f5f9; border:1px solid #cbd5e1; cursor:pointer;" title="Pause message"><i class="fas fa-pause"></i> Pause</button>
                                        </form>
                                        <button type="button" class="btn btn-xs btn-danger" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="openCancelModal(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars($log['recipient']); ?>', '<?php echo htmlspecialchars($log['status']); ?>')" title="Cancel message"><i class="fas fa-xmark"></i> Cancel</button>
                                    <?php elseif ($log['status'] === 'paused'): ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="resume_queue_item">
                                            <input type="hidden" name="queue_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" class="btn btn-xs btn-success" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; color:#fff; background:#10b981; border:none; cursor:pointer;" title="Resume message"><i class="fas fa-play"></i> Resume</button>
                                        </form>
                                        <button type="button" class="btn btn-xs btn-danger" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="openCancelModal(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars($log['recipient']); ?>', '<?php echo htmlspecialchars($log['status']); ?>')" title="Cancel message"><i class="fas fa-xmark"></i> Cancel</button>
                                    <?php elseif ($log['status'] === 'failed'): ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="retry_queue_item">
                                            <input type="hidden" name="queue_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" class="btn btn-xs btn-success" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; color:#fff; background:#8b5cf6; border:none; cursor:pointer;" title="Retry message"><i class="fas fa-rotate-right"></i> Retry</button>
                                        </form>
                                        <button type="button" class="btn btn-xs btn-danger" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="openCancelModal(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars($log['recipient']); ?>', '<?php echo htmlspecialchars($log['status']); ?>')" title="Cancel message"><i class="fas fa-xmark"></i> Cancel</button>
                                    <?php elseif ($log['status'] === 'cancelled'): ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="retry_queue_item">
                                            <input type="hidden" name="queue_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" class="btn btn-xs btn-success" style="padding:3px 6px; font-size:0.7rem; border-radius:4px; color:#fff; background:#8b5cf6; border:none; cursor:pointer;" title="Retry as new manual operation"><i class="fas fa-rotate-right"></i> Retry</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding:12px; color:#ef4444; max-width:320px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($log['error_message'] ?? ''); ?>">
                                    <?php echo $log['error_message'] ? htmlspecialchars(preg_replace('/(Bearer|token|key|secret)[^\s]*/i', '***', $log['error_message'])) : '<span style="color:#9ca3af;">-</span>'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination controls -->
        <div style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #e5e7eb; background: #f8fafc; flex-wrap: wrap; gap: 12px;">
            <div style="font-size: 0.85rem; color: #475569; font-weight: 500;">
                <?php if ($totalRecords > 0): ?>
                    Showing <strong><?php echo $offset + 1; ?></strong> to <strong><?php echo min($offset + $perPage, $totalRecords); ?></strong> of <strong><?php echo number_format($totalRecords); ?></strong> records
                <?php else: ?>
                    No communication records found.
                <?php endif; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                    <!-- Previous Button -->
                    <?php if ($page > 1): ?>
                        <a href="<?php echo getPageUrl($page - 1); ?>" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.85rem; background: #fff; color: #374151; font-weight: 600; text-decoration: none; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"><i class="fas fa-chevron-left" style="font-size: 0.7rem; margin-right: 4px;"></i> Previous</a>
                    <?php else: ?>
                        <span style="padding: 6px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.85rem; background: #f9fafb; color: #9ca3af; font-weight: 600; cursor: not-allowed; box-shadow: none;"><i class="fas fa-chevron-left" style="font-size: 0.7rem; margin-right: 4px;"></i> Previous</span>
                    <?php endif; ?>

                    <!-- Page Numbers -->
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1) {
                        echo '<a href="' . getPageUrl(1) . '" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.85rem; background: #fff; color: #374151; font-weight: 600; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">1</a>';
                        if ($startPage > 2) {
                            echo '<span style="color: #9ca3af; padding: 0 4px; font-size: 0.85rem;">...</span>';
                        }
                    }

                    for ($i = $startPage; $i <= $endPage; $i++) {
                        if ($i === $page) {
                            echo '<span style="padding: 6px 12px; background: linear-gradient(135deg, #8b5cf6, #7c3aed); border: 1px solid #7c3aed; color: #fff; border-radius: 8px; font-size: 0.85rem; font-weight: 700; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">' . $i . '</span>';
                        } else {
                            echo '<a href="' . getPageUrl($i) . '" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.85rem; background: #fff; color: #374151; font-weight: 600; text-decoration: none; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">' . $i . '</a>';
                        }
                    }

                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) {
                            echo '<span style="color: #9ca3af; padding: 0 4px; font-size: 0.85rem;">...</span>';
                        }
                        echo '<a href="' . getPageUrl($totalPages) . '" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.85rem; background: #fff; color: #374151; font-weight: 600; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">' . $totalPages . '</a>';
                    }
                    ?>

                    <!-- Next Button -->
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo getPageUrl($page + 1); ?>" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.85rem; background: #fff; color: #374151; font-weight: 600; text-decoration: none; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">Next <i class="fas fa-chevron-right" style="font-size: 0.7rem; margin-left: 4px;"></i></a>
                    <?php else: ?>
                        <span style="padding: 6px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.85rem; background: #f9fafb; color: #9ca3af; font-weight: 600; cursor: not-allowed; box-shadow: none;">Next <i class="fas fa-chevron-right" style="font-size: 0.7rem; margin-left: 4px;"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Cancel Queue Modal Overlay -->
<div id="cancelQueueModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); backdrop-filter:blur(4px); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:16px; width:450px; max-width:90%; padding:24px; box-shadow:0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); border:1px solid #e5e7eb;">
        <h3 style="margin-top:0; font-size:1.15rem; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-circle-exclamation" style="color:#ef4444;"></i> Cancel Queue <span id="modalQueueIdDisplay">#XXX</span>?
        </h3>
        
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; margin:16px 0; font-size:0.85rem;">
            <div style="margin-bottom:6px; color:#475569;"><strong>Recipient:</strong> <span id="modalRecipientDisplay">+91XXXXXXXXXX</span></div>
            <div style="color:#475569;"><strong>Status:</strong> <span id="modalStatusDisplay">Failed</span></div>
        </div>

        <p style="font-size:0.85rem; color:#4b5563; margin-bottom:20px;">
            Once cancelled, the communication engine will not retry this queue item.
        </p>

        <form method="POST" action="" id="cancelQueueForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="cancel_queue_item">
            <input type="hidden" name="queue_id" id="modalQueueIdInput" value="">
            
            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:0.8rem; font-weight:600; color:#4b5563; margin-bottom:6px;">Reason for Cancellation (Optional):</label>
                <select name="cancel_reason" style="width:100%; padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; font-size:0.85rem; background:#fff;">
                    <option value="No longer required">No longer required</option>
                    <option value="Invalid number">Invalid number</option>
                    <option value="Student changed number">Student changed number</option>
                    <option value="Duplicate message">Duplicate message</option>
                    <option value="Wrong recipient">Wrong recipient</option>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-sm" style="border-radius:8px; padding:8px 16px; font-weight:600; border:1px solid #d1d5db; background:#fff; cursor:pointer;" onclick="closeCancelModal()">Keep Queue</button>
                <button type="submit" class="btn btn-sm btn-danger" style="border-radius:8px; padding:8px 16px; font-weight:600; background:#ef4444; border:none; color:#fff; cursor:pointer;">Cancel Queue</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCancelModal(queueId, recipient, status) {
    document.getElementById('modalQueueIdDisplay').innerText = '#' + queueId;
    document.getElementById('modalQueueIdInput').value = queueId;
    document.getElementById('modalRecipientDisplay').innerText = recipient;
    document.getElementById('modalStatusDisplay').innerText = status.toUpperCase();
    document.getElementById('cancelQueueModal').style.display = 'flex';
}

function closeCancelModal() {
    document.getElementById('cancelQueueModal').style.display = 'none';
}

function toggleApiSettings() {
    const body = document.getElementById('api-settings-body');
    const icon = document.getElementById('api-settings-toggle-icon');
    if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.style.transform = 'rotate(180deg)';
    } else {
        body.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
