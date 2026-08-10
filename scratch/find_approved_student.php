<?php
require_once '../config/database.php';
try {
    $stmt = $pdo->query("SELECT email, date_of_birth, pepp_course FROM users WHERE status = 'approved' LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
