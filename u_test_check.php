<?php
require_once 'config/database.php';
try {
    $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE username = 'superadmin'");
    $stmt->execute();
    $hash = $stmt->fetchColumn();
    header('Content-Type: text/plain');
    echo "Match default: " . (password_verify('admin123@pepp', $hash) ? 'YES' : 'NO') . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
