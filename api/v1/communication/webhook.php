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
                        SET status = ?, 
                            error_message = COALESCE(?, error_message), 
                            delivered_at = CASE WHEN ? IN ('delivered', 'read') AND delivered_at IS NULL THEN NOW() ELSE delivered_at END,
                            updated_at = NOW() 
                        WHERE message_id = ?
                    ");
                    $stmtQueue->execute([$status, $errMsg, $status, $msgId]);

                    // Update local message tracking status (Safeguard 1)
                    $stmtMsgUpd = $pdo->prepare("
                        UPDATE whatsapp_messages 
                        SET status = ?, 
                            delivered_at = CASE WHEN ? = 'delivered' AND delivered_at IS NULL THEN NOW() ELSE delivered_at END,
                            read_at = CASE WHEN ? = 'read' AND read_at IS NULL THEN NOW() ELSE read_at END
                        WHERE wa_message_id = ?
                    ");
                    $stmtMsgUpd->execute([$status, $status, $status, $msgId]);

                    // Sync status to communication campaign recipients
                    $stmtGetQ = $pdo->prepare("SELECT id FROM communication_queue WHERE message_id = ? LIMIT 1");
                    $stmtGetQ->execute([$msgId]);
                    $qId = $stmtGetQ->fetchColumn();
                    if ($qId) {
                        $stmtCampRecip = $pdo->prepare("
                            UPDATE communication_campaign_recipients 
                            SET status = CASE WHEN ? = 'failed' THEN 'failed' ELSE 'sent' END,
                                sent_at = CASE WHEN ? IN ('sent', 'delivered', 'read') AND sent_at IS NULL THEN NOW() ELSE sent_at END
                            WHERE queue_id = ?
                        ");
                        $stmtCampRecip->execute([$status, $status, $qId]);
                    }

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

    // Process incoming WhatsApp messages
    if (isset($payload['entry'][0]['changes'][0]['value']['messages'])) {
        $messages = $payload['entry'][0]['changes'][0]['value']['messages'];
        $contacts = $payload['entry'][0]['changes'][0]['value']['contacts'] ?? [];
        
        $contactNames = [];
        foreach ($contacts as $c) {
            $waId = $c['wa_id'] ?? '';
            $profileName = $c['profile']['name'] ?? '';
            if ($waId) {
                $contactNames[$waId] = $profileName;
            }
        }

        foreach ($messages as $msg) {
            $from = $msg['from'] ?? '';
            $msgId = $msg['id'] ?? '';
            $timestamp = $msg['timestamp'] ?? time();
            $type = $msg['type'] ?? 'text';
            
            if (empty($msgId) || empty($from)) {
                error_log("WhatsApp Webhook: Missing message ID or sender info in inbound message payload");
                continue;
            }
            
            // Check if message ID already exists to guarantee idempotency (Safeguard 2)
            try {
                $stmtCheck = $pdo->prepare("SELECT id FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1");
                $stmtCheck->execute([$msgId]);
                if ($stmtCheck->fetchColumn()) {
                    continue; // Skip duplicate Meta webhook deliveries
                }
            } catch (Exception $e) {
                error_log("WhatsApp Webhook idempotency check error: " . $e->getMessage());
            }

            $text = '';
            $mediaId = null;
            $mediaMime = null;
            $mediaFilename = null;
            $caption = null;
            $replyToId = $msg['context']['message_id'] ?? null;
            
            $buttonPayload = '';
            if ($type === 'text') {
                $text = $msg['text']['body'] ?? '';
            } elseif ($type === 'button') {
                $btnText = $msg['button']['text'] ?? '';
                $text = "Student clicked button: \"{$btnText}\"";
                $buttonPayload = $msg['button']['payload'] ?? '';
            } elseif ($type === 'interactive') {
                $intType = $msg['interactive']['type'] ?? '';
                if ($intType === 'button_reply') {
                    $btnText = $msg['interactive']['button_reply']['title'] ?? '';
                    $text = "Student clicked interactive button: \"{$btnText}\"";
                    $buttonPayload = $msg['interactive']['button_reply']['id'] ?? '';
                } elseif ($intType === 'list_reply') {
                    $text = $msg['interactive']['list_reply']['title'] ?? '';
                }
            } elseif ($type === 'image') {
                $mediaId = $msg['image']['id'] ?? '';
                $mediaMime = $msg['image']['mime_type'] ?? '';
                $caption = $msg['image']['caption'] ?? '';
                $text = '[Image]';
            } elseif ($type === 'document') {
                $mediaId = $msg['document']['id'] ?? '';
                $mediaMime = $msg['document']['mime_type'] ?? '';
                $mediaFilename = $msg['document']['filename'] ?? '';
                $caption = $msg['document']['caption'] ?? '';
                $text = '[Document]';
            } elseif ($type === 'audio') {
                $mediaId = $msg['audio']['id'] ?? '';
                $mediaMime = $msg['audio']['mime_type'] ?? '';
                $text = '[Audio]';
            } elseif ($type === 'video') {
                $mediaId = $msg['video']['id'] ?? '';
                $mediaMime = $msg['video']['mime_type'] ?? '';
                $text = '[Video]';
            } else {
                $text = "[Unsupported message type: {$type}]";
            }

            // Student matching by sender phone number (normalization logic)
            $cleanFrom = preg_replace('/\D/', '', $from);
            $last10 = substr($cleanFrom, -10);
            
            $studentMatch = null;
            try {
                $stmtMatch = $pdo->prepare("
                    SELECT id, user_id, name, pepp_course, whatsapp_country_code, whatsapp_number 
                    FROM users 
                    WHERE status = 'approved' 
                      AND whatsapp_number LIKE ?
                ");
                $stmtMatch->execute(['%' . $last10]);
                $allMatches = $stmtMatch->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($allMatches as $u) {
                    $uClean = preg_replace('/\D/', '', $u['whatsapp_country_code'] . $u['whatsapp_number']);
                    $uLast10 = substr(preg_replace('/\D/', '', $u['whatsapp_number']), -10);
                    if ($uClean === $cleanFrom || $uLast10 === $last10) {
                        $studentMatch = $u;
                        break;
                    }
                }
            } catch (Exception $e) {
                error_log("WhatsApp Webhook matching error: " . $e->getMessage());
            }

            $contactName = $contactNames[$from] ?? $studentMatch['name'] ?? 'Unknown WhatsApp Contact';
            $studentUid = $studentMatch['user_id'] ?? null;
            $studentUserId = $studentMatch['id'] ?? null;

            try {
                $pdo->beginTransaction();

                // Find or create conversation
                $stmtConv = $pdo->prepare("SELECT id FROM whatsapp_conversations WHERE wa_phone_number = ? LIMIT 1");
                $stmtConv->execute([$cleanFrom]);
                $convId = $stmtConv->fetchColumn();

                if (!$convId) {
                    $insConv = $pdo->prepare("
                        INSERT INTO whatsapp_conversations (wa_phone_number, student_uid, student_user_id, contact_name, last_message_text, last_message_at, last_inbound_at, unread_count, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 'open', NOW(), NOW())
                    ");
                    $insConv->execute([$cleanFrom, $studentUid, $studentUserId, $contactName, $text]);
                    $convId = (int)$pdo->lastInsertId();
                } else {
                    $updConv = $pdo->prepare("
                        UPDATE whatsapp_conversations 
                        SET student_uid = ?, 
                            student_user_id = ?, 
                            contact_name = ?, 
                            last_message_text = ?, 
                            last_message_at = NOW(), 
                            last_inbound_at = NOW(), 
                            unread_count = unread_count + 1, 
                            updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updConv->execute([$studentUid, $studentUserId, $contactName, $text, $convId]);
                }

                // Insert inbound message record
                $insMsg = $pdo->prepare("
                    INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, media_id, media_mime_type, media_filename, caption, reply_to_wa_message_id, status, raw_payload, sent_at, created_at)
                    VALUES (?, ?, 'inbound', ?, ?, ?, ?, ?, ?, ?, 'delivered', ?, NOW(), NOW())
                ");
                $insMsg->execute([
                    $convId,
                    $msgId,
                    $type,
                    $text,
                    $mediaId,
                    $mediaMime,
                    $mediaFilename,
                    $caption,
                    $replyToId,
$rawPayload
                ]);

                $pdo->commit();

                $preventAutoResponse = false;
                // ── WHATSAPP TEMPLATE QUICK-REPLY BUTTON ACTION ROUTING ──
                $isButtonClick = ($type === 'button' || ($type === 'interactive' && ($msg['interactive']['type'] ?? '') === 'button_reply'));
                if ($isButtonClick) {
                    try {
                        // Resolve parent template name from conversation history context if replyToId is present
                        $parentTemplateName = null;
                        if (!empty($replyToId)) {
                            $stmtParent = $pdo->prepare("SELECT raw_payload FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1");
                            $stmtParent->execute([$replyToId]);
                            $parentPayloadJson = $stmtParent->fetchColumn();
                            if ($parentPayloadJson) {
                                $parentPayload = json_decode($parentPayloadJson, true);
                                $parentTemplateName = $parentPayload['name'] ?? null;
                            }
                        }

                        $matchedAction = null;
                        
                        // First attempt: Match by unique payload (if available)
                        if (!empty($buttonPayload)) {
                            $stmtAction = $pdo->prepare("
                                SELECT template_name, meta_data 
                                FROM communication_templates 
                                WHERE channel = 'whatsapp' 
                                  AND status = 'approved' 
                                  AND meta_data LIKE ?
                            ");
                            $stmtAction->execute(['%' . $buttonPayload . '%']);
                            $allActions = $stmtAction->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($allActions as $tplRow) {
                                $tplMeta = json_decode($tplRow['meta_data'], true) ?: [];
                                $actions = $tplMeta['buttons']['quick_reply'] ?? [];
                                foreach ($actions as $act) {
                                    if (isset($act['payload']) && trim($act['payload']) === $buttonPayload) {
                                        $matchedAction = [
                                            'source_template_name' => $tplRow['template_name'],
                                            'action_type' => $act['action_type'] ?? 'NONE',
                                            'target_template_name' => $act['target_template_name'] ?? ''
                                        ];
                                        break 2;
                                    }
                                }
                            }
                        }
                        
                        // Second attempt: Fallback to parent template name + button text match
                        if (!$matchedAction && !empty($parentTemplateName) && !empty($btnText)) {
                            $stmtParentTpl = $pdo->prepare("
                                SELECT template_name, meta_data 
                                FROM communication_templates 
                                WHERE template_name = ? 
                                  AND channel = 'whatsapp' 
                                  AND status = 'approved' 
                                  LIMIT 1
                            ");
                            $stmtParentTpl->execute([$parentTemplateName]);
                            $parentTplRow = $stmtParentTpl->fetch();
                            
                            if ($parentTplRow) {
                                $tplMeta = json_decode($parentTplRow['meta_data'], true) ?: [];
                                $actions = $tplMeta['buttons']['quick_reply'] ?? [];
                                foreach ($actions as $act) {
                                    if (isset($act['text']) && trim($act['text']) === $btnText) {
                                        $matchedAction = [
                                            'source_template_name' => $parentTplRow['template_name'],
                                            'action_type' => $act['action_type'] ?? 'NONE',
                                            'target_template_name' => $act['target_template_name'] ?? ''
                                        ];
                                        break;
                                    }
                                }
                            }
                        }
                        
                        if ($matchedAction) {
                            $actionType = $matchedAction['action_type'];
                            $targetTplName = $matchedAction['target_template_name'];
                            
                            error_log("INBOUND BUTTON ACTION TRIGGERED: Inbound Message ID: {$msgId} | Payload: {$buttonPayload} | Text: {$btnText} | Action: {$actionType} | Target Template: {$targetTplName} | Student: " . ($studentMatch['name'] ?? 'Unknown'));
                            
                            if ($actionType === 'SEND_TEMPLATE' && !empty($targetTplName)) {
                                $preventAutoResponse = true;
                                
                                // Find target template details
                                $stmtTarget = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? AND channel = 'whatsapp' AND status = 'approved' LIMIT 1");
                                $stmtTarget->execute([$targetTplName]);
                                $targetTpl = $stmtTarget->fetch();
                                
                                if ($targetTpl) {
                                    $targetMeta = json_decode($targetTpl['meta_data'], true) ?: [];
                                    $targetMetaTemplateId = $targetMeta['meta_template_id'] ?? 'N/A';
                                    
                                    // Determine parameters to send
                                    $parameters = [];
                                    $targetBody = $targetMeta['body_text'] ?? '';
                                    preg_match_all('/\{\{(\d+)\}\}/', $targetBody, $bodyMatches);
                                    $expectedParamsCount = !empty($bodyMatches[1]) ? max(array_map('intval', $bodyMatches[1])) : 0;
                                    
                                    if ($expectedParamsCount > 0) {
                                        $studentName = $studentMatch['name'] ?? 'Student';
                                        $peppCourse = $studentMatch['pepp_course'] ?? 'PEPP Course';
                                        
                                        for ($i = 0; $i < $expectedParamsCount; $i++) {
                                            if ($i === 0) {
                                                $parameters[] = $studentName;
                                            } elseif ($i === 1) {
                                                $parameters[] = $peppCourse;
                                            } else {
                                                $parameters[] = 'Sample';
                                            }
                                        }
                                    }
                                    
                                    // Robust header extraction for target template
                                    $targetHeaderType = $targetMeta['header_type'] ?? 'NONE';
                                    if ($targetHeaderType === 'NONE' && !empty($targetMeta['components'])) {
                                        foreach ($targetMeta['components'] as $c) {
                                            if (($c['type'] ?? '') === 'HEADER') {
                                                $targetHeaderType = $c['format'] ?? 'NONE';
                                                break;
                                            }
                                        }
                                    }
                                    
                                    $targetHeaderMediaUrl = $targetMeta['header_media_url'] ?? '';
                                    if (empty($targetHeaderMediaUrl) && !empty($targetMeta['components'])) {
                                        // Fallback checks (skip temporary meta CDN links)
                                        foreach ($targetMeta['components'] as $c) {
                                            if (($c['type'] ?? '') === 'HEADER' && !empty($c['example']['header_handle'][0])) {
                                                $maybeUrl = $c['example']['header_handle'][0];
                                                if (strpos($maybeUrl, 'scontent.whatsapp.net') === false && strpos($maybeUrl, 'fbcdn.net') === false) {
                                                    $targetHeaderMediaUrl = $maybeUrl;
                                                }
                                            }
                                        }
                                    }
                                    
                                    $templateDataToSend = [
                                        'name' => $targetTplName,
                                        'language' => $targetTpl['language'] ?? 'en_US',
                                        'parameters' => $parameters
                                    ];
                                    if ($targetHeaderType !== 'NONE' && !empty($targetHeaderMediaUrl)) {
                                        $templateDataToSend['header_type'] = $targetHeaderType;
                                        $templateDataToSend['header_parameters'] = [$targetHeaderMediaUrl];
                                    }
                                    
                                    // Enqueue in communication queue
                                    require_once dirname(dirname(dirname(__DIR__))) . '/includes/communication/CommunicationEngine.php';
                                    $engine = CommunicationEngine::getInstance($pdo);
                                    
                                    $subject = "Auto-Reply: " . $targetTplName;
                                    $targetBodyText = $targetMeta['body_text'] ?? '';
                                    $targetBodyHtml = nl2br($targetBodyText);
                                    
                                    $recipientName = $contactName;
                                    
                                    $queueId = $engine->queueMessage(
                                        'whatsapp',
                                        $cleanFrom,
                                        $recipientName,
                                        $subject,
                                        $targetBodyHtml,
                                        $targetBodyText,
                                        [],
                                        $templateDataToSend,
                                        'system_auto_reply',
                                        null,
                                        $studentUid,
                                        'auto_reply_button'
                                    );
                                    
                                    if ($queueId) {
                                        $engine->dispatchQueueItemAsync($queueId);
                                        error_log("SUCCESS: Button action enqueued. Queue ID: {$queueId} | Target: {$targetTplName} | Meta Template ID: {$targetMetaTemplateId}");
                                    } else {
                                        error_log("FAILED: Unable to enqueue button action response template.");
                                    }
                                } else {
                                    error_log("FAILED: Target template '{$targetTplName}' is not approved or was not found in the database.");
                                }
                            }
                        } else {
                            error_log("INBOUND BUTTON ACTION: No matching template action mapping found for payload: {$buttonPayload} | Text: {$btnText}");
                        }
                    } catch (Exception $actionEx) {
                        error_log("WhatsApp Webhook button action routing error: " . $actionEx->getMessage());
                    }
                }

                // ── AUTOMATED INTERACTIVE AUTO-RESPONSE TRIGGER ──
                try {
                    $metadata = $payload['entry'][0]['changes'][0]['value']['metadata'] ?? [];
                    $displayNumber = preg_replace('/\D/', '', $metadata['display_phone_number'] ?? '');
                    
                    // Verify recipient WABA display number (916282563209)
                    if ((empty($displayNumber) || $displayNumber === '916282563209') && !$preventAutoResponse) {
                        $cooldown = 3600; // 1 hour default cooldown
                        try {
                            $stmtCd = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_auto_response_cooldown' LIMIT 1");
                            $stmtCd->execute();
                            $cdVal = $stmtCd->fetchColumn();
                            if ($cdVal !== false && $cdVal !== null && $cdVal !== '') {
                                $cooldown = (int)$cdVal;
                            }
                        } catch (Exception $cdEx) {}

                        // Check if auto-response was already sent within the cooldown window
                        $stmtRecent = $pdo->prepare("
                            SELECT COUNT(*) 
                            FROM whatsapp_messages 
                            WHERE conversation_id = ? 
                              AND direction = 'outbound' 
                              AND message_text LIKE '%Thank you for contacting PEPP Learning%'
                              AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                        ");
                        $stmtRecent->execute([$convId, $cooldown]);
                        $recentCount = (int)$stmtRecent->fetchColumn();

                        if ($recentCount === 0) {
                            require_once __DIR__ . '/../../../includes/communication/CommunicationEngine.php';
                            $engine = CommunicationEngine::getInstance($pdo);

                            $autoText = "Thank you for contacting PEPP Learning.\n\nIf you have any query or need assistance, please contact our support team.";

                            $templateData = [
                                'type' => 'interactive',
                                'interactive_type' => 'cta_url',
                                'interactive_body' => $autoText,
                                'interactive_button_text' => 'Message Here',
                                'interactive_button_url' => 'https://wa.me/917025000444'
                            ];

                            $queueId = $engine->queueMessage(
                                'whatsapp',
                                $cleanFrom,
                                $contactName,
                                'Auto Response',
                                $autoText,
                                $autoText,
                                [],
                                $templateData,
                                'system_auto_response',
                                null,
                                $studentUid,
                                'auto_response'
                            );

                            if ($queueId) {
                                $engine->dispatchQueueItemAsync($queueId);
                            }
                        }
                    }
                } catch (Exception $autoEx) {
                    error_log("WhatsApp Auto-Response trigger error: " . $autoEx->getMessage());
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("WhatsApp Webhook conversation/message save failure: " . $e->getMessage());
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
