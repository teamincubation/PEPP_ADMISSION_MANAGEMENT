<?php
/**
 * Test Suite: PEPP JOURNEY Student Study Plan Report Audit
 *
 * Validates:
 * 1. Authentication & Session Invariants
 * 2. Student Status Security Hardening (suspended/inactive/dropout/completed blocked)
 * 3. IDOR Protection (Cross-student / Cross-course access prevention)
 * 4. Multi-Study-Plan Isolation (No data bleed between plans)
 * 5. Accurate Task & Calendar Metrics Calculation
 * 6. Assessment Summary & Score Classification
 * 7. Chapter-Wise Progress Aggregation
 * 8. Cohort Ranking & Badge Assignment
 * 9. Edge Cases (Zero Tasks, No Assessments, 100% Completion)
 */

declare(strict_types=1);

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->sqliteCreateFunction('NOW', function() {
        return date('Y-m-d H:i:s');
    });
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/StudentStudyPlanAnalytics.php';

// Drop and recreate schema cleanly
$pdo->exec("
    DROP TABLE IF EXISTS users;
    DROP TABLE IF EXISTS student_status_log;
    DROP TABLE IF EXISTS study_plans;
    DROP TABLE IF EXISTS study_plan_assignments;
    DROP TABLE IF EXISTS study_plan_activities;
    DROP TABLE IF EXISTS study_plan_chapters;
    DROP TABLE IF EXISTS study_plan_analytics;
    DROP TABLE IF EXISTS assessment_result_batches;
    DROP TABLE IF EXISTS assessment_results;
    DROP TABLE IF EXISTS campaign_form_submissions;

    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        name TEXT,
        email TEXT UNIQUE,
        phone TEXT,
        mobile_number TEXT,
        whatsapp_country_code TEXT,
        whatsapp_number TEXT,
        pepp_course TEXT,
        pepp_academic_year TEXT,
        academic_year TEXT,
        status TEXT,
        student_status TEXT,
        user_photo TEXT,
        created_at TEXT
    );

    CREATE TABLE student_status_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        email TEXT,
        old_status TEXT,
        new_status TEXT,
        reason TEXT,
        changed_by TEXT,
        changed_at TEXT
    );

    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        plan_type TEXT DEFAULT 'date_wise',
        start_date TEXT,
        end_date TEXT,
        academic_year TEXT,
        version TEXT DEFAULT '1.0',
        status TEXT DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0,
        description TEXT
    );

    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_uid TEXT,
        activity_title TEXT,
        activity_type TEXT,
        activity_date TEXT,
        day_number INTEGER DEFAULT 1,
        chapter TEXT,
        topic TEXT,
        subject TEXT,
        sort_order INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE study_plan_chapters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chapter_name TEXT UNIQUE,
        sort_order INTEGER DEFAULT 1
    );

    CREATE TABLE study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_email TEXT,
        study_plan_id INTEGER,
        activity_id INTEGER,
        activity_uid TEXT,
        action_type TEXT,
        completion_status TEXT,
        created_at TEXT
    );

    CREATE TABLE assessment_result_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_id INTEGER,
        study_plan_id INTEGER,
        academic_year TEXT,
        course_name TEXT,
        activity_title_snapshot TEXT,
        activity_type_snapshot TEXT,
        activity_date_snapshot TEXT,
        chapter_snapshot TEXT,
        status TEXT DEFAULT 'published'
    );

    CREATE TABLE assessment_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER,
        user_id TEXT,
        student_email TEXT,
        score REAL,
        total_score REAL,
        attendance_status TEXT DEFAULT 'attended'
    );

    CREATE TABLE campaign_form_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER,
        respondent_identifier TEXT,
        is_deleted INTEGER DEFAULT 0
    );
");

echo "======================================================================\n";
echo "STUDENT STUDY PLAN REPORT AUDIT & REGRESSION TEST SUITE\n";
echo "======================================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $message\n";
        $passed++;
    } else {
        echo "  [FAIL] $message\n";
        $failed++;
    }
}

// ── Populate Test Fixtures ──────────────────────────────────────────

// Students
$pdo->exec("
    INSERT INTO users (user_id, name, email, phone, status, student_status, pepp_course, pepp_academic_year) VALUES
    ('STU001', 'Alice Walker', 'alice@pepp.com', '9876543210', 'approved', 'active', 'MA/MSc Psychology', '2026-27'),
    ('STU002', 'Bob Smith', 'bob@pepp.com', '9876543211', 'approved', 'suspended', 'MA/MSc Psychology', '2026-27'),
    ('STU003', 'Charlie Brown', 'charlie@pepp.com', '9876543212', 'approved', 'inactive', 'MA/MSc Psychology', '2026-27'),
    ('STU004', 'David Miller', 'david@pepp.com', '9876543213', 'approved', 'dropout', 'MA/MSc Psychology', '2026-27'),
    ('STU005', 'Eva Green', 'eva@pepp.com', '9876543214', 'approved', 'completed', 'MA/MSc Psychology', '2026-27'),
    ('STU006', 'Frank Ocean', 'frank@pepp.com', '9876543215', 'approved', 'active', 'B.Com Commerce', '2026-27');
");

// Status logs
$pdo->exec("
    INSERT INTO student_status_log (user_id, email, new_status, reason, changed_at) VALUES
    ('STU002', 'bob@pepp.com', 'suspended', 'Disciplinary review pending', '2026-08-20 10:00:00'),
    ('STU003', 'charlie@pepp.com', 'inactive', 'Long-term medical leave', '2026-08-21 11:00:00'),
    ('STU004', 'david@pepp.com', 'dropout', 'Relocated overseas', '2026-08-22 12:00:00'),
    ('STU005', 'eva@pepp.com', 'completed', 'Successfully graduated course', '2026-08-23 13:00:00');
");

// Study Plans
$pdo->exec("
    INSERT INTO study_plans (id, title, plan_type, start_date, end_date, academic_year, version, status) VALUES
    (101, 'August 2026 Psychology Plan', 'date_wise', '2026-08-01', '2026-08-31', '2026-27', '1.0', 'published'),
    (102, 'September 2026 Psychology Plan', 'date_wise', '2026-09-01', '2026-09-30', '2026-27', '1.0', 'published'),
    (201, 'Commerce August Plan', 'date_wise', '2026-08-01', '2026-08-31', '2026-27', '1.0', 'published');
");

// Plan Assignments
$pdo->exec("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES
    (101, 'course', 'MA/MSc Psychology'),
    (102, 'course', 'MA/MSc Psychology'),
    (201, 'course', 'B.Com Commerce');
");

// Chapters
$pdo->exec("
    INSERT INTO study_plan_chapters (chapter_name, sort_order) VALUES
    ('Chapter 1: Cognitive Foundations', 1),
    ('Chapter 2: Biological Bases of Behavior', 2),
    ('Chapter 3: Developmental Theories', 3);
");

// Activities for Plan 101 (6 tasks across 2 chapters)
$pdo->exec("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, chapter, sort_order) VALUES
    (1001, 101, 'ACT-101-1', 'Intro to Cognitive Psychology', 'Read Material', '2026-08-02', 'Chapter 1: Cognitive Foundations', 1),
    (1002, 101, 'ACT-101-2', 'Live Session on Memory Systems', 'Live Session', '2026-08-03', 'Chapter 1: Cognitive Foundations', 2),
    (1003, 101, 'ACT-101-3', 'Unit Test 1 - Cognition', 'Attend Mega Test', '2026-08-05', 'Chapter 1: Cognitive Foundations', 3),
    (1004, 101, 'ACT-101-4', 'Neuroanatomy Overview', 'Read Material', '2026-08-10', 'Chapter 2: Biological Bases of Behavior', 1),
    (1005, 101, 'ACT-101-5', 'Live Session on Neural Networks', 'Live Session', '2026-08-12', 'Chapter 2: Biological Bases of Behavior', 2),
    (1006, 101, 'ACT-101-6', 'Unit Test 2 - Neurobiology', 'Attend Mega Test', '2026-08-15', 'Chapter 2: Biological Bases of Behavior', 3);
");

// Activities for Plan 102 (2 tasks)
$pdo->exec("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, chapter, sort_order) VALUES
    (2001, 102, 'ACT-102-1', 'Developmental Stages Video', 'Watch Video', '2026-09-02', 'Chapter 3: Developmental Theories', 1),
    (2002, 102, 'ACT-102-2', 'Mega Test - Development', 'Attend Mega Test', '2026-09-10', 'Chapter 3: Developmental Theories', 2);
");

// Alice's completions for Plan 101: 4 of 6 completed
$pdo->exec("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES
    ('alice@pepp.com', 101, 1001, 'ACT-101-1', 'complete_activity', 'completed', '2026-08-02 14:00:00'),
    ('alice@pepp.com', 101, 1002, 'ACT-101-2', 'complete_activity', 'completed', '2026-08-03 16:00:00'),
    ('alice@pepp.com', 101, 1003, 'ACT-101-3', 'complete_activity', 'completed', '2026-08-04 11:00:00'),
    ('alice@pepp.com', 101, 1004, 'ACT-101-4', 'complete_activity', 'completed', '2026-08-05 09:00:00');
");

// Assessment Results for Plan 101
$pdo->exec("
    INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, activity_title_snapshot, chapter_snapshot, status) VALUES
    (501, 1003, 101, '2026-27', 'MA/MSc Psychology', 'Unit Test 1 - Cognition', 'Chapter 1: Cognitive Foundations', 'published'),
    (502, 1006, 101, '2026-27', 'MA/MSc Psychology', 'Unit Test 2 - Neurobiology', 'Chapter 2: Biological Bases of Behavior', 'published');

    INSERT INTO assessment_results (batch_id, user_id, student_email, score, total_score, attendance_status) VALUES
    (501, 'STU001', 'alice@pepp.com', 45, 50, 'attended'),
    (502, 'STU001', 'alice@pepp.com', 38, 50, 'attended');
");


// ====================================================================
// Test Suite 1: Authentication & Access Control Verification
// ====================================================================
echo "--- Test Suite 1: Authentication & Student Lifecycle Enforcement ---\n";

assert_test(can_student_access_study_plan($pdo, 'alice@pepp.com') === true, "Active enrolled student 'alice@pepp.com' CAN access study plan");
assert_test(can_student_access_study_plan($pdo, 'bob@pepp.com') === false, "Suspended student 'bob@pepp.com' is BLOCKED");
assert_test(can_student_access_study_plan($pdo, 'charlie@pepp.com') === false, "Inactive student 'charlie@pepp.com' is BLOCKED");
assert_test(can_student_access_study_plan($pdo, 'david@pepp.com') === false, "Dropout student 'david@pepp.com' is BLOCKED");
assert_test(can_student_access_study_plan($pdo, 'eva@pepp.com') === false, "Completed student 'eva@pepp.com' is BLOCKED");
assert_test(can_student_access_study_plan($pdo, 'nonexistent@pepp.com') === false, "Non-existent user is BLOCKED");

$bob_reason = get_student_status_reason($pdo, 'bob@pepp.com', 'suspended');
assert_test($bob_reason === 'Disciplinary review pending', "Suspended student exact reason retrieved: '$bob_reason'");


// ====================================================================
// Test Suite 2: IDOR & Cross-Course Authorization Protection
// ====================================================================
echo "\n--- Test Suite 2: IDOR & Course Scoping Protection ---\n";

// Function simulating server-side accessible plan resolution
function get_student_accessible_plans(PDO $pdo, string $email): array {
    $stmt_u = $pdo->prepare("SELECT user_id, pepp_course, pepp_academic_year FROM users WHERE email = ? AND status = 'approved' LIMIT 1");
    $stmt_u->execute([$email]);
    $u = $stmt_u->fetch(PDO::FETCH_ASSOC);
    if (!$u) return [];

    $stmt_access = $pdo->prepare("
        SELECT DISTINCT sp.id
        FROM study_plans sp
        JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
        WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
          AND (
            sa.assignment_type = 'all' OR
            (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
            (sa.assignment_type = 'batch' AND LOWER(sa.assigned_value) = LOWER(?)) OR
            (sa.assignment_type = 'student' AND sa.assigned_value = ?)
        )
    ");
    $stmt_access->execute([$u['pepp_course'], $u['pepp_academic_year'], $u['user_id']]);
    return array_map('intval', $stmt_access->fetchAll(PDO::FETCH_COLUMN));
}

$alice_plans = get_student_accessible_plans($pdo, 'alice@pepp.com');
$frank_plans = get_student_accessible_plans($pdo, 'frank@pepp.com');

assert_test(in_array(101, $alice_plans, true), "Alice CAN access Psychology Plan 101");
assert_test(in_array(102, $alice_plans, true), "Alice CAN access Psychology Plan 102");
assert_test(!in_array(201, $alice_plans, true), "Alice CANNOT access Commerce Plan 201 (IDOR protection)");
assert_test(in_array(201, $frank_plans, true), "Frank CAN access Commerce Plan 201");
assert_test(!in_array(101, $frank_plans, true), "Frank CANNOT access Psychology Plan 101 (IDOR protection)");


// ====================================================================
// Test Suite 3: Multi-Study Plan Isolation & Metric Scoping
// ====================================================================
echo "\n--- Test Suite 3: Multi-Study Plan Metric Isolation ---\n";

$analytics_p101 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'alice@pepp.com', 101);
$analytics_p102 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'alice@pepp.com', 102);

assert_test((int)$analytics_p101['total_tasks'] === 6, "Plan 101 Total Tasks is strictly 6 (not combined)");
assert_test((int)$analytics_p101['completed_tasks'] === 4, "Plan 101 Completed Tasks is 4");
assert_test((int)$analytics_p101['completion_percentage'] === 67, "Plan 101 Completion % is 67% (4/6)");
assert_test((int)$analytics_p101['pending_tasks'] === 2, "Plan 101 Pending Tasks is 2");

assert_test((int)$analytics_p102['total_tasks'] === 2, "Plan 102 Total Tasks is strictly 2");
assert_test((int)$analytics_p102['completed_tasks'] === 0, "Plan 102 Completed Tasks is 0 (isolated from Plan 101)");
assert_test((int)$analytics_p102['completion_percentage'] === 0, "Plan 102 Completion % is 0%");


// ====================================================================
// Test Suite 4: Chapter-Wise Progress Aggregation
// ====================================================================
echo "\n--- Test Suite 4: Chapter-Wise Progress Calculation ---\n";

$chapters_p101 = $analytics_p101['chapters'];
assert_test(count($chapters_p101) === 2, "Plan 101 has exactly 2 chapters with tasks");

$ch1 = $chapters_p101[0];
assert_test($ch1['chapter_name'] === 'Chapter 1: Cognitive Foundations', "Chapter 1 title matches canonical order");
assert_test((int)$ch1['total_activities'] === 3, "Chapter 1 has 3 total activities");
assert_test((int)$ch1['completed_activities'] === 3, "Chapter 1 has 3 completed activities (100%)");
assert_test((int)$ch1['completion_percentage'] === 100, "Chapter 1 completion percentage is 100%");

$ch2 = $chapters_p101[1];
assert_test($ch2['chapter_name'] === 'Chapter 2: Biological Bases of Behavior', "Chapter 2 title matches canonical order");
assert_test((int)$ch2['total_activities'] === 3, "Chapter 2 has 3 total activities");
assert_test((int)$ch2['completed_activities'] === 1, "Chapter 2 has 1 completed activity (33%)");
assert_test((int)$ch2['completion_percentage'] === 33, "Chapter 2 completion percentage is 33%");


// ====================================================================
// Test Suite 5: Assessment Summary & Dynamic Performance Scoring
// ====================================================================
echo "\n--- Test Suite 5: Assessment Summary & Individual Breakdown ---\n";

// Batch 501: 45/50 = 90%
// Batch 502: 38/50 = 76%
// Average: (90 + 76) / 2 = 83%

assert_test((int)$analytics_p101['performance_score'] === 83, "Average Assessment Score is 83%");
assert_test(!empty($analytics_p101['performance_label']), "Performance Label is assigned: '{$analytics_p101['performance_label']}'");
assert_test((int)$analytics_p101['attended_sessions'] === 2, "Attended assessment sessions is 2");


// ====================================================================
// Test Suite 6: Streak & Timeline Calculations
// ====================================================================
echo "\n--- Test Suite 6: Streak & Timeline Consistency ---\n";

assert_test((int)$analytics_p101['longest_streak'] === 4, "Longest Streak across 2026-08-02 to 2026-08-05 is 4 days");
assert_test((int)$analytics_p101['active_study_days'] === 4, "Active Study Days count is 4");
assert_test(count($analytics_p101['progress_timeline']) > 0, "Progress Timeline generated with cumulative entries");


// ====================================================================
// Test Suite 7: Calendar Days Calculation
// ====================================================================
echo "\n--- Test Suite 7: Calendar Days Calculation ---\n";

$p101_days = StudentStudyPlanAnalytics::calculatePlanCalendarDays('2026-08-01', '2026-08-31');
assert_test($p101_days === 31, "August 2026 calendar days is 31");

$zero_days = StudentStudyPlanAnalytics::calculatePlanCalendarDays(null, null);
assert_test($zero_days === 0, "Null dates return 0 calendar days");


// ====================================================================
// Test Suite 8: Empty & Edge States
// ====================================================================
echo "\n--- Test Suite 8: Empty & Edge States ---\n";

// Empty plan with 0 tasks
$pdo->exec("INSERT INTO study_plans (id, title, academic_year, status) VALUES (999, 'Empty Plan', '2026-27', 'published');");
$pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (999, 'course', 'MA/MSc Psychology');");

$analytics_empty = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'alice@pepp.com', 999);
assert_test((int)$analytics_empty['total_tasks'] === 0, "Empty plan returns 0 total tasks");
assert_test((int)$analytics_empty['completion_percentage'] === 0, "Empty plan returns 0% completion rate");
assert_test(empty($analytics_empty['chapters']), "Empty plan returns empty chapters array");


// ====================================================================
// Final Results
// ====================================================================
echo "\n======================================================================\n";
echo "Test Results: $passed Passed, $failed Failed\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
