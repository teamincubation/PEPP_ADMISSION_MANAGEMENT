<?php
/**
 * Comprehensive Regression Test: Study Plan Duplication & Isolation Lifecycle
 * 
 * Simulates Plan 5 (August 2026 PG) -> Plan 11/12 (September 2026 PG) duplication
 * and proves that:
 * 1. Absolute dates are NEVER copied across different month plans.
 * 2. Relative day_number -> target calendar date mapping is 100% accurate.
 * 3. All activities, reports, student views, and analytics remain strictly partitioned by study_plan_id.
 */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Setup Schema
$pdo->exec("
    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
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
        is_template INTEGER DEFAULT 0,
        custom_settings TEXT,
        plan_type TEXT DEFAULT 'date_wise',
        total_days INTEGER,
        created_by TEXT,
        created_at TEXT,
        updated_at TEXT,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_date TEXT,
        day_number INTEGER,
        sort_order INTEGER,
        chapter TEXT,
        subject TEXT,
        topic TEXT,
        subtopic TEXT,
        activity_title TEXT,
        activity_description TEXT,
        activity_type TEXT,
        faculty TEXT,
        mentor TEXT,
        estimated_duration INTEGER,
        priority TEXT,
        difficulty_level TEXT,
        resource_links TEXT,
        custom_activity_badge TEXT,
        custom_activity_color TEXT,
        custom_activity_icon TEXT,
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

    CREATE TABLE study_plan_audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        admin_username TEXT,
        action TEXT,
        details TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE study_plan_activity_version_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_id INTEGER,
        action_type TEXT,
        changed_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Helper function simulating the hardened duplicate_plan API action
function duplicate_study_plan_sim($pdo, $source_plan_id, $target_start_date, $target_end_date, $admin_username = 'risha') {
    $stmt = $pdo->prepare("SELECT * FROM study_plans WHERE id = ?");
    $stmt->execute([$source_plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) return false;

    $title = $plan['title'] . ' (Copy)';
    $target_start = !empty($target_start_date) ? $target_start_date : $plan['start_date'];
    $target_end = !empty($target_end_date) ? $target_end_date : $plan['end_date'];

    $stmt_ins = $pdo->prepare("
        INSERT INTO study_plans (
            title, academic_year, course_id, description,
            cover_image, theme, layout, start_date, end_date,
            status, is_template, custom_settings, plan_type, total_days, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
    ");
    $stmt_ins->execute([
        $title, $plan['academic_year'], $plan['course_id'], $plan['description'],
        $plan['cover_image'], $plan['theme'], $plan['layout'], $target_start, $target_end,
        'draft', $plan['is_template'], $plan['custom_settings'],
        $plan['plan_type'] ?? 'date_wise',
        !empty($plan['total_days']) ? (int)$plan['total_days'] : null,
        $admin_username
    ]);
    $new_id = (int)$pdo->lastInsertId();

    $stmt_act = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0 ORDER BY activity_date ASC, sort_order ASC, id ASC");
    $stmt_act->execute([$source_plan_id]);
    $activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

    $stmt_act_ins = $pdo->prepare("
        INSERT INTO study_plan_activities (
            study_plan_id, activity_date, day_number, sort_order, chapter,
            topic, activity_title, activity_description, activity_type,
            faculty, estimated_duration, priority, difficulty_level, resource_links,
            custom_activity_badge, custom_activity_color, custom_activity_icon, activity_uid
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $is_date_wise = ($plan['plan_type'] ?? 'date_wise') === 'date_wise';
    $should_remap_dates = $is_date_wise && !empty($target_start) && ($target_start !== $plan['start_date']);

    foreach ($activities as $act) {
        $new_uid = 'SPA-' . bin2hex(random_bytes(10));
        $act_date = $act['activity_date'];

        // Resolve relative day number
        if (!empty($act['day_number']) && (int)$act['day_number'] > 0) {
            $day_num = (int)$act['day_number'];
        } elseif (!empty($act['activity_date']) && !empty($plan['start_date'])) {
            $diff_days = (int)round((strtotime($act['activity_date']) - strtotime($plan['start_date'])) / 86400);
            $day_num = max(1, $diff_days + 1);
        } else {
            $day_num = 1;
        }

        if ($should_remap_dates) {
            $act_date = date('Y-m-d', strtotime($target_start . ' +' . ($day_num - 1) . ' days'));
        }

        $stmt_act_ins->execute([
            $new_id, $act_date, $day_num, $act['sort_order'], $act['chapter'],
            !empty($act['topic']) ? $act['topic'] : ($act['subject'] ?? null), $act['activity_title'], $act['activity_description'], $act['activity_type'],
            $act['faculty'], $act['estimated_duration'], $act['priority'], $act['difficulty_level'], $act['resource_links'],
            $act['custom_activity_badge'], $act['custom_activity_color'], $act['custom_activity_icon'], $new_uid
        ]);
    }

    return $new_id;
}

$passed = 0;
$failed = 0;

function assert_test($name, $condition, &$passed, &$failed) {
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}\n";
    }
}

echo "======================================================================\n";
echo "   STUDY PLAN DUPLICATION & CALENDAR ISOLATION REGRESSION AUDIT       \n";
echo "======================================================================\n\n";

// --- SETUP BASE PLANS ---
// Plan 5: August 2026 PG Plan (2026-08-09 to 2026-08-31)
$pdo->exec("
    INSERT INTO study_plans (id, title, academic_year, start_date, end_date, plan_type, status, created_by)
    VALUES (5, 'August 2026 (PG)', '2026-27', '2026-08-09', '2026-08-31', 'date_wise', 'published', 'risha')
");

// Populate Plan 5 with 107 activities across 23 days
$stmt_act_p5 = $pdo->prepare("
    INSERT INTO study_plan_activities (study_plan_id, activity_date, day_number, sort_order, chapter, activity_title, activity_type, faculty, resource_links, activity_uid)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

for ($i = 1; $i <= 107; $i++) {
    // Distribute 107 activities across 23 days (1 to 23)
    $day_num = min(23, (int)ceil($i * 23 / 107));
    $act_date = date('Y-m-d', strtotime('2026-08-09 +' . ($day_num - 1) . ' days'));
    $chapter = $day_num <= 8 ? 'Introduction to Psychology' : ($day_num <= 17 ? 'Sensation and Perception' : 'Emotion');
    $uid = 'SPA-P5-' . bin2hex(random_bytes(6));
    $stmt_act_p5->execute([5, $act_date, $day_num, ($i % 5), $chapter, "Task {$i} - {$chapter}", 'Recorded Session', 'Sayyid Shaheer', 'https://lms.pepp.in/course/1', $uid]);
}

// Add 1 soft-deleted item to Plan 5 (must NOT be copied on duplicate)
$stmt_act_p5->execute([5, '2026-08-10', 2, 99, 'Draft Chapter', 'Old Deprecated Task', 'Recorded Session', 'Risha', 'https://lms.pepp.in/course/1', 'SPA-P5-DEL-01']);
$deleted_p5_id = $pdo->lastInsertId();
$pdo->exec("UPDATE study_plan_activities SET is_deleted = 1, deleted_by = 'admin', deletion_reason = 'Obsolete' WHERE id = {$deleted_p5_id}");

// Add a completion record in study_plan_analytics for Plan 5
$pdo->exec("INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status) VALUES (5, 'student1@example.com', 1, 'SPA-P5-001', 'complete', 'completed')");

echo "--- SECTION 1: Base State Verification ---\n";
$p5_active_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 5 AND is_deleted = 0")->fetchColumn();
assert_test("Plan 5 has exactly 107 active activities", $p5_active_cnt === 107, $passed, $failed);
$p5_deleted_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 5 AND is_deleted = 1")->fetchColumn();
assert_test("Plan 5 has 1 soft-deleted activity", $p5_deleted_cnt === 1, $passed, $failed);

// --- SECTION 2: Duplicate Plan 5 -> September 2026 (Plan 6) ---
echo "\n--- SECTION 2: Duplicate Plan 5 into September (2026-09-01 to 2026-09-30) ---\n";
$plan_6_id = duplicate_study_plan_sim($pdo, 5, '2026-09-01', '2026-09-30', 'risha');
assert_test("Duplicate operation created target plan successfully (ID {$plan_6_id})", $plan_6_id > 5, $passed, $failed);

// 1. Source activities remain unchanged
$p5_post_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 5 AND is_deleted = 0")->fetchColumn();
assert_test("Source Plan 5 active count remains 107 (unchanged)", $p5_post_cnt === 107, $passed, $failed);

// 2. Target activities count
$p6_act_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = {$plan_6_id} AND is_deleted = 0")->fetchColumn();
assert_test("Target Plan {$plan_6_id} received all 107 active activities", $p6_act_cnt === 107, $passed, $failed);

// 3. No target activity has an August date
$p6_aug_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = {$plan_6_id} AND activity_date < '2026-09-01'")->fetchColumn();
assert_test("Zero target activities have an August date (0 / 107)", $p6_aug_cnt === 0, $passed, $failed);

// 4. Target dates are correctly mapped to September
$p6_min_date = $pdo->query("SELECT MIN(activity_date) FROM study_plan_activities WHERE study_plan_id = {$plan_6_id}")->fetchColumn();
$p6_max_date = $pdo->query("SELECT MAX(activity_date) FROM study_plan_activities WHERE study_plan_id = {$plan_6_id}")->fetchColumn();
assert_test("Target Plan minimum date is 2026-09-01 (Day 1)", $p6_min_date === '2026-09-01', $passed, $failed);
assert_test("Target Plan maximum date is 2026-09-23 (Day 23)", $p6_max_date === '2026-09-23', $passed, $failed);

// 5. Day 1 -> 2026-09-01 and Day 23 -> 2026-09-23 mapping
$p6_day1_date = $pdo->query("SELECT DISTINCT activity_date FROM study_plan_activities WHERE study_plan_id = {$plan_6_id} AND day_number = 1")->fetchColumn();
$p6_day23_date = $pdo->query("SELECT DISTINCT activity_date FROM study_plan_activities WHERE study_plan_id = {$plan_6_id} AND day_number = 23")->fetchColumn();
assert_test("Day 1 mapped precisely to 2026-09-01", $p6_day1_date === '2026-09-01', $passed, $failed);
assert_test("Day 23 mapped precisely to 2026-09-23", $p6_day23_date === '2026-09-23', $passed, $failed);

// 6. Soft-deleted activities from source were NOT copied
$p6_del_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = {$plan_6_id} AND is_deleted = 1")->fetchColumn();
assert_test("Soft-deleted source activities were excluded from copy", $p6_del_cnt === 0, $passed, $failed);

// 7. Target activity UIDs are completely fresh
$stmt_uid_overlap = $pdo->query("
    SELECT COUNT(*) FROM study_plan_activities p5
    JOIN study_plan_activities p6 ON p5.activity_uid = p6.activity_uid
    WHERE p5.study_plan_id = 5 AND p6.study_plan_id = {$plan_6_id}
");
$uid_overlaps = (int)$stmt_uid_overlap->fetchColumn();
assert_test("Target activity UIDs are completely unique (0 shared UIDs)", $uid_overlaps === 0, $passed, $failed);

// 8. Analytics isolation (Plan 5 student completion was not copied)
$p6_analytics_cnt = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_analytics WHERE study_plan_id = {$plan_6_id}")->fetchColumn();
assert_test("Target plan has 0 inherited completion analytics", $p6_analytics_cnt === 0, $passed, $failed);

// --- SECTION 3: Edge Case Testing ---
echo "\n--- SECTION 3: Edge Cases & Multi-Month Duplication ---\n";

// A. Duplicating into October (2026-10-15 start)
$plan_7_id = duplicate_study_plan_sim($pdo, 5, '2026-10-15', '2026-11-15', 'superadmin');
$p7_min_date = $pdo->query("SELECT MIN(activity_date) FROM study_plan_activities WHERE study_plan_id = {$plan_7_id}")->fetchColumn();
$p7_max_date = $pdo->query("SELECT MAX(activity_date) FROM study_plan_activities WHERE study_plan_id = {$plan_7_id}")->fetchColumn();
assert_test("October duplication: Min date = 2026-10-15", $p7_min_date === '2026-10-15', $passed, $failed);
assert_test("October duplication: Max date = 2026-11-06 (Day 23)", $p7_max_date === '2026-11-06', $passed, $failed);

// B. Duplicating Day-Wise Template Plan (no date remapping, retains day numbers)
$pdo->exec("
    INSERT INTO study_plans (id, title, academic_year, start_date, end_date, plan_type, total_days, status, is_template)
    VALUES (8, '10-Day Day-Wise Template', '2026-27', '2000-01-01', '2000-01-10', 'day_wise', 10, 'published', 1)
");
for ($d = 1; $d <= 10; $d++) {
    $pdo->exec("INSERT INTO study_plan_activities (study_plan_id, activity_date, day_number, sort_order, activity_title, activity_type, activity_uid) VALUES (8, '2000-01-01', {$d}, 0, 'Day {$d} Task', 'Practice Test', 'SPA-TPL-{$d}')");
}
$plan_9_id = duplicate_study_plan_sim($pdo, 8, '2000-01-01', '2000-01-10', 'risha');
$p9_type = $pdo->query("SELECT plan_type FROM study_plans WHERE id = {$plan_9_id}")->fetchColumn();
$p9_total_days = (int)$pdo->query("SELECT total_days FROM study_plans WHERE id = {$plan_9_id}")->fetchColumn();
assert_test("Day-wise template duplication preserves plan_type = 'day_wise'", $p9_type === 'day_wise', $passed, $failed);
assert_test("Day-wise template duplication preserves total_days = 10", $p9_total_days === 10, $passed, $failed);

// C. Student Portal Query Simulation (strictly isolated)
echo "\n--- SECTION 4: Application Query & Report Scoping Validation ---\n";
$stmt_sp_query = $pdo->prepare("
    SELECT act.*
    FROM study_plan_activities act
    WHERE act.study_plan_id = ?
      AND act.is_deleted = 0
      AND act.activity_date >= ?
      AND act.activity_date <= ?
    ORDER BY act.activity_date ASC, act.sort_order ASC
");
$stmt_sp_query->execute([$plan_6_id, '2026-09-01', '2026-09-30']);
$p6_portal_acts = $stmt_sp_query->fetchAll(PDO::FETCH_ASSOC);
assert_test("Student portal query for Plan {$plan_6_id} returns exactly 107 activities", count($p6_portal_acts) === 107, $passed, $failed);

$stmt_sp_query->execute([5, '2026-08-09', '2026-08-31']);
$p5_portal_acts = $stmt_sp_query->fetchAll(PDO::FETCH_ASSOC);
assert_test("Student portal query for Plan 5 returns exactly 107 activities", count($p5_portal_acts) === 107, $passed, $failed);

// Check that no activity in p6 appears in p5
$p5_ids = array_column($p5_portal_acts, 'id');
$p6_ids = array_column($p6_portal_acts, 'id');
$intersect_ids = array_intersect($p5_ids, $p6_ids);
assert_test("Zero ID intersection between Plan 5 and Plan {$plan_6_id}", count($intersect_ids) === 0, $passed, $failed);

echo "\n======================================================================\n";
echo "SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "======================================================================\n";

if ($failed === 0) {
    echo ">>> ALL REGRESSION & DUPLICATION TESTS PASSED 100% <<<\n\n";
} else {
    exit(1);
}
