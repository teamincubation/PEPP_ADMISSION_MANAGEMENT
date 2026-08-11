<?php
/**
 * Cron runner entry point to process messaging queue.
 * Configured to run in background.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/communication/QueueProcessor.php';

try {
    $queueId = isset($argv[1]) ? (int)$argv[1] : null;
    
    if ($queueId > 0) {
        require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
        $engine = CommunicationEngine::getInstance($pdo);
        $success = $engine->processQueueItem($queueId);
        echo "Queue item #{$queueId} processed: " . ($success ? "Success" : "Failed") . "\n";
    } else {
        $processor = new QueueProcessor($pdo, 25);
        $processed = $processor->execute();
        echo "Queue processed successfully. Items dispatched: " . $processed . "\n";
    }
} catch (Exception $e) {
    error_log("Cron Queue Processor Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
