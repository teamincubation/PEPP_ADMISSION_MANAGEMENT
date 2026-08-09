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
              AND retry_count < 3
            ORDER BY priority DESC, created_at ASC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $this->batchSize, PDO::PARAM_INT);
        $stmt->execute();
        
        $itemIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($itemIds)) {
            return 0;
        }

        $engine = CommunicationEngine::getInstance($this->pdo);
        $processedCount = 0;

        foreach ($itemIds as $id) {
            $success = $engine->processQueueItem($id);
            if ($success) {
                $processedCount++;
            }
            // Optional micro-sleep to throttle API requests (Meta recommends under 80/sec)
            usleep(100000); // 100ms
        }

        return $processedCount;
    }
}
