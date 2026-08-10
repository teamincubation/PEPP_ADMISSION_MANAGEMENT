<?php
require_once '../config/database.php';
try {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_analytics WHERE student_email = ? ORDER BY id DESC");
    $stmt->execute(['shaziyarazic@gmail.com']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
