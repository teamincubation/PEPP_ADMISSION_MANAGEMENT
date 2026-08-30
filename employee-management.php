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

// ── Download appointment PDF ──────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'appointment_pdf' && isset($_GET['id'])) {
    $table = ($_GET['source'] ?? '') === 'employee' ? 'employees' : 'staff_registration_requests';
    try {
        $stmt = $pdo->prepare("SELECT appointment_snapshot, appointment_reference FROM {$table} WHERE id = ? LIMIT 1");
        $safe_id = (int)$_GET['id'];
        $stmt->execute([$safe_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException("Employee/application record not found for ID: " . $safe_id);
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
        $stmt = $pdo->prepare("
            SELECT e.*,
                   a.username AS linked_admin_username,
                   a.full_name AS linked_admin_name,
                   a.email AS linked_admin_email,
                   a.phone AS linked_admin_phone,
                   a.role AS linked_admin_role
            FROM employees e
            LEFT JOIN admins a ON e.admin_id = a.id
            WHERE e.id = ? LIMIT 1
        ");
        $stmt->execute([(int)$_GET['id']]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { echo json_encode(['error' => 'Employee record not found.']); exit; }

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
        $stmt = $pdo->prepare("SELECT id, employee_id, full_name, aadhaar_encrypted, bank_account_encrypted FROM employees WHERE id = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { echo json_encode(['error' => 'Employee not found.']); exit; }

        $cipher = ($field === 'aadhaar') ? $emp['aadhaar_encrypted'] : $emp['bank_account_encrypted'];
        $plain = $cipher ? pepp_decrypt($cipher) : '';

        // Audit log: NEVER persist decrypted plaintext in activity logs
        $field_label = ($field === 'aadhaar') ? 'Aadhaar Number' : 'Bank Account Number';
        log_admin_activity($pdo, $admin_username, 'sensitive_data_reveal', "Revealed {$field_label} for staff {$emp['full_name']} ({$emp['employee_id']})");

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
        $stmt = $pdo->prepare("SELECT id, employee_id, full_name, aadhaar_encrypted, bank_account_encrypted, ifsc_code, upi_id FROM employees WHERE id = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { echo json_encode(['error' => 'Employee not found.']); exit; }

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
        log_admin_activity($pdo, $admin_username, 'sensitive_data_copy', "Copied {$field_label} for staff {$emp['full_name']} ({$emp['employee_id']})");

        echo json_encode(['success' => true, 'field' => $field, 'value' => $plain]);
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to copy sensitive data.']);
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
        $stmt = $pdo->prepare("SELECT id, employee_id, full_name, status FROM employees WHERE id = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) { echo json_encode(['error' => 'Employee not found.']); exit; }

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
        echo json_encode($r);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
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
        $designation = trim($_POST['designation'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $joining_date = trim($_POST['joining_date'] ?? '');
        $probation_till = trim($_POST['probation_till'] ?? '') ?: null;
        $contract_from = trim($_POST['contract_from'] ?? '');
        $contract_till = trim($_POST['contract_till'] ?? '');
        $monthly_salary = (float)($_POST['monthly_salary'] ?? 0);

        if (!$designation || !$department || !$joining_date || !$contract_from || !$contract_till || $monthly_salary <= 0) {
            $error_message = 'All employment fields are required for approval.';
        } else {
            try {
                $pdo->beginTransaction();

                // Load application
                $stmt = $pdo->prepare("SELECT * FROM staff_registration_requests WHERE id = ? AND status IN ('pending','under_review') FOR UPDATE");
                $stmt->execute([$app_id]);
                $app = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$app) throw new Exception('Application not found or already processed.');

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
                $snapshot = json_encode([
                    'employee_name' => $app['full_name'],
                    'employee_id' => $employee_id,
                    'designation' => $designation,
                    'department' => $department,
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
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                // ── Insert employee record ──
                $stmt = $pdo->prepare("
                    INSERT INTO employees (employee_id, photo, full_name, gender, blood_group, date_of_birth,
                        mobile_country_code, mobile_number, email, emergency_country_code, emergency_contact,
                        address, pincode, country, state, place_post_office,
                        aadhaar_encrypted, aadhaar_masked, bank_name, bank_account_encrypted, bank_account_masked,
                        ifsc_code, upi_id, application_for, designation, department,
                        joining_date, probation_till, contract_validity_from, contract_validity_till, monthly_salary,
                        appointment_reference, appointment_snapshot, appointment_generated_at,
                        application_id, created_by, created_at)
                    VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?,NOW())
                ");
                $stmt->execute([
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
                ]);
                $emp_record_id = (int)$pdo->lastInsertId();

                // ── Update registration request ──
                $pdo->prepare("
                    UPDATE staff_registration_requests SET
                        status = 'approved',
                        approved_employee_id = ?, designation = ?, department = ?,
                        joining_date = ?, probation_till = ?,
                        contract_validity_from = ?, contract_validity_till = ?,
                        monthly_salary = ?, appointment_reference = ?,
                        appointment_snapshot = ?, appointment_generated_at = ?,
                        approved_by_admin_id = ?, approved_by_username = ?,
                        approved_at = ?, employee_record_id = ?, reviewed_by = ?, reviewed_at = ?
                    WHERE id = ?
                ")->execute([
                    $employee_id, $designation, $department,
                    $joining_date, $probation_till, $contract_from, $contract_till,
                    $monthly_salary, $appointment_ref,
                    $snapshot, $now,
                    $admin_row['id'] ?? null, $admin_username,
                    $now, $emp_record_id, $admin_username, $now,
                    $app_id
                ]);

                // ── Copy custom field values to employee_custom_values ──
                if (!empty($app['custom_field_values'])) {
                    $custom_vals = json_decode($app['custom_field_values'], true);
                    if (is_array($custom_vals)) {
                        $valid_field_ids = $pdo->query("SELECT id FROM employee_custom_fields")->fetchAll(PDO::FETCH_COLUMN);
                        $ins_cf = $pdo->prepare("INSERT INTO employee_custom_values (employee_id, field_id, field_value) VALUES (?,?,?)");
                        foreach ($custom_vals as $fid => $fval) {
                            $fid_int = (int)$fid;
                            if (in_array($fid_int, $valid_field_ids, true) && $fval !== '' && $fval !== null) {
                                $ins_cf->execute([$emp_record_id, $fid_int, (string)$fval]);
                            }
                        }
                    }
                }

                // ── Audit ──
                log_admin_activity($pdo, $admin_username, 'staff_approved',
                    "Approved {$app['full_name']} as {$employee_id} ({$designation}, {$department}). Ref: {$appointment_ref}");

                $pdo->commit();
                $success_message = "Application approved! Employee ID: {$employee_id}, Appointment Ref: {$appointment_ref}";

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('approve_application: ' . $e->getMessage());
                $error_message = 'Approval failed: ' . $e->getMessage();
            }
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
                $pdo->prepare("UPDATE staff_registration_requests SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$new_status, $admin_username, $app_id]);
                log_admin_activity($pdo, $admin_username, 'staff_status_change', "Changed application #{$app_id} to {$new_status}");
                $success_message = 'Status updated.';
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }

    // ═══ UPDATE EMPLOYEE PROFILE ═══
    elseif ($action === 'update_employee_profile') {
        $emp_id = (int)($_POST['emp_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
        $stmt->execute([$emp_id]);
        $current_emp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current_emp) {
            $error_message = 'Employee record not found.';
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
            $application_for = $_POST['application_for'] ?? 'employee';
            $designation = trim($_POST['designation'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $joining_date = trim($_POST['joining_date'] ?? '');
            $probation_till = trim($_POST['probation_till'] ?? '') ?: null;
            $contract_from = trim($_POST['contract_validity_from'] ?? '') ?: null;
            $contract_till = trim($_POST['contract_validity_till'] ?? '') ?: null;
            $monthly_salary = (float)($_POST['monthly_salary'] ?? 0);
            $status = trim($_POST['status'] ?? 'active');

            $bank_name = trim($_POST['bank_name'] ?? '');
            $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
            $upi_id = trim($_POST['upi_id'] ?? '') ?: null;

            // Server-side validation
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
            if (!$designation) $val_errors[] = 'Designation is required.';
            if (!$department) $val_errors[] = 'Department is required.';
            if (!$joining_date) $val_errors[] = 'Joining date is required.';
            if (!array_key_exists($status, $CANONICAL_STAFF_STATUSES)) $val_errors[] = 'Invalid employment status.';

            if (!empty($val_errors)) {
                $error_message = implode(' ', $val_errors);
            } else {
                try {
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

                    $stmt_upd = $pdo->prepare("
                        UPDATE employees SET
                            photo = ?, full_name = ?, gender = ?, blood_group = ?, date_of_birth = ?,
                            mobile_country_code = ?, mobile_number = ?, email = ?, emergency_country_code = ?, emergency_contact = ?,
                            address = ?, pincode = ?, country = ?, state = ?, place_post_office = ?,
                            application_for = ?, designation = ?, department = ?,
                            joining_date = ?, probation_till = ?, contract_validity_from = ?, contract_validity_till = ?,
                            monthly_salary = ?, status = ?,
                            aadhaar_encrypted = ?, aadhaar_masked = ?,
                            bank_name = ?, bank_account_encrypted = ?, bank_account_masked = ?,
                            ifsc_code = ?, upi_id = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt_upd->execute([
                        $photo_path, $full_name, $gender, $blood_group, $dob,
                        $mobile_cc, $mobile, $email, $emergency_cc, $emergency,
                        $address, $pincode, $country, $state, $place,
                        $application_for, $designation, $department,
                        $joining_date, $probation_till, $contract_from, $contract_till,
                        $monthly_salary, $status,
                        $aadhaar_encrypted, $aadhaar_masked,
                        $bank_name, $bank_acc_encrypted, $bank_acc_masked,
                        $ifsc, $upi_id,
                        $emp_id
                    ]);

                    // Update custom field values
                    if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
                        $valid_fids = $pdo->query("SELECT id FROM employee_custom_fields")->fetchAll(PDO::FETCH_COLUMN);
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

                    $success_message = "Staff profile \"{$full_name}\" ({$current_emp['employee_id']}) updated successfully.";
                } catch (Exception $e) {
                    $error_message = 'Failed to update staff profile: ' . $e->getMessage();
                }
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

        if (!$cf_label) {
            $error_message = 'Field label is required.';
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

                    log_admin_activity($pdo, $admin_username, 'custom_field_added', "Added custom field: {$cf_label} ({$cf_type})");
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

        if (!$cf_label || !$cf_id) {
            $error_message = 'Field ID and label are required.';
        } elseif (!in_array($cf_type, $allowed_types, true)) {
            $error_message = 'Invalid field type.';
        } elseif ($cf_type === 'dropdown' && empty($cf_opts)) {
            $error_message = 'Dropdown fields require at least one option.';
        } else {
            try {
                $cols = get_employee_custom_field_columns($pdo);
                $update_sets = [];
                $update_vals = [];

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
}

// ── Load Data & Server-Side Filtering ─────────────────────────────────
$employees = [];
$applications = [];
$tab = $_GET['tab'] ?? 'employees';

// Filters
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$type_filter = trim($_GET['type'] ?? '');
$link_filter = trim($_GET['link'] ?? '');

$emp_total_count = 0;
$emp_active_count = 0;
$emp_prob_count = 0;
$emp_linked_count = 0;

if (emp_tables_exist($pdo)) {
    try {
        // Overall statistics
        $emp_total_count = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
        $emp_active_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
        $emp_prob_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status IN ('probation', 'contract')")->fetchColumn();
        $emp_linked_count = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE admin_id IS NOT NULL")->fetchColumn();

        // Filtered employee list
        $emp_where = ["1=1"];
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
        if ($type_filter !== '' && in_array($type_filter, ['employee', 'faculty', 'intern'], true)) {
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
}
$pending_count = count(array_filter($applications, fn($a) => in_array($a['status'], ['pending','under_review'], true)));
$type_labels = ['employee'=>'Employee','faculty'=>'Faculty','intern'=>'Intern'];
$status_colors = ['pending'=>'amber','under_review'=>'blue','approved'=>'green','rejected'=>'red','cancelled'=>'gray'];

// Load custom fields
$custom_fields = [];
try {
    $custom_fields = $pdo->query("SELECT * FROM employee_custom_fields ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $custom_fields = []; }
$cf_type_labels = ['text'=>'Text','number'=>'Number','email'=>'Email','date'=>'Date','dropdown'=>'Dropdown','textarea'=>'Textarea','phone'=>'Phone'];

include 'includes/admin_nav.php';
?>

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
        <a href="?tab=applications" class="btn btn-sm <?php echo $tab==='applications' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-file-alt"></i> Registration Requests (<?php echo count($applications); ?>)
            <?php if ($pending_count > 0): ?><span class="nav-badge" style="background:#f59e0b;color:#fff;margin-left:4px;"><?php echo $pending_count; ?></span><?php endif; ?>
        </a>
        <a href="?tab=custom_fields" class="btn btn-sm <?php echo $tab==='custom_fields' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-puzzle-piece"></i> Custom Fields (<?php echo count($custom_fields); ?>)</a>
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
    <div class="panel-head" style="flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-id-badge"></i></span>
            <h2>Approved Staff Directory (<?= count($employees) ?>)</h2>
        </div>

        <!-- Real Server-Side Filters -->
        <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0;">
            <input type="hidden" name="tab" value="employees">
            <div style="position:relative;">
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search name, ID, email, mobile…" style="padding:6px 10px 6px 28px; border:1px solid var(--border); border-radius:8px; font-size:0.82rem; width:220px;">
                <i class="fas fa-search" style="position:absolute; left:9px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.75rem;"></i>
            </div>
            <select name="status" onchange="this.form.submit()" style="padding:6px 10px; border:1px solid var(--border); border-radius:8px; font-size:0.82rem; background:var(--card);">
                <option value="">All Statuses</option>
                <?php foreach ($CANONICAL_STAFF_STATUSES as $st_k => $st_v): ?>
                    <option value="<?= $st_k ?>" <?= $status_filter === $st_k ? 'selected' : '' ?>><?= $st_v['label'] ?></option>
                <?php endforeach; ?>
            </select>
            <select name="type" onchange="this.form.submit()" style="padding:6px 10px; border:1px solid var(--border); border-radius:8px; font-size:0.82rem; background:var(--card);">
                <option value="">All Types</option>
                <option value="employee" <?= $type_filter === 'employee' ? 'selected' : '' ?>>Employee</option>
                <option value="faculty" <?= $type_filter === 'faculty' ? 'selected' : '' ?>>Faculty</option>
                <option value="intern" <?= $type_filter === 'intern' ? 'selected' : '' ?>>Intern</option>
            </select>
            <select name="link" onchange="this.form.submit()" style="padding:6px 10px; border:1px solid var(--border); border-radius:8px; font-size:0.82rem; background:var(--card);">
                <option value="">All Admin Links</option>
                <option value="linked" <?= $link_filter === 'linked' ? 'selected' : '' ?>>Linked to Admin</option>
                <option value="unlinked" <?= $link_filter === 'unlinked' ? 'selected' : '' ?>>Unlinked</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search || $status_filter || $type_filter || $link_filter): ?>
                <a href="?tab=employees" class="btn btn-sm btn-outline" title="Reset Filters"><i class="fas fa-rotate-left"></i></a>
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
                    <td><span class="badge gray"><?php echo $type_labels[$app['application_for']] ?? ucfirst($app['application_for']); ?></span></td>
                    <td class="cell-sub"><?php echo e($app['aadhaar_masked']); ?></td>
                    <td class="cell-sub"><?php echo e($app['bank_account_masked']); ?></td>
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
            <thead><tr><th>Order</th><th>Label</th><th>Key</th><th>Type</th><th>Required</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
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
<?php endif; ?>
<?php endif; ?>

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
                        <input type="text" id="semEmployeeId" readonly style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:#f1f5f9; font-weight:700; color:var(--text);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Staff Type *</label>
                        <select name="application_for" id="semAppFor" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                            <option value="employee">Employee</option>
                            <option value="faculty">Faculty</option>
                            <option value="intern">Intern</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Designation *</label>
                        <input type="text" name="designation" id="semDesignation" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Department *</label>
                        <input type="text" name="department" id="semDepartment" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Joining Date *</label>
                        <input type="date" name="joining_date" id="semJoiningDate" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Probation Till</label>
                        <input type="date" name="probation_till" id="semProbationTill" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Contract From</label>
                        <input type="date" name="contract_validity_from" id="semContractFrom" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Contract Till</label>
                        <input type="date" name="contract_validity_till" id="semContractTill" style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Monthly Salary (₹) *</label>
                        <input type="number" step="0.01" min="0" name="monthly_salary" id="semMonthlySalary" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card);">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.8rem; font-weight:700; margin-bottom:4px;">Employment Status *</label>
                        <select name="status" id="semStatus" required style="width:100%; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-weight:700;">
                            <?php foreach ($CANONICAL_STAFF_STATUSES as $stk => $stv): ?>
                                <option value="<?= $stk ?>"><?= $stv['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
            document.getElementById('semAppFor').value = emp.application_for || 'employee';
            document.getElementById('semDesignation').value = emp.designation || '';
            document.getElementById('semDepartment').value = emp.department || '';
            document.getElementById('semJoiningDate').value = emp.joining_date || '';
            document.getElementById('semProbationTill').value = emp.probation_till || '';
            document.getElementById('semContractFrom').value = emp.contract_validity_from || '';
            document.getElementById('semContractTill').value = emp.contract_validity_till || '';
            document.getElementById('semMonthlySalary').value = emp.monthly_salary || 0;
            document.getElementById('semStatus').value = emp.status || 'active';

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
        document.getElementById('cfOptions').value = d.field_options || d.dropdown_options || '';
        document.getElementById('cfOrder').value = d.sort_order || '0';
        document.getElementById('cfRequired').checked = (parseInt(d.is_required) === 1);
        cfTypeChanged();
    });
}

function openApproval(id, name, type) {
    document.getElementById('approvalAppId').value = id;
    document.getElementById('approvalName').textContent = 'Approving: ' + name + ' (' + type + ')';
    fetch('employee-management.php?action=get_departments').then(r=>r.json()).then(depts=>{
        const sel = document.getElementById('approvalDept');
        sel.innerHTML = '<option value="">— Select —</option>';
        depts.forEach(d=>{const o=document.createElement('option');o.value=d;o.textContent=d;sel.appendChild(o);});
    });
    openModal('approvalModal');
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

// Escape key to close open modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['approvalModal', 'rejectModal', 'viewModal', 'cfModal', 'staffEditModal', 'quickStatusModal'].forEach(id => {
            const m = document.getElementById(id);
            if (m && m.classList.contains('open')) {
                closeModal(id);
            }
        });
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>
