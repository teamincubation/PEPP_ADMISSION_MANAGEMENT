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
    
    echo "\n--- All Pending Queue Items ---\n";
    $stmt = $pdo->query("
        SELECT id, channel, recipient, status, retry_count, next_attempt_at, created_at, updated_at, error_message, event_name, template_name, priority
        FROM communication_queue 
        WHERE status = 'pending'
        ORDER BY priority DESC, created_at ASC
    ");
    while ($row = $stmt->fetch()) {
        echo "#{$row['id']}: Priority = {$row['priority']}, Recipient = {$row['recipient']}, Template = {$row['template_name']}, Retries = {$row['retry_count']}/3, NextAttempt = {$row['next_attempt_at']}, Created = {$row['created_at']}, Error = " . ($row['error_message'] ?? '-') . "\n";
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
