<?php
require_once 'includes/auth.php';
require_permission('faculties');

/* Faculties (Academics).
   Manage faculty members with per-session-type hourly rates, see their
   schedule and payment summary (earned from completed sessions vs paid),
   record payments from a payment account, and generate / email / download
   a statement. */

$success_message = ''; $error_message = '';

function faculties_ready($pdo) {
    try { return (bool)$pdo->query("SHOW TABLES LIKE 'faculties'")->fetchColumn(); }
    catch (Exception $e) { return false; }
}
if (!faculties_ready($pdo)) {
    $active_page = 'faculties'; $page_title = 'Faculties'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The Faculties module is not installed yet. Run <strong>database-update-7.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

$RATE_FIELDS = ['rate_live' => 'Live Session', 'rate_qpd' => 'QPD', 'rate_recorded' => 'Recorded', 'rate_offline' => 'Offline Session'];
$TYPE_RATE   = ['live' => 'rate_live', 'qpd' => 'rate_qpd', 'recorded' => 'rate_recorded', 'offline' => 'rate_offline'];

$sessions_ready = false;
try { $sessions_ready = (bool)$pdo->query("SHOW TABLES LIKE 'sessions'")->fetchColumn(); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_faculty' || $action === 'edit_faculty') {
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                
                if ($action === 'edit_faculty' && is_credential_restricted('faculties')) {
                    $fid = (int)($_POST['faculty_id'] ?? 0);
                    $stmt = $pdo->prepare("SELECT mobile, email FROM faculties WHERE id = ?");
                    $stmt->execute([$fid]);
                    $orig = $stmt->fetch();
                    if ($orig) {
                        if (strpos($mobile, '*') !== false || preg_match('/^[x\s@.]+$/i', $mobile) || strpos($mobile, '<span') !== false) {
                            $mobile = $orig['mobile'];
                        }
                        if (strpos($email, '*') !== false || preg_match('/^[x\s@.]+$/i', $email) || strpos($email, '<span') !== false) {
                            $email = $orig['email'];
                        }
                    }
                }
                
                if ($name === '') {
                    $error_message = 'Faculty name is required.';
                } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error_message = 'Please enter a valid email address (or leave blank).';
                } else {
                    $vals = [
                        $name, $mobile, $email ?: null,
                        (float)($_POST['rate_live'] ?? 0), (float)($_POST['rate_qpd'] ?? 0),
                        (float)($_POST['rate_recorded'] ?? 0), (float)($_POST['rate_offline'] ?? 0),
                        trim($_POST['academic_year'] ?? '') ?: null,
                        in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active',
                    ];
                    if ($action === 'add_faculty') {
                        $stmt = $pdo->prepare("INSERT INTO faculties (name, mobile, email, rate_live, rate_qpd, rate_recorded, rate_offline, academic_year, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
                        $stmt->execute(array_merge($vals, [$admin_username]));
                        log_admin_activity($pdo, $admin_username, 'faculty_added', "Added faculty: {$name}");
                        $success_message = 'Faculty added.';
                    } else {
                        $id = (int)($_POST['faculty_id'] ?? 0);
                        $stmt = $pdo->prepare("UPDATE faculties SET name=?, mobile=?, email=?, rate_live=?, rate_qpd=?, rate_recorded=?, rate_offline=?, academic_year=?, status=? WHERE id=?");
                        $stmt->execute(array_merge($vals, [$id]));
                        log_admin_activity($pdo, $admin_username, 'faculty_updated', "Updated faculty #{$id}: {$name}");
                        $success_message = 'Faculty updated.';
                    }
                }
            } elseif ($action === 'add_payment') {
                $fid = (int)($_POST['faculty_id'] ?? 0);
                $amt = (float)($_POST['amount'] ?? 0);
                if ($fid && $amt > 0) {
                    $stmt = $pdo->prepare("INSERT INTO faculty_payments (faculty_id, amount, payment_account_id, paid_date, remarks, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())");
                    $stmt->execute([$fid, $amt, ((int)($_POST['payment_account_id'] ?? 0)) ?: null,
                        $_POST['paid_date'] ?: date('Y-m-d'), trim($_POST['remarks'] ?? '') ?: null, $admin_username]);
                    log_admin_activity($pdo, $admin_username, 'faculty_paid', "Paid Rs. " . number_format($amt, 2) . " to faculty #{$fid}");
                    $success_message = 'Payment recorded.';
                } else {
                    $error_message = 'A faculty and a positive amount are required.';
                }
            } elseif ($action === 'delete_faculty') {
                if (!can_delete()) { $error_message = 'Only the Super Admin can delete a faculty.'; }
                else {
                    $id = (int)($_POST['faculty_id'] ?? 0);
                    $pdo->prepare("DELETE FROM faculty_payments WHERE faculty_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM faculties WHERE id = ?")->execute([$id]);
                    log_admin_activity($pdo, $admin_username, 'faculty_deleted', "Deleted faculty #{$id}");
                    $success_message = 'Faculty deleted.';
                }
            }
        } catch (Exception $e) {
            error_log('Faculties: ' . $e->getMessage());
            $error_message = 'Database error while saving.';
        }
    }
}

/** Earned amount for a faculty from COMPLETED sessions, by their per-type rate. */
function faculty_earned($pdo, $f, $sessions_ready, $TYPE_RATE) {
    if (!$sessions_ready) return ['earned' => 0.0, 'completed' => 0, 'pending' => 0];
    try {
        $stmt = $pdo->prepare("SELECT session_type, status, duration_hours FROM sessions WHERE faculty_id = ?");
        $stmt->execute([$f['id']]);
        $earned = 0.0; $completed = 0; $pending = 0;
        foreach ($stmt->fetchAll() as $s) {
            if ($s['status'] === 'completed') {
                $completed++;
                $rateField = $TYPE_RATE[$s['session_type']] ?? 'rate_live';
                $earned += (float)$f[$rateField] * (float)$s['duration_hours'];
            } elseif ($s['status'] === 'scheduled') {
                $pending++;
            }
        }
        return ['earned' => $earned, 'completed' => $completed, 'pending' => $pending];
    } catch (Exception $e) { return ['earned' => 0.0, 'completed' => 0, 'pending' => 0]; }
}
function faculty_paid_total($pdo, $fid) {
    try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM faculty_payments WHERE faculty_id = ?"); $stmt->execute([$fid]); return (float)$stmt->fetchColumn(); }
    catch (Exception $e) { return 0.0; }
}

/* ── Single-faculty detail view ─────────────────────────────────── */
$view_id = (int)($_GET['view'] ?? 0);
$detail = null; $detail_sessions = []; $detail_payments = []; $detail_calc = null;
if ($view_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM faculties WHERE id = ?"); $stmt->execute([$view_id]);
        $detail = $stmt->fetch();
        if ($detail) {
            $detail_calc = faculty_earned($pdo, $detail, $sessions_ready, $TYPE_RATE);
            $detail_calc['paid'] = faculty_paid_total($pdo, $view_id);
            $detail_calc['due'] = max(0, $detail_calc['earned'] - $detail_calc['paid']);
            if ($sessions_ready) {
                $stmt = $pdo->prepare("SELECT * FROM sessions WHERE faculty_id = ? ORDER BY session_datetime DESC LIMIT 100");
                $stmt->execute([$view_id]); $detail_sessions = $stmt->fetchAll();
            }
            $stmt = $pdo->prepare("SELECT fp.*, pa.account_name FROM faculty_payments fp LEFT JOIN payment_accounts pa ON pa.id = fp.payment_account_id WHERE fp.faculty_id = ? ORDER BY fp.paid_date DESC, fp.id DESC");
            $stmt->execute([$view_id]); $detail_payments = $stmt->fetchAll();
        }
    } catch (Exception $e) { error_log('Faculty detail: ' . $e->getMessage()); }
}

/* ── List data ──────────────────────────────────────────────────── */
$faculties = [];
$payment_accounts = [];
$academic_years = [];
try {
    $faculties = $pdo->query("SELECT * FROM faculties ORDER BY status='active' DESC, name ASC")->fetchAll();
    $payment_accounts = $pdo->query("SELECT * FROM payment_accounts WHERE status='active' ORDER BY account_name")->fetchAll();
    $academic_years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { error_log('Faculties list: ' . $e->getMessage()); }

$active_page = 'faculties';
$page_title  = $detail ? $detail['name'] : 'Faculties';
$page_sub    = $detail ? 'Faculty schedule & payments' : 'Manage faculty, rates & payments';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<?php if ($detail): /* ===== DETAIL VIEW ===== */ ?>
<div style="margin-bottom:16px;"><a href="faculties.php" class="btn btn-sm btn-outline"><i class="fas fa-arrow-left"></i> Back to Faculties</a></div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Completed Schedules</span><span class="stat-icon green"><i class="fas fa-circle-check"></i></span></div><div class="stat-value"><?php echo (int)$detail_calc['completed']; ?></div><div class="stat-hint"><?php echo (int)$detail_calc['pending']; ?> pending</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Earned</span><span class="stat-icon violet"><i class="fas fa-indian-rupee-sign"></i></span></div><div class="stat-value">₹<?php echo number_format($detail_calc['earned'], 0); ?></div><div class="stat-hint">From completed sessions</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Paid</span><span class="stat-icon green"><i class="fas fa-money-bill-wave"></i></span></div><div class="stat-value">₹<?php echo number_format($detail_calc['paid'], 0); ?></div><div class="stat-hint">Total paid out</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Payment Pending</span><span class="stat-icon amber"><i class="fas fa-hourglass-half"></i></span></div><div class="stat-value">₹<?php echo number_format($detail_calc['due'], 0); ?></div><div class="stat-hint">Earned minus paid</div></div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start;" class="fac-grid">
    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-money-bill-wave"></i></span><h2>Record Payment</h2>
            <div class="head-right">
                <a class="btn btn-sm btn-outline" href="faculty-report.php?id=<?php echo $view_id; ?>" target="_blank"><i class="fas fa-download"></i> Statement PDF</a>
                <?php if (!empty($detail['email'])): ?><a class="btn btn-sm btn-soft-blue" href="faculty-report.php?id=<?php echo $view_id; ?>&email=1"><i class="fas fa-paper-plane"></i> Email</a><?php endif; ?>
            </div>
        </div>
        <div class="panel-body">
            <div class="alert alert-info"><i class="fas fa-circle-info"></i><span>Payment pending: <strong>₹<?php echo number_format($detail_calc['due'], 2); ?></strong></span></div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="faculty_id" value="<?php echo $view_id; ?>">
                <div class="form-grid">
                    <div class="field"><label>Amount (₹) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="amount" required value="<?php echo $detail_calc['due'] > 0 ? round($detail_calc['due'], 2) : ''; ?>"></div>
                    <div class="field"><label>Payment Account</label><select name="payment_account_id"><option value="">-</option><?php foreach ($payment_accounts as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['account_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Paid Date</label><input type="date" name="paid_date" value="<?php echo date('Y-m-d'); ?>"></div>
                    <div class="field"><label>Remarks</label><input type="text" name="remarks" placeholder="Optional"></div>
                </div>
                <div style="display:flex; justify-content:flex-end; margin-top:12px;"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Payment</button></div>
            </form>

            <div style="margin-top:18px;">
                <div class="cell-sub" style="font-weight:700; margin-bottom:8px;">Payment history</div>
                <?php if (empty($detail_payments)): ?><div class="cell-sub">No payments yet.</div><?php else: ?>
                <table class="data-table"><thead><tr><th>Date</th><th>Amount</th><th>Account</th><th>Remarks</th></tr></thead><tbody>
                <?php foreach ($detail_payments as $p): ?>
                    <tr><td class="cell-sub"><?php echo $p['paid_date'] ? date('d M Y', strtotime($p['paid_date'])) : '-'; ?></td><td class="cell-main">₹<?php echo number_format((float)$p['amount'], 0); ?></td><td class="cell-sub"><?php echo e($p['account_name'] ?: '-'); ?></td><td class="cell-sub"><?php echo e($p['remarks'] ?: '-'); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-calendar"></i></span><h2>Schedules</h2></div>
        <div class="panel-body flush table-wrap">
            <?php if (empty($detail_sessions)): ?>
                <div class="empty-state"><i class="fas fa-calendar"></i><p><?php echo $sessions_ready ? 'No sessions for this faculty yet.' : 'Sessions module not installed.'; ?></p></div>
            <?php else: ?>
            <table class="data-table"><thead><tr><th>Topic</th><th>Type</th><th>When</th><th>Hours</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($detail_sessions as $s): ?>
                <tr>
                    <td class="cell-main"><?php echo e($s['topic']); ?></td>
                    <td><span class="badge gray"><?php echo ucfirst($s['session_type']); ?></span></td>
                    <td class="cell-sub"><?php echo date('d M Y, h:i A', strtotime($s['session_datetime'])); ?></td>
                    <td><?php echo rtrim(rtrim(number_format((float)$s['duration_hours'], 2), '0'), '.'); ?></td>
                    <td><span class="badge <?php echo $s['status'] === 'completed' ? 'green' : ($s['status'] === 'cancelled' ? 'red' : 'amber'); ?>"><?php echo ucfirst($s['status']); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php else: /* ===== LIST VIEW ===== */ ?>

<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-chalkboard-user"></i></span><h2>Faculties (<?php echo count($faculties); ?>)</h2>
        <div class="head-right"><button class="btn btn-sm btn-primary" onclick="openFacModal()"><i class="fas fa-plus"></i> Add Faculty</button></div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($faculties)): ?>
            <div class="empty-state"><i class="fas fa-chalkboard-user"></i><p>No faculties yet. Add your first faculty member.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Faculty</th><th>Rates (Live/QPD/Rec/Off)</th><th>Year</th><th>Earned</th><th>Paid</th><th>Due</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($faculties as $f):
                $calc = faculty_earned($pdo, $f, $sessions_ready, $TYPE_RATE);
                $paid = faculty_paid_total($pdo, $f['id']);
                $due = max(0, $calc['earned'] - $paid);
            ?>
                <tr>
                    <td><div class="cell-main"><?php echo e($f['name']); ?></div><div class="cell-sub"><?php echo format_credential($f['mobile'], 'phone', 'faculties') ?: '-'; ?><?php echo $f['email'] ? ' · ' . format_credential($f['email'], 'email', 'faculties') : ''; ?></div></td>
                    <td class="cell-sub">₹<?php echo (int)$f['rate_live']; ?> / ₹<?php echo (int)$f['rate_qpd']; ?> / ₹<?php echo (int)$f['rate_recorded']; ?> / ₹<?php echo (int)$f['rate_offline']; ?></td>
                    <td class="cell-sub"><?php echo e($f['academic_year'] ?: '-'); ?></td>
                    <td>₹<?php echo number_format($calc['earned'], 0); ?></td>
                    <td>₹<?php echo number_format($paid, 0); ?></td>
                    <td><?php echo $due > 0 ? '<span class="badge amber">₹' . number_format($due, 0) . '</span>' : '<span class="badge green">Clear</span>'; ?></td>
                    <td><span class="badge <?php echo $f['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($f['status']); ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a class="btn btn-sm btn-primary" href="faculties.php?view=<?php echo (int)$f['id']; ?>" title="Schedules & payments"><i class="fas fa-arrow-right"></i></a>
                        <button class="btn btn-sm btn-outline" title="Edit" onclick='editFac(<?php echo json_encode([
                            "id"=>(int)$f["id"],"name"=>$f["name"],
                            "mobile"=>(string)format_credential_text($f["mobile"], "phone", "faculties"),
                            "email"=>(string)format_credential_text($f["email"], "email", "faculties"),
                            "rate_live"=>$f["rate_live"],"rate_qpd"=>$f["rate_qpd"],"rate_recorded"=>$f["rate_recorded"],"rate_offline"=>$f["rate_offline"],
                            "academic_year"=>(string)$f["academic_year"],"status"=>$f["status"],
                        ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                        <?php if (can_delete()): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this faculty and their payment records?');">
                            <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_faculty"><input type="hidden" name="faculty_id" value="<?php echo (int)$f['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-soft-red" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ADD/EDIT MODAL -->
<div class="modal-backdrop" id="fac-modal">
    <div class="modal" style="max-width:640px;">
        <div class="modal-head"><h3 id="fac-modal-title"><i class="fas fa-chalkboard-user" style="color:var(--accent);"></i> Add Faculty</h3><button class="modal-close" onclick="closeModal('fac-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="fac-action" value="add_faculty">
            <input type="hidden" name="faculty_id" id="fac-id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>Faculty Name <span class="req">*</span></label><input type="text" name="name" id="fac-name" required></div>
                    <div class="field"><label>Mobile Number</label><input type="text" name="mobile" id="fac-mobile"></div>
                    <div class="field"><label>Email ID</label><input type="email" name="email" id="fac-email"></div>
                    <div class="field"><label>PEPP Academic Year</label>
                        <select name="academic_year" id="fac-year"><option value="">-</option><?php foreach ($academic_years as $y): ?><option value="<?php echo e($y); ?>"><?php echo e($y); ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="cell-sub" style="font-weight:700; margin:14px 0 6px;">Charge / hour by session type</div>
                <div class="form-grid">
                    <div class="field"><label>Live Session (₹/hr)</label><input type="number" step="0.01" min="0" name="rate_live" id="fac-rate_live" value="0"></div>
                    <div class="field"><label>QPD (₹/hr)</label><input type="number" step="0.01" min="0" name="rate_qpd" id="fac-rate_qpd" value="0"></div>
                    <div class="field"><label>Recorded (₹/hr)</label><input type="number" step="0.01" min="0" name="rate_recorded" id="fac-rate_recorded" value="0"></div>
                    <div class="field"><label>Offline Session (₹/hr)</label><input type="number" step="0.01" min="0" name="rate_offline" id="fac-rate_offline" value="0"></div>
                    <div class="field"><label>Status</label><select name="status" id="fac-status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('fac-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Faculty</button></div>
        </form>
    </div>
</div>

<?php
$extra_scripts = "<script>
function openFacModal() {
    document.getElementById('fac-action').value = 'add_faculty';
    document.getElementById('fac-modal-title').innerHTML = '<i class=\\\"fas fa-chalkboard-user\\\" style=\\\"color:var(--accent)\\\"></i> Add Faculty';
    ['id','name','mobile','email','year'].forEach(function(k){ var el=document.getElementById('fac-'+k); if(el) el.value=''; });
    ['rate_live','rate_qpd','rate_recorded','rate_offline'].forEach(function(k){ document.getElementById('fac-'+k).value='0'; });
    document.getElementById('fac-status').value='active';
    openModal('fac-modal');
}
function editFac(f) {
    document.getElementById('fac-action').value = 'edit_faculty';
    document.getElementById('fac-modal-title').innerHTML = '<i class=\\\"fas fa-pen\\\" style=\\\"color:var(--accent)\\\"></i> Edit Faculty';
    document.getElementById('fac-id').value = f.id;
    document.getElementById('fac-name').value = f.name || '';
    document.getElementById('fac-mobile').value = f.mobile || '';
    document.getElementById('fac-email').value = f.email || '';
    document.getElementById('fac-year').value = f.academic_year || '';
    document.getElementById('fac-rate_live').value = f.rate_live;
    document.getElementById('fac-rate_qpd').value = f.rate_qpd;
    document.getElementById('fac-rate_recorded').value = f.rate_recorded;
    document.getElementById('fac-rate_offline').value = f.rate_offline;
    document.getElementById('fac-status').value = f.status;
    openModal('fac-modal');
}
</script>";
include 'includes/admin_footer.php';
?>
<?php endif; ?>
