<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
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
