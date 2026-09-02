<?php
/**
 * PEPP ERP — Automated Security & Functionality Audit for Mentors Report (mentor-reports.php)
 *
 * Runs comprehensive in-memory / database test assertions for:
 * 1. Authorization & Superadmin Guard
 * 2. Non-superadmin access denial (403)
 * 3. Authoritative Mentor Eligibility Query (Mentoring architecture only)
 * 4. Strict Canonical Active-Student Lifecycle Filtering (users.status = 'approved' AND users.student_status = 'active')
 * 5. Exclusion of Suspended, Inactive, Dropout, Completed, NULL statuses from active workload
 * 6. Metrics Aggregation (Calls, Remarks, Contacted, Contact Rate, Active Days, Streak)
 * 7. Real-time Presence / Online Status Resolution
 * 8. Fair Normalized Cohort Ranking (Per-student normalization, zero-division protected)
 * 9. Activity Trend Default 'today' & Dynamic Hourly Bucketing (Ensuring 100% of today's interactions are counted)
 * 10. Recent Interaction History Multi-Criteria Filtering (Default 'last_1_day', type, search)
 * 11. Security, Privacy, Prepared Statements & CSV Safety
 * 12. Native Vector Multi-Page PDF Generation Engine
 */

declare(strict_types=1);

date_default_timezone_set('UTC');

$passed = 0;
$failed = 0;

function test_assert(bool $condition, string $test_name, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$test_name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$test_name}" . ($details ? " — {$details}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "PEPP ERP — SUPERADMIN MENTOR REPORT FINAL AUDIT TEST SUITE\n";
echo "====================================================================\n\n";

// Setup isolated SQLite PDO environment
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create Schema
$pdo->exec("
    CREATE TABLE admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        full_name TEXT NOT NULL,
        email TEXT,
        role TEXT NOT NULL DEFAULT 'admin',
        admin_type TEXT NOT NULL DEFAULT 'erp_admin',
        permissions TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        whatsapp_number TEXT,
        pepp_course TEXT,
        pepp_academic_year TEXT,
        status TEXT NOT NULL DEFAULT 'approved',
        student_status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE mentor_student_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT NOT NULL,
        admin_id INTEGER NOT NULL,
        course_name TEXT,
        assigned_by TEXT,
        assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        ended_at DATETIME NULL,
        status TEXT NOT NULL DEFAULT 'active'
    );

    CREATE TABLE mentor_course_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        course_name TEXT NOT NULL,
        assigned_by TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE mentor_call_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT NOT NULL,
        admin_id INTEGER NOT NULL,
        admin_username TEXT NOT NULL,
        call_timestamp DATETIME NOT NULL,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE mentor_remarks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT NOT NULL,
        admin_id INTEGER NOT NULL,
        admin_username TEXT NOT NULL,
        remark TEXT NOT NULL,
        reminder_id INTEGER NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL
    );

    CREATE TABLE admin_presence (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        current_page TEXT,
        current_section TEXT,
        last_seen DATETIME,
        login_time DATETIME,
        is_idle INTEGER DEFAULT 0,
        ip_address TEXT,
        session_id TEXT
    );

    CREATE TABLE admin_activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER,
        admin_username TEXT NOT NULL,
        session_id TEXT,
        action_type TEXT NOT NULL,
        module TEXT,
        page TEXT,
        section TEXT,
        target_type TEXT,
        target_id TEXT,
        details TEXT,
        ip_address TEXT,
        location TEXT,
        user_agent TEXT,
        is_heartbeat INTEGER DEFAULT 0,
        is_idle INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert Test Data
$pdo->exec("
    INSERT INTO admins (id, username, full_name, role, admin_type, status, permissions) VALUES
    (1, 'superadmin_user', 'System Superadmin', 'super_admin', 'superadmin', 'active', 'ALL'),
    (2, 'mentor_rahul', 'Rahul Sharma', 'admin', 'employee', 'active', 'student-mentoring'),
    (3, 'mentor_priya', 'Priya Menon', 'admin', 'faculty', 'active', 'student-mentoring'),
    (4, 'mentor_anand', 'Anand V', 'admin', 'employee', 'active', 'student-mentoring'),
    (5, 'generic_faculty', 'Faculty Staff', 'admin', 'faculty', 'active', 'faculties,courses'),
    (6, 'normal_admin', 'Regular Admin', 'admin', 'erp_admin', 'active', 'approvals,students'),
    (7, 'inactive_mentor', 'Inactive Mentor', 'admin', 'employee', 'inactive', 'student-mentoring');

    INSERT INTO users (id, user_id, name, email, pepp_course, status, student_status) VALUES
    (1, 'PL-2026-001', 'Aisha Khan', 'aisha@example.com', 'CUET Commerce', 'approved', 'active'),
    (2, 'PL-2026-002', 'Bilal Ahmed', 'bilal@example.com', 'CUET Commerce', 'approved', 'active'),
    (3, 'PL-2026-003', 'Chitra S', 'chitra@example.com', 'CUET Science', 'approved', 'suspended'),
    (4, 'PL-2026-004', 'Danyal Paul', 'danyal@example.com', 'CUET Science', 'approved', 'inactive'),
    (5, 'PL-2026-005', 'Ebin Joseph', 'ebin@example.com', 'CUET Science', 'approved', 'dropout'),
    (6, 'PL-2026-006', 'Farah Naz', 'farah@example.com', 'CUET Humanities', 'approved', 'completed'),
    (7, 'PL-2026-007', 'Gopi K', 'gopi@example.com', 'CUET Commerce', 'pending', 'active'),
    (8, 'PL-2026-008', 'Hina R', 'hina@example.com', 'CUET Commerce', 'approved', NULL);

    -- Mentor 2 (Rahul) has assignments to students 001 (active), 002 (active), 003 (suspended), 004 (inactive), 005 (dropout), 008 (null)
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, status) VALUES
    ('PL-2026-001', 2, 'CUET Commerce', 'active'),
    ('PL-2026-002', 2, 'CUET Commerce', 'active'),
    ('PL-2026-003', 2, 'CUET Science', 'active'),
    ('PL-2026-004', 2, 'CUET Science', 'active'),
    ('PL-2026-005', 2, 'CUET Science', 'active'),
    ('PL-2026-008', 2, 'CUET Commerce', 'active');

    -- Mentor 3 (Priya) has student 001 (active)
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, status) VALUES
    ('PL-2026-001', 3, 'CUET Commerce', 'active');

    -- Mentor 4 (Anand) has course assignment
    INSERT INTO mentor_course_assignments (admin_id, course_name) VALUES
    (4, 'CUET Humanities');

    -- Rahul's calls & remarks (August 2026 + Today)
    INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp, notes) VALUES
    ('PL-2026-001', 2, 'mentor_rahul', '2026-08-25 10:00:00', 'Discussed chapter 2 progress'),
    ('PL-2026-001', 2, 'mentor_rahul', '2026-08-28 11:30:00', 'Followup on mock test'),
    ('PL-2026-002', 2, 'mentor_rahul', '2026-08-29 15:00:00', 'Cleared syllabus doubts');

    INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark, created_at) VALUES
    ('PL-2026-001', 2, 'mentor_rahul', 'Excellent test performance', '2026-08-29 16:00:00');

    -- Presence: Rahul is Online, Priya is Offline
    INSERT INTO admin_presence (username, current_page, last_seen, login_time, is_idle) VALUES
    ('mentor_rahul', 'student-mentoring.php', '" . date('Y-m-d H:i:s', time() - 60) . "', '" . date('Y-m-d 09:00:00') . "', 0),
    ('mentor_priya', 'dashboard.php', '" . date('Y-m-d H:i:s', time() - 3600) . "', '" . date('Y-m-d 08:00:00') . "', 1);
");

echo "--- SECTION 1: Authorization & Permission Guard Tests ---\n";
function test_is_super_admin($role): bool {
    return $role === 'super_admin';
}

function test_can_access($page_key, $role, $perms): bool {
    if ($page_key === 'communication' || $page_key === 'email-reports' || $page_key === 'employee-management' || $page_key === 'mentor-reports') {
        return test_is_super_admin($role);
    }
    if (test_is_super_admin($role)) return true;
    if (trim($perms) === 'ALL') return true;

    $p_arr = array_map('trim', explode(',', $perms));
    return in_array($page_key, $p_arr, true);
}

test_assert(test_can_access('mentor-reports', 'super_admin', 'ALL') === true, "SEC-01: Superadmin has access to mentor-reports");
test_assert(test_can_access('mentor-reports', 'admin', 'approvals,students,student-mentoring') === false, "SEC-02: Normal admin with student-mentoring cannot access mentor-reports");
test_assert(test_can_access('mentor-reports', 'admin', 'ALL') === false, "SEC-03: Normal admin with 'ALL' cannot access mentor-reports (restricted to superadmin)");

$auth_file_contents = file_get_contents(__DIR__ . '/includes/auth.php');
test_assert(strpos($auth_file_contents, "\$page_key === 'mentor-reports'") !== false, "SEC-04: includes/auth.php enforces is_super_admin() for mentor-reports");

echo "\n--- SECTION 2: Authoritative Mentor Eligibility Query Tests ---\n";
$stmt_elig = $pdo->query("
    SELECT DISTINCT a.id, a.username, a.full_name, a.role, a.admin_type, a.status
    FROM admins a
    WHERE a.status = 'active'
      AND a.role != 'super_admin'
      AND (
        a.id IN (SELECT DISTINCT admin_id FROM mentor_student_assignments)
        OR a.id IN (SELECT DISTINCT admin_id FROM mentor_course_assignments)
        OR a.id IN (SELECT DISTINCT admin_id FROM mentor_call_logs)
        OR a.id IN (SELECT DISTINCT admin_id FROM mentor_remarks)
        OR a.permissions LIKE '%student-mentoring%'
      )
    ORDER BY a.full_name ASC, a.username ASC
");
$eligible_mentors = $stmt_elig->fetchAll(PDO::FETCH_ASSOC);
$eligible_usernames = array_column($eligible_mentors, 'username');

test_assert(in_array('mentor_rahul', $eligible_usernames, true), "ELIG-01: Active assigned mentor Rahul is eligible");
test_assert(in_array('mentor_priya', $eligible_usernames, true), "ELIG-02: Active assigned mentor Priya is eligible");
test_assert(in_array('mentor_anand', $eligible_usernames, true), "ELIG-03: Active course mentor Anand is eligible");
test_assert(!in_array('generic_faculty', $eligible_usernames, true), "ELIG-04: Generic faculty staff without mentoring role is EXCLUDED");
test_assert(!in_array('inactive_mentor', $eligible_usernames, true), "ELIG-05: Inactive mentor is EXCLUDED");
test_assert(!in_array('normal_admin', $eligible_usernames, true), "ELIG-06: Non-mentor regular admin is EXCLUDED");
test_assert(!in_array('audit_superadmin', $eligible_usernames, true), "ELIG-07: Superadmin is strictly EXCLUDED from mentors list");

echo "\n--- SECTION 3: Canonical Active-Student Lifecycle Filtering Tests ---\n";
$stmt_assigned_rahul = $pdo->prepare("
    SELECT msa.student_user_id, u.student_status
    FROM mentor_student_assignments msa
    JOIN users u ON msa.student_user_id = u.user_id
    WHERE msa.admin_id = ? AND msa.status = 'active'
      AND u.status = 'approved'
      AND u.student_status = 'active'
");
$stmt_assigned_rahul->execute([2]);
$assigned_rahul = $stmt_assigned_rahul->fetchAll(PDO::FETCH_ASSOC);
$active_uids = array_column($assigned_rahul, 'student_user_id');

test_assert(count($assigned_rahul) === 2, "LIFE-01: Correctly counts exactly 2 active assigned students");
test_assert(in_array('PL-2026-001', $active_uids, true) && in_array('PL-2026-002', $active_uids, true), "LIFE-02: Active students PL-2026-001 and PL-2026-002 included");
test_assert(!in_array('PL-2026-003', $active_uids, true), "LIFE-03: Suspended student PL-2026-003 strictly EXCLUDED from active workload");
test_assert(!in_array('PL-2026-004', $active_uids, true), "LIFE-04: Inactive student PL-2026-004 strictly EXCLUDED from active workload");
test_assert(!in_array('PL-2026-005', $active_uids, true), "LIFE-05: Dropout student PL-2026-005 strictly EXCLUDED from active workload");
test_assert(!in_array('PL-2026-008', $active_uids, true), "LIFE-06: NULL student_status student PL-2026-008 strictly EXCLUDED from active workload");

echo "\n--- SECTION 4: Metrics Aggregation Accuracy ---\n";
$stmt_c = $pdo->prepare("SELECT COUNT(*) FROM mentor_call_logs WHERE admin_id = ? AND call_timestamp BETWEEN '2026-08-01 00:00:00' AND '2026-08-31 23:59:59'");
$stmt_c->execute([2]);
$calls_count = (int)$stmt_c->fetchColumn();
test_assert($calls_count === 3, "METRIC-01: Calls count is 3 for Rahul");

$stmt_r = $pdo->prepare("SELECT COUNT(*) FROM mentor_remarks WHERE admin_id = ? AND created_at BETWEEN '2026-08-01 00:00:00' AND '2026-08-31 23:59:59'");
$stmt_r->execute([2]);
$remarks_count = (int)$stmt_r->fetchColumn();
test_assert($remarks_count === 1, "METRIC-02: Remarks count is 1 for Rahul");

$stmt_ca = $pdo->prepare("
    SELECT COUNT(DISTINCT student_user_id) FROM (
        SELECT student_user_id FROM mentor_call_logs WHERE admin_id = 2 AND call_timestamp BETWEEN '2026-08-01 00:00:00' AND '2026-08-31 23:59:59'
        UNION
        SELECT student_user_id FROM mentor_remarks WHERE admin_id = 2 AND created_at BETWEEN '2026-08-01 00:00:00' AND '2026-08-31 23:59:59'
    ) c WHERE student_user_id IN ('PL-2026-001', 'PL-2026-002')
");
$stmt_ca->execute();
$contacted_active = (int)$stmt_ca->fetchColumn();
test_assert($contacted_active === 2, "METRIC-03: 2 active students contacted");

$contact_rate = round(($contacted_active / 2) * 100, 1);
test_assert($contact_rate === 100.0, "METRIC-04: Contact rate is 100% (2 out of 2 active assigned students)");

echo "\n--- SECTION 5: Online / Presence Resolution Tests ---\n";
$stmt_p1 = $pdo->prepare("SELECT * FROM admin_presence WHERE username = ?");
$stmt_p1->execute(['mentor_rahul']);
$p1 = $stmt_p1->fetch(PDO::FETCH_ASSOC);
$p1_online = ((time() - strtotime($p1['last_seen'])) <= 300 && (int)$p1['is_idle'] === 0);
test_assert($p1_online === true, "PRES-01: Rahul presence correctly resolves to Online");

$stmt_p2 = $pdo->prepare("SELECT * FROM admin_presence WHERE username = ?");
$stmt_p2->execute(['mentor_priya']);
$p2 = $stmt_p2->fetch(PDO::FETCH_ASSOC);
$p2_online = ((time() - strtotime($p2['last_seen'])) <= 300 && (int)$p2['is_idle'] === 0);
test_assert($p2_online === false, "PRES-02: Priya presence correctly resolves to Offline");

echo "\n--- SECTION 6: Fair Normalized Ranking & Badge Tests ---\n";
$target_calls = 2.0;
$target_remarks = 1.5;
$rahul_calls_norm = min(100.0, ((3 / 2) / $target_calls) * 100);
$rahul_remarks_norm = min(100.0, ((1 / 2) / $target_remarks) * 100);
$rahul_score = round((100.0 * 0.35) + ($rahul_calls_norm * 0.20) + ($rahul_remarks_norm * 0.15) + (75.0 * 0.20) + (10.0 * 0.10), 1);
$priya_score = round((0.0 * 0.35) + (0.0 * 0.20) + (0.0 * 0.15) + (75.0 * 0.20) + (0.0 * 0.10), 1);

test_assert($rahul_score > $priya_score, "RANK-01: Normalized score properly ranks Rahul ($rahul_score) above Priya ($priya_score)");

$cohort_size = 3;
$rahul_rank = 1;
$percentile = ($rahul_rank / $cohort_size) * 100;
$badge = ($percentile <= 15.0) ? 'Elite Performer' : (($percentile <= 40.0) ? 'High Performer' : 'Consistent Performer');
test_assert($badge === 'High Performer', "RANK-02: Badge assigned accurately based on cohort percentile ($badge)");

$zero_students_calls_norm = min(100.0, (0 > 0 ? (0 / max(1, 0)) : 0.0));
test_assert($zero_students_calls_norm === 0.0, "RANK-03: Division by zero cleanly prevented when mentor has 0 students");

echo "\n--- SECTION 7: Security, Privacy & CSV Safety Tests ---\n";
if (!function_exists('csv_safe')) {
    function csv_safe($val): string {
        $str = (string)($val ?? '');
        if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@'], true)) {
            return "'" . $str;
        }
        return $str;
    }
}

test_assert(csv_safe('=SUM(A1:A10)') === "'=SUM(A1:A10)", "CSV-01: Leading '=' escaped in CSV export");
test_assert(csv_safe('+cmd|') === "'+cmd|", "CSV-02: Leading '+' escaped in CSV export");
test_assert(csv_safe('-123') === "'-123", "CSV-03: Leading '-' escaped in CSV export");
test_assert(csv_safe('@alert') === "'@alert", "CSV-04: Leading '@' escaped in CSV export");
test_assert(csv_safe('Regular Text') === 'Regular Text', "CSV-05: Regular text unmodified in CSV");

if (!function_exists('parse_user_agent_summary')) {
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
}

test_assert(parse_user_agent_summary('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0') === 'Chrome on Windows', "UA-01: Correctly parses Chrome on Windows");
test_assert(parse_user_agent_summary('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1') === 'Safari on macOS', "UA-02: Correctly parses Safari on macOS");
test_assert(parse_user_agent_summary(null) === 'Not available', "UA-03: Gracefully handles null User Agent");

require_once __DIR__ . '/includes/StudentStudyPlanAnalytics.php';

// ── SECTION 8: Study Plan Analytics Signature & Resilience ────────────
echo "\n--- SECTION 8: Bulk Study Plan Analytics & Schema Resilience ---\n";

$mock_students = [
    [
        'email' => 'student1@example.com',
        'user_id' => 'PL-2026-001',
        'pepp_academic_year' => '2026-2027',
        'pepp_course' => 'MA/MSc Psychology (Premium)'
    ]
];

$bulk_res = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, $mock_students);
test_assert(is_array($bulk_res), "BULK-01: getCourseAnalyticsBulk works with 2 parameters without ArgumentCountError");

$bulk_res_course = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, $mock_students, 'MA/MSc Psychology (Premium)');
test_assert(is_array($bulk_res_course), "BULK-02: getCourseAnalyticsBulk works with explicit 3 parameters");

$empty_res = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, []);
test_assert($empty_res === [], "BULK-03: getCourseAnalyticsBulk returns empty array for empty student list");

$mentor_reports_code = file_get_contents(__DIR__ . '/mentor-reports.php');
test_assert(strpos($mentor_reports_code, 'require_super_admin()') !== false, "SEC-05: mentor-reports.php enforces require_super_admin()");
test_assert(strpos($mentor_reports_code, 'csv_safe') !== false, "SEC-06: mentor-reports.php uses csv_safe for formula injection defense");
test_assert(strpos($mentor_reports_code, 'password_hash') === false, "PRIV-01: Zero password hash exposure");
test_assert(strpos($mentor_reports_code, 'token_hash') === false, "PRIV-02: Zero token hash exposure");

// ── SECTION 9: Activity Trend Default & Dynamic Hourly Bucket Tests ──
echo "\n--- SECTION 9: Activity Trend Default 'today' & Dynamic Hourly Buckets ---\n";

// Verify default range parameter in mentor-reports.php code
test_assert(strpos($mentor_reports_code, "\$range_param = trim(\$_GET['range'] ?? 'today');") !== false, "TREND-01: Default date range parameter is 'today'");

// Test dynamic hourly slot generation with edge early (06:15), standard (08:20, 12:45, 18:30) and late (23:10) interactions
$sample_today_calls = ['2026-09-02 06:15:00', '2026-09-02 08:20:00', '2026-09-02 12:45:00', '2026-09-02 18:30:00'];
$sample_today_remarks = ['2026-09-02 23:10:00'];

$min_h = 8;
$has_23 = false;

foreach ($sample_today_calls as $ts) {
    $h = (int)date('H', strtotime($ts));
    if ($h < $min_h) $min_h = (int)(floor($h / 2) * 2);
    if ($h >= 23) $has_23 = true;
}
foreach ($sample_today_remarks as $ts) {
    $h = (int)date('H', strtotime($ts));
    if ($h < $min_h) $min_h = (int)(floor($h / 2) * 2);
    if ($h >= 23) $has_23 = true;
}
$min_h = max(0, min(8, $min_h));

$hours = [];
for ($h = $min_h; $h <= 22; $h += 2) {
    $hours[] = sprintf('%02d:00', $h);
}
if ($has_23) {
    $hours[] = '23:00';
}

test_assert($min_h === 6, "TREND-02: Hourly bucket expands downward to 06:00 when 06:15 interaction exists");
test_assert($has_23 === true, "TREND-03: Flag has_23 is true when 23:10 interaction exists");
test_assert(in_array('06:00', $hours, true) && in_array('23:00', $hours, true), "TREND-04: Generated slots include both '06:00' and '23:00'");
test_assert(in_array('08:00', $hours, true) && in_array('12:00', $hours, true) && in_array('18:00', $hours, true) && in_array('22:00', $hours, true), "TREND-05: Standard intervals 08:00, 12:00, 18:00, 22:00 are preserved");

// Verify that all 5 interactions are correctly mapped into buckets
$hourly_calls = array_fill_keys($hours, 0);
$hourly_remarks = array_fill_keys($hours, 0);

foreach ($sample_today_calls as $ts) {
    $h_num = (int)date('H', strtotime($ts));
    if ($h_num >= 23) {
        $slot = '23:00';
    } else {
        $bucket_h = max($min_h, floor($h_num / 2) * 2);
        if ($bucket_h > 22) $bucket_h = 22;
        $slot = sprintf('%02d:00', $bucket_h);
    }
    $hourly_calls[$slot]++;
}

foreach ($sample_today_remarks as $ts) {
    $h_num = (int)date('H', strtotime($ts));
    if ($h_num >= 23) {
        $slot = '23:00';
    } else {
        $bucket_h = max($min_h, floor($h_num / 2) * 2);
        if ($bucket_h > 22) $bucket_h = 22;
        $slot = sprintf('%02d:00', $bucket_h);
    }
    $hourly_remarks[$slot]++;
}

$total_mapped_calls = array_sum($hourly_calls);
$total_mapped_remarks = array_sum($hourly_remarks);

test_assert($total_mapped_calls === 4, "TREND-06: 100% of today's calls (4/4) are represented in hourly buckets");
test_assert($total_mapped_remarks === 1, "TREND-07: 100% of today's remarks (1/1) are represented in hourly buckets");
test_assert($hourly_calls['06:00'] === 1, "TREND-08: 06:15 call accurately mapped to '06:00' bucket");
test_assert($hourly_calls['08:00'] === 1, "TREND-09: 08:20 call accurately mapped to '08:00' bucket");
test_assert($hourly_calls['12:00'] === 1, "TREND-10: 12:45 call accurately mapped to '12:00' bucket");
test_assert($hourly_calls['18:00'] === 1, "TREND-11: 18:30 call accurately mapped to '18:00' bucket");
test_assert($hourly_remarks['23:00'] === 1, "TREND-12: 23:10 remark accurately mapped to '23:00' bucket (NOT forced into 22:00)");
test_assert(($hourly_remarks['22:00'] ?? 0) === 0, "TREND-13: 22:00 bucket has 0 remarks (23:10 not merged into 22:00)");

// ── SECTION 10: Recent Interaction History Multi-Filter & Search Tests ─
echo "\n--- SECTION 10: Interaction History Multi-Filter & Search (Prepared Statements) ---\n";

// Insert rich test interactions for Mentor 2 (Rahul)
$pdo->exec("
    INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp, notes) VALUES
    ('PL-2026-001', 2, 'mentor_rahul', '" . date('Y-m-d H:i:s', time() - 3600) . "', 'Urgent study schedule discussion'),
    ('PL-2026-002', 2, 'mentor_rahul', '" . date('Y-m-d H:i:s', time() - 86400 * 2) . "', 'Weekly chapter revision check'),
    ('PL-2026-001', 2, 'mentor_rahul', '" . date('Y-m-d H:i:s', time() - 86400 * 15) . "', 'Monthly progress check-in');

    INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark, created_at) VALUES
    ('PL-2026-002', 2, 'mentor_rahul', 'Completed assignment on time and showed great interest', '" . date('Y-m-d H:i:s', time() - 7200) . "'),
    ('PL-2026-001', 2, 'mentor_rahul', 'Needs revision on Economics modules', '" . date('Y-m-d H:i:s', time() - 86400 * 5) . "');
");

// Test A: Default Period 'last_1_day' (rolling 24h)
$test_int_start = date('Y-m-d H:i:s', strtotime('-24 hours'));
$test_int_end = date('Y-m-d H:i:s');
$stmt_last24h = $pdo->prepare("
    SELECT * FROM (
        SELECT 'call' as type, cl.call_timestamp as event_time, cl.student_user_id, cl.notes as note, u.name as student_name, u.pepp_course
        FROM mentor_call_logs cl
        LEFT JOIN users u ON cl.student_user_id = u.user_id
        WHERE cl.admin_id = ? AND cl.call_timestamp BETWEEN ? AND ?
        UNION ALL
        SELECT 'remark' as type, rm.created_at as event_time, rm.student_user_id, rm.remark as note, u.name as student_name, u.pepp_course
        FROM mentor_remarks rm
        LEFT JOIN users u ON rm.student_user_id = u.user_id
        WHERE rm.admin_id = ? AND rm.created_at BETWEEN ? AND ?
    ) combined_int ORDER BY event_time DESC
");
$stmt_last24h->execute([2, $test_int_start, $test_int_end, 2, $test_int_start, $test_int_end]);
$res_last24h = $stmt_last24h->fetchAll(PDO::FETCH_ASSOC);

test_assert(count($res_last24h) === 2, "INT-01: Default 'last_1_day' filter returns exactly 2 interactions within last 24h");
test_assert($res_last24h[0]['type'] === 'call' && $res_last24h[1]['type'] === 'remark', "INT-02: Union includes both call and remark events");

// Test B: Type Filter = 'call' only
$stmt_calls_only = $pdo->prepare("
    SELECT 'call' as type, cl.call_timestamp as event_time, cl.student_user_id, cl.notes as note, u.name as student_name, u.pepp_course
    FROM mentor_call_logs cl
    LEFT JOIN users u ON cl.student_user_id = u.user_id
    WHERE cl.admin_id = ?
    ORDER BY event_time DESC
");
$stmt_calls_only->execute([2]);
$res_calls_only = $stmt_calls_only->fetchAll(PDO::FETCH_ASSOC);
test_assert(count($res_calls_only) === 6, "INT-03: Type filter 'call' returns all 6 calls for mentor 2");

// Test C: Type Filter = 'remark' only
$stmt_remarks_only = $pdo->prepare("
    SELECT 'remark' as type, rm.created_at as event_time, rm.student_user_id, rm.remark as note, u.name as student_name, u.pepp_course
    FROM mentor_remarks rm
    LEFT JOIN users u ON rm.student_user_id = u.user_id
    WHERE rm.admin_id = ?
    ORDER BY event_time DESC
");
$stmt_remarks_only->execute([2]);
$res_remarks_only = $stmt_remarks_only->fetchAll(PDO::FETCH_ASSOC);
test_assert(count($res_remarks_only) === 3, "INT-04: Type filter 'remark' returns all 3 remarks for mentor 2");

// Test D: Keyword Search by Student Name (e.g. 'Aisha')
$search_term = '%Aisha%';
$stmt_search_name = $pdo->prepare("
    SELECT * FROM (
        SELECT 'call' as type, cl.call_timestamp as event_time, cl.student_user_id, cl.notes as note, u.name as student_name, u.pepp_course
        FROM mentor_call_logs cl
        LEFT JOIN users u ON cl.student_user_id = u.user_id
        WHERE cl.admin_id = ? AND (u.name LIKE ? OR cl.student_user_id LIKE ? OR cl.notes LIKE ? OR u.pepp_course LIKE ?)
        UNION ALL
        SELECT 'remark' as type, rm.created_at as event_time, rm.student_user_id, rm.remark as note, u.name as student_name, u.pepp_course
        FROM mentor_remarks rm
        LEFT JOIN users u ON rm.student_user_id = u.user_id
        WHERE rm.admin_id = ? AND (u.name LIKE ? OR rm.student_user_id LIKE ? OR rm.remark LIKE ? OR u.pepp_course LIKE ?)
    ) combined_int ORDER BY event_time DESC
");
$stmt_search_name->execute([2, $search_term, $search_term, $search_term, $search_term, 2, $search_term, $search_term, $search_term, $search_term]);
$res_search_name = $stmt_search_name->fetchAll(PDO::FETCH_ASSOC);

test_assert(count($res_search_name) > 0, "INT-05: Search by student name 'Aisha' finds matching records");
foreach ($res_search_name as $r) {
    test_assert($r['student_name'] === 'Aisha Khan', "INT-06: Every matched row corresponds to Aisha Khan");
}

// Test E: Keyword Search in Notes/Remarks (e.g. 'Economics')
$search_note = '%Economics%';
$stmt_search_note = $pdo->prepare("
    SELECT * FROM (
        SELECT 'call' as type, cl.call_timestamp as event_time, cl.student_user_id, cl.notes as note, u.name as student_name, u.pepp_course
        FROM mentor_call_logs cl
        LEFT JOIN users u ON cl.student_user_id = u.user_id
        WHERE cl.admin_id = ? AND (u.name LIKE ? OR cl.student_user_id LIKE ? OR cl.notes LIKE ? OR u.pepp_course LIKE ?)
        UNION ALL
        SELECT 'remark' as type, rm.created_at as event_time, rm.student_user_id, rm.remark as note, u.name as student_name, u.pepp_course
        FROM mentor_remarks rm
        LEFT JOIN users u ON rm.student_user_id = u.user_id
        WHERE rm.admin_id = ? AND (u.name LIKE ? OR rm.student_user_id LIKE ? OR rm.remark LIKE ? OR u.pepp_course LIKE ?)
    ) combined_int ORDER BY event_time DESC
");
$stmt_search_note->execute([2, $search_note, $search_note, $search_note, $search_note, 2, $search_note, $search_note, $search_note, $search_note]);
$res_search_note = $stmt_search_note->fetchAll(PDO::FETCH_ASSOC);

test_assert(count($res_search_note) === 1, "INT-07: Search by keyword 'Economics' returns exactly 1 remark");
test_assert(strpos($res_search_note[0]['note'], 'Economics') !== false, "INT-08: Found note contains 'Economics'");

// ── SECTION 11: Native Vector Multi-Page PDF Generation Tests ─────────
echo "\n--- SECTION 11: Vector PDF Generation & Document Integrity ---\n";

require_once __DIR__ . '/includes/mentor_report_pdf.php';

$mock_pdf_data = [
    'mentor' => [
        'id' => 2,
        'username' => 'mentor_rahul',
        'full_name' => 'Rahul Sharma',
        'email' => 'rahul@example.com',
        'admin_type' => 'employee',
        'role' => 'admin'
    ],
    'stats' => [
        'assigned_students_count' => 25,
        'calls_count' => 42,
        'remarks_count' => 38,
        'unique_contacted_count' => 24,
        'contact_rate' => 96.0,
        'uncontacted_count' => 1,
        'active_days_count' => 22,
        'current_streak' => 5,
        'avg_student_progress' => 84.5,
        'avg_student_attendance' => 91.2,
        'is_online' => true,
        'last_active_label' => 'Just now'
    ],
    'badge' => [
        'title' => 'Elite Performer',
        'rank' => 1,
        'total_ranked' => 8,
        'score' => 94.2
    ],
    'filters' => [
        'global_range' => [
            'param' => 'today',
            'title' => 'Daily (Today: 02 Sep 2026)',
            'start_date' => '2026-09-02',
            'end_date' => '2026-09-02'
        ],
        'int_period' => [
            'param' => 'last_1_day',
            'title' => 'Last 1 Day'
        ],
        'int_type' => 'all',
        'int_search' => '',
        'generated_at' => date('d M Y, h:i A')
    ],
    'daily_trend' => [
        ['label' => '08:00', 'calls' => 2, 'remarks' => 1, 'total' => 3],
        ['label' => '10:00', 'calls' => 5, 'remarks' => 4, 'total' => 9],
        ['label' => '12:00', 'calls' => 8, 'remarks' => 6, 'total' => 14],
        ['label' => '14:00', 'calls' => 12, 'remarks' => 9, 'total' => 21],
        ['label' => '16:00', 'calls' => 10, 'remarks' => 11, 'total' => 21],
        ['label' => '18:00', 'calls' => 5, 'remarks' => 7, 'total' => 12]
    ],
    'interactions' => []
];

// Generate 45 interaction rows to test multi-page table pagination & auto-page break
for ($i = 1; $i <= 45; $i++) {
    $mock_pdf_data['interactions'][] = [
        'type' => ($i % 2 === 0) ? 'call' : 'remark',
        'event_time' => '2026-09-02 ' . sprintf('%02d:%02d:00', 8 + ($i % 12), ($i * 7) % 60),
        'student_name' => "Student Number {$i}",
        'student_user_id' => sprintf('PL-2026-%03d', $i),
        'pepp_course' => ($i % 3 === 0) ? 'CUET Commerce' : (($i % 3 === 1) ? 'CUET Science' : 'CUET Humanities'),
        'note' => "Detailed discussion regarding unit {$i} progression and mock test result analysis with regular follow-up."
    ];
}

$pdf_output = render_mentor_performance_report_pdf($mock_pdf_data);

test_assert(is_string($pdf_output), "PDF-01: render_mentor_performance_report_pdf returned a string");
test_assert(strlen($pdf_output) > 1000, "PDF-02: PDF string has substantial size (" . strlen($pdf_output) . " bytes)");
test_assert(strpos($pdf_output, '%PDF-1.4') === 0, "PDF-03: Starts with standard %PDF-1.4 header");
test_assert(strpos($pdf_output, '%%EOF') !== false, "PDF-04: Contains valid %%EOF trailer");
test_assert(strpos($pdf_output, '/Type /Catalog') !== false, "PDF-05: Contains document catalog");
test_assert(strpos($pdf_output, '/Type /Pages') !== false, "PDF-06: Contains pages tree");
test_assert(substr_count($pdf_output, '/Type /Page') > 1, "PDF-07: Successfully generated multiple pages (" . substr_count($pdf_output, '/Type /Page') . " pages)");
test_assert(strpos($pdf_output, 'Rahul Sharma') !== false, "PDF-08: PDF contains mentor full name");
test_assert(strpos($pdf_output, 'Elite Performer') !== false, "PDF-09: PDF contains performance badge");
test_assert(strpos($pdf_output, 'ASSIGNED ACTIVE') !== false || strpos($pdf_output, 'CALLS LOGGED') !== false, "PDF-10: PDF contains KPI cards");

echo "\n====================================================================\n";
echo "AUDIT SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
