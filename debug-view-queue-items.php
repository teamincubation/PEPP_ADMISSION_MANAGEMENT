<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    echo "=== ALL ACTIVE QUEUE ITEMS ===\n\n";
    
    // Fetch count of items grouped by status
    $stmt = $pdo->query("SELECT status, COUNT(*) as c FROM communication_queue GROUP BY status");
    while ($row = $stmt->fetch()) {
        echo "Status: " . $row['status'] . " - Count: " . $row['c'] . "\n";
    }
    
    echo "\n--- Recent 15 items order by priority/created ---\n";
    $stmt = $pdo->query("
        SELECT id, channel, recipient, status, retry_count, next_attempt_at, created_at, updated_at, error_message, event_name, template_name
        FROM communication_queue 
        ORDER BY id DESC
        LIMIT 15
    ");
    while ($row = $stmt->fetch()) {
        echo "#{$row['id']}: Status = {$row['status']}, Template = {$row['template_name']}, Recipient = {$row['recipient']}, Retries = {$row['retry_count']}/3, NextAttempt = {$row['next_attempt_at']}, Updated = {$row['updated_at']}, Error = " . ($row['error_message'] ?? '-') . "\n";
    }
    
    echo "\n--- Check MySQL Server time details ---\n";
    $dbTime = $pdo->query("SELECT NOW() as now, CURDATE() as curdate, @@session.time_zone as tz")->fetch();
    echo "MySQL NOW(): " . $dbTime['now'] . "\n";
    echo "MySQL CURDATE(): " . $dbTime['curdate'] . "\n";
    echo "MySQL Session TZ: " . $dbTime['tz'] . "\n";
    echo "PHP date(): " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
