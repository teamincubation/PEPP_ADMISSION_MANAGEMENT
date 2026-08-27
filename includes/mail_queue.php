<?php
/**
 * PEPP Learning — Unified Mail Queue.
 *
 * Central function that enqueues outgoing emails into the `communication_queue`
 * table. The cron-based QueueProcessor dispatches them asynchronously.
 *
 * USAGE:
 *   require_once __DIR__ . '/mail_queue.php';
 *   pepp_enqueue_mail('user@example.com', 'Subject', '<h1>Hi</h1>', 'Hi');
 *
 * Every legacy call site (pepp_mail, peppian_send_email, etc.) should route
 * through this function instead of dispatching inline.
 */

require_once dirname(__DIR__) . '/config/database.php';

/**
 * Enqueue an email for asynchronous dispatch via the communication queue.
 *
 * @param string $to           Recipient email address
 * @param string $subject      Email subject
 * @param string $html         HTML body
 * @param string $text         Plain-text body (auto-generated from $html if empty)
 * @param array  $attachments  Array of ['name'=>..., 'bytes'=>..., 'type'=>...] (stored as JSON)
 * @param string $fromEmail    Sender email override
 * @param string $fromName     Sender name override
 * @param int    $priority     Queue priority (1=lowest, 10=highest, 5=default)
 * @param string|null $eventName  Business event name for dedup (e.g. 'invoice_email', 'session_reminder')
 * @param string|null $sentBy     Who triggered the email
 * @param string|null $studentUid Associated student user_id for tracking
 * @param string|null $invoiceId  Associated invoice ID
 * @return int|false            Queue item ID on success, false on failure
 */
function pepp_enqueue_mail(
    $to,
    $subject,
    $html,
    $text = '',
    array $attachments = [],
    $fromEmail = 'noreply@pepplearning.in',
    $fromName = 'PEPP Learning',
    $priority = 5,
    $eventName = null,
    $sentBy = 'system',
    $studentUid = null,
    $invoiceId = null
) {
    global $pdo;

    // Validate recipient
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("pepp_enqueue_mail: Invalid recipient: {$to}");
        return false;
    }

    // Auto-generate plain text from HTML if not provided
    if (empty($text)) {
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html));
    }

    // Serialize attachments to JSON (if any)
    $attachmentsJson = !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null;

    try {
        // Use the CommunicationEngine if available (preferred path)
        $engineFile = __DIR__ . '/communication/CommunicationEngine.php';
        if (file_exists($engineFile) && isset($pdo)) {
            require_once $engineFile;
            $engine = CommunicationEngine::getInstance($pdo);
            $queueId = $engine->queueMessage(
                'email',           // channel
                $to,               // recipient
                null,              // recipientName
                $subject,          // subject
                $html,             // bodyHtml
                $text,             // bodyText
                $attachments,      // attachments
                [],                // templateData (not used for email)
                $sentBy,           // sentBy
                null,              // scheduledAt
                $studentUid,       // studentUid
                $eventName,        // eventName
                $invoiceId         // invoiceId
            );
            return $queueId;
        }

        // Fallback: direct INSERT if CommunicationEngine is not available
        if (!isset($pdo)) {
            error_log("pepp_enqueue_mail: No PDO connection available, falling back to synchronous dispatch");
            require_once __DIR__ . '/mailer.php';
            return pepp_mail_dispatch($to, $subject, $html, $text, $attachments, $fromEmail, $fromName) ? -1 : false;
        }

        $stmt = $pdo->prepare("
            INSERT INTO communication_queue
            (channel, recipient, subject, body_html, body_text,
             from_email, from_name, attachments, priority,
             event_name, sent_by, student_uid, invoice_id,
             status, retry_count, next_attempt_at, created_at, updated_at)
            VALUES
            ('email', ?, ?, ?, ?,
             ?, ?, ?, ?,
             ?, ?, ?, ?,
             'pending', 0, NOW(), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $to, $subject, $html, $text,
            $fromEmail, $fromName, $attachmentsJson, $priority,
            $eventName, $sentBy, $studentUid, $invoiceId
        ]);
        $id = (int) $pdo->lastInsertId();
        error_log("pepp_enqueue_mail: Queued email #{$id} to {$to} [{$subject}]");
        return $id;

    } catch (Exception $e) {
        error_log("pepp_enqueue_mail error: " . $e->getMessage());

        // CASE 2: If the queue record was already inserted (lastInsertId > 0),
        // do NOT re-send synchronously — that would cause a duplicate email.
        // The cron processor will pick it up from the queue.
        if (isset($pdo) && $pdo->lastInsertId() > 0) {
            error_log("pepp_enqueue_mail: Queue record exists (ID=" . $pdo->lastInsertId() . "), skipping sync fallback to prevent duplicate.");
            return (int) $pdo->lastInsertId();
        }

        // CASE 1: Queue persistence genuinely failed before record creation.
        // Safe to attempt synchronous fallback as a last resort.
        try {
            require_once __DIR__ . '/mailer.php';
            return pepp_mail_dispatch($to, $subject, $html, $text, $attachments, $fromEmail, $fromName) ? -1 : false;
        } catch (Exception $e2) {
            error_log("pepp_enqueue_mail fallback also failed: " . $e2->getMessage());
            return false;
        }
    }
}
