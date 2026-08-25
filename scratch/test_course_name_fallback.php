<?php
/**
 * Automated Unit Test verifying Course Name Fallback Logic for Published Test Result Dropdown.
 */

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once dirname(__DIR__) . '/config/database.php';

function assert_test($label, $assertion) {
    if ($assertion) {
        echo "✅ PASS: {$label}\n";
    } else {
        echo "❌ FAIL: {$label}\n";
        exit(1);
    }
}

global $pdo;

echo "=== Running Course Name Fallback Logic Tests ===\n";

try {
    // Clean up SQLite tables first
    $pdo->exec("DELETE FROM pepp_courses");
    $pdo->exec("DELETE FROM study_plans");
    $pdo->exec("DELETE FROM study_plan_activities");
    $pdo->exec("DELETE FROM assessment_result_batches");

    // 1. Insert course with active ID (1503)
    $pdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, academic_year, status) VALUES (1503, 'MA/MSc Psychology (Standard)', 'PSYC_STD', '2026-27', 'active')");

    // 2. Insert study plan and activity
    $pdo->exec("INSERT INTO study_plans (id, title, academic_year, status) VALUES (20, 'August 2026', '2026-27', 'published')");
    $pdo->exec("INSERT INTO study_plan_activities (id, study_plan_id, activity_title, activity_type) VALUES (50, 20, 'August Mock Exam', 'Attend Mock Test')");

    // 3. Seed assessment batch with mismatched course_id (e.g. 9999) but matching course_name
    $stmt = $pdo->prepare("INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, academic_year, course_id, course_name, version, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([100, 50, 20, '2026-27', 9999, 'MA/MSc Psychology (Standard)', 1, 'published']);

    // 4. Simulate AJAX get_tests action
    $plan_id = 20;
    $course_id = 1503; // Using the active course ID

    $test_types = ['Attend Mock Test','Attend Mega Test','Attend Weekly Test','Practice Test','Previous Year Questions','Daily Quiz','Self-Assessment'];
    $placeholders = implode(',', array_fill(0, count($test_types), '?'));

    $stmt_act = $pdo->prepare("
        SELECT id, activity_title, activity_type
        FROM study_plan_activities WHERE study_plan_id = ? AND activity_type IN ($placeholders)
    ");
    $stmt_act->execute(array_merge([$plan_id], $test_types));
    $activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

    assert_test("Retrieved one study plan activity", count($activities) === 1);
    $act = $activities[0];
    assert_test("Activity ID is 50", (int)$act['id'] === 50);

    // Resolve course_name for matching fallback
    $stmt_cn = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ?");
    $stmt_cn->execute([$course_id]);
    $course_name = $stmt_cn->fetchColumn();

    assert_test("Resolved course name is correct", $course_name === 'MA/MSc Psychology (Standard)');

    // Query published batch using the updated fallback query logic
    $bs = $pdo->prepare("SELECT id, version, status FROM assessment_result_batches WHERE activity_id = ? AND (course_id = ? OR (course_name = ? AND course_name != '')) AND status = 'published' LIMIT 1");
    $bs->execute([$act['id'], $course_id, $course_name]);
    $batch = $bs->fetch(PDO::FETCH_ASSOC);

    assert_test("Batch resolves successfully despite course_id mismatch (resolved via course_name fallback)", $batch ? true : false);
    assert_test("Batch ID matches target batch (100)", (int)$batch['id'] === 100);

    echo "=== All course name fallback tests passed successfully! ===\n";

} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
