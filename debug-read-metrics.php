<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $stmt = $pdo->prepare("SELECT * FROM communication_queue WHERE id = 49");
    $stmt->execute();
    $item = $stmt->fetch();
    
    if (!$item) {
        echo "Record not found.";
        exit;
    }
    
    echo "ID: " . $item['id'] . "\n";
    echo "Status: " . $item['status'] . "\n";
    echo "MsgID: " . $item['message_id'] . "\n";
    echo "Created: " . $item['created_at'] . "\n";
    echo "Started: " . $item['worker_started_at'] . "\n";
    echo "Requested: " . $item['api_requested_at'] . "\n";
    echo "Responded: " . $item['api_responded_at'] . "\n";
    echo "Delivered: " . $item['delivered_at'] . "\n";
    
    if ($item['worker_started_at']) {
        $qToWorker = strtotime($item['worker_started_at']) - strtotime($item['created_at']);
        echo "QueueToWorker: {$qToWorker}s\n";
    }
    if ($item['api_requested_at'] && $item['api_responded_at']) {
        $workerToMeta = strtotime($item['api_responded_at']) - strtotime($item['api_requested_at']);
        echo "WorkerToMeta: {$workerToMeta}s\n";
    }
    if ($item['api_responded_at'] && $item['delivered_at']) {
        $metaToDelivery = strtotime($item['delivered_at']) - strtotime($item['api_responded_at']);
        $totalTime = strtotime($item['delivered_at']) - strtotime($item['created_at']);
        echo "MetaToDelivery: {$metaToDelivery}s\n";
        echo "TotalTime: {$totalTime}s\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
