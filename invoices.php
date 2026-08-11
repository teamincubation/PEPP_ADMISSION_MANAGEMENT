<?php
require_once 'includes/auth.php';
require_permission('invoices');

/* Defensive include: if any invoice file was not uploaded, show a clear
   checklist instead of an HTTP 500 page. */
$missing_inv_files = [];
foreach (['includes/invoice_helper.php', 'includes/pdf_invoice.php', 'includes/invoice_mailer.php', 'pepp-logo.jpg'] as $__f) {
    if (!file_exists(__DIR__ . '/' . $__f)) $missing_inv_files[] = $__f;
}
if (file_exists(__DIR__ . '/includes/invoice_helper.php')) {
    require_once __DIR__ . '/includes/invoice_helper.php';
}
if (!empty($missing_inv_files) || !function_exists('generate_payment_invoice')) {
    $active_page = 'invoices'; $page_title = 'Invoices'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>'
       . '<strong>Invoice files are missing on the server.</strong> Upload these from the package, then reload:<br>'
       . '<code>' . implode('</code><br><code>', array_map('htmlspecialchars', $missing_inv_files ?: ['includes/invoice_helper.php'])) . '</code>'
       . '<br><br>You can also open <code>system-check.php</code> for a full diagnostic.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

/* Invoices - automatic invoices for every approved payment.
   GST invoices (AXIS LABINC receipts, GSTIN 32AAFCL3813L1ZL) use the GST
   series INV/{FY}/{seq}; all other accounts get clean non-GST invoices
   numbered INV/{DDMMYY}/{seq}. Download as PDF, resend the confirmation
   email, or backfill invoices for payments approved before this feature. */

$success_message = '';
$error_message   = '';

if (!invoices_table_exists($pdo)) {
    $active_page = 'invoices'; $page_title = 'Invoices'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The invoice system is not installed yet. Run <strong>database-update-3.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'resend_email') {
                $id = (int)($_POST['invoice_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT i.*, pa.account_name FROM invoices i LEFT JOIN payment_accounts pa ON pa.id = i.payment_account_id WHERE i.id = ?");
                $stmt->execute([$id]);
                $inv = $stmt->fetch();
                if (!$inv) {
                    $error_message = 'Invoice not found.';
                } elseif (!filter_var($inv['email'], FILTER_VALIDATE_EMAIL)) {
                    $error_message = 'The student has no valid email address.';
                } else {
                    $sent = send_invoice_email($inv, render_invoice_pdf($inv, $inv['account_name'] ?? ''));
                    $pdo->prepare("UPDATE invoices SET email_status = ? WHERE id = ?")->execute([$sent ? 'sent' : 'failed', $id]);
                    log_admin_activity($pdo, $admin_username, 'invoice_email_resent', $inv['invoice_no'] . ' → ' . $inv['email'] . ($sent ? '' : ' (FAILED)'));
                    $success_message = $sent ? "Invoice {$inv['invoice_no']} emailed to {$inv['email']}." : 'Email sending failed - check the mail configuration on the server.';
                    if (!$sent) { $error_message = $success_message; $success_message = ''; }
                }
            } elseif ($action === 'generate_missing') {
                $with_email = isset($_POST['with_email']);
                $made = 0; $skipped = 0;

                // Registration payments without an invoice
                $rows = $pdo->query("
                    SELECT u.id, u.user_id, u.paid_amount, u.payment_account_id, u.payment_mode, u.paid_date
                    FROM users u
                    LEFT JOIN invoices i ON i.source = 'registration' AND i.source_ref = u.id
                    WHERE u.status = 'approved' AND u.paid_amount > 0 AND i.id IS NULL
                ")->fetchAll();
                foreach ($rows as $r) {
                    [$ok] = generate_payment_invoice($pdo, [
                        'source' => 'registration', 'source_ref' => $r['id'], 'user_id' => $r['user_id'],
                        'amount' => $r['paid_amount'], 'account_id' => $r['payment_account_id'],
                        'payment_mode' => $r['payment_mode'] ?: 'Online', 'paid_date' => $r['paid_date'],
                        'generated_by' => $admin_username, 'send_email' => $with_email,
                    ]);
                    $ok ? $made++ : $skipped++;
                }

                // Approved installments without an invoice
                $rows = $pdo->query("
                    SELECT d.id, d.user_id, COALESCE(d.paid_amount, d.amount) AS amt, d.payment_account_id,
                           d.payment_mode, d.paid_date, d.instalment_number
                    FROM instalment_details d
                    LEFT JOIN invoices i ON i.source = 'installment' AND i.source_ref = d.id
                    WHERE d.status IN ('approved','paid') AND i.id IS NULL
                ")->fetchAll();
                foreach ($rows as $r) {
                    [$ok] = generate_payment_invoice($pdo, [
                        'source' => 'installment', 'source_ref' => $r['id'], 'user_id' => $r['user_id'],
                        'amount' => $r['amt'], 'account_id' => $r['payment_account_id'],
                        'payment_mode' => $r['payment_mode'] ?: 'Online', 'paid_date' => $r['paid_date'],
                        'instalment_number' => $r['instalment_number'],
                        'generated_by' => $admin_username, 'send_email' => $with_email,
                    ]);
                    $ok ? $made++ : $skipped++;
                }

                log_admin_activity($pdo, $admin_username, 'invoices_backfilled', "Generated {$made} missing invoice(s)" . ($with_email ? ' with emails' : ''));
                $success_message = "Backfill complete: {$made} invoice(s) generated" . ($skipped ? ", {$skipped} skipped" : '') . '.';
            }
        } catch (Exception $e) {
            error_log('Invoices page: ' . $e->getMessage());
            $error_message = 'Database error while processing the request.';
        }
    }
}

/* ── Filters + list ─────────────────────────────────────────────── */
$f_type = trim($_GET['type'] ?? '');
$f_from = trim($_GET['from'] ?? '');
$f_to   = trim($_GET['to'] ?? '');
$f_q    = trim($_GET['q'] ?? '');

$where = ['1=1']; $params = [];
if (in_array($f_type, ['gst', 'non_gst'], true)) { $where[] = "i.invoice_type = ?"; $params[] = $f_type; }
if ($f_from !== '') { $where[] = "i.paid_date >= ?"; $params[] = $f_from; }
if ($f_to   !== '') { $where[] = "i.paid_date <= ?"; $params[] = $f_to; }
if ($f_q    !== '') {
    $where[] = "(i.invoice_no LIKE ? OR i.student_name LIKE ? OR i.user_id LIKE ? OR i.email LIKE ?)";
    $like = "%{$f_q}%"; array_push($params, $like, $like, $like, $like);
}
$where_sql = implode(' AND ', $where);

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$total = 0; $invoices = [];
$stats = ['count' => 0, 'gst_count' => 0, 'gross' => 0, 'tax' => 0];

try {
    $stats = $pdo->query("
        SELECT COUNT(*) AS count,
               SUM(invoice_type = 'gst') AS gst_count,
               COALESCE(SUM(gross_amount), 0) AS gross,
               COALESCE(SUM(cgst_amount + sgst_amount), 0) AS tax
        FROM invoices
    ")->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i WHERE $where_sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $per_page;
    $stmt = $pdo->prepare("
        SELECT i.*, pa.account_name
        FROM invoices i
        LEFT JOIN payment_accounts pa ON pa.id = i.payment_account_id
        WHERE $where_sql
        ORDER BY i.created_at DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Invoice list: ' . $e->getMessage());
    $error_message = $error_message ?: 'Could not load invoices.';
}

$total_pages = max(1, (int)ceil($total / $per_page));
function iqs($overrides = []) {
    $q = array_merge($_GET, $overrides);
    unset($q['logout']);
    return '?' . http_build_query($q);
}

$active_page = 'invoices';
$page_title  = 'Invoices';
$page_sub    = 'Automatic invoices for every approved payment';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Total Invoices</span><span class="stat-icon violet"><i class="fas fa-file-invoice"></i></span></div>
        <div class="stat-value"><?php echo number_format((int)$stats['count']); ?></div>
        <div class="stat-hint"><?php echo (int)$stats['gst_count']; ?> GST · <?php echo (int)$stats['count'] - (int)$stats['gst_count']; ?> non-GST</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Invoiced Amount</span><span class="stat-icon green"><i class="fas fa-indian-rupee-sign"></i></span></div>
        <div class="stat-value">₹<?php echo number_format((float)$stats['gross'], 0); ?></div>
        <div class="stat-hint">All invoices, gross</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Total GST</span><span class="stat-icon amber"><i class="fas fa-landmark"></i></span></div>
        <div class="stat-value">₹<?php echo number_format((float)$stats['tax'], 0); ?></div>
        <div class="stat-hint">CGST + SGST on GST invoices</div>
    </div>
</div>

<!-- ── FILTERS + BACKFILL ── -->
<div class="panel">
    <div class="panel-body">
        <form method="GET" class="filter-bar" style="margin-bottom:12px;">
            <div class="field">
                <label>Type</label>
                <select name="type">
                    <option value="">All</option>
                    <option value="gst"     <?php echo $f_type === 'gst' ? 'selected' : ''; ?>>GST (AXIS LABINC)</option>
                    <option value="non_gst" <?php echo $f_type === 'non_gst' ? 'selected' : ''; ?>>Non-GST</option>
                </select>
            </div>
            <div class="field"><label>From</label><input type="date" name="from" value="<?php echo e($f_from); ?>"></div>
            <div class="field"><label>To</label><input type="date" name="to" value="<?php echo e($f_to); ?>"></div>
            <div class="field grow-2"><label>Search</label><input type="text" name="q" value="<?php echo e($f_q); ?>" placeholder="Invoice no, student, ID or email"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="invoices.php" class="btn btn-outline">Reset</a>
        </form>
        <form method="POST" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; border-top:1px dashed var(--border); padding-top:12px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="generate_missing">
            <span class="cell-sub"><i class="fas fa-wand-magic-sparkles"></i> Create invoices for payments approved before this feature:</span>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:.78rem;font-weight:600;cursor:pointer;">
                <input type="checkbox" name="with_email" value="1" style="accent-color:var(--accent);"> also email students
            </label>
            <button type="submit" class="btn btn-sm btn-soft-violet" onclick="return confirm('Generate invoices for all approved payments that do not have one yet?');">
                <i class="fas fa-wand-magic-sparkles"></i> Generate missing invoices
            </button>
        </form>
    </div>
</div>

<!-- ── LIST ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-file-invoice"></i></span>
        <h2>Invoices (<?php echo number_format($total); ?>)</h2>
        <div class="head-right"><span class="badge gray">Series configured in <a href="settings.php">Settings → Invoice Settings</a></span></div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($invoices)): ?>
            <div class="empty-state"><i class="fas fa-file-invoice"></i><p>No invoices yet - they are created automatically when payments are approved.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Invoice No.</th><th>Student</th><th>Payment</th><th>Amount</th><th>GST</th><th>Email</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($inv['invoice_no']); ?></div>
                        <div class="cell-sub"><?php echo $inv['paid_date'] ? date('d M Y', strtotime($inv['paid_date'])) : '-'; ?> · <span class="badge <?php echo $inv['invoice_type'] === 'gst' ? 'amber' : 'blue'; ?>" style="font-size:.62rem;"><?php echo $inv['invoice_type'] === 'gst' ? 'GST' : 'Non-GST'; ?></span></div>
                    </td>
                    <td>
                        <div class="cell-main"><?php echo e($inv['student_name']); ?></div>
                        <div class="cell-sub"><?php echo e($inv['user_id']); ?> · <?php echo e(mb_strimwidth($inv['course'], 0, 32, '…')); ?></div>
                    </td>
                    <td>
                        <div style="font-size:.8rem;font-weight:600;"><?php echo $inv['source'] === 'installment' ? 'Installment #' . (int)$inv['instalment_number'] : 'Registration'; ?></div>
                        <div class="cell-sub"><?php echo e($inv['account_name'] ?: '-'); ?> · <?php echo e($inv['payment_mode'] ?: '-'); ?></div>
                    </td>
                    <td class="cell-main">₹<?php echo number_format((float)$inv['gross_amount'], 0); ?></td>
                    <td>
                        <?php if ($inv['invoice_type'] === 'gst'): ?>
                            <div class="cell-sub">₹<?php echo number_format((float)$inv['cgst_amount'] + (float)$inv['sgst_amount'], 2); ?></div>
                        <?php else: ?><span class="cell-sub">-</span><?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $inv['email_status'] === 'sent' ? 'green' : ($inv['email_status'] === 'failed' ? 'red' : 'gray'); ?>">
                            <?php echo ucfirst($inv['email_status']); ?>
                        </span>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a class="btn btn-sm btn-outline" href="invoice-pdf.php?id=<?php echo (int)$inv['id']; ?>&view=1" target="_blank" title="View Invoice"><i class="fas fa-eye"></i> View</a>
                        <a class="btn btn-sm btn-primary" href="invoice-pdf.php?id=<?php echo (int)$inv['id']; ?>" title="Download PDF"><i class="fas fa-file-pdf"></i> PDF</a>
                        <!-- Resend email form with verified parameters -->
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="resend_email">
                            <input type="hidden" name="invoice_id" value="<?php echo (int)$inv['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-soft-blue" title="Send / resend email"><i class="fas fa-paper-plane"></i></button>
                        </form>
                        <a class="btn btn-sm btn-outline" href="student-details.php?user_id=<?php echo urlencode($inv['user_id']); ?>" title="Student profile"><i class="fas fa-user"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a class="page-link" href="<?php echo e(iqs(['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
            <?php for ($p = max(1, $page - 3); $p <= min($total_pages, $page + 3); $p++): ?>
                <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo e(iqs(['page' => $p])); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?><a class="page-link" href="<?php echo e(iqs(['page' => $page + 1])); ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
