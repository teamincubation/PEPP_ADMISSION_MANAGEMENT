<?php
/**
 * PEPP Learning ERP — Student Mentoring Page
 * - View assigned students (based on mentor course assignments)
 * - Log calls, add remarks, set reminders
 * - Track student progress, streaks, and study completion
 */
require_once 'includes/auth.php';
require_permission('student-mentoring');

$active_page = 'student-mentoring';
$page_title  = 'Student Mentoring';
$page_sub    = 'Track, mentor and support your assigned students';

$success_message = '';
$error_message = '';
$admin_id = $admin_row['id'] ?? 0;

// Check if mentoring tables exist
function mentor_tables_exist($pdo) {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'mentor_course_assignments'")->fetchColumn(); }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}

// ── POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    // Log a call
    if ($action === 'log_call' && mentor_tables_exist($pdo)) {
        $student_id = trim($_POST['student_user_id'] ?? '');
        $notes = trim($_POST['call_notes'] ?? '');
        $call_time = trim($_POST['call_timestamp'] ?? date('Y-m-d H:i:s'));
        if ($student_id) {
            try {
                $pdo->prepare("INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp, notes) VALUES (?,?,?,?,?)")
                    ->execute([$student_id, $admin_id, $admin_username, $call_time, $notes ?: null]);
                log_admin_activity($pdo, $admin_username, 'mentor_call', "Logged call for student {$student_id}");
                $success_message = 'Call logged successfully.';
            } catch (Exception $e) { $error_message = 'Error logging call: ' . $e->getMessage(); }
        } else { $error_message = 'Student ID is required.'; }
    }

    // Add remark
    elseif ($action === 'add_remark' && mentor_tables_exist($pdo)) {
        $student_id = trim($_POST['student_user_id'] ?? '');
        $remark = trim($_POST['remark_text'] ?? '');
        if ($student_id && $remark) {
            try {
                $pdo->prepare("INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark) VALUES (?,?,?,?)")
                    ->execute([$student_id, $admin_id, $admin_username, $remark]);
                log_admin_activity($pdo, $admin_username, 'mentor_remark', "Added remark for student {$student_id}");
                $success_message = 'Remark added.';
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        } else { $error_message = 'Student ID and remark text are required.'; }
    }

    // Assign mentor to course (Super Admin only)
    elseif ($action === 'assign_mentor' && is_super_admin() && mentor_tables_exist($pdo)) {
        $mentor_admin_id = (int)($_POST['mentor_admin_id'] ?? 0);
        $course = trim($_POST['course_name'] ?? '');
        if ($mentor_admin_id && $course) {
            try {
                $pdo->prepare("INSERT IGNORE INTO mentor_course_assignments (admin_id, course_name, assigned_by) VALUES (?,?,?)")
                    ->execute([$mentor_admin_id, $course, $admin_username]);
                log_admin_activity($pdo, $admin_username, 'mentor_assigned', "Assigned admin #{$mentor_admin_id} to course: {$course}");
                $success_message = 'Mentor assigned to course.';
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }

    // Remove assignment (Super Admin only)
    elseif ($action === 'remove_assignment' && is_super_admin() && mentor_tables_exist($pdo)) {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        if ($assignment_id) {
            try {
                $pdo->prepare("DELETE FROM mentor_course_assignments WHERE id = ?")->execute([$assignment_id]);
                log_admin_activity($pdo, $admin_username, 'mentor_unassigned', "Removed mentor assignment #{$assignment_id}");
                $success_message = 'Assignment removed.';
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }
}

// ── Load Data ──
$my_courses = [];
$assignments = [];
$students = [];
$call_logs = [];
$remarks_list = [];
$all_admins = [];
$all_courses = [];

if (mentor_tables_exist($pdo)) {
    // Mentor's assigned courses
    $my_courses = get_mentor_courses($pdo, $admin_id);

    // If super admin, show all assignments
    if (is_super_admin()) {
        try {
            $assignments = $pdo->query("SELECT mca.*, a.username, a.full_name FROM mentor_course_assignments mca LEFT JOIN admins a ON mca.admin_id = a.id ORDER BY mca.course_name, a.username")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    // Load students from assigned courses (or all for super admin)
    try {
        if (is_super_admin()) {
            $students = $pdo->query("SELECT u.user_id, u.full_name, u.email, u.whatsapp, u.course, u.status, u.pepp_academic_year FROM users u WHERE u.status IN ('approved','active') ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($my_courses)) {
            $placeholders = implode(',', array_fill(0, count($my_courses), '?'));
            $stmt = $pdo->prepare("SELECT u.user_id, u.full_name, u.email, u.whatsapp, u.course, u.status, u.pepp_academic_year FROM users u WHERE u.course IN ({$placeholders}) AND u.status IN ('approved','active') ORDER BY u.full_name");
            $stmt->execute($my_courses);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}

    // Load recent call logs
    try {
        $cl_query = is_super_admin()
            ? "SELECT * FROM mentor_call_logs ORDER BY call_timestamp DESC LIMIT 100"
            : "SELECT * FROM mentor_call_logs WHERE admin_id = ? ORDER BY call_timestamp DESC LIMIT 50";
        $stmt = $pdo->prepare($cl_query);
        is_super_admin() ? $stmt->execute() : $stmt->execute([$admin_id]);
        $call_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Load recent remarks
    try {
        $rm_query = is_super_admin()
            ? "SELECT * FROM mentor_remarks ORDER BY created_at DESC LIMIT 100"
            : "SELECT * FROM mentor_remarks WHERE admin_id = ? ORDER BY created_at DESC LIMIT 50";
        $stmt = $pdo->prepare($rm_query);
        is_super_admin() ? $stmt->execute() : $stmt->execute([$admin_id]);
        $remarks_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // For super admin: all admins and courses for assignment
    if (is_super_admin()) {
        try {
            $all_admins = $pdo->query("SELECT id, username, full_name FROM admins WHERE status='active' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
            $all_courses = $pdo->query("SELECT DISTINCT course_name FROM course_offerings WHERE status='active' ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            // If course_offerings doesn't exist, try unique courses from users
            try { $all_courses = $pdo->query("SELECT DISTINCT course FROM users WHERE course IS NOT NULL AND course != '' ORDER BY course")->fetchAll(PDO::FETCH_COLUMN); }
            catch (Exception $e2) {}
        }
    }
}

$tab = $_GET['tab'] ?? 'students';

include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?>
<div class="alert alert-ok"><i class="fas fa-check-circle"></i><span><?php echo e($success_message); ?></span></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-warn"><i class="fas fa-exclamation-circle"></i><span><?php echo e($error_message); ?></span></div>
<?php endif; ?>

<?php if (!mentor_tables_exist($pdo)): ?>
<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>Mentoring tables not installed. Run <strong>database-update-22.sql</strong>.</span></div>
<?php else: ?>

<!-- Tabs -->
<div class="panel" style="margin-bottom:1.2rem;">
    <div class="panel-head" style="gap:8px;flex-wrap:wrap;">
        <a href="?tab=students" class="btn btn-sm <?php echo $tab==='students' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-users"></i> Students (<?php echo count($students); ?>)</a>
        <a href="?tab=calls" class="btn btn-sm <?php echo $tab==='calls' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-phone"></i> Call Logs (<?php echo count($call_logs); ?>)</a>
        <a href="?tab=remarks" class="btn btn-sm <?php echo $tab==='remarks' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-comment-dots"></i> Remarks (<?php echo count($remarks_list); ?>)</a>
        <?php if (is_super_admin()): ?>
        <a href="?tab=assignments" class="btn btn-sm <?php echo $tab==='assignments' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-link"></i> Assignments (<?php echo count($assignments); ?>)</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($tab === 'students'): ?>
<!-- Students -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-users"></i></span>
        <h2>My Students<?php if (!empty($my_courses) && !is_super_admin()): ?> <span class="badge gray" style="font-size:.7rem;"><?php echo implode(', ', $my_courses); ?></span><?php endif; ?></h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($students)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">
                <?php echo empty($my_courses) && !is_super_admin() ? 'No course assignments yet. Ask the Super Admin to assign you.' : 'No students found in your assigned courses.'; ?>
            </div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Student</th><th>Course</th><th>Year</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
            <tr>
                <td>
                    <div class="cell-main"><?php echo e($s['full_name']); ?></div>
                    <div class="cell-sub"><?php echo e($s['email']); ?> · <?php echo e($s['whatsapp'] ?? ''); ?></div>
                </td>
                <td><span class="badge gray" style="font-size:.7rem;"><?php echo e($s['course']); ?></span></td>
                <td class="cell-sub"><?php echo e($s['pepp_academic_year'] ?? ''); ?></td>
                <td><span class="badge <?php echo $s['status']==='approved' ? 'green' : 'blue'; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                <td style="text-align:right;white-space:nowrap;">
                    <button class="btn btn-sm btn-outline" onclick="openCall('<?php echo e($s['user_id']); ?>', '<?php echo e($s['full_name']); ?>')" title="Log Call"><i class="fas fa-phone"></i></button>
                    <button class="btn btn-sm btn-outline" onclick="openRemark('<?php echo e($s['user_id']); ?>', '<?php echo e($s['full_name']); ?>')" title="Add Remark"><i class="fas fa-comment-dots"></i></button>
                    <a href="student-study-reports.php?user_id=<?php echo urlencode($s['user_id']); ?>" class="btn btn-sm btn-outline" title="Study Report"><i class="fas fa-chart-line"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'calls'): ?>
<!-- Call Logs -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-phone"></i></span>
        <h2>Call Logs</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($call_logs)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">No call logs yet.</div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Student ID</th><th>Called By</th><th>Time</th><th>Notes</th></tr></thead>
            <tbody>
            <?php foreach ($call_logs as $cl): ?>
            <tr>
                <td class="cell-main"><?php echo e($cl['student_user_id']); ?></td>
                <td class="cell-sub"><?php echo e($cl['admin_username']); ?></td>
                <td class="cell-sub"><?php echo date('d M Y, h:i A', strtotime($cl['call_timestamp'])); ?></td>
                <td style="max-width:300px;"><?php echo e($cl['notes'] ?? '—'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'remarks'): ?>
<!-- Remarks -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-comment-dots"></i></span>
        <h2>Student Remarks</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($remarks_list)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">No remarks yet.</div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Student ID</th><th>By</th><th>Remark</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($remarks_list as $rm): ?>
            <tr>
                <td class="cell-main"><?php echo e($rm['student_user_id']); ?></td>
                <td class="cell-sub"><?php echo e($rm['admin_username']); ?></td>
                <td style="max-width:350px;"><?php echo e($rm['remark']); ?></td>
                <td class="cell-sub"><?php echo date('d M Y, h:i A', strtotime($rm['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'assignments' && is_super_admin()): ?>
<!-- Mentor Assignments (Super Admin) -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--red-soft);color:var(--red-ink);"><i class="fas fa-link"></i></span>
        <h2>Mentor ↔ Course Assignments</h2>
        <div class="head-right">
            <button class="btn btn-sm btn-primary" onclick="document.getElementById('assignModal').style.display='flex'"><i class="fas fa-plus"></i> Assign Mentor</button>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($assignments)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">No mentor assignments. Use the button above to assign admins to courses.</div>
        <?php else: ?>
        <table class="data-table">
            <thead><tr><th>Admin</th><th>Course</th><th>Assigned By</th><th>Date</th><th style="text-align:right;">Action</th></tr></thead>
            <tbody>
            <?php foreach ($assignments as $as): ?>
            <tr>
                <td>
                    <div class="cell-main"><?php echo e($as['username']); ?></div>
                    <div class="cell-sub"><?php echo e($as['full_name'] ?? ''); ?></div>
                </td>
                <td><span class="badge blue"><?php echo e($as['course_name']); ?></span></td>
                <td class="cell-sub"><?php echo e($as['assigned_by']); ?></td>
                <td class="cell-sub"><?php echo date('d M Y', strtotime($as['created_at'])); ?></td>
                <td style="text-align:right;">
                    <form method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="remove_assignment">
                        <input type="hidden" name="assignment_id" value="<?php echo $as['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline" style="color:#ef4444;" onclick="return confirm('Remove this assignment?')"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Assign Modal -->
<div id="assignModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;padding:20px;">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:440px;width:100%;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-link"></i> Assign Mentor to Course</h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="assign_mentor">
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Admin *</label>
            <select name="mentor_admin_id" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                <option value="">— Select Admin —</option>
                <?php foreach ($all_admins as $adm): ?>
                <option value="<?php echo $adm['id']; ?>"><?php echo e($adm['username']); ?> (<?php echo e($adm['full_name'] ?? ''); ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Course *</label>
            <select name="course_name" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
                <option value="">— Select Course —</option>
                <?php foreach ($all_courses as $c): ?>
                <option value="<?php echo e($c); ?>"><?php echo e($c); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('assignModal').style.display='none'">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Assign</button>
        </div>
    </form>
</div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Log Call Modal -->
<div id="callModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;padding:20px;">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:440px;width:100%;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-phone" style="color:#22c55e;"></i> Log Call</h3>
    <p id="callStudentName" style="margin-bottom:1rem;color:var(--text-muted);font-size:.85rem;"></p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="log_call">
        <input type="hidden" name="student_user_id" id="callStudentId">
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Call Time</label>
            <input type="datetime-local" name="call_timestamp" value="<?php echo date('Y-m-d\TH:i'); ?>" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Notes</label>
            <textarea name="call_notes" rows="3" placeholder="Call summary, follow-up needed, etc." style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);resize:vertical;"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('callModal').style.display='none'">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#22c55e;border-color:#22c55e;"><i class="fas fa-phone"></i> Log Call</button>
        </div>
    </form>
</div>
</div>

<!-- Add Remark Modal -->
<div id="remarkModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;padding:20px;">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:440px;width:100%;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-comment-dots" style="color:#f59e0b;"></i> Add Remark</h3>
    <p id="remarkStudentName" style="margin-bottom:1rem;color:var(--text-muted);font-size:.85rem;"></p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="add_remark">
        <input type="hidden" name="student_user_id" id="remarkStudentId">
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Remark *</label>
            <textarea name="remark_text" rows="4" required placeholder="Progress notes, concerns, praise, etc." style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);resize:vertical;"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('remarkModal').style.display='none'">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#f59e0b;border-color:#f59e0b;"><i class="fas fa-comment-dots"></i> Save Remark</button>
        </div>
    </form>
</div>
</div>

<script>
function openCall(id, name) {
    document.getElementById('callStudentId').value = id;
    document.getElementById('callStudentName').textContent = 'Student: ' + name + ' (' + id + ')';
    document.getElementById('callModal').style.display = 'flex';
}
function openRemark(id, name) {
    document.getElementById('remarkStudentId').value = id;
    document.getElementById('remarkStudentName').textContent = 'Student: ' + name + ' (' + id + ')';
    document.getElementById('remarkModal').style.display = 'flex';
}
// Close modals on backdrop
['callModal','remarkModal','assignModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
});
</script>

<?php include 'includes/admin_footer.php'; ?>
