<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('courses');

/* Course catalogue management. Fixes vs old version:
   - academic years come from the academic_years table (was hardcoded)
   - delete is blocked while students are enrolled
   - status toggle + edit covered, CSRF protected, audited */

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_course') {
                $name = trim($_POST['course_name'] ?? '');
                $code = trim($_POST['course_code'] ?? '');
                $year = trim($_POST['academic_year'] ?? '');
                $fee  = (float)($_POST['total_fee'] ?? 0);
                $type = in_array($_POST['course_type'] ?? '', ['UG','PG','NCET','NET','KESA'], true) ? $_POST['course_type'] : 'UG';
                if ($name === '') {
                    $error_message = 'Course name is required.';
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pepp_courses WHERE course_name = ? AND academic_year = ?");
                    $stmt->execute([$name, $year]);
                    if ($stmt->fetchColumn() > 0) {
                        $error_message = 'A course with this name already exists for that academic year.';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO pepp_courses (course_name, course_code, academic_year, total_fee, course_type, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                        $stmt->execute([$name, $code, $year, $fee, $type]);
                        $success_message = "Course \"{$name}\" added.";
                    }
                }
            } elseif ($action === 'update_course') {
                $id   = (int)($_POST['course_id'] ?? 0);
                $name = trim($_POST['course_name'] ?? '');
                $code = trim($_POST['course_code'] ?? '');
                $year = trim($_POST['academic_year'] ?? '');
                $fee  = (float)($_POST['total_fee'] ?? 0);
                $type = in_array($_POST['course_type'] ?? '', ['UG','PG','NCET','NET','KESA'], true) ? $_POST['course_type'] : 'UG';
                if ($id && $name !== '') {
                    // Keep enrolled students linked: pepp_course stores the name, so update it too
                    $stmt = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ?");
                    $stmt->execute([$id]);
                    $old_name = $stmt->fetchColumn();

                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE pepp_courses SET course_name = ?, course_code = ?, academic_year = ?, total_fee = ?, course_type = ? WHERE id = ?");
                    $stmt->execute([$name, $code, $year, $fee, $type, $id]);
                    if ($old_name && $old_name !== $name) {
                        $pdo->prepare("UPDATE users SET pepp_course = ? WHERE pepp_course = ?")->execute([$name, $old_name]);
                    }
                    $pdo->commit();
                    $success_message = 'Course updated.';
                }
            } elseif ($action === 'toggle_status') {
                $id = (int)($_POST['course_id'] ?? 0);
                $stmt = $pdo->prepare("UPDATE pepp_courses SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
                $stmt->execute([$id]);
                $success_message = 'Course status updated.';
            } elseif ($action === 'delete_course') {
                if (!can_delete()) {
                    $error_message = 'Only the Super Admin can delete courses. You can set the course inactive instead.';
                } else {
                $id = (int)($_POST['course_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE pepp_course = (SELECT course_name FROM pepp_courses WHERE id = ?)");
                $stmt->execute([$id]);
                $enrolled = (int)$stmt->fetchColumn();
                if ($enrolled > 0) {
                    $error_message = "Cannot delete: {$enrolled} student(s) are enrolled in this course. Set it inactive instead.";
                } else {
                    $pdo->prepare("DELETE FROM pepp_courses WHERE id = ?")->execute([$id]);
                    $success_message = 'Course deleted.';
                }
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Course mgmt: ' . $e->getMessage());
            $error_message = 'Database error while saving changes.';
        }
    }
}

// ── Data ────────────────────────────────────────────────────────
$courses = [];
try {
    $courses = $pdo->query("
        SELECT pc.*,
               (SELECT COUNT(*) FROM users u WHERE u.pepp_course = pc.course_name AND u.status = 'approved') AS student_count
        FROM pepp_courses pc
        ORDER BY pc.academic_year DESC, pc.course_name ASC
    ")->fetchAll();
} catch (Exception $e) {
    error_log('Course list: ' . $e->getMessage());
    $error_message = $error_message ?: 'Could not load courses.';
}
try {
    $years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $years = []; }
if (empty($years)) $years = [date('Y') . '-' . substr(date('Y') + 1, 2)];

$active_total = count(array_filter($courses, function ($c) { return $c['status'] === 'active'; }));

$active_page = 'courses';
$page_title  = 'Course Management';
$page_sub    = 'Catalogue used by register.php and the approval flow';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Total Courses</span><span class="stat-icon blue"><i class="fas fa-book-open"></i></span></div>
        <div class="stat-value"><?php echo count($courses); ?></div>
        <div class="stat-hint"><?php echo $active_total; ?> active</div>
    </div>
    <div class="stat-card">
        <div class="stat-top"><span class="stat-label">Academic Years</span><span class="stat-icon violet"><i class="fas fa-calendar-days"></i></span></div>
        <div class="stat-value"><?php echo count($years); ?></div>
        <div class="stat-hint">Manage in <a href="settings.php">Settings</a></div>
    </div>
</div>

<!-- ── ADD COURSE ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-plus"></i></span><h2>Add Course</h2></div>
    <div class="panel-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_course">
            <div class="form-grid">
                <div class="field"><label>Course Name <span class="req">*</span></label><input type="text" name="course_name" required placeholder="e.g. MA/MSc Psychology (Premium)"></div>
                <div class="field"><label>Course Code</label><input type="text" name="course_code" placeholder="e.g. PSY-PRM"></div>
                <div class="field"><label>Type</label>
                    <select name="course_type"><?php foreach (['UG','PG','NCET','NET','KESA'] as $t) echo "<option>$t</option>"; ?></select></div>
                <div class="field"><label>Academic Year</label>
                    <select name="academic_year"><option value="All years">All years</option><?php foreach ($years as $y) echo '<option>' . e($y) . '</option>'; ?></select></div>
                <div class="field"><label>Total Fee (₹)</label><input type="number" name="total_fee" min="0" step="0.01" value="0"></div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Course</button>
            </div>
        </form>
    </div>
</div>

<!-- ── COURSE LIST ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-list"></i></span><h2>Courses (<?php echo count($courses); ?>)</h2></div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($courses)): ?>
            <div class="empty-state"><i class="fas fa-book-open"></i><p>No courses yet - add the first one above.</p></div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Course</th><th>Type</th><th>Year</th><th>Fee</th><th>Students</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($courses as $c): ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($c['course_name']); ?></div>
                        <?php if ($c['course_code']): ?><div class="cell-sub"><?php echo e($c['course_code']); ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge violet"><?php echo e($c['course_type']); ?></span></td>
                    <td class="cell-sub"><?php echo e($c['academic_year']); ?></td>
                    <td class="cell-main">₹<?php echo number_format((float)$c['total_fee'], 0); ?></td>
                    <td>
                        <?php if ((int)$c['student_count'] > 0): ?>
                            <span class="badge blue"><?php echo (int)$c['student_count']; ?> enrolled</span>
                        <?php else: ?><span class="cell-sub">0</span><?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo $c['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button class="btn btn-sm btn-outline" onclick='openEdit(<?php echo json_encode([
                            "id" => (int)$c["id"], "name" => $c["course_name"], "code" => (string)$c["course_code"],
                            "year" => $c["academic_year"], "fee" => (float)$c["total_fee"], "type" => $c["course_type"],
                        ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="course_id" value="<?php echo (int)$c['id']; ?>">
                            <button type="submit" class="btn btn-sm <?php echo $c['status'] === 'active' ? 'btn-soft-amber' : 'btn-soft-green'; ?>" title="<?php echo $c['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                <i class="fas fa-power-off"></i>
                            </button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this course? Only possible when no students are enrolled.');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_course">
                            <input type="hidden" name="course_id" value="<?php echo (int)$c['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-soft-red" <?php echo !can_delete() ? 'disabled title="Super Admin only"' : ((int)$c['student_count'] > 0 ? 'disabled title="Students enrolled - set inactive instead"' : ''); ?>><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── EDIT MODAL ── -->
<div class="modal-backdrop" id="edit-modal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-head">
            <h3><i class="fas fa-pen" style="color:var(--accent);"></i> Edit Course</h3>
            <button class="modal-close" onclick="closeModal('edit-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_course">
            <input type="hidden" name="course_id" id="ed-id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field full"><label>Course Name</label><input type="text" name="course_name" id="ed-name" required></div>
                    <div class="field"><label>Code</label><input type="text" name="course_code" id="ed-code"></div>
                    <div class="field"><label>Type</label>
                        <select name="course_type" id="ed-type"><?php foreach (['UG','PG','NCET','NET','KESA'] as $t) echo "<option>$t</option>"; ?></select></div>
                    <div class="field"><label>Academic Year</label>
                        <select name="academic_year" id="ed-year"><option value="All years">All years</option><?php foreach ($years as $y) echo '<option>' . e($y) . '</option>'; ?></select></div>
                    <div class="field"><label>Total Fee (₹)</label><input type="number" name="total_fee" id="ed-fee" min="0" step="0.01"></div>
                </div>
                <div class="alert alert-warn" style="margin-top:12px; margin-bottom:0;">
                    <i class="fas fa-circle-info"></i>
                    <span>Renaming a course automatically updates enrolled students so they stay linked.</span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('edit-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<?php
$extra_scripts = "<script>
function openEdit(c) {
    document.getElementById('ed-id').value = c.id;
    document.getElementById('ed-name').value = c.name;
    document.getElementById('ed-code').value = c.code;
    document.getElementById('ed-type').value = c.type;
    document.getElementById('ed-year').value = c.year;
    document.getElementById('ed-fee').value = c.fee;
    openModal('edit-modal');
}
</script>";
include 'includes/admin_footer.php';
?>
