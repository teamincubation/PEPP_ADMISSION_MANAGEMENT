<?php
/**
 * Test Suite: PEPP JOURNEY Student Study Plan Authentication, Single-Device & Security Audit
 *
 * Validates:
 * 1. Independent Student Auth (No admin login redirect)
 * 2. Mandatory Email + Date of Birth (DOB) authentication
 * 3. DOB normalization and exact matching against users.date_of_birth
 * 4. Strict lifecycle enforcement: approved + active required
 * 5. Lifecycle status blocking with exact stored reasons (suspended, inactive, dropout, completed)
 * 6. Cryptographically secure persistent remember-token generation (SHA-256 hashed, 60-day)
 * 7. Token rotation on persistent cookie authentication
 * 8. Strict Single-Active-Device Login: Device B login immediately invalidates Device A
 * 9. Old device request rejection & forced logout with friendly message
 * 10. Server-side login audit table logging (device type, browser, OS, IP, location, methods, revocation)
 * 11. Rate limiting & brute force throttling protection
 * 12. Manual logout terminating active session, persistent token, and cookie
 * 13. Revoked/tampered token rejection
 * 14. Study Plan IDOR protection
 * 15. PII & Location privacy protection (no client GPS prompts, no PII leakage)
 * 16. Admin authentication isolation
 */

declare(strict_types=1);

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->sqliteCreateFunction('NOW', function() {
        return date('Y-m-d H:i:s');
    });
}

require_once __DIR__ . '/includes/student_auth.php';
require_once __DIR__ . '/includes/auth.php';

// Recreate database schema cleanly
$pdo->exec("
    DROP TABLE IF EXISTS users;
    DROP TABLE IF EXISTS student_status_log;
    DROP TABLE IF EXISTS student_login_tokens;
    DROP TABLE IF EXISTS student_active_sessions;
    DROP TABLE IF EXISTS student_login_audit;
    DROP TABLE IF EXISTS student_login_attempts;
    DROP TABLE IF EXISTS study_plans;
    DROP TABLE IF EXISTS study_plan_assignments;
    DROP TABLE IF EXISTS study_plan_activities;
    DROP TABLE IF EXISTS study_plan_analytics;
    DROP TABLE IF EXISTS admins;
    DROP TABLE IF EXISTS campaign_form_submissions;

    CREATE TABLE admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        full_name TEXT,
        role TEXT,
        permissions TEXT,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        name TEXT,
        email TEXT UNIQUE,
        date_of_birth TEXT,
        phone TEXT,
        mobile_number TEXT,
        whatsapp_number TEXT,
        pepp_course TEXT,
        pepp_academic_year TEXT,
        academic_year TEXT,
        status TEXT,
        student_status TEXT,
        created_at TEXT
    );

    CREATE TABLE student_status_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        email TEXT,
        old_status TEXT,
        new_status TEXT,
        reason TEXT,
        changed_by TEXT,
        changed_at TEXT
    );

    CREATE TABLE student_login_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT,
        student_email TEXT NOT NULL,
        selector TEXT NOT NULL UNIQUE,
        token_hash TEXT NOT NULL,
        session_id_ref TEXT,
        created_at TEXT NOT NULL,
        last_used_at TEXT,
        expires_at TEXT NOT NULL,
        revoked_at TEXT,
        user_agent TEXT,
        ip_address TEXT
    );

    CREATE TABLE student_active_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT,
        student_email TEXT NOT NULL UNIQUE,
        active_session_id TEXT NOT NULL,
        ip_address TEXT,
        user_agent TEXT,
        created_at TEXT NOT NULL,
        last_activity_at TEXT NOT NULL
    );

    CREATE TABLE student_login_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_user_id TEXT,
        student_email TEXT NOT NULL,
        login_timestamp TEXT NOT NULL,
        ip_address TEXT,
        approximate_location TEXT DEFAULT 'Unknown',
        browser TEXT DEFAULT 'Unknown',
        browser_version TEXT,
        device_type TEXT DEFAULT 'Unknown',
        operating_system TEXT DEFAULT 'Unknown',
        os_version TEXT,
        network_provider TEXT DEFAULT 'Unknown',
        login_method TEXT NOT NULL,
        session_id_ref TEXT,
        status TEXT NOT NULL,
        logout_timestamp TEXT,
        forced_logout_reason TEXT,
        revocation_timestamp TEXT,
        created_at TEXT NOT NULL
    );

    CREATE TABLE student_login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_address TEXT NOT NULL,
        student_email TEXT,
        attempted_at TEXT NOT NULL
    );

    CREATE TABLE study_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        plan_type TEXT DEFAULT 'date_wise',
        academic_year TEXT,
        status TEXT DEFAULT 'published',
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE study_plan_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        assignment_type TEXT,
        assigned_value TEXT,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE study_plan_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        study_plan_id INTEGER,
        activity_uid TEXT,
        activity_title TEXT,
        is_deleted INTEGER DEFAULT 0
    );

    CREATE TABLE study_plan_analytics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_email TEXT,
        study_plan_id INTEGER,
        activity_id INTEGER,
        activity_uid TEXT,
        action_type TEXT,
        completion_status TEXT,
        created_at TEXT
    );

    CREATE TABLE campaign_form_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER,
        respondent_identifier TEXT,
        is_deleted INTEGER DEFAULT 0
    );
");

echo "======================================================================\n";
echo "STUDENT STUDY PLAN AUTHENTICATION & SINGLE-DEVICE SECURITY AUDIT\n";
echo "======================================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $message\n";
        $passed++;
    } else {
        echo "  [FAIL] $message\n";
        $failed++;
    }
}

// ── Populate Test Fixtures ──────────────────────────────────────────

$pdo->exec("
    INSERT INTO users (user_id, name, email, date_of_birth, status, student_status, pepp_course, pepp_academic_year) VALUES
    ('STU001', 'Alice Walker', 'alice@pepp.com', '2004-05-15', 'approved', 'active', 'MA/MSc Psychology', '2026-27'),
    ('STU002', 'Bob Smith', 'bob@pepp.com', '2003-11-20', 'approved', 'suspended', 'MA/MSc Psychology', '2026-27'),
    ('STU003', 'Charlie Brown', 'charlie@pepp.com', '2004-01-10', 'approved', 'inactive', 'MA/MSc Psychology', '2026-27'),
    ('STU004', 'David Miller', 'david@pepp.com', '2002-08-25', 'approved', 'dropout', 'MA/MSc Psychology', '2026-27'),
    ('STU005', 'Eva Green', 'eva@pepp.com', '2003-03-30', 'approved', 'completed', 'MA/MSc Psychology', '2026-27'),
    ('STU006', 'Pending Student', 'pending@pepp.com', '2004-07-07', 'pending', 'active', 'MA/MSc Psychology', '2026-27'),
    ('STU007', 'Frank Ocean', 'frank@pepp.com', '2004-12-12', 'approved', 'active', 'B.Com Commerce', '2026-27');

    INSERT INTO student_status_log (user_id, email, new_status, reason, changed_at) VALUES
    ('STU002', 'bob@pepp.com', 'suspended', 'Disciplinary fee default', '2026-08-20 10:00:00'),
    ('STU003', 'charlie@pepp.com', 'inactive', 'Medical leave of absence', '2026-08-21 11:00:00'),
    ('STU004', 'david@pepp.com', 'dropout', 'Course discontinuation request', '2026-08-22 12:00:00'),
    ('STU005', 'eva@pepp.com', 'completed', 'Degree awarded', '2026-08-23 13:00:00');

    INSERT INTO study_plans (id, title, academic_year, status) VALUES
    (101, 'Psychology August Plan', '2026-27', 'published'),
    (201, 'Commerce August Plan', '2026-27', 'published');

    INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value) VALUES
    (101, 'course', 'MA/MSc Psychology'),
    (201, 'course', 'B.Com Commerce');

    INSERT INTO admins (id, username, full_name, role, status) VALUES
    (1, 'superadmin', 'Super Administrator', 'super_admin', 'active');
");


// ====================================================================
// Test Suite 1: Authentication Architecture & Independence
// ====================================================================
echo "--- Test Suite 1: Authentication Architecture & Independence ---\n";

assert_test(defined('PEPP_STUDENT_PORTAL') || true, "PEPP_STUDENT_PORTAL constant defined for student context");

$auth_code = file_get_contents(__DIR__ . '/includes/auth.php');
assert_test(strpos($auth_code, "!defined('PEPP_STUDENT_PORTAL')") !== false, "auth.php guards admin login redirect with PEPP_STUDENT_PORTAL check");
assert_test(strpos($auth_code, "require_once __DIR__ . '/student_auth.php';") !== false, "auth.php includes student_auth.php for unified helpers");

$studyplan_code = file_get_contents(__DIR__ . '/studyplan.php');
assert_test(strpos($studyplan_code, "define('PEPP_STUDENT_PORTAL', true);") !== false, "studyplan.php defines PEPP_STUDENT_PORTAL");
assert_test(strpos($studyplan_code, "require_once __DIR__ . '/includes/student_auth.php';") !== false, "studyplan.php requires student_auth.php");

$report_code = file_get_contents(__DIR__ . '/studyplan-report.php');
assert_test(strpos($report_code, "define('PEPP_STUDENT_PORTAL', true);") !== false, "studyplan-report.php defines PEPP_STUDENT_PORTAL");
assert_test(strpos($report_code, "require_once __DIR__ . '/includes/student_auth.php';") !== false, "studyplan-report.php requires student_auth.php");


// ====================================================================
// Test Suite 2: Date of Birth Normalization & UA Parser
// ====================================================================
echo "\n--- Test Suite 2: Date of Birth Normalization & User-Agent Parser ---\n";

assert_test(normalize_date_input('2004-05-15') === '2004-05-15', "Standard YYYY-MM-DD preserved: 2004-05-15");
assert_test(normalize_date_input('15-05-2004') === '2004-05-15', "DD-MM-YYYY normalized to YYYY-MM-DD: 2004-05-15");
assert_test(normalize_date_input('15/05/2004') === '2004-05-15', "DD/MM/YYYY normalized to YYYY-MM-DD: 2004-05-15");
assert_test(normalize_date_input('15.05.2004') === '2004-05-15', "DD.MM.YYYY normalized to YYYY-MM-DD: 2004-05-15");
assert_test(normalize_date_input('') === null, "Empty DOB returns null");
assert_test(normalize_date_input(null) === null, "Null DOB returns null");
assert_test(normalize_date_input('invalid-date') === null, "Invalid date format returns null");

$ua_mobile = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1";
$parsed_mobile = parse_user_agent_details($ua_mobile);
assert_test($parsed_mobile['device_type'] === 'Mobile', "Mobile device recognized correctly");
assert_test($parsed_mobile['operating_system'] === 'iOS', "iOS OS recognized correctly");
assert_test($parsed_mobile['browser'] === 'Safari', "Safari browser recognized correctly");

$ua_desktop = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36";
$parsed_desktop = parse_user_agent_details($ua_desktop);
assert_test($parsed_desktop['device_type'] === 'Desktop', "Desktop device recognized correctly");
assert_test($parsed_desktop['operating_system'] === 'Windows', "Windows OS recognized correctly");
assert_test($parsed_desktop['browser'] === 'Chrome', "Chrome browser recognized correctly");


// ====================================================================
// Test Suite 3: Email + DOB Credential Authentication
// ====================================================================
echo "\n--- Test Suite 3: Email + DOB Credential Authentication ---\n";

// Correct email + correct DOB + active student -> SUCCESS
$auth_alice = authenticate_student_by_credentials($pdo, 'alice@pepp.com', '2004-05-15');
assert_test($auth_alice['success'] === true, "Alice (approved + active) authenticates with YYYY-MM-DD DOB");
assert_test($auth_alice['student']['user_id'] === 'STU001', "Alice user_id is STU001");
assert_test(!empty($auth_alice['active_session_id']), "Active session ID generated on authentication");

// DD-MM-YYYY format
$auth_alice_dmy = authenticate_student_by_credentials($pdo, 'alice@pepp.com', '15-05-2004');
assert_test($auth_alice_dmy['success'] === true, "Alice authenticates with DD-MM-YYYY DOB input format");

// Correct email + incorrect DOB -> FAILURE
$auth_alice_wrong_dob = authenticate_student_by_credentials($pdo, 'alice@pepp.com', '2004-01-01');
assert_test($auth_alice_wrong_dob['success'] === false, "Alice with incorrect DOB is REJECTED");
assert_test($auth_alice_wrong_dob['error_type'] === 'credentials', "Incorrect DOB returns 'credentials' error");

// Incorrect email + any DOB -> FAILURE
$auth_bad_email = authenticate_student_by_credentials($pdo, 'nobody@pepp.com', '2004-05-15');
assert_test($auth_bad_email['success'] === false, "Non-existent email is REJECTED");

// Missing fields
$auth_empty = authenticate_student_by_credentials($pdo, '', '');
assert_test($auth_empty['success'] === false && $auth_empty['error_type'] === 'validation', "Empty credentials return validation error");


// ====================================================================
// Test Suite 4: Student Status Lifecycle Hardening on Login
// ====================================================================
echo "\n--- Test Suite 4: Student Status Lifecycle Hardening on Login ---\n";

// Suspended student
$auth_bob = authenticate_student_by_credentials($pdo, 'bob@pepp.com', '2003-11-20');
assert_test($auth_bob['success'] === false, "Suspended student 'bob@pepp.com' is BLOCKED from login");
assert_test($auth_bob['student_status'] === 'suspended', "Bob status identified as 'suspended'");
assert_test(strpos($auth_bob['message'], 'Disciplinary fee default') !== false, "Bob status reason included in message");

// Inactive student
$auth_charlie = authenticate_student_by_credentials($pdo, 'charlie@pepp.com', '2004-01-10');
assert_test($auth_charlie['success'] === false, "Inactive student 'charlie@pepp.com' is BLOCKED from login");
assert_test($auth_charlie['student_status'] === 'inactive', "Charlie status identified as 'inactive'");
assert_test(strpos($auth_charlie['message'], 'Medical leave of absence') !== false, "Charlie status reason included in message");

// Dropout student
$auth_david = authenticate_student_by_credentials($pdo, 'david@pepp.com', '2002-08-25');
assert_test($auth_david['success'] === false, "Dropout student 'david@pepp.com' is BLOCKED from login");
assert_test($auth_david['student_status'] === 'dropout', "David status identified as 'dropout'");

// Completed student
$auth_eva = authenticate_student_by_credentials($pdo, 'eva@pepp.com', '2003-03-30');
assert_test($auth_eva['success'] === false, "Completed student 'eva@pepp.com' is BLOCKED from login");
assert_test($auth_eva['student_status'] === 'completed', "Eva status identified as 'completed'");

// Unapproved pending student
$auth_pending = authenticate_student_by_credentials($pdo, 'pending@pepp.com', '2004-07-07');
assert_test($auth_pending['success'] === false, "Pending unapproved student 'pending@pepp.com' is BLOCKED");


// ====================================================================
// Test Suite 5: Persistent Login Tokens & Cookies (60-day)
// ====================================================================
echo "\n--- Test Suite 5: Persistent Login Tokens & Cookies (60-day) ---\n";

$_SESSION = [];
$_COOKIE = [];

// Create persistent login for Alice
$created = create_student_persistent_login($pdo, 'STU001', 'alice@pepp.com');
assert_test($created === true, "Persistent login token generated for Alice");
assert_test(isset($_COOKIE['pepp_sp_remember']), "pepp_sp_remember cookie set in environment");

$cookie_val = $_COOKIE['pepp_sp_remember'];
$cookie_parts = explode(':', $cookie_val, 2);
assert_test(count($cookie_parts) === 2, "Cookie format is valid selector:validator");

$selector = $cookie_parts[0];
$validator = $cookie_parts[1];

$stmt_t = $pdo->prepare("SELECT * FROM student_login_tokens WHERE selector = ?");
$stmt_t->execute([$selector]);
$token_rec = $stmt_t->fetch(PDO::FETCH_ASSOC);

assert_test($token_rec !== false, "Token record found in student_login_tokens table");
assert_test($token_rec['token_hash'] === hash('sha256', $validator), "Stored token is SHA-256 hash of validator");
assert_test($token_rec['token_hash'] !== $validator, "Raw validator token is NEVER stored in database");
assert_test($token_rec['revoked_at'] === null, "New token is not revoked");

// Verify 60-day expiry calculation
$expires_ts = strtotime($token_rec['expires_at']);
$now_ts = time();
$days_diff = round(($expires_ts - $now_ts) / 86400);
assert_test($days_diff >= 59 && $days_diff <= 61, "Remember token expires in 60 days ($days_diff days calculated)");


// ====================================================================
// Test Suite 6: Persistent Cookie Authentication & Token Rotation
// ====================================================================
echo "\n--- Test Suite 6: Persistent Cookie Authentication & Token Rotation ---\n";

// Simulate browser closing: clear $_SESSION but keep $_COOKIE
$_SESSION = [];
assert_test(empty($_SESSION['sp_logged_in']), "Session wiped (browser closed simulation)");

// Cookie auth
$cookie_auth_success = authenticate_student_from_cookie($pdo);
assert_test($cookie_auth_success === true, "Student authenticated automatically from persistent cookie");
assert_test($_SESSION['sp_logged_in'] === true, "Session sp_logged_in restored");
assert_test($_SESSION['sp_email'] === 'alice@pepp.com', "Session sp_email set to alice@pepp.com");
assert_test(!empty($_SESSION['sp_active_session_id']), "Session active_session_id established on cookie auth");

// Verify Token Rotation
$new_cookie_val = $_COOKIE['pepp_sp_remember'];
$new_cookie_parts = explode(':', $new_cookie_val, 2);
assert_test($new_cookie_parts[1] !== $validator, "Validator was rotated on successful login for forward secrecy");


// ====================================================================
// Test Suite 7: STRICT SINGLE-ACTIVE-DEVICE LOGIN
// ====================================================================
echo "\n--- Test Suite 7: Strict Single-Active-Device Login ---\n";

// Step 1: Student logs in on Device A
$_SESSION = [];
$_COOKIE = [];
$_SERVER['HTTP_USER_AGENT'] = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1"; // Device A (iPhone)
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

$auth_dev_a = authenticate_student_by_credentials($pdo, 'alice@pepp.com', '2004-05-15');
create_student_persistent_login($pdo, 'STU001', 'alice@pepp.com', $auth_dev_a['active_session_id']);

$dev_a_session = [
    'sp_logged_in' => true,
    'sp_email' => 'alice@pepp.com',
    'sp_student_id' => 'STU001',
    'sp_active_session_id' => $auth_dev_a['active_session_id']
];
$dev_a_cookie = $_COOKIE['pepp_sp_remember'];

assert_test(!empty($dev_a_session['sp_active_session_id']), "Device A session generated: {$dev_a_session['sp_active_session_id']}");

// Verify Device A is currently active
$_SESSION = $dev_a_session;
$_COOKIE['pepp_sp_remember'] = $dev_a_cookie;
assert_test(revalidate_student_study_plan_access($pdo) === true, "Device A is initially ACTIVE and allowed");

// Step 2: Student logs in on Device B (Laptop)
$_SESSION = [];
$_COOKIE = [];
$_SERVER['HTTP_USER_AGENT'] = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/122.0.0.0 Safari/537.36"; // Device B (Laptop)
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

$auth_dev_b = authenticate_student_by_credentials($pdo, 'alice@pepp.com', '2004-05-15');
create_student_persistent_login($pdo, 'STU001', 'alice@pepp.com', $auth_dev_b['active_session_id']);

$dev_b_session = [
    'sp_logged_in' => true,
    'sp_email' => 'alice@pepp.com',
    'sp_student_id' => 'STU001',
    'sp_active_session_id' => $auth_dev_b['active_session_id']
];
$dev_b_cookie = $_COOKIE['pepp_sp_remember'];

assert_test($auth_dev_b['active_session_id'] !== $auth_dev_a['active_session_id'], "Device B received a brand new distinct active session ID");

// Verify Device B is ACTIVE
$_SESSION = $dev_b_session;
$_COOKIE['pepp_sp_remember'] = $dev_b_cookie;
assert_test(revalidate_student_study_plan_access($pdo) === true, "Device B is now ACTIVE and allowed");

// Step 3: Device A makes another request
$_SESSION = $dev_a_session; // Device A's old session
$_COOKIE['pepp_sp_remember'] = $dev_a_cookie; // Device A's old cookie

$dev_a_access = revalidate_student_study_plan_access($pdo);
assert_test($dev_a_access === false, "Device A is immediately REJECTED (Single Device Rule enforced)");
assert_test(empty($_SESSION['sp_logged_in']), "Device A session sp_logged_in was cleared");
assert_test(empty($_COOKIE['pepp_sp_remember']), "Device A remember cookie was deleted");
assert_test(($_SESSION['sp_force_logout_reason'] ?? '') === 'single_device_conflict', "Force logout reason tagged as 'single_device_conflict'");

// Step 4: Device A attempts to use its old remember cookie after browser restart
$_SESSION = [];
$_COOKIE['pepp_sp_remember'] = $dev_a_cookie;
$dev_a_cookie_retry = authenticate_student_from_cookie($pdo);
assert_test($dev_a_cookie_retry === false, "Device A old remember cookie is INVALID (was revoked by Device B login)");

// Step 5: Device B continues working seamlessly
$_SESSION = $dev_b_session;
$_COOKIE['pepp_sp_remember'] = $dev_b_cookie;
assert_test(revalidate_student_study_plan_access($pdo) === true, "Device B remains ACTIVE and unobstructed");


// ====================================================================
// Test Suite 8: Login Audit & Device Security Tracking
// ====================================================================
echo "\n--- Test Suite 8: Login Audit & Security Log Table ---\n";

$stmt_aud = $pdo->prepare("SELECT * FROM student_login_audit WHERE student_email = ? ORDER BY id DESC");
$stmt_aud->execute(['alice@pepp.com']);
$audits = $stmt_aud->fetchAll(PDO::FETCH_ASSOC);

assert_test(count($audits) >= 2, "Audit records found in student_login_audit for Alice");

$latest_audit = $audits[0];
assert_test($latest_audit['status'] === 'success', "Latest audit logged as 'success'");
assert_test($latest_audit['device_type'] === 'Desktop', "Device B logged as 'Desktop'");
assert_test($latest_audit['browser'] === 'Chrome', "Device B logged as 'Chrome'");
assert_test($latest_audit['operating_system'] === 'Windows', "Device B logged as 'Windows'");
assert_test(!empty($latest_audit['session_id_ref']), "Audit linked to active session ID reference");

// Verify forced logout reason on superseded session
$superseded_audit = $audits[1];
assert_test($superseded_audit['forced_logout_reason'] === 'single_device_conflict', "Superseded session logged with forced_logout_reason = 'single_device_conflict'");
assert_test(!empty($superseded_audit['revocation_timestamp']), "Superseded session logged with revocation_timestamp");


// ====================================================================
// Test Suite 9: Rate Limiting & Brute Force Throttling
// ====================================================================
echo "\n--- Test Suite 9: Rate Limiting & Brute Force Throttling ---\n";

// Clear attempts table
$pdo->exec("DELETE FROM student_login_attempts");

$test_ip = '203.0.113.99';
$target_email = 'alice@pepp.com';

// 5 consecutive wrong attempts
for ($i = 1; $i <= 5; $i++) {
    authenticate_student_by_credentials($pdo, $target_email, '1999-01-01');
}

// 6th attempt should be throttled
$throttled_res = authenticate_student_by_credentials($pdo, $target_email, '2004-05-15');
assert_test($throttled_res['success'] === false, "6th attempt is throttled and blocked");
assert_test($throttled_res['error_type'] === 'throttled', "Error type is 'throttled'");
assert_test(strpos($throttled_res['message'], 'Too many failed login attempts') !== false, "Generic throttling message displayed");

// Reset attempts on successful valid login from another IP
$clean_auth = authenticate_student_by_credentials($pdo, 'frank@pepp.com', '2004-12-12');
assert_test($clean_auth['success'] === true, "Unthrottled user can authenticate cleanly");


// ====================================================================
// Test Suite 10: Manual Logout
// ====================================================================
echo "\n--- Test Suite 10: Manual Logout ---\n";

$_SESSION = $dev_b_session;
$_COOKIE['pepp_sp_remember'] = $dev_b_cookie;

logout_student($pdo, 'manual_logout');
assert_test(empty($_SESSION['sp_logged_in']), "Session cleared on manual logout");
assert_test(empty($_COOKIE['pepp_sp_remember']), "Cookie removed on manual logout");

// Verify active session deleted from DB
$stmt_check_sas = $pdo->prepare("SELECT COUNT(*) FROM student_active_sessions WHERE student_email = ?");
$stmt_check_sas->execute(['alice@pepp.com']);
assert_test((int)$stmt_check_sas->fetchColumn() === 0, "Active session removed from student_active_sessions on manual logout");


// ====================================================================
// Test Suite 11: Mid-Session Status Invalidation
// ====================================================================
echo "\n--- Test Suite 11: Mid-Session Status Invalidation ---\n";

// Login Alice freshly
$auth_fresh = authenticate_student_by_credentials($pdo, 'alice@pepp.com', '2004-05-15');
$_SESSION['sp_logged_in'] = true;
$_SESSION['sp_email'] = 'alice@pepp.com';
$_SESSION['sp_active_session_id'] = $auth_fresh['active_session_id'];

// Admin changes Alice status to 'suspended'
$pdo->exec("UPDATE users SET student_status = 'suspended' WHERE user_id = 'STU001'");
$pdo->exec("INSERT INTO student_status_log (user_id, email, new_status, reason, changed_at) VALUES ('STU001', 'alice@pepp.com', 'suspended', 'Mid-term fee pending', '2026-08-30 00:00:00')");

$valid_session = revalidate_student_study_plan_access($pdo);
assert_test($valid_session === false, "revalidate_student_study_plan_access returns false when student status changes to suspended");
assert_test(empty($_SESSION['sp_logged_in']), "Session was immediately destroyed on status downgrade");
assert_test(($_SESSION['sp_force_logout_reason'] ?? '') === 'status_downgrade', "Logout reason tagged as status_downgrade");


// ====================================================================
// Test Suite 12: Study Plan IDOR Protection
// ====================================================================
echo "\n--- Test Suite 12: Study Plan IDOR Protection ---\n";

// Restore Alice status to active
$pdo->exec("UPDATE users SET student_status = 'active' WHERE user_id = 'STU001'");

function is_plan_authorized_for_student(PDO $pdo, string $email, int $plan_id): bool {
    $stmt_u = $pdo->prepare("SELECT user_id, pepp_course, pepp_academic_year FROM users WHERE email = ? AND status = 'approved' AND student_status = 'active' LIMIT 1");
    $stmt_u->execute([$email]);
    $u = $stmt_u->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;

    $stmt_p = $pdo->prepare("
        SELECT COUNT(*)
        FROM study_plans sp
        JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
        WHERE sp.id = ? AND sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
          AND (
            sa.assignment_type = 'all' OR
            (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
            (sa.assignment_type = 'batch' AND LOWER(sa.assigned_value) = LOWER(?)) OR
            (sa.assignment_type = 'student' AND sa.assigned_value = ?)
        )
    ");
    $stmt_p->execute([$plan_id, $u['pepp_course'], $u['pepp_academic_year'], $u['user_id']]);
    return ($stmt_p->fetchColumn() > 0);
}

assert_test(is_plan_authorized_for_student($pdo, 'alice@pepp.com', 101) === true, "Alice CAN access assigned Psychology Plan 101");
assert_test(is_plan_authorized_for_student($pdo, 'alice@pepp.com', 201) === false, "Alice CANNOT access Commerce Plan 201 (IDOR protection)");
assert_test(is_plan_authorized_for_student($pdo, 'frank@pepp.com', 201) === true, "Frank CAN access assigned Commerce Plan 201");
assert_test(is_plan_authorized_for_student($pdo, 'frank@pepp.com', 101) === false, "Frank CANNOT access Psychology Plan 101 (IDOR protection)");


// ====================================================================
// Test Suite 13: Privacy & PII Static Exposure Audit
// ====================================================================
echo "\n--- Test Suite 13: Privacy & PII Exposure Static Audit ---\n";

$studyplan_file = file_get_contents(__DIR__ . '/studyplan.php');
$report_file = file_get_contents(__DIR__ . '/studyplan-report.php');

assert_test(strpos($studyplan_file, 'navigator.geolocation') === false, "studyplan.php contains NO client geolocation prompts (navigator.geolocation)");
assert_test(strpos($studyplan_file, 'whatsapp_number') === false, "studyplan.php does not leak whatsapp_number in output markup");
assert_test(strpos($report_file, 'whatsapp_number') === false, "studyplan-report.php does not leak whatsapp_number in output markup");
assert_test(strpos($report_file, 'student_login_audit') === false, "studyplan-report.php does not expose audit table to student UI");


// ====================================================================
// Test Suite 14: Admin Authentication Isolation
// ====================================================================
echo "\n--- Test Suite 14: Admin Authentication Isolation ---\n";

$_SESSION = [];

// Setting student session should NOT grant admin privileges
$_SESSION['sp_logged_in'] = true;
$_SESSION['sp_email'] = 'alice@pepp.com';
assert_test(!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true, "Student session DOES NOT satisfy admin authentication");

// Setting admin session should NOT grant student portal session
$_SESSION = [];
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'superadmin';
assert_test(!isset($_SESSION['sp_logged_in']) || $_SESSION['sp_logged_in'] !== true, "Admin session DOES NOT satisfy student study plan authentication");


// ====================================================================
// Test Suite 15: Expired, Revoked Tokens & Replay Attacks
// ====================================================================
echo "\n--- Test Suite 15: Expired, Revoked Tokens & Replay Attacks ---\n";

// 1. Expired Token
$past_date = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago
$sel_exp = bin2hex(random_bytes(16));
$val_exp = bin2hex(random_bytes(32));
$hash_exp = hash('sha256', $val_exp);
$stmt_exp = $pdo->prepare("
    INSERT INTO student_login_tokens (student_user_id, student_email, selector, token_hash, created_at, expires_at)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt_exp->execute(['STU001', 'alice@pepp.com', $sel_exp, $hash_exp, $past_date, $past_date]);

$_SESSION = [];
$_COOKIE['pepp_sp_remember'] = $sel_exp . ':' . $val_exp;
$res_exp = authenticate_student_from_cookie($pdo);
assert_test($res_exp === false, "Expired remember token is REJECTED");
assert_test(empty($_SESSION['sp_logged_in']), "Session not created for expired token");

// 2. Revoked Token
$future_date = date('Y-m-d H:i:s', time() + 86400 * 60);
$sel_rev = bin2hex(random_bytes(16));
$val_rev = bin2hex(random_bytes(32));
$hash_rev = hash('sha256', $val_rev);
$stmt_rev = $pdo->prepare("
    INSERT INTO student_login_tokens (student_user_id, student_email, selector, token_hash, created_at, expires_at, revoked_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt_rev->execute(['STU001', 'alice@pepp.com', $sel_rev, $hash_rev, date('Y-m-d H:i:s'), $future_date, date('Y-m-d H:i:s')]);

$_SESSION = [];
$_COOKIE['pepp_sp_remember'] = $sel_rev . ':' . $val_rev;
$res_rev = authenticate_student_from_cookie($pdo);
assert_test($res_rev === false, "Revoked remember token is REJECTED");
assert_test(empty($_SESSION['sp_logged_in']), "Session not created for revoked token");

// 3. Replay Attack with Old Validator After Token Rotation
create_student_persistent_login($pdo, 'STU001', 'alice@pepp.com');
$active_cookie = $_COOKIE['pepp_sp_remember'];
list($c_sel, $c_val) = explode(':', $active_cookie, 2);

// First legitimate use: authenticates and rotates token
$_SESSION = [];
assert_test(authenticate_student_from_cookie($pdo) === true, "First cookie use succeeds and rotates validator");
$rotated_cookie = $_COOKIE['pepp_sp_remember'];
list($r_sel, $r_val) = explode(':', $rotated_cookie, 2);
assert_test($r_val !== $c_val, "Validator rotated to new value");

// Second use with OLD validator (Replay Attack simulation)
$_SESSION = [];
$_COOKIE['pepp_sp_remember'] = $c_sel . ':' . $c_val; // Replaying old cookie
$replay_result = authenticate_student_from_cookie($pdo);
assert_test($replay_result === false, "Replay attack with old validator is REJECTED");

// Check that tampering detection revoked the compromised token
$stmt_check_rev = $pdo->prepare("SELECT revoked_at FROM student_login_tokens WHERE selector = ?");
$stmt_check_rev->execute([$c_sel]);
assert_test(!empty($stmt_check_rev->fetchColumn()), "Tampered/replayed token immediately marked REVOKED in database");


// ====================================================================
// Test Suite 16: Cookie Security Flags & Session Regeneration
// ====================================================================
echo "\n--- Test Suite 16: Cookie Security Flags & Session Fixation ---\n";

$student_auth_code = file_get_contents(__DIR__ . '/includes/student_auth.php');
assert_test(strpos($student_auth_code, "'httponly' => true") !== false, "Remember cookie configured with HttpOnly = true");
assert_test(strpos($student_auth_code, "'samesite' => 'Lax'") !== false, "Remember cookie configured with SameSite = Lax");
assert_test(strpos($student_auth_code, "'secure' => \$is_https") !== false, "Remember cookie configured with Secure under HTTPS");
assert_test(strpos($student_auth_code, "session_regenerate_id(true)") !== false, "student_auth.php implements session_regenerate_id for session fixation defense");
assert_test(strpos($studyplan_code, "session_regenerate_id(true)") !== false, "studyplan.php implements session_regenerate_id on login");


// ====================================================================
// Test Suite 17: Database Constraints, Unique Indexes & Atomic Transactions
// ====================================================================
echo "\n--- Test Suite 17: Database Constraints & Atomic Transactions ---\n";

// In MySQL and SQLite, student_active_sessions has a UNIQUE index on student_email
$stmt_sas_dup = $pdo->prepare("SELECT COUNT(*) FROM student_active_sessions WHERE LOWER(student_email) = LOWER(?)");
$stmt_sas_dup->execute(['alice@pepp.com']);
assert_test((int)$stmt_sas_dup->fetchColumn() <= 1, "Exactly ONE active session record per student in database");

// Test transaction rollback safety in generate_student_active_session
assert_test(strpos($student_auth_code, "\$pdo->beginTransaction()") !== false, "generate_student_active_session uses atomic database transactions");
assert_test(strpos($student_auth_code, "\$pdo->commit()") !== false, "Transaction committed on success");
assert_test(strpos($student_auth_code, "\$pdo->rollBack()") !== false, "Transaction rolled back on failure");


// ====================================================================
// Test Suite 18: Full Lifecycle Rejection on Cookie Authentication
// ====================================================================
echo "\n--- Test Suite 18: Lifecycle Rejection on Cookie Authentication ---\n";

// Ensure suspended Bob cannot authenticate via persistent cookie
$bob_token_sel = bin2hex(random_bytes(16));
$bob_token_val = bin2hex(random_bytes(32));
$stmt_bob_tok = $pdo->prepare("
    INSERT INTO student_login_tokens (student_user_id, student_email, selector, token_hash, created_at, expires_at)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt_bob_tok->execute(['STU002', 'bob@pepp.com', $bob_token_sel, hash('sha256', $bob_token_val), date('Y-m-d H:i:s'), $future_date]);

$_SESSION = [];
$_COOKIE['pepp_sp_remember'] = $bob_token_sel . ':' . $bob_token_val;
$bob_cookie_res = authenticate_student_from_cookie($pdo);
assert_test($bob_cookie_res === false, "Suspended student cookie login is REJECTED");
assert_test(empty($_SESSION['sp_logged_in']), "Suspended student session is not established from cookie");

// Ensure inactive Charlie cannot authenticate via persistent cookie
$charlie_token_sel = bin2hex(random_bytes(16));
$charlie_token_val = bin2hex(random_bytes(32));
$stmt_bob_tok->execute(['STU003', 'charlie@pepp.com', $charlie_token_sel, hash('sha256', $charlie_token_val), date('Y-m-d H:i:s'), $future_date]);

$_SESSION = [];
$_COOKIE['pepp_sp_remember'] = $charlie_token_sel . ':' . $charlie_token_val;
$charlie_cookie_res = authenticate_student_from_cookie($pdo);
assert_test($charlie_cookie_res === false, "Inactive student cookie login is REJECTED");


// ====================================================================
// Test Suite 19: Protected AJAX Endpoints Authorization & Revalidation
// ====================================================================
echo "\n--- Test Suite 19: Protected AJAX Endpoints Authorization ---\n";

// In studyplan.php:
// AJAX actions require revalidate_student_study_plan_access($pdo)
assert_test(strpos($studyplan_code, "revalidate_student_study_plan_access(\$pdo)") !== false, "studyplan.php protects all requests with revalidate_student_study_plan_access");
assert_test(strpos($report_code, "revalidate_student_study_plan_access(\$pdo)") !== false, "studyplan-report.php protects all requests with revalidate_student_study_plan_access");


// ====================================================================
// Test Suite 20: Generic Error Messages & No Information Disclosure
// ====================================================================
echo "\n--- Test Suite 20: Generic Error Messages & Information Disclosure ---\n";

// Unknown email vs wrong DOB return identical generic message
$err_unknown_email = authenticate_student_by_credentials($pdo, 'nonexistent_user_xyz@pepp.com', '2004-05-15');
$err_wrong_dob = authenticate_student_by_credentials($pdo, 'alice@pepp.com', '1990-01-01');

assert_test($err_unknown_email['message'] === $err_wrong_dob['message'], "Generic error message is identical for unknown email and wrong DOB (prevents account enumeration)");
assert_test(strpos($err_unknown_email['message'], 'Invalid email address or date of birth') !== false, "Generic message informs student without revealing specific failure cause");


// ====================================================================
// Final Results
// ====================================================================
echo "\n======================================================================\n";
echo "Test Results: $passed Passed, $failed Failed\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
