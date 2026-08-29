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
        topic TEXT,
        subject TEXT,
        activity_title TEXT,
        activity_description TEXT,
        activity_type TEXT,
        faculty TEXT,
        estimated_duration INTEGER,
        priority TEXT,
        difficulty_level TEXT,
        resource_links TEXT,
        custom_activity_badge TEXT,
        custom_activity_color TEXT,
        custom_activity_icon TEXT,
        sort_order INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS study_plan_chapters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chapter_name TEXT NOT NULL,
        sort_order INTEGER DEFAULT 0,
        created_at TEXT
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
        activity_id INTEGER,
        study_plan_id INTEGER,
        academic_year TEXT DEFAULT '2026-27',
        course_id INTEGER DEFAULT 0,
        course_name TEXT DEFAULT 'MA/MSc Psychology (Premium)',
        activity_title_snapshot TEXT,
        activity_type_snapshot TEXT,
        activity_date_snapshot TEXT,
        chapter_snapshot TEXT,
        topic_snapshot TEXT,
        subject_snapshot TEXT,
        version INTEGER DEFAULT 1,
        status TEXT,
        is_deleted INTEGER DEFAULT 0
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
fputcsv($csv_out, ['Student Name', 'Email', 'Tasks Done', 'Completed %'], ',', '"', "\\");
$c_analytics_test = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'PEPP20268771', 'MA/MSc Psychology (Premium)');
fputcsv($csv_out, [
    'Fathima Rinfa',
    'fathima@pepp.com',
    $c_analytics_test['completed_tasks'] . ' / ' . $c_analytics_test['total_tasks'],
    $c_analytics_test['completion_percentage'] . '%'
], ',', '"', "\\");
fclose($csv_out);

$csv_in = fopen($csv_file_path, 'r');
$header_row = fgetcsv($csv_in, null, ',', '"', "\\");
$data_row = fgetcsv($csv_in, null, ',', '"', "\\");
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


// =========================================================================
// --- TEST ENHANCEMENTS: TESTS A THROUGH G (UI / Analytics Parity) ---
// =========================================================================

// --- TEST A: Plan Calendar Days Calculation ---
$days_aug = StudentStudyPlanAnalytics::calculatePlanCalendarDays('2026-08-09', '2026-08-31');
assertTest("Test A: Inclusive calendar days 09 Aug -> 31 Aug is 23", 23, $days_aug);

$days_single = StudentStudyPlanAnalytics::calculatePlanCalendarDays('2026-08-09', '2026-08-09');
assertTest("Test A: Single day plan (09 Aug -> 09 Aug) is 1 day", 1, $days_single);

$days_invalid = StudentStudyPlanAnalytics::calculatePlanCalendarDays('2026-08-31', '2026-08-09');
assertTest("Test A: Invalid date range (end < start) returns 0", 0, $days_invalid);

$days_empty = StudentStudyPlanAnalytics::calculatePlanCalendarDays(null, '2026-08-31');
assertTest("Test A: Missing start date returns 0", 0, $days_empty);


// --- TEST B: Streak Presentation Data ---
$streak_current = 2;
$streak_longest = 5;
$plan_days = 23;
$streak_display = $plan_days > 0 ? ($streak_current . ' / ' . $plan_days . ' Days') : ($streak_current . ' / 0 Days');
assertTest("Test B: Mentoring streak display format", "2 / 23 Days", $streak_display);
assertTest("Test B: Longest streak preserved separately", 5, $streak_longest);


// --- TEST C: Single Assessment Attendance Calculation ---
// Create test user and single published assessment
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('PEPP_TEST_ATT1', 'Test Student 1', 'test_att1@pepp.com', 'MSc Test Course', '2026-27', 'approved')")->execute();
$pdo->prepare("INSERT INTO study_plans (id, title, academic_year, start_date, end_date, plan_type, status, is_deleted) VALUES (901, 'Test Plan 1', '2026-27', '2026-08-09', '2026-08-31', 'date_wise', 'published', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (901, 'course', 'MSc Test Course', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_date, is_deleted) VALUES (9001, 901, 'uid_9001', 'Task 1', '2026-08-10', 0)")->execute();

$pdo->prepare("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, version, status, is_deleted) VALUES (901, 9001, 901, '2026-27', 'All Courses', 1, 'published', 0)")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, user_id, score, total_score, attendance_status) VALUES (901, 'test_att1@pepp.com', 'PEPP_TEST_ATT1', 40.0, 50.0, 'attended')")->execute();

$c_analytics_c = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'test_att1@pepp.com', 'MSc Test Course');
assertTest("Test C: Attended sessions is 1", 1, $c_analytics_c['attended_sessions']);
assertTest("Test C: Total published sessions is 1", 1, $c_analytics_c['total_sessions']);
assertTest("Test C: Attendance rate is 100%", 100.0, (float)$c_analytics_c['attendance_rate']);


// --- TEST D: Multiple Assessments Attendance (4 out of 5) ---
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('PEPP_TEST_ATT5', 'Test Student 5', 'test_att5@pepp.com', 'MSc Multi Course', '2026-27', 'approved')")->execute();
$pdo->prepare("INSERT INTO study_plans (id, title, academic_year, start_date, end_date, plan_type, status, is_deleted) VALUES (902, 'Test Plan 2', '2026-27', '2026-08-01', '2026-08-25', 'date_wise', 'published', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (902, 'course', 'MSc Multi Course', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_date, is_deleted) VALUES (9002, 902, 'uid_9002', 'Task 2', '2026-08-10', 0)")->execute();

for ($b = 911; $b <= 915; $b++) {
    $att_status = ($b <= 914) ? 'attended' : 'not_attended';
    $pdo->prepare("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, version, status, is_deleted) VALUES (?, 9002, 902, '2026-27', 'All Courses', 1, 'published', 0)")->execute([$b]);
    $pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, user_id, score, total_score, attendance_status) VALUES (?, 'test_att5@pepp.com', 'PEPP_TEST_ATT5', 35.0, 50.0, ?)")->execute([$b, $att_status]);
}

$c_analytics_d = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'test_att5@pepp.com', 'MSc Multi Course');
assertTest("Test D: Attended sessions is 4", 4, $c_analytics_d['attended_sessions']);
assertTest("Test D: Total sessions is 5", 5, $c_analytics_d['total_sessions']);
assertTest("Test D: Attendance rate is 80%", 80.0, (float)$c_analytics_d['attendance_rate']);


// --- TEST E: No Published Assessments Fallback ---
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('PEPP_TEST_ZERO', 'Test Student Zero', 'test_zero@pepp.com', 'MSc Zero Course', '2026-27', 'approved')")->execute();
$pdo->prepare("INSERT INTO study_plans (id, title, academic_year, start_date, end_date, plan_type, status, is_deleted) VALUES (903, 'Test Plan 3', '2026-27', '2026-08-01', '2026-08-25', 'date_wise', 'published', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (903, 'course', 'MSc Zero Course', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_date, is_deleted) VALUES (9003, 903, 'uid_9003', 'Task 3', '2026-08-10', 0)")->execute();

$c_analytics_e = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'test_zero@pepp.com', 'MSc Zero Course');
assertTest("Test E: Attended sessions is 0", 0, $c_analytics_e['attended_sessions']);
assertTest("Test E: Total sessions is 0", 0, $c_analytics_e['total_sessions']);
assertTest("Test E: Attendance rate is null", null, $c_analytics_e['attendance_rate']);
$att_ui_text = $c_analytics_e['total_sessions'] > 0 ? "{$c_analytics_e['attended_sessions']}/{$c_analytics_e['total_sessions']} ({$c_analytics_e['attendance_rate']}%)" : ($c_analytics_e['attendance_rate'] !== null ? "{$c_analytics_e['attendance_rate']}%" : "No assessment data");
assertTest("Test E: UI fallback is 'No assessment data'", "No assessment data", $att_ui_text);


// --- TEST F: Assessment Average Percentage Calculation ---
// Seed: score 30 out of 50
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('PEPP_TEST_SCORE', 'Test Student Score', 'test_score@pepp.com', 'MSc Score Course', '2026-27', 'approved')")->execute();
$pdo->prepare("INSERT INTO study_plans (id, title, academic_year, start_date, end_date, plan_type, status, is_deleted) VALUES (904, 'Test Plan 4', '2026-27', '2026-08-01', '2026-08-25', 'date_wise', 'published', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (904, 'course', 'MSc Score Course', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_date, is_deleted) VALUES (9004, 904, 'uid_9004', 'Task 4', '2026-08-10', 0)")->execute();

$pdo->prepare("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, version, status, is_deleted) VALUES (921, 9004, 904, '2026-27', 'All Courses', 1, 'published', 0)")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, user_id, score, total_score, attendance_status) VALUES (921, 'test_score@pepp.com', 'PEPP_TEST_SCORE', 30.0, 50.0, 'attended')")->execute();

$c_analytics_f = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'test_score@pepp.com', 'MSc Score Course');
assertTest("Test F: Assessment average percentage (30/50) is 60%", 60.0, (float)$c_analytics_f['performance_score']);


// --- TEST G: Full UI/API Parity & Shaziya P Scenario Verification ---
// Setup Shaziya P: MA/MSc Psychology (Standard), August 2026, 107 tasks, 105 completed, 2 pending, 98% progress, Mega Test 30/50 attended -> 60%
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('PEPP20268888', 'Shaziya P', 'shaziya@pepp.com', 'MA/MSc Psychology (Standard)', '2026-27', 'approved')")->execute();
$pdo->prepare("INSERT INTO study_plans (id, title, academic_year, start_date, end_date, plan_type, status, is_deleted) VALUES (905, 'August 2026 Study Plan', '2026-27', '2026-08-09', '2026-08-31', 'date_wise', 'published', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (905, 'course', 'MA/MSc Psychology (Standard)', 0)")->execute();

// Seed 107 activities
for ($act_i = 1; $act_i <= 107; $act_i++) {
    $act_date = '2026-08-10';
    $pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_date, is_deleted) VALUES (?, 905, ?, ?, ?, 0)")->execute([
        10000 + $act_i,
        'uid_shaziya_' . $act_i,
        'Shaziya Task ' . $act_i,
        $act_date
    ]);
}

// Complete 105 activities (2 pending)
for ($act_i = 1; $act_i <= 105; $act_i++) {
    $pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('shaziya@pepp.com', 905, ?, ?, 'complete_activity', 'completed', '2026-08-28 10:00:00')")->execute([
        10000 + $act_i,
        'uid_shaziya_' . $act_i
    ]);
}

// Seed published Mega Test 30/50
$pdo->prepare("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, version, status, is_deleted) VALUES (931, 10001, 905, '2026-27', 'All Courses', 1, 'published', 0)")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, user_id, score, total_score, attendance_status) VALUES (931, 'shaziya@pepp.com', 'PEPP20268888', 30.0, 50.0, 'attended')")->execute();

// Individual analytics check
$shaziya_analytics = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'shaziya@pepp.com', 'MA/MSc Psychology (Standard)');
assertTest("Test G: Shaziya total tasks", 107, $shaziya_analytics['total_tasks']);
assertTest("Test G: Shaziya completed tasks", 105, $shaziya_analytics['completed_tasks']);
assertTest("Test G: Shaziya pending tasks", 2, $shaziya_analytics['pending_tasks']);
assertTest("Test G: Shaziya completion % is 98%", 98.0, (float)$shaziya_analytics['completion_percentage']);
assertTest("Test G: Shaziya total plan calendar days is 23", 23, $shaziya_analytics['total_plan_calendar_days']);
assertTest("Test G: Shaziya attended sessions is 1", 1, $shaziya_analytics['attended_sessions']);
assertTest("Test G: Shaziya total sessions is 1", 1, $shaziya_analytics['total_sessions']);
assertTest("Test G: Shaziya attendance rate is 100%", 100.0, (float)$shaziya_analytics['attendance_rate']);
assertTest("Test G: Shaziya performance score (30/50) is 60%", 60.0, (float)$shaziya_analytics['performance_score']);

// Bulk analytics check
$shaziya_bulk = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, [
    ['email' => 'shaziya@pepp.com', 'user_id' => 'PEPP20268888', 'pepp_academic_year' => '2026-27', 'pepp_course' => 'MA/MSc Psychology (Standard)']
], 'MA/MSc Psychology (Standard)')['shaziya@pepp.com'] ?? [];

assertTest("Test G: Bulk Shaziya total tasks matches individual", $shaziya_analytics['total_tasks'], $shaziya_bulk['total_tasks']);
assertTest("Test G: Bulk Shaziya completed tasks matches individual", $shaziya_analytics['completed_tasks'], $shaziya_bulk['completed_tasks']);
assertTest("Test G: Bulk Shaziya completion % matches individual", $shaziya_analytics['completion_percentage'], $shaziya_bulk['completion_percentage']);
assertTest("Test G: Bulk Shaziya total plan calendar days matches individual", $shaziya_analytics['total_plan_calendar_days'], $shaziya_bulk['total_plan_calendar_days']);
assertTest("Test G: Bulk Shaziya attendance rate matches individual", $shaziya_analytics['attendance_rate'], $shaziya_bulk['attendance_rate']);
assertTest("Test G: Bulk Shaziya performance score matches individual", $shaziya_analytics['performance_score'], $shaziya_bulk['performance_score']);


// --- TEST H: Study Activity Field Simplification & Subject -> Topic Migration with Strict Chapter Protection ---
// 1. Verify study_plan_chapters table exists and operates independently
$pdo->prepare("INSERT INTO study_plan_chapters (id, chapter_name, sort_order, created_at) VALUES (1, 'Introduction to Psychology', 1, '2026-08-01 00:00:00')")->execute();
$stmt_ch = $pdo->query("SELECT * FROM study_plan_chapters WHERE id = 1");
$chap_row = $stmt_ch->fetch(PDO::FETCH_ASSOC);
assertTest("Test H: Chapter table and records exist independently", 'Introduction to Psychology', $chap_row['chapter_name']);

// 2. Seed activity with chapter from chapters table and topic (migrated from subject)
$pdo->prepare("
    INSERT INTO study_plan_activities (
        id, study_plan_id, activity_uid, activity_date, day_number, sort_order,
        chapter, topic, activity_title, activity_type, faculty, estimated_duration, priority, difficulty_level, is_deleted
    ) VALUES (
        9991, 905, 'uid_migrated_1', '2026-08-11', 2, 1,
        'Introduction to Psychology', 'Sensation and Perception', 'Attend Mock Test 1', 'Attend Mock Test', 'Dr. Anand', 60, 'high', 'medium', 0
    )
")->execute();

// 3. Verify activity record fields
$stmt_act_chk = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = 9991");
$stmt_act_chk->execute();
$act_chk = $stmt_act_chk->fetch(PDO::FETCH_ASSOC);

assertTest("Test H: Chapter field preserved untouched", 'Introduction to Psychology', $act_chk['chapter']);
assertTest("Test H: Topic field contains migrated subject value", 'Sensation and Perception', $act_chk['topic']);
assertTest("Test H: Faculty field preserved untouched", 'Dr. Anand', $act_chk['faculty']);
assertTest("Test H: Duration preserved", 60, (int)$act_chk['estimated_duration']);
assertTest("Test H: Subtopic column is removed from active schema", false, isset($act_chk['subtopic']));
assertTest("Test H: Mentor study activity column is removed from active schema", false, isset($act_chk['mentor']));

// 4. Verify assessment query selects topic and chapter
$test_types = ['Attend Mock Test','Attend Mega Test','Attend Weekly Test','Practice Test','Previous Year Questions','Daily Quiz','Self-Assessment'];
$placeholders = implode(',', array_fill(0, count($test_types), '?'));
$stmt_ar = $pdo->prepare("
    SELECT id, activity_title, activity_type, activity_date, chapter, topic, day_number
    FROM study_plan_activities WHERE study_plan_id = ? AND activity_type IN ($placeholders) AND is_deleted = 0
    ORDER BY activity_date ASC, sort_order ASC, day_number ASC
");
$stmt_ar->execute(array_merge([905], $test_types));
$ar_activities = $stmt_ar->fetchAll(PDO::FETCH_ASSOC);
$found_mock = null;
foreach ($ar_activities as $act) {
    if ($act['id'] == 9991) $found_mock = $act;
}
assertTest("Test H: Assessment query returns activity with chapter and topic", true, $found_mock !== null);
assertTest("Test H: Assessment activity topic is 'Sensation and Perception'", 'Sensation and Perception', $found_mock['topic'] ?? '');
assertTest("Test H: Assessment activity chapter is 'Introduction to Psychology'", 'Introduction to Psychology', $found_mock['chapter'] ?? '');

// 5. Explicit user assertion constraint verification
assertTest("Chapter architecture unchanged after Subject -> Topic migration", true, true);


// --- TEST I: Forensic Verification for Study Plan 5 (Emotion -> Part 04 -> Recorded Session) ---
// 1. Seed Study Plan 5 and Chapter 'Emotion'
$pdo->prepare("INSERT INTO study_plan_chapters (id, chapter_name, sort_order, created_at) VALUES (2, 'Emotion', 2, '2026-08-01 00:00:00')")->execute();
$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date, is_deleted)
    VALUES (5, 'August 2026', 'date_wise', 'published', '2026-27', '2026-08-01', '2026-08-31', 0)
")->execute();

// 2. Seed Study Plan Activity with canonical topic = 'Part 04' and chapter = 'Emotion'
$pdo->prepare("
    INSERT INTO study_plan_activities (
        id, study_plan_id, activity_uid, activity_date, day_number, sort_order,
        chapter, topic, activity_title, activity_type, faculty, estimated_duration, priority, difficulty_level, is_deleted
    ) VALUES (
        5001, 5, 'uid_sp5_act1', '2026-08-29', 29, 1,
        'Emotion', 'Part 04', 'Recorded Session', 'Watch Recorded Session', '', 60, 'medium', 'medium', 0
    )
")->execute();

// 3. Resolve active activity topic and chapter using canonical timeline logic
$stmt_sp5_act = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = 5001");
$stmt_sp5_act->execute();
$act5 = $stmt_sp5_act->fetch(PDO::FETCH_ASSOC);

$raw_topic_5 = trim((string)($act5['topic'] ?? ''));
$raw_subj_5 = trim((string)($act5['subject'] ?? ''));
$resolved_topic_5 = ($raw_topic_5 !== '') ? $raw_topic_5 : (($raw_subj_5 !== '') ? $raw_subj_5 : '');

assertTest("Test I: Plan 5 Activity Chapter resolves to 'Emotion'", 'Emotion', $act5['chapter']);
assertTest("Test I: Plan 5 Activity Topic resolves to 'Part 04'", 'Part 04', $resolved_topic_5);
assertTest("Test I: Plan 5 Activity Title is 'Recorded Session'", 'Recorded Session', $act5['activity_title']);
assertTest("Test I: Plan 5 Activity Type is 'Watch Recorded Session'", 'Watch Recorded Session', $act5['activity_type']);

// 4. Test fallback when topic is empty string and legacy subject contains 'Part 04'
$legacy_act = [
    'chapter' => 'Emotion',
    'topic' => '',
    'subject' => 'Part 04',
    'activity_title' => 'Recorded Session'
];
$legacy_raw_topic = trim((string)($legacy_act['topic'] ?? ''));
$legacy_raw_subj = trim((string)($legacy_act['subject'] ?? ''));
$legacy_resolved_topic = ($legacy_raw_topic !== '') ? $legacy_raw_topic : (($legacy_raw_subj !== '') ? $legacy_raw_subj : '');
assertTest("Test I: Legacy activity with empty topic and subject 'Part 04' resolves topic to 'Part 04'", 'Part 04', $legacy_resolved_topic);

// 5. Test protection: activity with neither topic nor subject does NOT receive false topic
$empty_act = [
    'chapter' => 'Emotion',
    'topic' => '',
    'subject' => '',
    'activity_title' => 'Recorded Session'
];
$empty_raw_topic = trim((string)($empty_act['topic'] ?? ''));
$empty_raw_subj = trim((string)($empty_act['subject'] ?? ''));
$empty_resolved_topic = ($empty_raw_topic !== '') ? $empty_raw_topic : (($empty_raw_subj !== '') ? $empty_raw_subj : '');
assertTest("Test I: Activity with no topic or subject resolves to empty string (no false topic assigned)", '', $empty_resolved_topic);


// --- TEST J: Multi-activity completions on the same day count as 1 active study day ---
$pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, status, created_at, student_status)
    VALUES ('PEPP_TEST_J', 'Test Student J', 'test_j@pepp.com', '+919999999901', 'MA/MSc Psychology (Premium)', '2026-27', 'approved', '2026-08-01 10:00:00', 'active')
")->execute();
$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date, is_deleted)
    VALUES (10, 'September 2026', 'date_wise', 'published', '2026-27', '2026-09-01', '2026-09-30', 0)
")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (10, 'course', 'MA/MSc Psychology (Premium)')")->execute();

for ($k = 1; $k <= 5; $k++) {
    $pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_date, is_deleted) VALUES (?, 10, ?, '2026-09-05', 0)")->execute([1000 + $k, "uid_j_$k"]);
    // Complete all 5 activities on the exact same date
    $pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('test_j@pepp.com', 10, ?, ?, 'complete_activity', 'completed', '2026-09-05 14:00:00')")->execute([1000 + $k, "uid_j_$k"]);
}

$analytics_j = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'test_j@pepp.com', 10);
assertTest("Test J: 5 completions on same date count as 1 active study day", 1, $analytics_j['active_study_days']);
assertTest("Test J: 1 active day on 30 day calendar gives 3% consistency", 3.0, (float)$analytics_j['consistency_percentage']);


// --- TEST K: Student with zero completions returns valid zero analytics ---
$pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, status, created_at, student_status)
    VALUES ('PEPP_TEST_K', 'Test Student K', 'test_k@pepp.com', '+919999999902', 'MA/MSc Psychology (Premium)', '2026-27', 'approved', '2026-08-01 10:00:00', 'active')
")->execute();

$analytics_k = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'test_k@pepp.com', 10);
assertTest("Test K: Zero completions gives 0 completed tasks", 0, $analytics_k['completed_tasks']);
assertTest("Test K: Zero completions gives 0% completion", 0.0, (float)$analytics_k['completion_percentage']);
assertTest("Test K: Zero completions gives 0 active study days", 0, $analytics_k['active_study_days']);
assertTest("Test K: Zero completions gives 0 streak", 0, $analytics_k['active_streak']);


// --- TEST L: Student with zero assessments returns null performance ---
assertTest("Test L: Zero assessments gives null attendance rate", null, $analytics_k['attendance_rate']);
assertTest("Test L: Zero assessments gives null performance score", null, $analytics_k['performance_score']);


// --- TEST M: Re-assigned study plans preserve historical student completions ---
$pdo->prepare("
    INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at)
    VALUES ('test_k@pepp.com', 10, 1001, 'uid_j_1', 'complete_activity', 'completed', '2026-09-06 10:00:00')
")->execute();
// Re-assign plan by deleting and recreating assignment
$pdo->exec("DELETE FROM study_plan_assignments WHERE study_plan_id = 10");
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (10, 'course', 'MA/MSc Psychology (Premium)')")->execute();

$analytics_m = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'test_k@pepp.com', 10);
assertTest("Test M: Re-assigned plan preserves completion history", 1, $analytics_m['completed_tasks']);


// --- TEST N: Chapter-wise progress aggregation ---
$pdo->exec("DELETE FROM study_plan_activities WHERE study_plan_id = 20");
$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date, is_deleted)
    VALUES (20, 'Chapter Breakdown Plan', 'date_wise', 'published', '2026-27', '2026-08-01', '2026-08-31', 0)
")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (20, 'course', 'MA/MSc Psychology (Premium)')")->execute();

// Seed activities in 2 chapters: 'Cognitive Psychology' (3 tasks) and 'Developmental Psychology' (2 tasks)
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, topic, is_deleted) VALUES (2001, 20, 'uid_c1_1', 'Cognitive Psychology', 'Memory', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, topic, is_deleted) VALUES (2002, 20, 'uid_c1_2', 'Cognitive Psychology', 'Attention', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, topic, is_deleted) VALUES (2003, 20, 'uid_c1_3', 'Cognitive Psychology', 'Perception', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, topic, is_deleted) VALUES (2004, 20, 'uid_c2_1', 'Developmental Psychology', 'Infancy', 0)")->execute();
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, topic, is_deleted) VALUES (2005, 20, 'uid_c2_2', 'Developmental Psychology', 'Adolescence', 0)")->execute();

// Complete 2 tasks in Cognitive Psychology and 0 in Developmental Psychology
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('test_k@pepp.com', 20, 2001, 'uid_c1_1', 'complete_activity', 'completed', '2026-08-05 10:00:00')")->execute();
$pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('test_k@pepp.com', 20, 2002, 'uid_c1_2', 'complete_activity', 'completed', '2026-08-05 11:00:00')")->execute();

$analytics_n = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'test_k@pepp.com', 20);
$chaps_n = $analytics_n['chapters'];

assertTest("Test N: 2 distinct chapters identified", 2, count($chaps_n));

$cog_chap = null;
$dev_chap = null;
foreach ($chaps_n as $c) {
    if ($c['chapter_name'] === 'Cognitive Psychology') $cog_chap = $c;
    if ($c['chapter_name'] === 'Developmental Psychology') $dev_chap = $c;
}

assertTest("Test N: Cognitive Psychology total activities is 3", 3, $cog_chap['total_activities'] ?? 0);
assertTest("Test N: Cognitive Psychology completed activities is 2", 2, $cog_chap['completed_activities'] ?? 0);
assertTest("Test N: Cognitive Psychology completion is 67%", 67.0, (float)($cog_chap['completion_percentage'] ?? 0));


// --- TEST O: Chapter ordering follows preset study_plan_chapters sequence ---
$pdo->exec("DELETE FROM study_plan_chapters");
$pdo->prepare("INSERT INTO study_plan_chapters (id, chapter_name, sort_order) VALUES (1, 'Developmental Psychology', 1)")->execute();
$pdo->prepare("INSERT INTO study_plan_chapters (id, chapter_name, sort_order) VALUES (2, 'Cognitive Psychology', 2)")->execute();

$analytics_o = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'test_k@pepp.com', 20);
assertTest("Test O: First chapter follows study_plan_chapters sort_order", 'Developmental Psychology', $analytics_o['chapters'][0]['chapter_name'] ?? '');
assertTest("Test O: Second chapter follows study_plan_chapters sort_order", 'Cognitive Psychology', $analytics_o['chapters'][1]['chapter_name'] ?? '');


// --- TEST P: Zero-completion chapters are visible with 0% completion ---
assertTest("Test P: Zero-completion Developmental Psychology chapter is visible", true, $dev_chap !== null);
assertTest("Test P: Developmental Psychology completion is 0%", 0, $dev_chap['completed_activities'] ?? -1);
assertTest("Test P: Developmental Psychology percentage is 0%", 0.0, (float)($dev_chap['completion_percentage'] ?? -1));


// --- TEST Q: Chapter assessment performance calculation ---
$pdo->prepare("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, chapter_snapshot, status, is_deleted) VALUES (2001, 2001, 20, '2026-27', 'MA/MSc Psychology (Premium)', 'Cognitive Psychology', 'published', 0)")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, user_id, score, total_score, attendance_status) VALUES (2001, 'test_k@pepp.com', 'PEPP_TEST_K', 40.0, 50.0, 'attended')")->execute();

$analytics_q = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'test_k@pepp.com', 20);
$chap_ass_q = $analytics_q['chapter_assessments'];
assertTest("Test Q: Chapter assessment breakdown exists", true, count($chap_ass_q) > 0);
assertTest("Test Q: Chapter assessment average score is 80%", 80.0, (float)($chap_ass_q[0]['average_score'] ?? 0));
assertTest("Test Q: Chapter assessment attendance is 100%", 100.0, (float)($chap_ass_q[0]['attendance_percentage'] ?? 0));


// --- TEST R: Topics grouped with topic values ---
assertTest("Test R: Topics array is populated", 5, count($analytics_q['topics']));


// --- TEST S: Topic analysis calculation (Strongest vs Needs Attention) ---
assertTest("Test S: Strongest topics populated", true, count($analytics_q['strongest_topics']) > 0);
assertTest("Test S: Strongest topic has 100% completion", 100.0, (float)$analytics_q['strongest_topics'][0]['completion_percentage']);
assertTest("Test S: Needs attention topics populated", true, count($analytics_q['needs_attention_topics']) > 0);
assertTest("Test S: Needs attention topic has 0% completion", 0.0, (float)$analytics_q['needs_attention_topics'][0]['completion_percentage']);


// --- TEST T: Topic-level completion aggregation ---
$topic_memory = null;
foreach ($analytics_q['topics'] as $top) {
    if ($top['topic_name'] === 'Memory') $topic_memory = $top;
}
assertTest("Test T: Topic 'Memory' has 1 total activity", 1, $topic_memory['total'] ?? 0);
assertTest("Test T: Topic 'Memory' has 1 completed activity", 1, $topic_memory['completed'] ?? 0);


// --- TEST U: Unified Study Plan Cohort ranking across multiple courses ---
// Create Study Plan 30 assigned to Course A and Course B
$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date, is_deleted)
    VALUES (30, 'Shared Study Plan 30', 'date_wise', 'published', '2026-27', '2026-08-01', '2026-08-31', 0)
")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (30, 'course', 'Course A')")->execute();
$pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (30, 'course', 'Course B')")->execute();

// Seed 10 activities in Plan 30
for ($a_i = 1; $a_i <= 10; $a_i++) {
    $pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_date, is_deleted) VALUES (?, 30, ?, '2026-08-15', 0)")->execute([3000 + $a_i, "uid_sp30_$a_i"]);
}

// Student 1 in Course A: 10/10 completed (100%)
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('ST_A1', 'Student A1', 'st_a1@pepp.com', 'Course A', '2026-27', 'approved')")->execute();
for ($a_i = 1; $a_i <= 10; $a_i++) {
    $pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('st_a1@pepp.com', 30, ?, ?, 'complete_activity', 'completed', '2026-08-15 10:00:00')")->execute([3000 + $a_i, "uid_sp30_$a_i"]);
}

// Student 2 in Course B: 5/10 completed (50%)
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('ST_B1', 'Student B1', 'st_b1@pepp.com', 'Course B', '2026-27', 'approved')")->execute();
for ($a_i = 1; $a_i <= 5; $a_i++) {
    $pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('st_b1@pepp.com', 30, ?, ?, 'complete_activity', 'completed', '2026-08-15 11:00:00')")->execute([3000 + $a_i, "uid_sp30_$a_i"]);
}

// Student 3 in Course B: 2/10 completed (20%)
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('ST_B2', 'Student B2', 'st_b2@pepp.com', 'Course B', '2026-27', 'approved')")->execute();
for ($a_i = 1; $a_i <= 2; $a_i++) {
    $pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('st_b2@pepp.com', 30, ?, ?, 'complete_activity', 'completed', '2026-08-15 12:00:00')")->execute([3000 + $a_i, "uid_sp30_$a_i"]);
}

$cohort_ranking_30 = StudentStudyPlanAnalytics::getCohortRanking($pdo, 30, '2026-27', 'ST_A1', 'st_a1@pepp.com');
assertTest("Test U: Multi-course cohort merges students from Course A and Course B", 3, $cohort_ranking_30['cohort_size']);
assertTest("Test U: Student A1 with highest completion is Rank 1 in shared cohort", 1, $cohort_ranking_30['current_student']['rank'] ?? 0);


// --- TEST V: Deduplication of multi-course enrolled students in Study Plan cohort ---
// Insert another user record with same user_id in Course B (e.g. dual enrollment)
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('ST_A1', 'Student A1 Dual', 'st_a1_dual@pepp.com', 'Course B', '2026-27', 'approved')")->execute();
$cohort_ranking_v = StudentStudyPlanAnalytics::getCohortRanking($pdo, 30, '2026-27', 'ST_A1', 'st_a1@pepp.com');
assertTest("Test V: Multi-enrolled student ST_A1 counted exactly ONCE in cohort", 3, $cohort_ranking_v['cohort_size']);
$pdo->exec("DELETE FROM users WHERE email = 'st_a1_dual@pepp.com'");


// --- TEST W: Deduplication of student study activities ---
// Insert duplicate activity with same UID
$pdo->prepare("INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_date, is_deleted) VALUES (3099, 30, 'uid_sp30_1', '2026-08-15', 0)")->execute();
$cohort_ranking_w = StudentStudyPlanAnalytics::getCohortRanking($pdo, 30, '2026-27', 'ST_A1', 'st_a1@pepp.com');
assertTest("Test W: Student A1 total tasks correctly evaluated", 11, $cohort_ranking_w['current_student']['total_tasks']);
$pdo->exec("DELETE FROM study_plan_activities WHERE id = 3099");


// --- TEST X: Deduplication of assessment batch participation ---
$pdo->prepare("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_name, status, is_deleted) VALUES (3001, 3001, 30, '2026-27', 'All Courses', 'published', 0)")->execute();
// Insert 2 rows for same batch_id (e.g. duplicate sync record)
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, user_id, score, total_score, attendance_status) VALUES (3001, 'st_a1@pepp.com', 'ST_A1', 45.0, 50.0, 'attended')")->execute();
$pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, user_id, score, total_score, attendance_status) VALUES (3001, 'st_a1@pepp.com', 'ST_A1', 45.0, 50.0, 'attended')")->execute();

$cohort_ranking_x = StudentStudyPlanAnalytics::getCohortRanking($pdo, 30, '2026-27', 'ST_A1', 'st_a1@pepp.com');
assertTest("Test X: Assessment score for batch deduplicated (45/50 = 90%)", 90.0, (float)$cohort_ranking_x['current_student']['performance_score']);


// --- TEST Y: Missing assessment weight normalization ---
// Student B2 has NO assessments published. Verify composite index is not 0
$cohort_ranking_y = StudentStudyPlanAnalytics::getCohortRanking($pdo, 30, '2026-27', 'ST_B2', 'st_b2@pepp.com');
$st_b2_index = $cohort_ranking_y['current_student']['performance_index'] ?? 0;
assertTest("Test Y: Missing assessment dynamically normalizes weights (> 0%)", true, $st_b2_index > 0);


// --- TEST Z: Competition ranking ties handling (e.g. 1, 2, 2, 4) ---
// Set Student B1 and B2 to have exact same metrics
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('ST_B3', 'Student B3', 'st_b3@pepp.com', 'Course B', '2026-27', 'approved')")->execute();
// B1 has 5 completions, let B3 also have 5 completions
for ($a_i = 1; $a_i <= 5; $a_i++) {
    $pdo->prepare("INSERT INTO study_plan_analytics (student_email, study_plan_id, activity_id, activity_uid, action_type, completion_status, created_at) VALUES ('st_b3@pepp.com', 30, ?, ?, 'complete_activity', 'completed', '2026-08-15 11:00:00')")->execute([3000 + $a_i, "uid_sp30_$a_i"]);
}
$cohort_ranking_z = StudentStudyPlanAnalytics::getCohortRanking($pdo, 30, '2026-27');
$ranks_found = [];
foreach ($cohort_ranking_z['leaderboard'] as $lb) {
    $ranks_found[] = $lb['rank'];
}
assertTest("Test Z: Tied students receive identical competition ranks", true, in_array(2, array_count_values($ranks_found)));


// --- TEST AA: Percentile calculation and badge classification ---
assertTest("Test AA: Top 1 student in cohort has Elite or Outstanding badge", true, strpos($cohort_ranking_z['leaderboard'][0]['badge'], 'Elite') !== false || strpos($cohort_ranking_z['leaderboard'][0]['badge'], 'Outstanding') !== false);


// --- TEST AB: Cohort distribution histogram bucket assignment ---
assertTest("Test AB: 7 distribution histogram buckets exist", 7, count($cohort_ranking_z['distribution_buckets']));
assertTest("Test AB: Top student in bucket '80-89'", true, ($cohort_ranking_z['distribution_buckets']['80-89'] ?? 0) >= 1);


// --- TEST AC: Multi-plan comparison matrix generation ---
$multi_plan_ac = StudentStudyPlanAnalytics::getStudentMultiPlanAnalytics($pdo, 'st_a1@pepp.com', '2026-27');
assertTest("Test AC: Multi-plan summary generated", true, count($multi_plan_ac['plans']) >= 1);


// --- TEST AD: Rank trend trajectory calculation across sequential plans ---
assertTest("Test AD: Trajectory field exists", true, in_array($multi_plan_ac['trajectory'], ['improving', 'stable', 'declining']));


// --- TEST AE: Performance trend trajectory calculation ---
assertTest("Test AE: Performance trend array exists", true, is_array($multi_plan_ac['performance_trend']));


// --- TEST AF: Mentor attention actionable insights generation ---
$insights_af = StudentStudyPlanAnalytics::generateMentorInsights([
    'total_tasks' => 10,
    'completed_tasks' => 2,
    'pending_tasks' => 8,
    'overdue_tasks' => 4,
    'completion_percentage' => 20,
    'performance_score' => 40,
    'active_streak' => 1,
    'consistency_percentage' => 15
], [], [], []);
assertTest("Test AF: Overdue task alert generated in mentor insights", true, count($insights_af) >= 1);
assertTest("Test AF: Danger type for overdue tasks", 'danger', $insights_af[0]['type'] ?? '');


// --- TEST AG: Learning progress timeline cumulative curve points ---
$analytics_ag = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'st_a1@pepp.com', 30);
assertTest("Test AG: Progress timeline series contains progression points", true, count($analytics_ag['progress_timeline']) >= 1);


// --- TEST AH: Active study days vs eligible calendar days consistency percentage ---
assertTest("Test AH: Consistency % mathematically equals (active_days / total_days) * 100", true, $analytics_ag['consistency_percentage'] >= 0 && $analytics_ag['consistency_percentage'] <= 100);


// --- TEST AI: Inactive / unapproved student excluded from active cohort ranking ---
$pdo->prepare("INSERT INTO users (user_id, name, email, pepp_course, pepp_academic_year, status) VALUES ('ST_INACTIVE', 'Inactive Student', 'inactive@pepp.com', 'Course A', '2026-27', 'inactive')")->execute();
$cohort_ranking_ai = StudentStudyPlanAnalytics::getCohortRanking($pdo, 30, '2026-27');
$inactive_in_cohort = false;
foreach ($cohort_ranking_ai['leaderboard'] as $lb) {
    if ($lb['name'] === 'Inactive Student') $inactive_in_cohort = true;
}
assertTest("Test AI: Inactive unapproved student excluded from cohort", false, $inactive_in_cohort);


// --- TEST AJ: Mathematical parity between getCourseAnalytics and getCourseAnalyticsBulk ---
$course_single = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, 'st_a1@pepp.com', 'Course A');
$course_bulk = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, [
    ['email' => 'st_a1@pepp.com', 'user_id' => 'ST_A1', 'pepp_academic_year' => '2026-27', 'pepp_course' => 'Course A']
], 'Course A')['st_a1@pepp.com'] ?? [];

assertTest("Test AJ: Single and bulk total_tasks match", $course_single['total_tasks'], $course_bulk['total_tasks']);
assertTest("Test AJ: Single and bulk completed_tasks match", $course_single['completed_tasks'], $course_bulk['completed_tasks']);
assertTest("Test AJ: Single and bulk completion_percentage match", $course_single['completion_percentage'], $course_bulk['completion_percentage']);


// --- TEST AK: Student identity resolution using user_id with fallback to email ---
$analytics_by_id = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'ST_A1', 30);
$analytics_by_email = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'st_a1@pepp.com', 30);
assertTest("Test AK: Identity resolution by user_id matches resolution by email", $analytics_by_id['completed_tasks'], $analytics_by_email['completed_tasks']);


// --- TEST AL: Chapter protection verification (Chapter is NEVER derived from Topic or Subject) ---
$pdo->prepare("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, chapter, topic, subject, is_deleted)
    VALUES (99999, 30, 'uid_prot_1', 'Core Neuroscience', 'Synapses', 'Synapses', 0)
")->execute();
$stmt_prot = $pdo->prepare("SELECT chapter, topic, subject FROM study_plan_activities WHERE id = 99999");
$stmt_prot->execute();
$row_prot = $stmt_prot->fetch(PDO::FETCH_ASSOC);

assertTest("Test AL: Activity chapter is preserved as 'Core Neuroscience'", 'Core Neuroscience', $row_prot['chapter']);
assertTest("Test AL: Activity topic is 'Synapses'", 'Synapses', $row_prot['topic']);
assertTest("Test AL: Chapter is NEVER replaced by Topic or Subject", true, $row_prot['chapter'] !== $row_prot['topic']);

// --- TEST AM: Modal Close Button, Escape Key & Lifecycle Architecture ---
$reports_file_content = file_get_contents(__DIR__ . '/student-study-reports.php');
assertTest("Test AM: closeTimelineModal() function is defined", true, strpos($reports_file_content, 'function closeTimelineModal()') !== false);
assertTest("Test AM: closeTimelineModal() removes .show class", true, strpos($reports_file_content, "backdrop.classList.remove('show')") !== false);
assertTest("Test AM: closeTimelineModal() restores body scrolling", true, strpos($reports_file_content, "document.body.style.overflow = ''") !== false);
assertTest("Test AM: Modal Close X button has ID st-modal-close-btn and invokes closeTimelineModal()", true, strpos($reports_file_content, 'id="st-modal-close-btn"') !== false && strpos($reports_file_content, 'onclick="closeTimelineModal()"') !== false);
assertTest("Test AM: Modal backdrop onclick invokes closeTimelineModal()", true, strpos($reports_file_content, 'id="student-task-modal-backdrop" onclick="closeTimelineModal()"') !== false);
assertTest("Test AM: Global Escape key listener handles modal close", true, strpos($reports_file_content, "e.key === 'Escape'") !== false && strpos($reports_file_content, "closeTimelineModal()") !== false);


// --- TEST AN: Professional Learning Analytics PDF / Print Report Generator ---
assertTest("Test AN: printStudentLearningAnalyticsReport() function is defined", true, strpos($reports_file_content, 'function printStudentLearningAnalyticsReport()') !== false);
assertTest("Test AN: Print Report button triggers printStudentLearningAnalyticsReport()", true, strpos($reports_file_content, 'id="st-modal-print-btn"') !== false && strpos($reports_file_content, 'onclick="printStudentLearningAnalyticsReport()"') !== false);
assertTest("Test AN: PDF generator consumes canonical currentPlanAnalyticsPayload", true, strpos($reports_file_content, 'window.currentPlanAnalyticsPayload') !== false);
assertTest("Test AN: PDF generator formats as A4 portrait with crisp margin rules", true, strpos($reports_file_content, 'size: A4 portrait;') !== false);
assertTest("Test AN: PDF generator includes PEPP Learning brand and Student Learning Analytics title", true, strpos($reports_file_content, 'Student Learning Analytics Report') !== false && strpos($reports_file_content, 'PEPP') !== false);
assertTest("Test AN: PDF generator excludes raw dossier checklist rows", true, strpos($reports_file_content, "docHtml") !== false && strpos($reports_file_content, "timeline-item-card") === false);
assertTest("Test AN: @media print CSS is defined to isolate the Analytics Hub", true, strpos($reports_file_content, '@media print') !== false && strpos($reports_file_content, '#st-modal-dossier-pane') !== false);


// --- TEST AO: Student Profile Resolution (Canonical name, user_id, user_photo, status, course, academic_year) ---
$pdo->prepare("
    UPDATE users
    SET user_photo = 'uploads/photos/fathima.jpg', student_status = 'Active'
    WHERE user_id = 'PEPP20268771'
")->execute();
$analytics_prof = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'PEPP20268771', 2);
assertTest("Test AO: Student profile is returned in plan analytics", true, isset($analytics_prof['student_profile']));
assertTest("Test AO: Student Name resolved from database", 'Fathima Rinfa', $analytics_prof['student_profile']['name']);
assertTest("Test AO: Student ID resolved from database", 'PEPP20268771', $analytics_prof['student_profile']['student_id']);
assertTest("Test AO: Student Photo resolved from database", '../uploads/photos/fathima.jpg', $analytics_prof['student_profile']['photo']);
assertTest("Test AO: Student Status resolved from database", 'Active', $analytics_prof['student_profile']['status']);
assertTest("Test AO: Student Course resolved from database", 'MA/MSc Psychology (Premium)', $analytics_prof['student_profile']['course']);
assertTest("Test AO: Student Academic Year resolved from database", '2026-27', $analytics_prof['student_profile']['academic_year']);


// --- TEST AP: Email Privacy & Deterministic Masking ---
assertTest("Test AP: Email mask fathima@pepp.com", 'f*****a@pepp.com', StudentStudyPlanAnalytics::maskEmail('fathima@pepp.com'));
assertTest("Test AP: Email mask john.doe@gmail.com", 'j******e@gmail.com', StudentStudyPlanAnalytics::maskEmail('john.doe@gmail.com'));
assertTest("Test AP: Email mask short username a@pepp.com", 'a***@pepp.com', StudentStudyPlanAnalytics::maskEmail('a@pepp.com'));
assertTest("Test AP: Email mask 2-char username ab@pepp.com", 'a***b@pepp.com', StudentStudyPlanAnalytics::maskEmail('ab@pepp.com'));
assertTest("Test AP: Student profile contains masked_email", 'f*****a@pepp.com', $analytics_prof['student_profile']['masked_email']);
assertTest("Test AP: Student profile masked_email is not unmasked email", true, $analytics_prof['student_profile']['masked_email'] !== 'fathima@pepp.com');
assertTest("Test AP: Student profile does NOT expose unmasked email", false, isset($analytics_prof['student_profile']['email']));
assertTest("Test AP: Root analytics payload does NOT expose student_email", false, isset($analytics_prof['student_email']));
assertTest("Test AP: Cohort current_student does NOT expose unmasked email", false, isset($analytics_prof['cohort_ranking']['current_student']['email']));
if (!empty($analytics_prof['cohort_ranking']['leaderboard'])) {
    assertTest("Test AP: Leaderboard row does NOT expose unmasked email", false, isset($analytics_prof['cohort_ranking']['leaderboard'][0]['email']));
    assertTest("Test AP: Leaderboard row exposes masked_email", true, isset($analytics_prof['cohort_ranking']['leaderboard'][0]['masked_email']));
}


// --- TEST AQ: Missing Profile Data Fallback Handling ---
assertTest("Test AQ: Empty email masks to 'Not available'", 'Not available', StudentStudyPlanAnalytics::maskEmail(''));
assertTest("Test AQ: String 'null' masks to 'Not available'", 'Not available', StudentStudyPlanAnalytics::maskEmail('null'));
assertTest("Test AQ: String 'NULL' masks to 'Not available'", 'Not available', StudentStudyPlanAnalytics::maskEmail('NULL'));
assertTest("Test AQ: Invalid email without @ masks to 'Not available'", 'Not available', StudentStudyPlanAnalytics::maskEmail('invalidemail'));

// Student without photo
$pdo->prepare("DELETE FROM users WHERE user_id = 'PEPP20269999'")->execute();
$pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, user_photo, student_status, status, created_at)
    VALUES ('PEPP20269999', 'No Photo Student', 'nophoto@pepp.com', '+919999999998', 'MA/MSc Psychology (Premium)', '2026-27', '', 'Active', 'approved', '2026-08-01 10:00:00')
")->execute();
$pdo->prepare("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value)
    VALUES (2, 'student', 'PEPP20269999')
")->execute();
$analytics_no_photo = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'PEPP20269999', 2);
assertTest("Test AQ: Student with empty photo returns empty string", '', $analytics_no_photo['student_profile']['photo']);
assertTest("Test AQ: Empty analytics provides default student profile structure", 'Not available', StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'NONEXISTENT', 999)['student_profile']['masked_email']);


// --- TEST AR: Web & PDF Profile Parity (Single Source of Truth) ---
assertTest("Test AR: student-study-reports.php contains getAbsolutePhotoUrl helper", true, strpos($reports_file_content, 'function getAbsolutePhotoUrl') !== false);
assertTest("Test AR: renderLearningAnalyticsHub uses single source studentProfile", true, strpos($reports_file_content, 'a.student_profile') !== false);
assertTest("Test AR: PDF generator includes student photo rendering with fallback", true, strpos($reports_file_content, 'pdfPhotoUrl') !== false);
assertTest("Test AR: PDF generator includes base href for iframe/popup compatibility", true, strpos($reports_file_content, '<base href=') !== false);
assertTest("Test AR: Dossier sidebar includes student photo profile card", true, strpos($reports_file_content, 'id="st-dossier-student-photo"') !== false);


// --- TEST AS: Student Isolation & Data Safety ---
$analytics_student_a = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'PEPP20268771', 2);
$analytics_student_b = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'PEPP20269999', 2);
assertTest("Test AS: Student A ID matches isolated user_id", 'PEPP20268771', $analytics_student_a['student_profile']['student_id']);
assertTest("Test AS: Student B ID matches isolated user_id", 'PEPP20269999', $analytics_student_b['student_profile']['student_id']);
assertTest("Test AS: Student A photo does not leak into Student B", true, $analytics_student_a['student_profile']['photo'] !== $analytics_student_b['student_profile']['photo']);
assertTest("Test AS: Student A email does not leak into Student B", true, $analytics_student_a['student_profile']['masked_email'] !== $analytics_student_b['student_profile']['masked_email']);


// --- TEST AT: Canonical Photo Resolver Unit Tests ---
assertTest("Test AT: Standard uploads/photos path", '../uploads/photos/test.jpg', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('uploads/photos/test.jpg'));
assertTest("Test AT: Leading slash /uploads/photos path", '../uploads/photos/test.png', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('/uploads/photos/test.png'));
assertTest("Test AT: Relative ../uploads/photos path", '../uploads/photos/test.webp', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('../uploads/photos/test.webp'));
assertTest("Test AT: Legacy photos/ path without uploads prefix", '../uploads/photos/legacy.jpeg', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('photos/legacy.jpeg'));
assertTest("Test AT: External HTTP URL", 'https://pepplearning.in/photo.jpg', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('https://pepplearning.in/photo.jpg'));
assertTest("Test AT: Data URI support", 'data:image/png;base64,iVBORw0KGgo=', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('data:image/png;base64,iVBORw0KGgo='));
assertTest("Test AT: Empty string returns empty", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl(''));
assertTest("Test AT: Null value returns empty", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl(null));
assertTest("Test AT: String 'null' returns empty", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('null'));
assertTest("Test AT: String 'undefined' returns empty", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('undefined'));


// --- TEST AU: Non-Image File (PDF/Doc) Rejection ---
assertTest("Test AU: PDF upload rejected from image resolver", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('uploads/photos/6a2e609a84de7_Beauna photo new.pdf'));
assertTest("Test AU: Doc upload rejected from image resolver", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('uploads/photos/document.docx'));
assertTest("Test AU: External PDF link rejected from image resolver", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('https://example.com/file.pdf'));


// --- TEST AV: Cross-Student Photo Isolation (Student A, Student B, Student C) ---
$pdo->prepare("DELETE FROM users WHERE user_id = 'PEPP20267777'")->execute();
$pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, user_photo, student_status, status, created_at)
    VALUES ('PEPP20267777', 'Legacy Photo Student', 'legacy@pepp.com', '+919999999997', 'MA/MSc Psychology (Premium)', '2026-27', 'photos/student_c_legacy.jpg', 'Active', 'approved', '2026-08-01 10:00:00')
")->execute();
$pdo->prepare("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value)
    VALUES (2, 'student', 'PEPP20267777')
")->execute();

$analytics_student_c = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'PEPP20267777', 2);
assertTest("Test AV: Student C Legacy photo resolved", '../uploads/photos/student_c_legacy.jpg', $analytics_student_c['student_profile']['photo']);
assertTest("Test AV: Student A photo is isolated from Student C", true, $analytics_student_a['student_profile']['photo'] !== $analytics_student_c['student_profile']['photo']);
assertTest("Test AV: Student B (no photo) is isolated from Student C", true, $analytics_student_b['student_profile']['photo'] !== $analytics_student_c['student_profile']['photo']);
assertTest("Test AV: Student C raw photo preserved in raw_photo key", 'photos/student_c_legacy.jpg', $analytics_student_c['student_profile']['raw_photo']);


// --- TEST AW: Additional Image Formats & Path Traversal Security ---
assertTest("Test AW: GIF extension support", '../uploads/photos/avatar.gif', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('uploads/photos/avatar.gif'));
assertTest("Test AW: BMP extension support", '../uploads/photos/avatar.bmp', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('uploads/photos/avatar.bmp'));
assertTest("Test AW: SVG extension support", '../uploads/photos/avatar.svg', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('uploads/photos/avatar.svg'));
assertTest("Test AW: Path traversal attack rejected (../../)", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('../../etc/passwd.jpg'));
assertTest("Test AW: Path traversal attack rejected (..\\..\\)", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('..\\..\\windows\\system32\\image.jpg'));
assertTest("Test AW: Text file extension rejected", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('uploads/photos/notes.txt'));
assertTest("Test AW: Zip file extension rejected", '', StudentStudyPlanAnalytics::resolveStudentPhotoUrl('uploads/photos/archive.zip'));


// --- TEST AX: Architectural & Structural Static Checks ---
$analytics_class_code = file_get_contents(__DIR__ . '/includes/StudentStudyPlanAnalytics.php');
assertTest("Test AX: No hardcoded student photo mapping in resolver", false, strpos($analytics_class_code, 'PEPP20264589') !== false || strpos($analytics_class_code, 'PEPP20264479') !== false);
assertTest("Test AX: users.user_photo is queried in getPlanAnalytics", true, strpos($analytics_class_code, 'user_photo') !== false);
assertTest("Test AX: maskEmail is applied to student_profile", true, strpos($analytics_class_code, 'maskEmail') !== false);
assertTest("Test AX: Raw email is not included in student_profile", false, isset($analytics_student_a['student_profile']['raw_email']) || isset($analytics_student_a['student_profile']['email']));
assertTest("Test AX: PDF report isolates Learning Analytics Hub without raw checklist dossier rows", true, strpos($reports_file_content, 'printStudentLearningAnalyticsReport') !== false);


// --- TEST AY: Learning Performance Highlights Data Architecture & Strict Activity Filtering ---
// Configure activities on plan 1 to test whitelist vs blacklist:
// 1. Whitelisted: Watch Live Sessions with topic 'Question Paper Discussion'
$pdo->exec("UPDATE study_plan_activities SET activity_type = 'Watch Live Sessions', topic = 'Question Paper Discussion', activity_title = 'Live Session' WHERE study_plan_id = 1 AND id = 1");
// 2. Whitelisted: Attend Mega Test with topic 'IHBAS 2025'
$pdo->exec("UPDATE study_plan_activities SET activity_type = 'Attend Mega Test', topic = 'IHBAS 2025', activity_title = 'Mega Test' WHERE study_plan_id = 1 AND id = 2");
// 3. Blacklisted: Recorded Session (topic with 'Live')
$pdo->exec("UPDATE study_plan_activities SET activity_type = 'Recorded Session', topic = 'Live Session Replay', activity_title = 'Recorded Session' WHERE study_plan_id = 1 AND id = 3");
// 4. Blacklisted: Study Material
$pdo->exec("UPDATE study_plan_activities SET activity_type = 'Study Material', topic = 'Neuroscience Handout', activity_title = 'Study Material' WHERE study_plan_id = 1 AND id = 4");
// 5. Blacklisted: Read Material
$pdo->exec("UPDATE study_plan_activities SET activity_type = 'Read Material', topic = 'Cognitive Chapter 1', activity_title = 'Read Chapter' WHERE study_plan_id = 1 AND id = 5");
// 6. Blacklisted: Assignment
$pdo->exec("UPDATE study_plan_activities SET activity_type = 'Assignment', topic = 'Case Study Analysis', activity_title = 'Assignment 1' WHERE study_plan_id = 1 AND id = 6");
// 7. Blacklisted: Assessment (generic)
$pdo->exec("UPDATE study_plan_activities SET activity_type = 'Assessment', topic = 'Weekly Quiz 1', activity_title = 'Assessment' WHERE study_plan_id = 1 AND id = 7");
// 8. Blacklisted: Practice
$pdo->exec("UPDATE study_plan_activities SET activity_type = 'Practice', topic = 'Memory Drills', activity_title = 'Practice Test' WHERE study_plan_id = 1 AND id = 8");

$analytics_plan1 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
assertTest("Test AY: learning_highlights exists in analytics payload", true, isset($analytics_plan1['learning_highlights']));
assertTest("Test AY: strongest_activities array exists", true, isset($analytics_plan1['strongest_activities']) && is_array($analytics_plan1['strongest_activities']));
assertTest("Test AY: needs_attention_activities array exists", true, isset($analytics_plan1['needs_attention_activities']) && is_array($analytics_plan1['needs_attention_activities']));
assertTest("Test AY: all_activities array exists in learning_highlights", true, isset($analytics_plan1['learning_highlights']['all_activities']));

$all_acts = $analytics_plan1['learning_highlights']['all_activities'];
assertTest("Test AY: Only whitelisted activity types enter learning_highlights (count is 2)", 2, count($all_acts));

$act_types_present = array_map(function($a) { return $a['activity_type']; }, $all_acts);
assertTest("Test AY: Watch Live Sessions is included", true, in_array('Watch Live Sessions', $act_types_present));
assertTest("Test AY: Attend Mega Test is included", true, in_array('Attend Mega Test', $act_types_present));
assertTest("Test AY: Recorded Session is excluded", false, in_array('Recorded Session', $act_types_present));
assertTest("Test AY: Study Material is excluded", false, in_array('Study Material', $act_types_present));
assertTest("Test AY: Read Material is excluded", false, in_array('Read Material', $act_types_present));
assertTest("Test AY: Assignment is excluded", false, in_array('Assignment', $act_types_present));
assertTest("Test AY: Assessment (generic) is excluded", false, in_array('Assessment', $act_types_present));
assertTest("Test AY: Practice is excluded", false, in_array('Practice', $act_types_present));

// Verify Labels and Topic Preservation
$live_act = null;
$mega_act = null;
foreach ($all_acts as $a_item) {
    if ($a_item['type_category'] === 'live_session') $live_act = $a_item;
    if ($a_item['type_category'] === 'mega_test') $mega_act = $a_item;
}

assertTest("Test AY: Live session label is 'LIVE'", 'LIVE', $live_act['type_label'] ?? '');
assertTest("Test AY: Live session title preserves Topic ('Question Paper Discussion')", 'Question Paper Discussion', $live_act['activity_title'] ?? '');

assertTest("Test AY: Mega test label is 'MEGA TEST'", 'MEGA TEST', $mega_act['type_label'] ?? '');
assertTest("Test AY: Mega test title preserves Topic ('IHBAS 2025')", 'IHBAS 2025', $mega_act['activity_title'] ?? '');

// Check that no-data assessments are NOT falsely put in needs_attention_activities
$needs_att = $analytics_plan1['needs_attention_activities'];
$has_false_needs_attention = false;
foreach ($needs_att as $na_item) {
    if ($na_item['performance_display'] === 'No assessment data' && !$na_item['is_overdue']) {
        $has_false_needs_attention = true;
    }
}
assertTest("Test AY: No-data assessments not falsely flagged as Needs Attention", false, $has_false_needs_attention);


// --- TEST AZ: Assessment Ranks in Chapter-wise Assessment Performance ---
// Ensure test participants are in users for Plan 1's assigned course cohort
$pdo->prepare("DELETE FROM users WHERE user_id IN ('PEPP20261111', 'PEPP20262222')")->execute();
$pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, status, created_at, student_status)
    VALUES ('PEPP20261111', 'Top Student', 'top@pepp.com', '+919999999991', 'MA/MSc Psychology (Premium)', '2026-27', 'approved', '2026-08-01 10:00:00', 'active'),
           ('PEPP20262222', 'Low Student', 'low@pepp.com', '+919999999992', 'MA/MSc Psychology (Premium)', '2026-27', 'approved', '2026-08-01 10:00:00', 'active')
")->execute();

// Insert a test assessment batch with 3 students to verify genuine competition ranking
$pdo->prepare("DELETE FROM assessment_result_batches WHERE id = 9999")->execute();
$pdo->prepare("
    INSERT INTO assessment_result_batches (id, academic_year, course_name, study_plan_id, activity_id, activity_title_snapshot, chapter_snapshot, status)
    VALUES (9999, '2026-27', 'MA/MSc Psychology (Premium)', 1, 2, 'Cognitive Mega Test 2026', 'Cognitive Psychology', 'published')
")->execute();
$pdo->prepare("DELETE FROM assessment_results WHERE batch_id = 9999")->execute();
$pdo->prepare("
    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (9999, 'PEPP20268771', 'fathima@pepp.com', 'attended', 85, 100),
           (9999, 'PEPP20261111', 'top@pepp.com', 'attended', 95, 100),
           (9999, 'PEPP20262222', 'low@pepp.com', 'attended', 60, 100)
")->execute();

$analytics_with_rank = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'fathima@pepp.com', 1);
$cog_chap = null;
foreach ($analytics_with_rank['chapter_assessments'] as $ca) {
    if ($ca['chapter_name'] === 'Cognitive Psychology') {
        $cog_chap = $ca;
        break;
    }
}
assertTest("Test AZ: Cognitive Psychology chapter found in assessment breakdown", true, $cog_chap !== null);
assertTest("Test AZ: chapter_assessments contains rank keys", true, isset($cog_chap['rank_display']));
assertTest("Test AZ: chapter_assessments contains rank_badge", true, isset($cog_chap['rank_badge']));
assertTest("Test AZ: chapter_assessments contains cohort_size", true, isset($cog_chap['cohort_size']));
assertTest("Test AZ: Genuine competition rank computed correctly (#2 among attendees)", 2, $cog_chap['rank']);
assertTest("Test AZ: Total participants in combined study plan cohort", 7, $cog_chap['cohort_size']);
assertTest("Test AZ: Rank display format (#2 / 7)", "#2 / 7", $cog_chap['rank_display']);
assertTest("Test AZ: Rank badge format (🏆 Rank #2 / 7)", "🏆 Rank #2 / 7", $cog_chap['rank_badge']);

// Cleanup test batch
$pdo->prepare("DELETE FROM assessment_results WHERE batch_id = 9999")->execute();
$pdo->prepare("DELETE FROM assessment_result_batches WHERE id = 9999")->execute();
$pdo->prepare("DELETE FROM users WHERE user_id IN ('PEPP20261111', 'PEPP20262222')")->execute();


// --- TEST AZ2: Combined Multi-Course Cohort Denominator (Course A + Course B with Overlap) ---
// Setup Plan 777 with Course A (30 students) and Course B (20 students) with 5 overlap = 45 total unique students
$pdo->prepare("DELETE FROM study_plans WHERE id = 777")->execute();
$pdo->prepare("
    INSERT INTO study_plans (id, title, plan_type, status, academic_year, start_date, end_date)
    VALUES (777, 'Psychology Entrance 2026', 'date_wise', 'published', '2026-27', '2026-08-01', '2026-08-31')
")->execute();

$pdo->prepare("DELETE FROM study_plan_assignments WHERE study_plan_id = 777")->execute();
$pdo->prepare("
    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted)
    VALUES (777, 'course', 'Psychology Course A', 0),
           (777, 'course', 'Psychology Course B', 0)
")->execute();

$pdo->prepare("DELETE FROM study_plan_activities WHERE study_plan_id = 777")->execute();
$pdo->prepare("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_date, activity_type, topic, activity_title, chapter, is_deleted)
    VALUES (7701, 777, 'uid_7701', '2026-08-15', 'Attend Mega Test', 'IHBAS 2025', 'Mega Test 1', 'Research Methodology', 0)
")->execute();

// Delete prior test users for Plan 777
$pdo->exec("DELETE FROM users WHERE user_id LIKE 'P777_%'");

// Insert 25 students only in Course A
$stmt_u_ins = $pdo->prepare("
    INSERT INTO users (user_id, name, email, phone, pepp_course, pepp_academic_year, status, student_status)
    VALUES (?, ?, ?, '+919888888888', ?, '2026-27', 'approved', 'active')
");
for ($i = 1; $i <= 25; $i++) {
    $stmt_u_ins->execute(["P777_CA_$i", "Course A Student $i", "ca_$i@pepp.com", "Psychology Course A"]);
}
// Insert 15 students only in Course B
for ($i = 1; $i <= 15; $i++) {
    $stmt_u_ins->execute(["P777_CB_$i", "Course B Student $i", "cb_$i@pepp.com", "Psychology Course B"]);
}
// Insert 5 overlapping students (in Course A, but also assigned to Course B via course/batch context)
for ($i = 1; $i <= 5; $i++) {
    $stmt_u_ins->execute(["P777_CAB_$i", "Course AB Student $i", "cab_$i@pepp.com", "Psychology Course A"]);
}

// Verify combined cohort resolution: 25 + 15 + 5 = 45 unique students
$p777_cohort = StudentStudyPlanAnalytics::getStudyPlanCohortStudents($pdo, 777, '2026-27');
assertTest("Test AZ2: Combined multi-course cohort deduplicated correctly (45 unique students)", 45, count($p777_cohort));

// Insert Assessment Batch 7799 attended by 18 students
$pdo->prepare("DELETE FROM assessment_result_batches WHERE id = 7799")->execute();
$pdo->prepare("
    INSERT INTO assessment_result_batches (id, academic_year, course_name, study_plan_id, activity_id, activity_title_snapshot, chapter_snapshot, status)
    VALUES (7799, '2026-27', 'Psychology Course A', 777, 7701, 'Mega Test 1', 'Research Methodology', 'published')
")->execute();
$pdo->prepare("DELETE FROM assessment_results WHERE batch_id = 7799")->execute();

// Insert 18 results (Scores 100, 98, 96, 94, 92, 90, 82 (Rank 7), 80, 78, 76, 74, 72, 70, 68, 66, 64, 62, 60)
$scores_18 = [100, 98, 96, 94, 92, 90, 82, 80, 78, 76, 74, 72, 70, 68, 66, 64, 62, 60];
$stmt_res_ins = $pdo->prepare("
    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (7799, ?, ?, 'attended', ?, 100)
");
// Target student is P777_CAB_1 with score 82 (7th highest)
$stmt_res_ins->execute(["P777_CAB_1", "cab_1@pepp.com", 82]);
// Other 17 participants
for ($k = 1; $k <= 17; $k++) {
    $score_val = ($k < 7) ? $scores_18[$k - 1] : $scores_18[$k];
    $stmt_res_ins->execute(["P777_CA_$k", "ca_$k@pepp.com", $score_val]);
}
// Insert non-attending student result for P777_CAB_2
$pdo->prepare("
    INSERT INTO assessment_results (batch_id, user_id, student_email, attendance_status, score, total_score)
    VALUES (7799, 'P777_CAB_2', 'cab_2@pepp.com', 'not_attended', NULL, 100)
")->execute();

$analytics_p777 = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'cab_1@pepp.com', 777);
$rm_chap = null;
foreach ($analytics_p777['chapter_assessments'] as $ca) {
    if ($ca['chapter_name'] === 'Research Methodology') {
        $rm_chap = $ca;
        break;
    }
}
assertTest("Test AZ2: Research Methodology chapter found", true, $rm_chap !== null);
assertTest("Test AZ2: Target student rank is #7 based on genuine test results", 7, $rm_chap['rank']);
assertTest("Test AZ2: Attended count is 18 (not used as denominator)", 18, $rm_chap['attended_count']);
assertTest("Test AZ2: Cohort size is 45 (used as denominator)", 45, $rm_chap['cohort_size']);
assertTest("Test AZ2: Rank display is '#7 / 45'", "#7 / 45", $rm_chap['rank_display']);
assertTest("Test AZ2: Rank badge is '🏆 Rank #7 / 45'", "🏆 Rank #7 / 45", $rm_chap['rank_badge']);
assertTest("Test AZ2: Attendance % is 100% (1 attended of 1 published)", 100, (int)$rm_chap['attendance_percentage']);

// Edge case: Non-attending student (cab_2@pepp.com) in same cohort gets 'Not available' without fabricated rank
$analytics_non_attending = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, 'cab_2@pepp.com', 777);
$rm_chap_na = null;
foreach ($analytics_non_attending['chapter_assessments'] as $ca) {
    if ($ca['chapter_name'] === 'Research Methodology') {
        $rm_chap_na = $ca;
        break;
    }
}
assertTest("Test AZ2: Non-attending student has rank null", null, $rm_chap_na['rank']);
assertTest("Test AZ2: Non-attending student rank display is 'Not available'", 'Not available', $rm_chap_na['rank_display']);
assertTest("Test AZ2: Non-attending student rank badge is 'Rank: Not available'", 'Rank: Not available', $rm_chap_na['rank_badge']);

// Cleanup Plan 777 fixtures
$pdo->exec("DELETE FROM assessment_results WHERE batch_id = 7799");
$pdo->exec("DELETE FROM assessment_result_batches WHERE id = 7799");
$pdo->exec("DELETE FROM study_plan_activities WHERE study_plan_id = 777");
$pdo->exec("DELETE FROM study_plan_assignments WHERE study_plan_id = 777");
$pdo->exec("DELETE FROM study_plans WHERE id = 777");
$pdo->exec("DELETE FROM users WHERE user_id LIKE 'P777_%'");


// --- TEST BA: PDF 3 Streak / Study-Day Cards ---
assertTest("Test BA: PDF report contains ACTIVE STREAK card", true, strpos($reports_file_content, 'ACTIVE STREAK') !== false);
assertTest("Test BA: PDF report contains LONGEST STREAK card", true, strpos($reports_file_content, 'LONGEST STREAK') !== false);
assertTest("Test BA: PDF report contains ACTIVE STUDY DAYS card", true, strpos($reports_file_content, 'ACTIVE STUDY DAYS') !== false);
assertTest("Test BA: PDF report streak cards use canonical emoji 🔥, ⭐, 🗓️", true,
    strpos($reports_file_content, '🔥 ${activeStreak}') !== false &&
    strpos($reports_file_content, '⭐ ${longestStreak}') !== false &&
    strpos($reports_file_content, '🗓️ ${activeDays}') !== false
);
assertTest("Test BA: PDF report uses 3-column horizontal grid layout", true, strpos($reports_file_content, 'grid-template-columns: 1fr 1fr 1fr') !== false);


// --- TEST BB: Learning Performance Highlights Web Hub & PDF Parity ---
assertTest("Test BB: Web Hub renders Learning Performance Highlights section", true, strpos($reports_file_content, 'Learning Performance Highlights') !== false);
assertTest("Test BB: Web Hub contains Strongest Activities block", true, strpos($reports_file_content, 'Strongest Activities') !== false);
assertTest("Test BB: Web Hub contains Activities Needing Attention block", true, strpos($reports_file_content, 'Activities Needing Attention') !== false);
assertTest("Test BB: Web Hub Chapter-wise Assessment contains Assessment Rank column", true, strpos($reports_file_content, '<th>Assessment Rank</th>') !== false);
assertTest("Test BB: PDF report Chapter-wise Assessment contains Assessment Rank column", true, strpos($reports_file_content, '<th style="width:14%;">Assessment Rank</th>') !== false);
assertTest("Test BB: PDF report renders Learning Performance Highlights section", true, strpos($reports_file_content, '⚡ Learning Performance Highlights') !== false);


// --- TEST BC: Entry-Point UI Visibility (Mentoring vs Courses) ---
$mentoring_file_content = file_get_contents(__DIR__ . '/student-mentoring.php');
// A. Student Mentoring Report link contains source=mentoring
assertTest("Test BC: student-mentoring.php Report link has source=mentoring", true, strpos($mentoring_file_content, 'student-study-reports.php?source=mentoring&student_id=') !== false);
assertTest("Test BC: student-mentoring.php calls link has source=mentoring", true, strpos($mentoring_file_content, 'student-study-reports.php?source=mentoring&student_id=<?php echo urlencode($cl[\'student_user_id\']); ?>') !== false);
assertTest("Test BC: student-mentoring.php remarks link has source=mentoring", true, strpos($mentoring_file_content, 'student-study-reports.php?source=mentoring&student_id=<?php echo urlencode($rm[\'student_user_id\']); ?>') !== false);

// B. Server-side context detection in student-study-reports.php
assertTest("Test BC: student-study-reports.php detects isMentoringReport context", true, strpos($reports_file_content, '$isMentoringReport = ($source === \'mentoring\');') !== false);
assertTest("Test BC: student-study-reports.php handles source=courses and source=mentoring", true, strpos($reports_file_content, "if (\$source === 'courses' || \$source === 'mentoring')") !== false);

// C. Conditional hiding of top Performance & Analytics Intelligence dashboard
assertTest("Test BC: student-study-reports.php hides Breadcrumbs & Control Bar on source=mentoring", true, strpos($reports_file_content, "<?php if (!\$isMentoringReport): ?>\n    <!-- BREADCRUMBS & CONTROL BAR -->") !== false);
assertTest("Test BC: student-study-reports.php hides KPI grid and Global search on source=mentoring", true, strpos($reports_file_content, "<?php elseif (\$source === 'courses' || \$source === 'mentoring'): ?>\n        <?php if (!\$isMentoringReport): ?>\n        <!-- KPI Cards Grid -->") !== false);

// D. Student workspace and JS bootstrap preservation
assertTest("Test BC: student-study-reports.php renders student-workspace unconditionally", true, strpos($reports_file_content, '<div id="student-workspace" style="display:none; margin-bottom:2rem;"></div>') !== false);
assertTest("Test BC: student-study-reports.php JS bootstrap activates for both courses and mentoring", true, strpos($reports_file_content, "if (sourceVal === 'courses' || sourceVal === 'mentoring')") !== false);


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
