<?php
require_once 'config/database.php';
require_once 'includes/communication/CommunicationEngine.php';

header('Content-Type: text/plain');

echo "=== END-TO-END ASYNCHRONOUS WHATSAPP DISPATCH VERIFICATION ===\n\n";

$studentUid = 'PEPP20264649';
$waPhone = '919567276458';

try {
    // 1. Setup/Reset dummy student in users table
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$studentUid]);
    $stmt = $pdo->prepare("
        INSERT INTO users (user_id, name, whatsapp_number, whatsapp_country_code, pepp_course, pepp_academic_year, paid_amount, paid_date, payment_plan, payment_mode, total_fee, discount_amount, email)
        VALUES (?, 'Adnan Async Test', '9567276458', '91', 'MA/MSc Psychology (Standard)', '2026-27', 5500, '2026-08-11', 'Standard', 'Online', 12000, 2000, 'adnanmongam@gmail.com')
    ");
    $stmt->execute([$studentUid]);
    echo "1. Set up dummy student '{$studentUid}' in database.\n";

    // 2. Clear any old queue items for this student to ensure idempotency test is fresh
    $pdo->prepare("DELETE FROM communication_queue WHERE student_uid = ?")->execute([$studentUid]);
    echo "2. Cleared previous queue logs for {$studentUid}.\n";

    // 3. Trigger notification and measure parent process execution time (should be < 100ms due to async)
    $startTime = microtime(true);
    
    $engine = CommunicationEngine::getInstance($pdo);
    $queueId = $engine->sendEventNotification(
        'student_approval',
        $waPhone,
        [
            'student_uid' => $studentUid,
            'student_name' => 'Adnan Async Test',
            'invoice_id' => 145
        ],
        'system_test'
    );
    
    $endTime = microtime(true);
    $durationMs = round(($endTime - $startTime) * 1000, 2);
    
    if (!$queueId) {
        throw new Exception("Failed to queue message.");
    }
    
    echo "3. Notification Triggered. Queue ID: #{$queueId}\n";
    echo "   Parent Page Execution Duration: {$durationMs} ms (Target: < 200ms to prove non-blocking)\n\n";

    // 4. Sleep 6 seconds to allow background process cURL call and delivery webhook to complete
    echo "4. Sleeping for 6 seconds to allow async worker & webhook processing...\n";
    sleep(6);
    echo "5. Fetching performance timestamp metrics from database...\n\n";

    // 5. Query and report performance timestamps & latencies
    $qStmt = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
    $qStmt->execute([$queueId]);
    $item = $qStmt->fetch();
    
    if (!$item) {
        throw new Exception("Queue item #{$queueId} not found.");
    }
    
    echo "DATABASE RECORD METRICS:\n";
    echo "  Status: " . $item['status'] . "\n";
    echo "  Message ID: " . ($item['message_id'] ?: 'NONE') . "\n";
    echo "  Error: " . ($item['error_message'] ?: 'NONE') . "\n";
    echo "  Created At (Queue Insertion): " . $item['created_at'] . "\n";
    echo "  Worker Started At: " . ($item['worker_started_at'] ?: 'N/A') . "\n";
    echo "  Meta API Requested At: " . ($item['api_requested_at'] ?: 'N/A') . "\n";
    echo "  Meta API Responded At: " . ($item['api_responded_at'] ?: 'N/A') . "\n";
    echo "  Webhook Delivered At: " . ($item['delivered_at'] ?: 'N/A') . "\n\n";

    // Calculate Latency Metrics if populated
    if ($item['worker_started_at']) {
        $qToWorker = strtotime($item['worker_started_at']) - strtotime($item['created_at']);
        echo "LATENCY RESULTS:\n";
        echo "  - Queue-to-Worker Latency: {$qToWorker} seconds\n";
        
        if ($item['api_requested_at'] && $item['api_responded_at']) {
            $workerToMeta = strtotime($item['api_responded_at']) - strtotime($item['api_requested_at']);
            echo "  - Worker-to-Meta API Latency: {$workerToMeta} seconds\n";
        }
        
        if ($item['api_responded_at'] && $item['delivered_at']) {
            $metaToDelivery = strtotime($item['delivered_at']) - strtotime($item['api_responded_at']);
            $totalTime = strtotime($item['delivered_at']) - strtotime($item['created_at']);
            echo "  - Meta-to-Delivery Latency: {$metaToDelivery} seconds\n";
            echo "  - Total Approval-to-Delivery Time: {$totalTime} seconds\n";
        } else {
            echo "  - Meta-to-Delivery Latency: Webhook delivery callback not received yet.\n";
        }
    } else {
        echo "LATENCY RESULTS: Async background worker failed to start.\n";
    }

} catch (Exception $e) {
    echo "CRITICAL VERIFICATION ERROR: " . $e->getMessage() . "\n";
}
