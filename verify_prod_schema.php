<?php
require_once 'config/database.php';
header('Content-Type: application/json');

try {
    // Get communication_queue status column definition
    $stmt = $pdo->query("SHOW COLUMNS FROM `communication_queue` LIKE 'status'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'column_info' => $col
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
