<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT course_name, total_fee, duration, course_code FROM pepp_courses WHERE status = 'active' ORDER BY course_name");
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'courses' => $courses
    ]);
    
} catch (Exception $e) {
    error_log("Get courses error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch courses'
    ]);
}
?>
