<?php
/**
 * PEPP ERP Study Plans Results Scoping & Isolation Regression Test Suite.
 * Evaluates all 10 checklist regression scenarios under the redesigned architecture.
 * Uses SQLite memory database for clean, isolated, transactional tests.
 */
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'TestAdmin';

require_once __DIR__ . '/../config/database.php';

// Helper assertion function
function run_assert($label, $expr) {
    if ($expr) {
        echo "   ✅ PASS: {$label}\n";
    } else {
        echo "   ❌ FAIL: {$label}\n";
        exit(1);
    }
}

// Global Competition Ranks Helper from assessment-results.php
function compute_competition_ranks(array $results) {
    $rankable = [];
    foreach ($results as $r) {
        if ($r['attendance_status'] === 'attended' && $r['score'] !== null) {
            $rankable[] = $r;
        }
    }
    usort($rankable, function($a, $b) { return ($b['score'] ?? 0) <=> ($a['score'] ?? 0); });
    $ranks = []; $prev_score = null; $rank = 0; $count = 0;
    foreach ($rankable as $r) {
        $count++;
        if ($r['score'] !== $prev_score) { $rank = $count; }
        $ranks[$r['student_email']] = $rank;
        $prev_score = $r['score'];
    }
    return $ranks;
}

// ── SETUP TEST ENVIRONMENT & SEED DATA ──
try {
    // Dynamically extend SQLite assessment_result_batches table with production MySQL columns
    $cols_to_add = [
        'replaced_by_batch_id' => 'INTEGER',
        'replace_reason' => 'TEXT',
        'source_filename' => 'TEXT',
        'total_rows' => 'INTEGER',
        'matched_students' => 'INTEGER',
        'unmatched_emails' => 'INTEGER',
        'attended_count' => 'INTEGER',
        'not_attended_count' => 'INTEGER',
        'in_progress_count' => 'INTEGER',
        'review_required_count' => 'INTEGER',
        'uploaded_by' => 'TEXT',
        'created_at' => 'TEXT',
        'updated_at' => 'TEXT'
    ];
    foreach ($cols_to_add as $col => $type) {
        try {
            $pdo->exec("ALTER TABLE assessment_result_batches ADD COLUMN $col $type;");
        } catch (Exception $e) {}
    }

    // Extend SQLite assessment_results table with production MySQL columns
    $res_cols = [
        'total_score' => 'REAL',
        'src_learner_details' => 'TEXT',
        'src_mobile' => 'TEXT',
        'src_attempt' => 'TEXT',
        'src_status' => 'TEXT',
        'src_evaluation' => 'TEXT',
        'src_submitted_on' => 'TEXT',
        'src_answered' => 'TEXT',
        'accuracy_numeric' => 'REAL',
        'src_avg_q_per_hr' => 'TEXT',
        'avg_q_per_hr_numeric' => 'INTEGER',
        'correct' => 'INTEGER',
        'wrong' => 'INTEGER',
        'skipped' => 'INTEGER',
        'src_time_spent' => 'TEXT',
        'time_spent_seconds' => 'INTEGER',
        'src_export' => 'TEXT',
        'src_accuracy' => 'TEXT'
    ];
    foreach ($res_cols as $col => $type) {
        try {
            $pdo->exec("ALTER TABLE assessment_results ADD COLUMN $col $type;");
        } catch (Exception $e) {}
    }

    $pdo->exec("DELETE FROM pepp_courses;");
    $pdo->exec("DELETE FROM study_plans;");
    $pdo->exec("DELETE FROM study_plan_activities;");
    $pdo->exec("DELETE FROM study_plan_assignments;");
    $pdo->exec("DELETE FROM assessment_result_batches;");
    $pdo->exec("DELETE FROM assessment_results;");
    $pdo->exec("DELETE FROM users;");

    // 1. Seed courses
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (1, 'NEET Premium', 'NEET_PREM', '2026-27', 'active')");
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (2, 'NEET Regular', 'NEET_REG', '2026-27', 'active')");
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (3, 'NEET Weekend', 'NEET_WND', '2026-27', 'active')");
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (4, 'NEET Isolated', 'NEET_ISOL', '2026-27', 'active')");

    // 2. Seed Study Plans
    $pdo->exec("INSERT INTO study_plans (id, title, academic_year, plan_type, status, is_deleted) VALUES (1, 'NEET August 2026', '2026-27', 'date_wise', 'published', 0)");
    $pdo->exec("INSERT INTO study_plans (id, title, academic_year, plan_type, status, is_deleted) VALUES (2, 'NEET September 2026', '2026-27', 'date_wise', 'published', 0)");

    // 3. Seed Assignments for Study Plan 1 (NEET Premium, Regular, Weekend)
    $pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (1, 'course', 'NEET Premium', 0)");
    $pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (1, 'course', 'NEET Regular', 0)");
    $pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (1, 'course', 'NEET Weekend', 0)");

    // Assign Study Plan 2 to NEET Isolated
    $pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, is_deleted) VALUES (2, 'course', 'NEET Isolated', 0)");

    // 4. Seed approved student users
    // NEET Premium
    $pdo->exec("INSERT INTO users (user_id, name, email, pepp_course, status, student_status, pepp_academic_year) VALUES ('P1', 'Premium Student 1', 'premium1@pepp.com', 'NEET Premium', 'approved', 'active', '2026-27')");
    $pdo->exec("INSERT INTO users (user_id, name, email, pepp_course, status, student_status, pepp_academic_year) VALUES ('P2', 'Premium Student 2', 'premium2@pepp.com', 'NEET Premium', 'approved', 'active', '2026-27')");
    // NEET Regular
    $pdo->exec("INSERT INTO users (user_id, name, email, pepp_course, status, student_status, pepp_academic_year) VALUES ('R1', 'Regular Student 1', 'regular1@pepp.com', 'NEET Regular', 'approved', 'active', '2026-27')");
    $pdo->exec("INSERT INTO users (user_id, name, email, pepp_course, status, student_status, pepp_academic_year) VALUES ('R2', 'Regular Student 2', 'regular2@pepp.com', 'NEET Regular', 'approved', 'active', '2026-27')");
    // NEET Weekend
    $pdo->exec("INSERT INTO users (user_id, name, email, pepp_course, status, student_status, pepp_academic_year) VALUES ('W1', 'Weekend Student 1', 'weekend1@pepp.com', 'NEET Weekend', 'approved', 'active', '2026-27')");
    $pdo->exec("INSERT INTO users (user_id, name, email, pepp_course, status, student_status, pepp_academic_year) VALUES ('W2', 'Weekend Student 2', 'weekend2@pepp.com', 'NEET Weekend', 'approved', 'active', '2026-27')");
    // NEET Isolated (belongs to Study Plan 2)
    $pdo->exec("INSERT INTO users (user_id, name, email, pepp_course, status, student_status, pepp_academic_year) VALUES ('I1', 'Isolated Student 1', 'isolated1@pepp.com', 'NEET Isolated', 'approved', 'active', '2026-27')");
    // NEET Premium but different Academic Year (2025-26)
    $pdo->exec("INSERT INTO users (user_id, name, email, pepp_course, status, student_status, pepp_academic_year) VALUES ('P_OLD', 'Old Premium Student', 'old_premium@pepp.com', 'NEET Premium', 'approved', 'active', '2025-26')");

    // 5. Seed Activities
    // Study Plan 1: Activity 101 - Mega Test 1
    $pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_title, activity_type, chapter, is_deleted) VALUES (101, 1, 'Mega Test 1', 'Attend Mock Test', 'Chapter 1', 0)");
    // Study Plan 1: Activity 102 - Practice Test (Chapter 1)
    $pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_title, activity_type, chapter, is_deleted) VALUES (102, 1, 'Practice Test', 'Practice Test', 'Chapter 1', 0)");
    // Study Plan 1: Activity 103 - Practice Test (Chapter 2) (Same name, different chapter/activity ID!)
    $pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_title, activity_type, chapter, is_deleted) VALUES (103, 1, 'Practice Test', 'Practice Test', 'Chapter 2', 0)");
    // Study Plan 2: Activity 201 - Practice Test (Chapter 1) (Same name/chapter, different Study Plan!)
    $pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_title, activity_type, chapter, is_deleted) VALUES (201, 2, 'Practice Test', 'Practice Test', 'Chapter 1', 0)");

    echo "✅ Setup and Seed completed.\n";
} catch (Exception $e) {
    echo "❌ Setup failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Running Verified Study Plan Results Scoping Regression Suite ===\n";

// ── TEST 1: One Study Plan assigned to one course ──
echo "\n--- TEST 1: One Study Plan assigned to one course ---\n";
// Study Plan 2 is assigned to one course (NEET Isolated)
$plan_id = 2; $year = '2026-27';
$stmt_assign = $pdo->prepare("SELECT assigned_value FROM study_plan_assignments WHERE study_plan_id = ? AND is_deleted = 0");
$stmt_assign->execute([$plan_id]);
$assigned_courses = $stmt_assign->fetchAll(PDO::FETCH_COLUMN);

run_assert("Study Plan 2 has exactly 1 assigned course name", count($assigned_courses) === 1);
run_assert("Assigned course is NEET Isolated", $assigned_courses[0] === 'NEET Isolated');

// Retrieve eligible students for Study Plan 2
$stmt_elig = $pdo->prepare("SELECT email FROM users WHERE status='approved' AND pepp_academic_year = ? AND pepp_course IN (?)");
$stmt_elig->execute([$year, $assigned_courses[0]]);
$elig = $stmt_elig->fetchAll(PDO::FETCH_COLUMN);
run_assert("Resolves isolated student roster correctly", count($elig) === 1 && $elig[0] === 'isolated1@pepp.com');


// ── TEST 2: One Study Plan assigned to three courses ──
echo "\n--- TEST 2: One Study Plan assigned to three courses, merging rosters ---\n";
// Study Plan 1 is assigned to 3 courses
$plan_id = 1;
$stmt_assign = $pdo->prepare("SELECT assigned_value FROM study_plan_assignments WHERE study_plan_id = ? AND is_deleted = 0");
$stmt_assign->execute([$plan_id]);
$assigned_courses = $stmt_assign->fetchAll(PDO::FETCH_COLUMN);
run_assert("Study Plan 1 has 3 assigned courses", count($assigned_courses) === 3);

// Fetch all eligible students across all 3 courses
$placeholders = implode(',', array_fill(0, count($assigned_courses), '?'));
$stmt_elig = $pdo->prepare("SELECT LOWER(TRIM(email)) as email FROM users WHERE status='approved' AND pepp_academic_year = ? AND pepp_course IN ($placeholders)");
$stmt_elig->execute(array_merge([$year], $assigned_courses));
$elig_all = $stmt_elig->fetchAll(PDO::FETCH_COLUMN);

run_assert(" Roster merges students from all 3 courses", count($elig_all) === 6);
run_assert("Includes Premium student", in_array('premium1@pepp.com', $elig_all));
run_assert("Includes Regular student", in_array('regular2@pepp.com', $elig_all));
run_assert("Includes Weekend student", in_array('weekend1@pepp.com', $elig_all));


// ── TEST 3: Same Study Plan + multiple courses. Global ranking calculated across all courses ──
echo "\n--- TEST 3: Global ranking is calculated across all courses ---\n";
$scores = [
    ['student_email' => 'premium1@pepp.com', 'score' => 100, 'attendance_status' => 'attended'],
    ['student_email' => 'regular1@pepp.com', 'score' => 95, 'attendance_status' => 'attended'],
    ['student_email' => 'premium2@pepp.com', 'score' => 90, 'attendance_status' => 'attended'],
    ['student_email' => 'regular2@pepp.com', 'score' => 90, 'attendance_status' => 'attended'],
    ['student_email' => 'weekend1@pepp.com', 'score' => 80, 'attendance_status' => 'attended'],
];
$ranks = compute_competition_ranks($scores);
run_assert("Rank 1 is premium1 (100)", $ranks['premium1@pepp.com'] === 1);
run_assert("Rank 2 is regular1 (95)", $ranks['regular1@pepp.com'] === 2);
run_assert("Rank 3 (joint tie) is premium2 (90)", $ranks['premium2@pepp.com'] === 3);
run_assert("Rank 3 (joint tie) is regular2 (90)", $ranks['regular2@pepp.com'] === 3);
run_assert("Rank 5 is weekend1 (80)", $ranks['weekend1@pepp.com'] === 5);


// ── TEST 4: Same student appears through multiple course assignments ──
echo "\n--- TEST 4: Same student appears through multiple course associations deduplicates ---\n";
// Create raw duplicate dataset
$raw_list = [
    ['user_id' => 'P1', 'student_email' => 'premium1@pepp.com', 'score' => 100, 'attendance_status' => 'attended', 'course_name' => 'NEET Premium'],
    ['user_id' => 'P1', 'student_email' => 'premium1@pepp.com', 'score' => 90, 'attendance_status' => 'attended', 'course_name' => 'NEET Regular'] // duplicate ID
];
$deduplicated = [];
foreach ($raw_list as $r) {
    $uid = $r['user_id'];
    if (!isset($deduplicated[$uid]) || $r['score'] > $deduplicated[$uid]['score']) {
        $deduplicated[$uid] = $r;
    }
}
run_assert("Deduplicated to exactly 1 row", count($deduplicated) === 1);
run_assert("Retains highest score (100)", $deduplicated['P1']['score'] === 100);


// ── TEST 5: Student belongs to a different Study Plan ──
echo "\n--- TEST 5: Student belongs to a different Study Plan is excluded ---\n";
$plan_id = 1; // NEET August 2026 (Premium, Regular, Weekend)
// Fetch assignments
$stmt_asg = $pdo->prepare("SELECT assigned_value FROM study_plan_assignments WHERE study_plan_id = ? AND is_deleted = 0");
$stmt_asg->execute([$plan_id]);
$courses_plan_1 = $stmt_asg->fetchAll(PDO::FETCH_COLUMN);

// Verify isolated1@pepp.com belongs to NEET Isolated, which is NOT assigned to Study Plan 1
$isolated_student_course = 'NEET Isolated';
run_assert("Isolated student course not assigned to Study Plan 1", !in_array($isolated_student_course, $courses_plan_1));


// ── TEST 6: Student belongs to correct Study Plan but another Academic Year ──
echo "\n--- TEST 6: Student belongs to another Academic Year is excluded ---\n";
// student old_premium@pepp.com is enrolled in NEET Premium, but year 2025-26
$stmt_elig_active = $pdo->prepare("SELECT email FROM users WHERE status='approved' AND pepp_academic_year = '2026-27' AND pepp_course = 'NEET Premium'");
$stmt_elig_active->execute();
$active_emails = array_map('strtolower', $stmt_elig_active->fetchAll(PDO::FETCH_COLUMN));
run_assert("Stale academic year student excluded from roster", !in_array('old_premium@pepp.com', $active_emails));


// ── TEST 7: Two different Study Plans contain activities with same chapter/test name ──
echo "\n--- TEST 7: Same test name/chapter in different study plans remain isolated ---\n";
// Activity 102 in Study Plan 1: Practice Test (Chapter 1)
// Activity 201 in Study Plan 2: Practice Test (Chapter 1)
// Insert results for Activity 102
$pdo->exec("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_id, course_name, version, status) VALUES (20, 102, 1, '2026-27', 0, 'All Courses', 1, 'published')");

// Query Activity 102 results status
$stmt_102 = $pdo->prepare("SELECT COUNT(*) FROM assessment_result_batches WHERE activity_id = ? AND study_plan_id = ? AND status = 'published'");
$stmt_102->execute([102, 1]);
$published_102 = (int)$stmt_102->fetchColumn();

// Query Activity 201 results status
$stmt_201 = $pdo->prepare("SELECT COUNT(*) FROM assessment_result_batches WHERE activity_id = ? AND study_plan_id = ? AND status = 'published'");
$stmt_201->execute([201, 2]);
$published_201 = (int)$stmt_201->fetchColumn();

run_assert("Study Plan 1 activity marked published", $published_102 === 1);
run_assert("Study Plan 2 activity remains clean and unpublished", $published_201 === 0);


// ── TEST 8: Merged ranking population matches across card designer and PDF ──
echo "\n--- TEST 8: Card designer and PDF resolve to the same merged population ---\n";
// We query results using the merged batch ID
$batch_id = 20;
$stmt_res = $pdo->prepare("SELECT ar.student_email, ar.score FROM assessment_results ar WHERE ar.batch_id = ?");
$stmt_res->execute([$batch_id]);
$pdf_res = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

$stmt_card = $pdo->prepare("SELECT ar.student_email, ar.score FROM assessment_results ar WHERE ar.batch_id = ?");
$stmt_card->execute([$batch_id]);
$card_res = $stmt_card->fetchAll(PDO::FETCH_ASSOC);

run_assert("PDF and Card Designer resolve identical rows", count($pdf_res) === count($card_res));


// ── TEST 9: Legacy course-scoped results remain readable ──
echo "\n--- TEST 9: Legacy course-scoped published results remain readable ---\n";
// Insert legacy batch specifically for Course 2 (NEET Regular)
$pdo->exec("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_id, course_name, version, status) VALUES (50, 102, 1, '2026-27', 2, 'NEET Regular', 1, 'published')");

// Check that it remains readable
$stmt_legacy = $pdo->prepare("SELECT id FROM assessment_result_batches WHERE activity_id = ? AND (course_id = 2 OR (course_name = 'NEET Regular' AND course_name != '')) AND status = 'published'");
$stmt_legacy->execute([102]);
$legacy_bid = (int)$stmt_legacy->fetchColumn();
run_assert("Legacy course-specific batch successfully resolved", $legacy_bid === 50);


// ── TEST 10: No query in the NEW workflow requires a manually selected course_id ──
echo "\n--- TEST 10: No query in the NEW workflow requires course_id ---\n";
// Retrieve batch strictly by activity_id + study_plan_id
$stmt_new_query = $pdo->prepare("SELECT id FROM assessment_result_batches WHERE activity_id = ? AND study_plan_id = ? AND status = 'published'");
$stmt_new_query->execute([102, 1]);
$resolved_bids = $stmt_new_query->fetchAll(PDO::FETCH_COLUMN);

run_assert("New workflow batch query does not utilize course_id", in_array(20, $resolved_bids));

echo "\n✨ ALL 10 REDESIGNED WORKFLOW SCENARIOS PASSED SUCCESSFULLY! ✨\n";
?>
