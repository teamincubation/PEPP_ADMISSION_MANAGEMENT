<?php
session_start();
require_once 'config/database.php';
require_once 'includes/file_helper.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$step = $_GET['step'] ?? 'verify';
$verification_success = false;
$student_data = null;
$installment_data = null;
$success_message = '';
$error_message = '';
$pending_request = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['email'])) {
    $email = $_GET['email'];
    
    // Get student data
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Check for pending payment requests
        $stmt = $pdo->prepare("
            SELECT id.*, u.name as student_name
            FROM instalment_details id
            JOIN users u ON id.user_id = u.user_id
            WHERE id.user_id = ? AND id.status = 'pending' AND id.paid_date IS NOT NULL
            ORDER BY id.updated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user['user_id']]);
        $pending_request = $stmt->fetch();
    }
}

// Handle verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify') {
    $email = trim($_POST['email'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    
    if ($email && $date_of_birth) {
        try {
            // Get student details with course information
            $stmt = $pdo->prepare("
                SELECT u.*, 
                       pc.course_name as course_display_name,
                       pc.total_fee as course_total_fee
                FROM users u
                LEFT JOIN pepp_courses pc ON u.pepp_course = pc.course_name
                WHERE u.email = ? AND u.date_of_birth = ? AND u.status = 'approved'
            ");
            $stmt->execute([$email, $date_of_birth]);
            $student_data = $stmt->fetch();
            
            if ($student_data) {
                // Check for pending payment requests first
                $stmt = $pdo->prepare("
                    SELECT * FROM instalment_details 
                    WHERE user_id = ? AND status = 'pending' AND paid_date IS NOT NULL
                    ORDER BY updated_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$student_data['user_id']]);
                $pending_request = $stmt->fetch();
                
                // Get next installment to pay
                $stmt = $pdo->prepare("
                    SELECT * FROM instalment_details 
                    WHERE user_id = ? AND status = 'pending' AND paid_date IS NULL
                    ORDER BY instalment_number ASC
                    LIMIT 1
                ");
                $stmt->execute([$student_data['user_id']]);
                $installment_data = $stmt->fetch();
                
                if ($installment_data || $pending_request) {
                    $verification_success = true;
                    $step = 'payment';
                } else {
                    $error_message = "No pending installments found for your account.";
                }
            } else {
                $error_message = "Invalid email or date of birth. Please check your details.";
            }
        } catch (Exception $e) {
            $error_message = "Database error. Please try again later.";
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Handle payment update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_payment') {
    $user_id = $_POST['user_id'] ?? '';
    $installment_id = $_POST['installment_id'] ?? '';
    $paid_date = $_POST['paid_date'] ?? '';
    
    if ($user_id && $installment_id && $paid_date) {
        try {
            $pdo->beginTransaction();
            
            // Handle payment screenshot upload
            $stmt_old = $pdo->prepare("SELECT payment_reference FROM instalment_details WHERE id = ? AND user_id = ?");
            $stmt_old->execute([$installment_id, $user_id]);
            $old_screenshot = $stmt_old->fetchColumn();

            $payment_screenshot_path = handle_file_upload_with_replace('payment_screenshot', 'installment_payments', $old_screenshot ?: null, ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
            if (empty($payment_screenshot_path)) {
                if ($old_screenshot) {
                    $payment_screenshot_path = $old_screenshot;
                } else {
                    throw new Exception('Please select a payment screenshot to upload.');
                }
            }
            
            // Update installment record with payment details
            $stmt = $pdo->prepare("
                UPDATE instalment_details SET 
                    paid_date = ?,
                    payment_reference = ?,
                    updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $result = $stmt->execute([$paid_date, $payment_screenshot_path, $installment_id, $user_id]);
            
            if (!$result || $stmt->rowCount() === 0) {
                throw new Exception('Failed to update payment record. Please try again.');
            }
            
            // Log the payment update
            $stmt = $pdo->prepare("
                INSERT INTO student_status_log (user_id, old_status, new_status, changed_by, reason, changed_at)
                VALUES (?, 'pending_payment', 'payment_submitted', 'student', 'Student submitted installment payment', NOW())
            ");
            $stmt->execute([$user_id]);
            
            $pdo->commit();
            $step = 'success';
            $success_message = "Your installment payment has been recorded successfully! Your payment screenshot has been uploaded and is pending admin review.";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            // Clean up uploaded file if there was an error
            if (!empty($payment_screenshot_path) && file_exists('../' . $payment_screenshot_path)) {
                unlink('../' . $payment_screenshot_path);
            }
            $error_message = "Error updating payment: " . $e->getMessage();
            
            // Stay on payment step to allow retry
            $step = 'payment';
            
            // Get student and installment data again for the payment form
            if ($user_id) {
                $stmt = $pdo->prepare("
                    SELECT u.*, 
                           pc.course_name as course_display_name,
                           pc.total_fee as course_total_fee
                    FROM users u
                    LEFT JOIN pepp_courses pc ON u.pepp_course = pc.course_name
                    WHERE u.user_id = ?
                ");
                $stmt->execute([$user_id]);
                $student_data = $stmt->fetch();
                
                $stmt = $pdo->prepare("
                    SELECT * FROM instalment_details 
                    WHERE user_id = ? AND status = 'pending' AND paid_date IS NULL
                    ORDER BY instalment_number ASC
                    LIMIT 1
                ");
                $stmt->execute([$user_id]);
                $installment_data = $stmt->fetch();
                
                // Check for pending requests
                $stmt = $pdo->prepare("
                    SELECT * FROM instalment_details 
                    WHERE user_id = ? AND status = 'pending' AND paid_date IS NOT NULL
                    ORDER BY updated_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$user_id]);
                $pending_request = $stmt->fetch();
            }
        }
    } else {
        $error_message = "Please fill in all required fields.";
        $step = 'payment';
        
        // Get student and installment data again for the payment form
        if ($user_id) {
            $stmt = $pdo->prepare("
                SELECT u.*, 
                       pc.course_name as course_display_name,
                       pc.total_fee as course_total_fee
                FROM users u
                LEFT JOIN pepp_courses pc ON u.pepp_course = pc.course_name
                WHERE u.user_id = ?
            ");
            $stmt->execute([$user_id]);
            $student_data = $stmt->fetch();
            
            $stmt = $pdo->prepare("
                SELECT * FROM instalment_details 
                WHERE user_id = ? AND status = 'pending'
                ORDER BY instalment_number ASC
                LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $installment_data = $stmt->fetch();
            
            // Check for pending requests
            $stmt = $pdo->prepare("
                SELECT * FROM instalment_details 
                WHERE user_id = ? AND status = 'pending' AND paid_date IS NOT NULL
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $pending_request = $stmt->fetch();
        }
    }
}

// Get student and installment data for payment step
if ($step === 'payment' && isset($_GET['user_id'])) {
    $user_id = $_GET['user_id'];
    
    // Get student details
    $stmt = $pdo->prepare("
        SELECT u.*, 
               pc.course_name as course_display_name,
               pc.total_fee as course_total_fee
        FROM users u
        LEFT JOIN pepp_courses pc ON u.pepp_course = pc.course_name
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $student_data = $stmt->fetch();
    
    // Get next pending installment
    $stmt = $pdo->prepare("
        SELECT * FROM instalment_details 
        WHERE user_id = ? AND status = 'pending'
        ORDER BY instalment_number ASC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $installment_data = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Installment Payment — PEPP Learning</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="apple-touch-icon" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ── PEPP amber theme — matches register.php ── */
        :root {
            --primary: #d97706;
            --primary-dark: #b45309;
            --primary-light: #fef3c7;
            --success: #16a34a;
            --success-light: #dcfce7;
            --warning: #d97706;
            --warning-light: #fef3c7;
            --danger: #dc2626;
            --danger-light: #fee2e2;
            --gray-50: #fafbfd;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background-color: #181003;
            background-image:
                radial-gradient(ellipse 80% 60% at 10% 10%, rgba(232,152,12,.20) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 90% 90%, rgba(240,165,17,.14) 0%, transparent 55%),
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23e8980c' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            min-height: 100vh;
            line-height: 1.6;
        }

        .payment-container {
            min-height: 100vh;
            padding: 2.5rem 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .payment-card {
            background: #ffffff;
            border-radius: 28px;
            box-shadow:
                0 0 0 1px rgba(255,255,255,.06),
                0 8px 32px rgba(0,0,0,.4),
                0 32px 80px rgba(0,0,0,.35);
            width: 100%;
            max-width: 700px;
            overflow: hidden;
            position: relative;
        }

        /* ── HERO HEADER (matches register.php) ── */
        .payment-header {
            background: linear-gradient(135deg, #2a1a03 0%, #4a2e04 45%, #6b4205 100%);
            color: white;
            padding: 2.5rem 2rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .payment-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle 420px at 110% 0%, rgba(250,204,21,.22) 0%, transparent 60%),
                radial-gradient(circle 300px at -10% 110%, rgba(232,152,12,.18) 0%, transparent 50%);
            pointer-events: none;
        }

        .brand-mark {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,.35);
            overflow: hidden;
            position: relative;
            z-index: 1;
            box-shadow: 0 4px 18px rgba(0,0,0,.35);
        }
        .brand-mark img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

        .payment-header h1 {
            font-size: 1.55rem;
            font-weight: 800;
            letter-spacing: -0.4px;
            margin-bottom: 0.4rem;
            position: relative;
            z-index: 1;
        }

        .payment-header p {
            color: rgba(251,211,141,.9);
            font-size: 0.875rem;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }

        .payment-body {
            background: #f5f6fa;
            padding: 2rem 1.75rem 2.25rem;
        }

        /* ── STEP INDICATOR ── */
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 1.75rem;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.7rem 1.6rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.2px;
            transition: all 0.3s ease;
        }

        .step.active {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: white;
            box-shadow: 0 6px 20px rgba(217,119,6,.4);
        }

        .step.inactive {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        /* ── FORM ── */
        .form-group { margin-bottom: 1.4rem; }

        .form-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 0.7rem 1rem;
            border: 1.5px solid var(--gray-200);
            border-radius: 10px;
            background: var(--gray-50);
            color: var(--gray-800);
            font-size: 0.9rem;
            font-weight: 500;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-color: #e8980c;
            background: white;
            box-shadow: 0 0 0 3px rgba(232,152,12,.18);
        }

        /* ── BUTTONS ── */
        .btn {
            padding: 0.85rem 1.8rem;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            transition: all 0.25s ease;
            font-size: 0.92rem;
            font-family: inherit;
            letter-spacing: 0.2px;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before { left: 100%; }

        .btn-primary {
            background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(217,119,6,.45);
        }

        .btn-success {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: white;
            box-shadow: 0 6px 20px rgba(22,163,74,.4);
        }

        .btn-secondary {
            background: #fff;
            color: #92400e;
            border: 1.5px solid #fcd34d;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .btn-secondary:hover { background: #fffbeb; }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
        }

        .btn:active { transform: translateY(-1px); }

        .btn-full { width: 100%; }

        /* ── ALERTS ── */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 14px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .alert i { margin-top: 2px; flex-shrink: 0; }

        .alert-success {
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            color: #15803d;
        }

        .alert-error {
            background: #fff1f2;
            border: 1.5px solid #fca5a5;
            color: #b91c1c;
        }

        /* ── STUDENT INFO CARD ── */
        .student-info {
            background: #fff;
            padding: 1.4rem;
            border-radius: 18px;
            margin-bottom: 1.5rem;
            border: 1px solid #e8eaf0;
            box-shadow: 0 1px 4px rgba(0,0,0,.04), 0 4px 16px rgba(180,83,9,.06);
            position: relative;
            overflow: hidden;
        }
        .student-info::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            border-radius: 0 4px 4px 0;
            background: linear-gradient(180deg, #b45309, #f59e0b);
        }

        .student-info h3 {
            margin: 0 0 1.1rem 0;
            color: #3b2604;
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .student-info h3 i { color: #d97706; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 0.9rem;
        }

        .info-item {
            background: var(--gray-50);
            padding: 0.9rem 1rem;
            border-radius: 12px;
            border: 1.5px solid var(--gray-200);
            transition: all 0.2s ease;
        }

        .info-item:hover {
            border-color: #fcd34d;
            background: #fffbeb;
        }

        .info-label {
            display: block;
            font-weight: 700;
            color: var(--gray-600);
            font-size: 0.66rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.3rem;
        }

        .info-value {
            color: var(--gray-800);
            font-weight: 700;
            font-size: 0.95rem;
        }

        /* ── INSTALLMENT HIGHLIGHT ── */
        .installment-highlight {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #fff;
            padding: 1.75rem 1.5rem;
            border-radius: 18px;
            margin-bottom: 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(217,119,6,.4);
        }

        .installment-highlight::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, transparent 70%);
            animation: pulse 2.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }

        .installment-highlight h4 {
            margin: 0 0 0.5rem 0;
            font-weight: 700;
            font-size: 1rem;
            position: relative;
            z-index: 1;
        }

        .installment-amount {
            font-size: 2.4rem;
            font-weight: 800;
            letter-spacing: -1px;
            margin: 0.5rem 0;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 12px rgba(0,0,0,.15);
        }
        .installment-highlight p { position: relative; z-index: 1; font-size: 0.875rem; }

        /* ── FILE UPLOAD ── */
        .file-upload-area {
            border: 2px dashed var(--gray-300);
            border-radius: 12px;
            padding: 2rem 1.25rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #f8fafc;
            position: relative;
            overflow: hidden;
        }

        .file-upload-area:hover {
            border-color: #f59e0b;
            background: #fffbeb;
        }

        .file-upload-area.has-file {
            border-color: var(--success);
            background: #f0fdf4;
        }

        .file-upload-area i {
            font-size: 2rem;
            margin-bottom: 0.6rem;
            color: #f59e0b;
            display: block;
        }

        .file-upload-area p {
            margin: 0.3rem 0;
            font-size: 0.875rem;
            color: #334155;
            position: relative;
            z-index: 1;
        }

        /* ── SUCCESS ACTIONS ── */
        .success-actions {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
            margin-top: 1.75rem;
        }

        .whatsapp-btn {
            background: linear-gradient(135deg, #25D366, #128C7E);
            color: white;
            box-shadow: 0 6px 20px rgba(37, 211, 102, 0.35);
        }

        /* ── DAYS REMAINING BADGE ── */
        .days-remaining {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.55rem 1.25rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-top: 0.75rem;
            position: relative;
            z-index: 1;
        }

        .days-remaining.urgent {
            background: #fff1f2;
            color: #dc2626;
        }

        .days-remaining.warning {
            background: #fffbeb;
            color: #b45309;
        }

        .days-remaining.good {
            background: #f0fdf4;
            color: #16a34a;
        }

        /* ── PENDING NOTIFICATION ── */
        .pending-notification {
            background: #fffbeb;
            color: #92400e;
            border: 1.5px solid #fcd34d;
            border-radius: 16px;
            padding: 1.4rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            position: relative;
            overflow: hidden;
        }

        .pending-notification::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(245, 158, 11, 0.15), transparent);
            animation: slide 2.5s ease-in-out infinite;
        }

        @keyframes slide {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            .payment-container { padding: 1rem 0.5rem; }
            .payment-card { border-radius: 20px; }
            .payment-header { padding: 2rem 1.25rem 1.5rem; }
            .payment-header h1 { font-size: 1.25rem; }
            .payment-body { padding: 1.5rem 1rem 1.75rem; }
            .info-grid { grid-template-columns: 1fr; }
            .installment-amount { font-size: 1.9rem; }
            .file-upload-area { padding: 1.5rem 1rem; }
            .step { padding: 0.6rem 1.25rem; font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-card">
            <div class="payment-header">
                <div class="brand-mark">
                    <img src="logo.png" alt="PEPP Learning logo">
                </div>
                <h1>Update Installment Payment</h1>
                <p>Secure payment update portal for PEPP Learning students</p>
            </div>

            <div class="payment-body">
                <?php if ($step === 'verify'): ?>
                    <!-- Step 1: Verification -->
                    <div class="step-indicator">
                        <div class="step active">
                            <i class="fas fa-user-check"></i> Verify Identity
                        </div>
                    </div>

                    <?php if ($error_message): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="verify">
                        
                        <div class="form-group">
                            <label class="form-label">Registered Email Address</label>
                            <input type="email" name="email" class="form-input" required 
                                   placeholder="Enter your registered email address">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-input" required>
                        </div>

                        <button type="submit" class="btn btn-primary btn-full">
                            <i class="fas fa-search"></i> Verify & Continue
                        </button>
                    </form>

                <?php elseif ($step === 'payment' && $student_data && ($installment_data || $pending_request)): ?>
                    <!-- Step 2: Payment Update -->
                    <div class="step-indicator">
                        <div class="step active">
                            <i class="fas fa-credit-card"></i> Update Payment
                        </div>
                    </div>

                    <?php if ($error_message): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Added pending request notification at the top -->
                    <?php if ($pending_request): ?>
                        <div class="pending-notification">
                            <i class="fas fa-clock" style="font-size: 1.5rem; margin-bottom: 1rem;"></i>
                            <div>
                                <strong style="font-size: 1.1rem;">Payment Request Pending Review</strong><br>
                                Your installment #<?php echo $pending_request['instalment_number']; ?> payment of ₹<?php echo number_format($pending_request['amount'], 2); ?> 
                                submitted on <?php echo date('d M Y H:i', strtotime($pending_request['updated_at'])); ?> is pending admin approval.
                                <br><small>Status will be updated once admin reviews your payment.</small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Student Information -->
                    <div class="student-info">
                        <h3><i class="fas fa-user"></i> Student Details</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($student_data['name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Course</span>
                                <span class="info-value"><?php echo htmlspecialchars($student_data['pepp_course']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Academic Year</span>
                                <span class="info-value"><?php echo htmlspecialchars($student_data['pepp_academic_year']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Initial Payment</span>
                                <span class="info-value">₹<?php echo number_format($student_data['paid_amount'], 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Installment Information -->
                    <div class="installment-highlight">
                        <h4><i class="fas fa-calendar-alt"></i> Next Installment Due</h4>
                        <?php if ($installment_data): ?>
                            <div class="installment-amount">₹<?php echo number_format($installment_data['amount'], 2); ?></div>
                            <p><strong>Due Date:</strong> <?php echo date('d M Y', strtotime($installment_data['due_date'])); ?></p>
                            
                            <?php
                            $due_date = new DateTime($installment_data['due_date']);
                            $today = new DateTime();
                            $days_diff = $today->diff($due_date)->days;
                            $is_overdue = $today > $due_date;
                            
                            if ($is_overdue) {
                                $class = 'urgent';
                                $text = $days_diff . ' days overdue';
                                $icon = 'fas fa-exclamation-triangle';
                            } elseif ($days_diff <= 3) {
                                $class = 'urgent';
                                $text = $days_diff . ' days remaining';
                                $icon = 'fas fa-clock';
                            } elseif ($days_diff <= 7) {
                                $class = 'warning';
                                $text = $days_diff . ' days remaining';
                                $icon = 'fas fa-clock';
                            } else {
                                $class = 'good';
                                $text = $days_diff . ' days remaining';
                                $icon = 'fas fa-check-circle';
                            }
                            ?>
                            
                            <div class="days-remaining <?php echo $class; ?>">
                                <i class="<?php echo $icon; ?>"></i>
                                <?php echo $text; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Direct Banking Payment Details -->
                    <?php
                    try {
                        $public_payment_accounts = $pdo->query("SELECT * FROM payment_accounts WHERE is_public = 1 AND status = 'active' LIMIT 2")->fetchAll();
                    } catch (Exception $e) {
                        $public_payment_accounts = [];
                    }
                    if (!empty($public_payment_accounts)):
                    ?>
                    <div style="background:#fef3c7; border:1px solid #f59e0b; border-radius:12px; padding:12px 16px; margin: 15px 0;">
                        <p style="margin:0 0 6px 0; font-size:0.88rem; font-weight:700; color:#b45309;"><i class="fas fa-building-columns"></i> Direct Payment Details</p>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php foreach ($public_payment_accounts as $pa): ?>
                                <div style="font-size:0.82rem; color:#78350f;">
                                    <strong style="color:#b45309;"><?php echo htmlspecialchars($pa['account_name']); ?>:</strong>
                                    <span style="word-break:break-all; font-family:monospace;"><?php echo htmlspecialchars($pa['banking_details']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Payment Update Form -->
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_payment">
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($student_data['user_id']); ?>">
                        <?php if ($installment_data): ?>
                            <input type="hidden" name="installment_id" value="<?php echo htmlspecialchars($installment_data['id']); ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label">Installment Amount (Fixed)</label>
                            <input type="text" class="form-input" 
                                   value="₹<?php echo $installment_data ? number_format($installment_data['amount'], 2) : ''; ?>" 
                                   readonly style="background: var(--gray-50); cursor: not-allowed;">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Date</label>
                            <input type="date" name="paid_date" class="form-input" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Screenshot <span style="color: var(--danger);">*</span></label>
                            <div class="file-upload-area" onclick="document.getElementById('payment_screenshot').click()">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p><strong>Click to upload payment screenshot</strong></p>
                                <p style="font-size: 0.9rem; opacity: 0.7;">Supported formats: JPG, PNG, PDF (Max 5MB)</p>
                                <input type="file" id="payment_screenshot" name="payment_screenshot" 
                                       accept="image/*,.pdf" style="display: none;" required>
                            </div>
                            <div id="file-info" style="margin-top: 10px; display: none; padding: 10px; background: var(--success-light); border-radius: 8px; color: var(--success);">
                                <i class="fas fa-check-circle"></i> <span id="file-name"></span> (<span id="file-size"></span>)
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success btn-full">
                            <i class="fas fa-check"></i> Update Installment Payment
                        </button>
                    </form>

                    <div style="margin-top: 1rem; text-align: center;">
                        <a href="installmentpayment.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Start Over
                        </a>
                    </div>

                <?php elseif ($step === 'success'): ?>
                    <!-- Step 3: Success -->
                    <div class="step-indicator">
                        <div class="step active">
                            <i class="fas fa-check-circle"></i> Success
                        </div>
                    </div>

                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>

                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-check-circle" style="font-size: 4rem; color: var(--success); margin-bottom: 1rem;"></i>
                        <h3 style="color: var(--gray-800); margin-bottom: 1rem;">Payment Update Recorded!</h3>
                        <p style="color: var(--gray-600); margin-bottom: 2rem;">
                            Administration will approve your request as soon as possible and your course access will be extended.
                        </p>
                    </div>

                    <div class="success-actions">
                        <a href="https://wa.me/917025000444?text=Urgent%3A%20I%20just%20paid%20and%20updated%20my%20installment%20payment%20right%20now.%20Please%20complete%20the%20verification%20asap." 
                           target="_blank" class="btn whatsapp-btn btn-full">
                            <i class="fab fa-whatsapp"></i> Notify Admin via WhatsApp
                        </a>
                        
                        <a href="installmentpayment.php" class="btn btn-secondary btn-full">
                            <i class="fas fa-plus"></i> Update Another Payment
                        </a>
                        
                        <a href="register.php" class="btn btn-primary btn-full">
                            <i class="fas fa-home"></i> Back to Home
                        </a>
                    </div>

                <?php else: ?>
                    <!-- Error State -->
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Unable to load payment information. Please try again.
                    </div>
                    
                    <div style="text-align: center; margin-top: 2rem;">
                        <a href="installmentpayment.php" class="btn btn-primary">
                            <i class="fas fa-refresh"></i> Try Again
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('payment_screenshot').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const fileInfo = document.getElementById('file-info');
            const fileName = document.getElementById('file-name');
            const fileSize = document.getElementById('file-size');
            
            if (file) {
                fileName.textContent = file.name;
                fileSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                fileInfo.style.display = 'block';
            } else {
                fileInfo.style.display = 'none';
            }
        });

        document.querySelector('form[method="POST"]')?.addEventListener('submit', function(e) {
            const action = this.querySelector('input[name="action"]')?.value;
            
            if (action === 'update_payment') {
                // Enhanced loading state with animation
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Payment...';
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.8';
                }
                
                return true; // Let PHP handle all validation
            }
        });

        // Add smooth scroll animation for better UX
        document.addEventListener('DOMContentLoaded', function() {
            const card = document.querySelector('.payment-card');
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.6s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 100);
        });
    </script>
</body>
</html>
