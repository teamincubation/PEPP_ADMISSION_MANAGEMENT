<?php
require_once 'includes/auth.php';
require_permission('marketing');
require_once 'includes/referral_helper.php';
require_once 'includes/peppian_notify.php';

/* Marketing (CRM) - two sections:
   1. Alumni Referral Earning Program: configure per active academic year,
      view referees + wallets, record manual payouts with proof, analytics.
   2. Discount Coupons: full coupon management for registration.
*/

$success_message = ''; $error_message = '';
$tab = $_GET['tab'] ?? 'referral';
require_once 'includes/peppian_notify.php';
try { marketing_mark_seen($pdo, $tab === 'coupons' ? 'coupon' : 'referral'); } catch (Exception $e) {}

$DEFAULT_TERMS = "1. The referral code is valid only for the academic year for which it was issued.\n"
    . "2. Referral earnings are credited only after the referred student's admission is approved and onboarding is completed.\n"
    . "3. If the referred student opts for an instalment plan, 50% of the referral earning is credited on onboarding and the remaining 50% once all dues are cleared (when partial crediting is enabled).\n"
    . "4. A referred student may use a referral code only once.\n"
    . "5. PEPP Learning reserves the right to modify or discontinue the referral program at any time.\n"
    . "6. Referral earnings will be paid to the bank/UPI details provided by the referee. PEPP Learning is not responsible for incorrect payout details.\n"
    . "7. Any misuse or fraudulent activity will result in disqualification from the program.";

function marketing_ready($pdo) { return pepp_tables_exist($pdo, ['referral_programs', 'coupons']); }
if (!marketing_ready($pdo)) {
    $active_page = 'marketing'; $page_title = 'Marketing'; $page_sub = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The Marketing module is not installed yet. Run <strong>database-update-8.sql</strong> once in phpMyAdmin, then reload.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

/* ── Uploads dir for payout proofs ── */
$UP_DIR = __DIR__ . '/uploads/payouts';
if (!is_dir($UP_DIR)) @mkdir($UP_DIR, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_program') {
                $year = trim($_POST['academic_year'] ?? '');
                if ($year === '') { $error_message = 'Select an academic year.'; }
                else {
                    $data = [
                        (float)($_POST['user_discount'] ?? 0), (float)($_POST['alumni_earning'] ?? 0),
                        isset($_POST['once_per_user']) ? 1 : 0, isset($_POST['partial_credit']) ? 1 : 0,
                        trim($_POST['terms'] ?? '') ?: $GLOBALS['DEFAULT_TERMS'],
                        trim($_POST['id_prefix'] ?? 'PEPPREF') ?: 'PEPPREF', (int)($_POST['id_start'] ?? 1001),
                        $_POST['start_date'] ?: null, $_POST['end_date'] ?: null,
                        in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active',
                    ];
                    // Upsert by academic_year
                    $stmt = $pdo->prepare("SELECT id FROM referral_programs WHERE academic_year = ?");
                    $stmt->execute([$year]);
                    $pid = $stmt->fetchColumn();
                    $newStatus = $data[9];

                    // Only ONE program may be active at a time. If this save would make a
                    // program active while a DIFFERENT year's program is already active,
                    // block it and tell the admin to deactivate the other one first.
                    $blocked = false;
                    if ($newStatus === 'active') {
                        $chk = $pdo->prepare("SELECT academic_year FROM referral_programs WHERE status='active' AND academic_year <> ? LIMIT 1");
                        $chk->execute([$year]);
                        $otherActive = $chk->fetchColumn();
                        if ($otherActive) {
                            $blocked = true;
                            $error_message = 'The ' . htmlspecialchars($otherActive) . ' referral program is currently active. Deactivate it first before activating another.';
                        }
                    }

                    if (!$blocked) {
                        // NOTE: changing user_discount / alumni_earning here affects only
                        // FUTURE referrals. Already-recorded earnings and the discounts
                        // users already received are frozen in referral_earnings /
                        // coupon_redemptions and are never rewritten.
                        if ($pid) {
                            $pdo->prepare("UPDATE referral_programs SET user_discount=?, alumni_earning=?, once_per_user=?, partial_credit=?, terms=?, id_prefix=?, id_start=?, start_date=?, end_date=?, status=? WHERE id=?")
                                ->execute(array_merge($data, [$pid]));
                        } else {
                            $pdo->prepare("INSERT INTO referral_programs (academic_year, user_discount, alumni_earning, once_per_user, partial_credit, terms, id_prefix, id_start, start_date, end_date, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                                ->execute(array_merge([$year], $data, [$admin_username]));
                        }
                        log_admin_activity($pdo, $admin_username, 'referral_program_saved', "Referral program for {$year} saved ({$newStatus})");
                        $success_message = 'Referral program saved. Amount changes apply to future referrals only - existing earnings stay unchanged.';
                    }
                }
            } elseif ($action === 'pay_referee') {
                $rid = (int)($_POST['referee_id'] ?? 0);
                $amt = (float)($_POST['amount'] ?? 0);
                if ($rid && $amt > 0) {
                    $proof = null;
                    if (isset($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
                        $isImg = @getimagesize($_FILES['proof']['tmp_name']) !== false;
                        $isPdf = ($ext === 'pdf');
                        if (in_array($ext, ['jpg','jpeg','png','pdf','webp'], true) && ($isImg || $isPdf) && $_FILES['proof']['size'] <= 6 * 1024 * 1024) {
                            $fn = 'payout_' . $rid . '_' . time() . '.' . $ext;
                            if (@move_uploaded_file($_FILES['proof']['tmp_name'], $GLOBALS['UP_DIR'] . '/' . $fn)) $proof = 'uploads/payouts/' . $fn;
                        }
                    }
                    $pdo->prepare("INSERT INTO referral_payouts (referee_id, amount, paid_date, payment_account_id, proof_path, remarks, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
                        ->execute([$rid, $amt, $_POST['paid_date'] ?: date('Y-m-d'), ((int)($_POST['payment_account_id'] ?? 0)) ?: null, $proof, trim($_POST['remarks'] ?? '') ?: null, $admin_username]);
                    // Mark fully-credited earnings as paid (best-effort: oldest first up to amount)
                    $pdo->prepare("UPDATE referral_earnings SET status = 'paid', updated_at = NOW() WHERE referee_id = ? AND status = 'credited'")->execute([$rid]);
                    log_admin_activity($pdo, $admin_username, 'referral_payout', "Paid Rs. " . number_format($amt, 2) . " to referee #{$rid}");
                    try { notify_referral_paid($pdo, $rid, $amt); } catch (Exception $e) {}
                    $success_message = 'Payout recorded.';
                } else { $error_message = 'A referee and positive amount are required.'; }
            } elseif ($action === 'save_coupon') {
                $code = strtoupper(trim($_POST['code'] ?? ''));
                if ($code === '') { $error_message = 'Coupon code is required.'; }
                else {
                    $data = [
                        $code, trim($_POST['description'] ?? '') ?: null,
                        in_array($_POST['discount_type'] ?? '', ['flat', 'percent'], true) ? $_POST['discount_type'] : 'flat',
                        (float)($_POST['discount_value'] ?? 0),
                        ($_POST['max_discount'] !== '' ? (float)$_POST['max_discount'] : null),
                        trim($_POST['scope_year'] ?? '') ?: null, trim($_POST['scope_course'] ?? '') ?: null,
                        ($_POST['usage_limit'] !== '' ? (int)$_POST['usage_limit'] : null),
                        isset($_POST['per_user_once']) ? 1 : 0,
                        $_POST['start_date'] ?: null, $_POST['end_date'] ?: null,
                        in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active',
                    ];
                    $cid = (int)($_POST['coupon_id'] ?? 0);
                    if ($cid) {
                        $pdo->prepare("UPDATE coupons SET code=?, description=?, discount_type=?, discount_value=?, max_discount=?, scope_year=?, scope_course=?, usage_limit=?, per_user_once=?, start_date=?, end_date=?, status=? WHERE id=?")
                            ->execute(array_merge($data, [$cid]));
                        $success_message = 'Coupon updated.';
                    } else {
                        try {
                            $pdo->prepare("INSERT INTO coupons (code, description, discount_type, discount_value, max_discount, scope_year, scope_course, usage_limit, per_user_once, start_date, end_date, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                                ->execute(array_merge($data, [$admin_username]));
                            $success_message = 'Coupon created.';
                        } catch (Exception $e) { $error_message = 'That coupon code already exists.'; }
                    }
                    log_admin_activity($pdo, $admin_username, 'coupon_saved', "Coupon {$code} saved");
                    try { marketing_flag($pdo, 'coupon', 'Coupon ' . $code . ' saved'); } catch (Exception $e) {}
                }
            } elseif ($action === 'toggle_coupon') {
                $pdo->prepare("UPDATE coupons SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([(int)($_POST['coupon_id'] ?? 0)]);
                $success_message = 'Coupon updated.';
            } elseif ($action === 'delete_coupon') {
                if (can_delete()) { $pdo->prepare("DELETE FROM coupons WHERE id = ?")->execute([(int)($_POST['coupon_id'] ?? 0)]); $success_message = 'Coupon deleted.'; }
                else { $error_message = 'Only the Super Admin can delete a coupon.'; }
            }
        } catch (Exception $e) {
            error_log('Marketing: ' . $e->getMessage());
            $error_message = 'Database error while saving.';
        }
    }
}

/* ── Export referral analytics ── */
if (isset($_GET['export']) && $_GET['export'] === 'referral') {
    $rows = [];
    try {
        $stmt = $pdo->query("SELECT r.referral_code, p2.full_name, p2.email, rp.academic_year,
                                    COUNT(re.id) AS joined,
                                    COALESCE(SUM(re.credited_amount),0) AS credited,
                                    COALESCE((SELECT SUM(amount) FROM referral_payouts WHERE referee_id=r.id),0) AS paid
                             FROM referees r
                             JOIN peppians p2 ON p2.id = r.peppian_id
                             JOIN referral_programs rp ON rp.id = r.program_id
                             LEFT JOIN referral_earnings re ON re.referee_id = r.id
                             GROUP BY r.id ORDER BY joined DESC");
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {}
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="referral-analytics-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Referral Code', 'Alumnus', 'Email', 'Academic Year', 'Users Joined', 'Credited', 'Paid', 'Balance']);
    foreach ($rows as $r) fputcsv($out, [$r['referral_code'], $r['full_name'], $r['email'], $r['academic_year'], $r['joined'], $r['credited'], $r['paid'], max(0, $r['credited'] - $r['paid'])]);
    fclose($out); exit();
}

/* ── Data ── */
$active_years = []; $all_years = []; $payment_accounts = []; $courses = [];
try {
    $active_years = $pdo->query("SELECT year FROM academic_years WHERE status='active' ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
    $all_years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
    $payment_accounts = $pdo->query("SELECT * FROM payment_accounts WHERE status='active' ORDER BY account_name")->fetchAll();
    $courses = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Programs (one per year)
$programs = [];
try { $programs = $pdo->query("SELECT * FROM referral_programs ORDER BY status='active' DESC, academic_year DESC")->fetchAll(); } catch (Exception $e) {}

// Referees with wallets
$referees = [];
try {
    $stmt = $pdo->query("SELECT r.*, p.full_name, p.email, p.whatsapp, rp.academic_year, rp.alumni_earning
                         FROM referees r JOIN peppians p ON p.id = r.peppian_id JOIN referral_programs rp ON rp.id = r.program_id
                         ORDER BY r.id DESC");
    foreach ($stmt->fetchAll() as $r) { $r['wallet'] = referee_wallet($pdo, $r['id']); $referees[] = $r; }
} catch (Exception $e) { error_log('referees: ' . $e->getMessage()); }

// Analytics
$an = ['joined' => 0, 'credited' => 0.0, 'paid' => 0.0, 'user_benefit' => 0.0, 'pending' => 0.0];
try {
    $an['joined']   = (int)$pdo->query("SELECT COUNT(*) FROM referral_earnings")->fetchColumn();
    $an['credited'] = (float)$pdo->query("SELECT COALESCE(SUM(credited_amount),0) FROM referral_earnings")->fetchColumn();
    $an['paid']     = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM referral_payouts")->fetchColumn();
    $an['pending']  = (float)$pdo->query("SELECT COALESCE(SUM(full_amount - credited_amount),0) FROM referral_earnings WHERE status IN ('pending','half')")->fetchColumn();
    $an['user_benefit'] = (float)$pdo->query("SELECT COALESCE(SUM(coupon_discount),0) FROM users WHERE referral_code IS NOT NULL AND referral_code <> ''")->fetchColumn();
} catch (Exception $e) {}

// Coupons
$coupons = [];
try { $coupons = $pdo->query("SELECT * FROM coupons ORDER BY status='active' DESC, id DESC")->fetchAll(); } catch (Exception $e) {}

$active_page = 'marketing';
$page_title  = 'Marketing';
$page_sub    = 'Referral earning program & discount coupons';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="tabs" style="margin-bottom:18px;">
    <a class="tab <?php echo $tab === 'referral' ? 'active' : ''; ?>" href="?tab=referral"><i class="fas fa-gift"></i> Referral Program</a>
    <a class="tab <?php echo $tab === 'coupons' ? 'active' : ''; ?>" href="?tab=coupons"><i class="fas fa-ticket"></i> Discount Coupons</a>
</div>

<?php if ($tab === 'referral'): ?>
<!-- ════ REFERRAL PROGRAM ════ -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Users Joined</span><span class="stat-icon violet"><i class="fas fa-users"></i></span></div><div class="stat-value"><?php echo $an['joined']; ?></div><div class="stat-hint">Via referral codes</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Alumni Credited</span><span class="stat-icon green"><i class="fas fa-indian-rupee-sign"></i></span></div><div class="stat-value">₹<?php echo number_format($an['credited'], 0); ?></div><div class="stat-hint">Earnings credited</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Paid Out</span><span class="stat-icon green"><i class="fas fa-money-bill-wave"></i></span></div><div class="stat-value">₹<?php echo number_format($an['paid'], 0); ?></div><div class="stat-hint">To referees</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">User Benefits</span><span class="stat-icon amber"><i class="fas fa-tags"></i></span></div><div class="stat-value">₹<?php echo number_format($an['user_benefit'], 0); ?></div><div class="stat-hint">Discounts given</div></div>
</div>

<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-gift"></i></span><h2>Alumni Referral Earning Program</h2>
        <div class="head-right"><a href="?tab=referral&export=referral" class="btn btn-sm btn-soft-green"><i class="fas fa-file-excel"></i> Export Analytics</a></div>
    </div>
    <div class="panel-body">
        <?php
            $__activeProg = null;
            foreach ($programs as $__p) { if ($__p['status'] === 'active') { $__activeProg = $__p; break; } }
            if ($__activeProg): ?>
            <div class="alert alert-info" style="margin-bottom:14px;"><i class="fas fa-circle-info"></i><span>The <strong><?php echo e($__activeProg['academic_year']); ?></strong> program is currently <strong>active</strong>. To start a different year's program, set this one to <em>Inactive</em> first. Editing amounts here changes only future referrals - existing earnings stay frozen.</span></div>
            <?php endif; ?>
            <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_program">
            <div class="form-grid">
                <div class="field"><label>PEPP Academic Year (Active) <span class="req">*</span></label>
                    <select name="academic_year" id="prog-year" onchange="loadProgram()" required>
                        <option value="">Select…</option>
                        <?php foreach ($active_years as $y): ?><option value="<?php echo e($y); ?>"><?php echo e($y); ?></option><?php endforeach; ?>
                    </select>
                    <?php if (empty($active_years)): ?><div class="help" style="color:#ef4444;">No active academic year - set one in Settings.</div><?php endif; ?>
                </div>
                <div class="field"><label>Referral Discount (₹ for the user)</label><input type="number" step="0.01" min="0" name="user_discount" id="prog-discount" value="0"></div>
                <div class="field"><label>Alumni Earning per Referral (₹)</label><input type="number" step="0.01" min="0" name="alumni_earning" id="prog-earning" value="0"></div>
                <div class="field"><label>Referral ID Prefix</label><input type="text" name="id_prefix" id="prog-prefix" value="PEPPREF"></div>
                <div class="field"><label>ID Start Sequence</label><input type="number" min="1" name="id_start" id="prog-idstart" value="1001"></div>
                <div class="field"><label>Start Date</label><input type="date" name="start_date" id="prog-start"></div>
                <div class="field"><label>End Date (referral expiry)</label><input type="date" name="end_date" id="prog-end"></div>
                <div class="field"><label>Status</label><select name="status" id="prog-status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div>
            <div style="display:flex; gap:24px; flex-wrap:wrap; margin:12px 0;">
                <label class="switch-row"><input type="checkbox" name="once_per_user" id="prog-once" checked> A user can use a referral code only <strong>once</strong></label>
                <label class="switch-row"><input type="checkbox" name="partial_credit" id="prog-partial"> <strong>Partial credit</strong> (50% on join + onboarding, 50% on dues cleared)</label>
            </div>
            <div class="field full"><label>Referee Terms &amp; Conditions <button type="button" class="btn btn-sm btn-outline" onclick="openModal('terms-modal')" style="margin-left:8px;">Edit</button></label>
                <textarea name="terms" id="prog-terms" rows="3" placeholder="Terms shown to referees"><?php echo e($DEFAULT_TERMS); ?></textarea>
            </div>
            <div class="alert alert-info" style="margin-top:8px;"><i class="fas fa-circle-info"></i><span>Activating a program automatically lets <strong>past alumni</strong> (PEPPians not in this active year) apply for it in their portal and share their referral link for this batch.</span></div>
            <div style="display:flex; justify-content:flex-end;"><button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Program</button></div>
        </form>
    </div>
</div>

<?php if (!empty($programs)): ?>
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-layer-group"></i></span><h2>Programs</h2></div>
    <div class="panel-body flush table-wrap">
        <table class="data-table"><thead><tr><th>Year</th><th>User Discount</th><th>Alumni Earning</th><th>Window</th><th>Rules</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($programs as $p): ?>
            <tr>
                <td class="cell-main"><?php echo e($p['academic_year']); ?></td>
                <td>₹<?php echo number_format((float)$p['user_discount'], 0); ?></td>
                <td>₹<?php echo number_format((float)$p['alumni_earning'], 0); ?></td>
                <td class="cell-sub"><?php echo $p['start_date'] ? date('d M Y', strtotime($p['start_date'])) : '-'; ?> → <?php echo $p['end_date'] ? date('d M Y', strtotime($p['end_date'])) : '-'; ?></td>
                <td class="cell-sub"><?php echo $p['once_per_user'] ? 'Once/user' : 'Multi'; ?><?php echo $p['partial_credit'] ? ' · Partial' : ''; ?></td>
                <td><span class="badge <?php echo $p['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($p['status']); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-wallet"></i></span><h2>Referees &amp; Earning Wallets (<?php echo count($referees); ?>)</h2></div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($referees)): ?>
            <div class="empty-state"><i class="fas fa-wallet"></i><p>No referees have joined yet. Alumni apply from their portal once a program is active.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Alumnus</th><th>Referral Code</th><th>Year</th><th>Joined</th><th>Credited</th><th>Pending</th><th>Paid</th><th>Balance</th><th style="text-align:right;">Pay</th></tr></thead>
            <tbody>
            <?php foreach ($referees as $r): $w = $r['wallet']; ?>
                <tr>
                    <td><div class="cell-main"><?php echo e($r['full_name']); ?></div><div class="cell-sub"><?php echo e($r['email']); ?><?php echo $r['payout_method'] ? '<br>' . e($r['payout_method']) . ': ' . e($r['payout_details']) : ''; ?></div></td>
                    <td><span class="badge violet"><?php echo e($r['referral_code']); ?></span></td>
                    <td class="cell-sub"><?php echo e($r['academic_year']); ?></td>
                    <td><?php echo (int)$w['joined']; ?></td>
                    <td>₹<?php echo number_format($w['credited'], 0); ?></td>
                    <td><?php echo $w['pending'] > 0 ? '<span class="badge amber">₹' . number_format($w['pending'], 0) . '</span>' : '-'; ?></td>
                    <td>₹<?php echo number_format($w['paid'], 0); ?></td>
                    <td><?php echo $w['balance'] > 0 ? '<span class="badge green">₹' . number_format($w['balance'], 0) . '</span>' : '<span class="cell-sub">Clear</span>'; ?></td>
                    <td style="text-align:right;">
                        <button class="btn btn-sm btn-primary" onclick='openPay(<?php echo json_encode(["id"=>(int)$r["id"],"name"=>$r["full_name"],"balance"=>round($w["balance"],2)], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-money-bill"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Terms editor modal -->
<div class="modal-backdrop" id="terms-modal">
    <div class="modal" style="max-width:640px;">
        <div class="modal-head"><h3><i class="fas fa-file-contract" style="color:var(--accent);"></i> Referee Terms &amp; Conditions</h3><button class="modal-close" onclick="closeModal('terms-modal')"><i class="fas fa-xmark"></i></button></div>
        <div class="modal-body"><textarea id="terms-editor" rows="14" style="width:100%;font-family:inherit;font-size:.85rem;line-height:1.6;"><?php echo e($DEFAULT_TERMS); ?></textarea></div>
        <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('terms-modal')">Cancel</button><button type="button" class="btn btn-primary" onclick="document.getElementById('prog-terms').value=document.getElementById('terms-editor').value;closeModal('terms-modal');">Use these terms</button></div>
    </div>
</div>

<!-- Pay referee modal -->
<div class="modal-backdrop" id="pay-modal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-head"><h3><i class="fas fa-money-bill-wave" style="color:var(--accent);"></i> Pay Referee</h3><button class="modal-close" onclick="closeModal('pay-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="pay_referee">
            <input type="hidden" name="referee_id" id="pay-id">
            <div class="modal-body">
                <div class="alert alert-info"><i class="fas fa-user"></i><span id="pay-name"></span></div>
                <div class="form-grid">
                    <div class="field"><label>Amount (₹) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="amount" id="pay-amount" required></div>
                    <div class="field"><label>Credit Date</label><input type="date" name="paid_date" value="<?php echo date('Y-m-d'); ?>"></div>
                    <div class="field"><label>Payment Account</label><select name="payment_account_id"><option value="">-</option><?php foreach ($payment_accounts as $a): ?><option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['account_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Remarks</label><input type="text" name="remarks" placeholder="Optional"></div>
                    <div class="field full"><label>Payment Proof (screenshot/invoice)</label><input type="file" name="proof" accept="image/*,.pdf"></div>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('pay-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Record Payout</button></div>
        </form>
    </div>
</div>

<?php
$prog_json = [];
foreach ($programs as $p) $prog_json[$p['academic_year']] = $p;
$extra_scripts = "<script>
var PROGRAMS = " . json_encode($prog_json, JSON_HEX_APOS | JSON_HEX_QUOT) . ";
function loadProgram() {
    var y = document.getElementById('prog-year').value;
    var p = PROGRAMS[y];
    if (!p) return;
    document.getElementById('prog-discount').value = p.user_discount;
    document.getElementById('prog-earning').value = p.alumni_earning;
    document.getElementById('prog-prefix').value = p.id_prefix;
    document.getElementById('prog-idstart').value = p.id_start;
    document.getElementById('prog-start').value = p.start_date || '';
    document.getElementById('prog-end').value = p.end_date || '';
    document.getElementById('prog-status').value = p.status;
    document.getElementById('prog-once').checked = (p.once_per_user == 1);
    document.getElementById('prog-partial').checked = (p.partial_credit == 1);
    if (p.terms) { document.getElementById('prog-terms').value = p.terms; document.getElementById('terms-editor').value = p.terms; }
}
function openPay(r) {
    document.getElementById('pay-id').value = r.id;
    document.getElementById('pay-name').textContent = r.name + ' - balance ₹' + Number(r.balance).toLocaleString('en-IN');
    document.getElementById('pay-amount').value = r.balance > 0 ? r.balance : '';
    openModal('pay-modal');
}
</script>";
include 'includes/admin_footer.php';
?>

<?php else: /* ════ COUPONS ════ */ ?>
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--accent-soft);color:var(--accent-dark);"><i class="fas fa-ticket"></i></span><h2>Discount Coupons</h2>
        <div class="head-right"><button class="btn btn-sm btn-primary" onclick="openCoupon()"><i class="fas fa-plus"></i> New Coupon</button></div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($coupons)): ?>
            <div class="empty-state"><i class="fas fa-ticket"></i><p>No coupons yet. Create one to offer discounts at registration.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Code</th><th>Discount</th><th>Scope</th><th>Usage</th><th>Window</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($coupons as $c): ?>
                <tr>
                    <td><div class="cell-main"><?php echo e($c['code']); ?></div><?php if ($c['description']): ?><div class="cell-sub"><?php echo e($c['description']); ?></div><?php endif; ?></td>
                    <td class="cell-main"><?php echo $c['discount_type'] === 'percent' ? (rtrim(rtrim(number_format((float)$c['discount_value'],2),'0'),'.') . '%') : ('₹' . number_format((float)$c['discount_value'], 0)); ?><?php echo $c['max_discount'] !== null ? '<div class="cell-sub">max ₹' . number_format((float)$c['max_discount'],0) . '</div>' : ''; ?></td>
                    <td class="cell-sub"><?php echo e($c['scope_year'] ?: 'Any year'); ?><?php echo $c['scope_course'] ? '<br>' . e($c['scope_course']) : ''; ?></td>
                    <td class="cell-sub"><?php echo (int)$c['used_count']; ?><?php echo $c['usage_limit'] !== null ? ' / ' . (int)$c['usage_limit'] : ''; ?><?php echo $c['per_user_once'] ? '<br>once/user' : ''; ?></td>
                    <td class="cell-sub"><?php echo $c['start_date'] ? date('d M', strtotime($c['start_date'])) : '-'; ?> → <?php echo $c['end_date'] ? date('d M Y', strtotime($c['end_date'])) : '-'; ?></td>
                    <td><span class="badge <?php echo $c['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn btn-sm btn-outline" onclick='editCoupon(<?php echo json_encode([
                            "id"=>(int)$c["id"],"code"=>$c["code"],"description"=>(string)$c["description"],"discount_type"=>$c["discount_type"],
                            "discount_value"=>$c["discount_value"],"max_discount"=>$c["max_discount"],"scope_year"=>(string)$c["scope_year"],
                            "scope_course"=>(string)$c["scope_course"],"usage_limit"=>$c["usage_limit"],"per_user_once"=>(int)$c["per_user_once"],
                            "start_date"=>(string)$c["start_date"],"end_date"=>(string)$c["end_date"],"status"=>$c["status"],
                        ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_coupon"><input type="hidden" name="coupon_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-sm btn-soft-amber"><i class="fas fa-power-off"></i></button></form>
                        <?php if (can_delete()): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this coupon?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_coupon"><input type="hidden" name="coupon_id" value="<?php echo (int)$c['id']; ?>"><button class="btn btn-sm btn-soft-red"><i class="fas fa-trash"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="modal-backdrop" id="coupon-modal">
    <div class="modal" style="max-width:640px;">
        <div class="modal-head"><h3 id="coupon-title"><i class="fas fa-ticket" style="color:var(--accent);"></i> New Coupon</h3><button class="modal-close" onclick="closeModal('coupon-modal')"><i class="fas fa-xmark"></i></button></div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_coupon">
            <input type="hidden" name="coupon_id" id="c-id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>Coupon Code <span class="req">*</span></label><input type="text" name="code" id="c-code" required style="text-transform:uppercase;"></div>
                    <div class="field"><label>Description</label><input type="text" name="description" id="c-desc" placeholder="Internal label"></div>
                    <div class="field"><label>Discount Type</label><select name="discount_type" id="c-type"><option value="flat">Flat (₹)</option><option value="percent">Percent (%)</option></select></div>
                    <div class="field"><label>Discount Value</label><input type="number" step="0.01" min="0" name="discount_value" id="c-value"></div>
                    <div class="field"><label>Max Discount (₹, for %)</label><input type="number" step="0.01" min="0" name="max_discount" id="c-max" placeholder="Optional"></div>
                    <div class="field"><label>Scope: Academic Year</label><select name="scope_year" id="c-year"><option value="">Any</option><?php foreach ($all_years as $y): ?><option value="<?php echo e($y); ?>"><?php echo e($y); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Scope: Course</label><select name="scope_course" id="c-course"><option value="">Any</option><?php foreach ($courses as $c): ?><option value="<?php echo e($c); ?>"><?php echo e($c); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Usage Limit (total)</label><input type="number" min="0" name="usage_limit" id="c-limit" placeholder="Unlimited"></div>
                    <div class="field"><label>Start Date</label><input type="date" name="start_date" id="c-start"></div>
                    <div class="field"><label>End Date</label><input type="date" name="end_date" id="c-end"></div>
                    <div class="field"><label>Status</label><select name="status" id="c-status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
                <label class="switch-row" style="margin-top:10px;"><input type="checkbox" name="per_user_once" id="c-once" checked> Each user can use this coupon only once</label>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-outline" onclick="closeModal('coupon-modal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Coupon</button></div>
        </form>
    </div>
</div>

<?php
$extra_scripts = "<script>
function openCoupon() {
    document.getElementById('coupon-title').innerHTML = '<i class=\\\"fas fa-ticket\\\" style=\\\"color:var(--accent)\\\"></i> New Coupon';
    document.getElementById('c-id').value=''; document.getElementById('c-code').value='';
    document.getElementById('c-desc').value=''; document.getElementById('c-type').value='flat';
    document.getElementById('c-value').value=''; document.getElementById('c-max').value='';
    document.getElementById('c-year').value=''; document.getElementById('c-course').value='';
    document.getElementById('c-limit').value=''; document.getElementById('c-start').value='';
    document.getElementById('c-end').value=''; document.getElementById('c-status').value='active';
    document.getElementById('c-once').checked=true;
    openModal('coupon-modal');
}
function editCoupon(c) {
    document.getElementById('coupon-title').innerHTML = '<i class=\\\"fas fa-pen\\\" style=\\\"color:var(--accent)\\\"></i> Edit Coupon';
    document.getElementById('c-id').value=c.id; document.getElementById('c-code').value=c.code;
    document.getElementById('c-desc').value=c.description||''; document.getElementById('c-type').value=c.discount_type;
    document.getElementById('c-value').value=c.discount_value; document.getElementById('c-max').value=c.max_discount||'';
    document.getElementById('c-year').value=c.scope_year||''; document.getElementById('c-course').value=c.scope_course||'';
    document.getElementById('c-limit').value=(c.usage_limit==null?'':c.usage_limit); document.getElementById('c-start').value=c.start_date||'';
    document.getElementById('c-end').value=c.end_date||''; document.getElementById('c-status').value=c.status;
    document.getElementById('c-once').checked=(c.per_user_once==1);
    openModal('coupon-modal');
}
</script>";
include 'includes/admin_footer.php';
?>
<?php endif; ?>
