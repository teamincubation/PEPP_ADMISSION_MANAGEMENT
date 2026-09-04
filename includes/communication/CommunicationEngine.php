<?php
/**
 * Central Communication Engine.
 * Manages the dispatch queue, channels providers routing, templates variable
 * interpolation, and Meta webhook events.
 */

require_once __DIR__ . '/Providers/CommunicationProviderInterface.php';
require_once __DIR__ . '/Providers/WhatsAppCloudProvider.php';
require_once __DIR__ . '/Providers/EmailMailerProvider.php';
require_once __DIR__ . '/CommunicationHelper.php';

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

        $normalizedRecipient = self::normalizePhone($recipient);

        // Permanent recipient failure suppression check for automatic actions
        if ($channel === 'whatsapp' && !empty($normalizedRecipient) && in_array($sentBy, ['system', 'system_scheduler', 'system_test'], true)) {
            $stmtSupp = $this->pdo->prepare("
                SELECT error_message FROM communication_queue
                WHERE recipient = ? AND status = 'failed'
                  AND retry_count >= 2
                  AND (
                    error_message LIKE '%healthy ecosystem engagement%' OR
                    error_message LIKE '%131026%' OR
                    error_message LIKE '%policy%' OR
                    error_message LIKE '%not in allowed list%' OR
                    error_message LIKE '%invalid phone number%' OR
                    error_message LIKE '%does not exist%' OR
                    error_message LIKE '%recipient%' OR
                    error_message LIKE '%undeliverable%' OR
                    error_message LIKE '%not a whatsapp number%' OR
                    error_message LIKE '%Suppressed%'
                  )
                LIMIT 1
            ");
            $stmtSupp->execute([$normalizedRecipient]);
            $suppressedMsg = $stmtSupp->fetchColumn();

            if ($suppressedMsg) {
                $status = 'failed';
                $errorMsg = 'Suppressed: WhatsApp recipient previously failed permanently';
                $retryCount = 3; // Block execution
            }
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

    public $mockProvider = null;

    /**
     * Instantiates and returns the configured channel provider.
     */
    public function getProvider($channel) {
        if ($this->mockProvider !== null) {
            return $this->mockProvider;
        }
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
        if ($this->isQueuePaused()) {
            error_log("[QUEUE_PAUSED] Global queue processing is paused. Skipping queue item #{$queueId}.");
            return false;
        }

        $item = null;
        try {
            // Atomically lock and claim the queue item to prevent concurrency issues
            $this->pdo->beginTransaction();

            $procStmt = $this->pdo->prepare("
                UPDATE communication_queue
                SET status = 'processing', worker_started_at = NOW(), updated_at = NOW()
                WHERE id = ?
                  AND status IN ('pending', 'scheduled', 'failed', 'retrying')
                  AND next_attempt_at <= NOW()
                  AND (
                    (channel = 'whatsapp' AND retry_count < 3) OR
                    (channel = 'email' AND retry_count < 5) OR
                    (channel NOT IN ('whatsapp', 'email') AND retry_count < 3)
                  )
            ");
            $procStmt->execute([$queueId]);
            $affected = $procStmt->rowCount();

            if ($affected === 0) {
                $this->pdo->rollBack();
                return false;
            }

            error_log("[QUEUE_CLAIM] queue_id={$queueId} claimed by worker.");

            // Retrieve item details securely
            $stmt = $this->pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
            $stmt->execute([$queueId]);
            $item = $stmt->fetch();

            // Permanent WhatsApp recipient suppression check
            if ($item && $item['channel'] === 'whatsapp' && in_array($item['sent_by'] ?? 'system', ['system', 'system_scheduler', 'system_test'], true)) {
                $normalizedRecipient = self::normalizePhone($item['recipient']);
                $stmtSupp = $this->pdo->prepare("
                    SELECT id FROM communication_queue
                    WHERE recipient = ? AND status = 'failed'
                      AND id != ?
                      AND retry_count >= 2
                      AND (
                        error_message LIKE '%healthy ecosystem engagement%' OR
                        error_message LIKE '%131026%' OR
                        error_message LIKE '%policy%' OR
                        error_message LIKE '%not in allowed list%' OR
                        error_message LIKE '%invalid phone number%' OR
                        error_message LIKE '%does not exist%' OR
                        error_message LIKE '%recipient%' OR
                        error_message LIKE '%undeliverable%' OR
                        error_message LIKE '%not a whatsapp number%' OR
                        error_message LIKE '%Suppressed%'
                      )
                    LIMIT 1
                ");
                $stmtSupp->execute([$normalizedRecipient, $queueId]);
                $hasFailedBefore = $stmtSupp->fetchColumn();

                if ($hasFailedBefore) {
                    $updSupp = $this->pdo->prepare("
                        UPDATE communication_queue
                        SET status = 'failed',
                            retry_count = 3,
                            error_message = 'Suppressed: WhatsApp recipient previously failed permanently',
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updSupp->execute([$queueId]);

                    try {
                        $updCampStmt = $this->pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = 'Suppressed: WhatsApp recipient previously failed permanently' WHERE queue_id = ?");
                        $updCampStmt->execute([$queueId]);
                    } catch (Exception $e) {}

                    try {
                        $updRemStmt = $this->pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'failed' WHERE queue_id = ?");
                        $updRemStmt->execute([$queueId]);
                    } catch (Exception $e) {}

                    error_log("[QUEUE_SUPPRESSED] Suppressed dispatch for queue ID {$queueId} targeting previously failed recipient {$normalizedRecipient}");
                    $this->pdo->commit();
                    return false;
                }
            }

            // Pre-send validation check
            if ($item && $item['channel'] === 'whatsapp' && !empty($item['student_uid'])) {
                $studStmt = $this->pdo->prepare("SELECT whatsapp_country_code, whatsapp_number FROM users WHERE user_id = ?");
                $studStmt->execute([$item['student_uid']]);
                $student = $studStmt->fetch();
                if ($student) {
                    $currentPhone = self::normalizePhone($student['whatsapp_country_code'] . $student['whatsapp_number']);
                    $queuedPhone = self::normalizePhone($item['recipient']);
                    if ($currentPhone !== $queuedPhone) {
                        // Mark old queue item as failed / superseded (storing the exact mismatch reason)
                        $updStale = $this->pdo->prepare("
                            UPDATE communication_queue
                            SET status = 'failed',
                                error_message = 'Superseded: Recipient number changed',
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $updStale->execute([$queueId]);

                        // Try to re-enqueue for the new phone number (with duplicate check)
                        $dupStmt = $this->pdo->prepare("
                            SELECT COUNT(*) FROM communication_queue
                            WHERE student_uid = ?
                              AND (event_name = ? OR (event_name IS NULL AND ? IS NULL))
                              AND recipient = ?
                              AND status IN ('pending', 'processing', 'sent', 'delivered', 'read')
                        ");
                        $dupStmt->execute([$item['student_uid'], $item['event_name'], $item['event_name'], $currentPhone]);
                        $exists = (int)$dupStmt->fetchColumn();

                        if ($exists === 0) {
                            $insStmt = $this->pdo->prepare("
                                INSERT INTO communication_queue
                                (channel, recipient, recipient_name, subject, body_html, body_text, template_name, template_data, attachments, status, next_attempt_at, sent_by, student_uid, event_name, invoice_id, error_message, retry_count, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, NULL, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ");
                            $insStmt->execute([
                                $item['channel'],
                                $currentPhone,
                                $item['recipient_name'],
                                $item['subject'],
                                $item['body_html'],
                                $item['body_text'],
                                $item['template_name'],
                                $item['template_data'],
                                $item['attachments'],
                                $item['sent_by'],
                                $item['student_uid'],
                                $item['event_name'],
                                $item['invoice_id']
                            ]);
                            $newQueueId = (int)$this->pdo->lastInsertId();

                            // Update tracking table installment_whatsapp_reminders
                            $updTrack = $this->pdo->prepare("
                                UPDATE installment_whatsapp_reminders
                                SET queue_id = ?
                                WHERE queue_id = ?
                            ");
                            $updTrack->execute([$newQueueId, $queueId]);
                            error_log("[QUEUE_REENQUEUED] old_queue_id={$queueId} new_queue_id={$newQueueId} student_id={$item['student_uid']} recipient={$currentPhone}");
                        }

                        error_log("[QUEUE_RECIPIENT_MISMATCH] queue_id={$queueId} student_uid={$item['student_uid']} queued_phone={$queuedPhone} current_phone={$currentPhone} action=superseded");

                        $this->pdo->commit();
                        return false; // Stop execution
                    }
                }
            }

            // Fail-closed student status validation:
            // Transactional communications (invoices, installments, receipts, registration, onboarding, security alerts) are explicitly whitelisted and proceed for all students.
            // All non-transactional academic/study plan communications fail closed for non-active students (dropouts, suspended, inactive).
            $transactional_events = [
                'invoice_email',
                'installment_reminder',
                'installment_email',
                'installment_overdue',
                'payment_receipt',
                'payment_confirmation',
                'payment_reminder',
                'payment_rejection',
                'fee_update',
                'student_registration',
                'student_approval',
                'student_rejection',
                'student_welcome',
                'student_onboarding',
                'onboarding_app_access',
                'course_migration_completed',
                'course_migration',
                'session_scheduled',
                'session_reminder',
                'activity_log_export',
                'email_reports_export',
                'monthly_backup',
                'system_alert',
                'password_reset',
                'account_security',
                'auth_verification',
                'alumni_verification_completed',
                'alumni_referral_code_generated'
            ];
            $eventName = strtolower(trim((string)($item['event_name'] ?? '')));
            $isTransactional = in_array($eventName, $transactional_events, true)
                || strpos($eventName, 'installment_') === 0
                || strpos($eventName, 'payment_') === 0
                || strpos($eventName, 'invoice_') === 0
                || strpos($eventName, 'fee_') === 0
                || strpos($eventName, 'registration') !== false
                || strpos($eventName, 'student_') === 0
                || strpos($eventName, 'onboarding') !== false
                || strpos($eventName, 'admission') !== false
                || strpos($eventName, 'migration') !== false
                || strpos($eventName, 'auth_') === 0
                || strpos($eventName, 'lead_') === 0
                || strpos($eventName, 'alumni_') === 0
                || strpos($eventName, 'referral_') === 0;

            if (!$isTransactional) {
                $recipientIdent = !empty($item['student_uid']) ? $item['student_uid'] : $item['recipient'];
                require_once __DIR__ . '/../auth.php';
                $st_status = get_student_status($this->pdo, $recipientIdent);

                // If recipient is a student in the users table and is not strictly active -> cancel
                if ($st_status !== 'unknown' && !is_student_active($this->pdo, $recipientIdent)) {
                    $reason = get_student_status_reason($this->pdo, $recipientIdent, $st_status);
                    $cancelMsg = "Non-transactional communication skipped: student status is '{$st_status}'" . ($reason ? " (Reason: {$reason})" : "");
                    $cancelStmt = $this->pdo->prepare("UPDATE communication_queue SET status = 'cancelled', error_message = ?, updated_at = NOW() WHERE id = ?");
                    $cancelStmt->execute([$cancelMsg, $queueId]);
                    error_log("[COMMUNICATION_CANCELLED] queue_id={$queueId} recipient={$recipientIdent} event={$eventName} status={$st_status}");
                    $this->pdo->commit();
                    return false;
                }
            }

            // Check campaign status and compliance if this queue item is part of a bulk campaign
            $campStmt = $this->pdo->prepare("
                SELECT c.id as campaign_id, c.status as campaign_status, c.target_audience, r.lead_id, r.id as recipient_id
                FROM communication_campaigns c
                JOIN communication_campaign_recipients r ON c.id = r.campaign_id
                WHERE r.queue_id = ? LIMIT 1
            ");
            $campStmt->execute([$queueId]);
            $campInfo = $campStmt->fetch();

            if ($campInfo) {
                $campStatus = $campInfo['campaign_status'];
                if ($campStatus === 'paused') {
                    // Revert status back to pending
                    $revertStmt = $this->pdo->prepare("UPDATE communication_queue SET status = 'pending', worker_started_at = NULL WHERE id = ?");
                    $revertStmt->execute([$queueId]);
                    $this->pdo->commit();
                    return false;
                } elseif ($campStatus === 'cancelled') {
                    // Cancel queue item
                    $cancelStmt = $this->pdo->prepare("UPDATE communication_queue SET status = 'cancelled', error_message = 'Campaign cancelled', updated_at = NOW() WHERE id = ?");
                    $cancelStmt->execute([$queueId]);

                    // Mark recipient failed
                    $recipStmt = $this->pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = 'Campaign cancelled' WHERE id = ?");
                    $recipStmt->execute([$campInfo['recipient_id']]);

                    $this->pdo->commit();
                    return false;
                }

                // Pre-dispatch compliance opt-out check for lead campaigns
                if ($campInfo['target_audience'] === 'leads' && !empty($campInfo['lead_id'])) {
                    $optOutStmt = $this->pdo->prepare("SELECT is_opted_out FROM leads WHERE id = ? LIMIT 1");
                    $optOutStmt->execute([$campInfo['lead_id']]);
                    $leadOptedOut = (int)$optOutStmt->fetchColumn();

                    if ($leadOptedOut === 1) {
                        // Cancel queue item
                        $cancelStmt = $this->pdo->prepare("UPDATE communication_queue SET status = 'cancelled', error_message = 'Lead opted out before dispatch', updated_at = NOW() WHERE id = ?");
                        $cancelStmt->execute([$queueId]);

                        // Mark campaign recipient as failed/skipped
                        $recipStmt = $this->pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = 'Lead opted out before dispatch' WHERE id = ?");
                        $recipStmt->execute([$campInfo['recipient_id']]);

                        $this->pdo->commit();
                        return false;
                    }
                }
            }

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

                // Dynamically inject Quick Reply payloads from template metadata if configured
                $quickReplyPayloads = [];
                $qrConfig = $meta['buttons']['quick_reply'] ?? [];
                for ($idx = 1; $idx <= 3; $idx++) {
                    $btnData = $qrConfig[$idx] ?? null;
                    if ($btnData && isset($btnData['text']) && trim($btnData['text']) !== '') {
                        $quickReplyPayloads[] = trim($btnData['payload'] ?? '');
                    }
                }
                if (!empty($quickReplyPayloads)) {
                    $templateData['quick_reply_payloads'] = $quickReplyPayloads;
                }
            }

            $provider = $this->getProvider($channel);

            // Race-condition revalidation right before Meta API dispatch
            $checkStmt = $this->pdo->prepare("SELECT status, recipient, student_uid, event_name, recipient_name, subject, body_html, body_text, template_name, template_data, attachments, sent_by, invoice_id FROM communication_queue WHERE id = ?");
            $checkStmt->execute([$queueId]);
            $chkItem = $checkStmt->fetch();

            if (!$chkItem) {
                return false;
            }

            if ($chkItem['status'] === 'cancelled' || $chkItem['status'] === 'paused') {
                error_log("[QUEUE_RACE_ABORT] Queue item #{$queueId} was updated to {$chkItem['status']} by admin before Meta API dispatch. Aborting.");
                return false;
            }

            if (!empty($chkItem['student_uid'])) {
                $studStmt = $this->pdo->prepare("SELECT whatsapp_country_code, whatsapp_number FROM users WHERE user_id = ?");
                $studStmt->execute([$chkItem['student_uid']]);
                $student = $studStmt->fetch();
                if ($student) {
                    $currentPhone = self::normalizePhone($student['whatsapp_country_code'] . $student['whatsapp_number']);
                    $queuedPhone = self::normalizePhone($chkItem['recipient']);
                    if ($currentPhone !== $queuedPhone) {
                        // Recipient changed! Mark old item as superseded
                        $updStale = $this->pdo->prepare("UPDATE communication_queue SET status = 'failed', error_message = 'Superseded: Recipient number changed', updated_at = NOW() WHERE id = ?");
                        $updStale->execute([$queueId]);

                        // Enqueue replacement for new number
                        $dupStmt = $this->pdo->prepare("
                            SELECT COUNT(*) FROM communication_queue
                            WHERE student_uid = ?
                              AND (event_name = ? OR (event_name IS NULL AND ? IS NULL))
                              AND recipient = ?
                              AND status IN ('pending', 'processing', 'sent', 'delivered', 'read')
                        ");
                        $dupStmt->execute([$chkItem['student_uid'], $chkItem['event_name'], $chkItem['event_name'], $currentPhone]);
                        $exists = (int)$dupStmt->fetchColumn();

                        if ($exists === 0) {
                            $insStmt = $this->pdo->prepare("
                                INSERT INTO communication_queue
                                (channel, recipient, recipient_name, subject, body_html, body_text, template_name, template_data, attachments, status, next_attempt_at, sent_by, student_uid, event_name, invoice_id, error_message, retry_count, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, NULL, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ");
                            $insStmt->execute([
                                'whatsapp',
                                $currentPhone,
                                $chkItem['recipient_name'],
                                $chkItem['subject'],
                                $chkItem['body_html'],
                                $chkItem['body_text'],
                                $chkItem['template_name'],
                                $chkItem['template_data'],
                                $chkItem['attachments'],
                                $chkItem['sent_by'],
                                $chkItem['student_uid'],
                                $chkItem['event_name'],
                                $chkItem['invoice_id']
                            ]);
                            $newQueueId = (int)$this->pdo->lastInsertId();

                            // Update tracking table installment_whatsapp_reminders
                            $updTrack = $this->pdo->prepare("
                                UPDATE installment_whatsapp_reminders
                                SET queue_id = ?, status = 'queued'
                                WHERE queue_id = ?
                            ");
                            $updTrack->execute([$newQueueId, $queueId]);
                            error_log("[QUEUE_REENQUEUED_RACE] old_queue_id={$queueId} new_queue_id={$newQueueId} student_id={$chkItem['student_uid']} recipient={$currentPhone}");
                        }
                        return false;
                    }
                }
            }

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

                error_log("[QUEUE_SENT] queue_id={$queueId} sent successfully. Meta Message ID: {$msgId}");

                // Update installment reminder tracking table to 'sent'
                try {
                    $updRemStmt = $this->pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'sent' WHERE queue_id = ?");
                    $updRemStmt->execute([$queueId]);
                } catch (Exception $remEx) {}

                // Update campaign recipient mapping to 'sent'
                try {
                    $updCampStmt = $this->pdo->prepare("UPDATE communication_campaign_recipients SET status = 'sent', sent_at = NOW() WHERE queue_id = ?");
                    $updCampStmt->execute([$queueId]);
                } catch (Exception $campEx) {}

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
                        $renderedText = null;

                        if (!empty($item['template_name'])) {
                            try {
                                $tplData = json_decode($item['template_data'] ?? '', true) ?: [];
                                $params = $tplData['parameters'] ?? [];

                                // Fetch template definition
                                $stmtTpl = $this->pdo->prepare("SELECT meta_data FROM communication_templates WHERE template_name = ? LIMIT 1");
                                $stmtTpl->execute([$item['template_name']]);
                                $tplRec = $stmtTpl->fetch(PDO::FETCH_ASSOC);
                                if ($tplRec) {
                                    $meta = json_decode($tplRec['meta_data'] ?? '', true) ?: [];
                                    $bodyTpl = $meta['body_text'] ?? '';
                                    if (!empty($bodyTpl)) {
                                        $compiled = $bodyTpl;
                                        foreach ($params as $idx => $val) {
                                            $placeholder = '{{' . ($idx + 1) . '}}';
                                            $compiled = str_replace($placeholder, $val, $compiled);
                                        }
                                        $renderedText = $compiled;
                                    }
                                }
                            } catch (Exception $tplEx) {
                                error_log("Failed to render template message at send time: " . $tplEx->getMessage());
                            }
                        }

                        if ($renderedText === null) {
                            $renderedText = $bodyText;
                        }

                        if (!$convId) {
                            $insConv = $this->pdo->prepare("
                                INSERT INTO whatsapp_conversations (wa_phone_number, student_uid, student_user_id, contact_name, last_message_text, last_message_at, unread_count, status, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, NOW(), 0, 'open', NOW(), NOW())
                            ");
                            $insConv->execute([$cleanPhone, $studentUid, $studentUserId, $contactName, $renderedText]);
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
                            $updConv->execute([$studentUid, $studentUserId, $contactName, $renderedText, $convId]);
                        }

                        // Insert outbound message with status 'sent'
                        $msgType = (isset($templateData['type']) && $templateData['type'] === 'interactive') ? 'interactive' : 'text';
                        $rawPayloadJson = !empty($item['template_data']) ? $item['template_data'] : null;
                        $insMsg = $this->pdo->prepare("
                            INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, status, raw_payload, created_at, sent_at)
                            VALUES (?, ?, 'outbound', ?, ?, 'sent', ?, NOW(), NOW())
                        ");
                        $insMsg->execute([$convId, $msgId, $msgType, $renderedText, $rawPayloadJson]);

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
                $errMsg = 'Provider failed to dispatch message.';
                if ($res && is_array($res) && isset($res['error'])) {
                    $errMsg = $res['error'];
                } elseif ($provider instanceof WhatsAppCloudProvider) {
                    $errMsg = $provider->getLastError();
                }
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
            $errCode = 0;
            if (isset($provider) && $provider instanceof WhatsAppCloudProvider) {
                $errCode = $provider->getLastErrorCode();
            }

            $retryCount = $item ? ((int)$item['retry_count'] + 1) : 1;
            $maxRetries = ($item && $item['channel'] === 'email') ? 5 : 3;

            $isPermanentFailure = false;
            $chan = $item ? $item['channel'] : 'whatsapp';
            if ($chan === 'whatsapp') {
                $isPermanentFailure = CommunicationHelper::isPermanentMetaFailure($errCode, $errMsg);
            } else {
                $isPermanentFailure = (strpos(strtolower($errMsg), 'not approved') !== false || strpos(strtolower($errMsg), 'parameter') !== false);
            }

            if ($isPermanentFailure) {
                $retryCount = $maxRetries;
                if ($errCode > 0) {
                    $errMsg = "[Meta Code {$errCode}] " . $errMsg;
                }
                error_log("[QUEUE_PERMANENT_FAILURE] queue_id={$queueId} recipient=" . ($item ? $item['recipient'] : 'unknown') . " marked terminal failure instantly. Error: " . $errMsg);
            } else {
                error_log("[QUEUE_RETRY] queue_id={$queueId} recipient=" . ($item ? $item['recipient'] : 'unknown') . " transient error backoff attempt={$retryCount} error=" . $errMsg);
            }

            $chan = $item ? $item['channel'] : 'whatsapp';
            $legacyErr = $item ? (string)$item['error_message'] : '';

            if ($retryCount >= $maxRetries) {
                $status = 'failed';
                $nextAttempt = date('Y-m-d H:i:s', time() + 3600 * 24 * 365); // Far future
            } else {
                $status = 'retrying';
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

                try {
                    $updCampStmt = $this->pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = ? WHERE queue_id = ?");
                    $updCampStmt->execute([$errMsg, $queueId]);
                } catch (Exception $campEx) {}
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
                    } elseif ($val === 'student_uid' || $val === 'student_id' || $val === 'application_id') {
                        $resolvedVal = $student['user_id'] ?? '';
                    } elseif ($val === 'whatsapp_number' || $val === 'student_phone') {
                        $resolvedVal = ($student['whatsapp_country_code'] ?? '') . ($student['whatsapp_number'] ?? '');
                    } elseif ($val === 'email' || $val === 'student_email') {
                        $resolvedVal = $student['email'] ?? '';
                    } elseif ($val === 'gender') {
                        $resolvedVal = $student['gender'] ?? '';
                    } elseif ($val === 'date_of_birth') {
                        $resolvedVal = !empty($student['date_of_birth']) ? date('d M Y', strtotime($student['date_of_birth'])) : '';
                    } elseif ($val === 'college_school') {
                        $resolvedVal = $student['college_school'] ?? '';
                    } elseif ($val === 'source' || $val === 'how_know_pepp') {
                        $resolvedVal = $student['how_know_pepp'] ?? '';
                    } elseif ($val === 'mobile_number') {
                        $resolvedVal = $student['mobile_number'] ?? '';
                    } elseif ($val === 'course_name' || $val === 'current_course_name') {
                        $resolvedVal = $student['pepp_course'] ?? '';
                    } elseif ($val === 'current_course_fee' || $val === 'course_fee') {
                        $courseFee = (float)($student['total_fee'] ?? 0) + (float)($student['discount_amount'] ?? 0);
                        $resolvedVal = number_format($courseFee);
                    } elseif ($val === 'academic_year') {
                        $resolvedVal = $student['pepp_academic_year'] ?? '';
                    } elseif ($val === 'payment_plan') {
                        $resolvedVal = $student['payment_plan'] ?? '';
                    } elseif ($val === 'registration_fee' || $val === 'registration_fee_paid' || $val === 'registration_paid' || $val === 'registration_payment_amount') {
                        $resolvedVal = number_format((float)($student['paid_amount'] ?? 0));
                    } elseif ($val === 'registration_paid_date' || $val === 'registration_payment_date' || $val === 'paid_date' || $val === 'payment_date') {
                        $resolvedVal = !empty($student['paid_date']) ? date('d M Y', strtotime($student['paid_date'])) : '';
                    } elseif ($val === 'total_paid' || $val === 'total_collected') {
                        $resolvedVal = number_format($collected);
                    } elseif ($val === 'outstanding_balance' || $val === 'balance_amount' || $val === 'balance') {
                        $balance = max(0.0, (float)($student['total_fee'] ?? 0) - $collected);
                        $resolvedVal = number_format($balance);
                    } elseif ($val === 'amount_paid' || $val === 'payment_amount' || $val === 'paid_amount') {
                        $resolvedVal = number_format((float)($student['paid_amount'] ?? 0));
                    } elseif ($val === 'installment_count' || $val === 'number_of_installments') {
                        try {
                            $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM instalment_details WHERE user_id = ?");
                            $cntStmt->execute([$student['user_id']]);
                            $resolvedVal = (string)$cntStmt->fetchColumn();
                        } catch (Exception $e) {
                            $resolvedVal = '0';
                        }
                    } elseif ($val === 'invoice_number') {
                        try {
                            $invStmt = $this->pdo->prepare("SELECT invoice_no FROM invoices WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                            $invStmt->execute([$student['user_id']]);
                            $resolvedVal = (string)$invStmt->fetchColumn();
                        } catch (Exception $e) {
                            $resolvedVal = '';
                        }
                    } elseif ($val === 'invoice_link') {
                        try {
                            $invStmt = $this->pdo->prepare("SELECT invoice_no FROM invoices WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                            $invStmt->execute([$student['user_id']]);
                            $invNo = $invStmt->fetchColumn();
                            $resolvedVal = $invNo ? "https://pepplearning.in/invoice/" . rawurlencode($invNo) : '';
                        } catch (Exception $e) {
                            $resolvedVal = '';
                        }
                    } elseif ($val === 'rejection_reason') {
                        try {
                            $rejStmt = $this->pdo->prepare("SELECT reason FROM student_status_log WHERE user_id = ? AND new_status = 'rejected' ORDER BY id DESC LIMIT 1");
                            $rejStmt->execute([$student['user_id']]);
                            $resolvedVal = (string)$rejStmt->fetchColumn();
                        } catch (Exception $e) {
                            $resolvedVal = '';
                        }
                    } elseif ($val === 'installment_paid') {
                        $resolvedVal = number_format(max(0.0, $collected - (float)($student['paid_amount'] ?? 0)));
                    } elseif ($val === 'previous_academic_year' || $val === 'new_academic_year') {
                        $resolvedVal = $student['pepp_academic_year'] ?? '';
                    } elseif ($val === 'previous_course_name' || $val === 'new_course_name') {
                        $resolvedVal = $student['pepp_course'] ?? '';
                    } elseif ($val === 'previous_course_fee') {
                        $resolvedVal = '0.00';
                    } elseif ($val === 'new_course_fee') {
                        $resolvedVal = number_format((float)($student['total_fee'] ?? 0));
                    } elseif ($val === 'migration_amount_paid') {
                        $resolvedVal = '0.00';
                    } elseif ($val === 'upgrade_amount') {
                        $resolvedVal = '0.00';
                    } elseif ($val === 'new_outstanding_balance') {
                        $balance = max(0, (float)($student['total_fee'] ?? 0) - $collected);
                        $resolvedVal = number_format($balance);
                    } elseif ($val === 'migration_date') {
                        $resolvedVal = date('d M Y');
                    } elseif ($val === 'migration_reason') {
                        $resolvedVal = '';
                    } elseif ($val === 'payment_mode') {
                        $resolvedVal = $student['payment_mode'] ?? '';
                    } elseif ($val === 'discount_amount') {
                        $resolvedVal = number_format((float)($student['discount_amount'] ?? 0));
                    } elseif ($val === 'total_payable') {
                        $resolvedVal = number_format((float)($student['total_fee'] ?? 0));
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
                    } elseif ($val === 'updated_payment_details') {
                        try {
                            $stmtInst = $this->pdo->prepare("SELECT amount, due_date FROM instalment_details WHERE user_id = ? AND status = 'pending' AND paid_date IS NULL ORDER BY instalment_number ASC");
                            $stmtInst->execute([$student['user_id']]);
                            $pendingInst = $stmtInst->fetchAll();
                            if (empty($pendingInst)) {
                                $resolvedVal = 'One Time payment plan, no outstanding balance.';
                            } else {
                                $count = count($pendingInst);
                                $firstInst = $pendingInst[0];
                                $formattedAmount = number_format((float)$firstInst['amount']);
                                $formattedDate = date('d M Y', strtotime($firstInst['due_date']));
                                if ($count === 1) {
                                    $resolvedVal = "1 installment of ₹{$formattedAmount}, due {$formattedDate}";
                                } else {
                                    $resolvedVal = "{$count} installments of ₹{$formattedAmount} each, starting {$formattedDate}";
                                }
                            }
                        } catch (Exception $e) {
                            $resolvedVal = 'Rescheduled installments schedule';
                        }
                    }

                    // Retrieve dynamically from migration history as fallback
                    if (in_array($val, ['previous_course_name', 'new_course_name', 'previous_course_fee', 'new_course_fee', 'migration_amount_paid', 'upgrade_amount', 'outstanding_balance', 'new_outstanding_balance', 'migration_date', 'migration_reason', 'previous_academic_year', 'new_academic_year'], true)) {
                        try {
                            $migStmt = $this->pdo->prepare("SELECT * FROM student_course_migrations WHERE user_id = ? ORDER BY migrated_at DESC LIMIT 1");
                            $migStmt->execute([$student['user_id']]);
                            $lastMig = $migStmt->fetch();
                            if ($lastMig) {
                                if ($val === 'previous_course_name') {
                                    $resolvedVal = $lastMig['old_course'];
                                } elseif ($val === 'new_course_name') {
                                    $resolvedVal = $lastMig['new_course'];
                                } elseif ($val === 'previous_course_fee') {
                                    $resolvedVal = number_format((float)$lastMig['old_course_fee']);
                                } elseif ($val === 'new_course_fee') {
                                    $resolvedVal = number_format((float)$lastMig['new_course_fee']);
                                } elseif ($val === 'migration_amount_paid') {
                                    $expectedOutstanding = (float)$lastMig['outstanding_before'] + ((float)$lastMig['new_course_fee'] - (float)$lastMig['old_course_fee']);
                                    $diff = $expectedOutstanding - (float)$lastMig['outstanding_after'];
                                    $resolvedVal = number_format(max(0.0, min($diff, (float)$lastMig['upgrade_amount'])));
                                } elseif ($val === 'upgrade_amount') {
                                    $resolvedVal = number_format((float)$lastMig['upgrade_amount']);
                                } elseif ($val === 'outstanding_balance') {
                                    $resolvedVal = number_format((float)$lastMig['outstanding_before']);
                                } elseif ($val === 'new_outstanding_balance') {
                                    $resolvedVal = number_format((float)$lastMig['outstanding_after']);
                                } elseif ($val === 'migration_date') {
                                    $resolvedVal = date('d M Y', strtotime($lastMig['migrated_at']));
                                } elseif ($val === 'migration_reason') {
                                    $resolvedVal = $lastMig['migration_reason'];
                                }
                            }
                        } catch (Exception $e) {}
                    }
                }

                // Fallbacks
                if ($resolvedVal === '') {
                    if ($val === 'current_datetime') {
                        $resolvedVal = date('d M Y h:i A');
                    } elseif ($val === 'alumni_name') {
                        if (!empty($contextData['peppian_id'])) {
                            try {
                                $stmtPep = $this->pdo->prepare("SELECT full_name FROM peppians WHERE id = ? LIMIT 1");
                                $stmtPep->execute([$contextData['peppian_id']]);
                                $resolvedVal = (string)$stmtPep->fetchColumn();
                            } catch (Exception $e) {}
                        } elseif (!empty($contextData['name'])) {
                            $resolvedVal = $contextData['name'];
                        }
                    } elseif ($val === 'referral_code') {
                        if (!empty($contextData['referee_id'])) {
                            try {
                                $stmtRef = $this->pdo->prepare("SELECT referral_code FROM referees WHERE id = ? LIMIT 1");
                                $stmtRef->execute([$contextData['referee_id']]);
                                $resolvedVal = (string)$stmtRef->fetchColumn();
                            } catch (Exception $e) {}
                        } elseif (!empty($contextData['code'])) {
                            $resolvedVal = $contextData['code'];
                        }
                    } elseif ($val === 'referral_link') {
                        $refCode = $contextData['referral_code'] ?? $contextData['code'] ?? null;
                        if (!$refCode && !empty($contextData['referee_id'])) {
                            try {
                                $stmtRef = $this->pdo->prepare("SELECT referral_code FROM referees WHERE id = ? LIMIT 1");
                                $stmtRef->execute([$contextData['referee_id']]);
                                $refCode = (string)$stmtRef->fetchColumn();
                            } catch (Exception $e) {}
                        }
                        if ($refCode) {
                            $resolvedVal = 'https://pepplearning.in/admissions/register.php?ref=' . urlencode((string)$refCode);
                        }
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
        if (!$studentUid && !empty($contextData['peppian_id'])) {
            $studentUid = 'peppian_' . $contextData['peppian_id'];
        }
        $invoiceId = $contextData['invoice_id'] ?? null;

        try {
            $resolved = $this->resolveEventTemplate($eventName, $studentUid, $contextData);
            if (!$resolved) {
                $this->lastError = "Event '{$eventName}' not mapped to a template or mapping is disabled.";
                return null;
            }

            // Strict duplicate notification check based on student_uid + event_name + template_name
            if ($studentUid && $eventName && $resolved['name'] && !in_array($eventName, ['installment_reminder', 'installment_overdue'], true)) {
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

            $recipientName = $contextData['alumni_name'] ?? $contextData['student_name'] ?? null;

            $queueId = $this->queueMessage(
                'whatsapp',
                $recipient,
                $recipientName,
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
        if ($this->isQueuePaused()) {
            return false;
        }

        if ($this->pdo->inTransaction()) {
            // Defer dispatch to background cron to prevent nested transactions and unsafe external API calls during transaction
            return true;
        }

        $phpBinary = 'php';
        if (file_exists('/opt/alt/php82/usr/bin/php')) {
            $phpBinary = '/opt/alt/php82/usr/bin/php';
        }
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

    /**
     * Checks if the communication queue is globally paused.
     */
    public function isQueuePaused() {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'communication_queue_paused' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return ((int)$val === 1);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Toggles the global pause state.
     */
    public function setQueuePaused($paused) {
        try {
            $val = $paused ? '1' : '0';
            $this->pdo->prepare("DELETE FROM admin_settings WHERE setting_name = 'communication_queue_paused'")->execute();
            $stmt = $this->pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('communication_queue_paused', ?, NOW())");
            $stmt->execute([$val]);
            return true;
        } catch (Exception $e) {
            error_log("Failed to setQueuePaused: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Centralized phone-number normalization mechanism.
     */
    public static function normalizePhone($phone) {
        if (function_exists('normalizeLeadPhone')) {
            return normalizeLeadPhone($phone);
        }
        $cleaned = preg_replace('/\D/', '', (string)$phone);
        if (strlen($cleaned) === 11 && strpos($cleaned, '0') === 0) {
            $cleaned = substr($cleaned, 1);
        }
        if (strlen($cleaned) === 10) {
            $cleaned = '91' . $cleaned;
        }
        return $cleaned;
    }

    /**
     * Synchronizes future pending/scheduled/failed queue items when a student's phone number changes.
     * Cancels the old queue items as 'superseded' and clones them targeting the new phone number.
     * Updates tracking row queue_ids atomically to prevent duplicate reminders.
     */
    public function syncStudentQueueOnNumberChange($studentUid, $newCountryCode, $newNumber) {
        $newPhone = self::normalizePhone($newCountryCode . $newNumber);
        if (empty($newPhone)) {
            return;
        }

        try {
            $this->pdo->beginTransaction();

            // Find all eligible future/pending/retryable queue items for this student
            $stmt = $this->pdo->prepare("
                SELECT * FROM communication_queue
                WHERE student_uid = ?
                  AND status IN ('pending', 'scheduled', 'failed')
                  AND channel = 'whatsapp'
            ");
            $stmt->execute([$studentUid]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $oldPhone = self::normalizePhone($item['recipient']);
                if ($oldPhone === $newPhone) {
                    continue; // Already matching, skip
                }

                // Check for duplicate queue protection on the new number for this specific event/installment/invoice
                $dupStmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM communication_queue
                    WHERE student_uid = ?
                      AND (event_name = ? OR (event_name IS NULL AND ? IS NULL))
                      AND recipient = ?
                      AND status IN ('pending', 'processing', 'sent', 'delivered', 'read')
                ");
                $dupStmt->execute([$studentUid, $item['event_name'], $item['event_name'], $newPhone]);
                $exists = (int)$dupStmt->fetchColumn();

                if ($exists === 0) {
                    // Clone queue item targeting the new number
                    $insStmt = $this->pdo->prepare("
                        INSERT INTO communication_queue
                        (channel, recipient, recipient_name, subject, body_html, body_text, template_name, template_data, attachments, status, next_attempt_at, sent_by, student_uid, event_name, invoice_id, error_message, retry_count, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, NULL, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $insStmt->execute([
                        $item['channel'],
                        $newPhone,
                        $item['recipient_name'],
                        $item['subject'],
                        $item['body_html'],
                        $item['body_text'],
                        $item['template_name'],
                        $item['template_data'],
                        $item['attachments'],
                        $item['sent_by'],
                        $studentUid,
                        $item['event_name'],
                        $item['invoice_id']
                    ]);
                    $newQueueId = (int)$this->pdo->lastInsertId();

                    // Update tracking table installment_whatsapp_reminders
                    $updTrack = $this->pdo->prepare("
                        UPDATE installment_whatsapp_reminders
                        SET queue_id = ?
                        WHERE queue_id = ?
                    ");
                    $updTrack->execute([$newQueueId, $item['id']]);
                    error_log("[QUEUE_REENQUEUED] old_queue_id={$item['id']} new_queue_id={$newQueueId} student_id={$studentUid} recipient={$newPhone}");
                }

                // Supersede the old queue item
                $updOld = $this->pdo->prepare("
                    UPDATE communication_queue
                    SET status = 'failed',
                        error_message = 'Superseded: Recipient number changed',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $updOld->execute([$item['id']]);

                error_log("[QUEUE_SUPERSEDED] queue_id={$item['id']} student_uid={$studentUid} queued_phone={$oldPhone} current_phone={$newPhone}");
            }

            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Failed syncStudentQueueOnNumberChange: " . $e->getMessage());
        }
    }

    /**
     * Spawns an immediate background process to trigger the queue processor.
     * Uses curl HTTP loopback with 1-second timeout.
     */
    public function triggerCronBackground() {
        if ($this->isQueuePaused()) {
            error_log("[CRON_TRIGGER_ABORT] Queue is paused. Background cron trigger skipped.");
            return;
        }
        try {
            $stmtSec = $this->pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_cron_worker_key' LIMIT 1");
            $stmtSec->execute();
            $token = $stmtSec->fetchColumn();
            if (!$token) return;

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/admissions/index.php';
            $dir = dirname($scriptName);
            if ($dir === '\\' || $dir === '/') {
                $dir = '';
            }
            $url = $protocol . '://' . $host . $dir . '/cron-queue.php?key=' . $token;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_exec($ch);
            curl_close($ch);
            error_log("[CRON_TRIGGER] background cron loopback triggered. URL: {$url}");
        } catch (Exception $e) {
            error_log("Failed to trigger background cron: " . $e->getMessage());
        }
    }
}
