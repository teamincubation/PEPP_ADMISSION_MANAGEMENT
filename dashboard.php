<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
if (!can_access('dashboard')) {
    $first_page = get_first_accessible_page_url();
    if ($first_page !== 'dashboard.php') {
        header('Location: ' . $first_page);
        exit();
    }
}
require_permission('dashboard');

/* ── Real-time statistics - clean revenue model ──────────────────
   Revenue rules (no double counting):
   • users.paid_amount        = REGISTRATION payment only
   • instalment_details       = every installment payment
     (approved/paid, amount received in paid_amount)
   • Net payable per student  = users.total_fee (already net of discount)
   • Outstanding              = net payable − collected (floored at 0)
   Run database-update.sql once so historical data follows these rules. */
$stats_error = '';
$total_students = $pending_approvals = $active_courses = $pending_payments = $pending_onboarding = 0;
$month_reg = $month_inst = $total_reg = $total_inst = $outstanding_total = 0.0;
$recent_applications = [];
$recent_payments = [];
$monthly_report = [];
$course_report = [];

try {
    $total_students    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved'")->fetchColumn();
    $pending_approvals = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $active_courses    = (int)$pdo->query("SELECT COUNT(*) FROM pepp_courses WHERE status = 'active'")->fetchColumn();
    $pending_payments  = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NOT NULL")->fetchColumn();
    $pending_onboarding = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved' AND (onboarding_status IS NULL OR onboarding_status <> 'completed')")->fetchColumn();

    // ── Collections: this month + all time ──────────────────────
    $month_reg = (float)$pdo->query("
        SELECT COALESCE(SUM(paid_amount), 0) FROM users
        WHERE status = 'approved'
          AND MONTH(paid_date) = MONTH(CURRENT_DATE()) AND YEAR(paid_date) = YEAR(CURRENT_DATE())
    ")->fetchColumn();
    $month_inst = (float)$pdo->query("
        SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0) FROM instalment_details
        WHERE status IN ('approved','paid')
          AND MONTH(paid_date) = MONTH(CURRENT_DATE()) AND YEAR(paid_date) = YEAR(CURRENT_DATE())
    ")->fetchColumn();
    $total_reg = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM users WHERE status = 'approved'")->fetchColumn();
    $total_inst = (float)$pdo->query("SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0) FROM instalment_details WHERE status IN ('approved','paid')")->fetchColumn();

    // ── Outstanding across active students ──────────────────────
    $outstanding_total = (float)$pdo->query("
        SELECT COALESCE(SUM(GREATEST(0, u.total_fee - (u.paid_amount + COALESCE(x.inst_paid, 0)))), 0)
        FROM users u
        LEFT JOIN (
            SELECT user_id, SUM(COALESCE(paid_amount, amount)) AS inst_paid
            FROM instalment_details WHERE status IN ('approved','paid') GROUP BY user_id
        ) x ON x.user_id = u.user_id
        WHERE u.status = 'approved' AND u.student_status = 'active' AND u.total_fee > 0
    ")->fetchColumn();

    // ── Monthly collection report (last 6 months) ────────────────
    $stmt = $pdo->query("
        SELECT m.ym, SUM(m.reg) AS reg, SUM(m.inst) AS inst
        FROM (
            SELECT DATE_FORMAT(paid_date, '%Y-%m') AS ym, paid_amount AS reg, 0 AS inst
            FROM users
            WHERE status = 'approved' AND paid_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            UNION ALL
            SELECT DATE_FORMAT(paid_date, '%Y-%m') AS ym, 0 AS reg, COALESCE(paid_amount, amount) AS inst
            FROM instalment_details
            WHERE status IN ('approved','paid') AND paid_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        ) m
        WHERE m.ym IS NOT NULL
        GROUP BY m.ym
        ORDER BY m.ym DESC
    ");
    $monthly_report = $stmt->fetchAll();

    // ── Per-course collection & outstanding ──────────────────────
    $stmt = $pdo->query("
        SELECT u.pepp_course,
               COUNT(*) AS students,
               SUM(u.total_fee) AS payable,
               SUM(u.paid_amount) AS reg_collected,
               SUM(COALESCE(x.inst_paid, 0)) AS inst_collected,
               SUM(GREATEST(0, u.total_fee - (u.paid_amount + COALESCE(x.inst_paid, 0)))) AS outstanding
        FROM users u
        LEFT JOIN (
            SELECT user_id, SUM(COALESCE(paid_amount, amount)) AS inst_paid
            FROM instalment_details WHERE status IN ('approved','paid') GROUP BY user_id
        ) x ON x.user_id = u.user_id
        WHERE u.status = 'approved' AND (u.student_status <> 'dropout' OR u.student_status IS NULL)
        GROUP BY u.pepp_course
        ORDER BY (SUM(u.paid_amount) + SUM(COALESCE(x.inst_paid, 0))) DESC
    ");
    $course_report = $stmt->fetchAll();

    // Recent registrations → linked to the right detail page per status
    $stmt = $pdo->query("
        SELECT user_id, name, pepp_course, pepp_academic_year, paid_amount, created_at, status
        FROM users ORDER BY created_at DESC LIMIT 6
    ");
    $recent_applications = $stmt->fetchAll();

    // Recent installment submissions (data arriving from installmentpayment.php)
    $stmt = $pdo->query("
        SELECT idt.id, idt.user_id, idt.instalment_number, idt.amount, idt.status, idt.paid_date, idt.updated_at,
               u.name AS student_name
        FROM instalment_details idt
        JOIN users u ON u.user_id = idt.user_id
        WHERE idt.paid_date IS NOT NULL
        ORDER BY idt.updated_at DESC LIMIT 6
    ");
    $recent_payments = $stmt->fetchAll();

} catch (Exception $e) {
    error_log('Dashboard stats: ' . $e->getMessage());
    $stats_error = 'Some statistics could not be loaded. Please check the database connection.';
}

$month_total = $month_reg + $month_inst;
$alltime_total = $total_reg + $total_inst;

$active_page = 'dashboard';
$page_title  = 'Dashboard';
$page_sub    = 'Overview of the PEPP Learning admission system';
include 'includes/admin_nav.php';
?>

<?php if ($stats_error): ?>
    <div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($stats_error); ?></span></div>
<?php endif; ?>

<!-- ── STATS ── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Active Students</span>
            <span class="stat-icon violet"><i class="fas fa-users"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($total_students); ?></div>
        <div class="stat-hint"><a href="studentpage.php">View all students</a></div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Pending Approvals</span>
            <span class="stat-icon amber"><i class="fas fa-user-clock"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($pending_approvals); ?></div>
        <div class="stat-hint"><a href="student-approval.php">Review applications</a></div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Payments to Review</span>
            <span class="stat-icon pink"><i class="fas fa-file-invoice-dollar"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($pending_payments); ?></div>
        <div class="stat-hint"><a href="phpinstalmentpaymentupdate.php">Review installments</a></div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Onboarding Queue</span>
            <span class="stat-icon teal"><i class="fas fa-handshake"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($pending_onboarding); ?></div>
        <div class="stat-hint"><a href="studentonboarding.php">Onboard students</a></div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Active Courses</span>
            <span class="stat-icon blue"><i class="fas fa-book-open"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($active_courses); ?></div>
        <div class="stat-hint"><a href="course-management.php">Manage courses</a></div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Collected (this month)</span>
            <span class="stat-icon green"><i class="fas fa-indian-rupee-sign"></i></span>
        </div>
        <div class="stat-value">₹<?php echo number_format($month_total, 0); ?></div>
        <div class="stat-hint">Registrations ₹<?php echo number_format($month_reg, 0); ?> &middot; Installments ₹<?php echo number_format($month_inst, 0); ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Collected (all time)</span>
            <span class="stat-icon teal"><i class="fas fa-vault"></i></span>
        </div>
        <div class="stat-value">₹<?php echo number_format($alltime_total, 0); ?></div>
        <div class="stat-hint">Registrations ₹<?php echo number_format($total_reg, 0); ?> &middot; Installments ₹<?php echo number_format($total_inst, 0); ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Outstanding (active)</span>
            <span class="stat-icon <?php echo $outstanding_total > 0 ? 'amber' : 'green'; ?>"><i class="fas fa-scale-balanced"></i></span>
        </div>
        <div class="stat-value">₹<?php echo number_format($outstanding_total, 0); ?></div>
        <div class="stat-hint">Yet to collect from active students</div>
    </div>
</div>

<!-- ── FEE COLLECTION REPORT ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-chart-column"></i></span>
        <h2>Fee Collection Report</h2>
        <div class="head-right"><span class="badge gray">Registration + approved installments · no double counting</span></div>
    </div>
    <div class="panel-body flush">
        <div class="table-wrap" style="border-bottom:1px solid var(--border);">
            <table class="data-table">
                <thead><tr><th>Month</th><th>Registrations</th><th>Installments</th><th>Total Collected</th></tr></thead>
                <tbody>
                <?php if (empty($monthly_report)): ?>
                    <tr><td colspan="4"><div class="empty-state" style="padding:22px;"><p>No collections in the last 6 months.</p></div></td></tr>
                <?php else: foreach ($monthly_report as $m): ?>
                    <tr>
                        <td class="cell-main"><?php echo date('M Y', strtotime($m['ym'] . '-01')); ?></td>
                        <td>₹<?php echo number_format((float)$m['reg'], 0); ?></td>
                        <td>₹<?php echo number_format((float)$m['inst'], 0); ?></td>
                        <td class="cell-main">₹<?php echo number_format((float)$m['reg'] + (float)$m['inst'], 0); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Course</th><th>Students</th><th>Net Payable</th><th>Collected</th><th>Outstanding</th><th>Progress</th></tr></thead>
                <tbody>
                <?php if (empty($course_report)): ?>
                    <tr><td colspan="6"><div class="empty-state" style="padding:22px;"><p>No approved students yet.</p></div></td></tr>
                <?php else: foreach ($course_report as $c):
                    $collected_c = (float)$c['reg_collected'] + (float)$c['inst_collected'];
                    $payable_c   = (float)$c['payable'];
                    $pct = $payable_c > 0 ? min(100, round($collected_c / $payable_c * 100)) : 100;
                ?>
                    <tr>
                        <td class="cell-main" style="max-width:260px;"><?php echo e($c['pepp_course']); ?></td>
                        <td><?php echo (int)$c['students']; ?></td>
                        <td>₹<?php echo number_format($payable_c, 0); ?></td>
                        <td>₹<?php echo number_format($collected_c, 0); ?>
                            <div class="cell-sub">Reg ₹<?php echo number_format((float)$c['reg_collected'], 0); ?> · Inst ₹<?php echo number_format((float)$c['inst_collected'], 0); ?></div></td>
                        <td><?php if ((float)$c['outstanding'] > 0): ?><span class="badge amber">₹<?php echo number_format((float)$c['outstanding'], 0); ?></span><?php else: ?><span class="badge green">Cleared</span><?php endif; ?></td>
                        <td style="min-width:120px;">
                            <div style="background:var(--card);border-radius:50px;height:8px;overflow:hidden;">
                                <div style="background:var(--green);height:100%;width:<?php echo $pct; ?>%;"></div>
                            </div>
                            <div class="cell-sub" style="margin-top:3px;"><?php echo $pct; ?>%</div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="alert alert-info" style="margin:14px 16px;">
            <i class="fas fa-circle-info"></i>
            <span>Net Payable uses each student's total fee after discount. If older students show ₹0 payable, run <strong>database-update.sql</strong> once to backfill it.</span>
        </div>
    </div>
</div>

<!-- ── QUICK ACTIONS ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon"><i class="fas fa-bolt"></i></span>
        <h2>Quick Actions</h2>
    </div>
    <div class="panel-body" style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="student-approval.php" class="btn btn-soft-amber"><i class="fas fa-user-check"></i> Review Approvals</a>
        <a href="add-student.php" class="btn btn-soft-violet"><i class="fas fa-user-plus"></i> Add Student</a>
        <a href="phpinstalmentpaymentupdate.php" class="btn btn-soft-blue"><i class="fas fa-money-bill-wave"></i> Installment Payments</a>
        <a href="studentonboarding.php" class="btn btn-soft-green"><i class="fas fa-handshake"></i> Onboarding</a>
        <a href="course-management.php" class="btn btn-outline"><i class="fas fa-book-open"></i> Courses</a>
        <a href="settings.php" class="btn btn-outline"><i class="fas fa-gear"></i> Settings</a>
    </div>
</div>

<!-- ── RECENT REGISTRATIONS (from register.php) ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon"><i class="fas fa-inbox"></i></span>
        <h2>Recent Registrations</h2>
        <div class="head-right">
            <span class="badge gray">Source: register.php</span>
            <a href="student-approval.php" class="btn btn-sm btn-outline">View all <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($recent_applications)): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No registrations yet.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th>Student</th><th>Course</th><th>Year</th><th>Paid</th><th>Status</th><th>Registered</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent_applications as $app):
                $status = $app['status'];
                $badge  = $status === 'approved' ? 'green' : ($status === 'rejected' ? 'red' : 'amber');
                // pending registrations open in the review page; approved students in the profile page
                $link = $status === 'pending'
                    ? 'nonapproval-studentdetails.php?id=' . urlencode($app['user_id'])
                    : 'student-details.php?user_id=' . urlencode($app['user_id']);
            ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($app['name']); ?></div>
                        <div class="cell-sub"><?php echo e($app['user_id']); ?></div>
                    </td>
                    <td><?php echo e($app['pepp_course']); ?></td>
                    <td><?php echo e($app['pepp_academic_year']); ?></td>
                    <td>₹<?php echo number_format((float)$app['paid_amount'], 0); ?></td>
                    <td><span class="badge <?php echo $badge; ?>"><?php echo ucfirst($status); ?></span></td>
                    <td class="cell-sub"><?php echo date('d M Y, h:i A', strtotime($app['created_at'])); ?></td>
                    <td><a class="btn btn-sm btn-outline" href="<?php echo $link; ?>"><i class="fas fa-eye"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── RECENT INSTALLMENT SUBMISSIONS (from installmentpayment.php) ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon"><i class="fas fa-receipt"></i></span>
        <h2>Recent Installment Submissions</h2>
        <div class="head-right">
            <span class="badge gray">Source: installmentpayment.php</span>
            <a href="phpinstalmentpaymentupdate.php" class="btn btn-sm btn-outline">View all <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($recent_payments)): ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>No installment submissions yet.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th>Student</th><th>Installment</th><th>Amount</th><th>Paid Date</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent_payments as $pay):
                $st = $pay['status'];
                $badge = in_array($st, ['approved','paid']) ? 'green' : ($st === 'rejected' ? 'red' : 'amber');
                $label = $st === 'pending' ? 'Pending review' : ucfirst($st);
            ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($pay['student_name']); ?></div>
                        <div class="cell-sub"><?php echo e($pay['user_id']); ?></div>
                    </td>
                    <td>#<?php echo (int)$pay['instalment_number']; ?></td>
                    <td>₹<?php echo number_format((float)$pay['amount'], 0); ?></td>
                    <td class="cell-sub"><?php echo $pay['paid_date'] ? date('d M Y', strtotime($pay['paid_date'])) : '-'; ?></td>
                    <td><span class="badge <?php echo $badge; ?>"><?php echo $label; ?></span></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="payment-review.php?id=<?php echo (int)$pay['id']; ?>" title="Review payment"><i class="fas fa-magnifying-glass"></i></a>
                        <a class="btn btn-sm btn-outline" href="student-details.php?user_id=<?php echo urlencode($pay['user_id']); ?>" title="Student profile"><i class="fas fa-user"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
