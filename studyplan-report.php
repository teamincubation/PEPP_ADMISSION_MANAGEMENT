<?php
/**
 * PEPP JOURNEY — Dedicated Student Study Plan Performance Report
 * Mobile-First Performance Dashboard for Students
 *
 * Scoped strictly to the selected Study Plan ID with IDOR protection
 * and canonical student lifecycle security enforcement.
 */
define('PEPP_STUDENT_PORTAL', true);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/student_auth.php';
require_once __DIR__ . '/includes/StudentStudyPlanAnalytics.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

// 1. Attempt persistent login from cookie if not in session
if (!isset($_SESSION['sp_logged_in']) || $_SESSION['sp_logged_in'] !== true) {
    authenticate_student_from_cookie($pdo);
}

// 2. Authentication Check
if (!isset($_SESSION['sp_logged_in']) || $_SESSION['sp_logged_in'] !== true || empty($_SESSION['sp_email'])) {
    header('Location: studyplan.php');
    exit;
}

$email = trim($_SESSION['sp_email']);
$student_name = $_SESSION['sp_name'] ?? 'Student';
$study_plan_id = (int)($_GET['study_plan_id'] ?? $_GET['plan_id'] ?? 0);

// 3. Canonical Security Revalidation (Active Student & Single-Device Check)
$can_access = revalidate_student_study_plan_access($pdo);
if (!$can_access) {
    $forced_reason = $_SESSION['sp_force_logout_reason'] ?? '';
    unset($_SESSION['sp_force_logout_reason']);

    if ($forced_reason === 'single_device_conflict') {
        header('Location: studyplan.php?reason=device_conflict');
        exit;
    }

    $st_status = get_student_status($pdo, $email);
    $exact_reason = get_student_status_reason($pdo, $email, $st_status);
    logout_student($pdo, 'status_downgrade');
    
    $status_label = strtoupper($st_status);
    $reason_msg = $exact_reason ? htmlspecialchars($exact_reason, ENT_QUOTES, 'UTF-8') : 'Your admission status does not permit Study Plan access.';
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Account Status Restricted | PEPP JOURNEY</title>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700;800&family=Space+Grotesk:wght@700;800&display=swap" rel="stylesheet">
        <style>
            :root { --bg: #f8fafc; --text: #0f172a; --accent: #E8980C; }
            body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
            .card { background: #fff; border-radius: 20px; padding: 32px 24px; max-width: 440px; width: 100%; text-align: center; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); border: 1px solid #fee2e2; }
            .badge { display: inline-block; background: #fee2e2; color: #dc2626; padding: 6px 14px; border-radius: 20px; font-weight: 800; font-size: 0.8rem; letter-spacing: 0.5px; margin-bottom: 16px; }
            h1 { font-family: 'Space Grotesk', sans-serif; font-size: 1.3rem; margin: 0 0 10px; color: #991b1b; }
            p { color: #4b5563; font-size: 0.9rem; line-height: 1.5; margin: 0 0 24px; }
            .reason-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 14px; font-size: 0.85rem; color: #7f1d1d; margin-bottom: 24px; text-align: left; }
            .btn { display: inline-block; background: var(--accent); color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 12px; font-weight: 700; font-size: 0.9rem; transition: background 0.2s; }
            .btn:hover { background: #d2860a; }
        </style>
    </head>
    <body>
        <div class="card">
            <span class="badge">ACCOUNT <?php echo $status_label; ?></span>
            <h1>Access Restricted</h1>
            <p>Your student account is currently marked as <strong><?php echo $status_label; ?></strong>.</p>
            <div class="reason-box">
                <strong>Reason:</strong> <?php echo $reason_msg; ?>
            </div>
            <a href="studyplan.php" class="btn">Return to Portal</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 3. Resolve student details & validated courses/forms
$stmt_u = $pdo->prepare("
    SELECT user_id, name, email, phone, pepp_course, pepp_academic_year, user_photo, student_status
    FROM users
    WHERE email = ? AND status = 'approved'
    LIMIT 1
");
$stmt_u->execute([$email]);
$student_record = $stmt_u->fetch(PDO::FETCH_ASSOC);

$user_id = $student_record['user_id'] ?? '';
$course_name = $student_record['pepp_course'] ?? '';
$academic_year = $student_record['pepp_academic_year'] ?? '';
$user_photo_url = StudentStudyPlanAnalytics::resolveStudentPhotoUrl($student_record['user_photo'] ?? '');

// 4. Resolve Accessible Study Plans (Server-Side IDOR Protection)
$accessible_plan_ids = [];
if (!empty($course_name) || !empty($user_id) || !empty($email)) {
    $stmt_access = $pdo->prepare("
        SELECT DISTINCT sp.id
        FROM study_plans sp
        JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
        WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
          AND (
            sa.assignment_type = 'all' OR
            (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
            (sa.assignment_type = 'batch' AND LOWER(sa.assigned_value) = LOWER(?)) OR
            (sa.assignment_type = 'student' AND sa.assigned_value = ?) OR
            (sa.assignment_type = 'form' AND EXISTS (
                SELECT 1 FROM campaign_form_submissions s
                WHERE s.respondent_identifier = ? AND CAST(s.form_id AS CHAR) = sa.assigned_value AND s.is_deleted = 0
            ))
        )
    ");
    $stmt_access->execute([$course_name, $academic_year, $user_id, $email]);
    $accessible_plan_ids = $stmt_access->fetchAll(PDO::FETCH_COLUMN);
}

// Fallback: Check if study plan was accessed via direct valid custom form
if ($study_plan_id > 0 && !in_array($study_plan_id, $accessible_plan_ids)) {
    // Check form assignments specifically
    $stmt_form_check = $pdo->prepare("
        SELECT 1
        FROM study_plan_assignments sa
        JOIN campaign_form_submissions s ON CAST(s.form_id AS CHAR) = sa.assigned_value
        WHERE sa.study_plan_id = ? AND s.respondent_identifier = ? AND sa.assignment_type = 'form' AND sa.is_deleted = 0 AND s.is_deleted = 0
        LIMIT 1
    ");
    $stmt_form_check->execute([$study_plan_id, $email]);
    if ($stmt_form_check->fetchColumn()) {
        $accessible_plan_ids[] = $study_plan_id;
    }
}

// Auto-select first accessible plan if none specified
if ($study_plan_id <= 0 && !empty($accessible_plan_ids)) {
    $study_plan_id = (int)$accessible_plan_ids[0];
}

// Security Check: If study plan is still invalid or unauthorized, block IDOR
if ($study_plan_id <= 0 || !in_array($study_plan_id, $accessible_plan_ids)) {
    header('Location: studyplan.php?error=unauthorized_plan');
    exit;
}

// 5. Fetch Comprehensive Analytics Scoped to THIS Study Plan
$analytics = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, $email, $study_plan_id);

// If study plan details could not be loaded, redirect gracefully
if (empty($analytics['study_plan_title']) && empty($analytics['total_tasks'])) {
    // Check if plan exists
    $stmt_pcheck = $pdo->prepare("SELECT id, title, start_date, end_date, plan_type, version FROM study_plans WHERE id = ? AND is_deleted = 0");
    $stmt_pcheck->execute([$study_plan_id]);
    $raw_plan = $stmt_pcheck->fetch(PDO::FETCH_ASSOC);
    if (!$raw_plan) {
        header('Location: studyplan.php?error=plan_not_found');
        exit;
    }
    $plan_title = $raw_plan['title'];
    $plan_start_date = $raw_plan['start_date'];
    $plan_end_date = $raw_plan['end_date'];
    $plan_type = $raw_plan['plan_type'] ?? 'date_wise';
} else {
    $plan_title = $analytics['study_plan_title'];
    $stmt_pdates = $pdo->prepare("SELECT start_date, end_date, plan_type, version FROM study_plans WHERE id = ?");
    $stmt_pdates->execute([$study_plan_id]);
    $pdate_row = $stmt_pdates->fetch(PDO::FETCH_ASSOC);
    $plan_start_date = $pdate_row['start_date'] ?? null;
    $plan_end_date = $pdate_row['end_date'] ?? null;
    $plan_type = $pdate_row['plan_type'] ?? 'date_wise';
}

// Extract Key Metrics
$total_tasks = (int)($analytics['total_tasks'] ?? 0);
$completed_tasks = (int)($analytics['completed_tasks'] ?? 0);
$pending_tasks = (int)($analytics['pending_tasks'] ?? max(0, $total_tasks - $completed_tasks));
$completion_pct = (int)($analytics['completion_percentage'] ?? 0);

$current_streak = (int)($analytics['active_streak'] ?? $analytics['current_streak'] ?? 0);
$longest_streak = (int)($analytics['longest_streak'] ?? $current_streak);

// Calendar Days Calculation
$total_plan_days = StudentStudyPlanAnalytics::calculatePlanCalendarDays($plan_start_date, $plan_end_date);
$days_elapsed = 0;
$days_remaining = 0;
if (!empty($plan_start_date) && !empty($plan_end_date)) {
    $now_ts = time();
    $start_ts = strtotime($plan_start_date);
    $end_ts = strtotime($plan_end_date . ' 23:59:59');
    
    if ($now_ts >= $start_ts) {
        $days_elapsed = min($total_plan_days, max(1, (int)ceil(($now_ts - $start_ts) / 86400)));
    }
    if ($end_ts >= $now_ts) {
        $days_remaining = max(0, (int)ceil(($end_ts - $now_ts) / 86400));
    }
}

// Live Session Attendance
$stmt_live_counts = $pdo->prepare("
    SELECT COUNT(*) AS total_live
    FROM study_plan_activities
    WHERE study_plan_id = ? AND is_deleted = 0
      AND LOWER(activity_type) IN ('live session', 'live sessions', 'watch live session', 'watch live sessions')
");
$stmt_live_counts->execute([$study_plan_id]);
$total_live_sessions = (int)$stmt_live_counts->fetchColumn();

$attended_live_sessions = 0;
if ($total_live_sessions > 0) {
    $stmt_live_att = $pdo->prepare("
        SELECT COUNT(DISTINCT spa.id)
        FROM study_plan_activities spa
        JOIN study_plan_analytics an ON (
            (an.activity_uid = spa.activity_uid AND spa.activity_uid IS NOT NULL AND spa.activity_uid != '')
            OR (an.activity_id = spa.id AND (spa.activity_uid IS NULL OR spa.activity_uid = ''))
        )
        WHERE spa.study_plan_id = ? AND spa.is_deleted = 0
          AND LOWER(spa.activity_type) IN ('live session', 'live sessions', 'watch live session', 'watch live sessions')
          AND LOWER(an.student_email) = LOWER(?) AND an.action_type = 'complete_activity' AND an.completion_status = 'completed'
    ");
    $stmt_live_att->execute([$study_plan_id, $email]);
    $attended_live_sessions = (int)$stmt_live_att->fetchColumn();
}
$live_att_pct = $total_live_sessions > 0 ? round(($attended_live_sessions / $total_live_sessions) * 100) : 100;

// ── MEGA TEST ATTENDANCE & PERFORMANCE CALCULATION ──
// 1. Fetch all defined MEGA TEST activities for this Study Plan
$stmt_all_tests = $pdo->prepare("
    SELECT id, activity_uid, activity_title, activity_type, activity_date, chapter, day_number
    FROM study_plan_activities
    WHERE study_plan_id = ? AND is_deleted = 0
      AND (
        LOWER(activity_type) = 'attend mega test' OR
        LOWER(activity_type) = 'mega test' OR
        LOWER(activity_type) = 'mega tests' OR
        LOWER(activity_type) LIKE '%mega test%' OR
        LOWER(activity_title) LIKE '%mega test%'
      )
    ORDER BY day_number ASC, id ASC
");
$stmt_all_tests->execute([$study_plan_id]);
$plan_test_activities = $stmt_all_tests->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch published Mega Test result batches and results for this student (Strictly matching by registered email)
$stmt_individual_assessments = $pdo->prepare("
    SELECT 
        ar.id, ar.batch_id, ar.score, ar.total_score, ar.attendance_status, ar.student_email,
        arb.id as batch_table_id, arb.activity_id,
        arb.activity_title_snapshot, arb.activity_type_snapshot, arb.activity_date_snapshot, arb.chapter_snapshot,
        act.activity_title, act.activity_type, act.activity_date, act.chapter
    FROM assessment_results ar
    JOIN assessment_result_batches arb ON ar.batch_id = arb.id
    LEFT JOIN study_plan_activities act ON arb.activity_id = act.id
    WHERE LOWER(TRIM(ar.student_email)) = LOWER(TRIM(?))
      AND arb.study_plan_id = ?
      AND arb.status = 'published'
    ORDER BY COALESCE(arb.activity_date_snapshot, act.activity_date, '1970-01-01') ASC, arb.id ASC
");
$stmt_individual_assessments->execute([$email, $study_plan_id]);
$raw_assessment_rows = $stmt_individual_assessments->fetchAll(PDO::FETCH_ASSOC);

$mega_results_by_act_id = [];
$mega_results_by_batch_id = [];
foreach ($raw_assessment_rows as $ass_row) {
    if (!empty($ass_row['activity_id'])) {
        $mega_results_by_act_id[(int)$ass_row['activity_id']] = $ass_row;
    }
    $mega_results_by_batch_id[(int)$ass_row['batch_id']] = $ass_row;
}

$total_tests = 0;
$tests_attended = 0;
$assessment_scores_list = [];
$score_percentages = [];
$accounted_batch_ids = [];

foreach ($plan_test_activities as $tact) {
    $total_tests++;
    $tact_id = (int)$tact['id'];

    // MEGA TEST: Attendance derived EXCLUSIVELY from matching assessment_results by student email
    $res = $mega_results_by_act_id[$tact_id] ?? null;
    if ($res) {
        $accounted_batch_ids[(int)$res['batch_id']] = true;
        $att_status = $res['attendance_status'] ?? 'attended';
        $score = $res['score'] !== null ? (float)$res['score'] : null;
        $total_sc = $res['total_score'] !== null ? (float)$res['total_score'] : 100;
        $title = $res['activity_title_snapshot'] ?: ($tact['activity_title'] ?: 'Mega Test');
        $date_raw = $res['activity_date_snapshot'] ?: ($tact['activity_date'] ?: null);
        $date_formatted = $date_raw ? date('d M Y', strtotime($date_raw)) : 'Scheduled';

        $score_pct = null;
        $perf_badge = 'Pending';
        $perf_color = '#64748b';

        if ($att_status === 'attended') {
            $tests_attended++;
            if ($score !== null && $total_sc > 0) {
                $score_pct = round(($score / $total_sc) * 100);
                $score_percentages[] = $score_pct;
                if ($score_pct >= 90) { $perf_badge = 'Excellent'; $perf_color = '#10b981'; }
                elseif ($score_pct >= 75) { $perf_badge = 'Good'; $perf_color = '#0284c7'; }
                elseif ($score_pct >= 50) { $perf_badge = 'Average'; $perf_color = '#E8980C'; }
                else { $perf_badge = 'Needs Improvement'; $perf_color = '#ef4444'; }
            } else {
                $perf_badge = 'Attended';
                $perf_color = '#10b981';
            }
        } else {
            $perf_badge = 'Missed';
            $perf_color = '#ef4444';
        }

        $assessment_scores_list[] = [
            'title' => $title,
            'date' => $date_formatted,
            'attendance' => $att_status,
            'score' => $score,
            'total_score' => $total_sc,
            'percentage' => $score_pct,
            'badge' => $perf_badge,
            'badge_color' => $perf_color
        ];
    } else {
        // Mega test with NO matching assessment result
        // Checkbox in studyplan.php alone does NOT grant attendance!
        $date_formatted = !empty($tact['activity_date']) ? date('d M Y', strtotime($tact['activity_date'])) : 'Scheduled';
        $assessment_scores_list[] = [
            'title' => $tact['activity_title'] ?: 'Mega Test',
            'date' => $date_formatted,
            'attendance' => 'pending',
            'score' => null,
            'total_score' => 100,
            'percentage' => null,
            'badge' => 'Pending',
            'badge_color' => '#64748b'
        ];
    }
}

// Add any standalone published Mega Test batches not directly mapped to a study_plan_activities id
foreach ($raw_assessment_rows as $ass_row) {
    $bid = (int)$ass_row['batch_id'];
    if (!isset($accounted_batch_ids[$bid])) {
        $total_tests++;
        $att_status = $ass_row['attendance_status'] ?? 'attended';
        $score = $ass_row['score'] !== null ? (float)$ass_row['score'] : null;
        $total_sc = $ass_row['total_score'] !== null ? (float)$ass_row['total_score'] : 100;
        $title = $ass_row['activity_title_snapshot'] ?: ($ass_row['activity_title'] ?: 'Mega Test');
        $date_raw = $ass_row['activity_date_snapshot'] ?: ($ass_row['activity_date'] ?: null);
        $date_formatted = $date_raw ? date('d M Y', strtotime($date_raw)) : 'Scheduled';

        $score_pct = null;
        $perf_badge = 'Pending';
        $perf_color = '#64748b';

        if ($att_status === 'attended') {
            $tests_attended++;
            if ($score !== null && $total_sc > 0) {
                $score_pct = round(($score / $total_sc) * 100);
                $score_percentages[] = $score_pct;
                if ($score_pct >= 90) { $perf_badge = 'Excellent'; $perf_color = '#10b981'; }
                elseif ($score_pct >= 75) { $perf_badge = 'Good'; $perf_color = '#0284c7'; }
                elseif ($score_pct >= 50) { $perf_badge = 'Average'; $perf_color = '#E8980C'; }
                else { $perf_badge = 'Needs Improvement'; $perf_color = '#ef4444'; }
            } else {
                $perf_badge = 'Attended';
                $perf_color = '#10b981';
            }
        } else {
            $perf_badge = 'Missed';
            $perf_color = '#ef4444';
        }

        $assessment_scores_list[] = [
            'title' => $title,
            'date' => $date_formatted,
            'attendance' => $att_status,
            'score' => $score,
            'total_score' => $total_sc,
            'percentage' => $score_pct,
            'badge' => $perf_badge,
            'badge_color' => $perf_color
        ];
        $accounted_batch_ids[$bid] = true;
    }
}

$tests_pending = max(0, $total_tests - $tests_attended);
$avg_assessment_score = count($score_percentages) > 0 ? round(array_sum($score_percentages) / count($score_percentages)) : ($analytics['performance_score'] ?? null);
$assess_att_pct = $total_tests > 0 ? round(($tests_attended / $total_tests) * 100) : 0;

// Chapters
$chapters = $analytics['chapters'] ?? [];
$completed_chapters_count = 0;
foreach ($chapters as $ch) {
    if (($ch['completion_percentage'] ?? 0) >= 100) {
        $completed_chapters_count++;
    }
}
$total_chapters_count = count($chapters);

// Cohort Ranking for THIS Study Plan
$cohort_ranking = $analytics['cohort_ranking'] ?? [];
$cohort_size = (int)($cohort_ranking['cohort_size'] ?? 0);
$current_student_rank_data = $cohort_ranking['current_student'] ?? null;
$my_rank = $current_student_rank_data['rank'] ?? null;
$my_badge = $current_student_rank_data['badge'] ?? 'Consistent Learner';
$my_percentile = $current_student_rank_data['top_percentile'] ?? null;

// Weekly / Daily Activity (Recent 7 days)
$weekly_days = [];
$today_ts = strtotime('today');
for ($i = 6; $i >= 0; $i--) {
    $d_ts = strtotime("-$i days", $today_ts);
    $d_str = date('Y-m-d', $d_ts);
    $d_label = date('D', $d_ts);
    $weekly_days[$d_str] = [
        'day' => $d_label,
        'date' => $d_str,
        'count' => 0
    ];
}

// Fetch activities completed in last 7 days
$stmt_week_logs = $pdo->prepare("
    SELECT created_at
    FROM study_plan_analytics
    WHERE LOWER(student_email) = LOWER(?) AND study_plan_id = ? AND action_type = 'complete_activity' AND completion_status = 'completed'
      AND created_at >= ?
");
$week_start_date = date('Y-m-d 00:00:00', strtotime('-6 days', $today_ts));
$stmt_week_logs->execute([$email, $study_plan_id, $week_start_date]);
$week_logs = $stmt_week_logs->fetchAll(PDO::FETCH_ASSOC);

foreach ($week_logs as $wlog) {
    $log_date = date('Y-m-d', strtotime($wlog['created_at']));
    if (isset($weekly_days[$log_date])) {
        $weekly_days[$log_date]['count']++;
    }
}
$weekly_data = array_values($weekly_days);

// Motivational Text Generator
$first_name = explode(' ', trim($student_name))[0] ?: 'Student';
if ($completion_pct >= 90) {
    $motivation_title = "Outstanding Dedication, {$first_name}! 🚀";
    $motivation_body = "You have completed {$completion_pct}% of your study plan. You're operating at top-tier consistency. Keep dominating!";
    $motivation_icon = "🔥";
} elseif ($completion_pct >= 60) {
    $motivation_title = "Great Momentum, {$first_name}! 👏";
    $motivation_body = "You have completed {$completed_tasks} tasks so far. Maintain your daily streak to achieve your target milestone smoothly.";
    $motivation_icon = "⭐";
} elseif ($completion_pct >= 25) {
    $motivation_title = "Building Strong Habits, {$first_name}! 📈";
    $motivation_body = "You are making steady progress. Dedicate a focused study session today to clear pending tasks.";
    $motivation_icon = "💡";
} else {
    $motivation_title = "Let's Get Started, {$first_name}! 🎯";
    $motivation_body = "Every big achievement starts with completing today's tasks. Take on your next study activity now!";
    $motivation_icon = "✨";
}

// SVG Circle circumference calculation for overall completion (radius 38 -> 2 * PI * 38 = 238.76)
$circle_r = 38;
$circle_c = 2 * M_PI * $circle_r;
$circle_offset = $circle_c - (($completion_pct / 100) * $circle_c);

// SVG Donut calculation for assessment score (radius 34 -> 2 * PI * 34 = 213.63)
$donut_r = 34;
$donut_c = 2 * M_PI * $donut_r;
$donut_pct = $avg_assessment_score !== null ? min(100, max(0, $avg_assessment_score)) : 0;
$donut_offset = $donut_c - (($donut_pct / 100) * $donut_c);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($plan_title, ENT_QUOTES, 'UTF-8'); ?> — Study Plan Report | PEPP JOURNEY</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400..800;1,9..40,400..800&family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --pepp-orange: #E8980C;
            --pepp-orange-hover: #d2860a;
            --pepp-orange-light: #fff7ed;
            --pepp-orange-border: #ffedd5;
            --pepp-orange-gradient: linear-gradient(135deg, #E8980C 0%, #f97316 100%);
            
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            --border-color: #e2e8f0;
            --border-light: #f1f5f9;
            
            --green: #10b981;
            --green-light: #ecfdf5;
            --blue: #0284c7;
            --blue-light: #f0f9ff;
            --purple: #8b5cf6;
            --purple-light: #faf5ff;
            --red: #ef4444;
            --red-light: #fef2f2;
            
            --font-main: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-head: 'Space Grotesk', var(--font-main);
            
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.02);
            --shadow-card: 0 4px 12px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.03);
            --shadow-hero: 0 12px 24px -4px rgba(232, 152, 12, 0.15), 0 4px 8px -2px rgba(0, 0, 0, 0.04);
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 22px;
            --radius-pill: 9999px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg);
            color: var(--text-dark);
            min-height: 100vh;
            line-height: 1.5;
            display: flex;
            justify-content: center;
        }

        /* Responsive Mobile Container */
        .report-app-container {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.05);
        }

        /* Top App Bar */
        .app-header {
            position: sticky;
            top: 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 100;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--bg);
            color: var(--text-dark);
            text-decoration: none;
            border: 1px solid var(--border-color);
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .btn-back:hover, .btn-back:active {
            background: var(--border-color);
            color: var(--pepp-orange);
            transform: scale(0.96);
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-logo-icon {
            width: 26px;
            height: 26px;
            border-radius: 7px;
            background: var(--pepp-orange-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-family: var(--font-head);
            font-weight: 800;
            font-size: 0.8rem;
            box-shadow: 0 2px 6px rgba(232, 152, 12, 0.3);
        }

        .brand-title {
            font-family: var(--font-head);
            font-weight: 800;
            font-size: 0.95rem;
            color: var(--text-dark);
            letter-spacing: -0.2px;
        }

        .brand-sub {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--pepp-orange);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-top: -3px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .streak-pill-mini {
            display: flex;
            align-items: center;
            gap: 4px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #ea580c;
            padding: 5px 10px;
            border-radius: var(--radius-pill);
            font-size: 0.78rem;
            font-weight: 800;
        }

        /* Scrollable Content */
        .report-content {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding-bottom: 40px;
        }

        /* Student Greeting & Plan Badge */
        .student-hero-banner {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 4px 0 0 0;
        }

        .student-greeting {
            font-family: var(--font-head);
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .student-subtext {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .plan-identity-card {
            background: linear-gradient(to right, #f8fafc, #ffffff);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 4px;
        }

        .plan-title-tag {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .plan-dates-tag {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* Overall Performance Hero Card */
        .hero-progress-card {
            background: #ffffff;
            border: 1.5px solid var(--pepp-orange-border);
            border-radius: var(--radius-lg);
            padding: 18px 16px;
            box-shadow: var(--shadow-hero);
            position: relative;
            overflow: hidden;
        }

        .hero-progress-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 140px;
            height: 140px;
            background: radial-gradient(circle, rgba(232, 152, 12, 0.08) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-stats-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            text-align: center;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-light);
            margin-bottom: 14px;
        }

        .stat-col {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .stat-col:not(:last-child) {
            border-right: 1px solid var(--border-light);
        }

        .stat-num {
            font-family: var(--font-head);
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .stat-num.total { color: var(--text-dark); }
        .stat-num.completed { color: var(--green); }
        .stat-num.pending { color: var(--pepp-orange); }

        .stat-lbl {
            font-size: 0.68rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-muted);
            margin-top: 3px;
            letter-spacing: 0.3px;
        }

        .hero-ring-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .ring-text-block {
            flex: 1;
        }

        .ring-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 2px;
        }

        .ring-sub {
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.35;
        }

        .ring-wrapper {
            position: relative;
            width: 86px;
            height: 86px;
            flex-shrink: 0;
        }

        .progress-ring-svg {
            transform: rotate(-90deg);
            width: 86px;
            height: 86px;
        }

        .ring-bg {
            fill: none;
            stroke: #f1f5f9;
            stroke-width: 7;
        }

        .ring-fill {
            fill: none;
            stroke: url(#orangeGradient);
            stroke-width: 7;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.8s ease-in-out;
        }

        .ring-center-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-family: var(--font-head);
            font-weight: 800;
            font-size: 1.1rem;
            color: var(--text-dark);
        }

        /* 2x2 Key Performance Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .kpi-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 14px 12px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: transform 0.15s ease;
        }

        .kpi-card:active {
            transform: scale(0.98);
        }

        .kpi-top {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .kpi-icon {
            font-size: 0.85rem;
        }

        .kpi-val {
            font-family: var(--font-head);
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.1;
        }

        .kpi-meta {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .badge-elite {
            color: #d97706;
            background: #fef3c7;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 800;
            font-size: 0.65rem;
            display: inline-block;
        }

        /* Chapter-Wise Progress Section */
        .section-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px;
            box-shadow: var(--shadow-sm);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .section-title {
            font-family: var(--font-head);
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-dark);
        }

        .section-counter {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
        }

        .section-link {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--pepp-orange);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }

        .section-link:hover {
            color: var(--pepp-orange-hover);
        }

        .chapter-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .chapter-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .chapter-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .chapter-ratio {
            color: var(--text-muted);
            font-size: 0.78rem;
        }

        .progress-bar-bg {
            width: 100%;
            height: 7px;
            background: #f1f5f9;
            border-radius: var(--radius-pill);
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: var(--pepp-orange-gradient);
            border-radius: var(--radius-pill);
            transition: width 0.6s ease;
        }

        .progress-bar-fill.complete {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        /* Assessment Summary Card */
        .assess-summary-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px;
            box-shadow: var(--shadow-sm);
        }

        .donut-stats-layout {
            display: flex;
            align-items: center;
            justify-content: space-around;
            gap: 16px;
            padding: 8px 0;
        }

        .donut-visual-wrap {
            position: relative;
            width: 90px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .donut-svg {
            transform: rotate(-90deg);
            width: 90px;
            height: 90px;
        }

        .donut-bg {
            fill: none;
            stroke: #f1f5f9;
            stroke-width: 8;
        }

        .donut-fill {
            fill: none;
            stroke: var(--pepp-orange);
            stroke-width: 8;
            stroke-linecap: round;
        }

        .donut-center-content {
            position: absolute;
            text-align: center;
        }

        .donut-score-text {
            font-family: var(--font-head);
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1;
        }

        .donut-lbl-text {
            font-size: 0.58rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-top: 2px;
        }

        .stats-vertical-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .stat-item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 4px;
        }

        .stat-item-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .stat-item-lbl {
            color: var(--text-muted);
            font-weight: 600;
        }

        .stat-item-val {
            font-family: var(--font-head);
            font-weight: 800;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        /* Assessment Scores List */
        .assessments-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .assess-item-card {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .assess-left {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .assess-status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .assess-title {
            font-size: 0.84rem;
            font-weight: 700;
            color: var(--text-dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .assess-date {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .assess-right {
            text-align: right;
            flex-shrink: 0;
        }

        .assess-pct {
            font-family: var(--font-head);
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1;
        }

        .assess-badge-pill {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            display: block;
            margin-top: 2px;
        }

        /* Rank & Performance Card */
        .rank-card {
            background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
            border: 1.5px solid #fef3c7;
            border-radius: var(--radius-md);
            padding: 16px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            position: relative;
        }

        .trophy-badge {
            width: 48px;
            height: 48px;
            background: #fef3c7;
            color: #d97706;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin: 0 auto 8px;
            box-shadow: 0 4px 8px rgba(217, 119, 6, 0.15);
        }

        .rank-number {
            font-family: var(--font-head);
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.1;
        }

        .rank-lbl {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .rank-tag-pill {
            display: inline-block;
            background: #fef3c7;
            color: #b45309;
            padding: 4px 12px;
            border-radius: var(--radius-pill);
            font-weight: 800;
            font-size: 0.75rem;
            margin-bottom: 6px;
        }

        .rank-percentile-msg {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* Weekly Progress Chart */
        .chart-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px;
            box-shadow: var(--shadow-sm);
        }

        .svg-chart-container {
            width: 100%;
            height: 110px;
            margin: 10px 0 4px;
        }

        .chart-day-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.68rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            padding: 0 4px;
        }

        /* Timeline Vertical List */
        .timeline-vertical {
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            padding-left: 20px;
        }

        .timeline-vertical::before {
            content: '';
            position: absolute;
            top: 8px;
            bottom: 8px;
            left: 6px;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-node {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.82rem;
        }

        .node-dot {
            position: absolute;
            left: -20px;
            top: 4px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #ffffff;
            border: 3px solid var(--pepp-orange);
            box-shadow: 0 0 0 2px #ffffff;
        }

        .node-dot.green { border-color: var(--green); }
        .node-dot.blue { border-color: var(--blue); }
        .node-dot.red { border-color: var(--red); }

        .node-label {
            font-weight: 700;
            color: var(--text-dark);
        }

        .node-val {
            font-weight: 700;
            color: var(--text-muted);
            font-size: 0.78rem;
        }

        /* Motivation Section */
        .motivation-card {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .motivation-icon {
            font-size: 1.4rem;
            line-height: 1;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .motivation-title {
            font-family: var(--font-head);
            font-size: 0.95rem;
            font-weight: 800;
            color: #9a3412;
            margin-bottom: 2px;
        }

        .motivation-body {
            font-size: 0.8rem;
            color: #7c2d12;
            line-height: 1.4;
        }

        /* Value Proposition Badges */
        .value-props-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 4px;
        }

        .value-prop-item {
            background: #f8fafc;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .value-prop-icon {
            font-size: 1.1rem;
            color: var(--pepp-orange);
            flex-shrink: 0;
        }

        .value-prop-text {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.25;
        }

        .value-prop-sub {
            display: block;
            font-size: 0.62rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        /* Bottom Action Bar */
        .bottom-action-bar {
            margin-top: 10px;
        }

        .btn-full-action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            background: var(--pepp-orange-gradient);
            color: #ffffff;
            text-decoration: none;
            padding: 14px;
            border-radius: var(--radius-md);
            font-family: var(--font-head);
            font-weight: 800;
            font-size: 0.95rem;
            box-shadow: 0 4px 12px rgba(232, 152, 12, 0.25);
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }

        .btn-full-action:active {
            transform: scale(0.98);
        }

        /* Utility Hidden Elements */
        .hidden { display: none !important; }
    </style>
</head>
<body>

<div class="report-app-container">
    <!-- SVG Gradients Definition -->
    <svg width="0" height="0" style="position:absolute;">
        <defs>
            <linearGradient id="orangeGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#E8980C" />
                <stop offset="100%" stop-color="#f97316" />
            </linearGradient>
            <linearGradient id="chartAreaGradient" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="rgba(232, 152, 12, 0.3)" />
                <stop offset="100%" stop-color="rgba(232, 152, 12, 0.0)" />
            </linearGradient>
        </defs>
    </svg>

    <!-- 1. Top App Header -->
    <header class="app-header">
        <a href="studyplan.php?plan_id=<?php echo $study_plan_id; ?>" class="btn-back" title="Back to Study Plan" aria-label="Back to Study Plan">
            <i class="fas fa-arrow-left"></i>
        </a>
        
        <div class="header-brand">
            <div class="brand-logo-icon">pe</div>
            <div>
                <div class="brand-title">PEPP JOURNEY</div>
                <span class="brand-sub">Study Plan Report</span>
            </div>
        </div>

        <div class="header-actions">
            <?php if ($current_streak > 0): ?>
                <div class="streak-pill-mini" title="<?php echo $current_streak; ?> Days Active Streak">
                    <i class="fas fa-fire"></i> <?php echo $current_streak; ?>
                </div>
            <?php else: ?>
                <div style="width: 38px;"></div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Scrollable Report Content Area -->
    <main class="report-content">

        <!-- 2. Student Greeting & Study Plan Scope -->
        <section class="student-hero-banner">
            <div class="student-greeting">
                Hello, <?php echo htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8'); ?> 👋
            </div>
            <div class="student-subtext">
                <?php if ($current_streak > 0): ?>
                    <?php echo $current_streak; ?> days and counting – keep it going!
                <?php else: ?>
                    Here is your performance report for this study plan.
                <?php endif; ?>
            </div>

            <div class="plan-identity-card">
                <div class="plan-title-tag">
                    <i class="fas fa-bookmark" style="color:var(--pepp-orange);"></i>
                    <span><?php echo htmlspecialchars($plan_title, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="plan-dates-tag">
                    <?php if (!empty($plan_start_date) && !empty($plan_end_date)): ?>
                        <?php echo date('d M', strtotime($plan_start_date)); ?> – <?php echo date('d M Y', strtotime($plan_end_date)); ?>
                    <?php else: ?>
                        Active Plan
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 3. Overall Performance Hero Card -->
        <section class="hero-progress-card">
            <div class="hero-stats-row">
                <div class="stat-col">
                    <span class="stat-num total"><?php echo $total_tasks; ?></span>
                    <span class="stat-lbl">Total Tasks</span>
                </div>
                <div class="stat-col">
                    <span class="stat-num completed"><?php echo $completed_tasks; ?></span>
                    <span class="stat-lbl">Completed</span>
                </div>
                <div class="stat-col">
                    <span class="stat-num pending"><?php echo $pending_tasks; ?></span>
                    <span class="stat-lbl">Due + Pending</span>
                </div>
            </div>

            <div class="hero-ring-row">
                <div class="ring-text-block">
                    <div class="ring-title">Completion Rate</div>
                    <div class="ring-sub">
                        <?php if ($total_plan_days > 0): ?>
                            <strong><?php echo $days_elapsed; ?> of <?php echo $total_plan_days; ?> days</strong> elapsed<br>
                            <?php echo $days_remaining > 0 ? "{$days_remaining} days to reach goal" : "Study plan concluded"; ?>
                        <?php else: ?>
                            <?php echo $completed_tasks; ?> of <?php echo $total_tasks; ?> total learning activities finished
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ring-wrapper">
                    <svg class="progress-ring-svg" viewBox="0 0 86 86">
                        <circle class="ring-bg" cx="43" cy="43" r="<?php echo $circle_r; ?>" />
                        <circle class="ring-fill" cx="43" cy="43" r="<?php echo $circle_r; ?>"
                                stroke-dasharray="<?php echo $circle_c; ?>"
                                stroke-dashoffset="<?php echo $circle_offset; ?>" />
                    </svg>
                    <div class="ring-center-text"><?php echo $completion_pct; ?>%</div>
                </div>
            </div>
        </section>

        <!-- 4. Key Performance 2x2 Grid -->
        <section class="kpi-grid">
            <!-- Longest Streak -->
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-icon" style="color:#ea580c;"><i class="fas fa-fire"></i></span>
                    <span>Longest Streak</span>
                </div>
                <div class="kpi-val"><?php echo $longest_streak; ?> <span style="font-size:0.8rem; font-weight:600; color:var(--text-muted);">days</span></div>
                <div class="kpi-meta">
                    <i class="fas fa-bolt" style="color:var(--pepp-orange);"></i> <?php echo $current_streak; ?> Days Active Streak
                </div>
            </div>

            <!-- Study Plan Rank -->
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-icon" style="color:#d97706;"><i class="fas fa-trophy"></i></span>
                    <span>Study Plan Rank</span>
                </div>
                <div class="kpi-val">
                    <?php if ($my_rank !== null && $cohort_size > 0): ?>
                        <?php echo $my_rank; ?> <span style="font-size:0.8rem; font-weight:600; color:var(--text-muted);">/ <?php echo $cohort_size; ?></span>
                    <?php else: ?>
                        — <span style="font-size:0.75rem; font-weight:600; color:var(--text-muted);">N/A</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-meta">
                    <?php if (!empty($my_badge)): ?>
                        <span class="badge-elite"><?php echo htmlspecialchars($my_badge, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                        Cohort Rank
                    <?php endif; ?>
                </div>
            </div>

            <!-- Live Attendance -->
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-icon" style="color:#ef4444;"><i class="fas fa-video"></i></span>
                    <span>Live Attendance</span>
                </div>
                <div class="kpi-val">
                    <?php if ($total_live_sessions > 0): ?>
                        <?php echo $attended_live_sessions; ?> / <?php echo $total_live_sessions; ?>
                    <?php else: ?>
                        100%
                    <?php endif; ?>
                </div>
                <div class="kpi-meta">
                    <span style="color:<?php echo $live_att_pct >= 80 ? 'var(--green)' : 'var(--text-muted)'; ?>;">
                        <?php echo $live_att_pct; ?>% Attendance
                    </span>
                </div>
            </div>

            <!-- Mega Test Attendance -->
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-icon" style="color:#0284c7;"><i class="fas fa-pen-nib"></i></span>
                    <span>Mega Test Att.</span>
                </div>
                <div class="kpi-val">
                    <?php if ($total_tests > 0): ?>
                        <?php echo $tests_attended; ?> / <?php echo $total_tests; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
                <div class="kpi-meta">
                    <span style="color:<?php echo $assess_att_pct >= 75 ? 'var(--green)' : 'var(--text-muted)'; ?>;">
                        <?php echo $assess_att_pct; ?>% Attended
                    </span>
                </div>
            </div>
        </section>

        <!-- 5. Chapter-Wise Progress Section -->
        <?php if (!empty($chapters)): ?>
            <section class="section-card">
                <div class="section-header">
                    <div class="section-title">Chapter-wise Progress</div>
                    <div class="section-counter"><?php echo $completed_chapters_count; ?>/<?php echo $total_chapters_count; ?> Done</div>
                </div>

                <div class="chapter-list" id="chapterListContainer">
                    <?php 
                    $ch_index = 0;
                    foreach ($chapters as $ch): 
                        $ch_index++;
                        $is_overflow_ch = $ch_index > 4;
                        $ch_pct = (int)($ch['completion_percentage'] ?? 0);
                        $ch_completed = (int)($ch['completed_activities'] ?? 0);
                        $ch_total = (int)($ch['total_activities'] ?? 0);
                    ?>
                        <div class="chapter-row <?php echo $is_overflow_ch ? 'chapter-overflow hidden' : ''; ?>">
                            <div class="chapter-info">
                                <span><?php echo htmlspecialchars($ch['chapter_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="chapter-ratio"><?php echo $ch_completed; ?>/<?php echo $ch_total; ?></span>
                            </div>
                            <div class="progress-bar-bg">
                                <div class="progress-bar-fill <?php echo $ch_pct >= 100 ? 'complete' : ''; ?>" style="width: <?php echo $ch_pct; ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_chapters_count > 4): ?>
                    <div style="text-align: center; margin-top: 14px;">
                        <span class="section-link" id="toggleChaptersBtn" onclick="toggleChapterList()">
                            View All Chapters <i class="fas fa-chevron-down" id="toggleChapterIcon"></i>
                        </span>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- 6. Mega Test Summary Card -->
        <section class="assess-summary-card">
            <div class="section-header">
                <div class="section-title">Mega Test Summary</div>
                <div class="section-counter"><?php echo $tests_attended; ?> of <?php echo $total_tests; ?> Tests</div>
            </div>

            <div class="donut-stats-layout">
                <!-- Donut Visual -->
                <div class="donut-visual-wrap">
                    <svg class="donut-svg" viewBox="0 0 90 90">
                        <circle class="donut-bg" cx="45" cy="45" r="<?php echo $donut_r; ?>" />
                        <circle class="donut-fill" cx="45" cy="45" r="<?php echo $donut_r; ?>"
                                stroke-dasharray="<?php echo $donut_c; ?>"
                                stroke-dashoffset="<?php echo $donut_offset; ?>" />
                    </svg>
                    <div class="donut-center-content">
                        <div class="donut-score-text">
                            <?php echo $avg_assessment_score !== null ? $avg_assessment_score . '%' : '—'; ?>
                        </div>
                        <div class="donut-lbl-text">Avg Score</div>
                    </div>
                </div>

                <!-- Stats Items List -->
                <div class="stats-vertical-list">
                    <div class="stat-item-row">
                        <span class="stat-item-lbl">Total Tests</span>
                        <span class="stat-item-val"><?php echo $total_tests; ?></span>
                    </div>
                    <div class="stat-item-row">
                        <span class="stat-item-lbl">Tests Attended</span>
                        <span class="stat-item-val" style="color:var(--green);"><?php echo $tests_attended; ?></span>
                    </div>
                    <div class="stat-item-row">
                        <span class="stat-item-lbl">Tests Pending</span>
                        <span class="stat-item-val" style="color:var(--pepp-orange);"><?php echo $tests_pending; ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- 7. Mega Test Performance Breakdown -->
        <?php if (!empty($assessment_scores_list)): ?>
            <section class="section-card">
                <div class="section-header">
                    <div class="section-title">Mega Test Performance</div>
                    <div class="section-counter"><?php echo count($assessment_scores_list); ?> Recorded</div>
                </div>

                <div class="assessments-list">
                    <?php foreach ($assessment_scores_list as $ass_item): ?>
                        <div class="assess-item-card">
                            <div class="assess-left">
                                <span class="assess-status-dot" style="background-color: <?php echo $ass_item['badge_color']; ?>;"></span>
                                <div>
                                    <div class="assess-title"><?php echo htmlspecialchars($ass_item['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="assess-date"><?php echo htmlspecialchars($ass_item['date'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            </div>
                            <div class="assess-right">
                                <div class="assess-pct">
                                    <?php echo $ass_item['percentage'] !== null ? $ass_item['percentage'] . '%' : '—'; ?>
                                </div>
                                <span class="assess-badge-pill" style="color: <?php echo $ass_item['badge_color']; ?>;">
                                    <?php echo htmlspecialchars($ass_item['badge'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- 8. Rank & Cohort Performance -->
        <?php if ($my_rank !== null && $cohort_size > 0): ?>
            <section class="rank-card">
                <div class="trophy-badge">
                    <i class="fas fa-award"></i>
                </div>
                <div class="rank-number"><?php echo $my_rank; ?> / <?php echo $cohort_size; ?></div>
                <div class="rank-lbl">Your Cohort Rank</div>
                <div class="rank-tag-pill"><?php echo htmlspecialchars($my_badge, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="rank-percentile-msg">
                    <?php if ($my_percentile !== null && $my_percentile <= 20): ?>
                        🌟 You are in the top <strong><?php echo max(5, round($my_percentile)); ?>%</strong> of your batch!
                    <?php elseif ($my_percentile !== null && $my_percentile <= 50): ?>
                        📈 You are in the top <strong><?php echo round($my_percentile); ?>%</strong> of your batch!
                    <?php else: ?>
                        Keep completing activities daily to climb higher in the cohort rank!
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- 9. Weekly Activity Chart (Responsive SVG Curve) -->
        <section class="chart-card">
            <div class="section-header" style="margin-bottom: 4px;">
                <div class="section-title">Weekly Progress</div>
                <div class="section-counter">Recent Activity</div>
            </div>

            <?php
            // Calculate SVG coordinates for 7 days
            $max_val = 1;
            foreach ($weekly_data as $wd) {
                if ($wd['count'] > $max_val) $max_val = $wd['count'];
            }
            $chart_points = [];
            $width = 400;
            $height = 80;
            $padding_x = 24;
            $step_x = ($width - (2 * $padding_x)) / 6;

            foreach ($weekly_data as $idx => $wd) {
                $x = $padding_x + ($idx * $step_x);
                $ratio = $wd['count'] / $max_val;
                $y = ($height - 15) - ($ratio * ($height - 30));
                $chart_points[] = ['x' => $x, 'y' => $y, 'count' => $wd['count'], 'day' => $wd['day']];
            }

            // Build smooth SVG path
            $path_d = "M " . $chart_points[0]['x'] . " " . $chart_points[0]['y'];
            for ($i = 0; $i < count($chart_points) - 1; $i++) {
                $p0 = $chart_points[$i];
                $p1 = $chart_points[$i + 1];
                $cp_x = ($p0['x'] + $p1['x']) / 2;
                $path_d .= " C $cp_x " . $p0['y'] . ", $cp_x " . $p1['y'] . ", " . $p1['x'] . " " . $p1['y'];
            }

            // Area path
            $last_p = end($chart_points);
            $first_p = $chart_points[0];
            $area_d = $path_d . " L {$last_p['x']} {$height} L {$first_p['x']} {$height} Z";
            ?>

            <div class="svg-chart-container">
                <svg viewBox="0 0 400 90" width="100%" height="100%">
                    <!-- Area Fill -->
                    <path d="<?php echo $area_d; ?>" fill="url(#chartAreaGradient)" />
                    <!-- Curve Line -->
                    <path d="<?php echo $path_d; ?>" fill="none" stroke="#E8980C" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
                    <!-- Data Points -->
                    <?php foreach ($chart_points as $pt): ?>
                        <circle cx="<?php echo $pt['x']; ?>" cy="<?php echo $pt['y']; ?>" r="4.5" fill="#ffffff" stroke="#E8980C" stroke-width="2.5" />
                        <?php if ($pt['count'] > 0): ?>
                            <text x="<?php echo $pt['x']; ?>" y="<?php echo max(12, $pt['y'] - 8); ?>" text-anchor="middle" font-family="'DM Sans', sans-serif" font-size="10" font-weight="800" fill="#0f172a"><?php echo $pt['count']; ?></text>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </svg>
                <div class="chart-day-labels">
                    <?php foreach ($weekly_data as $wd): ?>
                        <span><?php echo $wd['day']; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- 10. Study Plan Timeline -->
        <section class="section-card">
            <div class="section-header">
                <div class="section-title">Study Plan Timeline</div>
                <div class="section-counter">Milestones</div>
            </div>

            <div class="timeline-vertical">
                <!-- Plan Started -->
                <div class="timeline-node">
                    <span class="node-dot green"></span>
                    <span class="node-label">Plan Started</span>
                    <span class="node-val"><?php echo !empty($plan_start_date) ? date('d M Y', strtotime($plan_start_date)) : 'Started'; ?></span>
                </div>

                <!-- Tasks Completed -->
                <div class="timeline-node">
                    <span class="node-dot green"></span>
                    <span class="node-label">Tasks Completed</span>
                    <span class="node-val"><?php echo $completed_tasks; ?> / <?php echo $total_tasks; ?> (<?php echo $completion_pct; ?>%)</span>
                </div>

                <!-- Live Classes Attended -->
                <div class="timeline-node">
                    <span class="node-dot <?php echo $attended_live_sessions > 0 ? 'green' : 'blue'; ?>"></span>
                    <span class="node-label">Live Classes Attended</span>
                    <span class="node-val">
                        <?php if ($total_live_sessions > 0): ?>
                            <?php echo $attended_live_sessions; ?> / <?php echo $total_live_sessions; ?> (<?php echo $live_att_pct; ?>%)
                        <?php else: ?>
                            All Up to Date
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Assessments Completed -->
                <div class="timeline-node">
                    <span class="node-dot <?php echo $tests_attended > 0 ? 'green' : 'blue'; ?>"></span>
                    <span class="node-label">Assessments Completed</span>
                    <span class="node-val"><?php echo $tests_attended; ?> / <?php echo $total_tests; ?> (<?php echo $assess_att_pct; ?>%)</span>
                </div>

                <!-- Plan Ends -->
                <div class="timeline-node">
                    <span class="node-dot red"></span>
                    <span class="node-label">Plan Ends</span>
                    <span class="node-val"><?php echo !empty($plan_end_date) ? date('d M Y', strtotime($plan_end_date)) : 'Ongoing'; ?></span>
                </div>
            </div>
        </section>

        <!-- 11. Contextual Motivational Section -->
        <section class="motivation-card">
            <div class="motivation-icon"><?php echo $motivation_icon; ?></div>
            <div>
                <div class="motivation-title"><?php echo htmlspecialchars($motivation_title, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="motivation-body"><?php echo htmlspecialchars($motivation_body, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </section>

        <!-- 12. PEPP Learning Value Propositions -->
        <section class="value-props-grid">
            <div class="value-prop-item">
                <div class="value-prop-icon"><i class="fas fa-chart-line"></i></div>
                <div>
                    <span class="value-prop-text">Track your progress</span>
                    <span class="value-prop-sub">in one place</span>
                </div>
            </div>
            <div class="value-prop-item">
                <div class="value-prop-icon"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <span class="value-prop-text">Stay consistent</span>
                    <span class="value-prop-sub">build winning habits</span>
                </div>
            </div>
            <div class="value-prop-item">
                <div class="value-prop-icon"><i class="fas fa-trophy"></i></div>
                <div>
                    <span class="value-prop-text">Compete &amp; improve</span>
                    <span class="value-prop-sub">beat your personal best</span>
                </div>
            </div>
            <div class="value-prop-item">
                <div class="value-prop-icon"><i class="fas fa-bullseye"></i></div>
                <div>
                    <span class="value-prop-text">Achieve your goals</span>
                    <span class="value-prop-sub">we are with you</span>
                </div>
            </div>
        </section>

        <!-- 13. Bottom Quick Action Button -->
        <section class="bottom-action-bar">
            <a href="studyplan.php?plan_id=<?php echo $study_plan_id; ?>" class="btn-full-action">
                <i class="fas fa-arrow-left"></i> Back to Study Plan
            </a>
        </section>

    </main>
</div>

<script>
    function toggleChapterList() {
        const overflowRows = document.querySelectorAll('.chapter-overflow');
        const icon = document.getElementById('toggleChapterIcon');
        const btn = document.getElementById('toggleChaptersBtn');
        
        let isExpanded = false;
        overflowRows.forEach(row => {
            if (row.classList.contains('hidden')) {
                row.classList.remove('hidden');
                isExpanded = true;
            } else {
                row.classList.add('hidden');
                isExpanded = false;
            }
        });

        if (isExpanded) {
            btn.innerHTML = 'Show Fewer Chapters <i class="fas fa-chevron-up" id="toggleChapterIcon"></i>';
        } else {
            btn.innerHTML = 'View All Chapters <i class="fas fa-chevron-down" id="toggleChapterIcon"></i>';
        }
    }
</script>

</body>
</html>
