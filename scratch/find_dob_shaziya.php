<?php
require_once '../config/database.php';
try {
    $stmt = $pdo->prepare("SELECT email, date_of_birth, status FROM users WHERE email = ?");
    $stmt->execute(['shaziyarazic@gmail.com']);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
