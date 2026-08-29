<?php
/**
 * PEPP Learning ERP — Superadmin Mentor Performance Report & Analytics
 *
 * Comprehensive, production-grade analytics dashboard for Superadmin:
 * - Real-time mentor performance command center
 * - Strict Superadmin authorization guard (403 for non-superadmin)
 * - Canonical student lifecycle filtering: users.status = 'approved' AND users.student_status = 'active'
 * - Authoritative mentor eligibility from mentoring architecture
 * - Fair, normalized per-student cohort ranking and deterministic performance badges
 * - Activity trend charts, student engagement distribution, and timeline
 * - Zero unnecessary PII exposure (no WhatsApp/DOB/tokens)
 * - Safe CSV export with formula-injection neutralization
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_super_admin(); // Strict Superadmin Authorization Guard

require_once __DIR__ . '/includes/StudentStudyPlanAnalytics.php';

$active_page = 'mentor-reports';
$page_title  = 'Mentors Report';
$page_sub    = 'Complete performance analytics and work report of mentors';

// ── Helper: Safe CSV string to prevent formula injection ─────────────
function csv_safe($val): string {
    $str = (string)($val ?? '');
    if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@'], true)) {
        return "'" . $str;
    }
    return $str;
}

// ── Helper: Parse User Agent to readable summary ──────────────────────
function parse_user_agent_summary(?string $ua): string {
    if (!$ua || trim($ua) === '') return 'Not available';
    $os = 'Unknown OS';
    $browser = 'Unknown Browser';

    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    if (stripos($ua, 'Edg') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'Chrome') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) $browser = 'Safari';
    elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) $browser = 'Opera';

    if ($os === 'Unknown OS' && $browser === 'Unknown Browser') {
        return 'Not available';
    }
    return "{$browser} on {$os}";
}

// ── Helper: Resolve Staff Photo URL safely ───────────────────────────
if (!function_exists('resolve_staff_photo_url')) {
    function resolve_staff_photo_url(?string $photo): string {
        if (!$photo || trim($photo) === '') return '';
        $photo = trim($photo);

        // 1. External absolute URLs or Data URIs
        if (preg_match('#^https?://#i', $photo) || strpos($photo, 'data:image/') === 0) {
            if (strpos($photo, 'data:image/') === 0 || preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)(\?.*)?$/i', $photo)) {
                return $photo;
            }
            return '';
        }

        // 2. Reject non-image extensions
        if (!preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)$/i', $photo)) {
            return '';
        }

        // 3. Security: Block directory traversal
        if (strpos($photo, '../..') !== false || strpos($photo, '..\\..') !== false) {
            return '';
        }

        // 4. Normalize path
        $clean = preg_replace('#^[./\\\\]+#', '', $photo);
        $clean = ltrim($clean, '/\\');

        if (strpos($clean, 'photos/') === 0) {
            $clean = 'uploads/' . $clean;
        }

        if (strpos($clean, 'uploads/') === 0) {
            return '../' . $clean;
        }

        return '../uploads/photos/' . basename($clean);
    }
}

// ── 1. Fetch Authoritative Active Mentors ──────────────────────────────
// Only admins with actual mentoring assignments, activity, or mentoring permissions
$has_employees = false;
try { $has_employees = (bool)$pdo->query("SELECT 1 FROM employees LIMIT 1"); } catch (Throwable $e) {}

$has_admin_type = false;
try { $has_admin_type = (bool)$pdo->query("SELECT admin_type FROM admins LIMIT 1"); } catch (Throwable $e) {}

$has_full_name = false;
try { $has_full_name = (bool)$pdo->query("SELECT full_name FROM admins LIMIT 1"); } catch (Throwable $e) {}

$has_email = false;
try { $has_email = (bool)$pdo->query("SELECT email FROM admins LIMIT 1"); } catch (Throwable $e) {}

$admin_type_col = $has_admin_type ? "a.admin_type" : "a.role AS admin_type";
$full_name_col = $has_full_name ? "a.full_name" : "a.username AS full_name";
$email_col = $has_email ? "a.email" : "'' AS email";
$staff_joins = $has_employees ? "LEFT JOIN employees e ON a.id = e.admin_id" : "";
$staff_cols = $has_employees ? "e.photo AS staff_photo, e.employee_id AS staff_code" : "NULL AS staff_photo, NULL AS staff_code";

$mentor_where_clauses = ["a.permissions LIKE '%student-mentoring%'", "a.role = 'super_admin'"];
try {
    if ($pdo->query("SELECT 1 FROM mentor_student_assignments LIMIT 1")) {
        $mentor_where_clauses[] = "a.id IN (SELECT DISTINCT admin_id FROM mentor_student_assignments)";
    }
} catch (Throwable $e) {}
try {
    if ($pdo->query("SELECT 1 FROM mentor_course_assignments LIMIT 1")) {
        $mentor_where_clauses[] = "a.id IN (SELECT DISTINCT admin_id FROM mentor_course_assignments)";
    }
} catch (Throwable $e) {}
try {
    if ($pdo->query("SELECT 1 FROM mentor_call_logs LIMIT 1")) {
        $mentor_where_clauses[] = "a.id IN (SELECT DISTINCT admin_id FROM mentor_call_logs)";
    }
} catch (Throwable $e) {}
try {
    if ($pdo->query("SELECT 1 FROM mentor_remarks LIMIT 1")) {
        $mentor_where_clauses[] = "a.id IN (SELECT DISTINCT admin_id FROM mentor_remarks)";
    }
} catch (Throwable $e) {}

$mentor_where_sql = implode(" OR ", $mentor_where_clauses);

$all_mentors = [];
try {
    $stmt_mentors = $pdo->query("
        SELECT DISTINCT a.id, a.username, $full_name_col, $email_col, a.role, $admin_type_col, a.status,
               $staff_cols
        FROM admins a
        $staff_joins
        WHERE a.status = 'active'
          AND ($mentor_where_sql)
        ORDER BY $full_name_col ASC, a.username ASC
    ");
    $all_mentors = $stmt_mentors->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Mentor reports mentor query error: " . $e->getMessage());
    try {
        $all_mentors = $pdo->query("SELECT id, username, role, status FROM admins WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $all_mentors = [];
    }
}

// Selected Mentor ID
$selected_mentor_id = isset($_GET['mentor_id']) ? (int)$_GET['mentor_id'] : 0;
if ($selected_mentor_id <= 0 && !empty($all_mentors)) {
    $selected_mentor_id = (int)$all_mentors[0]['id'];
}

$selected_mentor = null;
foreach ($all_mentors as $m) {
    if ((int)$m['id'] === $selected_mentor_id) {
        $selected_mentor = $m;
        break;
    }
}

// If selected mentor is invalid, fallback to first available
if (!$selected_mentor && !empty($all_mentors)) {
    $selected_mentor = $all_mentors[0];
    $selected_mentor_id = (int)$selected_mentor['id'];
}

// ── 2. Date Range Resolution ──────────────────────────────────────────
$range_param = trim($_GET['range'] ?? 'last_30_days');
$custom_start = trim($_GET['start_date'] ?? '');
$custom_end   = trim($_GET['end_date'] ?? '');

$today = date('Y-m-d');
$range_title = 'Last 30 Days';

switch ($range_param) {
    case 'today':
        $start_date = $today;
        $end_date   = $today;
        $range_title = 'Today (' . date('d M Y') . ')';
        break;
    case 'yesterday':
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date   = $start_date;
        $range_title = 'Yesterday (' . date('d M Y', strtotime('-1 day')) . ')';
        break;
    case 'last_7_days':
        $start_date = date('Y-m-d', strtotime('-6 days'));
        $end_date   = $today;
        $range_title = 'Last 7 Days';
        break;
    case 'this_week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date   = $today;
        $range_title = 'This Week';
        break;
    case 'this_month':
        $start_date = date('Y-m-01');
        $end_date   = $today;
        $range_title = 'This Month (' . date('F Y') . ')';
        break;
    case 'prev_month':
        $start_date = date('Y-m-01', strtotime('first day of last month'));
        $end_date   = date('Y-m-t', strtotime('last day of last month'));
        $range_title = 'Previous Month (' . date('F Y', strtotime('first day of last month')) . ')';
        break;
    case 'all_time':
        $start_date = '2020-01-01';
        $end_date   = $today;
        $range_title = 'All Time';
        break;
    case 'custom':
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_end)) {
            $start_date = $custom_start;
            $end_date   = $custom_end;
            $range_title = date('d M Y', strtotime($start_date)) . ' – ' . date('d M Y', strtotime($end_date));
        } else {
            $start_date = date('Y-m-d', strtotime('-29 days'));
            $end_date   = $today;
            $range_param = 'last_30_days';
            $range_title = 'Last 30 Days';
        }
        break;
    case 'last_30_days':
    default:
        $start_date = date('Y-m-d', strtotime('-29 days'));
        $end_date   = $today;
        $range_param = 'last_30_days';
        $range_title = 'Last 30 Days';
        break;
}

$start_datetime = $start_date . ' 00:00:00';
$end_datetime   = $end_date . ' 23:59:59';
$total_days_in_window = max(1, (int)round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);

// ── 3. CSV Export Handler ─────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $selected_mentor) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mentor_report_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $selected_mentor['username']) . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['PEPP Learning ERP — Mentor Performance Report']);
    fputcsv($out, ['Mentor Name', csv_safe($selected_mentor['full_name'] ?: $selected_mentor['username'])]);
    fputcsv($out, ['Username', csv_safe($selected_mentor['username'])]);
    fputcsv($out, ['Role / Type', csv_safe(ucfirst(str_replace('_', ' ', $selected_mentor['admin_type'] ?? $selected_mentor['role'])))]);
    fputcsv($out, ['Reporting Period', csv_safe($range_title . " ($start_date to $end_date)")]);
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    // Summary Section
    fputcsv($out, ['--- METRIC SUMMARY ---']);
    fputcsv($out, ['Metric', 'Value']);

    $calls_exp = 0;
    try {
        $stmt_c_exp = $pdo->prepare("SELECT COUNT(*) FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?");
        $stmt_c_exp->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        $calls_exp = (int)$stmt_c_exp->fetchColumn();
    } catch (Throwable $e) {}
    fputcsv($out, ['Total Calls Logged', $calls_exp]);

    $remarks_exp = 0;
    try {
        $stmt_r_exp = $pdo->prepare("SELECT COUNT(*) FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ?");
        $stmt_r_exp->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        $remarks_exp = (int)$stmt_r_exp->fetchColumn();
    } catch (Throwable $e) {}
    fputcsv($out, ['Total Remarks Added', $remarks_exp]);

    $assigned_exp = 0;
    try {
        $stmt_st_exp = $pdo->prepare("
            SELECT COUNT(DISTINCT msa.student_user_id)
            FROM mentor_student_assignments msa
            JOIN users u ON msa.student_user_id = u.user_id
            WHERE msa.admin_id = ? AND msa.status = 'active'
              AND u.status = 'approved'
              AND u.student_status = 'active'
        ");
        $stmt_st_exp->execute([$selected_mentor_id]);
        $assigned_exp = (int)$stmt_st_exp->fetchColumn();
    } catch (Throwable $e) {}
    fputcsv($out, ['Assigned Active Students', $assigned_exp]);

    fputcsv($out, []);
    fputcsv($out, ['--- RECENT CALL LOGS IN PERIOD ---']);
    fputcsv($out, ['Date & Time', 'Student ID', 'Call Notes']);
    try {
        $stmt_cl_rows = $pdo->prepare("
            SELECT call_timestamp, student_user_id, notes
            FROM mentor_call_logs
            WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?
            ORDER BY call_timestamp DESC LIMIT 500
        ");
        $stmt_cl_rows->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        while ($cr = $stmt_cl_rows->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [csv_safe($cr['call_timestamp']), csv_safe($cr['student_user_id']), csv_safe($cr['notes'] ?? '')]);
        }
    } catch (Throwable $e) {}

    fputcsv($out, []);
    fputcsv($out, ['--- RECENT REMARKS IN PERIOD ---']);
    fputcsv($out, ['Date & Time', 'Student ID', 'Remark']);
    try {
        $stmt_rm_rows = $pdo->prepare("
            SELECT created_at, student_user_id, remark
            FROM mentor_remarks
            WHERE admin_id = ? AND created_at BETWEEN ? AND ?
            ORDER BY created_at DESC LIMIT 500
        ");
        $stmt_rm_rows->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        while ($rr = $stmt_rm_rows->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [csv_safe($rr['created_at']), csv_safe($rr['student_user_id']), csv_safe($rr['remark'])]);
        }
    } catch (Throwable $e) {}

    fclose($out);
    exit();
}

// ── 4. Calculations for Selected Mentor ────────────────────────────────
$mentor_stats = [
    'assigned_students_count' => 0,
    'assigned_students' => [],
    'calls_count' => 0,
    'remarks_count' => 0,
    'unique_contacted_count' => 0,
    'contact_rate' => 0.0,
    'uncontacted_count' => 0,
    'active_days_count' => 0,
    'current_streak' => 0,
    'avg_student_progress' => 0.0,
    'avg_student_attendance' => 0.0,
    'is_online' => false,
    'last_active_time' => null,
    'last_active_label' => 'Not available',
    'current_page' => null,
    'login_time' => null,
    'approx_location' => 'Location unavailable',
    'device_info' => 'Not available',
];

if ($selected_mentor) {
    $m_username = $selected_mentor['username'] ?? '';

    // A. Online / Offline Presence Check
    try {
        $stmt_pres = $pdo->prepare("SELECT * FROM admin_presence WHERE username = ? LIMIT 1");
        $stmt_pres->execute([$m_username]);
        $presence_row = $stmt_pres->fetch(PDO::FETCH_ASSOC);

        if ($presence_row) {
            $last_seen_ts = strtotime($presence_row['last_seen'] ?? '');
            $is_recent = (time() - $last_seen_ts) <= 300; // 5 minutes
            $is_not_idle = (int)($presence_row['is_idle'] ?? 0) === 0;

            $mentor_stats['is_online'] = ($is_recent && $is_not_idle);
            $mentor_stats['last_active_time'] = $presence_row['last_seen'] ?? null;
            $mentor_stats['current_page'] = $presence_row['current_page'] ?? null;
            $mentor_stats['login_time'] = $presence_row['login_time'] ?? null;

            $sec_ago = time() - $last_seen_ts;
            if ($sec_ago < 60) {
                $mentor_stats['last_active_label'] = 'Just now';
            } elseif ($sec_ago < 3600) {
                $mentor_stats['last_active_label'] = round($sec_ago / 60) . ' mins ago';
            } elseif ($sec_ago < 86400) {
                $mentor_stats['last_active_label'] = round($sec_ago / 3600) . ' hours ago';
            } else {
                $mentor_stats['last_active_label'] = date('d M, h:i A', $last_seen_ts);
            }
        } else {
            // Fallback to admin_activity_log for last active
            try {
                $stmt_last_act = $pdo->prepare("SELECT MAX(created_at) FROM admin_activity_log WHERE admin_username = ?");
                $stmt_last_act->execute([$m_username]);
                $last_act_str = $stmt_last_act->fetchColumn();
                if ($last_act_str) {
                    $mentor_stats['last_active_time'] = $last_act_str;
                    $mentor_stats['last_active_label'] = date('d M Y, h:i A', strtotime($last_act_str));
                }
            } catch (Throwable $e2) {}
        }
    } catch (Throwable $e) {}

    // B. Assigned Active Students (Strict Canonical Invariant: status = 'approved' AND student_status = 'active')
    try {
        $stmt_assigned = $pdo->prepare("
            SELECT msa.student_user_id, msa.course_name, msa.assigned_at,
                   u.name AS student_name, u.email AS student_email, u.student_status, u.status AS user_status,
                   u.pepp_academic_year, u.pepp_course
            FROM mentor_student_assignments msa
            JOIN users u ON msa.student_user_id = u.user_id
            WHERE msa.admin_id = ? AND msa.status = 'active'
              AND u.status = 'approved'
              AND u.student_status = 'active'
            ORDER BY u.name ASC
        ");
        $stmt_assigned->execute([$selected_mentor_id]);
        $mentor_stats['assigned_students'] = $stmt_assigned->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mentor_stats['assigned_students_count'] = count($mentor_stats['assigned_students']);
    } catch (Throwable $e) {
        $mentor_stats['assigned_students'] = [];
        $mentor_stats['assigned_students_count'] = 0;
    }

    $assigned_uids = array_column($mentor_stats['assigned_students'], 'student_user_id');

    // C. Calls in Period
    try {
        $stmt_calls = $pdo->prepare("
            SELECT COUNT(*) FROM mentor_call_logs
            WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?
        ");
        $stmt_calls->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        $mentor_stats['calls_count'] = (int)$stmt_calls->fetchColumn();
    } catch (Throwable $e) {}

    // D. Remarks in Period
    try {
        $stmt_remarks = $pdo->prepare("
            SELECT COUNT(*) FROM mentor_remarks
            WHERE admin_id = ? AND created_at BETWEEN ? AND ?
        ");
        $stmt_remarks->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        $mentor_stats['remarks_count'] = (int)$stmt_remarks->fetchColumn();
    } catch (Throwable $e) {}

    // E. Unique Students Contacted in Period & Contact Rate
    try {
        $stmt_contacted = $pdo->prepare("
            SELECT COUNT(DISTINCT student_user_id) FROM (
                SELECT student_user_id FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?
                UNION
                SELECT student_user_id FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ?
            ) combined_contacts
        ");
        $stmt_contacted->execute([$selected_mentor_id, $start_datetime, $end_datetime, $selected_mentor_id, $start_datetime, $end_datetime]);
        $mentor_stats['unique_contacted_count'] = (int)$stmt_contacted->fetchColumn();

        if ($mentor_stats['assigned_students_count'] > 0) {
            if (!empty($assigned_uids)) {
                $placeholders = implode(',', array_fill(0, count($assigned_uids), '?'));
                $params = array_merge([$selected_mentor_id, $start_datetime, $end_datetime, $selected_mentor_id, $start_datetime, $end_datetime], $assigned_uids);
                $stmt_ca = $pdo->prepare("
                    SELECT COUNT(DISTINCT student_user_id) FROM (
                        SELECT student_user_id FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?
                        UNION
                        SELECT student_user_id FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ?
                    ) c WHERE student_user_id IN ($placeholders)
                ");
                $stmt_ca->execute($params);
                $contacted_assigned = (int)$stmt_ca->fetchColumn();
                $mentor_stats['contact_rate'] = round(($contacted_assigned / $mentor_stats['assigned_students_count']) * 100, 1);
                $mentor_stats['uncontacted_count'] = max(0, $mentor_stats['assigned_students_count'] - $contacted_assigned);
            }
        }
    } catch (Throwable $e) {}

    // F. Active Days & Consecutive Streak (Meaningful Tracked Activity)
    try {
        $stmt_act_days = $pdo->prepare("
            SELECT DISTINCT act_date FROM (
                SELECT DATE(call_timestamp) as act_date FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?
                UNION
                SELECT DATE(created_at) as act_date FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ?
                UNION
                SELECT DATE(created_at) as act_date FROM admin_activity_log WHERE admin_username = ? AND created_at BETWEEN ? AND ? AND action_type IN ('login', 'call_logged', 'remark_added', 'student_assigned', 'student_updated', 'studyplan_reviewed')
            ) all_days
            WHERE act_date IS NOT NULL
            ORDER BY act_date DESC
        ");
        $stmt_act_days->execute([$selected_mentor_id, $start_datetime, $end_datetime, $selected_mentor_id, $start_datetime, $end_datetime, $m_username, $start_datetime, $end_datetime]);
        $active_dates = $stmt_act_days->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $mentor_stats['active_days_count'] = count($active_dates);

        // Calculate consecutive streak working backward from today or yesterday
        $streak = 0;
        $check_date = date('Y-m-d');
        if (!in_array($check_date, $active_dates, true)) {
            $check_date = date('Y-m-d', strtotime('-1 day'));
        }
        while (in_array($check_date, $active_dates, true)) {
            $streak++;
            $check_date = date('Y-m-d', strtotime($check_date . ' -1 day'));
        }
        $mentor_stats['current_streak'] = $streak;
    } catch (Throwable $e) {}

    // G. Bulk Study Plan Analytics on Assigned Active Students
    if (!empty($mentor_stats['assigned_students'])) {
        try {
            $bulk_students = [];
            foreach ($mentor_stats['assigned_students'] as $ast) {
                $bulk_students[] = [
                    'email' => $ast['student_email'] ?? '',
                    'user_id' => $ast['student_user_id'] ?? '',
                    'pepp_academic_year' => $ast['pepp_academic_year'] ?? '',
                    'pepp_course' => $ast['course_name'] ?? ($ast['pepp_course'] ?? '')
                ];
            }
            $bulk_analytics = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, $bulk_students);
            if (!empty($bulk_analytics)) {
                $sum_progress = 0;
                $sum_att = 0;
                $valid_cnt = 0;
                foreach ($bulk_analytics as $ba) {
                    if (isset($ba['completion_percentage']) && $ba['completion_percentage'] !== null) {
                        $sum_progress += (float)$ba['completion_percentage'];
                        $valid_cnt++;
                    }
                    if (isset($ba['attendance_rate']) && $ba['attendance_rate'] !== null) {
                        $sum_att += (float)$ba['attendance_rate'];
                    }
                }
                if ($valid_cnt > 0) {
                    $mentor_stats['avg_student_progress'] = round($sum_progress / $valid_cnt, 1);
                    $mentor_stats['avg_student_attendance'] = round($sum_att / $valid_cnt, 1);
                }
            }
        } catch (Throwable $e) {
            error_log("Study plan analytics calculation error: " . $e->getMessage());
        }
    }
}

// ── 5. Active Mentors Cohort Leaderboard & Normalized Ranking ─────────
$cohort_rankings = [];
$total_cohort_active = count($all_mentors);

// Period target benchmarks (scaled proportionally to date window length)
$target_calls_per_student = max(0.5, ($total_days_in_window / 30.0) * 2.0); // e.g. 2 calls/month/student
$target_remarks_per_student = max(0.5, ($total_days_in_window / 30.0) * 1.5); // e.g. 1.5 remarks/month/student

foreach ($all_mentors as $mentor_entry) {
    $mid = (int)$mentor_entry['id'];
    $muser = $mentor_entry['username'] ?? '';
    $m_assigned_cnt = 0;
    $m_calls = 0;
    $m_remarks = 0;
    $m_contacted = 0;
    $m_active_days = 0;

    // Assigned active students (Strict Canonical Invariant: status = 'approved' AND student_status = 'active')
    try {
        $stmt_c_ass = $pdo->prepare("
            SELECT COUNT(DISTINCT msa.student_user_id)
            FROM mentor_student_assignments msa
            JOIN users u ON msa.student_user_id = u.user_id
            WHERE msa.admin_id = ? AND msa.status = 'active'
              AND u.status = 'approved'
              AND u.student_status = 'active'
        ");
        $stmt_c_ass->execute([$mid]);
        $m_assigned_cnt = (int)$stmt_c_ass->fetchColumn();
    } catch (Throwable $e) {}

    // Calls in range
    try {
        $stmt_m_calls = $pdo->prepare("SELECT COUNT(*) FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?");
        $stmt_m_calls->execute([$mid, $start_datetime, $end_datetime]);
        $m_calls = (int)$stmt_m_calls->fetchColumn();
    } catch (Throwable $e) {}

    // Remarks in range
    try {
        $stmt_m_rem = $pdo->prepare("SELECT COUNT(*) FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ?");
        $stmt_m_rem->execute([$mid, $start_datetime, $end_datetime]);
        $m_remarks = (int)$stmt_m_rem->fetchColumn();
    } catch (Throwable $e) {}

    // Unique students contacted in range
    try {
        $stmt_m_cont = $pdo->prepare("
            SELECT COUNT(DISTINCT student_user_id) FROM (
                SELECT student_user_id FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?
                UNION
                SELECT student_user_id FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ?
            ) c
        ");
        $stmt_m_cont->execute([$mid, $start_datetime, $end_datetime, $mid, $start_datetime, $end_datetime]);
        $m_contacted = (int)$stmt_m_cont->fetchColumn();
    } catch (Throwable $e) {}

    // Normalized Contact Rate
    $m_contact_rate = $m_assigned_cnt > 0 ? min(100.0, round(($m_contacted / $m_assigned_cnt) * 100, 1)) : ($m_contacted > 0 ? 100.0 : 0.0);

    // Active days in range
    try {
        $stmt_m_days = $pdo->prepare("
            SELECT COUNT(DISTINCT act_date) FROM (
                SELECT DATE(call_timestamp) as act_date FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?
                UNION
                SELECT DATE(created_at) as act_date FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ?
                UNION
                SELECT DATE(created_at) as act_date FROM admin_activity_log WHERE admin_username = ? AND created_at BETWEEN ? AND ? AND action_type IN ('login', 'call_logged', 'remark_added', 'student_assigned', 'student_updated', 'studyplan_reviewed')
            ) d WHERE act_date IS NOT NULL
        ");
        $stmt_m_days->execute([$mid, $start_datetime, $end_datetime, $mid, $start_datetime, $end_datetime, $muser, $start_datetime, $end_datetime]);
        $m_active_days = (int)$stmt_m_days->fetchColumn();
    } catch (Throwable $e) {}

    // Fair Normalized Scoring:
    // 1. Normalized Calls (20%): Evaluates calls per active student rather than raw volume
    $per_student_calls = $m_assigned_cnt > 0 ? ($m_calls / $m_assigned_cnt) : ($m_calls > 0 ? 1.0 : 0.0);
    $calls_norm = min(100.0, ($per_student_calls / $target_calls_per_student) * 100);

    // 2. Normalized Remarks (15%): Evaluates remarks per active student
    $per_student_remarks = $m_assigned_cnt > 0 ? ($m_remarks / $m_assigned_cnt) : ($m_remarks > 0 ? 1.0 : 0.0);
    $remarks_norm = min(100.0, ($per_student_remarks / $target_remarks_per_student) * 100);

    // 3. Consistency (10%): Active days relative to window length
    $consistency_norm = min(100.0, ($m_active_days / max(1, $total_days_in_window)) * 100);

    // 4. Progress Baseline (20%)
    $progress_norm = 75.0;

    // Composite Formula:
    // Score = Contact Rate (35%) + Normalized Calls (20%) + Normalized Remarks (15%) + Progress (20%) + Consistency (10%)
    $score = round(
        ($m_contact_rate * 0.35) +
        ($calls_norm * 0.20) +
        ($remarks_norm * 0.15) +
        ($progress_norm * 0.20) +
        ($consistency_norm * 0.10),
        1
    );

    $cohort_rankings[] = [
        'id' => $mid,
        'username' => $mentor_entry['username'],
        'full_name' => $mentor_entry['full_name'] ?: $mentor_entry['username'],
        'staff_photo' => $mentor_entry['staff_photo'] ?? null,
        'assigned_students' => $m_assigned_cnt,
        'calls' => $m_calls,
        'remarks' => $m_remarks,
        'contacted' => $m_contacted,
        'contact_rate' => $m_contact_rate,
        'active_days' => $m_active_days,
        'score' => $score
    ];
}

// Sort cohort by score descending, then by contact rate, then by calls
usort($cohort_rankings, function ($a, $b) {
    if ($b['score'] == $a['score']) {
        if ($b['contact_rate'] == $a['contact_rate']) {
            return $b['calls'] <=> $a['calls'];
        }
        return ($b['contact_rate'] > $a['contact_rate']) ? 1 : -1;
    }
    return ($b['score'] > $a['score']) ? 1 : -1;
});

// Determine Selected Mentor Rank & Badge
$selected_mentor_rank = 1;
$selected_mentor_score = 0;
$total_ranked = count($cohort_rankings);

foreach ($cohort_rankings as $idx => $r) {
    if ($r['id'] === $selected_mentor_id) {
        $selected_mentor_rank = $idx + 1;
        $selected_mentor_score = $r['score'];
        break;
    }
}

// Performance Badge Determination (Deterministic, Explainable, Cohort-Aware)
if ($mentor_stats['calls_count'] === 0 && $mentor_stats['remarks_count'] === 0 && $mentor_stats['active_days_count'] === 0) {
    $badge_title = 'Pending Activity';
    $badge_icon  = 'fa-circle-info';
    $badge_class = 'badge-pending';
} elseif ($total_ranked >= 3) {
    // Relative Cohort Percentile Bracket
    $percentile = ($selected_mentor_rank / $total_ranked) * 100;
    if ($percentile <= 15.0) {
        $badge_title = 'Elite Performer';
        $badge_icon  = 'fa-trophy';
        $badge_class = 'badge-elite';
    } elseif ($percentile <= 40.0) {
        $badge_title = 'High Performer';
        $badge_icon  = 'fa-star';
        $badge_class = 'badge-high';
    } elseif ($percentile <= 75.0) {
        $badge_title = 'Consistent Performer';
        $badge_icon  = 'fa-thumbs-up';
        $badge_class = 'badge-consistent';
    } else {
        $badge_title = 'Developing Performer';
        $badge_icon  = 'fa-arrow-trend-up';
        $badge_class = 'badge-attention';
    }
} else {
    // Score Bracket for small cohorts (< 3 mentors)
    if ($selected_mentor_score >= 80.0) {
        $badge_title = 'Elite Performer';
        $badge_icon  = 'fa-trophy';
        $badge_class = 'badge-elite';
    } elseif ($selected_mentor_score >= 65.0) {
        $badge_title = 'High Performer';
        $badge_icon  = 'fa-star';
        $badge_class = 'badge-high';
    } elseif ($selected_mentor_score >= 45.0) {
        $badge_title = 'Consistent Performer';
        $badge_icon  = 'fa-thumbs-up';
        $badge_class = 'badge-consistent';
    } else {
        $badge_title = 'Developing Performer';
        $badge_icon  = 'fa-arrow-trend-up';
        $badge_class = 'badge-attention';
    }
}

// ── 6. Activity Over Time Graph Data (Dynamic Multi-Scale Aggregation) ──
$daily_trend = [];
$chart_categories = [];
$chart_calls = [];
$chart_remarks = [];
$chart_totals = [];

if ($range_param === 'today') {
    // Hourly breakdown for Daily reporting view
    $hours = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00'];
    $hourly_calls = array_fill_keys($hours, 0);
    $hourly_remarks = array_fill_keys($hours, 0);

    try {
        $stmt_today_c = $pdo->prepare("SELECT call_timestamp FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ?");
        $stmt_today_c->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        while ($crow = $stmt_today_c->fetch(PDO::FETCH_ASSOC)) {
            $h_num = (int)date('H', strtotime($crow['call_timestamp']));
            $slot = sprintf('%02d:00', min(22, max(8, floor($h_num / 2) * 2)));
            $hourly_calls[$slot] = ($hourly_calls[$slot] ?? 0) + 1;
        }
    } catch (Throwable $e) {}

    try {
        $stmt_today_r = $pdo->prepare("SELECT created_at FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ?");
        $stmt_today_r->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        while ($rrow = $stmt_today_r->fetch(PDO::FETCH_ASSOC)) {
            $h_num = (int)date('H', strtotime($rrow['created_at']));
            $slot = sprintf('%02d:00', min(22, max(8, floor($h_num / 2) * 2)));
            $hourly_remarks[$slot] = ($hourly_remarks[$slot] ?? 0) + 1;
        }
    } catch (Throwable $e) {}

    foreach ($hours as $h) {
        $c_val = $hourly_calls[$h] ?? 0;
        $r_val = $hourly_remarks[$h] ?? 0;
        $t_val = $c_val + $r_val;

        $chart_categories[] = $h;
        $chart_calls[] = $c_val;
        $chart_remarks[] = $r_val;
        $chart_totals[] = $t_val;

        $daily_trend[] = [
            'date' => $h,
            'label' => $h,
            'calls' => $c_val,
            'remarks' => $r_val,
            'total' => $t_val
        ];
    }
} elseif ($total_days_in_window > 90 || $range_param === 'all_time') {
    // Monthly aggregation for Overall / Long ranges
    $raw_calls_mo = [];
    $raw_remarks_mo = [];
    try {
        $stmt_mo_c = $pdo->prepare("SELECT SUBSTR(call_timestamp, 1, 7) as ym, COUNT(*) as cnt FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ? GROUP BY ym ORDER BY ym ASC");
        $stmt_mo_c->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        $raw_calls_mo = $stmt_mo_c->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {}

    try {
        $stmt_mo_r = $pdo->prepare("SELECT SUBSTR(created_at, 1, 7) as ym, COUNT(*) as cnt FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ? GROUP BY ym ORDER BY ym ASC");
        $stmt_mo_r->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        $raw_remarks_mo = $stmt_mo_r->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {}

    $all_yms = array_unique(array_merge(array_keys($raw_calls_mo), array_keys($raw_remarks_mo)));
    sort($all_yms);
    if (empty($all_yms)) {
        $all_yms = [date('Y-m')];
    }

    foreach ($all_yms as $ym) {
        $label = date('M Y', strtotime($ym . '-01'));
        $c_val = (int)($raw_calls_mo[$ym] ?? 0);
        $r_val = (int)($raw_remarks_mo[$ym] ?? 0);
        $t_val = $c_val + $r_val;

        $chart_categories[] = $label;
        $chart_calls[] = $c_val;
        $chart_remarks[] = $r_val;
        $chart_totals[] = $t_val;

        $daily_trend[] = [
            'date' => $ym,
            'label' => $label,
            'calls' => $c_val,
            'remarks' => $r_val,
            'total' => $t_val
        ];
    }
} else {
    // Daily aggregation for Weekly / Monthly / Standard ranges
    $raw_calls_by_day = [];
    $raw_remarks_by_day = [];
    try {
        $stmt_daily_c = $pdo->prepare("SELECT DATE(call_timestamp) as d, COUNT(*) as cnt FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN ? AND ? GROUP BY d");
        $stmt_daily_c->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        $raw_calls_by_day = $stmt_daily_c->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {}

    try {
        $stmt_daily_r = $pdo->prepare("SELECT DATE(created_at) as d, COUNT(*) as cnt FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN ? AND ? GROUP BY d");
        $stmt_daily_r->execute([$selected_mentor_id, $start_datetime, $end_datetime]);
        $raw_remarks_by_day = $stmt_daily_r->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {}

    $cur = strtotime($start_date);
    $end_ts = strtotime($end_date);
    $day_step = 86400;

    while ($cur <= $end_ts) {
        $d_key = date('Y-m-d', $cur);
        $d_label = date('d M', $cur);
        $c_val = (int)($raw_calls_by_day[$d_key] ?? 0);
        $r_val = (int)($raw_remarks_by_day[$d_key] ?? 0);
        $t_val = $c_val + $r_val;

        $chart_categories[] = $d_label;
        $chart_calls[] = $c_val;
        $chart_remarks[] = $r_val;
        $chart_totals[] = $t_val;

        $daily_trend[] = [
            'date' => $d_key,
            'label' => $d_label,
            'calls' => $c_val,
            'remarks' => $r_val,
            'total' => $t_val
        ];

        $cur += $day_step;
    }
}

$max_daily_val = max(1, (!empty($chart_totals) ? max($chart_totals) : 1));

// ── 7. Recent Interaction History (Calls + Remarks Union) ─────────────
$recent_interactions = [];
try {
    $stmt_interactions = $pdo->prepare("
        SELECT 'call' as type, cl.call_timestamp as event_time, cl.student_user_id, cl.notes as note, u.name as student_name, u.pepp_course
        FROM mentor_call_logs cl
        LEFT JOIN users u ON cl.student_user_id = u.user_id
        WHERE cl.admin_id = ? AND cl.call_timestamp BETWEEN ? AND ?
        UNION ALL
        SELECT 'remark' as type, rm.created_at as event_time, rm.student_user_id, rm.remark as note, u.name as student_name, u.pepp_course
        FROM mentor_remarks rm
        LEFT JOIN users u ON rm.student_user_id = u.user_id
        WHERE rm.admin_id = ? AND rm.created_at BETWEEN ? AND ?
        ORDER BY event_time DESC
        LIMIT 50
    ");
    $stmt_interactions->execute([$selected_mentor_id, $start_datetime, $end_datetime, $selected_mentor_id, $start_datetime, $end_datetime]);
    $recent_interactions = $stmt_interactions->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recent_interactions = [];
}

// ── 8. Superadmin Login & Location History (Strict Superadmin View) ────
$login_history_rows = [];
try {
    $stmt_login_hist = $pdo->prepare("
        SELECT created_at, action_type, details, ip_address, location, user_agent
        FROM admin_activity_log
        WHERE admin_username = ? AND action_type IN ('login', 'logout', 'page_view')
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt_login_hist->execute([$selected_mentor['username'] ?? '']);
    $login_history_rows = $stmt_login_hist->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $login_history_rows = [];
}

include __DIR__ . '/includes/admin_nav.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* ── Premium ERP Analytics Styles ─────────────────────────────────── */
:root {
    --pepp-orange: #ff6b00;
    --pepp-orange-dark: #e05e00;
    --pepp-orange-light: #fff4ec;
    --card-bg: #ffffff;
    --card-border: #e2e8f0;
    --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -2px rgba(0, 0, 0, 0.03);
    --card-radius: 16px;
    --text-dark: #0f172a;
    --text-muted: #64748b;
    --text-sub: #475569;
    --brand-blue: #3b82f6;
    --brand-green: #10b981;
    --brand-purple: #8b5cf6;
    --brand-amber: #f59e0b;
}

.report-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 10px 0 40px 0;
    font-family: 'DM Sans', system-ui, -apple-system, sans-serif;
    color: var(--text-dark);
}

/* Header & Controls */
.report-top-bar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
}

.report-heading h1 {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--text-dark);
    margin: 0 0 4px 0;
    letter-spacing: -0.5px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.report-heading p {
    margin: 0;
    font-size: 0.9rem;
    color: var(--text-muted);
}

.report-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}

.mentor-select-box {
    position: relative;
    min-width: 260px;
}

.mentor-select-box select {
    width: 100%;
    padding: 10px 14px;
    padding-right: 36px;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-dark);
    background: #fff;
    border: 1.5px solid var(--card-border);
    border-radius: 10px;
    cursor: pointer;
    box-shadow: var(--card-shadow);
    transition: all 0.2s ease;
    appearance: none;
    -webkit-appearance: none;
}

.mentor-select-box select:focus {
    border-color: var(--pepp-orange);
    outline: none;
    box-shadow: 0 0 0 3px rgba(255, 107, 0, 0.15);
}

.mentor-select-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
    font-size: 0.85rem;
}

/* Date Range Pill Group */
.date-pill-group {
    display: flex;
    background: #e2e8f0;
    padding: 4px;
    border-radius: 10px;
    gap: 2px;
    overflow-x: auto;
}

.date-pill {
    padding: 6px 12px;
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-sub);
    text-decoration: none;
    border-radius: 8px;
    white-space: nowrap;
    transition: all 0.15s ease;
}

.date-pill:hover {
    color: var(--text-dark);
    background: rgba(255,255,255,0.6);
}

.date-pill.active {
    background: #fff;
    color: var(--pepp-orange);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

/* Custom Range Drawer */
.custom-range-drawer {
    display: none;
    width: 100%;
    margin-top: 10px;
    background: #f8fafc;
    border: 1px solid var(--card-border);
    padding: 10px 14px;
    border-radius: 10px;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.custom-range-drawer.open {
    display: flex;
}

.date-input {
    padding: 6px 10px;
    font-size: 0.82rem;
    font-weight: 600;
    border: 1px solid var(--card-border);
    border-radius: 6px;
    background: #fff;
    color: var(--text-dark);
}

.btn-apply-range {
    padding: 6px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    background: var(--pepp-orange);
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s ease;
}

.btn-apply-range:hover {
    background: var(--pepp-orange-dark);
}

.btn-export-report {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: var(--pepp-orange);
    color: #fff;
    font-weight: 700;
    font-size: 0.85rem;
    border-radius: 10px;
    text-decoration: none;
    box-shadow: 0 4px 10px rgba(255, 107, 0, 0.25);
    transition: all 0.2s ease;
}

.btn-export-report:hover {
    background: var(--pepp-orange-dark);
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(255, 107, 0, 0.35);
    color: #fff;
}

/* ── Profile Hero Header Card ────────────────────────────────────── */
.mentor-hero-card {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #fff;
    border-radius: var(--card-radius);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.2);
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 24px;
}

.mentor-avatar-badge {
    position: relative;
}

.mentor-avatar {
    width: 76px;
    height: 76px;
    border-radius: 20px;
    background: linear-gradient(135deg, var(--pepp-orange) 0%, #f97316 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    font-weight: 800;
    border: 3px solid rgba(255,255,255,0.2);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.presence-dot {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 3px solid #0f172a;
}

.presence-dot.online {
    background: #22c55e;
    box-shadow: 0 0 10px #22c55e;
}

.presence-dot.offline {
    background: #94a3b8;
}

.mentor-hero-info h2 {
    font-size: 1.35rem;
    font-weight: 800;
    margin: 0 0 4px 0;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 10px;
}

.mentor-meta-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 14px;
    font-size: 0.82rem;
    color: #94a3b8;
}

.meta-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.08);
    padding: 3px 10px;
    border-radius: 6px;
}

.mentor-badge-container {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
}

.badge-pill-elite {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #fff;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

.badge-pill-high {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #fff;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.badge-pill-consistent {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.badge-pill-attention {
    background: linear-gradient(135deg, #64748b 0%, #475569 100%);
    color: #fff;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.badge-pending {
    background: #334155;
    color: #cbd5e1;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.cohort-rank-tag {
    font-size: 0.78rem;
    color: #cbd5e1;
    font-weight: 700;
}

/* ── KPI Grid ─────────────────────────────────────────────────────── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.kpi-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--card-radius);
    padding: 18px;
    box-shadow: var(--card-shadow);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.15s ease, border-color 0.15s ease;
}

.kpi-card:hover {
    transform: translateY(-2px);
    border-color: #cbd5e1;
}

.kpi-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.kpi-title {
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
}

.kpi-icon-wrap {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.kpi-num {
    font-size: 1.7rem;
    font-weight: 800;
    color: var(--text-dark);
    line-height: 1.1;
    margin-bottom: 4px;
}

.kpi-sub {
    font-size: 0.75rem;
    color: var(--text-muted);
    font-weight: 600;
}

/* Colors */
.icon-orange { background: var(--pepp-orange-light); color: var(--pepp-orange); }
.icon-blue { background: #eff6ff; color: var(--brand-blue); }
.icon-green { background: #ecfdf5; color: var(--brand-green); }
.icon-purple { background: #f5f3ff; color: var(--brand-purple); }
.icon-amber { background: #fffbeb; color: var(--brand-amber); }

/* ── Main Layout: Analytics & Charts ─────────────────────────────── */
.dashboard-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

@media (max-width: 1024px) {
    .dashboard-row {
        grid-template-columns: 1fr;
    }
}

.panel-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--card-radius);
    padding: 22px;
    box-shadow: var(--card-shadow);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f1f5f9;
}

.panel-header h3 {
    font-size: 1.05rem;
    font-weight: 800;
    margin: 0;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Trend Chart */
.chart-container {
    height: 240px;
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding-top: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e2e8f0;
    position: relative;
}

.chart-bar-group {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    height: 100%;
    justify-content: flex-end;
    position: relative;
    cursor: pointer;
}

.chart-bar-group:hover .chart-tooltip {
    opacity: 1;
    visibility: visible;
}

.chart-bars {
    width: 100%;
    max-width: 28px;
    display: flex;
    gap: 3px;
    align-items: flex-end;
    height: 100%;
}

.bar-call {
    background: var(--brand-blue);
    width: 50%;
    border-radius: 4px 4px 0 0;
    transition: height 0.3s ease;
}

.bar-remark {
    background: var(--pepp-orange);
    width: 50%;
    border-radius: 4px 4px 0 0;
    transition: height 0.3s ease;
}

.chart-x-label {
    font-size: 0.68rem;
    color: var(--text-muted);
    font-weight: 700;
    margin-top: 8px;
    text-align: center;
    white-space: nowrap;
}

.chart-tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #0f172a;
    color: #fff;
    font-size: 0.72rem;
    padding: 6px 10px;
    border-radius: 6px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.15s ease;
    z-index: 10;
    pointer-events: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.15);
}

.chart-legend {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 14px;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--text-muted);
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    display: inline-block;
    margin-right: 6px;
}

/* Distribution / Progress Bar Representation */
.distribution-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.dist-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.dist-meta {
    display: flex;
    justify-content: space-between;
    font-size: 0.82rem;
    font-weight: 700;
}

.dist-bar-track {
    background: #f1f5f9;
    height: 10px;
    border-radius: 6px;
    overflow: hidden;
}

.dist-bar-fill {
    height: 100%;
    border-radius: 6px;
}

/* Interaction & History Table */
.data-table-wrap {
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}

.report-table th {
    text-align: left;
    padding: 10px 12px;
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    border-bottom: 2px solid #f1f5f9;
    background: #f8fafc;
}

.report-table td {
    padding: 12px;
    border-bottom: 1px solid #f1f5f9;
    color: var(--text-sub);
    vertical-align: middle;
}

.report-table tr:hover td {
    background: #fafafa;
}

.badge-event-call {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    font-weight: 800;
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 6px;
}

.badge-event-remark {
    background: var(--pepp-orange-light);
    color: var(--pepp-orange-dark);
    border: 1px solid #fed7aa;
    font-weight: 800;
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 6px;
}

/* Leaderboard Ranking Table */
.leaderboard-rank {
    font-weight: 800;
    font-size: 0.95rem;
    width: 40px;
}

.rank-gold { color: #f59e0b; }
.rank-silver { color: #94a3b8; }
.rank-bronze { color: #b45309; }

.tr-current-mentor td {
    background: #fffbf7 !important;
    font-weight: 700;
    border-left: 3px solid var(--pepp-orange);
}

/* Insights Alert Box */
.insights-card {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1.5px dashed #cbd5e1;
    border-radius: var(--card-radius);
    padding: 20px;
    margin-top: 24px;
}

.insights-list {
    margin: 10px 0 0 0;
    padding-left: 20px;
    font-size: 0.88rem;
    color: var(--text-sub);
    line-height: 1.7;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .mentor-hero-card {
        grid-template-columns: 1fr;
        text-align: center;
    }
    .mentor-avatar-badge {
        margin: 0 auto;
    }
    .mentor-meta-row {
        justify-content: center;
    }
    .mentor-badge-container {
        align-items: center;
    }
    .report-top-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .mentor-select-box {
        width: 100%;
    }
}
</style>

<div class="report-container">

    <!-- ── 1. Top Header & Controls ─────────────────────────────────── -->
    <div class="report-top-bar">
        <div class="report-heading">
            <h1><i class="fas fa-chart-line" style="color:var(--pepp-orange);"></i> Mentors Performance Report</h1>
            <p>Analyse mentor productivity, student engagement, activity logs, and cohort rankings.</p>
        </div>

        <div class="report-controls">
            <!-- Mentor Selector -->
            <form method="GET" id="mentor-select-form" style="margin:0;">
                <input type="hidden" name="range" value="<?= htmlspecialchars($range_param, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($range_param === 'custom'): ?>
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <div class="mentor-select-box">
                    <select name="mentor_id" onchange="this.form.submit()">
                        <?php if (empty($all_mentors)): ?>
                            <option value="0">No Active Mentors Found</option>
                        <?php else: ?>
                            <?php foreach ($all_mentors as $m): ?>
                                <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $selected_mentor_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['full_name'] ?: $m['username'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($m['username'], ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <i class="fas fa-chevron-down mentor-select-icon"></i>
                </div>
            </form>

            <!-- Date Range Filters (Daily, Weekly, Monthly, Overall, Custom) -->
            <div class="date-pill-group">
                <a href="?mentor_id=<?= $selected_mentor_id ?>&range=today" class="date-pill <?= $range_param === 'today' ? 'active' : '' ?>">Daily (Today)</a>
                <a href="?mentor_id=<?= $selected_mentor_id ?>&range=this_week" class="date-pill <?= $range_param === 'this_week' ? 'active' : '' ?>">Weekly</a>
                <a href="?mentor_id=<?= $selected_mentor_id ?>&range=this_month" class="date-pill <?= $range_param === 'this_month' ? 'active' : '' ?>">Monthly</a>
                <a href="?mentor_id=<?= $selected_mentor_id ?>&range=all_time" class="date-pill <?= $range_param === 'all_time' ? 'active' : '' ?>">Overall</a>
                <button type="button" onclick="document.getElementById('custom-range-drawer').classList.toggle('open')" class="date-pill <?= $range_param === 'custom' ? 'active' : '' ?>" style="background:transparent; border:none; cursor:pointer;">
                    <i class="fas fa-sliders"></i> Custom Range
                </button>
            </div>

            <!-- CSV Export Button -->
            <a href="?export=csv&mentor_id=<?= $selected_mentor_id ?>&range=<?= urlencode($range_param) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>" class="btn-export-report" title="Export report data as CSV">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
        </div>

        <!-- Inline Custom Date Range Drawer -->
        <form method="GET" id="custom-range-drawer" class="custom-range-drawer <?= $range_param === 'custom' ? 'open' : '' ?>">
            <input type="hidden" name="mentor_id" value="<?= $selected_mentor_id ?>">
            <input type="hidden" name="range" value="custom">
            <span style="font-size:0.82rem; font-weight:700; color:var(--text-dark);"><i class="fas fa-calendar-days"></i> Custom Range:</span>
            <label style="font-size:0.8rem; font-weight:600; color:var(--text-sub);">From:</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') ?>" class="date-input" required>
            <label style="font-size:0.8rem; font-weight:600; color:var(--text-sub);">To:</label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') ?>" class="date-input" required>
            <button type="submit" class="btn-apply-range"><i class="fas fa-filter"></i> Apply Interval</button>
        </form>
    </div>

    <?php if (!$selected_mentor): ?>
        <div class="panel-card" style="text-align:center; padding:50px 20px;">
            <i class="fas fa-user-slash" style="font-size:3rem; color:var(--text-muted); margin-bottom:14px;"></i>
            <h3>No Mentors Available</h3>
            <p style="color:var(--text-muted);">Please assign mentors or enable mentoring permissions to view performance reports.</p>
        </div>
    <?php else: ?>

        <!-- ── 2. Mentor Profile Header Card ────────────────────────────── -->
        <div class="mentor-hero-card">
            <?php
            $m_photo_url = resolve_staff_photo_url($selected_mentor['staff_photo'] ?? '');
            ?>
            <div class="mentor-avatar-badge">
                <div class="mentor-avatar" style="<?= $m_photo_url ? 'background-image:url(' . htmlspecialchars($m_photo_url, ENT_QUOTES, 'UTF-8') . '); background-size:cover; background-position:center; color:transparent;' : '' ?>">
                    <?php if (!$m_photo_url): ?>
                        <?= strtoupper(substr($selected_mentor['full_name'] ?: $selected_mentor['username'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div class="presence-dot <?= $mentor_stats['is_online'] ? 'online' : 'offline' ?>" title="<?= $mentor_stats['is_online'] ? 'Online / Active' : 'Offline' ?>"></div>
            </div>

            <div class="mentor-hero-info">
                <h2>
                    <?= htmlspecialchars($selected_mentor['full_name'] ?: $selected_mentor['username'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($mentor_stats['is_online']): ?>
                        <span style="font-size:0.75rem; background:rgba(34, 197, 94, 0.2); color:#4ade80; border:1px solid rgba(74, 222, 128, 0.4); padding:2px 8px; border-radius:6px;">● ONLINE</span>
                    <?php else: ?>
                        <span style="font-size:0.75rem; background:rgba(148, 163, 184, 0.2); color:#cbd5e1; border:1px solid rgba(203, 213, 225, 0.3); padding:2px 8px; border-radius:6px;">○ OFFLINE</span>
                    <?php endif; ?>
                </h2>
                <div class="mentor-meta-row">
                    <div class="meta-pill"><i class="fas fa-user-shield"></i> <?= ucfirst(str_replace('_', ' ', $selected_mentor['admin_type'] ?? $selected_mentor['role'])) ?></div>
                    <div class="meta-pill"><i class="fas fa-clock"></i> Last active: <?= htmlspecialchars($mentor_stats['last_active_label'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="meta-pill"><i class="fas fa-graduation-cap"></i> <?= (int)$mentor_stats['assigned_students_count'] ?> Active Assigned Students</div>
                    <div class="meta-pill"><i class="fas fa-calendar-range"></i> Period: <?= htmlspecialchars($range_title, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="mentor-badge-container">
                <div class="<?= $badge_class === 'badge-elite' ? 'badge-pill-elite' : ($badge_class === 'badge-high' ? 'badge-pill-high' : ($badge_class === 'badge-consistent' ? 'badge-pill-consistent' : ($badge_class === 'badge-pending' ? 'badge-pending' : 'badge-pill-attention'))) ?>">
                    <i class="fas <?= $badge_icon ?>"></i> <?= $badge_title ?>
                </div>
                <div class="cohort-rank-tag">
                    Rank #<?= $selected_mentor_rank ?> of <?= $total_ranked ?> Active Mentors
                </div>
            </div>
        </div>

        <!-- ── 3. KPI Performance Cards Grid ───────────────────────────── -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-head">
                    <span class="kpi-title">Assigned Active Students</span>
                    <div class="kpi-icon-wrap icon-purple"><i class="fas fa-users"></i></div>
                </div>
                <div class="kpi-num"><?= (int)$mentor_stats['assigned_students_count'] ?></div>
                <div class="kpi-sub">Strict active enrolled workload</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-head">
                    <span class="kpi-title">Calls in Period</span>
                    <div class="kpi-icon-wrap icon-blue"><i class="fas fa-phone"></i></div>
                </div>
                <div class="kpi-num"><?= (int)$mentor_stats['calls_count'] ?></div>
                <div class="kpi-sub">Total call interactions logged</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-head">
                    <span class="kpi-title">Remarks in Period</span>
                    <div class="kpi-icon-wrap icon-orange"><i class="fas fa-comment-dots"></i></div>
                </div>
                <div class="kpi-num"><?= (int)$mentor_stats['remarks_count'] ?></div>
                <div class="kpi-sub">Student notes & follow-ups</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-head">
                    <span class="kpi-title">Contact Rate</span>
                    <div class="kpi-icon-wrap icon-green"><i class="fas fa-bullseye"></i></div>
                </div>
                <div class="kpi-num"><?= number_format($mentor_stats['contact_rate'], 1) ?>%</div>
                <div class="kpi-sub"><?= (int)$mentor_stats['unique_contacted_count'] ?> students reached in period</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-head">
                    <span class="kpi-title">Active Days</span>
                    <div class="kpi-icon-wrap icon-amber"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="kpi-num"><?= (int)$mentor_stats['active_days_count'] ?> <span style="font-size:0.9rem; font-weight:600; color:var(--text-muted);">/ <?= $total_days_in_window ?> days</span></div>
                <div class="kpi-sub">Current streak: <?= (int)$mentor_stats['current_streak'] ?> day(s)</div>
            </div>
        </div>

        <!-- ── 4. Main Analytics Row: Activity Over Time & Engagement ──── -->
        <div class="dashboard-row">
            <!-- Activity Trend Chart -->
            <div class="panel-card">
                <div class="panel-header">
                    <h3><i class="fas fa-chart-area" style="color:var(--brand-blue);"></i> Mentor Activity Trend (<?= htmlspecialchars($range_title, ENT_QUOTES, 'UTF-8') ?>)</h3>
                    <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted);">Calls vs Remarks</span>
                </div>

                <?php if (empty($daily_trend) || ($mentor_stats['calls_count'] === 0 && $mentor_stats['remarks_count'] === 0)): ?>
                    <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                        <i class="fas fa-chart-line" style="font-size:2.5rem; margin-bottom:10px; opacity:0.3;"></i>
                        <p style="margin:0; font-size:0.9rem;">No call or remark activity recorded for the selected period.</p>
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <?php foreach ($daily_trend as $dt):
                            $c_pct = round(($dt['calls'] / $max_daily_val) * 100);
                            $r_pct = round(($dt['remarks'] / $max_daily_val) * 100);
                        ?>
                            <div class="chart-bar-group">
                                <div class="chart-tooltip">
                                    <strong><?= $dt['label'] ?></strong><br>
                                    Calls: <?= $dt['calls'] ?><br>
                                    Remarks: <?= $dt['remarks'] ?><br>
                                    Total: <?= $dt['total'] ?>
                                </div>
                                <div class="chart-bars">
                                    <div class="bar-call" style="height: <?= max(4, $c_pct) ?>%;"></div>
                                    <div class="bar-remark" style="height: <?= max(4, $r_pct) ?>%;"></div>
                                </div>
                                <div class="chart-x-label"><?= $dt['label'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="chart-legend">
                        <span><span class="legend-dot" style="background:var(--brand-blue);"></span> Calls Made</span>
                        <span><span class="legend-dot" style="background:var(--pepp-orange);"></span> Remarks Added</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Student Engagement Summary -->
            <div class="panel-card">
                <div class="panel-header">
                    <h3><i class="fas fa-pie-chart" style="color:var(--brand-green);"></i> Student Engagement</h3>
                    <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted);"><?= (int)$mentor_stats['assigned_students_count'] ?> Active</span>
                </div>

                <div class="distribution-list">
                    <div class="dist-item">
                        <div class="dist-meta">
                            <span>Contacted Active Students</span>
                            <span style="color:var(--brand-green);"><?= number_format($mentor_stats['contact_rate'], 1) ?>% (<?= (int)$mentor_stats['unique_contacted_count'] ?>)</span>
                        </div>
                        <div class="dist-bar-track">
                            <div class="dist-bar-fill" style="width: <?= min(100, $mentor_stats['contact_rate']) ?>%; background:var(--brand-green);"></div>
                        </div>
                    </div>

                    <div class="dist-item">
                        <div class="dist-meta">
                            <span>Uncontacted Active Students</span>
                            <span style="color:#ef4444;"><?= (int)$mentor_stats['uncontacted_count'] ?></span>
                        </div>
                        <div class="dist-bar-track">
                            <div class="dist-bar-fill" style="width: <?= $mentor_stats['assigned_students_count'] > 0 ? min(100, round(($mentor_stats['uncontacted_count'] / $mentor_stats['assigned_students_count']) * 100)) : 0 ?>%; background:#ef4444;"></div>
                        </div>
                    </div>

                    <div class="dist-item">
                        <div class="dist-meta">
                            <span>Avg. Study Plan Progress</span>
                            <span style="color:var(--brand-blue);"><?= number_format($mentor_stats['avg_student_progress'], 1) ?>%</span>
                        </div>
                        <div class="dist-bar-track">
                            <div class="dist-bar-fill" style="width: <?= min(100, $mentor_stats['avg_student_progress']) ?>%; background:var(--brand-blue);"></div>
                        </div>
                    </div>

                    <div class="dist-item">
                        <div class="dist-meta">
                            <span>Avg. Attendance Rate</span>
                            <span style="color:var(--brand-purple);"><?= number_format($mentor_stats['avg_student_attendance'], 1) ?>%</span>
                        </div>
                        <div class="dist-bar-track">
                            <div class="dist-bar-fill" style="width: <?= min(100, $mentor_stats['avg_student_attendance']) ?>%; background:var(--brand-purple);"></div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px; padding:12px; background:#f8fafc; border-radius:10px; font-size:0.8rem; color:var(--text-sub); display:flex; justify-content:space-between;">
                    <span>Assigned Active: <strong><?= $mentor_stats['assigned_students_count'] ?></strong></span>
                    <span>Contacted: <strong><?= $mentor_stats['unique_contacted_count'] ?></strong></span>
                </div>
            </div>
        </div>

        <!-- ── 5. Active Mentors Leaderboard Ranking Table ─────────────── -->
        <div class="panel-card" style="margin-bottom:24px;">
            <div class="panel-header">
                <h3><i class="fas fa-ranking-star" style="color:var(--brand-amber);"></i> Active Mentors Performance Ranking</h3>
                <span style="font-size:0.8rem; font-weight:700; color:var(--text-muted);">Cohort of <?= $total_ranked ?> Active Mentors</span>
            </div>

            <div class="data-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">Rank</th>
                            <th>Mentor</th>
                            <th>Assigned Students</th>
                            <th>Calls Made</th>
                            <th>Remarks Added</th>
                            <th>Contact Rate</th>
                            <th>Active Days</th>
                            <th style="text-align:right;">Performance Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cohort_rankings as $idx => $rnk):
                            $r_num = $idx + 1;
                            $is_current = ($rnk['id'] === $selected_mentor_id);
                        ?>
                            <tr class="<?= $is_current ? 'tr-current-mentor' : '' ?>">
                                <td class="leaderboard-rank">
                                    <?php if ($r_num === 1): ?>
                                        <i class="fas fa-crown rank-gold" title="Rank 1"></i> 1
                                    <?php elseif ($r_num === 2): ?>
                                        <i class="fas fa-medal rank-silver" title="Rank 2"></i> 2
                                    <?php elseif ($r_num === 3): ?>
                                        <i class="fas fa-medal rank-bronze" title="Rank 3"></i> 3
                                    <?php else: ?>
                                        <?= $r_num ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $r_photo_url = resolve_staff_photo_url($rnk['staff_photo'] ?? '');
                                    ?>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:34px; height:34px; border-radius:50%; background:<?= $r_photo_url ? 'url(' . htmlspecialchars($r_photo_url, ENT_QUOTES, 'UTF-8') . ') center/cover no-repeat' : 'linear-gradient(135deg, var(--pepp-orange), var(--pepp-orange-dark))' ?>; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; flex-shrink:0; border:1.5px solid #fff; box-shadow:0 2px 5px rgba(0,0,0,0.1);">
                                            <?= $r_photo_url ? '' : strtoupper(substr($rnk['full_name'] ?: $rnk['username'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <a href="?mentor_id=<?= (int)$rnk['id'] ?>&range=<?= urlencode($range_param) ?>" style="color:var(--text-dark); text-decoration:none; font-weight:700;">
                                                <?= htmlspecialchars($rnk['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                            <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($rnk['username'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= (int)$rnk['assigned_students'] ?></td>
                                <td><?= (int)$rnk['calls'] ?></td>
                                <td><?= (int)$rnk['remarks'] ?></td>
                                <td>
                                    <span style="font-weight:700; color:<?= $rnk['contact_rate'] >= 75 ? 'var(--brand-green)' : ($rnk['contact_rate'] >= 50 ? 'var(--brand-blue)' : '#ef4444') ?>;">
                                        <?= number_format($rnk['contact_rate'], 1) ?>%
                                    </span>
                                </td>
                                <td><?= (int)$rnk['active_days'] ?> / <?= $total_days_in_window ?></td>
                                <td style="text-align:right; font-weight:800; font-size:0.95rem; color:var(--pepp-orange);">
                                    <?= number_format($rnk['score'], 1) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:14px; padding:10px 14px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; font-size:0.75rem; color:var(--text-sub); line-height:1.5;">
                <i class="fas fa-circle-info" style="color:var(--brand-blue);"></i> <strong>Fair Ranking Methodology:</strong> Scores are normalized on a per-assigned-active-student basis to ensure equal comparison across mentors with different batch sizes. Scoring components: <strong>Contact Rate (35%)</strong>, <strong>Normalized Calls (20%)</strong>, <strong>Normalized Remarks (15%)</strong>, <strong>Avg Study Plan Progress (20%)</strong>, and <strong>Active Days Consistency (10%)</strong>. Configurable benchmark targets: 2.0 calls & 1.5 remarks per student per month.
            </div>
        </div>

        <!-- ── 6. Recent Interaction History Table ─────────────────────── -->
        <div class="panel-card" style="margin-bottom:24px;">
            <div class="panel-header">
                <h3><i class="fas fa-clock-rotate-left" style="color:var(--brand-purple);"></i> Recent Interaction History (Calls & Remarks)</h3>
                <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted);">Showing last <?= count($recent_interactions) ?> interactions</span>
            </div>

            <?php if (empty($recent_interactions)): ?>
                <div style="text-align:center; padding:40px 20px; color:var(--text-muted);">
                    <i class="fas fa-comments" style="font-size:2rem; margin-bottom:8px; opacity:0.3;"></i>
                    <p style="margin:0;">No calls or remarks recorded in this period.</p>
                </div>
            <?php else: ?>
                <div class="data-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th style="width:90px;">Type</th>
                                <th style="width:140px;">Timestamp</th>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Notes / Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_interactions as $ri): ?>
                                <tr>
                                    <td>
                                        <?php if ($ri['type'] === 'call'): ?>
                                            <span class="badge-event-call"><i class="fas fa-phone"></i> CALL</span>
                                        <?php else: ?>
                                            <span class="badge-event-remark"><i class="fas fa-comment"></i> REMARK</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap; font-size:0.78rem; color:var(--text-muted);">
                                        <?= date('d M Y, h:i A', strtotime($ri['event_time'])) ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($ri['student_name'] ?: $ri['student_user_id'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div style="font-size:0.72rem; color:var(--text-muted);"><?= htmlspecialchars($ri['student_user_id'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($ri['pepp_course'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="font-size:0.82rem; color:var(--text-dark);">
                                        <?= nl2br(htmlspecialchars($ri['note'] ?? '—', ENT_QUOTES, 'UTF-8')) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── 7. Superadmin Login & Location Audit (Privileged View) ──── -->
        <div class="panel-card">
            <div class="panel-header">
                <h3><i class="fas fa-shield-halved" style="color:var(--text-dark);"></i> Login & Activity Security History (Superadmin Only)</h3>
                <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted);">Server-side audit logs</span>
            </div>

            <?php if (empty($login_history_rows)): ?>
                <div style="text-align:center; padding:30px 20px; color:var(--text-muted);">
                    <p style="margin:0;">No recent system login logs found for this account.</p>
                </div>
            <?php else: ?>
                <div class="data-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Event Type</th>
                                <th>Details</th>
                                <th>Device / Browser</th>
                                <th>Approx. Location</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($login_history_rows as $lh): ?>
                                <tr>
                                    <td style="white-space:nowrap; font-size:0.78rem; color:var(--text-muted);">
                                        <?= date('d M Y, h:i A', strtotime($lh['created_at'])) ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:#f1f5f9; color:var(--text-dark); font-weight:700; font-size:0.72rem; padding:2px 8px; border-radius:4px;">
                                            <?= htmlspecialchars(strtoupper($lh['action_type']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($lh['details'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="font-size:0.78rem; color:var(--text-sub);">
                                        <?= htmlspecialchars(parse_user_agent_summary($lh['user_agent'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars($lh['location'] ?: 'Location unavailable', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="font-family:monospace; font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($lh['ip_address'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── 8. Data-Backed Insights Summary ─────────────────────────── -->
        <div class="insights-card">
            <h4 style="margin:0; font-size:0.95rem; font-weight:800; color:var(--text-dark); display:flex; align-items:center; gap:8px;">
                <i class="fas fa-lightbulb" style="color:var(--brand-amber);"></i> Data-Backed Observations & Summary
            </h4>
            <ul class="insights-list">
                <li>
                    <strong>Student Reach:</strong> This mentor has reached <strong><?= number_format($mentor_stats['contact_rate'], 1) ?>%</strong> of their <?= (int)$mentor_stats['assigned_students_count'] ?> currently assigned active students in the selected period.
                </li>
                <li>
                    <strong>Work Activity:</strong> Recorded <strong><?= (int)$mentor_stats['calls_count'] ?> calls</strong> and <strong><?= (int)$mentor_stats['remarks_count'] ?> remarks</strong> across <strong><?= (int)$mentor_stats['active_days_count'] ?> active working days</strong>.
                </li>
                <li>
                    <strong>Cohort Standing:</strong> Currently ranks <strong>#<?= $selected_mentor_rank ?> of <?= $total_ranked ?> active mentors</strong> with a composite performance score of <strong><?= number_format($selected_mentor_score, 1) ?></strong> (<?= $badge_title ?>).
                </li>
                <?php if ($mentor_stats['uncontacted_count'] > 0): ?>
                    <li style="color:#b91c1c;">
                        <strong>Attention Needed:</strong> <strong><?= (int)$mentor_stats['uncontacted_count'] ?> assigned active student(s)</strong> have not received any recorded call or remark within this period.
                    </li>
                <?php else: ?>
                    <li style="color:#15803d;">
                        <strong>Optimal Coverage:</strong> 100% of currently assigned active students have received recorded contact in this period.
                    </li>
                <?php endif; ?>
            </ul>
        </div>

    <?php endif; ?>

</div>

<?php
include __DIR__ . '/includes/admin_footer.php';
?>
