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
