<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('students');
if (file_exists(__DIR__ . '/includes/referral_helper.php')) {
    require_once __DIR__ . '/includes/referral_helper.php';
}

/* Full profile of an approved/registered student.
   Linked from: studentpage.php, dashboard.php, phpinstalmentpaymentupdate.php. */

$user_id = trim($_GET['user_id'] ?? '');
if ($user_id === '') {
    header('Location: studentpage.php');
    exit();
}

$message = '';
$error   = '';

// ── Load student ─────────────────────────────────────────────────
function load_student($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT u.*, pc.total_fee AS course_fee, pc.course_type,
               pa.account_name AS payment_account_name
        FROM users u
        LEFT JOIN pepp_courses pc ON pc.course_name = u.pepp_course
        LEFT JOIN payment_accounts pa ON pa.id = u.payment_account_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

try {
    $student = load_student($pdo, $user_id);
} catch (Exception $e) {
    $student = null;
}

if (!$student) {
    $active_page = 'students';
    $page_title  = 'Student Profile';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span>Student not found.</span></div>';
    echo '<a class="btn btn-outline" href="studentpage.php"><i class="fas fa-arrow-left"></i> Back to Students</a>';
    include 'includes/admin_footer.php';
    exit();
}

/* ── POST: delete student (Super Admin only) / edit details ────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revert_to_pending') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } elseif (!is_super_admin()) {
        $error = 'Only the Super Admin can revert an approved student to the pending list.';
    } else {
        try {
            $pdo->beginTransaction();

            // 1) Move the student back to the pending registration list and
            //    undo every approval-time field.
            $pdo->prepare("
                UPDATE users SET
                    status = 'pending',
                    student_status = NULL,
                    onboarding_status = NULL,
                    approved_by = NULL,
                    approval_date = NULL,
                    joined_date = NULL,
                    course_duration_date = NULL,
                    total_fee = NULL,
                    payment_plan = NULL,
                    payment_mode = NULL,
                    payment_account_id = NULL
                WHERE user_id = ?
            ")->execute([$user_id]);

            // 2) Remove generated installments (created at approval). The
            //    registration payment fields on the user row are kept.
            $pdo->prepare("DELETE FROM instalment_details WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM installment_configuration WHERE user_id = ?")->execute([$user_id]);

            // 3) Undo onboarding.
            $pdo->prepare("DELETE FROM student_onboarding WHERE user_id = ?")->execute([$user_id]);

            if (function_exists('reset_referral_earning_for_user')) {
                reset_referral_earning_for_user($pdo, $user_id);
            }

            // 4) Void any invoices generated for this student's payments
            //    (registration + installments) so numbering stays clean only
            //    if you re-approve; we delete the records here.
            try { $pdo->prepare("DELETE FROM invoices WHERE user_id = ?")->execute([$user_id]); } catch (Exception $e) {}

            // 5) Record the reversal.
            $pdo->prepare("
                INSERT INTO student_approval_history (user_id, action, approved_by, payment_mode, approval_date, notes)
                VALUES (?, 'reverted', ?, 'Online', NOW(), ?)
            ")->execute([$user_id, $admin_username, 'Approval reverted to pending by Super Admin']);

            $pdo->commit();

            status_log($pdo, $user_id, 'approved', 'pending', 'Reverted to pending by Super Admin', $admin_username);
            track_record($pdo, $user_id, 'approval_reverted',
                'Approved student reverted to pending - approval, installments, onboarding and invoices undone', $admin_username);
            log_admin_activity($pdo, $admin_username, 'student_reverted',
                "Reverted {$user_id} ({$student['name']}) back to the pending list");

            header('Location: student-approval.php?reverted=' . urlencode($student['name']));
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Revert to pending: ' . $e->getMessage());
            $error = 'Error reverting the student to pending.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_student') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } elseif (!can_delete()) {
        $error = 'Only the Super Admin can delete student data.';
    } else {
        try {
            $pdo->beginTransaction();
            if (function_exists('cleanup_referral_and_coupon_for_user')) {
                cleanup_referral_and_coupon_for_user($pdo, $user_id);
            }
            // Permanent record before removal
            $stmt = $pdo->prepare("
                INSERT INTO student_approval_history (user_id, action, approved_by, payment_mode, approval_date, notes)
                VALUES (?, 'deleted', ?, 'Online', NOW(), ?)
            ");
            $stmt->execute([$user_id, $admin_username, 'Student deleted by Super Admin: ' . $student['name'] . ' / ' . $student['email']]);
            $pdo->prepare("DELETE FROM instalment_details WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM installment_configuration WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM student_onboarding WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$user_id]);
            $pdo->commit();
            track_record($pdo, $user_id, 'student_deleted', 'Student and related records deleted by Super Admin', $admin_username);
            log_admin_activity($pdo, $admin_username, 'student_deleted', "Deleted student {$user_id} ({$student['name']}) with all related data");
            header('Location: studentpage.php?deleted=' . urlencode($student['name']));
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Student delete: ' . $e->getMessage());
            $error = 'Error deleting the student.';
        }
    }
}

/* ── POST: edit core details (whitelist, CSRF, audit) ──────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(($_POST['action'] ?? ''), ['delete_student', 'revert_to_pending'], true)) {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        try {
            $editable = [
                'name', 'email', 'whatsapp_country_code', 'whatsapp_number', 'mobile_number',
                'emergency_contact', 'postal_address', 'postal_pincode', 'state', 'district',
                'place_post_office', 'college_school', 'course', 'university_board',
                'instagram_id', 'payment_plan', 'peppkit_eligible', 'discount_amount', 'discount_remark'
            ];
            $set = []; $vals = []; $changed = [];
            foreach ($editable as $f) {
                if (!isset($_POST[$f])) continue;
                $v = trim((string)$_POST[$f]);
                $set[] = "$f = ?";
                $vals[] = $v;
                if ((string)$student[$f] !== $v) $changed[] = $f;
            }
            if ($set) {
                $vals[] = $user_id;
                $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE user_id = ?")->execute($vals);
                
                // If discount_amount was changed, recalculate and update total_fee, and sync coupon_redemptions.discount_applied
                if (in_array('discount_amount', $changed, true)) {
                    $new_discount = max(0, floatval($_POST['discount_amount'] ?? 0));
                    $course_fee = (float)($student['course_fee'] ?? 0);
                    $new_total_fee = max(0, $course_fee - $new_discount);
                    
                    $pdo->prepare("UPDATE users SET total_fee = ? WHERE user_id = ?")->execute([$new_total_fee, $user_id]);
                    $pdo->prepare("UPDATE coupon_redemptions SET discount_applied = ? WHERE user_id = ?")->execute([$new_discount, $user_id]);
                }

                if ($changed) {
                    track_record($pdo, $user_id, 'profile_edited', 'Fields changed: ' . implode(', ', $changed), $admin_username);
                }
                $message = 'Profile updated successfully.';
                $student = load_student($pdo, $user_id);
            }
        } catch (Exception $e) {
            error_log('Profile edit: ' . $e->getMessage());
            $error = 'Error saving profile changes.';
        }
    }
}

/* ── Related records ────────────────────────────────────────────── */
$installments = $approval_history = $status_logs = $track_records = [];
$onboarding = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM instalment_details WHERE user_id = ? ORDER BY instalment_number ASC");
    $stmt->execute([$user_id]);
    $installments = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT h.*, pa.account_name FROM student_approval_history h LEFT JOIN payment_accounts pa ON pa.id = h.payment_account_id WHERE h.user_id = ? ORDER BY h.approval_date DESC");
    $stmt->execute([$user_id]);
    $approval_history = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM student_status_log WHERE user_id = ? ORDER BY changed_at DESC LIMIT 30");
    $stmt->execute([$user_id]);
    $status_logs = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM track_records WHERE user_id = ? ORDER BY performed_at DESC LIMIT 30");
    $stmt->execute([$user_id]);
    $track_records = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM student_onboarding WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $onboarding = $stmt->fetch();
} catch (Exception $e) {
    error_log('Profile related: ' . $e->getMessage());
}

/* ── Payment summary ────────────────────────────────────────────── */
$reg_paid = (float)$student['paid_amount'];
$inst_paid = 0.0; $inst_due = 0.0;
foreach ($installments as $i) {
    if (in_array($i['status'], ['approved', 'paid'], true)) {
        $inst_paid += (float)($i['paid_amount'] ?: $i['amount']);
    } elseif ($i['status'] !== 'rejected') {
        $inst_due += (float)$i['amount'];
    }
}
$total_collected = $reg_paid + $inst_paid;
/* total_fee is ALREADY net of discount (set on approval / by the migration).
   Subtracting the discount again here double-discounted the balance - fixed.
   Fallback (total_fee not yet backfilled): course fee − discount. */
$net_payable = (float)$student['total_fee'] > 0
    ? (float)$student['total_fee']
    : max(0, (float)($student['course_fee'] ?? 0) - (float)$student['discount_amount']);
$balance = max(0, $net_payable - $total_collected);

$st = $student['student_status'] ?: 'active';
$stBadge = $st === 'active' ? 'green' : ($st === 'completed' ? 'blue' : ($st === 'suspended' ? 'red' : 'gray'));
$days = $student['course_duration_date'] ? (int)floor((strtotime($student['course_duration_date']) - strtotime(date('Y-m-d'))) / 86400) : null;

$active_page = 'students';
$page_title  = 'Student Profile';
$page_sub    = $student['name'] . ' · ' . $student['user_id'];
include 'includes/admin_nav.php';
?>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; align-items:center;">
    <a class="btn btn-outline" href="studentpage.php"><i class="fas fa-arrow-left"></i> All Students</a>
    <span class="badge <?php echo $stBadge; ?>">Status: <?php echo ucfirst($st); ?></span>
    <span class="badge <?php echo $student['onboarding_status'] === 'completed' ? 'green' : 'amber'; ?>">
        Onboarding: <?php echo ucfirst($student['onboarding_status'] ?: 'pending'); ?>
    </span>
    <?php if ($student['course_duration_date']): ?>
        <span class="badge <?php echo $days !== null && $days < 0 ? 'red' : ($days !== null && $days <= 7 ? 'amber' : 'blue'); ?>">
            Access until <?php echo date('d M Y', strtotime($student['course_duration_date'])); ?><?php echo $days !== null ? ($days < 0 ? ' (expired)' : " ({$days}d left)") : ''; ?>
        </span>
    <?php endif; ?>
    <div style="margin-left:auto; display:flex; gap:8px;">
        <?php $wa = preg_replace('/\D/', '', $student['whatsapp_country_code'] . $student['whatsapp_number']); ?>
        <a class="btn btn-sm btn-whatsapp" href="https://wa.me/<?php echo e($wa); ?>" target="_blank"><i class="fab fa-whatsapp"></i> WhatsApp</a>
        <a class="btn btn-sm btn-outline" href="mailto:<?php echo e($student['email']); ?>"><i class="fas fa-envelope"></i> Email</a>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($message); ?></span></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error); ?></span></div><?php endif; ?>

<!-- ── PAYMENT SUMMARY ── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Net Payable</span><span class="stat-icon violet"><i class="fas fa-tag"></i></span></div>
        <div class="stat-value">₹<?php echo number_format($net_payable, 0); ?></div>
        <div class="stat-hint">After ₹<?php echo number_format((float)$student['discount_amount'], 0); ?> discount</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Collected</span><span class="stat-icon green"><i class="fas fa-indian-rupee-sign"></i></span></div>
        <div class="stat-value">₹<?php echo number_format($total_collected, 0); ?></div>
        <div class="stat-hint">Reg ₹<?php echo number_format($reg_paid, 0); ?> · Installments ₹<?php echo number_format($inst_paid, 0); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Balance</span><span class="stat-icon <?php echo $balance > 0 ? 'amber' : 'green'; ?>"><i class="fas fa-scale-balanced"></i></span></div>
        <div class="stat-value">₹<?php echo number_format($balance, 0); ?></div>
        <div class="stat-hint">Scheduled installments ₹<?php echo number_format($inst_due, 0); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Plan</span><span class="stat-icon blue"><i class="fas fa-calendar-days"></i></span></div>
        <div class="stat-value" style="font-size:1.1rem; line-height:2.1;"><?php echo e($student['payment_plan'] ?: 'One Time'); ?></div>
        <div class="stat-hint"><?php echo e($student['payment_mode'] ?: '-'); ?><?php echo $student['payment_account_name'] ? ' · ' . e($student['payment_account_name']) : ''; ?></div>
    </div>
</div>

<!-- ── PHOTO + OVERVIEW ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-id-card"></i></span><h2>Overview</h2></div>
    <div class="panel-body" style="display:flex; gap:24px; flex-wrap:wrap;">
        <div style="flex-shrink:0;">
            <?php echo render_photo_box($student['user_photo'] ?? '', 110); ?>
            <?php if (!empty($student['payment_screenshot'])): ?>
                <div style="margin-top:8px;">
                    <?php if (upload_is_image($student['payment_screenshot'])): ?>
                        <a class="proof-link" href="<?php echo e($student['payment_screenshot']); ?>" target="_blank"><i class="fas fa-receipt"></i> Reg. receipt</a>
                    <?php elseif (upload_is_pdf($student['payment_screenshot'])): ?>
                        <a class="proof-link" href="<?php echo e($student['payment_screenshot']); ?>" target="_blank" style="color:#dc2626;"><i class="fas fa-file-pdf"></i> Reg. receipt (PDF)</a>
                    <?php else: ?>
                        <a class="proof-link" href="<?php echo e($student['payment_screenshot']); ?>" target="_blank"><i class="fas fa-file-lines"></i> Reg. receipt</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="detail-list" style="flex:1;">
            <div class="detail-row"><div class="dl">Student ID</div><div class="dv"><?php echo e($student['user_id']); ?></div></div>
            <div class="detail-row"><div class="dl">Gender / DOB</div><div class="dv"><?php echo e($student['gender']); ?> · <?php echo $student['date_of_birth'] ? date('d M Y', strtotime($student['date_of_birth'])) : '-'; ?></div></div>
            <div class="detail-row"><div class="dl">PEPP Course</div><div class="dv"><?php echo e($student['pepp_course']); ?> (<?php echo e($student['pepp_academic_year']); ?>)</div></div>
            <div class="detail-row"><div class="dl">Joined</div><div class="dv"><?php echo $student['joined_date'] ? date('d M Y', strtotime($student['joined_date'])) : '-'; ?></div></div>
            <div class="detail-row"><div class="dl">Approved by</div><div class="dv"><?php echo e($student['approved_by'] ?: '-'); ?><?php echo $student['approval_date'] ? ' · ' . date('d M Y', strtotime($student['approval_date'])) : ''; ?></div></div>
            <div class="detail-row"><div class="dl">PEPP Kit</div><div class="dv"><?php echo e($student['peppkit_eligible'] ?: 'Not Eligible'); ?></div></div>
            <div class="detail-row"><div class="dl">College / School</div><div class="dv"><?php echo e($student['college_school']); ?></div></div>
            <div class="detail-row"><div class="dl">Current course</div><div class="dv"><?php echo e($student['course']); ?> - <?php echo e($student['university_board']); ?></div></div>
            <div class="detail-row"><div class="dl">Remaining semesters</div><div class="dv"><?php echo e($student['remaining_semesters'] ?: '-'); ?></div></div>
            <div class="detail-row"><div class="dl">Address</div><div class="dv"><?php echo e($student['postal_address']); ?>, <?php echo e($student['place_post_office']); ?>, <?php echo e($student['district']); ?>, <?php echo e($student['state']); ?> - <?php echo e($student['postal_pincode']); ?></div></div>
            <div class="detail-row"><div class="dl">Registered</div><div class="dv"><?php echo date('d M Y, h:i A', strtotime($student['created_at'])); ?><?php echo $student['ip_address'] ? ' · IP ' . e($student['ip_address']) : ''; ?></div></div>
            <div class="detail-row"><div class="dl">Source</div><div class="dv"><?php echo e($student['how_know_pepp'] ?: '-'); ?></div></div>
        </div>
    </div>
</div>

<!-- ── INSTALLMENTS ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--pink-soft);color:var(--pink-ink);"><i class="fas fa-money-bill-wave"></i></span>
        <h2>Installments</h2>
        <div class="head-right"><a class="btn btn-sm btn-outline" href="phpinstalmentpaymentupdate.php">All payments <i class="fas fa-arrow-right"></i></a></div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($installments)): ?>
            <div class="empty-state"><i class="fas fa-circle-check"></i><p>No installments scheduled - one-time payment plan.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>#</th><th>Amount</th><th>Due Date</th><th>Paid Date</th><th>Proof</th><th>Status</th><th>Reviewed by</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($installments as $i):
                $ist = $i['status'];
                $iRej = ($ist === 'pending' && !$i['paid_date'] && !empty($i['rejected_at']));
                $isOverdue = $ist === 'pending' && !$i['paid_date'] && !$iRej && strtotime($i['due_date']) < time();
                $b = in_array($ist, ['approved','paid']) ? 'green' : ($ist === 'rejected' || $iRej ? 'red' : ($isOverdue ? 'red' : 'amber'));
                $label = in_array($ist, ['approved','paid']) ? 'Approved' : ($ist === 'rejected' ? 'Rejected' : ($iRej ? 'Awaiting re-payment' : ($i['paid_date'] ? 'Pending review' : ($isOverdue ? 'Overdue' : 'Upcoming'))));
            ?>
                <tr>
                    <td class="cell-main">#<?php echo (int)$i['instalment_number']; ?></td>
                    <td>₹<?php echo number_format((float)$i['amount'], 0); ?><?php if ($i['paid_amount'] && (float)$i['paid_amount'] !== (float)$i['amount']): ?><div class="cell-sub">paid ₹<?php echo number_format((float)$i['paid_amount'], 0); ?></div><?php endif; ?></td>
                    <td class="cell-sub"><?php echo date('d M Y', strtotime($i['due_date'])); ?></td>
                    <td class="cell-sub"><?php echo $i['paid_date'] ? date('d M Y', strtotime($i['paid_date'])) : '-'; ?></td>
                    <td><?php if ($i['payment_reference']): ?><a class="proof-link" href="<?php echo e($i['payment_reference']); ?>" target="_blank"><i class="fas fa-receipt"></i> View</a><?php else: ?><span class="cell-sub">-</span><?php endif; ?></td>
                    <td><span class="badge <?php echo $b; ?>"><?php echo $label; ?></span></td>
                    <td class="cell-sub"><?php echo e($i['approved_by'] ?: $i['rejected_by'] ?: '-'); ?></td>
                    <td><?php if ($ist === 'pending' && $i['paid_date']): ?><a class="btn btn-sm btn-soft-violet" href="payment-review.php?id=<?php echo (int)$i['id']; ?>"><i class="fas fa-magnifying-glass"></i> Review</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── EDIT PROFILE ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-pen-to-square"></i></span><h2>Edit Details</h2></div>
    <div class="panel-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-grid">
                <div class="field"><label>Name</label><input type="text" name="name" value="<?php echo e($student['name']); ?>"></div>
                <div class="field"><label>Email</label><input type="email" name="email" value="<?php echo e($student['email']); ?>"></div>
                <div class="field"><label>WhatsApp Code</label><input type="text" name="whatsapp_country_code" value="<?php echo e($student['whatsapp_country_code']); ?>"></div>
                <div class="field"><label>WhatsApp Number</label><input type="text" name="whatsapp_number" value="<?php echo e($student['whatsapp_number']); ?>"></div>
                <div class="field"><label>Mobile Number</label><input type="text" name="mobile_number" value="<?php echo e($student['mobile_number']); ?>"></div>
                <div class="field"><label>Emergency Contact</label><input type="text" name="emergency_contact" value="<?php echo e($student['emergency_contact']); ?>"></div>
                <div class="field full"><label>Postal Address</label><textarea name="postal_address"><?php echo e($student['postal_address']); ?></textarea></div>
                <div class="field"><label>PIN Code</label><input type="text" name="postal_pincode" value="<?php echo e($student['postal_pincode']); ?>"></div>
                <div class="field"><label>State</label><input type="text" name="state" value="<?php echo e($student['state']); ?>"></div>
                <div class="field"><label>District</label><input type="text" name="district" value="<?php echo e($student['district']); ?>"></div>
                <div class="field"><label>Place / Post Office</label><input type="text" name="place_post_office" value="<?php echo e($student['place_post_office']); ?>"></div>
                <div class="field"><label>College / School</label><input type="text" name="college_school" value="<?php echo e($student['college_school']); ?>"></div>
                <div class="field"><label>Current Course</label><input type="text" name="course" value="<?php echo e($student['course']); ?>"></div>
                <div class="field"><label>University / Board</label><input type="text" name="university_board" value="<?php echo e($student['university_board']); ?>"></div>
                <div class="field"><label>Instagram</label><input type="text" name="instagram_id" value="<?php echo e($student['instagram_id']); ?>"></div>
                <div class="field"><label>Payment Plan</label>
                    <select name="payment_plan">
                        <?php foreach (['One Time','2 Installments','3 Installments','4 Installments','5 Installments'] as $pl): ?>
                            <option value="<?php echo $pl; ?>" <?php echo $student['payment_plan'] === $pl ? 'selected' : ''; ?>><?php echo $pl; ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="field"><label>PEPP Kit</label>
                    <select name="peppkit_eligible">
                        <option value="Not Eligible" <?php echo $student['peppkit_eligible'] !== 'Eligible' ? 'selected' : ''; ?>>Not Eligible</option>
                        <option value="Eligible" <?php echo $student['peppkit_eligible'] === 'Eligible' ? 'selected' : ''; ?>>Eligible</option>
                    </select></div>
                <div class="field"><label>Discount (₹)</label><input type="number" name="discount_amount" min="0" step="0.01" value="<?php echo e($student['discount_amount']); ?>"></div>
                <div class="field"><label>Discount Remark</label><input type="text" name="discount_remark" value="<?php echo e($student['discount_remark']); ?>"></div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:16px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ── ONBOARDING RECORD ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--teal-soft);color:var(--teal-ink);"><i class="fas fa-handshake"></i></span><h2>Onboarding</h2>
        <div class="head-right"><a class="btn btn-sm btn-outline" href="studentonboarding.php">Onboarding queue <i class="fas fa-arrow-right"></i></a></div>
    </div>
    <div class="panel-body">
        <?php if ($onboarding): ?>
            <div class="detail-list">
                <div class="detail-row"><div class="dl">App access</div><div class="dv"><span class="badge <?php echo $onboarding['app_access_provided'] === 'Yes' ? 'green' : 'gray'; ?>"><?php echo e($onboarding['app_access_provided']); ?></span></div></div>
                <div class="detail-row"><div class="dl">Saved to contacts</div><div class="dv"><span class="badge <?php echo $onboarding['saved_to_contacts'] === 'Yes' ? 'green' : 'gray'; ?>"><?php echo e($onboarding['saved_to_contacts']); ?></span></div></div>
                <div class="detail-row"><div class="dl">WhatsApp groups</div><div class="dv"><span class="badge <?php echo $onboarding['added_whatsapp_groups'] === 'Yes' ? 'green' : 'gray'; ?>"><?php echo e($onboarding['added_whatsapp_groups']); ?></span></div></div>
                <div class="detail-row"><div class="dl">Semester guide</div><div class="dv"><span class="badge <?php echo $onboarding['semester_guide_provided'] === 'Yes' ? 'green' : 'gray'; ?>"><?php echo e($onboarding['semester_guide_provided']); ?></span></div></div>
                <div class="detail-row"><div class="dl">Onboarded by</div><div class="dv"><?php echo e($onboarding['onboarded_by']); ?> · <?php echo date('d M Y', strtotime($onboarding['onboarded_at'])); ?></div></div>
            </div>
        <?php else: ?>
            <div class="cell-sub">Onboarding checklist not completed yet.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── HISTORY & LOGS ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-clock-rotate-left"></i></span><h2>Approval History</h2></div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($approval_history)): ?>
            <div class="empty-state"><i class="fas fa-clock-rotate-left"></i><p>No approval events recorded.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Action</th><th>By</th><th>Mode / Account</th><th>Plan</th><th>Discount</th><th>Date</th><th>Notes</th></tr></thead>
            <tbody>
            <?php foreach ($approval_history as $h): ?>
                <tr>
                    <td><span class="badge <?php echo $h['action'] === 'approved' ? 'green' : ($h['action'] === 'rejected' ? 'red' : 'gray'); ?>"><?php echo ucfirst($h['action']); ?></span></td>
                    <td class="cell-sub"><?php echo e($h['approved_by']); ?></td>
                    <td class="cell-sub"><?php echo e($h['payment_mode']); ?><?php echo $h['account_name'] ? ' · ' . e($h['account_name']) : ''; ?></td>
                    <td class="cell-sub"><?php echo e($h['payment_plan']); ?></td>
                    <td class="cell-sub">₹<?php echo number_format((float)$h['discount_amount'], 0); ?></td>
                    <td class="cell-sub"><?php echo date('d M Y, h:i A', strtotime($h['approval_date'])); ?></td>
                    <td class="cell-sub"><?php echo e($h['notes'] ?: $h['discount_remark'] ?: '-'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--card);color:var(--secondary);"><i class="fas fa-list-ul"></i></span><h2>Activity Log</h2></div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($status_logs) && empty($track_records)): ?>
            <div class="empty-state"><i class="fas fa-list-ul"></i><p>No activity recorded.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>When</th><th>Type</th><th>Details</th><th>By</th></tr></thead>
            <tbody>
            <?php
            $events = [];
            foreach ($status_logs as $l) {
                $events[] = [
                    'time' => $l['changed_at'],
                    'type' => $l['old_status'] === 'remark' ? 'Remark' : 'Status: ' . $l['old_status'] . ' → ' . $l['new_status'],
                    'details' => $l['reason'],
                    'by' => $l['changed_by'],
                ];
            }
            foreach ($track_records as $t) {
                $events[] = [
                    'time' => $t['performed_at'],
                    'type' => ucwords(str_replace('_', ' ', $t['action_type'])),
                    'details' => $t['action_details'],
                    'by' => $t['performed_by'],
                ];
            }
            usort($events, function ($a, $b) { return strtotime($b['time']) <=> strtotime($a['time']); });
            foreach (array_slice($events, 0, 40) as $ev): ?>
                <tr>
                    <td class="cell-sub" style="white-space:nowrap;"><?php echo date('d M Y, h:i A', strtotime($ev['time'])); ?></td>
                    <td><span class="badge gray"><?php echo e($ev['type']); ?></span></td>
                    <td class="cell-sub"><?php echo e($ev['details'] ?: '-'); ?></td>
                    <td class="cell-sub"><?php echo e($ev['by']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php if (can_delete()): ?>
<!-- ── DANGER ZONE (Super Admin only) ── -->
<div class="panel" style="border-color:#fecaca;">
    <div class="panel-head" style="background:var(--red-soft);">
        <span class="head-icon" style="background:#fff;color:var(--red-ink);"><i class="fas fa-triangle-exclamation"></i></span>
        <h2 style="color:var(--red-ink);">Danger Zone - Super Admin</h2>
    </div>
    <div class="panel-body" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
        <?php if ($student['status'] === 'approved'): ?>
        <div style="flex:1; min-width:240px;">
            <div class="cell-main">Revert approval - move back to pending</div>
            <div class="cell-sub">Undoes the approval, generated installments, onboarding records and invoices, and returns the student to the Approvals (pending) list. The original registration details and registration payment are kept so you can review and re-approve. This does not delete the student.</div>
        </div>
        <form method="POST" onsubmit="return confirm('Revert ' + <?php echo json_encode($student['name']); ?> + ' to the pending list?\n\nThis undoes the approval, installments, onboarding and invoices. The student and their registration details are kept.');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="revert_to_pending">
            <button type="submit" class="btn btn-soft-amber"><i class="fas fa-rotate-left"></i> Revert to Pending</button>
        </form>
        <div style="flex-basis:100%; height:0; border-top:1px dashed var(--border); margin:4px 0;"></div>
        <?php endif; ?>
        <div style="flex:1; min-width:240px;">
            <div class="cell-main">Delete this student permanently</div>
            <div class="cell-sub">Removes the student, all installments, onboarding records and configuration. A deletion record is kept in the approval history and activity log. This cannot be undone.</div>
        </div>
        <form method="POST" onsubmit="return confirm('PERMANENTLY DELETE ' + <?php echo json_encode($student['name']); ?> + ' (<?php echo e($student['user_id']); ?>) and ALL related data?\n\nThis cannot be undone.');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete_student">
            <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete Student &amp; All Data</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/admin_footer.php'; ?>
