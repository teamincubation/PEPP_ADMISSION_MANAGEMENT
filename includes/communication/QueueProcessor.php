<?php
/**
 * Processes the pending and retry-scheduled items in the communication queue.
 */

require_once __DIR__ . '/CommunicationEngine.php';

class QueueProcessor {
    private $pdo;
    private $batchSize;

    public function __construct($pdo, $batchSize = 25) {
        $this->pdo = $pdo;
        $this->batchSize = (int)$batchSize;
    }

    /**
     * Finds and dispatches due queue items.
     *
     * @return int Number of successfully processed items
     */
    public function execute() {


        // ── Stale-job recovery ──────────────────────────────────────────
        // If PHP crashed during processQueueItem(), jobs stay stuck in 'processing'.
        // Channel-aware recovery:
        // - Email: SMTP submission is non-atomic with DB update. Outcome is ambiguous.
        //   Do NOT blindly resend. Mark as 'failed' with max retries and descriptive error.
        // - WhatsApp: Check if message_id exists (Meta accepted) or webhook event arrived.
        //   If message_id exists, mark as 'sent'. If no trace and retry_count < 3, reset to 'pending'.
        try {
            $cutoff = date('Y-m-d H:i:s', time() - 600); // 10-minute threshold
            $isSqlite = ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

            $staleSelect = $this->pdo->prepare("
                SELECT id, channel, recipient, message_id, retry_count, error_message
                FROM communication_queue
                WHERE status = 'processing'
                  AND worker_started_at < ?
            ");
            $staleSelect->execute([$cutoff]);
            $staleJobs = $staleSelect->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($staleJobs)) {
                $farFuture = date('Y-m-d H:i:s', time() + 3600 * 24 * 365);
                foreach ($staleJobs as $stale) {
                    $sId = (int)$stale['id'];
                    $sChan = strtolower($stale['channel'] ?? 'whatsapp');
                    $sMsgId = trim($stale['message_id'] ?? '');
                    $sErr = (string)($stale['error_message'] ?? '');
                    $sRetries = (int)($stale['retry_count'] ?? 0);

                    if ($sChan === 'email') {
                        // Ambiguous SMTP submission: do NOT blindly resend. Prevent duplicate delivery.
                        $flag = '[stale:smtp_outcome_ambiguous_requires_review]';
                        $newErr = trim($sErr . ' ' . $flag);
                        $upd = $this->pdo->prepare("
                            UPDATE communication_queue
                            SET status = 'failed',
                                retry_count = 5,
                                next_attempt_at = ?,
                                error_message = ?,
                                updated_at = " . ($isSqlite ? "datetime('now')" : "NOW()") . "
                            WHERE id = ? AND status = 'processing'
                        ");
                        $upd->execute([$farFuture, $newErr, $sId]);
                        error_log("QueueProcessor stale recovery: Email item #{$sId} marked 'failed' (ambiguous SMTP submission). Resend prevented.");
                    } elseif ($sChan === 'whatsapp') {
                        if (!empty($sMsgId)) {
                            // Meta accepted the message earlier. Mark as sent rather than resending.
                            $flag = '[stale:meta_message_id_retained]';
                            $newErr = trim($sErr . ' ' . $flag);
                            $upd = $this->pdo->prepare("
                                UPDATE communication_queue
                                SET status = 'sent',
                                    error_message = ?,
                                    updated_at = " . ($isSqlite ? "datetime('now')" : "NOW()") . "
                                WHERE id = ? AND status = 'processing'
                            ");
                            $upd->execute([$newErr, $sId]);
                            error_log("QueueProcessor stale recovery: WhatsApp item #{$sId} marked 'sent' (Meta ID {$sMsgId} retained).");
                        } else {
                            // Check if a webhook arrived for this recipient
                            $whEvent = null;
                            try {
                                $whCheck = $this->pdo->prepare("
                                    SELECT id FROM communication_webhook_events
                                    WHERE payload LIKE ?
                                    LIMIT 1
                                ");
                                $whCheck->execute(['%' . $stale['recipient'] . '%']);
                                $whEvent = $whCheck->fetchColumn();
                            } catch (Exception $whEx) {}

                            if ($whEvent) {
                                $flag = '[stale:webhook_evidence_found]';
                                $newErr = trim($sErr . ' ' . $flag);
                                $upd = $this->pdo->prepare("
                                    UPDATE communication_queue
                                    SET status = 'sent',
                                        error_message = ?,
                                        updated_at = " . ($isSqlite ? "datetime('now')" : "NOW()") . "
                                    WHERE id = ? AND status = 'processing'
                                ");
                                $upd->execute([$newErr, $sId]);
                            } elseif ($sRetries < 3) {
                                // Pre-dispatch crash without external evidence; safe to retry
                                $flag = '[stale-recovery]';
                                $newErr = trim($sErr . ' ' . $flag);
                                $upd = $this->pdo->prepare("
                                    UPDATE communication_queue
                                    SET status = 'pending',
                                        retry_count = retry_count + 1,
                                        error_message = ?,
                                        updated_at = " . ($isSqlite ? "datetime('now')" : "NOW()") . "
                                    WHERE id = ? AND status = 'processing'
                                ");
                                $upd->execute([$newErr, $sId]);
                            } else {
                                // Max retries exhausted
                                $flag = '[stale:retries_exhausted]';
                                $newErr = trim($sErr . ' ' . $flag);
                                $upd = $this->pdo->prepare("
                                    UPDATE communication_queue
                                    SET status = 'failed',
                                        next_attempt_at = ?,
                                        error_message = ?,
                                        updated_at = " . ($isSqlite ? "datetime('now')" : "NOW()") . "
                                    WHERE id = ? AND status = 'processing'
                                ");
                                $upd->execute([$farFuture, $newErr, $sId]);
                            }
                        }
                    } else {
                        // Other channels: mark failed for safety
                        $flag = '[stale:channel_unknown_requires_review]';
                        $newErr = trim($sErr . ' ' . $flag);
                        $upd = $this->pdo->prepare("
                            UPDATE communication_queue
                            SET status = 'failed',
                                next_attempt_at = ?,
                                error_message = ?,
                                updated_at = " . ($isSqlite ? "datetime('now')" : "NOW()") . "
                            WHERE id = ? AND status = 'processing'
                        ");
                        $upd->execute([$farFuture, $newErr, $sId]);
                    }
                }
            }
        } catch (Exception $staleEx) {
            error_log("QueueProcessor stale-recovery error: " . $staleEx->getMessage());
        }

        // Query pending, scheduled, failed, or retrying items that are ready for attempt
        $nowCutoff = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            SELECT id FROM communication_queue
            WHERE status IN ('pending', 'scheduled', 'failed', 'retrying')
              AND next_attempt_at <= ?
              AND (
                (channel = 'whatsapp' AND retry_count < 3) OR
                (channel = 'email' AND retry_count < 5) OR
                (channel NOT IN ('whatsapp', 'email') AND retry_count < 3)
              )
            ORDER BY priority DESC, created_at ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $nowCutoff, PDO::PARAM_STR);
        $stmt->bindValue(2, $this->batchSize, PDO::PARAM_INT);
        $stmt->execute();

        $itemIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($itemIds)) {
            return [
                'processed' => 0,
                'failed' => 0,
                'eligible' => 0,
                'ids' => [],
                'duration' => 0.0
            ];
        }

        $startTime = microtime(true);
        $eligibleCount = count($itemIds);
        $claimedIds = implode(',', $itemIds);
        error_log("QueueProcessor started. Eligible items found: {$eligibleCount}. Claimed IDs: [{$claimedIds}].");

        $engine = CommunicationEngine::getInstance($this->pdo);
        $processedCount = 0;

        foreach ($itemIds as $id) {
            $success = $engine->processQueueItem($id);
            if ($success) {
                $processedCount++;
            }
            // Optional micro-sleep to throttle API requests (Meta recommends under 80/sec)
            if (!isset($_SERVER['HTTP_X_TESTING_MODE']) || $_SERVER['HTTP_X_TESTING_MODE'] !== 'true') {
                usleep(100000); // 100ms
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $failedCount = $eligibleCount - $processedCount;
        error_log("QueueProcessor completed. Dispatched: {$processedCount}. Failed/skipped: {$failedCount}. Duration: {$duration}s.");

        return [
            'processed' => $processedCount,
            'failed' => $failedCount,
            'eligible' => $eligibleCount,
            'ids' => $itemIds,
            'duration' => $duration
        ];
    }
}
