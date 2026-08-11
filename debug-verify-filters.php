<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';

try {
    echo "=== DISPATCH SPEED VERIFICATION ===\n";
    
    // Select the last 5 whatsapp queue items
    $stmt = $pdo->query("
        SELECT id, recipient, created_at, worker_started_at, api_requested_at, api_responded_at, status, error_message
        FROM communication_queue 
        WHERE channel = 'whatsapp'
        ORDER BY id DESC LIMIT 5
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        echo "Queue ID #{$item['id']}:\n";
        echo "  Recipient: {$item['recipient']}\n";
        echo "  Created At: {$item['created_at']}\n";
        echo "  Worker Started At: " . ($item['worker_started_at'] ?: 'N/A') . "\n";
        echo "  API Requested At: " . ($item['api_requested_at'] ?: 'N/A') . "\n";
        echo "  API Responded At: " . ($item['api_responded_at'] ?: 'N/A') . "\n";
        echo "  Status: {$item['status']}\n";
        echo "  Error: " . ($item['error_message'] ?: 'None') . "\n";
        
        if ($item['worker_started_at']) {
            $created = strtotime($item['created_at']);
            $started = strtotime($item['worker_started_at']);
            $delay = $started - $created;
            echo "  Delay between creation and worker execution: {$delay} seconds\n";
        }
        if ($item['api_responded_at'] && $item['api_requested_at']) {
            $req = strtotime($item['api_requested_at']);
            $resp = strtotime($item['api_responded_at']);
            $duration = $resp - $req;
            echo "  Meta API response latency: {$duration} seconds\n";
        }
        echo "\n";
    }
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n";
}
exit;
