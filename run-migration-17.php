<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

echo "=== EXECUTING DATABASE UPDATE 17 ===\n\n";

try {
    $sql = file_get_contents('database-update-17.sql');
    if (!$sql) {
        throw new Exception("Could not read database-update-17.sql");
    }
    
    // Check if columns already exist to avoid errors
    $check = $pdo->query("SHOW COLUMNS FROM communication_queue LIKE 'worker_started_at'");
    if ($check->rowCount() > 0) {
        echo "Columns already exist in the database. Skipping migration.\n";
    } else {
        $pdo->exec($sql);
        echo "Migration executed successfully! Columns added to communication_queue.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
