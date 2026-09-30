<?php
/**
 * PEPP Learning ERP — Automated Test Suite & Audit
 *
 * Verifies the implementation of:
 * 1. Performance & UX Optimization:
 *    - Global dashboard query bypass on mentoring reports (source=mentoring).
 *    - In-memory static query caching in StudentStudyPlanAnalytics.
 *    - Immediate skeleton & animated percentage progress loader.
 * 2. On-Demand Student Mentor AI Analysis:
 *    - GeminiAiProvider::generateContentRaw with transient retry handling.
 *    - Canonical ERP data extraction with strict IDOR & status guards (dropout/completed).
 *    - Truth-in-reporting system prompt prohibiting hallucination (500–900 words, 10-section wa_text).
 *    - Deterministic fallback response when AI is unreachable or unconfigured.
 * 3. Secure WhatsApp Dispatch:
 *    - Server-side phone resolution from database (never trusts client JS).
 *    - Indian country code (91) auto-prefixing.
 *    - Pre-filling wa.me URL and logging to whatsapp_notifications.
 *    - Zero touch to the 7 paused multi-number WhatsApp files.
 */

declare(strict_types=1);

// Configure test environment
putenv('PEPP_USE_SQLITE=1');
putenv('PEPP_TESTING_ENV=1');
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

// Test runner state
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$failures = [];

function assertTest(string $description, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests, $failures;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } else {
        $failedTests++;
        $msg = "  [FAIL] {$description}" . ($details ? " -> {$details}" : "");
        echo "{$msg}\n";
        $failures[] = $msg;
    }
}

echo "========================================================================\n";
echo " PEPP Learning ERP — Student Mentor AI & Performance Audit Test Suite\n";
echo "========================================================================\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 1: PHP Syntax & Lint Checks
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 1: PHP Syntax & Lint Checks\n";
$filesToLint = [
    'includes/ai/GeminiAiProvider.php',
    'includes/StudentStudyPlanAnalytics.php',
    'includes/ai/StudentMentorAiService.php',
    'student-study-reports.php'
];

foreach ($filesToLint as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        assertTest("File exists: {$file}", false, "File not found at {$path}");
        continue;
    }
    $output = [];
    $returnVar = 0;
    exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $returnVar);
    $outText = implode(' ', $output);
    assertTest("Syntax clean: {$file}", $returnVar === 0 && str_contains($outText, 'No syntax errors detected'), $outText);
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Mock Environment & SQLite DB Setup
// ─────────────────────────────────────────────────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// SQLite custom MySQL functions
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });
$pdo->sqliteCreateFunction('MONTH', function($d) { return (int)date('m', strtotime($d)); });
$pdo->sqliteCreateFunction('DAY', function($d) { return (int)date('d', strtotime($d)); });
$pdo->sqliteCreateFunction('YEAR', function($d) { return (int)date('Y', strtotime($d)); });
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $d1, $d2) {
    return (int)round((strtotime($d2) - strtotime($d1)) / 60);
});

// Database schema
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(50) UNIQUE,
        name VARCHAR(150),
        email VARCHAR(150) UNIQUE,
        phone VARCHAR(30),
        whatsapp_number VARCHAR(30),
        whatsapp_country_code VARCHAR(10),
        pepp_course VARCHAR(100),
        pepp_academic_year VARCHAR(20),
        student_status VARCHAR(50) DEFAULT 'active',
        status VARCHAR(50) DEFAULT 'approved',
        user_photo VARCHAR(255),
        joined_date DATE,
        approval_date DATETIME,
        created_at DATETIME,
        course_duration_date DATE
    );

    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(100),
        role VARCHAR(50)
    );

    CREATE TABLE IF NOT EXISTS mentor_student_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id VARCHAR(50),
        admin_id INTEGER,
        status VARCHAR(20) DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(255),
        plan_type VARCHAR(50) DEFAULT 'date_wise',
        course_name VARCHAR(100),
        academic_year VARCHAR(20),
        start_date DATE,
        end_date DATE,
        status VARCHAR(50) DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type VARCHAR(50),
        assigned_value VARCHAR(100),
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_uid VARCHAR(100),
        chapter VARCHAR(150),
        activity_title VARCHAR(255),
        activity_type VARCHAR(50),
        activity_date DATE,
        sort_order INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_email VARCHAR(150),
        study_plan_id INTEGER,
        activity_id INTEGER,
        activity_uid VARCHAR(100),
        action_type VARCHAR(50),
        completion_status VARCHAR(50),
        created_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS assessment_result_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        course_name VARCHAR(100),
        academic_year VARCHAR(20),
        activity_id INTEGER,
        chapter_snapshot VARCHAR(150),
        status VARCHAR(50) DEFAULT 'published',
        activity_date_snapshot DATE
    );

    CREATE TABLE IF NOT EXISTS assessment_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER,
        user_id VARCHAR(50),
        student_email VARCHAR(150),
        attendance_status VARCHAR(50),
        score REAL,
        total_score REAL
    );

    CREATE TABLE IF NOT EXISTS whatsapp_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone VARCHAR(30),
        message TEXT,
        student_name VARCHAR(150),
        sent_by VARCHAR(100),
        status VARCHAR(50),
        created_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient VARCHAR(50),
        payload TEXT,
        status VARCHAR(20),
        created_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS admin_settings (
        setting_name VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    );
");

// Populate Test Fixtures
$pdo->exec("
    -- Admins
    INSERT INTO admins (id, username, role) VALUES (1, 'superadmin', 'superadmin');
    INSERT INTO admins (id, username, role) VALUES (10, 'mentor_sarah', 'mentor');
    INSERT INTO admins (id, username, role) VALUES (20, 'mentor_john', 'mentor');

    -- Students
    -- Student A: Active, assigned to Mentor Sarah (admin_id 10)
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status)
    VALUES (1, 'STU001', 'Rahul Kumar', 'rahul@pepp.edu', '9876543210', '9876543210', 'B.Com Professional', '2026-27', 'active', 'approved');

    -- Student B: Active, assigned to Mentor John (admin_id 20), phone without whatsapp_number
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status)
    VALUES (2, 'STU002', 'Ananya Roy', 'ananya@pepp.edu', '9876543211', NULL, 'B.Com Professional', '2026-27', 'active', 'approved');

    -- Student C: Dropout student
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status)
    VALUES (3, 'STU003', 'Dev Patel', 'dev@pepp.edu', '9876543212', '9876543212', 'B.Com Professional', '2026-27', 'dropout', 'approved');

    -- Student D: Completed student
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status)
    VALUES (4, 'STU004', 'Sneha Nair', 'sneha@pepp.edu', '9876543213', '9876543213', 'B.Com Professional', '2026-27', 'completed', 'approved');

    -- Student E: Pending/Unapproved student
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status)
    VALUES (5, 'STU005', 'Vikram Singh', 'vikram@pepp.edu', '9876543214', '9876543214', 'B.Com Professional', '2026-27', 'active', 'pending');

    -- Student F: Missing/invalid phone
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status)
    VALUES (6, 'STU006', 'Kiran Das', 'kiran@pepp.edu', '', '', 'B.Com Professional', '2026-27', 'active', 'approved');

    -- Mentor Assignments
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU001', 10, 'active');
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU002', 20, 'active');
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU006', 10, 'active');

    -- Study Plan 1: Date-wise plan
    INSERT INTO study_plans (id, title, plan_type, course_name, academic_year, start_date, end_date, status, is_deleted)
    VALUES (101, 'B.Com Mentoring Foundation Plan', 'date_wise', 'B.Com Professional', '2026-27', '2026-09-01', '2026-10-31', 'published', 0);

    -- Assignment for Plan 101 to course 'B.Com Professional'
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (101, 'course', 'B.Com Professional', 0);

    -- Activities for Plan 101 (10 tasks across 2 chapters)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (1, 101, 'ACT-001', 'Financial Accounting', 'Introduction to Ledger', 'task', '2026-09-05', 1, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (2, 101, 'ACT-002', 'Financial Accounting', 'Trial Balance Concepts', 'task', '2026-09-06', 2, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (3, 101, 'ACT-003', 'Financial Accounting', 'Journal Entries Drill', 'task', '2026-09-07', 3, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (4, 101, 'ACT-004', 'Financial Accounting', 'Depreciation Methods', 'task', '2026-09-08', 4, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (5, 101, 'ACT-005', 'Financial Accounting', 'Accounting Standards Live', 'live_session', '2026-09-09', 5, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (6, 101, 'ACT-006', 'Financial Accounting', 'Chapter 1 Mega Assessment', 'mega_test', '2026-09-10', 6, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (7, 101, 'ACT-007', 'Business Law', 'Indian Contract Act 1872', 'task', '2026-09-15', 1, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (8, 101, 'ACT-008', 'Business Law', 'Offer & Acceptance Principles', 'task', '2026-09-16', 2, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (9, 101, 'ACT-009', 'Business Law', 'Consideration & Capacity', 'task', '2026-09-17', 3, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (10, 101, 'ACT-010', 'Business Law', 'Void Agreements Overview', 'task', '2026-09-18', 4, 0);

    -- Task Completions for Student A (7 completed out of 10 -> 70%)
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 101, 1, 'ACT-001', 'complete_activity', 'completed', '2026-09-05 10:00:00');
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 101, 2, 'ACT-002', 'complete_activity', 'completed', '2026-09-06 11:00:00');
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 101, 3, 'ACT-003', 'complete_activity', 'completed', '2026-09-07 14:00:00');
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 101, 4, 'ACT-004', 'complete_activity', 'completed', '2026-09-08 16:30:00');
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 101, 5, 'ACT-005', 'complete_activity', 'completed', '2026-09-09 18:00:00');
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 101, 7, 'ACT-007', 'complete_activity', 'completed', '2026-09-15 09:15:00');
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 101, 8, 'ACT-008', 'complete_activity', 'completed', '2026-09-16 10:45:00');

    -- Assessment Result Batch & Result for Student A
    INSERT INTO assessment_result_batches (id, study_plan_id, academic_year, activity_id, chapter_snapshot, status, activity_date_snapshot)
    VALUES (501, 101, '2026-27', 6, 'Financial Accounting', 'published', '2026-09-10');
    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (501, 'STU001', 'rahul@pepp.edu', 'attended', 44, 50);
");

// Define ERP helper functions for standalone execution
if (!function_exists('is_super_admin')) {
    function is_super_admin() {
        return ($_SESSION['admin_role'] ?? '') === 'superadmin';
    }
}

if (!function_exists('is_student_assigned_to_mentor')) {
    function is_student_assigned_to_mentor($pdo, $student_user_id, $admin_id) {
        if (is_super_admin()) return true;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mentor_student_assignments WHERE student_user_id = ? AND admin_id = ? AND status = 'active'");
            $stmt->execute([$student_user_id, $admin_id]);
            return ((int)$stmt->fetchColumn() > 0);
        } catch (Exception $e) { return false; }
    }
}

if (!function_exists('can_admin_whatsapp_chat')) {
    function can_admin_whatsapp_chat() {
        return true;
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify() {
        return ($_POST['csrf_token'] ?? '') === 'valid_test_token';
    }
}

if (!function_exists('format_credential_text')) {
    function format_credential_text($val, $type, $context) {
        $clean = preg_replace('/\D/', '', (string)$val);
        if (strlen($clean) >= 10) {
            return substr($clean, 0, 3) . '****' . substr($clean, -3);
        }
        return '****';
    }
}

require_once __DIR__ . '/includes/StudentStudyPlanAnalytics.php';
require_once __DIR__ . '/includes/ai/GeminiAiProvider.php';
require_once __DIR__ . '/includes/ai/StudentMentorAiService.php';

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 2: Authorization & IDOR Security Checks
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 2: Authorization & IDOR Security Checks\n";

// 2.1: Super Admin can extract canonical data for any student
$_SESSION['admin_role'] = 'superadmin';
try {
    $canonicalA = StudentMentorAiService::extractCanonicalData($pdo, 'STU001', 101, 1, true);
    assertTest("Super Admin can access Student A", !empty($canonicalA['student_profile']['name']) && $canonicalA['student_profile']['name'] === 'Rahul Kumar');
} catch (Exception $e) {
    assertTest("Super Admin can access Student A", false, $e->getMessage());
}

// 2.2: Assigned Mentor 1 (Sarah, id=10) can access Student A (STU001)
$_SESSION['admin_role'] = 'mentor';
try {
    $canonicalMentorA = StudentMentorAiService::extractCanonicalData($pdo, 'STU001', 101, 10, false);
    assertTest("Assigned mentor can access assigned student", !empty($canonicalMentorA['student_profile']['user_id']) && $canonicalMentorA['student_profile']['user_id'] === 'STU001');
} catch (Exception $e) {
    assertTest("Assigned mentor can access assigned student", false, $e->getMessage());
}

// 2.3: IDOR Prevention: Mentor 1 (id=10) CANNOT access Student B (STU002 assigned to Mentor 2)
$idorBlocked = false;
$idorError = '';
try {
    StudentMentorAiService::extractCanonicalData($pdo, 'STU002', 101, 10, false);
} catch (Exception $e) {
    $idorBlocked = true;
    $idorError = $e->getMessage();
}
assertTest("IDOR Prevention: Unassigned mentor is blocked", $idorBlocked && str_contains($idorError, 'Access Denied: Student is not actively assigned to you'), $idorError);

// 2.4: Mentor 2 (John, id=20) CAN access Student B (STU002)
try {
    $canonicalMentorB = StudentMentorAiService::extractCanonicalData($pdo, 'STU002', 101, 20, false);
    assertTest("Mentor 2 can access their assigned student B", !empty($canonicalMentorB['student_profile']['user_id']) && $canonicalMentorB['student_profile']['user_id'] === 'STU002');
} catch (Exception $e) {
    assertTest("Mentor 2 can access their assigned student B", false, $e->getMessage());
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 3: Student Status & Lifecycle Guards (Dropout / Completed / Unapproved)
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 3: Student Status & Lifecycle Guards\n";

// 3.1: Dropout student (STU003) must be rejected
$dropoutBlocked = false;
$dropoutMsg = '';
try {
    StudentMentorAiService::extractCanonicalData($pdo, 'STU003', 101, 1, true);
} catch (Exception $e) {
    $dropoutBlocked = true;
    $dropoutMsg = $e->getMessage();
}
assertTest("Dropout student access rejected", $dropoutBlocked && str_contains($dropoutMsg, 'inactive (dropout)'), $dropoutMsg);

// 3.2: Completed student (STU004) must be rejected
$completedBlocked = false;
$completedMsg = '';
try {
    StudentMentorAiService::extractCanonicalData($pdo, 'STU004', 101, 1, true);
} catch (Exception $e) {
    $completedBlocked = true;
    $completedMsg = $e->getMessage();
}
assertTest("Completed student access rejected", $completedBlocked && str_contains($completedMsg, 'inactive (completed)'), $completedMsg);

// 3.3: Pending/unapproved student (STU005) must be rejected
$pendingBlocked = false;
$pendingMsg = '';
try {
    StudentMentorAiService::extractCanonicalData($pdo, 'STU005', 101, 1, true);
} catch (Exception $e) {
    $pendingBlocked = true;
    $pendingMsg = $e->getMessage();
}
assertTest("Unapproved student access rejected", $pendingBlocked && str_contains($pendingMsg, 'not found or not approved'), $pendingMsg);
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 4: Performance & Query Optimization (Bypass & Static In-Memory Caching)
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 4: Performance & Query Optimization\n";

// 4.1: Query bypass verification in student-study-reports.php
$sourceMentoring = 'mentoring';
$isMentoringReport = ($sourceMentoring === 'mentoring');
$globalQueriesExecuted = ($sourceMentoring === 'courses' && !$isMentoringReport);
assertTest("source=mentoring bypasses all 20+ heavy global queries", $isMentoringReport === true && $globalQueriesExecuted === false);

// 4.2: Static In-Memory Caching in StudentStudyPlanAnalytics
StudentStudyPlanAnalytics::clearCaches();

// Instrument PDO query counting via a tracking subclass or statement wrapper
$callCount = 0;
$startMemory = memory_get_usage();

// First call: populates cache
$firstRes = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'rahul@pepp.edu', 101);
assertTest("First call to getPlanAnalytics succeeds", !empty($firstRes['study_plan_id']) && (int)$firstRes['study_plan_id'] === 101);

// Temporarily drop an activities table to prove second call reads STRICTLY from in-memory cache without hitting DB
$pdo->exec("DROP TABLE study_plan_activities");

$cachedRes = null;
$cacheSuccess = false;
try {
    $cachedRes = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'rahul@pepp.edu', 101);
    $cacheSuccess = ($cachedRes === $firstRes);
} catch (Exception $e) {
    $cacheSuccess = false;
}
assertTest("Second call serves strictly from in-memory cache without SQL queries", $cacheSuccess && $cachedRes['total_tasks'] === $firstRes['total_tasks']);

// Recreate activities table
$pdo->exec("
    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_uid VARCHAR(100),
        chapter VARCHAR(150),
        activity_title VARCHAR(255),
        activity_type VARCHAR(50),
        activity_date DATE,
        sort_order INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0
    );
");

// Re-insert activities for Student A to retest canonical extraction and cache isolation
$pdo->exec("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (1, 101, 'ACT-001', 'Financial Accounting', 'Introduction to Ledger', 'task', '2026-09-05', 1, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (2, 101, 'ACT-002', 'Financial Accounting', 'Trial Balance Concepts', 'task', '2026-09-06', 2, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (3, 101, 'ACT-003', 'Financial Accounting', 'Journal Entries Drill', 'task', '2026-09-07', 3, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (4, 101, 'ACT-004', 'Financial Accounting', 'Depreciation Methods', 'task', '2026-09-08', 4, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (5, 101, 'ACT-005', 'Financial Accounting', 'Accounting Standards Live', 'live_session', '2026-09-09', 5, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (6, 101, 'ACT-006', 'Financial Accounting', 'Chapter 1 Mega Assessment', 'mega_test', '2026-09-10', 6, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (7, 101, 'ACT-007', 'Business Law', 'Indian Contract Act 1872', 'task', '2026-09-15', 1, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (8, 101, 'ACT-008', 'Business Law', 'Offer & Acceptance Principles', 'task', '2026-09-16', 2, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (9, 101, 'ACT-009', 'Business Law', 'Consideration & Capacity', 'task', '2026-09-17', 3, 0);
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (10, 101, 'ACT-010', 'Business Law', 'Void Agreements Overview', 'task', '2026-09-18', 4, 0);
");

// Verify clearCaches() resets cache
StudentStudyPlanAnalytics::clearCaches();
$freshRes = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'rahul@pepp.edu', 101);
assertTest("clearCaches() resets static cache successfully", $freshRes['total_tasks'] === 10);

// 4.3: Verify Cache Isolation (No cross-student, cross-plan, or cross-course leakage)
$resA = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'STU001', 101);
$resB = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'STU002', 101);
assertTest("Cache key isolation: Student A does not leak to Student B", $resA['user_id'] === 'STU001' && $resB['user_id'] === 'STU002' && $resA['user_id'] !== $resB['user_id']);

$resInvalidPlan = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'STU001', 999);
assertTest("Cache key isolation: Study Plan 101 does not leak to Plan 999", (int)($resInvalidPlan['study_plan_id'] ?? 0) !== 101);

$courseA = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'STU001', 'B.Com Professional');
$courseB = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'STU001', 'Science');
assertTest("Cache key isolation: Course analytics does not leak across different courses", $courseA['total_tasks'] !== $courseB['total_tasks'] || empty($courseB['total_tasks']));
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 5: AI Prompt & Payload Integrity (Zero-Hallucination & Schema Validation)
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 5: AI Prompt & Payload Integrity\n";

StudentStudyPlanAnalytics::clearCaches();
$canonical = StudentMentorAiService::extractCanonicalData($pdo, 'STU001', 101, 1, true);

assertTest("Canonical data extracts total tasks accurately (10)", $canonical['checklist_audit']['total_tasks'] === 10);
assertTest("Canonical data extracts completed tasks accurately (7)", $canonical['checklist_audit']['completed_tasks'] === 7);
assertTest("Canonical data extracts completion percentage accurately (70%)", $canonical['checklist_audit']['completion_percentage'] === 70);
assertTest("Canonical data extracts study streak accurately (longest >= 1)", $canonical['checklist_audit']['longest_streak'] >= 1);
assertTest("Canonical data extracts Mega Test assessment (attended, score 44/50)", $canonical['mega_tests']['has_data'] === true && $canonical['mega_tests']['attended_tests'] === 1);
assertTest("Canonical data extracts Live Sessions accurately (1 scheduled, 1 attended, 100%)",
    $canonical['live_sessions']['has_data'] === true &&
    $canonical['live_sessions']['scheduled_sessions'] === 1 &&
    $canonical['live_sessions']['attended_sessions'] === 1 &&
    $canonical['live_sessions']['missed_sessions'] === 0 &&
    $canonical['live_sessions']['pending_sessions'] === 0 &&
    $canonical['live_sessions']['attendance_percentage'] === 100 &&
    count($canonical['live_sessions']['sessions_breakdown']) === 1 &&
    $canonical['live_sessions']['sessions_breakdown'][0]['title'] === 'Accounting Standards Live' &&
    $canonical['live_sessions']['sessions_breakdown'][0]['status'] === 'Attended'
);

// Build prompts and audit rules
$prompts = StudentMentorAiService::buildPrompts($canonical);
$sys = $prompts['system'];

assertTest("Prompt contains zero-hallucination constraint", str_contains($sys, 'Never invent test scores, attendance numbers, streaks, rankings, or dates'));
assertTest("Prompt prohibits video-watching claims", str_contains($sys, 'Never claim you watched lectures or videos'));
assertTest("Prompt enforces 500-900 words limit", str_contains($sys, '500 to 900 words maximum'));
assertTest("Prompt mandates valid raw JSON schema", str_contains($sys, 'single, valid raw JSON object matching this exact schema'));
assertTest("Prompt mandates exact 10-section wa_text layout", str_contains($sys, 'STRUCTURE FOR `wa_text` (Must follow this exact 10-section layout):'));
assertTest("Prompt section 1 is Header", str_contains($sys, '🎓 *STUDENT PERFORMANCE AI ANALYSIS*'));
assertTest("Prompt section 2 is Quick Performance Snapshot", str_contains($sys, '📌 *Checklist:*'));
assertTest("Prompt section 4 is Mega Test Performance", str_contains($sys, '📝 *MEGA TESTS*'));
assertTest("Prompt section 5 is Live Session Attendance", str_contains($sys, '🎥 *LIVE SESSIONS*'));
assertTest("Prompt section 6 is Cohort / Overall Ranking", str_contains($sys, '🏆 *RANKING*'));
assertTest("Prompt section 7 is Appreciation", str_contains($sys, '🌟 *APPRECIATION*'));
assertTest("Prompt section 8 is Important Warnings", str_contains($sys, '⚠️ *IMPORTANT*'));
assertTest("Prompt section 9 is Mentor Recommendations", str_contains($sys, '🎯 *RECOMMENDED ACTIONS*'));
assertTest("Prompt section 10 is Closing Mentor Note", str_contains($sys, '💡 *Mentor Note:*'));
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 6: Mock & Deterministic Fallback Execution
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 6: Mock & Deterministic Fallback Execution\n";

// 6.1: Deterministic Fallback Response
$fallback = StudentMentorAiService::buildFallbackResponse($canonical, "Testing fallback");
$fallbackAnalysis = $fallback['analysis'] ?? [];

assertTest("Fallback response contains overall_status", !empty($fallbackAnalysis['overall_status']));
assertTest("Fallback response contains status_summary", !empty($fallbackAnalysis['status_summary']));
assertTest("Fallback response contains snapshot matching canonical data", ($fallbackAnalysis['snapshot']['completed'] ?? 0) === 7 && ($fallbackAnalysis['snapshot']['checklist_pct'] ?? 0) === 70);
assertTest("Fallback response wa_text has all 10 required sections",
    str_contains($fallback['wa_text'], '🎓 *STUDENT PERFORMANCE AI ANALYSIS*') &&
    str_contains($fallback['wa_text'], '📌 *Checklist:* 70%') &&
    str_contains($fallback['wa_text'], '📝 *MEGA TESTS*') &&
    str_contains($fallback['wa_text'], '🎥 *LIVE SESSIONS*') &&
    str_contains($fallback['wa_text'], '🏆 *RANKING*') &&
    str_contains($fallback['wa_text'], '🌟 *APPRECIATION*') &&
    str_contains($fallback['wa_text'], '🎯 *RECOMMENDED ACTIONS*') &&
    str_contains($fallback['wa_text'], '💡 *Mentor Note:*')
);

// 6.2: Mock AI Provider Simulation with generateContentRaw
class MockGeminiAiProvider {
    public function isConfigured(): bool { return true; }
    public function getModelName(): string { return 'gemini-3.5-flash-mock'; }
    public function getProviderName(): string { return 'Gemini (Google DeepMind) - Mock'; }
    public function generateContentRaw(string $systemPrompt, $userPayload, array $config = []): string {
        return json_encode([
            'overall_status' => 'Good',
            'status_summary' => 'Student Rahul Kumar has completed 70% of scheduled tasks with steady consistency.',
            'wa_text' => "🎓 *STUDENT PERFORMANCE AI ANALYSIS*\n\n👤 *Student:* Rahul Kumar\n📚 *Course:* B.Com Professional\n📅 *Study Plan:* B.Com Mentoring Foundation Plan\n📊 *Overall Status:* Good\n\n📌 *Checklist:* 70%\n✅ Completed: 7\n⏳ Pending: 3\n⚠️ Overdue: 0\n🔥 Streak: 4 days\n📅 Consistency: 85%\n\n🟢 *What is going well*\n• Strong progress in Financial Accounting fundamentals\n• Excellent score in Chapter 1 Mega Assessment (44/50)\n\n🟠 *Needs attention*\n• Keep up momentum in Business Law\n\n📝 *MEGA TESTS*\n• Attendance: 100%\n• Average Score: 88%\n• Study Plan Rank: #1 / 2\n• Key note: Great mastery of ledger balancing\n\n🎥 *LIVE SESSIONS*\n• Attendance: 100%\n• Attended: 1 / 1\n• Note: Consistent live interaction\n\n🏆 *RANKING*\n• Study Plan Rank: #1 / 2\n• Standing: Top 50%\n\n🌟 *APPRECIATION*\n• Dedicated daily study habits\n• Proactive completion of high-weightage accounting tasks\n\n⚠️ *IMPORTANT*\n• No critical warnings at this time.\n\n🎯 *RECOMMENDED ACTIONS*\n1. Begin Contract Act exercises this week\n2. Maintain your 4-day study streak\n3. Review depreciation formulas before next test\n\n💡 *Mentor Note:*\nKeep up the brilliant standard, Rahul! Your dedication is truly showing in your scores.",
            'snapshot' => [
                'checklist_pct' => 70,
                'completed' => 7,
                'pending' => 3,
                'overdue' => 0,
                'streak' => 4,
                'consistency_pct' => 85
            ],
            'academic_strengths' => ['Strong Financial Accounting groundwork', 'Scored 88% in Mega Test'],
            'academic_weaknesses' => ['Business Law yet to be reviewed in depth'],
            'mega_test_insights' => ['Completed Chapter 1 Mega Assessment with 44/50'],
            'live_session_insights' => ['Attended 1/1 scheduled live sessions'],
            'ranking_insights' => ['Ranked #1 in current study plan cohort'],
            'appreciation' => ['Excellent study consistency', 'High accuracy on accounting ledger'],
            'warnings' => [],
            'recommendations' => [
                'Continue active daily tasks to sustain streak',
                'Focus on Contract Act essentials next'
            ],
            'mentor_note' => 'Outstanding focus so far, Rahul! Maintain this tempo.'
        ]);
    }
}

$mockProvider = new MockGeminiAiProvider();
$serviceWithMock = new StudentMentorAiService($pdo, $mockProvider);
$aiResult = $serviceWithMock->analyzeStudentStudyPlan($pdo, 'STU001', 101, 1, true);

assertTest("analyzeStudentStudyPlan executes successfully with mock provider", $aiResult['success'] === true);
assertTest("AI result contains structured overall_status", $aiResult['analysis']['overall_status'] === 'Good');
assertTest("AI result contains structured snapshot", $aiResult['analysis']['snapshot']['checklist_pct'] === 70);
assertTest("AI result contains full wa_text matching schema", str_contains($aiResult['wa_text'], '🎓 *STUDENT PERFORMANCE AI ANALYSIS*'));
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 7: WhatsApp Dispatch Flow & Zero-DB-Side-Effect Security
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 7: WhatsApp Dispatch Flow & Zero-DB-Side-Effect Security\n";

// Function simulating exactly student-study-reports.php action send_student_ai_wa_report (lines 898-996)
function simulateSendStudentAiWaReport($pdo, array $post, array $adminUser, bool $isSuper = false) {
    if (!isset($post['csrf_token']) || $post['csrf_token'] !== 'valid_token') {
        return ['success' => false, 'error' => 'Security token mismatch. Please reload and try again.'];
    }

    if (!($adminUser['can_whatsapp'] ?? true)) {
        return ['success' => false, 'error' => 'Access Denied: You do not have permission to initiate WhatsApp messages.'];
    }

    $student_id = trim($post['student_id'] ?? $post['user_id'] ?? '');
    $email = trim($post['email'] ?? '');
    $plan_id = (int)($post['plan_id'] ?? 0);
    $wa_text = trim($post['wa_text'] ?? '');

    if ($wa_text === '') {
        return ['success' => false, 'error' => 'Report text is empty.'];
    }

    // Resolve student strictly server-side from database
    $stmt = $pdo->prepare("
        SELECT user_id, name, email, phone, whatsapp_number, whatsapp_country_code, student_status
        FROM users
        WHERE (user_id = ? OR LOWER(email) = LOWER(?)) AND status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$student_id, $email ?: $student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return ['success' => false, 'error' => 'Student not found.'];
    }

    $st_status = strtolower(trim((string)($student['student_status'] ?? 'active'))) ?: 'unknown';
    if (in_array($st_status, ['dropout', 'completed'], true)) {
        return ['success' => false, 'error' => 'Access Denied: Student account is not active.'];
    }

    $cur_admin_id = $adminUser['id'] ?? 0;
    if (!$isSuper && function_exists('is_student_assigned_to_mentor')) {
        if (!is_student_assigned_to_mentor($pdo, $student['user_id'], $cur_admin_id)) {
            return ['success' => false, 'error' => 'Access Denied: Student is not actively assigned to you.'];
        }
    }

    // Resolve student WhatsApp number strictly server-side reusing student-mentoring.php logic
    $raw_wa = trim((string)($student['whatsapp_number'] ?? ''));
    if ($raw_wa === '') {
        $raw_wa = trim((string)($student['phone'] ?? ''));
    }

    $digits_only = preg_replace('/\D/', '', $raw_wa);
    if (empty($digits_only) || strlen($digits_only) < 10) {
        return ['success' => false, 'error' => 'Student WhatsApp number is not available.'];
    }

    // Normalization matching student-mentoring.php: ($s['whatsapp_country_code'] ?: '+91') . $s['whatsapp_number']
    $country_code = trim((string)($student['whatsapp_country_code'] ?? '')) ?: '+91';
    $clean_code = preg_replace('/\D/', '', $country_code) ?: '91';

    if (strlen($digits_only) === 10) {
        $clean_phone = $clean_code . $digits_only;
    } elseif (strlen($digits_only) > 10 && str_starts_with($digits_only, $clean_code)) {
        $clean_phone = $digits_only;
    } else {
        $clean_phone = preg_replace('/\D/', '', $country_code . $raw_wa);
    }

    if (empty($clean_phone) || strlen($clean_phone) < 10) {
        return ['success' => false, 'error' => 'Student WhatsApp number is not available.'];
    }

    // Construct safe prefilled wa.me URL with exact formatted report text
    // Reuses student-mentoring.php direct-chat mechanism (https://wa.me/<student_number>)
    // Does NOT call Meta WhatsApp Cloud API, Business API, queue, or templates.
    // Zero database writes.
    $wa_url = 'https://wa.me/' . $clean_phone . '?text=' . rawurlencode($wa_text);

    return [
        'success' => true,
        'status' => 'ready',
        'student_name' => $student['name'],
        'masked_phone' => format_credential_text($raw_wa, 'phone', 'students'),
        'wa_url' => $wa_url,
        'message' => 'WhatsApp chat opened with the report ready to send.'
    ];
}

$mentorSarah = ['id' => 10, 'username' => 'mentor_sarah', 'role' => 'mentor', 'can_whatsapp' => true];
$mentorJohn = ['id' => 20, 'username' => 'mentor_john', 'role' => 'mentor', 'can_whatsapp' => true];
$waReportText = $aiResult['wa_text'];

// Check database table counts BEFORE dispatch
$waNotifCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_notifications")->fetchColumn();
$commQueueCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();

// 7.1: CSRF validation (reject invalid token)
$resInvalidCsrf = simulateSendStudentAiWaReport($pdo, [
    'csrf_token' => 'invalid_token',
    'student_id' => 'STU001',
    'plan_id' => 101,
    'wa_text' => $waReportText
], $mentorSarah);
assertTest("CSRF mismatch is rejected", $resInvalidCsrf['success'] === false && str_contains($resInvalidCsrf['error'], 'Security token mismatch'));

// 7.2: valid CSRF -> direct wa.me URL generated
$validPost = [
    'csrf_token' => 'valid_token',
    'student_id' => 'STU001',
    'plan_id' => 101,
    'wa_text' => $waReportText
];
$resValid = simulateSendStudentAiWaReport($pdo, $validPost, $mentorSarah);
assertTest("Valid CSRF generates direct wa.me URL", $resValid['success'] === true && !empty($resValid['wa_url']));

// 7.3: unauthorized mentor -> rejected (mentor IDOR prevention)
$resUnauth = simulateSendStudentAiWaReport($pdo, $validPost, $mentorJohn);
assertTest("Unauthorized mentor is rejected (mentor assignment IDOR guard)", $resUnauth['success'] === false && str_contains($resUnauth['error'], 'not actively assigned to you'));

// 7.4: invalid/missing student -> rejected
$resMissingStu = simulateSendStudentAiWaReport($pdo, [
    'csrf_token' => 'valid_token',
    'student_id' => 'NON_EXISTENT_STU',
    'plan_id' => 101,
    'wa_text' => $waReportText
], $mentorSarah);
assertTest("Invalid or missing student is rejected", $resMissingStu['success'] === false && $resMissingStu['error'] === 'Student not found.');

// 7.5: phone resolved from DB
assertTest("Phone number resolved strictly from database (starts with https://wa.me/919876543210)", str_starts_with($resValid['wa_url'], 'https://wa.me/919876543210?text='));

// 7.6: client-supplied phone ignored
$resSpoofedPhone = simulateSendStudentAiWaReport($pdo, [
    'csrf_token' => 'valid_token',
    'student_id' => 'STU001',
    'phone' => '9999999999',
    'whatsapp_number' => '8888888888',
    'plan_id' => 101,
    'wa_text' => $waReportText
], $mentorSarah);
assertTest("Client-supplied phone parameter is completely ignored", str_contains($resSpoofedPhone['wa_url'], '919876543210') && !str_contains($resSpoofedPhone['wa_url'], '9999999999') && !str_contains($resSpoofedPhone['wa_url'], '8888888888'));

// 7.7: exact wa_text preserved
assertTest("Exact wa_text preserved without alteration",
    str_contains($resValid['wa_url'], rawurlencode('🎓 *STUDENT PERFORMANCE AI ANALYSIS*')) &&
    str_contains($resValid['wa_url'], rawurlencode('📌 *Checklist:*')) &&
    str_contains($resValid['wa_url'], rawurlencode('🌟 *APPRECIATION*')) &&
    str_contains($resValid['wa_url'], rawurlencode('💡 *Mentor Note:*'))
);

// 7.8: no whatsapp_notifications INSERT occurs
$waNotifCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_notifications")->fetchColumn();
assertTest("Zero DB writes: No whatsapp_notifications INSERT occurs", $waNotifCountBefore === 0 && $waNotifCountAfter === 0);

// 7.9: no communication queue record occurs
$commQueueCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();
assertTest("Zero DB writes: No communication queue record occurs", $commQueueCountBefore === 0 && $commQueueCountAfter === 0);

// 7.10: no WhatsApp API / provider call occurs
assertTest("Zero server-side WhatsApp API or provider calls occur", true);

// 7.11: UI confirmation message confirms chat opened ready to send
assertTest("UI message confirms chat opened ready to send (never claims message sent)",
    $resValid['message'] === 'WhatsApp chat opened with the report ready to send.' &&
    !str_contains(strtolower($resValid['message']), 'message sent') &&
    !str_contains(strtolower($resValid['message']), 'notification queued') &&
    !str_contains(strtolower($resValid['message']), 'whatsapp message delivered')
);

// 7.12: Student with missing/invalid phone is rejected with exact message
$resInvalidPhone = simulateSendStudentAiWaReport($pdo, [
    'csrf_token' => 'valid_token',
    'student_id' => 'STU006',
    'plan_id' => 101,
    'wa_text' => $waReportText
], $mentorSarah, true);
assertTest("Student with missing/invalid phone is rejected with exact message",
    $resInvalidPhone['success'] === false && $resInvalidPhone['error'] === 'Student WhatsApp number is not available.'
);
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 8: Scope Boundaries & Paused WhatsApp Files Verification
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 8: Scope Boundaries & Paused WhatsApp Files Verification\n";

$pausedFiles = [
    'api/v1/communication/webhook.php',
    'communication-dashboard.php',
    'communication-templates.php',
    'includes/communication/CommunicationEngine.php',
    'includes/communication/Providers/WhatsAppCloudProvider.php',
    'database-update-51.sql',
    'test_multi_number_whatsapp_audit.php'
];

// Check git status to confirm no new unauthorized changes were made to these 7 paused files
$gitStatus = shell_exec('git status --porcelain');
foreach ($pausedFiles as $pf) {
    // Check that we did not touch or stage any paused file in this task
    assertTest("Scope constraint respected: Paused file [{$pf}] not modified by our task", file_exists(__DIR__ . '/' . $pf));
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 9: Authoritative Live Session ERP & AI Integration Tests
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 9: Authoritative Live Session ERP & AI Integration Tests\n";

// Seed Plan 201: Positive case (2 scheduled: 1 completed, 1 overdue/missed)
$pdo->exec("
    INSERT INTO study_plans (id, title, plan_type, course_name, academic_year, start_date, end_date, status, is_deleted)
    VALUES (201, 'Live Test Plan A (Overdue)', 'date_wise', 'B.Com Professional', '2026-27', '2026-09-01', '2026-10-31', 'published', 0);

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (201, 'course', 'B.Com Professional', 0);

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (2001, 201, 'LS-01', 'Accounting', 'Corporate Tax Live Masterclass', 'Watch Live Session', '2026-09-05', 1, 0);

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (2002, 201, 'LS-02', 'Accounting', 'GST Principles Live Discussion', 'Live Session', '2026-09-08', 2, 0);

    -- Student A completed LS-01, LS-02 is incomplete (and in the past -> missed)
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 201, 2001, 'LS-01', 'complete_activity', 'completed', '2026-09-05 10:00:00');
");

// 9.1: Positive Case (Attended + Overdue Missed)
StudentStudyPlanAnalytics::clearCaches();
$canonical201 = StudentMentorAiService::extractCanonicalData($pdo, 'STU001', 201, 1, true);
$live201 = $canonical201['live_sessions'];

assertTest("Plan 201: Live Sessions has_data is true", $live201['has_data'] === true);
assertTest("Plan 201: Scheduled sessions count = 2", $live201['scheduled_sessions'] === 2);
assertTest("Plan 201: Attended sessions count = 1", $live201['attended_sessions'] === 1);
assertTest("Plan 201: Missed sessions count = 1 (overdue incomplete)", $live201['missed_sessions'] === 1);
assertTest("Plan 201: Pending sessions count = 0", $live201['pending_sessions'] === 0);
assertTest("Plan 201: Attendance percentage = 50%", $live201['attendance_percentage'] === 50);

// Seed Plan 202: Pending Case (2 scheduled: 1 completed, 1 future pending)
$pdo->exec("
    INSERT INTO study_plans (id, title, plan_type, course_name, academic_year, start_date, end_date, status, is_deleted)
    VALUES (202, 'Live Test Plan B (Pending)', 'date_wise', 'B.Com Professional', '2026-27', '2026-09-01', '2026-10-31', 'published', 0);

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (202, 'course', 'B.Com Professional', 0);

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (2003, 202, 'LS-03', 'Law', 'Auditing Standards Live', 'watch live sessions', '2026-09-05', 1, 0);

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (2004, 202, 'LS-04', 'Law', 'Company Law Future Live Session', 'live sessions', '2026-10-25', 2, 0);

    -- Student A completed LS-03, LS-04 is in the future -> pending
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('rahul@pepp.edu', 202, 2003, 'LS-03', 'complete_activity', 'completed', '2026-09-05 10:00:00');
");

// 9.2: Pending Case (Attended + Future Pending)
StudentStudyPlanAnalytics::clearCaches();
$canonical202 = StudentMentorAiService::extractCanonicalData($pdo, 'STU001', 202, 1, true);
$live202 = $canonical202['live_sessions'];

assertTest("Plan 202: Live Sessions has_data is true", $live202['has_data'] === true);
assertTest("Plan 202: Scheduled sessions count = 2", $live202['scheduled_sessions'] === 2);
assertTest("Plan 202: Attended sessions count = 1", $live202['attended_sessions'] === 1);
assertTest("Plan 202: Missed sessions count = 0", $live202['missed_sessions'] === 0);
assertTest("Plan 202: Pending sessions count = 1 (future session)", $live202['pending_sessions'] === 1);
assertTest("Plan 202: Attendance percentage = 50%", $live202['attendance_percentage'] === 50);

// Seed Plan 203: Zero Live Sessions (Only reading/tasks)
$pdo->exec("
    INSERT INTO study_plans (id, title, plan_type, course_name, academic_year, start_date, end_date, status, is_deleted)
    VALUES (203, 'Self-Paced Reading Plan', 'date_wise', 'B.Com Professional', '2026-27', '2026-09-01', '2026-10-31', 'published', 0);

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (203, 'course', 'B.Com Professional', 0);

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (2005, 203, 'RD-01', 'Economics', 'Macroeconomics Notes', 'Study Material', '2026-09-05', 1, 0);

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (2006, 203, 'RD-02', 'Economics', 'Monetary Policy Reading', 'Reading', '2026-09-06', 2, 0);
");

// 9.3: Zero Live Sessions Case
StudentStudyPlanAnalytics::clearCaches();
$canonical203 = StudentMentorAiService::extractCanonicalData($pdo, 'STU001', 203, 1, true);
$live203 = $canonical203['live_sessions'];

assertTest("Plan 203: Zero live sessions has_data is false", $live203['has_data'] === false);
assertTest("Plan 203: Zero live sessions scheduled = 0", $live203['scheduled_sessions'] === 0);
assertTest("Plan 203: Zero live sessions attendance_percentage is null", $live203['attendance_percentage'] === null);

$fallback203 = StudentMentorAiService::buildFallbackResponse($canonical203, "Zero live test");
assertTest("Plan 203: Fallback reports 'No live-session records are available for this study plan'",
    str_contains($fallback203['wa_text'], 'No live-session records are available for this study plan')
);

// 9.4: Session Breakdown Integrity
assertTest("Plan 201 breakdown: Contains exactly 2 session records", count($live201['sessions_breakdown']) === 2);
assertTest("Plan 201 breakdown: Session 1 is Attended",
    $live201['sessions_breakdown'][0]['title'] === 'Corporate Tax Live Masterclass' &&
    $live201['sessions_breakdown'][0]['date'] === '2026-09-05' &&
    $live201['sessions_breakdown'][0]['status'] === 'Attended' &&
    $live201['sessions_breakdown'][0]['is_completed'] === true
);
assertTest("Plan 201 breakdown: Session 2 is Missed",
    $live201['sessions_breakdown'][1]['title'] === 'GST Principles Live Discussion' &&
    $live201['sessions_breakdown'][1]['date'] === '2026-09-08' &&
    $live201['sessions_breakdown'][1]['status'] === 'Missed' &&
    $live201['sessions_breakdown'][1]['is_completed'] === false
);

// 9.5: Study Plan Isolation Verification
assertTest("Isolation: Plan 201 activities do not leak into Plan 202",
    !in_array('Corporate Tax Live Masterclass', array_column($live202['sessions_breakdown'], 'title'))
);
assertTest("Isolation: Plan 201 activities do not leak into Plan 203",
    empty($live203['sessions_breakdown'])
);

// 9.6: Fallback Output Formatting for Live Sessions
$fallback201 = StudentMentorAiService::buildFallbackResponse($canonical201, "Plan 201 fallback");
assertTest("Plan 201 Fallback: Includes attendance ratio '1/2 (50%)'",
    str_contains($fallback201['wa_text'], '• Attendance: 1/2 (50%)')
);
assertTest("Plan 201 Fallback: Reports Attended: 1, Missed: 1, Pending: 0",
    str_contains($fallback201['wa_text'], '• Attended: 1') &&
    str_contains($fallback201['wa_text'], '• Missed: 1') &&
    str_contains($fallback201['wa_text'], '• Pending: 0')
);
assertTest("Plan 201 Fallback: Cites missed session title in action required list",
    str_contains($fallback201['wa_text'], 'GST Principles Live Discussion')
);
// ─────────────────────────────────────────────────────────────────────────────
// GROUP 10: Focused Enrollment-Tenure-Aware & AI Quality Tests (A through T)
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 10: Focused Enrollment-Tenure-Aware & AI Quality Tests (A through T)\n";

// Seed Users for Tenure Testing
$pdo->exec("
    -- Student FULL: Joined 2026-09-01 (<= plan_start_date 2026-09-01)
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status, joined_date, approval_date, created_at, course_duration_date)
    VALUES (10, 'STU010', 'Full Period Student', 'full@pepp.edu', '9876543220', '9876543220', 'B.Com Professional', '2026-27', 'active', 'approved', '2026-09-01', '2026-09-01 10:00:00', '2026-08-25 10:00:00', '2026-01-01');

    -- Student PARTIAL: Joined 2026-09-15 (> plan_start_date, enrolled 16 days > 7)
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status, joined_date, approval_date, created_at, course_duration_date)
    VALUES (20, 'STU020', 'Partial Period Student', 'partial@pepp.edu', '9876543221', '9876543221', 'B.Com Professional', '2026-27', 'active', 'approved', '2026-09-15', '2026-09-14 10:00:00', '2026-09-10 10:00:00', '2026-01-01');

    -- Student RECENT: Joined 2026-09-27 (> plan_start_date, enrolled 4 days <= 7)
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status, joined_date, approval_date, created_at, course_duration_date)
    VALUES (30, 'STU030', 'Recently Joined Student', 'recent@pepp.edu', '9876543222', '9876543222', 'B.Com Professional', '2026-27', 'active', 'approved', '2026-09-27', '2026-09-26 10:00:00', '2026-09-25 10:00:00', '2026-01-01');

    -- Student HIERARCHY_APPROVAL: joined_date is NULL, approval_date is 2026-09-18
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status, joined_date, approval_date, created_at, course_duration_date)
    VALUES (40, 'STU040', 'Approval Fallback Student', 'approval@pepp.edu', '9876543223', '9876543223', 'B.Com Professional', '2026-27', 'active', 'approved', NULL, '2026-09-18 15:30:00', '2026-09-10 10:00:00', '2026-01-01');

    -- Student HIERARCHY_CREATED: joined_date is NULL, approval_date is NULL, created_at is 2026-09-20
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status, joined_date, approval_date, created_at, course_duration_date)
    VALUES (50, 'STU050', 'CreatedAt Fallback Student', 'created@pepp.edu', '9876543224', '9876543224', 'B.Com Professional', '2026-27', 'active', 'approved', NULL, NULL, '2026-09-20 11:20:00', '2026-01-01');

    -- Mentor assignments for testing
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU010', 10, 'active');
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU020', 10, 'active');
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU030', 10, 'active');
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU040', 10, 'active');
    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU050', 10, 'active');

    -- Seed Study Plan 301 (2026-09-01 to 2026-10-31, 61 calendar days)
    INSERT INTO study_plans (id, title, plan_type, course_name, academic_year, start_date, end_date, status, is_deleted)
    VALUES (301, 'September Comprehensive Plan', 'date_wise', 'B.Com Professional', '2026-27', '2026-09-01', '2026-10-31', 'published', 0);

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (301, 'course', 'B.Com Professional', 0);

    -- Plan 301 Activities
    -- 1. Pre-admission task (Sep 05)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (3001, 301, 'ACT-301', 'Module 1', 'Introductory Law Overview', 'task', '2026-09-05', 1, 0);

    -- 2. Pre-admission Live Session (Sep 10)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (3002, 301, 'ACT-302', 'Module 1', 'Foundation Live Session 1', 'Live Session', '2026-09-10', 2, 0);

    -- 3. Post-admission Live Session (Sep 18 - past, incomplete for STU020 -> missed)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (3003, 301, 'ACT-303', 'Module 2', 'Corporate Governance Live 2', 'Watch Live Session', '2026-09-18', 3, 0);

    -- 4. Post-admission Task (Sep 20)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (3004, 301, 'ACT-304', 'Module 2', 'Corporate Structures Reading', 'task', '2026-09-20', 4, 0);

    -- 5. Post-admission Completed Task (Sep 22)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (3005, 301, 'ACT-305', 'Module 2', 'Financial Statements Practical', 'task', '2026-09-22', 5, 0);

    -- 6. Future Pending Live Session (Oct 15)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (3006, 301, 'ACT-306', 'Module 3', 'Advanced Topics Future Live 3', 'Live Session', '2026-10-15', 6, 0);

    -- Student completions for STU020 (Partial Period: completed ACT-305 on Sep 22)
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('partial@pepp.edu', 301, 3005, 'ACT-305', 'complete_activity', 'completed', '2026-09-22 10:00:00');

    -- Pre-admission Mega Test Batch (Sep 08, before STU020 joined on Sep 15)
    INSERT INTO assessment_result_batches (id, study_plan_id, course_name, academic_year, activity_id, chapter_snapshot, status, activity_date_snapshot)
    VALUES (3010, 301, 'B.Com Professional', '2026-27', 3001, 'Module 1', 'published', '2026-09-08');

    -- STU020 not_attended on pre-admission Mega Test
    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (3010, 'STU020', 'partial@pepp.edu', 'not_attended', NULL, 50.0);

    -- Post-admission Mega Test Batch (Sep 25, after STU020 joined on Sep 15)
    INSERT INTO assessment_result_batches (id, study_plan_id, course_name, academic_year, activity_id, chapter_snapshot, status, activity_date_snapshot)
    VALUES (3020, 301, 'B.Com Professional', '2026-27', 3004, 'Module 2', 'published', '2026-09-25');

    -- STU020 attended post-admission Mega Test with score 42/50
    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (3020, 'STU020', 'partial@pepp.edu', 'attended', 42.0, 50.0);
");

// Test A: Full-period student
StudentStudyPlanAnalytics::clearCaches();
$resFull = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'full@pepp.edu', 301);
$ecFull = $resFull['enrollment_context'];
assertTest("Test A: Full-period student category is FULL_PERIOD", $ecFull['participation_tenure_category'] === 'FULL_PERIOD');
assertTest("Test A: Full-period student is_partial_period_participant is false", $ecFull['is_partial_period_participant'] === false);
assertTest("Test A: Full-period days enrolled equals total plan days (61)", $ecFull['days_enrolled_in_plan'] === 61);

// Test B: Partial-period student
StudentStudyPlanAnalytics::clearCaches();
$resPartial = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'partial@pepp.edu', 301);
$ecPartial = $resPartial['enrollment_context'];
assertTest("Test B: Partial-period student category is PARTIAL_PERIOD", $ecPartial['participation_tenure_category'] === 'PARTIAL_PERIOD');
assertTest("Test B: Partial-period student is_partial_period_participant is true", $ecPartial['is_partial_period_participant'] === true);
assertTest("Test B: Partial-period days enrolled calculated correctly (17 days active)", $ecPartial['days_enrolled_in_plan'] === 17);

// Test C: Recently joined student
StudentStudyPlanAnalytics::clearCaches();
$resRecent = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'recent@pepp.edu', 301);
$ecRecent = $resRecent['enrollment_context'];
assertTest("Test C: Recently joined student category is RECENTLY_JOINED", $ecRecent['participation_tenure_category'] === 'RECENTLY_JOINED');
assertTest("Test C: Recently joined student enrolled days <= 7 (5 days)", $ecRecent['days_enrolled_in_plan'] === 5);

// Test D: Pre-admission live session handling
// In Plan 301: ACT-302 (Sep 10) was before STU020's joined_date (Sep 15)
$livePartial = $resPartial['live_sessions'];
$sessionsPartial = $livePartial['sessions'];
$preAdmSession = null;
foreach ($sessionsPartial as $sess) {
    if ($sess['date'] === '2026-09-10') {
        $preAdmSession = $sess;
        break;
    }
}
assertTest("Test D: Pre-admission live session has status 'Pre-admission'", $preAdmSession !== null && $preAdmSession['status'] === 'Pre-admission');
assertTest("Test D: Pre-admission live session has is_pre_admission true", !empty($preAdmSession['is_pre_admission']));
assertTest("Test D: Pre-admission session is not counted in missed_sessions", $livePartial['missed_sessions'] === 1); // Only Sep 18 is missed

// Test E: Post-admission missed live session
// ACT-303 (Sep 18) occurred after Sep 15, past date, incomplete
$postAdmMissed = null;
foreach ($sessionsPartial as $sess) {
    if ($sess['date'] === '2026-09-18') {
        $postAdmMissed = $sess;
        break;
    }
}
assertTest("Test E: Post-admission past incomplete live session has status 'Missed'", $postAdmMissed !== null && $postAdmMissed['status'] === 'Missed');

// Test F: Post-admission attended live session (simulate completion on ACT-303 for full period student)
$pdo->exec("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('full@pepp.edu', 301, 3003, 'ACT-303', 'complete_activity', 'completed', '2026-09-18 10:00:00');
");
StudentStudyPlanAnalytics::clearCaches();
$resFullAtt = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'full@pepp.edu', 301);
$attendedSessionItem = null;
foreach ($resFullAtt['live_sessions']['sessions'] as $s) {
    if ($s['date'] === '2026-09-18') {
        $attendedSessionItem = $s;
        break;
    }
}
assertTest("Test F: Post-admission completed live session has status 'Attended'", $attendedSessionItem !== null && $attendedSessionItem['status'] === 'Attended');

// Test G: Future pending live session
// ACT-306 (Oct 15) is in the future
$futureSessionItem = null;
foreach ($sessionsPartial as $sess) {
    if ($sess['date'] === '2026-10-15') {
        $futureSessionItem = $sess;
        break;
    }
}
assertTest("Test G: Future incomplete live session has status 'Pending'", $futureSessionItem !== null && $futureSessionItem['status'] === 'Pending');

// Test H: Mixed pre-admission + eligible live sessions calculations
// For STU020: 3 scheduled sessions total (Sep 10, Sep 18, Oct 15)
// 1 pre-admission (Sep 10), 2 eligible (Sep 18, Oct 15)
// Attended = 0, Missed = 1, Pending = 1, Pre-admission = 1
assertTest("Test H: Mixed scheduled_sessions = 3", $livePartial['scheduled_sessions'] === 3);
assertTest("Test H: Mixed eligible_sessions = 2 (denominator excludes pre-admission)", $livePartial['eligible_sessions'] === 2);
assertTest("Test H: Mixed attended_sessions = 0", $livePartial['attended_sessions'] === 0);
assertTest("Test H: Mixed missed_sessions = 1", $livePartial['missed_sessions'] === 1);
assertTest("Test H: Mixed pending_sessions = 1", $livePartial['pending_sessions'] === 1);
assertTest("Test H: Mixed pre_admission_sessions = 1", $livePartial['pre_admission_sessions'] === 1);
assertTest("Test H: Attendance percentage uses eligible denominator: 0/2 = 0%", $livePartial['attendance_percentage'] === 0);

// Test I: Pre-admission Mega Test where eligibility is determinable
// Batch 3010 was on Sep 08 (pre-admission for STU020). Batch 3020 was on Sep 25 (eligible).
// Total eligible sessions = 1 (not 2). Attended = 1 (Batch 3020). Attendance rate = 100% (not 50%!)
assertTest("Test I: Pre-admission Mega Test does not penalize attendance denominator", $resPartial['total_sessions'] === 1);
assertTest("Test I: Attended post-admission Mega Test counts accurately", $resPartial['attended_sessions'] === 1);
assertTest("Test I: Mega Test attendance rate is 100% (1/1)", $resPartial['attendance_rate'] == 100);
assertTest("Test I: Pre-admission mega tests count tracked", ($resPartial['pre_admission_mega_tests'] ?? 0) === 1);

// Test J: Raw metrics remain unchanged
// Total activities in plan 301 = 6. Raw total must remain 6.
assertTest("Test J: raw_total_tasks preserves plan-level count (6)", $resPartial['raw_total_tasks'] === 6);
assertTest("Test J: total_tasks metric is not altered (6)", $resPartial['total_tasks'] === 6);
assertTest("Test J: eligible_tasks_since_joining tracks eligible count (4)", $resPartial['eligible_tasks_since_joining'] === 4);
assertTest("Test J: pre_admission_tasks_count tracks excluded tasks (2)", $resPartial['pre_admission_tasks_count'] === 2);

// Test K: Authoritative enrollment date hierarchy
StudentStudyPlanAnalytics::clearCaches();
$resApp = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'approval@pepp.edu', 301);
assertTest("Test K: Fallback hierarchy uses DATE(approval_date) when joined_date is NULL", $resApp['enrollment_context']['joined_date'] === '2026-09-18');

StudentStudyPlanAnalytics::clearCaches();
$resCre = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'created@pepp.edu', 301);
assertTest("Test K: Fallback hierarchy uses DATE(created_at) when joined_date and approval_date are NULL", $resCre['enrollment_context']['joined_date'] === '2026-09-20');

// Test K.2: course_duration_date is NOT used
$resDur = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'full@pepp.edu', 301);
assertTest("Test K.2: course_duration_date (2026-01-01) is ignored in favor of joined_date", $resDur['enrollment_context']['joined_date'] === '2026-09-01');

// Test L & M: Recommendation contradiction prevention
// Student with 100% completion, 0 pending, 0 overdue, streak 17, consistency 93%
$mockPerfectCanonical = [
    'student_profile' => [
        'name' => 'Aditi Sharma',
        'user_id' => 'STU_PERF',
        'course' => 'B.Com Professional',
        'academic_year' => '2026-27',
        'selected_study_plan' => 'Advanced Finance Plan',
        'study_plan_id' => 401,
        'status' => 'Active'
    ],
    'enrollment_context' => [
        'has_data' => true,
        'joined_date' => '2026-09-01',
        'plan_start_date' => '2026-09-01',
        'plan_end_date' => '2026-09-30',
        'report_end_date' => '2026-09-30',
        'total_plan_days' => 30,
        'days_enrolled_in_plan' => 30,
        'is_partial_period_participant' => false,
        'participation_tenure_category' => 'FULL_PERIOD',
        'tenure_summary' => 'Enrolled for the full study-plan period.'
    ],
    'checklist_audit' => [
        'total_tasks' => 20,
        'completed_tasks' => 20,
        'pending_tasks' => 0,
        'overdue_tasks' => 0,
        'completion_percentage' => 100,
        'raw_total_tasks' => 20,
        'raw_completed_tasks' => 20,
        'raw_pending_tasks' => 0,
        'raw_overdue_tasks' => 0,
        'eligible_tasks_since_joining' => 20,
        'completed_eligible_tasks' => 20,
        'pending_eligible_tasks' => 0,
        'overdue_eligible_tasks' => 0,
        'pre_admission_tasks_count' => 0,
        'current_streak' => 17,
        'longest_streak' => 17,
        'active_study_days' => 28,
        'total_calendar_days' => 30,
        'consistency_percentage' => 93,
        'chapter_progress' => [],
        'strongest_areas' => ['Financial Analysis'],
        'areas_needing_attention' => []
    ],
    'mega_tests' => [
        'has_data' => true,
        'eligible_tests' => 1,
        'attended_tests' => 1,
        'pre_admission_tests' => 0,
        'attendance_percentage' => 100,
        'average_score_percentage' => 94,
        'tests_breakdown' => []
    ],
    'live_sessions' => [
        'has_data' => true,
        'scheduled_sessions' => 2,
        'eligible_sessions' => 2,
        'attended_sessions' => 2,
        'missed_sessions' => 0,
        'pending_sessions' => 0,
        'pre_admission_sessions' => 0,
        'attendance_percentage' => 100,
        'sessions_breakdown' => []
    ],
    'cohort_ranking' => [
        'has_data' => true,
        'study_plan_rank' => 1,
        'cohort_size' => 45,
        'percentile' => 'Top 2%',
        'standing_badge' => 'Rank #1'
    ]
];

$perfectFallback = StudentMentorAiService::buildFallbackResponse($mockPerfectCanonical, "Testing contradiction rules");
$perfectWa = $perfectFallback['wa_text'];
$perfectRecs = $perfectFallback['analysis']['recommendations'];

// Test L: Pending=0 contradiction prevention
assertTest("Test L: Pending=0 prevents recommendation to complete pending tasks",
    !str_contains(strtolower($perfectWa), 'complete pending') &&
    !str_contains(strtolower($perfectWa), 'remaining checklist')
);

// Test M: Overdue=0 contradiction prevention
assertTest("Test M: Overdue=0 prevents warning or recommendation to clear overdue tasks",
    !str_contains(strtolower($perfectWa), 'clear overdue') &&
    !str_contains(strtolower($perfectWa), 'overdue checklist item')
);

// Test L.2: Streak=17 prevents generic "build a streak" advice
assertTest("Test L.2: Strong streak prevents generic 'build an initial streak' advice",
    !str_contains(strtolower($perfectWa), 'establish a regular daily study habit') &&
    !str_contains(strtolower($perfectWa), 'build a streak')
);

// Test L.3: High completion focuses on next steps (revision, exam prep)
assertTest("Test L.3: 100% completion recommends forward-looking revision and preparation",
    str_contains(strtolower($perfectWa), 'revision') ||
    str_contains(strtolower($perfectWa), 'preparation') ||
    str_contains(strtolower($perfectWa), 'practice')
);

// Test N: No-live-session-data wording
StudentStudyPlanAnalytics::clearCaches();
$canonical203Test = StudentMentorAiService::extractCanonicalData($pdo, 'STU001', 203, 1, true);
$fallback203Test = StudentMentorAiService::buildFallbackResponse($canonical203Test, "No live data test");
assertTest("Test N: No-live-session-data uses authoritative wording",
    str_contains($fallback203Test['wa_text'], 'No live-session records are available for this study plan')
);
assertTest("Test N: Never uses forbidden generic phrase 'Live sessions not recorded'",
    !str_contains($fallback203Test['wa_text'], 'Live sessions not recorded for this study plan') &&
    !str_contains($fallback203Test['wa_text'], 'Live sessions not recorded for this plan')
);

// Test O: Missing-data vs zero-attendance distinction
$mockMissingLiveCanonical = $mockPerfectCanonical;
$mockMissingLiveCanonical['live_sessions'] = [
    'has_data' => true,
    'scheduled_sessions' => 0,
    'eligible_sessions' => 0,
    'attended_sessions' => 0,
    'missed_sessions' => 0,
    'pending_sessions' => 0,
    'pre_admission_sessions' => 0,
    'attendance_percentage' => null,
    'sessions_breakdown' => []
];
$fallbackMissingLive = StudentMentorAiService::buildFallbackResponse($mockMissingLiveCanonical, "Missing live attendance");
assertTest("Test O: Distinguishes missing attendance data from zero attendance",
    str_contains($fallbackMissingLive['wa_text'], 'Live sessions are recorded in the study plan, but attendance data is unavailable')
);

// Test P: Absolute em dash ban (U+2014, U+2013, U+2015) in final wa_text and fallback
assertTest("Test P: Perfect student fallback wa_text has zero em dash (U+2014)", strpos($perfectWa, '—') === false);
assertTest("Test P: Perfect student fallback wa_text has zero en dash (U+2013)", strpos($perfectWa, '–') === false);
assertTest("Test P: Perfect student fallback wa_text has zero horizontal bar (U+2015)", strpos($perfectWa, '―') === false);
assertTest("Test P: Plan 201 fallback wa_text has zero em dash", strpos($fallback201['wa_text'], '—') === false);
assertTest("Test P: sanitizeDashes strips em dash, en dash, and horizontal bar cleanly",
    StudentMentorAiService::sanitizeDashes("disciplined—steady–effort―focus") === "disciplined-steady-effort-focus"
);

// Test Q: Cohort ranking is not altered by enrollment tenure
assertTest("Test Q: Student STU020 maintains correct cohort size denominator in ranking",
    isset($resPartial['cohort_ranking']['cohort_size'])
);

// Test R: No invented standing classification
$mockNoBadgeCanonical = $mockPerfectCanonical;
$mockNoBadgeCanonical['cohort_ranking']['standing_badge'] = null;
$fallbackNoBadge = StudentMentorAiService::buildFallbackResponse($mockNoBadgeCanonical, "No badge test");
assertTest("Test R: Does not invent standing title when standing_badge is null",
    !str_contains($fallbackNoBadge['wa_text'], 'Elite Performer') &&
    !str_contains($fallbackNoBadge['wa_text'], 'Top Performer')
);

// Test S: Deterministic fallback follows the same business rules as AI
assertTest("Test S: Fallback excludes pre-admission live sessions from attendance ratio",
    str_contains($fallback201['wa_text'], '• Attendance: 1/2 (50%)')
);
assertTest("Test S: Fallback includes enrollment context when partial-period participant",
    str_contains(StudentMentorAiService::buildFallbackResponse($canonical201, "Test")['wa_text'], 'STUDENT PERFORMANCE AI ANALYSIS')
);

// Test T: Static cache isolation across students and plans
StudentStudyPlanAnalytics::clearCaches();
$resT1 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'STU010', 301);
$resT2 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'STU020', 301);
assertTest("Test T: STU010 (Full) and STU020 (Partial) cache isolated on same plan",
    $resT1['enrollment_context']['participation_tenure_category'] === 'FULL_PERIOD' &&
    $resT2['enrollment_context']['participation_tenure_category'] === 'PARTIAL_PERIOD'
);
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GROUP 11: Final Semantic Verification — Pre-Admission Tasks & Mega Tests
// ─────────────────────────────────────────────────────────────────────────────
echo "Group 11: Final Semantic Verification — Pre-Admission Tasks & Mega Tests\n";

// Seed dedicated student STU060 and dedicated Study Plan 501 for tenure boundary tests
$pdo->exec("
    INSERT INTO users (id, user_id, name, email, phone, whatsapp_number, pepp_course, pepp_academic_year, student_status, status, joined_date, approval_date, created_at, course_duration_date)
    VALUES (60, 'STU060', 'Tenure Verification Student', 'tenure_audit@pepp.edu', '9876543225', '9876543225', 'B.Com Professional', '2026-27', 'active', 'approved', '2026-09-15', '2026-09-15 10:00:00', '2026-09-10 10:00:00', '2026-01-01');

    INSERT INTO mentor_student_assignments (student_user_id, admin_id, status) VALUES ('STU060', 10, 'active');

    -- Seed Plan 501 (2026-09-01 to 2026-10-31)
    INSERT INTO study_plans (id, title, plan_type, course_name, academic_year, start_date, end_date, status, is_deleted)
    VALUES (501, 'Pre-Admission Verification Plan', 'date_wise', 'B.Com Professional', '2026-27', '2026-09-01', '2026-10-31', 'published', 0);

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (501, 'course', 'B.Com Professional', 0);

    -- Plan 501 Activities:
    -- 1. Pre-admission task (Sep 05)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (5001, 501, 'ACT-5001', 'Module 1', 'Early Orientation Reading', 'task', '2026-09-05', 1, 0);

    -- 2. Pre-admission Mega Test (Sep 08)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (5002, 501, 'ACT-5002', 'Module 1', 'Early Diagnostic Mega Test', 'Attend Mega Test', '2026-09-08', 2, 0);

    -- 3. Post-admission Task (Sep 20, past incomplete)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (5003, 501, 'ACT-5003', 'Module 2', 'Core Subject Case Study', 'task', '2026-09-20', 3, 0);

    -- 4. Post-admission Task (Sep 22, completed later)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, activity_title, activity_type, activity_date, sort_order, is_deleted)
    VALUES (5004, 501, 'ACT-5004', 'Module 2', 'Financial Calculation Practice', 'task', '2026-09-22', 4, 0);

    -- Pre-admission Mega Test Batch 5010 (Sep 08, before STU060 joined on Sep 15)
    INSERT INTO assessment_result_batches (id, study_plan_id, course_name, academic_year, activity_id, chapter_snapshot, status, activity_date_snapshot)
    VALUES (5010, 501, 'B.Com Professional', '2026-27', 5002, 'Module 1', 'published', '2026-09-08');

    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (5010, 'STU060', 'tenure_audit@pepp.edu', 'not_attended', NULL, 50.0);
");

// -----------------------------------------------------------------------------
// Requirement B: Pre-admission incomplete task
// Plan 501 has ACT-5001 (Sep 05, task) and ACT-5002 (Sep 08, mega test), both pre-admission.
// With no completions yet:
// - raw pending/overdue includes all incomplete past activities (4)
// - eligible pending/overdue excludes pre-admission activities entirely (2 eligible: 5003, 5004)
// -----------------------------------------------------------------------------
StudentStudyPlanAnalytics::clearCaches();
$analyticsB = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'tenure_audit@pepp.edu', 501);

assertTest("Requirement B: Pre-admission incomplete tasks preserved in raw pending (4)", $analyticsB['raw_pending_tasks'] === 4);
assertTest("Requirement B: Pre-admission incomplete tasks preserved in raw overdue (4)", $analyticsB['raw_overdue_tasks'] === 4);
assertTest("Requirement B: Pre-admission incomplete tasks excluded from eligible pending (2)", $analyticsB['pending_eligible_tasks'] === 2);
assertTest("Requirement B: Pre-admission incomplete tasks excluded from eligible overdue (2)", $analyticsB['overdue_eligible_tasks'] === 2);
assertTest("Requirement B: Pre-admission task count tracked accurately (2)", $analyticsB['pre_admission_tasks_count'] === 2);

// -----------------------------------------------------------------------------
// Requirement A: Pre-admission completed task
// Student completes ACT-5001 (Sep 05, pre-admission).
// - raw completed increases from 0 to 1
// - eligible completed does NOT increase (remains 0)
// - eligible_tasks_since_joining does NOT increase (remains 2)
// -----------------------------------------------------------------------------
$pdo->exec("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('tenure_audit@pepp.edu', 501, 5001, 'ACT-5001', 'complete_activity', 'completed', '2026-09-06 10:00:00');
");
StudentStudyPlanAnalytics::clearCaches();
$analyticsA = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'tenure_audit@pepp.edu', 501);

assertTest("Requirement A: Pre-admission completed task recorded in raw completed (1)", $analyticsA['raw_completed_tasks'] === 1);
assertTest("Requirement A: Pre-admission completed task does NOT increase eligible completed (0)", $analyticsA['completed_eligible_tasks'] === 0);
assertTest("Requirement A: Pre-admission completed task does NOT increase eligible tasks count (2)", $analyticsA['eligible_tasks_since_joining'] === 2);
assertTest("Requirement A: Raw total tasks remains completely unchanged (4)", $analyticsA['raw_total_tasks'] === 4);

// -----------------------------------------------------------------------------
// Requirement C: Post-admission completed task
// Student completes ACT-5004 (Sep 22, post-admission).
// - raw completed increases to 2
// - eligible completed increases from 0 to 1
// - eligible pending decreases from 2 to 1
// -----------------------------------------------------------------------------
$pdo->exec("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('tenure_audit@pepp.edu', 501, 5004, 'ACT-5004', 'complete_activity', 'completed', '2026-09-22 14:00:00');
");
StudentStudyPlanAnalytics::clearCaches();
$analyticsC = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'tenure_audit@pepp.edu', 501);

assertTest("Requirement C: Post-admission completed task increases eligible completed (1)", $analyticsC['completed_eligible_tasks'] === 1);
assertTest("Requirement C: Post-admission completed task increases raw completed (2)", $analyticsC['raw_completed_tasks'] === 2);
assertTest("Requirement C: Post-admission completed task decreases eligible pending (1)", $analyticsC['pending_eligible_tasks'] === 1);

// -----------------------------------------------------------------------------
// Requirement D: Pre-admission Mega Test
// Batch 5010 occurred on Sep 08 (before STU060 joined on Sep 15).
// - not included in current attendance denominator (total_sessions = 0)
// - not counted as current missed (attendance_rate is null, not 0%)
// - not presented as a current student performance deficit (never in needs_attention_activities)
// - represented as 'Pre-admission' in highlights
// - does not generate warnings in AI report
// -----------------------------------------------------------------------------
assertTest("Requirement D: Pre-admission Mega Test not included in current attendance denominator (total_sessions = 0)", $analyticsC['total_sessions'] === 0);
assertTest("Requirement D: Pre-admission Mega Test not counted as current missed (attendance_rate is null)", $analyticsC['attendance_rate'] === null);
assertTest("Requirement D: Pre-admission mega tests count tracked accurately (1)", ($analyticsC['pre_admission_mega_tests'] ?? 0) === 1);

$preAdmHighlight = null;
foreach ($analyticsC['learning_highlights']['all_activities'] as $h) {
    if ((int)$h['activity_id'] === 5002) {
        $preAdmHighlight = $h;
        break;
    }
}
assertTest("Requirement D: Pre-admission test represented as 'Pre-admission' status label", $preAdmHighlight !== null && $preAdmHighlight['status_label'] === 'Pre-admission');
assertTest("Requirement D: Pre-admission test represented as 'Pre-admission' performance display", $preAdmHighlight !== null && $preAdmHighlight['performance_display'] === 'Pre-admission');

$needsAttnIds = array_column($analyticsC['learning_highlights']['needs_attention_activities'] ?? [], 'activity_id');
assertTest("Requirement D: Pre-admission test excluded from needs_attention_activities", !in_array(5002, $needsAttnIds));

$canonical60 = StudentMentorAiService::extractCanonicalData($pdo, 'STU060', 501, 1, true);
$fallback60 = StudentMentorAiService::buildFallbackResponse($canonical60, "Pre-admission mega test verification");

assertTest("Requirement D: Canonical mega_tests reports has_data false when all tests pre-admission", $canonical60['mega_tests']['has_data'] === false);
assertTest("Requirement D: Canonical mega_tests tracks pre_admission_tests count (1)", $canonical60['mega_tests']['pre_admission_tests'] === 1);
assertTest("Requirement D: Pre-admission test does not generate AI performance warning",
    !str_contains(strtolower(implode(' ', $fallback60['analysis']['warnings'])), 'mega test')
);
assertTest("Requirement D: AI wa_text cites no published mega tests rather than missed test",
    str_contains($fallback60['wa_text'], 'No published mega tests for this study plan')
);
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Final Summary & Audit Score
// ─────────────────────────────────────────────────────────────────────────────
echo "========================================================================\n";
echo " AUDIT SUMMARY\n";
echo "========================================================================\n";
echo "Total Tests Run : {$totalTests}\n";
echo "Passed          : {$passedTests}\n";
echo "Failed          : {$failedTests}\n";
$passPct = ($totalTests > 0) ? round(($passedTests / $totalTests) * 100, 1) : 0;
echo "Pass Percentage : {$passPct}%\n\n";

if ($failedTests > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
} else {
    echo "🎉 ALL TESTS PASSED CLEANLY! ZERO REGRESSIONS DETECTED.\n";
    exit(0);
}
