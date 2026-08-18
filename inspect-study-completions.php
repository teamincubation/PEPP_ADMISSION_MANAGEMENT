<?php
require_once 'includes/auth.php';
require_permission('studyplans');
require_once 'config/database.php';

header('Content-Type: application/json');

// Only allow Super Admins to run diagnostics
if (!is_super_admin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Super Admin access required.']);
    exit;
}

try {
    $report = [];

    // 1. Total completion rows
    $total_completions = (int)$pdo->query("
        SELECT COUNT(*) FROM study_plan_analytics WHERE action_type = 'complete_activity'
    ")->fetchColumn();
    $report['total_completion_rows'] = $total_completions;

    // 2. Duplicate active completions (same student + study_plan + activity)
    $stmt_dupes = $pdo->query("
        SELECT student_email, study_plan_id, activity_id, COUNT(*) as occurrence_count 
        FROM study_plan_analytics 
        WHERE action_type = 'complete_activity'
        GROUP BY student_email, study_plan_id, activity_id 
        HAVING occurrence_count > 1
    ");
    $dupes_list = $stmt_dupes->fetchAll(PDO::FETCH_ASSOC);
    $report['duplicate_active_completions_count'] = count($dupes_list);
    $report['duplicate_active_completions_details'] = $dupes_list;

    // 3. Records with NULL activity IDs
    $null_activities = (int)$pdo->query("
        SELECT COUNT(*) FROM study_plan_analytics WHERE activity_id IS NULL AND action_type = 'complete_activity'
    ")->fetchColumn();
    $report['records_with_null_activity_ids'] = $null_activities;

    // 4. Records with invalid study plan references
    $invalid_plans = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM study_plan_analytics an 
        LEFT JOIN study_plans sp ON an.study_plan_id = sp.id 
        WHERE sp.id IS NULL
    ")->fetchColumn();
    $report['records_with_invalid_study_plan_references'] = $invalid_plans;

    // 5. Records with invalid activity references
    $invalid_activities = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM study_plan_analytics an 
        LEFT JOIN study_plan_activities act ON an.activity_id = act.id 
        WHERE act.id IS NULL AND an.action_type = 'complete_activity'
    ")->fetchColumn();
    $report['records_with_invalid_activity_references'] = $invalid_activities;

    // 6. Timezone information from Database
    $db_time = $pdo->query("SELECT NOW() as db_now, @@global.time_zone as global_tz, @@session.time_zone as session_tz")->fetch(PDO::FETCH_ASSOC);
    $report['database_timezone_metadata'] = $db_time;

    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'diagnostics' => $report,
        'instructions' => 'Please copy these results, report them to the developer, and delete this file inspect-study-completions.php immediately.'
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
