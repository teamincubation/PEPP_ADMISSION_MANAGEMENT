<?php
require_once '../config/database.php';
try {
    $stmt = $pdo->prepare("SELECT id, activity_title, activity_date FROM study_plan_activities WHERE study_plan_id = ?");
    $stmt->execute([5]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
