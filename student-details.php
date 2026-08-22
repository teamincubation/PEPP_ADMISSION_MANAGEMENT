<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
if (!can_access('students') && !can_access('peppkit') && !can_access('onboarding') && !can_access('installments') && !can_access('approvals') && !can_access('cards')) {
    require_permission('students');
}
if (file_exists(__DIR__ . '/includes/referral_helper.php')) {
    require_once __DIR__ . '/includes/referral_helper.php';
}

/* Self-healing database structure setup */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_remarks` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` VARCHAR(50) NOT NULL,
            `remark` TEXT NOT NULL,
            `created_by` VARCHAR(100) NOT NULL,
            `reminder_id` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_stud_rem_uid` (`user_id`),
            KEY `idx_stud_rem_reminder` (`reminder_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {
    error_log("Failed to create student_remarks table: " . $e->getMessage());
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM reminders LIKE 'student_id'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE reminders ADD COLUMN student_id VARCHAR(50) NULL");
    }
} catch (Exception $e) {
    error_log("Failed to alter reminders table: " . $e->getMessage());
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

/* ── EXPORT INSTALLMENTS TO EXCEL ── */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    if (!can_admin_export()) {
        die("<div style='color:#ef4444; font-family:sans-serif; text-align:center; margin-top:50px; padding:20px; border:1px solid #fca5a5; background:#fef2f2; border-radius:12px; max-width:500px; margin-left:auto; margin-right:auto;'><h3>Access Denied</h3><p>You do not have permission to export data.</p></div>");
    }
    try {
        $stmt_inst = $pdo->prepare("SELECT * FROM instalment_details WHERE user_id = ? ORDER BY instalment_number ASC");
        $stmt_inst->execute([$user_id]);
        $rows = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);

        log_admin_activity($pdo, $admin_username, 'data_export', "Exported installment schedule for student {$user_id} (" . count($rows) . ' rows)');

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="student-installments-' . $user_id . '-' . date('Y-m-d-Hi') . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($out, ['Student ID', 'Student Name', 'Installment #', 'Amount (₹)', 'Due Date', 'Status', 'Paid Date', 'Payment Reference']);
        
        foreach ($rows as $r) {
            fputcsv($out, [
                $student['user_id'],
                $student['name'],
                $r['instalment_number'],
                $r['amount'],
                $r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '-',
                ucfirst($r['status'] ?: 'pending'),
                $r['paid_date'] ? date('d M Y', strtotime($r['paid_date'])) : '-',
                $r['payment_reference'] ?: '-'
            ]);
        }
        fclose($out);
        exit();
    } catch (Exception $e) {
        die("Export failed: " . htmlspecialchars($e->getMessage()));
    }
}

/* ── POST: update attachments (photo / screenshot) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_attachments') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        try {
            require_once 'includes/file_helper.php';
            $photo_updated = false;
            $screenshot_updated = false;
            
            $pdo->beginTransaction();
            
            // Handle Photo Upload
            if (!empty($_FILES['user_photo']['name']) && $_FILES['user_photo']['error'] === UPLOAD_ERR_OK) {
                $new_photo = handle_file_upload_with_replace('user_photo', 'photos', $student['user_photo'], ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                if ($new_photo) {
                    $stmt = $pdo->prepare("UPDATE users SET user_photo = ? WHERE user_id = ?");
                    $stmt->execute([$new_photo, $user_id]);
                    track_record($pdo, $user_id, 'photo_updated', "Updated profile photo", $admin_username);
                    $photo_updated = true;
                } else {
                    throw new Exception("Failed to upload student photo. Only image formats (jpg, png, gif, webp) are allowed.");
                }
            }
            
            // Handle Screenshot Upload
            if (!empty($_FILES['payment_screenshot']['name']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
                $new_screenshot = handle_file_upload_with_replace('payment_screenshot', 'screenshots', $student['payment_screenshot'], ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']);
                if ($new_screenshot) {
                    $stmt = $pdo->prepare("UPDATE users SET payment_screenshot = ? WHERE user_id = ?");
                    $stmt->execute([$new_screenshot, $user_id]);
                    track_record($pdo, $user_id, 'receipt_updated', "Updated registration payment receipt", $admin_username);
                    $screenshot_updated = true;
                } else {
                    throw new Exception("Failed to upload receipt screenshot. Formats allowed: image or pdf.");
                }
            }
            
            $pdo->commit();
            
            if ($photo_updated || $screenshot_updated) {
                $message = "Attachments updated successfully.";
                log_admin_activity($pdo, $admin_username, 'attachments_updated', "Updated attachments for student {$user_id}");
                $student = load_student($pdo, $user_id);
            } else {
                $error = "No files selected or uploaded.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Update attachments: ' . $e->getMessage());
            $error = 'Error updating attachments: ' . $e->getMessage();
        }
    }
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

/* ── POST: update_status ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        try {
            $new_status = $_POST['student_status'] ?? '';
            $allowed = ['active', 'inactive', 'suspended', 'completed', 'dropout'];
            if (!in_array($new_status, $allowed, true)) {
                $error = 'Invalid status.';
            } else {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE users SET student_status = ?, course_status = ? WHERE user_id = ?");
                $course_status = $new_status === 'inactive' ? 'suspended' : ($new_status === 'completed' ? 'completed' : (($new_status === 'suspended' || $new_status === 'dropout') ? 'suspended' : 'active'));
                $stmt->execute([$new_status, $course_status, $user_id]);
                
                if ($new_status === 'dropout') {
                    $pdo->prepare("DELETE FROM instalment_details WHERE user_id = ? AND status = 'pending' AND paid_date IS NULL")->execute([$user_id]);
                }
                
                status_log($pdo, $user_id, $student['student_status'] ?: 'active', $new_status, trim($_POST['reason'] ?? 'Status updated by admin'), $admin_username);
                track_record($pdo, $user_id, 'status_changed', "Student status: " . ($student['student_status'] ?: 'active') . " → {$new_status}", $admin_username);
                $pdo->commit();
                
                $message = "Student status updated successfully.";
                $student = load_student($pdo, $user_id);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Update student status: ' . $e->getMessage());
            $error = 'Error updating student status: ' . $e->getMessage();
        }
    }
}

/* ── POST: edit_installments ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_installments') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        try {
            $new_discount = max(0, floatval($_POST['discount_amount'] ?? 0));
            $new_plan = $_POST['payment_plan'] ?? 'One Time';
            
            // Recalculate fees
            $course_fee = (float)($student['course_fee'] ?? 0);
            $new_total_fee = max(0, $course_fee - $new_discount);
            $reg_paid = (float)$student['paid_amount'];
            
            // Calculate how many installments are paid/approved
            $paid_count = 1; // 1 for registration payment
            $stmt = $pdo->prepare("SELECT * FROM instalment_details WHERE user_id = ? ORDER BY instalment_number ASC");
            $stmt->execute([$user_id]);
            $current_installments = $stmt->fetchAll();
            
            $already_paid = [];
            foreach ($current_installments as $inst) {
                if (in_array($inst['status'], ['approved', 'paid'], true) || !empty($inst['paid_date'])) {
                    $paid_count++;
                    $already_paid[$inst['instalment_number']] = $inst;
                }
            }
            
            // Parse new plan count
            $new_count = 1;
            if ($new_plan !== 'One Time') {
                $new_count = (int)explode(' ', $new_plan)[0];
            }
            
            // Validations
            if ($new_count < $paid_count) {
                throw new Exception("New plan term cannot be less than currently paid/approved installments count ($paid_count).");
            }
            
            // Read input installment amounts and due dates from POST
            $new_installments_data = [];
            $sum_installments = 0.0;
            for ($i = 2; $i <= $new_count; $i++) {
                if (isset($already_paid[$i])) {
                    // For already paid installments, keep existing values
                    $amt = (float)$already_paid[$i]['amount'];
                    $due = $already_paid[$i]['due_date'];
                    $status = $already_paid[$i]['status'];
                    $paid_d = $already_paid[$i]['paid_date'];
                    $ref = $already_paid[$i]['payment_reference'];
                } else {
                    // For upcoming/pending installments
                    $amt = max(0.0, floatval($_POST["inst_{$i}_amount"] ?? 0));
                    $due = $_POST["inst_{$i}_due_date"] ?? '';
                    if ($amt < 1) {
                        throw new Exception("Installment #$i amount must be at least ₹1.");
                    }
                    if (empty($due)) {
                        throw new Exception("Due date for installment #$i is required.");
                    }
                    $status = 'pending';
                    $paid_d = null;
                    $ref = null;
                }
                $new_installments_data[$i] = [
                    'amount' => $amt,
                    'due_date' => $due,
                    'status' => $status,
                    'paid_date' => $paid_d,
                    'payment_reference' => $ref
                ];
                $sum_installments += $amt;
            }
            
            // Total installment amounts assigned shouldn't overtake the total payable amount
            $max_installments_allowed = max(0.0, $new_total_fee - $reg_paid);
            if (round($sum_installments, 2) > round($max_installments_allowed, 2)) {
                throw new Exception("Total scheduled installments (₹" . number_format($sum_installments) . ") cannot exceed the total payable balance (₹" . number_format($max_installments_allowed) . ").");
            }
            
            // Proceed with updates
            $pdo->beginTransaction();
            
            // 1. Update user columns: discount_amount, total_fee, payment_plan
            $stmt = $pdo->prepare("UPDATE users SET discount_amount = ?, total_fee = ?, payment_plan = ? WHERE user_id = ?");
            $stmt->execute([$new_discount, $new_total_fee, $new_plan, $user_id]);
            
            // 2. Clear old installments except already paid/approved ones
            $pdo->prepare("DELETE FROM instalment_details WHERE user_id = ? AND status NOT IN ('approved', 'paid') AND paid_date IS NULL")->execute([$user_id]);
            
            // 3. Insert or update the new installments
            $ins = $pdo->prepare("
                INSERT INTO instalment_details (user_id, instalment_number, amount, due_date, status, paid_date, payment_reference, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_date = VALUES(due_date), status = VALUES(status), paid_date = VALUES(paid_date), payment_reference = VALUES(payment_reference), updated_at = NOW()
            ");
            foreach ($new_installments_data as $num => $data) {
                $ins->execute([
                    $user_id, $num, $data['amount'], $data['due_date'],
                    $data['status'], $data['paid_date'], $data['payment_reference']
                ]);
            }
            
            $pdo->commit();
            
            track_record($pdo, $user_id, 'installments_edited', 
                "Updated plan to $new_plan, discount to ₹$new_discount. Total fee: ₹$new_total_fee", $admin_username);
            log_admin_activity($pdo, $admin_username, 'installments_edited',
                "Edited installments configuration for student $user_id");
            
            $message = 'Installment configuration updated successfully.';
            
            // Reload page data
            $student = load_student($pdo, $user_id);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Edit installments: ' . $e->getMessage());
            $error = 'Error saving installments: ' . $e->getMessage();
        }
    }
}

/* ── POST: update_onboarding ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_onboarding') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        try {
            $app  = isset($_POST['app_access_provided']) ? 'Yes' : 'No';
            $sav  = isset($_POST['saved_to_contacts']) ? 'Yes' : 'No';
            $wa   = isset($_POST['added_whatsapp_groups']) ? 'Yes' : 'No';
            $sem  = isset($_POST['semester_guide_provided']) ? 'Yes' : 'No';
            
            $all_checked = ($app === 'Yes' && $sav === 'Yes' && $wa === 'Yes' && $sem === 'Yes');
            
            $pdo->beginTransaction();
            
            // Check if onboarding row exists
            $stmt = $pdo->prepare("SELECT id FROM student_onboarding WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $exists = $stmt->fetch();
            
            if ($exists) {
                $stmt = $pdo->prepare("
                    UPDATE student_onboarding SET
                        app_access_provided = ?,
                        saved_to_contacts = ?,
                        added_whatsapp_groups = ?,
                        semester_guide_provided = ?,
                        onboarded_by = ?,
                        onboarded_at = COALESCE(onboarded_at, NOW()),
                        updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$app, $sav, $wa, $sem, $admin_username, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO student_onboarding
                        (user_id, app_access_provided, saved_to_contacts, added_whatsapp_groups, semester_guide_provided, onboarded_by, onboarded_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ");
                $stmt->execute([$user_id, $app, $sav, $wa, $sem, $admin_username]);
            }
            
            $onb_status = $all_checked ? 'completed' : 'pending';
            
            $stmt = $pdo->prepare("UPDATE users SET onboarding_status = ?, course_access_provided = ? WHERE user_id = ?");
            $stmt->execute([$onb_status, $app === 'Yes' ? 'yes' : 'no', $user_id]);
            
            $pdo->commit();
            
            track_record($pdo, $user_id, 'onboarding_updated',
                "Onboarding checklist updated. App: $app, Contacts: $sav, WhatsApp: $wa, Sem Guide: $sem. Status: $onb_status", $admin_username);
            log_admin_activity($pdo, $admin_username, 'onboarding_updated',
                "Updated onboarding checklist for student $user_id. Status: $onb_status");
            
            $message = 'Onboarding checklist updated successfully.';
            $student = load_student($pdo, $user_id);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Onboarding edit: ' . $e->getMessage());
            $error = 'Error updating onboarding checklist.';
        }
    }
}

/* ── POST: add_remark ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_remark') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        $remark = trim($_POST['remark'] ?? '');
        $set_reminder = isset($_POST['set_reminder']);
        $rem_title = trim($_POST['reminder_title'] ?? '');
        $rem_time = $_POST['reminder_time'] ?? '';
        
        if ($remark === '') {
            $error = 'Remark cannot be empty.';
        } else {
            try {
                $pdo->beginTransaction();
                $reminder_id = null;
                if ($set_reminder && $rem_title !== '' && $rem_time !== '') {
                    $ts = strtotime(str_replace('T', ' ', $rem_time));
                    if ($ts) {
                        $stmt = $pdo->prepare("
                            INSERT INTO reminders (title, notes, remind_at, assigned_to, status, created_by, student_id, created_at)
                            VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
                        ");
                        $stmt->execute([$rem_title, $remark, date('Y-m-d H:i:s', $ts), $admin_username, $admin_username, $user_id]);
                        $reminder_id = $pdo->lastInsertId();
                    }
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO student_remarks (user_id, remark, created_by, reminder_id, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$user_id, $remark, $admin_username, $reminder_id]);
                
                $pdo->commit();
                
                track_record($pdo, $user_id, 'remark_added', "Remark: $remark" . ($reminder_id ? " (Reminder scheduled)" : ""), $admin_username);
                log_admin_activity($pdo, $admin_username, 'remark_added', "Added remark/note for student $user_id");
                $message = 'Remark/note saved successfully.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Add remark error: ' . $e->getMessage());
                $error = 'Error saving remark/note.';
            }
        }
    }
}

/* ── POST: edit_remark ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_remark') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        $remark_id = (int)$_POST['remark_id'];
        $remark = trim($_POST['remark'] ?? '');
        if ($remark === '') {
            $error = 'Remark cannot be empty.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM student_remarks WHERE id = ? AND user_id = ?");
                $stmt->execute([$remark_id, $user_id]);
                $old = $stmt->fetch();
                if ($old) {
                    $pdo->prepare("UPDATE student_remarks SET remark = ? WHERE id = ?")->execute([$remark, $remark_id]);
                    if ($old['reminder_id']) {
                        $pdo->prepare("UPDATE reminders SET notes = ? WHERE id = ? AND status = 'pending'")->execute([$remark, $old['reminder_id']]);
                    }
                    track_record($pdo, $user_id, 'remark_edited', "Old: {$old['remark']} -> New: $remark", $admin_username);
                    log_admin_activity($pdo, $admin_username, 'remark_edited', "Edited remark $remark_id for student $user_id");
                    $message = 'Remark/note updated successfully.';
                }
            } catch (Exception $e) {
                error_log('Edit remark error: ' . $e->getMessage());
                $error = 'Error editing remark/note.';
            }
        }
    }
}

/* ── POST: delete_remark ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_remark') {
    if (!csrf_verify()) {
        $error = 'Security token mismatch. Please retry.';
    } else {
        $remark_id = (int)$_POST['remark_id'];
        try {
            $stmt = $pdo->prepare("SELECT * FROM student_remarks WHERE id = ? AND user_id = ?");
            $stmt->execute([$remark_id, $user_id]);
            $rem = $stmt->fetch();
            if ($rem) {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM student_remarks WHERE id = ?")->execute([$remark_id]);
                $pdo->commit();
                track_record($pdo, $user_id, 'remark_deleted', "Deleted remark: {$rem['remark']}", $admin_username);
                log_admin_activity($pdo, $admin_username, 'remark_deleted', "Deleted remark $remark_id for student $user_id");
                $message = 'Remark/note deleted successfully.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Delete remark error: ' . $e->getMessage());
            $error = 'Error deleting remark/note.';
        }
    }
}

/* ── POST: edit core details (whitelist, CSRF, audit) ──────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(($_POST['action'] ?? ''), ['delete_student', 'revert_to_pending', 'edit_installments', 'update_onboarding', 'add_remark', 'edit_remark', 'delete_remark'], true)) {
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
                if (!is_super_admin() && ($admin_credential_visibility === 'hide' || $admin_credential_visibility === 'mask')) {
                    if (in_array($f, ['email', 'whatsapp_number', 'mobile_number', 'postal_address'], true)) {
                        if (strpos($v, '*') !== false || preg_match('/^[x\s@.]+$/i', $v) || strpos($v, '<span') !== false) {
                            continue;
                        }
                    }
                }
                $set[] = "$f = ?";
                $vals[] = $v;
                if ((string)$student[$f] !== $v) $changed[] = $f;
            }
            if ($set) {
                $vals[] = $user_id;
                $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE user_id = ?")->execute($vals);
                
                if (in_array('whatsapp_number', $changed, true) || in_array('whatsapp_country_code', $changed, true)) {
                    try {
                        require_once 'includes/communication/CommunicationEngine.php';
                        $commEngine = CommunicationEngine::getInstance($pdo);
                        $commEngine->syncStudentQueueOnNumberChange($user_id, $_POST['whatsapp_country_code'] ?? '', $_POST['whatsapp_number'] ?? '');
                    } catch (Exception $syncEx) {
                        error_log("Queue number sync error: " . $syncEx->getMessage());
                    }
                }

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
$stBadge = $st === 'active' ? 'green' : ($st === 'completed' ? 'blue' : (($st === 'suspended' || $st === 'dropout') ? 'red' : 'gray'));
$days = $student['course_duration_date'] ? (int)floor((strtotime($student['course_duration_date']) - strtotime(date('Y-m-d'))) / 86400) : null;

$active_page = 'students';
$page_title  = 'Student Profile';
$page_sub    = $student['name'] . ' · ' . trim(($student['whatsapp_country_code'] ?? '') . ' ' . format_credential_text($student['whatsapp_number'], 'phone'));
include 'includes/admin_nav.php';
?>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; align-items:center;">
    <a class="btn btn-outline" href="studentpage.php"><i class="fas fa-arrow-left"></i> All Students</a>
    <span class="badge <?php echo $stBadge; ?>" style="cursor:pointer; display:inline-flex; align-items:center; gap:6px;" onclick="openStatusChangeModal('<?php echo htmlspecialchars($student['user_id']); ?>', '<?php echo htmlspecialchars(addslashes($student['name'])); ?>', '<?php echo $st; ?>')" title="Change Status">Status: <?php echo ucfirst($st); ?> <i class="fas fa-pen" style="font-size:0.7rem; opacity:0.8;"></i></span>
    <span class="badge <?php echo $student['onboarding_status'] === 'completed' ? 'green' : 'amber'; ?>">
        Onboarding: <?php echo ucfirst($student['onboarding_status'] ?: 'pending'); ?>
    </span>
    <?php if ($student['course_duration_date']): ?>
        <span class="badge <?php echo $days !== null && $days < 0 ? 'red' : ($days !== null && $days <= 7 ? 'amber' : 'blue'); ?>">
            Access until <?php echo date('d M Y', strtotime($student['course_duration_date'])); ?><?php echo $days !== null ? ($days < 0 ? ' (expired)' : " ({$days}d left)") : ''; ?>
        </span>
    <?php endif; ?>
    <div style="margin-left:auto; display:flex; gap:8px;">
        <?php 
        $raw_wa = preg_replace('/\D/', '', $student['whatsapp_country_code'] . $student['whatsapp_number']);
        $use_wa = (is_credential_restricted('students') && !can_admin_whatsapp_chat()) ? '' : $raw_wa;
        
        if (is_credential_restricted('students') && !can_admin_whatsapp_chat()): ?>
            <a class="btn btn-sm btn-whatsapp" href="javascript:void(0)" onclick="alert('Access to student WhatsApp chat is restricted.')" style="opacity:0.6; cursor:not-allowed;" title="WhatsApp chat denied"><i class="fab fa-whatsapp"></i> WhatsApp</a>
        <?php else: ?>
            <a class="btn btn-sm btn-whatsapp" href="https://wa.me/<?php echo e($use_wa); ?>" target="_blank"><i class="fab fa-whatsapp"></i> WhatsApp</a>
        <?php endif; ?>
        <?php if ($admin_credential_visibility === 'visible' || is_super_admin()): ?>
            <a class="btn btn-sm btn-outline" href="mailto:<?php echo e($student['email']); ?>"><i class="fas fa-envelope"></i> Email</a>
        <?php else: ?>
            <a class="btn btn-sm btn-outline" href="javascript:void(0)" onclick="alert('Access to student email is restricted.')" style="opacity:0.6;"><i class="fas fa-envelope"></i> Email</a>
        <?php endif; ?>
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
        <div style="flex-shrink:0; display:flex; flex-direction:column; align-items:center; gap:8px;">
            <div style="position:relative;">
                <?php echo render_photo_box($student['user_photo'] ?? '', 110); ?>
                <button class="btn btn-sm btn-soft-violet" onclick="openModal('update-attachments-modal')" style="margin-top:8px; display:flex; align-items:center; gap:6px; font-size:0.75rem; width:100%; justify-content:center;">
                    <i class="fas fa-edit"></i> Edit Attachments
                </button>
            </div>
            <?php if (!empty($student['payment_screenshot'])): ?>
                <div style="margin-top:2px;">
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
            <div class="detail-row"><div class="dl">WhatsApp Number</div><div class="dv"><?php echo trim(($student['whatsapp_country_code'] ?? '') . ' ' . format_credential($student['whatsapp_number'], 'phone')); ?></div></div>
            <div class="detail-row"><div class="dl">Gender / DOB</div><div class="dv"><?php echo e($student['gender']); ?> · <?php echo $student['date_of_birth'] ? date('d M Y', strtotime($student['date_of_birth'])) : '-'; ?></div></div>
            <div class="detail-row"><div class="dl">PEPP Course</div><div class="dv"><?php echo e($student['pepp_course']); ?> (<?php echo e($student['pepp_academic_year']); ?>)</div></div>
            <div class="detail-row"><div class="dl">Joined</div><div class="dv"><?php echo $student['joined_date'] ? date('d M Y', strtotime($student['joined_date'])) : '-'; ?></div></div>
            <div class="detail-row"><div class="dl">Approved by</div><div class="dv"><?php echo e($student['approved_by'] ?: '-'); ?><?php echo $student['approval_date'] ? ' · ' . date('d M Y', strtotime($student['approval_date'])) : ''; ?></div></div>
            <div class="detail-row"><div class="dl">PEPP Kit</div><div class="dv"><?php echo e($student['peppkit_eligible'] ?: 'Not Eligible'); ?></div></div>
            <div class="detail-row"><div class="dl">College / School</div><div class="dv"><?php echo e($student['college_school']); ?></div></div>
            <div class="detail-row"><div class="dl">Current course</div><div class="dv"><?php echo e($student['course']); ?> - <?php echo e($student['university_board']); ?></div></div>
            <div class="detail-row"><div class="dl">Remaining semesters</div><div class="dv"><?php echo e($student['remaining_semesters'] ?: '-'); ?></div></div>
            <div class="detail-row"><div class="dl">Address</div><div class="dv"><?php echo format_credential($student['postal_address'], 'address'); ?>, <?php echo e($student['place_post_office']); ?>, <?php echo e($student['district']); ?>, <?php echo e($student['state']); ?> - <?php echo e($student['postal_pincode']); ?></div></div>
            <div class="detail-row"><div class="dl">Registered</div><div class="dv"><?php echo date('d M Y, h:i A', strtotime($student['created_at'])); ?><?php echo $student['ip_address'] ? ' · IP ' . e($student['ip_address']) : ''; ?></div></div>
            <div class="detail-row"><div class="dl">Source</div><div class="dv"><?php echo e($student['how_know_pepp'] ?: '-'); ?></div></div>
            <?php if (!empty($student['referral_code'])): ?>
                <div class="detail-row"><div class="dl">Referral Code</div><div class="dv"><span class="badge violet" style="font-size:0.75rem; padding: 2px 8px; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-gift"></i> <?php echo e($student['referral_code']); ?></span> (₹<?php echo number_format((float)$student['coupon_discount'], 0); ?> discount)</div></div>
            <?php elseif (!empty($student['applied_coupon'])): ?>
                <div class="detail-row"><div class="dl">Applied Coupon</div><div class="dv"><span class="badge green" style="font-size:0.75rem; padding: 2px 8px; display:inline-flex; align-items:center; gap:4px;"><i class="fas fa-ticket"></i> <?php echo e($student['applied_coupon']); ?></span> (₹<?php echo number_format((float)$student['coupon_discount'], 0); ?> discount)</div></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── INSTALLMENTS ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--pink-soft);color:var(--pink-ink);"><i class="fas fa-money-bill-wave"></i></span>
        <h2>Installments</h2>
        <div class="head-right" style="display:flex; gap:8px; align-items:center;">
            <?php if (can_admin_export()): ?>
                <a class="btn btn-sm btn-soft-green" href="?user_id=<?php echo urlencode($student['user_id']); ?>&export=excel"><i class="fas fa-file-excel"></i> Export to Excel</a>
            <?php endif; ?>
            <?php if ($student['status'] === 'approved'): ?>
                <button class="btn btn-sm btn-primary" onclick="openEditInstallmentsModal()"><i class="fas fa-edit"></i> Edit Installments</button>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline" href="phpinstalmentpaymentupdate.php">All payments <i class="fas fa-arrow-right"></i></a>
        </div>
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
                <div class="field"><label>Email</label><input type="text" name="email" value="<?php echo htmlspecialchars(format_credential_text($student['email'], 'email'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="field"><label>WhatsApp Code</label><input type="text" name="whatsapp_country_code" value="<?php echo e($student['whatsapp_country_code']); ?>"></div>
                <div class="field"><label>WhatsApp Number</label><input type="text" name="whatsapp_number" value="<?php echo htmlspecialchars(format_credential_text($student['whatsapp_number'], 'phone'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="field"><label>Mobile Number</label><input type="text" name="mobile_number" value="<?php echo htmlspecialchars(format_credential_text($student['mobile_number'], 'phone'), ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="field"><label>Emergency Contact</label><input type="text" name="emergency_contact" value="<?php echo e($student['emergency_contact']); ?>"></div>
                <div class="field full"><label>Postal Address</label><textarea name="postal_address"><?php echo htmlspecialchars(format_credential_text($student['postal_address'], 'address'), ENT_QUOTES, 'UTF-8'); ?></textarea></div>
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
    <div class="panel-head">
        <span class="head-icon" style="background:var(--teal-soft);color:var(--teal-ink);"><i class="fas fa-handshake"></i></span>
        <h2>Onboarding Checklist</h2>
        <div class="head-right"><a class="btn btn-sm btn-outline" href="studentonboarding.php">Onboarding queue <i class="fas fa-arrow-right"></i></a></div>
    </div>
    <div class="panel-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_onboarding">
            <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:15px;">
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin:0; cursor:pointer;">
                    <input type="checkbox" name="app_access_provided" value="Yes" <?php echo ($onboarding && $onboarding['app_access_provided'] === 'Yes') ? 'checked' : ''; ?>>
                    App access provided
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin:0; cursor:pointer;">
                    <input type="checkbox" name="saved_to_contacts" value="Yes" <?php echo ($onboarding && $onboarding['saved_to_contacts'] === 'Yes') ? 'checked' : ''; ?>>
                    Saved to contacts
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin:0; cursor:pointer;">
                    <input type="checkbox" name="added_whatsapp_groups" value="Yes" <?php echo ($onboarding && $onboarding['added_whatsapp_groups'] === 'Yes') ? 'checked' : ''; ?>>
                    Added to WhatsApp groups
                </label>
                <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin:0; cursor:pointer;">
                    <input type="checkbox" name="semester_guide_provided" value="Yes" <?php echo ($onboarding && $onboarding['semester_guide_provided'] === 'Yes') ? 'checked' : ''; ?>>
                    Semester guide provided
                </label>
            </div>
            <?php if ($onboarding): ?>
                <div class="cell-sub" style="margin-bottom:12px;">
                    Last updated by: <strong><?php echo e($onboarding['onboarded_by']); ?></strong> on <?php echo date('d M Y, h:i A', strtotime($onboarding['updated_at'] ?: $onboarding['onboarded_at'])); ?>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save Onboarding Checklist</button>
        </form>
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

<!-- ── REMARKS & REMINDERS ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--accent-soft);color:var(--accent-ink);"><i class="fas fa-clipboard"></i></span>
        <h2>Remarks &amp; Reminders</h2>
        <div class="head-right">
            <button class="btn btn-sm btn-primary" onclick="openAddRemarkModal()"><i class="fas fa-plus"></i> Add Remark / Note</button>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php
        $remarks = [];
        try {
            $stmt = $pdo->prepare("
                SELECT sr.*, r.title AS reminder_title, r.remind_at, r.status AS reminder_status 
                FROM student_remarks sr
                LEFT JOIN reminders r ON r.id = sr.reminder_id
                WHERE sr.user_id = ?
                ORDER BY sr.created_at DESC
            ");
            $stmt->execute([$user_id]);
            $remarks = $stmt->fetchAll();
        } catch (Exception $e) {}
        
        if (empty($remarks)):
        ?>
            <div class="empty-state" style="padding:20px;"><i class="fas fa-clipboard"></i><p>No remarks or notes added yet.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>By</th>
                    <th>Remark / Note</th>
                    <th>Linked Reminder</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($remarks as $rem): ?>
                <tr>
                    <td class="cell-sub" style="white-space:nowrap;"><?php echo date('d M Y, h:i A', strtotime($rem['created_at'])); ?></td>
                    <td class="cell-sub"><?php echo e($rem['created_by']); ?></td>
                    <td style="word-break:break-word; max-width:300px;"><?php echo nl2br(e($rem['remark'])); ?></td>
                    <td class="cell-sub">
                        <?php if ($rem['reminder_id']): ?>
                            <strong><?php echo e($rem['reminder_title']); ?></strong><br>
                            <i class="fas fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($rem['remind_at'])); ?><br>
                            <span class="badge <?php echo $rem['reminder_status'] === 'completed' ? 'green' : ($rem['reminder_status'] === 'dismissed' ? 'gray' : 'amber'); ?>">
                                <?php echo ucfirst($rem['reminder_status']); ?>
                            </span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn btn-sm btn-soft-blue" onclick='openEditRemarkModal(<?php echo json_encode($rem, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this remark?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_remark">
                            <input type="hidden" name="remark_id" value="<?php echo (int)$rem['id']; ?>">
                            <button class="btn btn-sm btn-soft-red"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
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

<!-- ── EDIT INSTALLMENTS MODAL ── -->
<div class="modal-backdrop" id="edit-installments-modal">
    <div class="modal" style="max-width:560px; width:90%;">
        <div class="modal-head">
            <h3><i class="fas fa-money-bill-wave" style="color:var(--accent);"></i> Edit Installment Schedule</h3>
            <button class="modal-close" onclick="closeModal('edit-installments-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" id="edit-installments-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_installments">
            <div class="modal-body">
                <div class="alert alert-info" style="margin-bottom:12px; font-size:0.82rem; line-height:1.4;">
                    Course Base Fee: <strong>₹<?php echo number_format($student['course_fee'] ?? 0, 2); ?></strong><br>
                    Registration Fee Paid: <strong>₹<?php echo number_format($student['paid_amount'] ?? 0, 2); ?></strong>
                </div>
                
                <div class="form-grid">
                    <div class="field">
                        <label>Discount Amount (₹)</label>
                        <input type="number" name="discount_amount" id="ei-discount" min="0" step="0.01" value="<?php echo e($student['discount_amount']); ?>" oninput="recalcEI()">
                    </div>
                    <div class="field">
                        <label>Net Payable Amount (₹)</label>
                        <input type="text" id="ei-net-payable" readonly style="background:var(--gray-100); font-weight:700;">
                    </div>
                    <div class="field full">
                        <label>Payment Plan</label>
                        <select name="payment_plan" id="ei-plan" onchange="generateEIFields()">
                            <?php foreach (['One Time','2 Installments','3 Installments','4 Installments','5 Installments'] as $pl): ?>
                                <option value="<?php echo $pl; ?>"><?php echo $pl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:15px; border-top:1px dashed var(--border); padding-top:12px;">
                    <div style="font-weight:700; font-size:0.85rem; margin-bottom:8px;">Installment Breakdown</div>
                    <div id="ei-fields-container"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('edit-installments-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Schedule</button>
            </div>
        </form>
    </div>
</div>

<!-- ── ADD REMARK MODAL ── -->
<div class="modal-backdrop" id="add-remark-modal">
    <div class="modal" style="max-width:460px; width:90%;">
        <div class="modal-head">
            <h3><i class="fas fa-clipboard" style="color:var(--accent);"></i> Add Remark / Note</h3>
            <button class="modal-close" onclick="closeModal('add-remark-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_remark">
            <div class="modal-body">
                <div class="field full" style="margin-bottom:12px;">
                    <label>Remark / Note <span class="req">*</span></label>
                    <textarea name="remark" rows="3" required placeholder="Enter note details here..."></textarea>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:inline-flex; align-items:center; gap:8px; font-weight:normal; margin:0; cursor:pointer;">
                        <input type="checkbox" id="add-set-reminder" name="set_reminder" onchange="toggleAddReminderFields()">
                        Schedule a reminder with this note
                    </label>
                </div>
                <div id="add-reminder-fields" style="display:none; border-top:1px dashed var(--border); padding-top:12px; margin-top:8px;">
                    <div class="field full" style="margin-bottom:10px;">
                        <label>Reminder Title <span class="req">*</span></label>
                        <input type="text" id="add-rem-title" name="reminder_title" placeholder="e.g. Call student for follow up">
                    </div>
                    <div class="field full">
                        <label>Date &amp; Time <span class="req">*</span></label>
                        <input type="datetime-local" id="add-rem-time" name="reminder_time" value="<?php echo date('Y-m-d\TH:i', strtotime('+1 hour')); ?>">
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('add-remark-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Remark</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT REMARK MODAL ── -->
<div class="modal-backdrop" id="edit-remark-modal">
    <div class="modal" style="max-width:460px; width:90%;">
        <div class="modal-head">
            <h3><i class="fas fa-clipboard" style="color:var(--accent);"></i> Edit Remark / Note</h3>
            <button class="modal-close" onclick="closeModal('edit-remark-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_remark">
            <input type="hidden" name="remark_id" id="er-remark-id">
            <div class="modal-body">
                <div class="field full">
                    <label>Remark / Note <span class="req">*</span></label>
                    <textarea name="remark" id="er-remark-text" rows="4" required></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('edit-remark-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Remark</button>
            </div>
        </form>
    </div>
</div>

<script>
var COURSE_FEE = <?php echo (float)($student['course_fee'] ?? 0); ?>;
var REG_PAID = <?php echo (float)($student['paid_amount'] ?? 0); ?>;
var EXISTING_INSTALLMENTS = <?php echo json_encode($installments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
var CURRENT_PLAN = <?php echo json_encode($student['payment_plan'] ?: 'One Time'); ?>;

function openEditInstallmentsModal() {
    document.getElementById('ei-discount').value = <?php echo (float)$student['discount_amount']; ?>;
    document.getElementById('ei-plan').value = CURRENT_PLAN;
    recalcEI();
    generateEIFields();
    openModal('edit-installments-modal');
}

function recalcEI() {
    var disc = parseFloat(document.getElementById('ei-discount').value) || 0;
    var net = Math.max(0, COURSE_FEE - disc);
    document.getElementById('ei-net-payable').value = '₹' + net.toLocaleString('en-IN', {minimumFractionDigits: 2});
}

function generateEIFields() {
    var plan = document.getElementById('ei-plan').value;
    var count = 1;
    if (plan !== 'One Time') {
        count = parseInt(plan) || 1;
    }
    
    var container = document.getElementById('ei-fields-container');
    container.innerHTML = '';
    
    if (count <= 1) {
        container.innerHTML = '<div style="font-size:0.8rem; color:var(--text-muted);">One-time payment plan. No future installments scheduled.</div>';
        return;
    }
    
    for (var i = 2; i <= count; i++) {
        var existing = EXISTING_INSTALLMENTS.find(inst => parseInt(inst.instalment_number) === i);
        var amt = existing ? parseFloat(existing.amount) : '';
        var due = existing ? existing.due_date : '';
        var status = existing ? existing.status : 'pending';
        var isLocked = existing && (status === 'approved' || status === 'paid' || existing.paid_date);
        
        var row = document.createElement('div');
        row.style.display = 'flex';
        row.style.gap = '10px';
        row.style.alignItems = 'center';
        row.style.marginBottom = '8px';
        row.innerHTML = `
            <div style="font-weight:700; font-size:0.8rem; width:100px;">Installment #${i}:</div>
            <div style="flex:1;">
                <input type="number" name="inst_${i}_amount" value="${amt}" placeholder="Amount" min="1" step="0.01" required class="form-input" style="padding:6px 10px;" ${isLocked ? 'readonly style="background:var(--gray-100); color:var(--text-muted);"' : ''}>
            </div>
            <div style="flex:1.2;">
                <input type="date" name="inst_${i}_due_date" value="${due}" required class="form-input" style="padding:6px 10px;" ${isLocked ? 'readonly style="background:var(--gray-100); color:var(--text-muted);"' : ''}>
            </div>
            <div style="width:80px; font-size:0.75rem; text-align:right; font-weight:700;">
                ${isLocked ? '<span class="badge green">Paid</span>' : '<span class="badge gray">Pending</span>'}
            </div>
        `;
        container.appendChild(row);
    }
}

function openAddRemarkModal() {
    document.getElementById('add-set-reminder').checked = false;
    document.getElementById('add-rem-title').required = false;
    document.getElementById('add-rem-time').required = false;
    document.getElementById('add-reminder-fields').style.display = 'none';
    openModal('add-remark-modal');
}

function toggleAddReminderFields() {
    var checked = document.getElementById('add-set-reminder').checked;
    document.getElementById('add-reminder-fields').style.display = checked ? 'block' : 'none';
    document.getElementById('add-rem-title').required = checked;
    document.getElementById('add-rem-time').required = checked;
}

function openEditRemarkModal(rem) {
    document.getElementById('er-remark-id').value = rem.id;
    document.getElementById('er-remark-text').value = rem.remark;
    openModal('edit-remark-modal');
}

function openStatusChangeModal(userId, name, status) {
    document.getElementById('st-user-id').value = userId;
    document.getElementById('st-name').innerText = name;
    document.getElementById('st-status').value = status;
    openModal('status-modal');
}
</script>

<!-- ── STATUS MODAL ── -->
<div class="modal-backdrop" id="status-modal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-head">
            <h3><i class="fas fa-user-gear" style="color:var(--amber);"></i> Change Student Status</h3>
            <button class="modal-close" onclick="closeModal('status-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="user_id" id="st-user-id">
            <div class="modal-body">
                <p id="st-name" style="font-weight:700; margin-bottom:12px;"></p>
                <div class="field" style="margin-bottom:12px;">
                    <label>New status</label>
                    <select name="student_status" id="st-status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                        <option value="completed">Completed</option>
                        <option value="dropout">Dropout</option>
                    </select>
                </div>
                <div class="field">
                    <label>Reason</label>
                    <input type="text" name="reason" placeholder="Why is this changing?">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('status-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- ── UPDATE ATTACHMENTS MODAL ── -->
<div class="modal-backdrop" id="update-attachments-modal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-head">
            <h3><i class="fas fa-file-arrow-up" style="color:var(--accent);"></i> Update Attachments</h3>
            <button class="modal-close" onclick="closeModal('update-attachments-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_attachments">
            <div class="modal-body">
                <div class="field" style="margin-bottom:12px;">
                    <label>Student Photo</label>
                    <input type="file" name="user_photo" accept="image/*">
                    <p style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Leave empty to keep existing photo.</p>
                </div>
                <div class="field">
                    <label>Payment Receipt Screenshot</label>
                    <input type="file" name="payment_screenshot" accept="image/*,application/pdf">
                    <p style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Leave empty to keep existing screenshot.</p>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('update-attachments-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
