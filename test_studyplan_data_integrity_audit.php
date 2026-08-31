<?php
/**
 * Test Suite: Study Plan Data Integrity & Production Remediation Audit
 * 
 * Verifies that the exact production remediation UPDATE safely soft-deletes
 * the 107 confirmed cloned August rows from Plan 11 while leaving Plan 5,
 * legitimate September Plan 11 rows, and historical soft-deleted rows completely intact.
 */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Create Schema
$pdo->exec("
    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY,
        title TEXT,
        academic_year TEXT,
        course_id INTEGER,
        description TEXT,
        cover_image TEXT,
        theme TEXT,
        layout TEXT,
        start_date TEXT,
        end_date TEXT,
        status TEXT,
        plan_type TEXT,
        total_days INTEGER,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY,
        study_plan_id INTEGER,
        activity_date TEXT,
        day_number INTEGER,
        sort_order INTEGER,
        chapter TEXT,
        subject TEXT,
        topic TEXT,
        subtopic TEXT,
        activity_title TEXT,
        activity_type TEXT,
        faculty TEXT,
        mentor TEXT,
        estimated_duration INTEGER,
        priority TEXT,
        difficulty_level TEXT,
        resource_links TEXT,
        activity_uid TEXT,
        is_deleted INTEGER DEFAULT 0,
        deleted_at TEXT,
        deleted_by TEXT,
        deletion_reason TEXT
    );

    CREATE TABLE study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        student_email TEXT,
        activity_id INTEGER,
        activity_uid TEXT,
        action_type TEXT,
        completion_status TEXT,
        created_at TEXT
    );
");

// 2. Populate Plans
$pdo->exec("
    INSERT INTO study_plans (id, title, academic_year, start_date, end_date, plan_type, status)
    VALUES (5, 'August 2026 (PG)', '2026-27', '2026-08-09', '2026-08-31', 'date_wise', 'published'),
           (11, 'September 2026 (PG)', '2026-27', '2026-09-01', '2026-09-30', 'date_wise', 'published');
");

// 3. Populate Plan 5 (107 active rows)
$stmt_act_ins = $pdo->prepare("
    INSERT INTO study_plan_activities (id, study_plan_id, activity_date, day_number, sort_order, chapter, activity_title, activity_type, activity_uid, is_deleted)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

for ($i = 1; $i <= 107; $i++) {
    $p5_id = 40695 + $i;
    $day_num = min(23, intdiv($i - 1, 5) + 1);
    $act_date = date('Y-m-d', strtotime('2026-08-09 +' . ($day_num - 1) . ' days'));
    $uid = 'SPA-P5-' . str_pad($i, 4, '0', STR_PAD_LEFT);
    $stmt_act_ins->execute([$p5_id, 5, $act_date, $day_num, ($i % 5), 'Chapter ' . $day_num, 'August Task ' . $i, 'Recorded Session', $uid, 0]);
}

// 4. Populate Plan 11 Contaminated August rows (IDs 41247 to 41353, exactly 107 rows)
for ($i = 1; $i <= 107; $i++) {
    $p11_aug_id = 41246 + $i;
    $day_num = min(23, intdiv($i - 1, 5) + 1);
    $act_date = date('Y-m-d', strtotime('2026-08-09 +' . ($day_num - 1) . ' days'));
    $uid = 'SPA-P11-AUG-' . str_pad($i, 4, '0', STR_PAD_LEFT);
    $stmt_act_ins->execute([$p11_aug_id, 11, $act_date, $day_num, ($i % 5), 'Chapter ' . $day_num, 'August Task ' . $i, 'Recorded Session', $uid, 0]);
}

// 5. Populate Plan 11 Legitimate September rows (46 active rows)
for ($i = 1; $i <= 46; $i++) {
    $p11_sep_id = 41353 + $i;
    $day_num = min(30, intdiv($i - 1, 2) + 1);
    $act_date = date('Y-m-d', strtotime('2026-09-01 +' . ($day_num - 1) . ' days'));
    $uid = 'SPA-P11-SEP-' . str_pad($i, 4, '0', STR_PAD_LEFT);
    $stmt_act_ins->execute([$p11_sep_id, 11, $act_date, $day_num, ($i % 2), 'Motivation', 'September Task ' . $i, 'Practice Test', $uid, 0]);
}

// 6. Populate Plan 11 previously soft-deleted rows (279 deleted rows)
for ($i = 1; $i <= 279; $i++) {
    $p11_del_id = 41400 + $i;
    $act_date = '2026-09-02';
    $uid = 'SPA-P11-DEL-' . str_pad($i, 4, '0', STR_PAD_LEFT);
    $stmt_act_ins->execute([$p11_del_id, 11, $act_date, 2, $i, 'Motivation', 'Duplicate Test ' . $i, 'Practice Test', $uid, 1]);
}

$passed = 0;
$failed = 0;

function assert_check($desc, $cond, &$passed, &$failed) {
    if ($cond) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}\n";
    }
}

echo "======================================================================\n";
echo "       PRODUCTION DATA REMEDIATION SIMULATION & INTEGRITY AUDIT       \n";
echo "======================================================================\n\n";

// --- PRE-REMEDIATION VERIFICATION ---
echo "--- SECTION 1: Pre-Remediation Database State ---\n";
$p5_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 5 AND is_deleted = 0")->fetchColumn();
assert_check("Plan 5 has exactly 107 active activities before update", $p5_cnt === 107, $passed, $failed);

$p11_aug_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND is_deleted = 0 AND activity_date < '2026-09-01'")->fetchColumn();
assert_check("Plan 11 has exactly 107 active August clone activities before update", $p11_aug_cnt === 107, $passed, $failed);

$p11_sep_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND is_deleted = 0 AND activity_date >= '2026-09-01'")->fetchColumn();
assert_check("Plan 11 has exactly 46 active September activities before update", $p11_sep_cnt === 46, $passed, $failed);

$p11_del_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND is_deleted = 1")->fetchColumn();
assert_check("Plan 11 has exactly 279 previously soft-deleted activities", $p11_del_cnt === 279, $passed, $failed);

// --- EXECUTE REMEDIATION TRANSACTION ---
echo "\n--- SECTION 2: Execute Transactional Soft-Delete Remediation ---\n";
$pdo->beginTransaction();

$target_ids = range(41247, 41353);
$ids_str = implode(',', $target_ids);

$stmt_upd = $pdo->prepare("
    UPDATE study_plan_activities
    SET is_deleted = 1,
        deleted_at = datetime('now'),
        deleted_by = 'admin_remediation',
        deletion_reason = 'Contaminated August clone removed from September Plan 11'
    WHERE study_plan_id = 11
      AND is_deleted = 0
      AND id IN ({$ids_str})
");
$stmt_upd->execute();
$affected = $stmt_upd->rowCount();
assert_check("UPDATE affected exactly 107 rows", $affected === 107, $passed, $failed);

// --- POST-REMEDIATION ASSERTIONS BEFORE COMMIT ---
echo "\n--- SECTION 3: Post-Remediation Verification Assertions ---\n";
$p11_aug_post = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND is_deleted = 0 AND activity_date < '2026-09-01'")->fetchColumn();
assert_check("Plan 11 has 0 active August activities after update", $p11_aug_post === 0, $passed, $failed);

$p11_sep_post = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND is_deleted = 0 AND activity_date >= '2026-09-01'")->fetchColumn();
assert_check("Plan 11 retains all 46 active September activities untouched", $p11_sep_post === 46, $passed, $failed);

$p5_post = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 5 AND is_deleted = 0")->fetchColumn();
assert_check("Plan 5 retains all 107 active activities completely untouched", $p5_post === 107, $passed, $failed);

$new_remed_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND deletion_reason = 'Contaminated August clone removed from September Plan 11'")->fetchColumn();
assert_check("Exactly 107 rows tagged with exact remediation audit metadata", $new_remed_cnt === 107, $passed, $failed);

$pdo->commit();
echo "\n--- SECTION 4: Transaction Committed Successfully ---\n";

// --- SECTION 5: ROLLBACK REVERSIBILITY VERIFICATION ---
echo "--- SECTION 5: Verify Rollback Query Reversibility & Isolation ---\n";
$stmt_rev = $pdo->prepare("
    UPDATE study_plan_activities
    SET is_deleted = 0,
        deleted_at = NULL,
        deleted_by = NULL,
        deletion_reason = NULL
    WHERE study_plan_id = 11
      AND is_deleted = 1
      AND deletion_reason = 'Contaminated August clone removed from September Plan 11'
      AND id IN ({$ids_str})
");
$stmt_rev->execute();
$rev_affected = $stmt_rev->rowCount();
assert_check("Reversal query restores exactly 107 rows", $rev_affected === 107, $passed, $failed);

$p11_del_after_rev = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND is_deleted = 1")->fetchColumn();
assert_check("Reversal did NOT restore any of the 279 previously deleted rows", $p11_del_after_rev === 279, $passed, $failed);

// Re-apply remediation so database state remains remediated
$stmt_upd->execute();

echo "\n======================================================================\n";
echo "SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "======================================================================\n";

if ($failed === 0) {
    echo ">>> DATA INTEGRITY & REMEDIATION AUDIT: 100% PASS <<<\n\n";
} else {
    exit(1);
}
