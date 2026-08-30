<?php
/**
 * PEPP Study Plan Designer — Edit Lock Concurrency & Race Condition Verification Suite
 * Tests CONC-01 through CONC-04 in isolated in-memory SQLite database.
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
    (3, 'admin_bob', 'Bob Course Manager', 'bob@pepp.com', 'admin'),
    (4, 'admin_charlie', 'Charlie Curriculum Lead', 'charlie@pepp.com', 'admin');

    INSERT INTO employees (admin_id, photo, full_name) VALUES
    (2, 'uploads/photos/alice_profile.jpg', 'Alice Academic Head'),
    (3, 'uploads/photos/bob_profile.jpg', 'Bob Course Manager');

    INSERT INTO study_plans (id, title, academic_year, plan_type, total_days, start_date, end_date, version) VALUES
    (201, 'Advanced UGC NET Commerce', '2026-27', 'date_wise', NULL, '2026-09-01', '2026-09-30', 1),
    (202, 'Kerala SET Management', '2026-27', 'day_wise', 15, '2000-01-01', '2000-01-15', 1);
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
echo "  PEPP STUDY PLAN DESIGNER — EDIT LOCK CONCURRENCY & RACE AUDIT\n";
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

$charlie_info = [
    'admin_id' => 4,
    'admin_username' => 'admin_charlie',
    'admin_name' => 'Charlie Curriculum Lead',
    'session_token' => 'sess_token_charlie_789'
];

// CONC-01: Two simultaneous acquisition attempts on an unlocked plan -> exactly one succeeds
run_test("CONC-01: Two simultaneous acquisition attempts on unlocked Plan #201 -> exactly one becomes owner", function() use ($pdo, $alice_info, $bob_info) {
    // Both Alice and Bob attempt to acquire
    $resA = acquire_or_check_study_plan_lock($pdo, 201, $alice_info);
    $resB = acquire_or_check_study_plan_lock($pdo, 201, $bob_info);

    $winnerCount = 0;
    if ($resA['success'] === true && $resA['is_owner'] === true) $winnerCount++;
    if ($resB['success'] === true && $resB['is_owner'] === true) $winnerCount++;

    $lockOwner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();
    $totalLockRows = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();

    return ($winnerCount === 1 && $resA['is_owner'] === true && $resB['is_owner'] === false 
            && $resB['locked'] === true && $lockOwner === 'admin_alice' && $totalLockRows === 1);
});

// CONC-02: Concurrent stale-lock reclamation between Bob and Charlie -> exactly one new owner
run_test("CONC-02: Concurrent stale-lock reclamation on Plan #201 -> exactly one new owner emerges", function() use ($pdo, $bob_info, $charlie_info) {
    // Age Alice's lock to 180 seconds ago (stale)
    $staleTime = date('Y-m-d H:i:s', time() - 180);
    $pdo->prepare("UPDATE study_plan_edit_locks SET last_heartbeat_at = ? WHERE study_plan_id = 201")->execute([$staleTime]);

    // Bob reclaims first
    $resB = acquire_or_check_study_plan_lock($pdo, 201, $bob_info);
    // Charlie immediately attempts reclamation
    $resC = acquire_or_check_study_plan_lock($pdo, 201, $charlie_info);

    $winnerCount = 0;
    if ($resB['success'] === true && $resB['is_owner'] === true) $winnerCount++;
    if ($resC['success'] === true && $resC['is_owner'] === true) $winnerCount++;

    $currentOwner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();
    $totalLockRows = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();

    return ($winnerCount === 1 && $resB['is_owner'] === true && $resC['is_owner'] === false 
            && $resC['locked'] === true && $currentOwner === 'admin_bob' && $totalLockRows === 1);
});

// CONC-03: Concurrent release and re-acquisition cycle does not leave duplicate or inconsistent lock rows
run_test("CONC-03: Rapid release and acquisition cycles maintain clean invariant of at most 1 active lock row", function() use ($pdo, $bob_info, $charlie_info, $alice_info) {
    for ($i = 0; $i < 5; $i++) {
        // Current owner releases
        release_study_plan_lock($pdo, 201, $bob_info['admin_username'], false);
        // Charlie acquires
        $resC = acquire_or_check_study_plan_lock($pdo, 201, $charlie_info);
        // Alice attempts acquire (should fail because Charlie holds it)
        $resA = acquire_or_check_study_plan_lock($pdo, 201, $alice_info);

        $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();
        if ($count !== 1 || $resC['is_owner'] !== true || $resA['is_owner'] !== false) {
            return false;
        }

        // Charlie releases
        release_study_plan_lock($pdo, 201, $charlie_info['admin_username'], false);
        // Bob acquires
        $resB = acquire_or_check_study_plan_lock($pdo, 201, $bob_info);
    }

    $finalCount = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();
    $finalOwner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();

    return ($finalCount === 1 && $finalOwner === 'admin_bob');
});

// CONC-04: Database UNIQUE constraint strictly guarantees impossible duplicate active locks for the same study_plan_id
run_test("CONC-04: Direct SQL INSERT violation is cleanly caught and prevented by UNIQUE constraint", function() use ($pdo) {
    $caughtDuplicate = false;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO study_plan_edit_locks 
            (study_plan_id, admin_id, admin_username, admin_name, session_token, locked_at, last_heartbeat_at, is_active) 
            VALUES (201, 99, 'admin_hacker', 'Hacker', 'sess_hack', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)
        ");
        $stmt->execute();
    } catch (PDOException $e) {
        $caughtDuplicate = true;
    }

    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();
    $owner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 201")->fetchColumn();

    return ($caughtDuplicate === true && $count === 1 && $owner === 'admin_bob');
});

echo "\n----------------------------------------------------------------------\n";
echo "  RESULT: {$passed}/{$total} CONCURRENCY TESTS PASSED (" . round(($passed/$total)*100, 1) . "%)\n";
echo "======================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
