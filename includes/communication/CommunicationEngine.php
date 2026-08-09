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
    public function queueMessage($channel, $recipient, $recipientName, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = [], $sentBy = 'system', $scheduledAt = null, $studentUid = null) {
        $status = 'pending';
        $nextAttempt = date('Y-m-d H:i:s');

        if ($scheduledAt && strtotime($scheduledAt) > time()) {
            $status = 'scheduled';
            $nextAttempt = date('Y-m-d H:i:s', strtotime($scheduledAt));
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
            (channel, recipient, recipient_name, subject, body_html, body_text, template_name, template_data, attachments, status, next_attempt_at, sent_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
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
            $sentBy
        ]);

        $queueId = (int)$this->pdo->lastInsertId();

        // LEGACY PARALLEL WRITE: write to legacy whatsapp_notifications table
        if ($channel === 'whatsapp') {
            try {
                $legacyPhone = substr(preg_replace('/\D/', '', $recipient), -15);
                $legacyStmt = $this->pdo->prepare("
                    INSERT INTO whatsapp_notifications (phone, message, student_name, sent_by, status, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $legacyStmt->execute([
                    $legacyPhone,
                    $bodyText ?: strip_tags($bodyHtml),
                    $recipientName,
                    $sentBy,
                    $status === 'scheduled' ? 'pending' : 'pending' // starts in pending
                ]);
                $legacyId = (int)$this->pdo->lastInsertId();
                
                // Store legacy ID mapping inside our queue data
                $updateStmt = $this->pdo->prepare("UPDATE communication_queue SET error_message = ? WHERE id = ?");
                $updateStmt->execute(['legacy_id:' . $legacyId, $queueId]);
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
        try {
            // Lock queue item via SELECT FOR UPDATE to prevent race conditions
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("SELECT * FROM communication_queue WHERE id = ? FOR UPDATE");
            $stmt->execute([$queueId]);
            $item = $stmt->fetch();

            if (!$item || !in_array($item['status'], ['pending', 'scheduled', 'failed'], true)) {
                $this->pdo->rollBack();
                return false;
            }

            // Update state to processing to avoid double attempts
            $procStmt = $this->pdo->prepare("UPDATE communication_queue SET status = 'processing', updated_at = NOW() WHERE id = ?");
            $procStmt->execute([$queueId]);
            $this->pdo->commit();

            $channel = $item['channel'];
            $recipient = $item['recipient'];
            $subject = $item['subject'];
            $bodyHtml = $item['body_html'];
            $bodyText = $item['body_text'];
            
            $attachments = $item['attachments'] ? json_decode($item['attachments'], true) : [];
            $templateData = $item['template_data'] ? json_decode($item['template_data'], true) : [];

            $provider = $this->getProvider($channel);
            $res = $provider->sendMessage($recipient, $subject, $bodyHtml, $bodyText, $attachments, $templateData);

            if ($res && isset($res['success']) && $res['success'] === true) {
                // Sent successfully
                $msgId = $res['message_id'];
                
                $doneStmt = $this->pdo->prepare("
                    UPDATE communication_queue 
                    SET status = 'sent', message_id = ?, error_message = NULL, updated_at = NOW() 
                    WHERE id = ?
                ");
                $doneStmt->execute([$msgId, $queueId]);

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
            
            $errMsg = $e->getMessage();
            $retryCount = ($item['retry_count'] ?? 0) + 1;
            $maxRetries = ($item['channel'] === 'whatsapp') ? 3 : 5;

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

            // Sync status to legacy log table if applicable
            if ($item['channel'] === 'whatsapp' && strpos((string)$item['error_message'], 'legacy_id:') === 0) {
                $legacyId = (int)substr((string)$item['error_message'], 10);
                $legStmt = $this->pdo->prepare("UPDATE whatsapp_notifications SET status = 'failed', updated_at = NOW() WHERE id = ?");
                $legStmt->execute([$legacyId]);
            }

            error_log("Communication Queue Dispatch Failed for ID {$queueId}: {$errMsg}");
            return false;
        }
    }
}
