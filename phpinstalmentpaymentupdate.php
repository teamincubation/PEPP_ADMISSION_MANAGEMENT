<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('installments');

// Handle AJAX triggers for Scan & Send reminders
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'message' => 'CSRF verification failed.']);
        exit();
    }

    $ajax_action = $_POST['ajax_action'];
    require_once 'includes/session_cron.php';

    if ($ajax_action === 'scan') {
        $eligible = get_eligible_whatsapp_reminders($pdo);
        $up_7d = 0; $up_3d = 0; $up_0d = 0;
        $ov_3d = 0; $ov_7d = 0;

        foreach ($eligible as $item) {
            if ($item['is_overdue']) {
                if ($item['stage'] === 'overdue_3d') $ov_3d++;
                elseif ($item['stage'] === 'overdue_7d') $ov_7d++;
            } else {
                if ($item['stage'] === '7d') $up_7d++;
                elseif ($item['stage'] === '3d') $up_3d++;
                elseif ($item['stage'] === '0d') $up_0d++;
            }
        }

        echo json_encode([
            'ok' => true,
            'up_7d' => $up_7d,
            'up_3d' => $up_3d,
            'up_0d' => $up_0d,
            'ov_3d' => $ov_3d,
            'ov_7d' => $ov_7d,
            'total' => count($eligible)
        ]);
        exit();
    }

    if ($ajax_action === 'send') {
        $eligible = get_eligible_whatsapp_reminders($pdo);
        $up_7d_q = 0; $up_3d_q = 0; $up_0d_q = 0;
        $ov_3d_q = 0; $ov_7d_q = 0;
        
        $skipped = 0;
        $failed = 0;

        // Fetch active public banking details
        $public_banking_details = '';
        try {
            $public_accs = $pdo->query("SELECT account_name, banking_details FROM payment_accounts WHERE is_public = 1 AND status = 'active' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
            $details_arr = [];
            foreach ($public_accs as $pa) {
                $details_arr[] = $pa['account_name'] . ($pa['banking_details'] ? " (" . $pa['banking_details'] . ")" : "");
            }
            $public_banking_details = implode(" or ", $details_arr);
        } catch (Exception $e) {}

        require_once 'includes/communication/CommunicationEngine.php';
        $commEngine = CommunicationEngine::getInstance($pdo);

        foreach ($eligible as $inst) {
            $stage = $inst['stage'];
            try {
                $pdo->beginTransaction();

                // Re-verify tracking to prevent concurrent race conditions
                $stmtCheck = $pdo->prepare("SELECT status FROM installment_whatsapp_reminders WHERE installment_id = ? AND reminder_stage = ?");
                $stmtCheck->execute([$inst['installment_id'], $stage]);
                $existingStatus = $stmtCheck->fetchColumn();

                if ($existingStatus === 'sent' || $existingStatus === 'queued') {
                    $pdo->rollBack();
                    $skipped++;
                    continue;
                }

                if ($existingStatus === false) {
                    $stmtTrack = $pdo->prepare("INSERT INTO installment_whatsapp_reminders (installment_id, reminder_stage, status, last_attempted_at) VALUES (?, ?, 'queued', NOW())");
                    $stmtTrack->execute([$inst['installment_id'], $stage]);
                } else {
                    $stmtTrack = $pdo->prepare("UPDATE installment_whatsapp_reminders SET status = 'queued', last_attempted_at = NOW() WHERE installment_id = ? AND reminder_stage = ? AND status NOT IN ('queued', 'sent')");
                    $stmtTrack->execute([$inst['installment_id'], $stage]);
                    if ($stmtTrack->rowCount() === 0) {
                        $pdo->rollBack();
                        $skipped++;
                        continue;
                    }
                }

                $wa_phone = preg_replace('/\D/', '', $inst['whatsapp_country_code'] . $inst['whatsapp_number']);
                if (empty($wa_phone)) {
                    $pdo->rollBack();
                    $failed++;
                    continue;
                }

                $ord = (int)$inst['instalment_number'];
                if ($ord === 1) $ordStr = "1st";
                elseif ($ord === 2) $ordStr = "2nd";
                elseif ($ord === 3) $ordStr = "3rd";
                else $ordStr = $ord . "th";

                $context = [
                    'student_name' => $inst['student_name'] ?? '',
                    'course_name' => $inst['pepp_course'] ?? '',
                    'academic_year' => $inst['pepp_academic_year'] ?? '',
                    'installment_number' => $ordStr,
                    'installment_amount' => number_format((float)$inst['amount']),
                    'installment_due_date' => date('d M Y', strtotime($inst['due_date']))
                ];
                
                if (!$inst['is_overdue']) {
                    $context['banking_details'] = $public_banking_details;
                }

                $eventName = $inst['is_overdue'] ? 'installment_overdue' : 'installment_reminder';
                $queueId = $commEngine->sendEventNotification(
                    $eventName,
                    $wa_phone,
                    $context,
                    $admin_username
                );

                if ($queueId) {
                    $stmtUpd = $pdo->prepare("UPDATE installment_whatsapp_reminders SET queue_id = ? WHERE installment_id = ? AND reminder_stage = ?");
                    $stmtUpd->execute([$queueId, $inst['installment_id'], $stage]);
                    $pdo->commit();

                    if ($inst['is_overdue']) {
                        if ($stage === 'overdue_3d') $ov_3d_q++;
                        elseif ($stage === 'overdue_7d') $ov_7d_q++;
                    } else {
                        if ($stage === '7d') $up_7d_q++;
                        elseif ($stage === '3d') $up_3d_q++;
                        elseif ($stage === '0d') $up_0d_q++;
                    }
                } else {
                    $pdo->rollBack();
                    $failed++;
                }

            } catch (Exception $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Failed to manually queue reminder for {$inst['installment_id']}: " . $ex->getMessage());
                $failed++;
            }
        }

        echo json_encode([
            'ok' => true,
            'up_7d_q' => $up_7d_q,
            'up_3d_q' => $up_3d_q,
            'up_0d_q' => $up_0d_q,
            'ov_3d_q' => $ov_3d_q,
            'ov_7d_q' => $ov_7d_q,
            'total_queued' => ($up_7d_q + $up_3d_q + $up_0d_q + $ov_3d_q + $ov_7d_q),
            'skipped' => $skipped,
            'failed' => $failed
        ]);
        exit();
    }
}


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
    $upcoming_warning_count = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NULL AND rejected_at IS NULL AND due_date <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)")->fetchColumn();

    switch ($tab) {
        case 'pending':  $cond = "i.status = 'pending' AND i.paid_date IS NOT NULL"; break;
        case 'approved': $cond = "i.status IN ('approved','paid')"; break;
        case 'rejected': $cond = "(i.status = 'rejected' OR (i.status = 'pending' AND i.paid_date IS NULL AND i.rejected_at IS NOT NULL))"; break;
        case 'upcoming': $cond = "i.status = 'pending' AND i.paid_date IS NULL AND i.rejected_at IS NULL"; break;
        default:         $cond = "1=1";
    }

    $order = "CASE WHEN i.status = 'pending' AND i.paid_date IS NOT NULL THEN 0 ELSE 1 END, i.updated_at DESC, i.due_date ASC";
    if ($tab === 'upcoming') {
        $order = "CASE WHEN i.due_date < CURDATE() THEN 0 ELSE 1 END, i.due_date ASC";
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

    // Fetch last reminder status and timestamps
    $last_reminders = [];
    if (!empty($rows)) {
        $instIds = array_map(function($r) { return (int)$r['id']; }, $rows);
        $inClause = implode(',', $instIds);
        
        $remStmt = $pdo->query("
            SELECT r1.* 
            FROM installment_whatsapp_reminders r1
            INNER JOIN (
                SELECT installment_id, MAX(last_attempted_at) as max_time
                FROM installment_whatsapp_reminders
                WHERE installment_id IN ($inClause)
                GROUP BY installment_id
            ) r2 ON r1.installment_id = r2.installment_id AND r1.last_attempted_at = r2.max_time
        ");
        foreach ($remStmt->fetchAll() as $rem) {
            $last_reminders[$rem['installment_id']] = $rem;
        }
    }
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
        <?php if ($tab === 'upcoming' && whatsapp_outbound_mode($pdo) === 'meta_api'): ?>
            <button class="btn btn-sm" id="btn-manual-reminders" style="margin-left:15px; background:#059669; border-color:#059669; color:#fff; display:inline-flex; align-items:center; gap:6px; font-weight:600;"><i class="fab fa-whatsapp"></i> Review & Send WhatsApp Reminders</button>
        <?php endif; ?>
        <div class="head-right tabs">
            <a class="tab <?php echo $tab === 'pending' ? 'active' : ''; ?>" href="?page=pending">Pending review <span class="count"><?php echo $stats['pending']; ?></span></a>
            <a class="tab <?php echo $tab === 'upcoming' ? 'active' : ''; ?>" href="?page=upcoming" style="<?php echo $upcoming_warning_count > 0 ? 'border-bottom: 2px solid #ef4444; color: #ef4444; font-weight: 700;' : ''; ?>">Upcoming <span class="count" style="<?php echo $upcoming_warning_count > 0 ? 'background:#ef4444; color:#fff;' : ''; ?>"><?php echo $stats['upcoming']; ?></span></a>
            <a class="tab <?php echo $tab === 'approved' ? 'active' : ''; ?>" href="?page=approved">Approved</a>
            <a class="tab <?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="?page=rejected">Rejected</a>
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
                <tr<?php echo ($tab === 'upcoming' && $isOverdue) ? ' style="background:#fef2f2; border-left: 4px solid #ef4444;"' : ''; ?>>
                    <td>
                        <div class="cell-main"><?php echo e($r['student_name']); ?></div>
                        <div class="cell-sub"><?php echo e($r['user_id']); ?> · <?php echo e($r['whatsapp_country_code']); ?> <?php echo format_credential($r['whatsapp_number'], 'phone'); ?></div>
                        <?php
                        $lastRem = $last_reminders[$r['id']] ?? null;
                        if ($lastRem) {
                            $stageMap = [
                                '7d' => 'Upcoming 7-day',
                                '3d' => 'Upcoming 3-day',
                                '0d' => 'Due Today',
                                'overdue_3d' => 'Overdue 3-day',
                                'overdue_7d' => 'Overdue 7-day'
                            ];
                            $stageLabel = $stageMap[$lastRem['reminder_stage']] ?? $lastRem['reminder_stage'];
                            $timeStr = date('d M Y, h:i A', strtotime($lastRem['last_attempted_at']));
                            
                            $statusLabel = 'Never';
                            $statusColor = '#9ca3af';
                            if ($lastRem['status'] === 'queued') {
                                $statusLabel = 'Queued';
                                $statusColor = '#d97706';
                            } elseif ($lastRem['status'] === 'sent') {
                                $statusLabel = 'Sent';
                                $statusColor = '#16a34a';
                            } elseif ($lastRem['status'] === 'failed') {
                                $statusLabel = 'Failed';
                                $statusColor = '#dc2626';
                            }
                            echo '<div class="cell-sub" style="font-size:0.7rem; margin-top:3px; color:#4b5563;"><i class="fab fa-whatsapp" style="color:#25d366;"></i> Last Reminder: ' . htmlspecialchars($stageLabel) . ' • ' . $timeStr . ' <strong style="color:' . $statusColor . ';">(' . $statusLabel . ')</strong></div>';
                        } else {
                            echo '<div class="cell-sub" style="font-size:0.7rem; margin-top:3px; color:#9ca3af;"><i class="fab fa-whatsapp"></i> Last Reminder: <strong style="color:#9ca3af;">(Never)</strong></div>';
                        }
                        ?>
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
                    <td>
                        <?php if ($tab === 'upcoming' && $isOverdue): ?>
                            <span class="badge red" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-triangle-exclamation"></i> Overdue <?php echo (int)$r['days_overdue']; ?>d</span>
                        <?php else: ?>
                            <span class="badge <?php echo $badge; ?>"><?php echo $label; ?></span>
                        <?php endif; ?>
                        <?php if ($r['approved_by'] || $r['rejected_by']): ?><div class="cell-sub">by <?php echo e($r['approved_by'] ?: $r['rejected_by']); ?></div><?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if ($tab === 'upcoming'): ?>
                            <?php if (whatsapp_outbound_mode($pdo) === 'meta_api'): ?>
                                <span class="badge blue" style="font-size:0.65rem;" title="Automated reminders via Meta API"><i class="fas fa-robot"></i> Auto</span>
                            <?php else: ?>
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

<!-- Custom Modal for WhatsApp Reminders Review -->
<div id="reminder-modal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); backdrop-filter:blur(4px); z-index:9999; align-items:center; justify-content:center;">
    <div class="modal-card" style="background:#fff; border-radius:12px; width:100%; max-width:480px; box-shadow:0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); border:1px solid #e2e8f0; overflow:hidden; animation: slideIn 0.2s ease-out;">
        <div style="padding:20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:10px;">
            <span style="background:#dcfce7; color:#15803d; width:36px; height:36px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:1.1rem;"><i class="fab fa-whatsapp"></i></span>
            <div>
                <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:#0f172a;">WhatsApp Reminder Review</h3>
                <p style="margin:0; font-size:0.75rem; color:#64748b;">Read-only eligibility check results</p>
            </div>
        </div>
        
        <div style="padding:20px; color:#334155;" id="modal-content-panel">
            <!-- Loading State -->
            <div id="modal-loading" style="text-align:center; padding:30px 0;">
                <i class="fas fa-spinner fa-spin" style="font-size:2rem; color:#059669;"></i>
                <p style="margin-top:10px; font-size:0.85rem; color:#64748b;">Scanning database for eligible reminders...</p>
            </div>

            <!-- Review State -->
            <div id="modal-review" style="display:none;">
                <div style="margin-bottom:15px;">
                    <strong style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em; color:#475569; display:block; margin-bottom:8px;">Upcoming / Due</strong>
                    <div style="display:flex; flex-direction:column; gap:6px; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #f1f5f9; font-size:0.9rem;">
                        <div style="display:flex; justify-content:between; width:100%; align-items:center;">
                            <span>7-day upcoming reminders:</span>
                            <strong style="margin-left:auto;" id="count-up-7d">0</strong>
                        </div>
                        <div style="display:flex; justify-content:between; width:100%; align-items:center;">
                            <span>3-day upcoming reminders:</span>
                            <strong style="margin-left:auto;" id="count-up-3d">0</strong>
                        </div>
                        <div style="display:flex; justify-content:between; width:100%; align-items:center;">
                            <span>Due today reminders:</span>
                            <strong style="margin-left:auto;" id="count-up-0d">0</strong>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:20px;">
                    <strong style="font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em; color:#475569; display:block; margin-bottom:8px;">Overdue</strong>
                    <div style="display:flex; flex-direction:column; gap:6px; background:#fef2f2; padding:10px; border-radius:8px; border:1px solid #fee2e2; font-size:0.9rem; color:#991b1b;">
                        <div style="display:flex; justify-content:between; width:100%; align-items:center;">
                            <span>3-day overdue reminders:</span>
                            <strong style="margin-left:auto;" id="count-ov-3d">0</strong>
                        </div>
                        <div style="display:flex; justify-content:between; width:100%; align-items:center;">
                            <span>7-day overdue reminders:</span>
                            <strong style="margin-left:auto;" id="count-ov-7d">0</strong>
                        </div>
                    </div>
                </div>

                <div style="display:flex; justify-content:between; align-items:center; background:#f0fdf4; padding:12px; border-radius:8px; border:1px solid #dcfce7; margin-bottom:20px; font-weight:700; color:#166534;">
                    <span>Total reminders to be queued:</span>
                    <span style="font-size:1.1rem;" id="count-total">0</span>
                </div>
            </div>

            <!-- Result State -->
            <div id="modal-result" style="display:none;">
                <div style="text-align:center; margin-bottom:20px;">
                    <span style="background:#dcfce4; color:#15803d; width:48px; height:48px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:1.5rem; margin-bottom:10px;"><i class="fas fa-check-circle"></i></span>
                    <h4 style="margin:0; font-size:1.05rem; font-weight:700; color:#0f172a;">Reminder Dispatch Complete</h4>
                    <p style="margin:0; font-size:0.8rem; color:#64748b;">The reminders have been successfully queued.</p>
                </div>
                
                <div style="display:flex; flex-direction:column; gap:6px; background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #f1f5f9; font-size:0.9rem; margin-bottom:20px;">
                    <div style="display:flex; justify-content:between; width:100%; align-items:center;">
                        <span>Upcoming 7-day queued:</span>
                        <strong style="margin-left:auto;" id="result-up-7d">0</strong>
                    </div>
                    <div style="display:flex; justify-content:between; width:100%; align-items:center;">
                        <span>Upcoming 3-day queued:</span>
                        <strong style="margin-left:auto;" id="result-up-3d">0</strong>
                    </div>
                    <div style="display:flex; justify-content:between; width:100%; align-items:center;">
                        <span>Due today queued:</span>
                        <strong style="margin-left:auto;" id="result-up-0d">0</strong>
                    </div>
                    <div style="display:flex; justify-content:between; width:100%; align-items:center; color:#991b1b;">
                        <span>Overdue 3-day queued:</span>
                        <strong style="margin-left:auto;" id="result-ov-3d">0</strong>
                    </div>
                    <div style="display:flex; justify-content:between; width:100%; align-items:center; color:#991b1b;">
                        <span>Overdue 7-day queued:</span>
                        <strong style="margin-left:auto;" id="result-ov-7d">0</strong>
                    </div>
                    <hr style="border:0; border-top:1px solid #e2e8f0; margin:6px 0;">
                    <div style="display:flex; justify-content:between; width:100%; align-items:center; font-weight:700;">
                        <span>Total successfully queued:</span>
                        <strong style="margin-left:auto; color:#15803d;" id="result-total">0</strong>
                    </div>
                    <div style="display:flex; justify-content:between; width:100%; align-items:center; color:#64748b;">
                        <span>Already processed/skipped:</span>
                        <strong style="margin-left:auto;" id="result-skipped">0</strong>
                    </div>
                    <div style="display:flex; justify-content:between; width:100%; align-items:center; color:#ef4444;">
                        <span>Ineligible/Failed:</span>
                        <strong style="margin-left:auto;" id="result-failed">0</strong>
                    </div>
                </div>
            </div>
        </div>

        <div style="padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:end; gap:10px;">
            <button class="btn btn-outline" id="btn-modal-cancel" style="padding:6px 12px; font-size:0.85rem;">Cancel</button>
            <button class="btn btn-primary" id="btn-modal-confirm" style="padding:6px 12px; font-size:0.85rem; background:#059669; border-color:#059669; display:none;">Confirm & Send</button>
            <button class="btn btn-primary" id="btn-modal-close" style="padding:6px 12px; font-size:0.85rem; display:none;">Close</button>
        </div>
    </div>
</div>

<input type="hidden" id="csrf_token" value="<?php echo csrf_token(); ?>">

<style>
@keyframes slideIn {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-overlay {
    display: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnTrigger = document.getElementById('btn-manual-reminders');
    const modal = document.getElementById('reminder-modal');
    const btnCancel = document.getElementById('btn-modal-cancel');
    const btnConfirm = document.getElementById('btn-modal-confirm');
    const btnClose = document.getElementById('btn-modal-close');
    
    const loadingState = document.getElementById('modal-loading');
    const reviewState = document.getElementById('modal-review');
    const resultState = document.getElementById('modal-result');
    const csrfToken = document.getElementById('csrf_token').value;

    if (btnTrigger) {
        btnTrigger.addEventListener('click', function() {
            // Show modal and loading state
            modal.style.display = 'flex';
            loadingState.style.display = 'block';
            reviewState.style.display = 'none';
            resultState.style.display = 'none';
            btnConfirm.style.display = 'none';
            btnCancel.style.display = 'block';
            btnClose.style.display = 'none';
            
            // Fetch eligibility scan
            const formData = new FormData();
            formData.append('ajax_action', 'scan');
            formData.append('csrf_token', csrfToken);
            
            fetch('phpinstalmentpaymentupdate.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                loadingState.style.display = 'none';
                if (data.ok) {
                    reviewState.style.display = 'block';
                    document.getElementById('count-up-7d').innerText = data.up_7d;
                    document.getElementById('count-up-3d').innerText = data.up_3d;
                    document.getElementById('count-up-0d').innerText = data.up_0d;
                    document.getElementById('count-ov-3d').innerText = data.ov_3d;
                    document.getElementById('count-ov-7d').innerText = data.ov_7d;
                    document.getElementById('count-total').innerText = data.total;
                    
                    if (data.total > 0) {
                        btnConfirm.style.display = 'block';
                    }
                } else {
                    alert('Error: ' + data.message);
                    modal.style.display = 'none';
                }
            })
            .catch(err => {
                loadingState.style.display = 'none';
                alert('Connection error occurred.');
                modal.style.display = 'none';
            });
        });
    }

    if (btnCancel) {
        btnCancel.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    }

    if (btnConfirm) {
        btnConfirm.addEventListener('click', function() {
            // Switch to loading
            reviewState.style.display = 'none';
            loadingState.style.display = 'block';
            btnConfirm.style.display = 'none';
            btnCancel.style.display = 'none';
            
            const formData = new FormData();
            formData.append('ajax_action', 'send');
            formData.append('csrf_token', csrfToken);
            
            fetch('phpinstalmentpaymentupdate.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                loadingState.style.display = 'none';
                if (data.ok) {
                    resultState.style.display = 'block';
                    document.getElementById('result-up-7d').innerText = data.up_7d_q;
                    document.getElementById('result-up-3d').innerText = data.up_3d_q;
                    document.getElementById('result-up-0d').innerText = data.up_0d_q;
                    document.getElementById('result-ov-3d').innerText = data.ov_3d_q;
                    document.getElementById('result-ov-7d').innerText = data.ov_7d_q;
                    document.getElementById('result-total').innerText = data.total_queued;
                    document.getElementById('result-skipped').innerText = data.skipped;
                    document.getElementById('result-failed').innerText = data.failed;
                    
                    btnClose.style.display = 'block';
                } else {
                    alert('Error: ' + data.message);
                    modal.style.display = 'none';
                }
            })
            .catch(err => {
                loadingState.style.display = 'none';
                alert('Connection error occurred.');
                modal.style.display = 'none';
            });
        });
    }

    if (btnClose) {
        btnClose.addEventListener('click', function() {
            modal.style.display = 'none';
            window.location.reload(); // Reload page to update "Last WhatsApp Reminder" labels
        });
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>

