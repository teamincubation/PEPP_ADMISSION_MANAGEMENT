<?php
header('Content-Type: text/plain; charset=UTF-8');

try {
    require_once __DIR__ . '/../config/database.php';
    if (!isset($pdo) && isset($conn)) {
        $pdo = $conn;
    }
    
    echo "DATABASE CONNECTION SUCCESSFUL\n\n";
    
    echo "CUSTOM FONTS:\n";
    $stmt = $pdo->query("SELECT * FROM custom_fonts");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
