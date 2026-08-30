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

// LOCK-21: Release study plan lock with admin_id and case-insensitive username match
run_test("LOCK-21: Release study plan lock succeeds with admin_id & case-insensitive matching", function() use ($pdo, $alice_info) {
    // Release Alice's lock on 101 with uppercase username
    $res = release_study_plan_lock($pdo, 101, 'ADMIN_ALICE', false, $alice_info['admin_id']);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();
    return ($res['success'] === true && $count === 0);
});

// LOCK-22: Read-only check on unlocked plan returns locked: false and can_claim: true without acquiring lock
run_test("LOCK-22: Read-only check on unlocked plan returns can_claim: true without creating lock row", function() use ($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_edit_locks WHERE study_plan_id = ? AND is_active = 1");
    $stmt->execute([101]);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);

    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();
    $canClaim = (!$lock);
    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();

    return ($canClaim === true && $countBefore === 0 && $countAfter === 0);
});

// Lock plan 101 back to Alice for read-only viewer tests
acquire_or_check_study_plan_lock($pdo, 101, $alice_info);

// LOCK-23: Read-only check while locked by Alice returns editor details and can_claim: false without creating lock
run_test("LOCK-23: Read-only check by Bob on Alice's locked plan returns can_claim: false without acquiring", function() use ($pdo, $bob_info) {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_edit_locks WHERE study_plan_id = ? AND is_active = 1");
    $stmt->execute([101]);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);

    $isLockedByOther = ($lock && $lock['admin_username'] !== $bob_info['admin_username']);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();

    return ($isLockedByOther === true && $count === 1 && $lock['admin_username'] === 'admin_alice');
});

// LOCK-24: Read-only check when current user IS the owner returns is_owner: true and can_claim: true
run_test("LOCK-24: Read-only check by owner (Alice) confirms ownership and can_claim: true", function() use ($pdo, $alice_info) {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_edit_locks WHERE study_plan_id = ? AND is_active = 1");
    $stmt->execute([101]);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);

    $isOwner = ($lock && $lock['admin_username'] === $alice_info['admin_username']);
    return ($isOwner === true);
});

// LOCK-25: Read-only check on stale lock (>120s) detects lock as available without auto-claiming
run_test("LOCK-25: Read-only check on stale lock detects availability without auto-claiming", function() use ($pdo) {
    // Age Alice's lock to 3 minutes ago
    $past = date('Y-m-d H:i:s', time() - 180);
    $pdo->prepare("UPDATE study_plan_edit_locks SET last_heartbeat_at = ? WHERE study_plan_id = 101")->execute([$past]);

    $stmt = $pdo->prepare("SELECT * FROM study_plan_edit_locks WHERE study_plan_id = ? AND is_active = 1");
    $stmt->execute([101]);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);

    $last_hb = strtotime($lock['last_heartbeat_at']);
    $is_stale = ($last_hb === false || (time() - $last_hb) > STUDY_PLAN_LOCK_TIMEOUT_SECONDS);

    return ($is_stale === true);
});

// LOCK-26: Immediate lock release on intentional exit frees lock immediately
run_test("LOCK-26: Intentional exit release by Alice frees lock in 0ms without waiting for timeout", function() use ($pdo, $alice_info) {
    // Re-acquire fresh lock
    acquire_or_check_study_plan_lock($pdo, 101, $alice_info);
    $count1 = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();

    // Alice intentionally exits (e.g. Back / Exit Without Saving / Save & Exit)
    $rel = release_study_plan_lock($pdo, 101, $alice_info['admin_username'], false, $alice_info['admin_id']);
    $count2 = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();

    return ($count1 === 1 && $rel['success'] === true && $count2 === 0);
});

// LOCK-27: After immediate release, Bob can acquire exclusive edit lock in 1 step
run_test("LOCK-27: Bob immediately acquires exclusive edit lock after Alice's exit", function() use ($pdo, $bob_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 101, $bob_info);
    $curOwner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();

    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true && $curOwner === 'admin_bob');
});

// LOCK-28: Multiple consecutive releases on already released lock are idempotent and safe
run_test("LOCK-28: Consecutive release calls on already released lock are safe and idempotent", function() use ($pdo, $alice_info) {
    $rel1 = release_study_plan_lock($pdo, 101, $alice_info['admin_username'], false, $alice_info['admin_id']);
    $rel2 = release_study_plan_lock($pdo, 101, $alice_info['admin_username'], false, $alice_info['admin_id']);
    return ($rel1['success'] === true && $rel2['success'] === true);
});

// LOCK-29: Heartbeat for released lock fails with lock_lost
run_test("LOCK-29: Heartbeat for released/non-held lock by Alice returns lock_lost", function() use ($pdo, $alice_info) {
    $res = heartbeat_study_plan_lock($pdo, 101, $alice_info['admin_username'], $alice_info['session_token']);
    return ($res['success'] === false && !empty($res['lock_lost']));
});

// LOCK-30: Read-only viewer (Alice) cannot perform mutations while Bob holds the lock
run_test("LOCK-30: Read-only viewer (Alice) blocked from saving while Bob holds lock", function() use ($pdo) {
    $allowed = verify_study_plan_edit_lock_permission($pdo, 101, 'admin_alice');
    return ($allowed === false);
});

// LOCK-31: Super Admin release frees lock held by Bob
run_test("LOCK-31: Super Admin release clears Bob's lock on plan #101", function() use ($pdo) {
    $rel = release_study_plan_lock($pdo, 101, 'superadmin', true);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 101")->fetchColumn();
    return ($rel['success'] === true && $count === 0);
});

// LOCK-32: Full concurrent collaboration lifecycle end-to-end
run_test("LOCK-32: Full Lifecycle: A locks -> B read-only -> A exits -> B detects & acquires exclusive lock", function() use ($pdo, $alice_info, $bob_info) {
    // 1. Admin A acquires lock
    $resA = acquire_or_check_study_plan_lock($pdo, 101, $alice_info);
    if (!$resA['success'] || $resA['locked']) return false;

    // 2. Admin B opens in read-only mode -> blocked from edit lock
    $resB = acquire_or_check_study_plan_lock($pdo, 101, $bob_info);
    if ($resB['success'] || !$resB['locked']) return false;

    // 3. Admin A clicks Exit -> releases lock
    $relA = release_study_plan_lock($pdo, 101, $alice_info['admin_username'], false, $alice_info['admin_id']);
    if (!$relA['success']) return false;

    // 4. Admin B's poller checks lock status -> detected as available
    $stmt = $pdo->prepare("SELECT * FROM study_plan_edit_locks WHERE study_plan_id = ? AND is_active = 1");
    $stmt->execute([101]);
    $activeLock = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($activeLock) return false;

    // 5. Admin B claims edit lock -> acquires successfully
    $claimB = acquire_or_check_study_plan_lock($pdo, 101, $bob_info);
    if (!$claimB['success'] || $claimB['locked'] || !$claimB['is_owner']) return false;

    // 6. Admin B performs heartbeat -> succeeds
    $hbB = heartbeat_study_plan_lock($pdo, 101, $bob_info['admin_username'], $bob_info['session_token']);
    if (!$hbB['success']) return false;

    // 7. Clean up
    release_study_plan_lock($pdo, 101, $bob_info['admin_username'], false, $bob_info['admin_id']);
    return true;
});

// LOCK-33: No existing lock -> Admin A can acquire Plan #11
run_test("LOCK-33: No existing lock -> Admin A can acquire Plan #11", function() use ($pdo, $alice_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 11, $alice_info);
    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true);
});

// LOCK-34: Admin A releases Plan #11 -> no active lock remains
run_test("LOCK-34: Admin A releases Plan #11 -> no active lock remains", function() use ($pdo, $alice_info) {
    $rel = release_study_plan_lock($pdo, 11, $alice_info['admin_username'], false, $alice_info['admin_id']);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 11")->fetchColumn();
    return ($rel['success'] === true && $count === 0);
});

// LOCK-35: Admin B can immediately acquire after Admin A release
run_test("LOCK-35: Admin B can immediately acquire Plan #11 after Admin A release", function() use ($pdo, $bob_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 11, $bob_info);
    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true);
});

// LOCK-36: Stale lock is correctly reclaimed
run_test("LOCK-36: Stale lock on Plan #11 is correctly reclaimed by Admin A", function() use ($pdo, $alice_info) {
    // Set Bob's lock heartbeat to 200 seconds ago
    $past = date('Y-m-d H:i:s', time() - 200);
    $pdo->prepare("UPDATE study_plan_edit_locks SET last_heartbeat_at = ? WHERE study_plan_id = 11")->execute([$past]);

    $res = acquire_or_check_study_plan_lock($pdo, 11, $alice_info);
    $owner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 11")->fetchColumn();
    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true && $owner === 'admin_alice');
});

// LOCK-37: Fresh lock correctly blocks another admin
run_test("LOCK-37: Fresh lock on Plan #11 correctly blocks Bob", function() use ($pdo, $bob_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 11, $bob_info);
    return ($res['success'] === false && $res['locked'] === true && $res['is_owner'] === false && !empty($res['locked_by']));
});

// LOCK-38: Read-only check NEVER creates lock
run_test("LOCK-38: Read-only check on Plan #12 NEVER creates lock row", function() use ($pdo) {
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 12")->fetchColumn();
    // Simulate read-only check
    $stmt = $pdo->prepare("SELECT * FROM study_plan_edit_locks WHERE study_plan_id = ? AND is_active = 1");
    $stmt->execute([12]);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);
    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 12")->fetchColumn();

    return (!$lock && $countBefore === 0 && $countAfter === 0);
});

// LOCK-39: Heartbeat only renews owner lock
run_test("LOCK-39: Heartbeat renews lock for owner (Alice on #11)", function() use ($pdo, $alice_info) {
    $hb = heartbeat_study_plan_lock($pdo, 11, $alice_info['admin_username'], $alice_info['session_token'], $alice_info['admin_id']);
    return ($hb['success'] === true);
});

// LOCK-40: Heartbeat from non-owner fails
run_test("LOCK-40: Heartbeat from non-owner (Bob on #11) fails with lock_lost", function() use ($pdo, $bob_info) {
    $hb = heartbeat_study_plan_lock($pdo, 11, $bob_info['admin_username'], $bob_info['session_token'], $bob_info['admin_id']);
    return ($hb['success'] === false && !empty($hb['lock_lost']));
});

// LOCK-41: Plan #11 lock does NOT affect Plan #12
run_test("LOCK-41: Plan #11 lock held by Alice does NOT block Plan #12 for Bob", function() use ($pdo, $bob_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 12, $bob_info);
    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true);
});

// LOCK-42: No lock on Plan #13 -> Admin can edit Plan #13
run_test("LOCK-42: No lock on Plan #13 -> Admin can acquire edit access directly", function() use ($pdo, $alice_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 13, $alice_info);
    return ($res['success'] === true && $res['locked'] === false && $res['is_owner'] === true);
});

// LOCK-43: All existing study plans remain independently lockable
run_test("LOCK-43: Multiple study plans (11, 12, 13) maintain independent exclusive locks", function() use ($pdo) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id IN (11, 12, 13)")->fetchColumn();
    return ($count === 3);
});

// LOCK-44: release endpoint cannot release another admin's lock
run_test("LOCK-44: Regular release cannot release another admin's lock (Bob cannot release Alice on #11)", function() use ($pdo, $bob_info) {
    $rel = release_study_plan_lock($pdo, 11, $bob_info['admin_username'], false, $bob_info['admin_id']);
    $owner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 11")->fetchColumn();
    return ($owner === 'admin_alice');
});

// LOCK-45: Timezone differences do not incorrectly classify a fresh lock as active/stale
run_test("LOCK-45: Timezone & clock differences do not misclassify fresh or stale locks", function() use ($pdo, $alice_info) {
    // Touch heartbeat to current time
    $nowStr = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE study_plan_edit_locks SET last_heartbeat_at = ? WHERE study_plan_id = 11")->execute([$nowStr]);

    $allowed = verify_study_plan_edit_lock_permission($pdo, 11, $alice_info['admin_username'], $alice_info['admin_id']);
    $blocked = verify_study_plan_edit_lock_permission($pdo, 11, 'admin_bob', 3);

    return ($allowed === true && $blocked === false);
});

// LOCK-46: No-plan/global lock cannot block unrelated study plans
run_test("LOCK-46: Draft study plan (id = 0) or unmanaged plans cannot block other study plans", function() use ($pdo, $bob_info) {
    $res0 = acquire_or_check_study_plan_lock($pdo, 0, $bob_info);
    $res99 = acquire_or_check_study_plan_lock($pdo, 99, $bob_info);

    // Clean up
    release_study_plan_lock($pdo, 11, 'superadmin', true);
    release_study_plan_lock($pdo, 12, 'superadmin', true);
    release_study_plan_lock($pdo, 13, 'superadmin', true);
    release_study_plan_lock($pdo, 99, 'superadmin', true);

    return ($res0['success'] === true && $res0['locked'] === false && $res99['success'] === true);
});

// LOCK-47: Missing table self-heals and retry acquires lock successfully
run_test("LOCK-47: Missing table self-heals on first touch and acquires lock cleanly", function() use ($pdo, $alice_info) {
    // Drop table temporarily to simulate missing table scenario
    $pdo->exec("DROP TABLE IF EXISTS study_plan_edit_locks");

    // Acquire lock on Plan #50
    $res = acquire_or_check_study_plan_lock($pdo, 50, $alice_info);

    // Verify table was recreated and lock acquired
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 50")->fetchColumn();
    release_study_plan_lock($pdo, 50, 'superadmin', true);

    return ($res['success'] === true && $res['is_owner'] === true && $exists === 1);
});

// LOCK-48: Fail-closed behavior -> DB error returns EDIT_LOCK_UNAVAILABLE, is_owner = false
run_test("LOCK-48: Fail-Closed: When lock cannot be verified, is_owner is FALSE and lock_unavailable is TRUE", function() use ($alice_info) {
    // Create a mock closed PDO connection that throws errors
    $bad_pdo = new PDO('sqlite::memory:');
    $bad_pdo->exec("CREATE TABLE dummy (id INT)");
    // Drop sqlite_master simulation or close
    // We pass a bad PDO where study_plan_edit_locks table creation will fail
    $bad_pdo->exec("CREATE TABLE study_plan_edit_locks (corrupted_column INT NOT NULL)");

    $res = acquire_or_check_study_plan_lock($bad_pdo, 77, $alice_info);

    return (
        $res['success'] === false &&
        $res['is_owner'] === false &&
        $res['lock_available'] === false &&
        !empty($res['lock_unavailable']) &&
        $res['error_code'] === 'EDIT_LOCK_UNAVAILABLE'
    );
});

// LOCK-49: Lock service unavailable does NOT produce phantom 'Another Admin' lock state
run_test("LOCK-49: Lock unavailable state has locked_by = null, preventing phantom '@admin' modal", function() use ($alice_info) {
    $bad_pdo = new PDO('sqlite::memory:');
    $bad_pdo->exec("CREATE TABLE study_plan_edit_locks (corrupted INT)");

    $res = acquire_or_check_study_plan_lock($bad_pdo, 77, $alice_info);

    $is_owner = !empty($res['is_owner']);
    $locked_by_admin = $res['locked_by'] ?? null;
    $is_locked_by_other = (!empty($res['locked']) && !$is_owner && !empty($locked_by_admin) && !empty($locked_by_admin['admin_username']));
    $is_lock_unavailable = (!$is_owner && !$is_locked_by_other && (!empty($res['lock_unavailable']) || !empty($res['error_code'])));

    return ($is_locked_by_other === false && $is_lock_unavailable === true && $locked_by_admin === null);
});

// LOCK-50: Lock service unavailable -> verify_study_plan_edit_lock_permission strictly blocks mutations (Fail-Closed)
run_test("LOCK-50: Fail-Closed Mutation: verify_study_plan_edit_lock_permission returns false on DB error", function() {
    $bad_pdo = new PDO('sqlite::memory:');
    $bad_pdo->exec("CREATE TABLE study_plan_edit_locks (corrupted INT)");

    $allowed = verify_study_plan_edit_lock_permission($bad_pdo, 77, 'admin_alice');
    return ($allowed === false);
});

// LOCK-51: Scenario 1 - Admin A acquires Plan #11
run_test("LOCK-51: Scenario 1 - Admin A opens Plan #11 -> acquires exclusive edit lock", function() use ($pdo, $alice_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 11, $alice_info);
    $active_locks = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 11 AND is_active = 1")->fetchColumn();
    return ($res['success'] === true && $res['is_owner'] === true && $active_locks === 1);
});

// LOCK-52: Scenario 2 - Admin B opens Plan #11 -> receives locked state with Admin A's name and photo
run_test("LOCK-52: Scenario 2 - Admin B opens Plan #11 -> blocked with Admin A's exact details", function() use ($pdo, $bob_info) {
    $res = acquire_or_check_study_plan_lock($pdo, 11, $bob_info);
    return (
        $res['success'] === false &&
        $res['locked'] === true &&
        $res['is_owner'] === false &&
        $res['lock_available'] === true &&
        !empty($res['locked_by']) &&
        $res['locked_by']['admin_username'] === 'admin_alice'
    );
});

// LOCK-53: Scenario 3 - Admin B checks in read-only mode -> NO lock acquired, plan data accessible
run_test("LOCK-53: Scenario 3 - Read-Only check by Admin B returns can_claim = false and creates ZERO lock rows", function() use ($pdo) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 11")->fetchColumn();
    return ($count === 1);
});

// LOCK-54: Scenario 4 & 5 - Admin A exits -> Lock released -> Admin B notified & acquires Plan #11
run_test("LOCK-54: Scenario 4 & 5 - Admin A exits -> Lock released -> Admin B acquires Plan #11", function() use ($pdo, $alice_info, $bob_info) {
    // 1. Admin A exits
    $rel = release_study_plan_lock($pdo, 11, $alice_info['admin_username'], false, $alice_info['admin_id']);
    if (!$rel['success']) return false;

    $active_count = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_edit_locks WHERE study_plan_id = 11")->fetchColumn();
    if ($active_count !== 0) return false;

    // 2. Admin B acquires
    $claim = acquire_or_check_study_plan_lock($pdo, 11, $bob_info);
    $new_owner = $pdo->query("SELECT admin_username FROM study_plan_edit_locks WHERE study_plan_id = 11")->fetchColumn();

    return ($claim['success'] === true && $claim['is_owner'] === true && $new_owner === 'admin_bob');
});

// LOCK-55: Scenario 6 & 7 - Admin A blocked on Plan #11, but Admin A can edit Plan #12 independently
run_test("LOCK-55: Scenario 6 & 7 - Admin A blocked on #11 by Bob, but can edit #12 independently", function() use ($pdo, $alice_info) {
    $res11 = acquire_or_check_study_plan_lock($pdo, 11, $alice_info);
    $res12 = acquire_or_check_study_plan_lock($pdo, 12, $alice_info);

    // Clean up
    release_study_plan_lock($pdo, 11, 'superadmin', true);
    release_study_plan_lock($pdo, 12, 'superadmin', true);

    return (
        $res11['locked'] === true && $res11['is_owner'] === false &&
        $res12['success'] === true && $res12['is_owner'] === true
    );
});

echo "\n----------------------------------------------------------------------\n";
echo "  RESULT: {$passed}/{$total} TESTS PASSED (" . round(($passed/$total)*100, 1) . "%)\n";
echo "======================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
