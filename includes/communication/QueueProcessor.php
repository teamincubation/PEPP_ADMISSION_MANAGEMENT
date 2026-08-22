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
        // Query pending or failed items that are ready for attempt
        $stmt = $this->pdo->prepare("
            SELECT id FROM communication_queue 
            WHERE status IN ('pending', 'failed') 
              AND next_attempt_at <= NOW() 
              AND (
                (channel = 'whatsapp' AND retry_count < 3) OR
                (channel = 'email' AND retry_count < 5) OR
                (channel NOT IN ('whatsapp', 'email') AND retry_count < 3)
              )
            ORDER BY priority DESC, created_at ASC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $this->batchSize, PDO::PARAM_INT);
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
