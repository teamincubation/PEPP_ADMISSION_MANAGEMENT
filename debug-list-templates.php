<?php
require_once 'config/database.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT template_name, status, category FROM communication_templates");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
