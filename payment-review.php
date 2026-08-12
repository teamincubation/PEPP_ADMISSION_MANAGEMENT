<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('installments');
require_once 'includes/invoice_helper.php';

/* Installment payment review.
   Fixes vs the old version:
   - NO LONGER adds the installment to users.paid_amount (that double-counted
     revenue - users.paid_amount is the registration payment only; installments
     are summed from instalment_details)
   - records the received amount in instalment_details.paid_amount
   - rejection RESETS the installment to a payable state (paid_date / proof
     cleared, status back to pending) so the student can actually re-submit
     through installmentpayment.php - previously a rejected row was stuck
     forever; the rejection itself stays recorded (rejected_by/at + remarks)
   - no longer marks the COURSE 'completed' just because payments finished
   - broken redirects to the non-existent installment-payment.php fixed
   - processed installments are viewable read-only (no more dead links)
   - direct WhatsApp messaging (wa.me) right after the action, like onboarding
   - CSRF protection + full audit logging */

$request_id = (int)($_GET['id'] ?? 0);
if (!$request_id) {
    header('Location: phpinstalmentpaymentupdate.php');
    exit();
}

$success_message = '';
$error_message   = '';
$whatsapp_url    = '';

function load_request($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT i.*,
               u.name AS student_name, u.email AS student_email,
               u.whatsapp_country_code, u.whatsapp_number,
               u.pepp_course, u.pepp_academic_year,
               u.paid_amount AS registration_payment,
               u.total_fee, u.discount_amount, u.payment_plan,
               u.course_duration_date AS current_access_end,
               pc.total_fee AS course_fee,
               DATEDIFF(CURDATE(), i.due_date) AS days_overdue
        FROM instalment_details i
        JOIN users u ON u.user_id = i.user_id
        LEFT JOIN pepp_courses pc ON pc.course_name = u.pepp_course
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

try {
    $req = load_request($pdo, $request_id);
} catch (Exception $e) {
    error_log('Payment review load: ' . $e->getMessage());
    $req = null;
}

if (!$req) {
    $active_page = 'installments';
    $page_title  = 'Payment Review';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span>Installment not found.</span></div>';
    echo '<a class="btn btn-outline" href="phpinstalmentpaymentupdate.php"><i class="fas fa-arrow-left"></i> Back to Installments</a>';
    include 'includes/admin_footer.php';
    exit();
}

$reviewable = ($req['status'] === 'pending' && $req['paid_date'] !== null);
$wa_phone = preg_replace('/\D/', '', $req['whatsapp_country_code'] . $req['whatsapp_number']);

/* ── POST: approve / reject ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } elseif (!$reviewable) {
        $error_message = 'This installment has already been processed.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'approve') {
                $payment_mode    = $_POST['payment_mode'] ?? '';
                $payment_account = !empty($_POST['payment_account_id']) ? (int)$_POST['payment_account_id'] : null;
                $received_amount = (float)($_POST['received_amount'] ?? $req['amount']);
                $new_access_end  = $_POST['course_due_date'] ?? '';
                $next_due_date   = $_POST['next_due_date'] ?? '';
                $admin_remarks   = trim($_POST['admin_remarks'] ?? '');

                if (!in_array($payment_mode, ['Online','Cash','100% Scholarship','Pay later'], true)) {
                    throw new Exception('Please select a valid payment mode.');
                }
                if (!$payment_account) throw new Exception('Please select the payment account that received the money.');
                if (!$new_access_end)  throw new Exception('Please set the new course access end date.');
                if ($received_amount <= 0) throw new Exception('Received amount must be greater than zero.');

                $pdo->beginTransaction();

                // 1. Approve the installment, record the received amount
                $stmt = $pdo->prepare("
                    UPDATE instalment_details SET
                        status = 'approved',
                        paid_amount = ?,
                        payment_mode = ?,
                        payment_account_id = ?,
                        admin_remarks = ?,
                        approved_by = ?,
                        approved_at = NOW(),
                        rejected_by = NULL,
                        rejected_at = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$received_amount, $payment_mode, $payment_account, $admin_remarks, $admin_username, $request_id]);

                // 2. Extend course access. IMPORTANT: users.paid_amount is NOT
                //    touched - installment revenue is summed from
                //    instalment_details, so adding it here double-counted it.
                $stmt = $pdo->prepare("
                    UPDATE users SET
                        course_duration_date = ?,
                        course_access_provided = 'yes',
                        updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$new_access_end, $req['user_id']]);

                // 3. Optionally adjust the next pending installment's due date
                if ($next_due_date) {
                    $stmt = $pdo->prepare("
                        UPDATE instalment_details SET due_date = ?, updated_at = NOW()
                        WHERE user_id = ? AND status = 'pending' AND paid_date IS NULL AND instalment_number > ?
                        ORDER BY instalment_number ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$next_due_date, $req['user_id'], $req['instalment_number']]);
                }

                $pdo->commit();

                status_log($pdo, $req['user_id'], 'payment_pending', 'payment_approved',
                    "Installment #{$req['instalment_number']} approved - ₹" . number_format($received_amount, 2) . " - access until {$new_access_end}", $admin_username);
                track_record($pdo, $req['user_id'], 'installment_approved',
                    "Installment #{$req['instalment_number']} (₹" . number_format($received_amount, 2) . ") approved; course access extended to {$new_access_end}", $admin_username);

                // ── 1. Automatic invoice generation (REUSE EXISTING ENGINE) ────────
                $inv_ok = false;
                $inv_msg = '';
                $inv_id = null;
                $inv_no = null;
                try {
                    [$inv_ok, $inv_msg, $inv_id, $inv_no] = generate_payment_invoice($pdo, [
                        'source' => 'installment', 'source_ref' => $request_id, 'user_id' => $req['user_id'],
                        'amount' => $received_amount, 'account_id' => $payment_account,
                        'payment_mode' => $payment_mode,
                        'paid_date' => $req['paid_date'] ?: date('Y-m-d'),
                        'instalment_number' => $req['instalment_number'],
                        'generated_by' => $admin_username, 'send_email' => true,
                    ]);
                } catch (Exception $invEx) {
                    error_log("Installment invoice generation error: " . $invEx->getMessage());
                }

                $inv_note = ($inv_ok && $inv_no)
                    ? ' Invoice ' . $inv_no . ' generated and emailed - <a href="invoice-pdf.php?id=' . (int)$inv_id . '">download PDF</a>.'
                    : '';

                // ── 2. Queue message via Communication Engine (Only AFTER valid invoice) ────────
                $formatted_amount = '₹' . number_format($received_amount, 0);
                $formatted_date = date('d M Y', strtotime($new_access_end));
                $msg = "*Installment Payment Approved!*\n"
                     . "Your installment #{$req['instalment_number']} of *{$formatted_amount}* has been approved.\n"
                     . "Your course access is extended until *{$formatted_date}*. Refresh your app to get continued access.\n\n"
                     . "Thank you!\n"
                     . "`PEPP Learning`";

                if (whatsapp_outbound_mode($pdo) === 'meta_api') {
                    // META API mode: dispatch WhatsApp notification only if invoice was successfully generated/retrieved
                    if ($inv_ok && !empty($inv_id)) {
                        try {
                            require_once 'includes/communication/CommunicationEngine.php';
                            $engine = CommunicationEngine::getInstance($pdo);
                            
                            $inst_num_raw = (int)($req['instalment_number'] ?? 1);
                            $inst_num_str = ($inst_num_raw === 1) ? '1st' : (($inst_num_raw === 2) ? '2nd' : (($inst_num_raw === 3) ? '3rd' : ($inst_num_raw . 'th')));

                            $context = [
                                'student_uid'        => $req['user_id'],
                                'student_name'       => $req['student_name'] ?? '',
                                'application_id'     => $req['user_id'],
                                'payment_amount'     => number_format($received_amount),
                                'invoice_number'     => $inv_no,
                                'invoice_id'         => $inv_id,
                                'installment_number' => $inst_num_str,
                                'paid_date'          => !empty($req['paid_date']) ? date('d M Y', strtotime($req['paid_date'])) : date('d M Y'),
                                'new_access_end'     => !empty($new_access_end) ? date('d M Y', strtotime($new_access_end)) : '',
                                'course_name'        => $req['pepp_course'] ?? '',
                                'balance_amount'     => $remaining ?? 0
                            ];
                            
                            $qId = $engine->sendEventNotification('payment_receipt', $wa_phone, $context, $admin_username);
                            if (!$qId) {
                                error_log("Payment approval WhatsApp skipped: payment_receipt template not configured for student {$req['user_id']}");
                            }
                        } catch (Exception $ex) { error_log('Payment approval WA error: ' . $ex->getMessage()); }
                    } else {
                        error_log("Payment approval WhatsApp skipped for student {$req['user_id']}: Invoice generation failed or returned empty ID.");
                    }
                    // No wa.me redirect in META mode
                } else {
                    // MANUAL mode: wa.me redirect only, no engine calls
                    $whatsapp_url = 'https://wa.me/' . $wa_phone . '?text=' . rawurlencode($msg);
                }

                // Send approval email
                if (file_exists('includes/peppian_notify.php')) {
                    require_once 'includes/peppian_notify.php';
                    try {
                        $amt_f = number_format($received_amount, 2);
                        $access_f = date('d M Y', strtotime($new_access_end));
                        $subj = "Payment Approved - Installment #{$req['instalment_number']}";
                        $head = "Installment Payment Confirmed";
                        $body = "<p>Dear {$req['student_name']},</p>
                                 <p>We are pleased to inform you that your installment payment for installment <strong>#{$req['instalment_number']}</strong> has been approved by our accounts desk.</p>
                                 <div style='background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:16px; margin:20px 0; font-size:14px;'>
                                     <table style='width:100%; border-collapse:collapse;'>
                                         <tr><td style='padding:6px 0; color:#166534;'>Amount Paid:</td><td style='padding:6px 0; font-weight:700; color:#166534;'>₹{$amt_f}</td></tr>
                                         <tr><td style='padding:6px 0; color:#166534;'>Payment Mode:</td><td style='padding:6px 0; font-weight:700; color:#166534;'>{$payment_mode}</td></tr>
                                         <tr><td style='padding:6px 0; color:#166534;'>Course Access Extended Until:</td><td style='padding:6px 0; font-weight:700; color:#166534;'>{$access_f}</td></tr>
                                     </table>
                                 </div>
                                 <p>Your updated invoice has been generated and emailed. You can access all your learning resources and study modules as usual.</p>
                                 <p>Keep up the good work and keep learning!</p>";
                        peppian_send_email_general($req['student_email'], $subj, $head, $body);
                    } catch (Exception $mailEx) {
                        error_log("Failed to send installment approval email: " . $mailEx->getMessage());
                    }
                }

                $success_message = "Installment #{$req['instalment_number']} approved. Course access extended to " . date('d M Y', strtotime($new_access_end)) . '.' . $inv_note;
                $req = load_request($pdo, $request_id);
                $reviewable = false;

            } elseif ($action === 'reject') {
                $admin_remarks = trim($_POST['admin_remarks'] ?? '');
                if ($admin_remarks === '') throw new Exception('Please give the rejection reason in Admin Remarks - it is sent to the student.');

                $pdo->beginTransaction();

                /* Reset to a payable state so the student can re-submit from
                   installmentpayment.php (which only operates on pending rows).
                   The rejection itself is preserved in rejected_by / rejected_at
                   / admin_remarks and in the logs. */
                $stmt = $pdo->prepare("
                    UPDATE instalment_details SET
                        status = 'pending',
                        paid_date = NULL,
                        payment_reference = NULL,
                        paid_amount = NULL,
                        admin_remarks = ?,
                        rejected_by = ?,
                        rejected_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$admin_remarks, $admin_username, $request_id]);

                $pdo->commit();

                status_log($pdo, $req['user_id'], 'payment_pending', 'payment_rejected',
                    "Installment #{$req['instalment_number']} rejected - {$admin_remarks}", $admin_username);
                track_record($pdo, $req['user_id'], 'installment_rejected',
                    "Installment #{$req['instalment_number']} rejected: {$admin_remarks}. Student can re-submit.", $admin_username);

                // Queue rejection message (mode-aware, no plain-text fallback in META mode)
                $msg = "Installment payment request rejected due to: {$admin_remarks}. Please submit the payment again after addressing the issue. - PEPP Learning";

                if (whatsapp_outbound_mode($pdo) === 'meta_api') {
                    try {
                        require_once 'includes/communication/CommunicationEngine.php';
                        $engine = CommunicationEngine::getInstance($pdo);

                        $ord = (int)$req['instalment_number'];
                        if ($ord === 1) $ordStr = "1st";
                        elseif ($ord === 2) $ordStr = "2nd";
                        elseif ($ord === 3) $ordStr = "3rd";
                        else $ordStr = $ord . "th";

                        $context = [
                            'student_uid'        => $req['user_id'],
                            'student_name'       => $req['student_name'] ?? '',
                            'course_name'        => $req['pepp_course'] ?? '',
                            'installment_number' => $ordStr,
                            'payment_amount'     => number_format((float)($req['paid_amount'] ?: $req['amount'])),
                            'paid_date'          => !empty($req['paid_date']) ? date('d M Y', strtotime($req['paid_date'])) : '',
                            'rejection_reason'   => $admin_remarks,
                            'invoice_id'         => $request_id // Store installment_id in invoice_id column for idempotency
                        ];

                        $qId = $engine->sendEventNotification('payment_rejection', $wa_phone, $context, $admin_username);
                        if (!$qId) {
                            error_log("Payment rejection WhatsApp skipped: payment_rejection template not configured for student {$req['user_id']}");
                        }
                    } catch (Exception $ex) {
                        error_log('Payment rejection WA error: ' . $ex->getMessage());
                    }
                    // No wa.me redirect in META mode
                } else {
                    // MANUAL mode: wa.me redirect only, no engine calls
                    $whatsapp_url = 'https://wa.me/' . $wa_phone . '?text=' . rawurlencode($msg);
                }

                $success_message = "Installment #{$req['instalment_number']} rejected. The student can submit the payment again.";
                $req = load_request($pdo, $request_id);
                $reviewable = false;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = $e->getMessage();
        }
    }
}

/* ── Supporting data ────────────────────────────────────────────── */
try {
    $payment_accounts = $pdo->query("SELECT id, account_name, account_type FROM payment_accounts WHERE status = 'active' ORDER BY account_name")->fetchAll();
} catch (Exception $e) { $payment_accounts = []; }

$history = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM instalment_details WHERE user_id = ? ORDER BY instalment_number ASC");
    $stmt->execute([$req['user_id']]);
    $history = $stmt->fetchAll();
} catch (Exception $e) {}

// Money summary (registration + approved installments; never double-counted)
$inst_paid = 0.0;
foreach ($history as $h) {
    if (in_array($h['status'], ['approved', 'paid'], true)) $inst_paid += (float)($h['paid_amount'] ?: $h['amount']);
}
$collected   = (float)$req['registration_payment'] + $inst_paid;
$net_payable = (float)$req['total_fee'] > 0
    ? (float)$req['total_fee']
    : max(0, (float)($req['course_fee'] ?? 0) - (float)$req['discount_amount']);
$balance = max(0, $net_payable - $collected);

$st = $req['status'];
$wasRejected = ($st === 'pending' && !$req['paid_date'] && $req['rejected_at']);
$stBadge = in_array($st, ['approved','paid']) ? 'green' : ($st === 'rejected' || $wasRejected ? 'red' : ($req['paid_date'] ? 'amber' : 'blue'));
$stLabel = in_array($st, ['approved','paid']) ? 'Approved' : ($st === 'rejected' ? 'Rejected' : ($wasRejected ? 'Rejected - awaiting re-payment' : ($req['paid_date'] ? 'Pending review' : 'Upcoming')));

// Suggested new access end: +30 days from current end (or today)
$suggest_end = date('Y-m-d', strtotime(($req['current_access_end'] && strtotime($req['current_access_end']) > time() ? $req['current_access_end'] : 'today') . ' +30 days'));

$active_page = 'installments';
$page_title  = 'Payment Review';
$page_sub    = $req['student_name'] . ' · Installment #' . $req['instalment_number'];
include 'includes/admin_nav.php';
?>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; align-items:center;">
    <a class="btn btn-outline" href="phpinstalmentpaymentupdate.php"><i class="fas fa-arrow-left"></i> All Installments</a>
    <a class="btn btn-outline" href="student-details.php?user_id=<?php echo urlencode($req['user_id']); ?>"><i class="fas fa-user"></i> Student Profile</a>
    <span class="badge <?php echo $stBadge; ?>"><?php echo $stLabel; ?></span>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success"><i class="fas fa-circle-check"></i>
        <span><?php echo $success_message; /* built server-side only; may contain the invoice link */ ?>
        <?php if ($whatsapp_url): ?>
            &nbsp;<a class="btn btn-sm btn-whatsapp" href="<?php echo e($whatsapp_url); ?>" target="_blank" style="margin-left:8px;"><i class="fab fa-whatsapp"></i> Send WhatsApp to Student</a>
        <?php endif; ?>
        </span>
    </div>
<?php endif; ?>
<?php if ($error_message): ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<!-- ── MONEY SUMMARY ── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Net Payable</span><span class="stat-icon violet"><i class="fas fa-tag"></i></span></div>
        <div class="stat-value">₹<?php echo number_format($net_payable, 0); ?></div>
        <div class="stat-hint">After ₹<?php echo number_format((float)$req['discount_amount'], 0); ?> discount</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Collected So Far</span><span class="stat-icon green"><i class="fas fa-indian-rupee-sign"></i></span></div>
        <div class="stat-value">₹<?php echo number_format($collected, 0); ?></div>
        <div class="stat-hint">Reg ₹<?php echo number_format((float)$req['registration_payment'], 0); ?> · Installments ₹<?php echo number_format($inst_paid, 0); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Balance</span><span class="stat-icon <?php echo $balance > 0 ? 'amber' : 'green'; ?>"><i class="fas fa-scale-balanced"></i></span></div>
        <div class="stat-value">₹<?php echo number_format($balance, 0); ?></div>
        <div class="stat-hint"><?php echo e($req['payment_plan'] ?: 'One Time'); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">This Installment</span><span class="stat-icon pink"><i class="fas fa-file-invoice-dollar"></i></span></div>
        <div class="stat-value">₹<?php echo number_format((float)$req['amount'], 0); ?></div>
        <div class="stat-hint">Due <?php echo date('d M Y', strtotime($req['due_date'])); ?><?php echo (int)$req['days_overdue'] > 0 && !$req['paid_date'] ? ' · ' . (int)$req['days_overdue'] . 'd overdue' : ''; ?></div>
    </div>
</div>

<!-- ── SUBMISSION DETAILS ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--pink-soft);color:var(--pink-ink);"><i class="fas fa-receipt"></i></span>
        <h2>Submission - Installment #<?php echo (int)$req['instalment_number']; ?></h2>
    </div>
    <div class="panel-body" style="display:flex; gap:28px; flex-wrap:wrap;">
        <div>
            <div class="cell-sub" style="margin-bottom:6px; font-weight:700;">PAYMENT PROOF</div>
            <?php if ($req['payment_reference']): ?>
                <a href="<?php echo e($req['payment_reference']); ?>" target="_blank">
                    <img class="student-photo" style="width:150px;height:150px;" src="<?php echo e($req['payment_reference']); ?>" alt="Payment proof">
                </a>
                <div style="margin-top:6px;"><a class="proof-link" href="<?php echo e($req['payment_reference']); ?>" target="_blank"><i class="fas fa-up-right-from-square"></i> Open full size</a></div>
            <?php else: ?><span class="badge gray">No proof uploaded<?php echo $wasRejected ? ' (cleared on rejection)' : ''; ?></span><?php endif; ?>
        </div>
        <div class="detail-list" style="flex:1;">
            <div class="detail-row"><div class="dl">Student</div><div class="dv"><?php echo e($req['student_name']); ?> (<?php echo e($req['user_id']); ?>)</div></div>
            <div class="detail-row"><div class="dl">Contact</div><div class="dv"><?php echo e($req['whatsapp_country_code'] . ' ' . $req['whatsapp_number']); ?> · <?php echo e($req['student_email']); ?></div></div>
            <div class="detail-row"><div class="dl">Course</div><div class="dv"><?php echo e($req['pepp_course']); ?> (<?php echo e($req['pepp_academic_year']); ?>)</div></div>
            <div class="detail-row"><div class="dl">Paid date (claimed)</div><div class="dv"><?php echo $req['paid_date'] ? date('d M Y', strtotime($req['paid_date'])) : '-'; ?></div></div>
            <div class="detail-row"><div class="dl">Current access ends</div><div class="dv"><?php echo $req['current_access_end'] ? date('d M Y', strtotime($req['current_access_end'])) : '-'; ?></div></div>
            <?php if ($req['approved_by']): ?>
                <div class="detail-row"><div class="dl">Approved</div><div class="dv"><?php echo e($req['approved_by']); ?> · <?php echo date('d M Y, h:i A', strtotime($req['approved_at'])); ?></div></div>
            <?php endif; ?>
            <?php if ($req['rejected_by']): ?>
                <div class="detail-row"><div class="dl">Last rejection</div><div class="dv"><?php echo e($req['rejected_by']); ?> · <?php echo date('d M Y, h:i A', strtotime($req['rejected_at'])); ?></div></div>
            <?php endif; ?>
            <?php if ($req['admin_remarks']): ?>
                <div class="detail-row"><div class="dl">Admin remarks</div><div class="dv"><?php echo e($req['admin_remarks']); ?></div></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($reviewable): ?>
<!-- ── REVIEW FORM ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-gavel"></i></span><h2>Review Decision</h2></div>
    <div class="panel-body">
        <form method="POST" id="review-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="rv-action" value="">
            <div class="form-grid">
                <div class="field"><label>Received amount (₹) <span class="req">*</span></label>
                    <input type="number" name="received_amount" min="0.01" step="0.01" value="<?php echo e($req['amount']); ?>">
                    <div class="help">Adjust if the student paid a different amount</div></div>
                <div class="field"><label>Payment mode <span class="req">*</span></label>
                    <select name="payment_mode">
                        <option value="Online">Online</option>
                        <option value="Cash">Cash</option>
                        <option value="100% Scholarship">100% Scholarship</option>
                        <option value="Pay later">Pay later</option>
                    </select></div>
                <div class="field"><label>Payment account <span class="req">*</span></label>
                    <select name="payment_account_id">
                        <option value="">- Select account -</option>
                        <?php foreach ($payment_accounts as $a): ?>
                            <option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['account_name']); ?> (<?php echo e($a['account_type']); ?>)</option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>New course access end date <span class="req">*</span></label>
                    <input type="date" name="course_due_date" value="<?php echo e($suggest_end); ?>">
                    <div class="help">Currently: <?php echo $req['current_access_end'] ? date('d M Y', strtotime($req['current_access_end'])) : '-'; ?></div></div>
                <div class="field"><label>Next installment due date (optional)</label>
                    <input type="date" name="next_due_date">
                    <div class="help">Reschedules the next unpaid installment</div></div>
                <div class="field full"><label>Admin remarks <span class="req">(required for rejection)</span></label>
                    <textarea name="admin_remarks" rows="2" placeholder="Approval note, or the rejection reason sent to the student"></textarea></div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px; flex-wrap:wrap;">
                <button type="button" class="btn btn-danger" onclick="submitReview('reject')"><i class="fas fa-xmark"></i> Reject &amp; Ask Re-payment</button>
                <button type="button" class="btn btn-success" onclick="submitReview('approve')"><i class="fas fa-check"></i> Approve Payment</button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info"><i class="fas fa-circle-info"></i>
    <span>This installment is not awaiting review<?php echo $wasRejected ? ' - it was rejected and is waiting for the student to re-submit payment' : ''; ?>.
    You can message the student directly: </span>
    <a class="btn btn-sm btn-whatsapp" href="whatsapp-notification.php?phone=<?php echo e($wa_phone); ?>&name=<?php echo urlencode($req['student_name']); ?>" style="margin-left:6px;"><i class="fab fa-whatsapp"></i> WhatsApp</a>
</div>
<?php endif; ?>

<!-- ── INSTALLMENT SCHEDULE ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-list-ol"></i></span><h2>Full Installment Schedule</h2></div>
    <div class="panel-body flush table-wrap">
        <table class="data-table">
            <thead><tr><th>#</th><th>Scheduled</th><th>Received</th><th>Due</th><th>Paid</th><th>Status</th><th>Reviewed by</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h):
                $hs = $h['status'];
                $hRej = ($hs === 'pending' && !$h['paid_date'] && $h['rejected_at']);
                $hb = in_array($hs, ['approved','paid']) ? 'green' : ($hs === 'rejected' || $hRej ? 'red' : ($h['paid_date'] ? 'amber' : 'blue'));
                $hl = in_array($hs, ['approved','paid']) ? 'Approved' : ($hs === 'rejected' ? 'Rejected' : ($hRej ? 'Awaiting re-payment' : ($h['paid_date'] ? 'Pending review' : 'Upcoming')));
            ?>
                <tr style="<?php echo (int)$h['id'] === $request_id ? 'background:var(--accent-soft);' : ''; ?>">
                    <td class="cell-main">#<?php echo (int)$h['instalment_number']; ?><?php echo (int)$h['id'] === $request_id ? ' ←' : ''; ?></td>
                    <td>₹<?php echo number_format((float)$h['amount'], 0); ?></td>
                    <td><?php echo $h['paid_amount'] ? '₹' . number_format((float)$h['paid_amount'], 0) : '-'; ?></td>
                    <td class="cell-sub"><?php echo date('d M Y', strtotime($h['due_date'])); ?></td>
                    <td class="cell-sub"><?php echo $h['paid_date'] ? date('d M Y', strtotime($h['paid_date'])) : '-'; ?></td>
                    <td><span class="badge <?php echo $hb; ?>"><?php echo $hl; ?></span></td>
                    <td class="cell-sub"><?php echo e($h['approved_by'] ?: $h['rejected_by'] ?: '-'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$extra_scripts = "<script>
function submitReview(action) {
    const form = document.getElementById('review-form');
    const remarks = form.querySelector('[name=admin_remarks]').value.trim();
    if (action === 'reject') {
        if (!remarks) { alert('Please write the rejection reason in Admin Remarks - it is sent to the student.'); return; }
        if (!confirm('Reject this payment? The proof will be cleared and the student will be asked to submit again.')) return;
    } else {
        if (!form.querySelector('[name=payment_account_id]').value) { alert('Please select the payment account that received the money.'); return; }
        if (!form.querySelector('[name=course_due_date]').value) { alert('Please set the new course access end date.'); return; }
        if (!confirm('Approve this payment and extend course access?')) return;
    }
    document.getElementById('rv-action').value = action;
    form.submit();
}
" . ($whatsapp_url ? "window.open(" . json_encode($whatsapp_url) . ", '_blank');" : "") . "
</script>";
include 'includes/admin_footer.php';
?>
