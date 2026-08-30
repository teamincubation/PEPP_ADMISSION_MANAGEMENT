<?php
/**
 * PEPP ERP — Comprehensive Mega Test & Study Plan Test Attendance Audit Test Suite
 * 
 * Verifies all business rules and edge cases:
 * 1. Practice Test completed -> attended (via study_plan_analytics)
 * 2. Practice Test incomplete -> pending
 * 3. Mock Test completed -> attended (via study_plan_analytics)
 * 4. Mock Test incomplete -> pending
 * 5. Mega Test checkbox only (no matching assessment_results) -> NOT attended / pending
 * 6. Mega Test result only (no study_plan_analytics checkmark) -> attended
 * 7. Mega Test checkbox + matching result -> attended
 * 8. Wrong email in assessment_results -> NOT attended
 * 9. Duplicate Mega Test results / batches -> de-duplicated / total tests preserved
 * 10. Multiple Mega Tests -> correctly associated per test/batch
 * 11. getCohortRanking evaluation with pure email matching
 * 12. getCourseAnalytics and getCourseAnalyticsBulk evaluation with pure email matching
 */

$test_count = 0;
$pass_count = 0;
$fail_count = 0;

function assertTest($name, $expected, $actual) {
    global $test_count, $pass_count, $fail_count;
    $test_count++;
    if ($expected === $actual || (is_numeric($expected) && is_numeric($actual) && (float)$expected === (float)$actual)) {
        $pass_count++;
        echo "✅ PASS: $name\n";
    } else {
        $fail_count++;
        $exp_str = is_array($expected) ? json_encode($expected) : (var_export($expected, true));
        $act_str = is_array($actual) ? json_encode($actual) : (var_export($actual, true));
        echo "❌ FAIL: $name\n   Expected: $exp_str\n   Actual:   $act_str\n";
    }
}

// Set up in-memory SQLite database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Define schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        email TEXT UNIQUE,
        name TEXT,
        phone TEXT,
        pepp_course TEXT,
        pepp_academic_year TEXT,
        user_photo TEXT,
        student_status TEXT DEFAULT 'active',
        status TEXT DEFAULT 'approved',
        created_at TEXT
    );

    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        academic_year TEXT,
        course_name TEXT,
        start_date TEXT,
        end_date TEXT,
        plan_type TEXT DEFAULT 'date_wise',
        version INTEGER DEFAULT 1,
        status TEXT DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0,
        created_at TEXT
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
        chapter TEXT,
        topic TEXT,
        subject TEXT,
        day_number INTEGER,
        activity_date TEXT,
        sort_order INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        student_email TEXT,
        activity_id INTEGER,
        activity_uid TEXT,
        action_type TEXT,
        completion_status TEXT,
        ip_address TEXT,
        created_at TEXT
    );

    CREATE TABLE assessment_result_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_id INTEGER,
        study_plan_id INTEGER,
        academic_year TEXT,
        course_id INTEGER DEFAULT 0,
        course_name TEXT DEFAULT 'All Courses',
        activity_title_snapshot TEXT,
        activity_type_snapshot TEXT,
        activity_date_snapshot TEXT,
        chapter_snapshot TEXT,
        version INTEGER DEFAULT 1,
        status TEXT DEFAULT 'published',
        source_filename TEXT,
        total_rows INTEGER DEFAULT 0,
        matched_students INTEGER DEFAULT 0,
        unmatched_emails INTEGER DEFAULT 0,
        attended_count INTEGER DEFAULT 0,
        not_attended_count INTEGER DEFAULT 0,
        in_progress_count INTEGER DEFAULT 0,
        review_required_count INTEGER DEFAULT 0,
        uploaded_by INTEGER,
        published_by INTEGER,
        published_at TEXT,
        created_at TEXT
    );

    CREATE TABLE assessment_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER,
        user_id TEXT,
        student_email TEXT,
        attendance_status TEXT,
        score REAL,
        total_score REAL,
        accuracy REAL,
        rank INTEGER,
        created_at TEXT
    );

    CREATE TABLE study_plan_chapters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chapter_name TEXT,
        sort_order INTEGER DEFAULT 0
    );
");

// Insert seed data: 2 students in B.Com, 2026-27
$pdo->exec("
    INSERT INTO users (id, user_id, email, name, pepp_course, pepp_academic_year, status)
    VALUES (1, 'STU001', 'rahul@pepp.com', 'Rahul Sharma', 'B.Com', '2026-27', 'approved');

    INSERT INTO users (id, user_id, email, name, pepp_course, pepp_academic_year, status)
    VALUES (2, 'STU002', 'ananya@pepp.com', 'Ananya Verma', 'B.Com', '2026-27', 'approved');

    INSERT INTO study_plans (id, title, academic_year, course_name, start_date, end_date, plan_type, status, is_deleted)
    VALUES (10, 'Target 2027 Mastery', '2026-27', 'B.Com', '2026-08-01', '2026-08-31', 'date_wise', 'published', 0);

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (10, 'course', 'B.Com', 0);

    INSERT INTO study_plan_chapters (id, chapter_name, sort_order)
    VALUES (1, 'Financial Accounting', 1), (2, 'Business Economics', 2);
");

// Insert study plan activities:
// Act 1: Live Session (Watch Live Session)
// Act 2: Practice Test (Attend Practice Test)
// Act 3: Practice Test 2 (Practice Quiz)
// Act 4: Mock Test 1 (Attend Mock Test)
// Act 5: Mock Test 2 (Model Exam)
// Act 6: Mega Test 1 (Attend Mega Test)
// Act 7: Mega Test 2 (Attend Mega Test)
// Act 8: Mega Test 3 (Attend Mega Test)
$pdo->exec("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date)
    VALUES (1, 10, 'UID-01', 'Live Masterclass', 'Watch Live Session', 'Financial Accounting', 'Accounting Standards', 1, '2026-08-01');

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date)
    VALUES (2, 10, 'UID-02', 'Practice Drill 1', 'Attend Practice Test', 'Financial Accounting', 'Journal Entries', 2, '2026-08-02');

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date)
    VALUES (3, 10, 'UID-03', 'Practice Drill 2', 'Practice Quiz', 'Financial Accounting', 'Ledgers', 3, '2026-08-03');

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date)
    VALUES (4, 10, 'UID-04', 'Unit Mock Exam', 'Attend Mock Test', 'Business Economics', 'Supply and Demand', 4, '2026-08-04');

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date)
    VALUES (5, 10, 'UID-05', 'Model Exam 2', 'Model Exam', 'Business Economics', 'Market Structures', 5, '2026-08-05');

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date)
    VALUES (6, 10, 'UID-06', 'Mega Test 1 - Accounting', 'Attend Mega Test', 'Financial Accounting', 'Financial Statements', 6, '2026-08-06');

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date)
    VALUES (7, 10, 'UID-07', 'Mega Test 2 - Economics', 'Attend Mega Test', 'Business Economics', 'Macroeconomics', 7, '2026-08-07');

    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, chapter, topic, day_number, activity_date)
    VALUES (8, 10, 'UID-08', 'Mega Test 3 - Grand Finale', 'Attend Mega Test', 'Financial Accounting', 'Comprehensive Test', 8, '2026-08-08');
");

require_once __DIR__ . '/includes/StudentStudyPlanAnalytics.php';

echo "\n--- RUNNING MEGA TEST & STUDY PLAN ATTENDANCE AUDIT SUITE ---\n\n";

// Scenario 1 & 3: Student Rahul completes Practice Drill 1 and Unit Mock Exam in studyplan.php
$pdo->exec("
    INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES (10, 'rahul@pepp.com', 2, 'UID-02', 'complete_activity', 'completed', '2026-08-02 10:00:00');

    INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES (10, 'rahul@pepp.com', 4, 'UID-04', 'complete_activity', 'completed', '2026-08-04 11:00:00');
");

// Scenario 5: Rahul also checks off Mega Test 1 in studyplan.php, BUT no assessment_results record exists for Mega Test 1
$pdo->exec("
    INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES (10, 'rahul@pepp.com', 6, 'UID-06', 'complete_activity', 'completed', '2026-08-06 12:00:00');
");

// Scenario 6: Rahul did NOT check off Mega Test 2 in studyplan.php, BUT a published result batch exists in assessment_results with 'attended'
$pdo->exec("
    INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, activity_title_snapshot, activity_type_snapshot, chapter_snapshot, status)
    VALUES (101, 7, 10, '2026-27', 'B.Com', 'Mega Test 2 - Economics', 'Attend Mega Test', 'Business Economics', 'published');

    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (101, 'STU001', 'rahul@pepp.com', 'attended', 85.0, 100.0);
");

// Scenario 7: Rahul checked off Mega Test 3 in studyplan.php AND a published result batch exists in assessment_results with 'attended'
$pdo->exec("
    INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES (10, 'rahul@pepp.com', 8, 'UID-08', 'complete_activity', 'completed', '2026-08-08 14:00:00');

    INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, activity_title_snapshot, activity_type_snapshot, chapter_snapshot, status)
    VALUES (102, 8, 10, '2026-27', 'B.Com', 'Mega Test 3 - Grand Finale', 'Attend Mega Test', 'Financial Accounting', 'published');

    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (102, 'STU001', 'rahul@pepp.com', 'attended', 92.0, 100.0);
");

// Scenario 8: Result uploaded under wrong email for Mega Test 1
$pdo->exec("
    INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, activity_title_snapshot, activity_type_snapshot, chapter_snapshot, status)
    VALUES (100, 6, 10, '2026-27', 'B.Com', 'Mega Test 1 - Accounting', 'Attend Mega Test', 'Financial Accounting', 'published');

    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (100, 'OTHER99', 'wrong_email@other.com', 'attended', 95.0, 100.0);
");

// Run Analytics for Rahul
$analytics = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'rahul@pepp.com', 10);

// Total defined tasks = 8 (Live, Practice 1, Practice 2, Mock 1, Mock 2, Mega 1, Mega 2, Mega 3)
assertTest("Total tasks count", 8, $analytics['total_tasks']);

// Completed tasks in study plan = 4 (Practice 1, Mock 1, Mega 1 checkmark, Mega 3 checkmark)
assertTest("Completed tasks in study plan", 4, $analytics['completed_tasks']);

// Verify Mega Test Attendance: Rahul attended Mega Test 2 and Mega Test 3 (Batch 101 and 102).
// Mega Test 1 had wrong email in results -> not attended for Rahul.
assertTest("Mega Test Sessions Published (Batches 100, 101, 102)", 2, $analytics['total_sessions']);
assertTest("Mega Test Sessions Attended (Batches 101, 102)", 2, $analytics['attended_sessions']);
assertTest("Mega Test Attendance Rate is 100%", 100.0, (float)$analytics['attendance_rate']);

// Average score across attended mega tests (85 and 92 -> avg 88.5 -> round to 89)
assertTest("Mega Test Performance Score (Average 88.5 -> 89)", 89.0, (float)$analytics['performance_score']);

// Check that Mega Test 1 is NOT credited as completed highlight
$mega1_in_strongest = false;
foreach ($analytics['strongest_activities'] as $sa) {
    if ($sa['activity_id'] == 6) {
        $mega1_in_strongest = true;
    }
}
assertTest("Mega Test 1 (checkbox only) NOT credited as completed highlight", false, $mega1_in_strongest);

// Now simulate Study Plan Report Calculation logic (as in studyplan-report.php)
$email = 'rahul@pepp.com';
$study_plan_id = 10;

// 1. Fetch test activities
$stmt_all_tests = $pdo->prepare("
    SELECT id, activity_uid, activity_title, activity_type, activity_date, chapter, day_number
    FROM study_plan_activities
    WHERE study_plan_id = ? AND is_deleted = 0
      AND (
        LOWER(activity_type) LIKE '%test%' OR 
        LOWER(activity_type) LIKE '%exam%' OR 
        LOWER(activity_type) LIKE '%assessment%' OR 
        LOWER(activity_type) LIKE '%quiz%' OR
        LOWER(activity_title) LIKE '%mega test%' OR
        LOWER(activity_title) LIKE '%mock test%' OR
        LOWER(activity_title) LIKE '%practice test%'
      )
    ORDER BY day_number ASC, id ASC
");
$stmt_all_tests->execute([$study_plan_id]);
$plan_test_activities = $stmt_all_tests->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch results strictly by email
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

// 3. Fetch completion logs
$stmt_test_logs = $pdo->prepare("
    SELECT activity_id, activity_uid, completion_status
    FROM study_plan_analytics
    WHERE LOWER(TRIM(student_email)) = LOWER(TRIM(?)) AND study_plan_id = ? AND action_type = 'complete_activity'
    ORDER BY id ASC
");
$stmt_test_logs->execute([$email, $study_plan_id]);
$test_logs = $stmt_test_logs->fetchAll(PDO::FETCH_ASSOC);
$effective_test_completions = [];
foreach ($test_logs as $tlog) {
    $k = !empty($tlog['activity_uid']) ? $tlog['activity_uid'] : 'id_' . $tlog['activity_id'];
    $effective_test_completions[$k] = $tlog['completion_status'];
    if (!empty($tlog['activity_id'])) {
        $effective_test_completions['id_' . $tlog['activity_id']] = $tlog['completion_status'];
    }
}

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
    $tact_type_lower = strtolower(trim((string)$tact['activity_type']));
    $tact_title_lower = strtolower(trim((string)$tact['activity_title']));
    $key_uid = !empty($tact['activity_uid']) ? $tact['activity_uid'] : 'id_' . $tact_id;
    $is_studyplan_completed = (
        (isset($effective_test_completions[$key_uid]) && $effective_test_completions[$key_uid] === 'completed') ||
        (isset($effective_test_completions['id_' . $tact_id]) && $effective_test_completions['id_' . $tact_id] === 'completed')
    );

    $is_mega = (
        in_array($tact_type_lower, ['attend mega test', 'mega test', 'mega tests'], true) ||
        stripos($tact_type_lower, 'mega test') !== false ||
        stripos($tact_title_lower, 'mega test') !== false
    );

    if ($is_mega) {
        $res = $mega_results_by_act_id[$tact_id] ?? null;
        if ($res) {
            $accounted_batch_ids[(int)$res['batch_id']] = true;
            $att_status = $res['attendance_status'] ?? 'attended';
            if ($att_status === 'attended') {
                $tests_attended++;
            }
        }
    } else {
        if ($is_studyplan_completed) {
            $tests_attended++;
        }
    }
}

// Total tests defined = 7 tests (Practice 1, Practice 2, Mock 1, Mock 2, Mega 1, Mega 2, Mega 3)
assertTest("Report Total Tests count is 7", 7, $total_tests);

// Attended tests:
// Practice 1 (completed) -> 1
// Practice 2 (incomplete) -> 0
// Mock 1 (completed) -> 1
// Mock 2 (incomplete) -> 0
// Mega 1 (checkbox only, no result) -> 0 (PENDING!)
// Mega 2 (result attended) -> 1
// Mega 3 (checkbox + result attended) -> 1
// Total Attended = 1 + 0 + 1 + 0 + 0 + 1 + 1 = 4
assertTest("Report Tests Attended count is 4", 4, $tests_attended);
assertTest("Report Tests Pending count is 3", 3, $total_tests - $tests_attended);

// Scenario 9: Duplicate result / batch
$pdo->exec("
    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (101, 'STU001', 'rahul@pepp.com', 'attended', 85.0, 100.0);
");

// Re-run to ensure de-duplication prevents double-counting
$stmt_individual_assessments->execute([$email, $study_plan_id]);
$raw_assessment_rows_dup = $stmt_individual_assessments->fetchAll(PDO::FETCH_ASSOC);
$mega_results_by_act_id_dup = [];
foreach ($raw_assessment_rows_dup as $ass_row) {
    if (!empty($ass_row['activity_id'])) {
        $mega_results_by_act_id_dup[(int)$ass_row['activity_id']] = $ass_row;
    }
}

$tests_attended_dup = 0;
foreach ($plan_test_activities as $tact) {
    $tact_id = (int)$tact['id'];
    $tact_type_lower = strtolower(trim((string)$tact['activity_type']));
    $tact_title_lower = strtolower(trim((string)$tact['activity_title']));
    $key_uid = !empty($tact['activity_uid']) ? $tact['activity_uid'] : 'id_' . $tact_id;
    $is_studyplan_completed = (
        (isset($effective_test_completions[$key_uid]) && $effective_test_completions[$key_uid] === 'completed') ||
        (isset($effective_test_completions['id_' . $tact_id]) && $effective_test_completions['id_' . $tact_id] === 'completed')
    );
    $is_mega = (
        in_array($tact_type_lower, ['attend mega test', 'mega test', 'mega tests'], true) ||
        stripos($tact_type_lower, 'mega test') !== false ||
        stripos($tact_title_lower, 'mega test') !== false
    );
    if ($is_mega) {
        $res = $mega_results_by_act_id_dup[$tact_id] ?? null;
        if ($res && ($res['attendance_status'] ?? '') === 'attended') {
            $tests_attended_dup++;
        }
    } else {
        if ($is_studyplan_completed) {
            $tests_attended_dup++;
        }
    }
}
assertTest("Duplicate result record cannot double-count attendance", 4, $tests_attended_dup);

// Scenario 10: Cohort Ranking Evaluation
$cohort = StudentStudyPlanAnalytics::getCohortRanking($pdo, 10, '2026-27', 'STU001', 'rahul@pepp.com');
assertTest("Cohort size is 2", 2, $cohort['cohort_size']);
assertTest("Current student in cohort ranking is Rahul", 'Rahul Sharma', $cohort['current_student']['name'] ?? '');

// Scenario 11: Course Bulk Analytics Evaluation
$bulk_res = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, [
    ['email' => 'rahul@pepp.com', 'user_id' => 'STU001', 'pepp_course' => 'B.Com', 'pepp_academic_year' => '2026-27'],
    ['email' => 'ananya@pepp.com', 'user_id' => 'STU002', 'pepp_course' => 'B.Com', 'pepp_academic_year' => '2026-27']
], 'B.Com');

assertTest("Bulk Analytics contains Rahul", true, isset($bulk_res['rahul@pepp.com']));
assertTest("Bulk Analytics Rahul attended sessions is 2", 2, $bulk_res['rahul@pepp.com']['attended_sessions']);
assertTest("Bulk Analytics Ananya attended sessions is 0", 0, $bulk_res['ananya@pepp.com']['attended_sessions']);

echo "\n======================================================\n";
echo "MEGA TEST AUDIT TEST SUITE RESULTS:\n";
echo "Total Tests: $test_count | Passed: $pass_count | Failed: $fail_count\n";
echo "======================================================\n";

if ($fail_count > 0) {
    exit(1);
}
