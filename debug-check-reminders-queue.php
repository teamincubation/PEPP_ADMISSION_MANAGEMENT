<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    echo "=== REMINDERS QUEUE ITEMS ===\n\n";
    $stmt = $pdo->query("
        SELECT id, event_name, template_name, recipient, recipient_name, status, template_data, created_at 
        FROM communication_queue 
        WHERE event_name IN ('installment_reminder', 'installment_overdue')
        ORDER BY id DESC LIMIT 15
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        echo "Queue ID #{$item['id']}:\n";
        echo "  Event: {$item['event_name']}\n";
        echo "  Template: {$item['template_name']}\n";
        echo "  Recipient: {$item['recipient']}\n";
        echo "  Status: {$item['status']}\n";
        echo "  Template Data: {$item['template_data']}\n";
        echo "  Created: {$item['created_at']}\n\n";
    }

    echo "=== TRACKING RECORDS ===\n\n";
    $stmt = $pdo->query("
        SELECT installment_id, reminder_stage, status, queue_id, last_attempted_at 
        FROM installment_whatsapp_reminders 
        ORDER BY last_attempted_at DESC LIMIT 15
    ");
    $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tracks as $t) {
        echo "Inst ID #{$t['installment_id']} | Stage: {$t['reminder_stage']} | Status: {$t['status']} | Queue ID: {$t['queue_id']} | Time: {$t['last_attempted_at']}\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
