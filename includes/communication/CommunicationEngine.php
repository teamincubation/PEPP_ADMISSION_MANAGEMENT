<?php
/**
 * Central Communication Engine.
 * Manages the dispatch queue, channels providers routing, templates variable
 * interpolation, and Meta webhook events.
 */

require_once __DIR__ . '/Providers/CommunicationProviderInterface.php';
require_once __DIR__ . '/Providers/WhatsAppCloudProvider.php';
require_once __DIR__ . '/Providers/EmailMailerProvider.php';

if (file_exists(dirname(dirname(__DIR__)) . '/includes/template_helper.php')) {
    require_once dirname(dirname(__DIR__)) . '/includes/template_helper.php';
}

class CommunicationEngine {
    private static $instance = null;
    private $pdo;
    public $lastError = null;

    private function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Singleton instance retriever.
     */
    public static function getInstance($pdo) {
        if (self::$instance === null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Enqueues an outgoing message to the communication queue.
     * Maps to legacy log table in parallel to maintain backward compatibility.
     *
     * @param string $channel e.g., 'whatsapp' or 'email'
     * @param string $recipient Phone number or email address
     * @param string|null $recipientName
     * @param string|null $subject
     * @param string $bodyHtml
     * @param string $bodyText
     * @param array $attachments Optional list of attachment files
     * @param array $templateData Optional array containing WhatsApp template info
     * @param string $sentBy Username of the administrator trigger
     * @param string|null $scheduledAt Timestamp string (Y-m-d H:i:s)
     * @param string|null $studentUid Associated student user_id for placeholder replacement
     * @return int Queue Item ID
     */
    public function queueMessage($channel, $recipient, $recipientName, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = [], $sentBy = 'system', $scheduledAt = null, $studentUid = null, $eventName = null, $invoiceId = null) {
        $status = 'pending';
        $nextAttempt = date('Y-m-d H:i:s');
        $errorMsg = null;
        $retryCount = 0;

        if ($scheduledAt && strtotime($scheduledAt) > time()) {
            $status = 'scheduled';
            $nextAttempt = date('Y-m-d H:i:s', strtotime($scheduledAt));
        }

        // Validate recipient phone number (must be numeric and >= 10 digits for WhatsApp)
        $cleanPhone = preg_replace('/\D/', '', $recipient);
        if ($channel === 'whatsapp' && (empty($cleanPhone) || strlen($cleanPhone) < 10)) {
            $status = 'failed';
            $errorMsg = 'Invalid or missing phone number: ' . $recipient;
            $retryCount = 3; // Block auto-retries for invalid phone numbers
        }

        // Auto-resolve placeholders if student UID is provided and text is raw
        if ($studentUid && empty($templateData['name'])) {
            if (function_exists('fill_student_template')) {
                $bodyHtml = fill_student_template($this->pdo, $bodyHtml, $studentUid);
                $bodyText = fill_student_template($this->pdo, $bodyText, $studentUid);
            }
        }

        // Map attachments: Add public PDF access URL if applicable
        $processedAttachments = [];
        foreach ($attachments as $att) {
            $processedAttachments[] = [
                'name'  => $att['name'] ?? 'invoice.pdf',
                'bytes' => $att['bytes'] ?? null,
                'type'  => $att['type'] ?? 'application/pdf',
                'url'   => $att['url'] ?? ''
            ];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO communication_queue 
            (channel, recipient, recipient_name, subject, body_html, body_text, template_name, template_data, attachments, status, next_attempt_at, sent_by, student_uid, event_name, invoice_id, error_message, retry_count, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $templateJson = !empty($templateData) ? json_encode($templateData) : null;
        $attachmentsJson = !empty($processedAttachments) ? json_encode($processedAttachments) : null;

        $stmt->execute([
            $channel,
            $recipient,
            $recipientName,
            $subject,
            $bodyHtml,
            $bodyText,
            $templateData['name'] ?? null,
            $templateJson,
            $attachmentsJson,
            $status,
            $nextAttempt,
            $sentBy,
            $studentUid,
            $eventName,
            $invoiceId,
            $errorMsg,
            $retryCount
        ]);

        $queueId = (int)$this->pdo->lastInsertId();

        // LEGACY PARALLEL WRITE: write to legacy whatsapp_notifications table
        if ($channel === 'whatsapp') {
            try {
                $legacyPhone = substr(preg_replace('/\D/', '', $recipient), -15);
                $lat = isset($_COOKIE['pepp_lat']) && is_numeric($_COOKIE['pepp_lat']) ? (float)$_COOKIE['pepp_lat'] : null;
                $lng = isset($_COOKIE['pepp_lng']) && is_numeric($_COOKIE['pepp_lng']) ? (float)$_COOKIE['pepp_lng'] : null;
                $meta = $_COOKIE['pepp_meta'] ?? null;
                $legacyStmt = $this->pdo->prepare("
                    INSERT INTO whatsapp_notifications (phone, message, student_name, sent_by, status, latitude, longitude, metadata, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $legacyStmt->execute([
                    $legacyPhone,
                    $bodyText ?: strip_tags($bodyHtml),
                    $recipientName,
                    $sentBy,
                    $status === 'failed' ? 'failed' : 'pending',
                    $lat,
                    $lng,
                    $meta
                ]);
                $legacyId = (int)$this->pdo->lastInsertId();
                
                // Store legacy ID mapping inside our queue data
                $updateStmt = $this->pdo->prepare("UPDATE communication_queue SET error_message = ? WHERE id = ?");
                $updateStmt->execute([
                    ($errorMsg ? $errorMsg . ' (legacy_id:' . $legacyId . ')' : 'legacy_id:' . $legacyId),
                    $queueId
                ]);
            } catch (Exception $ex) {
                error_log('Legacy parallel write failed: ' . $ex->getMessage());
            }
        }

        return $queueId;
    }

    /**
     * Instantiates and returns the configured channel provider.
     */
    public function getProvider($channel) {
        // Read dynamic configuration options from the general database settings
        $stmt = $this->pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if ($channel === 'whatsapp') {
            $businessId  = $settings['whatsapp_business_id'] ?? '';
            $phoneId     = $settings['whatsapp_phone_id'] ?? '';
            $accessToken = $settings['whatsapp_access_token'] ?? '';
            $apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';

            if (empty($phoneId) || empty($accessToken)) {
                throw new Exception("WhatsApp Cloud API configuration is missing or incomplete.");
            }

            return new WhatsAppCloudProvider($businessId, $phoneId, $accessToken, $apiVersion);
        } elseif ($channel === 'email') {
            return new EmailMailerProvider();
        }

        throw new Exception("Unsupported communication channel provider: {$channel}");
    }

    /**
     * Executes the dispatch logic of a specific queued message.
     */
    public function processQueueItem($queueId) {
        $item = null;
        try {
            // Atomically lock and claim the queue item to prevent concurrency issues
            $this->pdo->beginTransaction();
            
            $procStmt = $this->pdo->prepare("
                UPDATE communication_queue 
                SET status = 'processing', worker_started_at = NOW(), updated_at = NOW() 
                WHERE id = ? 
                  AND status IN ('pending', 'scheduled', 'failed')
                  AND next_attempt_at <= NOW()
                  AND retry_count < 3
            ");
            $procStmt->execute([$queueId]);
            $affected = $procStmt->rowCount();
            
            if ($affected === 0) {
                $this->pdo->rollBack();
                return false;
            }
            
            // Retrieve item details securely
            $stmt = $this->pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
            $stmt->execute([$queueId]);
            $item = $stmt->fetch();
            
            $this->pdo->commit();

            // ── MODE-ERA GUARD: Prevent stale/mode-incompatible WhatsApp dispatches ──
            $channel = $item['channel'];
            if ($channel === 'whatsapp') {
                // Read current outbound mode
                $waMode = 'manual';
                if (function_exists('whatsapp_outbound_mode')) {
                    $waMode = whatsapp_outbound_mode($this->pdo);
                } else {
                    try {
                        $modeStmt = $this->pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode' LIMIT 1");
                        $modeStmt->execute();
                        $waMode = $modeStmt->fetchColumn() ?: 'manual';
                    } catch (Exception $e) {}
                }

                $cancelReason = null;

                if ($waMode === 'manual') {
                    // Mode A: MANUAL mode — automated WhatsApp queue items must not dispatch
                    $cancelReason = 'cancelled:mode_guard_manual_active';
                } else {
                    // Mode B: META API — check if item predates the current META activation
                    try {
                        $eraStmt = $this->pdo->prepare("
                            SELECT changed_at FROM whatsapp_mode_audit 
                            WHERE new_mode = 'meta_api' 
                            ORDER BY id DESC LIMIT 1
                        ");
                        $eraStmt->execute();
                        $metaActivatedAt = $eraStmt->fetchColumn();

                        if ($metaActivatedAt && $item['created_at'] < $metaActivatedAt) {
                            $cancelReason = 'cancelled:stale_pre_meta_activation_' . $metaActivatedAt;
                        }
                    } catch (Exception $e) {
                        // If audit table unavailable, allow dispatch (fail-open for meta mode)
                        error_log('Mode-era guard: audit query failed: ' . $e->getMessage());
                    }
                }

                if ($cancelReason !== null) {
                    $cancelStmt = $this->pdo->prepare("
                        UPDATE communication_queue 
                        SET status = 'cancelled', 
                            error_message = CONCAT(IFNULL(error_message, ''), ' | ', ?), 
                            updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $cancelStmt->execute([$cancelReason, $queueId]);
                    
                    // UPDATE tracking status for installment reminders to failed (only if matching and currently queued)
                    try {
                        $updRemStmt = $this->pdo->prepare("
                            UPDATE installment_whatsapp_reminders 
                            SET status = 'failed' 
                            WHERE queue_id = ? AND status = 'queued'
                        ");
                        $updRemStmt->execute([$queueId]);
                    } catch (Exception $remEx) {}

                    error_log("Queue item #{$queueId} cancelled by mode-era guard: {$cancelReason}");
                    return false;
                }
            }

            $recipient = $item['recipient'];
            $subject = $item['subject'];
            $bodyHtml = $item['body_html'];
            $bodyText = $item['body_text'];
            
            $attachments = $item['attachments'] ? json_decode($item['attachments'], true) : [];
            $templateData = $item['template_data'] ? json_decode($item['template_data'], true) : [];

            // Template status and parameters validation before sending
            if ($channel === 'whatsapp' && !empty($item['template_name'])) {
                $stmtTpl = $this->pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? LIMIT 1");
                $stmtTpl->execute([$item['template_name']]);
                $template = $stmtTpl->fetch();
                if (!$template) {
                    throw new Exception("Template '{$item['template_name']}' not found in database.");
                }
                if (strtolower($template['status']) !== 'approved') {
                    throw new Exception("Template '{$item['template_name']}' status is '{$template['status']}' (not approved). Dispatch cancelled.");
                }
                
                // Count placeholders in template BODY
                $meta = json_decode($template['meta_data'], true) ?: [];
                $bodyTpl = $meta['body_text'] ?? '';
                preg_match_all('/\{\{(\d+)\}\}/', $bodyTpl, $matches);
                $expectedParamsCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
                
                $providedParams = $templateData['parameters'] ?? [];
                if (count($providedParams) < $expectedParamsCount) {
                    throw new Exception("Parameter count mismatch: Template expects {$expectedParamsCount} parameters, only " . count($providedParams) . " provided.");
                }
            }

            $provider = $this->getProvider($channel);
            
            // Log API request time
            $updReqStmt = $this->pdo->prepare("UPDATE communication_queue SET api_requested_at = NOW() WHERE id = ?");
            $updReqStmt->execute([$queueId]);
            
            $res = $provider->sendMessage($recipient, $subject, $bodyHtml, $bodyText, $attachments, $templateData);
            
            // Log API response time
            $updRespStmt = $this->pdo->prepare("UPDATE communication_queue SET api_responded_at = NOW() WHERE id = ?");
            $updRespStmt->execute([$queueId]);

            if ($res && isset($res['success']) && $res['success'] === true) {
                // Sent successfully
                $msgId = $res['message_id'];
                
                $doneStmt = $this->pdo->prepare("
                    UPDATE communication_queue 
                    SET status = 'sent', message_id = ?, error_message = NULL, updated_at = NOW() 
                    WHERE id = ?
                ");
                $doneStmt->execute([$msgId, $queueId]);

                // Update installment reminder tracking table to 'sent'
                try {
                    $updRemStmt = $this->pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'sent' WHERE queue_id = ?");
                    $updRemStmt->execute([$queueId]);
                } catch (Exception $remEx) {}

                // Log outbound message in WhatsApp Inbox (Safeguard 1 & 2)
                if ($channel === 'whatsapp' && !empty($msgId)) {
                    try {
                        $cleanPhone = preg_replace('/\D/', '', $recipient);
                        $last10 = substr($cleanPhone, -10);

                        $studentMatch = null;
                        $stmtMatch = $this->pdo->prepare("
                            SELECT id, user_id, name, whatsapp_country_code, whatsapp_number 
                            FROM users 
                            WHERE status = 'approved' 
                              AND whatsapp_number LIKE ?
                        ");
                        $stmtMatch->execute(['%' . $last10]);
                        $allMatches = $stmtMatch->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($allMatches as $u) {
                            $uClean = preg_replace('/\D/', '', $u['whatsapp_country_code'] . $u['whatsapp_number']);
                            $uLast10 = substr(preg_replace('/\D/', '', $u['whatsapp_number']), -10);
                            if ($uClean === $cleanPhone || $uLast10 === $last10) {
                                $studentMatch = $u;
                                break;
                            }
                        }

                        $contactName = $studentMatch['name'] ?? 'Unknown WhatsApp Contact';
                        $studentUid = $studentMatch['user_id'] ?? null;
                        $studentUserId = $studentMatch['id'] ?? null;
                        
                        $this->pdo->beginTransaction();

                        // Find or create conversation
                        $stmtConv = $this->pdo->prepare("SELECT id FROM whatsapp_conversations WHERE wa_phone_number = ? LIMIT 1");
                        $stmtConv->execute([$cleanPhone]);
                        $convId = $stmtConv->fetchColumn();

                        $bodyText = $item['body_text'] ?? '';

                        if (!$convId) {
                            $insConv = $this->pdo->prepare("
                                INSERT INTO whatsapp_conversations (wa_phone_number, student_uid, student_user_id, contact_name, last_message_text, last_message_at, unread_count, status, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, NOW(), 0, 'open', NOW(), NOW())
                            ");
                            $insConv->execute([$cleanPhone, $studentUid, $studentUserId, $contactName, $bodyText]);
                            $convId = (int)$this->pdo->lastInsertId();
                        } else {
                            $updConv = $this->pdo->prepare("
                                UPDATE whatsapp_conversations 
                                SET student_uid = ?, 
                                    student_user_id = ?, 
                                    contact_name = ?, 
                                    last_message_text = ?, 
                                    last_message_at = NOW(), 
                                    updated_at = NOW() 
                                WHERE id = ?
                            ");
                            $updConv->execute([$studentUid, $studentUserId, $contactName, $bodyText, $convId]);
                        }

                        // Insert outbound message with status 'sent'
                        $msgType = (isset($templateData['type']) && $templateData['type'] === 'interactive') ? 'interactive' : 'text';
                        $rawPayloadJson = !empty($item['template_data']) ? $item['template_data'] : null;
                        $insMsg = $this->pdo->prepare("
                            INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, status, raw_payload, created_at, sent_at)
                            VALUES (?, ?, 'outbound', ?, ?, 'sent', ?, NOW(), NOW())
                        ");
                        $insMsg->execute([$convId, $msgId, $msgType, $bodyText, $rawPayloadJson]);

                        $this->pdo->commit();
                    } catch (Exception $e) {
                        if ($this->pdo->inTransaction()) {
                            $this->pdo->rollBack();
                        }
                        error_log("WhatsApp Inbox log outbound message failed: " . $e->getMessage());
                    }
                }

                // Sync status to legacy log table if applicable
                if ($channel === 'whatsapp' && strpos((string)$item['error_message'], 'legacy_id:') === 0) {
                    $legacyId = (int)substr((string)$item['error_message'], 10);
                    $legStmt = $this->pdo->prepare("UPDATE whatsapp_notifications SET status = 'sent', updated_at = NOW() WHERE id = ?");
                    $legStmt->execute([$legacyId]);
                }
                return true;
            } else {
                $errMsg = ($provider instanceof WhatsAppCloudProvider) ? $provider->getLastError() : 'Provider failed to dispatch message.';
                throw new Exception($errMsg);
            }

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            
            // Ensure api_responded_at is filled if the request was made but failed/threw exception
            try {
                $checkStmt = $this->pdo->prepare("SELECT api_requested_at, api_responded_at FROM communication_queue WHERE id = ?");
                $checkStmt->execute([$queueId]);
                $chk = $checkStmt->fetch();
                if ($chk && $chk['api_requested_at'] && !$chk['api_responded_at']) {
                    $updRespStmt = $this->pdo->prepare("UPDATE communication_queue SET api_responded_at = NOW() WHERE id = ?");
                    $updRespStmt->execute([$queueId]);
                }
            } catch (Exception $ex_resp) {}
            
            $errMsg = $e->getMessage();
            $retryCount = $item ? ((int)$item['retry_count'] + 1) : 1;
            $maxRetries = ($item && $item['channel'] === 'whatsapp') ? 3 : 5;
            $chan = $item ? $item['channel'] : 'whatsapp';
            $legacyErr = $item ? (string)$item['error_message'] : '';

            if ($retryCount >= $maxRetries) {
                $status = 'failed';
                $nextAttempt = date('Y-m-d H:i:s', time() + 3600 * 24 * 365); // Far future
            } else {
                $status = 'failed'; // kept in failed but scheduling retries
                $backoffMinutes = pow(2, $retryCount); // Exponential backoff: 2m, 4m, 8m...
                $nextAttempt = date('Y-m-d H:i:s', time() + (60 * $backoffMinutes));
            }

            $failStmt = $this->pdo->prepare("
                UPDATE communication_queue 
                SET status = ?, retry_count = ?, last_retry_at = NOW(), next_attempt_at = ?, error_message = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $failStmt->execute([
                $status,
                $retryCount,
                $nextAttempt,
                $errMsg,
                $queueId
            ]);

            // If the status is final 'failed' (max retries reached), update tracking row to 'failed'
            if ($status === 'failed' && $retryCount >= $maxRetries) {
                try {
                    $updRemStmt = $this->pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'failed' WHERE queue_id = ?");
                    $updRemStmt->execute([$queueId]);
                } catch (Exception $remEx) {}
            }

            // Sync status to legacy log table if applicable
            if ($chan === 'whatsapp' && strpos($legacyErr, 'legacy_id:') === 0) {
                $legacyId = (int)substr($legacyErr, 10);
                $legStmt = $this->pdo->prepare("UPDATE whatsapp_notifications SET status = 'failed', updated_at = NOW() WHERE id = ?");
                $legStmt->execute([$legacyId]);
            }

            error_log("Communication Queue Dispatch Failed for ID {$queueId}: {$errMsg}");
            return false;
        }
    }

    /**
     * Resolves mapped parameters for a given event, student ID, and custom context data.
     *
     * @param string $eventName
     * @param string|null $studentUid
     * @param array $contextData
     * @return array|null Array containing 'name', 'language', and 'parameters', or null if not mapped
     */
    public function resolveEventTemplate($eventName, $studentUid = null, array $contextData = []) {
        $stmt = $this->pdo->prepare("SELECT * FROM communication_event_mappings WHERE event_name = ? LIMIT 1");
        $stmt->execute([$eventName]);
        $mapping = $stmt->fetch();
        
        if (!$mapping || empty($mapping['template_name'])) {
            return null; // Event not mapped or mapping is disabled
        }
        
        $templateName = $mapping['template_name'];
        
        $stmtTpl = $this->pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? LIMIT 1");
        $stmtTpl->execute([$templateName]);
        $template = $stmtTpl->fetch();
        
        if (!$template) {
            throw new Exception("Mapped template '{$templateName}' not found in database.");
        }
        
        if (strtolower($template['status']) !== 'approved') {
            throw new Exception("Mapped template '{$templateName}' status is '{$template['status']}' (not approved).");
        }
        
        $langCode = $template['language'] ?? 'en';
        
        // Fetch student details from ERP if studentUid is provided
        $student = null;
        if ($studentUid) {
            $stmtStud = $this->pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
            $stmtStud->execute([$studentUid]);
            $student = $stmtStud->fetch();
        }
        
        $parameterMappings = json_decode($mapping['parameter_mappings'], true) ?: [];
        $resolvedParameters = [];
        
        // Sort keys numerically to match template parameter indexes (e.g. 1, 2, 3...)
        ksort($parameterMappings);

        // Pre-fetch installment details for calculations if student context exists
        $collected = 0.0;
        $nextDueDate = 'N/A';
        if ($student) {
            $collected = (float)($student['paid_amount'] ?? 0);
            try {
                $instStmt = $this->pdo->prepare("
                    SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0)
                    FROM instalment_details 
                    WHERE user_id = ? AND status IN ('approved', 'paid')
                ");
                $instStmt->execute([$student['user_id']]);
                $instPaid = (float)$instStmt->fetchColumn();
                $collected += $instPaid;
            } catch (Exception $e) {}
            
            try {
                $dueStmt = $this->pdo->prepare("
                    SELECT due_date FROM instalment_details 
                    WHERE user_id = ? AND status = 'pending' AND due_date >= CURRENT_DATE
                    ORDER BY instalment_number ASC LIMIT 1
                ");
                $dueStmt->execute([$student['user_id']]);
                $dueDateVal = $dueStmt->fetchColumn();
                if ($dueDateVal) {
                    $nextDueDate = date('d M Y', strtotime($dueDateVal));
                }
            } catch (Exception $e) {}
        }
        
        foreach ($parameterMappings as $idx => $mapInfo) {
            $type = $mapInfo['type'] ?? 'variable';
            $val = $mapInfo['value'] ?? '';
            
            if ($type === 'custom') {
                $resolvedParameters[] = $val;
            } else {
                $resolvedVal = '';
                if (isset($contextData[$val])) {
                    $resolvedVal = $contextData[$val];
                } elseif ($student) {
                    if ($val === 'student_name') {
                        $resolvedVal = $student['name'] ?? '';
                    } elseif ($val === 'application_id') {
                        $resolvedVal = $student['user_id'] ?? '';
                    } elseif ($val === 'course_name') {
                        $resolvedVal = $student['pepp_course'] ?? '';
                    } elseif ($val === 'student_phone') {
                        $resolvedVal = ($student['whatsapp_country_code'] ?? '') . ($student['whatsapp_number'] ?? '');
                    } elseif ($val === 'student_email') {
                        $resolvedVal = $student['email'] ?? '';
                    } elseif ($val === 'academic_year') {
                        $resolvedVal = $student['pepp_academic_year'] ?? '';
                    } elseif ($val === 'payment_amount' || $val === 'paid_amount') {
                        $resolvedVal = number_format((float)($student['paid_amount'] ?? 0));
                    } elseif ($val === 'paid_date') {
                        $resolvedVal = !empty($student['paid_date']) ? date('d M Y', strtotime($student['paid_date'])) : '';
                    } elseif ($val === 'payment_plan') {
                        $resolvedVal = $student['payment_plan'] ?? '';
                    } elseif ($val === 'payment_mode') {
                        $resolvedVal = $student['payment_mode'] ?? '';
                    } elseif ($val === 'course_fee') {
                        $courseFee = (float)($student['total_fee'] ?? 0) + (float)($student['discount_amount'] ?? 0);
                        $resolvedVal = number_format($courseFee);
                    } elseif ($val === 'discount_amount') {
                        $resolvedVal = number_format((float)($student['discount_amount'] ?? 0));
                    } elseif ($val === 'total_payable') {
                        $resolvedVal = number_format((float)($student['total_fee'] ?? 0));
                    } elseif ($val === 'total_paid') {
                        $resolvedVal = number_format($collected);
                    } elseif ($val === 'balance_amount') {
                        $balance = max(0, (float)($student['total_fee'] ?? 0) - $collected);
                        $resolvedVal = number_format($balance);
                    } elseif ($val === 'next_due_date' || $val === 'installment_due_date') {
                        $resolvedVal = $nextDueDate;
                    } elseif ($val === 'new_access_end' || $val === 'course_duration_date' || $val === 'access_end') {
                        $resolvedVal = !empty($student['course_duration_date']) ? date('d M Y', strtotime($student['course_duration_date'])) : '';
                    } elseif ($val === 'installment_number') {
                        $instStmt = $this->pdo->prepare("SELECT instalment_number FROM instalment_details WHERE user_id = ? AND status = 'pending' AND paid_date IS NULL ORDER BY instalment_number ASC LIMIT 1");
                        $instStmt->execute([$student['user_id']]);
                        $rawNum = $instStmt->fetchColumn() ?: '1';
                        $ord = (int)$rawNum;
                        if ($ord === 1) $resolvedVal = "1st";
                        elseif ($ord === 2) $resolvedVal = "2nd";
                        elseif ($ord === 3) $resolvedVal = "3rd";
                        else $resolvedVal = $ord . "th";
                    } elseif ($val === 'installment_amount') {
                        $instStmt = $this->pdo->prepare("SELECT amount FROM instalment_details WHERE user_id = ? AND status = 'pending' AND paid_date IS NULL ORDER BY instalment_number ASC LIMIT 1");
                        $instStmt->execute([$student['user_id']]);
                        $resolvedVal = number_format((float)($instStmt->fetchColumn() ?: 0));
                    } elseif ($val === 'banking_details') {
                        try {
                            $public_accs = $this->pdo->query("SELECT account_name, banking_details FROM payment_accounts WHERE is_public = 1 AND status = 'active' LIMIT 2")->fetchAll();
                            $details_arr = [];
                            foreach ($public_accs as $pa) {
                                $details_arr[] = $pa['account_name'] . ($pa['banking_details'] ? " (" . $pa['banking_details'] . ")" : "");
                            }
                            $resolvedVal = implode(" or ", $details_arr);
                        } catch (Exception $e) {
                            $resolvedVal = '';
                        }
                    }
                }
                
                // Fallbacks
                if ($resolvedVal === '') {
                    if ($val === 'current_datetime') {
                        $resolvedVal = date('d M Y h:i A');
                    }
                }
                
                $resolvedParameters[] = $resolvedVal;
            }
        }
        
        $result = [
            'name' => $templateName,
            'language' => $langCode,
            'parameters' => $resolvedParameters
        ];

        // Inspect template components to check if there is a URL button ending in ?token={{1}}
        $meta = json_decode($template['meta_data'], true) ?: [];
        $hasUrlButton = false;
        if (isset($meta['components']) && is_array($meta['components'])) {
            foreach ($meta['components'] as $comp) {
                if (($comp['type'] ?? '') === 'BUTTONS' && isset($comp['buttons']) && is_array($comp['buttons'])) {
                    foreach ($comp['buttons'] as $btn) {
                        if (($btn['type'] ?? '') === 'URL' && strpos($btn['url'] ?? '', 'token={{1}}') !== false) {
                            $hasUrlButton = true;
                            break 2;
                        }
                    }
                }
            }
        }

        // Retrieve invoice ID for generating dynamic URL token
        $invoiceId = $contextData['invoice_id'] ?? null;
        if ($hasUrlButton) {
            if (!$invoiceId && $student) {
                try {
                    $invStmt = $this->pdo->prepare("
                        SELECT id FROM invoices 
                        WHERE user_id = ? AND source = 'registration' 
                        ORDER BY id DESC LIMIT 1
                    ");
                    $invStmt->execute([$student['user_id']]);
                    $invoiceId = $invStmt->fetchColumn();
                } catch (Exception $e) {}
            }
            if ($invoiceId) {
                $hmac = hash_hmac('sha256', (string)$invoiceId, INVOICE_HMAC_SECRET);
                $result['button_parameters'] = [$invoiceId . '-' . $hmac];
            }
        }

        return $result;
    }

    /**
     * Resolves event mapping and queues the resolved WhatsApp template message.
     *
     * @param string $eventName
     * @param string $recipient Phone number
     * @param array $contextData Variable values or context e.g. ['student_uid' => '123', 'payment_amount' => 500]
     * @param string $sentBy Admin user triggering the event
     * @param string|null $scheduledAt
     * @return int|null Queue ID, or null if mapping is disabled/not found
     */
    public function sendEventNotification($eventName, $recipient, array $contextData = [], $sentBy = 'system', $scheduledAt = null) {
        $this->lastError = null;
        $studentUid = $contextData['student_uid'] ?? null;
        $invoiceId = $contextData['invoice_id'] ?? null;
        
        try {
            $resolved = $this->resolveEventTemplate($eventName, $studentUid, $contextData);
            if (!$resolved) {
                $this->lastError = "Event '{$eventName}' not mapped to a template or mapping is disabled.";
                return null;
            }

            // Strict duplicate notification check based on student_uid + event_name + template_name
            if ($studentUid && $eventName && $resolved['name']) {
                if ($eventName === 'payment_receipt' && $invoiceId) {
                    $dupStmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM communication_queue 
                        WHERE student_uid = ? AND event_name = ? AND template_name = ? AND invoice_id = ?
                          AND status IN ('pending', 'processing', 'sent', 'delivered', 'read')
                    ");
                    $dupStmt->execute([$studentUid, $eventName, $resolved['name'], $invoiceId]);
                } elseif ($eventName === 'payment_rejection' && $invoiceId) {
                    // Tie duplicate check to the specific installment rejection request ID
                    $dupStmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM communication_queue 
                        WHERE student_uid = ? AND event_name = ? AND template_name = ? AND invoice_id = ?
                          AND status IN ('pending', 'processing')
                    ");
                    $dupStmt->execute([$studentUid, $eventName, $resolved['name'], $invoiceId]);
                } else {
                    $dupStmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM communication_queue 
                        WHERE student_uid = ? AND event_name = ? AND template_name = ? 
                          AND status IN ('pending', 'processing', 'sent', 'delivered', 'read')
                    ");
                    $dupStmt->execute([$studentUid, $eventName, $resolved['name']]);
                }
                $exists = (int)$dupStmt->fetchColumn();
                if ($exists > 0) {
                    $this->lastError = "Duplicate prevention blocked enqueuing (already sent or queued).";
                    error_log("Duplicate prevention blocked enqueuing for student: {$studentUid}, event: {$eventName}, template: {$resolved['name']}");
                    return null;
                }
            }
            
            // Build simple fallback text representation
            $bodyText = "WhatsApp Template: " . $resolved['name'] . "\nParameters:\n";
            foreach ($resolved['parameters'] as $i => $p) {
                $bodyText .= "Param " . ($i + 1) . ": " . $p . "\n";
            }
            
            $queueId = $this->queueMessage(
                'whatsapp',
                $recipient,
                $contextData['student_name'] ?? null,
                "Event Notification: {$eventName}",
                $bodyText,
                $bodyText,
                [], // attachments
                $resolved, // templateData
                $sentBy,
                $scheduledAt,
                $studentUid,
                $eventName,
                $invoiceId
            );

            if ($queueId) {
                $this->dispatchQueueItemAsync($queueId);
            }

            return $queueId;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Failed to sendEventNotification for event {$eventName}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Spawns an immediate background process to execute cron-queue.php for a specific queue item.
     * Uses proc_open CLI trigger (verified to be working and reliable on Hostinger production).
     *
     * @param int $queueId
     * @return bool
     */
    public function dispatchQueueItemAsync($queueId) {
        if ($this->pdo->inTransaction()) {
            // Defer dispatch to background cron to prevent nested transactions and unsafe external API calls during transaction
            return true;
        }

        $phpBinary = 'php';
        $cronScript = dirname(dirname(__DIR__)) . '/cron-queue.php';
        
        if (!file_exists($cronScript)) {
            error_log("Async Dispatch Error: cron-queue.php not found at {$cronScript}");
            return false;
        }
        
        if (substr(php_uname(), 0, 7) === "Windows") {
            $cmd = "start /B " . $phpBinary . " " . escapeshellarg($cronScript) . " " . (int)$queueId . " > NUL 2>&1";
        } else {
            $cmd = $phpBinary . " " . escapeshellarg($cronScript) . " " . (int)$queueId . " > /dev/null 2>&1 &";
        }
        
        if (!function_exists('proc_open')) {
            error_log("Async Dispatch: proc_open is disabled. Falling back to synchronous execution for queue item #{$queueId}");
            return $this->processQueueItem($queueId);
        }

        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["file", "/dev/null", "w"],
            2 => ["file", "/dev/null", "w"]
        ];
        
        try {
            $process = @proc_open($cmd, $descriptorspec, $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                proc_close($process);
                return true;
            } else {
                error_log("Async Dispatch Error: proc_open failed. Falling back to synchronous execution for queue item #{$queueId}");
                return $this->processQueueItem($queueId);
            }
        } catch (\Throwable $e) {
            error_log("Async Dispatch Throwable (proc_open disabled or failed): " . $e->getMessage() . ". Falling back to synchronous execution for queue item #{$queueId}");
            return $this->processQueueItem($queueId);
        }
    }
}
