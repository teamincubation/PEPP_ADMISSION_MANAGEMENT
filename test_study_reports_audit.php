<?php
/**
 * PEPP ERP — Student Study Reports & Checklist Audit Verification Suite
 */

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once 'config/database.php';
require_once 'includes/StudentStudyPlanAnalytics.php';

echo "<h1>PEPP ERP — Student Study Reports & Checklist Audit Verification Suite</h1>\n";
echo "<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background: #f8fafc; color: #334155; }
    .test-card { background: white; padding: 15px; margin: 15px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 5px solid #cbd5e1; }
    .pass { border-left-color: #10b981; }
    .fail { border-left-color: #ef4444; }
    .status-badge { display: inline-block; padding: 3px 8px; font-weight: bold; border-radius: 4px; font-size: 0.8rem; }
    .badge-pass { background: #d1fae5; color: #065f46; }
    .badge-fail { background: #fee2e2; color: #991b1b; }
    pre { background: #f1f5f9; padding: 10px; border-radius: 5px; font-size: 0.85rem; }
</style>\n";

$total_tests = 0;
$passed_tests = 0;

function assertTest($name, $expected, $actual) {
    global $total_tests, $passed_tests;
    $total_tests++;
    $passed = ($expected === $actual);
    if ($passed) $passed_tests++;

    $class = $passed ? 'pass' : 'fail';
    $status = $passed ? 'PASS' : 'FAIL';
    $badge = $passed ? 'badge-pass' : 'badge-fail';

    echo "<div class='test-card $class'>";
    echo "<strong>$name</strong> &nbsp; <span class='status-badge $badge'>$status</span><br>";
    if (!$passed) {
        echo "<pre>Expected: " . var_export($expected, true) . "\nGot:      " . var_export($actual, true) . "</pre>";
    } else {
        echo "<span style='color: #64748b; font-size: 0.9rem;'>Value: " . var_export($actual, true) . "</span>";
    }
    echo "</div>";
}

// Recreate clean database state for study plan & activity tests
$pdo->exec("DROP TABLE IF EXISTS users");
$pdo->exec("DROP TABLE IF EXISTS study_plans");
$pdo->exec("DROP TABLE IF EXISTS study_plan_activities");
$pdo->exec("DROP TABLE IF EXISTS study_plan_analytics");
$pdo->exec("DROP TABLE IF EXISTS study_plan_assignments");
$pdo->exec("DROP TABLE IF EXISTS assessment_results");
$pdo->exec("DROP TABLE IF EXISTS assessment_result_batches");

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        name TEXT,
        email TEXT,
        phone TEXT,
        pepp_course TEXT,
        pepp_academic_year TEXT,
        status TEXT,
        created_at TEXT,
        student_status TEXT,
        user_photo TEXT
    );
    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        plan_type TEXT,
        status TEXT,
        academic_year TEXT,
        start_date TEXT,
        end_date TEXT,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_uid TEXT,
        activity_date TEXT,
        day_number INTEGER,
        chapter TEXT,
        subject TEXT,
        topic TEXT,
        activity_title TEXT,
        activity_type TEXT,
        faculty TEXT,
        resource_links TEXT,
        sort_order INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_email TEXT,
        study_plan_id INTEGER,
        activity_id INTEGER,
        activity_uid TEXT,
        action_type TEXT,
        completion_status TEXT,
        created_at TEXT,
        ip_address TEXT,
        latitude TEXT,
        longitude TEXT,
        resolved_place TEXT
    );
    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE assessment_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER,
        student_email TEXT,
        score REAL,
        total_score REAL,
        attendance_status TEXT,
        src_name TEXT,
        user_id INTEGER
    );
    CREATE TABLE assessment_result_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        academic_year TEXT DEFAULT '2026-27',
        course_name TEXT DEFAULT 'MA/MSc Psychology (Premium)',
        activity_date_snapshot TEXT,
        status TEXT
    );
");

// Insert default test student
$pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, status, created_at, student_status)
    VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, 'active')
")->execute(['PEPP20268771', 'Fathima Rinfa', 'fathima@pepp.com', '+919999999999', 'MA/MSc Psychology (Premium)', '2026-27', '2026-08-01 10:00:00']);

$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date)
    VALUES (1, 'August 2026', 'date_wise', 'published', '2026-27', '2026-08-01', '2026-08-31')
")->execute();

$pdo->prepare("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value)
    VALUES (1, 'course', 'MA/MSc Psychology (Premium)')
")->execute();


echo "<h2>Executing Study Plan Scoping & Math Tests</h2>\n";

// --- TEST 1: Mathematical Accuracy (90 completed / 107 total = 84%) ---
// Insert 107 activities
for ($i = 1; $i <= 107; $i++) {
    $pdo->prepare("
        INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_date, is_deleted)
        VALUES (?, 1, ?, '2026-08-10', 0)
    ")->execute([$i, "uid_$i"]);
}

// Insert 90 completions
for ($i = 1; $i <= 90; $i++) {
    $pdo->prepare("
        INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
        VALUES ('fathima@pepp.com', 1, ?, ?, 'complete_activity', 'completed', '2026-08-10 12:00:00')
    ")->execute([$i, "uid_$i"]);
}

$analytics = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 1: Total Tasks count", 107, $analytics['total_tasks']);
assertTest("Test 1: Completed Tasks count", 90, $analytics['completed_tasks']);
assertTest("Test 1: Pending Tasks count", 17, $analytics['pending_tasks']);
assertTest("Test 1: Completion Percentage", 84.0, (float)$analytics['completion_percentage']);


// --- TEST 2: Completed > Total safety guard ---
// Insert completions for all remaining activities so completed = 107
for ($i = 91; $i <= 107; $i++) {
    $pdo->prepare("
        INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status)
        VALUES ('fathima@pepp.com', 1, ?, ?, 'complete_activity', 'completed')
    ")->execute([$i, "uid_$i"]);
}
// Now insert one extra completion for a non-existent task 999
$pdo->prepare("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status)
    VALUES ('fathima@pepp.com', 1, 999, 'uid_999', 'complete_activity', 'completed')
")->execute();

// Check if safety limits completed to total
$analytics2 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 2: Completed Tasks clamped to total", 107, $analytics2['completed_tasks']);
assertTest("Test 2: Pending Tasks safety clamped to 0", 0, $analytics2['pending_tasks']);
assertTest("Test 2: Completion clamped to 100%", 100.0, (float)$analytics2['completion_percentage']);

// Cleanup task 999 and extra completions to restore 90 completed / 107 total
$pdo->exec("DELETE FROM study_plan_analytics WHERE activity_id = 999");
$pdo->exec("DELETE FROM study_plan_analytics WHERE activity_id >= 91 AND study_plan_id = 1");


// --- TEST 3: Deleted activity must not count ---
// Mark task 90 (which IS completed) as deleted
$pdo->exec("UPDATE study_plan_activities SET is_deleted = 1 WHERE id = 90");
$analytics3 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 3: Deleted activity excluded from total tasks", 106, $analytics3['total_tasks']);
assertTest("Test 3: Deleted activity completion ignored", 89, $analytics3['completed_tasks']);

// Re-activate task 90
$pdo->exec("UPDATE study_plan_activities SET is_deleted = 0 WHERE id = 90");


// --- TEST 4: Duplicate completion records must count once ---
// Insert duplicate completion for task 1
$pdo->prepare("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status)
    VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed')
")->execute();
$analytics4 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 4: Duplicate completion row does not inflate completions", 90, $analytics4['completed_tasks']);

// Cleanup duplicate completion
$pdo->exec("DELETE FROM study_plan_analytics WHERE id = (SELECT max(id) FROM study_plan_analytics WHERE activity_id = 1)");


// --- TEST 5: Future activity must not be overdue ---
// Set task 107 date to tomorrow
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$pdo->prepare("UPDATE study_plan_activities SET activity_date = ? WHERE id = 107")->execute([$tomorrow]);
// Ensure task 107 is incomplete (deleted completion)
$pdo->exec("DELETE FROM study_plan_analytics WHERE activity_id = 107");

$analytics5 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
// Overdue tasks: 107 total activities.
// Completed: 90 (tasks 1 to 90).
// Incomplete: 17 (tasks 91 to 107).
// Tasks 91 to 106 are in the past ('2026-08-10') and incomplete -> 16 overdue tasks.
// Task 107 is in the future (tomorrow) and incomplete -> NOT overdue.
// So total overdue should be 16.
assertTest("Test 5: Future incomplete task is not overdue (overdue count = 16)", 16, $analytics5['overdue_tasks']);


// --- TEST 6: Completed past activity must not be overdue ---
$yesterday = date('Y-m-d', strtotime('-1 day'));
$pdo->prepare("UPDATE study_plan_activities SET activity_date = ? WHERE id = 1")->execute([$yesterday]);
$analytics6 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
// Task 1 is completed. Even though it is yesterday, it must NOT be overdue.
// So overdue count remains 16.
assertTest("Test 6: Completed yesterday task is not overdue (overdue count = 16)", 16, $analytics6['overdue_tasks']);

// Mark task 2 (yesterday) as incomplete
$pdo->prepare("UPDATE study_plan_activities SET activity_date = ? WHERE id = 2")->execute([$yesterday]);
$pdo->exec("DELETE FROM study_plan_analytics WHERE activity_id = 2");
$analytics6_2 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
// Task 2 is now incomplete and yesterday -> overdue.
// Total overdue tasks should increase from 16 to 17 (completions dropped to 89, so 17 tasks are overdue: 2, and 91 to 106).
assertTest("Test 6.2: Incomplete yesterday task IS overdue (overdue count = 17)", 17, $analytics6_2['overdue_tasks']);

// Re-complete task 2 and reset activity dates to past dates for remaining tasks
$pdo->prepare("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('fathima@pepp.com', 1, 2, 'uid_2', 'complete_activity', 'completed', '2026-08-10 12:00:00')
")->execute();
$pdo->exec("UPDATE study_plan_activities SET activity_date = '2026-08-10'");


// --- TEST 7: Different study plans must remain isolated ---
// Create study plan 2
$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date)
    VALUES (2, 'September 2026', 'date_wise', 'published', '2026-27', '2026-09-01', '2026-09-30')
")->execute();
$pdo->prepare("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value)
    VALUES (2, 'course', 'MA/MSc Psychology (Premium)')
")->execute();
$pdo->prepare("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_date)
    VALUES (200, 2, 'uid_200', '2026-09-05')
")->execute();

$analytics7 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 2);
assertTest("Test 7: Isolated plan 2 total tasks", 1, $analytics7['total_tasks']);
assertTest("Test 7: Isolated plan 2 completed tasks", 0, $analytics7['completed_tasks']);


// --- TEST 8: Different students must remain isolated ---
// Insert another student
$pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, status)
    VALUES (?, ?, ?, ?, ?, ?, 'approved')
")->execute(['PEPP20269999', 'Other Student', 'other@pepp.com', '+918888888888', 'MA/MSc Psychology (Premium)', '2026-27']);

$analytics8 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'other@pepp.com', 1);
assertTest("Test 8: Other student completion in plan 1 is isolated", 0, $analytics8['completed_tasks']);


// --- TEST 9: Different courses must remain isolated ---
// Insert student in different course
$pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, status)
    VALUES (?, ?, ?, ?, ?, ?, 'approved')
")->execute(['PEPP20261111', 'Course B Student', 'courseb@pepp.com', '+917777777777', 'BSc Psychology', '2026-27']);

$analytics9 = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'courseb@pepp.com', 'BSc Psychology');
assertTest("Test 9: Course B student has no plans assigned", 0, $analytics9['total_tasks']);


echo "<h2>Executing Assessment Metrics Tests</h2>\n";

// --- TEST 10: No assessments: attendance = null, performance = null ---
$analytics10 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 10: Assessment Attendance is NULL when empty", null, $analytics10['attendance_rate']);
assertTest("Test 10: Assessment Average is NULL when empty", null, $analytics10['performance_score']);


// --- TEST 11: Attendance: 8 attended, 2 not attended = 80% ---
// Create 10 assessment batches linked to plan 1
for ($b = 1; $b <= 10; $b++) {
    $pdo->prepare("
        INSERT INTO assessment_result_batches (id, study_plan_id, academic_year, course_name, activity_date_snapshot, status)
        VALUES (?, 1, '2026-27', 'MA/MSc Psychology (Premium)', '2026-08-10', 'published')
    ")->execute([$b]);
}

// 8 attended
for ($b = 1; $b <= 8; $b++) {
    $pdo->prepare("
        INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status)
        VALUES (?, 'fathima@pepp.com', 8.0, 10.0, 'attended')
    ")->execute([$b]);
}

// 2 not attended
for ($b = 9; $b <= 10; $b++) {
    $pdo->prepare("
        INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status)
        VALUES (?, 'fathima@pepp.com', 0.0, 10.0, 'not_attended')
    ")->execute([$b]);
}

$analytics11 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 11: Assessment Attendance rate", 80.0, (float)$analytics11['attendance_rate']);


// --- TEST 12: Assessment performance: 80%, 70%, 90% = 80% average ---
// Reset results
$pdo->exec("DELETE FROM assessment_results");
$pdo->exec("DELETE FROM assessment_result_batches");

// Batch 1 (80%)
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (1, 1, 'published')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (1, 'fathima@pepp.com', 80, 100, 'attended')")->execute();

// Batch 2 (70%)
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (2, 1, 'published')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (2, 'fathima@pepp.com', 7, 10, 'attended')")->execute();

// Batch 3 (90%)
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (3, 1, 'published')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (3, 'fathima@pepp.com', 9, 10, 'attended')")->execute();

$analytics12 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 12: Assessment Average performance score", 80.0, (float)$analytics12['performance_score']);


// --- TEST 13: Draft/unpublished assessment must not count ---
// Batch 4 (unpublished)
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (4, 1, 'draft')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (4, 'fathima@pepp.com', 10, 10, 'attended')")->execute();

$analytics13 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 13: Draft status excluded from average score", 80.0, (float)$analytics13['performance_score']);


// --- TEST 14: Duplicate assessment JOIN must not alter average ---
// Insert a duplicate row for student in batch 3
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (3, 'fathima@pepp.com', 9, 10, 'attended')")->execute();

$analytics14 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 14: De-duplicated average performance score", 80.0, (float)$analytics14['performance_score']);

$pdo->exec("DELETE FROM assessment_results WHERE id = (SELECT max(id) FROM assessment_results WHERE batch_id = 3)");


echo "<h2>Executing Learning Streak Tests</h2>\n";

// --- TEST 15: Streak: Aug 20, Aug 21, Aug 22, Aug 24, Aug 25 -> Longest streak = 3 ---
$pdo->exec("DELETE FROM study_plan_analytics");
$dates = ['2026-08-20', '2026-08-21', '2026-08-22', '2026-08-24', '2026-08-25'];
foreach ($dates as $idx => $d) {
    $pdo->prepare("
        INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
        VALUES ('fathima@pepp.com', 1, ?, ?, 'complete_activity', 'completed', ?)
    ")->execute([$idx + 1, "uid_" . ($idx + 1), "$d 12:00:00"]);
}

$analytics15 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 15: Longest Streak sequence", 3, $analytics15['longest_streak']);


// --- TEST 16: Current streak must be independently validated ---
// Current streak should be 2 (yesterday and today in local time mock, or 0 if date is far in future)
// Let's set completions to yesterday and today
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$pdo->exec("DELETE FROM study_plan_analytics");
$pdo->prepare("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed', ?)
")->execute(["$yesterday 14:00:00"]);

$analytics16_1 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 16: Current Streak when last completion was yesterday", 1, $analytics16_1['active_streak']);

$pdo->prepare("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('fathima@pepp.com', 1, 2, 'uid_2', 'complete_activity', 'completed', ?)
")->execute(["$today 10:00:00"]);

$analytics16_2 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 16: Current Streak when last completion is today", 2, $analytics16_2['active_streak']);


// --- TEST 17: Timezone boundary test ---
// Since default timezone is set to Asia/Kolkata, verify date matches expected Kolkata date.
assertTest("Test 17: Timezone set to Asia/Kolkata", 'Asia/Kolkata', date_default_timezone_get());


// --- TEST 18: Course aggregation must not double count study-plan activities ---
$analytics18 = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'fathima@pepp.com', 'MA/MSc Psychology (Premium)');
assertTest("Test 18: Aggregated total tasks across course", 108, $analytics18['total_tasks']);


echo "<h2>Executing Extended Data Integrity Tests (Identity, Cleared, Scores)</h2>\n";

// --- TEST 19: Student identity priority and email-change scenario ---
// Update student email to fathima_new@pepp.com
$pdo->exec("UPDATE users SET email = 'fathima_new@pepp.com' WHERE user_id = 'PEPP20268771'");
// Fetch plan analytics with old user_id or new email
$analytics19_1 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'PEPP20268771', 1);
$analytics19_2 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima_new@pepp.com', 1);
assertTest("Test 19: Resolve by user_id after email change", 107, $analytics19_1['total_tasks']);
assertTest("Test 19: Resolve by new email", 107, $analytics19_2['total_tasks']);

// Restore email for subsequent tests
$pdo->exec("UPDATE users SET email = 'fathima@pepp.com' WHERE user_id = 'PEPP20268771'");


// --- TEST 20: Identity priority in assessment results (user_id match) ---
// Insert assessment with student's user_id but a legacy mismatching email
$pdo->exec("DELETE FROM assessment_results");
$pdo->exec("DELETE FROM assessment_result_batches");
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (5, 1, 'published')")->execute();
$pdo->prepare("
    INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status, user_id)
    VALUES (5, 'mismatch@pepp.com', 95.0, 100.0, 'attended', 'PEPP20268771')
")->execute();

$analytics20 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'PEPP20268771', 1);
assertTest("Test 20: Matches assessment using user_id even if email mismatches", 95.0, (float)$analytics20['performance_score']);


// --- TEST 21: Invalid assessment scores (NULL score, total_score = 0, negative score) ---
$pdo->exec("DELETE FROM assessment_results");
$pdo->exec("DELETE FROM assessment_result_batches");

$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (6, 1, 'published')")->execute();
// NULL score
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (6, 'fathima@pepp.com', NULL, 100.0, 'attended')")->execute();

// total_score = 0
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (7, 1, 'published')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (7, 'fathima@pepp.com', 10.0, 0.0, 'attended')")->execute();

// Negative score
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (8, 1, 'published')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (8, 'fathima@pepp.com', -10.0, 100.0, 'attended')")->execute();

// Valid score (90%)
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (9, 1, 'published')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (9, 'fathima@pepp.com', 9.0, 10.0, 'attended')")->execute();

$analytics21 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 21: Performance average ignores NULL and total_score=0", 90.0, (float)$analytics21['performance_score']);


// --- TEST 22: Cleared completions logic (completed -> cleared) ---
$pdo->exec("DELETE FROM study_plan_analytics");
// 1. Mark activity 1 completed
$pdo->prepare("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status)
    VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed')
")->execute();
// 2. Mark activity 1 cleared
$pdo->prepare("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status)
    VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'cleared')
")->execute();

$analytics22 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 22: Cleared completions are NOT counted in completed count", 0, $analytics22['completed_tasks']);
assertTest("Test 22: Cleared activity is pending", 107, $analytics22['pending_tasks']);


// --- TEST 23: Study plan scoping validation ---
// student has course: MA/MSc Psychology (Premium)
// create a plan 3 assigned to BSc Psychology
$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date)
    VALUES (3, 'BSc Plan', 'date_wise', 'published', '2026-27', '2026-08-01', '2026-08-31')
")->execute();
$pdo->prepare("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value)
    VALUES (3, 'course', 'BSc Psychology')
")->execute();

$analytics23 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 3);
// Should return empty analytics because BSc Plan is not assigned to Fathima Rinfa (she is in MA/MSc)
assertTest("Test 23: Plan assignment validation (unauthorized plan returns empty analytics)", 0, $analytics23['total_tasks']);


// --- TEST 24: Duplicate assessment records ---
// Add two identical results for same batch
$pdo->exec("DELETE FROM assessment_results");
$pdo->exec("DELETE FROM assessment_result_batches");
$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status) VALUES (10, 1, 'published')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (10, 'fathima@pepp.com', 80.0, 100.0, 'attended')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (10, 'fathima@pepp.com', 80.0, 100.0, 'attended')")->execute();

$analytics24 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 24: De-duplicate same-batch assessment attendance", 1, $analytics24['total_sessions']);
assertTest("Test 24: De-duplicate same-batch performance", 80.0, (float)$analytics24['performance_score']);


// --- TEST 25: Chronological states machine (Phase 4 completion state machine) ---
$pdo->exec("DELETE FROM study_plan_analytics");
// Case A: completed
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed')")->execute();
$analytics25_A = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 25 Case A: completed is completed", 1, $analytics25_A['completed_tasks']);

// Case B: completed -> cleared
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'cleared')")->execute();
$analytics25_B = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 25 Case B: completed -> cleared is pending", 0, $analytics25_B['completed_tasks']);

// Case C: completed -> cleared -> completed
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed')")->execute();
$analytics25_C = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 25 Case C: completed -> cleared -> completed is completed", 1, $analytics25_C['completed_tasks']);

// Case F: completed -> cleared -> completed -> cleared
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'cleared')")->execute();
$analytics25_F = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 25 Case F: completed -> cleared -> completed -> cleared is pending", 0, $analytics25_F['completed_tasks']);


// --- TEST 26: Streak de-duplication (same-day completions) (Phase 5) ---
$pdo->exec("DELETE FROM study_plan_analytics");
// Student completes three tasks on same day (today)
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed', '$today 10:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 2, 'uid_2', 'complete_activity', 'completed', '$today 11:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 3, 'uid_3', 'complete_activity', 'completed', '$today 12:00:00')")->execute();

$analytics26 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 26: Multiple same-day completions count as one streak day (current)", 1, $analytics26['active_streak']);
assertTest("Test 26: Multiple same-day completions count as one streak day (longest)", 1, $analytics26['longest_streak']);


// --- TEST 27: Consecutive vs broken streaks (Phase 5) ---
$pdo->exec("DELETE FROM study_plan_analytics");
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
$today = $now->format('Y-m-d');
$yesterday = $now->modify('-1 day')->format('Y-m-d');
$day_before = $now->modify('-2 days')->format('Y-m-d');
$four_days_ago = $now->modify('-4 days')->format('Y-m-d');

// Scenario 1: Consecutive (today, yesterday, day_before)
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed', '$today 12:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 2, 'uid_2', 'complete_activity', 'completed', '$yesterday 12:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 3, 'uid_3', 'complete_activity', 'completed', '$day_before 12:00:00')")->execute();

$analytics27_consec = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 27: Consecutive streak counts", 3, $analytics27_consec['active_streak']);

// Scenario 2: Broken (today, day_before, 4 days ago)
$pdo->exec("DELETE FROM study_plan_analytics");
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed', '$today 12:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 2, 'uid_2', 'complete_activity', 'completed', '$day_before 12:00:00')")->execute();

$analytics27_broken = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 27: Broken streak reverts active streak to 1", 1, $analytics27_broken['active_streak']);


// --- TEST 28: Current vs longest streak calculations (Phase 5) ---
$pdo->exec("DELETE FROM study_plan_analytics");
$days_5_ago = $now->modify('-5 days')->format('Y-m-d');
$days_6_ago = $now->modify('-6 days')->format('Y-m-d');
$days_7_ago = $now->modify('-7 days')->format('Y-m-d');
$days_8_ago = $now->modify('-8 days')->format('Y-m-d');

$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed', '$today 12:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 2, 'uid_2', 'complete_activity', 'completed', '$yesterday 12:00:00')")->execute();

$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 3, 'uid_3', 'complete_activity', 'completed', '$days_5_ago 12:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 4, 'uid_4', 'complete_activity', 'completed', '$days_6_ago 12:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 5, 'uid_5', 'complete_activity', 'completed', '$days_7_ago 12:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 6, 'uid_6', 'complete_activity', 'completed', '$days_8_ago 12:00:00')")->execute();

$analytics28 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 28: Current streak is 2", 2, $analytics28['active_streak']);
assertTest("Test 28: Longest streak is 4", 4, $analytics28['longest_streak']);


// --- TEST 29: Midnight boundaries & UTC -> IST conversion (Phase 3) ---
$pdo->exec("DELETE FROM study_plan_analytics");
// completed on 2026-08-10T19:00:00Z -> parsed as UTC and shifts +5:30 to 2026-08-11T00:30:00+05:30 -> Kolkata calendar date: 2026-08-11
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 1, 'uid_1', 'complete_activity', 'completed', '2026-08-10T19:00:00Z')")->execute();
// completed on 2026-08-10T23:30:00+00:00 -> parsed and shifts +5:30 to 2026-08-11T05:00:00+05:30 -> Kolkata calendar date: 2026-08-11
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('fathima@pepp.com', 1, 2, 'uid_2', 'complete_activity', 'completed', '2026-08-10T23:30:00+00:00')")->execute();

$analytics29 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 29: UTC to IST shift correctly de-duplicates same-day local dates", 1, $analytics29['longest_streak']);


// --- TEST 30: Assessment exclusion (invalid scores, total_score=0) (Phase 6) ---
$pdo->exec("DELETE FROM assessment_results");
$pdo->exec("DELETE FROM assessment_result_batches");

$pdo->prepare("INSERT INTO assessment_result_batches (id, study_plan_id, status, course_name, academic_year) VALUES (15, 1, 'published', 'MA/MSc Psychology (Premium)', '2026-27')")->execute();
// total score = 0
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (15, 'fathima@pepp.com', 10.0, 0.0, 'attended')")->execute();
// score exceeds total score
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (15, 'fathima@pepp.com', 150.0, 100.0, 'attended')")->execute();
// score is negative
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (15, 'fathima@pepp.com', -20.0, 100.0, 'attended')")->execute();
// valid score (100%)
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, total_score, attendance_status) VALUES (15, 'fathima@pepp.com', 50.0, 50.0, 'attended')")->execute();

$analytics30 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test 30: Discard invalid scores from performance calculations", 100.0, (float)$analytics30['performance_score']);


// --- TEST 31: Scoping isolation (course & academic year) (Phase 1) ---
$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date)
    VALUES (4, 'Old Plan 2025', 'date_wise', 'published', '2025-26', '2025-08-01', '2025-08-31')
")->execute();
$pdo->prepare("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value)
    VALUES (4, 'course', 'MA/MSc Psychology (Premium)')
")->execute();
$pdo->prepare("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_date)
    VALUES (401, 4, 'uid_401', '2025-08-15')
")->execute();

$analytics31_plan = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 4);
assertTest("Test 31: Academic year isolation on plan level assignment validation", 0, $analytics31_plan['total_tasks']);

$analytics31_course = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'fathima@pepp.com', 'MA/MSc Psychology (Premium)');
assertTest("Test 31: Academic year isolation on course aggregation", 108, $analytics31_course['total_tasks']);


// --- TEST 32: Excluded items (deleted, future, cleared overdue) (Phase 7) ---
$pdo->exec("DELETE FROM study_plan_analytics");
$yesterday_str = $yesterday;
$pdo->exec("UPDATE study_plan_activities SET activity_date = '$yesterday_str' WHERE id = 200");

$analytics32_1 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 2);
assertTest("Test 32: Uncompleted activity in past is overdue", 1, $analytics32_1['overdue_tasks']);

$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status) VALUES ('fathima@pepp.com', 2, 200, 'uid_200', 'complete_activity', 'completed')")->execute();
$analytics32_2 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 2);
assertTest("Test 32: Completed overdue activity is not overdue", 0, $analytics32_2['overdue_tasks']);

$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status) VALUES ('fathima@pepp.com', 2, 200, 'uid_200', 'complete_activity', 'cleared')")->execute();
$analytics32_3 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 2);
assertTest("Test 32: Cleared overdue activity is overdue", 1, $analytics32_3['overdue_tasks']);

$tomorrow_str = $now->modify('+1 day')->format('Y-m-d');
$pdo->exec("UPDATE study_plan_activities SET activity_date = '$tomorrow_str' WHERE id = 200");
$analytics32_4 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 2);
assertTest("Test 32: Future activity is not overdue", 0, $analytics32_4['overdue_tasks']);


// --- TEST 33: CSV Actual file verification (Phase 9) ---
$csv_file_path = __DIR__ . '/scratch/test_export.csv';
$csv_out = fopen($csv_file_path, 'w');
fputcsv($csv_out, ['Student Name', 'Email', 'Tasks Done', 'Completed %']);
$c_analytics_test = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'PEPP20268771', 'MA/MSc Psychology (Premium)');
fputcsv($csv_out, [
    'Fathima Rinfa',
    'fathima@pepp.com',
    $c_analytics_test['completed_tasks'] . ' / ' . $c_analytics_test['total_tasks'],
    $c_analytics_test['completion_percentage'] . '%'
]);
fclose($csv_out);

$csv_in = fopen($csv_file_path, 'r');
$header_row = fgetcsv($csv_in);
$data_row = fgetcsv($csv_in);
fclose($csv_in);
unlink($csv_file_path);

assertTest("Test 33: CSV matches Student Name", 'Fathima Rinfa', $data_row[0]);
assertTest("Test 33: CSV matches Tasks Done", $c_analytics_test['completed_tasks'] . ' / ' . $c_analytics_test['total_tasks'], $data_row[2]);
assertTest("Test 33: CSV matches Completed %", $c_analytics_test['completion_percentage'] . '%', $data_row[3]);


// --- TEST 34: Individual vs bulk analytics equality (Phase 8) ---
$students_input = [
    [
        'email' => 'fathima@pepp.com',
        'user_id' => 'PEPP20268771',
        'pepp_academic_year' => '2026-27',
        'pepp_course' => 'MA/MSc Psychology (Premium)'
    ]
];
$bulk_results = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, $students_input, 'MA/MSc Psychology (Premium)');
$student_bulk = $bulk_results['fathima@pepp.com'] ?? [];

assertTest("Test 34: Bulk total tasks matches individual", $c_analytics_test['total_tasks'], $student_bulk['total_tasks']);
assertTest("Test 34: Bulk completed tasks matches individual", $c_analytics_test['completed_tasks'], $student_bulk['completed_tasks']);
assertTest("Test 34: Bulk completion % matches individual", $c_analytics_test['completion_percentage'], $student_bulk['completion_percentage']);
assertTest("Test 34: Bulk active streak matches individual", $c_analytics_test['active_streak'], $student_bulk['active_streak']);
assertTest("Test 34: Bulk longest streak matches individual", $c_analytics_test['longest_streak'], $student_bulk['longest_streak']);


// --- TEST 35: Mentoring bulk consistency (Phase 8) ---
assertTest("Test 35: Mentoring bulk consistency progress matches", $c_analytics_test['completion_percentage'], $student_bulk['completion_percentage']);
assertTest("Test 35: Mentoring bulk consistency attendance matches", $c_analytics_test['attendance_rate'], $student_bulk['attendance_rate']);


echo "<h2>Test Execution Summary</h2>\n";
$percent = $total_tests > 0 ? round(($passed_tests / $total_tests) * 100) : 0;
echo "<div style='font-size: 1.2rem; font-weight: bold; margin-top: 20px;'>";
echo "Total Tests: $total_tests | Passed: $passed_tests | Success Rate: $percent%";
echo "</div>";

if ($passed_tests === $total_tests) {
    echo "<h2 style='color:#10b981;'>All Tests Passed Successfully!</h2>\n";
    exit(0);
} else {
    echo "<h2 style='color:#ef4444;'>Some Tests Failed!</h2>\n";
    exit(1);
}
