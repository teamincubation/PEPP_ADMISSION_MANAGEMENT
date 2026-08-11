<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

echo "=== QUEUE INVESTIGATION REPORT ===\n\n";

try {
    // 1. Inspect queue records #45, #46, #47
    $ids = [45, 46, 47];
    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        
        if ($row) {
            echo "--- Record #{$id} ---\n";
            echo "Queue ID: " . $row['id'] . "\n";
            echo "Channel: " . $row['channel'] . "\n";
            echo "Recipient: " . $row['recipient'] . "\n";
            echo "Recipient Name: " . ($row['recipient_name'] ?? '-') . "\n";
            echo "Template Name: " . ($row['template_name'] ?? '-') . "\n";
            echo "Event Name: " . ($row['event_name'] ?? '-') . "\n";
            echo "Student UID: " . ($row['student_uid'] ?? '-') . "\n";
            echo "Invoice ID: " . ($row['invoice_id'] ?? '-') . "\n";
            echo "Status: " . $row['status'] . "\n";
            echo "Retry Count: " . $row['retry_count'] . "\n";
            echo "Next Attempt At: " . $row['next_attempt_at'] . "\n";
            echo "Created At: " . $row['created_at'] . "\n";
            echo "Updated At: " . $row['updated_at'] . "\n";
            echo "Error Message: " . ($row['error_message'] ?? '-') . "\n";
            echo "Payload: " . substr($row['template_data'], 0, 500) . "\n\n";
        } else {
            echo "--- Record #{$id} NOT FOUND ---\n\n";
        }
    }
    
    // 2. Check if the cron queue is running and execute it manually to capture API logs
    echo "--- Queue Processing Simulation ---\n";
    // We execute QueueProcessor to see if it processes them or throws an error.
    require_once 'includes/communication/QueueProcessor.php';
    $processor = new QueueProcessor($pdo, 5);
    
    // Let's capture output buffering to see if processing echo statements occur
    ob_start();
    $processed = $processor->execute();
    $procOutput = ob_get_clean();
    
    echo "Processed count by QueueProcessor run: " . $processed . "\n";
    echo "Output: " . $procOutput . "\n";
    
    // Let's query the logs again to see if the status or errors changed after this execution
    echo "--- Post-run Record Status ---\n";
    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT id, status, retry_count, error_message, updated_at FROM communication_queue WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            echo "Record #{$id}: Status = {$row['status']}, Retries = {$row['retry_count']}, Error = " . ($row['error_message'] ?? '-') . ", Updated = {$row['updated_at']}\n";
        }
    }

} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}
