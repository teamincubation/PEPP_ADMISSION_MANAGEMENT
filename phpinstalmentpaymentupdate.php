<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('installments');

/* Installment payments monitor.
   Data flow: students submit payments on installmentpayment.php, which sets
   paid_date + payment_reference on instalment_details (status stays
   'pending'). Pending review = status 'pending' AND paid_date IS NOT NULL.
   Detailed review (approve / reject / extend access) happens on
   payment-review.php, linked from every row. */

$tab = $_GET['page'] ?? 'pending';
if (!in_array($tab, ['pending', 'approved', 'rejected', 'upcoming', 'all'], true)) $tab = 'pending';

$stats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'upcoming' => 0, 'collected' => 0.0];
$rows = [];
$load_error = '';

try {
    /* A rejected submission is reset to pending with paid_date cleared so the
       student can re-submit; rejected_at marks the rejection. */
    $stats['pending']  = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NOT NULL")->fetchColumn();
    $stats['approved'] = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status IN ('approved','paid')")->fetchColumn();
    $stats['rejected'] = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'rejected' OR (status = 'pending' AND paid_date IS NULL AND rejected_at IS NOT NULL)")->fetchColumn();
    $stats['upcoming'] = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NULL AND rejected_at IS NULL")->fetchColumn();
    $stats['collected'] = (float)$pdo->query("SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0) FROM instalment_details WHERE status IN ('approved','paid')")->fetchColumn();

    switch ($tab) {
        case 'pending':  $cond = "i.status = 'pending' AND i.paid_date IS NOT NULL"; break;
        case 'approved': $cond = "i.status IN ('approved','paid')"; break;
        case 'rejected': $cond = "(i.status = 'rejected' OR (i.status = 'pending' AND i.paid_date IS NULL AND i.rejected_at IS NOT NULL))"; break;
        case 'upcoming': $cond = "i.status = 'pending' AND i.paid_date IS NULL AND i.rejected_at IS NULL"; break;
        default:         $cond = "1=1";
    }

    $order = "CASE WHEN i.status = 'pending' AND i.paid_date IS NOT NULL THEN 0 ELSE 1 END, i.updated_at DESC, i.due_date ASC";
    if ($tab === 'upcoming') {
        $order = "i.due_date ASC";
    }

    /* NOTE: u.whatsapp_number is used for contact (the old page read u.phone,
       which register.php never filled, so it was always empty). */
    $stmt = $pdo->query("
        SELECT i.*,
               u.name AS student_name, u.email AS student_email,
               u.whatsapp_country_code, u.whatsapp_number,
               u.pepp_course, u.pepp_academic_year, u.course_duration_date,
               DATEDIFF(CURDATE(), i.due_date) AS days_overdue
        FROM instalment_details i
        JOIN users u ON u.user_id = i.user_id
        WHERE $cond
        ORDER BY $order
        LIMIT 100
    ");
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Installment list: ' . $e->getMessage());
    $load_error = 'Could not load installment data.';
}

// Fetch WhatsApp reminder template and public payment accounts for the compose link
$reminder_template = '';
$public_banking_details = '';
try {
    $reminder_template = $pdo->query("SELECT setting_value FROM admin_settings WHERE setting_name = 'installment_reminder_message'")->fetchColumn() ?: '';
    
    // Fetch public payment accounts
    $public_accs = $pdo->query("SELECT account_name, banking_details FROM payment_accounts WHERE is_public = 1 AND status = 'active' LIMIT 2")->fetchAll();
    $details_arr = [];
    foreach ($public_accs as $pa) {
        $details_arr[] = $pa['account_name'] . ($pa['banking_details'] ? " (" . $pa['banking_details'] . ")" : "");
    }
    $public_banking_details = implode(" or ", $details_arr);
} catch (Exception $e) {}

$active_page = 'installments';
$page_title  = 'Installment Payments';
$page_sub    = 'Submissions arriving from installmentpayment.php';
include 'includes/admin_nav.php';
?>

<?php if ($load_error): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($load_error); ?></span></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Pending Review</span><span class="stat-icon amber"><i class="fas fa-hourglass-half"></i></span></div>
        <div class="stat-value"><?php echo $stats['pending']; ?></div>
        <div class="stat-hint">Paid, waiting for approval</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Approved</span><span class="stat-icon green"><i class="fas fa-circle-check"></i></span></div>
        <div class="stat-value"><?php echo $stats['approved']; ?></div>
        <div class="stat-hint">Verified payments</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Rejected</span><span class="stat-icon red"><i class="fas fa-circle-xmark"></i></span></div>
        <div class="stat-value"><?php echo $stats['rejected']; ?></div>
        <div class="stat-hint">Need re-submission</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Upcoming / Unpaid</span><span class="stat-icon blue"><i class="fas fa-calendar"></i></span></div>
        <div class="stat-value"><?php echo $stats['upcoming']; ?></div>
        <div class="stat-hint">Scheduled installments</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Collected</span><span class="stat-icon violet"><i class="fas fa-indian-rupee-sign"></i></span></div>
        <div class="stat-value">₹<?php echo number_format($stats['collected'], 0); ?></div>
        <div class="stat-hint">Total installment revenue</div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--pink-soft);color:var(--pink-ink);"><i class="fas fa-money-bill-wave"></i></span>
        <h2>Installments</h2>
        <div class="head-right tabs">
            <a class="tab <?php echo $tab === 'pending' ? 'active' : ''; ?>" href="?page=pending">Pending review <span class="count"><?php echo $stats['pending']; ?></span></a>
            <a class="tab <?php echo $tab === 'approved' ? 'active' : ''; ?>" href="?page=approved">Approved</a>
            <a class="tab <?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="?page=rejected">Rejected</a>
            <a class="tab <?php echo $tab === 'upcoming' ? 'active' : ''; ?>" href="?page=upcoming">Upcoming</a>
            <a class="tab <?php echo $tab === 'all' ? 'active' : ''; ?>" href="?page=all">All</a>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($rows)): ?>
            <div class="empty-state"><i class="fas fa-circle-check"></i><p>Nothing here right now.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th>Student</th><th>Course</th><th>Inst.</th><th>Amount</th><th>Due</th><th>Paid</th><th>Proof</th><th>Status</th><th style="text-align:right;">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $st = $r['status'];
                $wasRejected = ($st === 'pending' && !$r['paid_date'] && !empty($r['rejected_at']));
                $isOverdue = $st === 'pending' && !$r['paid_date'] && !$wasRejected && (int)$r['days_overdue'] > 0;
                $badge = in_array($st, ['approved','paid']) ? 'green' : ($st === 'rejected' || $wasRejected ? 'red' : ($r['paid_date'] ? 'amber' : ($isOverdue ? 'red' : 'blue')));
                $label = in_array($st, ['approved','paid']) ? 'Approved' : ($st === 'rejected' ? 'Rejected' : ($wasRejected ? 'Awaiting re-payment' : ($r['paid_date'] ? 'Pending review' : ($isOverdue ? 'Overdue ' . (int)$r['days_overdue'] . 'd' : 'Upcoming'))));
            ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($r['student_name']); ?></div>
                        <div class="cell-sub"><?php echo e($r['user_id']); ?> · <?php echo e($r['whatsapp_country_code'] . ' ' . $r['whatsapp_number']); ?></div>
                    </td>
                    <td>
                        <div style="font-size:.8rem;font-weight:600;"><?php echo e($r['pepp_course']); ?></div>
                        <div class="cell-sub"><?php echo e($r['pepp_academic_year']); ?></div>
                    </td>
                    <td class="cell-main">#<?php echo (int)$r['instalment_number']; ?></td>
                    <td>₹<?php echo number_format((float)$r['amount'], 0); ?>
                        <?php if ($r['paid_amount'] && (float)$r['paid_amount'] !== (float)$r['amount']): ?>
                            <div class="cell-sub">paid ₹<?php echo number_format((float)$r['paid_amount'], 0); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="cell-main" style="font-size:0.85rem; font-weight:600;"><?php echo date('d M Y', strtotime($r['due_date'])); ?></div>
                        <?php
                        $due_time = strtotime($r['due_date']);
                        $today_time = strtotime(date('Y-m-d'));
                        $diff_days = (int)round(($due_time - $today_time) / 86400);
                        
                        if ($r['status'] === 'pending' && !$r['paid_date']) {
                            if ($diff_days > 0) {
                                echo '<div class="cell-sub" style="font-size:0.75rem; color:#475569;">' . $diff_days . ' days pending</div>';
                            } elseif ($diff_days === 0) {
                                echo '<div class="cell-sub" style="font-size:0.75rem; color:#ea580c; font-weight:700;">Due today</div>';
                            } else {
                                echo '<div class="cell-sub" style="font-size:0.75rem; color:#dc2626; font-weight:700;">Overdue ' . abs($diff_days) . ' days</div>';
                            }
                            
                            if ($diff_days <= 10) {
                                $label_bg = $diff_days < 0 ? '#fee2e2' : '#fef3c7';
                                $label_color = $diff_days < 0 ? '#991b1b' : '#92400e';
                                $label_text = $diff_days < 0 ? 'Overdue' : 'Due in ' . $diff_days . 'd';
                                if ($diff_days === 0) { $label_bg = '#ffedd5'; $label_color = '#c2410c'; $label_text = 'Due Today'; }
                                echo '<span class="badge" style="background:' . $label_bg . '; color:' . $label_color . '; font-size:0.7rem; font-weight:bold; padding:2px 6px; border-radius:4px; margin-top:4px; display:inline-block;">' . $label_text . '</span>';
                            }
                        }
                        ?>
                    </td>
                    <td class="cell-sub"><?php echo $r['paid_date'] ? date('d M Y', strtotime($r['paid_date'])) : '-'; ?></td>
                    <td><?php if ($r['payment_reference']): ?><a class="proof-link" href="<?php echo e($r['payment_reference']); ?>" target="_blank"><i class="fas fa-receipt"></i> View</a><?php else: ?><span class="cell-sub">-</span><?php endif; ?></td>
                    <td><span class="badge <?php echo $badge; ?>"><?php echo $label; ?></span>
                        <?php if ($r['approved_by'] || $r['rejected_by']): ?><div class="cell-sub">by <?php echo e($r['approved_by'] ?: $r['rejected_by']); ?></div><?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if ($tab === 'upcoming'): ?>
                            <button class="btn btn-sm btn-whatsapp" onclick='sendReminder(<?php echo json_encode([
                                "name" => $r["student_name"],
                                "whatsapp_country_code" => $r["whatsapp_country_code"],
                                "whatsapp_number" => $r["whatsapp_number"],
                                "pepp_course" => $r["pepp_course"],
                                "instalment_number" => (int)$r["instalment_number"],
                                "amount" => (float)$r["amount"],
                                "due_date" => date("d M Y", strtotime($r["due_date"]))
                            ], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="Send WhatsApp Reminder"><i class="fab fa-whatsapp"></i> Reminder</button>
                        <?php endif; ?>
                        <?php if ($st === 'pending' && $r['paid_date']): ?>
                            <a class="btn btn-sm btn-primary" href="payment-review.php?id=<?php echo (int)$r['id']; ?>"><i class="fas fa-magnifying-glass"></i> Review</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline" href="payment-review.php?id=<?php echo (int)$r['id']; ?>" title="Open payment"><i class="fas fa-eye"></i></a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline" href="student-details.php?user_id=<?php echo urlencode($r['user_id']); ?>" title="Student profile"><i class="fas fa-user"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
var REMINDER_TEMPLATE = <?php echo json_encode($reminder_template); ?>;
var PUBLIC_BANKING = <?php echo json_encode($public_banking_details); ?>;

function sendReminder(r) {
    let tpl = REMINDER_TEMPLATE;
    if (!tpl) {
        tpl = "Dear {name}, this is a friendly reminder that your {installment_count} installment of ₹{amount} for the {course} course is due on {due_date}. Please pay to beneficiary {beneficiary} using banking details: {banking_details}. Thank you!";
    }
    
    // Parse ordinal prefix for instalment number
    let ord = r.instalment_number;
    if (ord === 1) ord = "1st";
    else if (ord === 2) ord = "2nd";
    else if (ord === 3) ord = "3rd";
    else ord = ord + "th";
    
    // Replace placeholders
    let msg = tpl
        .replace(/{name}/g, r.name)
        .replace(/{installment_count}/g, ord)
        .replace(/{amount}/g, Number(r.amount).toLocaleString('en-IN'))
        .replace(/{course}/g, r.pepp_course)
        .replace(/{due_date}/g, r.due_date)
        .replace(/{beneficiary}/g, PUBLIC_BANKING ? PUBLIC_BANKING.split(' (')[0] : 'PEPP Learning')
        .replace(/{banking_details}/g, PUBLIC_BANKING || '-');
        
    let cleanPhone = (r.whatsapp_country_code + r.whatsapp_number).replace(/\D/g, '');
    if (cleanPhone.length === 10) cleanPhone = '91' + cleanPhone;
    
    // Open whatsapp-notification.php compose page in a new tab
    window.open('whatsapp-notification.php?phone=' + encodeURIComponent(cleanPhone) + 
                '&name=' + encodeURIComponent(r.name) + 
                '&message=' + encodeURIComponent(msg), '_blank');
}
</script>

<div class="alert alert-info">
    <i class="fas fa-circle-info"></i>
    <span>Students pay through <strong>installmentpayment.php</strong> (sets paid date + uploads proof). <strong>Review</strong> approves (extends access) or rejects - a rejection clears the proof so the student can submit again.</span>
</div>

<?php include 'includes/admin_footer.php'; ?>
