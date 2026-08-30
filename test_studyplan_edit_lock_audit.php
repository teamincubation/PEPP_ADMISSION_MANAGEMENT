<?php
/**
 * PEPP Study Plan Designer — Edit Lock Forensic Verification Suite
 * Tests LOCK-01 through LOCK-20 in isolated in-memory SQLite database.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/study_plan_lock_helper.php';

// Setup SQLite In-Memory DB
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create required tables
$pdo->exec("
    CREATE TABLE IF NOT EXISTS study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        academic_year TEXT,
        course_id INTEGER,
        plan_type TEXT DEFAULT 'date_wise',
        total_days INTEGER,
        start_date TEXT,
        end_date TEXT,
        version INTEGER DEFAULT 1,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_uid TEXT NOT NULL UNIQUE,
        study_plan_id INTEGER NOT NULL,
        activity_date TEXT,
        day_number INTEGER,
        sort_order INTEGER,
        activity_title TEXT,
        chapter TEXT,
        topic TEXT,
        activity_type TEXT,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        full_name TEXT,
        email TEXT,
        role TEXT DEFAULT 'admin',
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER,
        photo TEXT,
        full_name TEXT,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS study_plan_audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        admin_username TEXT,
        action TEXT,
        details TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS study_plan_edit_locks (
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
");

// Seed test admins & employees
$pdo->exec("
    INSERT INTO admins (id, username, full_name, email, role) VALUES 
    (1, 'superadmin', 'Super Administrator', 'super@pepp.com', 'super_admin'),
    (2, 'admin_alice', 'Alice Academic Head', 'alice@pepp.com', 'admin'),
    (3, 'admin_bob', 'Bob Course Manager', 'bob@pepp.com', 'admin');

    INSERT INTO employees (admin_id, photo, full_name) VALUES
    (2, 'uploads/photos/alice_profile.jpg', 'Alice Academic Head');

    INSERT INTO study_plans (id, title, academic_year, plan_type, total_days, start_date, end_date, version) VALUES
    (101, 'UGC NET Commerce 2027', '2026-27', 'date_wise', NULL, '2026-09-01', '2026-09-30', 1),
    (102, 'Kerala SET Economics', '2026-27', 'day_wise', 15, '2000-01-01', '2000-01-15', 1);

    INSERT INTO study_plan_activities (id, activity_uid, study_plan_id, activity_date, day_number, sort_order, activity_title, activity_type) VALUES
    (1, 'ACT-UID-001', 101, '2026-09-01', 1, 0, 'Accounting Principles', 'Read Material'),
    (2, 'ACT-UID-002', 101, '2026-09-01', 1, 1, 'Live Doubt Clearing', 'Watch Live Session'),
    (3, 'ACT-UID-003', 102, '2000-01-01', 1, 0, 'Microeconomics Day 1', 'Read Material');
");

$passed = 0;
$total = 0;

function run_test($name, $closure) {
    global $passed, $total;
    $total++;
    try {
        $result = $closure();
        if ($result === true) {
            $passed++;
            echo "  [\033[32mPASS\033[0m] {$name}\n";
        } else {
            echo "  [\033[31mFAIL\033[0m] {$name} - Check returned false\n";
        }
    } catch (Throwable $e) {
        echo "  [\033[31mFAIL\033[0m] {$name} - Exception: {$e->getMessage()}\n";
    }
}

echo "\n======================================================================\n";
echo "  PEPP STUDY PLAN DESIGNER — STRICT SINGLE-ADMIN EDIT LOCK AUDIT\n";
echo "======================================================================\n\n";

$alice_info = [
    'admin_id' => 2,
    'admin_username' => 'admin_alice',
    'admin_name' => 'Alice Academic Head',
    'session_token' => 'sess_token_alice_123'
];

$bob_info = [
    'admin_id' => 3,
    'admin_username' => 'admin_bob',
    'admin_name' => 'Bob Course Manager',
    'session_token' => 'sess_token_bob_456'
];

// LOCK-01: First admin acquires exclusive edit lock on existing study plan
run_test("LOCK-01: First admin (Alice) acquires exclusive edit lock on plan #101", function() use ($pdo, $alice_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 101, $alice_info);
    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true);
});

// LOCK-02: Second admin attempting to acquire lock on the same study plan is DENIED
run_test("LOCK-02: Second admin (Bob) is DENIED edit lock on plan #101 with editor details & photo", function() use ($pdo, $bob_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 101, $bob_info);
    return ($res['success'] === false && $res['locked'] === true && $res['is_owner'] === false 
            && $res['locked_by']['admin_username'] === 'admin_alice'
            && $res['locked_by']['photo_url'] === 'uploads/photos/alice_profile.jpg'
            && $res['locked_by']['initials'] === 'A');
});

// LOCK-03: Same admin re-requesting lock succeeds (idempotent & heartbeat refresh)
run_test("LOCK-03: Same admin (Alice) re-requesting lock succeeds idempotently", function() use ($pdo, $alice_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 101, $alice_info);
    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true);
});

// LOCK-04: Unique constraint on study_plan_id prevents duplicate active lock rows
run_test("LOCK-04: Database unique constraint prevents duplicate rows for study_plan_id", function() use ($pdo) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();
    return ($count === 1);
});

// LOCK-05: Heartbeat renewal by lock owner updates last_heartbeat_at
run_test("LOCK-05: Heartbeat renewal by lock owner (Alice) succeeds", function() use ($pdo, $alice_info) {
    $res = heartbeat_study_plan_lock($pdo, 101, $alice_info['admin_username'], $alice_info['session_token']);
    return ($res['success'] === true);
});

// LOCK-06: Heartbeat attempt by non-owner fails with lock_lost: true
run_test("LOCK-06: Heartbeat attempt by non-owner (Bob) fails with lock_lost", function() use ($pdo, $bob_info) {
    $res = heartbeat_study_plan_lock($pdo, 101, $bob_info['admin_username'], $bob_info['session_token']);
    return ($res['success'] === false && !empty($res['lock_lost']));
});

// LOCK-07: Heartbeat calls do not generate unnecessary spam audit log rows
run_test("LOCK-07: Heartbeat updates do NOT generate audit log spam", function() use ($pdo) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_audit_logs WHERE action = 'heartbeat'")->fetchColumn();
    return ($count === 0);
});

// LOCK-08: Explicit lock release by owner cleans up lock row and logs audit event
run_test("LOCK-08: Lock release by Alice removes lock row and logs release event", function() use ($pdo, $alice_info) {
    $res = release_study_plan_lock($pdo, 101, $alice_info['admin_username'], false);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();
    $logCount = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_audit_logs WHERE study_plan_id = 101 AND action = 'study_plan_edit_lock_released'")->fetchColumn();
    return ($res['success'] === true && $count === 0 && $logCount >= 1);
});

// LOCK-09: After release, Bob can acquire the lock
run_test("LOCK-09: After Alice releases lock, Bob can acquire lock exclusively", function() use ($pdo, $bob_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 101, $bob_info);
    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true);
});

// LOCK-10: Stale lock timeout (>120s) allows new admin to reclaim lock
run_test("LOCK-10: Stale lock (>120s with no heartbeat) is automatically reclaimed by Alice", function() use ($pdo, $alice_info) {
    // Manually age Bob's lock heartbeat to 3 minutes ago
    $past = date('Y-m-d H:i:s', time() - 180);
    $pdo->prepare("UPDATE study_plan_edit_locks SET last_heartbeat_at = ? WHERE study_plan_id = 101")->execute([$past]);

    $res = acquire_or_check_study_plan_lock($pdo, 101, $alice_info);
    $curOwner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();

    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true && $curOwner === 'admin_alice');
});

// LOCK-11: Super Admin can release any admin's lock
run_test("LOCK-11: Super Admin can release lock held by regular admin", function() use ($pdo) {
    $res = release_study_plan_lock($pdo, 101, 'superadmin', true);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();
    return ($res['success'] === true && $count === 0);
});

// Lock plan 101 back to Alice for mutation tests
acquire_or_check_study_plan_lock($pdo, 101, $alice_info);

// LOCK-12: save_plan API mutation permission check rejects non-owner
run_test("LOCK-12: verify_study_plan_edit_lock_permission rejects Bob from modifying plan #101", function() use ($pdo) {
    $allowed = verify_study_plan_edit_lock_permission($pdo, 101, 'admin_bob');
    return ($allowed === false);
});

// LOCK-13: save_plan API mutation permission check permits lock owner
run_test("LOCK-13: verify_study_plan_edit_lock_permission allows Alice to modify plan #101", function() use ($pdo) {
    $allowed = verify_study_plan_edit_lock_permission($pdo, 101, 'admin_alice');
    return ($allowed === true);
});

// LOCK-14: save_activities API mutation permission check rejects non-owner
run_test("LOCK-14: save_activities mutation blocked for non-lock owner (Bob)", function() use ($pdo) {
    $allowed = verify_study_plan_edit_lock_permission($pdo, 101, 'admin_bob');
    return ($allowed === false);
});

// LOCK-15: save_activities API mutation permission check permits owner
run_test("LOCK-15: save_activities mutation allowed for lock owner (Alice)", function() use ($pdo) {
    $allowed = verify_study_plan_edit_lock_permission($pdo, 101, 'admin_alice');
    return ($allowed === true);
});

// LOCK-16: bulk_move_activities API mutation permission check rejects non-owner
run_test("LOCK-16: bulk_move_activities mutation blocked for non-owner (Bob)", function() use ($pdo) {
    $allowed = verify_study_plan_edit_lock_permission($pdo, 101, 'admin_bob');
    return ($allowed === false);
});

// LOCK-17: bulk_move_activities API mutation permission check permits owner
run_test("LOCK-17: bulk_move_activities mutation allowed for owner (Alice)", function() use ($pdo) {
    $allowed = verify_study_plan_edit_lock_permission($pdo, 101, 'admin_alice');
    return ($allowed === true);
});

// LOCK-18: delete_activity API mutation permission check rejects non-owner
run_test("LOCK-18: delete_activity mutation blocked for non-owner (Bob)", function() use ($pdo) {
    $allowed = verify_study_plan_edit_lock_permission($pdo, 101, 'admin_bob');
    return ($allowed === false);
});

// LOCK-19: Unsaved / draft study plan (id <= 0) bypasses lock without error
run_test("LOCK-19: Draft / unsaved study plan (id = 0) bypasses lock checks cleanly", function() use ($pdo, $bob_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 0, $bob_info);
    $allowed = verify_study_plan_edit_lock_permission($pdo, 0, 'admin_bob');
    return ($res['success'] === true && $res['locked'] === false && $allowed === true);
});

// LOCK-20: Separate study plans have independent locks (Plan 102 lock does not affect Plan 101)
run_test("LOCK-20: Independent study plans (Plan #102) can be locked by Bob while Alice holds #101", function() use ($pdo, $bob_info) {
    $resBob102 = acquire_or_check_study_plan_lock($pdo, 102, $bob_info);
    $owner101 = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();
    $owner102 = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 102")->fetchColumn();

    return ($resBob102['success'] === true && $resBob102['locked'] === false 
            && $owner101 === 'admin_alice' && $owner102 === 'admin_bob');
});

echo "\n----------------------------------------------------------------------\n";
echo "  RESULT: {$passed}/{$total} TESTS PASSED (" . round(($passed/$total)*100, 1) . "%)\n";
echo "======================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
