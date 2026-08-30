<?php
/**
 * READ-ONLY Forensic Diagnostic Script for Study Plan Integrity
 * 
 * IMPORTANT: This script performs ONLY SELECT queries.
 * It NEVER modifies, repairs, or writes to the database.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/includes/auth.php';
    require_permission('studyplans');
}

require_once __DIR__ . '/config/database.php';

$is_cli = (php_sapi_name() === 'cli');

function diag_log(string $msg, string $type = 'info'): void {
    global $is_cli;
    if ($is_cli) {
        $prefix = match($type) {
            'warning' => '  [WARNING] ',
            'error'   => '  [ERROR]   ',
            'ok'      => '  [OK]      ',
            default   => '  [INFO]    '
        };
        echo $prefix . $msg . "\n";
    } else {
        $color = match($type) {
            'warning' => '#f59e0b',
            'error'   => '#ef4444',
            'ok'      => '#10b981',
            default   => '#64748b'
        };
        echo "<div style='color:{$color}; margin: 4px 0; font-family: monospace;'>{$msg}</div>";
    }
}

if (!$is_cli) {
    echo "<h2>Study Plan Architecture Read-Only Diagnostic Report</h2><hr>";
} else {
    echo "====================================================================\n";
    echo "PEPP STUDY PLAN ARCHITECTURE — READ-ONLY INTEGRITY DIAGNOSTIC\n";
    echo "====================================================================\n\n";
}

try {
    // 1. Total Plans & Activities Overview
    $total_plans = (int)$pdo->query("SELECT COUNT(*) FROM study_plans WHERE is_deleted = 0")->fetchColumn();
    $total_acts = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE is_deleted = 0")->fetchColumn();
    diag_log("Total Active Study Plans: {$total_plans}, Total Active Activities: {$total_acts}");

    // 2. Check for Missing or Duplicate activity_uid
    echo "\n--- 1. ACTIVITY UID INTEGRITY ---\n";
    $missing_uids = $pdo->query("SELECT id, study_plan_id, activity_title FROM study_plan_activities WHERE (activity_uid IS NULL OR activity_uid = '') AND is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($missing_uids)) {
        diag_log("Found " . count($missing_uids) . " activities with missing/empty activity_uid:", 'warning');
        foreach ($missing_uids as $m) {
            diag_log("  - Activity ID #{$m['id']} (Plan #{$m['study_plan_id']}): '{$m['activity_title']}'", 'warning');
        }
    } else {
        diag_log("All active activities have a non-empty activity_uid", 'ok');
    }

    $dup_uids = $pdo->query("
        SELECT activity_uid, COUNT(*) as cnt, GROUP_CONCAT(id) as ids 
        FROM study_plan_activities 
        WHERE activity_uid IS NOT NULL AND activity_uid != '' AND is_deleted = 0 
        GROUP BY activity_uid 
        HAVING cnt > 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($dup_uids)) {
        diag_log("Found " . count($dup_uids) . " duplicate activity_uid values across active records:", 'error');
        foreach ($dup_uids as $d) {
            diag_log("  - UID '{$d['activity_uid']}' used {$d['cnt']} times on IDs ({$d['ids']})", 'error');
        }
    } else {
        diag_log("Zero duplicate activity_uid values found across active records", 'ok');
    }

    // 3. Check for Duplicate sort_order within Same Day/Date
    echo "\n--- 2. LOCAL SORT ORDER INTEGRITY ---\n";
    $dup_orders = $pdo->query("
        SELECT study_plan_id, activity_date, day_number, sort_order, COUNT(*) as cnt, GROUP_CONCAT(activity_title SEPARATOR ' | ') as titles, GROUP_CONCAT(id) as ids
        FROM study_plan_activities 
        WHERE is_deleted = 0 
        GROUP BY study_plan_id, activity_date, day_number, sort_order 
        HAVING cnt > 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($dup_orders)) {
        diag_log("Found " . count($dup_orders) . " day/date buckets with duplicate sort_order:", 'warning');
        foreach ($dup_orders as $o) {
            diag_log("  - Plan #{$o['study_plan_id']} on Date '{$o['activity_date']}' (Day {$o['day_number']}), sort_order {$o['sort_order']} shared by {$o['cnt']} tasks (IDs: {$o['ids']}): [{$o['titles']}]", 'warning');
        }
    } else {
        diag_log("Zero duplicate sort_order values found across all day/date buckets", 'ok');
    }

    // 4. Check for Potential Phantom "Rest Day / Self Study" Rows
    echo "\n--- 3. REST DAY / SELF STUDY OCCURRENCES ---\n";
    $rest_days = $pdo->query("
        SELECT study_plan_id, activity_date, day_number, sort_order, activity_title, activity_type, id, created_at 
        FROM study_plan_activities 
        WHERE (activity_title LIKE '%Rest Day%' OR activity_title = 'Self Study') AND is_deleted = 0 
        ORDER BY study_plan_id ASC, activity_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rest_days)) {
        diag_log("Found " . count($rest_days) . " 'Rest Day / Self Study' activity records in database:", 'info');
        foreach ($rest_days as $r) {
            diag_log("  - Plan #{$r['study_plan_id']} ID #{$r['id']} on {$r['activity_date']} (Day {$r['day_number']}): '{$r['activity_title']}' [{$r['activity_type']}]", 'info');
        }
    } else {
        diag_log("Zero 'Rest Day / Self Study' records found", 'ok');
    }

    // 5. Check for Date-Wise Activities Outside Plan Start/End Range
    echo "\n--- 4. OUT-OF-BOUNDS DATE-WISE ACTIVITIES ---\n";
    $out_of_bounds = $pdo->query("
        SELECT sp.id as plan_id, sp.title, sp.start_date, sp.end_date, act.id as act_id, act.activity_date, act.activity_title
        FROM study_plan_activities act
        JOIN study_plans sp ON act.study_plan_id = sp.id
        WHERE sp.is_deleted = 0 
          AND act.is_deleted = 0 
          AND (sp.plan_type = 'date_wise' OR sp.plan_type IS NULL)
          AND sp.start_date IS NOT NULL AND sp.start_date != ''
          AND sp.end_date IS NOT NULL AND sp.end_date != ''
          AND (act.activity_date < sp.start_date OR act.activity_date > sp.end_date)
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($out_of_bounds)) {
        diag_log("Found " . count($out_of_bounds) . " activities with dates outside their plan range:", 'warning');
        foreach ($out_of_bounds as $ob) {
            diag_log("  - Plan #{$ob['plan_id']} '{$ob['title']}' range [{$ob['start_date']} to {$ob['end_date']}], but Act #{$ob['act_id']} has date '{$ob['activity_date']}' ('{$ob['activity_title']}')", 'warning');
        }
    } else {
        diag_log("All date_wise activities fall within their parent plan's start/end dates", 'ok');
    }

    // 6. Check for Day-Wise Activities Exceeding Total Days
    echo "\n--- 5. OUT-OF-BOUNDS DAY-WISE ACTIVITIES ---\n";
    $dw_out_of_bounds = $pdo->query("
        SELECT sp.id as plan_id, sp.title, sp.total_days, act.id as act_id, act.day_number, act.activity_title
        FROM study_plan_activities act
        JOIN study_plans sp ON act.study_plan_id = sp.id
        WHERE sp.is_deleted = 0 
          AND act.is_deleted = 0 
          AND sp.plan_type = 'day_wise'
          AND sp.total_days IS NOT NULL AND sp.total_days > 0
          AND (act.day_number < 1 OR act.day_number > sp.total_days)
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($dw_out_of_bounds)) {
        diag_log("Found " . count($dw_out_of_bounds) . " day-wise activities exceeding total_days:", 'warning');
        foreach ($dw_out_of_bounds as $dw_ob) {
            diag_log("  - Plan #{$dw_ob['plan_id']} '{$dw_ob['title']}' total_days [{$dw_ob['total_days']}], but Act #{$dw_ob['act_id']} has day_number {$dw_ob['day_number']} ('{$dw_ob['activity_title']}')", 'warning');
        }
    } else {
        diag_log("All day_wise activities fall within their parent plan's total_days", 'ok');
    }

    // 7. Check Analytics Completion Consistency
    echo "\n--- 6. COMPLETION ANALYTICS INTEGRITY ---\n";
    $orphan_analytics = $pdo->query("
        SELECT an.id, an.study_plan_id, an.student_email, an.activity_uid, an.activity_id 
        FROM study_plan_analytics an
        LEFT JOIN study_plan_activities act ON (
            (an.activity_uid = act.activity_uid AND act.activity_uid IS NOT NULL AND act.activity_uid != '')
            OR (an.activity_id = act.id AND (an.activity_uid IS NULL OR an.activity_uid = '' OR act.activity_uid IS NULL OR act.activity_uid = ''))
        )
        WHERE an.action_type = 'complete_activity' AND act.id IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($orphan_analytics)) {
        diag_log("Found " . count($orphan_analytics) . " student completions referencing deleted or unknown activities (normal after activity deletion)", 'info');
    } else {
        diag_log("All completion records map to active study plan activities", 'ok');
    }

    echo "\n====================================================================\n";
    echo "DIAGNOSTIC SCAN COMPLETE — ZERO DATA MODIFIED (READ-ONLY)\n";
    echo "====================================================================\n";

} catch (Exception $e) {
    diag_log("Diagnostic query error: " . $e->getMessage(), 'error');
}
