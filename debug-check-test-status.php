<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    echo "=== TEST RECORDS RUN STATUS REPORT ===\n\n";
    
    $ids = [47];
    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT id, status, message_id, error_message, updated_at, retry_count FROM communication_queue WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            echo "ID #{$row['id']}:\n";
            echo "  Status: " . $row['status'] . "\n";
            echo "  Message ID: " . ($row['message_id'] ?: 'NONE') . "\n";
            echo "  Error Message: " . ($row['error_message'] ?: 'NONE') . "\n";
            echo "  Retry Count: " . $row['retry_count'] . "\n";
            echo "  Updated At: " . $row['updated_at'] . "\n\n";
        } else {
            echo "ID #{$id} NOT FOUND\n\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
