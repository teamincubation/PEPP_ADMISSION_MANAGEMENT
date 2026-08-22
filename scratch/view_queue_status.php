<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');

echo "========================================\n";
echo "    PRODUCTION QUEUE ITEMS STATUS CHECK\n";
echo "========================================\n\n";

$ids = [154, 272, 289, 296, 297, 298, 299, 300, 301, 302, 303, 304, 305, 306, 307];

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("
    SELECT id, channel, recipient, status, retry_count, next_attempt_at, message_id, error_message, created_at, updated_at 
    FROM communication_queue 
    WHERE id IN ($placeholders)
    ORDER BY id ASC
");
$stmt->execute($ids);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($results as $row) {
    echo "ID: {$row['id']} | Channel: {$row['channel']} | Recipient: {$row['recipient']}\n";
    echo " - Status: {$row['status']} | Retries: {$row['retry_count']} | Next Attempt: {$row['next_attempt_at']}\n";
    echo " - Message ID: " . ($row['message_id'] ?: '-') . "\n";
    echo " - Error Message: " . ($row['error_message'] ?: '-') . "\n";
    echo " - Created: {$row['created_at']} | Updated: {$row['updated_at']}\n\n";
}
