<?php
require_once 'includes/auth.php';
require_super_admin();

/* Admin Management - Super Admin only.
   Create admin accounts, grant page/section access (current and future pages
   from the registry in includes/auth.php), activate/deactivate, reset
   passwords, link approved staff profiles, and remove accounts. Every change is recorded in the activity
   log. Only the Super Admin can delete data anywhere in the system. */

$success_message = '';
$error_message   = '';

// ── Namespaced State Containers (Create Admin vs Edit Admin Isolation) ──
$create_admin_error = '';
$reopen_create_admin_modal = false;
$form_ca_employee_id = 0;
$form_ca_username = '';
$form_ca_full_name = '';
$form_ca_email = '';
$form_ca_google_email = '';
$form_ca_phone = '';
$form_ca_password = '';
$form_ca_confirm_password = '';
$form_ca_admin_type = 'erp_admin';
$form_ca_cred_vis = 'visible';
$form_ca_cred_scopes = ['students'];
$form_ca_can_edit = true;
$form_ca_can_delete = true;
$form_ca_can_export = true;
$form_ca_allow_copy_email = true;
$form_ca_allow_whatsapp_chat = true;
$form_ca_allow_phone_call = true;
$form_ca_perm_all = false;
$form_ca_perms = [];

$edit_admin_error = '';
$reopen_edit_admin_modal = false;
$form_ea_admin_id = 0;
$form_ea_username = '';
$form_ea_full_name = '';
$form_ea_email = '';
$form_ea_google_email = '';
$form_ea_phone = '';
$form_ea_admin_type = 'erp_admin';
$form_ea_cred_vis = 'visible';
$form_ea_cred_scopes = [];
$form_ea_can_edit = true;
$form_ea_can_delete = false;
$form_ea_can_export = true;
$form_ea_allow_copy_email = true;
$form_ea_allow_whatsapp_chat = false;
$form_ea_allow_phone_call = true;
$form_ea_perm_all = false;
$form_ea_perms = [];

if (!admins_table_exists($pdo)) {
    $active_page = 'admin-management';
    $page_title  = 'Admin Management';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The multi-admin system is not installed yet. Please run <strong>database-update-2.sql</strong> once in phpMyAdmin, then reload this page.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

// ── Phone and Email Normalization / Masking Helpers ────────────────────
if (!function_exists('normalize_phone_number')) {
    function normalize_phone_number(?string $phone): string {
        if (!$phone) return '';
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && substr($digits, 0, 1) === '0') {
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
        if (!$phone) return '—';
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) <= 4) return '****';
        return str_repeat('*', max(1, strlen($digits) - 4)) . substr($digits, -4);
    }
}

// ── AJAX: Check Staff-Admin Match ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'check_staff_admin_match') {
    header('Content-Type: application/json');
    try {
        $admin_id = (int)($_GET['admin_id'] ?? 0);
        $emp_id = (int)($_GET['employee_id'] ?? 0);

        $stmt_a = $pdo->prepare("SELECT id, username, full_name, email, phone, role FROM admins WHERE id = ? LIMIT 1");
        $stmt_a->execute([$admin_id]);
        $adm = $stmt_a->fetch(PDO::FETCH_ASSOC);

        $stmt_e = $pdo->prepare("SELECT id, employee_id, full_name, email, mobile_number, photo, designation, department, status, admin_id FROM employees WHERE id = ? LIMIT 1");
        $stmt_e->execute([$emp_id]);
        $emp = $stmt_e->fetch(PDO::FETCH_ASSOC);

        if (!$adm || !$emp) {
            echo json_encode(['success' => false, 'error' => 'Admin or Staff profile not found.']);
            exit;
        }

        $admin_email_norm = strtolower(trim($adm['email'] ?? ''));
        $staff_email_norm = strtolower(trim($emp['email'] ?? ''));
        $admin_phone_norm = normalize_phone_number($adm['phone'] ?? '');
        $staff_phone_norm = normalize_phone_number($emp['mobile_number'] ?? '');

        $email_match = ($admin_email_norm !== '' && $staff_email_norm !== '' && $admin_email_norm === $staff_email_norm);
        $phone_match = ($admin_phone_norm !== '' && $staff_phone_norm !== '' && $admin_phone_norm === $staff_phone_norm);

        $errors = [];
        if (!$email_match) {
            $errors[] = "EMAIL MISMATCH: Staff email is '" . mask_email_display($emp['email']) . "' but Admin email is '" . mask_email_display($adm['email']) . "'. Canonical email addresses must match before linking.";
        }
        if (!$phone_match) {
            $errors[] = "MOBILE MISMATCH: Staff mobile is '" . mask_phone_display($emp['mobile_number']) . "' but Admin phone is '" . mask_phone_display($adm['phone']) . "'. Canonical mobile numbers must match before linking.";
        }

        $existing_conflict = null;
        if (!empty($emp['admin_id']) && (int)$emp['admin_id'] !== $admin_id) {
            $stmt_ca = $pdo->prepare("SELECT username FROM admins WHERE id = ?");
            $stmt_ca->execute([(int)$emp['admin_id']]);
            $conflict_admin_name = $stmt_ca->fetchColumn() ?: 'another admin';
            $existing_conflict = "Staff is currently linked to admin account '{$conflict_admin_name}'. Linking will reassign this staff profile.";
        }

        echo json_encode([
            'success' => true,
            'can_link' => ($email_match && $phone_match),
            'email_match' => $email_match,
            'phone_match' => $phone_match,
            'admin_email_masked' => mask_email_display($adm['email']),
            'staff_email_masked' => mask_email_display($emp['email']),
            'admin_phone_masked' => mask_phone_display($adm['phone']),
            'staff_phone_masked' => mask_phone_display($emp['mobile_number']),
            'admin' => [
                'id' => (int)$adm['id'],
                'username' => $adm['username'],
                'full_name' => $adm['full_name'],
                'role' => $adm['role']
            ],
            'staff' => [
                'id' => (int)$emp['id'],
                'employee_id' => $emp['employee_id'],
                'full_name' => $emp['full_name'],
                'photo' => $emp['photo'],
                'designation' => $emp['designation'],
                'department' => $emp['department']
            ],
            'conflict_warning' => $existing_conflict,
            'errors' => $errors
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Load All Approved Staff for Linking Dropdown ──────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_staff_list_for_linking') {
    header('Content-Type: application/json');
    try {
        $staff_list = $pdo->query("SELECT id, employee_id, full_name, email, mobile_number, photo, designation, department, status, admin_id FROM employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'staff' => $staff_list]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'staff' => []]);
    }
    exit;
}

// ── AJAX: Load Admin Details for Edit Modal ─────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_admin_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $admin_id = (int)$_GET['id'];
        $stmt = $pdo->prepare("
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
        $stmt->execute([$admin_id]);
        $adm = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$adm) {
            echo json_encode(['success' => false, 'error' => 'Admin record not found.']);
            exit;
        }
        echo json_encode(['success' => true, 'admin' => $adm]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to load admin details: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_admin') {
                $form_ca_employee_id = (int)($_POST['employee_id'] ?? 0);
                $form_ca_username = trim($_POST['username'] ?? '');
                $form_ca_full_name = trim($_POST['full_name'] ?? '');
                $form_ca_email = trim($_POST['email'] ?? '');
                $form_ca_google_email = trim($_POST['google_email'] ?? '');
                $form_ca_phone = trim($_POST['phone'] ?? '');
                $form_ca_password = $_POST['password'] ?? '';
                $form_ca_confirm_password = $_POST['confirm_password'] ?? '';
                $form_ca_admin_type = in_array($_POST['admin_type'] ?? 'erp_admin', ['superadmin','erp_admin','employee','intern','faculty'], true) ? $_POST['admin_type'] : 'erp_admin';
                $form_ca_cred_vis = in_array($_POST['credential_visibility'] ?? 'visible', ['visible', 'hide', 'mask'], true) ? $_POST['credential_visibility'] : 'visible';
                $form_ca_cred_scopes = (array)($_POST['credential_visibility_scopes'] ?? []);
                $form_ca_can_edit = isset($_POST['can_edit']);
                $form_ca_can_delete = isset($_POST['can_delete']);
                $form_ca_can_export = isset($_POST['can_export']);
                $form_ca_allow_copy_email = isset($_POST['allow_copy_email']);
                $form_ca_allow_whatsapp_chat = isset($_POST['allow_whatsapp_chat']);
                $form_ca_allow_phone_call = isset($_POST['allow_phone_call']);
                $form_ca_perm_all = isset($_POST['perm_all']);
                $form_ca_perms = (array)($_POST['perms'] ?? []);

                $perms = $form_ca_perm_all
                    ? 'ALL'
                    : implode(',', array_intersect(array_keys($GLOBALS['ADMIN_PAGES']), $form_ca_perms));

                if (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $form_ca_username)) {
                    $error_message = 'Username must be 3-50 characters (letters, numbers, _ . @ -).';
                } elseif (strlen($form_ca_password) < 8) {
                    $error_message = 'Password must be at least 8 characters.';
                } elseif ($form_ca_password !== $form_ca_confirm_password) {
                    $error_message = 'Password and Confirm Password do not match.';
                } elseif ($perms === '') {
                    $error_message = 'Grant at least one page, or tick Full access.';
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
                    $stmt->execute([$form_ca_username]);
                    if ($stmt->fetchColumn() > 0) {
                        $error_message = "Username \"{$form_ca_username}\" already exists.";
                    } elseif ($form_ca_email !== '' && !filter_var($form_ca_email, FILTER_VALIDATE_EMAIL)) {
                        $error_message = 'Please enter a valid email address (or leave it blank).';
                    } else {
                        // Validate selected employee if provided
                        $selected_emp = null;
                        if ($form_ca_employee_id > 0) {
                            $stmt_emp_chk = $pdo->prepare("SELECT id, employee_id, full_name, email, mobile_number, status, admin_id FROM employees WHERE id = ?");
                            $stmt_emp_chk->execute([$form_ca_employee_id]);
                            $selected_emp = $stmt_emp_chk->fetch(PDO::FETCH_ASSOC);

                            if (!$selected_emp) {
                                $error_message = 'Selected employee record not found.';
                            } elseif (!empty($selected_emp['admin_id'])) {
                                $error_message = 'The selected employee is already linked to another admin account. Please choose a different employee or manual entry.';
                            } else {
                                $ineligible_statuses = ['inactive', 'suspended', 'resigned', 'contract_ended', 'terminated'];
                                $emp_status_clean = strtolower(trim((string)($selected_emp['status'] ?? 'active')));
                                if ($emp_status_clean !== '' && in_array($emp_status_clean, $ineligible_statuses, true)) {
                                    $error_message = "The selected employee has status '{$selected_emp['status']}' and is not an active/eligible registered employee.";
                                }
                            }
                        }

                        if ($error_message === '') {
                            $pdo->beginTransaction();
                            try {
                                if ($form_ca_employee_id > 0) {
                                    // Concurrency check inside transaction
                                    $stmt_lock = $pdo->prepare("SELECT id, employee_id, full_name, admin_id FROM employees WHERE id = ?");
                                    $stmt_lock->execute([$form_ca_employee_id]);
                                    $lock_emp = $stmt_lock->fetch(PDO::FETCH_ASSOC);
                                    if (!$lock_emp || !empty($lock_emp['admin_id'])) {
                                        throw new RuntimeException("Selected employee is already linked to another admin account. Concurrency conflict.");
                                    }
                                }

                                $scopes = implode(',', $form_ca_cred_scopes);
                                $stmt_ins = $pdo->prepare("
                                    INSERT INTO admins (username, password_hash, full_name, email, google_email, phone, role, admin_type, permissions, status, credential_visibility, credential_visibility_scopes, can_edit, can_delete, can_export, allow_copy_email, allow_whatsapp_chat, allow_phone_call, created_by, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, 'admin', ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                ");
                                $stmt_ins->execute([
                                    $form_ca_username,
                                    password_hash($form_ca_password, PASSWORD_DEFAULT),
                                    $form_ca_full_name ?: null,
                                    $form_ca_email ?: null,
                                    ($form_ca_google_email ?: $form_ca_email) ?: null,
                                    $form_ca_phone ?: null,
                                    $form_ca_admin_type,
                                    $perms,
                                    $form_ca_cred_vis,
                                    $scopes,
                                    $form_ca_can_edit ? 1 : 0,
                                    $form_ca_can_delete ? 1 : 0,
                                    $form_ca_can_export ? 1 : 0,
                                    $form_ca_allow_copy_email ? 1 : 0,
                                    $form_ca_allow_whatsapp_chat ? 1 : 0,
                                    $form_ca_allow_phone_call ? 1 : 0,
                                    $admin_username
                                ]);
                                $new_admin_id = (int)$pdo->lastInsertId();

                                if ($form_ca_employee_id > 0) {
                                    // Check if linked_at and linked_by columns exist in employees table
                                    $has_linked_cols = false;
                                    try {
                                        $chk_col = $pdo->query("SELECT linked_at, linked_by FROM employees LIMIT 0");
                                        $has_linked_cols = ($chk_col !== false);
                                    } catch (Exception $e) {
                                        $has_linked_cols = false;
                                    }

                                    if ($has_linked_cols) {
                                        $stmt_link = $pdo->prepare("UPDATE employees SET admin_id = ?, linked_at = NOW(), linked_by = ? WHERE id = ? AND admin_id IS NULL");
                                        $stmt_link->execute([$new_admin_id, $admin_username, $form_ca_employee_id]);
                                    } else {
                                        $stmt_link = $pdo->prepare("UPDATE employees SET admin_id = ? WHERE id = ? AND admin_id IS NULL");
                                        $stmt_link->execute([$new_admin_id, $form_ca_employee_id]);
                                    }

                                    if ($stmt_link->rowCount() !== 1) {
                                        throw new RuntimeException("Failed to link employee to admin account. The employee may have already been linked.");
                                    }

                                    log_admin_activity($pdo, $admin_username, 'staff_admin_link', "Linked new admin \"{$form_ca_username}\" (ID: {$new_admin_id}) to staff \"{$selected_emp['full_name']}\" ({$selected_emp['employee_id']})");
                                }

                                log_admin_activity($pdo, $admin_username, 'admin_created', "Created admin \"{$form_ca_username}\" ({$form_ca_admin_type})" . ($selected_emp ? " linked to {$selected_emp['employee_id']}" : "") . " with access: {$perms}");

                                $pdo->commit();
                                $success_message = "Admin \"{$form_ca_username}\" created." . ($selected_emp ? " Linked to staff {$selected_emp['full_name']} ({$selected_emp['employee_id']})." : "");

                                // Reset Create Admin form state on success
                                $form_ca_employee_id = 0;
                                $form_ca_username = '';
                                $form_ca_full_name = '';
                                $form_ca_email = '';
                                $form_ca_google_email = '';
                                $form_ca_phone = '';
                                $form_ca_password = '';
                                $form_ca_confirm_password = '';
                                $form_ca_admin_type = 'erp_admin';
                                $form_ca_cred_vis = 'visible';
                                $form_ca_cred_scopes = ['students'];
                                $form_ca_can_edit = true;
                                $form_ca_can_delete = true;
                                $form_ca_can_export = true;
                                $form_ca_allow_copy_email = true;
                                $form_ca_allow_whatsapp_chat = true;
                                $form_ca_allow_phone_call = true;
                                $form_ca_perm_all = false;
                                $form_ca_perms = [];
                            } catch (Exception $ex) {
                                if ($pdo->inTransaction()) {
                                    $pdo->rollBack();
                                }
                                $error_message = $ex->getMessage();
                            }
                        }
                    }
                }

                if ($error_message !== '') {
                    $create_admin_error = $error_message;
                    $reopen_create_admin_modal = true;
                }
            } elseif ($action === 'update_perms') {
                $id = (int)($_POST['admin_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    $error_message = 'Admin not found.';
                } elseif ($target['role'] === 'super_admin') {
                    $error_message = 'The Super Admin always has full access.';
                } else {
                    $perms = isset($_POST['perm_all'])
                        ? 'ALL'
                        : implode(',', array_intersect(array_keys($GLOBALS['ADMIN_PAGES']), (array)($_POST['perms'] ?? [])));
                    $name  = trim($_POST['full_name'] ?? $target['full_name']);
                    $email = trim($_POST['email'] ?? '');
                    $phone = trim($_POST['phone'] ?? '');
                    $gemail = trim($_POST['google_email'] ?? '');
                    $scopes = implode(',', (array)($_POST['credential_visibility_scopes'] ?? []));
                    $cred_vis = in_array($_POST['credential_visibility'] ?? 'visible', ['visible', 'hide', 'mask'], true) ? $_POST['credential_visibility'] : 'visible';
                    $admin_type_upd = in_array($_POST['admin_type'] ?? 'erp_admin', ['superadmin','erp_admin','employee','intern','faculty'], true) ? $_POST['admin_type'] : 'erp_admin';
                    $can_edit = isset($_POST['can_edit']) ? 1 : 0;
                    $can_delete = isset($_POST['can_delete']) ? 1 : 0;
                    $can_export = isset($_POST['can_export']) ? 1 : 0;
                    $allow_copy_email = isset($_POST['allow_copy_email']) ? 1 : 0;
                    $allow_whatsapp_chat = isset($_POST['allow_whatsapp_chat']) ? 1 : 0;
                    $allow_phone_call = isset($_POST['allow_phone_call']) ? 1 : 0;

                    if ($perms === '') {
                        $error_message = 'Grant at least one page, or tick Full access.';
                        $edit_admin_error = $error_message;
                        $reopen_edit_admin_modal = true;
                        $form_ea_admin_id = $id;
                        $form_ea_username = $target['username'];
                        $form_ea_full_name = $name;
                        $form_ea_email = $email;
                        $form_ea_google_email = $gemail;
                        $form_ea_phone = $phone;
                        $form_ea_admin_type = $admin_type_upd;
                        $form_ea_cred_vis = $cred_vis;
                        $form_ea_cred_scopes = (array)($_POST['credential_visibility_scopes'] ?? []);
                        $form_ea_can_edit = (bool)$can_edit;
                        $form_ea_can_delete = (bool)$can_delete;
                        $form_ea_can_export = (bool)$can_export;
                        $form_ea_allow_copy_email = (bool)$allow_copy_email;
                        $form_ea_allow_whatsapp_chat = (bool)$allow_whatsapp_chat;
                        $form_ea_allow_phone_call = (bool)$allow_phone_call;
                        $form_ea_perm_all = isset($_POST['perm_all']);
                        $form_ea_perms = (array)($_POST['perms'] ?? []);
                    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error_message = 'Please enter a valid email address (or leave it blank).';
                        $edit_admin_error = $error_message;
                        $reopen_edit_admin_modal = true;
                        $form_ea_admin_id = $id;
                        $form_ea_username = $target['username'];
                        $form_ea_full_name = $name;
                        $form_ea_email = $email;
                        $form_ea_google_email = $gemail;
                        $form_ea_phone = $phone;
                        $form_ea_admin_type = $admin_type_upd;
                        $form_ea_cred_vis = $cred_vis;
                        $form_ea_cred_scopes = (array)($_POST['credential_visibility_scopes'] ?? []);
                        $form_ea_can_edit = (bool)$can_edit;
                        $form_ea_can_delete = (bool)$can_delete;
                        $form_ea_can_export = (bool)$can_export;
                        $form_ea_allow_copy_email = (bool)$allow_copy_email;
                        $form_ea_allow_whatsapp_chat = (bool)$allow_whatsapp_chat;
                        $form_ea_allow_phone_call = (bool)$allow_phone_call;
                        $form_ea_perm_all = isset($_POST['perm_all']);
                        $form_ea_perms = (array)($_POST['perms'] ?? []);
                    } else {
                        $pdo->prepare("UPDATE admins SET permissions = ?, full_name = ?, email = ?, google_email = ?, phone = ?, admin_type = ?, credential_visibility = ?, credential_visibility_scopes = ?, can_edit = ?, can_delete = ?, can_export = ?, allow_copy_email = ?, allow_whatsapp_chat = ?, allow_phone_call = ? WHERE id = ?")
                            ->execute([$perms, $name, $email ?: null, ($gemail ?: $email) ?: null, $phone ?: null, $admin_type_upd, $cred_vis, $scopes, $can_edit, $can_delete, $can_export, $allow_copy_email, $allow_whatsapp_chat, $allow_phone_call, $id]);
                        log_admin_activity($pdo, $admin_username, 'permissions_changed', "Access and visibility for \"{$target['username']}\" updated.");
                        $success_message = "Access and visibility updated for {$target['username']}.";
                    }
                }
            } elseif ($action === 'toggle_status') {
                $id = (int)($_POST['admin_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    $error_message = 'Admin not found.';
                } elseif ($target['role'] === 'super_admin') {
                    $error_message = 'The Super Admin account cannot be deactivated.';
                } else {
                    $pdo->prepare("UPDATE admins SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")->execute([$id]);
                    $new = $target['status'] === 'active' ? 'deactivated' : 'activated';
                    log_admin_activity($pdo, $admin_username, 'admin_status_changed', "Admin \"{$target['username']}\" {$new}");
                    $success_message = "Admin \"{$target['username']}\" {$new}." . ($new === 'deactivated' ? ' Their active session ends on their next click.' : '');
                }
            } elseif ($action === 'reset_password') {
                $id = (int)($_POST['admin_id'] ?? 0);
                $new_pass = $_POST['new_password'] ?? '';
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    $error_message = 'Admin not found.';
                } elseif (strlen($new_pass) < 8) {
                    $error_message = 'New password must be at least 8 characters.';
                } else {
                    $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?")
                        ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $id]);
                    log_admin_activity($pdo, $admin_username, 'password_reset', "Password reset for \"{$target['username']}\"");
                    $success_message = "Password reset for {$target['username']}.";
                }
            } elseif ($action === 'link_staff_admin') {
                $admin_id = (int)($_POST['admin_id'] ?? 0);
                $emp_id = (int)($_POST['employee_id'] ?? 0);

                $stmt_a = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt_a->execute([$admin_id]);
                $adm = $stmt_a->fetch();

                $stmt_e = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                $stmt_e->execute([$emp_id]);
                $emp = $stmt_e->fetch();

                if (!$adm) {
                    $error_message = 'Admin account not found.';
                } elseif (!$emp) {
                    $error_message = 'Staff profile not found.';
                } else {
                    // Strict Server-Side Match Validation
                    $admin_email_norm = strtolower(trim($adm['email'] ?? ''));
                    $staff_email_norm = strtolower(trim($emp['email'] ?? ''));
                    $admin_phone_norm = normalize_phone_number($adm['phone'] ?? '');
                    $staff_phone_norm = normalize_phone_number($emp['mobile_number'] ?? '');

                    if (!$admin_email_norm || !$staff_email_norm || $admin_email_norm !== $staff_email_norm) {
                        $error_message = "Cannot link accounts: Canonical email mismatch between Admin and Staff.";
                    } elseif (!$admin_phone_norm || !$staff_phone_norm || $admin_phone_norm !== $staff_phone_norm) {
                        $error_message = "Cannot link accounts: Canonical mobile number mismatch between Admin and Staff.";
                    } else {
                        // Unlink any other employee currently linked to this admin
                        $pdo->prepare("UPDATE employees SET admin_id = NULL, linked_at = NULL, linked_by = NULL WHERE admin_id = ?")->execute([$admin_id]);
                        // Link target employee to admin
                        $pdo->prepare("UPDATE employees SET admin_id = ?, linked_at = NOW(), linked_by = ? WHERE id = ?")->execute([$admin_id, $admin_username, $emp_id]);

                        log_admin_activity($pdo, $admin_username, 'staff_admin_link', "Linked admin \"{$adm['username']}\" (ID: {$admin_id}) to staff \"{$emp['full_name']}\" ({$emp['employee_id']})");
                        $success_message = "Staff profile \"{$emp['full_name']}\" ({$emp['employee_id']}) successfully linked to Admin \"{$adm['username']}\".";
                    }
                }
            } elseif ($action === 'unlink_staff_admin') {
                $admin_id = (int)($_POST['admin_id'] ?? 0);
                $stmt_a = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt_a->execute([$admin_id]);
                $adm = $stmt_a->fetch();

                if (!$adm) {
                    $error_message = 'Admin account not found.';
                } else {
                    $stmt_e = $pdo->prepare("SELECT * FROM employees WHERE admin_id = ? LIMIT 1");
                    $stmt_e->execute([$admin_id]);
                    $emp = $stmt_e->fetch();

                    if ($emp) {
                        $pdo->prepare("UPDATE employees SET admin_id = NULL, linked_at = NULL, linked_by = NULL WHERE admin_id = ?")->execute([$admin_id]);
                        log_admin_activity($pdo, $admin_username, 'staff_admin_unlink', "Unlinked admin \"{$adm['username']}\" (ID: {$admin_id}) from staff \"{$emp['full_name']}\" ({$emp['employee_id']})");
                        $success_message = "Staff profile unlinked from Admin \"{$adm['username']}\".";
                    } else {
                        $error_message = 'Admin account is not linked to any staff profile.';
                    }
                }
            } elseif ($action === 'delete_admin') {
                $id = (int)($_POST['admin_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    $error_message = 'Admin not found.';
                } elseif ($target['role'] === 'super_admin') {
                    $error_message = 'The Super Admin account cannot be deleted.';
                } elseif ($target['username'] === $admin_username) {
                    $error_message = 'You cannot delete your own account.';
                } else {
                    // Also unlink staff if deleting admin
                    $pdo->prepare("UPDATE employees SET admin_id = NULL, linked_at = NULL, linked_by = NULL WHERE admin_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
                    log_admin_activity($pdo, $admin_username, 'admin_deleted', "Deleted admin account \"{$target['username']}\" (activity history kept)");
                    $success_message = "Admin \"{$target['username']}\" deleted. Their activity history is preserved in the log.";
                }
            }
        } catch (Exception $e) {
            error_log('Admin mgmt: ' . $e->getMessage());
            $error_message = 'Database error while saving changes.';
        }
    }
}

// ── Data ────────────────────────────────────────────────────────
$admins = [];
try {
    // Ensure self-healing columns exist in employees
    if (!defined('PEPP_DB_SCHEMA_VERSION')) {
        try {
            $pdo->exec("ALTER TABLE employees ADD COLUMN linked_at DATETIME DEFAULT NULL, ADD COLUMN linked_by VARCHAR(100) DEFAULT NULL");
        } catch (Exception $e) {}
    }

    $admins = $pdo->query("
        SELECT a.*,
               (SELECT MAX(created_at) FROM admin_activity_log l WHERE l.admin_username = a.username AND l.action_type = 'login') AS last_login_log,
               (SELECT COUNT(*) FROM admin_activity_log l WHERE l.admin_username = a.username) AS activity_count,
               e.id AS linked_staff_id,
               e.employee_id AS linked_staff_code,
               e.full_name AS linked_staff_name,
               e.email AS linked_staff_email,
               e.mobile_number AS linked_staff_mobile,
               e.photo AS linked_staff_photo,
               e.designation AS linked_staff_designation,
               e.department AS linked_staff_department,
               e.status AS linked_staff_status,
               e.linked_at,
               e.linked_by
        FROM admins a
        LEFT JOIN employees e ON a.id = e.admin_id
        ORDER BY a.role = 'super_admin' DESC, a.created_at ASC
    ")->fetchAll();
} catch (Exception $e) {
    error_log('Admin list: ' . $e->getMessage());
    $error_message = $error_message ?: 'Could not load admins.';
}

// ── Unlinked Registered Employees for Create Admin Dropdown ───────────
$unlinked_employees = [];
try {
    $stmt_ue = $pdo->query("
        SELECT id, employee_id, full_name, email, mobile_number, designation, department, application_for, status, admin_id
        FROM employees
        WHERE admin_id IS NULL
          AND (status IS NULL OR status = '' OR LOWER(status) NOT IN ('inactive', 'suspended', 'resigned', 'contract_ended', 'terminated'))
        ORDER BY full_name ASC
    ");
    if ($stmt_ue) {
        $unlinked_employees = $stmt_ue->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $unlinked_employees = [];
}

$active_page = 'admin-management';
$page_title  = 'Admin Management';
$page_sub    = 'Accounts, roles & page access - Super Admin only';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message && !$reopen_create_admin_modal && !$reopen_edit_admin_modal): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="alert alert-info">
    <i class="fas fa-shield-halved"></i>
    <span><strong>Role rules:</strong> Admins see only the pages you grant below and are logged out automatically after <strong>20 minutes</strong> of inactivity. Deleting data (students, courses, registrations) is reserved for the Super Admin everywhere in the system. Pages added to the system in the future appear here automatically for granting.</span>
</div>

<!-- ── ADMIN LIST ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--red-soft);color:var(--red-ink);"><i class="fas fa-user-shield"></i></span>
        <h2>Admin Accounts (<?php echo count($admins); ?>)</h2>
        <div class="head-right" style="display:flex; gap:8px;">
            <a class="btn btn-sm btn-outline" href="admin-activity.php"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>
            <button type="button" class="btn btn-sm btn-primary" onclick="openModal('create-admin-modal')"><i class="fas fa-user-plus"></i> Create New Admin</button>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <table class="data-table">
            <thead><tr><th>Admin</th><th>Role</th><th>Page Access</th><th>Last Login</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($admins as $a):
                $isSuper = $a['role'] === 'super_admin';
                $isSelf  = $a['username'] === $admin_username;
                $permList = $isSuper || trim((string)$a['permissions']) === 'ALL'
                    ? null
                    : array_intersect(array_map('trim', explode(',', (string)$a['permissions'])), array_keys($GLOBALS['ADMIN_PAGES']));
            ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($a['username']); ?><?php echo $isSelf ? ' <span class="badge violet">you</span>' : ''; ?></div>
                        <div class="cell-sub"><?php echo e($a['full_name'] ?: '-'); ?> · created by <?php echo e($a['created_by'] ?: '-'); ?></div>
                        <?php if (!empty($a['email']) || !empty($a['phone'])): ?>
                        <div class="cell-sub"><?php echo $a['email'] ? '<i class="fas fa-envelope"></i> ' . e($a['email']) : ''; ?><?php echo (!empty($a['email']) && !empty($a['phone'])) ? ' · ' : ''; ?><?php echo $a['phone'] ? '<i class="fas fa-phone"></i> ' . e($a['phone']) : ''; ?></div>
                        <?php endif; ?>
                        <?php if (!empty($a['linked_staff_id'])): ?>
                        <div style="margin-top:4px;">
                            <span class="badge blue" style="font-size:0.65rem;" title="Linked Approved Staff: <?php echo e($a['linked_staff_name']); ?> (<?php echo e($a['linked_staff_code']); ?>)">
                                <i class="fas fa-id-badge"></i> <?php echo e($a['linked_staff_name']); ?> (<?php echo e($a['linked_staff_code']); ?>)
                            </span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $isSuper ? 'red' : 'blue'; ?>"><?php echo $isSuper ? 'Super Admin' : 'Admin'; ?></span>
                        <?php $at = $a['admin_type'] ?? 'erp_admin'; $at_labels = ['superadmin'=>'Superadmin','erp_admin'=>'ERP Admin','employee'=>'Employee','intern'=>'Intern','faculty'=>'Faculty']; ?>
                        <div style="margin-top:3px;"><span class="badge gray" style="font-size:0.62rem;"><?php echo $at_labels[$at] ?? ucfirst($at); ?></span></div>
                        <?php if (!$isSuper): ?>
                            <div style="margin-top:4px;">
                                <span class="badge <?php echo ($a['credential_visibility'] ?? 'visible') === 'visible' ? 'green' : (($a['credential_visibility'] ?? 'visible') === 'hide' ? 'red' : 'amber'); ?>" style="font-size:0.65rem;">
                                    <?php echo ucfirst($a['credential_visibility'] ?? 'visible'); ?>
                                </span>
                            </div>
                            <?php if (($a['credential_visibility'] ?? 'visible') !== 'visible'): ?>
                            <div style="margin-top:2px; font-size:0.65rem; color:var(--text-muted);">
                                Scopes: <?php echo !empty($a['credential_visibility_scopes']) ? htmlspecialchars(str_replace(',', ', ', $a['credential_visibility_scopes'])) : 'None'; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:280px;">
                        <?php if ($permList === null): ?>
                            <span class="badge green">Full access</span>
                        <?php elseif (empty($permList)): ?>
                            <span class="badge gray">No pages</span>
                        <?php else: ?>
                            <?php foreach ($permList as $p): ?>
                                <span class="badge gray" style="margin:1px 2px;"><?php echo e($GLOBALS['ADMIN_PAGES'][$p][0]); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub">
                        <?php $ll = $a['last_login_log'] ?: $a['last_login_at'];
                        echo $ll ? date('d M Y, h:i A', strtotime($ll)) : 'Never'; ?>
                        <div><a href="admin-activity.php?admin=<?php echo urlencode($a['username']); ?>" style="font-size:.72rem;"><?php echo (int)$a['activity_count']; ?> activity records</a></div>
                    </td>
                    <td><span class="badge <?php echo $a['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if (!$isSuper): ?>
                            <button class="btn btn-sm btn-outline" title="Edit admin access & details" onclick="openPerms(<?php echo (int)$a['id']; ?>)"><i class="fas fa-pen-to-square"></i></button>
                            <?php if (empty($a['linked_staff_id'])): ?>
                                <button class="btn btn-sm btn-soft-blue" title="Link Staff Profile" onclick="openLinkStaffModal(<?php echo (int)$a['id']; ?>, '<?php echo e(addslashes($a['username'])); ?>', '<?php echo e(addslashes($a['full_name'] ?? '')); ?>', '<?php echo e(addslashes($a['email'] ?? '')); ?>', '<?php echo e(addslashes($a['phone'] ?? '')); ?>', '')"><i class="fas fa-link"></i></button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-soft-amber" title="Unlink Staff (<?php echo e(addslashes($a['linked_staff_name'])); ?>)" onclick="unlinkStaff(<?php echo (int)$a['id']; ?>, '<?php echo e(addslashes($a['username'])); ?>', '<?php echo e(addslashes($a['linked_staff_name'])); ?>')"><i class="fas fa-link-slash"></i></button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-soft-blue" title="Reset password" onclick="resetPassword(<?php echo (int)$a['id']; ?>, '<?php echo e(addslashes($a['username'])); ?>')"><i class="fas fa-lock-open"></i></button>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="admin_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $a['status'] === 'active' ? 'btn-soft-amber' : 'btn-soft-green'; ?>" title="<?php echo $a['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>"><i class="fas fa-power-off"></i></button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete admin <?php echo e(addslashes($a['username'])); ?>? Their activity history will be kept.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_admin">
                                <input type="hidden" name="admin_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-red" title="Delete admin"><i class="fas fa-trash"></i></button>
                            </form>
                        <?php else: ?>
                            <span class="cell-sub">Manage in <a href="settings.php">Settings</a></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── CREATE ADMIN MODAL ── -->
<div class="modal-backdrop<?php echo $reopen_create_admin_modal ? ' open' : ''; ?>" id="create-admin-modal"<?php echo $reopen_create_admin_modal ? ' style="display:flex;"' : ''; ?>>
    <div class="modal" style="max-width:640px;">
        <div class="modal-head">
            <h3><i class="fas fa-user-plus" style="color:var(--accent);"></i> Create Admin Account</h3>
            <button type="button" class="modal-close" onclick="closeModal('create-admin-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" id="create-admin-form" onsubmit="return validateCreateAdminForm(event)">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_admin">
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <?php if ($create_admin_error): ?>
                    <div class="alert alert-error" id="ca-modal-error" style="margin-bottom:14px; padding:10px 14px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-circle-exclamation" style="font-size:1rem;"></i>
                        <span><?php echo e($create_admin_error); ?></span>
                    </div>
                <?php endif; ?>

                <div class="form-grid">
                    <!-- 1. Link Registered Employee (Optional) -->
                    <div class="field full" style="margin-bottom:4px;">
                        <label for="ca-employee-id">Link Registered Employee (Optional)</label>
                        <select name="employee_id" id="ca-employee-id" onchange="createAdminWorkflow.onEmployeeSelect(this.value)">
                            <option value="">— Manual Entry (Non-Employee Admin) —</option>
                            <?php if (empty($unlinked_employees)): ?>
                                <option value="" disabled>No unlinked registered employees available</option>
                            <?php else: ?>
                                <?php foreach ($unlinked_employees as $ue): ?>
                                    <option value="<?php echo (int)$ue['id']; ?>" <?php echo ((int)$ue['id'] === (int)$form_ca_employee_id) ? 'selected' : ''; ?>>
                                        <?php echo e($ue['employee_id']); ?> — <?php echo e($ue['full_name']); ?> — <?php echo e($ue['designation'] ?: ($ue['department'] ?: 'Staff')); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <div id="ca-employee-indicator" style="display:<?php echo ($form_ca_employee_id > 0) ? 'block' : 'none'; ?>; font-size:0.75rem; color:#166534; margin-top:5px; font-weight:600;">
                            <i class="fas fa-circle-check"></i> Employee details loaded
                            <a href="javascript:void(0)" onclick="createAdminWorkflow.clearEmployeeSelection()" style="color:var(--accent); margin-left:8px; text-decoration:underline;">Clear employee selection</a>
                        </div>
                    </div>

                    <!-- 2. Username * -->
                    <div class="field">
                        <label for="ca-username">Username <span class="req">*</span></label>
                        <input type="text" name="username" id="ca-username" required pattern="[A-Za-z0-9_.@-]{3,50}" placeholder="e.g. office.staff" value="<?php echo e($form_ca_username); ?>">
                    </div>

                    <!-- 3. Full Name -->
                    <div class="field">
                        <label for="ca-full-name">Full Name</label>
                        <input type="text" name="full_name" id="ca-full-name" placeholder="Display name" value="<?php echo e($form_ca_full_name); ?>">
                    </div>

                    <!-- 4. Email -->
                    <div class="field">
                        <label for="ca-email">Email</label>
                        <input type="email" name="email" id="ca-email" placeholder="admin@example.com" value="<?php echo e($form_ca_email); ?>">
                        <div class="help">Used for reminders &amp; Google sign-in</div>
                    </div>

                    <!-- 5. Google Sign-in Email -->
                    <div class="field">
                        <label for="ca-gemail">Google Sign-in Email</label>
                        <input type="email" name="google_email" id="ca-gemail" placeholder="(defaults to email above)" value="<?php echo e($form_ca_google_email); ?>">
                        <div class="help">The Google account allowed to sign in as this admin</div>
                    </div>

                    <!-- 6. Phone -->
                    <div class="field">
                        <label for="ca-phone">Phone</label>
                        <input type="text" name="phone" id="ca-phone" placeholder="Mobile number" value="<?php echo e($form_ca_phone); ?>">
                    </div>

                    <!-- 7. Password * -->
                    <div class="field">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                            <label for="ca-password" style="margin-bottom:0;">Password <span class="req">*</span></label>
                            <button type="button" onclick="createAdminWorkflow.generateSecurePassword()" style="background:none; border:none; color:var(--accent); font-size:0.72rem; font-weight:700; cursor:pointer; padding:0; text-decoration:underline;" title="Generate a cryptographically secure random password">
                                <i class="fas fa-key"></i> Generate
                            </button>
                        </div>
                        <div style="position:relative;">
                            <input type="password" name="password" id="ca-password" required minlength="8" placeholder="Min. 8 characters" autocomplete="new-password" style="padding-right:38px;">
                            <button type="button" class="toggle-pw-btn" onclick="createAdminWorkflow.togglePasswordVisibility('ca-password', this)" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:#64748b; cursor:pointer; padding:4px;" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="help">Minimum 8 characters</div>
                    </div>

                    <!-- 8. Confirm Password * -->
                    <div class="field">
                        <label for="ca-confirm-password">Confirm Password <span class="req">*</span></label>
                        <div style="position:relative;">
                            <input type="password" name="confirm_password" id="ca-confirm-password" required minlength="8" placeholder="Repeat password" autocomplete="new-password" style="padding-right:38px;">
                            <button type="button" class="toggle-pw-btn" onclick="createAdminWorkflow.togglePasswordVisibility('ca-confirm-password', this)" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:#64748b; cursor:pointer; padding:4px;" aria-label="Toggle confirm password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="ca-password-match-error" style="display:none; color:#ef4444; font-size:0.72rem; margin-top:4px; font-weight:600;">
                            <i class="fas fa-circle-xmark"></i> Passwords do not match
                        </div>
                    </div>

                    <!-- 9. Admin Type -->
                    <div class="field">
                        <label for="ca-admin-type">Admin Type</label>
                        <select name="admin_type" id="ca-admin-type" required>
                            <option value="erp_admin" <?php echo ($form_ca_admin_type === 'erp_admin') ? 'selected' : ''; ?>>ERP Admin</option>
                            <option value="employee" <?php echo ($form_ca_admin_type === 'employee') ? 'selected' : ''; ?>>Employee</option>
                            <option value="faculty" <?php echo ($form_ca_admin_type === 'faculty') ? 'selected' : ''; ?>>Faculty</option>
                            <option value="intern" <?php echo ($form_ca_admin_type === 'intern') ? 'selected' : ''; ?>>Intern</option>
                            <option value="superadmin" <?php echo ($form_ca_admin_type === 'superadmin') ? 'selected' : ''; ?>>Superadmin</option>
                        </select>
                        <div class="help">Classification for this admin account</div>
                    </div>

                    <!-- 10. Credential Visibility -->
                    <div class="field">
                        <label for="ca-cred-vis">Credential Visibility</label>
                        <select name="credential_visibility" id="ca-cred-vis" required>
                            <option value="visible" <?php echo ($form_ca_cred_vis === 'visible') ? 'selected' : ''; ?>>Visible</option>
                            <option value="hide" <?php echo ($form_ca_cred_vis === 'hide') ? 'selected' : ''; ?>>Hide</option>
                            <option value="mask" <?php echo ($form_ca_cred_vis === 'mask') ? 'selected' : ''; ?>>Mask</option>
                        </select>
                    </div>

                    <!-- 11. Credential Visibility Scopes -->
                    <div class="field full" style="margin-top:-4px; margin-bottom:8px;">
                        <label style="margin-bottom:6px; display:block;">Credential Visibility Scopes</label>
                        <div style="display:flex; gap:16px; flex-wrap:wrap; background:#fafaf9; border:1px solid #e7e5e4; padding:8px 12px; border-radius:8px;">
                            <?php
                            $ca_scopes = [
                                'students' => 'Students',
                                'alumni' => 'Alumni Data',
                                'faculties' => 'Faculties',
                                'leads' => 'Leads',
                                'campaigns' => 'Custom Forms',
                                'student-study-reports' => 'Student Reports',
                                'financials' => 'Financials'
                            ];
                            foreach ($ca_scopes as $sc_key => $sc_lbl):
                                $checked = in_array($sc_key, $form_ca_cred_scopes, true);
                            ?>
                                <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                    <input type="checkbox" name="credential_visibility_scopes[]" value="<?php echo e($sc_key); ?>" style="width:16px; height:16px; accent-color:var(--accent);" <?php echo $checked ? 'checked' : ''; ?>> <?php echo e($sc_lbl); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 12. Action Permissions (Global) -->
                    <div class="field full" style="margin-top:-4px; margin-bottom:8px;">
                        <label style="margin-bottom:6px; display:block;">Action Permissions (Global)</label>
                        <div style="display:flex; gap:16px; flex-wrap:wrap; background:#fafaf9; border:1px solid #e7e5e4; padding:8px 12px; border-radius:8px;">
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="can_edit" value="1" style="width:16px; height:16px; accent-color:var(--accent);" <?php echo $form_ca_can_edit ? 'checked' : ''; ?>> Allow Edit / Modify
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="can_delete" value="1" style="width:16px; height:16px; accent-color:var(--accent);" <?php echo $form_ca_can_delete ? 'checked' : ''; ?>> Allow Delete
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="can_export" value="1" style="width:16px; height:16px; accent-color:var(--accent);" <?php echo $form_ca_can_export ? 'checked' : ''; ?>> Allow Export
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="allow_copy_email" value="1" style="width:16px; height:16px; accent-color:var(--accent);" <?php echo $form_ca_allow_copy_email ? 'checked' : ''; ?>> Allow Copy Original Email
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="allow_whatsapp_chat" value="1" style="width:16px; height:16px; accent-color:var(--accent);" <?php echo $form_ca_allow_whatsapp_chat ? 'checked' : ''; ?>> Allow WhatsApp Chat
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="allow_phone_call" value="1" style="width:16px; height:16px; accent-color:var(--accent);" <?php echo $form_ca_allow_phone_call ? 'checked' : ''; ?>> Allow Phone Call
                            </label>
                        </div>
                    </div>
                </div>

                <!-- 13. Full Access & Page Permissions -->
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:.84rem;font-weight:700;background:var(--green-soft);color:var(--green-ink);border-radius:50px;padding:7px 16px;cursor:pointer;margin-bottom:10px;">
                    <input type="checkbox" name="perm_all" value="1" id="ca-all" onchange="toggleAll(this, 'ca-perms')" style="width:16px;height:16px;accent-color:var(--green-ink);" <?php echo $form_ca_perm_all ? 'checked' : ''; ?>>
                    Full access (every current &amp; future page)
                </label>
                <div id="ca-perms" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php foreach ($GLOBALS['ADMIN_PAGES'] as $key => [$label, $icon]):
                        $p_checked = in_array($key, $form_ca_perms, true);
                    ?>
                        <label style="display:inline-flex;align-items:center;gap:7px;font-size:.8rem;font-weight:600;background:var(--card);border-radius:50px;padding:7px 14px;cursor:pointer;color:var(--foreground);">
                            <input type="checkbox" name="perms[]" value="<?php echo e($key); ?>" style="width:15px;height:15px;accent-color:var(--accent);" <?php echo $p_checked ? 'checked' : ''; ?> <?php echo $form_ca_perm_all ? 'disabled' : ''; ?>>
                            <i class="fas <?php echo e($icon); ?>" style="color:var(--secondary);font-size:.75rem;"></i> <?php echo e($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('create-admin-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Create Admin</button>
            </div>
        </form>
    </div>
</div>

<!-- ── PERMISSIONS & DETAILS MODAL ── -->
<div class="modal-backdrop<?php echo $reopen_edit_admin_modal ? ' open' : ''; ?>" id="perms-modal"<?php echo $reopen_edit_admin_modal ? ' style="display:flex;"' : ''; ?>>
    <div class="modal" style="max-width:580px;">
        <div class="modal-head">
            <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-user-pen"></i></span>
            <h2>Edit Admin Account: <span id="pm-username"><?php echo e($form_ea_username); ?></span></h2>
            <button type="button" class="close-btn" onclick="closeModal('perms-modal')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_perms">
            <input type="hidden" name="admin_id" id="pm-id" value="<?php echo (int)$form_ea_admin_id; ?>">
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <?php if ($edit_admin_error): ?>
                    <div class="alert alert-error" id="pm-modal-error" style="margin-bottom:14px; padding:10px 14px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-circle-exclamation" style="font-size:1rem;"></i>
                        <span><?php echo e($edit_admin_error); ?></span>
                    </div>
                <?php endif; ?>
                <div class="grid-2">
                    <div class="field">
                        <label>Full Name</label>
                        <input type="text" name="full_name" id="pm-name" placeholder="e.g. John Doe" value="<?php echo e($form_ea_full_name); ?>">
                    </div>
                    <div class="field">
                        <label>Email Address</label>
                        <input type="email" name="email" id="pm-email" placeholder="john@example.com" value="<?php echo e($form_ea_email); ?>">
                    </div>
                    <div class="field">
                        <label>Google OAuth Email</label>
                        <input type="email" name="google_email" id="pm-gemail" placeholder="john.doe@gmail.com" value="<?php echo e($form_ea_google_email); ?>">
                        <small style="color:var(--text-muted);font-size:0.75rem;">Used for Google Login (leave empty to match main email)</small>
                    </div>
                    <div class="field">
                        <label>Phone Number</label>
                        <input type="text" name="phone" id="pm-phone" placeholder="e.g. 9876543210" value="<?php echo e($form_ea_phone); ?>">
                    </div>
                    <div class="field">
                        <label>Admin Type</label>
                        <select name="admin_type" id="pm-admin-type">
                            <option value="erp_admin">ERP Admin</option>
                            <option value="employee">Employee</option>
                            <option value="faculty">Faculty</option>
                            <option value="intern">Intern</option>
                            <option value="superadmin">Superadmin</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Credential Visibility</label>
                        <select name="credential_visibility" id="pm-cred-visibility">
                            <option value="visible">Visible (Full Access)</option>
                            <option value="mask">Mask (Partial Hidden)</option>
                            <option value="hide">Hide (Completely Hidden)</option>
                        </select>
                    </div>
                    <div class="field" style="grid-column: span 2;">
                        <label style="margin-bottom:6px; display:block;">Apply Credential Visibility Scopes</label>
                        <div style="display:flex; gap:16px; flex-wrap:wrap; background:#fafaf9; border:1px solid #e7e5e4; padding:8px 12px; border-radius:8px;">
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="all" class="pm-scope" data-scope="all" style="width:16px; height:16px; accent-color:var(--accent);"> All Scopes
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="students" class="pm-scope" data-scope="students" style="width:16px; height:16px; accent-color:var(--accent);"> Students Directory
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="registrations" class="pm-scope" data-scope="registrations" style="width:16px; height:16px; accent-color:var(--accent);"> Registrations
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="student-mentoring" class="pm-scope" data-scope="student-mentoring" style="width:16px; height:16px; accent-color:var(--accent);"> Mentoring
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="student-study-reports" class="pm-scope" data-scope="student-study-reports" style="width:16px; height:16px; accent-color:var(--accent);"> Student Reports
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="financials" class="pm-scope" data-scope="financials" style="width:16px; height:16px; accent-color:var(--accent);"> Financials
                            </label>
                        </div>
                    </div>
                    <div class="field" style="grid-column: span 2; margin-top:-4px; margin-bottom:12px;">
                        <label style="margin-bottom:6px; display:block;">Action Permissions (Global)</label>
                        <div style="display:flex; gap:16px; flex-wrap:wrap; background:#fafaf9; border:1px solid #e7e5e4; padding:8px 12px; border-radius:8px;">
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="can_edit" value="1" id="pm-can-edit" style="width:16px; height:16px; accent-color:var(--accent);"> Allow Edit / Modify
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="can_delete" value="1" id="pm-can-delete" style="width:16px; height:16px; accent-color:var(--accent);"> Allow Delete
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="can_export" value="1" id="pm-can-export" style="width:16px; height:16px; accent-color:var(--accent);"> Allow Export
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="allow_copy_email" value="1" id="pm-allow-copy-email" style="width:16px; height:16px; accent-color:var(--accent);"> Allow Copy Original Email
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="allow_whatsapp_chat" value="1" id="pm-allow-wa-chat" style="width:16px; height:16px; accent-color:var(--accent);"> Allow WhatsApp Chat
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="allow_phone_call" value="1" id="pm-allow-phone-call" style="width:16px; height:16px; accent-color:var(--accent);"> Allow Phone Call
                            </label>
                        </div>
                    </div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:.84rem;font-weight:700;background:var(--green-soft);color:var(--green-ink);border-radius:50px;padding:7px 16px;cursor:pointer;margin-bottom:10px;">
                    <input type="checkbox" name="perm_all" value="1" id="pm-all" onchange="toggleAll(this, 'pm-perms')" style="width:16px;height:16px;accent-color:var(--green-ink);">
                    Full access (every current &amp; future page)
                </label>
                <div id="pm-perms" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php foreach ($GLOBALS['ADMIN_PAGES'] as $key => [$label, $icon]): ?>
                        <label style="display:inline-flex;align-items:center;gap:7px;font-size:.8rem;font-weight:600;background:var(--card);border-radius:50px;padding:7px 14px;cursor:pointer;color:var(--foreground);">
                            <input type="checkbox" name="perms[]" value="<?php echo e($key); ?>" class="pm-perm" data-key="<?php echo e($key); ?>" style="width:15px;height:15px;accent-color:var(--accent);">
                            <i class="fas <?php echo e($icon); ?>" style="color:var(--secondary);font-size:.75rem;"></i> <?php echo e($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('perms-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Access</button>
            </div>
        </form>
    </div>
</div>

<!-- ── LINK STAFF MODAL ── -->
<div class="modal-backdrop" id="link-staff-modal">
    <div class="modal" style="max-width:580px;">
        <div class="modal-head">
            <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-link"></i></span>
            <h2>Link Staff Profile</h2>
            <button type="button" class="close-btn" onclick="closeModal('link-staff-modal')">&times;</button>
        </div>
        <form method="POST" id="link-staff-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="link_staff_admin">
            <input type="hidden" name="admin_id" id="lsm-admin-id">

            <div class="modal-body">
                <!-- Admin info card -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px; margin-bottom:16px;">
                    <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">Target Admin Account</div>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-weight:700; font-size:0.95rem;" id="lsm-admin-name-user">Admin Name (@username)</div>
                            <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;" id="lsm-admin-contact">Email: ... · Phone: ...</div>
                        </div>
                        <span class="badge blue" id="lsm-admin-role">Admin</span>
                    </div>
                </div>

                <!-- Select Staff -->
                <div class="field" style="margin-bottom:16px;">
                    <label style="font-weight:700; font-size:0.85rem; margin-bottom:6px; display:block;">Select Approved Staff Member *</label>
                    <select name="employee_id" id="lsm-staff-select" required onchange="checkStaffAdminMatch()" style="width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); font-size:0.88rem;">
                        <option value="">— Loading staff directory… —</option>
                    </select>
                </div>

                <!-- Live match verification box -->
                <div id="lsm-verify-box" style="display:none; margin-bottom:16px;">
                    <div style="background:#fff; border:1.5px solid var(--border); border-radius:10px; padding:14px; box-shadow:0 2px 6px rgba(0,0,0,0.03);">
                        <div style="font-weight:700; font-size:0.85rem; margin-bottom:10px; display:flex; align-items:center; justify-content:space-between;">
                            <span><i class="fas fa-shield-halved"></i> Canonical Identity Verification</span>
                            <span id="lsm-status-badge"></span>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:0.82rem; margin-bottom:10px;">
                            <div style="background:#f8fafc; padding:8px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                                <div style="font-weight:700; color:var(--text-muted); font-size:0.72rem;">EMAIL COMPARISON</div>
                                <div style="margin-top:2px;">Staff: <strong id="lsm-staff-email-disp">—</strong></div>
                                <div>Admin: <strong id="lsm-admin-email-disp">—</strong></div>
                                <div id="lsm-email-match-indicator" style="margin-top:4px; font-weight:700;"></div>
                            </div>
                            <div style="background:#f8fafc; padding:8px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                                <div style="font-weight:700; color:var(--text-muted); font-size:0.72rem;">MOBILE COMPARISON</div>
                                <div style="margin-top:2px;">Staff: <strong id="lsm-staff-phone-disp">—</strong></div>
                                <div>Admin: <strong id="lsm-admin-phone-disp">—</strong></div>
                                <div id="lsm-phone-match-indicator" style="margin-top:4px; font-weight:700;"></div>
                            </div>
                        </div>

                        <!-- Conflict Warning / Error display -->
                        <div id="lsm-error-container"></div>
                    </div>
                </div>
            </div>

            <div class="modal-foot" style="display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="btn btn-outline" onclick="closeModal('link-staff-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="lsm-submit-btn" disabled><i class="fas fa-link"></i> Link Staff Profile</button>
            </div>
        </form>
    </div>
</div>

<!-- ── RESET PASSWORD (hidden form) ── -->
<form method="POST" id="reset-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="admin_id" id="rp-id">
    <input type="hidden" name="new_password" id="rp-pass">
</form>

<!-- ── UNLINK STAFF (hidden form) ── -->
<form method="POST" id="unlink-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="unlink_staff_admin">
    <input type="hidden" name="admin_id" id="unl-admin-id">
</form>

<?php
ob_start();
?>
<script>
let allStaffCache = null;
let currentTargetAdmin = null;

function toggleAll(cb, boxId) {
    document.querySelectorAll('#' + boxId + ' input[type=checkbox]').forEach(c => { c.disabled = cb.checked; if (cb.checked) c.checked = false; });
}

// ── CREATE ADMIN WORKFLOW (Namespaced) ───────────────────────────────────
const createAdminWorkflow = {
    unlinkedEmployees: <?php echo json_encode($unlinked_employees, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]'; ?>,
    lastAutofilled: null,

    onEmployeeSelect: function(empId) {
        if (!empId) {
            this.clearEmployeeSelection();
            return;
        }
        const emp = this.unlinkedEmployees.find(e => String(e.id) === String(empId));
        if (!emp) return;

        if (!this.lastAutofilled) {
            this.lastAutofilled = {};
        }

        const usernameEl = document.getElementById('ca-username');
        const nameEl = document.getElementById('ca-full-name');
        const emailEl = document.getElementById('ca-email');
        const gemailEl = document.getElementById('ca-gemail');
        const phoneEl = document.getElementById('ca-phone');
        const adminTypeEl = document.getElementById('ca-admin-type');

        // Suggest username from employee_id (e.g. EMP-101 -> emp-101)
        const suggestedUser = (emp.employee_id || '').toLowerCase().replace(/[^a-z0-9_.@-]/g, '');
        if (usernameEl && (!usernameEl.value || usernameEl.value === this.lastAutofilled.username)) {
            usernameEl.value = suggestedUser;
            this.lastAutofilled.username = suggestedUser;
        }

        if (nameEl && (!nameEl.value || nameEl.value === this.lastAutofilled.name)) {
            nameEl.value = emp.full_name || '';
            this.lastAutofilled.name = emp.full_name || '';
        }

        if (emailEl && (!emailEl.value || emailEl.value === this.lastAutofilled.email)) {
            emailEl.value = emp.email || '';
            this.lastAutofilled.email = emp.email || '';
        }

        if (gemailEl && (!gemailEl.value || gemailEl.value === this.lastAutofilled.gemail)) {
            gemailEl.value = emp.email || '';
            this.lastAutofilled.gemail = emp.email || '';
        }

        if (phoneEl && (!phoneEl.value || phoneEl.value === this.lastAutofilled.phone)) {
            phoneEl.value = emp.mobile_number || '';
            this.lastAutofilled.phone = emp.mobile_number || '';
        }

        if (adminTypeEl && (!adminTypeEl.dataset.manuallyChanged)) {
            const appFor = (emp.application_for || '').toLowerCase();
            if (appFor === 'faculty') adminTypeEl.value = 'faculty';
            else if (appFor === 'intern') adminTypeEl.value = 'intern';
            else if (appFor === 'employee') adminTypeEl.value = 'employee';
        }

        const indicator = document.getElementById('ca-employee-indicator');
        if (indicator) indicator.style.display = 'block';
    },

    clearEmployeeSelection: function() {
        const select = document.getElementById('ca-employee-id');
        if (select) select.value = '';

        if (this.lastAutofilled) {
            const usernameEl = document.getElementById('ca-username');
            const nameEl = document.getElementById('ca-full-name');
            const emailEl = document.getElementById('ca-email');
            const gemailEl = document.getElementById('ca-gemail');
            const phoneEl = document.getElementById('ca-phone');

            if (usernameEl && usernameEl.value === this.lastAutofilled.username) usernameEl.value = '';
            if (nameEl && nameEl.value === this.lastAutofilled.name) nameEl.value = '';
            if (emailEl && emailEl.value === this.lastAutofilled.email) emailEl.value = '';
            if (gemailEl && gemailEl.value === this.lastAutofilled.gemail) gemailEl.value = '';
            if (phoneEl && phoneEl.value === this.lastAutofilled.phone) phoneEl.value = '';

            this.lastAutofilled = null;
        }

        const indicator = document.getElementById('ca-employee-indicator');
        if (indicator) indicator.style.display = 'none';
    },

    generateSecurePassword: function() {
        const charset = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
        const arr = new Uint8Array(16);
        window.crypto.getRandomValues(arr);
        let pwd = '';
        for (let i = 0; i < arr.length; i++) {
            pwd += charset[arr[i] % charset.length];
        }

        const p1 = document.getElementById('ca-password');
        const p2 = document.getElementById('ca-confirm-password');
        if (p1 && p2) {
            p1.value = pwd;
            p2.value = pwd;
            p1.type = 'text';
            p2.type = 'text';

            const icons = document.querySelectorAll('#create-admin-modal .toggle-pw-btn i');
            icons.forEach(ic => { ic.className = 'fas fa-eye-slash'; });

            const matchErr = document.getElementById('ca-password-match-error');
            if (matchErr) matchErr.style.display = 'none';
        }
    },

    togglePasswordVisibility: function(fieldId, btn) {
        const input = document.getElementById(fieldId);
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            if (icon) icon.className = 'fas fa-eye';
        }
    }
};

function validateCreateAdminForm(e) {
    const p1 = document.getElementById('ca-password');
    const p2 = document.getElementById('ca-confirm-password');
    const matchErr = document.getElementById('ca-password-match-error');

    if (p1 && p2) {
        if (p1.value.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters.');
            p1.focus();
            return false;
        }
        if (p1.value !== p2.value) {
            e.preventDefault();
            if (matchErr) matchErr.style.display = 'block';
            p2.focus();
            return false;
        }
    }
    return true;
}

document.getElementById('ca-confirm-password')?.addEventListener('input', function() {
    const p1 = document.getElementById('ca-password')?.value;
    const matchErr = document.getElementById('ca-password-match-error');
    if (matchErr) {
        if (p1 && this.value && p1 !== this.value) {
            matchErr.style.display = 'block';
        } else {
            matchErr.style.display = 'none';
        }
    }
});

document.getElementById('ca-admin-type')?.addEventListener('change', function() {
    this.dataset.manuallyChanged = 'true';
});

function openCreateAdminModal() {
    openModal('create-admin-modal');
}

// ── EDIT ADMIN WORKFLOW (Protected / Legacy) ─────────────────────────────
function openPerms(adminIdOrObj) {
    if (typeof adminIdOrObj === 'number' || (typeof adminIdOrObj === 'string' && !isNaN(adminIdOrObj))) {
        fetch('admin-management.php?action=get_admin_details&id=' + encodeURIComponent(adminIdOrObj))
            .then(r => r.json())
            .then(d => {
                if (!d.success || !d.admin) {
                    alert(d.error || 'Failed to load admin details.');
                    return;
                }
                populateAndOpenPermsModal(d.admin);
            })
            .catch(err => {
                console.error(err);
                alert('Network error loading admin details.');
            });
    } else if (typeof adminIdOrObj === 'object' && adminIdOrObj !== null) {
        populateAndOpenPermsModal(adminIdOrObj);
    }
}

function populateAndOpenPermsModal(a) {
    document.getElementById('pm-id').value = a.id;
    document.getElementById('pm-name').value = a.full_name || a.name || '';
    document.getElementById('pm-email').value = a.email || '';
    document.getElementById('pm-phone').value = a.phone || '';
    document.getElementById('pm-gemail').value = a.google_email || a.gemail || '';
    document.getElementById('pm-cred-visibility').value = a.credential_visibility || 'visible';

    const scopes = a.credential_visibility_scopes ? a.credential_visibility_scopes.split(',').map(s => s.trim()) : [];
    document.querySelectorAll('.pm-scope').forEach(c => {
        c.checked = scopes.includes(c.dataset.scope);
    });

    if (document.getElementById('pm-can-edit')) document.getElementById('pm-can-edit').checked = (parseInt(a.can_edit ?? 1) === 1);
    if (document.getElementById('pm-can-delete')) document.getElementById('pm-can-delete').checked = (parseInt(a.can_delete ?? 1) === 1);
    if (document.getElementById('pm-can-export')) document.getElementById('pm-can-export').checked = (parseInt(a.can_export ?? 1) === 1);
    if (document.getElementById('pm-allow-copy-email')) document.getElementById('pm-allow-copy-email').checked = (parseInt(a.allow_copy_email ?? 1) === 1);
    if (document.getElementById('pm-allow-wa-chat')) document.getElementById('pm-allow-wa-chat').checked = (parseInt(a.allow_whatsapp_chat ?? 1) === 1);
    if (document.getElementById('pm-allow-phone-call')) document.getElementById('pm-allow-phone-call').checked = (parseInt(a.allow_phone_call ?? 1) === 1);

    document.getElementById('pm-admin-type').value = a.admin_type || 'erp_admin';
    document.getElementById('pm-username').textContent = a.username;

    const permsStr = (a.permissions !== undefined) ? (a.permissions || '') : (a.perms || '');
    const isAll = (permsStr === 'ALL');
    const pmAll = document.getElementById('pm-all');
    if (pmAll) pmAll.checked = isAll;

    const granted = isAll ? [] : permsStr.split(',').map(s => s.trim());
    document.querySelectorAll('.pm-perm').forEach(c => {
        c.checked = granted.includes(c.dataset.key);
        c.disabled = isAll;
    });

    openModal('perms-modal');
}

function resetPassword(id, username) {
    const p = prompt('New password for ' + username + ' (min 8 characters):');
    if (p === null) return;
    if (p.length < 8) { alert('Password must be at least 8 characters.'); return; }
    document.getElementById('rp-id').value = id;
    document.getElementById('rp-pass').value = p;
    document.getElementById('reset-form').submit();
}

function loadStaffList() {
    if (allStaffCache !== null) return Promise.resolve(allStaffCache);
    return fetch('admin-management.php?action=get_staff_list_for_linking')
        .then(r => r.json())
        .then(d => {
            if (d.success && d.staff) {
                allStaffCache = d.staff;
                return allStaffCache;
            }
            return [];
        });
}

function openLinkStaffModal(adminId, username, fullName, email, phone, currentStaffId) {
    currentTargetAdmin = {
        id: adminId,
        username: username,
        full_name: fullName,
        email: email,
        phone: phone,
        currentStaffId: currentStaffId
    };

    document.getElementById('lsm-admin-id').value = adminId;
    document.getElementById('lsm-admin-name-user').textContent = (fullName || username) + ' (@' + username + ')';
    document.getElementById('lsm-admin-contact').textContent = 'Canonical Email: ' + (email || 'None') + ' · Phone: ' + (phone || 'None');
    document.getElementById('lsm-verify-box').style.display = 'none';
    document.getElementById('lsm-submit-btn').disabled = true;

    const select = document.getElementById('lsm-staff-select');
    select.innerHTML = '<option value="">— Loading staff directory… —</option>';

    loadStaffList().then(staff => {
        select.innerHTML = '<option value="">— Select Staff Member —</option>';
        staff.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.full_name + ' (' + s.employee_id + ') — ' + (s.designation || s.department || 'Staff');
            if (currentStaffId && parseInt(s.id) === parseInt(currentStaffId)) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });

        if (currentStaffId) {
            checkStaffAdminMatch();
        }
    });

    openModal('link-staff-modal');
}

function checkStaffAdminMatch() {
    const empId = document.getElementById('lsm-staff-select').value;
    const adminId = document.getElementById('lsm-admin-id').value;
    const verifyBox = document.getElementById('lsm-verify-box');
    const submitBtn = document.getElementById('lsm-submit-btn');

    if (!empId) {
        verifyBox.style.display = 'none';
        submitBtn.disabled = true;
        return;
    }

    verifyBox.style.display = 'block';
    document.getElementById('lsm-status-badge').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking match…';

    fetch('admin-management.php?action=check_staff_admin_match&admin_id=' + adminId + '&employee_id=' + empId)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                document.getElementById('lsm-status-badge').innerHTML = '<span class="badge red">Error</span>';
                document.getElementById('lsm-error-container').innerHTML = '<div style="color:#ef4444; font-size:0.8rem; margin-top:8px;">' + (d.error || 'Check failed.') + '</div>';
                submitBtn.disabled = true;
                return;
            }

            document.getElementById('lsm-staff-email-disp').textContent = d.staff_email_masked;
            document.getElementById('lsm-admin-email-disp').textContent = d.admin_email_masked;
            document.getElementById('lsm-staff-phone-disp').textContent = d.staff_phone_masked;
            document.getElementById('lsm-admin-phone-disp').textContent = d.admin_phone_masked;

            if (d.email_match) {
                document.getElementById('lsm-email-match-indicator').innerHTML = '<span style="color:#22c55e;"><i class="fas fa-circle-check"></i> Email Matches</span>';
            } else {
                document.getElementById('lsm-email-match-indicator').innerHTML = '<span style="color:#ef4444;"><i class="fas fa-circle-xmark"></i> Mismatch</span>';
            }

            if (d.phone_match) {
                document.getElementById('lsm-phone-match-indicator').innerHTML = '<span style="color:#22c55e;"><i class="fas fa-circle-check"></i> Mobile Matches</span>';
            } else {
                document.getElementById('lsm-phone-match-indicator').innerHTML = '<span style="color:#ef4444;"><i class="fas fa-circle-xmark"></i> Mismatch</span>';
            }

            let errHtml = '';
            if (d.errors && d.errors.length > 0) {
                d.errors.forEach(e => {
                    errHtml += '<div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:6px 10px; border-radius:6px; font-size:0.78rem; margin-top:6px;"><i class="fas fa-triangle-exclamation"></i> ' + e + '</div>';
                });
            }
            if (d.conflict_warning) {
                errHtml += '<div style="background:#fffbeb; border:1px solid #fde68a; color:#b45309; padding:6px 10px; border-radius:6px; font-size:0.78rem; margin-top:6px;"><i class="fas fa-circle-info"></i> ' + d.conflict_warning + '</div>';
            }
            document.getElementById('lsm-error-container').innerHTML = errHtml;

            if (d.can_link) {
                document.getElementById('lsm-status-badge').innerHTML = '<span class="badge green"><i class="fas fa-check"></i> Verified Match</span>';
                submitBtn.disabled = false;
            } else {
                document.getElementById('lsm-status-badge').innerHTML = '<span class="badge red"><i class="fas fa-times"></i> Cannot Link</span>';
                submitBtn.disabled = true;
            }
        })
        .catch(() => {
            document.getElementById('lsm-status-badge').innerHTML = '<span class="badge red">Error</span>';
            submitBtn.disabled = true;
        });
}

function unlinkStaff(adminId, username, staffName) {
    if (confirm('Are you sure you want to unlink staff member "' + staffName + '" from admin account "' + username + '"?')) {
        document.getElementById('unl-admin-id').value = adminId;
        document.getElementById('unlink-form').submit();
    }
}
</script>
<?php
$extra_scripts = ob_get_clean();
include 'includes/admin_footer.php';
?>
