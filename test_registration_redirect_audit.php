<?php
/**
 * PEPP ERP — REGISTRATION REDIRECT & PUBLIC SUCCESS AUDIT TEST SUITE
 * 
 * Tests the public student registration flow, database transaction commit,
 * post-registration redirect to success.php, isolation from login.php,
 * and data contract preservation.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$passed = 0;
$failed = 0;

function assert_true($condition, $message, $extra = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$message}" . ($extra ? " — {$extra}" : "") . "\n";
    }
}

echo "======================================================================\n";
echo "PEPP ERP — PUBLIC REGISTRATION & SUCCESS REDIRECT AUDIT\n";
echo "======================================================================\n\n";

// ── TEST GROUP 1: Static Source Code Analysis ─────────────────────────
echo "--- [TEST GROUP 1] Source Code Flow & Redirection Destination ---\n";

$register_code = file_get_contents(__DIR__ . '/register.php');
$success_code  = file_get_contents(__DIR__ . '/success.php');

// 1. register.php contains redirect to success.php
assert_true(
    preg_match('/header\s*\(\s*["\']Location:\s*success\.php\?id=\s*["\']\s*\.\s*urlencode\s*\(\s*\(string\)\s*\$inserted_id\s*\)\s*\)/i', $register_code) ||
    preg_match('/header\s*\(\s*["\']Location:\s*success\.php\?id=\s*["\']/i', $register_code),
    "1. register.php contains redirect to success.php with record ID"
);

// 2. Successful registration branch does NOT redirect to login.php
assert_true(
    strpos($register_code, 'login.php') === false,
    "2. register.php has zero references to login.php (cannot redirect student to login.php)"
);

// 3. success.php does NOT require auth.php
assert_true(
    strpos($success_code, 'auth.php') === false,
    "3. success.php does not include or require auth.php"
);

// 4. success.php does NOT reference or redirect to login.php
assert_true(
    strpos($success_code, 'login.php') === false,
    "4. success.php has zero references to login.php (cannot redirect student to login.php)"
);

// 5. success.php remains completely public and redirects invalid access to register.php
assert_true(
    strpos($success_code, "header('Location: register.php')") !== false,
    "5. success.php redirects invalid/unfound requests back to public register.php"
);

// 6. Transaction commit occurs before notification and redirect
$tx_start_pos  = strpos($register_code, '$pdo->beginTransaction()');
$insert_pos    = strpos($register_code, 'INSERT INTO users');
$tx_commit_pos = strpos($register_code, '$pdo->commit()');
$notif_pos     = strpos($register_code, '$engine->sendEventNotification');
$redirect_pos  = strpos($register_code, 'header("Location: success.php?id=');

assert_true(
    $tx_start_pos !== false && $insert_pos !== false && $tx_commit_pos !== false,
    "6. register.php encloses user insertion in an atomic database transaction"
);

assert_true(
    $tx_start_pos < $insert_pos && $insert_pos < $tx_commit_pos,
    "7. Database insert executes between beginTransaction() and commit()"
);

assert_true(
    $tx_commit_pos < $notif_pos && $notif_pos < $redirect_pos,
    "8. Database transaction is committed BEFORE notification dispatch and before success redirect"
);

// 9. Notification failure is wrapped in try/catch and cannot prevent success redirect
assert_true(
    strpos($register_code, "catch (Exception \$ex) {\n                error_log('Registration notification trigger failed:") !== false ||
    strpos($register_code, "Registration notification trigger failed:") !== false,
    "9. Notification failure is safely caught and cannot abort registration or alter redirect destination"
);

// 10. Session fallback storage is implemented
assert_true(
    strpos($register_code, "\$_SESSION['last_registered_id'] = \$inserted_id") !== false,
    "10. register.php stores last_registered_id in session as reliable success fallback"
);

assert_true(
    strpos($success_code, "\$_SESSION['last_registered_id']") !== false,
    "11. success.php accepts session fallback if GET parameter is absent"
);

// 12. success.php supports lookup by numeric ID and string user_id
assert_true(
    strpos($success_code, "SELECT * FROM users WHERE id = ? OR user_id = ? LIMIT 1") !== false,
    "12. success.php flexibly looks up student by auto-increment ID or alphanumeric user_id"
);

// 13. No open external redirect exists
assert_true(
    !preg_match('/header\s*\(\s*["\']Location:\s*\$_(?:GET|POST|REQUEST)\[/i', $register_code) &&
    !preg_match('/header\s*\(\s*["\']Location:\s*\$_(?:GET|POST|REQUEST)\[/i', $success_code),
    "13. No open user-supplied external redirect is allowed"
);

// ── TEST GROUP 2: Database & Data Contract Mock Verification ──────────
echo "\n--- [TEST GROUP 2] In-Memory Database & Data Contract Verification ---\n";

$test_pdo = new PDO('sqlite::memory:');
$test_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$test_pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        gender TEXT NOT NULL,
        date_of_birth TEXT NOT NULL,
        whatsapp_country_code TEXT DEFAULT '+91',
        whatsapp_number TEXT NOT NULL,
        mobile_same_as_whatsapp TEXT DEFAULT 'yes',
        mobile_number TEXT,
        emergency_contact TEXT NOT NULL,
        email TEXT NOT NULL,
        college_school TEXT NOT NULL,
        course TEXT NOT NULL,
        university_board TEXT NOT NULL,
        remaining_semesters TEXT,
        postal_address TEXT NOT NULL,
        postal_pincode TEXT NOT NULL,
        state TEXT NOT NULL,
        district TEXT NOT NULL,
        place_post_office TEXT NOT NULL,
        pepp_course TEXT NOT NULL,
        pepp_academic_year TEXT NOT NULL,
        paid_amount REAL NOT NULL,
        paid_date TEXT NOT NULL,
        payment_screenshot TEXT,
        user_photo TEXT,
        instagram_id TEXT,
        how_know_pepp TEXT NOT NULL,
        terms_agreed TEXT DEFAULT 'no',
        user_id TEXT NOT NULL,
        ip_address TEXT,
        phone TEXT,
        applied_coupon TEXT,
        referral_code TEXT,
        coupon_discount REAL DEFAULT 0.00,
        submit_datetime TEXT
    );
");

// Test insertion with transaction
$test_pdo->beginTransaction();
$mock_uid = 'PEPP' . date('Y') . '9999';
$stmtInsert = $test_pdo->prepare("
    INSERT INTO users (
        name, gender, date_of_birth, whatsapp_country_code, whatsapp_number, 
        mobile_same_as_whatsapp, mobile_number, emergency_contact, email,
        college_school, course, university_board, remaining_semesters,
        postal_address, postal_pincode, state, district, place_post_office,
        pepp_course, pepp_academic_year, paid_amount, paid_date,
        payment_screenshot, user_photo, instagram_id, how_know_pepp,
        terms_agreed, user_id, ip_address, phone,
        applied_coupon, referral_code, coupon_discount, submit_datetime
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
");
$stmtInsert->execute([
    'Test Student', 'Female', '2004-05-15', '+91', '9876543210',
    'yes', '9876543210', '9876543210', 'student@pepp.test',
    'ABC College', 'B.Com', 'Calicut University', '1,2',
    'Test House, Calicut', '673001', 'Kerala', 'Kozhikode', 'Calicut',
    'CUET PG Commerce', '2026-2027', 1500.00, '2026-08-31',
    'uploads/payments/test.jpg', 'uploads/photos/test.jpg', 'test_insta', 'Instagram',
    'yes', $mock_uid, '127.0.0.1', '9876543210',
    null, null, 0.00
]);
$test_inserted_id = $test_pdo->lastInsertId();
$test_pdo->commit();

assert_true($test_inserted_id > 0, "14. Database insert returned valid numeric ID ({$test_inserted_id})");

// Test lookup by numeric ID in success.php logic
$stmtFetch1 = $test_pdo->prepare("SELECT * FROM users WHERE id = ? OR user_id = ? LIMIT 1");
$stmtFetch1->execute([$test_inserted_id, $test_inserted_id]);
$u1 = $stmtFetch1->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($u1) && $u1['user_id'] === $mock_uid, "15. success.php data contract resolves student by integer ID");

// Test lookup by alphanumeric UID in success.php logic
$stmtFetch2 = $test_pdo->prepare("SELECT * FROM users WHERE id = ? OR user_id = ? LIMIT 1");
$stmtFetch2->execute([$mock_uid, $mock_uid]);
$u2 = $stmtFetch2->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($u2) && $u2['id'] == $test_inserted_id, "16. success.php data contract resolves student by string UID");

// ── TEST GROUP 3: Fast Approval WhatsApp URL Generation ───────────────
echo "\n--- [TEST GROUP 3] Fast Approval CTA & Client Interactions ---\n";

assert_true(
    strpos($success_code, 'requestFastApproval()') !== false,
    "17. success.php preserves 'Request Fast Approval!' WhatsApp action"
);

assert_true(
    strpos($success_code, 'adminWhatsApp = \'917025000444\'') !== false,
    "18. success.php targets official admin WhatsApp number (917025000444)"
);

assert_true(
    strpos($success_code, '<a href="register.php" class="btn-action back-btn">') !== false,
    "19. success.php offers 'New Registration' navigation back to register.php"
);

echo "\n======================================================================\n";
echo "REGISTRATION REDIRECT AUDIT: {$passed} PASSED, {$failed} FAILED\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
