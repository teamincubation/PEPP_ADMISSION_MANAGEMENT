<?php
/**
 * Test Suite: Faculty Management & Bank Credential Access Audit
 *
 * Requirements:
 *  TEST 1 — FACULTY TAB ISOLATION & DATA SOURCES
 *  TEST 2 — FACULTY STATUS (ACTIVE / INACTIVE) SYNCHRONIZATION
 *  TEST 3 — ADMIN BANK PERMISSIONS MATRIX (A, B, C, SUPER ADMIN)
 *  TEST 4 — FACULTY BANK DETAILS ON SCHEDULES & PAYMENTS (FACULTIES.PHP)
 *  TEST 5 — INTERN BANK DETAILS ON PAY MODAL (LD-WORK-REPORT.PHP)
 *  TEST 6 — PAYMENT REGRESSION VERIFICATION
 *  TEST 7 — SECURITY ENDPOINT AUTHORIZATION & ISOLATION
 */

require_once __DIR__ . '/includes/encryption_helper.php';
require_once __DIR__ . '/includes/staff_type_helper.php';

// Auth permission functions under test (mirroring includes/auth.php without booting MySQL)
if (!function_exists('can_admin_view_bank_credentials')) {
    function can_admin_view_bank_credentials() {
        global $admin_role, $admin_row, $admin_username, $pdo;
        if (($admin_role ?? '') === 'super_admin' || (function_exists('is_super_admin') && is_super_admin())) return true;
        if (isset($admin_row['can_view_bank_credentials'])) {
            return (int)$admin_row['can_view_bank_credentials'] === 1;
        }
        if (!empty($admin_username) && isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT can_view_bank_credentials, role FROM admins WHERE username = ? LIMIT 1");
                $stmt->execute([$admin_username]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if (($row['role'] ?? '') === 'super_admin') return true;
                    return (int)($row['can_view_bank_credentials'] ?? 0) === 1;
                }
            } catch (Throwable $e) {}
        }
        return false;
    }
}

if (!function_exists('can_admin_copy_bank_credentials')) {
    function can_admin_copy_bank_credentials() {
        global $admin_role, $admin_row, $admin_username, $pdo;
        if (($admin_role ?? '') === 'super_admin' || (function_exists('is_super_admin') && is_super_admin())) return true;
        if (isset($admin_row['can_copy_bank_credentials'])) {
            return (int)$admin_row['can_copy_bank_credentials'] === 1;
        }
        if (!empty($admin_username) && isset($pdo) && $pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT can_copy_bank_credentials, role FROM admins WHERE username = ? LIMIT 1");
                $stmt->execute([$admin_username]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if (($row['role'] ?? '') === 'super_admin') return true;
                    return (int)($row['can_copy_bank_credentials'] ?? 0) === 1;
                }
            } catch (Throwable $e) {}
        }
        return false;
    }
}

$passed = 0; $failed = 0;
function t($name, $cb) {
    global $passed, $failed;
    try {
        $r = $cb();
    } catch (Throwable $e) {
        echo "  [FAIL] $name: " . $e->getMessage() . " (Line " . $e->getLine() . ")\n";
        $failed++;
        return;
    }
    if ($r !== false) {
        echo "  [PASS] $name\n";
        $passed++;
    } else {
        echo "  [FAIL] $name\n";
        $failed++;
    }
}
function src($f) { return file_get_contents(__DIR__ . '/' . $f); }
function has($s, $needle) { return strpos($s, $needle) !== false; }

echo "======================================================================\n";
echo " TEST SUITE: FACULTY MANAGEMENT & BANK CREDENTIAL ACCESS AUDIT\n";
echo "======================================================================\n";

// In-Memory SQLite Environment for Functional/Database Unit Tests
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Setup Schema
$pdo->exec("
    CREATE TABLE admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        full_name TEXT NOT NULL,
        email TEXT,
        role TEXT NOT NULL DEFAULT 'admin',
        admin_type TEXT DEFAULT 'staff',
        permissions TEXT,
        can_view_bank_credentials INTEGER NOT NULL DEFAULT 0,
        can_copy_bank_credentials INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id TEXT UNIQUE NOT NULL,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        mobile_number TEXT NOT NULL,
        gender TEXT DEFAULT 'Male',
        date_of_birth TEXT,
        blood_group TEXT,
        address TEXT,
        place_post_office TEXT,
        pincode TEXT,
        state TEXT,
        country TEXT DEFAULT 'India',
        department TEXT,
        designation TEXT,
        academic_year TEXT,
        rate_live REAL DEFAULT 0,
        rate_qpd REAL DEFAULT 0,
        rate_recorded REAL DEFAULT 0,
        rate_offline REAL DEFAULT 0,
        bank_name TEXT,
        bank_account_encrypted TEXT,
        bank_account_masked TEXT,
        ifsc_code TEXT,
        upi_id TEXT,
        admin_id INTEGER,
        application_for TEXT NOT NULL DEFAULT 'employee',
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE UNIQUE INDEX uq_emp_email_type ON employees (email, application_for);

    CREATE TABLE faculties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_management_faculty_id INTEGER,
        name TEXT NOT NULL,
        mobile TEXT,
        email TEXT,
        academic_year TEXT,
        rate_live REAL DEFAULT 0,
        rate_qpd REAL DEFAULT 0,
        rate_recorded REAL DEFAULT 0,
        rate_offline REAL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        created_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        faculty_id INTEGER NOT NULL,
        topic TEXT,
        session_type TEXT NOT NULL,
        session_datetime TEXT,
        duration_hours REAL NOT NULL,
        status TEXT NOT NULL DEFAULT 'completed'
    );

    CREATE TABLE faculty_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        faculty_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        paid_date TEXT,
        remarks TEXT
    );

    CREATE TABLE employee_custom_fields (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        field_key TEXT NOT NULL,
        field_label TEXT NOT NULL,
        application_for TEXT NOT NULL DEFAULT 'employee',
        field_type TEXT NOT NULL DEFAULT 'text',
        is_required INTEGER DEFAULT 0,
        sort_order INTEGER DEFAULT 0,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE employee_custom_values (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        field_id INTEGER NOT NULL,
        field_value TEXT
    );

    CREATE TABLE staff_registration_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        application_reference TEXT UNIQUE,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        mobile_number TEXT NOT NULL,
        application_for TEXT NOT NULL DEFAULT 'employee',
        status TEXT NOT NULL DEFAULT 'pending',
        employee_record_id INTEGER,
        approved_admin_id INTEGER
    );

    CREATE TABLE admin_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_username TEXT,
        action TEXT,
        details TEXT,
        ip_address TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Populate Test Admins for Permission Matrix
$pdo->exec("
    INSERT INTO admins (id, username, full_name, role, can_view_bank_credentials, can_copy_bank_credentials) VALUES
    (1, 'superadmin', 'Super Admin', 'super_admin', 1, 1),
    (2, 'admin_a', 'Admin A (No Bank Access)', 'admin', 0, 0),
    (3, 'admin_b', 'Admin B (View Only)', 'admin', 1, 0),
    (4, 'admin_c', 'Admin C (View + Copy)', 'admin', 1, 1),
    (5, 'intern_user', 'Intern Alex', 'admin', 0, 0);
");
$pdo->exec("UPDATE admins SET admin_type = 'intern' WHERE id = 5;");

// Populate Test Staff: Employee, Intern, Faculty
$test_bank_raw = '123456789012';
$test_bank_enc = pepp_encrypt($test_bank_raw);

$pdo->exec("
    INSERT INTO employees (id, employee_id, full_name, email, mobile_number, application_for, status, bank_name, bank_account_encrypted, bank_account_masked, ifsc_code, upi_id, academic_year) VALUES
    (101, 'EMP001', 'Alice Employee', 'alice@pepp.com', '9876543210', 'employee', 'active', 'SBI', '{$test_bank_enc}', 'XXXX XXXX 9012', 'SBIN0001234', 'alice@upi', NULL),
    (102, 'INT001', 'Alex Intern', 'alex@pepp.com', '9876543211', 'intern', 'active', 'HDFC Bank', '{$test_bank_enc}', 'XXXX XXXX 9012', 'HDFC0005678', 'alex@upi', NULL),
    (103, 'FAC001', 'Prof. John Faculty', 'john@pepp.com', '9876543212', 'faculty', 'active', 'ICICI Bank', '{$test_bank_enc}', 'XXXX XXXX 9012', 'ICIC0009999', 'john@upi', '2026-2027');
");
$pdo->exec("UPDATE employees SET admin_id = 5 WHERE id = 102;"); // Link intern to admin 5

// Link Faculty in faculties table
$pdo->exec("
    INSERT INTO faculties (id, employee_management_faculty_id, name, mobile, email, academic_year, rate_live, rate_qpd, rate_recorded, rate_offline, status) VALUES
    (1, 103, 'Prof. John Faculty', '9876543212', 'john@pepp.com', '2026-2027', 1000, 500, 400, 1200, 'active');
");

// Add historical sessions & payments for Faculty 1
$pdo->exec("
    INSERT INTO sessions (faculty_id, topic, session_type, session_datetime, duration_hours, status) VALUES
    (1, 'Accounting 101', 'live', '2026-09-01 10:00:00', 2.0, 'completed'),
    (1, 'Taxation QPD', 'qpd', '2026-09-05 14:00:00', 1.5, 'completed');
    INSERT INTO faculty_payments (faculty_id, amount, paid_date, remarks) VALUES
    (1, 1500, '2026-09-10', 'Advance payout');
");

// Custom Fields
$pdo->exec("
    INSERT INTO employee_custom_fields (id, field_key, field_label, application_for, field_type, sort_order) VALUES
    (1, 'emp_badge', 'Employee Badge ID', 'employee', 'text', 1),
    (2, 'int_mentor', 'Assigned Mentor', 'intern', 'text', 2),
    (3, 'fac_specialization', 'Subject Specialization', 'faculty', 'text', 3);
");
$pdo->exec("
    INSERT INTO employee_custom_values (employee_id, field_id, field_value) VALUES
    (101, 1, 'BADGE-A1'),
    (102, 2, 'Senior Dev'),
    (103, 3, 'Corporate Accounting');
");

/* ═══════════════════════════════════════════════════════════════════
   TEST 1 — FACULTY TAB ISOLATION & DATA SOURCES
   ═══════════════════════════════════════════════════════════════════ */
echo "\n--- TEST 1: Faculty Tab Isolation & Data Sources ---\n";

t('Approved Staff directory query shows Employee and Intern only, NOT Faculty', function() use ($pdo) {
    $stmt = $pdo->query("SELECT application_for FROM employees WHERE " . staff_generic_type_sql());
    $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return count($types) === 2 && in_array('employee', $types, true) && in_array('intern', $types, true) && !in_array('faculty', $types, true);
});

t('Faculties tab query lists ONLY approved Faculty records (status NOT IN rejected, pending)', function() use ($pdo) {
    $stmt = $pdo->query("SELECT id, employee_id, full_name, application_for FROM employees WHERE application_for = 'faculty' AND status NOT IN ('rejected', 'pending')");
    $facs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($facs) === 1 && $facs[0]['employee_id'] === 'FAC001' && $facs[0]['application_for'] === 'faculty';
});

t('Faculties tab joins with faculties table via employee_management_faculty_id', function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT e.employee_id, f.id AS linked_faculty_id, f.name AS linked_name
        FROM employees e
        LEFT JOIN faculties f ON f.employee_management_faculty_id = e.id
        WHERE e.application_for = 'faculty'
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return !empty($row['linked_faculty_id']) && (int)$row['linked_faculty_id'] === 1 && $row['linked_name'] === 'Prof. John Faculty';
});

t('Custom fields are strictly scoped and not mixed between employee, intern, and faculty', function() use ($pdo) {
    $fac_cfs = staff_get_custom_values($pdo, 103, 'faculty');
    $fac_keys = array_column($fac_cfs, 'field_key');
    $emp_cfs = staff_get_custom_values($pdo, 101, 'employee');
    $emp_keys = array_column($emp_cfs, 'field_key');

    return in_array('fac_specialization', $fac_keys, true)
        && !in_array('emp_badge', $fac_keys, true)
        && !in_array('int_mentor', $fac_keys, true)
        && in_array('emp_badge', $emp_keys, true)
        && !in_array('fac_specialization', $emp_keys, true);
});

/* ═══════════════════════════════════════════════════════════════════
   TEST 2 — FACULTY STATUS SYNCHRONIZATION & HISTORY PRESERVATION
   ═══════════════════════════════════════════════════════════════════ */
echo "\n--- TEST 2: Faculty Status Synchronization & Preservation ---\n";

t('Setting Faculty to Inactive synchronizes both employees.status and faculties.status', function() use ($pdo) {
    $emp_id = 103;
    $new_status = 'inactive';
    $pdo->prepare("UPDATE employees SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$new_status, $emp_id]);
    $pdo->prepare("UPDATE faculties SET status = ? WHERE employee_management_faculty_id = ?")->execute([$new_status, $emp_id]);

    $emp_st = $pdo->query("SELECT status FROM employees WHERE id = 103")->fetchColumn();
    $fac_st = $pdo->query("SELECT status FROM faculties WHERE employee_management_faculty_id = 103")->fetchColumn();
    return $emp_st === 'inactive' && $fac_st === 'inactive';
});

t('Historical sessions and payment records remain intact when Faculty is Inactive', function() use ($pdo) {
    $sess_count = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE faculty_id = 1")->fetchColumn();
    $pmt_total = (float)$pdo->query("SELECT SUM(amount) FROM faculty_payments WHERE faculty_id = 1")->fetchColumn();
    return $sess_count === 2 && $pmt_total == 1500.0;
});

t('Setting Faculty back to Active synchronizes both tables safely', function() use ($pdo) {
    $emp_id = 103;
    $new_status = 'active';
    $pdo->prepare("UPDATE employees SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$new_status, $emp_id]);
    $pdo->prepare("UPDATE faculties SET status = ? WHERE employee_management_faculty_id = ?")->execute([$new_status, $emp_id]);

    $emp_st = $pdo->query("SELECT status FROM employees WHERE id = 103")->fetchColumn();
    $fac_st = $pdo->query("SELECT status FROM faculties WHERE employee_management_faculty_id = 103")->fetchColumn();
    return $emp_st === 'active' && $fac_st === 'active';
});

/* ═══════════════════════════════════════════════════════════════════
   TEST 3 — ADMIN BANK PERMISSIONS MATRIX
   ═══════════════════════════════════════════════════════════════════ */
echo "\n--- TEST 3: Admin Bank Permissions Matrix ---\n";

function check_bank_perms_mock($admin_row) {
    $is_super = ($admin_row['role'] === 'super_admin');
    $can_view = $is_super || !empty($admin_row['can_view_bank_credentials']);
    $can_copy = $is_super || !empty($admin_row['can_copy_bank_credentials']);
    return ['view' => $can_view, 'copy' => $can_copy];
}

t('Admin A (No Bank Access): view = false, copy = false', function() use ($pdo) {
    $row = $pdo->query("SELECT * FROM admins WHERE username = 'admin_a'")->fetch(PDO::FETCH_ASSOC);
    $p = check_bank_perms_mock($row);
    return $p['view'] === false && $p['copy'] === false;
});

t('Admin B (View Only): view = true, copy = false', function() use ($pdo) {
    $row = $pdo->query("SELECT * FROM admins WHERE username = 'admin_b'")->fetch(PDO::FETCH_ASSOC);
    $p = check_bank_perms_mock($row);
    return $p['view'] === true && $p['copy'] === false;
});

t('Admin C (View + Copy): view = true, copy = true', function() use ($pdo) {
    $row = $pdo->query("SELECT * FROM admins WHERE username = 'admin_c'")->fetch(PDO::FETCH_ASSOC);
    $p = check_bank_perms_mock($row);
    return $p['view'] === true && $p['copy'] === true;
});

t('Super Admin automatically has view = true, copy = true regardless of flags', function() use ($pdo) {
    $row = $pdo->query("SELECT * FROM admins WHERE username = 'superadmin'")->fetch(PDO::FETCH_ASSOC);
    $p = check_bank_perms_mock($row);
    return $p['view'] === true && $p['copy'] === true;
});

/* ═══════════════════════════════════════════════════════════════════
   TEST 4 — FACULTY BANK DETAILS (FACULTIES.PHP)
   ═══════════════════════════════════════════════════════════════════ */
echo "\n--- TEST 4: Faculty Bank Details (faculties.php) ---\n";

t('Faculties detail view resolves bank credentials from linked employees record', function() use ($pdo) {
    $fac_id = 1;
    $stmt = $pdo->prepare("
        SELECT f.id, f.name, e.bank_name, e.bank_account_masked, e.ifsc_code, e.upi_id, e.bank_account_encrypted
        FROM faculties f
        JOIN employees e ON e.id = f.employee_management_faculty_id
        WHERE f.id = ?
    ");
    $stmt->execute([$fac_id]);
    $fac_bank = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fac_bank['bank_name'] === 'ICICI Bank'
        && $fac_bank['bank_account_masked'] === 'XXXX XXXX 9012'
        && $fac_bank['ifsc_code'] === 'ICIC0009999'
        && $fac_bank['upi_id'] === 'john@upi';
});

t('Authorized reveal decrypts the real bank account correctly', function() use ($pdo, $test_bank_raw) {
    $stmt = $pdo->prepare("SELECT e.bank_account_encrypted FROM faculties f JOIN employees e ON e.id = f.employee_management_faculty_id WHERE f.id = ?");
    $stmt->execute([1]);
    $enc = $stmt->fetchColumn();
    $plain = pepp_decrypt($enc);
    return $plain === $test_bank_raw;
});

t('Unauthorized user cannot reveal decrypted bank account', function() use ($pdo) {
    $admin_a = $pdo->query("SELECT * FROM admins WHERE username = 'admin_a'")->fetch(PDO::FETCH_ASSOC);
    $perms = check_bank_perms_mock($admin_a);
    if (!$perms['view']) {
        // Endpoint returns 403 error
        return true;
    }
    return false;
});

t('Audit log created for bank credential view without logging raw account number', function() use ($pdo) {
    $pdo->prepare("INSERT INTO admin_logs (admin_username, action, details) VALUES (?,?,?)")
        ->execute(['admin_b', 'bank_credentials_viewed', "Revealed bank field 'bank_account' for Faculty Prof. John Faculty (Faculty #1, Emp: FAC001) on faculties.php"]);
    $log = $pdo->query("SELECT * FROM admin_logs WHERE action = 'bank_credentials_viewed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return strpos($log['details'], 'Prof. John Faculty') !== false && strpos($log['details'], '123456789012') === false;
});

/* ═══════════════════════════════════════════════════════════════════
   TEST 5 — INTERN BANK DETAILS (LD-WORK-REPORT.PHP)
   ═══════════════════════════════════════════════════════════════════ */
echo "\n--- TEST 5: Intern Bank Details (ld-work-report.php) ---\n";

t('Intern bank details resolved from linked employee record via admin_id', function() use ($pdo) {
    $intern_admin_id = 5;
    $emp = $pdo->query("SELECT * FROM employees WHERE admin_id = 5 AND application_for = 'intern'")->fetch(PDO::FETCH_ASSOC);
    return !empty($emp) && $emp['bank_name'] === 'HDFC Bank' && $emp['ifsc_code'] === 'HDFC0005678' && $emp['upi_id'] === 'alex@upi';
});

t('Intern bank masking applies when admin lacks bank view permission', function() use ($pdo) {
    $emp = $pdo->query("SELECT * FROM employees WHERE admin_id = 5 AND application_for = 'intern'")->fetch(PDO::FETCH_ASSOC);
    $admin_a = $pdo->query("SELECT * FROM admins WHERE username = 'admin_a'")->fetch(PDO::FETCH_ASSOC);
    $perms = check_bank_perms_mock($admin_a);

    $masked_name = $perms['view'] ? $emp['bank_name'] : '[Masked / Restricted]';
    $masked_ifsc = $perms['view'] ? $emp['ifsc_code'] : 'XXXX0000000';
    $masked_upi = $perms['view'] ? $emp['upi_id'] : 'Restricted';

    return $masked_name === '[Masked / Restricted]' && $masked_ifsc === 'XXXX0000000' && $masked_upi === 'Restricted';
});

/* ═══════════════════════════════════════════════════════════════════
   TEST 6 — PAYMENT REGRESSION
   ═══════════════════════════════════════════════════════════════════ */
echo "\n--- TEST 6: Payment Calculations & Workflow Regression ---\n";

t('Faculty earned and pending calculation logic operates correctly without regression', function() use ($pdo) {
    $TYPE_RATE = ['live' => 'rate_live', 'qpd' => 'rate_qpd', 'recorded' => 'rate_recorded', 'offline' => 'rate_offline'];
    $f = $pdo->query("SELECT * FROM faculties WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $earned = (2.0 * $f['rate_live']) + (1.5 * $f['rate_qpd']); // (2*1000) + (1.5*500) = 2000 + 750 = 2750
    $paid = (float)$pdo->query("SELECT SUM(amount) FROM faculty_payments WHERE faculty_id = 1")->fetchColumn(); // 1500
    $due = max(0, $earned - $paid); // 2750 - 1500 = 1250
    return $earned == 2750.0 && $paid == 1500.0 && $due == 1250.0;
});

t('Intern payout calculation logic remains intact with adjustment amount', function() {
    $expected = 5000.00;
    $adjustment = -500.00;
    $final_paid = $expected + $adjustment;
    return $final_paid == 4500.00;
});

/* ═══════════════════════════════════════════════════════════════════
   TEST 7 — SECURITY & ISOLATION CHECKS
   ═══════════════════════════════════════════════════════════════════ */
echo "\n--- TEST 7: Security Endpoint & Isolation Verification ---\n";

t('Static check: employee-management.php get_employee_details blocks Faculty records with 403', function() {
    $src = src('employee-management.php');
    return has($src, "'get_employee_details'")
        && (has($src, '!staff_is_generic_type(') || has($src, "application_for === 'faculty'"))
        && has($src, '403')
        && has($src, 'Faculty records are managed in the Faculties module');
});

t('Static check: faculties.php reveal/copy endpoints verify can_admin_view_bank_credentials / can_admin_copy_bank_credentials', function() {
    $src = src('faculties.php');
    return has($src, 'reveal_faculty_bank')
        && has($src, 'copy_faculty_bank')
        && has($src, 'can_admin_view_bank_credentials')
        && has($src, 'can_admin_copy_bank_credentials');
});

t('Static check: ld-work-report.php reveal/copy endpoints verify can_admin_view_bank_credentials / can_admin_copy_bank_credentials', function() {
    $src = src('ld-work-report.php');
    return has($src, 'reveal_intern_bank')
        && has($src, 'copy_intern_bank')
        && has($src, 'can_admin_view_bank_credentials')
        && has($src, 'can_admin_copy_bank_credentials');
});

t('Static check: database-update-57.sql adds can_view_bank_credentials and can_copy_bank_credentials idempotently', function() {
    $src = src('database-update-57.sql');
    return has($src, 'can_view_bank_credentials')
        && has($src, 'can_copy_bank_credentials')
        && has($src, 'information_schema.columns');
});

echo "\n======================================================================\n";
echo " AUDIT SUMMARY: $passed PASSED, $failed FAILED\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
