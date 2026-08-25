<?php
require_once __DIR__ . '/../config/database.php';

try {
    // 1. Check all rows in assessment_result_batches
    echo "--- CHECKING ALL BATCHES ---\n";
    $stmt = $pdo->query("SELECT id, academic_year, activity_id, status FROM assessment_result_batches");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);

    // 2. Run the exact query from get_published_tests_by_year for '2026-27'
    echo "\n--- RUNNING PLANNED QUERY FOR 2026-27 ---\n";
    $stmt2 = $pdo->prepare("
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
    $stmt2->execute(['2026-27']);
    $res2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    print_r($res2);
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
