<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    echo "=== SUMMARY ===\n";
    $q = $pdo->query("SELECT COUNT(*) FROM communication_queue WHERE event_name IN ('installment_reminder', 'installment_overdue') AND created_at >= '2026-08-12 15:20:00'")->fetchColumn();
    echo "Queue count since trigger: $q\n";

    $t = $pdo->query("SELECT COUNT(*) FROM installment_whatsapp_reminders WHERE last_attempted_at >= '2026-08-12 15:20:00'")->fetchColumn();
    echo "Tracking count since trigger: $t\n";

    echo "\n=== QUEUE RECORDS ===\n";
    $stmt = $pdo->query("
        SELECT id, event_name, template_name, recipient, status, template_data 
        FROM communication_queue 
        WHERE event_name IN ('installment_reminder', 'installment_overdue')
        ORDER BY id DESC LIMIT 10
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $params = json_decode($item['template_data'], true)['parameters'] ?? [];
        echo "ID: {$item['id']} | Event: {$item['event_name']} | Tpl: {$item['template_name']} | Recipient: {$item['recipient']} | Status: {$item['status']} | Params: " . implode(', ', $params) . "\n";
    }

    echo "\n=== TRACKING RECORDS ===\n";
    $stmt = $pdo->query("
        SELECT installment_id, reminder_stage, status, queue_id 
        FROM installment_whatsapp_reminders 
        ORDER BY last_attempted_at DESC LIMIT 10
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $track) {
        echo "Inst ID: {$track['installment_id']} | Stage: {$track['reminder_stage']} | Status: {$track['status']} | Queue ID: {$track['queue_id']}\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
