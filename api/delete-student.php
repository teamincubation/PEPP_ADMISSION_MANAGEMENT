<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

// Only super_admin may delete students
if (!can_delete()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Only the Super Admin may delete student records.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// CSRF protection
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh and try again.']);
    exit();
}


try {
    require_once '../config/database.php';
    
    $user_id = $_POST['user_id'] ?? '';
    $deletion_reason = $_POST['deletion_reason'] ?? '';
    
    if (empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }
    
    // Get student details before deletion
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit();
    }
    
    // Add to approval history before deletion
    $stmt = $pdo->prepare("
        INSERT INTO student_approval_history 
        (user_id, action_type, admin_id, notes, created_at) 
        VALUES (?, 'deleted', ?, ?, NOW())
    ");
    $stmt->execute([$user_id, $_SESSION['admin_id'] ?? 1, $deletion_reason]);
    
    // Delete student record
    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    // Get WhatsApp message template
    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'reg_entry_cancelling_message'");
    $stmt->execute();
    $message_template = $stmt->fetchColumn();
    
    if ($message_template) {
        // Replace placeholders in message
        $whatsapp_message = str_replace(
            ['{name}', '{PEPP course}'],
            [$student['name'], $student['pepp_course']],
            $message_template
        );
        
        echo json_encode([
            'success' => true, 
            'message' => 'Student registration deleted successfully',
            'whatsapp_message' => $whatsapp_message,
            'phone_number' => $student['phone']
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'message' => 'Student registration deleted successfully (no WhatsApp template configured)'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
