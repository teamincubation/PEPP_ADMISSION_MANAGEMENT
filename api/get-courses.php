<?php
require_once __DIR__ . '/../includes/api_guard.php';
api_require_auth('courses', false); // Require admin login + 'courses' permission, no CSRF for GET

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
