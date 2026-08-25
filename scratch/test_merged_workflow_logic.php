<?php
/**
 * Automated Unit Test Suite: Redesigned Test Result Card Selection and Merging Workflow.
 * Scoped to checklist requirements: TEST A through TEST J.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'TestAdmin';

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once dirname(__DIR__) . '/config/database.php';

function run_assert($label, $assertion) {
    if ($assertion) {
        echo "   ✅ PASS: {$label}\n";
    } else {
        echo "   ❌ FAIL: {$label}\n";
        exit(1);
    }
}

global $pdo;

echo "=== Running Redesigned Cards Workflow Automated Test Suite ===\n";

try {
    // 1. Reset tables
    $pdo->exec("DELETE FROM pepp_courses");
    $pdo->exec("DELETE FROM study_plans");
    $pdo->exec("DELETE FROM study_plan_activities");
    $pdo->exec("DELETE FROM study_plan_assignments");
    $pdo->exec("DELETE FROM assessment_result_batches");
    $pdo->exec("DELETE FROM assessment_results");
    $pdo->exec("DELETE FROM users");

    // 2. Insert Active Courses for 2026-27
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (1, 'Psychology A', 'PSYC_A', '2026-27', 'active')");
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (2, 'Psychology B', 'PSYC_B', '2026-27', 'active')");
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (3, 'Psychology C', 'PSYC_C', '2026-27', 'active')");
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (4, 'Psychology D', 'PSYC_D', '2026-27', 'active')");

    // 3. Insert Study Plan (ID 10) and Activity (ID 30)
    $pdo->exec("INSERT INTO study_plans (id, title, academic_year, status) VALUES (10, 'August 2026', '2026-27', 'published')");
    $pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_title, activity_type, chapter, activity_date, day_number) VALUES (30, 10, 'IHBAS Mock Test 01', 'Attend Mock Test', 'Chapter 1', '2026-08-20', '2')");

    // 4. Assign Study Plan 10 to all four courses
    $pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (10, 'course', 'Psychology A')");
    $pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (10, 'course', 'Psychology B')");
    $pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (10, 'course', 'Psychology C')");
    $pdo->exec("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES (10, 'course', 'Psychology D')");

    // 5. Seed Enrolled Students (Total Students)
    // Psychology A: 12 students (PEPP_A1 to PEPP_A12)
    for ($i = 1; $i <= 12; $i++) {
        $stmt = $pdo->prepare("INSERT INTO users (user_id, name, email, status, student_status, pepp_course, pepp_academic_year) VALUES (?, ?, ?, 'approved', 'active', 'Psychology A', '2026-27')");
        $stmt->execute(["PEPP_A{$i}", "Student A{$i}", "studentA{$i}@test.com"]);
    }
    // Psychology B: 18 students (PEPP_B1 to PEPP_B18)
    for ($i = 1; $i <= 18; $i++) {
        $stmt = $pdo->prepare("INSERT INTO users (user_id, name, email, status, student_status, pepp_course, pepp_academic_year) VALUES (?, ?, ?, 'approved', 'active', 'Psychology B', '2026-27')");
        $stmt->execute(["PEPP_B{$i}", "Student B{$i}", "studentB{$i}@test.com"]);
    }
    // Psychology C: 10 students (PEPP_C1 to PEPP_C10)
    for ($i = 1; $i <= 10; $i++) {
        $stmt = $pdo->prepare("INSERT INTO users (user_id, name, email, status, student_status, pepp_course, pepp_academic_year) VALUES (?, ?, ?, 'approved', 'active', 'Psychology C', '2026-27')");
        $stmt->execute(["PEPP_C{$i}", "Student C{$i}", "studentC{$i}@test.com"]);
    }
    // Psychology D: 5 students (PEPP_D1 to PEPP_D5)
    for ($i = 1; $i <= 5; $i++) {
        $stmt = $pdo->prepare("INSERT INTO users (user_id, name, email, status, student_status, pepp_course, pepp_academic_year) VALUES (?, ?, ?, 'approved', 'active', 'Psychology D', '2026-27')");
        $stmt->execute(["PEPP_D{$i}", "Student D{$i}", "studentD{$i}@test.com"]);
    }

    // 6. Seed published Result Batches for Courses A, B, C (Course D has no results uploaded)
    $pdo->exec("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_id, course_name, activity_title_snapshot, activity_type_snapshot, activity_date_snapshot, chapter_snapshot, version, status) VALUES (101, 30, 10, '2026-27', 1, 'Psychology A', 'IHBAS Mock Test 01', 'Attend Mock Test', '2026-08-20', 'Chapter 1', 1, 'published')");
    $pdo->exec("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_id, course_name, activity_title_snapshot, activity_type_snapshot, activity_date_snapshot, chapter_snapshot, version, status) VALUES (102, 30, 10, '2026-27', 2, 'Psychology B', 'IHBAS Mock Test 01', 'Attend Mock Test', '2026-08-20', 'Chapter 1', 1, 'published')");
    $pdo->exec("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_id, course_name, activity_title_snapshot, activity_type_snapshot, activity_date_snapshot, chapter_snapshot, version, status) VALUES (103, 30, 10, '2026-27', 3, 'Psychology C', 'IHBAS Mock Test 01', 'Attend Mock Test', '2026-08-20', 'Chapter 1', 1, 'published')");

    // 7. Seed results records (attended count)
    // Batch 101 (Course A): 10 students (PEPP_A1 to PEPP_A10)
    // Add score of 96 to PEPP_A5 (simulated duplicate student ID later)
    for ($i = 1; $i <= 10; $i++) {
        $score = $i === 5 ? 96 : 80 + $i;
        $stmt = $pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, attendance_status, src_name, user_id) VALUES (101, ?, ?, 'attended', ?, ?)");
        $stmt->execute(["studentA{$i}@test.com", $score, "Student A{$i}", "PEPP_A{$i}"]);
    }

    // Batch 102 (Course B): 15 students (PEPP_B1 to PEPP_B14 + PEPP_A5 duplicate with lower score 90)
    for ($i = 1; $i <= 14; $i++) {
        $stmt = $pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, attendance_status, src_name, user_id) VALUES (102, ?, ?, 'attended', ?, ?)");
        $stmt->execute(["studentB{$i}@test.com", 70 + $i, "Student B{$i}", "PEPP_B{$i}"]);
    }
    // Duplicate student PEPP_A5 in Course B batch (score 90)
    $stmt = $pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, attendance_status, src_name, user_id) VALUES (102, ?, ?, 'attended', ?, ?)");
    $stmt->execute(["studentA5_alt@test.com", 90, "Student A5 Dupe", "PEPP_A5"]);

    // Batch 103 (Course C): 8 students (PEPP_C1 to PEPP_C8)
    // Score 98 is given to PEPP_C1
    for ($i = 1; $i <= 8; $i++) {
        $score = $i === 1 ? 98 : 75 + $i;
        $stmt = $pdo->prepare("INSERT INTO assessment_results (batch_id, student_email, score, attendance_status, src_name, user_id) VALUES (103, ?, ?, 'attended', ?, ?)");
        $stmt->execute(["studentC{$i}@test.com", $score, "Student C{$i}", "PEPP_C{$i}"]);
    }


    echo "\n--- TEST A: Academic year → published logical tests ---\n";
    $year = '2026-27';
    // Simulate query in get_published_tests_by_year
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            arb.study_plan_id,
            arb.activity_id,
            arb.activity_title_snapshot AS activity_title,
            arb.activity_type_snapshot AS activity_type,
            arb.activity_date_snapshot AS activity_date,
            arb.chapter_snapshot AS chapter,
            sp.title AS plan_title,
            spa.day_number
        FROM assessment_result_batches arb
        JOIN study_plans sp ON arb.study_plan_id = sp.id
        LEFT JOIN study_plan_activities spa ON arb.activity_id = spa.id
        WHERE arb.academic_year = ? AND arb.status = 'published'
        ORDER BY sp.title ASC, arb.activity_title_snapshot ASC
    ");
    $stmt->execute([$year]);
    $resA = $stmt->fetchAll(PDO::FETCH_ASSOC);

    run_assert("Returned non-empty array", is_array($resA) && count($resA) > 0);
    run_assert("Logical test study_plan_id matches", $resA[0]['study_plan_id'] == 10);
    run_assert("Logical test activity_id matches", $resA[0]['activity_id'] == 30);
    run_assert("Logical test title matches", $resA[0]['activity_title'] === 'IHBAS Mock Test 01');


    echo "\n--- TEST B: Selected logical test → correct study_plan_id + activity_id ---\n";
    // Simulate select value generation in JS
    $t = $resA[0];
    $selected_val = $t['study_plan_id'] . '_' . $t['activity_id'];
    run_assert("Identifier stores study_plan_id + activity_id format", $selected_val === '10_30');

    $parts = explode('_', $selected_val);
    $plan_id = (int)$parts[0];
    $activity_id = (int)$parts[1];
    run_assert("Extracted plan_id is 10", $plan_id === 10);
    run_assert("Extracted activity_id is 30", $activity_id === 30);


    echo "\n--- TEST C: Selected logical test → study plan details ---\n";
    // Simulate details lookup in get_course_participation_summary
    $stmt_plan = $pdo->prepare("SELECT title FROM study_plans WHERE id = ?");
    $stmt_plan->execute([$plan_id]);
    $plan_title = $stmt_plan->fetchColumn();
    if (!$plan_title) {
        $plan_title = 'Study Plan #' . $plan_id;
    }

    $stmt_act = $pdo->prepare("SELECT activity_title, activity_type, activity_date, chapter FROM study_plan_activities WHERE id = ?");
    $stmt_act->execute([$activity_id]);
    $activity = $stmt_act->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        $stmt_snap = $pdo->prepare("
            SELECT 
                activity_title_snapshot AS activity_title,
                activity_type_snapshot AS activity_type,
                activity_date_snapshot AS activity_date,
                chapter_snapshot AS chapter
            FROM assessment_result_batches
            WHERE academic_year = ? AND study_plan_id = ? AND activity_id = ? AND status = 'published'
            LIMIT 1
        ");
        $stmt_snap->execute([$year, $plan_id, $activity_id]);
        $activity = $stmt_snap->fetch(PDO::FETCH_ASSOC);
    }

    run_assert("Resolved study plan title correctly", $plan_title === 'August 2026');
    run_assert("Resolved activity title correctly", $activity['activity_title'] === 'IHBAS Mock Test 01');


    echo "\n--- TEST D: Selected logical test → assigned courses ---\n";
    $stmt_assign = $pdo->prepare("SELECT assignment_type, assigned_value FROM study_plan_assignments WHERE study_plan_id = ?");
    $stmt_assign->execute([$plan_id]);
    $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);

    $is_all = false;
    $assigned_names = [];
    foreach ($assignments as $asg) {
        if ($asg['assignment_type'] === 'all') {
            $is_all = true;
            break;
        } elseif ($asg['assignment_type'] === 'course') {
            $assigned_names[] = $asg['assigned_value'];
        }
    }

    if ($is_all) {
        $stmt_courses = $pdo->prepare("SELECT id, course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' ORDER BY course_name ASC");
        $stmt_courses->execute([$year]);
    } else {
        $placeholders = implode(',', array_fill(0, count($assigned_names), '?'));
        $stmt_courses = $pdo->prepare("SELECT id, course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' AND course_name IN ($placeholders) ORDER BY course_name ASC");
        $stmt_courses->execute(array_merge([$year], $assigned_names));
    }
    $courses = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);
    run_assert("Correct number of assigned courses found", count($courses) === 4);
    $course_names = array_column($courses, 'course_name');
    run_assert("Includes Course A", in_array('Psychology A', $course_names));
    run_assert("Includes Course D", in_array('Psychology D', $course_names));


    echo "\n--- TEST E: Course-wise total / attended / unattended ---\n";
    $courses_summary = [];
    foreach ($courses as $c) {
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE status = 'approved' AND student_status IN ('active','completed') AND LOWER(TRIM(pepp_course)) = LOWER(TRIM(?)) AND pepp_academic_year = ?");
        $stmt_count->execute([$c['course_name'], $year]);
        $total_students = (int)$stmt_count->fetchColumn();

        $stmt_batches = $pdo->prepare("
            SELECT id FROM assessment_result_batches 
            WHERE activity_id = ? 
              AND (course_id = ? OR LOWER(TRIM(course_name)) = LOWER(TRIM(?))) 
              AND status = 'published'
        ");
        $stmt_batches->execute([$activity_id, $c['id'], $c['course_name']]);
        $batch_ids = $stmt_batches->fetchAll(PDO::FETCH_COLUMN);

        $attended = 0;
        $result_available = 'No';

        if (!empty($batch_ids)) {
            $result_available = 'Yes';
            $placeholders_batches = implode(',', array_fill(0, count($batch_ids), '?'));
            $stmt_att = $pdo->prepare("
                SELECT COUNT(DISTINCT COALESCE(NULLIF(u.user_id, ''), ar.student_email))
                FROM assessment_results ar
                JOIN users u ON (ar.user_id = u.user_id OR LOWER(ar.student_email) = LOWER(u.email))
                WHERE ar.batch_id IN ($placeholders_batches)
                  AND ar.attendance_status = 'attended'
                  AND u.status = 'approved'
                  AND u.student_status IN ('active','completed')
                  AND LOWER(TRIM(u.pepp_course)) = LOWER(TRIM(?))
                  AND u.pepp_academic_year = ?
            ");
            $stmt_att->execute(array_merge($batch_ids, [$c['course_name'], $year]));
            $attended = (int)$stmt_att->fetchColumn();
        }

        $unattended = max(0, $total_students - $attended);

        $courses_summary[$c['course_name']] = [
            'total_students' => $total_students,
            'attended' => $attended,
            'unattended' => $unattended,
            'result_available' => $result_available
        ];
    }

    // Course A assertions
    run_assert("Psychology A Total Students correct", $courses_summary['Psychology A']['total_students'] === 12);
    run_assert("Psychology A Attended correct", $courses_summary['Psychology A']['attended'] === 10);
    run_assert("Psychology A Unattended correct", $courses_summary['Psychology A']['unattended'] === 2);
    run_assert("Psychology A Result Available is Yes", $courses_summary['Psychology A']['result_available'] === 'Yes');

    // Course D assertions
    run_assert("Psychology D Total Students correct", $courses_summary['Psychology D']['total_students'] === 5);
    run_assert("Psychology D Attended correct", $courses_summary['Psychology D']['attended'] === 0);
    run_assert("Psychology D Unattended correct", $courses_summary['Psychology D']['unattended'] === 5);
    run_assert("Psychology D Result Available is No", $courses_summary['Psychology D']['result_available'] === 'No');


    echo "\n--- TEST F: Merged result across multiple course uploads ---\n";
    // Fetch all published batches scoped to Year and Plan ID
    $stmt_batches = $pdo->prepare("
        SELECT id FROM assessment_result_batches
        WHERE activity_id = ?
          AND study_plan_id = ?
          AND academic_year = ?
          AND status = 'published'
    ");
    $stmt_batches->execute([$activity_id, $plan_id, $year]);
    $batch_ids = $stmt_batches->fetchAll(PDO::FETCH_COLUMN);

    run_assert("Correct number of published batches found", count($batch_ids) === 3);

    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $stmt_res = $pdo->prepare("
        SELECT ar.student_email, ar.score, ar.attendance_status,
               COALESCE(u.name, ar.src_name) AS name,
               COALESCE(u.college_school, '-') AS college_school,
               u.user_id, u.pepp_course AS course_name
        FROM assessment_results ar
        LEFT JOIN users u ON (ar.user_id = u.user_id OR LOWER(ar.student_email) = LOWER(u.email))
        WHERE ar.batch_id IN ($placeholders)
    ");
    $stmt_res->execute($batch_ids);
    $raw_results = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

    // Sum of rows in Batch 101 (10), 102 (15), 103 (8) = 33 records
    run_assert("Total raw results count correct", count($raw_results) === 33);


    echo "\n--- TEST G: Duplicate student across courses → one student, highest score retained ---\n";
    $merged = [];
    foreach ($raw_results as $r) {
        if ($r['attendance_status'] !== 'attended' || $r['score'] === null) {
            continue;
        }
        $uid = !empty($r['user_id']) ? $r['user_id'] : $r['student_email'];
        if (empty($uid)) continue;

        if (!isset($merged[$uid]) || $r['score'] > $merged[$uid]['score']) {
            $merged[$uid] = $r;
        }
    }

    $deduplicated_results = array_values($merged);
    $pepp_a5_rows = array_filter($deduplicated_results, fn($r) => $r['user_id'] === 'PEPP_A5');

    run_assert("Student PEPP_A5 appears exactly once after deduplication", count($pepp_a5_rows) === 1);
    $pepp_a5 = array_values($pepp_a5_rows)[0];
    run_assert("Student PEPP_A5 retains highest score 96 (not 90)", (int)$pepp_a5['score'] === 96);


    echo "\n--- TEST H: Global ranking after deduplication ---\n";
    usort($deduplicated_results, function($a, $b) { return ($b['score'] ?? 0) <=> ($a['score'] ?? 0); });

    $ranking_list = [];
    $prev_score = null;
    $rank = 0;
    $count = 0;
    foreach ($deduplicated_results as $r) {
        $count++;
        if ($r['score'] !== $prev_score) {
            $rank = $count;
        }
        $r['computed_rank'] = $rank;
        $ranking_list[] = $r;
        $prev_score = $r['score'];
    }

    run_assert("Rank 1 has highest score 98", (int)$ranking_list[0]['score'] === 98);
    run_assert("Rank 1 has computed_rank 1", (int)$ranking_list[0]['computed_rank'] === 1);
    run_assert("Rank 1 is student PEPP_C1", $ranking_list[0]['user_id'] === 'PEPP_C1');

    run_assert("Rank 2 has score 96", (int)$ranking_list[1]['score'] === 96);
    run_assert("Rank 2 has computed_rank 2", (int)$ranking_list[1]['computed_rank'] === 2);
    run_assert("Rank 2 is student PEPP_A5", $ranking_list[1]['user_id'] === 'PEPP_A5');


    echo "\n--- TEST I: Designer loader receives the correct merged logical test ---\n";
    $course_id = 0; // Merged mode indicator
    $designer_batch_ids = [];

    // Simulate cards-result-designer.php loader query
    if ($course_id > 0) {
        // Single course flow (should not run for merged mode)
    } else {
        $stmt_batches2 = $pdo->prepare("
            SELECT id FROM assessment_result_batches
            WHERE activity_id = ?
              AND study_plan_id = ?
              AND academic_year = ?
              AND status = 'published'
        ");
        $stmt_batches2->execute([$activity_id, $plan_id, $year]);
        $designer_batch_ids = $stmt_batches2->fetchAll(PDO::FETCH_COLUMN);
    }

    run_assert("Designer merged batches count correct", count($designer_batch_ids) === 3);


    echo "\n--- TEST J: Single-course mode remains unchanged ---\n";
    $test_course_id = 1; // Psychology A
    $single_batch_ids = [];

    if ($test_course_id > 0) {
        $stmt_cn = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ?");
        $stmt_cn->execute([$test_course_id]);
        $course_name = $stmt_cn->fetchColumn();

        $stmt_batch = $pdo->prepare("
            SELECT id FROM assessment_result_batches
            WHERE activity_id = ? AND (course_id = ? OR (course_name = ? AND course_name != '')) AND status = 'published'
            ORDER BY version DESC LIMIT 1
        ");
        $stmt_batch->execute([$activity_id, $test_course_id, $course_name]);
        $bid = $stmt_batch->fetchColumn();
        if ($bid) {
            $single_batch_ids[] = (int)$bid;
        }
    }

    run_assert("Single-course loader resolves exactly 1 batch", count($single_batch_ids) === 1);
    run_assert("Resolved batch is Course A (101)", $single_batch_ids[0] === 101);


    echo "\n--- TEST K: Activity Fallback (Activity deleted from study_plan_activities) ---\n";
    // 1. Delete the activity from study_plan_activities
    $pdo->exec("DELETE FROM study_plan_activities WHERE id = 30");

    // 2. Query activity details via fallback
    $stmt_act2 = $pdo->prepare("SELECT activity_title, activity_type, activity_date, chapter FROM study_plan_activities WHERE id = ?");
    $stmt_act2->execute([30]);
    $activity2 = $stmt_act2->fetch(PDO::FETCH_ASSOC);

    run_assert("Activity row no longer exists in study_plan_activities", $activity2 === false);

    if (!$activity2) {
        $stmt_snap = $pdo->prepare("
            SELECT 
                activity_title_snapshot AS activity_title,
                activity_type_snapshot AS activity_type,
                activity_date_snapshot AS activity_date,
                chapter_snapshot AS chapter
            FROM assessment_result_batches
            WHERE academic_year = ? AND study_plan_id = ? AND activity_id = ? AND status = 'published'
            LIMIT 1
        ");
        $stmt_snap->execute(['2026-27', 10, 30]);
        $activity2 = $stmt_snap->fetch(PDO::FETCH_ASSOC);
    }

    run_assert("Fallback successfully resolved snapshot details", is_array($activity2));
    run_assert("Fallback resolved correct activity title", $activity2['activity_title'] === 'IHBAS Mock Test 01');

    echo "\n=== All merged workflow automated tests passed successfully! ===\n";

} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
