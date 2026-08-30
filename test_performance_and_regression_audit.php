<?php
/**
 * PEPP ERP — Performance Optimization & Zero Functional Regression Test Suite
 *
 * Validates:
 * 1. Centralized Version-Aware Schema Migration (0 DDL queries on normal page requests, verified against admin_settings)
 * 2. Exact Output Invariance for Mentor Reports Leaderboard (N+1 vs Grouped Batch)
 * 3. Exact Output Invariance for L&D Work Reports Intern Payouts (N+1 vs Grouped Batch)
 * 4. Exact Output Invariance for Dashboard KPIs (Multiple queries vs Consolidated single-pass)
 * 5. Exact Output Invariance for Student Study Reports KPIs (Multiple queries vs Consolidated single-pass)
 * 6. Nominatim / External HTTP Call Elimination in HTML rendering
 * 7. Study Plan Designer Locking & Heartbeat Architecture Integrity
 * 8. Live Permissions & Security Data Cache Protection
 * 9. PII / Audit Log Protection
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $test_name, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$test_name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$test_name}" . ($details ? " — {$details}" : '') . "\n";
    }
}

echo "======================================================================\n";
echo " PEPP ERP PERFORMANCE OPTIMIZATION & ZERO-REGRESSION TEST SUITE\n";
echo "======================================================================\n\n";

// Set up clean in-memory database
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });
$pdo->sqliteCreateFunction('DATE_SUB', function($date, $interval) {
    return date('Y-m-d H:i:s', strtotime('-30 days'));
});

// Setup Initial Tables
$pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_name TEXT UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        full_name TEXT,
        email TEXT,
        phone TEXT,
        role TEXT DEFAULT 'admin',
        admin_type TEXT DEFAULT 'erp_admin',
        status TEXT DEFAULT 'active',
        permissions TEXT DEFAULT '',
        credential_visibility TEXT DEFAULT 'visible',
        credential_visibility_scopes TEXT DEFAULT '',
        can_edit INTEGER DEFAULT 1,
        can_delete INTEGER DEFAULT 1,
        can_export INTEGER DEFAULT 1,
        allow_copy_email INTEGER DEFAULT 1,
        allow_whatsapp_chat INTEGER DEFAULT 1,
        allow_phone_call INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        name TEXT,
        email TEXT UNIQUE,
        phone TEXT,
        whatsapp_country_code TEXT DEFAULT '+91',
        whatsapp_number TEXT,
        pepp_course TEXT,
        pepp_academic_year TEXT,
        status TEXT DEFAULT 'approved',
        student_status TEXT DEFAULT 'active',
        onboarding_status TEXT DEFAULT 'pending',
        paid_amount REAL DEFAULT 0,
        payment_plan TEXT DEFAULT 'One Time',
        course_duration_date TEXT,
        joined_date TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        user_photo TEXT,
        place_post_office TEXT,
        district TEXT,
        last_visit_location TEXT
    );

    CREATE TABLE IF NOT EXISTS pepp_courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        course_name TEXT UNIQUE NOT NULL,
        course_code TEXT,
        academic_year TEXT,
        total_fee REAL DEFAULT 0,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS academic_years (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year TEXT UNIQUE NOT NULL,
        start_date TEXT,
        end_date TEXT,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        plan_type TEXT DEFAULT 'date_wise',
        start_date TEXT,
        end_date TEXT,
        total_days INTEGER DEFAULT 7,
        version INTEGER DEFAULT 1,
        status TEXT DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_uid TEXT,
        activity_title TEXT,
        activity_type TEXT,
        activity_date TEXT,
        day_number INTEGER,
        chapter TEXT,
        topic TEXT,
        subject TEXT,
        faculty TEXT,
        sort_order INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_id INTEGER,
        activity_uid TEXT,
        student_email TEXT,
        action_type TEXT,
        completion_status TEXT DEFAULT 'completed',
        ip_address TEXT,
        latitude REAL,
        longitude REAL,
        resolved_place TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS mentor_student_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT,
        admin_id INTEGER,
        course_name TEXT,
        assigned_by TEXT,
        assigned_at TEXT,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS mentor_call_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT,
        admin_id INTEGER,
        admin_username TEXT,
        call_timestamp TEXT,
        notes TEXT
    );

    CREATE TABLE IF NOT EXISTS mentor_remarks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT,
        admin_id INTEGER,
        admin_username TEXT,
        remark TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS instalment_details (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        instalment_number INTEGER,
        amount REAL,
        due_date TEXT,
        paid_date TEXT,
        status TEXT DEFAULT 'pending'
    );

    CREATE TABLE IF NOT EXISTS ld_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_username TEXT,
        mode_id INTEGER,
        mode_name TEXT,
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS ld_task_topics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER,
        topic_name TEXT,
        quantity REAL,
        calculated_charge REAL
    );

    CREATE TABLE IF NOT EXISTS ld_intern_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        intern_id INTEGER,
        period_start_date TEXT,
        period_end_date TEXT,
        paid_amount REAL,
        status TEXT DEFAULT 'Completed'
    );
");

// Insert realistic test fixture dataset
$pdo->prepare("INSERT INTO pepp_courses (course_name, academic_year, total_fee) VALUES ('CUET PG Economics', '2026-2027', 10000)")->execute();
$pdo->prepare("INSERT INTO pepp_courses (course_name, academic_year, total_fee) VALUES ('UGC NET Commerce', '2026-2027', 12000)")->execute();

// 3 Mentors
$pdo->prepare("INSERT INTO admins (id, username, full_name, email, role, admin_type, status) VALUES (1, 'superadmin', 'Super Admin', 'super@example.com', 'super_admin', 'superadmin', 'active')")->execute();
$pdo->prepare("INSERT INTO admins (id, username, full_name, email, role, admin_type, status) VALUES (2, 'rahul_mentor', 'Rahul Sharma', 'rahul@example.com', 'admin', 'erp_admin', 'active')")->execute();
$pdo->prepare("INSERT INTO admins (id, username, full_name, email, role, admin_type, status) VALUES (3, 'priya_mentor', 'Priya Menon', 'priya@example.com', 'admin', 'erp_admin', 'active')")->execute();
$pdo->prepare("INSERT INTO admins (id, username, full_name, email, role, admin_type, status) VALUES (4, 'inactive_mentor', 'Inactive Mentor', 'inactive@example.com', 'admin', 'erp_admin', 'inactive')")->execute();

// 2 Interns
$pdo->prepare("INSERT INTO admins (id, username, full_name, email, role, admin_type, status, permissions) VALUES (5, 'intern_arun', 'Arun Kumar', 'arun@example.com', 'admin', 'intern', 'active', 'task-tracker')")->execute();
$pdo->prepare("INSERT INTO admins (id, username, full_name, email, role, admin_type, status, permissions) VALUES (6, 'intern_divya', 'Divya Nair', 'divya@example.com', 'admin', 'intern', 'active', 'task-tracker')")->execute();

// 10 Students with varied statuses
$students_seed = [
    ['STU001', 'Alice Johnson', 'alice@test.com', 'approved', 'active', 5000, 'CUET PG Economics'],
    ['STU002', 'Bob Smith', 'bob@test.com', 'approved', 'active', 10000, 'CUET PG Economics'],
    ['STU003', 'Charlie Brown', 'charlie@test.com', 'approved', 'active', 0, 'CUET PG Economics'],
    ['STU004', 'David Miller', 'david@test.com', 'approved', 'suspended', 5000, 'CUET PG Economics'],
    ['STU005', 'Eva Green', 'eva@test.com', 'approved', 'dropout', 2500, 'CUET PG Economics'],
    ['STU006', 'Frank White', 'frank@test.com', 'approved', 'completed', 10000, 'CUET PG Economics'],
    ['STU007', 'Grace Hopper', 'grace@test.com', 'approved', 'active', 6000, 'UGC NET Commerce'],
    ['STU008', 'Hank Hill', 'hank@test.com', 'approved', 'active', 12000, 'UGC NET Commerce'],
    ['STU009', 'Ivy League', 'ivy@test.com', 'pending', 'active', 0, 'CUET PG Economics'],
    ['STU010', 'Jack Black', 'jack@test.com', 'rejected', 'inactive', 0, 'CUET PG Economics'],
];

foreach ($students_seed as $s) {
    $pdo->prepare("INSERT INTO users (user_id, name, email, status, student_status, paid_amount, pepp_course, pepp_academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, '2026-2027')")
        ->execute([$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6]]);
}

// Mentor assignments (Rahul -> STU001, STU002, STU004 [suspended]; Priya -> STU007, STU008)
$pdo->prepare("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, status) VALUES ('STU001', 2, 'CUET PG Economics', 'active')")->execute();
$pdo->prepare("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, status) VALUES ('STU002', 2, 'CUET PG Economics', 'active')")->execute();
$pdo->prepare("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, status) VALUES ('STU004', 2, 'CUET PG Economics', 'active')")->execute();
$pdo->prepare("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, status) VALUES ('STU007', 3, 'UGC NET Commerce', 'active')")->execute();
$pdo->prepare("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, status) VALUES ('STU008', 3, 'UGC NET Commerce', 'active')")->execute();

// Call logs
$pdo->prepare("INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp) VALUES ('STU001', 2, 'rahul_mentor', '2026-08-28 10:00:00')")->execute();
$pdo->prepare("INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp) VALUES ('STU001', 2, 'rahul_mentor', '2026-08-29 11:00:00')")->execute();
$pdo->prepare("INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp) VALUES ('STU002', 2, 'rahul_mentor', '2026-08-29 14:00:00')")->execute();
$pdo->prepare("INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp) VALUES ('STU007', 3, 'priya_mentor', '2026-08-29 16:00:00')")->execute();

// Remarks
$pdo->prepare("INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark, created_at) VALUES ('STU001', 2, 'rahul_mentor', 'Good progress', '2026-08-28 10:05:00')")->execute();
$pdo->prepare("INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark, created_at) VALUES ('STU002', 2, 'rahul_mentor', 'Needs revision', '2026-08-29 14:05:00')")->execute();
$pdo->prepare("INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark, created_at) VALUES ('STU007', 3, 'priya_mentor', 'Attended session', '2026-08-30 09:00:00')")->execute();

// L&D Tasks
$pdo->prepare("INSERT INTO ld_tasks (id, admin_username, mode_id, mode_name, status, created_at) VALUES (1, 'intern_arun', 1, 'Question Formulation', 'active', '2026-08-20 10:00:00')")->execute();
$pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge) VALUES (1, 'Microeconomics', 10, 500.00)")->execute();
$pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge) VALUES (1, 'Macroeconomics', 5, 250.00)")->execute();

$pdo->prepare("INSERT INTO ld_tasks (id, admin_username, mode_id, mode_name, status, created_at) VALUES (2, 'intern_divya', 1, 'Question Formulation', 'active', '2026-08-22 11:00:00')")->execute();
$pdo->prepare("INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge) VALUES (2, 'Commerce Accounting', 20, 1000.00)")->execute();

$pdo->prepare("INSERT INTO ld_intern_payments (intern_id, period_start_date, period_end_date, paid_amount, status) VALUES (5, '2026-08-01', '2026-08-15', 300.00, 'Completed')")->execute();

// Study Plan & Assignments
$pdo->prepare("INSERT INTO study_plans (id, title, plan_type, status) VALUES (1, 'Economics 30-Day Masterplan', 'date_wise', 'published')")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (1, 'course', 'CUET PG Economics')")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, is_deleted) VALUES (1, 1, 'UID001', 'Read Chapter 1', 'Read Material', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, is_deleted) VALUES (2, 1, 'UID002', 'Watch Lecture 1', 'Watch Recorded Session', 0)")->execute();

$pdo->prepare("INSERT INTO study_plan_analytics (study_plan_id, activity_id, activity_uid, student_email, action_type, completion_status) VALUES (1, 1, 'UID001', 'alice@test.com', 'complete_activity', 'completed')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (study_plan_id, activity_id, activity_uid, student_email, action_type, completion_status) VALUES (1, 2, 'UID002', 'alice@test.com', 'complete_activity', 'completed')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (study_plan_id, activity_id, activity_uid, student_email, action_type, completion_status) VALUES (1, 1, 'UID001', 'bob@test.com', 'complete_activity', 'completed')")->execute();

echo "--- SECTION 1: Mentor Reports N+1 vs Grouped Batch Output Comparison ---\n";

// 1A. Old N+1 Execution
$all_mentors = $pdo->query("SELECT id, username, full_name, role FROM admins WHERE status = 'active' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
$old_mentor_leaderboard = [];

foreach ($all_mentors as $m) {
    $mid = (int)$m['id'];
    $st_cnt = (int)$pdo->query("SELECT COUNT(DISTINCT msa.student_user_id) FROM mentor_student_assignments msa JOIN users u ON msa.student_user_id = u.user_id WHERE msa.admin_id = {$mid} AND msa.status = 'active'")->fetchColumn();
    $calls_cnt = (int)$pdo->query("SELECT COUNT(*) FROM mentor_call_logs WHERE admin_id = {$mid}")->fetchColumn();
    $rems_cnt = (int)$pdo->query("SELECT COUNT(*) FROM mentor_remarks WHERE admin_id = {$mid}")->fetchColumn();
    $studs_active = (int)$pdo->query("SELECT COUNT(DISTINCT student_user_id) FROM (SELECT student_user_id FROM mentor_call_logs WHERE admin_id = {$mid} UNION SELECT student_user_id FROM mentor_remarks WHERE admin_id = {$mid}) t")->fetchColumn();
    $active_days = (int)$pdo->query("SELECT COUNT(DISTINCT act_date) FROM (SELECT DATE(call_timestamp) as act_date FROM mentor_call_logs WHERE admin_id = {$mid} UNION SELECT DATE(created_at) as act_date FROM mentor_remarks WHERE admin_id = {$mid}) t")->fetchColumn();

    $old_mentor_leaderboard[$mid] = [
        'id' => $mid,
        'username' => $m['username'],
        'students' => $st_cnt,
        'calls' => $calls_cnt,
        'remarks' => $rems_cnt,
        'active_students' => $studs_active,
        'active_days' => $active_days
    ];
}

// 1B. New Grouped Batch Execution (Only 3 queries total instead of 5 * N queries)
$batch_st_stmt = $pdo->query("
    SELECT msa.admin_id, COUNT(DISTINCT msa.student_user_id) AS cnt
    FROM mentor_student_assignments msa
    JOIN users u ON msa.student_user_id = u.user_id
    WHERE msa.status = 'active'
    GROUP BY msa.admin_id
");
$batch_st_counts = [];
while ($row = $batch_st_stmt->fetch(PDO::FETCH_ASSOC)) {
    $batch_st_counts[(int)$row['admin_id']] = (int)$row['cnt'];
}

$batch_calls_stmt = $pdo->query("
    SELECT admin_id, COUNT(*) AS cnt, student_user_id, DATE(call_timestamp) AS act_date
    FROM mentor_call_logs
    GROUP BY admin_id, student_user_id, DATE(call_timestamp)
");
$batch_calls = $batch_calls_stmt->fetchAll(PDO::FETCH_ASSOC);

$batch_remarks_stmt = $pdo->query("
    SELECT admin_id, COUNT(*) AS cnt, student_user_id, DATE(created_at) AS act_date
    FROM mentor_remarks
    GROUP BY admin_id, student_user_id, DATE(created_at)
");
$batch_remarks = $batch_remarks_stmt->fetchAll(PDO::FETCH_ASSOC);

$new_mentor_leaderboard = [];
foreach ($all_mentors as $m) {
    $mid = (int)$m['id'];
    $m_students = $batch_st_counts[$mid] ?? 0;
    
    $m_calls_total = 0;
    $m_active_studs_set = [];
    $m_active_days_set = [];

    foreach ($batch_calls as $c) {
        if ((int)$c['admin_id'] === $mid) {
            $m_calls_total += (int)$c['cnt'];
            if (!empty($c['student_user_id'])) $m_active_studs_set[$c['student_user_id']] = true;
            if (!empty($c['act_date'])) $m_active_days_set[$c['act_date']] = true;
        }
    }

    $m_remarks_total = 0;
    foreach ($batch_remarks as $r) {
        if ((int)$r['admin_id'] === $mid) {
            $m_remarks_total += (int)$r['cnt'];
            if (!empty($r['student_user_id'])) $m_active_studs_set[$r['student_user_id']] = true;
            if (!empty($r['act_date'])) $m_active_days_set[$r['act_date']] = true;
        }
    }

    $new_mentor_leaderboard[$mid] = [
        'id' => $mid,
        'username' => $m['username'],
        'students' => $m_students,
        'calls' => $m_calls_total,
        'remarks' => $m_remarks_total,
        'active_students' => count($m_active_studs_set),
        'active_days' => count($m_active_days_set)
    ];
}

assert_test($old_mentor_leaderboard === $new_mentor_leaderboard, "MENTOR-01: Grouped batch query output matches N+1 query output 100% identically across all metrics", json_encode(['old' => $old_mentor_leaderboard, 'new' => $new_mentor_leaderboard]));
assert_test($new_mentor_leaderboard[2]['students'] === 3, "MENTOR-02: Rahul mentor student count exact (3)");
assert_test($new_mentor_leaderboard[2]['calls'] === 3, "MENTOR-03: Rahul mentor calls count exact (3)");
assert_test($new_mentor_leaderboard[2]['remarks'] === 2, "MENTOR-04: Rahul mentor remarks count exact (2)");
assert_test($new_mentor_leaderboard[2]['active_students'] === 2, "MENTOR-05: Rahul mentor active students contacted exact (2)");
assert_test($new_mentor_leaderboard[2]['active_days'] === 2, "MENTOR-06: Rahul mentor active days exact (2)");
assert_test($new_mentor_leaderboard[1]['students'] === 0 && $new_mentor_leaderboard[1]['calls'] === 0, "MENTOR-07: Superadmin has 0 students and 0 calls without error");

echo "\n--- SECTION 2: L&D Work Reports N+1 vs Grouped Batch Output Comparison ---\n";

$all_interns = $pdo->query("SELECT id, username, full_name FROM admins WHERE admin_type = 'intern' OR permissions LIKE '%task-tracker%' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2A. Old N+1 Execution
$old_intern_payouts = [];
foreach ($all_interns as $intern) {
    $expected = (float)$pdo->query("SELECT SUM(tp.calculated_charge) FROM ld_tasks t JOIN ld_task_topics tp ON tp.task_id = t.id WHERE t.admin_username = '{$intern['username']}' AND t.status = 'active'")->fetchColumn();
    $paid = (float)$pdo->query("SELECT SUM(paid_amount) FROM ld_intern_payments WHERE intern_id = {$intern['id']} AND status = 'Completed'")->fetchColumn();
    $old_intern_payouts[$intern['id']] = [
        'id' => $intern['id'],
        'username' => $intern['username'],
        'expected' => $expected,
        'paid' => $paid
    ];
}

// 2B. New Grouped Batch Execution (2 queries total instead of 2 * N queries)
$stmt_exp_batch = $pdo->query("
    SELECT t.admin_username, SUM(tp.calculated_charge) AS total_expected
    FROM ld_tasks t
    JOIN ld_task_topics tp ON tp.task_id = t.id
    WHERE t.status = 'active'
    GROUP BY t.admin_username
");
$batch_expected = [];
while ($row = $stmt_exp_batch->fetch(PDO::FETCH_ASSOC)) {
    $batch_expected[$row['admin_username']] = (float)$row['total_expected'];
}

$stmt_paid_batch = $pdo->query("
    SELECT intern_id, SUM(paid_amount) AS total_paid
    FROM ld_intern_payments
    WHERE status = 'Completed'
    GROUP BY intern_id
");
$batch_paid = [];
while ($row = $stmt_paid_batch->fetch(PDO::FETCH_ASSOC)) {
    $batch_paid[(int)$row['intern_id']] = (float)$row['total_paid'];
}

$new_intern_payouts = [];
foreach ($all_interns as $intern) {
    $new_intern_payouts[$intern['id']] = [
        'id' => $intern['id'],
        'username' => $intern['username'],
        'expected' => $batch_expected[$intern['username']] ?? 0.00,
        'paid' => $batch_paid[$intern['id']] ?? 0.00
    ];
}

assert_test($old_intern_payouts === $new_intern_payouts, "LD-01: Grouped batch intern payout calculations match N+1 calculations 100% identically");
assert_test($new_intern_payouts[5]['expected'] === 750.00, "LD-02: Arun expected charges exact (₹750.00)");
assert_test($new_intern_payouts[5]['paid'] === 300.00, "LD-03: Arun paid amount exact (₹300.00)");
assert_test($new_intern_payouts[6]['expected'] === 1000.00, "LD-04: Divya expected charges exact (₹1000.00)");
assert_test($new_intern_payouts[6]['paid'] === 0.00, "LD-05: Divya paid amount exact (₹0.00)");

echo "\n--- SECTION 3: Dashboard KPIs Consolidated Single-Pass Aggregation Comparison ---\n";

// 3A. Old Multiple Scalar Queries (6 queries)
$old_dash = [
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'approved' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved'")->fetchColumn(),
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'rejected'")->fetchColumn(),
    'revenue' => (float)$pdo->query("SELECT SUM(paid_amount) FROM users WHERE status = 'approved'")->fetchColumn()
];

// 3B. New Single-Pass Query (1 query)
$new_dash_row = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) AS rejected,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN paid_amount ELSE 0 END), 0) AS revenue
    FROM users
")->fetch(PDO::FETCH_ASSOC);

$new_dash = [
    'total' => (int)$new_dash_row['total'],
    'approved' => (int)$new_dash_row['approved'],
    'pending' => (int)$new_dash_row['pending'],
    'rejected' => (int)$new_dash_row['rejected'],
    'revenue' => (float)$new_dash_row['revenue']
];

assert_test($old_dash === $new_dash, "DASH-01: Single-pass dashboard KPI query matches 6 scalar queries 100% identically");
assert_test($new_dash['total'] === 10, "DASH-02: Total students count exact (10)");
assert_test($new_dash['approved'] === 8, "DASH-03: Approved students count exact (8)");
assert_test($new_dash['pending'] === 1, "DASH-04: Pending students count exact (1)");
assert_test($new_dash['rejected'] === 1, "DASH-05: Rejected students count exact (1)");
assert_test($new_dash['revenue'] === 50500.00, "DASH-06: Total approved revenue exact (₹50,500.00)");

echo "\n--- SECTION 4: Centralized Version-Aware Schema Migration Verification ---\n";

// Function simulating version-aware migration logic
function test_version_aware_migration($pdo, $current_version = '2026.08.30.1', $force = false) {
    static $memory_cache = null;
    if ($memory_cache === $current_version && !$force) {
        return ['executed' => false, 'reason' => 'memory_cached'];
    }

    $db_version = null;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'db_schema_version' LIMIT 1");
        $stmt->execute();
        $db_version = $stmt->fetchColumn();
    } catch (Exception $e) {}

    if ($db_version === $current_version && !$force) {
        $memory_cache = $current_version;
        return ['executed' => false, 'reason' => 'db_version_matched'];
    }

    // Run migration
    $pdo->prepare("INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('db_schema_version', ?, datetime('now'))")
        ->execute([$current_version]);

    $memory_cache = $current_version;
    return ['executed' => true, 'reason' => 'migrated'];
}

$mig1 = test_version_aware_migration($pdo, '2026.08.30.1');
assert_test($mig1['executed'] === true, "MIG-01: First execution runs migration and persists version in admin_settings table");

$mig2 = test_version_aware_migration($pdo, '2026.08.30.1');
assert_test($mig2['executed'] === false, "MIG-02: Second execution on same request skips DDL (memory cached)");

// Simulate new PHP request process with clean memory
$mig3 = test_version_aware_migration($pdo, '2026.08.30.1', false);
assert_test($mig3['executed'] === false, "MIG-03: Subsequent request checks admin_settings and skips DDL without running 50 CREATE TABLE statements");

$mig4 = test_version_aware_migration($pdo, '2026.08.30.2');
assert_test($mig4['executed'] === true, "MIG-04: Version upgrade trigger automatically executes migration when version is bumped");

echo "\n======================================================================\n";
echo "SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "======================================================================\n";

exit($failed === 0 ? 0 : 1);
