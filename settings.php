<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('settings');

// Self-healing database check for payment_accounts new columns
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM payment_accounts LIKE 'banking_details'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE payment_accounts ADD COLUMN banking_details TEXT DEFAULT NULL");
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM payment_accounts LIKE 'is_public'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE payment_accounts ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {
    error_log("payment_accounts schema update failed: " . $e->getMessage());
}

// Self-healing check and seed for default installment reminder message template
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_settings WHERE setting_name = 'installment_reminder_message'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $default_tpl = "Dear {name}, this is a friendly reminder that your {installment_count} installment of ₹{amount} for the {course} course is due on {due_date}. Please pay to beneficiary {beneficiary} using banking details: {banking_details}. Thank you!";
        $stmt = $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at) VALUES ('installment_reminder_message', ?, NOW(), NOW())");
        $stmt->execute([$default_tpl]);
    }
} catch (Exception $e) {
    error_log("Seeding installment reminder message failed: " . $e->getMessage());
}

/* System settings:
   - academic years (add + activate/deactivate) - feeds register.php,
     add-student.php, course-management.php
   - WhatsApp message templates (admin_settings) - used by onboarding,
     approvals and payment review
   - payment accounts - used in approval & payment review dropdowns
   - admin account: change username/password (stored hashed in
     admin_settings; login.php verifies against it) */

$success_message = '';
$error_message   = '';

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success_message = 'Sidebar menu layout configuration saved successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } elseif (!is_super_admin() && ($_POST['action'] ?? '') !== 'change_password') {
        $error_message = 'Only the Super Admin can change these settings.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_academic_year') {
                $year  = trim($_POST['year'] ?? '');
                $start = $_POST['start_date'] ?? '';
                $end   = $_POST['end_date'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}$/', $year)) {
                    $error_message = 'Year must look like 2026-27.';
                } elseif (!$start || !$end) {
                    $error_message = 'Start and end dates are required.';
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM academic_years WHERE year = ?");
                    $stmt->execute([$year]);
                    if ($stmt->fetchColumn() > 0) {
                        $error_message = "Academic year {$year} already exists.";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO academic_years (year, start_date, end_date, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
                        $stmt->execute([$year, $start, $end]);
                        $success_message = "Academic year {$year} added.";
                    }
                }
            } elseif ($action === 'toggle_year') {
                $id = (int)($_POST['year_id'] ?? 0);
                $pdo->prepare("UPDATE academic_years SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")->execute([$id]);
                $success_message = 'Academic year status updated.';
            } elseif ($action === 'save_messages') {
                $keys = [
                    'onboarding_wp_message', 'approval_confirmation_message', 'approval_app_access_message',
                    'user_rejection_wp_message', 'reg_entry_cancelling_message', 'installment_reminder_message'
                ];
                $stmt = $pdo->prepare("
                    INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                foreach ($keys as $key) {
                    if (isset($_POST[$key])) $stmt->execute([$key, trim($_POST[$key])]);
                }
                $success_message = 'Message templates saved.';
            } elseif ($action === 'add_payment_account') {
                $name = trim($_POST['account_name'] ?? '');
                $type = in_array($_POST['account_type'] ?? '', ['Bank','UPI','Digital Wallet','Other'], true) ? $_POST['account_type'] : 'Bank';
                $details = trim($_POST['account_details'] ?? '');
                $banking_details = trim($_POST['banking_details'] ?? '');
                $is_public = isset($_POST['is_public']) ? 1 : 0;
                if ($name === '') {
                    $error_message = 'Account name is required.';
                } else {
                    if ($is_public) {
                        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM payment_accounts WHERE is_public = 1 AND status = 'active'")->fetchColumn();
                        if ($cnt >= 2) {
                            throw new Exception("Maximum of 2 active public payment accounts is allowed.");
                        }
                    }
                    $stmt = $pdo->prepare("INSERT INTO payment_accounts (account_name, account_type, account_details, banking_details, is_public, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                    $stmt->execute([$name, $type, $details, $banking_details, $is_public]);
                    $success_message = "Payment account \"{$name}\" added.";
                }
            } elseif ($action === 'edit_payment_account') {
                $id = (int)($_POST['account_id'] ?? 0);
                $name = trim($_POST['account_name'] ?? '');
                $type = in_array($_POST['account_type'] ?? '', ['Bank','UPI','Digital Wallet','Other'], true) ? $_POST['account_type'] : 'Bank';
                $details = trim($_POST['account_details'] ?? '');
                $banking_details = trim($_POST['banking_details'] ?? '');
                $is_public = isset($_POST['is_public']) ? 1 : 0;
                $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
                if ($name === '') {
                    $error_message = 'Account name is required.';
                } else {
                    if ($is_public && $status === 'active') {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_accounts WHERE is_public = 1 AND status = 'active' AND id <> ?");
                        $stmt->execute([$id]);
                        if ((int)$stmt->fetchColumn() >= 2) {
                            throw new Exception("Maximum of 2 active public payment accounts is allowed.");
                        }
                    }
                    $stmt = $pdo->prepare("UPDATE payment_accounts SET account_name = ?, account_type = ?, account_details = ?, banking_details = ?, is_public = ?, status = ? WHERE id = ?");
                    $stmt->execute([$name, $type, $details, $banking_details, $is_public, $status, $id]);
                    $success_message = "Payment account \"{$name}\" updated.";
                }
            } elseif ($action === 'toggle_account') {
                $id = (int)($_POST['account_id'] ?? 0);
                $acc = $pdo->prepare("SELECT is_public, status FROM payment_accounts WHERE id = ?");
                $acc->execute([$id]);
                $a = $acc->fetch();
                if ($a && $a['status'] === 'inactive' && $a['is_public'] == 1) {
                    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM payment_accounts WHERE is_public = 1 AND status = 'active'")->fetchColumn();
                    if ($cnt >= 2) {
                        throw new Exception("Maximum of 2 active public payment accounts is allowed. Please deactivate/unmark another public account first.");
                    }
                }
                $pdo->prepare("UPDATE payment_accounts SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")->execute([$id]);
                $success_message = 'Payment account status updated.';
            } elseif ($action === 'add_expense_type') {
                $name = trim($_POST['type_name'] ?? '');
                if ($name !== '') {
                    try {
                        $pdo->prepare("INSERT INTO expense_types (name, status, created_at) VALUES (?, 'active', NOW())")->execute([$name]);
                        $success_message = 'Expense type added.';
                    } catch (Exception $e) { $error_message = 'That expense type already exists.'; }
                }
            } elseif ($action === 'toggle_expense_type') {
                $id = (int)($_POST['type_id'] ?? 0);
                $pdo->prepare("UPDATE expense_types SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$id]);
                $success_message = 'Expense type updated.';
            } elseif ($action === 'delete_expense_type') {
                if (is_super_admin()) {
                    $id = (int)($_POST['type_id'] ?? 0);
                    $pdo->prepare("DELETE FROM expense_types WHERE id = ?")->execute([$id]);
                    $success_message = 'Expense type deleted.';
                }
            } elseif ($action === 'save_invoice_settings') {
                $stmt = $pdo->prepare("
                    INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $gst_seq    = max(1, (int)($_POST['inv_gst_seq'] ?? 1));
                $nongst_seq = max(1, (int)($_POST['inv_nongst_seq'] ?? 1));
                $fy = preg_replace('/[^0-9]/', '', $_POST['inv_gst_fy'] ?? '2627');
                $pairs = [
                    'inv_gst_account_id' => (string)(int)($_POST['inv_gst_account_id'] ?? 0),
                    'inv_gst_prefix'     => strtoupper(trim($_POST['inv_gst_prefix'] ?? 'INV')) ?: 'INV',
                    'inv_gst_fy'         => $fy ?: '2627',
                    'inv_gst_start'      => $_POST['inv_gst_start'] ?? '',
                    'inv_gst_end'        => $_POST['inv_gst_end'] ?? '',
                    'inv_gst_seq'        => (string)$gst_seq,
                    'inv_nongst_prefix'  => strtoupper(trim($_POST['inv_nongst_prefix'] ?? 'INV')) ?: 'INV',
                    'inv_nongst_seq'     => (string)$nongst_seq,
                ];
                foreach ($pairs as $k => $v) $stmt->execute([$k, $v]);
                log_admin_activity($pdo, $admin_username, 'invoice_settings_changed',
                    'GST series ' . $pairs['inv_gst_prefix'] . '/' . $pairs['inv_gst_fy'] . ' (next #' . $gst_seq . '), account id ' . $pairs['inv_gst_account_id']);
                $success_message = 'Invoice settings saved.';
            } elseif ($action === 'save_smtp_settings') {
                $stmt = $pdo->prepare("
                    INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $pairs = [
                    'smtp_enabled'    => isset($_POST['smtp_enabled']) ? '1' : '0',
                    'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
                    'smtp_port'       => (string)(int)($_POST['smtp_port'] ?? 465),
                    'smtp_secure'     => in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'ssl',
                    'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
                    'smtp_pass'       => trim($_POST['smtp_pass'] ?? ''),
                    'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
                    'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? 'PEPP Learning'),
                ];
                foreach ($pairs as $k => $v) $stmt->execute([$k, $v]);
                log_admin_activity($pdo, $admin_username, 'smtp_settings_changed', 'Updated SMTP Configuration Settings');
                $success_message = 'SMTP Mailer settings saved.';
            } elseif ($action === 'save_sidebar_config') {
                if (!is_super_admin()) {
                    $error_message = 'Only the Super Admin can change these settings.';
                } else {
                    $config_json = $_POST['sidebar_config'] ?? '';
                    $decoded = json_decode($config_json, true);
                    if (!is_array($decoded) || empty($decoded)) {
                        $error_message = 'Invalid sidebar configuration layout format.';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at)
                            VALUES ('sidebar_menu_config', ?, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                        ");
                        $stmt->execute([$config_json]);
                        log_admin_activity($pdo, $admin_username, 'sidebar_layout_changed', 'Reordered and regrouped sidebar menu layout');
                        
                        header("Location: settings.php?success=1");
                        exit();
                    }
                }
            } elseif ($action === 'change_password') {
                $new_user = trim($_POST['admin_username'] ?? '');
                $current  = $_POST['current_password'] ?? '';
                $new_pass = $_POST['new_password'] ?? '';
                $confirm  = $_POST['confirm_password'] ?? '';

                if (strlen($new_pass) < 8) {
                    $error_message = 'New password must be at least 8 characters.';
                } elseif ($new_pass !== $confirm) {
                    $error_message = 'New password and confirmation do not match.';
                } elseif ($new_user === '') {
                    $error_message = 'Username cannot be empty.';
                } elseif (admins_table_exists($pdo)) {
                    // Multi-admin mode: each admin changes their OWN credentials
                    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
                    $stmt->execute([$admin_username]);
                    $me = $stmt->fetch();
                    $current_ok = $me && (
                        ($me['password_hash'] !== '' && password_verify($current, $me['password_hash']))
                        || ($me['password_hash'] === '' && $current === 'admin123@pepp')
                    );
                    if (!$current_ok) {
                        $error_message = 'Current password is incorrect.';
                    } else {
                        // Username uniqueness
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ? AND id <> ?");
                        $stmt->execute([$new_user, $me['id']]);
                        if ($stmt->fetchColumn() > 0) {
                            $error_message = "Username \"{$new_user}\" is already taken.";
                        } else {
                            if (is_super_admin()) {
                                $gemail = trim($_POST['google_email'] ?? '');
                                if ($gemail !== '' && !filter_var($gemail, FILTER_VALIDATE_EMAIL)) {
                                    $error_message = 'Please enter a valid Google email address.';
                                } else {
                                    $pdo->prepare("UPDATE admins SET username = ?, password_hash = ?, google_email = ? WHERE id = ?")
                                        ->execute([$new_user, password_hash($new_pass, PASSWORD_DEFAULT), $gemail ?: null, $me['id']]);
                                    log_admin_activity($pdo, $new_user, 'password_changed', 'Changed own credentials and Google sign-in email' . ($new_user !== $admin_username ? " (username was {$admin_username})" : ''));
                                    $_SESSION['admin_username'] = $new_user;
                                    $admin_username = $new_user;
                                    $success_message = 'Your credentials and Google Auth email were updated. Use them on your next sign-in.';
                                }
                            } else {
                                $pdo->prepare("UPDATE admins SET username = ?, password_hash = ? WHERE id = ?")
                                    ->execute([$new_user, password_hash($new_pass, PASSWORD_DEFAULT), $me['id']]);
                                log_admin_activity($pdo, $new_user, 'password_changed', 'Changed own credentials' . ($new_user !== $admin_username ? " (username was {$admin_username})" : ''));
                                $_SESSION['admin_username'] = $new_user;
                                $admin_username = $new_user;
                                $success_message = 'Your credentials were updated. Use them on your next sign-in.';
                            }
                        }
                    }
                } else {
                    // Legacy single-admin mode (database-update-2.sql not run yet)
                    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'admin_password_hash'");
                    $stmt->execute();
                    $hash = $stmt->fetchColumn();
                    $current_ok = $hash ? password_verify($current, $hash) : ($current === 'admin123@pepp');
                    if (!$current_ok) {
                        $error_message = 'Current password is incorrect.';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO admin_settings (setting_name, setting_value, created_at, updated_at)
                            VALUES (?, ?, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                        ");
                        $stmt->execute(['admin_username', $new_user]);
                        $stmt->execute(['admin_password_hash', password_hash($new_pass, PASSWORD_DEFAULT)]);
                        $_SESSION['admin_username'] = $new_user;
                        $admin_username = $new_user;
                        $success_message = 'Admin credentials updated. Use them on your next sign-in.';
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Settings: ' . $e->getMessage());
            $error_message = 'Database error while saving settings.';
        }
    }
}

// ── Data ─────────────────────────────────────────────────────────
$current_settings = [];
$academic_years = [];
$payment_accounts = [];
try {
    foreach ($pdo->query("SELECT setting_name, setting_value FROM admin_settings")->fetchAll() as $row) {
        $current_settings[$row['setting_name']] = $row['setting_value'];
    }
    $academic_years   = $pdo->query("SELECT * FROM academic_years ORDER BY start_date DESC")->fetchAll();
    $expense_types    = [];
    try { $expense_types = $pdo->query("SELECT * FROM expense_types ORDER BY name")->fetchAll(); } catch (Exception $e) {}
    $payment_accounts = $pdo->query("SELECT * FROM payment_accounts ORDER BY status ASC, account_name ASC")->fetchAll();
} catch (Exception $e) {
    error_log('Settings load: ' . $e->getMessage());
    $error_message = $error_message ?: 'Could not load settings.';
}

$message_fields = [
    'onboarding_wp_message'        => ['Welcome / Onboarding message', 'Sent from the onboarding page'],
    'approval_confirmation_message'=> ['Approval confirmation', 'Sent after approving a registration'],
    'approval_app_access_message'  => ['App access message', 'Sent when app access is provided'],
    'user_rejection_wp_message'    => ['Rejection message', 'Sent when an application is rejected'],
    'reg_entry_cancelling_message' => ['Registration cancelled', 'Sent when an entry is removed'],
    'installment_reminder_message' => ['Installment Payment Reminder', 'Sent to remind learners of upcoming installments'],
];

$active_page = 'settings';
$page_title  = 'Settings';
$page_sub    = 'Academic years, templates, payment accounts & admin account';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<?php if (is_super_admin()): ?>
<!-- ── ACADEMIC YEARS ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-calendar-days"></i></span><h2>Academic Years</h2></div>
    <div class="panel-body">
        <form method="POST" class="filter-bar" style="margin-bottom:18px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_academic_year">
            <div class="field"><label>Year (e.g. 2026-27)</label><input type="text" name="year" placeholder="2026-27" required pattern="\d{4}-\d{2}"></div>
            <div class="field"><label>Start date</label><input type="date" name="start_date" required></div>
            <div class="field"><label>End date</label><input type="date" name="end_date" required></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Year</button>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Year</th><th>Period</th><th>Status</th><th style="text-align:right;"></th></tr></thead>
                <tbody>
                <?php foreach ($academic_years as $y): ?>
                    <tr>
                        <td class="cell-main"><?php echo e($y['year']); ?></td>
                        <td class="cell-sub"><?php echo date('d M Y', strtotime($y['start_date'])); ?> → <?php echo date('d M Y', strtotime($y['end_date'])); ?></td>
                        <td><span class="badge <?php echo $y['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($y['status']); ?></span></td>
                        <td style="text-align:right;">
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_year">
                                <input type="hidden" name="year_id" value="<?php echo (int)$y['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $y['status'] === 'active' ? 'btn-soft-amber' : 'btn-soft-green'; ?>">
                                    <i class="fas fa-power-off"></i> <?php echo $y['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($academic_years)): ?>
                    <tr><td colspan="4"><div class="empty-state" style="padding:24px;"><p>No academic years yet.</p></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="alert alert-info" style="margin-top:14px; margin-bottom:0;">
            <i class="fas fa-circle-info"></i>
            <span>Active years appear in register.php, Add Student and Course Management.</span>
        </div>
    </div>
</div>

<!-- ── WHATSAPP TEMPLATES ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fab fa-whatsapp"></i></span><h2>WhatsApp Message Templates</h2></div>
    <div class="panel-body">
        <div class="alert alert-info">
            <i class="fas fa-circle-info"></i>
            <span><strong>Placeholders are filled automatically from the database</strong> when a message is sent:
            <code>{name}</code> <code>{user_id}</code> <code>{email}</code> <code>{whatsapp_number}</code>
            <code>{PEPP course}</code> <code>{academic_year}</code> <code>{payment_plan}</code> <code>{payment_mode}</code>
            <code>{joined_date}</code> <code>{access_end}</code> (course access end date)
            <code>{paid_amount}</code> (registration payment) <code>{total_fee}</code> (net payable) <code>{course_fee}</code> (actual course fee before discount)
            <code>{discount_amount}</code> <code>{collected}</code> (total received) <code>{balance}</code> (remaining).
            Any other users-table column also works as <code>{column_name}</code>.</span>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_messages">
            <div class="form-grid" style="grid-template-columns: 1fr;">
                <?php foreach ($message_fields as $key => [$label, $hint]): ?>
                    <div class="field">
                        <label><?php echo e($label); ?></label>
                        <textarea name="<?php echo $key; ?>" rows="3" placeholder="<?php echo e($hint); ?>"><?php echo e($current_settings[$key] ?? ''); ?></textarea>
                        <div class="help"><?php echo e($hint); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Templates</button>
            </div>
        </form>
    </div>
</div>

<!-- ── PAYMENT ACCOUNTS ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-building-columns"></i></span><h2>Payment Accounts</h2></div>
    <div class="panel-body">
        <form method="POST" class="filter-bar" style="margin-bottom:18px; display:flex; flex-direction:column; gap:12px; align-items:stretch;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_payment_account">
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <div class="field grow-2" style="margin:0; flex:2;"><label>Account name</label><input type="text" name="account_name" placeholder="e.g. PEPP HDFC Current A/c" required></div>
                <div class="field" style="margin:0; flex:1;"><label>Type</label>
                    <select name="account_type"><?php foreach (['Bank','UPI','Digital Wallet','Other'] as $t) echo "<option>$t</option>"; ?></select></div>
                <div class="field grow-2" style="margin:0; flex:2;"><label>Details (internal info)</label><input type="text" name="account_details" placeholder="UPI ID / account no."></div>
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
                <div class="field grow-2" style="margin:0; flex:2;"><label>UPI / Banking details for students (Public)</label><input type="text" name="banking_details" placeholder="e.g. GPay/UPI ID: pepp@hdfcbank or Bank details"></div>
                <div style="display:flex; align-items:center; gap:8px; height:38px; padding-bottom:6px;">
                    <label class="switch-row" style="margin:0; font-weight:normal;"><input type="checkbox" name="is_public" value="1"> Public (Show on register.php)</label>
                </div>
                <button type="submit" class="btn btn-primary" style="height:38px; margin-left:auto;"><i class="fas fa-plus"></i> Add Account</button>
            </div>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Account</th><th>Type</th><th>Details</th><th>UPI/Banking (Public)</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($payment_accounts as $a): ?>
                    <tr>
                        <td class="cell-main">
                            <?php echo e($a['account_name']); ?>
                            <?php if ($a['is_public'] == 1): ?>
                                <span class="badge violet" style="font-size:0.7rem; padding: 2px 6px;">[Public]</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge blue"><?php echo e($a['account_type']); ?></span></td>
                        <td class="cell-sub"><?php echo e($a['account_details'] ?: '-'); ?></td>
                        <td><span class="cell-sub"><?php echo e($a['banking_details'] ?: '-'); ?></span></td>
                        <td><span class="badge <?php echo $a['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                        <td style="text-align:right; white-space:nowrap;">
                            <button type="button" class="btn btn-sm btn-outline" onclick='openEditAccount(<?php echo json_encode([
                                "id" => (int)$a["id"],
                                "account_name" => $a["account_name"],
                                "account_type" => $a["account_type"],
                                "account_details" => $a["account_details"],
                                "banking_details" => $a["banking_details"],
                                "is_public" => (int)$a["is_public"],
                                "status" => $a["status"]
                            ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_account">
                                <input type="hidden" name="account_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $a['status'] === 'active' ? 'btn-soft-amber' : 'btn-soft-green'; ?>">
                                    <i class="fas fa-power-off"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($payment_accounts)): ?>
                    <tr><td colspan="6"><div class="empty-state" style="padding:24px;"><p>No payment accounts yet.</p></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="alert alert-info" style="margin-top:14px; margin-bottom:0;">
            <i class="fas fa-circle-info"></i>
            <span>Active accounts appear in the approval modal and payment review dropdowns. Marked Public accounts (maximum 2) will be displayed on the student registration page.</span>
        </div>
    </div>
</div>



<!-- ── EXPENSE TYPES ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-tags"></i></span><h2>Expense Types</h2></div>
    <div class="panel-body">
        <form method="POST" class="filter-bar" style="margin-bottom:16px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_expense_type">
            <div class="field grow-2"><label>New expense type</label><input type="text" name="type_name" placeholder="e.g. Software Subscriptions" required></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Type</button>
        </form>
        <?php if (!empty($expense_types)): ?>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Expense Type</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($expense_types as $t): ?>
                <tr>
                    <td class="cell-main"><?php echo e($t['name']); ?></td>
                    <td><span class="badge <?php echo $t['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($t['status']); ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <form method="POST" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_expense_type"><input type="hidden" name="type_id" value="<?php echo (int)$t['id']; ?>"><button type="submit" class="btn btn-sm btn-soft-amber"><i class="fas fa-power-off"></i></button></form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this expense type?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_expense_type"><input type="hidden" name="type_id" value="<?php echo (int)$t['id']; ?>"><button type="submit" class="btn btn-sm btn-soft-red"><i class="fas fa-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><div class="cell-sub">No expense types yet - the standard list is seeded by database-update-7.sql.</div><?php endif; ?>
    </div>
</div>

<!-- ── INVOICE SETTINGS ── -->
<?php
$ivs = function ($k, $d = '') use ($current_settings) { return e($current_settings[$k] ?? $d); };
$gst_acc_current = (int)($current_settings['inv_gst_account_id'] ?? 0);
$gst_preview    = ($current_settings['inv_gst_prefix'] ?? 'INV') . '/' . ($current_settings['inv_gst_fy'] ?? '2627') . '/' . str_pad($current_settings['inv_gst_seq'] ?? '1', 3, '0', STR_PAD_LEFT);
$nongst_preview = ($current_settings['inv_nongst_prefix'] ?? 'INV') . '/' . date('dmy') . '/' . str_pad($current_settings['inv_nongst_seq'] ?? '1', 3, '0', STR_PAD_LEFT);
?>
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-file-invoice"></i></span><h2>Invoice Settings</h2>
        <div class="head-right"><a class="btn btn-sm btn-outline" href="invoices.php"><i class="fas fa-file-invoice"></i> Open Invoices</a></div>
    </div>
    <div class="panel-body">
        <div class="alert alert-info">
            <i class="fas fa-circle-info"></i>
            <span>Payments received in the <strong>GST account</strong> are treated as 18% GST-inclusive and get a GST invoice
            (GSTIN 32AAFCL3813L1ZL) numbered from the GST series. Every other account gets a clean invoice with no tax details,
            numbered <code>PREFIX/DDMMYY/seq</code> from its own independent sequence.</span>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_invoice_settings">
            <div class="form-grid">
                <div class="field full"><label>GST account (18% inclusive receipts)</label>
                    <select name="inv_gst_account_id">
                        <option value="0">- None / disable GST invoices -</option>
                        <?php foreach ($payment_accounts as $a): ?>
                            <option value="<?php echo (int)$a['id']; ?>" <?php echo $gst_acc_current === (int)$a['id'] ? 'selected' : ''; ?>>
                                <?php echo e($a['account_name']); ?> (<?php echo e($a['account_type']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Usually AXIS LABINC - change only if the GST-registered account changes</div></div>
                <div class="field"><label>GST series prefix</label>
                    <input type="text" name="inv_gst_prefix" value="<?php echo $ivs('inv_gst_prefix', 'INV'); ?>" maxlength="10"></div>
                <div class="field"><label>GST financial year code</label>
                    <input type="text" name="inv_gst_fy" value="<?php echo $ivs('inv_gst_fy', '2627'); ?>" maxlength="6" pattern="[0-9]{4,6}">
                    <div class="help">e.g. 2627 for FY 2026-27 (per GST department)</div></div>
                <div class="field"><label>GST series starts from</label>
                    <input type="date" name="inv_gst_start" value="<?php echo $ivs('inv_gst_start'); ?>"></div>
                <div class="field"><label>GST series valid till</label>
                    <input type="date" name="inv_gst_end" value="<?php echo $ivs('inv_gst_end'); ?>"></div>
                <div class="field"><label>GST next sequence number</label>
                    <input type="number" name="inv_gst_seq" min="1" value="<?php echo $ivs('inv_gst_seq', '1'); ?>">
                    <div class="help">Next GST invoice: <strong><?php echo e($gst_preview); ?></strong> - reset to 1 with each new financial year</div></div>
                <div class="field"><label>Non-GST prefix</label>
                    <input type="text" name="inv_nongst_prefix" value="<?php echo $ivs('inv_nongst_prefix', 'INV'); ?>" maxlength="10"></div>
                <div class="field"><label>Non-GST next sequence number</label>
                    <input type="number" name="inv_nongst_seq" min="1" value="<?php echo $ivs('inv_nongst_seq', '1'); ?>">
                    <div class="help">Next non-GST invoice (today): <strong><?php echo e($nongst_preview); ?></strong> - date part follows the paid date automatically</div></div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Invoice Settings</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SMTP MAIL CONFIGURATION ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-envelope-open-text"></i></span><h2>SMTP Mail Settings</h2></div>
    <div class="panel-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_smtp_settings">
            
            <div style="margin-bottom: 15px;">
                <label style="display:flex; align-items:center; gap:8px; font-weight:700; cursor:pointer;">
                    <input type="checkbox" name="smtp_enabled" value="1" <?php echo $ivs('smtp_enabled', '0') === '1' ? 'checked' : ''; ?>>
                    Enable Authenticated SMTP Mailer (Overrides php mail())
                </label>
                <div class="help" style="margin-left: 24px;">Check this to route all dispatches via external SMTP server. If unchecked, local server mail() fallback is used.</div>
            </div>

            <div class="form-grid">
                <div class="field"><label>SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?php echo e($ivs('smtp_host', 'smtp.hostinger.com')); ?>" placeholder="e.g. smtp.hostinger.com"></div>
                
                <div class="field"><label>SMTP Port</label>
                    <input type="number" name="smtp_port" value="<?php echo e($ivs('smtp_port', '465')); ?>" placeholder="465 or 587"></div>

                <div class="field"><label>Encryption / Security</label>
                    <select name="smtp_secure">
                        <option value="ssl" <?php echo $ivs('smtp_secure', 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL (Port 465)</option>
                        <option value="tls" <?php echo $ivs('smtp_secure', 'ssl') === 'tls' ? 'selected' : ''; ?>>TLS / STARTTLS (Port 587)</option>
                        <option value="none" <?php echo $ivs('smtp_secure', 'ssl') === 'none' ? 'selected' : ''; ?>>None</option>
                    </select>
                </div>

                <div class="field"><label>SMTP Username (Full Email)</label>
                    <input type="email" name="smtp_user" value="<?php echo e($ivs('smtp_user', 'noreply@pepplearning.in')); ?>" placeholder="noreply@pepplearning.in"></div>

                <div class="field"><label>SMTP Password</label>
                    <input type="password" name="smtp_pass" value="<?php echo e($ivs('smtp_pass', '')); ?>" placeholder="Enter SMTP password"></div>

                <div class="field"><label>Sender From Email</label>
                    <input type="email" name="smtp_from_email" value="<?php echo e($ivs('smtp_from_email', 'noreply@pepplearning.in')); ?>" placeholder="noreply@pepplearning.in"></div>

                <div class="field full"><label>Sender Display Name</label>
                    <input type="text" name="smtp_from_name" value="<?php echo e($ivs('smtp_from_name', 'PEPP Learning')); ?>" placeholder="PEPP Learning"></div>
            </div>

            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save SMTP Settings</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SIDEBAR MENU LAYOUT ── -->
<?php
$sub_item_labels = [
    'dashboard' => 'Dashboard',
    'approvals' => 'Approvals',
    'add-student' => 'Add Student',
    'students' => 'All Students',
    'onboarding' => 'Onboarding',
    'sessions' => 'Sessions',
    'leads' => 'Lead Management',
    'alumni' => 'Alumni Database',
    'peppkit' => 'PEPPKIT Report',
    'cards' => 'Generate Custom Cards',
    'accounts' => 'Accounts & Expenses',
    'campaigns' => 'Custom Forms',
    'marketing' => 'Marketing',
    'email-campaigns' => 'Email Campaigns',
    'installments' => 'Installments',
    'invoices' => 'Invoices',
    'communication' => 'Communication Engine',
    'whatsapp' => 'WhatsApp Messages',
    'courses' => 'Courses',
    'faculties' => 'Faculties',
    'studyplans' => 'Study Plans',
    'student-study-reports' => 'Student Reports',
    'settings' => 'Settings',
    'admin-management' => 'Admin Management',
    'admin-activity' => 'Activity Log',
    'reports' => 'Reports & Export'
];
?>
<div class="panel" style="margin-top:20px;">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-bars"></i></span>
        <h2>Sidebar Menu Layout Settings</h2>
    </div>
    <div class="panel-body">
        <div class="alert alert-info" style="margin-bottom:20px;">
            <i class="fas fa-circle-info"></i>
            <span>Super administrators can rearrange categories, reorder sub-categories inside a category, or move sub-categories from one category to another. Click <strong>Save Sidebar Layout</strong> to apply.</span>
        </div>
        
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_sidebar_config">
            <input type="hidden" name="sidebar_config" id="sidebar-config-input">
            
            <div id="sidebar-layout-editor" style="display:flex; flex-direction:column; gap:16px; margin-bottom:20px;">
                <!-- Reordering UI generated by JS -->
            </div>
            
            <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border); padding-top:14px;">
                <button type="button" class="btn btn-outline" onclick="resetSidebarToDefault()"><i class="fas fa-undo"></i> Reset to Default</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Sidebar Layout</button>
            </div>
        </form>
    </div>
</div>

<?php endif; /* super-only settings */ ?>

<?php if (!is_super_admin()): ?>
<div class="alert alert-info"><i class="fas fa-circle-info"></i><span>You can update your own password here. Other settings are managed by the Super Admin.</span></div>
<?php endif; ?>

<!-- ── ADMIN ACCOUNT ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--red-soft);color:var(--red-ink);"><i class="fas fa-user-shield"></i></span><h2>Admin Account</h2></div>
    <div class="panel-body">
        <div class="alert alert-warn">
            <i class="fas fa-shield-halved"></i>
            <span>The password is stored as a secure hash - it is never shown anywhere, including the login page. Choose a strong, unique password.</span>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-grid">
                <div class="field"><label>Username</label>
                    <input type="text" name="admin_username" value="<?php echo e($admin_username); ?>" required>
                    <div class="help">Changes apply to YOUR account (<?php echo is_super_admin() ? 'Super Admin' : 'Admin'; ?>)</div></div>
                <?php if (is_super_admin()): ?>
                <div class="field"><label>Google sign-in email</label>
                    <input type="email" name="google_email" value="<?php echo e($admin_row['google_email'] ?? ''); ?>" placeholder="superadmin@example.com">
                    <div class="help">Google account allowed to sign in as Super Admin</div></div>
                <?php endif; ?>
                <div class="field"><label>Current password <span class="req">*</span></label>
                    <input type="password" name="current_password" required autocomplete="current-password"></div>
                <div class="field"><label>New password <span class="req">*</span></label>
                    <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                    <div class="help">Minimum 8 characters</div></div>
                <div class="field"><label>Confirm new password <span class="req">*</span></label>
                    <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="submit" class="btn btn-danger"><i class="fas fa-key"></i> Update Credentials</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit payment account modal -->
<div class="modal-backdrop" id="edit-account-modal">
    <div class="modal" style="max-width:540px;">
        <div class="modal-head">
            <h3><i class="fas fa-building-columns" style="color:var(--accent);"></i> Edit Payment Account</h3>
            <button class="modal-close" onclick="closeModal('edit-account-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_payment_account">
            <input type="hidden" name="account_id" id="edit-acc-id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>Account Name <span class="req">*</span></label><input type="text" name="account_name" id="edit-acc-name" required></div>
                    <div class="field"><label>Type</label>
                        <select name="account_type" id="edit-acc-type">
                            <?php foreach (['Bank','UPI','Digital Wallet','Other'] as $t) echo "<option>$t</option>"; ?>
                        </select>
                    </div>
                    <div class="field full"><label>Details (internal info)</label><input type="text" name="account_details" id="edit-acc-details"></div>
                    <div class="field full"><label>UPI / Banking details for students (Public)</label><input type="text" name="banking_details" id="edit-acc-banking-details"></div>
                    <div class="field"><label>Status</label>
                        <select name="status" id="edit-acc-status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <label class="switch-row" style="font-weight:normal; margin:0;"><input type="checkbox" name="is_public" id="edit-acc-is-public" value="1"> Public (Show on register.php)</label>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('edit-account-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditAccount(acc) {
    document.getElementById('edit-acc-id').value = acc.id;
    document.getElementById('edit-acc-name').value = acc.account_name;
    document.getElementById('edit-acc-type').value = acc.account_type;
    document.getElementById('edit-acc-details').value = acc.account_details || '';
    document.getElementById('edit-acc-banking-details').value = acc.banking_details || '';
    document.getElementById('edit-acc-status').value = acc.status;
    document.getElementById('edit-acc-is-public').checked = (acc.is_public == 1);
    openModal('edit-account-modal');
}

// Sidebar Menu Layout Customizer JavaScript controller
<?php if (is_super_admin()): ?>
var sidebarConfig = <?php echo json_encode($sidebar_menu); ?>;
var defaultSidebar = <?php echo json_encode($default_sidebar); ?>;
var subItemLabels = <?php echo json_encode($sub_item_labels); ?>;

function renderEditor() {
    var container = document.getElementById('sidebar-layout-editor');
    if (!container) return;
    
    container.innerHTML = '';
    document.getElementById('sidebar-config-input').value = JSON.stringify(sidebarConfig);
    
    sidebarConfig.forEach(function(section, secIdx) {
        var card = document.createElement('div');
        card.style.border = '1px solid var(--border)';
        card.style.borderRadius = '12px';
        card.style.background = 'var(--card)';
        card.style.overflow = 'hidden';
        card.style.boxShadow = '0 2px 8px rgba(0,0,0,0.02)';
        
        // Header block
        var header = document.createElement('div');
        header.style.padding = '12px 16px';
        header.style.background = 'var(--input-bg)';
        header.style.borderBottom = '1px solid var(--border)';
        header.style.display = 'flex';
        header.style.justifyContent = 'space-between';
        header.style.alignItems = 'center';
        
        var headerTitle = document.createElement('div');
        headerTitle.style.fontWeight = '700';
        headerTitle.style.display = 'flex';
        headerTitle.style.alignItems = 'center';
        headerTitle.style.gap = '8px';
        headerTitle.style.fontSize = '0.85rem';
        headerTitle.style.textTransform = 'uppercase';
        headerTitle.style.color = 'var(--foreground)';
        
        var iconEl = document.createElement('i');
        iconEl.className = section.icon || 'fas fa-folder';
        iconEl.style.color = 'var(--accent)';
        iconEl.style.fontSize = '0.9rem';
        headerTitle.appendChild(iconEl);
        
        var titleText = document.createElement('span');
        titleText.textContent = section.title;
        headerTitle.appendChild(titleText);
        header.appendChild(headerTitle);
        
        var headerActions = document.createElement('div');
        headerActions.style.display = 'flex';
        headerActions.style.gap = '6px';
        
        var btnUp = document.createElement('button');
        btnUp.type = 'button';
        btnUp.className = 'btn btn-sm btn-outline';
        btnUp.style.padding = '4px 8px';
        btnUp.innerHTML = '<i class="fas fa-chevron-up"></i>';
        btnUp.disabled = (secIdx === 0);
        btnUp.onclick = function() {
            moveCategory(secIdx, -1);
        };
        headerActions.appendChild(btnUp);
        
        var btnDown = document.createElement('button');
        btnDown.type = 'button';
        btnDown.className = 'btn btn-sm btn-outline';
        btnDown.style.padding = '4px 8px';
        btnDown.innerHTML = '<i class="fas fa-chevron-down"></i>';
        btnDown.disabled = (secIdx === sidebarConfig.length - 1);
        btnDown.onclick = function() {
            moveCategory(secIdx, 1);
        };
        headerActions.appendChild(btnDown);
        
        header.appendChild(headerActions);
        card.appendChild(header);
        
        // Children items block
        var content = document.createElement('div');
        content.style.padding = '12px';
        content.style.display = 'flex';
        content.style.flexDirection = 'column';
        content.style.gap = '8px';
        
        if (!section.items || section.items.length === 0) {
            var empty = document.createElement('div');
            empty.style.padding = '16px';
            empty.style.textAlign = 'center';
            empty.style.color = 'var(--text-muted)';
            empty.style.fontSize = '0.8rem';
            empty.style.border = '1px dashed var(--border)';
            empty.style.borderRadius = '8px';
            empty.style.background = 'var(--surface)';
            empty.innerHTML = '<i class="fas fa-folder-open" style="margin-right:6px;"></i> No sub-categories in this category';
            content.appendChild(empty);
        } else {
            section.items.forEach(function(itemKey, itemIdx) {
                var row = document.createElement('div');
                row.style.display = 'flex';
                row.style.justifyContent = 'space-between';
                row.style.alignItems = 'center';
                row.style.padding = '8px 12px';
                row.style.background = 'var(--surface)';
                row.style.border = '1px solid var(--border)';
                row.style.borderRadius = '8px';
                
                var label = document.createElement('div');
                label.style.fontSize = '0.85rem';
                label.style.fontWeight = '500';
                label.style.color = 'var(--text-main)';
                label.textContent = subItemLabels[itemKey] || itemKey;
                row.appendChild(label);
                
                var actions = document.createElement('div');
                actions.style.display = 'flex';
                actions.style.alignItems = 'center';
                actions.style.gap = '8px';
                
                // Destination category selection dropdown
                var moveSelect = document.createElement('select');
                moveSelect.style.fontSize = '0.75rem';
                moveSelect.style.padding = '3px 8px';
                moveSelect.style.borderRadius = '6px';
                moveSelect.style.border = '1px solid var(--border)';
                moveSelect.style.background = 'var(--card)';
                moveSelect.style.color = 'var(--foreground)';
                
                var optDefault = document.createElement('option');
                optDefault.value = '';
                optDefault.textContent = 'Move Category to...';
                moveSelect.appendChild(optDefault);
                
                sidebarConfig.forEach(function(destSec) {
                    if (destSec.id !== section.id) {
                        var opt = document.createElement('option');
                        opt.value = destSec.id;
                        opt.textContent = destSec.title;
                        moveSelect.appendChild(opt);
                    }
                });
                
                moveSelect.onchange = function() {
                    var destId = moveSelect.value;
                    if (destId) {
                        moveSubcategoryToCategory(section.id, itemKey, destId);
                    }
                };
                actions.appendChild(moveSelect);
                
                // Move item up/down inside group
                var btnItemUp = document.createElement('button');
                btnItemUp.type = 'button';
                btnItemUp.className = 'btn btn-sm btn-outline';
                btnItemUp.style.padding = '2px 6px';
                btnItemUp.innerHTML = '<i class="fas fa-arrow-up" style="font-size:0.75rem;"></i>';
                btnItemUp.disabled = (itemIdx === 0);
                btnItemUp.onclick = function() {
                    moveSubcategoryInGroup(secIdx, itemIdx, -1);
                };
                actions.appendChild(btnItemUp);
                
                var btnItemDown = document.createElement('button');
                btnItemDown.type = 'button';
                btnItemDown.className = 'btn btn-sm btn-outline';
                btnItemDown.style.padding = '2px 6px';
                btnItemDown.innerHTML = '<i class="fas fa-arrow-down" style="font-size:0.75rem;"></i>';
                btnItemDown.disabled = (itemIdx === section.items.length - 1);
                btnItemDown.onclick = function() {
                    moveSubcategoryInGroup(secIdx, itemIdx, 1);
                };
                actions.appendChild(btnItemDown);
                
                row.appendChild(actions);
                content.appendChild(row);
            });
        }
        
        card.appendChild(content);
        container.appendChild(card);
    });
}

function moveCategory(index, direction) {
    var targetIndex = index + direction;
    if (targetIndex >= 0 && targetIndex < sidebarConfig.length) {
        var temp = sidebarConfig[index];
        sidebarConfig[index] = sidebarConfig[targetIndex];
        sidebarConfig[targetIndex] = temp;
        renderEditor();
    }
}

function moveSubcategoryInGroup(secIdx, itemIdx, direction) {
    var section = sidebarConfig[secIdx];
    var targetIdx = itemIdx + direction;
    if (targetIdx >= 0 && targetIdx < section.items.length) {
        var temp = section.items[itemIdx];
        section.items[itemIdx] = section.items[targetIdx];
        section.items[targetIdx] = temp;
        renderEditor();
    }
}

function moveSubcategoryToCategory(srcSecId, itemKey, destSecId) {
    var srcSec = null;
    var destSec = null;
    
    sidebarConfig.forEach(function(sec) {
        if (sec.id === srcSecId) srcSec = sec;
        if (sec.id === destSecId) destSec = sec;
    });
    
    if (srcSec && destSec) {
        srcSec.items = srcSec.items.filter(function(item) { return item !== itemKey; });
        if (!destSec.items) destSec.items = [];
        destSec.items.push(itemKey);
        renderEditor();
    }
}

function resetSidebarToDefault() {
    if (confirm('Are you sure you want to reset the sidebar menu layout to default?')) {
        sidebarConfig = JSON.parse(JSON.stringify(defaultSidebar));
        renderEditor();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    renderEditor();
});
<?php endif; ?>
</script>

<?php include 'includes/admin_footer.php'; ?>
