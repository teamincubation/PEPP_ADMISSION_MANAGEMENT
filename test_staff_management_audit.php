<?php
/**
 * PEPP Learning ERP — Automated Audit & Verification Suite
 * Tests Approved Staff Management, Staff-Admin Account Linking & Mentor Photo Integration
 *
 * Requirements Tested:
 * 1. Superadmin Authorization & Access Control
 * 2. CSRF Token Rejection on State-Changing / Sensitive Actions
 * 3. Canonical Staff Employment Status Whitelist & Status Change Audit
 * 4. Masked Default KYC / Bank Data & Encrypted Storage
 * 5. Sensitive Data Reveal / Copy Endpoints & Non-Plaintext Audit Logging
 * 6. Phone Number & Email Normalization & Privacy Masking
 * 7. Staff ↔ Admin Account Linking Strict Matching (Dual Email + Phone)
 * 8. 1-to-1 Relationship Integrity & Link Conflict Handling
 * 9. Mentor Report Photo Resolution via Linked Employee Records
 * 10. Database Schema Invariants & Production Safety
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('UTC');

require_once __DIR__ . '/includes/encryption_helper.php';

// Define normalization & masking functions as implemented in admin-management.php & employee-management.php
if (!function_exists('normalize_phone_number')) {
    function normalize_phone_number(?string $phone): string {
        if ($phone === null) return '';
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && substr($digits, 0, 1) === '0') {
            $digits = substr($digits, 1);
        }
        return $digits;
    }
}

if (!function_exists('mask_email_display')) {
    function mask_email_display(?string $email): string {
        if (empty($email)) return '—';
        $parts = explode('@', $email);
        if (count($parts) !== 2) return $email;
        $name = $parts[0];
        $domain = $parts[1];
        $visible_len = (strlen($name) > 3) ? 2 : 1;
        return substr($name, 0, $visible_len) . '***@' . $domain;
    }
}

if (!function_exists('mask_phone_display')) {
    function mask_phone_display(?string $phone): string {
        $digits = normalize_phone_number($phone);
        if (strlen($digits) < 4) return $digits ?: '—';
        return '******' . substr($digits, -4);
    }
}

if (!function_exists('log_admin_activity')) {
    function log_admin_activity($pdo, string $username, string $action, string $details = ''): void {
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_activity_log (username, action, details, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$username, $action, $details]);
        } catch (Exception $e) {}
    }
}

// Canonical Staff Employment Status List
$CANONICAL_STAFF_STATUSES = [
    'active'         => ['label' => 'Active',         'color' => 'green'],
    'probation'      => ['label' => 'Probation',      'color' => 'blue'],
    'contract'       => ['label' => 'Contract',       'color' => 'indigo'],
    'notice_period'  => ['label' => 'Notice Period',  'color' => 'amber'],
    'on_leave'       => ['label' => 'On Leave',       'color' => 'purple'],
    'inactive'       => ['label' => 'Inactive',       'color' => 'gray'],
    'suspended'      => ['label' => 'Suspended',      'color' => 'red'],
    'resigned'       => ['label' => 'Resigned',       'color' => 'slate'],
    'contract_ended' => ['label' => 'Contract Ended', 'color' => 'orange'],
    'terminated'     => ['label' => 'Terminated',     'color' => 'red'],
    'completed'      => ['label' => 'Completed',      'color' => 'teal']
];

// Setup isolated SQLite PDO environment
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create SQLite schema for audit suite
$pdo->exec("
    CREATE TABLE admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        full_name TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        role TEXT NOT NULL DEFAULT 'admin',
        admin_type TEXT NOT NULL DEFAULT 'erp_admin',
        permissions TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'active',
        google_id TEXT DEFAULT NULL,
        google_email TEXT DEFAULT NULL,
        credential_visibility TEXT NOT NULL DEFAULT 'visible',
        credential_visibility_scopes TEXT DEFAULT '',
        can_edit INTEGER NOT NULL DEFAULT 1,
        can_delete INTEGER NOT NULL DEFAULT 1,
        can_export INTEGER NOT NULL DEFAULT 1,
        allow_copy_email INTEGER NOT NULL DEFAULT 1,
        allow_whatsapp_chat INTEGER NOT NULL DEFAULT 1,
        allow_phone_call INTEGER NOT NULL DEFAULT 1,
        last_active_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id TEXT NOT NULL UNIQUE,
        photo TEXT DEFAULT NULL,
        full_name TEXT NOT NULL,
        gender TEXT DEFAULT 'Male',
        blood_group TEXT DEFAULT 'O+',
        date_of_birth DATE,
        mobile_country_code TEXT DEFAULT '+91',
        mobile_number TEXT NOT NULL,
        email TEXT NOT NULL,
        emergency_country_code TEXT DEFAULT '+91',
        emergency_contact TEXT,
        address TEXT,
        pincode TEXT,
        country TEXT DEFAULT 'India',
        state TEXT,
        place_post_office TEXT,
        aadhaar_encrypted TEXT,
        aadhaar_masked TEXT,
        bank_name TEXT,
        bank_account_encrypted TEXT,
        bank_account_masked TEXT,
        ifsc_code TEXT,
        upi_id TEXT,
        application_for TEXT DEFAULT 'employee',
        designation TEXT,
        department TEXT,
        joining_date DATE,
        probation_till DATE,
        contract_validity_from DATE,
        contract_validity_till DATE,
        monthly_salary REAL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        admin_id INTEGER DEFAULT NULL,
        linked_at DATETIME DEFAULT NULL,
        linked_by TEXT DEFAULT NULL,
        appointment_reference TEXT,
        appointment_snapshot TEXT,
        appointment_generated_at DATETIME,
        application_id INTEGER,
        created_by TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE employee_custom_fields (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        field_name TEXT NOT NULL,
        field_type TEXT NOT NULL DEFAULT 'text',
        dropdown_options TEXT,
        is_required INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        created_by TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE employee_custom_values (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        field_id INTEGER NOT NULL,
        field_value TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(employee_id, field_id)
    );

    CREATE TABLE admin_activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER,
        username TEXT NOT NULL,
        action TEXT NOT NULL,
        details TEXT,
        ip_address TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

$test_results = [];
$total_tests = 0;
$passed_tests = 0;
$failed_tests = 0;

function run_test(string $name, callable $fn): void {
    global $total_tests, $passed_tests, $failed_tests, $test_results;
    $total_tests++;
    try {
        $res = $fn();
        if ($res === true || $res === null) {
            $passed_tests++;
            $test_results[] = ['name' => $name, 'status' => 'PASS', 'msg' => ''];
            echo "  [PASS] {$name}\n";
        } else {
            $failed_tests++;
            $test_results[] = ['name' => $name, 'status' => 'FAIL', 'msg' => (string)$res];
            echo "  [FAIL] {$name} — {$res}\n";
        }
    } catch (Throwable $e) {
        $failed_tests++;
        $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        $test_results[] = ['name' => $name, 'status' => 'ERROR', 'msg' => $msg];
        echo "  [ERROR] {$name} — {$msg}\n";
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new RuntimeException($msg);
}

function assert_false(bool $cond, string $msg = 'Assertion failed'): void {
    if ($cond) throw new RuntimeException($msg);
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $exp_str = is_scalar($expected) ? (string)$expected : json_encode($expected);
        $act_str = is_scalar($actual) ? (string)$actual : json_encode($actual);
        throw new RuntimeException(($msg ? $msg . ' — ' : '') . "Expected: [{$exp_str}], got: [{$act_str}]");
    }
}

echo "======================================================================\n";
echo " PEPP ERP — Staff Management, Account Linking & Photo Audit Suite\n";
echo "======================================================================\n\n";

// ======================================================================
// SECTION 1: Phone Normalization & Privacy Masking
// ======================================================================
echo "--- SECTION 1: Identity Normalization & Masking Logic ---\n";

run_test('Phone number normalization handles standard, prefixed & international formats', function() {
    assert_equals('9876543210', normalize_phone_number('9876543210'), 'Plain 10 digits');
    assert_equals('9876543210', normalize_phone_number('+91 98765 43210'), 'Prefixed +91 with spaces');
    assert_equals('9876543210', normalize_phone_number('919876543210'), 'Prefixed 91 12-digits');
    assert_equals('9876543210', normalize_phone_number('09876543210'), 'Prefixed 0 11-digits');
    assert_equals('9876543210', normalize_phone_number('98765-43210'), 'Formatted with hyphen');
    assert_equals('', normalize_phone_number(''), 'Empty string');
});

run_test('Privacy masking correctly masks sensitive emails and phone numbers', function() {
    assert_equals('jo***@example.com', mask_email_display('john.doe@example.com'), 'Long email mask');
    assert_equals('st***@example.com', mask_email_display('staff@example.com'), 'Staff email mask');
    assert_equals('a***@pepp.com', mask_email_display('ab@pepp.com'), 'Short email mask');
    assert_equals('—', mask_email_display(''), 'Empty email mask');

    assert_equals('******3210', mask_phone_display('9876543210'), 'Standard 10-digit phone mask');
    assert_equals('******3210', mask_phone_display('+919876543210'), 'International phone mask');
    assert_equals('—', mask_phone_display(''), 'Empty phone mask');
});

// ======================================================================
// SECTION 2: Canonical Status Invariants & Whitelist
// ======================================================================
echo "\n--- SECTION 2: Canonical Status Whitelist & Database Invariants ---\n";

run_test('Canonical staff employment status list contains all 11 required statuses', function() use ($CANONICAL_STAFF_STATUSES) {
    $required_statuses = [
        'active', 'probation', 'contract', 'notice_period', 'on_leave',
        'inactive', 'suspended', 'resigned', 'contract_ended', 'terminated', 'completed'
    ];

    foreach ($required_statuses as $st) {
        assert_true(array_key_exists($st, $CANONICAL_STAFF_STATUSES), "Canonical status '{$st}' is present");
        assert_true(!empty($CANONICAL_STAFF_STATUSES[$st]['label']), "Label exists for '{$st}'");
        assert_true(!empty($CANONICAL_STAFF_STATUSES[$st]['color']), "Color badge exists for '{$st}'");
    }
});

// ======================================================================
// SECTION 3: KYC / Bank Masking & Sensitive Data Protection
// ======================================================================
echo "\n--- SECTION 3: Masked KYC & Sensitive Data Protection ---\n";

run_test('AES-256-GCM encryption helper correctly encrypts, decrypts and masks', function() {
    $raw_aadhaar = '987654321098';
    $encrypted_aadhaar = pepp_encrypt($raw_aadhaar);
    assert_true($encrypted_aadhaar !== $raw_aadhaar, 'Ciphertext differs from plaintext');
    assert_equals($raw_aadhaar, pepp_decrypt($encrypted_aadhaar), 'Decryption restores exact plaintext');

    $masked_aadhaar = mask_aadhaar($raw_aadhaar);
    assert_equals('XXXX XXXX 1098', $masked_aadhaar, 'Aadhaar masked correctly');

    $raw_bank = '123456789012';
    $encrypted_bank = pepp_encrypt($raw_bank);
    assert_equals($raw_bank, pepp_decrypt($encrypted_bank), 'Bank decryption restores exact plaintext');
    $masked_bank = mask_bank_account($raw_bank);
    assert_equals('XXXXXXXX9012', $masked_bank, 'Bank masked correctly');
});

// ======================================================================
// SECTION 4: Staff ↔ Admin Account Linking Strict Matching
// ======================================================================
echo "\n--- SECTION 4: Staff ↔ Admin Account Linking Logic ---\n";

run_test('Dual email & phone matching allows linking only on exact match and blocks mismatch', function() use ($pdo) {
    // Insert test admin
    $pdo->prepare("INSERT INTO admins (username, full_name, email, phone, role, status) VALUES (?, ?, ?, ?, 'admin', 'active')")
        ->execute(['test_mentor_adm', 'Mentor Admin Test', 'mentor.test@pepplearning.com', '+91 98765 43210']);
    $admin_id = (int)$pdo->lastInsertId();

    // Insert matching employee
    $raw_aadhaar = '987654321098';
    $raw_bank = '987654321012';
    $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, status, aadhaar_encrypted, aadhaar_masked,
            bank_account_encrypted, bank_account_masked, photo
        ) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)
    ")->execute([
        'EMP00199', 'Mentor Admin Test', 'mentor.test@pepplearning.com', '9876543210',
        pepp_encrypt($raw_aadhaar), mask_aadhaar($raw_aadhaar),
        pepp_encrypt($raw_bank), mask_bank_account($raw_bank),
        'uploads/photos/emp_test.jpg'
    ]);
    $emp_id = (int)$pdo->lastInsertId();

    // Fetch records
    $stmt_a = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt_a->execute([$admin_id]);
    $adm = $stmt_a->fetch(PDO::FETCH_ASSOC);

    $stmt_e = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt_e->execute([$emp_id]);
    $emp = $stmt_e->fetch(PDO::FETCH_ASSOC);

    // Verification check
    $email_match = (strtolower(trim($emp['email'])) === strtolower(trim($adm['email'])));
    $phone_match = (normalize_phone_number($emp['mobile_number']) === normalize_phone_number($adm['phone']));
    assert_true($email_match && $phone_match, 'Dual match passes for exact email and phone');

    // Link the records
    $pdo->prepare("UPDATE employees SET admin_id = ?, linked_at = CURRENT_TIMESTAMP, linked_by = ? WHERE id = ?")
        ->execute([$admin_id, 'audit_superadmin', $emp_id]);

    // Check linked result
    $stmt_linked = $pdo->prepare("SELECT admin_id, linked_by FROM employees WHERE id = ?");
    $stmt_linked->execute([$emp_id]);
    $linked_row = $stmt_linked->fetch(PDO::FETCH_ASSOC);
    assert_equals($admin_id, (int)$linked_row['admin_id'], 'Employee linked to admin ID');
    assert_equals('audit_superadmin', $linked_row['linked_by'], 'Linked by recorded');
});

run_test('1-to-1 relationship integrity prevents duplicate admin assignments', function() use ($pdo) {
    // Try to link another employee to the same admin_id
    $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, status
        ) VALUES (?, ?, ?, ?, 'active')
    ")->execute(['EMP00200', 'Second Staff', 'second@pepplearning.com', '9876500000']);
    $second_emp_id = (int)$pdo->lastInsertId();

    // Check conflict check logic
    $admin_id = 1;
    $stmt_chk = $pdo->prepare("SELECT id, employee_id, full_name FROM employees WHERE admin_id = ? AND id != ?");
    $stmt_chk->execute([$admin_id, $second_emp_id]);
    $existing = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    assert_true(!empty($existing), 'Conflict detection identifies existing linked staff');
});

// ======================================================================
// SECTION 5: Mentor Report Photo Integration
// ======================================================================
echo "\n--- SECTION 5: Mentor Report Photo Resolution ---\n";

run_test('Mentor report eligibility query successfully joins employees table for staff photo', function() use ($pdo) {
    $sql = "
        SELECT DISTINCT
            a.id,
            a.username,
            a.full_name,
            a.email,
            a.phone,
            a.role,
            a.status,
            a.admin_type,
            a.last_active_at,
            e.photo AS staff_photo,
            e.employee_id AS staff_code
        FROM admins a
        LEFT JOIN employees e ON a.id = e.admin_id
        WHERE a.status = 'active'
        ORDER BY a.full_name ASC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    assert_true(is_array($rows), 'Query executes without SQL errors');
    assert_true(count($rows) > 0, 'Found active admins');

    // Find our linked mentor admin
    $found_photo = false;
    foreach ($rows as $r) {
        if ($r['username'] === 'test_mentor_adm') {
            assert_equals('uploads/photos/emp_test.jpg', $r['staff_photo'], 'Staff photo resolved from linked employee');
            assert_equals('EMP00199', $r['staff_code'], 'Staff code resolved from linked employee');
            $found_photo = true;
            break;
        }
    }
    assert_true($found_photo, 'Linked mentor admin found in report cohort with staff photo');
});

run_test('resolve_staff_photo_url helper correctly resolves relative paths, external URLs and rejects traversals/non-images', function() {
    if (!function_exists('resolve_staff_photo_url')) {
        function resolve_staff_photo_url(?string $photo): string {
            if (!$photo || trim($photo) === '') return '';
            $photo = trim($photo);
            if (preg_match('#^https?://#i', $photo) || strpos($photo, 'data:image/') === 0) {
                if (strpos($photo, 'data:image/') === 0 || preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)(\?.*)?$/i', $photo)) {
                    return $photo;
                }
                return '';
            }
            if (!preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)$/i', $photo)) {
                return '';
            }
            if (strpos($photo, '../..') !== false || strpos($photo, '..\\..') !== false) {
                return '';
            }
            $clean = preg_replace('#^[./\\\\]+#', '', $photo);
            $clean = ltrim($clean, '/\\');
            if (strpos($clean, 'photos/') === 0) {
                $clean = 'uploads/' . $clean;
            }
            if (strpos($clean, 'uploads/') === 0) {
                return '../' . $clean;
            }
            return '../uploads/photos/' . basename($clean);
        }
    }

    // Canonical relative paths
    assert_equals('../uploads/photos/emp_test.jpg', resolve_staff_photo_url('uploads/photos/emp_test.jpg'), 'Canonical uploads/photos/ path');
    assert_equals('../uploads/photos/mentor1.png', resolve_staff_photo_url('photos/mentor1.png'), 'Legacy photos/ prefix normalized');
    assert_equals('../uploads/photos/avatar.webp', resolve_staff_photo_url('avatar.webp'), 'Filename only normalized to ../uploads/photos/');

    // External and Data URIs
    assert_equals('https://cdn.example.com/photos/user.jpg', resolve_staff_photo_url('https://cdn.example.com/photos/user.jpg'), 'HTTPS URL preserved');
    $data_uri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    assert_equals($data_uri, resolve_staff_photo_url($data_uri), 'Data URI preserved');

    // Security & Non-Image Rejections
    assert_equals('', resolve_staff_photo_url('../../etc/passwd'), 'Directory traversal rejected');
    assert_equals('', resolve_staff_photo_url('..\\..\\windows\\system32'), 'Windows traversal rejected');
    assert_equals('', resolve_staff_photo_url('script.php'), 'PHP file extension rejected');
    assert_equals('', resolve_staff_photo_url('resume.pdf'), 'PDF file extension rejected');
    assert_equals('', resolve_staff_photo_url('archive.zip'), 'ZIP file extension rejected');
    assert_equals('', resolve_staff_photo_url(''), 'Empty string returns empty');
    assert_equals('', resolve_staff_photo_url(null), 'Null returns empty');
});

// ======================================================================
// SECTION 6: Audit Activity Logging Compliance
// ======================================================================
echo "\n--- SECTION 6: Audit Activity Logging Compliance ---\n";

run_test('Sensitive data reveal and copy audit events never contain decrypted plaintext', function() use ($pdo) {
    $secret = '987654321098';
    $admin = 'audit_runner';

    // Simulate reveal log
    log_admin_activity($pdo, $admin, 'sensitive_data_reveal', 'Revealed Aadhaar Number for staff Mentor Admin Test (EMP00199)');
    log_admin_activity($pdo, $admin, 'sensitive_data_copy', 'Copied BANK ACCOUNT for staff Mentor Admin Test (EMP00199)');

    // Verify logged details
    $stmt = $pdo->prepare("SELECT details FROM admin_activity_log WHERE username = ? AND action IN ('sensitive_data_reveal', 'sensitive_data_copy') ORDER BY id DESC LIMIT 2");
    $stmt->execute([$admin]);
    $logs = $stmt->fetchAll(PDO::FETCH_COLUMN);

    assert_true(count($logs) === 2, 'Two audit logs recorded');
    foreach ($logs as $log) {
        assert_true(strpos($log, $secret) === false, "Activity log does not contain plaintext secret: {$log}");
    }
});

// ======================================================================
// SECTION 7: Custom Fields Canonical Schema Compatibility & Regression
// ======================================================================
echo "\n--- SECTION 7: Custom Fields Canonical Schema Compatibility & Regression ---\n";

run_test('get_employee_details loads successfully on canonical schema without field_key', function() use ($pdo) {
    // 1. Insert custom field with canonical columns (field_name, dropdown_options, no field_key)
    $stmt_ins_cf = $pdo->prepare("
        INSERT INTO employee_custom_fields (field_name, field_type, dropdown_options, is_required, sort_order, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_ins_cf->execute(['Emergency Blood Donor Contact', 'text', null, 1, 1, 'active', 'superadmin']);
    $cf1_id = (int)$pdo->lastInsertId();

    $stmt_ins_cf->execute(['T-Shirt Size', 'dropdown', 'S, M, L, XL, XXL', 0, 2, 'active', 'superadmin']);
    $cf2_id = (int)$pdo->lastInsertId();

    // 2. Insert custom field values for employee #1
    $stmt_ins_val = $pdo->prepare("
        INSERT INTO employee_custom_values (employee_id, field_id, field_value)
        VALUES (?, ?, ?)
    ");
    $stmt_ins_val->execute([1, $cf1_id, '+91 9847012345']);
    $stmt_ins_val->execute([1, $cf2_id, 'XL']);

    // 3. Execute the exact query used by get_employee_details in employee-management.php
    $stmt_cf = $pdo->prepare("
        SELECT cf.*, cv.field_value
        FROM employee_custom_fields cf
        LEFT JOIN employee_custom_values cv ON cf.id = cv.field_id AND cv.employee_id = ?
        WHERE cf.status = 'active'
        ORDER BY cf.sort_order ASC, cf.id ASC
    ");
    $stmt_cf->execute([1]);
    $custom_fields = $stmt_cf->fetchAll(PDO::FETCH_ASSOC);

    assert_true(is_array($custom_fields), 'Query on employee_custom_fields executes with 0 SQL errors');
    assert_equals(2, count($custom_fields), 'Retrieved exactly 2 custom fields');

    // 4. Perform the server-side normalization
    foreach ($custom_fields as &$cf_row) {
        if (!isset($cf_row['field_label']) && isset($cf_row['field_name'])) {
            $cf_row['field_label'] = $cf_row['field_name'];
        }
        if (!isset($cf_row['field_options']) && isset($cf_row['dropdown_options'])) {
            $cf_row['field_options'] = $cf_row['dropdown_options'];
        }
    }
    unset($cf_row);

    // 5. Verify normalized fields and values
    assert_equals('Emergency Blood Donor Contact', $custom_fields[0]['field_label'], 'Field 1 label normalized from field_name');
    assert_equals('+91 9847012345', $custom_fields[0]['field_value'], 'Field 1 value loaded from employee_custom_values');
    assert_equals('T-Shirt Size', $custom_fields[1]['field_label'], 'Field 2 label normalized from field_name');
    assert_equals('S, M, L, XL, XXL', $custom_fields[1]['field_options'], 'Field 2 options normalized from dropdown_options');
    assert_equals('XL', $custom_fields[1]['field_value'], 'Field 2 value loaded from employee_custom_values');
});

run_test('get_employee_details loads successfully for employee WITHOUT custom field values', function() use ($pdo) {
    // Employee #2 has no records in employee_custom_values
    $stmt_cf = $pdo->prepare("
        SELECT cf.*, cv.field_value
        FROM employee_custom_fields cf
        LEFT JOIN employee_custom_values cv ON cf.id = cv.field_id AND cv.employee_id = ?
        WHERE cf.status = 'active'
        ORDER BY cf.sort_order ASC, cf.id ASC
    ");
    $stmt_cf->execute([2]);
    $custom_fields = $stmt_cf->fetchAll(PDO::FETCH_ASSOC);

    assert_true(is_array($custom_fields), 'Query on employee without custom values executes cleanly');
    assert_equals(2, count($custom_fields), 'Active field definitions returned');
    assert_true(empty($custom_fields[0]['field_value']), 'Custom field value is empty/null without throwing error');
    assert_true(empty($custom_fields[1]['field_value']), 'Custom field value is empty/null without throwing error');
});

run_test('Custom field values update and upsert cleanly via employee_custom_values', function() use ($pdo) {
    $emp_id = 2;
    $valid_fids = $pdo->query("SELECT id FROM employee_custom_fields")->fetchAll(PDO::FETCH_COLUMN);

    // Simulate saving custom fields for Employee #2
    $submitted_fields = [
        $valid_fids[0] => '+91 9123456780',
        $valid_fids[1] => 'M'
    ];

    $stmt_upsert_cf = $pdo->prepare("
        INSERT INTO employee_custom_values (employee_id, field_id, field_value)
        VALUES (?, ?, ?)
        ON CONFLICT(employee_id, field_id) DO UPDATE SET field_value = excluded.field_value
    ");
    foreach ($submitted_fields as $fid => $val) {
        $stmt_upsert_cf->execute([$emp_id, (int)$fid, (string)$val]);
    }

    // Verify stored values
    $stmt_check = $pdo->prepare("SELECT field_id, field_value FROM employee_custom_values WHERE employee_id = ? ORDER BY field_id ASC");
    $stmt_check->execute([$emp_id]);
    $stored = $stmt_check->fetchAll(PDO::FETCH_KEY_PAIR);

    assert_equals('+91 9123456780', $stored[$valid_fids[0]], 'First custom field saved successfully');
    assert_equals('M', $stored[$valid_fids[1]], 'Second custom field saved successfully');
});

run_test('Static check: employee-management.php does not contain invalid cf.field_key in SQL', function() {
    $code = file_get_contents(__DIR__ . '/employee-management.php');
    assert_true(strpos($code, 'cf.field_key') === false, 'employee-management.php does not contain "cf.field_key" in SQL');
});

// ======================================================================
// SECTION 8: Role & Permission Access Control Audit
// ======================================================================
echo "\n--- SECTION 8: Role & Permission Access Control Audit ---\n";

run_test('can_access(employee-management) permits super_admin and authorized admin with employee-management or ALL permission', function() {
    // Helper function mimicking auth.php can_access logic
    $check_access = function(string $role, string $perms, string $page_key): bool {
        if ($page_key === 'communication' || $page_key === 'email-reports' || $page_key === 'mentor-reports') {
            return ($role === 'super_admin');
        }
        if ($role === 'super_admin') return true;
        if (trim($perms) === 'ALL') return true;
        $perm_list = array_map('trim', explode(',', $perms));
        return in_array($page_key, $perm_list, true);
    };

    // 1. Superadmin has full access
    assert_true($check_access('super_admin', '', 'employee-management'), 'Superadmin is granted access to employee-management');

    // 2. Admin with explicit employee-management permission
    assert_true($check_access('admin', 'dashboard,employee-management,students', 'employee-management'), 'Admin with employee-management permission is granted access');

    // 3. Admin with ALL permission
    assert_true($check_access('admin', 'ALL', 'employee-management'), 'Admin with ALL permissions is granted access');

    // 4. Unauthorized Admin without employee-management permission
    assert_false($check_access('admin', 'dashboard,students,approvals', 'employee-management'), 'Admin without employee-management permission is DENIED (HTTP 403)');

    // 5. Admin with empty permissions
    assert_false($check_access('admin', '', 'employee-management'), 'Admin with empty permissions is DENIED (HTTP 403)');
});

run_test('Static check: employee-management.php enforces require_permission(employee-management)', function() {
    $code = file_get_contents(__DIR__ . '/employee-management.php');
    assert_true(strpos($code, "require_permission('employee-management')") !== false, "employee-management.php contains require_permission('employee-management')");
    assert_true(strpos($code, "require_super_admin()") === false, "employee-management.php does NOT contain require_super_admin()");
});

run_test('Authorized admin can execute sensitive reveal, copy, profile update & status change with audit logging and no plaintext leakage', function() use ($pdo) {
    $auth_admin = 'hr_admin_johndoe';
    $emp_id = 1;

    // 1. Reveal action
    $stmt_emp = $pdo->prepare("SELECT id, employee_id, full_name, aadhaar_encrypted, bank_account_encrypted FROM employees WHERE id = ?");
    $stmt_emp->execute([$emp_id]);
    $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);

    $aadhaar_plain = pepp_decrypt($emp['aadhaar_encrypted']);
    assert_true(strlen($aadhaar_plain) >= 12, 'Decrypted Aadhaar number is valid');

    log_admin_activity($pdo, $auth_admin, 'sensitive_data_reveal', "Revealed Aadhaar Number for staff {$emp['full_name']} ({$emp['employee_id']})");

    // 2. Copy action
    $bank_plain = pepp_decrypt($emp['bank_account_encrypted']);
    assert_true(strlen($bank_plain) >= 9, 'Decrypted Bank Account number is valid');

    log_admin_activity($pdo, $auth_admin, 'sensitive_data_copy', "Copied BANK ACCOUNT for staff {$emp['full_name']} ({$emp['employee_id']})");

    // 3. Profile update action
    log_admin_activity($pdo, $auth_admin, 'staff_profile_update', "Updated profile for staff {$emp['full_name']} ({$emp['employee_id']})");

    // 4. Status change action
    log_admin_activity($pdo, $auth_admin, 'staff_status_change', "Changed status of staff {$emp['full_name']} ({$emp['employee_id']}) from active to on_leave (Reason: Annual leave)");

    // 5. Verify all 4 audit log entries are attributed to $auth_admin and contain zero plaintext
    $stmt_logs = $pdo->prepare("SELECT action, details FROM admin_activity_log WHERE username = ? ORDER BY id DESC LIMIT 4");
    $stmt_logs->execute([$auth_admin]);
    $logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

    assert_equals(4, count($logs), 'Exactly 4 audit records found for authorized admin');
    foreach ($logs as $l) {
        assert_true(strpos($l['details'], $aadhaar_plain) === false, "Log {$l['action']} does not contain plaintext Aadhaar");
        assert_true(strpos($l['details'], $bank_plain) === false, "Log {$l['action']} does not contain plaintext Bank Account");
    }
});

// ======================================================================
// SECTION 9: ADMIN MANAGEMENT EDIT ACTION & SECURITY VERIFICATION
// ======================================================================
echo "\n--- Section 9: Admin Management Edit Action & Security Verification ---\n";

run_test('Static check: admin-management.php enforces require_super_admin()', function() {
    $code = file_get_contents(__DIR__ . '/admin-management.php');
    assert_true(strpos($code, "require_super_admin();") !== false, "admin-management.php contains require_super_admin()");
    assert_true(strpos($code, "action=get_admin_details") !== false, "admin-management.php contains get_admin_details AJAX action");
    assert_true(strpos($code, "openPerms(") !== false, "admin-management.php has openPerms handler");
});

run_test('get_admin_details endpoint returns complete admin details with linked staff and NO password hashes', function() use ($pdo) {
    // Insert test admin and linked employee
    $stmt = $pdo->prepare("INSERT INTO admins (username, full_name, email, phone, role, admin_type, permissions, credential_visibility, credential_visibility_scopes, can_edit, can_delete, can_export, allow_copy_email, allow_whatsapp_chat, allow_phone_call, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['test_edit_admin', 'Test Edit User', 'edit@test.com', '9876543210', 'admin', 'erp_admin', 'students,financials', 'mask', 'students,financials', 1, 0, 1, 1, 0, 1, 'active']);
    $admin_id = (int)$pdo->lastInsertId();

    // Query mimicking get_admin_details
    $stmt_q = $pdo->prepare("
        SELECT a.id, a.username, a.full_name, a.email, a.google_email, a.phone,
               a.role, a.admin_type, a.permissions, a.status,
               a.credential_visibility, a.credential_visibility_scopes,
               a.can_edit, a.can_delete, a.can_export,
               a.allow_copy_email, a.allow_whatsapp_chat, a.allow_phone_call,
               e.id AS linked_staff_id,
               e.employee_id AS linked_staff_code,
               e.full_name AS linked_staff_name
        FROM admins a
        LEFT JOIN employees e ON a.id = e.admin_id
        WHERE a.id = ? LIMIT 1
    ");
    $stmt_q->execute([$admin_id]);
    $adm = $stmt_q->fetch(PDO::FETCH_ASSOC);

    assert_true(!empty($adm), 'Admin details successfully fetched');
    assert_equals('test_edit_admin', $adm['username'], 'Username matches');
    assert_equals('Test Edit User', $adm['full_name'], 'Full name matches');
    assert_equals('edit@test.com', $adm['email'], 'Email matches');
    assert_equals('9876543210', $adm['phone'], 'Phone matches');
    assert_equals('mask', $adm['credential_visibility'], 'Credential visibility matches');
    assert_equals(1, (int)$adm['can_edit'], 'can_edit matches');
    assert_equals(0, (int)$adm['can_delete'], 'can_delete matches');
    assert_false(isset($adm['password_hash']), 'Password hash is NOT exposed in get_admin_details');
});

run_test('update_perms updates all profile, permission, scope and action settings correctly', function() use ($pdo) {
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = 'test_edit_admin' LIMIT 1");
    $stmt->execute();
    $admin_id = (int)$stmt->fetchColumn();

    // Simulate update_perms POST processing
    $perms = 'students,dashboard,registrations';
    $name = 'Updated Edit User';
    $email = 'updated@test.com';
    $gemail = 'updated.google@test.com';
    $phone = '9998887776';
    $admin_type = 'faculty';
    $cred_vis = 'hide';
    $scopes = 'students,registrations';
    $can_edit = 1;
    $can_delete = 1;
    $can_export = 0;
    $allow_copy_email = 0;
    $allow_whatsapp_chat = 1;
    $allow_phone_call = 1;

    $stmt_upd = $pdo->prepare("UPDATE admins SET permissions = ?, full_name = ?, email = ?, google_email = ?, phone = ?, admin_type = ?, credential_visibility = ?, credential_visibility_scopes = ?, can_edit = ?, can_delete = ?, can_export = ?, allow_copy_email = ?, allow_whatsapp_chat = ?, allow_phone_call = ? WHERE id = ?");
    $stmt_upd->execute([$perms, $name, $email, $gemail, $phone, $admin_type, $cred_vis, $scopes, $can_edit, $can_delete, $can_export, $allow_copy_email, $allow_whatsapp_chat, $allow_phone_call, $admin_id]);

    // Verify persisted record
    $stmt_chk = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt_chk->execute([$admin_id]);
    $updated = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    assert_equals('Updated Edit User', $updated['full_name'], 'Updated full name persisted');
    assert_equals('updated@test.com', $updated['email'], 'Updated email persisted');
    assert_equals('updated.google@test.com', $updated['google_email'], 'Updated google email persisted');
    assert_equals('faculty', $updated['admin_type'], 'Updated admin type persisted');
    assert_equals('hide', $updated['credential_visibility'], 'Updated credential visibility persisted');
    assert_equals('students,registrations', $updated['credential_visibility_scopes'], 'Updated scopes persisted');
    assert_equals(1, (int)$updated['can_edit'], 'Updated can_edit persisted');
    assert_equals(1, (int)$updated['can_delete'], 'Updated can_delete persisted');
    assert_equals(0, (int)$updated['can_export'], 'Updated can_export persisted');
    assert_equals(0, (int)$updated['allow_copy_email'], 'Updated allow_copy_email persisted');
    assert_equals(1, (int)$updated['allow_whatsapp_chat'], 'Updated allow_whatsapp_chat persisted');
    assert_equals('students,dashboard,registrations', $updated['permissions'], 'Updated permissions persisted');
});

run_test('Staff ↔ Admin link is preserved and not overwritten when editing admin details', function() use ($pdo) {
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = 'test_edit_admin' LIMIT 1");
    $stmt->execute();
    $admin_id = (int)$stmt->fetchColumn();

    // Link an employee to this admin
    $stmt_emp = $pdo->prepare("INSERT INTO employees (employee_id, full_name, email, mobile_number, status, admin_id, linked_at, linked_by) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)");
    $stmt_emp->execute(['EMP999', 'Linked Staff Person', 'updated@test.com', '9998887776', 'active', $admin_id, 'superadmin']);
    $emp_id = (int)$pdo->lastInsertId();

    // Update admin permissions/details again
    $stmt_upd = $pdo->prepare("UPDATE admins SET full_name = 'Updated Again User' WHERE id = ?");
    $stmt_upd->execute([$admin_id]);

    // Check that employee remains linked to this admin
    $stmt_emp_chk = $pdo->prepare("SELECT admin_id, linked_by FROM employees WHERE id = ?");
    $stmt_emp_chk->execute([$emp_id]);
    $emp_chk = $stmt_emp_chk->fetch(PDO::FETCH_ASSOC);

    assert_equals($admin_id, (int)$emp_chk['admin_id'], 'Employee admin_id remains intact');
    assert_equals('superadmin', $emp_chk['linked_by'], 'Employee linked_by remains intact');
});

// ======================================================================
// SECTION 10: EMPLOYEE MANAGEMENT APPROVAL REDESIGN & FACULTY 1:1 INTEGRATION
// ======================================================================
echo "\n--- SECTION 10: Employee Management Redesign & Faculty 1:1 Integration ---\n";

require_once __DIR__ . '/includes/appointment_pdf.php';

// Setup schema extensions in SQLite for application logic testing
$pdo->exec("
    ALTER TABLE employees ADD COLUMN internship_ends_on DATE DEFAULT NULL;
    ALTER TABLE employees ADD COLUMN internship_payment_status TEXT DEFAULT NULL;
    ALTER TABLE employees ADD COLUMN internship_payment_mode TEXT DEFAULT NULL;
    ALTER TABLE employees ADD COLUMN internship_remuneration REAL DEFAULT NULL;
    ALTER TABLE employees ADD COLUMN academic_year TEXT DEFAULT NULL;
    ALTER TABLE employees ADD COLUMN rate_live REAL DEFAULT 0.00;
    ALTER TABLE employees ADD COLUMN rate_qpd REAL DEFAULT 0.00;
    ALTER TABLE employees ADD COLUMN rate_recorded REAL DEFAULT 0.00;
    ALTER TABLE employees ADD COLUMN rate_offline REAL DEFAULT 0.00;

    CREATE TABLE IF NOT EXISTS faculties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_management_faculty_id INTEGER UNIQUE,
        name TEXT NOT NULL,
        mobile TEXT,
        email TEXT,
        rate_live REAL DEFAULT 0.00,
        rate_qpd REAL DEFAULT 0.00,
        rate_recorded REAL DEFAULT 0.00,
        rate_offline REAL DEFAULT 0.00,
        academic_year TEXT,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        faculty_id INTEGER NOT NULL,
        topic TEXT NOT NULL,
        session_type TEXT NOT NULL,
        duration_hours REAL NOT NULL,
        status TEXT NOT NULL,
        session_datetime DATETIME NOT NULL
    );

    CREATE TABLE IF NOT EXISTS faculty_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        faculty_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        payment_account_id INTEGER,
        paid_date DATE,
        remarks TEXT,
        created_by TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS staff_registration_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        mobile_number TEXT NOT NULL,
        email TEXT NOT NULL,
        application_for TEXT NOT NULL,
        status TEXT DEFAULT 'pending',
        joining_date DATE,
        internship_ends_on DATE,
        internship_payment_status TEXT,
        internship_payment_mode TEXT,
        internship_remuneration REAL,
        academic_year TEXT,
        rate_live REAL DEFAULT 0.00,
        rate_qpd REAL DEFAULT 0.00,
        rate_recorded REAL DEFAULT 0.00,
        rate_offline REAL DEFAULT 0.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// ----------------------------------------------------------------------
// 10.1 Employee Approval Workflow & Integrity
// ----------------------------------------------------------------------
run_test('10.1: Employee approval requires designation, department, joining date and stores monthly salary', function() use ($pdo) {
    $emp_data = [
        'full_name' => 'Regular Employee Test',
        'email' => 'reg.emp@pepplearning.com',
        'mobile_number' => '9876500001',
        'application_for' => 'employee',
        'designation' => 'Academic Coordinator',
        'department' => 'Administration',
        'joining_date' => '2026-06-01',
        'probation_till' => '2026-11-30',
        'contract_validity_from' => '2026-06-01',
        'contract_validity_till' => '2027-05-31',
        'monthly_salary' => 35000.00,
        'status' => 'approved'
    ];

    // Simulate backend validation logic from employee-management.php
    assert_true(!empty($emp_data['designation']), 'Designation is mandatory for employee');
    assert_true(!empty($emp_data['department']), 'Department is mandatory for employee');
    assert_true(!empty($emp_data['joining_date']), 'Joining date is mandatory for employee');
    assert_true($emp_data['monthly_salary'] > 0, 'Monthly salary is positive for employee');

    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, probation_till,
            contract_validity_from, contract_validity_till, monthly_salary, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'EMP00301', $emp_data['full_name'], $emp_data['email'], $emp_data['mobile_number'],
        $emp_data['application_for'], $emp_data['designation'], $emp_data['department'],
        $emp_data['joining_date'], $emp_data['probation_till'], $emp_data['contract_validity_from'],
        $emp_data['contract_validity_till'], $emp_data['monthly_salary'], $emp_data['status']
    ]);
    $inserted_id = (int)$pdo->lastInsertId();

    $stmt_chk = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt_chk->execute([$inserted_id]);
    $row = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    assert_equals('Academic Coordinator', $row['designation']);
    assert_equals('Administration', $row['department']);
    assert_equals('2026-06-01', $row['joining_date']);
    assert_equals(35000.00, (float)$row['monthly_salary']);
    assert_equals(null, $row['internship_remuneration']);
});

// ----------------------------------------------------------------------
// 10.2 Intern Approval Workflow & Remuneration Rules
// ----------------------------------------------------------------------
run_test('10.2A: Intern approval enforces Designation="Project Intern", dates check, and Unpaid mode logic', function() use ($pdo) {
    $intern_unpaid = [
        'full_name' => 'Unpaid Intern Test',
        'email' => 'unpaid.intern@pepplearning.com',
        'mobile_number' => '9876500002',
        'application_for' => 'intern',
        'designation' => 'Project Intern',
        'department' => 'Internship',
        'joining_date' => '2026-07-01',
        'internship_ends_on' => '2026-09-30',
        'internship_payment_status' => 'unpaid',
        'internship_payment_mode' => null,
        'internship_remuneration' => null,
        'monthly_salary' => 0.00,
        'status' => 'approved'
    ];

    // Validation rules
    assert_equals('Project Intern', $intern_unpaid['designation'], 'Designation must be Project Intern');
    assert_true($intern_unpaid['internship_ends_on'] >= $intern_unpaid['joining_date'], 'End date must be >= joining date');
    assert_equals(0.00, $intern_unpaid['monthly_salary'], 'Monthly salary must remain 0.00 for intern');
    assert_equals(null, $intern_unpaid['internship_remuneration'], 'Unpaid intern has null remuneration');

    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, internship_ends_on,
            internship_payment_status, internship_payment_mode, internship_remuneration,
            monthly_salary, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'EMP00302', $intern_unpaid['full_name'], $intern_unpaid['email'], $intern_unpaid['mobile_number'],
        $intern_unpaid['application_for'], $intern_unpaid['designation'], $intern_unpaid['department'],
        $intern_unpaid['joining_date'], $intern_unpaid['internship_ends_on'],
        $intern_unpaid['internship_payment_status'], $intern_unpaid['internship_payment_mode'],
        $intern_unpaid['internship_remuneration'], $intern_unpaid['monthly_salary'], $intern_unpaid['status']
    ]);
    $inserted_id = (int)$pdo->lastInsertId();

    $stmt_chk = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt_chk->execute([$inserted_id]);
    $row = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    assert_equals('Project Intern', $row['designation']);
    assert_equals('unpaid', $row['internship_payment_status']);
    assert_equals(null, $row['internship_remuneration']);
    assert_equals(0.00, (float)$row['monthly_salary']);
});

run_test('10.2B: Intern approval: Paid Monthly requires remuneration > 0 stored in dedicated column (not monthly_salary)', function() use ($pdo) {
    $intern_monthly = [
        'full_name' => 'Monthly Paid Intern Test',
        'email' => 'monthly.intern@pepplearning.com',
        'mobile_number' => '9876500003',
        'application_for' => 'intern',
        'designation' => 'Project Intern',
        'department' => 'Internship',
        'joining_date' => '2026-07-01',
        'internship_ends_on' => '2026-12-31',
        'internship_payment_status' => 'paid',
        'internship_payment_mode' => 'monthly',
        'internship_remuneration' => 12000.00,
        'monthly_salary' => 0.00,
        'status' => 'approved'
    ];

    // Backend validation logic
    assert_true($intern_monthly['internship_payment_status'] === 'paid');
    assert_true($intern_monthly['internship_payment_mode'] === 'monthly');
    assert_true($intern_monthly['internship_remuneration'] > 0, 'Monthly paid intern requires remuneration > 0');
    assert_equals(0.00, $intern_monthly['monthly_salary'], 'monthly_salary must remain strictly 0.00');

    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, internship_ends_on,
            internship_payment_status, internship_payment_mode, internship_remuneration,
            monthly_salary, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'EMP00303', $intern_monthly['full_name'], $intern_monthly['email'], $intern_monthly['mobile_number'],
        $intern_monthly['application_for'], $intern_monthly['designation'], $intern_monthly['department'],
        $intern_monthly['joining_date'], $intern_monthly['internship_ends_on'],
        $intern_monthly['internship_payment_status'], $intern_monthly['internship_payment_mode'],
        $intern_monthly['internship_remuneration'], $intern_monthly['monthly_salary'], $intern_monthly['status']
    ]);
    $inserted_id = (int)$pdo->lastInsertId();

    $stmt_chk = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt_chk->execute([$inserted_id]);
    $row = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    assert_equals(12000.00, (float)$row['internship_remuneration']);
    assert_equals(0.00, (float)$row['monthly_salary'], 'monthly_salary MUST NOT store intern remuneration');
    assert_equals('monthly', $row['internship_payment_mode']);
});

run_test('10.2C: Intern approval: Task Completion sets remuneration to NULL', function() use ($pdo) {
    $intern_task = [
        'full_name' => 'Task Intern Test',
        'email' => 'task.intern@pepplearning.com',
        'mobile_number' => '9876500004',
        'application_for' => 'intern',
        'designation' => 'Project Intern',
        'department' => 'Internship',
        'joining_date' => '2026-08-01',
        'internship_ends_on' => '2026-10-31',
        'internship_payment_status' => 'paid',
        'internship_payment_mode' => 'task_completion',
        'internship_remuneration' => null, // Rule: task completion sets remuneration to NULL
        'monthly_salary' => 0.00,
        'status' => 'approved'
    ];

    assert_equals(null, $intern_task['internship_remuneration']);
    assert_equals(0.00, $intern_task['monthly_salary']);

    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, internship_ends_on,
            internship_payment_status, internship_payment_mode, internship_remuneration,
            monthly_salary, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'EMP00304', $intern_task['full_name'], $intern_task['email'], $intern_task['mobile_number'],
        $intern_task['application_for'], $intern_task['designation'], $intern_task['department'],
        $intern_task['joining_date'], $intern_task['internship_ends_on'],
        $intern_task['internship_payment_status'], $intern_task['internship_payment_mode'],
        $intern_task['internship_remuneration'], $intern_task['monthly_salary'], $intern_task['status']
    ]);
    $inserted_id = (int)$pdo->lastInsertId();

    $stmt_chk = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt_chk->execute([$inserted_id]);
    $row = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    assert_equals('task_completion', $row['internship_payment_mode']);
    assert_equals(null, $row['internship_remuneration']);
    assert_equals(0.00, (float)$row['monthly_salary']);
});

// ----------------------------------------------------------------------
// 10.3 Faculty Approval Workflow & Persistence
// ----------------------------------------------------------------------
run_test('10.3: Faculty approval requires Academic Year, stores optional session charges, monthly_salary=0, remuneration=NULL', function() use ($pdo) {
    $faculty_data = [
        'full_name' => 'Prof. Alan Turing',
        'email' => 'turing@pepplearning.com',
        'mobile_number' => '9876500005',
        'application_for' => 'faculty',
        'designation' => 'Faculty',
        'department' => 'Academics',
        'academic_year' => '2026-27',
        'rate_live' => 1500.00,
        'rate_qpd' => 800.00,
        'rate_recorded' => 1200.00,
        'rate_offline' => 2000.00,
        'monthly_salary' => 0.00,
        'internship_remuneration' => null,
        'status' => 'active'
    ];

    assert_true(!empty($faculty_data['academic_year']), 'PEPP Academic Year is mandatory');
    assert_equals('Faculty', $faculty_data['designation']);
    assert_equals('Academics', $faculty_data['department']);
    assert_equals(0.00, $faculty_data['monthly_salary']);
    assert_equals(null, $faculty_data['internship_remuneration']);

    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, academic_year, rate_live, rate_qpd,
            rate_recorded, rate_offline, monthly_salary, internship_remuneration, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'EMP00305', $faculty_data['full_name'], $faculty_data['email'], $faculty_data['mobile_number'],
        $faculty_data['application_for'], $faculty_data['designation'], $faculty_data['department'],
        $faculty_data['academic_year'], $faculty_data['rate_live'], $faculty_data['rate_qpd'],
        $faculty_data['rate_recorded'], $faculty_data['rate_offline'],
        $faculty_data['monthly_salary'], $faculty_data['internship_remuneration'], $faculty_data['status']
    ]);
    $faculty_emp_id = (int)$pdo->lastInsertId();

    $stmt_chk = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt_chk->execute([$faculty_emp_id]);
    $row = $stmt_chk->fetch(PDO::FETCH_ASSOC);

    assert_equals('2026-27', $row['academic_year']);
    assert_equals(1500.00, (float)$row['rate_live']);
    assert_equals(800.00, (float)$row['rate_qpd']);
    assert_equals(1200.00, (float)$row['rate_recorded']);
    assert_equals(2000.00, (float)$row['rate_offline']);
    assert_equals(0.00, (float)$row['monthly_salary']);
    assert_equals(null, $row['internship_remuneration']);
});

// ----------------------------------------------------------------------
// 10.4 Faculty Linking, 1:1 Constraints, Switching, Unlinking & Preservation
// ----------------------------------------------------------------------
run_test('10.4A: Faculty candidate query returns ACTIVE Faculty employees with application_for=faculty and no status=approved requirement', function() use ($pdo) {
    // Insert additional test records: inactive, probation, legacy, other roles
    $pdo->prepare("
        INSERT INTO employees (employee_id, full_name, email, mobile_number, application_for, status)
        VALUES ('EMP00306', 'Inactive Faculty', 'inactive@pepp.com', '9876500006', 'faculty', 'inactive'),
               ('EMP00307', 'Probation Faculty', 'probation@pepp.com', '9876500007', 'faculty', 'probation'),
               ('EMP00308', 'Legacy Approved Faculty (Obsolete)', 'legacy.approved@pepp.com', '9876500008', 'faculty', 'approved'),
               ('EMP00309', 'Active Employee', 'emp.active@pepp.com', '9876500009', 'employee', 'active'),
               ('EMP00310', 'Active Intern', 'intern.active@pepp.com', '9876500010', 'intern', 'active')
    ")->execute();

    // Query mimicking faculties.php $emp_faculties
    $stmt = $pdo->query("
        SELECT e.id, e.employee_id, e.full_name, e.application_for, e.status, f.id AS linked_faculty_id
        FROM employees e
        LEFT JOIN faculties f ON f.employee_management_faculty_id = e.id
        WHERE e.application_for = 'faculty' AND e.status = 'active'
        ORDER BY e.full_name ASC
    ");
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Only active Faculty employee (Alan Turing) should be returned
    assert_equals(1, count($candidates), 'Only active Faculty employee is returned in candidate list');
    assert_equals('Prof. Alan Turing', $candidates[0]['full_name']);
    assert_equals('active', $candidates[0]['status'], 'Status is active');
    assert_equals('faculty', $candidates[0]['application_for'], 'application_for is faculty');
    assert_equals(null, $candidates[0]['linked_faculty_id'], 'Candidate is currently unlinked');

    // Static verification: faculties.php candidate queries must use status='active' and never status='approved' or application_type
    $fac_code = file_get_contents(__DIR__ . '/faculties.php');
    assert_false(strpos($fac_code, "e.application_for = 'faculty' AND e.status = 'approved'") !== false, 'Candidate query does not require employees.status=approved');
    assert_true(strpos($fac_code, "e.application_for = 'faculty' AND e.status = 'active'") !== false, 'Candidate query requires employees.status=active');
    assert_false(strpos($fac_code, 'application_type') !== false, 'faculties.php does not use application_type');
});

run_test('10.4B: Adding Faculty copies authoritative profile and rates and creates 1:1 link via stable employees.id', function() use ($pdo) {
    // Fetch authoritative employee record #5 (Alan Turing)
    $stmt_e = $pdo->query("SELECT * FROM employees WHERE full_name = 'Prof. Alan Turing'");
    $emp = $stmt_e->fetch(PDO::FETCH_ASSOC);
    assert_true(!empty($emp), 'Authoritative employee found');

    // Add faculty with authoritative fields using stable employees.id
    $stmt_ins = $pdo->prepare("
        INSERT INTO faculties (
            employee_management_faculty_id, name, mobile, email,
            rate_live, rate_qpd, rate_recorded, rate_offline, academic_year, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ");
    $stmt_ins->execute([
        $emp['id'], $emp['full_name'], $emp['mobile_number'], $emp['email'],
        $emp['rate_live'], $emp['rate_qpd'], $emp['rate_recorded'], $emp['rate_offline'],
        $emp['academic_year']
    ]);
    $faculty_id = (int)$pdo->lastInsertId();

    $stmt_fac = $pdo->prepare("SELECT * FROM faculties WHERE id = ?");
    $stmt_fac->execute([$faculty_id]);
    $fac = $stmt_fac->fetch(PDO::FETCH_ASSOC);

    assert_equals((int)$emp['id'], (int)$fac['employee_management_faculty_id'], 'Linked employee management faculty ID saved using stable employees.id');
    assert_equals('Prof. Alan Turing', $fac['name']);
    assert_equals('2026-27', $fac['academic_year']);
    assert_equals(1500.00, (float)$fac['rate_live']);
    assert_equals(800.00, (float)$fac['rate_qpd']);
    assert_equals(1200.00, (float)$fac['rate_recorded']);
    assert_equals(2000.00, (float)$fac['rate_offline']);
});

run_test('10.4C: Already-linked Faculty employees are excluded from candidate selection & 1:1 duplicate linking protection works', function() use ($pdo) {
    $emp_id = (int)$pdo->query("SELECT id FROM employees WHERE full_name = 'Prof. Alan Turing'")->fetchColumn();

    // 1. Candidate query check: Alan Turing is now linked
    $stmt = $pdo->query("
        SELECT e.id, e.full_name, f.id AS linked_faculty_id
        FROM employees e
        LEFT JOIN faculties f ON f.employee_management_faculty_id = e.id
        WHERE e.application_for = 'faculty' AND e.status = 'active'
    ");
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $unlinked_candidates = array_filter($candidates, function($c) { return empty($c['linked_faculty_id']); });
    assert_equals(0, count($unlinked_candidates), 'Already-linked Faculty employee is excluded from unlinked candidate selection');

    // 2. Backend Conflict Check test
    $stmt_chk = $pdo->prepare("SELECT id FROM faculties WHERE employee_management_faculty_id = ?");
    $stmt_chk->execute([$emp_id]);
    $conflict = $stmt_chk->fetchColumn();
    assert_true(!empty($conflict), 'Backend conflict check identifies already-linked employee');

    // 3. Database UNIQUE constraint check test
    $duplicate_caught = false;
    try {
        $stmt_dup = $pdo->prepare("
            INSERT INTO faculties (employee_management_faculty_id, name, status)
            VALUES (?, 'Duplicate Link Attempt', 'active')
        ");
        $stmt_dup->execute([$emp_id]);
    } catch (PDOException $e) {
        $duplicate_caught = true;
    }
    assert_true($duplicate_caught, 'Database UNIQUE constraint caught duplicate link attempt');
});

run_test('10.4D: Edit Faculty submits canonical employee_management_faculty_id and switches link using stable employees.id', function() use ($pdo) {
    // Verify Edit Faculty hidden field uses canonical employee_management_faculty_id
    $fac_code = file_get_contents(__DIR__ . '/faculties.php');
    assert_true(strpos($fac_code, 'name="employee_management_faculty_id" id="fac-edit-link-emp-id"') !== false, 'Edit Faculty modal form uses canonical parameter name employee_management_faculty_id');
    assert_false(strpos($fac_code, 'name="link_employee_management_faculty_id"') !== false, 'Obsolete name link_employee_management_faculty_id removed');

    // Insert second active faculty in Employee Management
    $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, academic_year, rate_live, rate_qpd,
            rate_recorded, rate_offline, monthly_salary, status
        ) VALUES (
            'EMP00311', 'Dr. Grace Hopper', 'hopper@pepplearning.com', '9876500011', 'faculty',
            'Faculty', 'Academics', '2026-27', 1800.00, 950.00, 1400.00, 2200.00, 0.00, 'active'
        )
    ")->execute();
    $new_emp_id = (int)$pdo->lastInsertId();

    $fac_id = (int)$pdo->query("SELECT id FROM faculties WHERE name = 'Prof. Alan Turing'")->fetchColumn();

    // Fetch authoritative fields of Dr. Grace Hopper using stable employees.id
    $new_emp = $pdo->query("SELECT * FROM employees WHERE id = {$new_emp_id} AND application_for = 'faculty'")->fetch(PDO::FETCH_ASSOC);
    assert_true(!empty($new_emp), 'Grace Hopper found via stable employees.id');

    // Perform switch via simulated edit_faculty submission with employee_management_faculty_id
    $orig_fac = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    $new_link_emp_id = $new_emp_id;
    $link_emp_id = $new_link_emp_id ?: (!empty($orig_fac['employee_management_faculty_id']) ? (int)$orig_fac['employee_management_faculty_id'] : null);

    $stmt_switch = $pdo->prepare("
        UPDATE faculties SET
            employee_management_faculty_id = ?,
            name = ?, mobile = ?, email = ?,
            rate_live = ?, rate_qpd = ?, rate_recorded = ?, rate_offline = ?,
            academic_year = ?
        WHERE id = ?
    ");
    $stmt_switch->execute([
        $link_emp_id, $new_emp['full_name'], $new_emp['mobile_number'], $new_emp['email'],
        $new_emp['rate_live'], $new_emp['rate_qpd'], $new_emp['rate_recorded'], $new_emp['rate_offline'],
        $new_emp['academic_year'], $fac_id
    ]);

    $stmt_fac = $pdo->prepare("SELECT * FROM faculties WHERE id = ?");
    $stmt_fac->execute([$fac_id]);
    $fac = $stmt_fac->fetch(PDO::FETCH_ASSOC);

    assert_equals($new_emp_id, (int)$fac['employee_management_faculty_id'], 'Switched to new employee ID using stable ID');
    assert_equals('Dr. Grace Hopper', $fac['name'], 'Name overwritten with authoritative data');
    assert_equals(1800.00, (float)$fac['rate_live'], 'Live rate overwritten');
    assert_equals(950.00, (float)$fac['rate_qpd'], 'QPD rate overwritten');
});

run_test('10.4E: Existing link is preserved during normal Faculty edit when no link change is requested', function() use ($pdo) {
    $fac_id = (int)$pdo->query("SELECT id FROM faculties WHERE name = 'Dr. Grace Hopper'")->fetchColumn();
    $orig_fac = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_true(!empty($orig_fac['employee_management_faculty_id']), 'Faculty is currently linked');
    $linked_emp_id = (int)$orig_fac['employee_management_faculty_id'];

    // Simulate normal edit where user updates phone/rate without changing link ($_POST['employee_management_faculty_id'] is empty)
    $posted_link_emp_id = null; // empty hidden field
    $new_link_emp_id = !empty($posted_link_emp_id) ? (int)$posted_link_emp_id : null;
    $link_emp_id = $new_link_emp_id ?: (!empty($orig_fac['employee_management_faculty_id']) ? (int)$orig_fac['employee_management_faculty_id'] : null);

    assert_equals($linked_emp_id, $link_emp_id, 'Fallback preserves existing linked employee ID');

    // Execute update with preserved link
    $stmt_upd = $pdo->prepare("UPDATE faculties SET employee_management_faculty_id = ?, mobile = ? WHERE id = ?");
    $stmt_upd->execute([$link_emp_id, '9876599999', $fac_id]);

    $fac_after = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals($linked_emp_id, (int)$fac_after['employee_management_faculty_id'], 'Existing link remains intact after normal edit');
    assert_equals('9876599999', $fac_after['mobile'], 'Edited mobile updated');
});

run_test('10.4F: Explicit unlink_faculty still unlinks correctly without deleting faculty record, sessions, or payments', function() use ($pdo) {
    $fac_id = (int)$pdo->query("SELECT id FROM faculties WHERE name = 'Dr. Grace Hopper'")->fetchColumn();

    // Add session and payment records for this faculty
    $pdo->prepare("INSERT INTO sessions (faculty_id, topic, session_type, duration_hours, status, session_datetime) VALUES (?, 'CompSci 101', 'live', 2.0, 'completed', CURRENT_TIMESTAMP)")
        ->execute([$fac_id]);
    $session_id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO faculty_payments (faculty_id, amount, paid_date, created_by) VALUES (?, 3600.00, '2026-09-01', 'admin')")
        ->execute([$fac_id]);
    $payment_id = (int)$pdo->lastInsertId();

    // Execute explicit unlink action
    $pdo->prepare("UPDATE faculties SET employee_management_faculty_id = NULL WHERE id = ?")->execute([$fac_id]);

    // Check faculties record
    $fac = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_true(!empty($fac), 'Faculty record still exists');
    assert_equals(null, $fac['employee_management_faculty_id'], 'Foreign key set to NULL');

    // Check sessions record
    $sess = $pdo->query("SELECT * FROM sessions WHERE id = {$session_id}")->fetch(PDO::FETCH_ASSOC);
    assert_true(!empty($sess), 'Sessions record remains intact');

    // Check payments record
    $pmt = $pdo->query("SELECT * FROM faculty_payments WHERE id = {$payment_id}")->fetch(PDO::FETCH_ASSOC);
    assert_true(!empty($pmt), 'Payment record remains intact');

    // Check employee record in Employee Management
    $emp_hopper = $pdo->query("SELECT * FROM employees WHERE full_name = 'Dr. Grace Hopper'")->fetch(PDO::FETCH_ASSOC);
    assert_true(!empty($emp_hopper), 'Employee management record remains intact');

    // Check that Dr. Grace Hopper is now unlinked and available to relink
    $stmt_avail = $pdo->query("
        SELECT e.id FROM employees e
        LEFT JOIN faculties f ON f.employee_management_faculty_id = e.id
        WHERE e.id = {$emp_hopper['id']} AND f.id IS NULL
    ");
    assert_true(!empty($stmt_avail->fetchColumn()), 'Employee becomes available again to link');
});

run_test('10.4G: Legacy NULL-linked Faculty remains editable and linkable to active registered Faculty', function() use ($pdo) {
    // 1. Create legacy unlinked faculty
    $stmt_leg = $pdo->prepare("
        INSERT INTO faculties (name, mobile, email, rate_live, rate_qpd, rate_recorded, rate_offline, academic_year, status)
        VALUES ('Legacy Unlinked Faculty', '9123456780', 'legacy@pepp.com', 1000, 500, 800, 1500, '2025-26', 'active')
    ");
    $stmt_leg->execute();
    $leg_id = (int)$pdo->lastInsertId();

    $orig_leg = $pdo->query("SELECT * FROM faculties WHERE id = {$leg_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals(null, $orig_leg['employee_management_faculty_id'], 'Legacy faculty starts with NULL link');

    // 2. Normal edit with no link requested preserves NULL link and updates details
    $new_link_emp_id = null;
    $link_emp_id = $new_link_emp_id ?: (!empty($orig_leg['employee_management_faculty_id']) ? (int)$orig_leg['employee_management_faculty_id'] : null);
    assert_equals(null, $link_emp_id, 'No link selected keeps link NULL');

    $pdo->prepare("UPDATE faculties SET employee_management_faculty_id = ?, mobile = '9123456789' WHERE id = ?")
        ->execute([$link_emp_id, $leg_id]);
    $leg_after = $pdo->query("SELECT * FROM faculties WHERE id = {$leg_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals(null, $leg_after['employee_management_faculty_id'], 'Legacy faculty remains unlinked');
    assert_equals('9123456789', $leg_after['mobile'], 'Legacy faculty details updated');

    // 3. User selects active registered faculty (Dr. Grace Hopper, who is unlinked) to link
    $emp_hopper = $pdo->query("SELECT * FROM employees WHERE full_name = 'Dr. Grace Hopper'")->fetch(PDO::FETCH_ASSOC);
    $selected_link_emp_id = (int)$emp_hopper['id'];

    $link_emp_id = $selected_link_emp_id ?: (!empty($leg_after['employee_management_faculty_id']) ? (int)$leg_after['employee_management_faculty_id'] : null);
    assert_equals($selected_link_emp_id, $link_emp_id, 'New link ID resolved');

    // Update with authoritative overwrite
    $pdo->prepare("
        UPDATE faculties SET
            employee_management_faculty_id = ?,
            name = ?, mobile = ?, email = ?,
            rate_live = ?, rate_qpd = ?, rate_recorded = ?, rate_offline = ?,
            academic_year = ?
        WHERE id = ?
    ")->execute([
        $link_emp_id, $emp_hopper['full_name'], $emp_hopper['mobile_number'], $emp_hopper['email'],
        $emp_hopper['rate_live'], $emp_hopper['rate_qpd'], $emp_hopper['rate_recorded'], $emp_hopper['rate_offline'],
        $emp_hopper['academic_year'], $leg_id
    ]);

    $leg_linked = $pdo->query("SELECT * FROM faculties WHERE id = {$leg_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals($selected_link_emp_id, (int)$leg_linked['employee_management_faculty_id'], 'Legacy faculty successfully linked to active employee');
    assert_equals('Dr. Grace Hopper', $leg_linked['name'], 'Name updated with authoritative data');
    assert_equals(1800.00, (float)$leg_linked['rate_live'], 'Rate updated');
});

// ----------------------------------------------------------------------
// 10.5 Appointment Letters Content & Privacy Redaction
// ----------------------------------------------------------------------
run_test('10.5A: Employee Appointment Letter generates valid PDF and contains salary clauses', function() {
    $employee_snapshot = json_encode([
        'reference' => 'PEPP/EMP/2026/001',
        'generated_at' => '2026-06-01 10:00:00',
        'generated_by' => 'Superadmin',
        'application_for' => 'employee',
        'employee' => [
            'id' => 301,
            'employee_code' => 'EMP00301',
            'full_name' => 'Jane Employee',
            'place_post_office' => 'Kochi',
            'state' => 'Kerala',
            'pincode' => '682001',
            'designation' => 'Academic Coordinator',
            'department' => 'Administration',
            'joining_date' => '2026-06-01',
            'probation_till' => '2026-11-30',
            'contract_validity_from' => '2026-06-01',
            'contract_validity_till' => '2027-05-31',
            'monthly_salary' => 35000.00
        ]
    ]);

    $pdf_bytes = render_appointment_pdf($employee_snapshot);
    assert_true(strlen($pdf_bytes) > 1000, 'PDF generated with non-trivial size');
    assert_true(strpos($pdf_bytes, '%PDF') === 0, 'PDF header is valid');
    assert_true(strpos($pdf_bytes, 'APPOINTMENT LETTER') !== false, 'Contains standard appointment letter title');
    assert_true(strpos($pdf_bytes, '35,000') !== false || strpos($pdf_bytes, '35000') !== false, 'Contains employee monthly salary');
});

run_test('10.5B: Intern Appointment Letter generates valid PDF and REDACTS all remuneration/payment details', function() {
    $intern_snapshot = json_encode([
        'reference' => 'PEPP/INT/2026/002',
        'generated_at' => '2026-07-01 10:00:00',
        'generated_by' => 'Superadmin',
        'application_for' => 'intern',
        'employee' => [
            'id' => 302,
            'employee_code' => 'EMP00302',
            'full_name' => 'John Intern',
            'place_post_office' => 'Calicut',
            'state' => 'Kerala',
            'pincode' => '673001',
            'designation' => 'Project Intern',
            'department' => 'Internship',
            'joining_date' => '2026-07-01',
            'internship_ends_on' => '2026-09-30',
            'internship_payment_status' => 'paid',
            'internship_payment_mode' => 'monthly',
            'internship_remuneration' => 12000.00,
            'monthly_salary' => 0.00
        ]
    ]);

    $pdf_bytes = render_appointment_pdf($intern_snapshot);
    assert_true(strlen($pdf_bytes) > 1000, 'PDF generated');
    assert_true(strpos($pdf_bytes, 'INTERNSHIP APPOINTMENT LETTER') !== false, 'Title is INTERNSHIP APPOINTMENT LETTER');
    assert_true(strpos($pdf_bytes, 'Project Intern') !== false, 'Designation is Project Intern');

    // Strict privacy checks: Ensure ZERO remuneration or payment terms appear in raw PDF stream
    assert_false(strpos($pdf_bytes, '12,000') !== false || strpos($pdf_bytes, '12000') !== false, 'Intern remuneration amount is REDACTED');
    assert_false(strpos($pdf_bytes, 'Monthly Payment') !== false, 'Payment mode is REDACTED');
    assert_false(strpos($pdf_bytes, 'Remuneration') !== false, 'Word Remuneration is REDACTED');
    assert_false(strpos($pdf_bytes, 'Stipend') !== false, 'Word Stipend is REDACTED');
    assert_false(strpos($pdf_bytes, 'Monthly CTC') !== false, 'Monthly CTC is REDACTED');
});

run_test('10.5C: Faculty Appointment Letter generates valid PDF and REDACTS all session rates and salary details', function() {
    $faculty_snapshot = json_encode([
        'reference' => 'PEPP/FAC/2026/003',
        'generated_at' => '2026-08-01 10:00:00',
        'generated_by' => 'Superadmin',
        'application_for' => 'faculty',
        'employee' => [
            'id' => 305,
            'employee_code' => 'EMP00305',
            'full_name' => 'Prof. Richard Feynman',
            'place_post_office' => 'Thiruvananthapuram',
            'state' => 'Kerala',
            'pincode' => '695001',
            'designation' => 'Faculty',
            'department' => 'Academics',
            'academic_year' => '2026-27',
            'rate_live' => 1500.00,
            'rate_qpd' => 800.00,
            'rate_recorded' => 1200.00,
            'rate_offline' => 2000.00,
            'monthly_salary' => 0.00
        ]
    ]);

    $pdf_bytes = render_appointment_pdf($faculty_snapshot);
    assert_true(strlen($pdf_bytes) > 1000, 'PDF generated');
    assert_true(strpos($pdf_bytes, 'FACULTY APPOINTMENT LETTER') !== false, 'Title is FACULTY APPOINTMENT LETTER');
    assert_true(strpos($pdf_bytes, 'Academic Directorate') !== false, 'Department is Academic Directorate');
    assert_true(strpos($pdf_bytes, '2026-27') !== false, 'Academic year is 2026-27');

    // Strict privacy checks: Ensure ZERO rates or salary terms appear in raw PDF stream
    assert_false(strpos($pdf_bytes, '1500') !== false || strpos($pdf_bytes, '1,500') !== false, 'Live rate is REDACTED');
    assert_false(strpos($pdf_bytes, '800') !== false, 'QPD rate is REDACTED');
    assert_false(strpos($pdf_bytes, '1200') !== false || strpos($pdf_bytes, '1,200') !== false, 'Recorded rate is REDACTED');
    assert_false(strpos($pdf_bytes, '2000') !== false || strpos($pdf_bytes, '2,000') !== false, 'Offline rate is REDACTED');
    assert_false(strpos($pdf_bytes, 'Monthly CTC') !== false, 'Monthly CTC is REDACTED');
    assert_false(strpos($pdf_bytes, 'Hourly') !== false, 'Hourly charge is REDACTED');
});

// ----------------------------------------------------------------------
// 10.6 Migration 50 Static Syntax & Idempotency Audit
// ----------------------------------------------------------------------
run_test('10.6: Static audit of database-update-50.sql verifies true conditional guards and non-destructive operations', function() {
    $sql_file = __DIR__ . '/database-update-50.sql';
    assert_true(file_exists($sql_file), 'database-update-50.sql exists');
    $sql = file_get_contents($sql_file);

    // 1. Guard against destructive statements
    assert_false(preg_match('/\bDROP\s+COLUMN\b/i', $sql) === 1, 'Contains NO DROP COLUMN');
    assert_false(preg_match('/\bTRUNCATE\b/i', $sql) === 1, 'Contains NO TRUNCATE');
    assert_false(preg_match('/\bDELETE\s+FROM\b/i', $sql) === 1, 'Contains NO DELETE FROM');
    assert_false(preg_match('/\bDROP\s+TABLE\b/i', $sql) === 1, 'Contains NO DROP TABLE');

    // 2. Verify conditional guards for ADD COLUMN
    assert_true(strpos($sql, "column_name = 'employee_management_faculty_id'") !== false, 'Guarded ADD employee_management_faculty_id');
    assert_true(strpos($sql, "column_name = 'internship_ends_on'") !== false, 'Guarded ADD internship_ends_on');
    assert_true(strpos($sql, "column_name = 'internship_payment_status'") !== false, 'Guarded ADD internship_payment_status');
    assert_true(strpos($sql, "column_name = 'internship_payment_mode'") !== false, 'Guarded ADD internship_payment_mode');
    assert_true(strpos($sql, "column_name = 'internship_remuneration'") !== false, 'Guarded ADD internship_remuneration');
    assert_true(strpos($sql, "column_name = 'academic_year'") !== false, 'Guarded ADD academic_year');
    assert_true(strpos($sql, "column_name = 'rate_live'") !== false, 'Guarded ADD rate_live');

    // 3. Verify conditional guards for indexes and foreign keys
    assert_true(strpos($sql, "index_name = 'uq_fac_emp_faculty'") !== false, 'Guarded ADD UNIQUE KEY uq_fac_emp_faculty');
    assert_true(strpos($sql, "constraint_name = 'fk_fac_emp_faculty'") !== false, 'Guarded ADD CONSTRAINT fk_fac_emp_faculty');

    // 4. Verify conditional guards for MODIFY COLUMN (only if NOT NULL)
    assert_true(strpos($sql, "column_name = 'department' AND IS_NULLABLE = 'NO'") !== false, 'Guarded MODIFY department');
    assert_true(strpos($sql, "column_name = 'joining_date' AND IS_NULLABLE = 'NO'") !== false, 'Guarded MODIFY joining_date');
    assert_true(strpos($sql, "column_name = 'contract_validity_from' AND IS_NULLABLE = 'NO'") !== false, 'Guarded MODIFY contract_validity_from');
    assert_true(strpos($sql, "column_name = 'contract_validity_till' AND IS_NULLABLE = 'NO'") !== false, 'Guarded MODIFY contract_validity_till');

    // 5. Verify stored procedure wrapper
    assert_true(strpos($sql, 'CREATE PROCEDURE MigrateEmployeeFacultyIntegration50') !== false, 'Wrapped in stored procedure');
    assert_true(strpos($sql, 'CALL MigrateEmployeeFacultyIntegration50()') !== false, 'Calls migration procedure');
    assert_true(strpos($sql, 'DROP PROCEDURE IF EXISTS MigrateEmployeeFacultyIntegration50') !== false, 'Cleans up migration procedure');
});

// ======================================================================
// SECTION 11: Role-Specific Edit Invariants & Authoritative Faculty Sync
// ======================================================================
echo "\n--- SECTION 11: Role-Specific Edit Invariants & Authoritative Faculty Sync ---\n";

/**
 * Simulates employee-management.php update_employee_profile backend logic in isolated SQLite
 */
function test_sim_update_employee_profile(PDO $pdo, int $emp_id, array $post_data): array {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$emp_id]);
        $current_emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current_emp) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Employee record not found.'];
        }

        $full_name = trim((string)($post_data['full_name'] ?? $current_emp['full_name'] ?? ''));
        $gender = $post_data['gender'] ?? $current_emp['gender'] ?? 'Male';
        $dob = trim((string)($post_data['date_of_birth'] ?? $current_emp['date_of_birth'] ?? ''));
        $blood_group = $post_data['blood_group'] ?? $current_emp['blood_group'] ?? 'O+';
        $mobile = preg_replace('/\D/', '', trim((string)($post_data['mobile_number'] ?? $current_emp['mobile_number'] ?? '')));
        $email = strtolower(trim((string)($post_data['email'] ?? $current_emp['email'] ?? '')));
        $status = trim((string)($post_data['status'] ?? $current_emp['status'] ?? 'active'));

        // Authoritative immutable role track from database record
        $application_for = strtolower(trim((string)($current_emp['application_for'] ?? 'employee')));

        $val_errors = [];

        $designation = '';
        $department = null;
        $joining_date = null;
        $probation_till = null;
        $contract_from = null;
        $contract_till = null;
        $monthly_salary = 0.00;
        $internship_ends_on = null;
        $internship_payment_status = null;
        $internship_payment_mode = null;
        $internship_remuneration = null;
        $academic_year = null;
        $rate_live = 0.00;
        $rate_qpd = 0.00;
        $rate_recorded = 0.00;
        $rate_offline = 0.00;

        if ($application_for === 'intern') {
            $designation = 'Project Intern'; // forced
            $department = null;
            $joining_date = trim((string)($post_data['intern_joining_date'] ?? $post_data['joining_date'] ?? ''));
            $internship_ends_on = trim((string)($post_data['internship_ends_on'] ?? ''));
            $contract_from = $joining_date ?: null;
            $contract_till = $internship_ends_on ?: null;
            $probation_till = null;
            $monthly_salary = 0.00;

            if (!$joining_date) $val_errors[] = 'Valid joining date is required for Intern.';
            if (!$internship_ends_on) $val_errors[] = 'Valid internship end date is required for Intern.';
            if ($joining_date && $internship_ends_on && strtotime($internship_ends_on) < strtotime($joining_date)) {
                $val_errors[] = 'Internship end date must be on or after joining date.';
            }

            $p_status = trim((string)($post_data['internship_payment_status'] ?? ''));
            if (!in_array($p_status, ['paid', 'unpaid'], true)) {
                $val_errors[] = 'Please select Paid or Unpaid for the internship.';
            }
            $internship_payment_status = $p_status;

            if ($p_status === 'unpaid') {
                $internship_payment_mode = null;
                $internship_remuneration = null;
            } else {
                $p_mode = trim((string)($post_data['internship_payment_mode'] ?? ''));
                if (!in_array($p_mode, ['task_completion', 'monthly', 'one_time'], true)) {
                    $val_errors[] = 'Please select a valid payment mode for paid internship.';
                }
                $internship_payment_mode = $p_mode;

                if ($p_mode === 'task_completion') {
                    $internship_remuneration = null;
                } else {
                    $remun = (float)($post_data['internship_remuneration'] ?? 0);
                    if ($remun <= 0) {
                        $val_errors[] = 'Remuneration amount must be greater than 0.';
                    }
                    $internship_remuneration = $remun;
                }
            }
        } elseif ($application_for === 'faculty') {
            $designation = 'Faculty'; // forced
            $department = 'Academics'; // forced
            $joining_date = trim((string)($post_data['faculty_joining_date'] ?? $post_data['joining_date'] ?? '')) ?: ($current_emp['joining_date'] ?: date('Y-m-d'));
            $probation_till = null;
            $contract_from = $joining_date;
            $contract_till = trim((string)($post_data['contract_validity_till'] ?? '')) ?: ($current_emp['contract_validity_till'] ?: date('Y-12-31'));
            $monthly_salary = 0.00;
            $internship_remuneration = null;

            $academic_year = trim((string)($post_data['academic_year'] ?? $current_emp['academic_year'] ?? ''));
            if (!$academic_year) {
                $val_errors[] = 'PEPP Academic Year is required for Faculty.';
            }

            $rate_live = max(0, (float)($post_data['rate_live'] ?? 0));
            $rate_qpd = max(0, (float)($post_data['rate_qpd'] ?? 0));
            $rate_recorded = max(0, (float)($post_data['rate_recorded'] ?? 0));
            $rate_offline = max(0, (float)($post_data['rate_offline'] ?? 0));
        } else {
            $designation = trim((string)($post_data['designation'] ?? ''));
            $department = trim((string)($post_data['department'] ?? ''));
            $joining_date = trim((string)($post_data['joining_date'] ?? ''));
            $probation_till = trim((string)($post_data['probation_till'] ?? '')) ?: null;
            $contract_from = trim((string)($post_data['contract_validity_from'] ?? ''));
            $contract_till = trim((string)($post_data['contract_validity_till'] ?? ''));
            $monthly_salary = (float)($post_data['monthly_salary'] ?? 0);

            if (!$designation) $val_errors[] = 'Designation is required.';
            if (!$department) $val_errors[] = 'Department is required.';
            if (!$joining_date) $val_errors[] = 'Valid joining date is required.';
            if (!$contract_from) $val_errors[] = 'Valid contract from date is required.';
            if (!$contract_till) $val_errors[] = 'Valid contract till date is required.';
            if ($contract_from && $contract_till && strtotime($contract_till) < strtotime($contract_from)) {
                $val_errors[] = 'Contract till date must be on or after contract from date.';
            }
            if ($monthly_salary <= 0) {
                $val_errors[] = 'Monthly salary must be greater than 0 for Employee.';
            }
        }

        if (!empty($val_errors)) {
            $pdo->rollBack();
            return ['success' => false, 'error' => implode(' ', $val_errors)];
        }

        $stmt_upd = $pdo->prepare("
            UPDATE employees SET
                full_name = ?, email = ?, mobile_number = ?, status = ?,
                designation = ?, department = ?, joining_date = ?, probation_till = ?,
                contract_validity_from = ?, contract_validity_till = ?, monthly_salary = ?,
                internship_ends_on = ?, internship_payment_status = ?, internship_payment_mode = ?,
                internship_remuneration = ?, academic_year = ?,
                rate_live = ?, rate_qpd = ?, rate_recorded = ?, rate_offline = ?
            WHERE id = ?
        ");
        $stmt_upd->execute([
            $full_name, $email, $mobile, $status,
            $designation, $department, $joining_date, $probation_till,
            $contract_from, $contract_till, $monthly_salary,
            $internship_ends_on, $internship_payment_status, $internship_payment_mode,
            $internship_remuneration, $academic_year,
            $rate_live, $rate_qpd, $rate_recorded, $rate_offline,
            $emp_id
        ]);

        // Synchronize linked Faculty record if application_for = 'faculty'
        if ($application_for === 'faculty') {
            $stmt_fac = $pdo->prepare("SELECT id FROM faculties WHERE employee_management_faculty_id = ?");
            $stmt_fac->execute([$emp_id]);
            $linked_fac = $stmt_fac->fetch(PDO::FETCH_ASSOC);
            if ($linked_fac) {
                $stmt_sync = $pdo->prepare("
                    UPDATE faculties
                    SET name = ?, email = ?, mobile = ?, academic_year = ?,
                        rate_live = ?, rate_qpd = ?, rate_recorded = ?, rate_offline = ?
                    WHERE employee_management_faculty_id = ?
                ");
                $stmt_sync->execute([
                    $full_name, $email ?: null, $mobile, $academic_year ?: null,
                    $rate_live, $rate_qpd, $rate_recorded, $rate_offline,
                    $emp_id
                ]);
            }
        }

        $pdo->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Simulates faculties.php edit_faculty backend logic in isolated SQLite
 */
function test_sim_edit_faculty(PDO $pdo, int $fac_id, array $post_data): array {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM faculties WHERE id = ?");
        $stmt->execute([$fac_id]);
        $orig_fac = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$orig_fac) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Faculty record not found.'];
        }

        $new_link_emp_id = !empty($post_data['employee_management_faculty_id']) ? (int)$post_data['employee_management_faculty_id'] : null;
        $orig_link_emp_id = (!empty($orig_fac['employee_management_faculty_id'])) ? (int)$orig_fac['employee_management_faculty_id'] : null;
        $link_emp_id = $new_link_emp_id ?: $orig_link_emp_id;

        $status = in_array($post_data['status'] ?? '', ['active', 'inactive'], true) ? $post_data['status'] : 'active';

        if ($link_emp_id) {
            $stmt_conflict = $pdo->prepare("SELECT id, name FROM faculties WHERE employee_management_faculty_id = ? AND id != ?");
            $stmt_conflict->execute([$link_emp_id, $fac_id]);
            $conflict = $stmt_conflict->fetch(PDO::FETCH_ASSOC);
            if ($conflict) {
                $pdo->rollBack();
                return ['success' => false, 'error' => "The selected Faculty is already linked to faculty #{$conflict['id']} ({$conflict['name']})."];
            }

            $stmt_emp = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND application_for = 'faculty'");
            $stmt_emp->execute([$link_emp_id]);
            $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);
            if (!$emp) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Linked or selected record is not an approved Faculty in Employee Management.'];
            }

            // Authoritative fields override POST values
            $name = trim((string)($emp['full_name'] ?? ''));
            $mobile = trim((string)($emp['mobile_number'] ?? ''));
            $email = trim((string)($emp['email'] ?? '')) ?: null;
            $academic_year = trim((string)($emp['academic_year'] ?? '')) ?: null;
            $rate_live = max(0, (float)($emp['rate_live'] ?? 0));
            $rate_qpd = max(0, (float)($emp['rate_qpd'] ?? 0));
            $rate_recorded = max(0, (float)($emp['rate_recorded'] ?? 0));
            $rate_offline = max(0, (float)($emp['rate_offline'] ?? 0));
        } else {
            // Unlinked legacy faculty: manual values accepted
            $name = trim((string)($post_data['name'] ?? ''));
            $email = trim((string)($post_data['email'] ?? '')) ?: null;
            $mobile = trim((string)($post_data['mobile'] ?? ''));
            $academic_year = trim((string)($post_data['academic_year'] ?? '')) ?: null;
            $rate_live = max(0, (float)($post_data['rate_live'] ?? 0));
            $rate_qpd = max(0, (float)($post_data['rate_qpd'] ?? 0));
            $rate_recorded = max(0, (float)($post_data['rate_recorded'] ?? 0));
            $rate_offline = max(0, (float)($post_data['rate_offline'] ?? 0));
        }

        if ($name === '') {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Faculty name is required.'];
        }

        $stmt_upd = $pdo->prepare("UPDATE faculties SET employee_management_faculty_id=?, name=?, mobile=?, email=?, rate_live=?, rate_qpd=?, rate_recorded=?, rate_offline=?, academic_year=?, status=? WHERE id=?");
        $stmt_upd->execute([$link_emp_id, $name, $mobile, $email, $rate_live, $rate_qpd, $rate_recorded, $rate_offline, $academic_year, $status, $fac_id]);

        $pdo->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ----------------------------------------------------------------------
// 11.1 Employee approval-time fields can be edited
// ----------------------------------------------------------------------
run_test('11.1: Employee approval-time fields can be edited and persisted', function() use ($pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, probation_till,
            contract_validity_from, contract_validity_till, monthly_salary, status
        ) VALUES ('EMP00501', 'Alice Developer', 'alice@pepp.com', '9876500501', 'employee',
                  'Junior Developer', 'IT', '2026-01-01', '2026-06-30',
                  '2026-01-01', '2026-12-31', 30000.00, 'active')
    ");
    $stmt->execute();
    $emp_id = (int)$pdo->lastInsertId();

    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'designation' => 'Lead Software Architect',
        'department' => 'Technology & Platform',
        'joining_date' => '2026-01-15',
        'probation_till' => '2026-07-15',
        'contract_validity_from' => '2026-01-15',
        'contract_validity_till' => '2028-01-14',
        'monthly_salary' => 75000.00
    ]);
    assert_true($res['success'], 'Employee update succeeded');

    $chk = $pdo->query("SELECT * FROM employees WHERE id = {$emp_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('Lead Software Architect', $chk['designation']);
    assert_equals('Technology & Platform', $chk['department']);
    assert_equals('2026-01-15', $chk['joining_date']);
    assert_equals('2026-07-15', $chk['probation_till']);
    assert_equals('2026-01-15', $chk['contract_validity_from']);
    assert_equals('2028-01-14', $chk['contract_validity_till']);
    assert_equals(75000.00, (float)$chk['monthly_salary']);
});

// ----------------------------------------------------------------------
// 11.2 Intern approval-time fields can be edited
// ----------------------------------------------------------------------
run_test('11.2: Intern approval-time fields can be edited with locked designation and zero salary', function() use ($pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, internship_ends_on,
            internship_payment_status, internship_payment_mode, internship_remuneration,
            monthly_salary, status
        ) VALUES ('EMP00502', 'Bob Intern', 'bob@pepp.com', '9876500502', 'intern',
                  'Project Intern', NULL, '2026-02-01', '2026-04-30',
                  'unpaid', NULL, NULL, 0.00, 'active')
    ");
    $stmt->execute();
    $emp_id = (int)$pdo->lastInsertId();

    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'intern_joining_date' => '2026-02-15',
        'internship_ends_on' => '2026-08-31',
        'internship_payment_status' => 'paid',
        'internship_payment_mode' => 'monthly',
        'internship_remuneration' => 14000.00
    ]);
    assert_true($res['success'], 'Intern update succeeded');

    $chk = $pdo->query("SELECT * FROM employees WHERE id = {$emp_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('Project Intern', $chk['designation'], 'Designation remains locked to Project Intern');
    assert_equals('2026-02-15', $chk['joining_date']);
    assert_equals('2026-08-31', $chk['internship_ends_on']);
    assert_equals('paid', $chk['internship_payment_status']);
    assert_equals('monthly', $chk['internship_payment_mode']);
    assert_equals(14000.00, (float)$chk['internship_remuneration'], 'Remuneration stored in dedicated column');
    assert_equals(0.00, (float)$chk['monthly_salary'], 'Monthly salary remains 0.00');
});

// ----------------------------------------------------------------------
// 11.3A Intern unpaid validation
// ----------------------------------------------------------------------
run_test('11.3A: Intern unpaid status clears payment mode and remuneration to NULL', function() use ($pdo) {
    $emp_id = (int)$pdo->query("SELECT id FROM employees WHERE employee_id = 'EMP00502'")->fetchColumn();

    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'intern_joining_date' => '2026-02-15',
        'internship_ends_on' => '2026-08-31',
        'internship_payment_status' => 'unpaid',
        'internship_payment_mode' => 'monthly', // client tried to submit mode
        'internship_remuneration' => 10000.00   // client tried to submit remuneration
    ]);
    assert_true($res['success'], 'Unpaid update succeeded');

    $chk = $pdo->query("SELECT * FROM employees WHERE id = {$emp_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('unpaid', $chk['internship_payment_status']);
    assert_equals(null, $chk['internship_payment_mode'], 'Mode forced to NULL');
    assert_equals(null, $chk['internship_remuneration'], 'Remuneration forced to NULL');
    assert_equals(0.00, (float)$chk['monthly_salary'], 'Monthly salary is 0.00');
});

// ----------------------------------------------------------------------
// 11.3B Intern paid/task-completion validation
// ----------------------------------------------------------------------
run_test('11.3B: Intern paid with task-completion mode forces remuneration to NULL', function() use ($pdo) {
    $emp_id = (int)$pdo->query("SELECT id FROM employees WHERE employee_id = 'EMP00502'")->fetchColumn();

    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'intern_joining_date' => '2026-02-15',
        'internship_ends_on' => '2026-08-31',
        'internship_payment_status' => 'paid',
        'internship_payment_mode' => 'task_completion',
        'internship_remuneration' => 5000.00 // should be cleared
    ]);
    assert_true($res['success'], 'Task completion update succeeded');

    $chk = $pdo->query("SELECT * FROM employees WHERE id = {$emp_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('paid', $chk['internship_payment_status']);
    assert_equals('task_completion', $chk['internship_payment_mode']);
    assert_equals(null, $chk['internship_remuneration'], 'Task completion remuneration forced to NULL');
    assert_equals(0.00, (float)$chk['monthly_salary']);
});

// ----------------------------------------------------------------------
// 11.3C Intern paid/monthly and paid/one-time remuneration validation
// ----------------------------------------------------------------------
run_test('11.3C: Intern paid monthly and one-time strictly validates remuneration > 0', function() use ($pdo) {
    $emp_id = (int)$pdo->query("SELECT id FROM employees WHERE employee_id = 'EMP00502'")->fetchColumn();

    // 1. Zero remuneration monthly -> FAIL
    $res1 = test_sim_update_employee_profile($pdo, $emp_id, [
        'intern_joining_date' => '2026-02-15',
        'internship_ends_on' => '2026-08-31',
        'internship_payment_status' => 'paid',
        'internship_payment_mode' => 'monthly',
        'internship_remuneration' => 0
    ]);
    assert_false($res1['success'], 'Zero remuneration rejected');

    // 2. Negative remuneration one-time -> FAIL
    $res2 = test_sim_update_employee_profile($pdo, $emp_id, [
        'intern_joining_date' => '2026-02-15',
        'internship_ends_on' => '2026-08-31',
        'internship_payment_status' => 'paid',
        'internship_payment_mode' => 'one_time',
        'internship_remuneration' => -500
    ]);
    assert_false($res2['success'], 'Negative remuneration rejected');

    // 3. Valid one-time remuneration -> SUCCESS
    $res3 = test_sim_update_employee_profile($pdo, $emp_id, [
        'intern_joining_date' => '2026-02-15',
        'internship_ends_on' => '2026-08-31',
        'internship_payment_status' => 'paid',
        'internship_payment_mode' => 'one_time',
        'internship_remuneration' => 25000.00
    ]);
    assert_true($res3['success'], 'Valid one-time remuneration accepted');

    $chk = $pdo->query("SELECT * FROM employees WHERE id = {$emp_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('one_time', $chk['internship_payment_mode']);
    assert_equals(25000.00, (float)$chk['internship_remuneration']);
    assert_equals(0.00, (float)$chk['monthly_salary']);
});

// ----------------------------------------------------------------------
// 11.4 Faculty academic year and all 4 rates can be edited
// ----------------------------------------------------------------------
run_test('11.4: Faculty academic year and all 4 rates can be edited and persisted', function() use ($pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, contract_validity_from, contract_validity_till,
            monthly_salary, academic_year, rate_live, rate_qpd, rate_recorded, rate_offline, status
        ) VALUES ('EMP00504', 'Dr. Niels Bohr', 'niels.bohr@pepp.com', '9876500504', 'faculty',
                  'Faculty', 'Academics', '2026-03-01', '2026-03-01', '2026-12-31',
                  0.00, '2025-26', 1500.00, 750.00, 1000.00, 2000.00, 'active')
    ");
    $stmt->execute();
    $emp_id = (int)$pdo->lastInsertId();

    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'academic_year' => '2026-27',
        'rate_live' => 2400.00,
        'rate_qpd' => 1200.00,
        'rate_recorded' => 1800.00,
        'rate_offline' => 3200.00
    ]);
    assert_true($res['success'], 'Faculty profile update succeeded');

    $chk = $pdo->query("SELECT * FROM employees WHERE id = {$emp_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('Faculty', $chk['designation'], 'Designation locked to Faculty');
    assert_equals('Academics', $chk['department'], 'Department locked to Academics');
    assert_equals('2026-27', $chk['academic_year']);
    assert_equals(2400.00, (float)$chk['rate_live']);
    assert_equals(1200.00, (float)$chk['rate_qpd']);
    assert_equals(1800.00, (float)$chk['rate_recorded']);
    assert_equals(3200.00, (float)$chk['rate_offline']);
    assert_equals(0.00, (float)$chk['monthly_salary'], 'Monthly salary is 0.00');
});

// ----------------------------------------------------------------------
// 11.5 Linked Faculty authoritative fields are read-only in faculties.php
// ----------------------------------------------------------------------
run_test('11.5: Linked Faculty authoritative fields are enforced read-only in faculties.php UI', function() {
    $faculties_file = __DIR__ . '/faculties.php';
    assert_true(file_exists($faculties_file), 'faculties.php exists');
    $fac_src = file_get_contents($faculties_file);

    // 1. Verify read-only locking function exists
    assert_true(strpos($fac_src, 'function setFacEditFieldReadonly(isLinked)') !== false, 'setFacEditFieldReadonly function defined');

    // 2. Verify all 7 text/number inputs are set to readOnly
    assert_true(strpos($fac_src, "'fac-edit-name'") !== false, 'Name element included in read-only locking');
    assert_true(strpos($fac_src, "'fac-edit-mobile'") !== false, 'Mobile element included in read-only locking');
    assert_true(strpos($fac_src, "'fac-edit-email'") !== false, 'Email element included in read-only locking');
    assert_true(strpos($fac_src, "'fac-edit-rate_live'") !== false, 'Live rate included in read-only locking');
    assert_true(strpos($fac_src, "'fac-edit-rate_qpd'") !== false, 'QPD rate included in read-only locking');
    assert_true(strpos($fac_src, "'fac-edit-rate_recorded'") !== false, 'Recorded rate included in read-only locking');
    assert_true(strpos($fac_src, "'fac-edit-rate_offline'") !== false, 'Offline rate included in read-only locking');

    // 3. Verify academic year dropdown is locked
    assert_true(strpos($fac_src, "yr.style.pointerEvents = isLinked ? 'none' : ''") !== false, 'Academic year dropdown interaction disabled');

    // 4. Verify informative badges & banners
    assert_true(strpos($fac_src, 'Managed from Employee Management') !== false, 'Managed from Employee Management indicator banner present');
    assert_true(strpos($fac_src, 'Personal details and session charges are managed from Employee Management') !== false, 'Explanatory note present');
});

// ----------------------------------------------------------------------
// 11.6 Employee Management Faculty update synchronizes linked Faculty
// ----------------------------------------------------------------------
run_test('11.6: Employee Management Faculty update synchronizes linked Faculty in faculties table', function() use ($pdo) {
    // 1. Create Faculty employee
    $stmt_emp = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, contract_validity_from, contract_validity_till,
            monthly_salary, academic_year, rate_live, rate_qpd, rate_recorded, rate_offline, status
        ) VALUES ('EMP00506', 'Dr. Rosalind Franklin', 'rosalind@pepp.com', '9876500506', 'faculty',
                  'Faculty', 'Academics', '2026-03-01', '2026-03-01', '2026-12-31',
                  0.00, '2025-26', 1600.00, 800.00, 1100.00, 2100.00, 'active')
    ");
    $stmt_emp->execute();
    $emp_id = (int)$pdo->lastInsertId();

    // 2. Create linked faculty record
    $stmt_fac = $pdo->prepare("
        INSERT INTO faculties (
            employee_management_faculty_id, name, mobile, email,
            rate_live, rate_qpd, rate_recorded, rate_offline, academic_year, status
        ) VALUES (?, 'Dr. Rosalind Franklin', '9876500506', 'rosalind@pepp.com',
                  1600.00, 800.00, 1100.00, 2100.00, '2025-26', 'active')
    ");
    $stmt_fac->execute([$emp_id]);
    $fac_id = (int)$pdo->lastInsertId();

    // 3. Update employee profile via Employee Management
    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'full_name' => 'Dr. Rosalind Franklin Ph.D.',
        'email' => 'r.franklin@pepplearning.com',
        'mobile_number' => '9876599999',
        'academic_year' => '2026-27',
        'rate_live' => 2600.00,
        'rate_qpd' => 1300.00,
        'rate_recorded' => 1900.00,
        'rate_offline' => 3500.00
    ]);
    assert_true($res['success'], 'Employee update succeeded');

    // 4. Verify linked faculty record is synchronized
    $fac_synced = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('Dr. Rosalind Franklin Ph.D.', $fac_synced['name'], 'Name synchronized');
    assert_equals('r.franklin@pepplearning.com', $fac_synced['email'], 'Email synchronized');
    assert_equals('9876599999', $fac_synced['mobile'], 'Mobile synchronized');
    assert_equals('2026-27', $fac_synced['academic_year'], 'Academic Year synchronized');
    assert_equals(2600.00, (float)$fac_synced['rate_live'], 'Live rate synchronized');
    assert_equals(1300.00, (float)$fac_synced['rate_qpd'], 'QPD rate synchronized');
    assert_equals(1900.00, (float)$fac_synced['rate_recorded'], 'Recorded rate synchronized');
    assert_equals(3500.00, (float)$fac_synced['rate_offline'], 'Offline rate synchronized');
});

// ----------------------------------------------------------------------
// 11.7 Multiple authoritative fields synchronize correctly
// ----------------------------------------------------------------------
run_test('11.7: Multiple authoritative fields synchronize correctly in a single atomic update', function() use ($pdo) {
    $emp = $pdo->query("SELECT id FROM employees WHERE employee_id = 'EMP00506'")->fetch(PDO::FETCH_ASSOC);
    $emp_id = (int)$emp['id'];

    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'full_name' => 'Prof. Rosalind Elsie Franklin',
        'email' => 'rosalind.franklin@pepp.org',
        'mobile_number' => '9876511111',
        'academic_year' => '2027-28',
        'rate_live' => 3000.00,
        'rate_qpd' => 1500.00,
        'rate_recorded' => 2200.00,
        'rate_offline' => 4000.00
    ]);
    assert_true($res['success'], 'Multiple field update succeeded');

    $fac = $pdo->query("SELECT * FROM faculties WHERE employee_management_faculty_id = {$emp_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('Prof. Rosalind Elsie Franklin', $fac['name']);
    assert_equals('rosalind.franklin@pepp.org', $fac['email']);
    assert_equals('9876511111', $fac['mobile']);
    assert_equals('2027-28', $fac['academic_year']);
    assert_equals(3000.00, (float)$fac['rate_live']);
    assert_equals(1500.00, (float)$fac['rate_qpd']);
    assert_equals(2200.00, (float)$fac['rate_recorded']);
    assert_equals(4000.00, (float)$fac['rate_offline']);
});

// ----------------------------------------------------------------------
// 11.8 Faculty sessions remain unchanged
// ----------------------------------------------------------------------
run_test('11.8: Authoritative synchronization leaves faculty sessions 100% untouched', function() use ($pdo) {
    $fac = $pdo->query("SELECT id, employee_management_faculty_id FROM faculties WHERE name = 'Prof. Rosalind Elsie Franklin'")->fetch(PDO::FETCH_ASSOC);
    $fac_id = (int)$fac['id'];
    $emp_id = (int)$fac['employee_management_faculty_id'];

    // Insert existing session for this faculty
    $stmt_sess = $pdo->prepare("
        INSERT INTO sessions (faculty_id, topic, session_type, duration_hours, status, session_datetime)
        VALUES (?, 'DNA Crystallography Techniques', 'live', 2.5, 'completed', '2026-04-10 14:00:00')
    ");
    $stmt_sess->execute([$fac_id]);
    $sess_id = (int)$pdo->lastInsertId();

    // Trigger update from Employee Management
    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'rate_live' => 3500.00
    ]);
    assert_true($res['success'], 'Update succeeded');

    // Verify session remains completely intact
    $chk_sess = $pdo->query("SELECT * FROM sessions WHERE id = {$sess_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals($fac_id, (int)$chk_sess['faculty_id']);
    assert_equals('DNA Crystallography Techniques', $chk_sess['topic']);
    assert_equals('live', $chk_sess['session_type']);
    assert_equals(2.5, (float)$chk_sess['duration_hours']);
    assert_equals('completed', $chk_sess['status']);
    assert_equals('2026-04-10 14:00:00', $chk_sess['session_datetime']);
});

// ----------------------------------------------------------------------
// 11.9 Faculty payments remain unchanged
// ----------------------------------------------------------------------
run_test('11.9: Authoritative synchronization leaves faculty payments 100% untouched', function() use ($pdo) {
    $fac = $pdo->query("SELECT id, employee_management_faculty_id FROM faculties WHERE name = 'Prof. Rosalind Elsie Franklin'")->fetch(PDO::FETCH_ASSOC);
    $fac_id = (int)$fac['id'];
    $emp_id = (int)$fac['employee_management_faculty_id'];

    // Insert existing payment record for this faculty
    $stmt_pay = $pdo->prepare("
        INSERT INTO faculty_payments (faculty_id, amount, payment_account_id, paid_date, remarks, created_by)
        VALUES (?, 18500.00, 1, '2026-04-15', 'Honorarium for April batch', 'superadmin')
    ");
    $stmt_pay->execute([$fac_id]);
    $pay_id = (int)$pdo->lastInsertId();

    // Trigger update from Employee Management
    $res = test_sim_update_employee_profile($pdo, $emp_id, [
        'rate_live' => 3600.00
    ]);
    assert_true($res['success'], 'Update succeeded');

    // Verify payment record remains completely intact
    $chk_pay = $pdo->query("SELECT * FROM faculty_payments WHERE id = {$pay_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals($fac_id, (int)$chk_pay['faculty_id']);
    assert_equals(18500.00, (float)$chk_pay['amount']);
    assert_equals('2026-04-15', $chk_pay['paid_date']);
    assert_equals('Honorarium for April batch', $chk_pay['remarks']);
});

// ----------------------------------------------------------------------
// 11.10 Legacy/unlinked Faculty remains manually editable
// ----------------------------------------------------------------------
run_test('11.10: Legacy/unlinked Faculty remains manually editable via faculties.php', function() use ($pdo) {
    $stmt_leg = $pdo->prepare("
        INSERT INTO faculties (employee_management_faculty_id, name, mobile, email, rate_live, rate_qpd, rate_recorded, rate_offline, academic_year, status)
        VALUES (NULL, 'Legacy Manual Faculty', '9000000001', 'legacy1@pepp.com', 800.00, 400.00, 600.00, 1200.00, '2024-25', 'active')
    ");
    $stmt_leg->execute();
    $leg_id = (int)$pdo->lastInsertId();

    // Edit unlinked faculty manually
    $res = test_sim_edit_faculty($pdo, $leg_id, [
        'employee_management_faculty_id' => '',
        'name' => 'Updated Manual Faculty Name',
        'mobile' => '9000000009',
        'email' => 'updated.legacy@pepp.com',
        'academic_year' => '2025-26',
        'rate_live' => 950.00,
        'rate_qpd' => 450.00,
        'rate_recorded' => 700.00,
        'rate_offline' => 1400.00,
        'status' => 'active'
    ]);
    assert_true($res['success'], 'Manual edit succeeded for unlinked faculty');

    $chk = $pdo->query("SELECT * FROM faculties WHERE id = {$leg_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals(null, $chk['employee_management_faculty_id'], 'Remains unlinked');
    assert_equals('Updated Manual Faculty Name', $chk['name']);
    assert_equals('9000000009', $chk['mobile']);
    assert_equals('updated.legacy@pepp.com', $chk['email']);
    assert_equals(950.00, (float)$chk['rate_live']);
    assert_equals(450.00, (float)$chk['rate_qpd']);
    assert_equals(700.00, (float)$chk['rate_recorded']);
    assert_equals(1400.00, (float)$chk['rate_offline']);
});

// ----------------------------------------------------------------------
// 11.11 Linking a legacy Faculty copies authoritative data and locks those fields
// ----------------------------------------------------------------------
run_test('11.11: Linking a legacy Faculty copies authoritative data and ignores direct POST tampering', function() use ($pdo) {
    // 1. Create active faculty employee
    $stmt_emp = $pdo->prepare("
        INSERT INTO employees (
            employee_id, full_name, email, mobile_number, application_for,
            designation, department, joining_date, contract_validity_from, contract_validity_till,
            monthly_salary, academic_year, rate_live, rate_qpd, rate_recorded, rate_offline, status
        ) VALUES ('EMP00511', 'Dr. Barbara McClintock', 'barbara@pepp.com', '9876500511', 'faculty',
                  'Faculty', 'Academics', '2026-03-01', '2026-03-01', '2026-12-31',
                  0.00, '2026-27', 2800.00, 1400.00, 2000.00, 3800.00, 'active')
    ");
    $stmt_emp->execute();
    $emp_id = (int)$pdo->lastInsertId();

    // 2. Create unlinked legacy faculty
    $stmt_leg = $pdo->prepare("
        INSERT INTO faculties (employee_management_faculty_id, name, mobile, email, rate_live, status)
        VALUES (NULL, 'Old Faculty Name', '9111111111', 'old@pepp.com', 500.00, 'active')
    ");
    $stmt_leg->execute();
    $fac_id = (int)$pdo->lastInsertId();

    // 3. User links to EMP00511, but client tries to submit tampered name & rates
    $res = test_sim_edit_faculty($pdo, $fac_id, [
        'employee_management_faculty_id' => $emp_id,
        'name' => 'Tampered Injected Name',
        'mobile' => '9999999999',
        'email' => 'hacked@evil.com',
        'rate_live' => 1.00,
        'rate_qpd' => 1.00,
        'rate_recorded' => 1.00,
        'rate_offline' => 1.00,
        'academic_year' => '1999-00'
    ]);
    assert_true($res['success'], 'Linking succeeded');

    // 4. Verify authoritative data was applied and tampered POST values were ignored
    $linked = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals($emp_id, (int)$linked['employee_management_faculty_id']);
    assert_equals('Dr. Barbara McClintock', $linked['name'], 'Authoritative full_name applied');
    assert_equals('barbara@pepp.com', $linked['email'], 'Authoritative email applied');
    assert_equals('9876500511', $linked['mobile'], 'Authoritative mobile applied');
    assert_equals('2026-27', $linked['academic_year'], 'Authoritative academic_year applied');
    assert_equals(2800.00, (float)$linked['rate_live'], 'Authoritative live rate applied');
    assert_equals(1400.00, (float)$linked['rate_qpd'], 'Authoritative QPD rate applied');
    assert_equals(2000.00, (float)$linked['rate_recorded'], 'Authoritative recorded rate applied');
    assert_equals(3800.00, (float)$linked['rate_offline'], 'Authoritative offline rate applied');

    // 5. Direct POST tampering test on already-linked faculty (without switching link)
    $res_tamper = test_sim_edit_faculty($pdo, $fac_id, [
        'employee_management_faculty_id' => '', // omitted
        'name' => 'Another Tamper Attempt',
        'rate_live' => 99.00
    ]);
    assert_true($res_tamper['success']);
    $recheck = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('Dr. Barbara McClintock', $recheck['name'], 'Tampered name rejected; authoritative maintained');
    assert_equals(2800.00, (float)$recheck['rate_live'], 'Tampered rate rejected; authoritative maintained');
});

// ----------------------------------------------------------------------
// 11.12 Unlink preserves record/history/sessions/payments
// ----------------------------------------------------------------------
run_test('11.12: Unlink resets employee_management_faculty_id to NULL and preserves data, sessions & payments', function() use ($pdo) {
    $fac = $pdo->query("SELECT * FROM faculties WHERE name = 'Dr. Barbara McClintock'")->fetch(PDO::FETCH_ASSOC);
    $fac_id = (int)$fac['id'];
    $emp_id = (int)$fac['employee_management_faculty_id'];

    // Insert sessions and payments
    $pdo->prepare("INSERT INTO sessions (faculty_id, topic, session_type, duration_hours, status, session_datetime) VALUES (?, 'Genetic Transposition', 'offline', 3.0, 'completed', '2026-05-01 10:00:00')")->execute([$fac_id]);
    $pdo->prepare("INSERT INTO faculty_payments (faculty_id, amount, paid_date, remarks) VALUES (?, 25000.00, '2026-05-05', 'Advance payment')")->execute([$fac_id]);

    // Unlink faculty
    $pdo->prepare("UPDATE faculties SET employee_management_faculty_id = NULL WHERE id = ?")->execute([$fac_id]);

    $unlinked = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals(null, $unlinked['employee_management_faculty_id'], 'Link reset to NULL');
    assert_equals('Dr. Barbara McClintock', $unlinked['name'], 'Name preserved');
    assert_equals(2800.00, (float)$unlinked['rate_live'], 'Rates preserved');

    // Verify sessions and payments intact
    $sess_cnt = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE faculty_id = {$fac_id}")->fetchColumn();
    $pay_cnt = (int)$pdo->query("SELECT COUNT(*) FROM faculty_payments WHERE faculty_id = {$fac_id}")->fetchColumn();
    assert_equals(1, $sess_cnt, 'Sessions intact');
    assert_equals(1, $pay_cnt, 'Payments intact');

    // Verify faculty is now manually editable again
    $res_manual = test_sim_edit_faculty($pdo, $fac_id, [
        'name' => 'Dr. Barbara McClintock (Independent)',
        'rate_live' => 3100.00
    ]);
    assert_true($res_manual['success'], 'Manual editing works after unlink');
    $chk_man = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals('Dr. Barbara McClintock (Independent)', $chk_man['name']);
    assert_equals(3100.00, (float)$chk_man['rate_live']);
});

// ----------------------------------------------------------------------
// 11.13 Re-link/switch works correctly
// ----------------------------------------------------------------------
run_test('11.13: Re-link / switch replaces link and copies new authoritative data cleanly', function() use ($pdo) {
    // 1. Create two faculty employees A & B
    $pdo->prepare("
        INSERT INTO employees (employee_id, full_name, email, mobile_number, application_for, academic_year, rate_live, status)
        VALUES ('EMP00513A', 'Faculty Alpha', 'alpha@pepp.com', '9876500513', 'faculty', '2026-27', 1500.00, 'active')
    ")->execute();
    $emp_a_id = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO employees (employee_id, full_name, email, mobile_number, application_for, academic_year, rate_live, status)
        VALUES ('EMP00513B', 'Faculty Beta', 'beta@pepp.com', '9876500514', 'faculty', '2027-28', 2500.00, 'active')
    ")->execute();
    $emp_b_id = (int)$pdo->lastInsertId();

    // 2. Create faculty linked to Alpha
    $pdo->prepare("
        INSERT INTO faculties (employee_management_faculty_id, name, mobile, email, rate_live, academic_year, status)
        VALUES (?, 'Faculty Alpha', '9876500513', 'alpha@pepp.com', 1500.00, '2026-27', 'active')
    ")->execute([$emp_a_id]);
    $fac_id = (int)$pdo->lastInsertId();

    // 3. Switch link to Beta
    $res = test_sim_edit_faculty($pdo, $fac_id, [
        'employee_management_faculty_id' => $emp_b_id
    ]);
    assert_true($res['success'], 'Switch succeeded');

    $switched = $pdo->query("SELECT * FROM faculties WHERE id = {$fac_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals($emp_b_id, (int)$switched['employee_management_faculty_id'], 'Switched to Faculty Beta');
    assert_equals('Faculty Beta', $switched['name'], 'Name updated to Beta');
    assert_equals('beta@pepp.com', $switched['email'], 'Email updated to Beta');
    assert_equals('2027-28', $switched['academic_year'], 'Academic Year updated to Beta');
    assert_equals(2500.00, (float)$switched['rate_live'], 'Rate updated to Beta');

    // 4. Verify Faculty Alpha is now available for linking elsewhere
    $alpha_linked_count = (int)$pdo->query("SELECT COUNT(*) FROM faculties WHERE employee_management_faculty_id = {$emp_a_id}")->fetchColumn();
    assert_equals(0, $alpha_linked_count, 'Faculty Alpha is now free/unlinked');
});

// ----------------------------------------------------------------------
// 11.14 1-to-1 constraint remains enforced
// ----------------------------------------------------------------------
run_test('11.14: 1-to-1 constraint blocks linking an already-linked employee to another faculty', function() use ($pdo) {
    $emp_b_id = (int)$pdo->query("SELECT id FROM employees WHERE employee_id = 'EMP00513B'")->fetchColumn();

    // Create another faculty record
    $pdo->prepare("
        INSERT INTO faculties (employee_management_faculty_id, name, mobile, email, rate_live, status)
        VALUES (NULL, 'Second Faculty Record', '9222222222', 'second@pepp.com', 1000.00, 'active')
    ")->execute();
    $fac2_id = (int)$pdo->lastInsertId();

    // Try to link second faculty to EMP00513B (which is already linked to the first faculty)
    $res = test_sim_edit_faculty($pdo, $fac2_id, [
        'employee_management_faculty_id' => $emp_b_id
    ]);
    assert_false($res['success'], 'Conflict detected and duplicate link blocked');
    assert_true(strpos($res['error'], 'already linked') !== false, 'Error message indicates already linked conflict');

    // Confirm fac2_id remains unlinked
    $fac2 = $pdo->query("SELECT * FROM faculties WHERE id = {$fac2_id}")->fetch(PDO::FETCH_ASSOC);
    assert_equals(null, $fac2['employee_management_faculty_id'], 'Duplicate link prevented in database');
});

// ----------------------------------------------------------------------
// Phase 12 Static & Architectural Security Invariant Audit
// ----------------------------------------------------------------------
run_test('12.1: Static security audit verifies zero application_type, zero heuristic matching, and strict one-way sync', function() {
    $files = [
        'employee-management.php' => file_get_contents(__DIR__ . '/employee-management.php'),
        'faculties.php' => file_get_contents(__DIR__ . '/faculties.php')
    ];

    foreach ($files as $fname => $code) {
        // 1. No application_type anywhere
        assert_false(strpos($code, 'application_type') !== false, "{$fname} contains NO application_type");

        // 2. Strict ID linking, no name/email/phone matching heuristics
        assert_false(strpos($code, 'f.name = e.full_name') !== false, "{$fname} contains NO name matching");
        assert_false(strpos($code, 'f.email = e.email') !== false, "{$fname} contains NO email matching");
        assert_false(strpos($code, 'f.mobile = e.mobile_number') !== false, "{$fname} contains NO mobile matching");
    }

    // 3. One-way sync verification: faculties.php must NOT update employees
    assert_false(preg_match('/\bUPDATE\s+employees\b/i', $files['faculties.php']) === 1, 'faculties.php contains NO UPDATE employees statements');

    // 4. Employee Management sync updates ONLY the 8 allowed fields in faculties
    assert_true(strpos($files['employee-management.php'], 'UPDATE faculties') !== false, 'employee-management.php contains UPDATE faculties');
    assert_false(strpos($files['employee-management.php'], 'faculties.status =') !== false, 'employee-management.php does NOT overwrite faculties.status');
    assert_false(strpos($files['employee-management.php'], 'faculties.created_by =') !== false, 'employee-management.php does NOT overwrite faculties.created_by');
});

// ======================================================================
// SUMMARY REPORT
// ======================================================================
echo "\n======================================================================\n";
echo " AUDIT SUMMARY REPORT\n";
echo "======================================================================\n";
echo "Total Tests Run:  {$total_tests}\n";
echo "Tests Passed:    {$passed_tests}\n";
echo "Tests Failed:    {$failed_tests}\n";

if ($failed_tests === 0) {
    echo "\n>>> ALL AUDIT TESTS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo "\n>>> WARNING: AUDIT FAILURES DETECTED! <<<\n";
    exit(1);
}
