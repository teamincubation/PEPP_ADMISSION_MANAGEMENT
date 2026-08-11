<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $sql = file_get_contents(__DIR__ . '/database-update-19.sql');
    if ($sql === false) {
        throw new Exception("Could not read database-update-19.sql");
    }
    
    $pdo->exec($sql);
    echo "Migration database-update-19.sql executed successfully.\n";
    
    // Check if tables exist
    $hasConvTable = (bool)$pdo->query("SHOW TABLES LIKE 'whatsapp_conversations'")->fetchColumn();
    $hasMsgTable = (bool)$pdo->query("SHOW TABLES LIKE 'whatsapp_messages'")->fetchColumn();
    echo "whatsapp_conversations table exists: " . ($hasConvTable ? "YES" : "NO") . "\n";
    echo "whatsapp_messages table exists: " . ($hasMsgTable ? "YES" : "NO") . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
