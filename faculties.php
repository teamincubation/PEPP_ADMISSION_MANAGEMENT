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

require_once __DIR__ . '/includes/encryption_helper.php';
require_once __DIR__ . '/includes/staff_type_helper.php';

// ── AJAX: Reveal / Copy Bank Credentials for Faculty ───────────────────
if (isset($_POST['action']) && in_array($_POST['action'], ['reveal_faculty_bank', 'copy_faculty_bank'], true)) {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['error' => 'Security token mismatch.']);
        exit;
    }

    $fac_id = (int)($_POST['faculty_id'] ?? 0);
    $field = trim($_POST['field'] ?? 'bank_account');
    $is_copy = ($_POST['action'] === 'copy_faculty_bank');

    // Server-side permission check
    if ($is_copy) {
        if (!can_admin_copy_bank_credentials($pdo)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied: Cannot copy bank credentials.']);
            exit;
        }
    } else {
        if (!can_admin_view_bank_credentials($pdo)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied: Cannot view bank credentials.']);
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare("
            SELECT f.id AS faculty_id, f.name AS faculty_name, f.employee_management_faculty_id,
                   e.id AS emp_id, e.employee_id, e.full_name,
                   e.bank_name, e.bank_account_encrypted, e.bank_account_masked,
                   e.ifsc_code, e.upi_id
            FROM faculties f
            LEFT JOIN employees e ON e.id = f.employee_management_faculty_id
            WHERE f.id = ?
            LIMIT 1
        ");
        $stmt->execute([$fac_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Faculty record not found.']);
            exit;
        }

        if (empty($row['emp_id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'This faculty is not linked to an Employee Management record.']);
            exit;
        }

        $val = null;
        if ($field === 'bank_account') {
            if (!empty($row['bank_account_encrypted'])) {
                $val = pepp_decrypt($row['bank_account_encrypted']);
            }
        } elseif ($field === 'ifsc') {
            $val = $row['ifsc_code'] ?? '';
        } elseif ($field === 'upi') {
            $val = $row['upi_id'] ?? '';
        }

        if ($val === null || $val === false || $val === '') {
            echo json_encode(['error' => 'Requested credential is not on file.']);
            exit;
        }

        $log_action = $is_copy ? 'bank_credentials_copied' : 'bank_credentials_viewed';
        $log_detail = ($is_copy ? 'Copied' : 'Revealed') . " bank field '{$field}' for Faculty {$row['faculty_name']} (Faculty #{$fac_id}, Emp: {$row['employee_id']}) on faculties.php";
        log_admin_activity($pdo, $admin_username, $log_action, $log_detail);

        echo json_encode(['success' => true, 'value' => (string)$val, 'field' => $field]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_faculty') {
                $emp_id = (int)($_POST['employee_management_faculty_id'] ?? 0);
                if (!$emp_id) {
                    $error_message = 'Please select an approved Faculty record from Employee Management.';
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND application_for = 'faculty' FOR UPDATE");
                    $stmt->execute([$emp_id]);
                    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$emp) {
                        $pdo->rollBack();
                        $error_message = 'The selected record is not an approved Faculty in Employee Management.';
                    } else {
                        // Check if employee_management_faculty_id column exists
                        $has_col = false;
                        try {
                            $has_col = (bool)$pdo->query("SHOW COLUMNS FROM faculties LIKE 'employee_management_faculty_id'")->fetchColumn();
                        } catch (Exception $e) {}

                        if ($has_col) {
                            $stmt_conflict = $pdo->prepare("SELECT id, name FROM faculties WHERE employee_management_faculty_id = ? FOR UPDATE");
                            $stmt_conflict->execute([$emp_id]);
                            $conflict = $stmt_conflict->fetch(PDO::FETCH_ASSOC);
                            if ($conflict) {
                                $pdo->rollBack();
                                throw new Exception("This Faculty is already linked to faculty #{$conflict['id']} ({$conflict['name']}).");
                            }
                        }

                        $name = trim($emp['full_name']);
                        $mobile = trim($emp['mobile_number']);
                        $email = trim($emp['email']) ?: null;
                        $rate_live = (float)($emp['rate_live'] ?? 0);
                        $rate_qpd = (float)($emp['rate_qpd'] ?? 0);
                        $rate_recorded = (float)($emp['rate_recorded'] ?? 0);
                        $rate_offline = (float)($emp['rate_offline'] ?? 0);
                        $academic_year = trim($emp['academic_year'] ?? '') ?: null;
                        $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

                        if ($has_col) {
                            $stmt_ins = $pdo->prepare("INSERT INTO faculties (employee_management_faculty_id, name, mobile, email, rate_live, rate_qpd, rate_recorded, rate_offline, academic_year, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
                            $stmt_ins->execute([$emp_id, $name, $mobile, $email, $rate_live, $rate_qpd, $rate_recorded, $rate_offline, $academic_year, $status, $admin_username]);
                        } else {
                            $stmt_ins = $pdo->prepare("INSERT INTO faculties (name, mobile, email, rate_live, rate_qpd, rate_recorded, rate_offline, academic_year, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
                            $stmt_ins->execute([$name, $mobile, $email, $rate_live, $rate_qpd, $rate_recorded, $rate_offline, $academic_year, $status, $admin_username]);
                        }

                        $new_id = (int)$pdo->lastInsertId();
                        $pdo->commit();
                        log_admin_activity($pdo, $admin_username, 'faculty_added', "Added faculty #{$new_id} ({$name}) linked to Employee {$emp['employee_id']}");
                        $success_message = 'Faculty added and linked successfully.';
                    }
                }
            } elseif ($action === 'edit_faculty') {
                $id = (int)($_POST['faculty_id'] ?? 0);
                $new_link_emp_id = !empty($_POST['employee_management_faculty_id']) ? (int)$_POST['employee_management_faculty_id'] : null;

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM faculties WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $orig_fac = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$orig_fac) {
                    $pdo->rollBack();
                    $error_message = 'Faculty record not found.';
                } else {
                    $has_col = false;
                    try {
                        $has_col = (bool)$pdo->query("SHOW COLUMNS FROM faculties LIKE 'employee_management_faculty_id'")->fetchColumn();
                    } catch (Exception $e) {}

                    // Fallback to existing link if no new link was requested during edit
                    $orig_link_emp_id = (!empty($orig_fac['employee_management_faculty_id'])) ? (int)$orig_fac['employee_management_faculty_id'] : null;
                    $link_emp_id = $new_link_emp_id ?: $orig_link_emp_id;

                    $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

                    // PHASE 6 BACKEND PROTECTION:
                    // If the Faculty is linked (already linked or newly linking/switching):
                    // Authoritative values from employees MUST be used.
                    // Any incoming POST values for name, email, mobile, academic_year, rate_live, rate_qpd, rate_recorded, rate_offline
                    // are ignored to prevent direct POST / tampering from modifying authoritative Faculty fields.
                    if ($link_emp_id) {
                        if ($has_col) {
                            $stmt_conflict = $pdo->prepare("SELECT id, name FROM faculties WHERE employee_management_faculty_id = ? AND id != ? FOR UPDATE");
                            $stmt_conflict->execute([$link_emp_id, $id]);
                            $conflict = $stmt_conflict->fetch(PDO::FETCH_ASSOC);
                            if ($conflict) {
                                $pdo->rollBack();
                                throw new Exception("The selected Faculty is already linked to faculty #{$conflict['id']} ({$conflict['name']}).");
                            }
                        }

                        $stmt_emp = $pdo->prepare("SELECT * FROM employees WHERE id = ? AND application_for = 'faculty' FOR UPDATE");
                        $stmt_emp->execute([$link_emp_id]);
                        $emp = $stmt_emp->fetch(PDO::FETCH_ASSOC);
                        if (!$emp) {
                            $pdo->rollBack();
                            throw new Exception("Linked or selected record is not an approved Faculty in Employee Management.");
                        }

                        $name = trim($emp['full_name']);
                        $mobile = trim($emp['mobile_number']);
                        $email = trim($emp['email']) ?: null;
                        $academic_year = trim($emp['academic_year'] ?? '') ?: null;
                        $rate_live = max(0, (float)($emp['rate_live'] ?? 0));
                        $rate_qpd = max(0, (float)($emp['rate_qpd'] ?? 0));
                        $rate_recorded = max(0, (float)($emp['rate_recorded'] ?? 0));
                        $rate_offline = max(0, (float)($emp['rate_offline'] ?? 0));
                    } else {
                        // Legacy / Unlinked Faculty: retain manual editing behavior
                        $name = trim($_POST['name'] ?? '');
                        $email = trim($_POST['email'] ?? '') ?: null;
                        $mobile = trim($_POST['mobile'] ?? '');
                        $academic_year = trim($_POST['academic_year'] ?? '') ?: null;
                        $rate_live = max(0, (float)($_POST['rate_live'] ?? 0));
                        $rate_qpd = max(0, (float)($_POST['rate_qpd'] ?? 0));
                        $rate_recorded = max(0, (float)($_POST['rate_recorded'] ?? 0));
                        $rate_offline = max(0, (float)($_POST['rate_offline'] ?? 0));

                        if (is_credential_restricted('faculties')) {
                            if (strpos($mobile, '*') !== false || preg_match('/^[x\s@.]+$/i', $mobile) || strpos($mobile, '<span') !== false) {
                                $mobile = $orig_fac['mobile'];
                            }
                            if ($email && (strpos($email, '*') !== false || preg_match('/^[x\s@.]+$/i', $email) || strpos($email, '<span') !== false)) {
                                $email = $orig_fac['email'];
                            }
                        }

                        if (is_credential_restricted('financials')) {
                            $rate_live = (float)$orig_fac['rate_live'];
                            $rate_qpd = (float)$orig_fac['rate_qpd'];
                            $rate_recorded = (float)$orig_fac['rate_recorded'];
                            $rate_offline = (float)$orig_fac['rate_offline'];
                        }
                    }

                    if ($name === '') {
                        $pdo->rollBack();
                        $error_message = 'Faculty name is required.';
                    } elseif ($email !== '' && $email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $pdo->rollBack();
                        $error_message = 'Please enter a valid email address (or leave blank).';
                    } else {
                        if ($has_col) {
                            $stmt_upd = $pdo->prepare("UPDATE faculties SET employee_management_faculty_id=?, name=?, mobile=?, email=?, rate_live=?, rate_qpd=?, rate_recorded=?, rate_offline=?, academic_year=?, status=? WHERE id=?");
                            $stmt_upd->execute([$link_emp_id, $name, $mobile, $email ?: null, $rate_live, $rate_qpd, $rate_recorded, $rate_offline, $academic_year, $status, $id]);
                        } else {
                            $stmt_upd = $pdo->prepare("UPDATE faculties SET name=?, mobile=?, email=?, rate_live=?, rate_qpd=?, rate_recorded=?, rate_offline=?, academic_year=?, status=? WHERE id=?");
                            $stmt_upd->execute([$name, $mobile, $email ?: null, $rate_live, $rate_qpd, $rate_recorded, $rate_offline, $academic_year, $status, $id]);
                        }

                        $pdo->commit();
                        log_admin_activity($pdo, $admin_username, 'faculty_updated', "Updated faculty #{$id}: {$name}" . ($link_emp_id ? " (Linked to EMP #{$link_emp_id})" : ""));
                        $success_message = 'Faculty updated.';
                    }
                }
            } elseif ($action === 'unlink_faculty') {
                $id = (int)($_POST['faculty_id'] ?? 0);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM faculties WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $fac = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$fac) {
                    $pdo->rollBack();
                    $error_message = 'Faculty record not found.';
                } else {
                    $has_col = false;
                    try {
                        $has_col = (bool)$pdo->query("SHOW COLUMNS FROM faculties LIKE 'employee_management_faculty_id'")->fetchColumn();
                    } catch (Exception $e) {}
                    if ($has_col) {
                        $pdo->prepare("UPDATE faculties SET employee_management_faculty_id = NULL WHERE id = ?")->execute([$id]);
                    }
                    $pdo->commit();
                    log_admin_activity($pdo, $admin_username, 'faculty_unlinked', "Unlinked faculty #{$id} ({$fac['name']}) from Employee Management");
                    $success_message = 'Faculty unlinked from Employee Management. All sessions, payment history, and faculty data remain intact.';
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
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Faculties: ' . $e->getMessage());
            $error_message = 'Error: ' . $e->getMessage();
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
$detail = null; $detail_sessions = []; $detail_payments = []; $detail_calc = null; $detail_custom = [];
$detail_emp_bank = null;
if ($view_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM faculties WHERE id = ?"); $stmt->execute([$view_id]);
        $detail = $stmt->fetch();
        if ($detail) {
            $detail_calc = faculty_earned($pdo, $detail, $sessions_ready, $TYPE_RATE);
            $detail_calc['paid'] = faculty_paid_total($pdo, $view_id);
            $detail_calc['due'] = max(0, $detail_calc['earned'] - $detail_calc['paid']);
            // Faculty-type custom-field values (stored against the linked employees.id internally;
            // exposed ONLY here, never through generic Employee Management).
            if (!empty($detail['employee_management_faculty_id']) && is_file(__DIR__ . '/includes/staff_type_helper.php')) {
                require_once __DIR__ . '/includes/staff_type_helper.php';
                $detail_custom = array_filter(
                    staff_get_custom_values($pdo, (int)$detail['employee_management_faculty_id'], 'faculty'),
                    fn($r) => isset($r['field_value']) && $r['field_value'] !== ''
                );
            }
            // Linked employee bank details (source of truth for payment banking)
            if (!empty($detail['employee_management_faculty_id'])) {
                $stmt_eb = $pdo->prepare("SELECT id, employee_id, full_name, bank_name, bank_account_masked, ifsc_code, upi_id FROM employees WHERE id = ?");
                $stmt_eb->execute([(int)$detail['employee_management_faculty_id']]);
                $detail_emp_bank = $stmt_eb->fetch(PDO::FETCH_ASSOC);
            }
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
$emp_faculties = [];
$has_emp_link_col = false;
try {
    $has_emp_link_col = (bool)$pdo->query("SHOW COLUMNS FROM faculties LIKE 'employee_management_faculty_id'")->fetchColumn();
} catch (Exception $e) {
    try {
        $pdo->query("SELECT employee_management_faculty_id FROM faculties LIMIT 1");
        $has_emp_link_col = true;
    } catch (Exception $e2) {
        $has_emp_link_col = false;
    }
}

try {
    if ($has_emp_link_col) {
        $faculties = $pdo->query("
            SELECT f.*, e.employee_id AS emp_code, e.full_name AS emp_name
            FROM faculties f
            LEFT JOIN employees e ON e.id = f.employee_management_faculty_id
            ORDER BY f.status='active' DESC, f.name ASC
        ")->fetchAll();
    } else {
        $faculties = $pdo->query("SELECT f.*, NULL AS emp_code, NULL AS emp_name FROM faculties f ORDER BY f.status='active' DESC, f.name ASC")->fetchAll();
    }
    $payment_accounts = $pdo->query("SELECT * FROM payment_accounts WHERE status='active' ORDER BY account_name")->fetchAll();
    $academic_years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

    if ($has_emp_link_col) {
        $emp_faculties = $pdo->query("
            SELECT e.id, e.employee_id, e.employee_id AS employee_code, e.full_name, e.mobile_number, e.email, e.academic_year,
                   e.rate_live, e.rate_qpd, e.rate_recorded, e.rate_offline,
                   f.id AS linked_faculty_id, f.name AS linked_faculty_name
            FROM employees e
            LEFT JOIN faculties f ON f.employee_management_faculty_id = e.id
            WHERE e.application_for = 'faculty' AND e.status = 'active'
            ORDER BY e.full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $emp_faculties = $pdo->query("
            SELECT e.id, e.employee_id, e.employee_id AS employee_code, e.full_name, e.mobile_number, e.email, e.academic_year,
                   e.rate_live, e.rate_qpd, e.rate_recorded, e.rate_offline,
                   NULL AS linked_faculty_id, NULL AS linked_faculty_name
            FROM employees e
            WHERE e.application_for = 'faculty' AND e.status = 'active'
            ORDER BY e.full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
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
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Total Earned</span><span class="stat-icon violet"><i class="fas fa-indian-rupee-sign"></i></span></div><div class="stat-value"><?php echo format_financial($detail_calc['earned'], 0); ?></div><div class="stat-hint">From completed sessions</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Paid</span><span class="stat-icon green"><i class="fas fa-money-bill-wave"></i></span></div><div class="stat-value"><?php echo format_financial($detail_calc['paid'], 0); ?></div><div class="stat-hint">Total paid out</div></div>
    <div class="stat-card"><div class="stat-top"><span class="stat-label">Payment Pending</span><span class="stat-icon amber"><i class="fas fa-hourglass-half"></i></span></div><div class="stat-value"><?php echo format_financial($detail_calc['due'], 0); ?></div><div class="stat-hint">Earned minus paid</div></div>
</div>

<?php if (!empty($detail_custom)): ?>
<div class="panel" style="margin-bottom:16px;">
    <div class="panel-head"><h2>Registration Details</h2></div>
    <div class="panel-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
        <?php foreach ($detail_custom as $dc): ?>
        <div><div class="cell-sub" style="font-size:.7rem;text-transform:uppercase;font-weight:700;"><?php echo e($dc['field_label'] ?? $dc['field_name'] ?? ''); ?></div><div><?php echo e((string)$dc['field_value']); ?></div></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══ BANKING DETAILS FOR PAYMENT ═══ -->
<?php
$can_view_bank = can_admin_view_bank_credentials($pdo);
$can_copy_bank = can_admin_copy_bank_credentials($pdo);
?>
<div class="panel" style="margin-bottom:16px;">
    <div class="panel-head">
        <span class="head-icon" style="background:#dcfce7;color:#16a34a;"><i class="fas fa-building-columns"></i></span>
        <h2>Banking Details for Payment</h2>
        <?php if (!empty($detail['employee_management_faculty_id'])): ?>
            <div class="head-right">
                <span class="badge blue" style="font-size:0.75rem;"><i class="fas fa-link"></i> Linked to Employee Management</span>
            </div>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <?php if (!$detail_emp_bank): ?>
            <div style="color:var(--text-muted); font-size:0.85rem;">
                <i class="fas fa-info-circle"></i> No linked Employee Management record or banking details on file for this faculty member.
            </div>
        <?php elseif (!$can_view_bank): ?>
            <div class="alert alert-warn" style="margin-bottom:12px; font-size:0.85rem;">
                <i class="fas fa-lock"></i> <span>Bank credentials are restricted for your account.</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px;">
                <div>
                    <div class="cell-sub" style="font-size:0.75rem; text-transform:uppercase; font-weight:700;">Bank Name</div>
                    <div style="font-weight:600; color:var(--text-muted);">[Masked / Restricted]</div>
                </div>
                <div>
                    <div class="cell-sub" style="font-size:0.75rem; text-transform:uppercase; font-weight:700;">Account Number</div>
                    <div style="font-family:monospace; font-weight:700; color:var(--text-muted);"><?= e($detail_emp_bank['bank_account_masked'] ?: 'XXXX XXXX 1234') ?></div>
                </div>
                <div>
                    <div class="cell-sub" style="font-size:0.75rem; text-transform:uppercase; font-weight:700;">IFSC Code</div>
                    <div style="font-family:monospace; font-weight:700; color:var(--text-muted);">XXXX0000000</div>
                </div>
                <div>
                    <div class="cell-sub" style="font-size:0.75rem; text-transform:uppercase; font-weight:700;">UPI ID</div>
                    <div style="font-weight:600; color:var(--text-muted);">Restricted</div>
                </div>
            </div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; align-items:center;">
                <div>
                    <div class="cell-sub" style="font-size:0.75rem; text-transform:uppercase; font-weight:700;">Bank Name</div>
                    <div style="font-weight:700; font-size:0.95rem;"><?= e($detail_emp_bank['bank_name'] ?: 'Not on file') ?></div>
                </div>
                <div>
                    <div class="cell-sub" style="font-size:0.75rem; text-transform:uppercase; font-weight:700;">Account Number</div>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:2px;">
                        <span id="facBankAccDisplay" style="font-family:monospace; font-weight:700; font-size:0.95rem; letter-spacing:0.5px;"><?= e($detail_emp_bank['bank_account_masked'] ?: 'XXXX XXXX 1234') ?></span>
                        <button type="button" class="btn btn-sm btn-outline" id="facRevealBankBtn" onclick="revealFacultyBankDetail(<?= (int)$view_id ?>)" style="padding:2px 8px; font-size:0.75rem;">
                            <i class="fas fa-eye"></i> Reveal
                        </button>
                        <?php if ($can_copy_bank): ?>
                        <button type="button" class="btn btn-sm btn-outline" id="facCopyBankBtn" onclick="copyFacultyBankDetail(<?= (int)$view_id ?>)" style="padding:2px 8px; font-size:0.75rem;">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="cell-sub" style="font-size:0.75rem; text-transform:uppercase; font-weight:700;">IFSC Code</div>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:2px;">
                        <span id="facIfscDisplay" style="font-family:monospace; font-weight:700; font-size:0.95rem;"><?= e($detail_emp_bank['ifsc_code'] ?: 'Not on file') ?></span>
                        <?php if ($can_copy_bank && !empty($detail_emp_bank['ifsc_code'])): ?>
                        <button type="button" class="btn btn-sm btn-outline" onclick="copyFacultyStaticDetail(<?= (int)$view_id ?>, 'ifsc', '<?= e($detail_emp_bank['ifsc_code']) ?>', this)" style="padding:2px 8px; font-size:0.75rem;">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="cell-sub" style="font-size:0.75rem; text-transform:uppercase; font-weight:700;">UPI ID</div>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:2px;">
                        <span id="facUpiDisplay" style="font-weight:700; font-size:0.95rem;"><?= e($detail_emp_bank['upi_id'] ?: 'Not on file') ?></span>
                        <?php if ($can_copy_bank && !empty($detail_emp_bank['upi_id'])): ?>
                        <button type="button" class="btn btn-sm btn-outline" onclick="copyFacultyStaticDetail(<?= (int)$view_id ?>, 'upi', '<?= e($detail_emp_bank['upi_id']) ?>', this)" style="padding:2px 8px; font-size:0.75rem;">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
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
            <div class="alert alert-info"><i class="fas fa-circle-info"></i><span>Payment pending: <strong><?php echo format_financial($detail_calc['due'], 2); ?></strong></span></div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="faculty_id" value="<?php echo $view_id; ?>">
                <div class="form-grid">
                    <?php if (is_credential_restricted('financials')): ?>
                        <div class="field"><label>Amount (₹) <span class="req">*</span></label><input type="text" disabled value="***" style="background:#f1f5f9; cursor:not-allowed;"></div>
                    <?php else: ?>
                        <div class="field"><label>Amount (₹) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="amount" required value="<?php echo $detail_calc['due'] > 0 ? round($detail_calc['due'], 2) : ''; ?>"></div>
                    <?php endif; ?>
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
                    <tr><td class="cell-sub"><?php echo $p['paid_date'] ? date('d M Y', strtotime($p['paid_date'])) : '-'; ?></td><td class="cell-main"><?php echo format_financial($p['amount'], 0); ?></td><td class="cell-sub"><?php echo e($p['account_name'] ?: '-'); ?></td><td class="cell-sub"><?php echo e($p['remarks'] ?: '-'); ?></td></tr>
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

<script>
const FAC_CSRF = '<?= csrf_token() ?>';

function revealFacultyBankDetail(facId) {
    const btn = document.getElementById('facRevealBankBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('action', 'reveal_faculty_bank');
    fd.append('faculty_id', facId);
    fd.append('field', 'bank_account');
    fd.append('csrf_token', FAC_CSRF);

    fetch('faculties.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.success && d.value) {
                document.getElementById('facBankAccDisplay').textContent = d.value;
                btn.innerHTML = '<i class="fas fa-eye-slash"></i> Revealed';
            } else {
                alert(d.error || 'Permission denied: cannot reveal bank account.');
                btn.innerHTML = '<i class="fas fa-eye"></i> Reveal';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-eye"></i> Reveal';
            alert('Server error revealing bank credentials.');
        });
}

function copyFacultyBankDetail(facId) {
    const btn = document.getElementById('facCopyBankBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('action', 'copy_faculty_bank');
    fd.append('faculty_id', facId);
    fd.append('field', 'bank_account');
    fd.append('csrf_token', FAC_CSRF);

    fetch('faculties.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.success && d.value) {
                navigator.clipboard.writeText(d.value).then(() => {
                    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 2000);
                }).catch(() => {
                    alert('Clipboard access denied. Bank account: ' + d.value);
                    btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                });
            } else {
                alert(d.error || 'Permission denied: cannot copy bank account.');
                btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
            alert('Server error copying bank credentials.');
        });
}

function copyFacultyStaticDetail(facId, field, value, btn) {
    const fd = new FormData();
    fd.append('action', 'copy_faculty_bank');
    fd.append('faculty_id', facId);
    fd.append('field', field);
    fd.append('csrf_token', FAC_CSRF);

    fetch('faculties.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                navigator.clipboard.writeText(value).then(() => {
                    const originalHtml = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
                }).catch(() => {
                    alert('Copied: ' + value);
                });
            } else {
                alert(d.error || 'Permission denied: cannot copy this credential.');
            }
        })
        .catch(() => {
            alert('Server error verifying copy permissions.');
        });
}
</script>

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
            <thead><tr><th>Faculty</th><th>Linked Employee</th><th>Rates (Live/QPD/Rec/Off)</th><th>Year</th><th>Earned</th><th>Paid</th><th>Due</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($faculties as $f):
                $calc = faculty_earned($pdo, $f, $sessions_ready, $TYPE_RATE);
                $paid = faculty_paid_total($pdo, $f['id']);
                $due = max(0, $calc['earned'] - $paid);
            ?>
                <tr>
                    <td><div class="cell-main"><?php echo e($f['name']); ?></div><div class="cell-sub"><?php echo format_credential($f['mobile'], 'phone', 'faculties') ?: '-'; ?><?php echo $f['email'] ? ' · ' . format_credential($f['email'], 'email', 'faculties') : ''; ?></div></td>
                    <td>
                        <?php if (!empty($f['employee_management_faculty_id'])): ?>
                            <span class="badge blue" title="Linked to Employee Management"><i class="fas fa-link"></i> <?php echo e($f['emp_code'] ?: ('EMP #' . $f['employee_management_faculty_id'])); ?></span>
                        <?php else: ?>
                            <span class="badge gray"><i class="fas fa-link-slash"></i> Unlinked</span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub">
                        <?php if (is_credential_restricted('financials')): ?>
                            *** / *** / *** / ***
                        <?php else: ?>
                            ₹<?php echo (int)$f['rate_live']; ?> / ₹<?php echo (int)$f['rate_qpd']; ?> / ₹<?php echo (int)$f['rate_recorded']; ?> / ₹<?php echo (int)$f['rate_offline']; ?>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub"><?php echo e($f['academic_year'] ?: '-'); ?></td>
                    <td><?php echo format_financial($calc['earned'], 0); ?></td>
                    <td><?php echo format_financial($paid, 0); ?></td>
                    <td><?php echo $due > 0 ? (is_credential_restricted('financials') ? '<span class="badge amber">' . format_financial($due, 0) . '</span>' : '<span class="badge amber">₹' . number_format($due, 0) . '</span>') : '<span class="badge green">Clear</span>'; ?></td>
                    <td><span class="badge <?php echo $f['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($f['status']); ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a class="btn btn-sm btn-primary" href="faculties.php?view=<?php echo (int)$f['id']; ?>" title="Schedules & payments"><i class="fas fa-arrow-right"></i></a>
                        <button class="btn btn-sm btn-outline" title="Edit" onclick='editFac(<?php echo json_encode([
                            "id"=>(int)$f["id"],"name"=>$f["name"],
                            "mobile"=>(string)format_credential_text($f["mobile"], "phone", "faculties"),
                            "email"=>(string)format_credential_text($f["email"], "email", "faculties"),
                            "rate_live"=>$f["rate_live"],"rate_qpd"=>$f["rate_qpd"],"rate_recorded"=>$f["rate_recorded"],"rate_offline"=>$f["rate_offline"],
                            "academic_year"=>(string)$f["academic_year"],"status"=>$f["status"],
                            "employee_management_faculty_id"=>$f["employee_management_faculty_id"] ?? null,
                            "emp_code"=>$f["emp_code"] ?? null,
                            "emp_name"=>$f["emp_name"] ?? null,
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

<!-- ADD FACULTY MODAL (Registered in Employee Management) -->
<div class="modal-backdrop" id="fac-add-modal">
    <div class="modal" style="max-width:640px;">
        <div class="modal-head">
            <h3><i class="fas fa-user-plus" style="color:var(--accent);"></i> Add Faculty from Employee Management</h3>
            <button class="modal-close" onclick="closeModal('fac-add-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_faculty">
            <div class="modal-body">
                <div class="alert alert-info" style="margin-bottom:14px; font-size:13px;">
                    <i class="fas fa-info-circle"></i>
                    <span>Faculty members must first be approved in <strong>Employee Management</strong>. Select an approved record to create and link this faculty. Authoritative details and rates will be populated automatically.</span>
                </div>

                <?php
                $unlinked_emp_count = count(array_filter($emp_faculties, function($ef) { return empty($ef['linked_faculty_id']); }));
                if ($unlinked_emp_count === 0):
                ?>
                    <div class="alert alert-warning" style="margin-bottom:14px; font-size:13px;">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>No unlinked approved faculty found. Please approve faculty applications in <a href="employee-management.php" target="_blank" style="text-decoration:underline; font-weight:700;">Employee Management</a> first.</span>
                    </div>
                <?php endif; ?>

                <div class="field" style="margin-bottom:14px;">
                    <label>Select Registered Faculty <span class="req">*</span></label>
                    <select name="employee_management_faculty_id" id="fac-add-select" required onchange="onAddFacultySelected(this.value)">
                        <option value="">-- Select Approved Registered Faculty --</option>
                        <?php foreach ($emp_faculties as $ef):
                            $is_linked = !empty($ef['linked_faculty_id']);
                        ?>
                            <option value="<?php echo (int)$ef['id']; ?>" <?php echo $is_linked ? 'disabled' : ''; ?>>
                                <?php echo e($ef['full_name']); ?> (<?php echo e($ef['employee_code']); ?><?php echo !empty($ef['academic_year']) ? ' - ' . e($ef['academic_year']) : ''; ?>)<?php echo $is_linked ? ' [Already Linked to ' . e($ef['linked_faculty_name']) . ']' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Preview of Authoritative Data -->
                <div id="fac-add-preview" style="display:none; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:14px; margin-bottom:14px;">
                    <div style="font-weight:700; font-size:12px; color:#475569; text-transform:uppercase; margin-bottom:8px;"><i class="fas fa-id-card"></i> Authoritative Profile Details</div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:13px; margin-bottom:12px;">
                        <div><strong>Name:</strong> <span id="fac-add-prev-name">-</span></div>
                        <div><strong>Mobile:</strong> <span id="fac-add-prev-mobile">-</span></div>
                        <div><strong>Email:</strong> <span id="fac-add-prev-email">-</span></div>
                        <div><strong>Academic Year:</strong> <span id="fac-add-prev-year">-</span></div>
                    </div>
                    <div style="font-weight:700; font-size:12px; color:#475569; text-transform:uppercase; margin-bottom:6px;"><i class="fas fa-indian-rupee-sign"></i> Hourly Session Charges</div>
                    <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; font-size:12px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:8px; text-align:center;">
                        <div><span style="color:#64748b; display:block;">Live</span><strong id="fac-add-prev-live">₹0</strong></div>
                        <div><span style="color:#64748b; display:block;">QPD</span><strong id="fac-add-prev-qpd">₹0</strong></div>
                        <div><span style="color:#64748b; display:block;">Recorded</span><strong id="fac-add-prev-rec">₹0</strong></div>
                        <div><span style="color:#64748b; display:block;">Offline</span><strong id="fac-add-prev-off">₹0</strong></div>
                    </div>
                </div>

                <div class="field">
                    <label>Initial Status</label>
                    <select name="status" id="fac-add-status">
                        <option value="active" selected>Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('fac-add-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="fac-add-submit-btn" <?php echo $unlinked_emp_count === 0 ? 'disabled' : ''; ?>><i class="fas fa-link"></i> Add & Link Faculty</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT FACULTY MODAL -->
<div class="modal-backdrop" id="fac-edit-modal">
    <div class="modal" style="max-width:680px;">
        <div class="modal-head">
            <h3><i class="fas fa-pen" style="color:var(--accent);"></i> Edit Faculty</h3>
            <button class="modal-close" onclick="closeModal('fac-edit-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" id="fac-edit-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="edit_faculty">
            <input type="hidden" name="faculty_id" id="fac-edit-id">
            <input type="hidden" name="employee_management_faculty_id" id="fac-edit-link-emp-id" value="">
            <div class="modal-body">

                <!-- LINKED / UNLINKED STATUS CARD -->
                <div id="fac-edit-linked-box" class="alert alert-info" style="display:none; margin-bottom:14px; padding:12px 14px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; width:100%; gap:10px;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <i class="fas fa-link" style="color:var(--accent);"></i>
                                <span>Linked Record: <strong id="fac-edit-linked-name"></strong> (<span id="fac-edit-linked-code"></span>)</span>
                                <span class="badge" style="background:#0284c7; color:#fff; font-size:11px; padding:3px 8px; border-radius:12px; font-weight:600;"><i class="fas fa-lock"></i> Managed from Employee Management</span>
                            </div>
                            <div style="font-size:11.5px; color:#475569; margin-top:6px;">
                                <i class="fas fa-info-circle"></i> Personal details and session charges are managed from Employee Management. To modify these values, edit the linked record in Employee Management.
                            </div>
                        </div>
                        <div style="display:flex; gap:6px; flex-shrink:0;">
                            <button type="button" class="btn btn-sm btn-soft-blue" onclick="toggleSwitchFacultyUI()"><i class="fas fa-arrows-rotate"></i> Switch Link</button>
                            <button type="button" class="btn btn-sm btn-soft-red" onclick="triggerUnlinkFaculty()"><i class="fas fa-link-slash"></i> Unlink</button>
                        </div>
                    </div>
                </div>

                <div id="fac-edit-switch-box" style="display:none; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:14px;">
                    <label style="font-weight:700; font-size:12px; display:block; margin-bottom:4px; color:#334155;">Switch to Another Registered Faculty</label>
                    <select id="fac-edit-switch-select" onchange="onEditSwitchSelected(this.value)" style="width:100%; margin-bottom:4px;">
                        <option value="">-- Select New Faculty to Switch Link --</option>
                    </select>
                    <div style="font-size:11px; color:#64748b;">Switching will overwrite Name, Mobile, Email, Academic Year, and Session Rates with authoritative data from the newly selected record.</div>
                </div>

                <div id="fac-edit-unlinked-box" class="alert alert-warning" style="display:none; margin-bottom:14px;">
                    <div style="margin-bottom:8px;"><i class="fas fa-link-slash"></i> <span>This faculty is currently <strong>not linked</strong> to any Employee Management record.</span></div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <label style="font-weight:600; font-size:12px; white-space:nowrap;">Link Registered Faculty:</label>
                        <select id="fac-edit-link-select" onchange="onEditLinkSelected(this.value)" style="flex:1;">
                            <option value="">-- Select Registered Faculty to Link --</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label>Faculty Name <span class="req">*</span> <span class="fac-linked-field-badge" style="display:none; font-size:10px; color:#0284c7; font-weight:600; margin-left:4px;"><i class="fas fa-lock"></i> Managed</span></label>
                        <input type="text" name="name" id="fac-edit-name" required>
                    </div>
                    <div class="field">
                        <label>Mobile Number <span class="fac-linked-field-badge" style="display:none; font-size:10px; color:#0284c7; font-weight:600; margin-left:4px;"><i class="fas fa-lock"></i> Managed</span></label>
                        <input type="text" name="mobile" id="fac-edit-mobile">
                    </div>
                    <div class="field">
                        <label>Email ID <span class="fac-linked-field-badge" style="display:none; font-size:10px; color:#0284c7; font-weight:600; margin-left:4px;"><i class="fas fa-lock"></i> Managed</span></label>
                        <input type="email" name="email" id="fac-edit-email">
                    </div>
                    <div class="field">
                        <label>PEPP Academic Year <span class="fac-linked-field-badge" style="display:none; font-size:10px; color:#0284c7; font-weight:600; margin-left:4px;"><i class="fas fa-lock"></i> Managed</span></label>
                        <select name="academic_year" id="fac-edit-year"><option value="">-</option><?php foreach ($academic_years as $y): ?><option value="<?php echo e($y); ?>"><?php echo e($y); ?></option><?php endforeach; ?></select>
                    </div>
                </div>
                <div class="cell-sub" style="font-weight:700; margin:14px 0 6px;">
                    Charge / hour by session type
                    <span class="fac-linked-field-badge" style="display:none; font-size:10px; color:#0284c7; font-weight:600; margin-left:4px;"><i class="fas fa-lock"></i> Managed from Employee Management</span>
                </div>
                <div class="form-grid">
                    <?php if (is_credential_restricted('financials')): ?>
                        <div class="field"><label>Live Session (₹/hr)</label><input type="text" disabled value="***" style="background:#f1f5f9; cursor:not-allowed;"></div>
                        <div class="field"><label>QPD (₹/hr)</label><input type="text" disabled value="***" style="background:#f1f5f9; cursor:not-allowed;"></div>
                        <div class="field"><label>Recorded (₹/hr)</label><input type="text" disabled value="***" style="background:#f1f5f9; cursor:not-allowed;"></div>
                        <div class="field"><label>Offline Session (₹/hr)</label><input type="text" disabled value="***" style="background:#f1f5f9; cursor:not-allowed;"></div>
                    <?php else: ?>
                        <div class="field"><label>Live Session (₹/hr)</label><input type="number" step="0.01" min="0" name="rate_live" id="fac-edit-rate_live" value="0"></div>
                        <div class="field"><label>QPD (₹/hr)</label><input type="number" step="0.01" min="0" name="rate_qpd" id="fac-edit-rate_qpd" value="0"></div>
                        <div class="field"><label>Recorded (₹/hr)</label><input type="number" step="0.01" min="0" name="rate_recorded" id="fac-edit-rate_recorded" value="0"></div>
                        <div class="field"><label>Offline Session (₹/hr)</label><input type="number" step="0.01" min="0" name="rate_offline" id="fac-edit-rate_offline" value="0"></div>
                    <?php endif; ?>
                    <div class="field"><label>Status</label><select name="status" id="fac-edit-status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('fac-edit-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Faculty</button>
            </div>
        </form>
    </div>
</div>

<!-- HIDDEN UNLINK FORM -->
<form method="POST" id="unlink-faculty-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="unlink_faculty">
    <input type="hidden" name="faculty_id" id="unlink-fac-id">
</form>

<?php
$extra_scripts = "<script>
var registeredFacultiesData = " . json_encode($emp_faculties ?: [], JSON_HEX_APOS|JSON_HEX_QUOT) . ";

function setFacEditFieldReadonly(isLinked) {
    var fieldIds = ['fac-edit-name', 'fac-edit-mobile', 'fac-edit-email', 'fac-edit-rate_live', 'fac-edit-rate_qpd', 'fac-edit-rate_recorded', 'fac-edit-rate_offline'];
    fieldIds.forEach(function(fid) {
        var el = document.getElementById(fid);
        if (el) {
            el.readOnly = isLinked;
            el.style.backgroundColor = isLinked ? '#f1f5f9' : '';
            el.style.cursor = isLinked ? 'not-allowed' : '';
        }
    });

    var yr = document.getElementById('fac-edit-year');
    if (yr) {
        yr.style.pointerEvents = isLinked ? 'none' : '';
        yr.style.backgroundColor = isLinked ? '#f1f5f9' : '';
        yr.style.cursor = isLinked ? 'not-allowed' : '';
        yr.tabIndex = isLinked ? -1 : 0;
    }

    document.querySelectorAll('.fac-linked-field-badge').forEach(function(badge) {
        badge.style.display = isLinked ? 'inline-block' : 'none';
    });
}

function openFacModal() {
    var sel = document.getElementById('fac-add-select');
    if (sel) sel.value = '';
    var prev = document.getElementById('fac-add-preview');
    if (prev) prev.style.display = 'none';
    var st = document.getElementById('fac-add-status');
    if (st) st.value = 'active';
    openModal('fac-add-modal');
}

function onAddFacultySelected(empId) {
    var preview = document.getElementById('fac-add-preview');
    if (!empId) {
        if (preview) preview.style.display = 'none';
        return;
    }
    var emp = registeredFacultiesData.find(function(item) { return item.id == empId; });
    if (!emp) {
        if (preview) preview.style.display = 'none';
        return;
    }
    document.getElementById('fac-add-prev-name').innerText = emp.full_name || '-';
    document.getElementById('fac-add-prev-mobile').innerText = emp.mobile_number || '-';
    document.getElementById('fac-add-prev-email').innerText = emp.email || '-';
    document.getElementById('fac-add-prev-year').innerText = emp.academic_year || '-';
    document.getElementById('fac-add-prev-live').innerText = '₹' + parseFloat(emp.rate_live || 0).toFixed(0);
    document.getElementById('fac-add-prev-qpd').innerText = '₹' + parseFloat(emp.rate_qpd || 0).toFixed(0);
    document.getElementById('fac-add-prev-rec').innerText = '₹' + parseFloat(emp.rate_recorded || 0).toFixed(0);
    document.getElementById('fac-add-prev-off').innerText = '₹' + parseFloat(emp.rate_offline || 0).toFixed(0);
    if (preview) preview.style.display = 'block';
}

function editFac(f) {
    document.getElementById('fac-edit-id').value = f.id;
    document.getElementById('fac-edit-name').value = f.name || '';
    document.getElementById('fac-edit-mobile').value = f.mobile || '';
    document.getElementById('fac-edit-email').value = f.email || '';
    document.getElementById('fac-edit-year').value = f.academic_year || '';
    var rLive = document.getElementById('fac-edit-rate_live'); if (rLive) rLive.value = f.rate_live;
    var rQpd = document.getElementById('fac-edit-rate_qpd'); if (rQpd) rQpd.value = f.rate_qpd;
    var rRec = document.getElementById('fac-edit-rate_recorded'); if (rRec) rRec.value = f.rate_recorded;
    var rOff = document.getElementById('fac-edit-rate_offline'); if (rOff) rOff.value = f.rate_offline;
    document.getElementById('fac-edit-status').value = f.status || 'active';
    document.getElementById('fac-edit-link-emp-id').value = '';

    var linkedBox = document.getElementById('fac-edit-linked-box');
    var unlinkedBox = document.getElementById('fac-edit-unlinked-box');
    var switchBox = document.getElementById('fac-edit-switch-box');
    if (switchBox) switchBox.style.display = 'none';

    var isLinked = (f.employee_management_faculty_id && parseInt(f.employee_management_faculty_id) > 0);
    setFacEditFieldReadonly(isLinked);

    if (isLinked) {
        if (linkedBox) linkedBox.style.display = 'block';
        if (unlinkedBox) unlinkedBox.style.display = 'none';
        document.getElementById('fac-edit-linked-name').innerText = f.emp_name || f.name;
        document.getElementById('fac-edit-linked-code').innerText = f.emp_code || ('EMP #' + f.employee_management_faculty_id);

        var switchSel = document.getElementById('fac-edit-switch-select');
        if (switchSel) {
            switchSel.innerHTML = '<option value=\"\">-- Select New Faculty to Switch Link --</option>';
            registeredFacultiesData.forEach(function(ef) {
                if (!ef.linked_faculty_id || ef.id == f.employee_management_faculty_id) {
                    var opt = document.createElement('option');
                    opt.value = ef.id;
                    opt.textContent = ef.full_name + ' (' + ef.employee_code + (ef.academic_year ? ' - ' + ef.academic_year : '') + ')' + (ef.id == f.employee_management_faculty_id ? ' [Current]' : '');
                    if (ef.id == f.employee_management_faculty_id) opt.disabled = true;
                    switchSel.appendChild(opt);
                }
            });
        }
    } else {
        if (linkedBox) linkedBox.style.display = 'none';
        if (unlinkedBox) unlinkedBox.style.display = 'block';

        var linkSel = document.getElementById('fac-edit-link-select');
        if (linkSel) {
            linkSel.innerHTML = '<option value=\"\">-- Select Registered Faculty to Link --</option>';
            registeredFacultiesData.forEach(function(ef) {
                if (!ef.linked_faculty_id) {
                    var opt = document.createElement('option');
                    opt.value = ef.id;
                    opt.textContent = ef.full_name + ' (' + ef.employee_code + (ef.academic_year ? ' - ' + ef.academic_year : '') + ')';
                    linkSel.appendChild(opt);
                }
            });
        }
    }

    openModal('fac-edit-modal');
}

function toggleSwitchFacultyUI() {
    var box = document.getElementById('fac-edit-switch-box');
    if (!box) return;
    box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
}

function onEditLinkSelected(empId) {
    if (!empId) return;
    var emp = registeredFacultiesData.find(function(item) { return item.id == empId; });
    if (!emp) return;

    if (!confirm('Link with ' + emp.full_name + ' (' + emp.employee_code + ')?\\n\\nThis will overwrite Name, Mobile, Email, Academic Year, and Session Rates with authoritative data from Employee Management.')) {
        document.getElementById('fac-edit-link-select').value = '';
        return;
    }

    document.getElementById('fac-edit-link-emp-id').value = emp.id;
    document.getElementById('fac-edit-name').value = emp.full_name || '';
    document.getElementById('fac-edit-mobile').value = emp.mobile_number || '';
    document.getElementById('fac-edit-email').value = emp.email || '';
    document.getElementById('fac-edit-year').value = emp.academic_year || '';
    var rLive = document.getElementById('fac-edit-rate_live'); if (rLive) rLive.value = emp.rate_live || 0;
    var rQpd = document.getElementById('fac-edit-rate_qpd'); if (rQpd) rQpd.value = emp.rate_qpd || 0;
    var rRec = document.getElementById('fac-edit-rate_recorded'); if (rRec) rRec.value = emp.rate_recorded || 0;
    var rOff = document.getElementById('fac-edit-rate_offline'); if (rOff) rOff.value = emp.rate_offline || 0;
    setFacEditFieldReadonly(true);
}

function onEditSwitchSelected(empId) {
    if (!empId) return;
    var emp = registeredFacultiesData.find(function(item) { return item.id == empId; });
    if (!emp) return;

    if (!confirm('Switch linked faculty to ' + emp.full_name + ' (' + emp.employee_code + ')?\\n\\nThis will overwrite Name, Mobile, Email, Academic Year, and Session Rates with authoritative data from the new record.')) {
        document.getElementById('fac-edit-switch-select').value = '';
        return;
    }

    document.getElementById('fac-edit-link-emp-id').value = emp.id;
    document.getElementById('fac-edit-name').value = emp.full_name || '';
    document.getElementById('fac-edit-mobile').value = emp.mobile_number || '';
    document.getElementById('fac-edit-email').value = emp.email || '';
    document.getElementById('fac-edit-year').value = emp.academic_year || '';
    var rLive = document.getElementById('fac-edit-rate_live'); if (rLive) rLive.value = emp.rate_live || 0;
    var rQpd = document.getElementById('fac-edit-rate_qpd'); if (rQpd) rQpd.value = emp.rate_qpd || 0;
    var rRec = document.getElementById('fac-edit-rate_recorded'); if (rRec) rRec.value = emp.rate_recorded || 0;
    var rOff = document.getElementById('fac-edit-rate_offline'); if (rOff) rOff.value = emp.rate_offline || 0;
    setFacEditFieldReadonly(true);
}

function triggerUnlinkFaculty() {
    var facId = document.getElementById('fac-edit-id').value;
    if (!facId) return;
    if (!confirm('Are you sure you want to unlink this faculty from Employee Management?\\n\\nExisting faculty data, schedules, and payment history will remain intact. The Employee Management record will become available to link again.')) {
        return;
    }
    document.getElementById('unlink-fac-id').value = facId;
    document.getElementById('unlink-faculty-form').submit();
}
</script>\n";
?>
<?php endif; ?>
<?php include 'includes/admin_footer.php'; ?>
