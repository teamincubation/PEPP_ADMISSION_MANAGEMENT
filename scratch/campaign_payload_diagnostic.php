<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT id, template_name, template_data, status, error_message, message_id 
        FROM communication_queue 
        ORDER BY id DESC 
        LIMIT 5
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$item) {
        $item['template_data_decoded'] = json_decode($item['template_data'], true);
    }

    echo json_encode([
        'success' => true,
        'latest_queue_items' => $items
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
