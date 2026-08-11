<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $sql = file_get_contents(__DIR__ . '/database-update-18.sql');
    if ($sql === false) {
        throw new Exception("Could not read database-update-18.sql");
    }
    
    $pdo->exec($sql);
    echo "Migration database-update-18.sql executed successfully.\n";
    
    // Check if table exists
    $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'installment_whatsapp_reminders'")->fetchColumn();
    echo "installment_whatsapp_reminders table exists: " . ($hasTable ? "YES" : "NO") . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
