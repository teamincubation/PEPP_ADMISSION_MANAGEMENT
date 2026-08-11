<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    // 1. Find the generated student "Adnan Test"
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'pending' LIMIT 1");
    $stmt->execute(['adnanmongam@gmail.com']);
    $student = $stmt->fetch();
    
    if (!$student) {
        throw new Exception("Adnan Test not found with pending status. Maybe already approved?");
    }
    
    $user_id = $student['user_id'];
    echo "Found student User ID: {$user_id}\n";
    
    // 2. Set payment_account_id to null since accounts module is not installed
    $payment_account_id = null;
    
    // 3. Setup PEPP course fee record to match total
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM pepp_courses WHERE course_name = ?");
    $cStmt->execute(['MA/MSc Psychology (Standard)']);
    if ($cStmt->fetchColumn() == 0) {
        $pdo->prepare("
            INSERT INTO pepp_courses (course_name, total_fee, status) 
            VALUES (?, 14999.00, 'active')
        ")->execute(['MA/MSc Psychology (Standard)']);
        echo "Inserted course MA/MSc Psychology (Standard) with fee 14999.00\n";
    } else {
        $pdo->prepare("
            UPDATE pepp_courses SET total_fee = 14999.00 
            WHERE course_name = ?
        ")->execute(['MA/MSc Psychology (Standard)']);
        echo "Updated course MA/MSc Psychology (Standard) fee to 14999.00\n";
    }
    
    // 4. Mock request parameters for student-approval.php POST action
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    // Mock session auth parameters
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Find a real active admin in the database to satisfy auth.php checks
    $real_admin_user = 'admin';
    try {
        $real_admin_user = $pdo->query("SELECT username FROM admins WHERE status = 'active' LIMIT 1")->fetchColumn() ?: 'admin';
    } catch (Exception $e) {}
    
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username']  = $real_admin_user;
    $_SESSION['admin_role']      = 'super_admin';
    $_SESSION['csrf_token']      = 'test_csrf_token';
    
    // Mock POST input
    $_POST = [
        'csrf_token'             => 'test_csrf_token',
        'action'                 => 'approve',
        'user_id'                => $user_id,
        'course_duration_date'   => '2026-08-30',
        'payment_mode'           => 'Online',
        'payment_account_id'     => $payment_account_id,
        'payment_plan'           => '4 Instalments', // total 4 payments (1 registration + 3 installments)
        'peppkit_eligible'       => 'Not Eligible',
        'discount_amount'        => 0,
        'discount_remark'        => '',
        'installment_2_amount'   => 3999,
        'installment_2_due_date' => '2026-08-30',
        'installment_3_amount'   => 3500,
        'installment_3_due_date' => '2026-09-20',
        'installment_4_amount'   => 3000,
        'installment_4_due_date' => '2026-10-10'
    ];
    
    echo "Triggering student-approval.php POST action...\n";
    
    // Turn on output buffering to capture JSON response
    ob_start();
    include 'student-approval.php';
    $response = ob_get_clean();
    
    echo "Approval Response: " . $response . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
