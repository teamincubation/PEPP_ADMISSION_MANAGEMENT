<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    echo "=== STUDENT REGISTRATION QUEUE ITEMS ===\n\n";
    
    $stmt = $pdo->query("
        SELECT * FROM communication_queue 
        WHERE event_name = 'student_registration' OR template_name = 'pepp_admission_received' 
        ORDER BY id DESC LIMIT 10
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        echo "No queue records found for student_registration event or pepp_admission_received template.\n";
    } else {
        foreach ($items as $item) {
            echo "ID #{$item['id']}:\n";
            echo "  Event Name: " . ($item['event_name'] ?: 'NONE') . "\n";
            echo "  Template Name: " . ($item['template_name'] ?: 'NONE') . "\n";
            echo "  Recipient: {$item['recipient']}\n";
            echo "  Recipient Name: " . ($item['recipient_name'] ?: 'NONE') . "\n";
            echo "  Status: {$item['status']}\n";
            echo "  Retry Count: {$item['retry_count']}\n";
            echo "  Message ID: " . ($item['message_id'] ?: 'NONE') . "\n";
            echo "  Error Message: " . ($item['error_message'] ?: 'NONE') . "\n";
            echo "  Template Data: " . ($item['template_data'] ?: 'NONE') . "\n";
            echo "  Created At: {$item['created_at']}\n";
            echo "  Worker Started At: " . ($item['worker_started_at'] ?: 'NONE') . "\n";
            echo "  Api Requested At: " . ($item['api_requested_at'] ?: 'NONE') . "\n";
            echo "  Api Responded At: " . ($item['api_responded_at'] ?: 'NONE') . "\n";
            echo "  Delivered At: " . ($item['delivered_at'] ?: 'NONE') . "\n\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
