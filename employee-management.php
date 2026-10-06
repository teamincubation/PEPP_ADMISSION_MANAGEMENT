<?php
/**
 * PEPP Learning ERP — Employee Management (Super Admin Only)
 * - Approved Staff Directory & Profile Management
 * - Server-Side Filtering (Search, Status, Type, Link Status)
 * - Staff Profile View / Edit (Personal, Employment, KYC, Bank)
 * - Masked KYC & Bank Data by Default (Zero exposure in initial HTML)
 * - Secure Authenticated Reveal & Copy AJAX Endpoints with Full Audit Trails
 * - Canonical Staff Employment Status Management (Separate from Admin & Student status)
 * - Staff Registration Requests Workflow (Pending / Under Review / Approved / Rejected)
 * - Atomic Employee ID + Appointment Reference Generation & Immutable PDF Snapshot
 * - Custom Fields Management & Field Value Persistence
 */

declare(strict_types=1);

require_once 'includes/auth.php';
require_permission('employee-management');
require_once 'includes/encryption_helper.php';
require_once 'includes/file_helper.php';
require_once 'includes/staff_type_helper.php';
require_once 'includes/guest_faculty_helper.php';
require_once 'includes/policy_helper.php';

$active_page = 'employee-management';
$page_title  = 'Employee Management';
$page_sub    = 'Staff records, applications & appointments';

$success_message = '';
$error_message = '';

// ── Canonical Staff Employment Statuses ──────────────────────────────
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

// ── Self-healing: ensure tables and columns exist ─────────────────────
function emp_tables_exist($pdo): bool {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'employees'")->fetchColumn(); }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}
function srr_tables_exist($pdo): bool {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'staff_registration_requests'")->fetchColumn(); }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}
function get_employee_custom_field_columns($pdo): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = [];
    try {
        $stmt = $pdo->query("SELECT * FROM employee_custom_fields LIMIT 0");
        $count = $stmt->columnCount();
        for ($i = 0; $i < $count; $i++) {
            $m = $stmt->getColumnMeta($i);
            if ($m && isset($m['name'])) {
                $cols[] = strtolower($m['name']);
            }
        }
    } catch (Exception $e) {}
    return $cols;
}

if (emp_tables_exist($pdo) && !defined('PEPP_DB_SCHEMA_VERSION')) {
    try {
        $pdo->exec("ALTER TABLE employees MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN linked_at DATETIME DEFAULT NULL, ADD COLUMN linked_by VARCHAR(100) DEFAULT NULL");
    } catch (Exception $e) {}
}

function get_table_columns_safe($pdo, string $table): array {
    try {
        return $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        try {
            $cols = [];
            $rows = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (isset($r['name'])) $cols[] = $r['name'];
            }
            return $cols;
        } catch (Exception $e2) {
            return [];
        }
    }
}

$academic_years = [];
try {
    $academic_years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($academic_years)) $academic_years = ['2026-27', '2025-26', '2024-25'];
} catch (Exception $e) {
    $academic_years = ['2026-27', '2025-26', '2024-25'];
}

// ── Download appointment PDF ──────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'appointment_pdf' && isset($_GET['id'])) {
    $table = ($_GET['source'] ?? '') === 'employee' ? 'employees' : 'staff_registration_requests';
    try {
        $stmt = $pdo->prepare("SELECT appointment_snapshot, appointment_reference, application_for FROM {$table} WHERE id = ? LIMIT 1");
        $safe_id = (int)$_GET['id'];
        $stmt->execute([$safe_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Employee/application record not found for ID: " . $safe_id);
        }
        if ($table === 'employees' && !staff_is_generic_type($row['application_for'] ?? 'employee')) {
            http_response_code(403);
            throw new RuntimeException("Faculty records are managed in the Faculties module (faculties.php).");
        }
        if (empty($row['appointment_snapshot'])) {
            throw new RuntimeException("Appointment snapshot is missing for ID: " . $safe_id);
        }

        require_once 'includes/appointment_pdf.php';
        $pdf_bytes = render_appointment_pdf($row['appointment_snapshot']);

        $d = json_decode($row['appointment_snapshot'], true);
        $emp_id = $d['employee_id'] ?? 'UNKNOWN';
        $filename = 'PEPP_Appointment_Letter_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $emp_id) . '.pdf';

        // Log download event
        log_admin_activity($pdo, $admin_username, 'appointment_letter_generated', 'Generated and downloaded appointment letter for Ref: ' . $row['appointment_reference']);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_bytes));
        echo $pdf_bytes;
        exit;
    } catch (Exception $e) {
        error_log('appointment_pdf error: ' . $e->getMessage());
        http_response_code(500);
        echo 'Appointment letter could not be generated. Please try again or contact the system administrator.';
        exit;
    }
}

// ── AJAX: Load Employee Details (Masked KYC & Bank only) ──────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_employee_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (!emp_tables_exist($pdo)) { echo json_encode(['error' => 'Tables not ready']); exit; }
    try {
        $has_fac_link = false;
        try {
            $has_fac_link = (bool)$pdo->query("SHOW COLUMNS FROM faculties LIKE 'employee_management_faculty_id'")->fetchColumn();
        } catch (Exception $e) {
            try {
                $pdo->query("SELECT employee_management_faculty_id FROM faculties LIMIT 1");
                $has_fac_link = true;
            } catch (Exception $e2) {
                $has_fac_link = false;
            }
        }

        if ($has_fac_link) {
            $stmt = $pdo->prepare("
                SELECT e.*,
                       a.username AS linked_admin_username,
                       a.full_name AS linked_admin_name,
                       a.email AS linked_admin_email,
                       a.phone AS linked_admin_phone,
                       a.role AS linked_admin_role,
                       f.id AS linked_faculty_id,
                       f.name AS linked_faculty_name
                FROM employees e
                LEFT JOIN admins a ON e.admin_id = a.id
                LEFT JOIN faculties f ON f.employee_management_faculty_id = e.id
                WHERE e.id = ? LIMIT 1
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT e.*,
                       a.username AS linked_admin_username,
                       a.full_name AS linked_admin_name,
                       a.email AS linked_admin_email,
                       a.phone AS linked_admin_phone,
                       a.role AS linked_admin_role,
                       NULL AS linked_faculty_id,
                       NULL AS linked_faculty_name
                FROM employees e
                LEFT JOIN admins a ON e.admin_id = a.id
                WHERE e.id = ? LIMIT 1
            ");
        }
        $stmt->execute([(int)$_GET['id']]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { echo json_encode(['error' => 'Employee record not found.']); exit; }
        if (!staff_is_generic_type($emp['application_for'] ?? 'employee')) {
            http_response_code(403);
            echo json_encode(['error' => 'Faculty records are managed in the Faculties module (faculties.php), not in Employee Management.']);
            exit;
        }

        // NEVER send encrypted ciphertexts over wire
        unset($emp['aadhaar_encrypted'], $emp['bank_account_encrypted']);

        // Load custom fields with values (canonical schema compatibility)
        $custom_fields = [];
        try {
            $stmt_cf = $pdo->prepare("
                SELECT cf.*, cv.field_value
                FROM employee_custom_fields cf
                LEFT JOIN employee_custom_values cv ON cf.id = cv.field_id AND cv.employee_id = ?
                WHERE cf.status = 'active'
                ORDER BY cf.sort_order ASC, cf.id ASC
            ");
            $stmt_cf->execute([(int)$_GET['id']]);
            $custom_fields = $stmt_cf->fetchAll(PDO::FETCH_ASSOC);

            // Normalize field labels and dropdown options for consistent frontend rendering
            foreach ($custom_fields as &$cf_row) {
                if (!isset($cf_row['field_label']) && isset($cf_row['field_name'])) {
                    $cf_row['field_label'] = $cf_row['field_name'];
                }
                if (!isset($cf_row['field_options']) && isset($cf_row['dropdown_options'])) {
                    $cf_row['field_options'] = $cf_row['dropdown_options'];
                }
            }
            unset($cf_row);
            // Scope to the employee's own application type (employee|intern)
            $custom_fields = array_values(array_filter(
                staff_normalize_custom_field_rows($custom_fields),
                fn($r) => $r['application_for'] === staff_normalize_type($emp['application_for'] ?? '', 'employee')
            ));
        } catch (Exception $e) {
            $custom_fields = [];
        }

        echo json_encode([
            'success' => true,
            'employee' => $emp,
            'custom_fields' => $custom_fields
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Reveal Sensitive Data (Server-side Decrypt & Audit Log) ─────
if (isset($_POST['action']) && $_POST['action'] === 'reveal_sensitive_data') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (!csrf_verify()) { echo json_encode(['error' => 'Security token mismatch.']); exit; }
    $emp_id = (int)($_POST['id'] ?? 0);
    $field = trim($_POST['field'] ?? ''); // 'aadhaar' or 'bank_account'
    if (!in_array($field, ['aadhaar', 'bank_account'], true)) {
        echo json_encode(['error' => 'Invalid sensitive field requested.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, employee_id, full_name, aadhaar_encrypted, bank_account_encrypted, application_for FROM employees WHERE id = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { echo json_encode(['error' => 'Employee not found.']); exit; }

        if ($field === 'bank_account') {
            if (!can_admin_view_bank_credentials()) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: cannot view bank credentials.']);
                exit;
            }
        }

        // Generic employee management reveal endpoint is restricted to generic staff
        if (!staff_is_generic_type($emp['application_for'] ?? 'employee')) {
            http_response_code(403);
            echo json_encode(['error' => 'Faculty records are managed in the Faculties module (faculties.php).']);
            exit;
        }

        $cipher = ($field === 'aadhaar') ? $emp['aadhaar_encrypted'] : $emp['bank_account_encrypted'];
        $plain = $cipher ? pepp_decrypt($cipher) : '';

        // Audit log: NEVER persist decrypted plaintext in activity logs
        if ($field === 'bank_account') {
            log_admin_activity($pdo, $admin_username, 'bank_credentials_viewed', "Revealed Bank Account Number for staff {$emp['full_name']} ({$emp['employee_id']})");
        } else {
            $field_label = ($field === 'aadhaar') ? 'Aadhaar Number' : 'Bank Account Number';
            log_admin_activity($pdo, $admin_username, 'sensitive_data_reveal', "Revealed {$field_label} for staff {$emp['full_name']} ({$emp['employee_id']})");
        }

        echo json_encode(['success' => true, 'field' => $field, 'value' => $plain]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to decrypt sensitive data.']);
    }
    exit;
}

// ── AJAX: Copy Sensitive Data (Server-side Decrypt & Audit Log) ───────
if (isset($_POST['action']) && $_POST['action'] === 'copy_sensitive_data') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (!csrf_verify()) { echo json_encode(['error' => 'Security token mismatch.']); exit; }
    $emp_id = (int)($_POST['id'] ?? 0);
    $field = trim($_POST['field'] ?? ''); // 'bank_account', 'aadhaar', 'ifsc_code', 'upi_id'
    if (!in_array($field, ['bank_account', 'aadhaar', 'ifsc_code', 'upi_id'], true)) {
        echo json_encode(['error' => 'Invalid copy field requested.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, employee_id, full_name, aadhaar_encrypted, bank_account_encrypted, ifsc_code, upi_id, application_for FROM employees WHERE id = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { echo json_encode(['error' => 'Employee not found.']); exit; }

        if (in_array($field, ['bank_account', 'ifsc_code', 'upi_id'], true)) {
            if (!can_admin_copy_bank_credentials()) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: cannot copy bank credentials.']);
                exit;
            }
        }

        // Generic employee management copy endpoint is restricted to generic staff
        if (!staff_is_generic_type($emp['application_for'] ?? 'employee')) {
            http_response_code(403);
            echo json_encode(['error' => 'Faculty records are managed in the Faculties module (faculties.php).']);
            exit;
        }

        $plain = '';
        if ($field === 'bank_account') {
            $plain = $emp['bank_account_encrypted'] ? pepp_decrypt($emp['bank_account_encrypted']) : '';
        } elseif ($field === 'aadhaar') {
            $plain = $emp['aadhaar_encrypted'] ? pepp_decrypt($emp['aadhaar_encrypted']) : '';
        } elseif ($field === 'ifsc_code') {
            $plain = (string)($emp['ifsc_code'] ?? '');
        } elseif ($field === 'upi_id') {
            $plain = (string)($emp['upi_id'] ?? '');
        }

        // Audit log: NEVER persist plaintext in logs
        $field_label = strtoupper(str_replace('_', ' ', $field));
        if (in_array($field, ['bank_account', 'ifsc_code', 'upi_id'], true)) {
            log_admin_activity($pdo, $admin_username, 'bank_credentials_copied', "Copied {$field_label} for staff {$emp['full_name']} ({$emp['employee_id']})");
        } else {
            log_admin_activity($pdo, $admin_username, 'sensitive_data_copy', "Copied {$field_label} for staff {$emp['full_name']} ({$emp['employee_id']})");
        }

        echo json_encode(['success' => true, 'field' => $field, 'value' => $plain]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to copy sensitive data.']);
    }
    exit;
}

// ── AJAX: Load Faculty Details for View/Edit Modal ────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_faculty_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (!emp_tables_exist($pdo)) { echo json_encode(['error' => 'Tables not ready']); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT e.*,
                   f.id AS linked_faculty_id,
                   f.name AS linked_faculty_name,
                   f.status AS linked_faculty_status,
                   f.academic_year AS linked_faculty_academic_year
            FROM employees e
            LEFT JOIN faculties f ON f.employee_management_faculty_id = e.id
            WHERE e.id = ? AND e.application_for = 'faculty' LIMIT 1
        ");
        $stmt->execute([(int)$_GET['id']]);
        $fac = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fac) {
            http_response_code(404);
            echo json_encode(['error' => 'Faculty record not found.']);
            exit;
        }

        // NEVER expose ciphertexts
        unset($fac['aadhaar_encrypted'], $fac['bank_account_encrypted']);

        // Check bank view permission
        $can_view_bank = can_admin_view_bank_credentials();
        $can_copy_bank = can_admin_copy_bank_credentials();

        if (!$can_view_bank) {
            $fac['bank_name'] = !empty($fac['bank_name']) ? '[Restricted]' : '';
            $fac['bank_account_masked'] = staff_mask_account_number($fac['bank_account_masked'] ?? '');
            $fac['ifsc_code'] = staff_mask_ifsc($fac['ifsc_code'] ?? '');
            $fac['upi_id'] = staff_mask_upi($fac['upi_id'] ?? '');
        }

        // Faculty-specific custom fields ONLY
        $custom_fields = [];
        if (function_exists('staff_get_custom_values')) {
            $custom_fields = staff_get_custom_values($pdo, (int)$fac['id'], 'faculty');
        }

        echo json_encode([
            'success' => true,
            'faculty' => $fac,
            'custom_fields' => $custom_fields,
            'can_view_bank' => $can_view_bank,
            'can_copy_bank' => $can_copy_bank
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Update Faculty Profile ──────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'update_faculty_profile') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['error' => 'Security token mismatch.']); exit; }
    $emp_id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND application_for = 'faculty' LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) {
            http_response_code(404);
            echo json_encode(['error' => 'Faculty record not found.']);
            exit;
        }

        $full_name = trim($_POST['full_name'] ?? '');
        $gender = trim($_POST['gender'] ?? '') ?: null;
        $dob = trim($_POST['dob'] ?? '') ?: null;
        $blood_group = trim($_POST['blood_group'] ?? '') ?: null;
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');
        $address = trim($_POST['permanent_address'] ?? '') ?: null;
        $state = trim($_POST['state'] ?? '') ?: null;
        $country = trim($_POST['country'] ?? 'India');
        $place = trim($_POST['place'] ?? '') ?: null;
        $pin = trim($_POST['pin'] ?? '') ?: null;
        $academic_year = trim($_POST['academic_year'] ?? '') ?: null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        if (!$full_name) { echo json_encode(['error' => 'Full Name is required.']); exit; }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['error' => 'Valid email address is required.']); exit; }
        if (!$mobile) { echo json_encode(['error' => 'Mobile number is required.']); exit; }
        if (!$academic_year) { echo json_encode(['error' => 'Academic Year is required.']); exit; }

        // Bank updates (only if provided and not masked placeholder)
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_account_raw = trim($_POST['bank_account_number'] ?? '');
        $ifsc_code = strtoupper(trim($_POST['ifsc_code'] ?? ''));
        $upi_id = trim($_POST['upi_id'] ?? '');

        $bank_account_encrypted = $emp['bank_account_encrypted'];
        $bank_account_masked = $emp['bank_account_masked'];

        if ($bank_account_raw !== '' && strpos($bank_account_raw, 'X') === false && strpos($bank_account_raw, 'x') === false) {
            $clean_acc = preg_replace('/\s+/', '', $bank_account_raw);
            if (strlen($clean_acc) >= 4) {
                $bank_account_encrypted = pepp_encrypt($clean_acc);
                $bank_account_masked = staff_mask_account_number($clean_acc);
            }
        }
        if ($bank_name === '' || strpos($bank_name, '[Restricted]') !== false) {
            $bank_name = $emp['bank_name'];
        }
        if ($ifsc_code === '' || strpos($ifsc_code, 'XXXX') !== false) {
            $ifsc_code = $emp['ifsc_code'];
        }
        if ($upi_id === '' || strpos($upi_id, 'Restricted') !== false) {
            $upi_id = $emp['upi_id'];
        }

        $pdo->beginTransaction();

        $stmt_upd = $pdo->prepare("
            UPDATE employees SET
                full_name = ?, gender = ?, dob = ?, blood_group = ?,
                email = ?, mobile_number = ?, permanent_address = ?,
                state = ?, country = ?, place = ?, pin = ?,
                academic_year = ?, status = ?,
                bank_name = ?, bank_account_encrypted = ?, bank_account_masked = ?,
                ifsc_code = ?, upi_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt_upd->execute([
            $full_name, $gender, $dob, $blood_group,
            $email, $mobile, $address,
            $state, $country, $place, $pin,
            $academic_year, $status,
            $bank_name, $bank_account_encrypted, $bank_account_masked,
            $ifsc_code, $upi_id,
            $emp_id
        ]);

        // Save faculty custom fields
        if (function_exists('staff_save_custom_values') && isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            staff_save_custom_values($pdo, $emp_id, $_POST['custom_fields'], 'faculty');
        }

        // Synchronize linked faculties row (name, email, mobile, status, academic_year) WITHOUT overwriting rates, sessions, or payments
        $pdo->prepare("
            UPDATE faculties SET
                name = ?, email = ?, mobile = ?, status = ?, academic_year = ?
            WHERE employee_management_faculty_id = ?
        ")->execute([$full_name, $email, $mobile, $status, $academic_year, $emp_id]);

        $pdo->commit();

        log_admin_activity($pdo, $admin_username, 'faculty_profile_updated', "Updated faculty profile {$full_name} ({$emp['employee_id']})");

        echo json_encode(['success' => true, 'message' => 'Faculty profile updated successfully.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Toggle Faculty Status (Active / Inactive) ───────────────────
if (isset($_POST['action']) && $_POST['action'] === 'toggle_faculty_status') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['error' => 'Security token mismatch.']); exit; }
    $emp_id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT id, employee_id, full_name, status FROM employees WHERE id = ? AND application_for = 'faculty' LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) {
            http_response_code(404);
            echo json_encode(['error' => 'Faculty record not found.']);
            exit;
        }

        $new_status = ($emp['status'] === 'active') ? 'inactive' : 'active';

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE employees SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$new_status, $emp_id]);

        // Synchronize linked faculties row status safely
        $pdo->prepare("UPDATE faculties SET status = ? WHERE employee_management_faculty_id = ?")->execute([$new_status, $emp_id]);
        $pdo->commit();

        log_admin_activity($pdo, $admin_username, 'faculty_status_changed', "Changed status of faculty {$emp['full_name']} ({$emp['employee_id']}) from {$emp['status']} to {$new_status}");

        echo json_encode(['success' => true, 'new_status' => $new_status, 'message' => "Faculty status changed to " . ucfirst($new_status)]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Change Staff Status ─────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'change_staff_status') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['error' => 'Security token mismatch.']); exit; }
    $emp_id = (int)($_POST['id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    global $CANONICAL_STAFF_STATUSES;
    if (!array_key_exists($new_status, $CANONICAL_STAFF_STATUSES)) {
        echo json_encode(['error' => 'Invalid staff employment status.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, employee_id, full_name, status, application_for FROM employees WHERE id = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { echo json_encode(['error' => 'Employee not found.']); exit; }
        if (!staff_is_generic_type($emp['application_for'] ?? 'employee')) { http_response_code(403); echo json_encode(['error' => 'Faculty status is managed in the Faculties module (faculties.php).']); exit; }

        $old_status = $emp['status'];
        $pdo->prepare("UPDATE employees SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$new_status, $emp_id]);

        $log_msg = "Changed status of staff {$emp['full_name']} ({$emp['employee_id']}) from {$old_status} to {$new_status}" . ($reason ? " (Reason: {$reason})" : "");
        log_admin_activity($pdo, $admin_username, 'staff_status_change', $log_msg);

        echo json_encode(['success' => true, 'message' => "Status updated to " . $CANONICAL_STAFF_STATUSES[$new_status]['label']]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Database error updating status: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Load application details ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'load_application' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    if (!srr_tables_exist($pdo)) { echo json_encode(['error' => 'Tables not ready']); exit; }
    try {
        $stmt = $pdo->prepare("SELECT * FROM staff_registration_requests WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_GET['id']]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) { echo json_encode(['error' => 'Not found']); exit; }
        // NEVER send encrypted values — only masked
        unset($r['aadhaar_encrypted'], $r['bank_account_encrypted']);
        // Invited faculty: banking is permission-gated (masked unless the admin may view bank credentials)
        if (($r['application_for'] ?? '') === GUEST_FACULTY_TYPE) {
            $gbank = gf_admin_banking_view($r, can_admin_view_bank_credentials());
            $r['bank_name'] = $gbank['bank_name'];
            $r['bank_account_masked'] = $gbank['account_masked'];
            $r['ifsc_code'] = $gbank['ifsc'];
            $r['upi_id'] = $gbank['upi'];
        }

        // Decoded custom field values
        $r['custom_fields_decoded'] = [];
        if (!empty($r['custom_field_values'])) {
            $r['custom_fields_decoded'] = is_string($r['custom_field_values'])
                ? (json_decode($r['custom_field_values'], true) ?: [])
                : (array)$r['custom_field_values'];
        }
        $r['policy_acceptance'] = policy_get_acceptance($pdo, (int)$r['id']);

        echo json_encode($r);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// ── AJAX: Load invited (guest) faculty application for the dedicated approval modal ──
if (isset($_GET['action']) && $_GET['action'] === 'load_guest_application' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $gs = guest_faculty_schema_status($pdo);
        if (!$gs['ready']) { echo json_encode(['error' => 'Invited faculty tables are not installed yet (database-update-59-guest-faculty.sql).']); exit; }
        $st = $pdo->prepare("SELECT id, application_reference, status, photo, full_name, mobile_country_code, mobile_number, email, qualifications, payment_mode, rate_live, rate_qpd, rate_recorded, rate_offline, bank_name, bank_account_masked, ifsc_code, upi_id, guest_banking_submitted, custom_field_values, submitted_at FROM staff_registration_requests WHERE id = ? AND application_for = ? LIMIT 1");
        $st->execute([(int)$_GET['id'], GUEST_FACULTY_TYPE]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) { echo json_encode(['error' => 'Invited faculty application not found.']); exit; }
        $bank = gf_admin_banking_view($g, can_admin_view_bank_credentials());
        $fq = $pdo->prepare("SELECT id FROM faculties WHERE guest_faculty_registration_id = ? LIMIT 1");
        $fq->execute([(int)$g['id']]);
        $custom_fields = !empty($g['custom_field_values']) ? (json_decode($g['custom_field_values'], true) ?: []) : [];
        $acceptance = policy_get_acceptance($pdo, (int)$g['id']);
        echo json_encode([
            'success' => true,
            'application' => [
                'id' => (int)$g['id'], 'reference' => $g['application_reference'], 'status' => $g['status'],
                'photo' => gf_photo_url_valid($g['photo'] ?? null) ? $g['photo'] : null,
                'full_name' => $g['full_name'], 'mobile' => trim(($g['mobile_country_code'] ?: '+91') . ' ' . $g['mobile_number']),
                'email' => $g['email'], 'qualifications' => $g['qualifications'], 'submitted_at' => $g['submitted_at'],
                'payment_mode' => $g['payment_mode'],
            ],
            'banking' => $bank,
            'custom_fields' => $custom_fields,
            'policy_acceptance' => $acceptance,
            'faculty_id' => ($fid = $fq->fetchColumn()) ? (int)$fid : null,
        ]);
    } catch (Throwable $e) {
        error_log('load_guest_application: ' . $e->getMessage());
        echo json_encode(['error' => 'Could not load the application.']);
    }
    exit;
}

// ── AJAX: Load departments ────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_departments') {
    header('Content-Type: application/json');
    try {
        $depts = $pdo->query("SELECT department_name FROM departments WHERE status='active' ORDER BY sort_order, department_name")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($depts);
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// ── AJAX: Load academic years for Faculty approval ───────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_academic_years') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($years)) $years = ['2026-27', '2025-26', '2024-25'];
        echo json_encode($years);
    } catch (Exception $e) {
        echo json_encode(['2026-27', '2025-26', '2024-25']);
    }
    exit;
}

// ── AJAX: Load policy details for editing ─────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_policy' && isset($_GET['key'])) {
    header('Content-Type: application/json; charset=utf-8');
    $pol_key = trim($_GET['key']);
    if (!in_array($pol_key, ALLOWED_POLICY_KEYS, true)) {
        http_response_code(404);
        echo json_encode(['error' => 'Invalid policy key']);
        exit;
    }
    $pol = policy_get($pdo, $pol_key);
    if (!$pol) {
        http_response_code(404);
        echo json_encode(['error' => 'Policy not found']);
        exit;
    }
    echo json_encode(['success' => true, 'policy' => $pol]);
    exit;
}

// ── AJAX: Load custom field for editing ───────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'load_custom_field' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM employee_custom_fields WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_GET['id']]);
        $cf = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cf) {
            if (!isset($cf['field_label']) && isset($cf['field_name'])) {
                $cf['field_label'] = $cf['field_name'];
            }
            if (!isset($cf['field_options']) && isset($cf['dropdown_options'])) {
                $cf['field_options'] = $cf['dropdown_options'];
            }
            $cf['application_for'] = staff_normalize_custom_field_type($cf['application_for'] ?? '', 'employee');
            $cf['has_data'] = staff_custom_field_has_data($pdo, (int)$cf['id']);
        }
        echo json_encode($cf ?: ['error' => 'Not found']);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// ── POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    // ═══ APPROVE APPLICATION ═══
    if ($action === 'approve_application') {
        $app_id = (int)($_POST['app_id'] ?? 0);
        if ($app_id <= 0) {
            $error_message = 'Invalid application ID.';
        } else {
            try {
                $pdo->beginTransaction();

                // Load application
                $stmt = $pdo->prepare("SELECT * FROM staff_registration_requests WHERE id = ? AND status IN ('pending','under_review') FOR UPDATE");
                $stmt->execute([$app_id]);
                $app = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$app) throw new Exception('Application not found or already processed.');
                // Invited faculty NEVER goes through the employee/faculty/intern approval rules.
                if (strtolower(trim((string)($app['application_for'] ?? ''))) === GUEST_FACULTY_TYPE) {
                    throw new Exception('Invited faculty applications use the dedicated "Approve Invited Faculty" workflow.');
                }

                $app_for = strtolower(trim((string)($app['application_for'] ?? 'employee')));

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

                if ($app_for === 'intern') {
                    // Intern approval workflow
                    $designation = 'Project Intern';
                    $department = null;
                    $joining_date = trim($_POST['joining_date'] ?? '');
                    $internship_ends_on = trim($_POST['internship_ends_on'] ?? '');
                    $contract_from = $joining_date;
                    $contract_till = $internship_ends_on;

                    if (!$joining_date || !$internship_ends_on) {
                        throw new Exception('Joining date and internship end date are required for Intern approval.');
                    }
                    if (strtotime($internship_ends_on) < strtotime($joining_date)) {
                        throw new Exception('Internship end date must be on or after the joining date.');
                    }

                    $p_status = trim($_POST['internship_payment_status'] ?? '');
                    if (!in_array($p_status, ['paid', 'unpaid'], true)) {
                        throw new Exception('Please select Paid or Unpaid for the internship.');
                    }
                    $internship_payment_status = $p_status;

                    if ($p_status === 'unpaid') {
                        $internship_payment_mode = null;
                        $internship_remuneration = null;
                    } else {
                        $p_mode = trim($_POST['internship_payment_mode'] ?? '');
                        if (!in_array($p_mode, ['task_completion', 'monthly', 'one_time'], true)) {
                            throw new Exception('Please select a valid payment mode for paid internship.');
                        }
                        $internship_payment_mode = $p_mode;

                        if ($p_mode === 'task_completion') {
                            $internship_remuneration = null;
                        } else {
                            $remun = (float)($_POST['internship_remuneration'] ?? 0);
                            if ($remun <= 0) {
                                throw new Exception('Remuneration amount must be greater than 0 for ' . ($p_mode === 'monthly' ? 'Monthly' : 'One-time') . ' payment.');
                            }
                            $internship_remuneration = $remun;
                        }
                    }
                    // Intern remuneration MUST NOT be stored in monthly_salary
                    $monthly_salary = 0.00;

                } elseif ($app_for === 'faculty') {
                    // Faculty approval workflow
                    $designation = 'Faculty';
                    $department = 'Academics';
                    $academic_year = trim($_POST['academic_year'] ?? '');
                    if (!$academic_year) {
                        throw new Exception('PEPP Academic Year is required for Faculty approval.');
                    }

                    $rate_live = max(0, (float)($_POST['rate_live'] ?? 0));
                    $rate_qpd = max(0, (float)($_POST['rate_qpd'] ?? 0));
                    $rate_recorded = max(0, (float)($_POST['rate_recorded'] ?? 0));
                    $rate_offline = max(0, (float)($_POST['rate_offline'] ?? 0));

                    $joining_date = date('Y-m-d');
                    $contract_from = date('Y-m-d');
                    $contract_till = date('Y-12-31');
                    $monthly_salary = 0.00;
                    $internship_remuneration = null;

                } else {
                    // Employee approval workflow (unchanged)
                    $designation = trim($_POST['designation'] ?? '');
                    $department = trim($_POST['department'] ?? '');
                    $joining_date = trim($_POST['joining_date'] ?? '');
                    $probation_till = trim($_POST['probation_till'] ?? '') ?: null;
                    $contract_from = trim($_POST['contract_from'] ?? '');
                    $contract_till = trim($_POST['contract_till'] ?? '');
                    $monthly_salary = (float)($_POST['monthly_salary'] ?? 0);

                    if (!$designation || !$department || !$joining_date || !$contract_from || !$contract_till || $monthly_salary <= 0) {
                        throw new Exception('All employment fields are required for Employee approval.');
                    }
                }

                // ── Same-type duplicate guard (DB: UNIQUE(email, application_for)) ──
                // Different application_for with the same email is legitimate
                // (Employee + Faculty + Intern), so only the SAME type blocks.
                $dupe_chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE email = ? AND application_for = ?");
                $dupe_chk->execute([$app['email'], $app_for]);
                if ((int)$dupe_chk->fetchColumn() > 0) {
                    throw new Exception('An approved ' . ucfirst($app_for) . ' record with this email already exists.');
                }

                // ── SAVEPOINT: Allocate Employee ID ──
                $pdo->exec("SAVEPOINT sp_emp_id");
                $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'emp_id_seq' FOR UPDATE");
                $stmt->execute();
                $emp_seq = (int)$stmt->fetchColumn();
                if ($emp_seq < 124) $emp_seq = 124;
                $employee_id = 'EMP' . str_pad((string)$emp_seq, 5, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = NOW() WHERE setting_name = 'emp_id_seq'")->execute([(string)($emp_seq + 1)]);

                // ── SAVEPOINT: Allocate Appointment Reference ──
                $pdo->exec("SAVEPOINT sp_appt_ref");
                $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'appt_ref_seq' FOR UPDATE");
                $stmt->execute();
                $appt_seq = (int)$stmt->fetchColumn();
                if ($appt_seq < 1) $appt_seq = 1;
                $fy = get_active_academic_year_compact($pdo);
                $appointment_ref = 'PEPP/HR/' . $fy . '/' . $employee_id . '/' . str_pad((string)$appt_seq, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = NOW() WHERE setting_name = 'appt_ref_seq'")->execute([(string)($appt_seq + 1)]);

                $now = date('Y-m-d H:i:s');

                // ── Build immutable appointment snapshot ──
                $snapshot_data = [
                    'employee_name' => $app['full_name'],
                    'employee_id' => $employee_id,
                    'designation' => $designation,
                    'department' => $department ?: ($app_for === 'intern' ? 'Internship' : 'General'),
                    'application_for' => $app['application_for'],
                    'joining_date' => $joining_date,
                    'probation_till' => $probation_till,
                    'contract_from' => $contract_from,
                    'contract_till' => $contract_till,
                    'monthly_salary' => $monthly_salary,
                    'appointment_ref' => $appointment_ref,
                    'approved_at' => $now,
                    'approved_by_name' => $admin_row['full_name'] ?? $admin_username,
                    'approved_by_username' => $admin_username,
                    'company_name' => 'Labinc Education Pvt. Ltd.',
                    'brand_name' => 'PEPP Learning',
                    'company_address' => '2nd Floor, MM Ali Rd, Vellariyil Gardens, Palayam, Kozhikode, Kerala-673002',
                    'company_email' => 'office@pepplearning.com',
                    'company_phone' => '7025000444',
                    'snapshot_version' => 1,
                ];

                if ($app_for === 'intern') {
                    $snapshot_data['internship_ends_on'] = $internship_ends_on;
                    $snapshot_data['internship_payment_status'] = $internship_payment_status;
                    $snapshot_data['internship_payment_mode'] = $internship_payment_mode;
                    $snapshot_data['internship_remuneration'] = $internship_remuneration;
                } elseif ($app_for === 'faculty') {
                    $snapshot_data['academic_year'] = $academic_year;
                    $snapshot_data['rate_live'] = $rate_live;
                    $snapshot_data['rate_qpd'] = $rate_qpd;
                    $snapshot_data['rate_recorded'] = $rate_recorded;
                    $snapshot_data['rate_offline'] = $rate_offline;
                }

                $snapshot = json_encode($snapshot_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                // ── Dynamic schema-aware insert into employees ──
                $emp_table_cols = [];
                try {
                    $emp_table_cols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {}

                $ins_cols = [
                    'employee_id', 'photo', 'full_name', 'gender', 'blood_group', 'date_of_birth',
                    'mobile_country_code', 'mobile_number', 'email', 'emergency_country_code', 'emergency_contact',
                    'address', 'pincode', 'country', 'state', 'place_post_office',
                    'aadhaar_encrypted', 'aadhaar_masked', 'bank_name', 'bank_account_encrypted', 'bank_account_masked',
                    'ifsc_code', 'upi_id', 'application_for', 'designation', 'department',
                    'joining_date', 'probation_till', 'contract_validity_from', 'contract_validity_till', 'monthly_salary',
                    'appointment_reference', 'appointment_snapshot', 'appointment_generated_at',
                    'application_id', 'created_by', 'created_at'
                ];
                $ins_placeholders = array_fill(0, count($ins_cols), '?');
                $ins_placeholders[count($ins_cols) - 1] = 'NOW()';
                $ins_vals = [
                    $employee_id, $app['photo'], $app['full_name'], $app['gender'], $app['blood_group'], $app['date_of_birth'],
                    $app['mobile_country_code'], $app['mobile_number'], $app['email'],
                    $app['emergency_country_code'], $app['emergency_contact'],
                    $app['address'], $app['pincode'], $app['country'], $app['state'], $app['place_post_office'],
                    $app['aadhaar_encrypted'], $app['aadhaar_masked'], $app['bank_name'],
                    $app['bank_account_encrypted'], $app['bank_account_masked'],
                    $app['ifsc_code'], $app['upi_id'], $app['application_for'], $designation, $department,
                    $joining_date, $probation_till, $contract_from, $contract_till, $monthly_salary,
                    $appointment_ref, $snapshot, $now,
                    $app_id, $admin_username
                ];

                $opt_emp_map = [
                    'internship_ends_on' => $internship_ends_on,
                    'internship_payment_status' => $internship_payment_status,
                    'internship_payment_mode' => $internship_payment_mode,
                    'internship_remuneration' => $internship_remuneration,
                    'academic_year' => $academic_year,
                    'rate_live' => $rate_live,
                    'rate_qpd' => $rate_qpd,
                    'rate_recorded' => $rate_recorded,
                    'rate_offline' => $rate_offline,
                ];
                foreach ($opt_emp_map as $cname => $cval) {
                    if (in_array($cname, $emp_table_cols, true)) {
                        $ins_cols[] = $cname;
                        $ins_placeholders[] = '?';
                        $ins_vals[] = $cval;
                    }
                }

                $sql_ins = "INSERT INTO employees (" . implode(', ', $ins_cols) . ") VALUES (" . implode(', ', $ins_placeholders) . ")";
                $stmt = $pdo->prepare($sql_ins);
                $stmt->execute($ins_vals);
                $emp_record_id = (int)$pdo->lastInsertId();

                // ── Update registration request ──
                $srr_table_cols = [];
                try {
                    $srr_table_cols = $pdo->query("SHOW COLUMNS FROM staff_registration_requests")->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {}

                $srr_sets = [
                    'status = ?', 'approved_employee_id = ?', 'designation = ?', 'department = ?',
                    'joining_date = ?', 'probation_till = ?', 'contract_validity_from = ?', 'contract_validity_till = ?',
                    'monthly_salary = ?', 'appointment_reference = ?', 'appointment_snapshot = ?', 'appointment_generated_at = ?',
                    'approved_by_admin_id = ?', 'approved_by_username = ?', 'approved_at = ?',
                    'employee_record_id = ?', 'reviewed_by = ?', 'reviewed_at = ?'
                ];
                $srr_vals = [
                    'approved', $employee_id, $designation, $department,
                    $joining_date, $probation_till, $contract_from, $contract_till,
                    $monthly_salary, $appointment_ref, $snapshot, $now,
                    $admin_row['id'] ?? null, $admin_username, $now,
                    $emp_record_id, $admin_username, $now
                ];

                foreach ($opt_emp_map as $cname => $cval) {
                    if (in_array($cname, $srr_table_cols, true)) {
                        $srr_sets[] = "{$cname} = ?";
                        $srr_vals[] = $cval;
                    }
                }

                $srr_vals[] = $app_id;
                $sql_srr = "UPDATE staff_registration_requests SET " . implode(', ', $srr_sets) . " WHERE id = ?";
                $pdo->prepare($sql_srr)->execute($srr_vals);

                // ── Copy custom field values to employee_custom_values ──
                // Only fields belonging to this application type are copied. Faculty values
                // are stored against employees.id internally and read by faculties.php.
                if (!empty($app['custom_field_values'])) {
                    $custom_vals = staff_filter_custom_values_for_type($pdo, $app['custom_field_values'], $app_for);
                    if (!empty($custom_vals)) {
                        $ins_cf = $pdo->prepare("INSERT INTO employee_custom_values (employee_id, field_id, field_value) VALUES (?,?,?)");
                        foreach ($custom_vals as $fid_int => $fval) {
                            $ins_cf->execute([$emp_record_id, $fid_int, (string)$fval]);
                        }
                    }
                }

                // ── Audit ──
                log_admin_activity($pdo, $admin_username, 'staff_approved',
                    "Approved {$app['full_name']} as {$employee_id} ({$designation}, " . ($department ?: 'N/A') . "). Ref: {$appointment_ref}");

                $pdo->commit();
                $success_message = "Application approved! Employee ID: {$employee_id}, Appointment Ref: {$appointment_ref}";

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('approve_application: ' . $e->getMessage());
                $error_message = 'Approval failed: ' . $e->getMessage();
            }
        }
    }

    // ═══ APPROVE INVITED (GUEST) FACULTY — dedicated flow; never creates an employee ═══
    elseif ($action === 'approve_guest_faculty') {
        $app_id = (int)($_POST['app_id'] ?? 0);
        try {
            $gs = guest_faculty_schema_status($pdo);
            if (!$gs['ready']) throw new Exception('Invited faculty tables are not installed yet. Apply database-update-59-guest-faculty.sql first.');
            if ($app_id <= 0) throw new Exception('Invalid application ID.');
            $pr = gf_parse_rates($_POST);
            if ($pr['error'] !== null) throw new Exception($pr['error']);
            $res = gf_approve_request(
                $pdo, $app_id, $pr['rates'], (string)$pr['mode'],
                isset($admin_row['id']) ? (int)$admin_row['id'] : null, (string)$admin_username,
                !empty($_POST['add_to_directory'])
            );
            if (!$res['ok']) throw new Exception((string)$res['error']);
            if ($res['already']) {
                $success_message = 'This invited faculty application was already approved. No changes were made.';
            } else {
                $rate_txt = 'Live ' . number_format($pr['rates']['rate_live'], 2) . ' / QPD ' . number_format($pr['rates']['rate_qpd'], 2)
                          . ' / Recorded ' . number_format($pr['rates']['rate_recorded'], 2) . ' / Offline ' . number_format($pr['rates']['rate_offline'], 2);
                log_admin_activity($pdo, $admin_username, 'guest_faculty_approved',
                    "Approved invited faculty application #{$app_id}; payment: " . gf_payment_label($res['mode']) . " ({$rate_txt})"
                    . ($res['faculty_id'] ? "; added to Faculty Directory as faculty #{$res['faculty_id']}" : ''));
                $success_message = 'Invited faculty approved (' . gf_payment_label($res['mode']) . ').'
                    . ($res['faculty_id'] ? ' Added to the Faculty Directory.' : ' Use "Add to Faculty Directory" in Faculties to make this faculty assignable to sessions.');
            }
        } catch (Throwable $e) {
            error_log('approve_guest_faculty: ' . $e->getMessage());
            $error_message = 'Approval failed: ' . $e->getMessage();
        }
    }

    // ═══ GUEST FACULTY BANKING TOGGLE (Super Admin only, explicit value, audited) ═══
    elseif ($action === 'set_guest_faculty_banking') {
        try {
            if (!is_super_admin()) throw new Exception('Only a Super Admin can change this setting.');
            $v = (string)($_POST['banking_enabled'] ?? '');
            if (!in_array($v, ['0', '1'], true)) throw new Exception('Invalid setting value.');
            $prev = guest_faculty_set_banking_enabled($pdo, $v === '1');
            log_admin_activity($pdo, $admin_username, 'guest_faculty_banking_setting',
                'Guest Faculty Banking Details changed from ' . ($prev ? 'ON' : 'OFF') . ' to ' . ($v === '1' ? 'ON' : 'OFF'));
            $success_message = 'Guest Faculty Banking Details is now ' . ($v === '1' ? 'ON' : 'OFF') . '.';
        } catch (Throwable $e) {
            $error_message = 'Could not update setting: ' . $e->getMessage();
        }
    }

    // ═══ REJECT APPLICATION ═══
    elseif ($action === 'reject_application') {
        $app_id = (int)($_POST['app_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (!$reason) { $error_message = 'Rejection reason is required.'; }
        else {
            try {
                $stmt = $pdo->prepare("UPDATE staff_registration_requests SET status='rejected', rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status IN ('pending','under_review')");
                $stmt->execute([$reason, $admin_username, $app_id]);
                if ($stmt->rowCount()) {
                    log_admin_activity($pdo, $admin_username, 'staff_rejected', "Rejected application #{$app_id}: {$reason}");
                    $success_message = 'Application rejected.';
                } else { $error_message = 'Application not found or already processed.'; }
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }

    // ═══ UPDATE APPLICATION STATUS ═══
    elseif ($action === 'update_status') {
        $app_id = (int)($_POST['app_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        if (in_array($new_status, ['under_review','cancelled'], true)) {
            try {
                // An approved invited-faculty request is the source of a faculty record; freeze it.
                $gchk = $pdo->prepare("SELECT application_for, status FROM staff_registration_requests WHERE id = ?");
                $gchk->execute([$app_id]);
                $grow = $gchk->fetch(PDO::FETCH_ASSOC);
                if ($grow && $grow['application_for'] === GUEST_FACULTY_TYPE && $grow['status'] === 'approved') {
                    throw new Exception('An approved invited faculty application cannot be changed. Set the faculty inactive in Faculties instead.');
                }
                $pdo->prepare("UPDATE staff_registration_requests SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$new_status, $admin_username, $app_id]);
                log_admin_activity($pdo, $admin_username, 'staff_status_change', "Changed application #{$app_id} to {$new_status}");
                $success_message = 'Status updated.';
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }

    // ═══ UPDATE EMPLOYEE PROFILE ═══
    elseif ($action === 'update_employee_profile') {
        $emp_id = (int)($_POST['emp_id'] ?? 0);
        if ($emp_id <= 0) {
            $error_message = 'Invalid staff record ID.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? FOR UPDATE");
                $stmt->execute([$emp_id]);
                $current_emp = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$current_emp) {
                    $pdo->rollBack();
                    $error_message = 'Employee record not found.';
                } elseif (!staff_is_generic_type($current_emp['application_for'] ?? 'employee')) {
                    $pdo->rollBack();
                    $error_message = 'Faculty records are managed in the Faculties module (faculties.php), not in Employee Management.';
                } else {
                    $full_name = trim($_POST['full_name'] ?? '');
                    $gender = $_POST['gender'] ?? '';
                    $dob = trim($_POST['date_of_birth'] ?? '');
                    $blood_group = $_POST['blood_group'] ?? '';
                    $mobile_cc = trim($_POST['mobile_country_code'] ?? '+91');
                    $mobile = preg_replace('/\D/', '', trim($_POST['mobile_number'] ?? ''));
                    $email = strtolower(trim($_POST['email'] ?? ''));
                    $emergency_cc = trim($_POST['emergency_country_code'] ?? '+91');
                    $emergency = preg_replace('/\D/', '', trim($_POST['emergency_contact'] ?? ''));
                    $address = trim($_POST['address'] ?? '');
                    $pincode = preg_replace('/\D/', '', trim($_POST['pincode'] ?? ''));
                    $country = trim($_POST['country'] ?? 'India');
                    $state = trim($_POST['state'] ?? '');
                    $place = trim($_POST['place_post_office'] ?? '');

                    // Authoritative immutable role track from database record
                    $application_for = strtolower(trim((string)($current_emp['application_for'] ?? 'employee')));
                    $status = trim($_POST['status'] ?? 'active');

                    $bank_name = trim($_POST['bank_name'] ?? '');
                    $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
                    $upi_id = trim($_POST['upi_id'] ?? '') ?: null;

                    // Common Personal Validations
                    $val_errors = [];
                    if (strlen($full_name) < 2) $val_errors[] = 'Full name is required.';
                    if (!in_array($gender, ['Male','Female','Other','Prefer not to say'], true)) $val_errors[] = 'Select a valid gender.';
                    if (!$dob || !strtotime($dob)) $val_errors[] = 'Valid date of birth is required.';
                    if (!in_array($blood_group, ['A+','A-','B+','B-','AB+','AB-','O+','O-'], true)) $val_errors[] = 'Select a valid blood group.';
                    if (strlen($mobile) < 10) $val_errors[] = 'Valid 10-digit mobile number is required.';
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $val_errors[] = 'Valid email address is required.';
                    if (strlen($emergency) < 10) $val_errors[] = 'Valid emergency contact number is required.';
                    if (strlen($address) < 5) $val_errors[] = 'Full address is required.';
                    if (strlen($pincode) !== 6) $val_errors[] = 'Valid 6-digit PIN code is required.';
                    if (!array_key_exists($status, $CANONICAL_STAFF_STATUSES)) $val_errors[] = 'Invalid employment status.';

                    // Role-Specific Field Extraction and Validation
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
                        // Intern rules
                        $designation = 'Project Intern'; // business rule: forced
                        $department = null;
                        $joining_date = trim($_POST['intern_joining_date'] ?? $_POST['joining_date'] ?? '');
                        $internship_ends_on = trim($_POST['internship_ends_on'] ?? '');
                        $contract_from = $joining_date ?: null;
                        $contract_till = $internship_ends_on ?: null;
                        $probation_till = null;
                        $monthly_salary = 0.00; // never repurpose monthly_salary for intern

                        if (!$joining_date || !strtotime($joining_date)) {
                            $val_errors[] = 'Valid joining date is required for Intern.';
                        }
                        if (!$internship_ends_on || !strtotime($internship_ends_on)) {
                            $val_errors[] = 'Valid internship end date is required for Intern.';
                        }
                        if ($joining_date && $internship_ends_on && strtotime($internship_ends_on) < strtotime($joining_date)) {
                            $val_errors[] = 'Internship end date must be on or after joining date.';
                        }

                        $p_status = trim($_POST['internship_payment_status'] ?? '');
                        if (!in_array($p_status, ['paid', 'unpaid'], true)) {
                            $val_errors[] = 'Please select Paid or Unpaid for the internship.';
                        }
                        $internship_payment_status = $p_status;

                        if ($p_status === 'unpaid') {
                            $internship_payment_mode = null;
                            $internship_remuneration = null;
                        } else {
                            $p_mode = trim($_POST['internship_payment_mode'] ?? '');
                            if (!in_array($p_mode, ['task_completion', 'monthly', 'one_time'], true)) {
                                $val_errors[] = 'Please select a valid payment mode for paid internship.';
                            }
                            $internship_payment_mode = $p_mode;

                            if ($p_mode === 'task_completion') {
                                $internship_remuneration = null;
                            } else {
                                $remun = (float)($_POST['internship_remuneration'] ?? 0);
                                if ($remun <= 0) {
                                    $val_errors[] = 'Remuneration amount must be greater than 0 for ' . ($p_mode === 'monthly' ? 'Monthly' : 'One-time') . ' payment.';
                                }
                                $internship_remuneration = $remun;
                            }
                        }

                    } elseif ($application_for === 'faculty') {
                        // Faculty rules
                        $designation = 'Faculty'; // business rule: forced
                        $department = 'Academics'; // business rule: forced
                        $joining_date = trim($_POST['faculty_joining_date'] ?? $_POST['joining_date'] ?? '') ?: ($current_emp['joining_date'] ?: date('Y-m-d'));
                        $probation_till = null;
                        $contract_from = $joining_date;
                        $contract_till = trim($_POST['contract_validity_till'] ?? '') ?: ($current_emp['contract_validity_till'] ?: date('Y-12-31'));
                        $monthly_salary = 0.00;
                        $internship_remuneration = null;

                        $academic_year = trim($_POST['academic_year'] ?? '');
                        if (!$academic_year) {
                            $val_errors[] = 'PEPP Academic Year is required for Faculty.';
                        }

                        $rate_live = max(0, (float)($_POST['rate_live'] ?? 0));
                        $rate_qpd = max(0, (float)($_POST['rate_qpd'] ?? 0));
                        $rate_recorded = max(0, (float)($_POST['rate_recorded'] ?? 0));
                        $rate_offline = max(0, (float)($_POST['rate_offline'] ?? 0));

                    } else {
                        // Employee rules (unchanged)
                        $designation = trim($_POST['designation'] ?? '');
                        $department = trim($_POST['department'] ?? '');
                        $joining_date = trim($_POST['joining_date'] ?? '');
                        $probation_till = trim($_POST['probation_till'] ?? '') ?: null;
                        $contract_from = trim($_POST['contract_validity_from'] ?? '');
                        $contract_till = trim($_POST['contract_validity_till'] ?? '');
                        $monthly_salary = (float)($_POST['monthly_salary'] ?? 0);

                        if (!$designation) $val_errors[] = 'Designation is required.';
                        if (!$department) $val_errors[] = 'Department is required.';
                        if (!$joining_date || !strtotime($joining_date)) $val_errors[] = 'Valid joining date is required.';
                        if (!$contract_from || !strtotime($contract_from)) $val_errors[] = 'Valid contract from date is required.';
                        if (!$contract_till || !strtotime($contract_till)) $val_errors[] = 'Valid contract till date is required.';
                        if ($contract_from && $contract_till && strtotime($contract_till) < strtotime($contract_from)) {
                            $val_errors[] = 'Contract till date must be on or after contract from date.';
                        }
                        if ($probation_till && strtotime($probation_till) < strtotime($joining_date)) {
                            $val_errors[] = 'Probation till date must be on or after joining date.';
                        }
                        if ($monthly_salary <= 0) {
                            $val_errors[] = 'Monthly salary must be greater than 0 for Employee.';
                        }
                    }

                    if (!empty($val_errors)) {
                        $pdo->rollBack();
                        $error_message = implode(' ', $val_errors);
                    } else {
                        // Photo Upload Handling with safe replacement & validation
                        $photo_path = $current_emp['photo'];
                        if (isset($_FILES['photo_file']) && !empty($_FILES['photo_file']['name'])) {
                            if ($_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
                                $new_photo = handle_file_upload_with_replace('photo_file', 'photos', $photo_path ?: null, ['jpg', 'jpeg', 'png', 'webp']);
                                if ($new_photo) {
                                    $photo_path = $new_photo;
                                } else {
                                    throw new Exception('Invalid photo file. Allowed formats: JPG, PNG, WEBP under 5MB.');
                                }
                            }
                        }

                        // Sensitive KYC Aadhaar Update (Only if unmasked value entered)
                        $aadhaar_encrypted = $current_emp['aadhaar_encrypted'];
                        $aadhaar_masked = $current_emp['aadhaar_masked'];
                        $raw_aadhaar_input = preg_replace('/\D/', '', trim($_POST['aadhaar_number'] ?? ''));
                        if (strlen($raw_aadhaar_input) === 12 && strpos(trim($_POST['aadhaar_number'] ?? ''), 'X') === false) {
                            $aadhaar_encrypted = pepp_encrypt($raw_aadhaar_input);
                            $aadhaar_masked = mask_aadhaar($raw_aadhaar_input);
                        }

                        // Sensitive Bank Account Update (Only if unmasked value entered)
                        $bank_acc_encrypted = $current_emp['bank_account_encrypted'];
                        $bank_acc_masked = $current_emp['bank_account_masked'];
                        $raw_bank_input = preg_replace('/\s/', '', trim($_POST['bank_account_number'] ?? ''));
                        if (strlen($raw_bank_input) >= 6 && strpos(trim($_POST['bank_account_number'] ?? ''), 'X') === false) {
                            $bank_acc_encrypted = pepp_encrypt($raw_bank_input);
                            $bank_acc_masked = mask_bank_account($raw_bank_input);
                        }

                        $upd_cols = [
                            'photo = ?', 'full_name = ?', 'gender = ?', 'blood_group = ?', 'date_of_birth = ?',
                            'mobile_country_code = ?', 'mobile_number = ?', 'email = ?', 'emergency_country_code = ?', 'emergency_contact = ?',
                            'address = ?', 'pincode = ?', 'country = ?', 'state = ?', 'place_post_office = ?',
                            'application_for = ?', 'designation = ?', 'department = ?',
                            'joining_date = ?', 'probation_till = ?', 'contract_validity_from = ?', 'contract_validity_till = ?',
                            'monthly_salary = ?', 'status = ?',
                            'aadhaar_encrypted = ?', 'aadhaar_masked = ?',
                            'bank_name = ?', 'bank_account_encrypted = ?', 'bank_account_masked = ?',
                            'ifsc_code = ?', 'upi_id = ?'
                        ];
                        $upd_vals = [
                            $photo_path, $full_name, $gender, $blood_group, $dob,
                            $mobile_cc, $mobile, $email, $emergency_cc, $emergency,
                            $address, $pincode, $country, $state, $place,
                            $application_for, $designation, $department,
                            $joining_date, $probation_till, $contract_from, $contract_till,
                            $monthly_salary, $status,
                            $aadhaar_encrypted, $aadhaar_masked,
                            $bank_name, $bank_acc_encrypted, $bank_acc_masked,
                            $ifsc, $upi_id
                        ];

                        $emp_table_cols = get_table_columns_safe($pdo, 'employees');

                        if ($application_for === 'intern') {
                            if (in_array('internship_ends_on', $emp_table_cols, true)) {
                                $upd_cols[] = 'internship_ends_on = ?';
                                $upd_vals[] = $internship_ends_on;
                            }
                            if (in_array('internship_payment_status', $emp_table_cols, true)) {
                                $upd_cols[] = 'internship_payment_status = ?';
                                $upd_vals[] = $internship_payment_status;
                            }
                            if (in_array('internship_payment_mode', $emp_table_cols, true)) {
                                $upd_cols[] = 'internship_payment_mode = ?';
                                $upd_vals[] = $internship_payment_mode;
                            }
                            if (in_array('internship_remuneration', $emp_table_cols, true)) {
                                $upd_cols[] = 'internship_remuneration = ?';
                                $upd_vals[] = $internship_remuneration;
                            }
                        } elseif ($application_for === 'faculty') {
                            if (in_array('academic_year', $emp_table_cols, true)) {
                                $upd_cols[] = 'academic_year = ?';
                                $upd_vals[] = $academic_year;
                            }
                            if (in_array('rate_live', $emp_table_cols, true)) {
                                $upd_cols[] = 'rate_live = ?';
                                $upd_vals[] = $rate_live;
                            }
                            if (in_array('rate_qpd', $emp_table_cols, true)) {
                                $upd_cols[] = 'rate_qpd = ?';
                                $upd_vals[] = $rate_qpd;
                            }
                            if (in_array('rate_recorded', $emp_table_cols, true)) {
                                $upd_cols[] = 'rate_recorded = ?';
                                $upd_vals[] = $rate_recorded;
                            }
                            if (in_array('rate_offline', $emp_table_cols, true)) {
                                $upd_cols[] = 'rate_offline = ?';
                                $upd_vals[] = $rate_offline;
                            }
                        }

                        $upd_vals[] = $emp_id;
                        $sql_upd = "UPDATE employees SET " . implode(', ', $upd_cols) . ", updated_at = NOW() WHERE id = ?";
                        $stmt_upd = $pdo->prepare($sql_upd);
                        $stmt_upd->execute($upd_vals);

                        // Synchronize linked Faculty record if application_for === 'faculty'
                        if ($application_for === 'faculty') {
                            $has_fac_link_col = false;
                            try {
                                $has_fac_link_col = (bool)$pdo->query("SHOW COLUMNS FROM faculties LIKE 'employee_management_faculty_id'")->fetchColumn();
                            } catch (Exception $e) {
                                try {
                                    $pdo->query("SELECT employee_management_faculty_id FROM faculties LIMIT 1");
                                    $has_fac_link_col = true;
                                } catch (Exception $e2) {
                                    $has_fac_link_col = false;
                                }
                            }

                            if ($has_fac_link_col) {
                                $stmt_fac = $pdo->prepare("SELECT id, name FROM faculties WHERE employee_management_faculty_id = ? FOR UPDATE");
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
                                        $full_name,
                                        $email ?: null,
                                        $mobile,
                                        $academic_year ?: null,
                                        $rate_live,
                                        $rate_qpd,
                                        $rate_recorded,
                                        $rate_offline,
                                        $emp_id
                                    ]);
                                    log_admin_activity($pdo, $admin_username, 'faculty_synced',
                                        "Synchronized authoritative faculty details for faculty #{$linked_fac['id']} ({$full_name}) from Employee {$current_emp['employee_id']}");
                                }
                            }
                        }

                        // Update custom field values
                        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
                            $valid_fids = array_map(
                                fn($r) => (int)$r['id'],
                                array_filter(
                                    staff_normalize_custom_field_rows($pdo->query("SELECT * FROM employee_custom_fields")->fetchAll(PDO::FETCH_ASSOC)),
                                    fn($r) => $r['application_for'] === staff_normalize_type($application_for, 'employee')
                                )
                            );
                            $stmt_upsert_cf = $pdo->prepare("
                                INSERT INTO employee_custom_values (employee_id, field_id, field_value)
                                VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)
                            ");
                            foreach ($_POST['custom_fields'] as $cf_id_key => $cf_val) {
                                $cf_id_int = (int)$cf_id_key;
                                if (in_array($cf_id_int, $valid_fids, true)) {
                                    $stmt_upsert_cf->execute([$emp_id, $cf_id_int, (string)$cf_val]);
                                }
                            }
                        }

                        // Audit Logs
                        log_admin_activity($pdo, $admin_username, 'staff_profile_update', "Updated profile for staff {$full_name} ({$current_emp['employee_id']})");
                        if ($current_emp['status'] !== $status) {
                            log_admin_activity($pdo, $admin_username, 'staff_status_change', "Changed status of staff {$full_name} ({$current_emp['employee_id']}) from {$current_emp['status']} to {$status}");
                        }

                        $pdo->commit();
                        $success_message = "Staff profile \"{$full_name}\" ({$current_emp['employee_id']}) updated successfully.";
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_message = 'Failed to update staff profile: ' . $e->getMessage();
            }
        }
    }

    // ═══ ADD CUSTOM FIELD ═══
    elseif ($action === 'add_custom_field') {
        $cf_label = trim($_POST['cf_label'] ?? '');
        $cf_key   = trim($_POST['cf_key'] ?? '');
        $cf_type  = $_POST['cf_type'] ?? 'text';
        $cf_opts  = trim($_POST['cf_options'] ?? '');
        $cf_req   = isset($_POST['cf_required']) ? 1 : 0;
        $cf_order = (int)($_POST['cf_sort_order'] ?? 0);
        $allowed_types = ['text','number','email','date','dropdown','textarea','phone'];
        $cf_app_for = staff_normalize_type($_POST['cf_application_for'] ?? '') ?: staff_normalize_custom_field_type($_POST['cf_application_for'] ?? '');

        if (!$cf_label) {
            $error_message = 'Field label is required.';
        } elseif ($cf_app_for === '') {
            $error_message = 'Please select a valid Application Type (Employee, Faculty, Intern or Guest Faculty).';
        } elseif (!empty($cf_key) && !preg_match('/^[a-z][a-z0-9_]{1,49}$/', $cf_key)) {
            $error_message = 'Field key must be lowercase letters/numbers/underscore, 2-50 chars, start with a letter.';
        } elseif (!in_array($cf_type, $allowed_types, true)) {
            $error_message = 'Invalid field type.';
        } elseif ($cf_type === 'dropdown' && empty($cf_opts)) {
            $error_message = 'Dropdown fields require at least one option.';
        } else {
            try {
                $cols = get_employee_custom_field_columns($pdo);
                $has_field_key = in_array('field_key', $cols, true);

                if ($has_field_key && !empty($cf_key)) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_custom_fields WHERE field_key = ?");
                    $stmt->execute([$cf_key]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        $error_message = 'A custom field with this key already exists.';
                    }
                }

                if (empty($error_message)) {
                    $insert_data = [];
                    if (in_array('field_label', $cols, true)) {
                        $insert_data['field_label'] = $cf_label;
                    } elseif (in_array('field_name', $cols, true)) {
                        $insert_data['field_name'] = $cf_label;
                    }
                    if ($has_field_key && !empty($cf_key)) {
                        $insert_data['field_key'] = $cf_key;
                    }
                    if (in_array('field_type', $cols, true)) {
                        $insert_data['field_type'] = $cf_type;
                    }
                    if (in_array('application_for', $cols, true)) {
                        $insert_data['application_for'] = $cf_app_for;
                    }
                    if (in_array('field_options', $cols, true)) {
                        $insert_data['field_options'] = $cf_opts ?: null;
                    } elseif (in_array('dropdown_options', $cols, true)) {
                        $insert_data['dropdown_options'] = $cf_opts ?: null;
                    }
                    if (in_array('is_required', $cols, true)) {
                        $insert_data['is_required'] = $cf_req;
                    }
                    if (in_array('sort_order', $cols, true)) {
                        $insert_data['sort_order'] = $cf_order;
                    }
                    if (in_array('status', $cols, true)) {
                        $insert_data['status'] = 'active';
                    }
                    if (in_array('created_by', $cols, true)) {
                        $insert_data['created_by'] = $admin_username;
                    }

                    $col_names = implode(', ', array_keys($insert_data));
                    $placeholders = implode(', ', array_fill(0, count($insert_data), '?'));
                    $stmt = $pdo->prepare("INSERT INTO employee_custom_fields ({$col_names}) VALUES ({$placeholders})");
                    $stmt->execute(array_values($insert_data));

                    log_admin_activity($pdo, $admin_username, 'custom_field_added', "Added custom field: {$cf_label} ({$cf_type}) for {$cf_app_for}");
                    $success_message = "Custom field \"{$cf_label}\" created.";
                }
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }

    // ═══ UPDATE CUSTOM FIELD ═══
    elseif ($action === 'update_custom_field') {
        $cf_id    = (int)($_POST['cf_id'] ?? 0);
        $cf_label = trim($_POST['cf_label'] ?? '');
        $cf_type  = $_POST['cf_type'] ?? 'text';
        $cf_opts  = trim($_POST['cf_options'] ?? '');
        $cf_req   = isset($_POST['cf_required']) ? 1 : 0;
        $cf_order = (int)($_POST['cf_sort_order'] ?? 0);
        $allowed_types = ['text','number','email','date','dropdown','textarea','phone'];
        $cf_app_for_raw = $_POST['cf_application_for'] ?? '';
        $cf_app_for = staff_normalize_custom_field_type($cf_app_for_raw);

        if (!$cf_label || !$cf_id) {
            $error_message = 'Field ID and label are required.';
        } elseif ($cf_app_for === '') {
            $error_message = 'Please select a valid Application Type (Employee, Faculty, Intern or Guest Faculty).';
        } elseif (!in_array($cf_type, $allowed_types, true)) {
            $error_message = 'Invalid field type.';
        } elseif ($cf_type === 'dropdown' && empty($cf_opts)) {
            $error_message = 'Dropdown fields require at least one option.';
        } else {
            try {
                $cols = get_employee_custom_field_columns($pdo);
                $update_sets = [];
                $update_vals = [];

                if (in_array('application_for', $cols, true)) {
                    $st_cur = $pdo->prepare("SELECT application_for FROM employee_custom_fields WHERE id = ? LIMIT 1");
                    $st_cur->execute([$cf_id]);
                    $cur_type = staff_normalize_custom_field_type($st_cur->fetchColumn() ?: '', 'employee');
                    if ($cur_type !== $cf_app_for) {
                        if (staff_custom_field_has_data($pdo, $cf_id)) {
                            throw new Exception('Application Type cannot be changed: this field already has submitted values for ' . ucfirst($cur_type) . ' staff. Deactivate it and create a new field for ' . ucfirst($cf_app_for) . ' instead.');
                        }
                        $update_sets[] = "application_for = ?";
                        $update_vals[] = $cf_app_for;
                    }
                }

                if (in_array('field_label', $cols, true)) {
                    $update_sets[] = "field_label = ?";
                    $update_vals[] = $cf_label;
                } elseif (in_array('field_name', $cols, true)) {
                    $update_sets[] = "field_name = ?";
                    $update_vals[] = $cf_label;
                }
                if (in_array('field_type', $cols, true)) {
                    $update_sets[] = "field_type = ?";
                    $update_vals[] = $cf_type;
                }
                if (in_array('field_options', $cols, true)) {
                    $update_sets[] = "field_options = ?";
                    $update_vals[] = $cf_opts ?: null;
                } elseif (in_array('dropdown_options', $cols, true)) {
                    $update_sets[] = "dropdown_options = ?";
                    $update_vals[] = $cf_opts ?: null;
                }
                if (in_array('is_required', $cols, true)) {
                    $update_sets[] = "is_required = ?";
                    $update_vals[] = $cf_req;
                }
                if (in_array('sort_order', $cols, true)) {
                    $update_sets[] = "sort_order = ?";
                    $update_vals[] = $cf_order;
                }

                if (!empty($update_sets)) {
                    $update_vals[] = $cf_id;
                    $stmt = $pdo->prepare("UPDATE employee_custom_fields SET " . implode(', ', $update_sets) . " WHERE id = ?");
                    $stmt->execute($update_vals);

                    log_admin_activity($pdo, $admin_username, 'custom_field_updated', "Updated custom field #{$cf_id}: {$cf_label}");
                    $success_message = "Custom field \"{$cf_label}\" updated.";
                }
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }

    // ═══ TOGGLE CUSTOM FIELD STATUS ═══
    elseif ($action === 'toggle_custom_field') {
        $cf_id = (int)($_POST['cf_id'] ?? 0);
        if ($cf_id) {
            try {
                $pdo->prepare("UPDATE employee_custom_fields SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$cf_id]);
                log_admin_activity($pdo, $admin_username, 'custom_field_toggled', "Toggled custom field #{$cf_id} status");
                $success_message = 'Custom field status updated.';
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }

    // ═══ SAVE POLICY & TERMS ═══
    elseif ($action === 'save_policy') {
        $pol_key = trim($_POST['policy_key'] ?? '');
        $pol_title = trim($_POST['title'] ?? '');
        $pol_content = (string)($_POST['content'] ?? '');
        $bump_version = trim($_POST['bump_version'] ?? 'none');
        $pol_status = trim($_POST['status'] ?? 'active');

        if (!in_array($pol_key, ALLOWED_POLICY_KEYS, true)) {
            $error_message = 'Invalid policy key.';
        } elseif ($pol_title === '') {
            $error_message = 'Policy title is required.';
        } else {
            try {
                $saved = policy_save($pdo, $pol_key, $pol_title, $pol_content, $admin_username, $bump_version, $pol_status);
                if (empty($saved['success'])) {
                    $error_message = $saved['error'] ?? 'Failed to save policy.';
                } else {
                    log_admin_activity($pdo, $admin_username, 'policy_updated', "Updated policy: {$pol_key} ({$pol_title}) to version {$saved['version']}");
                    $success_message = "Policy \"{$pol_title}\" successfully saved to version {$saved['version']}.";
                }
            } catch (Exception $e) {
                $error_message = 'Failed to save policy: ' . $e->getMessage();
            }
        }
    }
}

// ── Load Data & Server-Side Filtering ─────────────────────────────────
$employees = [];
$applications = [];
$faculty_list = [];
$tab = $_GET['tab'] ?? 'employees';

// Filters
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$type_filter = trim($_GET['type'] ?? '');
$link_filter = trim($_GET['link'] ?? '');
$fac_year_filter = trim($_GET['academic_year'] ?? '');

$emp_total_count = 0;
$emp_active_count = 0;
$emp_prob_count = 0;
$emp_linked_count = 0;

$fac_total_count = 0;
$fac_active_count = 0;
$fac_inactive_count = 0;

if (emp_tables_exist($pdo)) {
    try {
        // Overall statistics
        $emp_total_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE " . staff_generic_type_sql())->fetchColumn();
        $emp_active_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active' AND " . staff_generic_type_sql())->fetchColumn();
        $emp_prob_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status IN ('probation', 'contract') AND " . staff_generic_type_sql())->fetchColumn();
        $emp_linked_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE admin_id IS NOT NULL AND " . staff_generic_type_sql())->fetchColumn();

        // Faculty tab counts (Approved faculties only: status NOT IN ('rejected', 'pending'))
        $fac_total_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE application_for = 'faculty' AND status NOT IN ('rejected', 'pending')")->fetchColumn();
        $fac_active_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE application_for = 'faculty' AND status = 'active'")->fetchColumn();
        $fac_inactive_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE application_for = 'faculty' AND status = 'inactive'")->fetchColumn();

        // Load faculties when on faculties tab
        if ($tab === 'faculties') {
            $fac_where = ["e.application_for = 'faculty'", "e.status NOT IN ('rejected', 'pending')"];
            $fac_params = [];

            if ($search !== '') {
                $fac_where[] = "(e.full_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ? OR e.mobile_number LIKE ? OR e.department LIKE ?)";
                $term = "%{$search}%";
                $fac_params = array_merge($fac_params, [$term, $term, $term, $term, $term]);
            }
            if ($status_filter !== '') {
                $fac_where[] = "e.status = ?";
                $fac_params[] = $status_filter;
            }
            if ($fac_year_filter !== '') {
                $fac_where[] = "e.academic_year = ?";
                $fac_params[] = $fac_year_filter;
            }

            $fac_sql = "
                SELECT e.*,
                       f.id AS linked_faculty_id,
                       f.name AS linked_faculty_name,
                       f.status AS linked_faculty_status,
                       f.academic_year AS linked_faculty_academic_year
                FROM employees e
                LEFT JOIN faculties f ON f.employee_management_faculty_id = e.id
                WHERE " . implode(' AND ', $fac_where) . "
                ORDER BY e.id DESC
            ";
            $stmt_fac_list = $pdo->prepare($fac_sql);
            $stmt_fac_list->execute($fac_params);
            $faculty_list = $stmt_fac_list->fetchAll(PDO::FETCH_ASSOC);
        }

        // Filtered employee list — Faculty is isolated (managed in faculties.php)
        $emp_where = [staff_generic_type_sql('e')];
        $emp_params = [];

        if ($search !== '') {
            $emp_where[] = "(e.full_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ? OR e.mobile_number LIKE ? OR e.designation LIKE ? OR e.department LIKE ?)";
            $term = "%{$search}%";
            $emp_params = array_merge($emp_params, [$term, $term, $term, $term, $term, $term]);
        }
        if ($status_filter !== '' && array_key_exists($status_filter, $CANONICAL_STAFF_STATUSES)) {
            $emp_where[] = "e.status = ?";
            $emp_params[] = $status_filter;
        }
        if ($type_filter !== '' && in_array($type_filter, STAFF_GENERIC_TYPES, true)) {
            $emp_where[] = "e.application_for = ?";
            $emp_params[] = $type_filter;
        }
        if ($link_filter === 'linked') {
            $emp_where[] = "e.admin_id IS NOT NULL";
        } elseif ($link_filter === 'unlinked') {
            $emp_where[] = "e.admin_id IS NULL";
        }

        $emp_sql = "
            SELECT e.*,
                   a.username AS linked_admin_username,
                   a.full_name AS linked_admin_name,
                   a.status AS linked_admin_status,
                   a.role AS linked_admin_role
            FROM employees e
            LEFT JOIN admins a ON e.admin_id = a.id
            WHERE " . implode(' AND ', $emp_where) . "
            ORDER BY e.id DESC
        ";
        $stmt_emp = $pdo->prepare($emp_sql);
        $stmt_emp->execute($emp_params);
        $employees = $stmt_emp->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $employees = [];
    }
}

if (srr_tables_exist($pdo)) {
    try {
        $applications = $pdo->query("SELECT id, application_reference, full_name, email, mobile_number, application_for, status, submitted_at, aadhaar_masked, bank_account_masked, approved_employee_id, appointment_reference FROM staff_registration_requests ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $applications = []; }
    // Invited-faculty columns exist only after database-update-59; enrich schema-tolerantly.
    try {
        $gf_extra = $pdo->query("SELECT id, payment_mode, guest_banking_submitted FROM staff_registration_requests WHERE application_for = 'guest_faculty'")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        foreach ($applications as &$gf_app_row) {
            $gx = $gf_extra[$gf_app_row['id']] ?? null;
            $gf_app_row['payment_mode'] = $gx['payment_mode'] ?? null;
            $gf_app_row['guest_banking_submitted'] = $gx['guest_banking_submitted'] ?? 0;
        }
        unset($gf_app_row);
    } catch (Exception $e) { /* pre-migration: guest columns absent, no guest rows possible */ }
}
$pending_count = count(array_filter($applications, fn($a) => in_array($a['status'], ['pending','under_review'], true)));
$type_labels = ['employee'=>'Employee','faculty'=>'Faculty','intern'=>'Intern','guest_faculty'=>'Invited Faculty'];
$status_colors = ['pending'=>'amber','under_review'=>'blue','approved'=>'green','rejected'=>'red','cancelled'=>'gray'];

// Load custom fields
$custom_fields = [];
try {
    $custom_fields = staff_normalize_custom_field_rows($pdo->query("SELECT * FROM employee_custom_fields ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) { $custom_fields = []; }
$cf_type_labels = ['text'=>'Text','number'=>'Number','email'=>'Email','date'=>'Date','dropdown'=>'Dropdown','textarea'=>'Textarea','phone'=>'Phone'];

include 'includes/admin_nav.php';
?>
<?php if ($tab === 'policy_terms'): ?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<?php endif; ?>

<?php if ($success_message): ?>
<div class="alert alert-ok"><i class="fas fa-check-circle"></i><span><?php echo e($success_message); ?></span></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-warn"><i class="fas fa-exclamation-circle"></i><span><?php echo e($error_message); ?></span></div>
<?php endif; ?>

<?php if (!emp_tables_exist($pdo)): ?>
<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>Employee Management tables are not installed. Please run <strong>database-update-22.sql</strong> in phpMyAdmin.</span></div>
<?php else: ?>

<!-- Tabs -->
<div class="panel" style="margin-bottom:1.2rem;">
    <div class="panel-head" style="gap:8px;flex-wrap:wrap;">
        <a href="?tab=employees" class="btn btn-sm <?php echo $tab==='employees' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-id-badge"></i> Approved Staff (<?php echo $emp_total_count; ?>)</a>
        <a href="?tab=faculties" class="btn btn-sm <?php echo $tab==='faculties' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-chalkboard-user"></i> Faculties (<?php echo $fac_total_count; ?>)</a>
        <a href="?tab=applications" class="btn btn-sm <?php echo $tab==='applications' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-file-alt"></i> Registration Requests (<?php echo count($applications); ?>)
            <?php if ($pending_count > 0): ?><span class="nav-badge" style="background:#f59e0b;color:#fff;margin-left:4px;"><?php echo $pending_count; ?></span><?php endif; ?>
        </a>
        <a href="?tab=custom_fields" class="btn btn-sm <?php echo $tab==='custom_fields' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-puzzle-piece"></i> Custom Fields (<?php echo count($custom_fields); ?>)</a>
        <a href="?tab=policy_terms" class="btn btn-sm <?php echo $tab==='policy_terms' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-file-contract"></i> Policy &amp; Terms</a>
    </div>
</div>

<?php if ($tab === 'employees'): ?>
<!-- ═══ APPROVED STAFF DIRECTORY TAB ═══ -->

<!-- Summary Metrics Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:1.2rem;">
    <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; display:flex; align-items:center; gap:14px;">
        <div style="width:44px; height:44px; border-radius:10px; background:var(--blue-soft); color:var(--blue-ink); display:flex; align-items:center; justify-content:center; font-size:1.2rem;"><i class="fas fa-users"></i></div>
        <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Total Staff</div>
            <div style="font-size:1.3rem; font-weight:800;"><?= $emp_total_count ?></div>
        </div>
    </div>
    <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; display:flex; align-items:center; gap:14px;">
        <div style="width:44px; height:44px; border-radius:10px; background:var(--green-soft); color:var(--green-ink); display:flex; align-items:center; justify-content:center; font-size:1.2rem;"><i class="fas fa-user-check"></i></div>
        <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Active Staff</div>
            <div style="font-size:1.3rem; font-weight:800; color:var(--brand-green,#16a34a);"><?= $emp_active_count ?></div>
        </div>
    </div>
    <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; display:flex; align-items:center; gap:14px;">
        <div style="width:44px; height:44px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.2rem;"><i class="fas fa-briefcase"></i></div>
        <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Probation / Contract</div>
            <div style="font-size:1.3rem; font-weight:800; color:#2563eb;"><?= $emp_prob_count ?></div>
        </div>
    </div>
    <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; display:flex; align-items:center; gap:14px;">
        <div style="width:44px; height:44px; border-radius:10px; background:var(--accent-soft,#ede9fe); color:var(--accent,#7c3aed); display:flex; align-items:center; justify-content:center; font-size:1.2rem;"><i class="fas fa-user-shield"></i></div>
        <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Linked to Admin</div>
            <div style="font-size:1.3rem; font-weight:800; color:var(--accent,#7c3aed);"><?= $emp_linked_count ?></div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-id-badge"></i></span>
        <h2>Approved Staff Directory (<?= count($employees) ?>)</h2>
    </div>

    <!-- Filters Toolbar -->
    <div class="panel-body filter-toolbar" style="padding:15px; border-bottom:1px solid var(--border); background:#f8fafc;">
        <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:0; width:100%;">
            <input type="hidden" name="tab" value="employees">
            <div style="flex:1.5; min-width:180px; position:relative;">
                <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.85rem; pointer-events:none;"></i>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name, ID, email, mobile…" style="width:100% !important; padding:8px 12px 8px 34px !important; border:1.5px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); height:42px !important;">
            </div>
            <select name="status" onchange="this.form.submit()" style="flex:1; min-width:125px; width:auto !important; height:42px !important; padding:0 36px 0 12px !important; border:1.5px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                <option value="">All Statuses</option>
                <?php foreach ($CANONICAL_STAFF_STATUSES as $st_k => $st_v): ?>
                    <option value="<?= $st_k ?>" <?= $status_filter === $st_k ? 'selected' : '' ?>><?= $st_v['label'] ?></option>
                <?php endforeach; ?>
            </select>
            <select name="type" onchange="this.form.submit()" style="flex:1; min-width:110px; width:auto !important; height:42px !important; padding:0 36px 0 12px !important; border:1.5px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                <option value="">All Types</option>
                <option value="employee" <?= $type_filter === 'employee' ? 'selected' : '' ?>>Employee</option>
                <option value="intern" <?= $type_filter === 'intern' ? 'selected' : '' ?>>Intern</option>
            </select>
            <select name="link" onchange="this.form.submit()" style="flex:1; min-width:125px; width:auto !important; height:42px !important; padding:0 36px 0 12px !important; border:1.5px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                <option value="">All Admin Links</option>
                <option value="linked" <?= $link_filter === 'linked' ? 'selected' : '' ?>>Linked to Admin</option>
                <option value="unlinked" <?= $link_filter === 'unlinked' ? 'selected' : '' ?>>Unlinked</option>
            </select>
            <button type="submit" class="btn btn-primary" style="flex-shrink:0; height:42px; padding:0 16px; border-radius:10px; white-space:nowrap; display:inline-flex; align-items:center; gap:6px;"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search || $status_filter || $type_filter || $link_filter): ?>
                <a href="?tab=employees" class="btn btn-outline" title="Reset Filters" style="flex-shrink:0; height:42px; padding:0 14px; border-radius:10px; white-space:nowrap; display:inline-flex; align-items:center; justify-content:center; gap:6px;"><i class="fas fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel-body flush table-wrap">
        <?php if (empty($employees)): ?>
            <div style="padding:2.5rem; text-align:center; color:var(--text-muted);">
                <i class="fas fa-user-slash" style="font-size:2.2rem; opacity:0.3; margin-bottom:10px;"></i>
                <div>No staff records found matching your filters.</div>
            </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Staff ID</th>
                    <th>Type</th>
                    <th>Dept &amp; Designation</th>
                    <th>Joined</th>
                    <th>Employment Status</th>
                    <th>Linked Admin</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $emp):
                $st_meta = $CANONICAL_STAFF_STATUSES[$emp['status']] ?? ['label' => ucfirst($emp['status']), 'color' => 'gray'];
                $s_photo = $emp['photo'] ?? '';
                $s_photo_valid = (!empty($s_photo) && strpos($s_photo, '..') === false && file_exists(__DIR__ . '/' . $s_photo));
            ?>
                <tr>
                    <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div style="width:38px; height:38px; border-radius:50%; background:<?= $s_photo_valid ? 'url(' . htmlspecialchars($s_photo, ENT_QUOTES, 'UTF-8') . ') center/cover no-repeat' : 'linear-gradient(135deg, var(--accent,#7c3aed), var(--accent-hover,#6d28d9))' ?>; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; flex-shrink:0; border:1.5px solid var(--border); box-shadow:0 1px 4px rgba(0,0,0,0.1);">
                                <?= $s_photo_valid ? '' : strtoupper(substr($emp['full_name'] ?: 'S', 0, 1)) ?>
                            </div>
                            <div>
                                <div class="cell-main" style="font-weight:700; font-size:0.9rem;"><?php echo e($emp['full_name']); ?></div>
                                <div class="cell-sub" style="font-size:0.76rem;"><?php echo e($emp['email']); ?> · <?php echo e($emp['mobile_country_code'] ?: '+91'); ?> <?php echo e($emp['mobile_number']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge blue" style="font-weight:700; font-size:0.75rem;"><?php echo e($emp['employee_id']); ?></span></td>
                    <td><span class="badge gray" style="font-size:0.72rem;"><?php echo $type_labels[$emp['application_for']] ?? ucfirst($emp['application_for']); ?></span></td>
                    <td>
                        <div class="cell-main" style="font-weight:600; font-size:0.85rem;"><?php echo e($emp['designation']); ?></div>
                        <div class="cell-sub" style="font-size:0.75rem; color:var(--text-muted);"><?php echo e($emp['department']); ?></div>
                    </td>
                    <td class="cell-sub" style="font-size:0.8rem;"><?php echo !empty($emp['joining_date']) ? date('d M Y', strtotime($emp['joining_date'])) : '—'; ?></td>
                    <td>
                        <span class="badge <?= $st_meta['color'] ?>" style="font-weight:700; font-size:0.72rem;">
                            <?= $st_meta['label'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($emp['admin_id'])): ?>
                            <span class="badge blue" style="font-size:0.72rem;" title="Linked to Admin #<?= (int)$emp['admin_id'] ?>">
                                <i class="fas fa-link"></i> @<?= e($emp['linked_admin_username']) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge gray" style="font-size:0.72rem;"><i class="fas fa-unlink"></i> Unlinked</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="openStaffEditModal(<?php echo (int)$emp['id']; ?>)" title="View &amp; Edit Complete Profile">
                            <i class="fas fa-pen-to-square"></i> View / Edit
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="openQuickStatusModal(<?php echo (int)$emp['id']; ?>, '<?php echo e(addslashes($emp['full_name'])); ?>', '<?php echo e($emp['status']); ?>')" title="Change Employment Status">
                            <i class="fas fa-tag"></i> Status
                        </button>
                        <?php if (!empty($emp['appointment_reference'])): ?>
                        <a href="?action=appointment_pdf&id=<?php echo (int)$emp['id']; ?>&source=employee" class="btn btn-sm btn-outline" target="_blank" title="Download Official Appointment Letter">
                            <i class="fas fa-file-pdf" style="color:#ef4444;"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'faculties'): ?>
<!-- ═══ FACULTIES TAB ═══ -->

<!-- Summary Metrics Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:1.2rem;">
    <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; display:flex; align-items:center; gap:14px;">
        <div style="width:44px; height:44px; border-radius:10px; background:#f3e8ff; color:#7c3aed; display:flex; align-items:center; justify-content:center; font-size:1.2rem;"><i class="fas fa-chalkboard-user"></i></div>
        <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Total Faculties</div>
            <div style="font-size:1.3rem; font-weight:800;"><?= $fac_total_count ?></div>
        </div>
    </div>
    <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; display:flex; align-items:center; gap:14px;">
        <div style="width:44px; height:44px; border-radius:10px; background:var(--green-soft); color:var(--green-ink); display:flex; align-items:center; justify-content:center; font-size:1.2rem;"><i class="fas fa-user-check"></i></div>
        <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Active Faculties</div>
            <div style="font-size:1.3rem; font-weight:800; color:var(--brand-green,#16a34a);"><?= $fac_active_count ?></div>
        </div>
    </div>
    <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; display:flex; align-items:center; gap:14px;">
        <div style="width:44px; height:44px; border-radius:10px; background:#fee2e2; color:#ef4444; display:flex; align-items:center; justify-content:center; font-size:1.2rem;"><i class="fas fa-user-xmark"></i></div>
        <div>
            <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Inactive Faculties</div>
            <div style="font-size:1.3rem; font-weight:800; color:#ef4444;"><?= $fac_inactive_count ?></div>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:#f3e8ff;color:#7c3aed;"><i class="fas fa-chalkboard-user"></i></span>
        <h2>Approved Faculty Directory (<?= count($faculty_list) ?>)</h2>
    </div>

    <!-- Filters Toolbar -->
    <div class="panel-body filter-toolbar" style="padding:15px; border-bottom:1px solid var(--border); background:#f8fafc;">
        <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:0; width:100%;">
            <input type="hidden" name="tab" value="faculties">
            <div style="flex:1.5; min-width:180px; position:relative;">
                <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.85rem; pointer-events:none;"></i>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name, ID, email, mobile, department…" style="width:100% !important; padding:8px 12px 8px 34px !important; border:1.5px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); height:42px !important;">
            </div>
            <select name="status" onchange="this.form.submit()" style="flex:1; min-width:125px; width:auto !important; height:42px !important; padding:0 36px 0 12px !important; border:1.5px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                <option value="">All Statuses</option>
                <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
            <input type="text" name="academic_year" value="<?= e($fac_year_filter) ?>" placeholder="Academic Year (e.g. 2026-2027)" style="flex:1; min-width:150px; height:42px !important; padding:8px 12px !important; border:1.5px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text);">
            <button type="submit" class="btn btn-primary" style="flex-shrink:0; height:42px; padding:0 16px; border-radius:10px; white-space:nowrap; display:inline-flex; align-items:center; gap:6px;"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search || $status_filter || $fac_year_filter): ?>
                <a href="?tab=faculties" class="btn btn-outline" title="Reset Filters" style="flex-shrink:0; height:42px; padding:0 14px; border-radius:10px; white-space:nowrap; display:inline-flex; align-items:center; justify-content:center; gap:6px;"><i class="fas fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel-body flush table-wrap">
        <?php if (empty($faculty_list)): ?>
            <div style="padding:2.5rem; text-align:center; color:var(--text-muted);">
                <i class="fas fa-user-slash" style="font-size:2.2rem; opacity:0.3; margin-bottom:10px;"></i>
                <div>No faculty records found matching your filters.</div>
            </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Faculty Name</th>
                    <th>Faculty ID</th>
                    <th>Email</th>
                    <th>Mobile</th>
                    <th>Department</th>
                    <th>Academic Year</th>
                    <th>Faculty Status</th>
                    <th>Linked Faculty Profile</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($faculty_list as $fac): ?>
                <tr>
                    <td>
                        <div class="cell-main" style="display:flex; align-items:center; gap:8px;">
                            <div style="width:32px; height:32px; border-radius:50%; background:#f3e8ff; color:#7c3aed; display:flex; align-items:center; justify-content:center; font-size:0.8rem; font-weight:700;">
                                <?= strtoupper(substr($fac['full_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <span style="font-weight:600;"><?= e($fac['full_name']) ?></span>
                                <div class="cell-sub" style="font-size:0.75rem;"><?= e($fac['designation'] ?: 'Faculty') ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge purple" style="font-size:0.75rem;"><?= e($fac['employee_id']) ?></span>
                    </td>
                    <td class="cell-sub"><?= e($fac['email']) ?></td>
                    <td class="cell-sub"><?= e($fac['mobile_number']) ?></td>
                    <td><?= e($fac['department'] ?: '—') ?></td>
                    <td><?= e($fac['academic_year'] ?: '—') ?></td>
                    <td>
                        <?php if ($fac['status'] === 'active'): ?>
                            <span class="badge green"><i class="fas fa-circle-check"></i> Active</span>
                        <?php else: ?>
                            <span class="badge red"><i class="fas fa-circle-xmark"></i> Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($fac['linked_faculty_id'])): ?>
                            <a href="faculties.php?view=<?= (int)$fac['linked_faculty_id'] ?>" class="btn btn-sm btn-outline" style="font-size:0.75rem; gap:4px;" title="Open Faculty Operations Profile">
                                <i class="fas fa-arrow-up-right-from-square"></i> Profile #<?= (int)$fac['linked_faculty_id'] ?>
                            </a>
                        <?php else: ?>
                            <span class="badge gray" style="font-size:0.72rem;">Not Linked</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="openFacultyEditModal(<?= (int)$fac['id'] ?>)" title="View &amp; Edit Faculty Profile">
                            <i class="fas fa-pen-to-square"></i> View / Edit
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" style="<?= $fac['status'] === 'active' ? 'color:#ef4444; border-color:#fca5a5;' : 'color:#16a34a; border-color:#86efac;' ?>" onclick="toggleFacultyStatus(<?= (int)$fac['id'] ?>, '<?= e($fac['status']) ?>', '<?= e(addslashes($fac['full_name'])) ?>')" title="Toggle Active / Inactive Status">
                            <i class="fas <?= $fac['status'] === 'active' ? 'fa-user-slash' : 'fa-user-check' ?>"></i> <?= $fac['status'] === 'active' ? 'Make Inactive' : 'Make Active' ?>
                        </button>
                        <?php if (!empty($fac['linked_faculty_id'])): ?>
                        <a href="faculties.php?view=<?= (int)$fac['linked_faculty_id'] ?>" class="btn btn-sm btn-outline" title="Open Faculty Schedule &amp; Payments">
                            <i class="fas fa-calendar-days" style="color:#7c3aed;"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'applications'): ?>
<!-- ═══ REGISTRATION REQUESTS TAB ═══ -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-file-alt"></i></span>
        <h2>Staff Registration Requests</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($applications)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">No staff registration records yet.</div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Applicant</th><th>Ref</th><th>Type</th><th>Aadhaar</th><th>Bank</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($applications as $app): ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($app['full_name']); ?></div>
                        <div class="cell-sub"><?php echo e($app['email']); ?> · <?php echo e($app['mobile_number']); ?></div>
                    </td>
                    <td><span class="badge violet" style="font-size:.7rem;"><?php echo e($app['application_reference']); ?></span></td>
                    <?php $is_guest_app = ($app['application_for'] === GUEST_FACULTY_TYPE); ?>
                    <td>
                        <?php if ($is_guest_app): ?>
                            <span class="badge teal" style="font-size:.7rem;background:#ccfbf1;color:#0f766e;font-weight:700;"><i class="fas fa-star"></i> INVITED FACULTY</span>
                        <?php else: ?>
                            <span class="badge gray"><?php echo $type_labels[$app['application_for']] ?? ucfirst($app['application_for']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub"><?php echo $is_guest_app ? '—' : e($app['aadhaar_masked']); ?></td>
                    <td class="cell-sub"><?php echo $is_guest_app ? (!empty($app['guest_banking_submitted']) ? 'Provided' : '—') : e($app['bank_account_masked']); ?></td>
                    <td><span class="badge <?php echo $status_colors[$app['status']] ?? 'gray'; ?>"><?php echo ucfirst(str_replace('_',' ',$app['status'])); ?></span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="btn btn-sm btn-outline" onclick="viewApp(<?php echo $app['id']; ?>)" title="View Details"><i class="fas fa-eye"></i></button>
                        <?php if (in_array($app['status'], ['pending','under_review'])): ?>
                        <button class="btn btn-sm btn-outline" style="color:#22c55e;" onclick="openApproval(<?php echo $app['id']; ?>, '<?php echo e(addslashes($app['full_name'])); ?>', '<?php echo e($app['application_for']); ?>')" title="Approve"><i class="fas fa-check"></i></button>
                        <button class="btn btn-sm btn-outline" style="color:#ef4444;" onclick="openReject(<?php echo $app['id']; ?>)" title="Reject"><i class="fas fa-times"></i></button>
                        <?php endif; ?>
                        <?php if (!empty($app['appointment_reference'])): ?>
                        <a href="?action=appointment_pdf&id=<?php echo $app['id']; ?>&source=application" class="btn btn-sm btn-outline" target="_blank" title="Appointment PDF"><i class="fas fa-file-pdf"></i></a>
                        <?php endif; ?>
                        <?php if ($is_guest_app && $app['status'] === 'approved'): ?>
                        <span class="badge <?php echo ($app['payment_mode'] ?? '') === 'free' ? 'gray' : 'green'; ?>" style="font-size:.65rem;"><?php echo e(gf_payment_label($app['payment_mode'] ?? null)); ?></span>
                        <a href="faculties.php#invited-faculty" class="btn btn-sm btn-outline" title="Add to Faculty Directory / view in Faculties"><i class="fas fa-chalkboard-user"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'custom_fields'): ?>
<!-- ═══ CUSTOM FIELDS TAB ═══ -->
<?php
    $gf_banking_on = guest_faculty_banking_enabled($pdo);
    $gf_super = is_super_admin();
?>
<!-- Guest Faculty Registration — admin-controlled banking collection (server-side enforced) -->
<div class="panel" id="guestFacultyBankingSetting" style="margin-bottom:1.2rem;">
    <div class="panel-head">
        <span class="head-icon" style="background:#ccfbf1;color:#0f766e;"><i class="fas fa-university"></i></span>
        <h2>Guest Faculty Banking Details</h2>
        <div class="head-right">
            <span class="badge <?php echo $gf_banking_on ? 'green' : 'gray'; ?>" id="gfBankingState" style="font-size:.8rem;font-weight:800;"><?php echo $gf_banking_on ? 'ON' : 'OFF'; ?></span>
        </div>
    </div>
    <div class="panel-body">
        <div style="font-size:.7rem;text-transform:uppercase;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Guest Faculty Registration · Banking Details</div>
        <p style="font-size:.85rem;color:var(--text-muted);line-height:1.55;margin-bottom:12px;">When enabled, invited faculty applicants can provide banking/payment details during registration. When disabled, these fields are completely hidden from new invited faculty registration forms. <strong>When OFF the server also rejects any submitted banking data — it is never stored.</strong></p>
        <?php if ($gf_super): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo $gf_banking_on ? 'Turn OFF banking details for new invited faculty registrations?' : 'Turn ON banking details for new invited faculty registrations?'; ?>');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="set_guest_faculty_banking">
            <input type="hidden" name="banking_enabled" value="<?php echo $gf_banking_on ? '0' : '1'; ?>">
            <button type="submit" id="gfBankingToggleBtn" class="btn btn-sm <?php echo $gf_banking_on ? 'btn-outline' : 'btn-primary'; ?>">
                <i class="fas fa-toggle-<?php echo $gf_banking_on ? 'off' : 'on'; ?>"></i> Turn <?php echo $gf_banking_on ? 'OFF' : 'ON'; ?>
            </button>
        </form>
        <?php else: ?>
            <span style="font-size:.8rem;color:var(--text-muted);"><i class="fas fa-lock"></i> Only a Super Admin can change this setting.</span>
        <?php endif; ?>
        <span style="font-size:.75rem;color:var(--text-muted);margin-left:10px;">Public form: <code>invited-faculty-registration.php</code></span>
    </div>
</div>
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--accent-soft,#ede9fe);color:var(--accent,#7c3aed);"><i class="fas fa-puzzle-piece"></i></span>
        <h2>Employee Custom Fields</h2>
        <div class="head-right">
            <button class="btn btn-sm btn-primary" onclick="openCfModal('add')"><i class="fas fa-plus"></i> Add Field</button>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($custom_fields)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">No custom fields defined yet. Click "Add Field" to create one.</div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Order</th><th>Label</th><th>Key</th><th>Application Type</th><th>Type</th><th>Required</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($custom_fields as $cf):
                $cf_lbl = $cf['field_label'] ?? $cf['field_name'] ?? ('Field #' . $cf['id']);
                $cf_key_display = $cf['field_key'] ?? ('cf_' . $cf['id']);
                $cf_opts_display = $cf['field_options'] ?? $cf['dropdown_options'] ?? '';
            ?>
                <tr style="<?php echo ($cf['status'] ?? 'active') !== 'active' ? 'opacity:.55;' : ''; ?>">
                    <td class="cell-sub"><?php echo (int)($cf['sort_order'] ?? 0); ?></td>
                    <td class="cell-main"><?php echo e($cf_lbl); ?></td>
                    <td><code style="font-size:.75rem;background:var(--muted);padding:2px 6px;border-radius:4px;"><?php echo e($cf_key_display); ?></code></td>
                    <td><span class="badge <?php echo ['employee'=>'blue','faculty'=>'purple','intern'=>'amber'][$cf['application_for']] ?? 'gray'; ?>" style="font-size:.7rem;"><?php echo e($type_labels[$cf['application_for']] ?? ucfirst($cf['application_for'])); ?></span></td>
                    <td><span class="badge blue" style="font-size:.7rem;"><?php echo $cf_type_labels[$cf['field_type']] ?? ucfirst($cf['field_type']); ?></span>
                        <?php if ($cf['field_type'] === 'dropdown' && !empty($cf_opts_display)): ?>
                        <div class="cell-sub" style="margin-top:2px;font-size:.65rem;"><?php echo e(mb_strimwidth($cf_opts_display, 0, 60, '…')); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo !empty($cf['is_required']) ? 'amber' : 'gray'; ?>"><?php echo !empty($cf['is_required']) ? 'Required' : 'Optional'; ?></span></td>
                    <td><span class="badge <?php echo ($cf['status'] ?? 'active') === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($cf['status'] ?? 'active'); ?></span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="btn btn-sm btn-outline" onclick="editCf(<?php echo $cf['id']; ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_custom_field">
                            <input type="hidden" name="cf_id" value="<?php echo $cf['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline" style="color:<?php echo $cf['status'] === 'active' ? '#f59e0b' : '#22c55e'; ?>;" title="<?php echo $cf['status'] === 'active' ? 'Disable' : 'Enable'; ?>">
                                <i class="fas <?php echo $cf['status'] === 'active' ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($tab === 'policy_terms'): ?>
<!-- ═══ POLICY & TERMS MANAGEMENT TAB ═══ -->
<?php
    $policies_data = policy_list_all($pdo);
?>
<div class="panel" style="margin-bottom:1.2rem;">
    <div class="panel-head">
        <span class="head-icon" style="background:#e0f2fe;color:#0284c7;"><i class="fas fa-file-contract"></i></span>
        <h2>Policy &amp; Terms Management</h2>
    </div>
    <div class="panel-body">
        <p style="font-size:0.85rem; color:var(--text-muted); line-height:1.55; margin-bottom:1.2rem;">
            Manage institutional terms, conditions and policies required during staff and faculty registrations.
            Every registration submission captures an immutable snapshot of the exact policy version, timestamp, and client evidence agreed to.
        </p>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
            <?php foreach ($policies_data as $k => $pol):
                $pkey = (string)($pol['policy_key'] ?? (is_string($k) ? $k : ''));
                if ($pkey === '') continue;
            ?>
            <div style="background:var(--card); border:1px solid var(--border); border-radius:12px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 2px 6px rgba(0,0,0,0.03);">
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                        <span class="badge blue" style="font-weight:700; font-size:0.75rem;">v<?php echo e($pol['current_version'] ?? '1.0'); ?></span>
                        <span class="badge <?php echo ($pol['status'] ?? 'active') === 'active' ? 'green' : 'gray'; ?>" style="font-size:0.7rem; text-transform:uppercase;">
                            <?php echo e($pol['status'] ?? 'active'); ?>
                        </span>
                    </div>
                    <h3 style="font-size:1.05rem; font-weight:700; margin:0 0 6px 0; color:var(--text);">
                        <?php echo e($pol['title'] ?? policy_title_default($pkey)); ?>
                    </h3>
                    <div style="font-size:0.72rem; color:var(--text-muted); margin-bottom:12px;">
                        Slug: <code style="background:var(--muted); padding:2px 6px; border-radius:4px;"><?php echo e($pkey); ?></code>
                    </div>
                    <div style="font-size:0.75rem; color:var(--text-muted); line-height:1.5; margin-bottom:16px;">
                        <div><strong>Last Updated:</strong> <?php echo !empty($pol['updated_at']) ? date('M j, Y, g:i A', strtotime($pol['updated_at'])) : 'Initial'; ?></div>
                        <div><strong>Updated By:</strong> <?php echo e($pol['updated_by'] ?? 'System'); ?></div>
                    </div>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; border-top:1px solid var(--border); padding-top:12px;">
                    <button type="button" class="btn btn-sm btn-primary" onclick="openPolicyEdit('<?php echo e($pkey); ?>')" style="flex:1; justify-content:center;">
                        <i class="fas fa-pen"></i> Edit
                    </button>
                    <a href="policy-view.php?policy=<?php echo urlencode($pkey); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline" style="flex:1; justify-content:center;" title="Preview public view in new tab">
                        <i class="fas fa-external-link-alt"></i> Preview
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- ═══ MODAL: FACULTY VIEW / EDIT MODAL ══════════════════════════════ -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="facultyEditModal" class="modal-backdrop">
    <div style="background:var(--card); border:1px solid var(--border); border-radius:16px; padding:1.8rem; max-width:860px; width:100%; max-height:92vh; overflow-y:auto; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.2rem; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="head-icon" style="background:#f3e8ff;color:#7c3aed;"><i class="fas fa-chalkboard-user"></i></span>
                <div>
                    <h3 style="margin:0; font-size:1.15rem;" id="femTitle">Faculty Profile Details</h3>
                    <div style="font-size:0.75rem; color:var(--text-muted);" id="femSubTitle">Approved Faculty Information &amp; Banking</div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('facultyEditModal')" style="border-radius:50%; width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
        </div>

        <form method="POST" id="facultyEditForm" onsubmit="saveFacultyProfile(event)">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_faculty_profile">
            <input type="hidden" name="emp_id" id="femEmpId">

            <!-- SECTION 1: PERSONAL INFORMATION -->
            <div style="font-size:0.85rem; font-weight:800; color:var(--accent,#7c3aed); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:4px;">
                <i class="fas fa-user"></i> Personal Information
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Full Name *</label>
                    <input type="text" name="full_name" id="femFullName" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Gender *</label>
                    <select name="gender" id="femGender" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                        <option value="Prefer not to say">Prefer not to say</option>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Date of Birth *</label>
                    <input type="date" name="date_of_birth" id="femDob" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Blood Group *</label>
                    <select name="blood_group" id="femBloodGroup" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        <option value="A+">A+</option><option value="A-">A-</option>
                        <option value="B+">B+</option><option value="B-">B-</option>
                        <option value="AB+">AB+</option><option value="AB-">AB-</option>
                        <option value="O+">O+</option><option value="O-">O-</option>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Email Address *</label>
                    <input type="email" name="email" id="femEmail" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Mobile Number *</label>
                    <input type="text" name="mobile_number" id="femMobile" required pattern="[0-9]{10}" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Address *</label>
                <textarea name="address" id="femAddress" rows="2" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); resize:vertical;"></textarea>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:12px; margin-bottom:16px;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Place / City</label>
                    <input type="text" name="place_post_office" id="femPlace" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">PIN Code</label>
                    <input type="text" name="pincode" id="femPin" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">State</label>
                    <input type="text" name="state" id="femState" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Country</label>
                    <input type="text" name="country" id="femCountry" value="India" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
            </div>

            <!-- SECTION 2: FACULTY & ACADEMICS -->
            <div style="font-size:0.85rem; font-weight:800; color:var(--accent,#7c3aed); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:4px;">
                <i class="fas fa-graduation-cap"></i> Faculty &amp; Academic Status
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:16px;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Faculty ID</label>
                    <input type="text" id="femEmployeeId" readonly style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#f1f5f9; font-weight:700; color:var(--text); cursor:not-allowed;">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Academic Year *</label>
                    <input type="text" name="academic_year" id="femAcademicYear" required placeholder="e.g. 2026-2027" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Faculty Status *</label>
                    <select name="status" id="femStatus" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-weight:700;">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div id="femLinkedInfo" style="display:none; margin-bottom:16px; padding:10px 14px; background:#f8fafc; border:1px solid var(--border); border-radius:10px; font-size:0.82rem; color:var(--text-muted);">
                <i class="fas fa-link" style="color:#7c3aed;"></i> Linked to Faculty Operations Profile: <strong id="femLinkedName" style="color:var(--text);"></strong> (<span id="femLinkedId"></span>). <em>Session rates and schedules are managed in faculties.php.</em>
            </div>

            <!-- SECTION 3: BANKING DETAILS -->
            <div style="font-size:0.85rem; font-weight:800; color:var(--accent,#7c3aed); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:4px;">
                <i class="fas fa-building-columns"></i> Banking Credentials
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Bank Name</label>
                    <input type="text" name="bank_name" id="femBankName" placeholder="e.g. State Bank of India" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Account Number</label>
                    <div style="display:flex; gap:6px;">
                        <input type="text" id="femBankAccDisplay" readonly style="flex:1; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#f8fafc; font-family:monospace; letter-spacing:1px; color:var(--text);">
                        <button type="button" class="btn btn-sm btn-outline" id="femRevealBankBtn" onclick="revealFacultyBankInModal()" style="font-size:0.75rem;"><i class="fas fa-eye"></i> Reveal</button>
                        <button type="button" class="btn btn-sm btn-outline" id="femCopyBankBtn" onclick="copyFacultyBankInModal()" style="font-size:0.75rem;"><i class="fas fa-copy"></i> Copy</button>
                    </div>
                    <div style="margin-top:6px;">
                        <input type="text" name="bank_account_number" id="femBankAccInput" placeholder="Update Account Number (Leave empty to keep existing)" style="width:100%; padding:6px 10px; border:1px solid var(--border); border-radius:6px; font-size:0.8rem; background:var(--card);">
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">IFSC Code</label>
                    <input type="text" name="ifsc_code" id="femIfsc" placeholder="e.g. SBIN0001234" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-family:monospace;">
                </div>
                <div>
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">UPI ID</label>
                    <input type="text" name="upi_id" id="femUpi" placeholder="e.g. name@upi" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                </div>
            </div>

            <!-- SECTION 4: FACULTY CUSTOM FIELDS -->
            <div id="femCustomFieldsWrap" style="display:none; margin-bottom:16px;">
                <div style="font-size:0.85rem; font-weight:800; color:var(--accent,#7c3aed); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:4px;">
                    <i class="fas fa-puzzle-piece"></i> Faculty Custom Fields
                </div>
                <div id="femCustomFieldsContainer" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;"></div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; border-top:1px solid var(--border); padding-top:14px; margin-top:10px;">
                <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('facultyEditModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary" id="femSaveBtn"><i class="fas fa-check"></i> Save Faculty Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- ═══ MODAL 1: COMPLETE STAFF VIEW / EDIT MODAL (TABBED) ════════════ -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="staffEditModal" class="modal-backdrop">
    <div style="background:var(--card); border:1px solid var(--border); border-radius:16px; padding:1.8rem; max-width:860px; width:100%; max-height:92vh; overflow-y:auto; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.2rem; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-user-pen"></i></span>
                <div>
                    <h3 style="margin:0; font-size:1.15rem;" id="semTitle">Staff Profile Details</h3>
                    <div style="font-size:0.75rem; color:var(--text-muted);" id="semSubTitle">Loading staff information…</div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('staffEditModal')" style="border-radius:50%; width:32px; height:32px; padding:0; display:flex; align-items:center; justify-content:center;">&times;</button>
        </div>

        <!-- Modal Sub-Tabs -->
        <div style="display:flex; gap:6px; border-bottom:2px solid var(--border); margin-bottom:1.4rem; overflow-x:auto;">
            <button type="button" class="btn btn-sm sem-tab-btn active" id="semTabBtnPersonal" onclick="switchSemTab('personal')"><i class="fas fa-user"></i> Personal</button>
            <button type="button" class="btn btn-sm sem-tab-btn" id="semTabBtnEmployment" onclick="switchSemTab('employment')"><i class="fas fa-briefcase"></i> Employment</button>
            <button type="button" class="btn btn-sm sem-tab-btn" id="semTabBtnKyc" onclick="switchSemTab('kyc')"><i class="fas fa-id-card"></i> KYC Information</button>
            <button type="button" class="btn btn-sm sem-tab-btn" id="semTabBtnBank" onclick="switchSemTab('bank')"><i class="fas fa-building-columns"></i> Bank &amp; Payout</button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="staffEditForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_employee_profile">
            <input type="hidden" name="emp_id" id="semEmpId">

            <!-- TAB 1: PERSONAL INFORMATION -->
            <div id="semTabPersonal" class="sem-tab-content">
                <div style="display:flex; gap:20px; align-items:center; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:16px;">
                    <div id="semPhotoPreviewWrap" style="width:72px; height:72px; border-radius:50%; background:#e2e8f0; color:#475569; display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:700; flex-shrink:0; overflow:hidden; border:2px solid #fff; box-shadow:0 2px 6px rgba(0,0,0,0.1);">
                        <i class="fas fa-user"></i>
                    </div>
                    <div style="flex:1;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Profile Photo</label>
                        <input type="file" name="photo_file" accept=".jpg,.jpeg,.png,.webp" style="font-size:0.8rem;">
                        <div style="font-size:0.7rem; color:var(--text-muted); margin-top:3px;">Supported: JPG, PNG, WEBP (Max 5MB). Leave empty to retain existing.</div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Full Name *</label>
                        <input type="text" name="full_name" id="semFullName" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Gender *</label>
                        <select name="gender" id="semGender" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                            <option value="Prefer not to say">Prefer not to say</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Date of Birth *</label>
                        <input type="date" name="date_of_birth" id="semDob" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Blood Group *</label>
                        <select name="blood_group" id="semBloodGroup" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                            <option value="A+">A+</option><option value="A-">A-</option>
                            <option value="B+">B+</option><option value="B-">B-</option>
                            <option value="AB+">AB+</option><option value="AB-">AB-</option>
                            <option value="O+">O+</option><option value="O-">O-</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Email Address *</label>
                        <input type="email" name="email" id="semEmail" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Mobile Number *</label>
                        <div style="display:flex; gap:6px;">
                            <input type="text" name="mobile_country_code" id="semMobileCc" value="+91" style="width:70px; padding:8px 10px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-size:0.85rem;">
                            <input type="text" name="mobile_number" id="semMobile" required pattern="[0-9]{10}" style="flex:1; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Emergency Contact *</label>
                        <div style="display:flex; gap:6px;">
                            <input type="text" name="emergency_country_code" id="semEmergCc" value="+91" style="width:70px; padding:8px 10px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-size:0.85rem;">
                            <input type="text" name="emergency_contact" id="semEmergContact" required style="flex:1; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">PIN Code *</label>
                        <input type="text" name="pincode" id="semPincode" required pattern="[0-9]{6}" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Address *</label>
                    <textarea name="address" id="semAddress" rows="2" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); resize:vertical;"></textarea>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Place / Post Office</label>
                        <input type="text" name="place_post_office" id="semPlace" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">State</label>
                        <input type="text" name="state" id="semState" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Country</label>
                        <input type="text" name="country" id="semCountry" value="India" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                </div>
            </div>

            <!-- TAB 2: EMPLOYMENT INFORMATION -->
            <div id="semTabEmployment" class="sem-tab-content" style="display:none;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Employee ID (Immutable)</label>
                        <input type="text" id="semEmployeeId" readonly style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#f1f5f9; font-weight:700; color:var(--text); cursor:not-allowed;">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Staff Type (Immutable)</label>
                        <input type="text" id="semAppForDisplay" readonly style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#f1f5f9; font-weight:700; color:var(--text); cursor:not-allowed;">
                        <input type="hidden" name="application_for" id="semAppFor">
                    </div>
                </div>

                <!-- ════ ROLE SECTION 1: EMPLOYEE ════ -->
                <div id="semFieldsEmployee">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Designation *</label>
                            <input type="text" name="designation" id="semDesignation" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Department *</label>
                            <input type="text" name="department" id="semDepartment" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Joining Date *</label>
                            <input type="date" name="joining_date" id="semJoiningDate" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Probation Till</label>
                            <input type="date" name="probation_till" id="semProbationTill" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Contract From *</label>
                            <input type="date" name="contract_validity_from" id="semContractFrom" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Contract Till *</label>
                            <input type="date" name="contract_validity_till" id="semContractTill" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Monthly Salary (₹) *</label>
                        <input type="number" step="0.01" min="0" name="monthly_salary" id="semMonthlySalary" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                </div>

                <!-- ════ ROLE SECTION 2: INTERN ════ -->
                <div id="semFieldsIntern" style="display:none;">
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Designation</label>
                        <input type="text" value="Project Intern" readonly style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#f1f5f9; color:var(--text); cursor:not-allowed; font-weight:600;">
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Joining Date *</label>
                            <input type="date" name="intern_joining_date" id="semInternJoiningDate" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Internship Ends On *</label>
                            <input type="date" name="internship_ends_on" id="semInternEndsOn" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Internship Type *</label>
                        <select name="internship_payment_status" id="semInternPaymentStatus" onchange="toggleInternEditPaymentUI()" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                            <option value="unpaid">Unpaid Internship</option>
                            <option value="paid">Paid Internship</option>
                        </select>
                    </div>

                    <div id="semInternPaymentModeWrap" style="display:none; margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Payment Mode *</label>
                        <select name="internship_payment_mode" id="semInternPaymentMode" onchange="toggleInternEditModeUI()" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                            <option value="task_completion">Based on task completion</option>
                            <option value="monthly">Monthly Payment</option>
                            <option value="one_time">One-time Payment</option>
                        </select>
                    </div>

                    <div id="semInternRemunerationWrap" style="display:none; margin-bottom:12px;">
                        <label id="semInternRemunLabel" style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Internship Remuneration (₹) *</label>
                        <input type="number" name="internship_remuneration" id="semInternRemuneration" min="0.01" step="0.01" placeholder="Enter amount" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        <div style="font-size:0.7rem; color:var(--text-muted); margin-top:3px;">Tracked separately as intern remuneration (never stored as monthly salary).</div>
                    </div>
                </div>

                <!-- ════ ROLE SECTION 3: FACULTY ════ -->
                <div id="semFieldsFaculty" style="display:none;">
                    <div id="semFacultyLinkedBanner" class="alert alert-info" style="display:none; margin-bottom:12px; font-size:12px; padding:10px 12px;">
                        <i class="fas fa-link" style="color:var(--accent);"></i>
                        <span id="semFacultyLinkedText">Linked to Faculty Management. Updates to Faculty profile and session charges here will automatically synchronize with Faculty Management.</span>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Designation</label>
                            <input type="text" value="Faculty" readonly style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#f1f5f9; color:var(--text); cursor:not-allowed; font-weight:600;">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Department</label>
                            <input type="text" value="Academics" readonly style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#f1f5f9; color:var(--text); cursor:not-allowed; font-weight:600;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Joining Date</label>
                            <input type="date" name="faculty_joining_date" id="semFacultyJoiningDate" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">PEPP Academic Year *</label>
                            <select name="academic_year" id="semFacultyAcademicYear" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                                <option value="">— Select Academic Year —</option>
                                <?php foreach ($academic_years as $y): ?>
                                    <option value="<?php echo e($y); ?>"><?php echo e($y); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="font-size:0.8rem; font-weight:700; margin:14px 0 6px; color:var(--text-muted);"><i class="fas fa-indian-rupee-sign"></i> Session Charges (₹/Hour) — Optional</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                        <div>
                            <label style="display:block; font-size:0.75rem; font-weight:600; margin-bottom:4px;">Live Session (₹/hr)</label>
                            <input type="number" step="0.01" min="0" name="rate_live" id="semRateLive" value="0.00" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.75rem; font-weight:600; margin-bottom:4px;">QPD (₹/hr)</label>
                            <input type="number" step="0.01" min="0" name="rate_qpd" id="semRateQpd" value="0.00" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.75rem; font-weight:600; margin-bottom:4px;">Recorded (₹/hr)</label>
                            <input type="number" step="0.01" min="0" name="rate_recorded" id="semRateRecorded" value="0.00" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.75rem; font-weight:600; margin-bottom:4px;">Offline Session (₹/hr)</label>
                            <input type="number" step="0.01" min="0" name="rate_offline" id="semRateOffline" value="0.00" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                        </div>
                    </div>
                </div>

                <!-- Common Employment Status (All tracks) -->
                <div style="margin-top:12px; margin-bottom:12px;">
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Employment Status *</label>
                    <select name="status" id="semStatus" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-weight:700;">
                        <?php foreach ($CANONICAL_STAFF_STATUSES as $stk => $stv): ?>
                            <option value="<?= $stk ?>"><?= $stv['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Custom Fields Container -->
                <div id="semCustomFieldsWrap" style="margin-top:16px; border-top:1px dashed var(--border); padding-top:14px;">
                    <div style="font-size:0.8rem; font-weight:700; margin-bottom:8px; color:var(--text-muted);">Additional Custom Fields</div>
                    <div id="semCustomFieldsContainer" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;"></div>
                </div>
            </div>

            <!-- TAB 3: KYC INFORMATION -->
            <div id="semTabKyc" class="sem-tab-content" style="display:none;">
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:16px;">
                    <div style="font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px;">Identity &amp; Government Verification</div>
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap;">
                        <div style="flex:1;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Aadhaar Number (Encrypted &amp; Masked)</label>
                            <input type="text" id="semAadhaarDisplay" readonly value="XXXX XXXX 1234" style="width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:8px; background:#fff; font-family:monospace; font-size:0.95rem; letter-spacing:1px; font-weight:700;">
                        </div>
                        <div style="display:flex; gap:8px; align-items:flex-end; padding-top:20px;">
                            <button type="button" class="btn btn-sm btn-outline" id="semRevealAadhaarBtn" onclick="revealField('aadhaar')"><i class="fas fa-eye"></i> Reveal</button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="copyField('aadhaar')"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                    </div>
                </div>

                <div style="margin-top:16px; background:#fff; border:1px dashed var(--border); border-radius:10px; padding:14px;">
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Update Aadhaar Number</label>
                    <input type="text" name="aadhaar_number" id="semAadhaarInput" placeholder="Enter new 12-digit Aadhaar to change (leave blank to keep current)" pattern="[0-9]{12}" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-family:monospace;">
                    <div style="font-size:0.7rem; color:var(--text-muted); margin-top:4px;"><i class="fas fa-shield-halved"></i> New number will be automatically encrypted with AES-256-GCM.</div>
                </div>
            </div>

            <!-- TAB 4: BANK & PAYOUT INFORMATION -->
            <div id="semTabBank" class="sem-tab-content" style="display:none;">
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:16px;">
                    <div style="font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:10px;">Primary Salary / Payout Account</div>

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Bank Name</label>
                        <input type="text" name="bank_name" id="semBankName" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#fff;">
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; gap:14px; margin-bottom:12px; flex-wrap:wrap;">
                        <div style="flex:1;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Bank Account Number (Encrypted &amp; Masked)</label>
                            <input type="text" id="semBankAccDisplay" readonly value="XXXX1234" style="width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:8px; background:#fff; font-family:monospace; font-size:0.95rem; letter-spacing:1px; font-weight:700;">
                        </div>
                        <div style="display:flex; gap:8px; align-items:flex-end; padding-top:20px;">
                            <button type="button" class="btn btn-sm btn-outline" id="semRevealBankBtn" onclick="revealField('bank_account')"><i class="fas fa-eye"></i> Reveal</button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="copyField('bank_account')"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">IFSC Code</label>
                            <div style="display:flex; gap:6px;">
                                <input type="text" name="ifsc_code" id="semIfsc" style="flex:1; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#fff; text-transform:uppercase; font-family:monospace;">
                                <button type="button" class="btn btn-sm btn-outline" onclick="copyField('ifsc_code')" title="Copy IFSC"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                        <div>
                            <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">UPI ID</label>
                            <div style="display:flex; gap:6px;">
                                <input type="text" name="upi_id" id="semUpi" placeholder="e.g. name@okaxis" style="flex:1; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#fff;">
                                <button type="button" class="btn btn-sm btn-outline" onclick="copyField('upi_id')" title="Copy UPI"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:16px; background:#fff; border:1px dashed var(--border); border-radius:10px; padding:14px;">
                    <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Update Bank Account Number</label>
                    <input type="text" name="bank_account_number" id="semBankAccInput" placeholder="Enter new Account Number to change (leave blank to keep current)" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-family:monospace;">
                    <div style="font-size:0.7rem; color:var(--text-muted); margin-top:4px;"><i class="fas fa-shield-halved"></i> New account number will be encrypted automatically.</div>
                </div>
            </div>

            <!-- Form Footer -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; border-top:1px solid var(--border); padding-top:14px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('staffEditModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Profile Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- ═══ MODAL 2: QUICK EMPLOYMENT STATUS CHANGE MODAL ═════════════════ -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="quickStatusModal" class="modal-backdrop">
    <div style="background:var(--card); border:1px solid var(--border); border-radius:16px; padding:1.6rem; max-width:440px; width:100%; box-shadow:0 10px 25px rgba(0,0,0,0.15);">
        <h3 style="margin-bottom:0.8rem;"><i class="fas fa-tag" style="color:var(--accent,#7c3aed);"></i> Change Staff Status</h3>
        <p id="qsmStaffName" style="font-weight:700; font-size:0.9rem; color:var(--text-muted); margin-bottom:1rem;"></p>
        <form method="POST" id="quickStatusForm" onsubmit="submitQuickStatus(event)">
            <input type="hidden" name="id" id="qsmEmpId">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Employment Status *</label>
                <select name="status" id="qsmStatus" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-weight:700;">
                    <?php foreach ($CANONICAL_STAFF_STATUSES as $stk => $stv): ?>
                        <option value="<?= $stk ?>"><?= $stv['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Status Change Reason / Note (Optional)</label>
                <textarea name="reason" id="qsmReason" rows="2" placeholder="e.g. Completed probation period, contract extended, etc." style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-size:0.85rem; resize:vertical;"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('quickStatusModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ APPROVAL MODAL ═══ -->
<div id="approvalModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-check-circle" style="color:#22c55e;"></i> Approve Application</h3>
    <p id="approvalName" style="margin-bottom:1rem;color:var(--text-muted);"></p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="approve_application">
        <input type="hidden" name="app_id" id="approvalAppId">
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Designation *</label>
            <input type="text" name="designation" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
        </div>
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Department *</label>
            <select name="department" id="approvalDept" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                <option value="">— Select —</option>
            </select>
        </div>
        <div style="display:flex;gap:10px;margin-bottom:12px;">
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Joining Date *</label>
                <input type="date" name="joining_date" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Probation Till</label>
                <input type="date" name="probation_till" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
        </div>
        <div style="display:flex;gap:10px;margin-bottom:12px;">
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Contract From *</label>
                <input type="date" name="contract_from" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Contract Till *</label>
                <input type="date" name="contract_till" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
        </div>
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Monthly Salary (₹) *</label>
            <input type="number" name="monthly_salary" min="1" step="0.01" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('approvalModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#22c55e;border-color:#22c55e;"><i class="fas fa-check"></i> Approve &amp; Generate</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ INTERN APPROVAL MODAL ═══ -->
<div id="internApprovalModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-user-graduate" style="color:#0284c7;"></i> Approve Intern Application</h3>
    <p id="internApprovalName" style="margin-bottom:1rem;color:var(--text-muted);"></p>
    <form method="POST" onsubmit="return validateInternApprovalForm(this)">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="approve_application">
        <input type="hidden" name="app_id" id="internApprovalAppId">

        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Designation</label>
            <input type="text" name="designation" value="Project Intern" readonly style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:#f1f5f9;color:var(--text);cursor:not-allowed;">
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px;">
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Joining Date *</label>
                <input type="date" name="joining_date" id="internJoiningDate" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Internship Ends On *</label>
                <input type="date" name="internship_ends_on" id="internEndsOn" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Internship Type *</label>
            <select name="internship_payment_status" id="internPaymentStatus" required onchange="toggleInternPaymentUI()" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                <option value="unpaid">Unpaid Internship</option>
                <option value="paid">Paid Internship</option>
            </select>
        </div>

        <!-- Payment Mode (Hidden if Unpaid) -->
        <div id="internPaymentModeWrap" style="display:none;margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Payment Mode *</label>
            <select name="internship_payment_mode" id="internPaymentMode" onchange="toggleInternModeUI()" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                <option value="task_completion">Based on task completion</option>
                <option value="monthly">Monthly Payment</option>
                <option value="one_time">One-time Payment</option>
            </select>
        </div>

        <!-- Remuneration (Hidden unless Monthly or One-time) -->
        <div id="internRemunerationWrap" style="display:none;margin-bottom:16px;">
            <label id="internRemunLabel" style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Remuneration (₹) *</label>
            <input type="number" name="internship_remuneration" id="internRemuneration" min="0.01" step="0.01" placeholder="Enter amount" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            <div style="font-size:0.7rem;color:var(--text-muted);margin-top:4px;">Note: Intern remuneration is tracked separately and will not be displayed on the appointment letter.</div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('internApprovalModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#0284c7;border-color:#0284c7;"><i class="fas fa-check"></i> Approve Intern</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ FACULTY APPROVAL MODAL ═══ -->
<div id="facultyApprovalModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-chalkboard-user" style="color:#7c3aed;"></i> Approve Faculty Application</h3>
    <p id="facultyApprovalName" style="margin-bottom:1rem;color:var(--text-muted);"></p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="approve_application">
        <input type="hidden" name="app_id" id="facultyApprovalAppId">

        <div style="display:flex;gap:10px;margin-bottom:12px;">
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Designation</label>
                <input type="text" name="designation" value="Faculty" readonly style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:#f1f5f9;color:var(--text);cursor:not-allowed;">
            </div>
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Department</label>
                <input type="text" name="department" value="Academics" readonly style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:#f1f5f9;color:var(--text);cursor:not-allowed;">
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">PEPP Academic Year *</label>
            <select name="academic_year" id="facultyApprovalYear" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                <option value="">— Select Academic Year —</option>
            </select>
        </div>

        <div style="font-size:0.8rem;font-weight:700;margin:14px 0 6px;color:var(--text-muted);">Session Charges (₹/Hour) — Optional (Default ₹0.00)</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
            <div>
                <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;">Live Session (₹/hr)</label>
                <input type="number" step="0.01" min="0" name="rate_live" value="0.00" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <div>
                <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;">QPD (₹/hr)</label>
                <input type="number" step="0.01" min="0" name="rate_qpd" value="0.00" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <div>
                <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;">Recorded (₹/hr)</label>
                <input type="number" step="0.01" min="0" name="rate_recorded" value="0.00" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <div>
                <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;">Offline Session (₹/hr)</label>
                <input type="number" step="0.01" min="0" name="rate_offline" value="0.00" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('facultyApprovalModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#7c3aed;border-color:#7c3aed;"><i class="fas fa-check"></i> Approve Faculty</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ INVITED (GUEST) FACULTY APPROVAL MODAL — dedicated; never uses employee rules ═══ -->
<div id="guestFacultyApprovalModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:620px;width:100%;max-height:92vh;overflow-y:auto;">
    <h3 style="margin-bottom:.4rem;"><i class="fas fa-star" style="color:#0f766e;"></i> Approve Invited Faculty</h3>
    <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:1rem;">Applicant details are read-only. Set the payment terms below; approval does not create a payment or a session.</p>
    <div style="display:flex;gap:14px;align-items:flex-start;margin-bottom:14px;">
        <div id="gfaPhotoWrap" style="width:84px;height:84px;border-radius:12px;background:#e2e8f0;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#64748b;"><i class="fas fa-user"></i></div>
        <div style="flex:1;font-size:.84rem;line-height:1.6;">
            <div><strong>Reference:</strong> <span id="gfaRef">—</span></div>
            <div><strong>Full Name:</strong> <span id="gfaName">—</span></div>
            <div><strong>Mobile:</strong> <span id="gfaMobile">—</span></div>
            <div><strong>Email:</strong> <span id="gfaEmail">—</span></div>
            <div><strong>Qualifications:</strong> <span id="gfaQuals" style="white-space:pre-wrap;">—</span></div>
        </div>
    </div>
    <div id="gfaBank" style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:.8rem;margin-bottom:14px;"></div>
    <form method="POST" id="gfaForm" onsubmit="return gfaSubmit(this);">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="approve_guest_faculty">
        <input type="hidden" name="app_id" id="gfaAppId">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <div style="font-size:.8rem;font-weight:700;color:var(--text-muted);">Faculty Payment Amount / Rate (₹ per hour) *</div>
            <button type="button" class="btn btn-sm btn-outline" id="gfaFreeBtn" onclick="gfaSetFree()">Set all to ₹0.00 (Free)</button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;">
            <?php foreach (['rate_live' => 'Live Session', 'rate_qpd' => 'QPD', 'rate_recorded' => 'Recorded', 'rate_offline' => 'Offline Session'] as $gf_rk => $gf_rl): ?>
            <div>
                <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px;"><?php echo $gf_rl; ?> (₹/hr)</label>
                <input type="number" step="0.01" min="0" max="999999.99" inputmode="decimal" name="<?php echo $gf_rk; ?>" id="gfa_<?php echo $gf_rk; ?>" placeholder="0.00" required oninput="gfaRefreshMode()" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <?php endforeach; ?>
        </div>
        <div id="gfaMode" style="font-size:.78rem;font-weight:700;margin-bottom:12px;color:var(--text-muted);">Enter an amount for every session type. <strong>₹0.00 is valid</strong> and means Not Payable / Free Faculty.</div>
        <label style="display:flex;align-items:center;gap:8px;font-size:.82rem;margin-bottom:16px;">
            <input type="checkbox" name="add_to_directory" value="1" id="gfaAddDir"> Also add to the Faculty Directory now (otherwise use “Add to Faculty Directory” in Faculties)
        </label>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('guestFacultyApprovalModal')">Cancel</button>
            <button type="submit" id="gfaSubmitBtn" class="btn btn-sm btn-primary" style="background:#0f766e;border-color:#0f766e;"><i class="fas fa-check"></i> Approve Invited Faculty</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ REJECT MODAL ═══ -->
<div id="rejectModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:460px;width:100%;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-times-circle" style="color:#ef4444;"></i> Reject Application</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="reject_application">
        <input type="hidden" name="app_id" id="rejectAppId">
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Reason for Rejection *</label>
            <textarea name="rejection_reason" rows="3" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);resize:vertical;"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#ef4444;border-color:#ef4444;"><i class="fas fa-times"></i> Reject</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ VIEW DETAIL MODAL ═══ -->
<div id="viewModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:620px;width:100%;max-height:90vh;overflow-y:auto;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-eye"></i> Application Details</h3>
    <div id="viewContent" style="font-size:.85rem;line-height:1.6;color:var(--text-muted);">Loading…</div>
    <div style="margin-top:1rem;text-align:right;">
        <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('viewModal')">Close</button>
    </div>
</div>
</div>

<!-- ═══ CUSTOM FIELD ADD/EDIT MODAL ═══ -->
<div id="cfModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;">
    <h3 id="cfModalTitle" style="margin-bottom:1rem;"><i class="fas fa-puzzle-piece" style="color:var(--accent,#7c3aed);"></i> Add Custom Field</h3>
    <form method="POST" id="cfForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" id="cfAction" value="add_custom_field">
        <input type="hidden" name="cf_id" id="cfId" value="">
        <div style="display:flex;gap:10px;margin-bottom:12px;">
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Field Label *</label>
                <input type="text" name="cf_label" id="cfLabel" required placeholder="e.g. Department Preference" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <div style="flex:1;" id="cfKeyWrap">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Field Key *</label>
                <input type="text" name="cf_key" id="cfKey" required pattern="[a-z][a-z0-9_]{1,49}" placeholder="e.g. dept_pref" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:monospace;">
                <div style="font-size:.65rem;color:var(--text-muted);margin-top:2px;">Lowercase, letters/numbers/underscore</div>
            </div>
        </div>
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Application Type *</label>
            <select name="cf_application_for" id="cfAppFor" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                <option value="employee">Employee</option>
                <option value="faculty">Faculty</option>
                <option value="intern">Intern</option>
                <option value="guest_faculty">Guest Faculty</option>
            </select>
            <div id="cfAppForNote" style="font-size:.65rem;color:var(--text-muted);margin-top:2px;">This field appears only on the selected application type's registration form.</div>
        </div>
        <div style="display:flex;gap:10px;margin-bottom:12px;">
            <div style="flex:1;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Field Type *</label>
                <select name="cf_type" id="cfType" required onchange="cfTypeChanged()" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                    <option value="email">Email</option>
                    <option value="date">Date</option>
                    <option value="dropdown">Dropdown</option>
                    <option value="textarea">Textarea</option>
                    <option value="phone">Phone</option>
                </select>
            </div>
            <div style="flex:0 0 80px;">
                <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Order</label>
                <input type="number" name="cf_sort_order" id="cfOrder" value="0" min="0" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
        </div>
        <div id="cfOptionsWrap" style="display:none;margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Dropdown Options *</label>
            <textarea name="cf_options" id="cfOptions" rows="3" placeholder="Option A, Option B, Option C" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);resize:vertical;"></textarea>
            <div style="font-size:.65rem;color:var(--text-muted);margin-top:2px;">Comma-separated list of allowed values</div>
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:inline-flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="cf_required" id="cfRequired" value="1" style="width:16px;height:16px;accent-color:var(--accent);">
                <span>Required field</span>
            </label>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('cfModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" id="cfSubmitBtn"><i class="fas fa-check"></i> Add Field</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ POLICY EDIT MODAL ═══ -->
<div id="policyEditModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.8rem;max-width:820px;width:100%;max-height:92vh;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,0.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;border-bottom:1px solid var(--border);padding-bottom:10px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="head-icon" style="background:#e0f2fe;color:#0284c7;"><i class="fas fa-file-contract"></i></span>
            <div>
                <h3 style="margin:0;font-size:1.15rem;" id="pemModalTitle">Edit Policy</h3>
                <div style="font-size:0.75rem;color:var(--text-muted);" id="pemModalSubtitle">Manage text content and version</div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('policyEditModal')" style="border-radius:50%;width:32px;height:32px;padding:0;display:flex;align-items:center;justify-content:center;">&times;</button>
    </div>

    <form method="POST" id="policyEditForm" onsubmit="return handlePolicySubmit(event);">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_policy">
        <input type="hidden" name="policy_key" id="pemKey" value="">
        <input type="hidden" name="content" id="pemContentInput" value="">

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;margin-bottom:4px;">Policy Title *</label>
                <input type="text" name="title" id="pemTitle" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:700;margin-bottom:4px;">Version Bumping</label>
                <select name="bump_version" id="pemBump" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                    <option value="minor" selected>Minor Bump (+0.1) — Recommended for Content Updates</option>
                    <option value="major">Major Bump (+1.0) — Substantive / Annual Revision</option>
                    <option value="none">Keep Current Version (Title/Status only — no content changes)</option>
                </select>
                <div style="font-size:0.68rem;color:var(--text-muted);margin-top:2px;">Current: <span id="pemCurrentVerBadge" style="font-weight:700;">v1.0</span></div>
            </div>
        </div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.8rem;font-weight:700;margin-bottom:6px;">Policy Content (Rich Text) *</label>
            <div id="policy-quill-editor" style="min-height:260px;background:#fff;color:#1e293b;border-radius:0 0 8px 8px;font-size:0.9rem;"></div>
            <div style="font-size:0.68rem;color:var(--text-muted);margin-top:4px;">Supports headings, bold, italic, numbered lists, bullet lists, links, and paragraphs. Unsafe script tags are strictly stripped on save.</div>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border);padding-top:12px;">
            <button type="button" class="btn btn-sm btn-outline" id="pemLivePreviewBtn" onclick="previewPolicyInModal()">
                <i class="fas fa-eye"></i> Quick Preview
            </button>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('policyEditModal')">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary" id="pemSubmitBtn"><i class="fas fa-save"></i> Save Policy</button>
            </div>
        </div>
    </form>
</div>
</div>

<style>
.sem-tab-btn {
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    padding: 8px 14px;
    font-weight: 700;
    font-size: 0.84rem;
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.2s ease;
}
.sem-tab-btn:hover {
    color: var(--text);
}
.sem-tab-btn.active {
    color: var(--accent, #7c3aed);
    border-bottom-color: var(--accent, #7c3aed);
    background: transparent;
}
</style>

<script>
const CSRF_TOKEN = '<?php echo csrf_token(); ?>';

function switchSemTab(tabName) {
    document.querySelectorAll('.sem-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.sem-tab-content').forEach(c => c.style.display = 'none');

    if (tabName === 'personal') {
        document.getElementById('semTabBtnPersonal').classList.add('active');
        document.getElementById('semTabPersonal').style.display = 'block';
    } else if (tabName === 'employment') {
        document.getElementById('semTabBtnEmployment').classList.add('active');
        document.getElementById('semTabEmployment').style.display = 'block';
    } else if (tabName === 'kyc') {
        document.getElementById('semTabBtnKyc').classList.add('active');
        document.getElementById('semTabKyc').style.display = 'block';
    } else if (tabName === 'bank') {
        document.getElementById('semTabBtnBank').classList.add('active');
        document.getElementById('semTabBank').style.display = 'block';
    }
}

function openStaffEditModal(empId) {
    switchSemTab('personal');
    document.getElementById('semEmpId').value = empId;
    document.getElementById('semTitle').textContent = 'Loading Staff Profile…';
    document.getElementById('semSubTitle').textContent = 'Fetching encrypted records from server…';
    document.getElementById('semAadhaarDisplay').value = 'XXXX XXXX 1234';
    document.getElementById('semBankAccDisplay').value = 'XXXX1234';
    document.getElementById('semAadhaarInput').value = '';
    document.getElementById('semBankAccInput').value = '';
    document.getElementById('semRevealAadhaarBtn').innerHTML = '<i class="fas fa-eye"></i> Reveal';
    document.getElementById('semRevealBankBtn').innerHTML = '<i class="fas fa-eye"></i> Reveal';

    openModal('staffEditModal');

    fetch('employee-management.php?action=get_employee_details&id=' + empId)
        .then(r => r.json())
        .then(d => {
            if (!d.success || !d.employee) {
                alert(d.error || 'Failed to load employee details.');
                closeModal('staffEditModal');
                return;
            }
            const emp = d.employee;
            document.getElementById('semTitle').textContent = emp.full_name + ' (' + emp.employee_id + ')';
            document.getElementById('semSubTitle').textContent = (emp.designation || 'Staff') + ' · ' + (emp.department || 'General') + (emp.linked_admin_username ? ' · Linked to Admin: @' + emp.linked_admin_username : ' · Unlinked');

            // Photo preview
            const photoWrap = document.getElementById('semPhotoPreviewWrap');
            if (emp.photo) {
                photoWrap.innerHTML = '<img src="../' + emp.photo + '" style="width:100%; height:100%; object-fit:cover;" alt="Photo">';
            } else {
                photoWrap.innerHTML = '<span style="font-size:1.6rem; color:#475569;">' + (emp.full_name ? emp.full_name.charAt(0).toUpperCase() : 'S') + '</span>';
            }

            // Personal
            document.getElementById('semFullName').value = emp.full_name || '';
            document.getElementById('semGender').value = emp.gender || 'Male';
            document.getElementById('semDob').value = emp.date_of_birth || '';
            document.getElementById('semBloodGroup').value = emp.blood_group || 'O+';
            document.getElementById('semEmail').value = emp.email || '';
            document.getElementById('semMobileCc').value = emp.mobile_country_code || '+91';
            document.getElementById('semMobile').value = emp.mobile_number || '';
            document.getElementById('semEmergCc').value = emp.emergency_country_code || '+91';
            document.getElementById('semEmergContact').value = emp.emergency_contact || '';
            document.getElementById('semPincode').value = emp.pincode || '';
            document.getElementById('semAddress').value = emp.address || '';
            document.getElementById('semPlace').value = emp.place_post_office || '';
            document.getElementById('semState').value = emp.state || '';
            document.getElementById('semCountry').value = emp.country || 'India';

            // Employment
            document.getElementById('semEmployeeId').value = emp.employee_id || '';
            document.getElementById('semStatus').value = emp.status || 'active';
            setupStaffEditFormForRole(emp);

            // Masked KYC & Bank Initial state
            document.getElementById('semAadhaarDisplay').value = emp.aadhaar_masked || 'XXXX XXXX 1234';
            document.getElementById('semBankName').value = emp.bank_name || '';
            document.getElementById('semBankAccDisplay').value = emp.bank_account_masked || 'XXXX1234';
            document.getElementById('semIfsc').value = emp.ifsc_code || '';
            document.getElementById('semUpi').value = emp.upi_id || '';

            // Custom fields render
            const cfContainer = document.getElementById('semCustomFieldsContainer');
            cfContainer.innerHTML = '';
            if (d.custom_fields && d.custom_fields.length > 0) {
                d.custom_fields.forEach(cf => {
                    const wrap = document.createElement('div');
                    const lbl = cf.field_label || cf.field_name || 'Custom Field';
                    wrap.innerHTML = '<label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">' + lbl + (cf.is_required == 1 ? ' *' : '') + '</label>';
                    let inp = '';
                    const optionsStr = cf.field_options || cf.dropdown_options || '';
                    if (cf.field_type === 'dropdown') {
                        const opts = optionsStr ? optionsStr.split(',').map(o => o.trim()) : [];
                        inp = '<select name="custom_fields[' + cf.id + ']" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">';
                        inp += '<option value="">— Select —</option>';
                        opts.forEach(o => {
                            const escapedOpt = o.replace(/"/g, '&quot;');
                            inp += '<option value="' + escapedOpt + '" ' + (cf.field_value === o ? 'selected' : '') + '>' + o + '</option>';
                        });
                        inp += '</select>';
                    } else if (cf.field_type === 'textarea') {
                        inp = '<textarea name="custom_fields[' + cf.id + ']" rows="2" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); resize:vertical;">' + (cf.field_value || '') + '</textarea>';
                    } else {
                        inp = '<input type="' + (cf.field_type === 'number' ? 'number' : (cf.field_type === 'date' ? 'date' : 'text')) + '" name="custom_fields[' + cf.id + ']" value="' + (cf.field_value || '').replace(/"/g, '&quot;') + '" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">';
                    }
                    wrap.innerHTML += inp;
                    cfContainer.appendChild(wrap);
                });
                document.getElementById('semCustomFieldsWrap').style.display = 'block';
            } else {
                document.getElementById('semCustomFieldsWrap').style.display = 'none';
            }
        })
        .catch(() => {
            alert('Server error loading employee details.');
            closeModal('staffEditModal');
        });
}

function setupStaffEditFormForRole(emp) {
    const role = (emp.application_for || 'employee').toLowerCase();
    const appForInput = document.getElementById('semAppFor');
    if (appForInput) appForInput.value = role;
    const appForDisplay = document.getElementById('semAppForDisplay');
    if (appForDisplay) appForDisplay.value = role.toUpperCase();

    const empSec = document.getElementById('semFieldsEmployee');
    const intSec = document.getElementById('semFieldsIntern');
    const facSec = document.getElementById('semFieldsFaculty');

    // Hide all role sections initially
    if (empSec) {
        empSec.style.display = 'none';
        empSec.querySelectorAll('input, select').forEach(el => el.disabled = true);
    }
    if (intSec) {
        intSec.style.display = 'none';
        intSec.querySelectorAll('input, select').forEach(el => el.disabled = true);
    }
    if (facSec) {
        facSec.style.display = 'none';
        facSec.querySelectorAll('input, select').forEach(el => el.disabled = true);
    }

    if (role === 'intern') {
        if (intSec) {
            intSec.style.display = 'block';
            intSec.querySelectorAll('input, select').forEach(el => el.disabled = false);
        }
        document.getElementById('semInternJoiningDate').value = emp.joining_date || '';
        document.getElementById('semInternEndsOn').value = emp.internship_ends_on || '';
        document.getElementById('semInternPaymentStatus').value = emp.internship_payment_status || 'unpaid';
        document.getElementById('semInternPaymentMode').value = emp.internship_payment_mode || 'task_completion';
        document.getElementById('semInternRemuneration').value = (emp.internship_remuneration !== undefined && emp.internship_remuneration !== null) ? emp.internship_remuneration : '';
        toggleInternEditPaymentUI();

    } else if (role === 'faculty') {
        if (facSec) {
            facSec.style.display = 'block';
            facSec.querySelectorAll('input, select').forEach(el => el.disabled = false);
        }
        document.getElementById('semFacultyJoiningDate').value = emp.joining_date || '';
        document.getElementById('semFacultyAcademicYear').value = emp.academic_year || '';
        document.getElementById('semRateLive').value = (emp.rate_live !== undefined && emp.rate_live !== null) ? emp.rate_live : '0.00';
        document.getElementById('semRateQpd').value = (emp.rate_qpd !== undefined && emp.rate_qpd !== null) ? emp.rate_qpd : '0.00';
        document.getElementById('semRateRecorded').value = (emp.rate_recorded !== undefined && emp.rate_recorded !== null) ? emp.rate_recorded : '0.00';
        document.getElementById('semRateOffline').value = (emp.rate_offline !== undefined && emp.rate_offline !== null) ? emp.rate_offline : '0.00';

        const banner = document.getElementById('semFacultyLinkedBanner');
        const text = document.getElementById('semFacultyLinkedText');
        if (emp.linked_faculty_id) {
            banner.style.display = 'block';
            text.textContent = 'Linked to Faculty Management (#' + emp.linked_faculty_id + (emp.linked_faculty_name ? ' - ' + emp.linked_faculty_name : '') + '). Updates to Faculty profile and session charges here will automatically synchronize with Faculty Management.';
        } else {
            banner.style.display = 'none';
        }

    } else {
        // Standard Employee
        if (empSec) {
            empSec.style.display = 'block';
            empSec.querySelectorAll('input, select').forEach(el => el.disabled = false);
        }
        document.getElementById('semDesignation').value = emp.designation || '';
        document.getElementById('semDepartment').value = emp.department || '';
        document.getElementById('semJoiningDate').value = emp.joining_date || '';
        document.getElementById('semProbationTill').value = emp.probation_till || '';
        document.getElementById('semContractFrom').value = emp.contract_validity_from || '';
        document.getElementById('semContractTill').value = emp.contract_validity_till || '';
        document.getElementById('semMonthlySalary').value = emp.monthly_salary || '';
    }
}

function toggleInternEditPaymentUI() {
    const statusEl = document.getElementById('semInternPaymentStatus');
    if (!statusEl) return;
    const status = statusEl.value;
    const modeWrap = document.getElementById('semInternPaymentModeWrap');
    const remunWrap = document.getElementById('semInternRemunerationWrap');
    const remunInput = document.getElementById('semInternRemuneration');
    if (status === 'paid') {
        if (modeWrap) modeWrap.style.display = 'block';
        toggleInternEditModeUI();
    } else {
        if (modeWrap) modeWrap.style.display = 'none';
        if (remunWrap) remunWrap.style.display = 'none';
        if (remunInput) {
            remunInput.value = '';
            remunInput.required = false;
        }
    }
}

function toggleInternEditModeUI() {
    const statusEl = document.getElementById('semInternPaymentStatus');
    const modeEl = document.getElementById('semInternPaymentMode');
    if (!statusEl || !modeEl) return;
    const status = statusEl.value;
    const mode = modeEl.value;
    const remunWrap = document.getElementById('semInternRemunerationWrap');
    const remunInput = document.getElementById('semInternRemuneration');
    const label = document.getElementById('semInternRemunLabel');

    if (status === 'paid' && (mode === 'monthly' || mode === 'one_time')) {
        if (remunWrap) remunWrap.style.display = 'block';
        if (remunInput) remunInput.required = true;
        if (label) label.textContent = (mode === 'monthly' ? 'Monthly Remuneration (₹) *' : 'One-time Remuneration (₹) *');
    } else {
        if (remunWrap) remunWrap.style.display = 'none';
        if (remunInput) {
            remunInput.required = false;
            remunInput.value = '';
        }
    }
}

// Client-side form validation before submitting staffEditForm
document.addEventListener('DOMContentLoaded', function() {
    const editForm = document.getElementById('staffEditForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            const role = (document.getElementById('semAppFor').value || 'employee').toLowerCase();
            if (role === 'intern') {
                const jd = document.getElementById('semInternJoiningDate').value;
                const ed = document.getElementById('semInternEndsOn').value;
                if (!jd) {
                    alert('Joining date is required for Intern.');
                    switchSemTab('employment');
                    e.preventDefault();
                    return false;
                }
                if (!ed) {
                    alert('Internship end date is required for Intern.');
                    switchSemTab('employment');
                    e.preventDefault();
                    return false;
                }
                if (ed < jd) {
                    alert('Internship end date must be on or after joining date.');
                    switchSemTab('employment');
                    e.preventDefault();
                    return false;
                }
                const status = document.getElementById('semInternPaymentStatus').value;
                const mode = document.getElementById('semInternPaymentMode').value;
                if (status === 'paid' && (mode === 'monthly' || mode === 'one_time')) {
                    const val = parseFloat(document.getElementById('semInternRemuneration').value || '0');
                    if (isNaN(val) || val <= 0) {
                        alert('Please enter a remuneration amount greater than zero.');
                        switchSemTab('employment');
                        e.preventDefault();
                        return false;
                    }
                }
            } else if (role === 'faculty') {
                const ay = document.getElementById('semFacultyAcademicYear').value;
                if (!ay) {
                    alert('PEPP Academic Year is required for Faculty.');
                    switchSemTab('employment');
                    e.preventDefault();
                    return false;
                }
            } else {
                const des = document.getElementById('semDesignation').value.trim();
                const dep = document.getElementById('semDepartment').value.trim();
                const jd = document.getElementById('semJoiningDate').value;
                const cf = document.getElementById('semContractFrom').value;
                const ct = document.getElementById('semContractTill').value;
                const sal = parseFloat(document.getElementById('semMonthlySalary').value || '0');

                if (!des || !dep || !jd || !cf || !ct || isNaN(sal) || sal <= 0) {
                    alert('All required Employment fields (Designation, Department, Joining Date, Contract Validity, Monthly Salary > 0) must be filled.');
                    switchSemTab('employment');
                    e.preventDefault();
                    return false;
                }
                if (ct < cf) {
                    alert('Contract till date must be on or after contract from date.');
                    switchSemTab('employment');
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
});

function revealField(field) {
    const empId = document.getElementById('semEmpId').value;
    const btn = (field === 'aadhaar') ? document.getElementById('semRevealAadhaarBtn') : document.getElementById('semRevealBankBtn');
    const disp = (field === 'aadhaar') ? document.getElementById('semAadhaarDisplay') : document.getElementById('semBankAccDisplay');

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Decrypting…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'reveal_sensitive_data');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('id', empId);
    fd.append('field', field);

    fetch('employee-management.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success && d.value) {
                disp.value = d.value;
                btn.innerHTML = '<i class="fas fa-eye-slash"></i> Revealed';
                btn.style.color = 'var(--brand-green,#16a34a)';
            } else {
                alert(d.error || 'Decryption failed or value unavailable.');
                btn.innerHTML = '<i class="fas fa-eye"></i> Reveal';
                btn.disabled = false;
            }
        })
        .catch(() => {
            alert('Failed to communicate with server.');
            btn.innerHTML = '<i class="fas fa-eye"></i> Reveal';
            btn.disabled = false;
        });
}

function copyField(field) {
    const empId = document.getElementById('semEmpId').value;

    const fd = new FormData();
    fd.append('action', 'copy_sensitive_data');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('id', empId);
    fd.append('field', field);

    fetch('employee-management.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success && d.value) {
                navigator.clipboard.writeText(d.value).then(() => {
                    alert('Copied ' + field.replace('_', ' ').toUpperCase() + ' to clipboard!');
                }).catch(() => {
                    prompt('Copy manually:', d.value);
                });
            } else {
                alert(d.error || 'No value available to copy.');
            }
        })
        .catch(() => {
            alert('Failed to retrieve value for copy.');
        });
}

function openQuickStatusModal(empId, staffName, currentStatus) {
    document.getElementById('qsmEmpId').value = empId;
    document.getElementById('qsmStaffName').textContent = 'Staff: ' + staffName;
    document.getElementById('qsmStatus').value = currentStatus || 'active';
    document.getElementById('qsmReason').value = '';
    openModal('quickStatusModal');
}

function submitQuickStatus(e) {
    e.preventDefault();
    const empId = document.getElementById('qsmEmpId').value;
    const status = document.getElementById('qsmStatus').value;
    const reason = document.getElementById('qsmReason').value;

    const fd = new FormData();
    fd.append('action', 'change_staff_status');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('id', empId);
    fd.append('status', status);
    fd.append('reason', reason);

    fetch('employee-management.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert(d.message || 'Status updated successfully.');
                location.reload();
            } else {
                alert(d.error || 'Status update failed.');
            }
        })
        .catch(() => {
            alert('Server error updating status.');
        });
}

function cfTypeChanged() {
    document.getElementById('cfOptionsWrap').style.display = document.getElementById('cfType').value === 'dropdown' ? 'block' : 'none';
}
function openCfModal(mode) {
    document.getElementById('cfAction').value = 'add_custom_field';
    document.getElementById('cfId').value = '';
    document.getElementById('cfLabel').value = '';
    document.getElementById('cfKey').value = '';
    document.getElementById('cfKey').readOnly = false;
    document.getElementById('cfKeyWrap').style.display = '';
    document.getElementById('cfType').value = 'text';
    document.getElementById('cfAppFor').value = 'employee';
    document.getElementById('cfAppForNote').textContent = "This field appears only on the selected application type's registration form.";
    document.getElementById('cfOptions').value = '';
    document.getElementById('cfOrder').value = '0';
    document.getElementById('cfRequired').checked = false;
    document.getElementById('cfModalTitle').innerHTML = '<i class="fas fa-puzzle-piece" style="color:var(--accent,#7c3aed);"></i> Add Custom Field';
    document.getElementById('cfSubmitBtn').innerHTML = '<i class="fas fa-check"></i> Add Field';
    cfTypeChanged();
    openModal('cfModal');
}
function editCf(id) {
    openCfModal('edit');
    document.getElementById('cfAction').value = 'update_custom_field';
    document.getElementById('cfId').value = id;
    document.getElementById('cfModalTitle').innerHTML = '<i class="fas fa-pen" style="color:var(--accent,#7c3aed);"></i> Edit Custom Field';
    document.getElementById('cfSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Save Changes';
    document.getElementById('cfKey').readOnly = true;
    document.getElementById('cfKeyWrap').style.display = 'none';

    fetch('employee-management.php?action=load_custom_field&id='+id).then(r=>r.json()).then(d=>{
        if (d.error) { alert(d.error); return; }
        document.getElementById('cfLabel').value = d.field_label || d.field_name || '';
        document.getElementById('cfKey').value = d.field_key || '';
        document.getElementById('cfType').value = d.field_type || 'text';
        document.getElementById('cfAppFor').value = d.application_for || 'employee';
        if (d.has_data) {
            document.getElementById('cfAppForNote').textContent = 'This field already has submitted values. Application Type can only be changed if no values exist (otherwise the save is blocked).';
        }
        document.getElementById('cfOptions').value = d.field_options || d.dropdown_options || '';
        document.getElementById('cfOrder').value = d.sort_order || '0';
        document.getElementById('cfRequired').checked = (parseInt(d.is_required) === 1);
        cfTypeChanged();
    });
}

// ═══ INVITED FACULTY APPROVAL (dedicated) ═══
function gfaSetFree() {
    ['rate_live','rate_qpd','rate_recorded','rate_offline'].forEach(k => { document.getElementById('gfa_' + k).value = '0.00'; });
    gfaRefreshMode();
}
function gfaRefreshMode() {
    const keys = ['rate_live','rate_qpd','rate_recorded','rate_offline'];
    const el = document.getElementById('gfaMode');
    let blank = false, sum = 0;
    keys.forEach(k => { const v = document.getElementById('gfa_' + k).value.trim(); if (v === '') blank = true; else sum += parseFloat(v) || 0; });
    if (blank) { el.innerHTML = 'Enter an amount for every session type. <strong>₹0.00 is valid</strong> and means Not Payable / Free Faculty.'; el.style.color = 'var(--text-muted)'; return; }
    if (sum === 0) { el.textContent = 'FREE FACULTY — ₹0.00 · Not Payable'; el.style.color = '#64748b'; }
    else { el.textContent = 'PAYABLE FACULTY — hourly rates as entered'; el.style.color = '#15803d'; }
}
function gfaSubmit(form) {
    const b = document.getElementById('gfaSubmitBtn');
    if (b.disabled) return false;
    b.disabled = true;
    b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving…';
    return true;
}
function openGuestApproval(id) {
    document.getElementById('gfaAppId').value = id;
    ['rate_live','rate_qpd','rate_recorded','rate_offline'].forEach(k => { document.getElementById('gfa_' + k).value = ''; });
    document.getElementById('gfaAddDir').checked = false;
    const sb = document.getElementById('gfaSubmitBtn'); sb.disabled = false; sb.innerHTML = '<i class="fas fa-check"></i> Approve Invited Faculty';
    gfaRefreshMode();
    ['gfaRef','gfaName','gfaMobile','gfaEmail','gfaQuals'].forEach(i => { document.getElementById(i).textContent = '…'; });
    document.getElementById('gfaBank').textContent = 'Loading…';
    openModal('guestFacultyApprovalModal');
    fetch('employee-management.php?action=load_guest_application&id=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert(d.error || 'Could not load application.'); closeModal('guestFacultyApprovalModal'); return; }
            const a = d.application;
            document.getElementById('gfaRef').textContent = a.reference || '—';
            document.getElementById('gfaName').textContent = a.full_name || '—';
            document.getElementById('gfaMobile').textContent = a.mobile || '—';
            document.getElementById('gfaEmail').textContent = a.email || '—';
            document.getElementById('gfaQuals').textContent = a.qualifications || '—';
            const pw = document.getElementById('gfaPhotoWrap');
            pw.textContent = '';
            if (a.photo) {
                const img = document.createElement('img');
                img.src = '../' + a.photo; img.alt = 'Photo'; img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                const link = document.createElement('a'); link.href = img.src; link.target = '_blank'; link.rel = 'noopener'; link.appendChild(img);
                pw.appendChild(link);
            } else { const ic = document.createElement('i'); ic.className = 'fas fa-user'; pw.appendChild(ic); }
            const bk = d.banking || {}; const bx = document.getElementById('gfaBank'); bx.textContent = '';
            const title = document.createElement('div'); title.style.cssText = 'font-weight:700;margin-bottom:4px;'; title.textContent = 'Banking details'; bx.appendChild(title);
            if (!bk.submitted) { const n = document.createElement('div'); n.textContent = 'Not provided by applicant.'; bx.appendChild(n); }
            else {
                if (bk.restricted) { const w = document.createElement('div'); w.style.color = '#b45309'; w.textContent = 'Restricted — your account is not permitted to view bank credentials.'; bx.appendChild(w); }
                [['Bank', bk.bank_name], ['Account', bk.account_masked], ['IFSC', bk.ifsc], ['UPI', bk.upi]].forEach(p => { const r = document.createElement('div'); r.textContent = p[0] + ': ' + (p[1] || '—'); bx.appendChild(r); });
            }
            if (a.status === 'approved') { alert('This application is already approved.'); closeModal('guestFacultyApprovalModal'); }
        })
        .catch(() => { alert('Error loading application.'); closeModal('guestFacultyApprovalModal'); });
}

function openApproval(id, name, type) {
    const appType = (type || 'employee').toLowerCase();
    if (appType === 'guest_faculty') { openGuestApproval(id); return; }
    if (appType === 'intern') {
        document.getElementById('internApprovalAppId').value = id;
        document.getElementById('internApprovalName').textContent = 'Approving Intern: ' + name;
        document.getElementById('internPaymentStatus').value = 'unpaid';
        document.getElementById('internPaymentMode').value = 'task_completion';
        document.getElementById('internRemuneration').value = '';
        toggleInternPaymentUI();
        openModal('internApprovalModal');
    } else if (appType === 'faculty') {
        document.getElementById('facultyApprovalAppId').value = id;
        document.getElementById('facultyApprovalName').textContent = 'Approving Faculty: ' + name;
        fetch('employee-management.php?action=get_academic_years').then(r=>r.json()).then(years=>{
            const sel = document.getElementById('facultyApprovalYear');
            sel.innerHTML = '<option value="">— Select Academic Year —</option>';
            years.forEach(y=>{
                const o = document.createElement('option');
                o.value = y;
                o.textContent = y;
                sel.appendChild(o);
            });
        }).catch(()=>{});
        openModal('facultyApprovalModal');
    } else {
        // Employee workflow (unchanged)
        document.getElementById('approvalAppId').value = id;
        document.getElementById('approvalName').textContent = 'Approving Employee: ' + name;
        fetch('employee-management.php?action=get_departments').then(r=>r.json()).then(depts=>{
            const sel = document.getElementById('approvalDept');
            sel.innerHTML = '<option value="">— Select —</option>';
            depts.forEach(d=>{const o=document.createElement('option');o.value=d;o.textContent=d;sel.appendChild(o);});
        });
        openModal('approvalModal');
    }
}

function toggleInternPaymentUI() {
    const status = document.getElementById('internPaymentStatus').value;
    const modeWrap = document.getElementById('internPaymentModeWrap');
    const remunWrap = document.getElementById('internRemunerationWrap');
    const remunInput = document.getElementById('internRemuneration');
    if (status === 'paid') {
        modeWrap.style.display = 'block';
        toggleInternModeUI();
    } else {
        modeWrap.style.display = 'none';
        remunWrap.style.display = 'none';
        remunInput.value = '';
        remunInput.required = false;
    }
}

function toggleInternModeUI() {
    const status = document.getElementById('internPaymentStatus').value;
    const mode = document.getElementById('internPaymentMode').value;
    const remunWrap = document.getElementById('internRemunerationWrap');
    const remunInput = document.getElementById('internRemuneration');
    const label = document.getElementById('internRemunLabel');

    if (status === 'paid' && (mode === 'monthly' || mode === 'one_time')) {
        remunWrap.style.display = 'block';
        remunInput.required = true;
        label.textContent = (mode === 'monthly' ? 'Monthly Remuneration (₹) *' : 'One-time Remuneration (₹) *');
    } else {
        remunWrap.style.display = 'none';
        remunInput.required = false;
        remunInput.value = '';
    }
}

function validateInternApprovalForm(form) {
    const jd = form.joining_date.value;
    const ed = form.internship_ends_on.value;
    if (jd && ed && ed < jd) {
        alert('Internship end date cannot be earlier than joining date.');
        return false;
    }
    const status = form.internship_payment_status.value;
    const mode = form.internship_payment_mode ? form.internship_payment_mode.value : '';
    if (status === 'paid' && (mode === 'monthly' || mode === 'one_time')) {
        const val = parseFloat(form.internship_remuneration.value || '0');
        if (isNaN(val) || val <= 0) {
            alert('Please enter a remuneration amount greater than zero.');
            return false;
        }
    }
    return true;
}

function openReject(id) {
    document.getElementById('rejectAppId').value = id;
    openModal('rejectModal');
}
function viewApp(id) {
    document.getElementById('viewContent').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
    openModal('viewModal');
    fetch('employee-management.php?action=load_application&id='+id).then(r=>r.json()).then(d=>{
        if (d.error) { document.getElementById('viewContent').innerHTML = d.error; return; }
        let h = '';
        if (d.photo) {
            h += '<div style="text-align:center; margin-bottom:1.5rem; display:flex; flex-direction:column; align-items:center; gap:8px;">';
            h += '  <a href="../' + d.photo + '" target="_blank" rel="noopener">';
            h += '    <img src="../' + d.photo + '" style="width:110px; height:110px; border-radius:50%; object-fit:cover; border:3px solid var(--accent,#7c3aed); box-shadow:0 4px 12px rgba(0,0,0,0.15);" alt="Staff Photo">';
            h += '  </a>';
            h += '  <span style="font-size:0.75rem; color:var(--text-muted);">Click photo to view full resolution</span>';
            h += '</div>';
        }
        h += '<table style="width:100%;border-collapse:collapse;">';
        const fields = [
            ['Reference', d.application_reference], ['Name', d.full_name],
            ['Gender', d.gender], ['DOB', d.date_of_birth], ['Blood Group', d.blood_group],
            ['Mobile', (d.mobile_country_code||'+91')+' '+d.mobile_number], ['Email', d.email],
            ['Emergency', (d.emergency_country_code||'+91')+' '+d.emergency_contact],
            ['Address', d.address], ['PIN', d.pincode], ['State', d.state],
            ['Place', d.place_post_office], ['Country', d.country],
            ['Aadhaar', d.aadhaar_masked], ['Bank', d.bank_name],
            ['Account', d.bank_account_masked], ['IFSC', d.ifsc_code],
            ['UPI', d.upi_id||'—'], ['Applied For', d.application_for],
            ['Status', d.status], ['Submitted', d.submitted_at],
            ['IP', d.ip_address||'—'],
        ];
        if (d.approved_employee_id) fields.push(['Employee ID', d.approved_employee_id]);
        if (d.appointment_reference) fields.push(['Appointment Ref', d.appointment_reference]);
        if (d.designation) fields.push(['Designation', d.designation]);
        if (d.department) fields.push(['Department', d.department]);

        // Intern fields
        if (d.application_for === 'intern') {
            if (d.internship_ends_on) fields.push(['Internship Ends', d.internship_ends_on]);
            if (d.internship_payment_status) fields.push(['Payment Status', d.internship_payment_status.toUpperCase()]);
            if (d.internship_payment_mode) fields.push(['Payment Mode', d.internship_payment_mode.replace('_', ' ')]);
            if (d.internship_remuneration) fields.push(['Remuneration', '₹' + parseFloat(d.internship_remuneration).toFixed(2)]);
        }
        // Faculty fields
        if (d.application_for === 'faculty') {
            if (d.academic_year) fields.push(['Academic Year', d.academic_year]);
            if (d.rate_live !== undefined && d.rate_live !== null) fields.push(['Live Session Rate', '₹' + (parseFloat(d.rate_live) || 0).toFixed(2) + '/hr']);
            if (d.rate_qpd !== undefined && d.rate_qpd !== null) fields.push(['QPD Rate', '₹' + (parseFloat(d.rate_qpd) || 0).toFixed(2) + '/hr']);
            if (d.rate_recorded !== undefined && d.rate_recorded !== null) fields.push(['Recorded Rate', '₹' + (parseFloat(d.rate_recorded) || 0).toFixed(2) + '/hr']);
            if (d.rate_offline !== undefined && d.rate_offline !== null) fields.push(['Offline Rate', '₹' + (parseFloat(d.rate_offline) || 0).toFixed(2) + '/hr']);
        }

        // Invited faculty: show only the fields this workflow collects (banking already permission-masked server-side)
        if (d.application_for === 'guest_faculty') {
            fields.length = 0;
            fields.push(['Reference', d.application_reference], ['Type', 'Invited Faculty'], ['Name', d.full_name],
                ['Mobile', (d.mobile_country_code||'+91')+' '+d.mobile_number], ['Email', d.email],
                ['Qualifications', d.qualifications], ['Bank', d.bank_name], ['Account', d.bank_account_masked],
                ['IFSC', d.ifsc_code], ['UPI', d.upi_id||'—'], ['Status', d.status], ['Submitted', d.submitted_at]);
            if (d.status === 'approved') {
                fields.push(['Payment', d.payment_mode === 'free' ? 'Not Payable (Free Faculty)' : (d.payment_mode === 'paid' ? 'Payable' : 'Not configured')]);
                fields.push(['Live / QPD / Rec / Off', ['rate_live','rate_qpd','rate_recorded','rate_offline'].map(k => '₹' + (parseFloat(d[k]) || 0).toFixed(2)).join(' / ')]);
            }
        }
        // Custom fields display (all roles)
        if (d.custom_fields_decoded && typeof d.custom_fields_decoded === 'object') {
            for (const [ck, cv] of Object.entries(d.custom_fields_decoded)) {
                let cleanKey = ck.replace(/^cf_/, '').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                fields.push(['[Custom] ' + cleanKey, cv || '—']);
            }
        }

        // Policy acceptance audit display (all roles)
        if (d.policy_acceptance) {
            fields.push(['Policy Accepted', d.policy_acceptance.policy_title + ' (v' + d.policy_acceptance.policy_version + ')']);
            fields.push(['Policy Accepted At', d.policy_acceptance.accepted_at + (d.policy_acceptance.ip_address ? ' [IP: ' + d.policy_acceptance.ip_address + ']' : '')]);
        }

        if (d.rejection_reason) fields.push(['Rejection Reason', d.rejection_reason]);
        fields.forEach(([k,v])=>{
            h+='<tr style="border-bottom:1px solid var(--border);"><td style="padding:6px 8px;font-weight:600;color:var(--text);white-space:nowrap;width:140px;">'+k+'</td><td style="padding:6px 8px;">'+((v||'—').toString().replace(/</g,'&lt;'))+'</td></tr>';
        });
        if (d.maps_url) {
            h+='<tr><td style="padding:6px 8px;font-weight:600;color:var(--text);">Location</td><td style="padding:6px 8px;"><a href="'+d.maps_url+'" target="_blank" style="color:var(--accent);">View on Maps</a></td></tr>';
        }
        h+='</table>';
        document.getElementById('viewContent').innerHTML = h;
    }).catch(()=>{document.getElementById('viewContent').innerHTML='Error loading details.';});
}

// ═══ FACULTY PROFILE MODAL & STATUS JS ═══════════════════════════════
function openFacultyEditModal(empId) {
    document.getElementById('femEmpId').value = empId;
    document.getElementById('femTitle').textContent = 'Loading Faculty Profile…';
    document.getElementById('femSubTitle').textContent = 'Fetching faculty information…';
    document.getElementById('femBankAccDisplay').value = 'XXXX1234';
    document.getElementById('femBankAccInput').value = '';
    document.getElementById('femRevealBankBtn').innerHTML = '<i class="fas fa-eye"></i> Reveal';
    document.getElementById('femRevealBankBtn').disabled = false;
    document.getElementById('femCopyBankBtn').innerHTML = '<i class="fas fa-copy"></i> Copy';
    document.getElementById('femCopyBankBtn').disabled = false;

    openModal('facultyEditModal');

    fetch('employee-management.php?action=get_faculty_details&id=' + empId)
        .then(r => r.json())
        .then(d => {
            if (!d.success || !d.faculty) {
                alert(d.error || 'Failed to load faculty details.');
                closeModal('facultyEditModal');
                return;
            }
            const fac = d.faculty;
            document.getElementById('femTitle').textContent = fac.full_name + ' (' + fac.employee_id + ')';
            document.getElementById('femSubTitle').textContent = 'Approved Faculty · ' + (fac.academic_year || 'Year Not Set');
            document.getElementById('femFullName').value = fac.full_name || '';
            document.getElementById('femGender').value = fac.gender || 'Male';
            document.getElementById('femDob').value = fac.date_of_birth || '';
            document.getElementById('femBloodGroup').value = fac.blood_group || 'O+';
            document.getElementById('femEmail').value = fac.email || '';
            document.getElementById('femMobile').value = fac.mobile_number || '';
            document.getElementById('femAddress').value = fac.address || '';
            document.getElementById('femPlace').value = fac.place_post_office || '';
            document.getElementById('femPin').value = fac.pincode || '';
            document.getElementById('femState').value = fac.state || '';
            document.getElementById('femCountry').value = fac.country || 'India';

            document.getElementById('femEmployeeId').value = fac.employee_id || '';
            document.getElementById('femAcademicYear').value = fac.academic_year || '';
            document.getElementById('femStatus').value = fac.status || 'active';

            if (fac.linked_faculty_id) {
                document.getElementById('femLinkedInfo').style.display = 'block';
                document.getElementById('femLinkedName').textContent = fac.linked_faculty_name || fac.full_name;
                document.getElementById('femLinkedId').textContent = 'Profile #' + fac.linked_faculty_id;
            } else {
                document.getElementById('femLinkedInfo').style.display = 'none';
            }

            document.getElementById('femBankName').value = fac.bank_name || '';
            document.getElementById('femBankAccDisplay').value = fac.bank_account_masked || 'XXXX1234';
            document.getElementById('femIfsc').value = fac.ifsc_code || '';
            document.getElementById('femUpi').value = fac.upi_id || '';

            // Custom fields render
            const cfContainer = document.getElementById('femCustomFieldsContainer');
            cfContainer.innerHTML = '';
            if (d.custom_fields && d.custom_fields.length > 0) {
                d.custom_fields.forEach(cf => {
                    const wrap = document.createElement('div');
                    const lbl = cf.field_label || cf.field_name || 'Custom Field';
                    wrap.innerHTML = '<label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">' + lbl + (cf.is_required == 1 ? ' *' : '') + '</label>';
                    let inp = '';
                    const optionsStr = cf.field_options || cf.dropdown_options || '';
                    if (cf.field_type === 'dropdown') {
                        const opts = optionsStr ? optionsStr.split(',').map(o => o.trim()) : [];
                        inp = '<select name="custom_fields[' + cf.id + ']" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">';
                        inp += '<option value="">— Select —</option>';
                        opts.forEach(o => {
                            const escapedOpt = o.replace(/"/g, '&quot;');
                            inp += '<option value="' + escapedOpt + '" ' + (cf.field_value === o ? 'selected' : '') + '>' + o + '</option>';
                        });
                        inp += '</select>';
                    } else if (cf.field_type === 'textarea') {
                        inp = '<textarea name="custom_fields[' + cf.id + ']" rows="2" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); resize:vertical;">' + (cf.field_value || '') + '</textarea>';
                    } else {
                        inp = '<input type="' + (cf.field_type === 'number' ? 'number' : (cf.field_type === 'date' ? 'date' : 'text')) + '" name="custom_fields[' + cf.id + ']" value="' + (cf.field_value || '').replace(/"/g, '&quot;') + '" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">';
                    }
                    wrap.innerHTML += inp;
                    cfContainer.appendChild(wrap);
                });
                document.getElementById('femCustomFieldsWrap').style.display = 'block';
            } else {
                document.getElementById('femCustomFieldsWrap').style.display = 'none';
            }
        })
        .catch(() => {
            alert('Server error loading faculty details.');
            closeModal('facultyEditModal');
        });
}

function revealFacultyBankInModal() {
    const empId = document.getElementById('femEmpId').value;
    const btn = document.getElementById('femRevealBankBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('action', 'reveal_sensitive_data');
    fd.append('id', empId);
    fd.append('field', 'bank_account');
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('employee-management.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.success && d.value) {
                document.getElementById('femBankAccDisplay').value = d.value;
                btn.innerHTML = '<i class="fas fa-eye-slash"></i> Revealed';
            } else {
                alert(d.error || 'Permission denied: cannot reveal bank account.');
                btn.innerHTML = '<i class="fas fa-eye"></i> Reveal';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-eye"></i> Reveal';
            alert('Server error revealing bank account.');
        });
}

function copyFacultyBankInModal() {
    const empId = document.getElementById('femEmpId').value;
    const btn = document.getElementById('femCopyBankBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('action', 'copy_sensitive_data');
    fd.append('id', empId);
    fd.append('field', 'bank_account');
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('employee-management.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.success && d.value) {
                navigator.clipboard.writeText(d.value).then(() => {
                    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 2000);
                }).catch(() => {
                    alert('Clipboard access denied. Bank account: ' + d.value);
                    btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                });
            } else {
                alert(d.error || 'Permission denied: cannot copy bank account.');
                btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
            alert('Server error copying bank account.');
        });
}

function saveFacultyProfile(e) {
    e.preventDefault();
    const btn = document.getElementById('femSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const form = document.getElementById('facultyEditForm');
    const fd = new FormData(form);

    fetch('employee-management.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Faculty Changes';
            if (d.success) {
                alert(d.message || 'Faculty profile updated successfully.');
                closeModal('facultyEditModal');
                window.location.reload();
            } else {
                alert(d.error || 'Failed to update faculty profile.');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Faculty Changes';
            alert('Server error saving faculty profile.');
        });
}

function toggleFacultyStatus(empId, currentStatus, name) {
    const nextStatus = currentStatus === 'active' ? 'Inactive' : 'Active';
    if (!confirm('Are you sure you want to change status of faculty "' + name + '" to ' + nextStatus + '?')) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'toggle_faculty_status');
    fd.append('id', empId);
    fd.append('csrf_token', CSRF_TOKEN);

    fetch('employee-management.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                alert(d.message || 'Status updated successfully.');
                window.location.reload();
            } else {
                alert(d.error || 'Failed to update status.');
            }
        })
        .catch(() => {
            alert('Server error updating status.');
        });
}

// ═══ POLICY & TERMS MANAGEMENT JS ════════════════════════════════════
let policyQuill = null;

function initPolicyQuill() {
    if (policyQuill) return;
    const container = document.getElementById('policy-quill-editor');
    if (!container) return;
    policyQuill = new Quill('#policy-quill-editor', {
        theme: 'snow',
        placeholder: 'Enter official policy content here...',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['link'],
                ['clean']
            ]
        }
    });
}

function openPolicyEdit(key) {
    initPolicyQuill();
    openModal('policyEditModal');
    document.getElementById('pemKey').value = key;
    document.getElementById('pemTitle').value = 'Loading…';
    document.getElementById('pemCurrentVerBadge').textContent = 'Loading…';
    if (policyQuill) {
        policyQuill.root.innerHTML = '<p>Loading policy content…</p>';
    }

    fetch('employee-management.php?action=get_policy&key=' + encodeURIComponent(key))
        .then(r => r.json())
        .then(d => {
            if (!d.success || !d.policy) {
                alert(d.error || 'Failed to load policy.');
                closeModal('policyEditModal');
                return;
            }
            const p = d.policy;
            document.getElementById('pemModalTitle').textContent = 'Edit ' + (p.title || 'Policy');
            document.getElementById('pemTitle').value = p.title || '';
            document.getElementById('pemCurrentVerBadge').textContent = 'v' + (p.current_version || '1.0');
            const curSpans = document.querySelectorAll('.pemVerCur');
            curSpans.forEach(s => s.textContent = (p.current_version || '1.0'));
            if (policyQuill) {
                policyQuill.root.innerHTML = p.content || '';
            }
        })
        .catch(() => {
            alert('Server error loading policy.');
            closeModal('policyEditModal');
        });
}

function handlePolicySubmit(e) {
    if (!policyQuill) return true;
    const raw = policyQuill.getText().trim();
    if (raw === '') {
        alert('Policy content cannot be empty.');
        e.preventDefault();
        return false;
    }
    document.getElementById('pemContentInput').value = policyQuill.root.innerHTML;
    const btn = document.getElementById('pemSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    return true;
}

function previewPolicyInModal() {
    if (!policyQuill) return;
    const win = window.open('', '_blank');
    if (!win) {
        alert('Please allow popups to preview the policy in a new window.');
        return;
    }
    const title = document.getElementById('pemTitle').value || 'Policy Preview';
    const content = policyQuill.root.innerHTML;
    win.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Preview - ' + title + '</title><style>body{font-family:system-ui,-apple-system,sans-serif;max-width:800px;margin:2rem auto;padding:0 1.5rem;line-height:1.7;color:#1e293b;}h1,h2,h3{color:#0f172a;}hr{border:none;border-top:1px solid #e2e8f0;margin:1.5rem 0;}</style></head><body><h1>' + title + ' <small style="font-size:0.5em;color:#64748b;">(Draft Preview)</small></h1><hr/>' + content + '</body></html>');
    win.document.close();
}

// Escape key to close open modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['approvalModal', 'internApprovalModal', 'facultyApprovalModal', 'rejectModal', 'viewModal', 'cfModal', 'staffEditModal', 'quickStatusModal', 'facultyEditModal', 'policyEditModal'].forEach(id => {
            const m = document.getElementById(id);
            if (m && m.classList.contains('open')) {
                closeModal(id);
            }
        });
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>
