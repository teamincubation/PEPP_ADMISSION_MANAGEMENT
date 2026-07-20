<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('students');


// AJAX API for remarks management
if (isset($_GET['ajax_remarks'])) {
    header('Content-Type: application/json');
    $user_id = trim($_GET['user_id'] ?? '');
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT name FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $student_name = $stmt->fetchColumn();
    
    if (!$student_name) {
        echo json_encode(['error' => 'Student not found.']);
        exit();
    }
    
    // Fetch remarks
    $stmt = $pdo->prepare("
        SELECT sr.*, r.remind_at, r.status AS reminder_status
        FROM student_remarks sr
        LEFT JOIN reminders r ON r.id = sr.reminder_id
        WHERE sr.user_id = ?
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $remarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'student_name' => $student_name,
        'remarks' => $remarks
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['error' => 'Security token mismatch. Please reload and retry.']);
        exit();
    }
    
    $action = $_POST['ajax_action'];
    $user_id = trim($_POST['user_id'] ?? '');
    
    if ($action === 'add') {
        $remark = trim($_POST['remark'] ?? '');
        if ($remark === '') {
            echo json_encode(['error' => 'Remark cannot be empty.']);
            exit();
        }
        
        try {
            $pdo->beginTransaction();
            
            $reminder_id = null;
            if (!empty($_POST['set_reminder'])) {
                $rem_title = trim($_POST['reminder_title'] ?? '');
                $rem_time = trim($_POST['reminder_time'] ?? '');
                if ($rem_title !== '' && $rem_time !== '') {
                    $ts = strtotime($rem_time);
                    if ($ts > 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO reminders (title, notes, remind_at, assigned_to, status, created_by, student_id, created_at)
                            VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
                        ");
                        $stmt->execute([$rem_title, $remark, date('Y-m-d H:i:s', $ts), $admin_username, $admin_username, $user_id]);
                        $reminder_id = $pdo->lastInsertId();
                    }
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
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Failed to save remark: ' . $e->getMessage()]);
        }
        exit();
        
    } elseif ($action === 'edit') {
        $remark_id = (int)($_POST['remark_id'] ?? 0);
        $remark = trim($_POST['remark'] ?? '');
        if ($remark === '') {
            echo json_encode(['error' => 'Remark cannot be empty.']);
            exit();
        }
        
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
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Remark not found.']);
            }
        } catch (Exception $e) {
            echo json_encode(['error' => 'Failed to update remark: ' . $e->getMessage()]);
        }
        exit();
        
    } elseif ($action === 'delete') {
        $remark_id = (int)($_POST['remark_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT * FROM student_remarks WHERE id = ? AND user_id = ?");
            $stmt->execute([$remark_id, $user_id]);
            $rem = $stmt->fetch();
            if ($rem) {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM student_remarks WHERE id = ?")->execute([$remark_id]);
                if ($rem['reminder_id']) {
                    $pdo->prepare("DELETE FROM reminders WHERE id = ? AND status = 'pending'")->execute([$rem['reminder_id']]);
                }
                $pdo->commit();
                track_record($pdo, $user_id, 'remark_deleted', "Deleted remark: {$rem['remark']}", $admin_username);
                log_admin_activity($pdo, $admin_username, 'remark_deleted', "Deleted remark $remark_id for student $user_id");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Remark not found.']);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Failed to delete remark: ' . $e->getMessage()]);
        }
        exit();
    }
}

$success_message = '';
$error_message   = '';

/* ── POST actions: status change / extend access / add remark ──── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action  = $_POST['action'] ?? '';
        $user_id = trim($_POST['user_id'] ?? '');
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $target = $stmt->fetch();

            if (!$target) {
                $error_message = 'Student not found.';
            } elseif ($action === 'update_status') {
                $new_status = $_POST['student_status'] ?? '';
                $allowed = ['active', 'inactive', 'suspended', 'completed', 'dropout'];
                if (!in_array($new_status, $allowed, true)) {
                    $error_message = 'Invalid status.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("UPDATE users SET student_status = ?, course_status = ? WHERE user_id = ?");
                        $course_status = $new_status === 'inactive' ? 'suspended' : ($new_status === 'completed' ? 'completed' : (($new_status === 'suspended' || $new_status === 'dropout') ? 'suspended' : 'active'));
                        $stmt->execute([$new_status, $course_status, $user_id]);
                        
                        if ($new_status === 'dropout') {
                            $pdo->prepare("DELETE FROM instalment_details WHERE user_id = ? AND status = 'pending' AND paid_date IS NULL")->execute([$user_id]);
                        }
                        
                        status_log($pdo, $user_id, $target['student_status'], $new_status, trim($_POST['reason'] ?? 'Status updated by admin'), $admin_username);
                        track_record($pdo, $user_id, 'status_changed', "Student status: {$target['student_status']} → {$new_status}", $admin_username);
                        $pdo->commit();
                        $success_message = "Status updated for {$target['name']}.";
                    } catch (Exception $ex) {
                        $pdo->rollBack();
                        throw $ex;
                    }
                }
            } elseif ($action === 'extend_access') {
                $new_date = $_POST['course_duration_date'] ?? '';
                if (!$new_date) {
                    $error_message = 'A new access end date is required.';
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET course_duration_date = ? WHERE user_id = ?");
                    $stmt->execute([$new_date, $user_id]);
                    track_record($pdo, $user_id, 'access_extended',
                        'Course access date changed from ' . ($target['course_duration_date'] ?: '-') . " to {$new_date}", $admin_username);
                    $success_message = "Course access for {$target['name']} now ends " . date('d M Y', strtotime($new_date)) . '.';
                }
            } elseif ($action === 'add_remark') {
                $remark = trim($_POST['remark'] ?? '');
                if ($remark === '') {
                    $error_message = 'Remark cannot be empty.';
                } else {
                    status_log($pdo, $user_id, 'remark', 'remark', $remark, $admin_username);
                    track_record($pdo, $user_id, 'remark_added', $remark, $admin_username);
                    $success_message = 'Remark saved.';
                }
            }
        } catch (Exception $e) {
            error_log('Student action: ' . $e->getMessage());
            $error_message = 'Database error while saving changes.';
        }
    }
}

/* ── Filters ────────────────────────────────────────────────────── */
$search        = trim($_GET['search'] ?? '');
$filter_course = trim($_GET['course'] ?? '');
$filter_year   = trim($_GET['year'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$sort_by       = trim($_GET['sort_by'] ?? '');

$where  = ["u.status = 'approved'"];
$params = [];

if ($search !== '') {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.user_id LIKE ? OR u.whatsapp_number LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($filter_course !== '') { $where[] = "u.pepp_course = ?";        $params[] = $filter_course; }
if ($filter_year   !== '') { $where[] = "u.pepp_academic_year = ?"; $params[] = $filter_year; }
if ($filter_status !== '') { 
    $where[] = "u.student_status = ?";     
    $params[] = $filter_status; 
} else {
    $where[] = "(u.student_status <> 'dropout' OR u.student_status IS NULL)";
}

$where_clause = implode(' AND ', $where);

$order_by = "ORDER BY u.created_at DESC";
if ($sort_by === 'remarks_desc') {
    $order_by = "ORDER BY remarks_count DESC, u.created_at DESC";
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$students = [];
$total_students = 0;
$courses = $years = [];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $where_clause");
    $stmt->execute($params);
    $total_students = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.name, u.email, u.whatsapp_country_code, u.whatsapp_number,
               u.pepp_course, u.pepp_academic_year, u.student_status, u.onboarding_status,
               u.paid_amount, u.payment_plan, u.course_duration_date, u.joined_date, u.created_at, u.user_photo,
               DATEDIFF(u.course_duration_date, CURDATE()) AS days_remaining,
               (SELECT COUNT(*) FROM instalment_details i WHERE i.user_id = u.user_id AND i.status = 'pending') AS open_installments,
               (SELECT COUNT(*) FROM student_remarks sr WHERE sr.user_id = u.user_id) AS remarks_count
        FROM users u
        WHERE $where_clause
        $order_by
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $courses = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
    $years   = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('Student list: ' . $e->getMessage());
    $error_message = $error_message ?: 'Could not load students.';
}

$total_pages = max(1, (int)ceil($total_students / $per_page));

function qs($overrides = []) {
    $q = array_merge($_GET, $overrides);
    unset($q['logout']);
    return '?' . http_build_query($q);
}

$active_page = 'students';
$page_title  = 'All Students';
$page_sub    = 'Approved students - profiles, status & course access';
include 'includes/admin_nav.php';
?>

<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span>Student "<?php echo e($_GET['deleted']); ?>" and all related data were deleted.</span></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<!-- ── FILTERS ── -->
<div class="panel">
    <div class="panel-body">
        <form method="GET" class="filter-bar">
            <div class="field grow-2">
                <label>Search</label>
                <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Name, email, ID or WhatsApp number">
            </div>
            <div class="field">
                <label>Course</label>
                <select name="course">
                    <option value="">All courses</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?php echo e($c); ?>" <?php echo $filter_course === $c ? 'selected' : ''; ?>><?php echo e($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Year</label>
                <select name="year">
                    <option value="">All years</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo e($y); ?>" <?php echo $filter_year === $y ? 'selected' : ''; ?>><?php echo e($y); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach (['active','inactive','suspended','completed','dropout'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $filter_status === $st ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Sort By</label>
                <select name="sort_by">
                    <option value="">Default (Newest)</option>
                    <option value="remarks_desc" <?php echo $sort_by === 'remarks_desc' ? 'selected' : ''; ?>>Remarks Added First</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="studentpage.php" class="btn btn-outline">Reset</a>
        </form>
    </div>
</div>

<!-- ── LIST ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon"><i class="fas fa-users"></i></span>
        <h2>Students (<?php echo number_format($total_students); ?>)</h2>
        <div class="head-right">
            <a class="btn btn-sm btn-soft-violet" href="add-student.php"><i class="fas fa-user-plus"></i> Add Student</a>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($students)): ?>
            <div class="empty-state"><i class="fas fa-user-slash"></i><p>No students match these filters.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr>
                <th>Student</th><th>Course</th><th>Access ends</th><th>Installments</th><th>Status</th><th style="text-align:right;">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($students as $s):
                $st = $s['student_status'] ?: 'active';
                $stBadge = $st === 'active' ? 'green' : ($st === 'completed' ? 'blue' : ($st === 'suspended' ? 'red' : 'gray'));
                $days = $s['days_remaining'];
                $accessBadge = $days === null ? 'gray' : ($days < 0 ? 'red' : ($days <= 7 ? 'amber' : 'green'));
            ?>
                <tr>
                    <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <?php
                            $photo = $s['user_photo'] ?: 'assets/img/default-avatar.svg';
                            ?>
                            <img src="<?php echo e($photo); ?>" onerror="this.src='assets/img/default-avatar.svg'; this.onerror=null;" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:1px solid var(--border);" alt="Avatar">
                            <div>
                                <div class="cell-main" style="display:inline-flex; align-items:center; gap:6px;">
                                    <?php echo e($s['name']); ?>
                                    <?php if ((int)$s['remarks_count'] > 0): ?>
                                        <span class="badge amber" title="Has Remarks/Notes (<?php echo (int)$s['remarks_count']; ?>)" style="font-size:0.62rem; padding:1px 4px; display:inline-flex; align-items:center; gap:2px; cursor:pointer;" onclick="openRemarksModal('<?php echo e($s['user_id']); ?>')"><i class="fas fa-clipboard"></i> Remark</span>
                                    <?php endif; ?>
                                </div>
                                <div class="cell-sub"><?php echo e($s['user_id']); ?> &middot; <?php echo format_credential($s['email'], 'email'); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:.82rem;font-weight:600;"><?php echo e($s['pepp_course']); ?></div>
                        <div class="cell-sub"><?php echo e($s['pepp_academic_year']); ?> &middot; <?php echo e($s['payment_plan'] ?: 'One Time'); ?></div>
                    </td>
                    <td>
                        <?php if ($s['course_duration_date']): ?>
                            <span class="badge <?php echo $accessBadge; ?>">
                                <?php echo date('d M Y', strtotime($s['course_duration_date'])); ?>
                                <?php echo $days !== null ? ($days < 0 ? ' · expired' : " · {$days}d") : ''; ?>
                            </span>
                        <?php else: ?><span class="cell-sub">-</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$s['open_installments'] > 0): ?>
                            <span class="badge amber"><?php echo (int)$s['open_installments']; ?> pending</span>
                        <?php else: ?>
                            <span class="badge green">Clear</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo $stBadge; ?>"><?php echo ucfirst($st); ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a class="btn btn-sm btn-outline" href="student-details.php?user_id=<?php echo urlencode($s['user_id']); ?>" title="Full profile"><i class="fas fa-eye"></i></a>
                        <button class="btn btn-sm btn-soft-violet" title="Remarks/Notes" onclick="openRemarksModal('<?php echo e($s['user_id']); ?>')"><i class="fas fa-clipboard"></i></button>
                        <button class="btn btn-sm btn-soft-blue" title="Extend access" onclick="openExtend('<?php echo e($s['user_id']); ?>', '<?php echo e(addslashes($s['name'])); ?>', '<?php echo e($s['course_duration_date']); ?>')"><i class="fas fa-calendar-plus"></i></button>
                        <button class="btn btn-sm btn-soft-amber" title="Change status" onclick="openStatus('<?php echo e($s['user_id']); ?>', '<?php echo e(addslashes($s['name'])); ?>', '<?php echo e($st); ?>')"><i class="fas fa-user-gear"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a class="page-link" href="<?php echo e(qs(['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
            <?php for ($p = max(1, $page - 3); $p <= min($total_pages, $page + 3); $p++): ?>
                <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" href="<?php echo e(qs(['page' => $p])); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?><a class="page-link" href="<?php echo e(qs(['page' => $page + 1])); ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── EXTEND ACCESS MODAL ── -->
<div class="modal-backdrop" id="extend-modal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-head">
            <h3><i class="fas fa-calendar-plus" style="color:var(--blue);"></i> Extend Course Access</h3>
            <button class="modal-close" onclick="closeModal('extend-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="extend_access">
            <input type="hidden" name="user_id" id="ext-user-id">
            <div class="modal-body">
                <p id="ext-name" style="font-weight:700; margin-bottom:12px;"></p>
                <div class="field">
                    <label>New access end date <span class="req">*</span></label>
                    <input type="date" name="course_duration_date" id="ext-date" required>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('extend-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
            </div>
        </form>
    </div>
</div>

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

<!-- ── REMARKS MODAL ── -->
<div class="modal-backdrop" id="remarks-modal">
    <div class="modal" style="max-width:680px; width:90%;">
        <div class="modal-head">
            <h3><i class="fas fa-clipboard" style="color:var(--accent);"></i> Remarks &amp; Notes</h3>
            <button class="modal-close" onclick="closeRemarksModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="max-height: 480px; overflow-y: auto;">
            <div class="alert alert-info" style="margin-bottom:12px;"><i class="fas fa-user-graduate"></i> Student: <strong id="rem-student-name"></strong> <span id="rem-student-id" class="cell-sub" style="font-size:0.8rem; font-weight:normal; margin-left:6px;"></span></div>
            
            <div id="remarks-list-container" style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
                <!-- Remarks list loaded dynamically -->
            </div>

            <!-- Form to add/edit a remark -->
            <div style="background:var(--gray-50); border:1px solid var(--border); padding:16px; border-radius:8px;">
                <h4 style="margin:0 0 12px 0; font-size:0.9rem; color:var(--text-main);" id="rem-form-title">Add New Remark / Note</h4>
                <input type="hidden" id="rem-edit-id" value="">
                
                <div class="field" style="margin-bottom:10px;">
                    <label style="font-size:0.75rem;">Remark Text <span class="req">*</span></label>
                    <textarea id="rem-text" rows="3" class="form-input" style="width:100%; border:1px solid var(--border); border-radius:6px; padding:8px;" placeholder="Type your remark or note here..."></textarea>
                </div>
                
                <div id="rem-add-only-reminder">
                    <label style="display:inline-flex; align-items:center; gap:8px; font-weight:600; font-size:0.8rem; cursor:pointer; margin-bottom:10px;">
                        <input type="checkbox" id="rem-set-reminder" style="width:16px; height:16px; accent-color:var(--accent);" onchange="toggleModalReminderFields(this.checked)"> Set a reminder for this note
                    </label>
                    
                    <div id="rem-reminder-fields" style="display:none; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                        <div class="field">
                            <label style="font-size:0.75rem;">Reminder Title <span class="req">*</span></label>
                            <input type="text" id="rem-reminder-title" class="form-input" placeholder="e.g. Call student back">
                        </div>
                        <div class="field">
                            <label style="font-size:0.75rem;">Reminder Time <span class="req">*</span></label>
                            <input type="datetime-local" id="rem-reminder-time" class="form-input">
                        </div>
                    </div>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" id="rem-cancel-edit-btn" class="btn btn-sm btn-outline" style="display:none;" onclick="cancelRemarkEdit()">Cancel Edit</button>
                    <button type="button" class="btn btn-sm btn-primary" onclick="saveRemarksModalRemark()"><i class="fas fa-floppy-disk"></i> Save Remark</button>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-outline" onclick="closeRemarksModal()">Close</button>
        </div>
    </div>
</div>

<?php
$extra_scripts = "<script>
var activeRemarkStudentId = '';
var remarkMutated = false;

function toggleModalReminderFields(checked) {
    document.getElementById('rem-reminder-fields').style.display = checked ? 'grid' : 'none';
    document.getElementById('rem-reminder-title').required = checked;
    document.getElementById('rem-reminder-time').required = checked;
}

function openRemarksModal(userId) {
    activeRemarkStudentId = userId;
    remarkMutated = false;
    cancelRemarkEdit();
    loadRemarksForModal(userId);
    openModal('remarks-modal');
}

function closeRemarksModal() {
    closeModal('remarks-modal');
    if (remarkMutated) {
        window.location.reload();
    }
}

function loadRemarksForModal(userId) {
    const listContainer = document.getElementById('remarks-list-container');
    listContainer.innerHTML = \"<div style='text-align:center; padding:20px; color:var(--text-muted);'><i class='fas fa-spinner fa-spin'></i> Loading remarks...</div>\";
    
    fetch('studentpage.php?ajax_remarks=1&user_id=' + encodeURIComponent(userId))
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                alert(data.error);
                closeModal('remarks-modal');
                return;
            }
            document.getElementById('rem-student-name').textContent = data.student_name;
            document.getElementById('rem-student-id').textContent = '(' + userId + ')';
            
            listContainer.innerHTML = '';
            if (data.remarks.length === 0) {
                listContainer.innerHTML = \"<div style='text-align:center; padding:20px; color:var(--text-muted); border:1px dashed var(--border); border-radius:6px; background:var(--gray-50);'><p style='margin:0;'>No remarks or notes added yet.</p></div>\";
                return;
            }
            
            data.remarks.forEach(rem => {
                const card = document.createElement('div');
                card.style.background = 'var(--card)';
                card.style.border = '1px solid var(--border)';
                card.style.borderRadius = '6px';
                card.style.padding = '12px';
                card.style.position = 'relative';
                
                let reminderHtml = '';
                if (rem.remind_at) {
                    const statusClass = rem.reminder_status === 'completed' ? 'green' : 'amber';
                    reminderHtml = `
                        <div style=\"margin-top:6px; display:inline-flex; align-items:center; gap:4px; font-size:0.7rem;\" class=\"cell-sub\">
                            <span class=\"badge \${statusClass}\" style=\"font-size:0.6rem; padding:1px 4px;\"><i class=\"fas fa-clock\"></i> Reminder: \${rem.remind_at} (\${rem.reminder_status})</span>
                        </div>
                    `;
                }
                
                const timeStr = new Date(rem.created_at).toLocaleDateString('en-IN', {day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'});
                
                card.innerHTML = `
                    <div style=\"font-size:0.85rem; color:var(--text-main); white-space:pre-wrap; line-height:1.4; padding-right:60px;\">\${escapeHtml(rem.remark)}</div>
                    <div style=\"font-size:0.7rem; color:var(--text-muted); margin-top:6px; display:flex; justify-content:space-between; align-items:center;\">
                        <span>By <strong>\${escapeHtml(rem.created_by)}</strong> on \${timeStr}</span>
                        <div style=\"display:flex; gap:8px;\">
                            <a href=\"javascript:void(0)\" onclick=\"editRemarkFromModal(\${rem.id}, \${JSON.stringify(rem.remark).replace(/\"/g, '&quot;')})\" style=\"color:var(--accent); font-weight:600; text-decoration:none;\"><i class=\"fas fa-pen\"></i> Edit</a>
                            <a href=\"javascript:void(0)\" onclick=\"deleteRemarkFromModal(\${rem.id})\" style=\"color:#ef4444; font-weight:600; text-decoration:none;\"><i class=\"fas fa-trash\"></i> Delete</a>
                        </div>
                    </div>
                    \${reminderHtml}
                `;
                listContainer.appendChild(card);
            });
        })
        .catch(err => {
            listContainer.innerHTML = \"<div class='alert alert-error'><i class='fas fa-triangle-exclamation'></i><span>Error loading remarks.</span></div>\";
        });
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function editRemarkFromModal(id, text) {
    document.getElementById('rem-edit-id').value = id;
    document.getElementById('rem-text').value = text;
    document.getElementById('rem-form-title').innerHTML = \"<i class='fas fa-pen' style='color:var(--accent);'></i> Edit Remark / Note\";
    document.getElementById('rem-cancel-edit-btn').style.display = 'inline-block';
    document.getElementById('rem-add-only-reminder').style.display = 'none';
}

function cancelRemarkEdit() {
    document.getElementById('rem-edit-id').value = '';
    document.getElementById('rem-text').value = '';
    document.getElementById('rem-form-title').textContent = 'Add New Remark / Note';
    document.getElementById('rem-cancel-edit-btn').style.display = 'none';
    document.getElementById('rem-add-only-reminder').style.display = 'block';
    document.getElementById('rem-set-reminder').checked = false;
    toggleModalReminderFields(false);
}

function saveRemarksModalRemark() {
    const text = document.getElementById('rem-text').value.trim();
    if (text === '') {
        alert('Remark text cannot be empty.');
        return;
    }
    
    const editId = document.getElementById('rem-edit-id').value;
    const isEdit = editId !== '';
    
    const fd = new FormData();
    fd.append('ajax_action', isEdit ? 'edit' : 'add');
    fd.append('user_id', activeRemarkStudentId);
    fd.append('remark', text);
    fd.append('csrf_token', '\" . csrf_token() . \"');
    
    if (isEdit) {
        fd.append('remark_id', editId);
    } else {
        if (document.getElementById('rem-set-reminder').checked) {
            fd.append('set_reminder', '1');
            fd.append('reminder_title', document.getElementById('rem-reminder-title').value.trim());
            fd.append('reminder_time', document.getElementById('rem-reminder-time').value);
        }
    }
    
    fetch('studentpage.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            alert(data.error);
        } else {
            remarkMutated = true;
            cancelRemarkEdit();
            loadRemarksForModal(activeRemarkStudentId);
        }
    })
    .catch(err => {
        alert('Failed to save remark.');
    });
}

function deleteRemarkFromModal(remarkId) {
    if (!confirm('Are you sure you want to delete this remark?')) return;
    
    const fd = new FormData();
    fd.append('ajax_action', 'delete');
    fd.append('user_id', activeRemarkStudentId);
    fd.append('remark_id', remarkId);
    fd.append('csrf_token', '\" . csrf_token() . \"');
    
    fetch('studentpage.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.error) {
            alert(data.error);
        } else {
            remarkMutated = true;
            loadRemarksForModal(activeRemarkStudentId);
        }
    })
    .catch(err => {
        alert('Failed to delete remark.');
    });
}

function openExtend(id, name, current) {
    document.getElementById('ext-user-id').value = id;
    document.getElementById('ext-name').textContent = name + ' (' + id + ')';
    document.getElementById('ext-date').value = current || '';
    openModal('extend-modal');
}
function openStatus(id, name, current) {
    document.getElementById('st-user-id').value = id;
    document.getElementById('st-name').textContent = name + ' (' + id + ')';
    document.getElementById('st-status').value = current || 'active';
    openModal('status-modal');
}
</script>";
include 'includes/admin_footer.php';
?>
