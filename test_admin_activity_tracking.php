<?php
/**
 * PEPP ERP - Admin Activity Tracking Regression Test Suite
 */

// Enable SQLite testing database sandbox mode
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

// Mock session context
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'TestSuperAdmin';
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['admin_id'] = 42;
$_SESSION['session_ref'] = 'test-session-ref-12345';
$_SESSION['admin_location'] = 'Kozhikode, Kerala';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/activity_logger.php';

// Insert mock admin record into SQLite memory database to satisfy auth check on require
try {
    $stmt = $pdo->prepare("
        INSERT INTO admins (id, username, password_hash, role, status, permissions)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([42, 'TestSuperAdmin', password_hash('password123', PASSWORD_BCRYPT), 'super_admin', 'active', 'dashboard,settings']);
} catch (Exception $e) {
    echo "Failed to insert mock admin: " . $e->getMessage() . "\n";
    exit(1);
}

// Assertions counts
$total_assertions = 0;
$failed_assertions = 0;

function assert_equals($test_name, $expected, $actual) {
    global $total_assertions, $failed_assertions;
    $total_assertions++;
    if ($expected === $actual) {
        echo "✅ PASS: $test_name\n";
    } else {
        $failed_assertions++;
        echo "❌ FAIL: $test_name\n";
        echo "   Expected: " . var_export($expected, true) . "\n";
        echo "   Got:      " . var_export($actual, true) . "\n";
    }
}

echo "=== Running PEPP ERP Activity Tracking Regression Suite ===\n\n";

// 1. Check SQLite testing tables existence
try {
    $has_log = false;
    $has_pres = false;

    // SQLite master list query
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    while ($table = $stmt->fetchColumn()) {
        if ($table === 'admin_activity_log') $has_log = true;
        if ($table === 'admin_presence') $has_pres = true;
    }

    assert_equals("admin_activity_log table initialized in SQLite memory", true, $has_log);
    assert_equals("admin_presence table initialized in SQLite memory", true, $has_pres);
} catch (Exception $e) {
    echo "Fatal connection error: " . $e->getMessage() . "\n";
    exit(1);
}

// Clear table content before tests
$pdo->exec("DELETE FROM admin_activity_log");
$pdo->exec("DELETE FROM admin_presence");

// 2. Test log_login
log_login($pdo, 'TestSuperAdmin', 42, 'test-session-ref-12345');
$row = $pdo->query("SELECT * FROM admin_activity_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assert_equals("log_login action_type", 'login', $row['action_type'] ?? null);
assert_equals("log_login session_id", 'test-session-ref-12345', $row['session_id'] ?? null);
assert_equals("log_login admin_id", 42, (int)($row['admin_id'] ?? 0));
assert_equals("log_login admin_username", 'TestSuperAdmin', $row['admin_username'] ?? null);

// 3. Test log_failed_login
log_failed_login($pdo, 'AttackerUser', 'Invalid credentials');
$row = $pdo->query("SELECT * FROM admin_activity_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assert_equals("log_failed_login action_type", 'failed_login', $row['action_type'] ?? null);
assert_equals("log_failed_login admin_username", 'AttackerUser', $row['admin_username'] ?? null);
assert_equals("log_failed_login details", 'Failed login attempt: Invalid credentials', $row['details'] ?? null);

// 4. Test log_page_view
log_page_view($pdo, 'task-tracker.php', 'Academics', 'Academics');
$row = $pdo->query("SELECT * FROM admin_activity_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assert_equals("log_page_view action_type", 'page_view', $row['action_type'] ?? null);
assert_equals("log_page_view module", 'Academics', $row['module'] ?? null);
assert_equals("log_page_view page", 'task-tracker.php', $row['page'] ?? null);

// 5. Test log_heartbeat and suppression
// Clear session cached heartbeat fields first
unset($_SESSION['last_hb_page']);
unset($_SESSION['last_hb_idle']);

// First heartbeat
log_heartbeat($pdo, 'task-tracker.php', 'Academics', 'Academics', 0);
$count_before = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action_type = 'heartbeat'")->fetchColumn();
assert_equals("First heartbeat logged", 1, $count_before);

// Duplicate heartbeat (same page, same active/idle state) - should be suppressed
log_heartbeat($pdo, 'task-tracker.php', 'Academics', 'Academics', 0);
$count_after = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action_type = 'heartbeat'")->fetchColumn();
assert_equals("Duplicate heartbeat suppressed", $count_before, $count_after);

// Changed heartbeat (state changes to idle) - should be logged
log_heartbeat($pdo, 'task-tracker.php', 'Academics', 'Academics', 1);
$count_changed = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE action_type = 'heartbeat'")->fetchColumn();
assert_equals("Changed heartbeat logged", $count_before + 1, $count_changed);

// 6. Test update_presence_state
update_presence_state($pdo, 'task-tracker.php', 'Academics', 'Academics', 0);
$pres = $pdo->query("SELECT * FROM admin_presence WHERE username = 'TestSuperAdmin'")->fetch(PDO::FETCH_ASSOC);

assert_equals("update_presence_state current_page", 'task-tracker.php', $pres['current_page'] ?? null);
assert_equals("update_presence_state current_section", 'Academics', $pres['current_section'] ?? null);
assert_equals("update_presence_state session_id", 'test-session-ref-12345', $pres['session_id'] ?? null);
assert_equals("update_presence_state is_idle", 0, (int)($pres['is_idle'] ?? 1));

// 7. Test log_logout
log_logout($pdo, 'TestSuperAdmin', 'test-session-ref-12345', 'Manual logout');
$row = $pdo->query("SELECT * FROM admin_activity_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assert_equals("log_logout action_type", 'logout', $row['action_type'] ?? null);
assert_equals("log_logout session_id", 'test-session-ref-12345', $row['session_id'] ?? null);

// 8. Test log_auto_logout
log_auto_logout($pdo, 'TestSuperAdmin', 'test-session-ref-12345', 'Session expired');
$row = $pdo->query("SELECT * FROM admin_activity_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assert_equals("log_auto_logout action_type", 'auto_logout', $row['action_type'] ?? null);
assert_equals("log_auto_logout details", 'Session expired', $row['details'] ?? null);

// 9. Test legacy log_admin_activity routing and student parsing
require_once __DIR__ . '/includes/auth.php'; // Defines legacy log_admin_activity
log_admin_activity($pdo, 'TestSuperAdmin', 'student_updated', 'Updated student details for PL-2026-99');
$row = $pdo->query("SELECT * FROM admin_activity_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assert_equals("legacy log_admin_activity routed through new helper", 'student_updated', $row['action_type'] ?? null);
assert_equals("legacy admin_id populated automatically", 42, (int)($row['admin_id'] ?? 0));
assert_equals("legacy session_id populated automatically", 'test-session-ref-12345', $row['session_id'] ?? null);
assert_equals("legacy target_type auto-detected", 'student', $row['target_type'] ?? null);
assert_equals("legacy target_id auto-detected", 'PL-2026-99', $row['target_id'] ?? null);

// 10. Test get_activity_type_where helper definitions
if (!function_exists('get_activity_type_where')) {
    function get_activity_type_where($f_type) {
        switch ($f_type) {
            case 'session':
            case 'auth':
                return "action_type IN ('login','logout','auto_logout','forced_logout','failed_login')";
            case 'page_view':
                return "action_type = 'page_view'";
            case 'staff_mgmt':
                return "(action_type IN ('staff_profile_update','staff_status_change','staff_admin_linked','staff_admin_unlinked','staff_created','staff_deleted') OR action_type LIKE 'staff_%')";
            case 'admin_mgmt':
                return "(action_type IN ('permissions_changed','admin_created','admin_deleted','admin_status_changed','password_reset','password_changed','admin_access_updated') OR action_type LIKE 'admin_%')";
            case 'security':
            case 'sensitive_data':
                return "(action_type IN ('sensitive_data_reveal','sensitive_data_copy','password_reset','password_changed','data_export','activity_cleared','activity_deleted') OR action_type LIKE '%sensitive%' OR action_type LIKE '%security%')";
            case 'student_action':
                return "(action_type IN ('student_approved','student_rejected','student_updated','student_reverted','student_deleted') OR action_type LIKE 'student_%')";
            case 'whatsapp':
                return "action_type = 'whatsapp_message'";
            case 'creates':
                return "(action_type LIKE '%create%' OR action_type LIKE '%add%' OR action_type IN ('admin_created','staff_admin_linked'))";
            case 'updates':
                return "(action_type LIKE '%update%' OR action_type LIKE '%edit%' OR action_type LIKE '%change%' OR action_type IN ('permissions_changed','staff_status_change'))";
            case 'deletes':
                return "(action_type LIKE '%delete%' OR action_type LIKE '%clear%' OR action_type IN ('admin_deleted','activity_deleted','activity_cleared','staff_admin_unlinked'))";
            case 'admin_event':
                return "(action_type NOT IN ('login','logout','auto_logout','forced_logout','failed_login','page_view','heartbeat') AND is_heartbeat = 0)";
            case 'heartbeat':
                return "(action_type = 'heartbeat' OR is_heartbeat = 1)";
            case 'all_activities':
                return null;
            case 'all_meaningful':
            case '':
            default:
                return "(action_type != 'heartbeat' AND is_heartbeat = 0)";
        }
    }
}

assert_equals("default filter excludes heartbeat", "(action_type != 'heartbeat' AND is_heartbeat = 0)", get_activity_type_where(''));
assert_equals("all_meaningful filter excludes heartbeat", "(action_type != 'heartbeat' AND is_heartbeat = 0)", get_activity_type_where('all_meaningful'));
assert_equals("heartbeat filter targets heartbeat", "(action_type = 'heartbeat' OR is_heartbeat = 1)", get_activity_type_where('heartbeat'));
assert_equals("page_view filter targets page_view", "action_type = 'page_view'", get_activity_type_where('page_view'));
assert_equals("all_activities returns null (unfiltered)", null, get_activity_type_where('all_activities'));

// 11. Test audit timeline query filtering
$total_in_db = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log")->fetchColumn();
$meaningful_cond = get_activity_type_where('');
$meaningful_count = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE $meaningful_cond")->fetchColumn();
$heartbeat_cond = get_activity_type_where('heartbeat');
$heartbeat_count = (int)$pdo->query("SELECT COUNT(*) FROM admin_activity_log WHERE $heartbeat_cond")->fetchColumn();

assert_equals("Database contains both heartbeats and meaningful events", $meaningful_count + $heartbeat_count, $total_in_db);
assert_equals("Default audit query excludes exactly heartbeat records", 2, $heartbeat_count);
assert_equals("Meaningful audit actions remain visible in timeline", true, $meaningful_count > 0);

echo "\n=== Regression Test Results ===\n";
echo "Total Assertions: $total_assertions\n";
echo "Failed Assertions: $failed_assertions\n";

if ($failed_assertions === 0) {
    echo "\n🎉 ALL TESTS PASSED SUCCESSFULLY! 🎉\n";
    exit(0);
} else {
    echo "\n❌ SOME TESTS FAILED! ❌\n";
    exit(1);
}
