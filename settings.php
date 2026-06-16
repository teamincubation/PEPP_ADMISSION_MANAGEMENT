<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('settings');

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
                    'user_rejection_wp_message', 'reg_entry_cancelling_message'
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
                if ($name === '') {
                    $error_message = 'Account name is required.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO payment_accounts (account_name, account_type, account_details, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
                    $stmt->execute([$name, $type, $details]);
                    $success_message = "Payment account \"{$name}\" added.";
                }
            } elseif ($action === 'toggle_account') {
                $id = (int)($_POST['account_id'] ?? 0);
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
                            $pdo->prepare("UPDATE admins SET username = ?, password_hash = ? WHERE id = ?")
                                ->execute([$new_user, password_hash($new_pass, PASSWORD_DEFAULT), $me['id']]);
                            log_admin_activity($pdo, $new_user, 'password_changed', 'Changed own credentials' . ($new_user !== $admin_username ? " (username was {$admin_username})" : ''));
                            $_SESSION['admin_username'] = $new_user;
                            $admin_username = $new_user;
                            $success_message = 'Your credentials were updated. Use them on your next sign-in.';
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
            <code>{paid_amount}</code> (registration payment) <code>{total_fee}</code> (net payable)
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
        <form method="POST" class="filter-bar" style="margin-bottom:18px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_payment_account">
            <div class="field grow-2"><label>Account name</label><input type="text" name="account_name" placeholder="e.g. PEPP HDFC Current A/c" required></div>
            <div class="field"><label>Type</label>
                <select name="account_type"><?php foreach (['Bank','UPI','Digital Wallet','Other'] as $t) echo "<option>$t</option>"; ?></select></div>
            <div class="field grow-2"><label>Details (optional)</label><input type="text" name="account_details" placeholder="UPI ID / account no."></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add</button>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Account</th><th>Type</th><th>Details</th><th>Status</th><th style="text-align:right;"></th></tr></thead>
                <tbody>
                <?php foreach ($payment_accounts as $a): ?>
                    <tr>
                        <td class="cell-main"><?php echo e($a['account_name']); ?></td>
                        <td><span class="badge blue"><?php echo e($a['account_type']); ?></span></td>
                        <td class="cell-sub"><?php echo e($a['account_details'] ?: '-'); ?></td>
                        <td><span class="badge <?php echo $a['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                        <td style="text-align:right;">
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_account">
                                <input type="hidden" name="account_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $a['status'] === 'active' ? 'btn-soft-amber' : 'btn-soft-green'; ?>">
                                    <i class="fas fa-power-off"></i> <?php echo $a['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($payment_accounts)): ?>
                    <tr><td colspan="5"><div class="empty-state" style="padding:24px;"><p>No payment accounts yet.</p></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="alert alert-info" style="margin-top:14px; margin-bottom:0;">
            <i class="fas fa-circle-info"></i>
            <span>Active accounts appear in the approval modal and payment review dropdowns.</span>
        </div>
    </div>
</div>

<!-- ── ALUMNI DATABASE LINK ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-user-graduate"></i></span><h2>Alumni Database</h2>
        <div class="head-right"><a class="btn btn-sm btn-primary" href="alumni-database.php"><i class="fas fa-arrow-right"></i> Open Alumni Database</a></div>
    </div>
    <div class="panel-body"><div class="cell-sub">Manage past students (add or bulk-import) used to verify PEPPian alumni accounts for the referral program.</div></div>
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

<?php include 'includes/admin_footer.php'; ?>
