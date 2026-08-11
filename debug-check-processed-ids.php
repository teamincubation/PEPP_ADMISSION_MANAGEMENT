<?php
require_once 'config/database.php';
require_once 'includes/communication/CommunicationEngine.php';

header('Content-Type: text/plain');

try {
    echo "=== QUEUE PROCESSOR DRY RUN & STEP-BY-STEP CHECK ===\n\n";
    
    // 1. Fetch the exact items the QueueProcessor SELECT query finds
    $stmt = $pdo->prepare("
        SELECT id, status, next_attempt_at, retry_count, channel, template_name, error_message, recipient 
        FROM communication_queue 
        WHERE status IN ('pending', 'failed') 
          AND next_attempt_at <= NOW() 
          AND retry_count < 3
        ORDER BY priority DESC, created_at ASC 
        LIMIT 10
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Query returned " . count($rows) . " items:\n";
    foreach ($rows as $r) {
        echo "ID #{$r['id']}: Status = {$r['status']}, Channel = {$r['channel']}, Template = " . ($r['template_name'] ?: 'NONE') . ", Recipient = {$r['recipient']}, NextAttempt = {$r['next_attempt_at']}, Retries = {$r['retry_count']}, Error = " . ($r['error_message'] ?? '-') . "\n";
    }
    
    if (empty($rows)) {
        echo "\nNo items are currently due to be processed.\n";
        exit;
    }
    
    // 2. Try processing the very first item manually and capture step-by-step output/errors
    $targetId = $rows[0]['id'];
    echo "\n--- Attempting to process ID #{$targetId} manually ---\n";
    
    $engine = CommunicationEngine::getInstance($pdo);
    
    // We run processQueueItem manually but with display_errors on
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    
    try {
        $success = $engine->processQueueItem($targetId);
        echo "processQueueItem returned: " . ($success ? "TRUE (Success)" : "FALSE (Failure)") . "\n";
    } catch (Exception $ex) {
        echo "processQueueItem threw Exception: " . $ex->getMessage() . "\n";
    }
    
    // Inspect target record state after processing
    $check = $pdo->query("SELECT status, retry_count, error_message, updated_at FROM communication_queue WHERE id = {$targetId}")->fetch();
    echo "After Processing: Status = {$check['status']}, Retries = {$check['retry_count']}, Error = " . ($check['error_message'] ?? '-') . ", Updated = {$check['updated_at']}\n";

} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}
