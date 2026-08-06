<?php
session_start();
require_once 'config/database.php';

// Toggle task completion AJAX handler
if (isset($_GET['action']) && $_GET['action'] === 'toggle_completion') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['sp_logged_in']) || $_SESSION['sp_logged_in'] !== true) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $activity_id = (int)($_POST['activity_id'] ?? 0);
    $plan_id = (int)($_POST['study_plan_id'] ?? 0);
    $latitude = !empty($_POST['latitude']) ? trim($_POST['latitude']) : null;
    $longitude = !empty($_POST['longitude']) ? trim($_POST['longitude']) : null;
    $email = $_SESSION['sp_email'];
    
    if ($activity_id <= 0 || $plan_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM study_plan_analytics WHERE student_email = ? AND activity_id = ? AND action_type = 'complete_activity' LIMIT 1");
        $stmt->execute([$email, $activity_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt_del = $pdo->prepare("DELETE FROM study_plan_analytics WHERE id = ?");
            $stmt_del->execute([$existing['id']]);
            echo json_encode(['success' => true, 'completed' => false, 'timestamp' => null]);
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO study_plan_analytics (study_plan_id, student_email, action_type, activity_id, ip_address, latitude, longitude, created_at) VALUES (?, ?, 'complete_activity', ?, ?, ?, ?, NOW())");
            $stmt_ins->execute([$plan_id, $email, $activity_id, $_SERVER['REMOTE_ADDR'], $latitude, $longitude]);
            
            $completed_at = date('d M Y h:i A');
            echo json_encode(['success' => true, 'completed' => true, 'timestamp' => $completed_at]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Log location AJAX handler
if (isset($_GET['action']) && $_GET['action'] === 'log_location') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['sp_logged_in']) || $_SESSION['sp_logged_in'] !== true) {
        echo json_encode(['success' => false]);
        exit();
    }
    
    $plan_id = (int)($_POST['study_plan_id'] ?? 0);
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $email = $_SESSION['sp_email'];
    
    if ($plan_id > 0 && !empty($latitude) && !empty($longitude)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO study_plan_analytics (study_plan_id, student_email, action_type, ip_address, latitude, longitude, created_at) VALUES (?, ?, 'view', ?, ?, ?, NOW())");
            $stmt->execute([$plan_id, $email, $_SERVER['REMOTE_ADDR'], $latitude, $longitude]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

// Helper to escape output
function p_esc($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper to format/validate absolute resource URL
function get_valid_url($url) {
    $url = trim($url ?? '');
    if (empty($url)) return '';
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
        $url = "https://" . $url;
    }
    return $url;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Email Address is required.';
    } else {
        try {
            // 1. Check Course Enrolled Students
            $stmt = $pdo->prepare("
                SELECT u.* 
                FROM users u
                WHERE u.email = ? AND u.status = 'approved' 
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $student = $stmt->fetch();
            
            // 2. Check Custom Campaign Form Submissions
            $stmt_form = $pdo->prepare("
                SELECT DISTINCT s.*, f.title as form_title 
                FROM campaign_form_submissions s
                JOIN campaign_forms f ON s.form_id = f.id
                LEFT JOIN campaign_form_answers a ON s.id = a.submission_id
                WHERE (s.respondent_identifier = ? OR a.answer_text = ?) AND s.is_deleted = 0
                LIMIT 1
            ");
            $stmt_form->execute([$email, $email]);
            $form_user = $stmt_form->fetch();
            
            if ($student || $form_user) {
                // Fetch real name from answers if custom form user
                $name = 'Student';
                if ($student) {
                    $name = $student['name'];
                } elseif ($form_user) {
                    // Try to resolve Name from answers
                    $stmt_name = $pdo->prepare("
                        SELECT a.answer_text 
                        FROM campaign_form_answers a
                        JOIN campaign_form_fields f ON a.field_id = f.id
                        WHERE a.submission_id = ? AND (f.label LIKE '%name%' OR f.field_name LIKE '%name%')
                        ORDER BY f.sort_order ASC
                        LIMIT 1
                    ");
                    $stmt_name->execute([$form_user['id']]);
                    $resolved = $stmt_name->fetchColumn();
                    $name = $resolved ?: ($form_user['respondent_identifier'] ?: 'User');
                }

                $_SESSION['sp_logged_in'] = true;
                $_SESSION['sp_email'] = $email;
                $_SESSION['sp_name'] = $name;
                $_SESSION['sp_course'] = $student ? $student['pepp_course'] : null;
                $_SESSION['sp_year'] = $student ? $student['academic_year'] : null;
                $_SESSION['sp_student_id'] = $student ? $student['user_id'] : null;
                
                header('Location: studyplan.php');
                exit();
            } else {
                $error = 'No active access details found for this email address. Please make sure you enter your registered email address.';
            }
        } catch (Exception $e) {
            $error = 'Database verification error. Please try again later.';
        }
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['sp_logged_in']);
    session_destroy();
    header('Location: studyplan.php');
    exit();
}

$is_logged_in = isset($_SESSION['sp_logged_in']) && $_SESSION['sp_logged_in'] === true;

// Predefined activity types presets
$types_config = [
    'Read Material' => ['icon' => 'fa-book-open', 'color' => '#3b82f6', 'badge' => 'Read'],
    'Watch Live Session' => ['icon' => 'fa-video', 'color' => '#ef4444', 'badge' => 'Live'],
    'Watch Recorded Session' => ['icon' => 'fa-play', 'color' => '#8b5cf6', 'badge' => 'Recorded'],
    'Attend Mock Test' => ['icon' => 'fa-file-signature', 'color' => '#f59e0b', 'badge' => 'Mock'],
    'Attend Mega Test' => ['icon' => 'fa-trophy', 'color' => '#ec4899', 'badge' => 'Mega'],
    'Attend Weekly Test' => ['icon' => 'fa-calendar-check', 'color' => '#06b6d4', 'badge' => 'Weekly'],
    'Practice Test' => ['icon' => 'fa-dumbbell', 'color' => '#10b981', 'badge' => 'Practice'],
    'Previous Year Questions' => ['icon' => 'fa-history', 'color' => '#64748b', 'badge' => 'PYQ'],
    'Daily Quiz' => ['icon' => 'fa-circle-question', 'color' => '#f43f5e', 'badge' => 'Quiz'],
    'Live WhatsApp Quiz' => ['icon' => 'fa-whatsapp', 'color' => '#22c55e', 'badge' => 'WA Quiz'],
    'Group Discussion' => ['icon' => 'fa-comments', 'color' => '#0ea5e9', 'badge' => 'GD'],
    'Meet the Scholar Session' => ['icon' => 'fa-graduation-cap', 'color' => '#d946ef', 'badge' => 'Scholar'],
    'Offline Session' => ['icon' => 'fa-building-columns', 'color' => '#84cc16', 'badge' => 'Offline'],
    'Assignment' => ['icon' => 'fa-file-pen', 'color' => '#f97316', 'badge' => 'Assignment'],
    'Revision' => ['icon' => 'fa-rotate', 'color' => '#059669', 'badge' => 'Revision'],
    'Self-Assessment' => ['icon' => 'fa-clipboard-user', 'color' => '#7c3aed', 'badge' => 'Assessment'],
    'Doubt Clearing Session' => ['icon' => 'fa-lightbulb', 'color' => '#eab308', 'badge' => 'Doubt']
];

// Fetch custom activity types
try {
    $custom_types = $pdo->query("SELECT * FROM study_plan_custom_types")->fetchAll();
    foreach ($custom_types as $ct) {
        $types_config[$ct['name']] = ['icon' => $ct['icon'], 'color' => $ct['color'], 'badge' => $ct['badge'] ?: $ct['name']];
    }
} catch (Exception $e) {}

// Fetch eligible courses and forms cards for the logged in email
$my_courses = [];
$my_forms = [];
if ($is_logged_in) {
    try {
        $email = $_SESSION['sp_email'];
        
        $stmt_courses = $pdo->prepare("SELECT DISTINCT pepp_course FROM users WHERE email = ? AND status = 'approved' AND pepp_course IS NOT NULL AND pepp_course != ''");
        $stmt_courses->execute([$email]);
        $my_courses = $stmt_courses->fetchAll(PDO::FETCH_COLUMN);
        
        $stmt_forms = $pdo->prepare("
            SELECT DISTINCT f.id, f.title 
            FROM campaign_form_submissions s 
            JOIN campaign_forms f ON s.form_id = f.id 
            LEFT JOIN campaign_form_answers a ON s.id = a.submission_id
            WHERE (s.respondent_identifier = ? OR a.answer_text = ?) AND s.is_deleted = 0
        ");
        $stmt_forms->execute([$email, $email]);
        $my_forms = $stmt_forms->fetchAll();
    } catch (Exception $e) {}
}

// Fetch plans inside selected course or form card
$plans = [];
if ($is_logged_in) {
    try {
        if (isset($_GET['course_name'])) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT sp.* 
                FROM study_plans sp
                JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
                WHERE sp.status = 'published' AND sa.assignment_type = 'course' AND sa.assigned_value = ?
                ORDER BY sp.start_date ASC
            ");
            $stmt->execute([$_GET['course_name']]);
            $plans = $stmt->fetchAll();
        } elseif (isset($_GET['form_id'])) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT sp.* 
                FROM study_plans sp
                JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
                WHERE sp.status = 'published' AND sa.assignment_type = 'form' AND sa.assigned_value = ?
                ORDER BY sp.start_date ASC
            ");
            $stmt->execute([$_GET['form_id']]);
            $plans = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $plans = [];
    }
}

// Fetch activities for a selected study plan
$selected_plan_id = (int)($_GET['plan_id'] ?? 0);
$selected_plan = null;
$activities = [];
$completions = [];
if ($is_logged_in && $selected_plan_id > 0) {
    try {
        // Validate student has access to this plan
        $email = $_SESSION['sp_email'];
        $stmt_validate = $pdo->prepare("
            SELECT DISTINCT sp.* 
            FROM study_plans sp
            JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
            WHERE sp.id = ? AND sp.status = 'published' AND (
                sa.assignment_type = 'all' OR
                (sa.assignment_type = 'course' AND sa.assigned_value IN (
                    SELECT pepp_course FROM users WHERE email = ? AND status = 'approved'
                )) OR
                (sa.assignment_type = 'form' AND sa.assigned_value IN (
                    SELECT CAST(form_id AS CHAR) FROM campaign_form_submissions WHERE respondent_identifier = ? AND is_deleted = 0
                    UNION
                    SELECT CAST(s.form_id AS CHAR) FROM campaign_form_submissions s JOIN campaign_form_answers a ON s.id = a.submission_id WHERE a.answer_text = ? AND s.is_deleted = 0
                )) OR
                (sa.assignment_type = 'student' AND sa.assigned_value IN (
                    SELECT user_id FROM users WHERE email = ? AND status = 'approved'
                ))
            )
        ");
        $stmt_validate->execute([$selected_plan_id, $email, $email, $email, $email]);
        $selected_plan = $stmt_validate->fetch();
        
        if ($selected_plan) {
            $stmt = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? ORDER BY activity_date ASC, sort_order ASC");
            $stmt->execute([$selected_plan_id]);
            $activities = $stmt->fetchAll();
            
            // Fetch completions
            $stmt_comp = $pdo->prepare("SELECT activity_id, created_at FROM study_plan_analytics WHERE student_email = ? AND study_plan_id = ? AND action_type = 'complete_activity'");
            $stmt_comp->execute([$email, $selected_plan_id]);
            $completions = $stmt_comp->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    } catch (Exception $e) {
        $activities = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>PEPP Journey - Access Study Plans</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --accent: #E8980C;
            --accent-hover: #d2860a;
            --accent-soft: rgba(232, 152, 12, 0.08);
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --font-family: 'DM Sans', sans-serif;
            --header-font: 'Space Grotesk', sans-serif;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: var(--font-family);
            background-color: var(--bg);
            color: var(--text-main);
            line-height: 1.5;
            -webkit-tap-highlight-color: transparent;
        }
        
        .container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: #fff;
            position: relative;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
        }
        
        /* Header styling */
        header {
            background: var(--card-bg);
            padding: 1rem 1.2rem;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1.5px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: var(--header-font);
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--accent);
        }
        
        .header-logo img {
            height: 28px;
        }
        
        /* Login Screen */
        .login-screen {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 2.2rem;
            background: radial-gradient(circle at 10% 20%, rgba(232, 152, 12, 0.05) 0%, transparent 90%);
        }
        
        .login-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2.2rem 1.8rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            text-align: center;
        }
        
        .login-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--accent-soft);
            color: var(--accent);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1.2rem;
            border: 1.5px solid rgba(232, 152, 12, 0.15);
        }
        
        .login-title {
            font-family: var(--header-font);
            font-weight: 700;
            font-size: 1.4rem;
            margin-bottom: 6px;
        }
        
        .login-subtitle {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-bottom: 1.8rem;
            line-height: 1.4;
        }
        
        .form-group {
            text-align: left;
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            font-weight: 700;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        
        .form-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 0.95rem;
            font-family: var(--font-family);
            outline: none;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--accent);
            border: none;
            border-radius: 12px;
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-submit:hover {
            background: var(--accent-hover);
        }
        
        .error-message {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
            padding: 10px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 1.2rem;
            text-align: left;
        }
        
        /* Dashboard Portal Styles */
        .portal-body {
            flex: 1;
            padding: 1.2rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .student-welcome {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #fff;
            padding: 1.2rem;
            border-radius: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .plan-row-card {
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: 16px;
            padding: 1.2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            margin-bottom: 8px;
        }
        
        .plan-row-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }
        
        /* Timeline View */
        .timeline-wrapper {
            position: relative;
            padding-left: 1rem;
            border-left: 2px solid var(--border);
            margin-left: 10px;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            margin-top: 1rem;
        }
        
        .timeline-day-node {
            position: relative;
        }
        
        .timeline-badge {
            position: absolute;
            left: -21px;
            top: 2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent);
            border: 2px solid #fff;
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        
        .timeline-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }
        
        .timeline-date-label {
            font-family: var(--header-font);
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
        }
        
        .activity-item {
            display: flex;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .activity-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .activity-icon-wrap {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.85rem;
        }
        
        .print-btn-float {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--accent);
            color: #fff;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 6px 20px rgba(232, 152, 12, 0.45);
            cursor: pointer;
            z-index: 100;
        }
        
        @media print {
            .container {
                max-width: 100% !important;
                box-shadow: none !important;
            }
            header, .print-btn-float, .student-welcome {
                display: none !important;
            }
        }
        
        .pulsing-live-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
            width: fit-content;
        }
        .pulse-dot {
            width: 6px;
            height: 6px;
            background-color: #22c55e;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            animation: pulse 1.6s infinite;
        }
        @keyframes pulse {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
            }
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
            }
        }
        
        @media (min-width: 769px) {
            .portal-mobile-only { display: none !important; }
            .portal-desktop-only { display: block !important; }
        }
        @media (max-width: 768px) {
            .portal-mobile-only { display: block !important; }
            .portal-desktop-only { display: none !important; }
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <div class="header-logo">
            <img src="logo.png" alt="PEPP">
            <span>PEPP JOURNEY</span>
        </div>
        <?php if ($is_logged_in): ?>
            <a href="?logout=1" style="text-decoration:none; font-size:0.85rem; font-weight:700; color:var(--text-muted);"><i class="fas fa-sign-out-alt"></i> Logout</a>
        <?php endif; ?>
    </header>

    <?php if (!$is_logged_in): ?>
        <!-- Login Screen -->
        <div class="login-screen">
            <form method="POST" action="" class="login-card">
                <input type="hidden" name="action" value="login">
                
                <div class="login-icon">
                    <i class="fas fa-route"></i>
                </div>
                
                <h3 class="login-title">Student Study Portal</h3>
                <p class="login-subtitle">Enter your registered email address to access your custom courses study plans.</p>
                
                <?php if ($error): ?>
                    <div class="error-message"><?php echo p_esc($error); ?></div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Registered Email Address</label>
                    <input type="email" name="email" class="form-input" placeholder="e.g. student@example.com" required>
                </div>
                
                <button type="submit" class="btn-submit"><i class="fas fa-arrow-right-to-bracket"></i> Retrieve Study Journey</button>
            </form>

            <!-- App Store shortcuts (Mobile/Tab only) -->
            <div class="portal-mobile-only" style="margin-top:20px; text-align:center; width:100%; max-width:380px;">
                <p style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px; letter-spacing:0.5px;">Download PEPP Learning App</p>
                <div style="display:flex; justify-content:center; gap:10px; align-items:center;">
                    <a href="https://play.google.com/store/apps/details?id=com.pepplearning&pcampaignid=web_share" target="_blank" style="display:inline-block; transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                        <img src="https://upload.wikimedia.org/wikipedia/commons/7/78/Google_Play_Store_badge_EN.svg" alt="Google Play Store" style="height:36px; border-radius:6px; box-shadow:0 2px 4px rgba(0,0,0,0.08);">
                    </a>
                    <a href="https://apps.apple.com/in/app/pepp-the-learning-app/id6475805137" target="_blank" style="display:inline-block; transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                        <img src="https://upload.wikimedia.org/wikipedia/commons/3/3c/Download_on_the_App_Store_Badge.svg" alt="Apple App Store" style="height:36px; border-radius:6px; box-shadow:0 2px 4px rgba(0,0,0,0.08);">
                    </a>
                </div>
            </div>

            <!-- Portal Signin link (Desktop only) -->
            <div class="portal-desktop-only" style="margin-top:20px; text-align:center; width:100%; max-width:380px;">
                <div style="background:#f8fafc; border:1.5px solid var(--border); border-radius:12px; padding:12px; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <i class="fas fa-right-to-bracket" style="color:var(--accent); font-size:1.1rem;"></i>
                    <div style="text-align:left;">
                        <span style="font-size:0.7rem; color:var(--text-muted); display:block; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Already registered?</span>
                        <a href="https://courses.pepplearning.com/learn/account/signin" target="_blank" style="font-size:0.85rem; font-weight:800; color:var(--accent); text-decoration:none;">Sign in to PEPP Learning Portal <i class="fas fa-arrow-right" style="font-size:0.7rem;"></i></a>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Dashboard Portal -->
        <div class="portal-body">
            <div class="student-welcome">
                <div>
                    <h3 style="font-weight:800; font-size:1.1rem; margin:0; font-family:var(--header-font);">Hi, <?php echo p_esc($_SESSION['sp_name']); ?></h3>
                    <small style="color:rgba(255,255,255,0.7); font-size:0.75rem;"><?php echo p_esc($_SESSION['sp_email']); ?></small>
                </div>
                <div style="font-size:1.5rem; opacity:0.8;"><i class="fas fa-circle-check" style="color:#22c55e;"></i></div>
            </div>

            <?php if (!$selected_plan): ?>
                <!-- Enrolled Cards selector -->
                <h3 style="font-family:var(--header-font); font-weight:700; font-size:0.9rem; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px; letter-spacing:0.5px;">Your Course &amp; Form Registrations</h3>
                <div style="display:grid; grid-template-columns:1fr; gap:10px; margin-bottom:12px;">
                    <!-- Course Cards -->
                    <?php foreach ($my_courses as $cname): 
                        $isSelected = isset($_GET['course_name']) && $_GET['course_name'] === $cname;
                    ?>
                        <a href="?course_name=<?php echo urlencode($cname); ?>" style="display:block; text-decoration:none; color:inherit; background: <?php echo $isSelected ? 'var(--accent-soft)' : '#fff'; ?>; border: 2px solid <?php echo $isSelected ? 'var(--accent)' : 'var(--border)'; ?>; padding: 12px; border-radius: 12px; transition: all 0.2s;">
                            <div style="font-size:0.7rem; text-transform:uppercase; font-weight:700; color:var(--text-muted); margin-bottom:4px;"><i class="fas fa-graduation-cap"></i> Course Enrollment</div>
                            <div style="font-size:0.95rem; font-weight:700; color:var(--text-main);"><?php echo p_esc($cname); ?></div>
                        </a>
                    <?php endforeach; ?>
                    
                    <!-- Form Cards -->
                    <?php foreach ($my_forms as $form_card): 
                        $isSelected = isset($_GET['form_id']) && $_GET['form_id'] == $form_card['id'];
                    ?>
                        <a href="?form_id=<?php echo $form_card['id']; ?>" style="display:block; text-decoration:none; color:inherit; background: <?php echo $isSelected ? 'var(--accent-soft)' : '#fff'; ?>; border: 2px solid <?php echo $isSelected ? 'var(--accent)' : 'var(--border)'; ?>; padding: 12px; border-radius: 12px; transition: all 0.2s;">
                            <div style="font-size:0.7rem; text-transform:uppercase; font-weight:700; color:var(--text-muted); margin-bottom:4px;"><i class="fab fa-wpforms"></i> Custom Form</div>
                            <div style="font-size:0.95rem; font-weight:700; color:var(--text-main);"><?php echo p_esc($form_card['title']); ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- List plans assigned to selected target -->
                <?php if (isset($_GET['course_name']) || isset($_GET['form_id'])): ?>
                    <h3 style="font-family:var(--header-font); font-weight:700; font-size:0.9rem; color:var(--text-muted); text-transform:uppercase; margin-top:0.5rem; letter-spacing:0.5px;">Available Study Plans</h3>
                    
                    <?php if (empty($plans)): ?>
                        <div style="text-align:center; padding:3rem; border:1px dashed var(--border); border-radius:16px; color:var(--text-muted);">
                            <i class="fas fa-calendar-xmark" style="font-size:2.5rem; margin-bottom:8px; display:block;"></i>
                            No study plans active for the selected card.
                        </div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <?php foreach ($plans as $p): 
                                $is_active_plan = false;
                                if (($p['plan_type'] ?? 'date_wise') === 'date_wise') {
                                    $p_start = strtotime($p['start_date']);
                                    $p_end = strtotime($p['end_date'] . ' 23:59:59');
                                    $now_time = time();
                                    if ($now_time >= $p_start && $now_time <= $p_end) {
                                        $is_active_plan = true;
                                    }
                                }
                            ?>
                                <a href="?plan_id=<?php echo $p['id']; ?>" class="plan-row-card" style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div style="font-weight:700; font-size:0.95rem; color:var(--text-main); display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                            <span><?php echo p_esc($p['title']); ?></span>
                                            <?php if ($is_active_plan): ?>
                                                <span class="pulsing-live-badge"><span class="pulse-dot"></span> Active</span>
                                            <?php endif; ?>
                                        </div>
                                        <small style="color:var(--text-muted);">
                                            <?php if (($p['plan_type'] ?? 'date_wise') === 'day_wise'): ?>
                                                <?php echo ($p['total_days'] ?? 0); ?> Days
                                            <?php else: ?>
                                                <?php echo date('d M', strtotime($p['start_date'])); ?> to <?php echo date('d M Y', strtotime($p['end_date'])); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div style="width:30px; height:30px; border-radius:50%; background:var(--accent-soft); color:var(--accent); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                        <i class="fas fa-arrow-right"></i>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="text-align:center; padding:3rem; border:1px dashed var(--border); border-radius:16px; color:var(--text-muted); background:var(--bg);">
                        <i class="fas fa-arrow-pointer" style="font-size:2rem; margin-bottom:8px; display:block; color:var(--accent);"></i>
                        Please select a course or custom form card above to view available study plans.
                    </div>
                <?php endif; ?>
            <?php else: 
                // Calculate percentage stats
                $total_tasks = count($activities);
                $completed_tasks = count($completions);
                $pending_tasks = $total_tasks - $completed_tasks;
                $completed_pct = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
                $pending_pct = $total_tasks > 0 ? 100 - $completed_pct : 0;
            ?>
                <!-- Render specific plan activities -->
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <a href="studyplan.php" style="text-decoration:none; color:var(--text-muted); font-size:0.85rem; font-weight:700;"><i class="fas fa-arrow-left"></i> All Plans</a>
                    <span class="badge blue" style="font-size:0.75rem; font-weight:700; background:var(--accent-soft); color:var(--accent); padding:4px 10px; border-radius:30px;">v<?php echo $selected_plan['version']; ?></span>
                </div>

                <!-- Sticky Header for Task Counts -->
                <div style="position: sticky; top: 58px; background: rgba(255,255,255,0.95); backdrop-filter: blur(8px); border-bottom: 1.5px solid var(--border); padding: 10px 1.2rem; display: flex; justify-content: space-around; align-items: center; z-index: 50; margin: 0 -1.2rem 1rem -1.2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
                    <div style="text-align: center;">
                        <span style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); display: block;">Total Tasks</span>
                        <strong id="header-total-tasks" style="font-size: 1.2rem; font-weight: 800; color: var(--text-main);"><?php echo $total_tasks; ?></strong>
                    </div>
                    <div style="width: 1px; height: 20px; background: var(--border);"></div>
                    <div style="text-align: center;">
                        <span style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #10b981; display: block;">Completed</span>
                        <strong style="font-size: 1.2rem; font-weight: 800; color: #10b981;">
                            <span id="header-completed-tasks"><?php echo $completed_tasks; ?></span> 
                            (<span id="header-completed-pct"><?php echo $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0; ?></span>%)
                        </strong>
                    </div>
                    <div style="width: 1px; height: 20px; background: var(--border);"></div>
                    <div style="text-align: center;">
                        <span style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: var(--accent); display: block;">Pending</span>
                        <strong style="font-size: 1.2rem; font-weight: 800; color: var(--accent);">
                            <span id="header-pending-tasks"><?php echo $pending_tasks; ?></span> 
                            (<span id="header-pending-pct"><?php echo $total_tasks > 0 ? 100 - round(($completed_tasks / $total_tasks) * 100) : 0; ?></span>%)
                        </strong>
                    </div>
                </div>
                
                <h3 style="font-family:var(--header-font); font-weight:800; font-size:1.15rem;"><?php echo p_esc($selected_plan['title']); ?></h3>
                
                <?php if ($selected_plan['description']): ?>
                    <p style="font-size:0.85rem; color:var(--text-muted);"><?php echo p_esc($selected_plan['description']); ?></p>
                <?php endif; ?>

                <?php if (empty($activities)): ?>
                    <div style="text-align:center; padding:3rem; border:1px dashed var(--border); border-radius:16px; color:var(--text-muted);">
                        <i class="fas fa-box-open" style="font-size:2.5rem; margin-bottom:8px; display:block;"></i>
                        No activities scheduled for this study plan.
                    </div>
                <?php else: ?>
                    <div class="timeline-wrapper">
                        <?php 
                        $grouped = [];
                        foreach ($activities as $act) {
                            $grouped[$act['activity_date']][] = $act;
                        }
                        
                        $is_day_wise = ($selected_plan['plan_type'] ?? 'date_wise') === 'day_wise';
                        
                        // Default timezone setup for India (IST)
                        date_default_timezone_set('Asia/Kolkata');
                        $today_str = date('Y-m-d');
                        
                        foreach ($grouped as $date => $items):
                            $date_lbl = date('d M Y (D)', strtotime($date));
                            if ($is_day_wise) {
                                $dayNum = !empty($items[0]['day_number']) ? $items[0]['day_number'] : 1;
                                $date_lbl = "Day " . str_pad($dayNum, 2, '0', STR_PAD_LEFT);
                            }
                            
                            $is_open = true; // Always expanded for day-wise
                            if (!$is_day_wise) {
                                $is_open = ($date === $today_str);
                            }
                            
                            $total_date_tasks = count($items);
                            $completed_date_tasks = 0;
                            foreach ($items as $it) {
                                if (isset($completions[$it['id']])) {
                                    $completed_date_tasks++;
                                }
                            }
                        ?>
                            <div class="timeline-day-node">
                                <div class="timeline-badge"></div>
                                <div class="timeline-card">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; <?php echo !$is_day_wise ? 'cursor:pointer;' : ''; ?>" <?php if (!$is_day_wise): ?>onclick="toggleDateCollapse('<?php echo $date; ?>')"<?php endif; ?>>
                                        <div class="timeline-date-label" style="margin:0; display:flex; align-items:center; gap:6px;">
                                            <span><?php echo $date_lbl; ?></span>
                                            <?php if (!$is_day_wise): ?>
                                                <i class="fas fa-chevron-<?php echo $is_open ? 'up' : 'down'; ?>" id="arrow-<?php echo $date; ?>" style="font-size:0.7rem; color:var(--text-muted);"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted);" class="date-ratio-wrapper">
                                            (<span class="completed-ratio-val"><?php echo $completed_date_tasks; ?></span>/<?php echo $total_date_tasks; ?>)
                                        </div>
                                    </div>
                                    <div id="activities-group-<?php echo $date; ?>" style="display:<?php echo $is_open ? 'flex' : 'none'; ?>; flex-direction:column; gap:10px;">
                                        <?php foreach ($items as $it): 
                                            $t_conf = $types_config[$it['activity_type']] ?? ['icon' => 'fa-book-open', 'color' => '#64748b'];
                                            $is_completed = isset($completions[$it['id']]);
                                            $comp_time = $is_completed ? date('d M Y h:i A', strtotime($completions[$it['id']])) : '';
                                        ?>
                                            <div class="activity-item" id="activity-row-<?php echo $it['id']; ?>" style="display: flex; align-items: center; justify-content: space-between; <?php echo $is_completed ? 'opacity: 0.75;' : ''; ?>">
                                                <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                                                    <!-- Check Circle to mark completion -->
                                                    <div class="chk-circle-btn" onclick="toggleTaskCompletion(<?php echo $it['id']; ?>, <?php echo $selected_plan_id; ?>)" style="cursor: pointer; padding: 0 4px; font-size: 1.15rem; color: <?php echo $is_completed ? '#22c55e' : '#cbd5e1'; ?>; transition: color 0.2s;">
                                                        <i class="fa-<?php echo $is_completed ? 'solid fa-circle-check' : 'regular fa-circle'; ?>"></i>
                                                    </div>
                                                    
                                                    <div class="activity-icon-wrap" style="background:<?php echo $t_conf['color']; ?>; flex-shrink: 0;">
                                                        <i class="fas <?php echo $t_conf['icon']; ?>"></i>
                                                    </div>
                                                    
                                                    <div>
                                                        <div style="font-size:0.85rem; font-weight:700; color:var(--text-main);">
                                                            <?php if (!empty($it['resource_links'])): ?>
                                                                <a href="<?php echo htmlspecialchars(get_valid_url($it['resource_links'])); ?>" target="_blank" style="color: var(--accent); text-decoration: underline; display: inline-flex; align-items: center; gap: 4px;">
                                                                    <?php echo p_esc($it['activity_title']); ?>
                                                                    <i class="fas fa-external-link-alt" style="font-size:0.68rem;"></i>
                                                                </a>
                                                            <?php else: ?>
                                                                <?php echo p_esc($it['activity_title']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div style="font-size:0.75rem; color:var(--text-muted);">
                                                            <?php echo p_esc($it['subject']); ?> · <?php echo p_esc($it['chapter']); ?>
                                                            <?php if ($it['faculty']): ?> · Fac: <?php echo p_esc($it['faculty']); ?><?php endif; ?>
                                                        </div>
                                                        <small class="comp-time-lbl" style="display: <?php echo $is_completed ? 'block' : 'none'; ?>; font-size:0.65rem; color:#22c55e; margin-top:2px;">
                                                            <i class="fas fa-check"></i> Completed on <span class="time-val"><?php echo $comp_time; ?></span>
                                                        </small>
                                                    </div>
                                                </div>
                                                <span style="font-size:0.7rem; font-weight:700; color:var(--text-muted); background:#f1f5f9; padding:2px 6px; border-radius:4px; flex-shrink: 0;"><?php echo $it['estimated_duration']; ?>m</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button class="print-btn-float" onclick="window.print()"><i class="fas fa-print"></i></button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleDateCollapse(dateStr) {
    var el = document.getElementById('activities-group-' + dateStr);
    var arrow = document.getElementById('arrow-' + dateStr);
    if (!el) return;
    
    if (el.style.display === 'none') {
        el.style.display = 'flex';
        if (arrow) {
            arrow.classList.remove('fa-chevron-down');
            arrow.classList.add('fa-chevron-up');
        }
    } else {
        el.style.display = 'none';
        if (arrow) {
            arrow.classList.remove('fa-chevron-up');
            arrow.classList.add('fa-chevron-down');
        }
    }
}

var currentLat = null;
var currentLon = null;

// Background Geolocation coordinate fetcher
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function(position) {
        currentLat = position.coords.latitude;
        currentLon = position.coords.longitude;
        
        <?php if ($selected_plan_id > 0): ?>
        var fd = new FormData();
        fd.append('study_plan_id', <?php echo $selected_plan_id; ?>);
        fd.append('latitude', currentLat);
        fd.append('longitude', currentLon);
        
        fetch('studyplan.php?action=log_location', {
            method: 'POST',
            body: fd
        });
        <?php endif; ?>
    }, function(err) {
        console.warn("Geolocation permission not granted / error", err);
    });
}

function toggleTaskCompletion(activityId, planId) {
    var row = document.getElementById('activity-row-' + activityId);
    var btn = row.querySelector('.chk-circle-btn i');
    var label = row.querySelector('.comp-time-lbl');
    var timeSpan = row.querySelector('.time-val');
    
    var fd = new FormData();
    fd.append('activity_id', activityId);
    fd.append('study_plan_id', planId);
    if (currentLat && currentLon) {
        fd.append('latitude', currentLat);
        fd.append('longitude', currentLon);
    }
    
    fetch('studyplan.php?action=toggle_completion', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            var total = parseInt(document.getElementById('header-total-tasks').innerText);
            var completed = parseInt(document.getElementById('header-completed-tasks').innerText);

            if (data.completed) {
                btn.className = 'fa-solid fa-circle-check';
                btn.parentElement.style.color = '#22c55e';
                row.style.opacity = '0.75';
                timeSpan.innerText = data.timestamp;
                label.style.display = 'block';
                completed++;
            } else {
                btn.className = 'fa-regular fa-circle';
                btn.parentElement.style.color = '#cbd5e1';
                row.style.opacity = '1';
                label.style.display = 'none';
                completed--;
            }

            // Update Sticky Header Numbers
            var completedPct = total > 0 ? Math.round((completed / total) * 100) : 0;
            var pendingPct = total > 0 ? (100 - completedPct) : 0;
            
            document.getElementById('header-completed-tasks').innerText = completed;
            document.getElementById('header-completed-pct').innerText = completedPct;
            document.getElementById('header-pending-tasks').innerText = total - completed;
            document.getElementById('header-pending-pct').innerText = pendingPct;

            // Update Date Ratio
            var card = row.closest('.timeline-card');
            var completedTasksOnDate = card.querySelectorAll('i.fa-circle-check').length;
            card.querySelector('.completed-ratio-val').innerText = completedTasksOnDate;
        } else {
            alert('Failed to update task: ' + data.message);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Server connection error.');
    });
}
</script>
</body>
</html>
