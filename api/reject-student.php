<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

// Permission check: only admins with 'approvals' access
if (!can_access('approvals')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. You do not have permission to reject students.']);
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
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    if (empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }
    
    // Get student details
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit();
    }
    
    // Delete student data after logging approval history to allow re-registration
    $pdo->beginTransaction();
    
    // Add to approval history
    $stmt = $pdo->prepare("
        INSERT INTO student_approval_history 
        (user_id, action_type, admin_id, notes, created_at) 
        VALUES (?, 'rejected', ?, ?, NOW())
    ");
    $stmt->execute([$user_id, $_SESSION['admin_id'] ?? 1, $rejection_reason]);

    // Clean up related rows
    $pdo->prepare("DELETE FROM instalment_details WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM installment_configuration WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM student_onboarding WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$user_id]);

    $pdo->commit();
    
    // Get WhatsApp message template
    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'user_rejection_wp_message'");
    $stmt->execute();
    $message_template = $stmt->fetchColumn();
    
    if ($message_template) {
        // Replace placeholders in message
        $whatsapp_message = str_replace(
            ['{name}'],
            [$student['name']],
            $message_template
        );
        
        echo json_encode([
            'success' => true, 
            'message' => 'Student rejected successfully',
            'whatsapp_message' => $whatsapp_message,
            'phone_number' => $student['phone']
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'message' => 'Student rejected successfully (no WhatsApp template configured)'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
