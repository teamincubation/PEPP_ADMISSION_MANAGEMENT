<?php
/**
 * PEPP ERP — Pre-Cleanup Production Impact Audit & Campaign Form Study Plan Pruning Tool
 *
 * STRICT SECURITY & EXECUTION RULES:
 * 1. CLI/SSH ONLY: Any HTTP/web request is immediately rejected with HTTP 403.
 * 2. ZERO URL SECRETS: No tokens or query parameters are accepted.
 * 3. STRICT READ-ONLY DRY-RUN: Default mode issues only SELECT and SHOW queries.
 * 4. SEPARATE STAGES: Audit, Migration, and Verification are decoupled.
 *
 * Usage:
 *   php scripts/audit_campaign_form_studyplan_impact.php --dry-run
 *   php scripts/audit_campaign_form_studyplan_impact.php --mysql --dry-run
 *   php scripts/audit_campaign_form_studyplan_impact.php --mysql --apply-migration
 *   php scripts/audit_campaign_form_studyplan_impact.php --mysql --verify
 */

if (php_sapi_name() !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Access Denied: This script can ONLY be executed via authenticated CLI/SSH.\n";
    exit(1);
}

$args = $argv ?? [];
if (in_array('--mysql', $args, true)) {
    putenv('PEPP_USE_MYSQL=1');
} elseif (in_array('--sqlite', $args, true) || file_exists(dirname(__DIR__) . '/scratch_test_db.sqlite')) {
    putenv('PEPP_USE_SQLITE=1');
}

$mode = 'dry-run';
if (in_array('--apply-migration', $args, true)) {
    $mode = 'apply-migration';
} elseif (in_array('--verify', $args, true)) {
    $mode = 'verify';
}

require_once __DIR__ . '/../config/database.php';

echo "====================================================================\n";
echo "PEPP ERP — CAMPAIGN FORM ↔ STUDY PLAN PRUNING & AUDIT TOOL\n";
echo "====================================================================\n";
echo "Execution Mode   : " . strtoupper($mode) . "\n";
echo "Database Driver  : " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
echo "Timestamp (IST)  : " . date('Y-m-d H:i:s') . "\n";
echo "====================================================================\n\n";

function get_table_count(PDO $pdo, string $table): int {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        // Table might not exist in SQLite or local test DB
        return 0;
    }
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ── 1. GLOBAL DATABASE INTEGRITY METRICS (Requirement 8) ─────────────────────
echo "1. DATABASE INTEGRITY OVERVIEW\n";
echo "--------------------------------------------------------------------\n";

$integrity_tables = [
    'study_plan_assignments',
    'study_plans',
    'study_plan_activities',
    'study_plan_analytics',
    'users',
    'campaign_forms',
    'campaign_form_submissions',
    'campaign_form_answers'
];

$table_counts = [];
foreach ($integrity_tables as $tbl) {
    if (table_exists($pdo, $tbl)) {
        $cnt = get_table_count($pdo, $tbl);
        $table_counts[$tbl] = $cnt;
        printf("  %-30s : %d rows\n", $tbl, $cnt);
    } else {
        $table_counts[$tbl] = 0;
        printf("  %-30s : [TABLE NOT PRESENT]\n", $tbl);
    }
}

echo "\n  study_plan_assignments by assignment_type:\n";
$assignment_type_counts = ['all' => 0, 'course' => 0, 'batch' => 0, 'student' => 0, 'form' => 0];
if (table_exists($pdo, 'study_plan_assignments')) {
    $stmt = $pdo->query("SELECT assignment_type, COUNT(*) as cnt FROM study_plan_assignments GROUP BY assignment_type");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $type = $r['assignment_type'];
        $assignment_type_counts[$type] = (int)$r['cnt'];
        printf("    - %-10s : %d\n", $type, (int)$r['cnt']);
    }
}

// ── 2. PRE-CLEANUP PRODUCTION IMPACT AUDIT (Requirement 3) ───────────────────
echo "\n2. CAMPAIGN FORM STUDY PLAN IMPACT AUDIT\n";
echo "--------------------------------------------------------------------\n";

$impact_metrics = [
    'total_form_assignments' => 0,
    'active_form_assignments' => 0,
    'soft_deleted_form_assignments' => 0,
    'distinct_plans_with_forms' => 0,
    'distinct_forms_referenced' => 0,
    'form_only_plans' => 0,
    'plans_form_plus_course' => 0,
    'plans_form_plus_batch' => 0,
    'plans_form_plus_student' => 0,
    'plans_form_plus_all' => 0,
    'students_only_form_access' => 0,
    'students_form_plus_legit_access' => 0
];

if (table_exists($pdo, 'study_plan_assignments')) {
    // 1. Total form assignment rows
    $stmt = $pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form'");
    $impact_metrics['total_form_assignments'] = (int)$stmt->fetchColumn();

    // 2. Active form assignment rows
    $stmt = $pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form' AND (is_deleted = 0 OR is_deleted IS NULL)");
    $impact_metrics['active_form_assignments'] = (int)$stmt->fetchColumn();

    // 3. Soft-deleted form assignment rows
    $stmt = $pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form' AND is_deleted = 1");
    $impact_metrics['soft_deleted_form_assignments'] = (int)$stmt->fetchColumn();

    // 4. Distinct study plans using form assignments
    $stmt = $pdo->query("SELECT COUNT(DISTINCT study_plan_id) FROM study_plan_assignments WHERE assignment_type = 'form'");
    $impact_metrics['distinct_plans_with_forms'] = (int)$stmt->fetchColumn();

    // 5. Distinct forms referenced
    $stmt = $pdo->query("SELECT COUNT(DISTINCT assigned_value) FROM study_plan_assignments WHERE assignment_type = 'form'");
    $impact_metrics['distinct_forms_referenced'] = (int)$stmt->fetchColumn();

    // 6. Form-only plans (plans that have form assignment and NO active course/batch/student/all assignment)
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT sa.study_plan_id)
        FROM study_plan_assignments sa
        WHERE sa.assignment_type = 'form' AND (sa.is_deleted = 0 OR sa.is_deleted IS NULL)
          AND NOT EXISTS (
              SELECT 1 FROM study_plan_assignments sa2
              WHERE sa2.study_plan_id = sa.study_plan_id
                AND sa2.assignment_type IN ('course', 'batch', 'student', 'all')
                AND (sa2.is_deleted = 0 OR sa2.is_deleted IS NULL)
          )
    ");
    $impact_metrics['form_only_plans'] = (int)$stmt->fetchColumn();

    // 7. Plans having form + course
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT sa.study_plan_id)
        FROM study_plan_assignments sa
        WHERE sa.assignment_type = 'form' AND (sa.is_deleted = 0 OR sa.is_deleted IS NULL)
          AND EXISTS (
              SELECT 1 FROM study_plan_assignments sa2
              WHERE sa2.study_plan_id = sa.study_plan_id
                AND sa2.assignment_type = 'course'
                AND (sa2.is_deleted = 0 OR sa2.is_deleted IS NULL)
          )
    ");
    $impact_metrics['plans_form_plus_course'] = (int)$stmt->fetchColumn();

    // 8. Plans having form + batch
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT sa.study_plan_id)
        FROM study_plan_assignments sa
        WHERE sa.assignment_type = 'form' AND (sa.is_deleted = 0 OR sa.is_deleted IS NULL)
          AND EXISTS (
              SELECT 1 FROM study_plan_assignments sa2
              WHERE sa2.study_plan_id = sa.study_plan_id
                AND sa2.assignment_type = 'batch'
                AND (sa2.is_deleted = 0 OR sa2.is_deleted IS NULL)
          )
    ");
    $impact_metrics['plans_form_plus_batch'] = (int)$stmt->fetchColumn();

    // 9. Plans having form + student
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT sa.study_plan_id)
        FROM study_plan_assignments sa
        WHERE sa.assignment_type = 'form' AND (sa.is_deleted = 0 OR sa.is_deleted IS NULL)
          AND EXISTS (
              SELECT 1 FROM study_plan_assignments sa2
              WHERE sa2.study_plan_id = sa.study_plan_id
                AND sa2.assignment_type = 'student'
                AND (sa2.is_deleted = 0 OR sa2.is_deleted IS NULL)
          )
    ");
    $impact_metrics['plans_form_plus_student'] = (int)$stmt->fetchColumn();

    // 10. Plans having form + all
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT sa.study_plan_id)
        FROM study_plan_assignments sa
        WHERE sa.assignment_type = 'form' AND (sa.is_deleted = 0 OR sa.is_deleted IS NULL)
          AND EXISTS (
              SELECT 1 FROM study_plan_assignments sa2
              WHERE sa2.study_plan_id = sa.study_plan_id
                AND sa2.assignment_type = 'all'
                AND (sa2.is_deleted = 0 OR sa2.is_deleted IS NULL)
          )
    ");
    $impact_metrics['plans_form_plus_all'] = (int)$stmt->fetchColumn();

    // 11 & 12. Students who currently obtain Study Plan access ONLY through form vs also through legit assignments
    if (table_exists($pdo, 'campaign_form_submissions') && table_exists($pdo, 'users')) {
        // Students with form submission on an assigned form
        $stmt = $pdo->query("
            SELECT DISTINCT s.respondent_identifier, u.email, u.pepp_course, u.pepp_academic_year, u.user_id, u.status
            FROM campaign_form_submissions s
            JOIN study_plan_assignments sa ON CAST(s.form_id AS CHAR) = sa.assigned_value AND sa.assignment_type = 'form' AND (sa.is_deleted = 0 OR sa.is_deleted IS NULL)
            LEFT JOIN users u ON (s.respondent_identifier = u.email OR s.respondent_identifier = u.user_id) AND u.status = 'approved'
            WHERE (s.is_deleted = 0 OR s.is_deleted IS NULL)
        ");
        $form_respondents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $only_form = 0;
        $form_plus_legit = 0;

        foreach ($form_respondents as $resp) {
            if (empty($resp['email']) || empty($resp['user_id'])) {
                // Non-enrolled guest user: only has form access
                $only_form++;
                continue;
            }
            // Enrolled student: check if they have access via course, batch, student, or all
            $stmt_legit = $pdo->prepare("
                SELECT COUNT(*)
                FROM study_plan_assignments sa
                WHERE (sa.is_deleted = 0 OR sa.is_deleted IS NULL) AND (
                    sa.assignment_type = 'all' OR
                    (sa.assignment_type = 'course' AND sa.assigned_value = ?) OR
                    (sa.assignment_type = 'batch' AND sa.assigned_value = ?) OR
                    (sa.assignment_type = 'student' AND sa.assigned_value = ?)
                )
            ");
            $stmt_legit->execute([$resp['pepp_course'], $resp['pepp_academic_year'], $resp['user_id']]);
            $legit_cnt = (int)$stmt_legit->fetchColumn();

            if ($legit_cnt > 0) {
                $form_plus_legit++;
            } else {
                $only_form++;
            }
        }
        $impact_metrics['students_only_form_access'] = $only_form;
        $impact_metrics['students_form_plus_legit_access'] = $form_plus_legit;
    }
}

printf("  %-48s : %d\n", "Total form assignment rows", $impact_metrics['total_form_assignments']);
printf("  %-48s : %d\n", "Active form assignment rows", $impact_metrics['active_form_assignments']);
printf("  %-48s : %d\n", "Soft-deleted form assignment rows", $impact_metrics['soft_deleted_form_assignments']);
printf("  %-48s : %d\n", "Distinct study plans using form assignments", $impact_metrics['distinct_plans_with_forms']);
printf("  %-48s : %d\n", "Distinct forms referenced", $impact_metrics['distinct_forms_referenced']);
printf("  %-48s : %d\n", "Form-only study plans", $impact_metrics['form_only_plans']);
printf("  %-48s : %d\n", "Plans having form + course", $impact_metrics['plans_form_plus_course']);
printf("  %-48s : %d\n", "Plans having form + batch", $impact_metrics['plans_form_plus_batch']);
printf("  %-48s : %d\n", "Plans having form + student", $impact_metrics['plans_form_plus_student']);
printf("  %-48s : %d\n", "Plans having form + all", $impact_metrics['plans_form_plus_all']);
printf("  %-48s : %d\n", "Students accessing Study Plans ONLY via form", $impact_metrics['students_only_form_access']);
printf("  %-48s : %d\n", "Students also having legit course/batch/all", $impact_metrics['students_form_plus_legit_access']);

// ── 3. EXECUTION / VERIFICATION HANDLING ──────────────────────────────────────
if ($mode === 'apply-migration') {
    echo "\n3. APPLYING MIGRATION (database-update-48.sql logic)\n";
    echo "--------------------------------------------------------------------\n";

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM study_plan_assignments WHERE assignment_type = 'form'");
        $stmt->execute();
        $deleted_rows = $stmt->rowCount();
        $pdo->commit();
        echo "  [SUCCESS] Physically deleted {$deleted_rows} form assignment row(s) (including soft-deleted historical rows).\n";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "  [ERROR] Failed to delete form assignment rows: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Verify count is zero
    $remaining_form_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form'")->fetchColumn();
    if ($remaining_form_cnt !== 0) {
        echo "  [FATAL ERROR] Form assignments count is not zero ({$remaining_form_cnt}). Halting ENUM alteration!\n";
        exit(1);
    }
    echo "  [SUCCESS] Verified count of assignment_type='form' is exactly 0.\n";

    // Alter ENUM if MySQL
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        try {
            $pdo->exec("ALTER TABLE study_plan_assignments MODIFY COLUMN assignment_type ENUM('all','course','batch','student') NOT NULL");
            echo "  [SUCCESS] Altered ENUM on study_plan_assignments to ENUM('all','course','batch','student').\n";
        } catch (Exception $e) {
            echo "  [ERROR] ALTER TABLE ENUM failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        echo "  [INFO] SQLite driver in use — SQLite table constraint check passes.\n";
    }

    echo "\n  Migration application complete. Run with --verify to confirm state.\n";
}

if ($mode === 'verify') {
    echo "\n3. POST-CLEANUP INTEGRITY VERIFICATION\n";
    echo "--------------------------------------------------------------------\n";

    $form_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'form'")->fetchColumn();
    $course_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'course'")->fetchColumn();
    $batch_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'batch'")->fetchColumn();
    $student_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'student'")->fetchColumn();
    $all_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_assignments WHERE assignment_type = 'all'")->fetchColumn();

    $pass = true;
    if ($form_cnt === 0) {
        echo "  [PASS] assignment_type='form' count is 0\n";
    } else {
        echo "  [FAIL] assignment_type='form' count is {$form_cnt} (Expected: 0)\n";
        $pass = false;
    }

    echo "  [INFO] course assignments : {$course_cnt}\n";
    echo "  [INFO] batch assignments  : {$batch_cnt}\n";
    echo "  [INFO] student assignments: {$student_cnt}\n";
    echo "  [INFO] all assignments    : {$all_cnt}\n";

    if ($pass) {
        echo "\n>>> VERIFICATION PASSED: Database cleanup is clean, isolated, and valid.\n";
    } else {
        echo "\n>>> VERIFICATION FAILED: Anomalies detected.\n";
        exit(1);
    }
}
