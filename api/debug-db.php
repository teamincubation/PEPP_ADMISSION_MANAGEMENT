<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    require_once '../config/database.php';
    
    // Test basic connection
    $test = $pdo->query("SELECT 1")->fetchColumn();
    
    // Check if users table exists
    $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
    
    // Get table structure
    $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if pepp_courses table exists
    $coursesTables = $pdo->query("SHOW TABLES LIKE 'pepp_courses'")->fetchAll();
    
    // Get sample user data
    $sampleUser = $pdo->query("SELECT user_id, name, email, pepp_course FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'connection' => 'OK',
        'users_table_exists' => count($tables) > 0,
        'pepp_courses_table_exists' => count($coursesTables) > 0,
        'users_columns' => array_column($columns, 'Field'),
        'sample_user' => $sampleUser
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>
