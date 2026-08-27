<?php
/**
 * PEPP Learning ERP — Employee Management (Super Admin Only)
 * - Employee Directory
 * - Staff Registration Records (pending/approved/rejected)
 * - Approval workflow with atomic Employee ID + Appointment Reference generation
 * - Appointment letter PDF (from immutable snapshot)
 * - Aadhaar/bank always masked
 */
require_once 'includes/auth.php';
require_super_admin();
require_once 'includes/encryption_helper.php';

$active_page = 'employee-management';
$page_title  = 'Employee Management';
$page_sub    = 'Staff records, applications & appointments';

$success_message = '';
$error_message = '';

// ── Self-healing: ensure tables exist ──
function emp_tables_exist($pdo) {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'employees'")->fetchColumn(); }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}
function srr_tables_exist($pdo) {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'staff_registration_requests'")->fetchColumn(); }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}

// ── Download appointment PDF ──
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

        // Log the download event
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

// ── AJAX: Load application details ──
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

// ── AJAX: Load departments ──
if (isset($_GET['action']) && $_GET['action'] === 'get_departments') {
    header('Content-Type: application/json');
    try {
        $depts = $pdo->query("SELECT department_name FROM departments WHERE status='active' ORDER BY sort_order, department_name")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($depts);
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// ── AJAX: Load custom field for editing ──
if (isset($_GET['action']) && $_GET['action'] === 'load_custom_field' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM employee_custom_fields WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_GET['id']]);
        $cf = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($cf ?: ['error' => 'Not found']);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// ── POST Actions ──
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
                $employee_id = 'EMP' . str_pad($emp_seq, 5, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = NOW() WHERE setting_name = 'emp_id_seq'")->execute([(string)($emp_seq + 1)]);

                // ── SAVEPOINT: Allocate Appointment Reference ──
                $pdo->exec("SAVEPOINT sp_appt_ref");
                $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'appt_ref_seq' FOR UPDATE");
                $stmt->execute();
                $appt_seq = (int)$stmt->fetchColumn();
                if ($appt_seq < 1) $appt_seq = 1;
                $fy = get_active_academic_year_compact($pdo);
                $appointment_ref = 'PEPP/HR/' . $fy . '/' . $employee_id . '/' . str_pad($appt_seq, 4, '0', STR_PAD_LEFT);
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
                        // Validate field IDs exist in employee_custom_fields
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

    // ═══ UPDATE STATUS ═══
    elseif ($action === 'update_status') {
        $app_id = (int)($_POST['app_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        if (in_array($new_status, ['under_review','cancelled'])) {
            try {
                $pdo->prepare("UPDATE staff_registration_requests SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$new_status, $admin_username, $app_id]);
                log_admin_activity($pdo, $admin_username, 'staff_status_change', "Changed application #{$app_id} to {$new_status}");
                $success_message = 'Status updated.';
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
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

        if (!$cf_label || !$cf_key) {
            $error_message = 'Field label and key are required.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $cf_key)) {
            $error_message = 'Field key must be lowercase letters/numbers/underscore, 2-50 chars, start with a letter.';
        } elseif (!in_array($cf_type, $allowed_types, true)) {
            $error_message = 'Invalid field type.';
        } elseif ($cf_type === 'dropdown' && empty($cf_opts)) {
            $error_message = 'Dropdown fields require at least one option.';
        } else {
            try {
                // Check duplicate key
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_custom_fields WHERE field_key = ?");
                $stmt->execute([$cf_key]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $error_message = 'A custom field with this key already exists.';
                } else {
                    $pdo->prepare("INSERT INTO employee_custom_fields (field_label, field_key, field_type, field_options, is_required, sort_order, status) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$cf_label, $cf_key, $cf_type, $cf_opts ?: null, $cf_req, $cf_order, 'active']);
                    log_admin_activity($pdo, $admin_username, 'custom_field_added', "Added custom field: {$cf_label} ({$cf_key}, {$cf_type})");
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
                $pdo->prepare("UPDATE employee_custom_fields SET field_label=?, field_type=?, field_options=?, is_required=?, sort_order=? WHERE id=?")
                    ->execute([$cf_label, $cf_type, $cf_opts ?: null, $cf_req, $cf_order, $cf_id]);
                log_admin_activity($pdo, $admin_username, 'custom_field_updated', "Updated custom field #{$cf_id}: {$cf_label}");
                $success_message = "Custom field \"{$cf_label}\" updated.";
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

// ── Load Data ──
$employees = [];
$applications = [];
$tab = $_GET['tab'] ?? 'employees';

if (emp_tables_exist($pdo)) {
    try {
        $employees = $pdo->query("SELECT id, employee_id, full_name, email, mobile_number, application_for, designation, department, joining_date, status, appointment_reference, aadhaar_masked, bank_account_masked, created_at FROM employees ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $employees = []; }
}
if (srr_tables_exist($pdo)) {
    try {
        $applications = $pdo->query("SELECT id, application_reference, full_name, email, mobile_number, application_for, status, submitted_at, aadhaar_masked, bank_account_masked, approved_employee_id, appointment_reference FROM staff_registration_requests ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $applications = []; }
}
$pending_count = count(array_filter($applications, fn($a) => in_array($a['status'], ['pending','under_review'])));
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
        <a href="?tab=employees" class="btn btn-sm <?php echo $tab==='employees' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-id-badge"></i> Employees (<?php echo count($employees); ?>)</a>
        <a href="?tab=applications" class="btn btn-sm <?php echo $tab==='applications' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-file-alt"></i> Applications (<?php echo count($applications); ?>)
            <?php if ($pending_count > 0): ?><span class="nav-badge" style="background:#f59e0b;color:#fff;margin-left:4px;"><?php echo $pending_count; ?></span><?php endif; ?>
        </a>
        <a href="?tab=custom_fields" class="btn btn-sm <?php echo $tab==='custom_fields' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-puzzle-piece"></i> Custom Fields (<?php echo count($custom_fields); ?>)</a>
    </div>
</div>

<?php if ($tab === 'employees'): ?>
<!-- ═══ EMPLOYEES TAB ═══ -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-id-badge"></i></span>
        <h2>Employee Directory</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($employees)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">No employees yet. Approve staff applications to create employee records.</div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Employee</th><th>ID</th><th>Type</th><th>Dept / Designation</th><th>Joined</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $emp): ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($emp['full_name']); ?></div>
                        <div class="cell-sub"><?php echo e($emp['email']); ?> · <?php echo e($emp['mobile_number']); ?></div>
                    </td>
                    <td><span class="badge blue"><?php echo e($emp['employee_id']); ?></span></td>
                    <td><span class="badge gray"><?php echo $type_labels[$emp['application_for']] ?? ucfirst($emp['application_for']); ?></span></td>
                    <td>
                        <div class="cell-main"><?php echo e($emp['department']); ?></div>
                        <div class="cell-sub"><?php echo e($emp['designation']); ?></div>
                    </td>
                    <td class="cell-sub"><?php echo date('d M Y', strtotime($emp['joining_date'])); ?></td>
                    <td><span class="badge <?php echo $emp['status']==='active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($emp['status']); ?></span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <?php if (!empty($emp['appointment_reference'])): ?>
                        <a href="?action=appointment_pdf&id=<?php echo $emp['id']; ?>&source=employee" class="btn btn-sm btn-outline" target="_blank" title="Appointment Letter"><i class="fas fa-file-pdf"></i></a>
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
<!-- ═══ APPLICATIONS TAB ═══ -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-file-alt"></i></span>
        <h2>Staff Registration Records</h2>
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
                        <button class="btn btn-sm btn-outline" style="color:#22c55e;" onclick="openApproval(<?php echo $app['id']; ?>, '<?php echo e($app['full_name']); ?>', '<?php echo e($app['application_for']); ?>')" title="Approve"><i class="fas fa-check"></i></button>
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
            <?php foreach ($custom_fields as $cf): ?>
                <tr style="<?php echo $cf['status'] !== 'active' ? 'opacity:.55;' : ''; ?>">
                    <td class="cell-sub"><?php echo (int)$cf['sort_order']; ?></td>
                    <td class="cell-main"><?php echo e($cf['field_label']); ?></td>
                    <td><code style="font-size:.75rem;background:var(--muted);padding:2px 6px;border-radius:4px;"><?php echo e($cf['field_key']); ?></code></td>
                    <td><span class="badge blue" style="font-size:.7rem;"><?php echo $cf_type_labels[$cf['field_type']] ?? ucfirst($cf['field_type']); ?></span>
                        <?php if ($cf['field_type'] === 'dropdown' && !empty($cf['field_options'])): ?>
                        <div class="cell-sub" style="margin-top:2px;font-size:.65rem;"><?php echo e(mb_strimwidth($cf['field_options'], 0, 60, '…')); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo $cf['is_required'] ? 'amber' : 'gray'; ?>"><?php echo $cf['is_required'] ? 'Required' : 'Optional'; ?></span></td>
                    <td><span class="badge <?php echo $cf['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($cf['status']); ?></span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="btn btn-sm btn-outline" onclick="editCf(<?php echo $cf['id']; ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_custom_field">
                            <input type="hidden" name="cf_id" value="<?php echo $cf['id']; ?>">
                            <button type="submit" class="btn btn-sm <?php echo $cf['status'] === 'active' ? 'btn-outline' : 'btn-outline'; ?>" style="color:<?php echo $cf['status'] === 'active' ? '#f59e0b' : '#22c55e'; ?>;" title="<?php echo $cf['status'] === 'active' ? 'Disable' : 'Enable'; ?>">
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

<!-- ═══ APPROVAL MODAL ═══ -->
<div id="approvalModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-check-circle" style="color:#22c55e;"></i> Approve Application</h3>
    <p id="approvalName" style="margin-bottom:1rem;color:var(--text-muted);"></p>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
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
            <button type="submit" class="btn btn-sm btn-primary" style="background:#22c55e;border-color:#22c55e;"><i class="fas fa-check"></i> Approve & Generate</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ REJECT MODAL ═══ -->
<div id="rejectModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:460px;width:100%;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-times-circle" style="color:#ef4444;"></i> Reject Application</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
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
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
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

<script>
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
    // Key is immutable after creation
    document.getElementById('cfKey').readOnly = true;
    document.getElementById('cfKeyWrap').style.display = 'none';
    // Load data
    fetch('?action=load_custom_field&id='+id).then(r=>r.json()).then(d=>{
        if (d.error) { alert(d.error); return; }
        document.getElementById('cfLabel').value = d.field_label || '';
        document.getElementById('cfKey').value = d.field_key || '';
        document.getElementById('cfType').value = d.field_type || 'text';
        document.getElementById('cfOptions').value = d.field_options || '';
        document.getElementById('cfOrder').value = d.sort_order || '0';
        document.getElementById('cfRequired').checked = (parseInt(d.is_required) === 1);
        cfTypeChanged();
    });
}

function openApproval(id, name, type) {
    document.getElementById('approvalAppId').value = id;
    document.getElementById('approvalName').textContent = 'Approving: ' + name + ' (' + type + ')';
    // Load departments
    fetch('?action=get_departments').then(r=>r.json()).then(depts=>{
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
    fetch('?action=load_application&id='+id).then(r=>r.json()).then(d=>{
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
        ['approvalModal', 'rejectModal', 'viewModal', 'cfModal'].forEach(id => {
            const m = document.getElementById(id);
            if (m && m.classList.contains('open')) {
                closeModal(id);
            }
        });
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>
