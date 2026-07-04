<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('students');

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
                $allowed = ['active', 'inactive', 'suspended', 'completed'];
                if (!in_array($new_status, $allowed, true)) {
                    $error_message = 'Invalid status.';
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET student_status = ?, course_status = ? WHERE user_id = ?");
                    $course_status = $new_status === 'inactive' ? 'suspended' : ($new_status === 'completed' ? 'completed' : ($new_status === 'suspended' ? 'suspended' : 'active'));
                    $stmt->execute([$new_status, $course_status, $user_id]);
                    status_log($pdo, $user_id, $target['student_status'], $new_status, trim($_POST['reason'] ?? 'Status updated by admin'), $admin_username);
                    track_record($pdo, $user_id, 'status_changed', "Student status: {$target['student_status']} → {$new_status}", $admin_username);
                    $success_message = "Status updated for {$target['name']}.";
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
if ($filter_status !== '') { $where[] = "u.student_status = ?";     $params[] = $filter_status; }

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
               u.paid_amount, u.payment_plan, u.course_duration_date, u.joined_date, u.created_at,
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
                    <?php foreach (['active','inactive','suspended','completed'] as $st): ?>
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
                        <div class="cell-main" style="display:inline-flex; align-items:center; gap:6px;">
                            <?php echo e($s['name']); ?>
                            <?php if ((int)$s['remarks_count'] > 0): ?>
                                <span class="badge amber" title="Has Remarks/Notes (<?php echo (int)$s['remarks_count']; ?>)" style="font-size:0.62rem; padding:1px 4px; display:inline-flex; align-items:center; gap:2px;"><i class="fas fa-clipboard"></i> Remark</span>
                            <?php endif; ?>
                        </div>
                        <div class="cell-sub"><?php echo e($s['user_id']); ?> &middot; <?php echo e($s['email']); ?></div>
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

<?php
$extra_scripts = "<script>
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
