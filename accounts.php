<?php
require_once 'includes/auth.php';
require_permission('accounts');

/* Accounts & Expenses (CRM).
   Record administrative expenses against a payment account, see faculty
   payments automatically reflected as outgoings, and view per-account balance
   = revenue collected − (expenses + faculty payments). Filter and export. */

$success_message = ''; $error_message = '';

function accounts_ready($pdo) {
    try { return (bool)$pdo->query("SHOW TABLES LIKE 'expenses'")->fetchColumn(); }
    catch (Exception $e) { return false; }
}
if (!accounts_ready($pdo)) {
    $active_page = 'accounts'; $page_title = 'Accounts & Expenses'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The Accounts module is not installed yet. Run <strong>database-update-7.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_expense') {
                $purpose = trim($_POST['purpose'] ?? '');
                $amount = (float)($_POST['amount'] ?? 0);
                if ($purpose === '' || $amount <= 0) {
                    $error_message = 'Purpose and a positive amount are required.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO expenses (purpose, expense_type, amount, remarks, payment_account_id, spent_date, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
                    $stmt->execute([
                        $purpose, trim($_POST['expense_type'] ?? '') ?: null, $amount,
                        trim($_POST['remarks'] ?? '') ?: null,
                        ((int)($_POST['payment_account_id'] ?? 0)) ?: null,
                        $_POST['spent_date'] ?: date('Y-m-d'), $admin_username
                    ]);
                    log_admin_activity($pdo, $admin_username, 'expense_added', "Expense: {$purpose} - Rs. " . number_format($amount, 2));
                    $success_message = 'Expense recorded.';
                }
            } elseif ($action === 'delete_expense') {
                if (!can_delete()) { $error_message = 'Only the Super Admin can delete an expense.'; }
                else {
                    $id = (int)($_POST['expense_id'] ?? 0);
                    $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
                    log_admin_activity($pdo, $admin_username, 'expense_deleted', "Deleted expense #{$id}");
                    $success_message = 'Expense deleted.';
                }
            }
        } catch (Exception $e) {
            error_log('Accounts: ' . $e->getMessage());
            $error_message = 'Database error while saving.';
        }
    }
}

/* ── Revenue collected per account (registration + approved installments) ── */
function revenue_by_account($pdo) {
    $map = [];
    try {
        foreach ($pdo->query("SELECT payment_account_id AS aid, COALESCE(SUM(paid_amount),0) AS amt FROM users WHERE status='approved' AND paid_amount>0 GROUP BY payment_account_id") as $r) {
            $map[(int)$r['aid']] = ($map[(int)$r['aid']] ?? 0) + (float)$r['amt'];
        }
        foreach ($pdo->query("SELECT payment_account_id AS aid, COALESCE(SUM(COALESCE(paid_amount,amount)),0) AS amt FROM instalment_details WHERE status IN ('approved','paid') GROUP BY payment_account_id") as $r) {
            $map[(int)$r['aid']] = ($map[(int)$r['aid']] ?? 0) + (float)$r['amt'];
        }
    } catch (Exception $e) {}
    return $map;
}
function expenses_by_account($pdo) {
    $map = [];
    try { foreach ($pdo->query("SELECT payment_account_id AS aid, COALESCE(SUM(amount),0) AS amt FROM expenses GROUP BY payment_account_id") as $r) $map[(int)$r['aid']] = (float)$r['amt']; }
    catch (Exception $e) {}
    return $map;
}
function faculty_pay_by_account($pdo) {
    $map = [];
    try {
        if ($pdo->query("SHOW TABLES LIKE 'faculty_payments'")->fetchColumn()) {
            foreach ($pdo->query("SELECT payment_account_id AS aid, COALESCE(SUM(amount),0) AS amt FROM faculty_payments GROUP BY payment_account_id") as $r) $map[(int)$r['aid']] = (float)$r['amt'];
        }
    } catch (Exception $e) {}
    return $map;
}
function referral_pay_by_account($pdo) {
    $map = [];
    try {
        if ($pdo->query("SHOW TABLES LIKE 'referral_payouts'")->fetchColumn()) {
            foreach ($pdo->query("SELECT payment_account_id AS aid, COALESCE(SUM(amount),0) AS amt FROM referral_payouts GROUP BY payment_account_id") as $r) $map[(int)$r['aid']] = (float)$r['amt'];
        }
    } catch (Exception $e) {}
    return $map;
}

$rev = revenue_by_account($pdo);
$exp = expenses_by_account($pdo);
$facpay = faculty_pay_by_account($pdo);
$refpay = referral_pay_by_account($pdo);

/* ── Filters for the expense ledger ─────────────────────────────── */
$f_type = trim($_GET['type'] ?? '');
$f_acc  = trim($_GET['account'] ?? '');
$f_from = trim($_GET['from'] ?? '');
$f_to   = trim($_GET['to'] ?? '');
$f_q    = trim($_GET['q'] ?? '');

/* ── Combined ledger: expenses + faculty payments (both are outgoings) ── */
function build_ledger($pdo, $f_type, $f_acc, $f_from, $f_to, $f_q) {
    $rows = [];
    // Expenses
    $w = ['1=1']; $p = [];
    if ($f_type !== '') { $w[] = "e.expense_type = ?"; $p[] = $f_type; }
    if ($f_acc  !== '') { $w[] = "e.payment_account_id = ?"; $p[] = (int)$f_acc; }
    if ($f_from !== '') { $w[] = "e.spent_date >= ?"; $p[] = $f_from; }
    if ($f_to   !== '') { $w[] = "e.spent_date <= ?"; $p[] = $f_to; }
    if ($f_q    !== '') { $w[] = "(e.purpose LIKE ? OR e.remarks LIKE ?)"; $p[] = "%$f_q%"; $p[] = "%$f_q%"; }
    $stmt = $pdo->prepare("SELECT e.*, pa.account_name FROM expenses e LEFT JOIN payment_accounts pa ON pa.id = e.payment_account_id WHERE " . implode(' AND ', $w) . " ORDER BY e.spent_date DESC, e.id DESC");
    $stmt->execute($p);
    foreach ($stmt->fetchAll() as $e) {
        $rows[] = ['kind' => 'Expense', 'date' => $e['spent_date'], 'purpose' => $e['purpose'],
                   'type' => $e['expense_type'] ?: '-', 'amount' => (float)$e['amount'], 'account' => $e['account_name'] ?: '-',
                   'remarks' => $e['remarks'] ?: '', 'by' => $e['created_by'], 'id' => (int)$e['id'], 'deletable' => true];
    }
    // Faculty payments (only when not filtering by an expense type)
    if ($f_type === '') {
        try {
            if ($pdo->query("SHOW TABLES LIKE 'faculty_payments'")->fetchColumn()) {
                $w2 = ['1=1']; $p2 = [];
                if ($f_acc  !== '') { $w2[] = "fp.payment_account_id = ?"; $p2[] = (int)$f_acc; }
                if ($f_from !== '') { $w2[] = "fp.paid_date >= ?"; $p2[] = $f_from; }
                if ($f_to   !== '') { $w2[] = "fp.paid_date <= ?"; $p2[] = $f_to; }
                if ($f_q    !== '') { $w2[] = "(f.name LIKE ? OR fp.remarks LIKE ?)"; $p2[] = "%$f_q%"; $p2[] = "%$f_q%"; }
                $stmt = $pdo->prepare("SELECT fp.*, f.name AS faculty_name, pa.account_name FROM faculty_payments fp LEFT JOIN faculties f ON f.id = fp.faculty_id LEFT JOIN payment_accounts pa ON pa.id = fp.payment_account_id WHERE " . implode(' AND ', $w2) . " ORDER BY fp.paid_date DESC, fp.id DESC");
                $stmt->execute($p2);
                foreach ($stmt->fetchAll() as $fp) {
                    $rows[] = ['kind' => 'Faculty Payment', 'date' => $fp['paid_date'], 'purpose' => 'Faculty payment - ' . ($fp['faculty_name'] ?: '?'),
                               'type' => 'Freelance & Consultant Payments', 'amount' => (float)$fp['amount'], 'account' => $fp['account_name'] ?: '-',
                               'remarks' => $fp['remarks'] ?: '', 'by' => $fp['created_by'], 'id' => (int)$fp['id'], 'deletable' => false];
                }
            }
        } catch (Exception $e) {}
    }
    usort($rows, function ($a, $b) { return strtotime($b['date'] ?: '1970-01-01') <=> strtotime($a['date'] ?: '1970-01-01'); });
    return $rows;
}

/* ── Export ─────────────────────────────────────────────────────── */
if (isset($_GET['export'])) {
    $rows = build_ledger($pdo, $f_type, $f_acc, $f_from, $f_to, $f_q);
    log_admin_activity($pdo, $admin_username, 'data_export', 'Exported expense ledger (' . count($rows) . ' rows)');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pepp-expenses-' . date('Y-m-d-Hi') . '.csv"');
    $out = fopen('php://output', 'w'); fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Kind', 'Purpose', 'Expense Type', 'Amount', 'Payment Account', 'Remarks', 'Recorded By']);
    foreach ($rows as $r) fputcsv($out, [$r['date'], $r['kind'], $r['purpose'], $r['type'], $r['amount'], $r['account'], $r['remarks'], $r['by']]);
    fclose($out); exit();
}

$ledger = build_ledger($pdo, $f_type, $f_acc, $f_from, $f_to, $f_q);

/* ── Lookups ───────────────────────────────────────────────────── */
$accounts = []; $types = [];
try {
    $accounts = $pdo->query("SELECT * FROM payment_accounts ORDER BY status='active' DESC, account_name")->fetchAll();
    $types = $pdo->query("SELECT name FROM expense_types WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$total_rev = array_sum($rev);
$total_exp = array_sum($exp);
$total_fac = array_sum($facpay);
$total_ref = array_sum($refpay);
$total_out = $total_exp + $total_fac + $total_ref;
$net_balance = $total_rev - $total_out;

function eqs($o = []) { $q = array_merge($_GET, $o); unset($q['logout'], $q['export']); return '?' . http_build_query($q); }

$active_page = 'accounts';
$page_title  = 'Accounts & Expenses';
$page_sub    = 'Administrative spending & account balances';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Revenue Collected</span><span class="stat-icon green"><i class="fas fa-arrow-down"></i></span></div><div class="stat-value">₹<?php echo number_format($total_rev, 0); ?></div><div class="stat-hint">All accounts</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Expenses</span><span class="stat-icon amber"><i class="fas fa-arrow-up"></i></span></div><div class="stat-value">₹<?php echo number_format($total_exp, 0); ?></div><div class="stat-hint">Admin expenses</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Faculty Payments</span><span class="stat-icon violet"><i class="fas fa-chalkboard-user"></i></span></div><div class="stat-value">₹<?php echo number_format($total_fac, 0); ?></div><div class="stat-hint">Auto-included outgoings</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Referral Payouts</span><span class="stat-icon amber"><i class="fas fa-gift"></i></span></div><div class="stat-value">₹<?php echo number_format($total_ref, 0); ?></div><div class="stat-hint">Paid to referees</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Net Balance</span><span class="stat-icon <?php echo $net_balance >= 0 ? 'green' : 'red'; ?>"><i class="fas fa-scale-balanced"></i></span></div><div class="stat-value">₹<?php echo number_format($net_balance, 0); ?></div><div class="stat-hint">Revenue − all outgoings</div></div>
</div>

<!-- Per-account balances -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-building-columns"></i></span><h2>Account Balances</h2></div>
    <div class="panel-body flush table-wrap">
        <table class="data-table">
            <thead><tr><th>Account</th><th>Revenue In</th><th>Expenses</th><th>Faculty Paid</th><th>Referral Paid</th><th>Balance</th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): $aid = (int)$a['id'];
                $r = $rev[$aid] ?? 0; $e = $exp[$aid] ?? 0; $fp = $facpay[$aid] ?? 0; $rfp = $refpay[$aid] ?? 0; $bal = $r - $e - $fp - $rfp; ?>
                <tr>
                    <td><div class="cell-main"><?php echo e($a['account_name']); ?></div><div class="cell-sub"><?php echo e($a['account_type']); ?></div></td>
                    <td>₹<?php echo number_format($r, 0); ?></td>
                    <td>₹<?php echo number_format($e, 0); ?></td>
                    <td>₹<?php echo number_format($fp, 0); ?></td>
                    <td>₹<?php echo number_format($rfp, 0); ?></td>
                    <td><span class="badge <?php echo $bal >= 0 ? 'green' : 'red'; ?>">₹<?php echo number_format($bal, 0); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add expense + filters -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-receipt"></i></span><h2>Record Expense</h2></div>
    <div class="panel-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_expense">
            <div class="form-grid">
                <div class="field"><label>Purpose <span class="req">*</span></label><input type="text" name="purpose" required></div>
                <div class="field"><label>Expense Type</label>
                    <select name="expense_type"><option value="">-</option><?php foreach ($types as $t): ?><option value="<?php echo e($t); ?>"><?php echo e($t); ?></option><?php endforeach; ?></select>
                    <div class="help">Manage types in <a href="settings.php">Settings → Expense Types</a></div></div>
                <div class="field"><label>Amount (₹) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="amount" required></div>
                <div class="field"><label>Payment Account</label><select name="payment_account_id"><option value="">-</option><?php foreach ($accounts as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['account_name']); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Spent Date</label><input type="date" name="spent_date" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="field full"><label>Remarks</label><input type="text" name="remarks" placeholder="Optional"></div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:12px;"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Expense</button></div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-list"></i></span><h2>Expense Ledger (<?php echo count($ledger); ?>)</h2></div>
    <div class="panel-body" style="border-bottom:1px solid var(--border);">
        <form method="GET" class="filter-bar">
            <div class="field"><label>Type</label><select name="type"><option value="">All</option><?php foreach ($types as $t): ?><option value="<?php echo e($t); ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo e($t); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Account</label><select name="account"><option value="">All</option><?php foreach ($accounts as $a): ?><option value="<?php echo (int)$a['id']; ?>" <?php echo $f_acc === (string)$a['id'] ? 'selected' : ''; ?>><?php echo e($a['account_name']); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>From</label><input type="date" name="from" value="<?php echo e($f_from); ?>"></div>
            <div class="field"><label>To</label><input type="date" name="to" value="<?php echo e($f_to); ?>"></div>
            <div class="field grow-2"><label>Search</label><input type="text" name="q" value="<?php echo e($f_q); ?>" placeholder="Purpose or remarks"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="accounts.php" class="btn btn-outline">Reset</a>
            <a href="<?php echo e(eqs(['export' => 1])); ?>" class="btn btn-soft-green"><i class="fas fa-file-excel"></i> Export</a>
        </form>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($ledger)): ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>No expenses match these filters.</p></div>
        <?php else:
            $sum = 0; foreach ($ledger as $r) $sum += $r['amount']; ?>
        <table class="data-table">
            <thead><tr><th>Date</th><th>Purpose</th><th>Type</th><th>Account</th><th>Amount</th><th>By</th><th style="text-align:right;"></th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $r): ?>
                <tr>
                    <td class="cell-sub"><?php echo $r['date'] ? date('d M Y', strtotime($r['date'])) : '-'; ?></td>
                    <td><div class="cell-main"><?php echo e($r['purpose']); ?></div><?php if ($r['remarks']): ?><div class="cell-sub"><?php echo e($r['remarks']); ?></div><?php endif; ?>
                        <?php if ($r['kind'] !== 'Expense'): ?><span class="badge violet" style="font-size:.6rem;"><?php echo e($r['kind']); ?></span><?php endif; ?></td>
                    <td class="cell-sub"><?php echo e($r['type']); ?></td>
                    <td class="cell-sub"><?php echo e($r['account']); ?></td>
                    <td class="cell-main">₹<?php echo number_format($r['amount'], 0); ?></td>
                    <td class="cell-sub"><?php echo e($r['by'] ?: '-'); ?></td>
                    <td style="text-align:right;">
                        <?php if ($r['deletable'] && can_delete()): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this expense?');">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="expense_id" value="<?php echo (int)$r['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-soft-red" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr style="background:var(--card);"><td colspan="4" class="cell-main" style="text-align:right;">Total (filtered)</td><td class="cell-main">₹<?php echo number_format($sum, 0); ?></td><td colspan="2"></td></tr>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
