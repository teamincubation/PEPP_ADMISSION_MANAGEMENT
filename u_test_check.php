<?php
require_once 'config/database.php';
try {
    $hash = password_hash('admin123@pepp', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO admins (username, password_hash, full_name, role, status, created_by) VALUES ('tempadmin', ?, 'Temp Admin', 'super_admin', 'active', 'system') ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)")
        ->execute([$hash]);
    header('Content-Type: text/plain');
    echo "Temporary superadmin user created/updated successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
