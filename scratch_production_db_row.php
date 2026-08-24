<?php
header('Content-Type: text/plain; charset=UTF-8');

try {
    require_once __DIR__ . '/config/database.php';
    if (!isset($pdo) && isset($conn)) {
        $pdo = $conn;
    }
    
    echo "DATABASE CONNECTION SUCCESSFUL\n\n";
    
    // 1. Query study plans
    echo "STUDY PLANS FOR ID 5:\n";
    $stmt = $pdo->prepare("SELECT * FROM study_plans WHERE id = 5");
    $stmt->execute();
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // 2. Query study plan activities
    echo "\nSTUDY PLAN ACTIVITIES FOR IDs 26305, 26335, 26343:\n";
    $stmt = $pdo->prepare("SELECT id, study_plan_id, activity_title, activity_type, activity_date, chapter, day_number FROM study_plan_activities WHERE id IN (26305, 26335, 26343)");
    $stmt->execute();
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    // 3. Run the exact query used in assessment-results.php
    echo "\nEXACT ENDPOINT SQL QUERY OUTPUT FOR 2026-27:\n";
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            arb.study_plan_id,
            arb.activity_id,
            arb.activity_title_snapshot AS activity_title,
            arb.activity_type_snapshot AS activity_type,
            COALESCE(spa.activity_date, arb.activity_date_snapshot) AS activity_date,
            COALESCE(spa.chapter, arb.chapter_snapshot) AS chapter,
            sp.title AS plan_title,
            spa.day_number
        FROM assessment_result_batches arb
        LEFT JOIN study_plans sp ON arb.study_plan_id = sp.id
        LEFT JOIN study_plan_activities spa ON arb.activity_id = spa.id
        WHERE arb.academic_year = ? AND arb.status = 'published'
        ORDER BY COALESCE(spa.activity_date, arb.activity_date_snapshot) DESC, arb.activity_id DESC
    ");
    $stmt->execute(['2026-27']);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
