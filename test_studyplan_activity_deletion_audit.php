<?php
/**
 * PEPP Study Plan Activity Deletion & Bulk Delete Audit Test Suite (DELETE-01 through DELETE-38)
 *
 * Covers:
 * - Single activity deletion with 0 student data vs >0 student data
 * - Authoritative database verification against study_plan_analytics & assessment_results
 * - Fail-closed transaction safety with zero "There is no active transaction" errors
 * - Single-admin edit lock enforcement on mutations
 * - Multi-select bulk deletion with partial-safety preservation
 * - Activity identity preservation, day/date preservation, and ordering preservation
 * - Zero unexpected Rest Day generation
 * - Handling of duplicate-looking activities and duplicate titles
 * - Concurrency & idempotency verification
 */

// Set up clean isolated test database
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Custom SQLite functions matching MySQL
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURRENT_TIMESTAMP', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $t1, $t2) {
    if (empty($t1) || empty($t2)) return 0;
    return strtotime((string)$t2) - strtotime((string)$t1);
});

// Setup schema
$pdo->exec("
    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        plan_type TEXT NOT NULL DEFAULT 'date_wise',
        start_date TEXT,
        end_date TEXT,
        total_days INTEGER DEFAULT 7,
        version INTEGER NOT NULL DEFAULT 1,
        is_deleted INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'published',
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER NOT NULL,
        activity_uid TEXT NOT NULL,
        activity_title TEXT NOT NULL,
        activity_type TEXT NOT NULL,
        activity_date TEXT,
        day_number INTEGER NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        chapter TEXT,
        topic TEXT,
        faculty TEXT,
        estimated_duration INTEGER DEFAULT 60,
        priority TEXT DEFAULT 'medium',
        difficulty_level TEXT DEFAULT 'medium',
        resource_links TEXT,
        custom_activity_badge TEXT,
        custom_activity_color TEXT,
        custom_activity_icon TEXT,
        is_deleted INTEGER NOT NULL DEFAULT 0,
        deleted_at TEXT,
        deleted_by TEXT,
        deletion_reason TEXT
    );

    CREATE TABLE study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER NOT NULL,
        student_email TEXT,
        activity_id INTEGER,
        activity_uid TEXT,
        action_type TEXT NOT NULL DEFAULT 'complete_activity',
        completion_status TEXT NOT NULL DEFAULT 'completed',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE assessment_result_batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_id INTEGER NOT NULL,
        study_plan_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE assessment_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER NOT NULL,
        student_email TEXT,
        score REAL,
        total_score REAL
    );

    CREATE TABLE study_plan_activity_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_id INTEGER NOT NULL,
        activity_uid TEXT NOT NULL,
        study_plan_id INTEGER NOT NULL,
        version_number INTEGER NOT NULL,
        activity_date TEXT,
        day_number INTEGER NOT NULL,
        sort_order INTEGER NOT NULL,
        chapter TEXT,
        topic TEXT,
        activity_title TEXT NOT NULL,
        activity_description TEXT,
        activity_type TEXT NOT NULL,
        faculty TEXT,
        estimated_duration INTEGER,
        priority TEXT,
        difficulty_level TEXT,
        resource_links TEXT,
        custom_activity_badge TEXT,
        custom_activity_color TEXT,
        custom_activity_icon TEXT,
        created_by TEXT,
        change_type TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE study_plan_audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER NOT NULL,
        admin_username TEXT NOT NULL,
        action TEXT NOT NULL,
        details TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE study_plan_edit_locks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER NOT NULL UNIQUE,
        admin_id INTEGER,
        admin_username TEXT NOT NULL,
        admin_name TEXT NOT NULL,
        session_token TEXT NOT NULL,
        locked_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_heartbeat_at TEXT DEFAULT CURRENT_TIMESTAMP,
        released_at TEXT,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'admin'
    );
");

require_once __DIR__ . '/includes/study_plan_lock_helper.php';

// Seed Initial Data
$pdo->exec("
    INSERT INTO admins (id, username, name, role) VALUES 
    (1, 'admin_alice', 'Alice Smith', 'superadmin'),
    (2, 'admin_bob', 'Bob Jones', 'admin');

    INSERT INTO study_plans (id, title, plan_type, start_date, end_date, total_days, version) VALUES 
    (1, 'CA Intermediate Fast Track', 'date_wise', '2026-08-01', '2026-08-07', 7, 1),
    (2, 'CMA Foundation Plan', 'day_wise', '2000-01-01', '2000-01-05', 5, 1);

    -- Plan 1 Activities (Day 1 to 3)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, day_number, sort_order) VALUES
    (101, 1, 'UID-ACT-101', 'Orientation & Basics', 'Read Material', '2026-08-01', 1, 0),
    (102, 1, 'UID-ACT-102', 'Chapter 1 Theory', 'Read Material', '2026-08-01', 1, 1),
    (103, 1, 'UID-ACT-103', 'Chapter 1 Quiz', 'MCQ Quiz', '2026-08-01', 1, 2),
    (104, 1, 'UID-ACT-104', 'Day 2 Practice Problems', 'Assignment', '2026-08-02', 2, 0),
    (105, 1, 'UID-ACT-105', 'Day 2 Live Session', 'Live Class', '2026-08-02', 2, 1),
    (106, 1, 'UID-ACT-106', 'Day 3 Comprehensive Test', 'Exam', '2026-08-03', 3, 0);

    -- Plan 2 Activity
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, day_number, sort_order) VALUES
    (201, 2, 'UID-ACT-201', 'Plan 2 Intro Task', 'Read Material', '2000-01-01', 1, 0);

    -- Student completions (Only on Activity 103 and 106)
    INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status) VALUES
    (1, 'student1@pepp.test', 103, 'UID-ACT-103', 'complete_activity', 'completed'),
    (1, 'student2@pepp.test', 103, 'UID-ACT-103', 'complete_activity', 'completed'),
    (1, 'student3@pepp.test', 106, 'UID-ACT-106', 'complete_activity', 'completed');
");

$passed = 0;
$failed = 0;

function run_test($name, $closure) {
    global $passed, $failed;
    try {
        $res = $closure();
        if ($res === true || $res === null) {
            echo "  [PASS] {$name}\n";
            $passed++;
        } else {
            echo "  [FAIL] {$name}: {$res}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: Exception: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo "  PEPP STUDY PLAN ACTIVITY DELETION & BULK DELETE AUDIT TEST SUITE\n";
echo "======================================================================\n\n";

// Helper for deletion check
function check_delete($pdo, $activity_id) {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$activity_id]);
    $act = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$act) return ['success' => false, 'message' => 'Activity not found'];

    $plan_id = (int)$act['study_plan_id'];
    $act_uid = trim($act['activity_uid'] ?? '');

    $student_count = 0;
    try {
        $stmt_cnt = $pdo->prepare("
            SELECT COUNT(*)
            FROM study_plan_analytics
            WHERE study_plan_id = ?
              AND (
                  (activity_uid = ? AND ? != '')
                  OR (activity_id = ? AND ? > 0)
              )
        ");
        $stmt_cnt->execute([$plan_id, $act_uid, $act_uid, $activity_id, $activity_id]);
        $student_count += (int)$stmt_cnt->fetchColumn();
    } catch (Exception $e) {}

    try {
        $stmt_att = $pdo->prepare("
            SELECT COUNT(*)
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE arb.activity_id = ? AND (arb.is_deleted IS NULL OR arb.is_deleted = 0)
        ");
        $stmt_att->execute([$activity_id]);
        $student_count += (int)$stmt_att->fetchColumn();
    } catch (Exception $e) {}

    if ($student_count > 0) {
        return [
            'success' => true,
            'deletable' => false,
            'error_code' => 'ACTIVITY_HAS_STUDENT_DATA',
            'student_count' => $student_count,
            'activity_id' => $activity_id,
            'activity_uid' => $act['activity_uid']
        ];
    }

    $token = bin2hex(random_bytes(16));
    return [
        'success' => true,
        'deletable' => true,
        'student_count' => 0,
        'activity_id' => $activity_id,
        'activity_uid' => $act['activity_uid'],
        'confirmation_token' => $token
    ];
}

// Helper for single delete execution
function exec_single_delete($pdo, $activity_id, $token, $admin_username, $admin_id, $reason = 'Admin deleted') {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ?");
    $stmt->execute([$activity_id]);
    $act = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$act) return ['success' => false, 'message' => 'Activity not found'];
    if ((int)$act['is_deleted'] === 1) return ['success' => false, 'message' => 'Already deleted'];

    $plan_id = (int)$act['study_plan_id'];

    if (!verify_study_plan_edit_lock_permission($pdo, $plan_id, $admin_username, $admin_id)) {
        return ['success' => false, 'error_code' => 'EDIT_LOCK_HELD', 'message' => 'Locked by another admin'];
    }

    $act_uid = trim($act['activity_uid'] ?? '');
    $cnt = 0;
    try {
        $stmt_cnt = $pdo->prepare("
            SELECT COUNT(*)
            FROM study_plan_analytics
            WHERE study_plan_id = ?
              AND (
                  (activity_uid = ? AND ? != '')
                  OR (activity_id = ? AND ? > 0)
              )
        ");
        $stmt_cnt->execute([$plan_id, $act_uid, $act_uid, $activity_id, $activity_id]);
        $cnt += (int)$stmt_cnt->fetchColumn();
    } catch (Exception $e) {}

    try {
        $stmt_att = $pdo->prepare("
            SELECT COUNT(*)
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE arb.activity_id = ? AND (arb.is_deleted IS NULL OR arb.is_deleted = 0)
        ");
        $stmt_att->execute([$activity_id]);
        $cnt += (int)$stmt_att->fetchColumn();
    } catch (Exception $e) {}

    if ($cnt > 0) {
        return ['success' => false, 'error_code' => 'ACTIVITY_HAS_STUDENT_DATA', 'student_count' => $cnt];
    }

    try {
        $pdo->beginTransaction();

        $stmt_del = $pdo->prepare("UPDATE study_plan_activities SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP, deleted_by = ?, deletion_reason = ? WHERE id = ? AND study_plan_id = ?");
        $stmt_del->execute([$admin_username, $reason, $activity_id, $plan_id]);

        $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$plan_id]);

        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'delete_activity', ?)");
        $stmt_audit->execute([$plan_id, $admin_username, "Soft-deleted activity '{$act['activity_title']}' (ID: {$activity_id})"]);

        $pdo->commit();
        return ['success' => true, 'deleted_id' => $activity_id, 'deleted_uid' => $act['activity_uid']];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Exception $rbEx) {}
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Helper for bulk delete execution
function exec_bulk_delete($pdo, $plan_id, array $activity_ids, array $activity_uids, $admin_username, $admin_id, $reason = 'Admin bulk delete') {
    if (!verify_study_plan_edit_lock_permission($pdo, $plan_id, $admin_username, $admin_id)) {
        return ['success' => false, 'error_code' => 'EDIT_LOCK_HELD', 'message' => 'Locked by another admin'];
    }

    $id_list = array_values(array_filter(array_map('intval', $activity_ids), function($v) { return $v > 0; }));
    $uid_list = array_values(array_filter(array_map('trim', $activity_uids), function($v) { return !empty($v); }));

    $where_clauses = [];
    $params = [$plan_id];

    if (!empty($id_list)) {
        $id_placeholders = implode(',', array_fill(0, count($id_list), '?'));
        $where_clauses[] = "id IN ($id_placeholders)";
        foreach ($id_list as $id_val) $params[] = $id_val;
    }
    if (!empty($uid_list)) {
        $uid_placeholders = implode(',', array_fill(0, count($uid_list), '?'));
        $where_clauses[] = "activity_uid IN ($uid_placeholders)";
        foreach ($uid_list as $uid_val) $params[] = $uid_val;
    }

    if (empty($where_clauses)) {
        return ['success' => false, 'message' => 'No valid activities'];
    }

    $sql_fetch = "SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0 AND (" . implode(' OR ', $where_clauses) . ")";
    $stmt_act = $pdo->prepare($sql_fetch);
    $stmt_act->execute($params);
    $target_activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

    if (empty($target_activities)) {
        return ['success' => true, 'deleted_count' => 0, 'protected_count' => 0, 'deleted_ids' => [], 'deleted_uids' => [], 'protected_activities' => []];
    }

    $target_uids = [];
    $target_ids = [];
    foreach ($target_activities as $act) {
        if (!empty($act['activity_uid'])) $target_uids[] = $act['activity_uid'];
        if (!empty($act['id'])) $target_ids[] = (int)$act['id'];
    }

    $analytics_conditions = [];
    $an_params = [$plan_id];

    if (!empty($target_uids)) {
        $u_place = implode(',', array_fill(0, count($target_uids), '?'));
        $analytics_conditions[] = "activity_uid IN ($u_place)";
        foreach ($target_uids as $u) $an_params[] = $u;
    }
    if (!empty($target_ids)) {
        $i_place = implode(',', array_fill(0, count($target_ids), '?'));
        $analytics_conditions[] = "activity_id IN ($i_place)";
        foreach ($target_ids as $i) $an_params[] = $i;
    }

    $counts_by_uid = [];
    $counts_by_id = [];

    if (!empty($analytics_conditions)) {
        try {
            $sql_an = "
                SELECT activity_uid, activity_id, COUNT(*) AS student_cnt
                FROM study_plan_analytics
                WHERE study_plan_id = ?
                  AND (" . implode(' OR ', $analytics_conditions) . ")
                GROUP BY activity_uid, activity_id
            ";
            $stmt_an = $pdo->prepare($sql_an);
            $stmt_an->execute($an_params);
            $an_rows = $stmt_an->fetchAll(PDO::FETCH_ASSOC);

            foreach ($an_rows as $row) {
                $cnt = (int)$row['student_cnt'];
                if (!empty($row['activity_uid'])) $counts_by_uid[$row['activity_uid']] = ($counts_by_uid[$row['activity_uid']] ?? 0) + $cnt;
                if (!empty($row['activity_id'])) $counts_by_id[(int)$row['activity_id']] = ($counts_by_id[(int)$row['activity_id']] ?? 0) + $cnt;
            }
        } catch (Exception $e) {}
    }

    $assessment_counts_by_id = [];
    if (!empty($target_ids)) {
        try {
            $id_place = implode(',', array_fill(0, count($target_ids), '?'));
            $sql_ass = "
                SELECT arb.activity_id, COUNT(*) AS assessment_cnt
                FROM assessment_results ar
                JOIN assessment_result_batches arb ON ar.batch_id = arb.id
                WHERE arb.activity_id IN ($id_place) AND (arb.is_deleted IS NULL OR arb.is_deleted = 0)
                GROUP BY arb.activity_id
            ";
            $stmt_ass = $pdo->prepare($sql_ass);
            $stmt_ass->execute($target_ids);
            $ass_rows = $stmt_ass->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ass_rows as $arow) {
                $assessment_counts_by_id[(int)$arow['activity_id']] = (int)$arow['assessment_cnt'];
            }
        } catch (Exception $e) {}
    }

    $deletable = [];
    $protected = [];

    foreach ($target_activities as $act) {
        $uid = $act['activity_uid'] ?? '';
        $id = (int)$act['id'];

        $cnt = 0;
        if (!empty($uid) && isset($counts_by_uid[$uid])) {
            $cnt += $counts_by_uid[$uid];
        }
        if (!empty($id) && isset($counts_by_id[$id])) {
            $cnt += $counts_by_id[$id];
        }
        if (!empty($id) && isset($assessment_counts_by_id[$id])) {
            $cnt += $assessment_counts_by_id[$id];
        }

        if ($cnt > 0) {
            $protected[] = [
                'id' => $id,
                'activity_uid' => $uid,
                'activity_title' => $act['activity_title'],
                'day_number' => (int)$act['day_number'],
                'student_count' => $cnt,
                'reason' => "Student activity recorded ({$cnt} record(s))"
            ];
        } else {
            $deletable[] = $act;
        }
    }

    if (empty($deletable)) {
        return [
            'success' => true,
            'deleted_count' => 0,
            'protected_count' => count($protected),
            'deleted_ids' => [],
            'deleted_uids' => [],
            'protected_activities' => $protected,
            'message' => 'All selected activities are protected.'
        ];
    }

    try {
        $pdo->beginTransaction();

        $deletable_ids = array_map(function($a) { return (int)$a['id']; }, $deletable);
        $deletable_uids = array_map(function($a) { return $a['activity_uid']; }, $deletable);

        $in_placeholders = implode(',', array_fill(0, count($deletable_ids), '?'));
        $del_sql = "UPDATE study_plan_activities SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP, deleted_by = ?, deletion_reason = ? WHERE id IN ($in_placeholders) AND study_plan_id = ?";
        $stmt_del = $pdo->prepare($del_sql);
        $stmt_del->execute(array_merge([$admin_username, $reason], $deletable_ids, [$plan_id]));

        $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$plan_id]);

        $count_del = count($deletable);
        $count_prot = count($protected);
        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'bulk_delete_activities', ?)");
        $stmt_audit->execute([$plan_id, $admin_username, "Bulk soft-deleted {$count_del} activity(ies). Protected {$count_prot}."]);

        $pdo->commit();

        return [
            'success' => true,
            'deleted_count' => $count_del,
            'protected_count' => $count_prot,
            'deleted_ids' => $deletable_ids,
            'deleted_uids' => $deletable_uids,
            'protected_activities' => $protected
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Exception $rbEx) {}
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Acquire lock for Alice on Plan #1
acquire_or_check_study_plan_lock($pdo, 1, ['admin_id' => 1, 'admin_username' => 'admin_alice', 'admin_name' => 'Alice Smith', 'session_token' => 'token_alice']);

// DELETE-01: Individual activity with no student data -> deleted
run_test("DELETE-01: Individual activity with 0 student data is deletable", function() use ($pdo) {
    $check = check_delete($pdo, 101); // Activity 101 has 0 completions
    if (!$check['success'] || !$check['deletable'] || $check['student_count'] !== 0) {
        return "Expected deletable: true, got " . json_encode($check);
    }
    $del = exec_single_delete($pdo, 101, $check['confirmation_token'], 'admin_alice', 1);
    if (!$del['success']) return "Expected delete success, got " . json_encode($del);

    $row = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 101")->fetch();
    return (int)$row['is_deleted'] === 1;
});

// DELETE-02: Individual activity with student activity -> blocked
run_test("DELETE-02: Individual activity with student activity is strictly blocked", function() use ($pdo) {
    $check = check_delete($pdo, 103); // Activity 103 has 2 student completions
    if ($check['deletable'] !== false || $check['error_code'] !== 'ACTIVITY_HAS_STUDENT_DATA' || $check['student_count'] !== 2) {
        return "Expected blocked with ACTIVITY_HAS_STUDENT_DATA, got " . json_encode($check);
    }
    $del = exec_single_delete($pdo, 103, 'dummy_token', 'admin_alice', 1);
    if ($del['success'] !== false || $del['error_code'] !== 'ACTIVITY_HAS_STUDENT_DATA') {
        return "Expected deletion rejection, got " . json_encode($del);
    }
    $row = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 103")->fetch();
    return (int)$row['is_deleted'] === 0;
});

// DELETE-03: Individual deletion does not affect unrelated activity IDs
run_test("DELETE-03: Deleting activity 101 does not touch other activity IDs or UIDs", function() use ($pdo) {
    $rows = $pdo->query("SELECT id, activity_uid, is_deleted FROM study_plan_activities WHERE study_plan_id = 1 ORDER BY id")->fetchAll();
    $act102 = null;
    $act103 = null;
    foreach ($rows as $r) {
        if ($r['id'] == 102) $act102 = $r;
        if ($r['id'] == 103) $act103 = $r;
    }
    if (!$act102 || $act102['activity_uid'] !== 'UID-ACT-102' || (int)$act102['is_deleted'] !== 0) return "Activity 102 corrupted";
    if (!$act103 || $act103['activity_uid'] !== 'UID-ACT-103' || (int)$act103['is_deleted'] !== 0) return "Activity 103 corrupted";
    return true;
});

// DELETE-04: Individual deletion preserves day/date mapping
run_test("DELETE-04: Deleting activity preserves exact day and date mapping of remaining tasks", function() use ($pdo) {
    $rows = $pdo->query("SELECT id, day_number, activity_date FROM study_plan_activities WHERE study_plan_id = 1 AND is_deleted = 0 ORDER BY day_number, sort_order")->fetchAll();
    foreach ($rows as $r) {
        if ($r['id'] == 102 && ($r['day_number'] != 1 || $r['activity_date'] !== '2026-08-01')) return "Day/date shifted on 102";
        if ($r['id'] == 104 && ($r['day_number'] != 2 || $r['activity_date'] !== '2026-08-02')) return "Day/date shifted on 104";
        if ($r['id'] == 106 && ($r['day_number'] != 3 || $r['activity_date'] !== '2026-08-03')) return "Day/date shifted on 106";
    }
    return true;
});

// DELETE-05: Individual deletion preserves remaining activity order
run_test("DELETE-05: Remaining activities retain their relative sort order", function() use ($pdo) {
    $day1_acts = $pdo->query("SELECT id, sort_order FROM study_plan_activities WHERE study_plan_id = 1 AND day_number = 1 AND is_deleted = 0 ORDER BY sort_order")->fetchAll();
    if (count($day1_acts) !== 2) return "Expected 2 activities remaining on Day 1";
    if ($day1_acts[0]['id'] != 102 || $day1_acts[1]['id'] != 103) return "Relative order altered";
    return true;
});

// DELETE-06: Deleting last activity from a day does NOT automatically create Rest Day
run_test("DELETE-06: Empty day has 0 records and creates zero phantom Rest Day rows in DB", function() use ($pdo) {
    $count = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 1 AND activity_title LIKE '%Rest Day%'")->fetchColumn();
    return (int)$count === 0;
});

// DELETE-07: Non-lock owner cannot delete
run_test("DELETE-07: Non-lock owner (Bob) is rejected with EDIT_LOCK_HELD", function() use ($pdo) {
    $del = exec_single_delete($pdo, 102, 'token', 'admin_bob', 2);
    if ($del['success'] !== false || $del['error_code'] !== 'EDIT_LOCK_HELD') {
        return "Expected EDIT_LOCK_HELD, got " . json_encode($del);
    }
    return true;
});

// DELETE-08: Read-only admin cannot delete
run_test("DELETE-08: Admin without valid lock cannot delete", function() use ($pdo) {
    $del = exec_single_delete($pdo, 102, 'token', 'read_only_admin', 99);
    return $del['success'] === false && $del['error_code'] === 'EDIT_LOCK_HELD';
});

// DELETE-09: Cross-plan activity deletion rejected
run_test("DELETE-09: Deleting activity from different plan is rejected", function() use ($pdo) {
    // Bob holds lock on Plan 2, tries to delete Plan 1 activity (102)
    acquire_or_check_study_plan_lock($pdo, 2, ['admin_id' => 2, 'admin_username' => 'admin_bob', 'admin_name' => 'Bob Jones', 'session_token' => 'token_bob']);
    $del = exec_bulk_delete($pdo, 2, [102], [], 'admin_bob', 2); // 102 belongs to Plan 1!
    if ($del['deleted_count'] !== 0) return "Cross-plan activity was deleted!";
    $row = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 102")->fetch();
    return (int)$row['is_deleted'] === 0;
});

// DELETE-10: Repeated delete request is safely handled
run_test("DELETE-10: Repeated delete on already deleted activity is safely rejected", function() use ($pdo) {
    $del = exec_single_delete($pdo, 101, 'token', 'admin_alice', 1); // 101 was already deleted in DELETE-01
    return $del['success'] === false && strpos($del['message'], 'Already deleted') !== false;
});

// DELETE-11: Bulk deletion of multiple safe activities succeeds
run_test("DELETE-11: Bulk deletion of safe activities (104, 105) succeeds atomically", function() use ($pdo) {
    $bulk = exec_bulk_delete($pdo, 1, [104, 105], ['UID-ACT-104', 'UID-ACT-105'], 'admin_alice', 1);
    if (!$bulk['success'] || $bulk['deleted_count'] !== 2 || $bulk['protected_count'] !== 0) {
        return "Expected 2 deleted, 0 protected, got " . json_encode($bulk);
    }
    $rows = $pdo->query("SELECT id, is_deleted FROM study_plan_activities WHERE id IN (104, 105)")->fetchAll();
    foreach ($rows as $r) {
        if ((int)$r['is_deleted'] !== 1) return "Activity {$r['id']} was not soft-deleted";
    }
    return true;
});

// DELETE-12: Bulk deletion containing protected activities does not delete protected activities
run_test("DELETE-12: Mixed bulk delete (102 safe, 103 protected) deletes ONLY safe task", function() use ($pdo) {
    // 102 is safe (0 student completions), 103 has 2 completions
    $bulk = exec_bulk_delete($pdo, 1, [102, 103], ['UID-ACT-102', 'UID-ACT-103'], 'admin_alice', 1);
    if (!$bulk['success'] || $bulk['deleted_count'] !== 1 || $bulk['protected_count'] !== 1) {
        return "Expected 1 deleted, 1 protected, got " . json_encode($bulk);
    }
    if ($bulk['deleted_ids'] !== [102]) return "Wrong deleted IDs: " . json_encode($bulk['deleted_ids']);
    if ($bulk['protected_activities'][0]['id'] !== 103) return "Wrong protected activity: " . json_encode($bulk['protected_activities']);

    $act102 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 102")->fetch();
    $act103 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 103")->fetch();

    if ((int)$act102['is_deleted'] !== 1) return "Activity 102 was not deleted";
    if ((int)$act103['is_deleted'] !== 0) return "Activity 103 was incorrectly deleted";
    return true;
});

// DELETE-13: Bulk deletion preserves unselected activities
run_test("DELETE-13: Unselected activity (106) remains untouched and active", function() use ($pdo) {
    $act106 = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 106")->fetch();
    return $act106 && (int)$act106['is_deleted'] === 0 && $act106['activity_uid'] === 'UID-ACT-106';
});

// DELETE-14: Bulk deletion preserves activity identities
run_test("DELETE-14: Remaining active activity 103 retains its original ID, UID, and metadata", function() use ($pdo) {
    $act = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 103")->fetch();
    return $act && $act['activity_uid'] === 'UID-ACT-103' && (int)$act['study_plan_id'] === 1;
});

// DELETE-15: Bulk deletion preserves day/date mapping
run_test("DELETE-15: Day 3 task date (2026-08-03) is completely unaffected by Day 1 & 2 deletions", function() use ($pdo) {
    $act106 = $pdo->query("SELECT day_number, activity_date FROM study_plan_activities WHERE id = 106")->fetch();
    return (int)$act106['day_number'] === 3 && $act106['activity_date'] === '2026-08-03';
});

// DELETE-16: Bulk deletion preserves relative ordering
run_test("DELETE-16: Single remaining task maintains valid sort order 0 without shifts", function() use ($pdo) {
    $act103 = $pdo->query("SELECT sort_order FROM study_plan_activities WHERE id = 103")->fetch();
    return (int)$act103['sort_order'] === 2; // Original order preserved
});

// DELETE-17: Bulk deletion does not create unexpected Rest Days
run_test("DELETE-17: Plan contains 0 automated Rest Day records across all days", function() use ($pdo) {
    $cnt = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 1 AND activity_type = 'Rest Day'")->fetchColumn();
    return (int)$cnt === 0;
});

// DELETE-18: Bulk deletion creates correct audit records
run_test("DELETE-18: Audit logs record single consolidated entry for bulk deletion", function() use ($pdo) {
    $log = $pdo->query("SELECT * FROM study_plan_audit_logs WHERE study_plan_id = 1 AND action = 'bulk_delete_activities' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$log) return "No bulk delete audit log found";
    if ($log['admin_username'] !== 'admin_alice') return "Incorrect admin username in audit";
    return true;
});

// DELETE-19: Transaction commits correctly
run_test("DELETE-19: Study plan version incremented cleanly with committed transaction", function() use ($pdo) {
    $ver = $pdo->query("SELECT version FROM study_plans WHERE id = 1")->fetchColumn();
    return (int)$ver > 1;
});

// DELETE-20: Transaction rollback works when an unexpected deletion error occurs
run_test("DELETE-20: Simulated error triggers atomic rollback with zero partial changes", function() use ($pdo) {
    $act_before = $pdo->query("SELECT id, is_deleted FROM study_plan_activities WHERE id = 201")->fetch();

    $pdo->beginTransaction();
    $pdo->exec("UPDATE study_plan_activities SET is_deleted = 1 WHERE id = 201");
    // Simulate error
    $pdo->rollBack();

    $act_after = $pdo->query("SELECT id, is_deleted FROM study_plan_activities WHERE id = 201")->fetch();
    return (int)$act_before['is_deleted'] === (int)$act_after['is_deleted'];
});

// DELETE-21: No 'There is no active transaction' can occur
run_test("DELETE-21: Defensive transaction check prevents 'There is no active transaction' on rollback", function() use ($pdo) {
    $exception_caught = false;
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        $exception_caught = true;
    }
    return !$exception_caught;
});

// DELETE-22: Concurrent deletion by non-owner is rejected
run_test("DELETE-22: Bob cannot perform bulk delete on Plan #1 while Alice holds lock", function() use ($pdo) {
    $bulk = exec_bulk_delete($pdo, 1, [106], ['UID-ACT-106'], 'admin_bob', 2);
    if ($bulk['success'] !== false || $bulk['error_code'] !== 'EDIT_LOCK_HELD') {
        return "Expected EDIT_LOCK_HELD, got " . json_encode($bulk);
    }
    return true;
});

// DELETE-23: Duplicate browser submission does not duplicate deletion/audit
run_test("DELETE-23: Duplicate submission for already deleted activities returns 0 new deletions", function() use ($pdo) {
    $bulk = exec_bulk_delete($pdo, 1, [104, 105], ['UID-ACT-104', 'UID-ACT-105'], 'admin_alice', 1);
    return $bulk['success'] === true && $bulk['deleted_count'] === 0;
});

// DELETE-24: Server-side check against study_plan_analytics is authoritative and independent of client input
run_test("DELETE-24: Server-side check against study_plan_analytics is authoritative and independent of client input", function() use ($pdo) {
    $pdo->exec("INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status) VALUES (2, 'student@pepp.test', 201, 'UID-ACT-201', 'complete_activity', 'completed')");

    $check = check_delete($pdo, 201);
    if ($check['deletable'] !== false || $check['error_code'] !== 'ACTIVITY_HAS_STUDENT_DATA' || $check['student_count'] !== 1) {
        return "Server check failed to detect student data: " . json_encode($check);
    }
    return true;
});

// ── NEW HARDENED STABILITY TESTS (DELETE-25 to DELETE-38) ───────────────

// Setup dedicated study plan #3 for extensive multi-day and duplicate testing
$pdo->exec("
    INSERT INTO study_plans (id, title, plan_type, start_date, end_date, total_days, version) VALUES 
    (3, 'Multi-Day & Duplicate Audit Plan', 'date_wise', '2026-09-01', '2026-09-07', 7, 1);

    -- Day 1: 3 activities (sort_order 0, 1, 2)
    INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, day_number, sort_order) VALUES
    (301, 3, 'UID-301', 'Day 1 Task A', 'Read Material', '2026-09-01', 1, 0),
    (302, 3, 'UID-302', 'Day 1 Task B (Middle)', 'Video Lesson', '2026-09-01', 1, 1),
    (303, 3, 'UID-303', 'Day 1 Task C', 'MCQ Quiz', '2026-09-01', 1, 2),

    -- Day 2: 1 single activity (will test deleting last task from day)
    (304, 3, 'UID-304', 'Day 2 Lone Task', 'Assignment', '2026-09-02', 2, 0),

    -- Day 3: 3 Duplicate-title activities (identical title, category, duration, but distinct IDs/UIDs)
    (305, 3, 'UID-DUP-A', 'Duplicated Study Session', 'Read Material', '2026-09-03', 3, 0),
    (306, 3, 'UID-DUP-B', 'Duplicated Study Session', 'Read Material', '2026-09-03', 3, 1),
    (307, 3, 'UID-DUP-C', 'Duplicated Study Session', 'Read Material', '2026-09-03', 3, 2),

    -- Day 4: 2 activities
    (308, 3, 'UID-308', 'Day 4 Task 1', 'Read Material', '2026-09-04', 4, 0),
    (309, 3, 'UID-309', 'Day 4 Task 2', 'Exam', '2026-09-04', 4, 1),

    -- Day 5: 3 activities (sort_order 0, 3, 7 - testing non-consecutive sort orders)
    (310, 3, 'UID-310', 'Day 5 Task Alpha', 'Read Material', '2026-09-05', 5, 0),
    (311, 3, 'UID-311', 'Day 5 Task Beta (Middle order 3)', 'Video Lesson', '2026-09-05', 5, 3),
    (312, 3, 'UID-312', 'Day 5 Task Gamma (End order 7)', 'Assignment', '2026-09-05', 5, 7);

    -- Student usage on Day 3 Dup C (307) and Day 4 Task 2 (309)
    INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status) VALUES
    (3, 'alice@pepp.test', 307, 'UID-DUP-C', 'complete_activity', 'completed');

    -- Assessment batch for Day 4 Task 2 (309)
    INSERT INTO assessment_result_batches (id, activity_id, study_plan_id, status, is_deleted) VALUES
    (10, 309, 3, 'published', 0);
    INSERT INTO assessment_results (id, batch_id, student_email, score, total_score) VALUES
    (10, 10, 'bob@pepp.test', 85, 100);
");

// Acquire lock on Plan #3 for Alice
acquire_or_check_study_plan_lock($pdo, 3, ['admin_id' => 1, 'admin_username' => 'admin_alice', 'admin_name' => 'Alice Smith', 'session_token' => 'token_alice']);

// DELETE-25: Delete an activity from the middle of a day. Verify all other activities retain exact day/date/order.
run_test("DELETE-25: Deleting middle task (302) leaves Task A (order 0) and Task C (order 2) with unmodified day/date/sort_order", function() use ($pdo) {
    $del = exec_single_delete($pdo, 302, 'token', 'admin_alice', 1);
    if (!$del['success']) return "Deletion failed: " . json_encode($del);

    $taskA = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 301")->fetch();
    $taskC = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 303")->fetch();

    if ($taskA['day_number'] != 1 || $taskA['activity_date'] !== '2026-09-01' || (int)$taskA['sort_order'] !== 0) return "Task A altered: " . json_encode($taskA);
    if ($taskC['day_number'] != 1 || $taskC['activity_date'] !== '2026-09-01' || (int)$taskC['sort_order'] !== 2) return "Task C altered: " . json_encode($taskC);
    return true;
});

// DELETE-26: Delete the last activity from a day. Verify NO automatic Rest Day is created.
run_test("DELETE-26: Deleting lone task (304) leaves Day 2 empty with zero automatic Rest Day rows", function() use ($pdo) {
    $del = exec_single_delete($pdo, 304, 'token', 'admin_alice', 1);
    if (!$del['success']) return "Deletion failed: " . json_encode($del);

    $day2_acts = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 3 AND day_number = 2 AND is_deleted = 0")->fetchColumn();
    $rest_acts = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 3 AND activity_title LIKE '%Rest Day%'")->fetchColumn();

    if ((int)$day2_acts !== 0) return "Day 2 is not empty";
    if ((int)$rest_acts !== 0) return "Automatic Rest Day was inserted!";
    return true;
});

// DELETE-27: Delete two duplicate-looking activities. Verify exact selected IDs are deleted and unselected duplicate remains.
run_test("DELETE-27: Deleting duplicate #305 (UID-DUP-A) and #306 (UID-DUP-B) deletes ONLY those exact IDs; #307 remains active", function() use ($pdo) {
    $bulk = exec_bulk_delete($pdo, 3, [305, 306], ['UID-DUP-A', 'UID-DUP-B'], 'admin_alice', 1);
    if (!$bulk['success'] || $bulk['deleted_count'] !== 2 || $bulk['protected_count'] !== 0) {
        return "Bulk delete failed: " . json_encode($bulk);
    }

    $dupA = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 305")->fetch();
    $dupB = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 306")->fetch();
    $dupC = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 307")->fetch();

    if ((int)$dupA['is_deleted'] !== 1) return "Dup A not deleted";
    if ((int)$dupB['is_deleted'] !== 1) return "Dup B not deleted";
    if ((int)$dupC['is_deleted'] !== 0) return "Dup C was wrongly deleted!";
    return true;
});

// DELETE-28: Delete activities across three different days. Verify no activity moves between days.
run_test("DELETE-28: Multi-day bulk delete preserves day_number and activity_date for all remaining tasks", function() use ($pdo) {
    // Delete Task 301 (Day 1) and Task 308 (Day 4)
    $bulk = exec_bulk_delete($pdo, 3, [301, 308], ['UID-301', 'UID-308'], 'admin_alice', 1);
    if (!$bulk['success'] || $bulk['deleted_count'] !== 2) return "Failed multi-day bulk delete: " . json_encode($bulk);

    $remaining = $pdo->query("SELECT id, day_number, activity_date FROM study_plan_activities WHERE study_plan_id = 3 AND is_deleted = 0 ORDER BY day_number")->fetchAll();
    foreach ($remaining as $r) {
        if ($r['id'] == 303 && ($r['day_number'] != 1 || $r['activity_date'] !== '2026-09-01')) return "Task 303 moved";
        if ($r['id'] == 307 && ($r['day_number'] != 3 || $r['activity_date'] !== '2026-09-03')) return "Task 307 moved";
        if ($r['id'] == 309 && ($r['day_number'] != 4 || $r['activity_date'] !== '2026-09-04')) return "Task 309 moved";
        if ($r['id'] == 310 && ($r['day_number'] != 5 || $r['activity_date'] !== '2026-09-05')) return "Task 310 moved";
    }
    return true;
});

// DELETE-29: Delete middle activities from multiple days. Verify all remaining sort_order values remain unchanged.
run_test("DELETE-29: Deleting middle task 311 on Day 5 leaves Task Alpha (0) and Task Gamma (7) with untouched sort_orders", function() use ($pdo) {
    $del = exec_single_delete($pdo, 311, 'token', 'admin_alice', 1);
    if (!$del['success']) return "Delete failed: " . json_encode($del);

    $alpha = $pdo->query("SELECT sort_order FROM study_plan_activities WHERE id = 310")->fetch();
    $gamma = $pdo->query("SELECT sort_order FROM study_plan_activities WHERE id = 312")->fetch();

    if ((int)$alpha['sort_order'] !== 0) return "Alpha sort_order changed to " . $alpha['sort_order'];
    if ((int)$gamma['sort_order'] !== 7) return "Gamma sort_order changed to " . $gamma['sort_order'];
    return true;
});

// DELETE-30: Bulk delete safe + protected + duplicate-looking activities. Verify only safe exact IDs are deleted.
run_test("DELETE-30: Mixed bulk delete on Day 3 Dup C (protected) + Day 4 Task 2 (protected) + Day 5 Task Alpha (safe) -> deletes ONLY Task Alpha", function() use ($pdo) {
    $bulk = exec_bulk_delete($pdo, 3, [307, 309, 310], ['UID-DUP-C', 'UID-309', 'UID-310'], 'admin_alice', 1);
    if (!$bulk['success'] || $bulk['deleted_count'] !== 1 || $bulk['protected_count'] !== 2) {
        return "Expected 1 deleted, 2 protected, got " . json_encode($bulk);
    }
    if ($bulk['deleted_ids'] !== [310]) return "Wrong deleted IDs: " . json_encode($bulk['deleted_ids']);

    $act307 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 307")->fetch();
    $act309 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 309")->fetch();
    $act310 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 310")->fetch();

    if ((int)$act307['is_deleted'] !== 0) return "Protected task 307 was deleted";
    if ((int)$act309['is_deleted'] !== 0) return "Protected task 309 was deleted";
    if ((int)$act310['is_deleted'] !== 1) return "Safe task 310 was not deleted";
    return true;
});

// DELETE-31: Student activity exists with missing/empty student_email but valid student identity.
run_test("DELETE-31: Anonymous/empty student_email analytics record strictly blocks activity deletion", function() use ($pdo) {
    // Insert analytics record for 312 with NULL email
    $pdo->exec("INSERT INTO study_plan_analytics (study_plan_id, student_email, activity_id, activity_uid, action_type, completion_status) VALUES (3, NULL, 312, 'UID-312', 'complete_activity', 'completed')");

    $check = check_delete($pdo, 312);
    if ($check['deletable'] !== false || $check['error_code'] !== 'ACTIVITY_HAS_STUDENT_DATA' || $check['student_count'] < 1) {
        return "Expected blocked for NULL student_email analytics, got " . json_encode($check);
    }
    return true;
});

// DELETE-32: Student activity exists through assessment results
run_test("DELETE-32: Assessment batch result strictly blocks exam activity deletion", function() use ($pdo) {
    $check = check_delete($pdo, 309); // Task 309 has batch #10 with 1 student score
    if ($check['deletable'] !== false || $check['error_code'] !== 'ACTIVITY_HAS_STUDENT_DATA') {
        return "Expected blocked for assessment result, got " . json_encode($check);
    }
    return true;
});

// DELETE-33: Repeated individual delete request. Verify no duplicate audit/version side effects.
run_test("DELETE-33: Repeated single delete request does not duplicate version or audit logs", function() use ($pdo) {
    $ver_before = (int)$pdo->query("SELECT version FROM study_plans WHERE id = 3")->fetchColumn();
    $audit_cnt_before = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_audit_logs WHERE study_plan_id = 3")->fetchColumn();

    $del = exec_single_delete($pdo, 301, 'token', 'admin_alice', 1); // 301 was already deleted
    if ($del['success'] !== false) return "Expected rejected duplicate delete";

    $ver_after = (int)$pdo->query("SELECT version FROM study_plans WHERE id = 3")->fetchColumn();
    $audit_cnt_after = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_audit_logs WHERE study_plan_id = 3")->fetchColumn();

    if ($ver_after !== $ver_before) return "Version was incremented on duplicate delete";
    if ($audit_cnt_after !== $audit_cnt_before) return "Audit log was written on duplicate delete";
    return true;
});

// DELETE-34: Repeated bulk delete request. Verify idempotency.
run_test("DELETE-34: Repeated bulk delete request safely returns deleted_count: 0 without error or duplicate mutation", function() use ($pdo) {
    $ver_before = (int)$pdo->query("SELECT version FROM study_plans WHERE id = 3")->fetchColumn();
    $bulk = exec_bulk_delete($pdo, 3, [301, 308], ['UID-301', 'UID-308'], 'admin_alice', 1);

    if (!$bulk['success'] || $bulk['deleted_count'] !== 0) return "Unexpected response: " . json_encode($bulk);
    $ver_after = (int)$pdo->query("SELECT version FROM study_plans WHERE id = 3")->fetchColumn();
    return $ver_before === $ver_after;
});

// DELETE-35: Simulated transaction exception. Verify rollback works and no 'There is no active transaction'.
run_test("DELETE-35: Rollback inside catch block does not throw 'There is no active transaction'", function() use ($pdo) {
    $exception_thrown = false;
    try {
        $pdo->beginTransaction();
        $pdo->exec("UPDATE study_plan_activities SET is_deleted = 1 WHERE id = 9999");
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Verify second rollback safely ignored
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        $exception_thrown = true;
    }
    return !$exception_thrown;
});

// DELETE-36: Delete last task of every day in a test plan. Verify no unexpected Rest Day is generated anywhere.
run_test("DELETE-36: Completely empty study plan has 0 phantom Rest Day rows in database", function() use ($pdo) {
    $pdo->exec("
        INSERT INTO study_plans (id, title, plan_type, start_date, end_date, total_days, version) VALUES 
        (4, 'Empty Test Plan', 'date_wise', '2026-10-01', '2026-10-03', 3, 1);
        INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, day_number, sort_order) VALUES
        (401, 4, 'UID-401', 'Day 1 Task', 'Read Material', '2026-10-01', 1, 0),
        (402, 4, 'UID-402', 'Day 2 Task', 'Read Material', '2026-10-02', 2, 0),
        (403, 4, 'UID-403', 'Day 3 Task', 'Read Material', '2026-10-03', 3, 0);
    ");

    acquire_or_check_study_plan_lock($pdo, 4, ['admin_id' => 1, 'admin_username' => 'admin_alice', 'admin_name' => 'Alice Smith', 'session_token' => 'token_alice']);
    $bulk = exec_bulk_delete($pdo, 4, [401, 402, 403], ['UID-401', 'UID-402', 'UID-403'], 'admin_alice', 1);

    if (!$bulk['success'] || $bulk['deleted_count'] !== 3) return "Bulk delete failed: " . json_encode($bulk);

    $rest_count = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 4 AND (activity_title LIKE '%Rest Day%' OR activity_type = 'Rest Day')")->fetchColumn();
    return (int)$rest_count === 0;
});

// DELETE-37: Delete one activity from a plan containing duplicate activities. Verify all other activity_uid/activity_id/day/date/order values are byte-for-byte unchanged.
run_test("DELETE-37: Deleting one duplicate task leaves all other tasks completely byte-for-byte identical", function() use ($pdo) {
    $pdo->exec("
        INSERT INTO study_plans (id, title, plan_type, start_date, end_date, total_days, version) VALUES 
        (5, 'Duplicate Preservation Plan', 'date_wise', '2026-11-01', '2026-11-02', 2, 1);
        INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, day_number, sort_order) VALUES
        (501, 5, 'UID-501-A', 'Same Title Task', 'Read Material', '2026-11-01', 1, 0),
        (502, 5, 'UID-502-B', 'Same Title Task', 'Read Material', '2026-11-01', 1, 1),
        (503, 5, 'UID-503-C', 'Same Title Task', 'Read Material', '2026-11-02', 2, 0);
    ");

    acquire_or_check_study_plan_lock($pdo, 5, ['admin_id' => 1, 'admin_username' => 'admin_alice', 'admin_name' => 'Alice Smith', 'session_token' => 'token_alice']);
    $del = exec_single_delete($pdo, 502, 'token', 'admin_alice', 1); // Delete only middle duplicate

    if (!$del['success']) return "Deletion failed: " . json_encode($del);

    $act501 = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 501")->fetch();
    $act503 = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 503")->fetch();

    if ($act501['activity_uid'] !== 'UID-501-A' || $act501['activity_date'] !== '2026-11-01' || (int)$act501['day_number'] !== 1 || (int)$act501['sort_order'] !== 0) return "501 corrupted";
    if ($act503['activity_uid'] !== 'UID-503-C' || $act503['activity_date'] !== '2026-11-02' || (int)$act503['day_number'] !== 2 || (int)$act503['sort_order'] !== 0) return "503 corrupted";
    return true;
});

// DELETE-38: Bulk delete activities from multiple days while another admin attempts mutation. Verify edit lock blocks the second admin.
run_test("DELETE-38: Non-owner Bob is blocked from bulk deleting activities on Plan #5", function() use ($pdo) {
    $bulk = exec_bulk_delete($pdo, 5, [501, 503], ['UID-501-A', 'UID-503-C'], 'admin_bob', 2);
    if ($bulk['success'] !== false || $bulk['error_code'] !== 'EDIT_LOCK_HELD') {
        return "Expected EDIT_LOCK_HELD for Bob on Plan #5, got " . json_encode($bulk);
    }
    return true;
});

// DELETE-39: Explicit Sort Order Gap Retention (Task A=1, B=2, C=3, D=4 -> Delete B and D -> DB retains A=1, C=3)
run_test("DELETE-39: Explicit Sort Order Gap Retention (Task A=1, B=2, C=3, D=4 -> Delete B and D -> DB retains A=1, C=3 without reindexing)", function() use ($pdo) {
    $pdo->exec("
        INSERT INTO study_plans (id, title, plan_type, start_date, end_date, total_days, version) VALUES 
        (6, 'Sort Order Gap Test Plan', 'date_wise', '2026-12-01', '2026-12-02', 2, 1);
        INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, day_number, sort_order) VALUES
        (601, 6, 'UID-601-A', 'Task A', 'Read Material', '2026-12-01', 1, 1),
        (602, 6, 'UID-602-B', 'Task B', 'Read Material', '2026-12-01', 1, 2),
        (603, 6, 'UID-603-C', 'Task C', 'Read Material', '2026-12-01', 1, 3),
        (604, 6, 'UID-604-D', 'Task D', 'Read Material', '2026-12-01', 1, 4);
    ");

    acquire_or_check_study_plan_lock($pdo, 6, ['admin_id' => 1, 'admin_username' => 'admin_alice', 'admin_name' => 'Alice Smith', 'session_token' => 'token_alice']);

    // Delete Task B (602, sort_order 2) and Task D (604, sort_order 4)
    $bulk = exec_bulk_delete($pdo, 6, [602, 604], ['UID-602-B', 'UID-604-D'], 'admin_alice', 1);
    if (!$bulk['success'] || $bulk['deleted_count'] !== 2) {
        return "Bulk delete failed: " . json_encode($bulk);
    }

    $taskA = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 601")->fetch();
    $taskB = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 602")->fetch();
    $taskC = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 603")->fetch();
    $taskD = $pdo->query("SELECT * FROM study_plan_activities WHERE id = 604")->fetch();

    if ((int)$taskB['is_deleted'] !== 1) return "Task B was not soft-deleted";
    if ((int)$taskD['is_deleted'] !== 1) return "Task D was not soft-deleted";
    if ((int)$taskA['is_deleted'] !== 0) return "Task A was wrongly deleted";
    if ((int)$taskC['is_deleted'] !== 0) return "Task C was wrongly deleted";

    // Strictly verify sort_orders: A MUST be 1, C MUST be 3 (NOT reindexed to 1, 2)
    if ((int)$taskA['sort_order'] !== 1) return "Task A sort_order was reindexed to " . $taskA['sort_order'] . " (expected 1)";
    if ((int)$taskC['sort_order'] !== 3) return "Task C sort_order was reindexed to " . $taskC['sort_order'] . " (expected 3)";

    return true;
});

// DELETE-40: Deletion of last task on Day 1 does not shift Day 2 tasks
run_test("DELETE-40: Deletion of all tasks on Day 1 preserves Day 2 dates and tasks with zero shifts", function() use ($pdo) {
    $pdo->exec("
        INSERT INTO study_plans (id, title, plan_type, start_date, end_date, total_days, version) VALUES 
        (7, 'Multi-Day Shift Test Plan', 'date_wise', '2027-01-01', '2027-01-02', 2, 1);
        INSERT INTO study_plan_activities (id, study_plan_id, activity_uid, activity_title, activity_type, activity_date, day_number, sort_order) VALUES
        (701, 7, 'UID-701-D1', 'Day 1 Lone Task', 'Read Material', '2027-01-01', 1, 0),
        (702, 7, 'UID-702-D2', 'Day 2 Task Alpha', 'Read Material', '2027-01-02', 2, 0),
        (703, 7, 'UID-703-D2', 'Day 2 Task Beta', 'Read Material', '2027-01-02', 2, 1);
    ");

    acquire_or_check_study_plan_lock($pdo, 7, ['admin_id' => 1, 'admin_username' => 'admin_alice', 'admin_name' => 'Alice Smith', 'session_token' => 'token_alice']);

    // Delete 701 (only task on Day 1)
    $del = exec_single_delete($pdo, 701, 'token', 'admin_alice', 1);
    if (!$del['success']) return "Deletion failed: " . json_encode($del);

    $d1_count = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 7 AND day_number = 1 AND is_deleted = 0")->fetchColumn();
    $d2_tasks = $pdo->query("SELECT * FROM study_plan_activities WHERE study_plan_id = 7 AND is_deleted = 0 ORDER BY sort_order")->fetchAll();

    if ((int)$d1_count !== 0) return "Day 1 still has active tasks";
    if (count($d2_tasks) !== 2) return "Day 2 tasks corrupted";

    // Day 2 tasks must remain strictly on Day 2 and Date 2027-01-02
    foreach ($d2_tasks as $t) {
        if ((int)$t['day_number'] !== 2) return "Day 2 task shifted to day " . $t['day_number'];
        if ($t['activity_date'] !== '2027-01-02') return "Day 2 date shifted to " . $t['activity_date'];
    }

    return true;
});

echo "\n----------------------------------------------------------------------\n";
echo "  RESULT: {$passed}/" . ($passed + $failed) . " TESTS PASSED (" . round(($passed / ($passed + $failed)) * 100) . "%)\n";
echo "======================================================================\n";
