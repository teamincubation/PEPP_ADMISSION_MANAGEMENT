<?php
/**
 * Official Meta WhatsApp Cloud API Webhook Callback Handler.
 * Validates request signatures and verify tokens, processes message status updates,
 * logs events, and synchronizes callbacks to the database messaging queue.
 */

require_once dirname(dirname(dirname(__DIR__))) . '/config/database.php';

// Fetch verification token and secrets
$stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$verifyToken = trim($settings['whatsapp_webhook_verify_token'] ?? 'pepp_verify_token_2026');
$appSecret   = trim($settings['whatsapp_app_secret'] ?? '');

/* ── 1. Webhook subscription verification challenge (GET) ────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verifyToken) {
        http_response_code(200);
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        echo "Forbidden - Verify Token Mismatch";
        exit;
    }
}

/* ── 2. Handle POST callback updates ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPayload = file_get_contents('php://input');
    
    // Validate Signature (supporting Apache, LiteSpeed, Nginx CGI/FastCGI environments)
    $signatureHeader = '';
    if (function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $signatureHeader = $headers['x-hub-signature-256'] ?? '';
    }
    if (!$signatureHeader) {
        $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    }

    if ($appSecret && $signatureHeader) {
        $expected = 'sha256=' . hash_hmac('sha256', $rawPayload, $appSecret);
        if (!hash_equals($expected, $signatureHeader)) {
            http_response_code(401);
            // Log failed signature
            error_log("WhatsApp Webhook Signature Mismatch: Received {$signatureHeader}");
            exit;
        }
    }

    $payload = json_decode($rawPayload, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }
    
    // Log the incoming event
    try {
        $eventType = 'unknown';
        $field = $payload['entry'][0]['changes'][0]['field'] ?? '';
        if ($field === 'message_template_status_update') {
            $eventType = 'templates.status_update';
        } elseif (isset($payload['entry'][0]['changes'][0]['value']['statuses'][0])) {
            $eventType = 'messages.status';
        } elseif (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            $eventType = 'messages.receive';
        }

        $logStmt = $pdo->prepare("
            INSERT INTO communication_webhook_events (provider, event_type, payload, processed, created_at) 
            VALUES ('meta', ?, ?, 0, NOW())
        ");
        $logStmt->execute([$eventType, $rawPayload]);
        $eventId = (int)$pdo->lastInsertId();
    } catch (Exception $ex) {
        error_log("Failed to log webhook event: " . $ex->getMessage());
        $eventId = 0;
    }

    // Process status updates
    if (isset($payload['entry'][0]['changes'][0]['value']['statuses'])) {
        $statuses = $payload['entry'][0]['changes'][0]['value']['statuses'];
        
        foreach ($statuses as $stat) {
            $msgId  = $stat['id'] ?? '';
            $status = $stat['status'] ?? ''; // sent, delivered, read, failed
            $errMsg = null;
            
            if ($status === 'failed' && !empty($stat['errors'])) {
                $errMsg = $stat['errors'][0]['message'] ?? 'Meta Delivery Failure';
            }

            if ($msgId && $status) {
                try {
                    // Update main communication queue status
                    $stmtQueue = $pdo->prepare("
                        UPDATE communication_queue 
                        SET status = ?, error_message = COALESCE(?, error_message), updated_at = NOW() 
                        WHERE message_id = ?
                    ");
                    $stmtQueue->execute([$status, $errMsg, $msgId]);

                    // Sync back to legacy whatsapp_notifications table if relevant
                    $stmtFind = $pdo->prepare("SELECT error_message FROM communication_queue WHERE message_id = ? LIMIT 1");
                    $stmtFind->execute([$msgId]);
                    $errVal = $stmtFind->fetchColumn();
                    
                    if ($errVal && strpos($errVal, 'legacy_id:') === 0) {
                        $legacyId = (int)substr($errVal, 10);
                        $legStatus = in_array($status, ['sent', 'delivered', 'read'], true) ? 'sent' : 'failed';
                        
                        $stmtLegacy = $pdo->prepare("UPDATE whatsapp_notifications SET status = ?, updated_at = NOW() WHERE id = ?");
                        $stmtLegacy->execute([$legStatus, $legacyId]);
                    }
                } catch (Exception $ex) {
                    error_log("Webhook database update failed: " . $ex->getMessage());
                }
            }
        }
    }

    // Process template status updates
    if (isset($payload['entry'][0]['changes'][0]['field']) && $payload['entry'][0]['changes'][0]['field'] === 'message_template_status_update') {
        $value = $payload['entry'][0]['changes'][0]['value'] ?? [];
        $tplName = $value['message_template_name'] ?? '';
        $newStatus = strtolower($value['event'] ?? ''); // APPROVED, REJECTED, PENDING, etc.
        $reason = $value['reason'] ?? null;
        
        if ($tplName && $newStatus) {
            try {
                $stmtTpl = $pdo->prepare("
                    UPDATE communication_templates 
                    SET status = ?, rejection_reason = COALESCE(?, rejection_reason), updated_at = NOW() 
                    WHERE template_name = ?
                ");
                $stmtTpl->execute([$newStatus, $reason, $tplName]);
            } catch (Exception $ex) {
                error_log("Webhook template status update failed: " . $ex->getMessage());
            }
        }
    }

    // Mark event as processed
    if ($eventId > 0) {
        try {
            $updStmt = $pdo->prepare("UPDATE communication_webhook_events SET processed = 1, processed_at = NOW() WHERE id = ?");
            $updStmt->execute([$eventId]);
        } catch (Exception $ex) {}
    }

    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}
