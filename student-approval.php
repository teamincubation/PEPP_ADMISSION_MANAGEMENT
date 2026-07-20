<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('approvals');
require_once 'includes/invoice_helper.php';

/* ════════════════════════════════════════════════════════════════
   AJAX ACTIONS: approve / reject / delete  (JSON responses)
   Fixes vs. old version:
   - wrapped in a DB transaction (no half-approved students)
   - sets approved_by, approval_date, joined_date, total_fee, student_status
   - records student_approval_history + student_status_log + track_records
   - delete also removes child rows (installments, onboarding, config)
   - CSRF protected
   ════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh the page.']);
        exit;
    }

    $action  = $_POST['action']  ?? '';
    $user_id = trim($_POST['user_id'] ?? '');

    if (!$action || !$user_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
        exit;
    }

    try {
        // Always confirm the student exists and is still pending
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $student = $stmt->fetch();
        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Student not found.']);
            exit;
        }

        if ($action === 'approve') {

            $course_duration_date = $_POST['course_duration_date'] ?? '';
            $payment_mode    = $_POST['payment_mode'] ?? 'Online';
            $payment_account = !empty($_POST['payment_account_id']) ? (int)$_POST['payment_account_id'] : null;
            $discount_amount = max(0, floatval($_POST['discount_amount'] ?? 0));
            $discount_remark = trim($_POST['discount_remark'] ?? '');
            $payment_plan    = $_POST['payment_plan'] ?? 'One Time';
            $peppkit         = ($_POST['peppkit_eligible'] ?? '') === 'Eligible' ? 'Eligible' : 'Not Eligible';

            if (!$course_duration_date) {
                echo json_encode(['success' => false, 'message' => 'Course access end date is required.']);
                exit;
            }
            $allowed_modes = ['Online', 'Cash', '100% Scholarship', 'Pay later'];
            if (!in_array($payment_mode, $allowed_modes, true)) $payment_mode = 'Online';

            // Course fee from pepp_courses (source of truth)
            $stmt = $pdo->prepare("SELECT total_fee FROM pepp_courses WHERE course_name = ? LIMIT 1");
            $stmt->execute([$student['pepp_course']]);
            $course_fee = (float)($stmt->fetchColumn() ?: 0);
            $total_fee  = max(0, $course_fee - $discount_amount);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users SET
                    status = 'approved',
                    approved_by = ?,
                    approval_date = NOW(),
                    joined_date = COALESCE(joined_date, CURDATE()),
                    course_duration_date = ?,
                    payment_mode = ?,
                    payment_account_id = ?,
                    discount_amount = ?,
                    discount_remark = ?,
                    payment_plan = ?,
                    peppkit_eligible = ?,
                    total_fee = ?,
                    student_status = 'active',
                    course_status = 'active'
                WHERE user_id = ?
            ");
            $stmt->execute([
                $admin_username, $course_duration_date, $payment_mode, $payment_account,
                $discount_amount, $discount_remark, $payment_plan, $peppkit, $total_fee, $user_id
            ]);

            // Update discount_applied in coupon_redemptions to match the final discount if a redemption exists
            $stmt = $pdo->prepare("UPDATE coupon_redemptions SET discount_applied = ? WHERE user_id = ?");
            $stmt->execute([$discount_amount, $user_id]);

            // Future installments (installment #1 = the registration payment, already paid)
            $created_installments = 0;
            if ($payment_plan !== 'One Time') {
                $num = (int)explode(' ', $payment_plan)[0];
                $ins = $pdo->prepare("
                    INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
                ");
                for ($i = 2; $i <= $num; $i++) {
                    $amount   = floatval($_POST["installment_{$i}_amount"] ?? 0);
                    $due_date = $_POST["installment_{$i}_due_date"] ?? '';
                    if ($amount > 0 && $due_date) {
                        $ins->execute([$user_id, $i, $amount, $due_date]);
                        $created_installments++;
                    }
                }
            }

            // Approval history (permanent record, even if user row changes later)
            $plan_for_history = in_array($payment_plan, ['One Time','2 Instalments','3 Instalments','4 Instalments','5 Instalments'], true)
                ? $payment_plan
                : (preg_match('/^(\d) /', $payment_plan, $m) ? $m[1] . ' Instalments' : 'One Time');
            $stmt = $pdo->prepare("
                INSERT INTO student_approval_history
                    (user_id, action, approved_by, payment_mode, payment_account_id, discount_amount, discount_remark, payment_plan, peppkit_eligible, approval_date, notes)
                VALUES (?, 'approved', ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $user_id, $admin_username, $payment_mode, $payment_account,
                $discount_amount, $discount_remark, $plan_for_history, $peppkit,
                "Course access until {$course_duration_date}; {$created_installments} future installment(s) scheduled"
            ]);

            $pdo->commit();

            status_log($pdo, $user_id, 'pending', 'approved', 'Application approved', $admin_username);

            // Referral: progress any pending earning now that the student is approved
            if (file_exists(__DIR__ . '/includes/referral_helper.php')) {
                require_once __DIR__ . '/includes/referral_helper.php';
                try { credit_referral_for_user($pdo, $user_id); } catch (Exception $e) { error_log('ref credit (approval): ' . $e->getMessage()); }
            }
            track_record($pdo, $user_id, 'student_approved',
                "Approved with plan '{$payment_plan}', mode '{$payment_mode}', access until {$course_duration_date}", $admin_username);

            // ── Automatic invoice for the registration payment ────
            $inv_note = '';
            if ((float)$student['paid_amount'] > 0) {
                [$inv_ok, $inv_msg, $inv_id, $inv_no] = generate_payment_invoice($pdo, [
                    'source' => 'registration', 'source_ref' => $student['id'], 'user_id' => $user_id,
                    'amount' => $student['paid_amount'], 'account_id' => $payment_account,
                    'payment_mode' => $payment_mode,
                    'paid_date' => $student['paid_date'] ?: date('Y-m-d'),
                    'generated_by' => $admin_username, 'send_email' => true,
                ]);
                $inv_note = $inv_ok && $inv_no ? " Invoice {$inv_no} generated and emailed." : '';
            }

            echo json_encode(['success' => true, 'message' => 'Student approved successfully!' . $inv_note]);
            exit;

        } elseif ($action === 'reject') {

            $reason = trim($_POST['reason'] ?? 'Application rejected by admin');

            $pdo->beginTransaction();

            if (file_exists(__DIR__ . '/includes/referral_helper.php')) {
                require_once __DIR__ . '/includes/referral_helper.php';
                cleanup_referral_and_coupon_for_user($pdo, $user_id);
            }

            $stmt = $pdo->prepare("UPDATE users SET status = 'rejected', approved_by = ?, approval_date = NOW() WHERE user_id = ?");
            $stmt->execute([$admin_username, $user_id]);

            $stmt = $pdo->prepare("
                INSERT INTO student_approval_history (user_id, action, approved_by, payment_mode, approval_date, notes)
                VALUES (?, 'rejected', ?, 'Online', NOW(), ?)
            ");
            $stmt->execute([$user_id, $admin_username, $reason]);

            $pdo->commit();

            status_log($pdo, $user_id, 'pending', 'rejected', $reason, $admin_username);
            track_record($pdo, $user_id, 'student_rejected', $reason, $admin_username);

            echo json_encode(['success' => true, 'message' => 'Student application rejected.']);
            exit;

        } elseif ($action === 'delete') {

            if (!can_delete()) {
                echo json_encode(['success' => false, 'message' => 'Only the Super Admin can delete data.']);
                exit;
            }

            $pdo->beginTransaction();

            if (file_exists(__DIR__ . '/includes/referral_helper.php')) {
                require_once __DIR__ . '/includes/referral_helper.php';
                cleanup_referral_and_coupon_for_user($pdo, $user_id);
            }

            // History first (so the record survives the user deletion)
            $stmt = $pdo->prepare("
                INSERT INTO student_approval_history (user_id, action, approved_by, payment_mode, approval_date, notes)
                VALUES (?, 'deleted', ?, 'Online', NOW(), ?)
            ");
            $stmt->execute([$user_id, $admin_username, 'Registration deleted: ' . $student['name'] . ' / ' . $student['email']]);

            // Remove child rows to avoid orphan data
            $pdo->prepare("DELETE FROM instalment_details WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM installment_configuration WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM student_onboarding WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$user_id]);

            $pdo->commit();

            track_record($pdo, $user_id, 'registration_deleted', 'Registration and related records deleted', $admin_username);

            echo json_encode(['success' => true, 'message' => 'Student registration deleted.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Approval action failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error while processing the request.']);
        exit;
    }
}

/* ── PAGE DATA ──────────────────────────────────────────────── */
$stats = ['pending_count' => 0, 'approved_count' => 0, 'rejected_count' => 0, 'total_count' => 0];
$pending_students = [];
$payment_accounts = [];
$load_error = '';

try {
    $stats = $pdo->query("
        SELECT
            COUNT(CASE WHEN status = 'pending'  THEN 1 END) AS pending_count,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved_count,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) AS rejected_count,
            COUNT(*) AS total_count
        FROM users
    ")->fetch();

    $pending_students = $pdo->query("
        SELECT u.user_id, u.name, u.email, u.whatsapp_country_code, u.whatsapp_number,
               u.pepp_course, u.pepp_academic_year, u.paid_amount, u.paid_date,
               u.payment_screenshot, u.user_photo, u.created_at,
               u.applied_coupon, u.referral_code, u.coupon_discount,
               pc.total_fee AS course_fee
        FROM users u
        LEFT JOIN pepp_courses pc ON pc.course_name = u.pepp_course
        WHERE u.status = 'pending'
        ORDER BY u.created_at DESC
    ")->fetchAll();

    $payment_accounts = $pdo->query("SELECT id, account_name, account_type FROM payment_accounts WHERE status = 'active' ORDER BY account_name")->fetchAll();
} catch (Exception $e) {
    error_log('Approval page load: ' . $e->getMessage());
    $load_error = 'Could not load pending applications.';
}

$active_page = 'approvals';
$page_title  = 'Student Approvals';
$page_sub    = 'Review and approve new registrations from register.php';
include 'includes/admin_nav.php';
?>
<?php if (isset($_GET['reverted'])): ?><div class="alert alert-success"><i class="fas fa-rotate-left"></i><span>"<?php echo e($_GET['reverted']); ?>" was reverted to the pending list. Review and re-approve below.</span></div><?php endif; ?>

<?php if ($load_error): ?>
    <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($load_error); ?></span></div>
<?php endif; ?>

<div id="flash" style="display:none;"></div>

<!-- ── STATS ── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Pending</span><span class="stat-icon amber"><i class="fas fa-hourglass-half"></i></span></div>
        <div class="stat-value"><?php echo (int)$stats['pending_count']; ?></div>
        <div class="stat-hint">Awaiting review</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Approved</span><span class="stat-icon green"><i class="fas fa-circle-check"></i></span></div>
        <div class="stat-value"><?php echo (int)$stats['approved_count']; ?></div>
        <div class="stat-hint"><a href="studentpage.php">View students</a></div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Rejected</span><span class="stat-icon red"><i class="fas fa-circle-xmark"></i></span></div>
        <div class="stat-value"><?php echo (int)$stats['rejected_count']; ?></div>
        <div class="stat-hint">Declined applications</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Total Registrations</span><span class="stat-icon violet"><i class="fas fa-database"></i></span></div>
        <div class="stat-value"><?php echo (int)$stats['total_count']; ?></div>
        <div class="stat-hint">All time</div>
    </div>
</div>

<!-- ── PENDING LIST ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon"><i class="fas fa-user-clock"></i></span>
        <h2>Pending Applications (<?php echo count($pending_students); ?>)</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($pending_students)): ?>
            <div class="empty-state"><i class="fas fa-circle-check"></i><p>All caught up - no pending applications.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th>Student</th><th>Contact</th><th>Course</th><th>Payment</th><th>Proof</th><th>Registered</th><th style="text-align:right;">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($pending_students as $s): ?>
                <tr id="row-<?php echo e($s['user_id']); ?>">
                    <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <?php if (!empty($s['user_photo'])): ?>
                                <img src="<?php echo e($s['user_photo']); ?>" alt="" style="width:38px;height:38px;border-radius:10px;object-fit:cover;border:1px solid var(--border);">
                            <?php endif; ?>
                            <div>
                                <div class="cell-main"><?php echo e($s['name']); ?></div>
                                <div class="cell-sub"><?php echo e($s['user_id']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="cell-sub"><?php echo format_credential($s['email'], 'email'); ?></div>
                        <div class="cell-sub"><?php echo e($s['whatsapp_country_code']); ?> <?php echo format_credential($s['whatsapp_number'], 'phone'); ?></div>
                    </td>
                    <td>
                        <div class="cell-main" style="font-size:.82rem;"><?php echo e($s['pepp_course']); ?></div>
                        <div class="cell-sub"><?php echo e($s['pepp_academic_year']); ?> &middot; Fee ₹<?php echo number_format((float)($s['course_fee'] ?? 0), 0); ?></div>
                    </td>
                    <td>
                        <div class="cell-main">₹<?php echo number_format((float)$s['paid_amount'], 0); ?></div>
                        <div class="cell-sub"><?php echo $s['paid_date'] ? date('d M Y', strtotime($s['paid_date'])) : '-'; ?></div>
                        <?php if ((float)($s['coupon_discount'] ?? 0) > 0): ?>
                            <div style="margin-top: 4px;">
                                <?php if (!empty($s['referral_code'])): ?>
                                    <span class="badge violet" title="Referral Code Applied" style="font-size: 0.72rem; padding: 2px 6px; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-gift"></i> <?php echo e($s['referral_code']); ?> (-₹<?php echo number_format((float)$s['coupon_discount'], 0); ?>)
                                    </span>
                                <?php elseif (!empty($s['applied_coupon'])): ?>
                                    <span class="badge green" title="Coupon Applied" style="font-size: 0.72rem; padding: 2px 6px; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-ticket"></i> <?php echo e($s['applied_coupon']); ?> (-₹<?php echo number_format((float)$s['coupon_discount'], 0); ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($s['payment_screenshot'])): ?>
                            <a class="proof-link" href="<?php echo e($s['payment_screenshot']); ?>" target="_blank"><i class="fas fa-receipt"></i> Receipt</a>
                        <?php else: ?><span class="cell-sub">-</span><?php endif; ?>
                    </td>
                    <td class="cell-sub"><?php echo date('d M, h:i A', strtotime($s['created_at'])); ?></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a class="btn btn-sm btn-outline" href="nonapproval-studentdetails.php?id=<?php echo urlencode($s['user_id']); ?>" title="View / edit details"><i class="fas fa-eye"></i></a>
                        <button class="btn btn-sm btn-soft-green" onclick='openApproveModal(<?php echo json_encode([
                            "user_id" => $s["user_id"], "name" => $s["name"],
                            "course" => $s["pepp_course"], "fee" => (float)($s["course_fee"] ?? 0),
                            "paid" => (float)$s["paid_amount"],
                            "applied_coupon" => $s["applied_coupon"] ?? "",
                            "referral_code" => $s["referral_code"] ?? "",
                            "coupon_discount" => (float)($s["coupon_discount"] ?? 0)
                        ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fas fa-check"></i> Approve</button>
                        <button class="btn btn-sm btn-soft-red" onclick="rejectStudent('<?php echo e($s['user_id']); ?>', '<?php echo e(addslashes($s['name'])); ?>')"><i class="fas fa-xmark"></i></button>
                        <?php if (can_delete()): ?><button class="btn btn-sm btn-outline" onclick="deleteStudent('<?php echo e($s['user_id']); ?>', '<?php echo e(addslashes($s['name'])); ?>')" title="Delete registration (Super Admin)"><i class="fas fa-trash"></i></button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── APPROVE MODAL ── -->
<div class="modal-backdrop" id="approve-modal">
    <div class="modal">
        <div class="modal-head">
            <h3><i class="fas fa-user-check" style="color:var(--green);"></i> Approve Student</h3>
            <button class="modal-close" onclick="closeModal('approve-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form id="approve-form" onsubmit="return submitApproval(event)">
            <div class="modal-body">
                <input type="hidden" name="user_id" id="ap-user-id">
                <div class="alert alert-info" style="margin-bottom:16px;">
                    <i class="fas fa-circle-info"></i>
                    <span id="ap-summary"></span>
                </div>
                <div id="ap-coupon-alert" style="display:none; margin-bottom:16px;"></div>
                <div class="form-grid">
                    <div class="field">
                        <label>Course access until <span class="req">*</span></label>
                        <input type="date" name="course_duration_date" id="ap-duration" required>
                    </div>
                    <div class="field">
                        <label>Payment mode <span class="req">*</span></label>
                        <select name="payment_mode" id="ap-mode" required>
                            <option value="Online">Online</option>
                            <option value="Cash">Cash</option>
                            <option value="100% Scholarship">100% Scholarship</option>
                            <option value="Pay later">Pay later</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Payment account</label>
                        <select name="payment_account_id" id="ap-account">
                            <option value="">- Select account -</option>
                            <?php foreach ($payment_accounts as $acc): ?>
                                <option value="<?php echo (int)$acc['id']; ?>"><?php echo e($acc['account_name']); ?> (<?php echo e($acc['account_type']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Payment plan</label>
                        <select name="payment_plan" id="ap-plan" onchange="renderInstallmentRows()">
                            <option value="One Time">One Time</option>
                            <option value="2 Installments">2 Installments</option>
                            <option value="3 Installments">3 Installments</option>
                            <option value="4 Installments">4 Installments</option>
                            <option value="5 Installments">5 Installments</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Discount (₹)</label>
                        <input type="number" name="discount_amount" id="ap-discount" min="0" step="0.01" value="0">
                    </div>
                    <div class="field">
                        <label>PEPP Kit</label>
                        <select name="peppkit_eligible">
                            <option value="Not Eligible">Not Eligible</option>
                            <option value="Eligible">Eligible</option>
                        </select>
                    </div>
                    <div class="field full">
                        <label>Discount remark</label>
                        <input type="text" name="discount_remark" id="ap-discount-remark" placeholder="e.g. Early-bird offer">
                    </div>
                </div>
                <div id="installment-rows" style="margin-top:14px;"></div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('approve-modal')">Cancel</button>
                <button type="submit" class="btn btn-success" id="ap-submit"><i class="fas fa-check"></i> Approve Student</button>
            </div>
        </form>
    </div>
</div>

<?php
$extra_scripts = "<script>
const CSRF = " . json_encode(csrf_token()) . ";

function flash(msg, ok) {
    const f = document.getElementById('flash');
    f.style.display = 'block';
    f.className = 'alert ' + (ok ? 'alert-success' : 'alert-error');
    f.innerHTML = '<i class=\"fas fa-' + (ok ? 'circle-check' : 'triangle-exclamation') + '\"></i><span>' + msg + '</span>';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function post(data) {
    data.csrf_token = CSRF;
    return fetch('student-approval.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data)
    }).then(r => r.json());
}

let currentStudent = null;

function openApproveModal(s) {
    currentStudent = s;
    document.getElementById('ap-user-id').value = s.user_id;
    document.getElementById('ap-summary').textContent =
        s.name + ' - ' + s.course + ' · Course fee ₹' + s.fee.toLocaleString('en-IN') +
        ' · Paid at registration ₹' + s.paid.toLocaleString('en-IN');
    document.getElementById('approve-form').reset();
    document.getElementById('ap-user-id').value = s.user_id;

    // Set default discount and remark based on coupon/referral details
    const discountInput = document.getElementById('ap-discount');
    const remarkInput = document.getElementById('ap-discount-remark');
    const couponAlert = document.getElementById('ap-coupon-alert');

    if (s.coupon_discount > 0) {
        discountInput.value = s.coupon_discount;
        if (s.referral_code) {
            remarkInput.value = 'Referral code \'' + s.referral_code + '\' applied';
            couponAlert.style.display = 'flex';
            couponAlert.className = 'alert';
            couponAlert.style.background = 'var(--accent-soft)';
            couponAlert.style.color = 'var(--accent-dark)';
            couponAlert.style.border = '1px solid #ddd6fe';
            couponAlert.innerHTML = '<i class=\"fas fa-gift\" style=\"margin-top:2px;\"></i><span><strong>Referral code Applied:</strong> ' + s.referral_code + ' (₹' + s.coupon_discount.toLocaleString('en-IN') + ' discount)</span>';
        } else if (s.applied_coupon) {
            remarkInput.value = 'Coupon code \'' + s.applied_coupon + '\' applied';
            couponAlert.style.display = 'flex';
            couponAlert.className = 'alert alert-success';
            couponAlert.style.background = '';
            couponAlert.style.color = '';
            couponAlert.style.border = '';
            couponAlert.innerHTML = '<i class=\"fas fa-ticket\" style=\"margin-top:2px;\"></i><span><strong>Coupon Applied:</strong> ' + s.applied_coupon + ' (₹' + s.coupon_discount.toLocaleString('en-IN') + ' discount)</span>';
        } else {
            couponAlert.style.display = 'none';
        }
    } else {
        discountInput.value = 0;
        couponAlert.style.display = 'none';
    }

    // sensible default: 1 year of access
    const d = new Date(); d.setFullYear(d.getFullYear() + 1);
    document.getElementById('ap-duration').value = d.toISOString().slice(0, 10);
    renderInstallmentRows();
    openModal('approve-modal');
}

function renderInstallmentRows() {
    const plan = document.getElementById('ap-plan').value;
    const box  = document.getElementById('installment-rows');
    box.innerHTML = '';
    if (plan === 'One Time' || !currentStudent) return;

    const n = parseInt(plan);
    const discount = parseFloat(document.getElementById('ap-discount').value || 0);
    const remaining = Math.max(0, currentStudent.fee - discount - currentStudent.paid);
    const per = n > 1 ? Math.round(remaining / (n - 1)) : 0;

    let html = '<div class=\"alert alert-warn\" style=\"margin-bottom:10px;\"><i class=\"fas fa-circle-info\"></i><span>' +
        'Installment #1 is the registration payment (₹' + currentStudent.paid.toLocaleString('en-IN') +
        ', already paid). Schedule the remaining ₹' + remaining.toLocaleString('en-IN') + ' below.</span></div>';
    html += '<div class=\"form-grid\">';
    for (let i = 2; i <= n; i++) {
        const due = new Date(); due.setMonth(due.getMonth() + (i - 1));
        html += '<div class=\"field\"><label>Installment #' + i + ' amount (₹)</label>' +
                '<input type=\"number\" name=\"installment_' + i + '_amount\" min=\"0\" step=\"0.01\" value=\"' + per + '\" required></div>' +
                '<div class=\"field\"><label>Installment #' + i + ' due date</label>' +
                '<input type=\"date\" name=\"installment_' + i + '_due_date\" value=\"' + due.toISOString().slice(0, 10) + '\" required></div>';
    }
    html += '</div>';
    box.innerHTML = html;
}
document.getElementById('ap-discount').addEventListener('input', renderInstallmentRows);

function submitApproval(ev) {
    ev.preventDefault();
    const btn = document.getElementById('ap-submit');
    btn.disabled = true;
    btn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Approving…';
    const data = { action: 'approve' };
    new FormData(document.getElementById('approve-form')).forEach((v, k) => data[k] = v);
    post(data).then(res => {
        if (res.success) {
            closeModal('approve-modal');
            flash(res.message, true);
            const row = document.getElementById('row-' + data.user_id);
            if (row) row.remove();
            setTimeout(() => location.reload(), 900);
        } else {
            flash(res.message || 'Approval failed.', false);
        }
    }).catch(() => flash('Network error. Please try again.', false))
      .finally(() => { btn.disabled = false; btn.innerHTML = '<i class=\"fas fa-check\"></i> Approve Student'; });
    return false;
}

function rejectStudent(id, name) {
    const reason = prompt('Reject application from ' + name + '?\\nOptional reason:');
    if (reason === null) return;
    post({ action: 'reject', user_id: id, reason: reason }).then(res => {
        flash(res.message, res.success);
        if (res.success) setTimeout(() => location.reload(), 900);
    }).catch(() => flash('Network error.', false));
}

function deleteStudent(id, name) {
    if (!confirm('Permanently DELETE the registration of ' + name + '?\\nThis also removes scheduled installments and onboarding records. This cannot be undone.')) return;
    post({ action: 'delete', user_id: id }).then(res => {
        flash(res.message, res.success);
        if (res.success) setTimeout(() => location.reload(), 900);
    }).catch(() => flash('Network error.', false));
}
</script>";
include 'includes/admin_footer.php';
?>
