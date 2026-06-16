<?php
require_once 'includes/auth.php';
require_super_admin();

/* Reports & Export - Super Admin only.
   On-screen summaries + one-click Excel (CSV, UTF-8 BOM) exports for:
     students · courses · revenue (monthly) · payments (installments)
     · admin activity
   Every export is recorded in the activity log.
   Revenue model (consistent with the dashboard):
     collected = registration payments (users.paid_amount, approved students)
               + approved installments (instalment_details.paid_amount)
     net payable = users.total_fee (already net of discount)               */

/* Invoice helper provides gst_account_id(); load it defensively so this
   page still works even if the invoice files were not uploaded yet. */
$__ih = __DIR__ . '/includes/invoice_helper.php';
if (file_exists($__ih)) { require_once $__ih; }
if (!function_exists('gst_account_id')) {
    function gst_account_id($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'inv_gst_account_id'");
            $stmt->execute();
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
            return (int)$pdo->query("SELECT id FROM payment_accounts WHERE account_name LIKE '%AXIS%' AND account_name LIKE '%LABINC%' LIMIT 1")->fetchColumn();
        } catch (Exception $e) { return 0; }
    }
}

$tab = $_GET['tab'] ?? 'students';
if (!in_array($tab, ['students', 'courses', 'revenue', 'payments', 'accounts', 'activity'], true)) $tab = 'students';

/* ── Shared report queries ──────────────────────────────────────── */
function q_students($pdo, $course = '', $year = '', $status = '') {
    $where = ["u.status = 'approved'"]; $p = [];
    if ($course !== '') { $where[] = "u.pepp_course = ?"; $p[] = $course; }
    if ($year   !== '') { $where[] = "u.pepp_academic_year = ?"; $p[] = $year; }
    if ($status !== '') { $where[] = "u.student_status = ?"; $p[] = $status; }
    $sql = "
        SELECT u.user_id, u.name, u.email, CONCAT(u.whatsapp_country_code, ' ', u.whatsapp_number) AS whatsapp,
               u.pepp_course, u.pepp_academic_year, u.payment_plan, u.student_status,
               u.joined_date, COALESCE(u.course_expiry_date, u.course_end_date) AS course_duration_date,
               u.total_fee AS net_payable, u.discount_amount,
               u.paid_amount AS registration_paid,
               COALESCE(x.inst_paid, 0) AS installments_paid,
               (u.paid_amount + COALESCE(x.inst_paid, 0)) AS total_collected,
               GREATEST(0, u.total_fee - (u.paid_amount + COALESCE(x.inst_paid, 0))) AS balance
        FROM users u
        LEFT JOIN (SELECT user_id, SUM(COALESCE(paid_amount, amount)) AS inst_paid
                   FROM instalment_details WHERE status IN ('approved','paid') GROUP BY user_id) x
               ON x.user_id = u.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY u.created_at DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute($p);
    return $stmt->fetchAll();
}
function q_courses($pdo) {
    return $pdo->query("
        SELECT pc.course_name, pc.course_code, pc.course_type, pc.academic_year, pc.total_fee AS course_fee, pc.status,
               COUNT(u.id) AS students,
               COALESCE(SUM(u.total_fee), 0) AS net_payable,
               COALESCE(SUM(u.paid_amount), 0) AS registration_collected,
               COALESCE(SUM(x.inst_paid), 0) AS installments_collected,
               COALESCE(SUM(GREATEST(0, u.total_fee - (u.paid_amount + COALESCE(x.inst_paid, 0)))), 0) AS outstanding
        FROM pepp_courses pc
        LEFT JOIN users u ON u.pepp_course = pc.course_name AND u.status = 'approved'
        LEFT JOIN (SELECT user_id, SUM(COALESCE(paid_amount, amount)) AS inst_paid
                   FROM instalment_details WHERE status IN ('approved','paid') GROUP BY user_id) x
               ON x.user_id = u.user_id
        GROUP BY pc.id
        ORDER BY pc.academic_year DESC, pc.course_name
    ")->fetchAll();
}
function q_revenue($pdo, $from = '', $to = '') {
    $cond_u = "status = 'approved' AND paid_date IS NOT NULL";
    $cond_i = "status IN ('approved','paid') AND paid_date IS NOT NULL";
    $p = [];
    if ($from !== '') { $cond_u .= " AND paid_date >= ?"; $cond_i .= " AND paid_date >= ?"; }
    if ($to   !== '') { $cond_u .= " AND paid_date <= ?"; $cond_i .= " AND paid_date <= ?"; }
    $params_u = []; $params_i = [];
    if ($from !== '') { $params_u[] = $from; $params_i[] = $from; }
    if ($to   !== '') { $params_u[] = $to;   $params_i[] = $to; }
    $sql = "
        SELECT m.ym AS month, SUM(m.reg) AS registrations, SUM(m.inst) AS installments,
               SUM(m.reg + m.inst) AS total
        FROM (
            SELECT DATE_FORMAT(paid_date, '%Y-%m') AS ym, paid_amount AS reg, 0 AS inst
            FROM users WHERE $cond_u
            UNION ALL
            SELECT DATE_FORMAT(paid_date, '%Y-%m'), 0, COALESCE(paid_amount, amount)
            FROM instalment_details WHERE $cond_i
        ) m GROUP BY m.ym ORDER BY m.ym DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute(array_merge($params_u, $params_i));
    return $stmt->fetchAll();
}
function q_payments($pdo, $status = '', $from = '', $to = '') {
    $where = ["1=1"]; $p = [];
    if ($status === 'approved') $where[] = "i.status IN ('approved','paid')";
    elseif ($status === 'pending') $where[] = "i.status = 'pending' AND i.paid_date IS NOT NULL";
    elseif ($status === 'upcoming') $where[] = "i.status = 'pending' AND i.paid_date IS NULL";
    if ($from !== '') { $where[] = "COALESCE(i.paid_date, i.due_date) >= ?"; $p[] = $from; }
    if ($to   !== '') { $where[] = "COALESCE(i.paid_date, i.due_date) <= ?"; $p[] = $to; }
    $sql = "
        SELECT i.user_id, u.name AS student, u.pepp_course,
               i.instalment_number, i.amount AS scheduled, i.paid_amount AS received,
               i.due_date, i.paid_date, i.status, i.payment_mode,
               pa.account_name, i.approved_by, i.rejected_by, i.admin_remarks
        FROM instalment_details i
        JOIN users u ON u.user_id = i.user_id
        LEFT JOIN payment_accounts pa ON pa.id = i.payment_account_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(i.paid_date, i.due_date) DESC";
    $stmt = $pdo->prepare($sql); $stmt->execute($p);
    return $stmt->fetchAll();
}
/* Payment Account report: every approved payment grouped by receiving
   account. The GST account's receipts are 18% GST-INCLUSIVE → split into
   taxable (ours) + CGST/SGST (to be remitted). Other accounts: fully ours. */
function q_accounts($pdo, $from = '', $to = '') {
    $du = ''; $di = ''; $p = [];
    if ($from !== '') { $du .= " AND paid_date >= ?"; $di .= " AND paid_date >= ?"; }
    if ($to   !== '') { $du .= " AND paid_date <= ?"; $di .= " AND paid_date <= ?"; }
    $pu = []; $pi = [];
    if ($from !== '') { $pu[] = $from; $pi[] = $from; }
    if ($to   !== '') { $pu[] = $to;   $pi[] = $to; }
    $sql = "
        SELECT pay.aid, COALESCE(pa.account_name, '(no account recorded)') AS account_name,
               COALESCE(pa.account_type, '-') AS account_type,
               COUNT(*) AS payments, SUM(pay.amt) AS gross
        FROM (
            SELECT payment_account_id AS aid, paid_amount AS amt
            FROM users WHERE status = 'approved' AND paid_amount > 0 $du
            UNION ALL
            SELECT payment_account_id, COALESCE(paid_amount, amount)
            FROM instalment_details WHERE status IN ('approved','paid') $di
        ) pay
        LEFT JOIN payment_accounts pa ON pa.id = pay.aid
        GROUP BY pay.aid, pa.account_name, pa.account_type
        ORDER BY gross DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($pu, $pi));
    $rows = $stmt->fetchAll();

    $gst_id = gst_account_id($pdo);
    foreach ($rows as &$r) {
        $r['is_gst'] = $gst_id && (int)$r['aid'] === $gst_id;
        $gross = (float)$r['gross'];
        if ($r['is_gst']) {
            $r['taxable'] = round($gross * 100 / 118, 2);
            $gst = round($gross - $r['taxable'], 2);
            $r['cgst'] = round($gst / 2, 2);
            $r['sgst'] = round($gst - $r['cgst'], 2);
            $r['net_ours'] = $r['taxable'];
        } else {
            $r['taxable'] = $gross; $r['cgst'] = 0.0; $r['sgst'] = 0.0; $r['net_ours'] = $gross;
        }
    }
    return $rows;
}

function q_activity($pdo, $from = '', $to = '') {
    /* Query each source separately and merge in PHP. This avoids the
       'Illegal mix of collations' error that a direct UNION throws when the
       two tables were created with different collations. */
    $all = [];
    $hasTable = function ($t) use ($pdo) {
        try { return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn(); }
        catch (Exception $e) { return false; }
    };

    if ($hasTable('admin_activity_log')) {
        try {
            $w = ['1=1']; $p = [];
            if ($from !== '') { $w[] = "created_at >= ?"; $p[] = $from . ' 00:00:00'; }
            if ($to   !== '') { $w[] = "created_at <= ?"; $p[] = $to . ' 23:59:59'; }
            $stmt = $pdo->prepare("SELECT created_at AS at_time, admin_username AS admin_name, action_type AS act,
                                          details, ip_address AS ip, location AS loc, NULL AS student
                                   FROM admin_activity_log WHERE " . implode(' AND ', $w) . " ORDER BY created_at DESC LIMIT 20000");
            $stmt->execute($p);
            $all = array_merge($all, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { error_log('q_activity admin_log: ' . $e->getMessage()); }
    }

    if ($hasTable('track_records')) {
        try {
            $w = ['1=1']; $p = [];
            if ($from !== '') { $w[] = "performed_at >= ?"; $p[] = $from . ' 00:00:00'; }
            if ($to   !== '') { $w[] = "performed_at <= ?"; $p[] = $to . ' 23:59:59'; }
            $stmt = $pdo->prepare("SELECT performed_at AS at_time, performed_by AS admin_name, action_type AS act,
                                          action_details AS details, NULL AS ip, NULL AS loc, user_id AS student
                                   FROM track_records WHERE " . implode(' AND ', $w) . " ORDER BY performed_at DESC LIMIT 20000");
            $stmt->execute($p);
            $all = array_merge($all, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { error_log('q_activity track: ' . $e->getMessage()); }
    }

    usort($all, function ($a, $b) { return strtotime($b['at_time']) <=> strtotime($a['at_time']); });
    return array_slice($all, 0, 20000);
}

/* ── EXPORT (before any output) ─────────────────────────────────── */
if (isset($_GET['export'])) {
    $export = $_GET['export'];
    $rows = []; $headers = []; $map = null;
    try {
        switch ($export) {
            case 'students':
                $rows = q_students($pdo, trim($_GET['course'] ?? ''), trim($_GET['year'] ?? ''), trim($_GET['status'] ?? ''));
                $headers = ['Student ID','Name','Email','WhatsApp','Course','Academic Year','Plan','Status','Joined','Access Ends','Net Payable','Discount','Registration Paid','Installments Paid','Total Collected','Balance'];
                $map = function ($r) { return [$r['user_id'],$r['name'],$r['email'],$r['whatsapp'],$r['pepp_course'],$r['pepp_academic_year'],$r['payment_plan'],$r['student_status'],$r['joined_date'],$r['course_duration_date'],$r['net_payable'],$r['discount_amount'],$r['registration_paid'],$r['installments_paid'],$r['total_collected'],$r['balance']]; };
                break;
            case 'courses':
                $rows = q_courses($pdo);
                $headers = ['Course','Code','Type','Academic Year','Course Fee','Status','Students','Net Payable','Registration Collected','Installments Collected','Total Collected','Outstanding'];
                $map = function ($r) { return [$r['course_name'],$r['course_code'],$r['course_type'],$r['academic_year'],$r['course_fee'],$r['status'],$r['students'],$r['net_payable'],$r['registration_collected'],$r['installments_collected'],$r['registration_collected']+$r['installments_collected'],$r['outstanding']]; };
                break;
            case 'revenue':
                $rows = q_revenue($pdo, trim($_GET['from'] ?? ''), trim($_GET['to'] ?? ''));
                $headers = ['Month','Registration Collections','Installment Collections','Total'];
                $map = function ($r) { return [$r['month'],$r['registrations'],$r['installments'],$r['total']]; };
                break;
            case 'payments':
                $rows = q_payments($pdo, trim($_GET['status'] ?? ''), trim($_GET['from'] ?? ''), trim($_GET['to'] ?? ''));
                $headers = ['Student ID','Student','Course','Installment #','Scheduled','Received','Due Date','Paid Date','Status','Mode','Account','Approved By','Rejected By','Remarks'];
                $map = function ($r) { return [$r['user_id'],$r['student'],$r['pepp_course'],$r['instalment_number'],$r['scheduled'],$r['received'],$r['due_date'],$r['paid_date'],$r['status'],$r['payment_mode'],$r['account_name'],$r['approved_by'],$r['rejected_by'],$r['admin_remarks']]; };
                break;
            case 'accounts':
                $rows = q_accounts($pdo, trim($_GET['from'] ?? ''), trim($_GET['to'] ?? ''));
                $headers = ['Payment Account','Type','GST Applicable','Payments','Gross Collected','Taxable / Our Amount','CGST 9%','SGST 9%','Total GST'];
                $map = function ($r) { return [$r['account_name'],$r['account_type'],$r['is_gst'] ? 'Yes (18% included)' : 'No',$r['payments'],$r['gross'],$r['net_ours'],$r['cgst'],$r['sgst'],$r['cgst']+$r['sgst']]; };
                break;
            case 'activity':
                $rows = q_activity($pdo, trim($_GET['from'] ?? ''), trim($_GET['to'] ?? ''));
                $headers = ['Date & Time','Admin','Action','Details','Student ID','IP Address','Location'];
                $map = function ($r) { return [$r['at_time'],$r['admin_name'],$r['act'],$r['details'],$r['student'],$r['ip'],$r['loc']]; };
                break;
        }
    } catch (Exception $e) { error_log('Report export: ' . $e->getMessage()); }

    if ($map) {
        log_admin_activity($pdo, $admin_username, 'data_export', "Exported {$export} report (" . count($rows) . ' rows)');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="pepp-' . $export . '-' . date('Y-m-d-Hi') . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $r) fputcsv($out, $map($r));
        fclose($out);
        exit();
    }
}

/* ── PAGE DATA per tab ──────────────────────────────────────────── */
$courses_list = []; $years_list = [];
try {
    $courses_list = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
    $years_list   = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$f_course = trim($_GET['course'] ?? '');
$f_year   = trim($_GET['year'] ?? '');
$f_status = trim($_GET['status'] ?? '');
$f_from   = trim($_GET['from'] ?? '');
$f_to     = trim($_GET['to'] ?? '');

$data = [];
$load_error = '';
try {
    switch ($tab) {
        case 'students': $data = q_students($pdo, $f_course, $f_year, $f_status); break;
        case 'courses':  $data = q_courses($pdo); break;
        case 'revenue':  $data = q_revenue($pdo, $f_from, $f_to); break;
        case 'payments': $data = q_payments($pdo, $f_status, $f_from, $f_to); break;
        case 'accounts': $data = q_accounts($pdo, $f_from, $f_to); break;
        case 'activity': $data = admins_table_exists($pdo) ? array_slice(q_activity($pdo, $f_from, $f_to), 0, 200) : []; break;
    }
} catch (Exception $e) {
    error_log('Reports (' . $tab . '): ' . $e->getMessage());
    // Show the real reason to the Super Admin so issues are diagnosable.
    $load_error = 'Could not load this report: ' . $e->getMessage();
}

function rqs($overrides = []) {
    $q = array_merge($_GET, $overrides);
    unset($q['logout'], $q['export']);
    return '?' . http_build_query($q);
}

$active_page = 'reports';
$page_title  = 'Reports & Export';
$page_sub    = 'Detailed reports with Excel export - Super Admin only';
include 'includes/admin_nav.php';
?>

<?php if ($load_error): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($load_error); ?></span></div><?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-chart-pie"></i></span>
        <h2>Reports</h2>
        <div class="head-right tabs">
            <a class="tab <?php echo $tab === 'students' ? 'active' : ''; ?>" href="?tab=students">Students</a>
            <a class="tab <?php echo $tab === 'courses' ? 'active' : ''; ?>" href="?tab=courses">Courses</a>
            <a class="tab <?php echo $tab === 'revenue' ? 'active' : ''; ?>" href="?tab=revenue">Revenue</a>
            <a class="tab <?php echo $tab === 'payments' ? 'active' : ''; ?>" href="?tab=payments">Payments</a>
            <a class="tab <?php echo $tab === 'accounts' ? 'active' : ''; ?>" href="?tab=accounts">Payment Accounts</a>
            <a class="tab <?php echo $tab === 'activity' ? 'active' : ''; ?>" href="?tab=activity">Admin Activity</a>
        </div>
    </div>

    <div class="panel-body" style="border-bottom:1px solid var(--border);">
        <form method="GET" class="filter-bar">
            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
            <?php if ($tab === 'students'): ?>
                <div class="field"><label>Course</label>
                    <select name="course"><option value="">All</option>
                        <?php foreach ($courses_list as $c): ?><option value="<?php echo e($c); ?>" <?php echo $f_course === $c ? 'selected' : ''; ?>><?php echo e($c); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="field"><label>Year</label>
                    <select name="year"><option value="">All</option>
                        <?php foreach ($years_list as $y): ?><option value="<?php echo e($y); ?>" <?php echo $f_year === $y ? 'selected' : ''; ?>><?php echo e($y); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="field"><label>Status</label>
                    <select name="status"><option value="">All</option>
                        <?php foreach (['active','inactive','suspended','completed'] as $s): ?><option value="<?php echo $s; ?>" <?php echo $f_status === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option><?php endforeach; ?>
                    </select></div>
            <?php elseif ($tab === 'payments'): ?>
                <div class="field"><label>Status</label>
                    <select name="status"><option value="">All</option>
                        <option value="approved" <?php echo $f_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="pending"  <?php echo $f_status === 'pending' ? 'selected' : ''; ?>>Pending review</option>
                        <option value="upcoming" <?php echo $f_status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                    </select></div>
                <div class="field"><label>From</label><input type="date" name="from" value="<?php echo e($f_from); ?>"></div>
                <div class="field"><label>To</label><input type="date" name="to" value="<?php echo e($f_to); ?>"></div>
            <?php elseif ($tab === 'revenue' || $tab === 'activity' || $tab === 'accounts'): ?>
                <div class="field"><label>From</label><input type="date" name="from" value="<?php echo e($f_from); ?>"></div>
                <div class="field"><label>To</label><input type="date" name="to" value="<?php echo e($f_to); ?>"></div>
            <?php endif; ?>
            <?php if ($tab !== 'courses'): ?><button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button><?php endif; ?>
            <a href="<?php echo e(rqs(['export' => $tab])); ?>" class="btn btn-soft-green"><i class="fas fa-file-excel"></i> Export to Excel</a>
        </form>
    </div>

    <div class="panel-body flush table-wrap">
        <?php if (empty($data)): ?>
            <div class="empty-state"><i class="fas fa-table"></i><p>No data for this report<?php echo $tab === 'activity' && !admins_table_exists($pdo) ? ' - run database-update-2.sql first' : ''; ?>.</p></div>

        <?php elseif ($tab === 'students'): ?>
            <table class="data-table">
                <thead><tr><th>Student</th><th>Course</th><th>Plan / Status</th><th>Net Payable</th><th>Collected</th><th>Balance</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($data, 0, 200) as $r): ?>
                    <tr>
                        <td><div class="cell-main"><?php echo e($r['name']); ?></div><div class="cell-sub"><?php echo e($r['user_id']); ?> · <?php echo e($r['whatsapp']); ?></div></td>
                        <td><div style="font-size:.82rem;font-weight:600;"><?php echo e($r['pepp_course']); ?></div><div class="cell-sub"><?php echo e($r['pepp_academic_year']); ?></div></td>
                        <td><div class="cell-sub"><?php echo e($r['payment_plan'] ?: 'One Time'); ?></div><span class="badge <?php echo $r['student_status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($r['student_status']); ?></span></td>
                        <td>₹<?php echo number_format((float)$r['net_payable'], 0); ?></td>
                        <td>₹<?php echo number_format((float)$r['total_collected'], 0); ?><div class="cell-sub">Reg ₹<?php echo number_format((float)$r['registration_paid'], 0); ?> · Inst ₹<?php echo number_format((float)$r['installments_paid'], 0); ?></div></td>
                        <td><?php if ((float)$r['balance'] > 0): ?><span class="badge amber">₹<?php echo number_format((float)$r['balance'], 0); ?></span><?php else: ?><span class="badge green">Cleared</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($data) > 200): ?><div class="alert alert-info" style="margin:14px 16px;"><i class="fas fa-circle-info"></i><span>Showing the first 200 of <?php echo count($data); ?> rows - the Excel export contains everything.</span></div><?php endif; ?>

        <?php elseif ($tab === 'courses'): ?>
            <table class="data-table">
                <thead><tr><th>Course</th><th>Year</th><th>Fee</th><th>Students</th><th>Collected</th><th>Outstanding</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($data as $r): ?>
                    <tr>
                        <td><div class="cell-main"><?php echo e($r['course_name']); ?></div><div class="cell-sub"><?php echo e($r['course_type']); ?><?php echo $r['course_code'] ? ' · ' . e($r['course_code']) : ''; ?></div></td>
                        <td class="cell-sub"><?php echo e($r['academic_year']); ?></td>
                        <td>₹<?php echo number_format((float)$r['course_fee'], 0); ?></td>
                        <td><?php echo (int)$r['students']; ?></td>
                        <td>₹<?php echo number_format((float)$r['registration_collected'] + (float)$r['installments_collected'], 0); ?></td>
                        <td><?php if ((float)$r['outstanding'] > 0): ?><span class="badge amber">₹<?php echo number_format((float)$r['outstanding'], 0); ?></span><?php else: ?><span class="badge green">-</span><?php endif; ?></td>
                        <td><span class="badge <?php echo $r['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($tab === 'revenue'): ?>
            <table class="data-table">
                <thead><tr><th>Month</th><th>Registrations</th><th>Installments</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($data as $r): ?>
                    <tr>
                        <td class="cell-main"><?php echo date('M Y', strtotime($r['month'] . '-01')); ?></td>
                        <td>₹<?php echo number_format((float)$r['registrations'], 0); ?></td>
                        <td>₹<?php echo number_format((float)$r['installments'], 0); ?></td>
                        <td class="cell-main">₹<?php echo number_format((float)$r['total'], 0); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($tab === 'payments'): ?>
            <table class="data-table">
                <thead><tr><th>Student</th><th>Inst.</th><th>Scheduled</th><th>Received</th><th>Due / Paid</th><th>Status</th><th>Reviewed by</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($data, 0, 200) as $r): ?>
                    <tr>
                        <td><div class="cell-main"><?php echo e($r['student']); ?></div><div class="cell-sub"><?php echo e($r['user_id']); ?> · <?php echo e($r['pepp_course']); ?></div></td>
                        <td>#<?php echo (int)$r['instalment_number']; ?></td>
                        <td>₹<?php echo number_format((float)$r['scheduled'], 0); ?></td>
                        <td><?php echo $r['received'] ? '₹' . number_format((float)$r['received'], 0) : '-'; ?></td>
                        <td class="cell-sub"><?php echo date('d M Y', strtotime($r['due_date'])); ?><?php echo $r['paid_date'] ? '<br>paid ' . date('d M Y', strtotime($r['paid_date'])) : ''; ?></td>
                        <td><span class="badge <?php echo in_array($r['status'], ['approved','paid']) ? 'green' : ($r['status'] === 'rejected' ? 'red' : 'amber'); ?>"><?php echo ucfirst($r['status']); ?></span></td>
                        <td class="cell-sub"><?php echo e($r['approved_by'] ?: $r['rejected_by'] ?: '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($data) > 200): ?><div class="alert alert-info" style="margin:14px 16px;"><i class="fas fa-circle-info"></i><span>Showing the first 200 of <?php echo count($data); ?> rows - the Excel export contains everything.</span></div><?php endif; ?>

        <?php elseif ($tab === 'accounts'):
            $tot_gross = 0.0; $tot_ours = 0.0; $tot_cgst = 0.0; $tot_sgst = 0.0; $tot_pay = 0;
            foreach ($data as $r) {
                $tot_gross += (float)$r['gross'];  $tot_ours += (float)$r['net_ours'];
                $tot_cgst  += (float)$r['cgst'];   $tot_sgst += (float)$r['sgst'];
                $tot_pay   += (int)$r['payments'];
            }
        ?>
            <table class="data-table">
                <thead><tr><th>Payment Account</th><th>Payments</th><th>Gross Collected</th><th>Our Amount</th><th>CGST 9%</th><th>SGST 9%</th><th>Total GST</th></tr></thead>
                <tbody>
                <?php foreach ($data as $r): ?>
                    <tr>
                        <td>
                            <div class="cell-main"><?php echo e($r['account_name']); ?></div>
                            <div class="cell-sub"><?php echo e($r['account_type']); ?> ·
                                <?php if ($r['is_gst']): ?><span class="badge amber" style="font-size:.62rem;">GST 18% included</span>
                                <?php else: ?><span class="badge green" style="font-size:.62rem;">No GST - fully ours</span><?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo (int)$r['payments']; ?></td>
                        <td class="cell-main">₹<?php echo number_format((float)$r['gross'], 2); ?></td>
                        <td>₹<?php echo number_format((float)$r['net_ours'], 2); ?>
                            <?php if ($r['is_gst']): ?><div class="cell-sub">taxable value</div><?php endif; ?></td>
                        <td><?php echo $r['is_gst'] ? '₹' . number_format((float)$r['cgst'], 2) : '-'; ?></td>
                        <td><?php echo $r['is_gst'] ? '₹' . number_format((float)$r['sgst'], 2) : '-'; ?></td>
                        <td><?php echo $r['is_gst'] ? '<span class="badge amber">₹' . number_format((float)$r['cgst'] + (float)$r['sgst'], 2) . '</span>' : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:var(--card);">
                        <td class="cell-main">TOTAL</td>
                        <td class="cell-main"><?php echo $tot_pay; ?></td>
                        <td class="cell-main">₹<?php echo number_format($tot_gross, 2); ?></td>
                        <td class="cell-main">₹<?php echo number_format($tot_ours, 2); ?></td>
                        <td class="cell-main">₹<?php echo number_format($tot_cgst, 2); ?></td>
                        <td class="cell-main">₹<?php echo number_format($tot_sgst, 2); ?></td>
                        <td class="cell-main">₹<?php echo number_format($tot_cgst + $tot_sgst, 2); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="alert alert-info" style="margin:14px 16px;">
                <i class="fas fa-landmark"></i>
                <span><strong>GST summary<?php echo ($f_from || $f_to) ? ' (filtered period)' : ''; ?>:</strong>
                Taxable value ₹<?php echo number_format($tot_ours, 2); ?> ·
                CGST ₹<?php echo number_format($tot_cgst, 2); ?> + SGST ₹<?php echo number_format($tot_sgst, 2); ?> =
                <strong>₹<?php echo number_format($tot_cgst + $tot_sgst, 2); ?> total GST to remit</strong>.
                Amounts received in the GST account are 18% inclusive; the GST account is set in
                <a href="settings.php">Settings → Invoice Settings</a>.</span>
            </div>

        <?php elseif ($tab === 'activity'): ?>
            <table class="data-table">
                <thead><tr><th>Date &amp; Time</th><th>Admin</th><th>Action</th><th>Details</th><th>IP / Location</th></tr></thead>
                <tbody>
                <?php foreach ($data as $r): ?>
                    <tr>
                        <td class="cell-sub" style="white-space:nowrap;"><?php echo date('d M Y, h:i A', strtotime($r['at_time'])); ?></td>
                        <td class="cell-main"><?php echo e($r['admin_name']); ?></td>
                        <td><span class="badge gray"><?php echo e(ucwords(str_replace('_', ' ', $r['act']))); ?></span></td>
                        <td class="cell-sub" style="max-width:380px;"><?php echo e(mb_strimwidth((string)$r['details'], 0, 130, '…')); ?><?php echo $r['student'] ? ' · ' . e($r['student']) : ''; ?></td>
                        <td class="cell-sub"><?php echo e($r['ip'] ?: '-'); ?><?php echo $r['loc'] ? '<br><span style="font-size:.7rem;">' . e($r['loc']) . '</span>' : ''; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="alert alert-info" style="margin:14px 16px;"><i class="fas fa-circle-info"></i><span>Showing the latest 200 events - use <a href="admin-activity.php">Activity Log</a> for full filtering, or Export for everything in range.</span></div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
