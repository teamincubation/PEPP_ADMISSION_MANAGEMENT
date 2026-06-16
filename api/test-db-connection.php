<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

try {
    require_once '../config/database.php';
    
    $tests = [];
    
    // Test 1: Database connection
    $tests['connection'] = [
        'status' => isset($pdo) && $pdo ? 'success' : 'failed',
        'message' => isset($pdo) && $pdo ? 'Connected' : 'Not connected'
    ];
    
    // Test 2: Check users table
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'users'");
        $tests['users_table'] = [
            'status' => $result->rowCount() > 0 ? 'success' : 'failed',
            'message' => $result->rowCount() > 0 ? 'Table exists' : 'Table missing'
        ];
    } catch (Exception $e) {
        $tests['users_table'] = ['status' => 'failed', 'message' => $e->getMessage()];
    }
    
    // Test 3: Check pepp_courses table
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'pepp_courses'");
        $tests['courses_table'] = [
            'status' => $result->rowCount() > 0 ? 'success' : 'failed',
            'message' => $result->rowCount() > 0 ? 'Table exists' : 'Table missing'
        ];
    } catch (Exception $e) {
        $tests['courses_table'] = ['status' => 'failed', 'message' => $e->getMessage()];
    }
    
    // Test 4: Count users
    try {
        $result = $pdo->query("SELECT COUNT(*) as count FROM users");
        $count = $result->fetch()['count'];
        $tests['user_count'] = [
            'status' => 'success',
            'message' => "Found {$count} users"
        ];
    } catch (Exception $e) {
        $tests['user_count'] = ['status' => 'failed', 'message' => $e->getMessage()];
    }
    
    // Test 5: Sample user query
    try {
        $result = $pdo->query("SELECT user_id, name, email FROM users LIMIT 1");
        $user = $result->fetch();
        $tests['sample_query'] = [
            'status' => $user ? 'success' : 'failed',
            'message' => $user ? 'Sample user: ' . $user['name'] : 'No users found',
            'data' => $user
        ];
    } catch (Exception $e) {
        $tests['sample_query'] = ['status' => 'failed', 'message' => $e->getMessage()];
    }
    
    echo json_encode([
        'success' => true,
        'tests' => $tests,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
