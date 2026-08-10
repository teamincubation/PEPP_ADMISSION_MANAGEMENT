<?php
require_once 'config/database.php';
try {
    $stmt = $pdo->query("SELECT id, username, full_name, role, status FROM admins");
    $rows = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($rows, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
