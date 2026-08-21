<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT * FROM communication_campaigns WHERE name = ? LIMIT 1");
    $stmt->execute(['Sample Test']);
    $camp = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'campaign' => $camp
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
