<?php
require_once '../config/database.php';
try {
    $stmt = $pdo->query("SELECT * FROM study_plan_analytics ORDER BY id DESC LIMIT 50");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
