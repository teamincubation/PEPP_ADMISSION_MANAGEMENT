<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
    echo "Admins table exists: " . ($hasTable ? "YES" : "NO") . "\n\n";
    
    if ($hasTable) {
        $stmt = $pdo->query("SHOW CREATE TABLE admins");
        echo $stmt->fetchColumn() . "\n\n";
        
        $stmt = $pdo->query("SELECT * FROM admins LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "CURRENT ADMINS:\n";
        print_r($rows);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
