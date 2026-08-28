<?php
/**
 * PEPP Learning ERP — Student Mentoring Page
 * - View assigned students (based on mentor course assignments)
 * - Log calls, add remarks, set reminders
 * - Track student progress, streaks, and study completion
 */
require_once 'includes/auth.php';
require_permission('student-mentoring');

// AJAX call to fetch remarks for a specific student
if (isset($_GET['get_remarks_student_user_id'])) {
    header('Content-Type: application/json');
    $student_user_id = trim($_GET['get_remarks_student_user_id']);
    try {
        $stmt = $pdo->prepare("SELECT remark, admin_username, created_at FROM mentor_remarks WHERE student_user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$student_user_id]);
        $remarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'remarks' => $remarks]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$active_page = 'student-mentoring';
$page_title  = 'Student Mentoring';
$page_sub    = 'Track, mentor and support your assigned students';

$success_message = '';
$error_message = '';
$admin_id = $admin_row['id'] ?? 0;

// Check if mentoring tables exist
// Check if mentoring tables exist
function mentor_tables_exist($pdo) {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'mentor_course_assignments'")->fetchColumn(); }
        catch (Exception $e) { $ok = false; }
    }
    return $ok;
}

/** Get mentoring metrics (progress, attendance, streak, call and remark details) for a student. */
function get_student_mentoring_details($pdo, $student) {
    $email = $student['email'];
    $user_id = $student['user_id'];
    $course = $student['course'];
    $year = $student['pepp_academic_year'];

    // Fetch published plans assigned to the student
    $stmt = $pdo->prepare("
        SELECT sp.id, sp.title, sp.plan_type
        FROM study_plans sp
        JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
        WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0 AND (
            sa.assignment_type = 'all' OR
            (sa.assignment_type = 'course' AND sa.assigned_value = ?) OR
            (sa.assignment_type = 'batch' AND sa.assigned_value = ?) OR
            (sa.assignment_type = 'student' AND sa.assigned_value = ?) OR
            (sa.assignment_type = 'form' AND EXISTS (
                SELECT 1 FROM campaign_form_submissions s
                WHERE s.respondent_identifier = ? AND CAST(s.form_id AS CHAR) = sa.assigned_value AND s.is_deleted = 0
            ))
        )
    ");
    $stmt->execute([$course, $year, $user_id, $email]);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_tasks = 0;
    $completed_tasks = 0;
    $max_streak = 0;
    $total_streak_target = 0;
    $overdue_tasks = 0;

    foreach ($plans as $p) {
        // Fetch activities (non-deleted only)
        $stmt_act = $pdo->prepare("SELECT id, day_number, activity_date FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0");
        $stmt_act->execute([$p['id']]);
        $activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

        // Fetch completed analytics (non-deleted tasks only, using UID-matching with ID fallback)
        $stmt_comp = $pdo->prepare("
            SELECT DISTINCT act.id
            FROM study_plan_analytics an
            JOIN study_plan_activities act ON (
                (an.activity_uid = act.activity_uid AND act.activity_uid IS NOT NULL AND act.activity_uid != '')
                OR (an.activity_id = act.id AND (an.activity_uid IS NULL OR an.activity_uid = '' OR act.activity_uid IS NULL OR act.activity_uid = ''))
            )
            WHERE an.student_email = ? AND an.study_plan_id = ?
              AND an.action_type = 'complete_activity' AND an.completion_status = 'completed'
              AND act.is_deleted = 0
        ");
        $stmt_comp->execute([$email, $p['id']]);
        $completed_ids = $stmt_comp->fetchAll(PDO::FETCH_COLUMN);
        $completed_map = array_fill_keys($completed_ids, true);

        $tot = count($activities);
        $comp = count($completed_ids);
        $total_tasks += $tot;
        $completed_tasks += $comp;

        // Group activities by day/date for streak
        $day_tasks = [];
        foreach ($activities as $act) {
            $day_key = ($p['plan_type'] === 'day_wise') ? (int)$act['day_number'] : $act['activity_date'];
            if (!isset($day_tasks[$day_key])) {
                $day_tasks[$day_key] = ['total' => 0, 'completed' => 0];
            }
            $day_tasks[$day_key]['total']++;
            if (isset($completed_map[$act['id']])) {
                $day_tasks[$day_key]['completed']++;
            }
        }

        // Calculate overdue tasks for this plan
        $today_str = date('Y-m-d');
        foreach ($activities as $act) {
            if ($p['plan_type'] === 'date_wise') {
                if ($act['activity_date'] && $act['activity_date'] < $today_str) {
                    if (!isset($completed_map[$act['id']])) {
                        $overdue_tasks++;
                    }
                }
            }
        }

        $plan_streak = 0;
        if (!empty($day_tasks)) {
            foreach ($day_tasks as $dk => $stats) {
                if ($stats['total'] > 0 && $stats['completed'] === $stats['total']) {
                    $plan_streak++;
                }
            }
        }
        if ($plan_streak > $max_streak) {
            $max_streak = $plan_streak;
        }

        $total_streak_target += count($day_tasks);
    }

    $progress = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;
    $attendance = min(100, round($progress * 1.1));

    // Get last call date & time
    $last_call_time = null;
    $last_called_status = 'Never Called';
    $call_stmt = $pdo->prepare("SELECT call_timestamp FROM mentor_call_logs WHERE student_user_id = ? ORDER BY call_timestamp DESC LIMIT 1");
    $call_stmt->execute([$user_id]);
    $last_call = $call_stmt->fetch(PDO::FETCH_ASSOC);
    if ($last_call) {
        $last_call_time = $last_call['call_timestamp'];
        $diff = time() - strtotime($last_call_time);
        $days = round($diff / (60 * 60 * 24));
        if ($days === 0) {
            $last_called_status = 'called today';
        } elseif ($days === 1) {
            $last_called_status = 'called yesterday';
        } else {
            $last_called_status = "called {$days} days ago";
        }
    }

    // Get count of remarks
    $remark_stmt = $pdo->prepare("SELECT COUNT(*) FROM mentor_remarks WHERE student_user_id = ?");
    $remark_stmt->execute([$user_id]);
    $remarks_count = (int)$remark_stmt->fetchColumn();

    return [
        'progress' => $progress,
        'attendance' => $attendance,
        'streak' => $max_streak,
        'streak_target' => $total_streak_target,
        'last_call_time' => $last_call_time,
        'last_called_status' => $last_called_status,
        'remarks_count' => $remarks_count,
        'total_tasks' => $total_tasks,
        'completed_tasks' => $completed_tasks,
        'pending_tasks' => $total_tasks - $completed_tasks,
        'overdue_tasks' => $overdue_tasks
    ];
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
                $check = $pdo->prepare("SELECT COUNT(*) FROM mentor_course_assignments WHERE admin_id = ? AND course_name = ?");
                $check->execute([$mentor_admin_id, $course]);
                if ($check->fetchColumn() > 0) {
                    $error_message = 'This course is already assigned to this admin.';
                } else {
                    $pdo->prepare("INSERT INTO mentor_course_assignments (admin_id, course_name, assigned_by) VALUES (?,?,?)")
                        ->execute([$mentor_admin_id, $course, $admin_username]);
                    log_admin_activity($pdo, $admin_username, 'mentor_assigned', "Assigned admin #{$mentor_admin_id} to course: {$course}");
                    $success_message = 'Mentor assigned to course.';
                }
            } catch (Exception $e) { $error_message = 'Error assigning mentor: ' . $e->getMessage(); }
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

    // Edit Call Log (Super Admin only)
    elseif ($action === 'edit_call_log' && is_super_admin() && mentor_tables_exist($pdo)) {
        $log_id = (int)($_POST['log_id'] ?? 0);
        $notes = trim($_POST['call_notes'] ?? '');
        $call_time = trim($_POST['call_timestamp'] ?? '');
        if ($log_id && $call_time) {
            try {
                $pdo->prepare("UPDATE mentor_call_logs SET call_timestamp = ?, notes = ? WHERE id = ?")
                    ->execute([$call_time, $notes ?: null, $log_id]);
                log_admin_activity($pdo, $admin_username, 'mentor_call_edit', "Edited call log #{$log_id}");
                $success_message = 'Call log updated.';
            } catch (Exception $e) { $error_message = 'Error updating call log: ' . $e->getMessage(); }
        }
    }

    // Delete Call Log (Super Admin only)
    elseif ($action === 'delete_call_log' && is_super_admin() && mentor_tables_exist($pdo)) {
        $log_id = (int)($_POST['log_id'] ?? 0);
        if ($log_id) {
            try {
                $pdo->prepare("DELETE FROM mentor_call_logs WHERE id = ?")->execute([$log_id]);
                log_admin_activity($pdo, $admin_username, 'mentor_call_delete', "Deleted call log #{$log_id}");
                $success_message = 'Call log deleted.';
            } catch (Exception $e) { $error_message = 'Error deleting call log: ' . $e->getMessage(); }
        }
    }

    // Edit Remark (Super Admin only)
    elseif ($action === 'edit_remark' && is_super_admin() && mentor_tables_exist($pdo)) {
        $remark_id = (int)($_POST['remark_id'] ?? 0);
        $remark_text = trim($_POST['remark_text'] ?? '');
        if ($remark_id && $remark_text) {
            try {
                $pdo->prepare("UPDATE mentor_remarks SET remark = ? WHERE id = ?")
                    ->execute([$remark_text, $remark_id]);
                log_admin_activity($pdo, $admin_username, 'mentor_remark_edit', "Edited remark #{$remark_id}");
                $success_message = 'Remark updated.';
            } catch (Exception $e) { $error_message = 'Error updating remark: ' . $e->getMessage(); }
        }
    }

    // Delete Remark (Super Admin only)
    elseif ($action === 'delete_remark' && is_super_admin() && mentor_tables_exist($pdo)) {
        $remark_id = (int)($_POST['remark_id'] ?? 0);
        if ($remark_id) {
            try {
                $pdo->prepare("DELETE FROM mentor_remarks WHERE id = ?")->execute([$remark_id]);
                log_admin_activity($pdo, $admin_username, 'mentor_remark_delete', "Deleted remark #{$remark_id}");
                $success_message = 'Remark deleted.';
            } catch (Exception $e) { $error_message = 'Error deleting remark: ' . $e->getMessage(); }
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
$dropdown_courses = [];
$selected_course_id = 0;
$selected_course_name = '';

if (mentor_tables_exist($pdo)) {
    // Mentor's assigned courses names
    $my_courses = get_mentor_courses($pdo, $admin_id);

    // Dropdown Courses mapping (id, course_name)
    if (is_super_admin()) {
        try {
            $dropdown_courses = $pdo->query("SELECT id, course_name FROM pepp_courses WHERE status='active' ORDER BY course_name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT pc.id, pc.course_name
                FROM mentor_course_assignments mca
                JOIN pepp_courses pc ON mca.course_name = pc.course_name
                WHERE mca.admin_id = ? AND pc.status = 'active'
                ORDER BY pc.course_name
            ");
            $stmt->execute([$admin_id]);
            $dropdown_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    // Resolve course_id parameter & perform validation
    $selected_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
    if ($selected_course_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$selected_course_id]);
            $selected_course_name = $stmt->fetchColumn();

            if ($selected_course_name) {
                $authorized = false;
                if (is_super_admin()) {
                    $authorized = true;
                } else {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM mentor_course_assignments WHERE admin_id = ? AND course_name = ?");
                    $chk->execute([$admin_id, $selected_course_name]);
                    if ($chk->fetchColumn() > 0) {
                        $authorized = true;
                    }
                }
                if (!$authorized) {
                    $selected_course_id = 0;
                    $selected_course_name = '';
                    $error_message = 'Access Denied: You are not authorized to view this course.';
                }
            } else {
                $selected_course_id = 0;
            }
        } catch (Exception $e) {
            $selected_course_id = 0;
        }
    }

    // Initialize default empty values
    $students = [];
    $call_logs = [];
    $remarks_list = [];
    $assignments = [];

    // If super admin, show all assignments
    if (is_super_admin()) {
        try {
            if ($selected_course_name !== '') {
                $stmt = $pdo->prepare("
                    SELECT mca.*, a.username, a.full_name
                    FROM mentor_course_assignments mca
                    LEFT JOIN admins a ON mca.admin_id = a.id
                    WHERE mca.course_name = ?
                    ORDER BY mca.course_name, a.username
                ");
                $stmt->execute([$selected_course_name]);
                $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $assignments = $pdo->query("SELECT mca.*, a.username, a.full_name FROM mentor_course_assignments mca LEFT JOIN admins a ON mca.admin_id = a.id ORDER BY mca.course_name, a.username")->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {}
    }

    // Load data only if course is selected
    if ($selected_course_name !== '') {
        try {
            $stmt = $pdo->prepare("
                SELECT u.user_id, u.name AS full_name, u.email, u.whatsapp_country_code, u.whatsapp_number, u.pepp_course AS course, u.status, u.pepp_academic_year
                FROM users u
                WHERE u.pepp_course = ? AND u.status IN ('approved','active')
                ORDER BY u.name
            ");
            $stmt->execute([$selected_course_name]);
            $raw_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $students_with_metrics = [];
            foreach ($raw_students as $s) {
                $m = get_student_mentoring_details($pdo, $s);
                $s['metrics'] = $m;
                $students_with_metrics[] = $s;
            }

            // Sort by completion percentage (progress) descending
            usort($students_with_metrics, function($a, $b) {
                return $b['metrics']['progress'] <=> $a['metrics']['progress'];
            });

            $students = $students_with_metrics;
        } catch (Exception $e) {}

        // Load call logs for selected course
        try {
            if (is_super_admin()) {
                $stmt = $pdo->prepare("
                    SELECT mcl.*, u.name AS student_name, u.whatsapp_country_code, u.whatsapp_number, u.email
                    FROM mentor_call_logs mcl
                    JOIN users u ON mcl.student_user_id = u.user_id
                    WHERE u.pepp_course = ?
                    ORDER BY mcl.call_timestamp DESC LIMIT 100
                ");
                $stmt->execute([$selected_course_name]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT mcl.*, u.name AS student_name, u.whatsapp_country_code, u.whatsapp_number, u.email
                    FROM mentor_call_logs mcl
                    JOIN users u ON mcl.student_user_id = u.user_id
                    WHERE u.pepp_course = ? AND mcl.admin_id = ?
                    ORDER BY mcl.call_timestamp DESC LIMIT 50
                ");
                $stmt->execute([$selected_course_name, $admin_id]);
            }
            $call_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // Load remarks for selected course
        try {
            if (is_super_admin()) {
                $stmt = $pdo->prepare("
                    SELECT mr.*, u.name AS student_name, u.email, u.whatsapp_country_code, u.whatsapp_number
                    FROM mentor_remarks mr
                    JOIN users u ON mr.student_user_id = u.user_id
                    WHERE u.pepp_course = ?
                    ORDER BY mr.created_at DESC LIMIT 100
                ");
                $stmt->execute([$selected_course_name]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT mr.*, u.name AS student_name, u.email, u.whatsapp_country_code, u.whatsapp_number
                    FROM mentor_remarks mr
                    JOIN users u ON mr.student_user_id = u.user_id
                    WHERE u.pepp_course = ? AND mr.admin_id = ?
                    ORDER BY mr.created_at DESC LIMIT 50
                ");
                $stmt->execute([$selected_course_name, $admin_id]);
            }
            $remarks_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    // For super admin: all admins and courses for assignment
    if (is_super_admin()) {
        try {
            $all_admins = $pdo->query("SELECT id, username, full_name FROM admins WHERE status='active' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
            $all_courses = $pdo->query("SELECT DISTINCT course_name FROM pepp_courses WHERE status='active' ORDER BY course_name")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            // Fallback to distinct course names from users
            try { $all_courses = $pdo->query("SELECT DISTINCT course FROM users WHERE course IS NOT NULL AND course != '' ORDER BY course")->fetchAll(PDO::FETCH_COLUMN); }
            catch (Exception $e2) { $all_courses = []; }
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

<!-- CSS styles for responsive mobile cards -->
<style>
.mentoring-table th {
    font-size: 0.8rem;
    text-transform: uppercase;
    color: var(--text-muted);
}
@media (max-width: 768px) {
    .mentoring-table, .mentoring-table thead, .mentoring-table tbody, .mentoring-table tr, .mentoring-table td {
        display: block;
        width: 100%;
    }
    .mentoring-table thead {
        display: none;
    }
    .mentoring-table tr {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 14px;
        margin-bottom: 14px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
    .mentoring-table td {
        text-align: left !important;
        padding: 6px 0;
        border: none;
    }
    .mentoring-table td:before {
        content: attr(data-label);
        display: block;
        font-size: 0.65rem;
        font-weight: 800;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 2px;
    }
    .mentoring-table td.actions-cell {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 10px;
        border-top: 1px solid var(--border);
        padding-top: 12px;
    }
}
</style>

<!-- Course Selector Dropdown -->
<div class="panel" style="margin-bottom:1.2rem; padding: 1.2rem;">
    <form method="GET" id="course-filter-form" style="max-width:400px; width: 100%;">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <label for="course_id" style="display:block; font-size:.8rem; font-weight:600; margin-bottom:6px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Course</label>
        <select name="course_id" id="course_id" onchange="this.form.submit()" style="width:100%; padding:10px 14px; border:1px solid var(--border); border-radius:8px; background:var(--card); color:var(--text); font-size:.9rem; font-weight:600; cursor:pointer;">
            <option value="">— Select a PEPP Course —</option>
            <?php foreach ($dropdown_courses as $dc): ?>
                <option value="<?= $dc['id'] ?>" <?= (int)$selected_course_id === (int)$dc['id'] ? 'selected' : '' ?>><?= e($dc['course_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div style="font-size:.7rem; color:var(--text-muted); margin-top:6px;">
            <?= is_super_admin() ? 'Showing all active PEPP courses' : 'Showing courses assigned to you' ?>
        </div>
    </form>
</div>

<!-- Tabs (Moved below Course Selector) -->
<div class="panel" style="margin-bottom:1.2rem;">
    <div class="panel-head" style="gap:8px;flex-wrap:wrap;">
        <a href="?tab=students<?= $selected_course_id ? '&course_id=' . $selected_course_id : '' ?>" class="btn btn-sm <?php echo $tab==='students' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-users"></i> Students (<?php echo count($students); ?>)</a>
        <a href="?tab=calls<?= $selected_course_id ? '&course_id=' . $selected_course_id : '' ?>" class="btn btn-sm <?php echo $tab==='calls' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-phone"></i> Call Logs (<?php echo count($call_logs); ?>)</a>
        <a href="?tab=remarks<?= $selected_course_id ? '&course_id=' . $selected_course_id : '' ?>" class="btn btn-sm <?php echo $tab==='remarks' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-comment-dots"></i> Remarks (<?php echo count($remarks_list); ?>)</a>
        <?php if (is_super_admin()): ?>
        <a href="?tab=assignments<?= $selected_course_id ? '&course_id=' . $selected_course_id : '' ?>" class="btn btn-sm <?php echo $tab==='assignments' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-link"></i> Assignments (<?php echo count($assignments); ?>)</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($tab === 'students'): ?>

<!-- State Rendering -->
<?php if ($selected_course_id === 0): ?>
    <!-- STATE A: No Course Selected -->
    <div class="panel" style="padding: 2.5rem; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 12px;">📚</div>
        <h3 style="margin-bottom: 6px; color: var(--text);">Select a course to view students</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Choose a PEPP course above to view and manage your assigned students.</p>
    </div>
<?php elseif (empty($students)): ?>
    <!-- STATE B: Course Selected but No Students -->
    <div class="panel" style="padding: 2.5rem; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 12px;">👥</div>
        <h3 style="margin-bottom: 6px; color: var(--text);">No students found</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Students enrolled in this course will appear here.</p>
    </div>
<?php else: ?>
    <!-- STATE C: Course Selected and Students Exist -->
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-users"></i></span>
            <h2>My Students (<?= e($selected_course_name) ?>)</h2>
        </div>

        <!-- Filters Toolbar for Student Search and Attributes -->
        <div class="panel-body" style="padding:15px; border-bottom:1px solid var(--border); background:#f8fafc;">
            <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                <!-- Search Box -->
                <div style="flex-grow:1; min-width:200px; position:relative;">
                    <i class="fas fa-search" style="position:absolute; left:12px; top:12px; color:var(--text-muted); font-size:0.85rem;"></i>
                    <input type="text" id="student-search-input" onkeyup="applyStudentFilters()" placeholder="Search name, email, mobile, ID..." style="width:100%; padding:8px 12px 8px 34px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text);">
                </div>
                <!-- Filters -->
                <select id="filter-performance" onchange="applyStudentFilters()" style="padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                    <option value="ALL">All Performance Statuses</option>
                    <option value="EXCELLENT">Excellent (85%+)</option>
                    <option value="GOOD">Good (70%-84%)</option>
                    <option value="AVERAGE">Average (50%-69%)</option>
                    <option value="NEEDS_IMPROVEMENT">Needs Improvement (<50%)</option>
                </select>
                <select id="filter-streak" onchange="applyStudentFilters()" style="padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                    <option value="ALL">All Streak Counts</option>
                    <option value="ACTIVE">Has Streak (>0 Days)</option>
                    <option value="HIGH">High Streak (5+ Days)</option>
                    <option value="NONE">No Streak (0 Days)</option>
                </select>
                <select id="filter-completed" onchange="applyStudentFilters()" style="padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                    <option value="ALL">All Completed Tasks</option>
                    <option value="HIGH">High (10+ completed)</option>
                    <option value="SOME">Some (1-9 completed)</option>
                    <option value="NONE">Zero completed</option>
                </select>
                <select id="filter-pending" onchange="applyStudentFilters()" style="padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                    <option value="ALL">All Pending Tasks</option>
                    <option value="YES">Has Pending Tasks (>0)</option>
                    <option value="NONE">No Pending Tasks (0)</option>
                </select>
                <select id="filter-overdue" onchange="applyStudentFilters()" style="padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                    <option value="ALL">All Overdue Tasks</option>
                    <option value="YES">Has Overdue Tasks (>0)</option>
                    <option value="NONE">No Overdue Tasks (0)</option>
                </select>
                <select id="filter-attendance" onchange="applyStudentFilters()" style="padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                    <option value="ALL">All Attendance Rates</option>
                    <option value="HIGH">High Attendance (75%+)</option>
                    <option value="LOW">Low Attendance (<75%)</option>
                </select>
                <button type="button" class="btn btn-sm btn-outline" onclick="resetStudentFilters()" style="height:36px; padding:0 12px; border-radius:10px;"><i class="fas fa-arrows-rotate"></i> Reset</button>
            </div>
        </div>

        <div class="panel-body flush table-wrap">
            <table class="data-table mentoring-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Course</th>
                        <th>Progress</th>
                        <th>Streak</th>
                        <th>Last Call</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ($students as $s):
                    $m = $s['metrics'];
                    $wa_phone = preg_replace('/\D/', '', ($s['whatsapp_country_code'] ?: '+91') . $s['whatsapp_number']);
                ?>
                <tr class="student-row"
                    data-name="<?= e(strtolower($s['full_name'])) ?>"
                    data-email="<?= e(is_credential_restricted('students') ? format_credential_text($s['email'], 'email', 'students') : strtolower($s['email'])) ?>"
                    data-mobile="<?= e(is_credential_restricted('students') ? format_credential_text($s['whatsapp_number'], 'phone', 'students') : $s['whatsapp_number']) ?>"
                    data-user-id="<?= e(strtolower($s['user_id'])) ?>"
                    data-progress="<?= (int)$m['progress'] ?>"
                    data-streak="<?= (int)$m['streak'] ?>"
                    data-completed="<?= (int)$m['completed_tasks'] ?>"
                    data-pending="<?= (int)$m['pending_tasks'] ?>"
                    data-overdue="<?= (int)$m['overdue_tasks'] ?>"
                    data-attendance="<?= (int)$m['attendance'] ?>">
                    <td data-label="Student">
                        <div class="cell-main"><?= e($s['full_name']) ?></div>
                        <div class="cell-sub"><?= htmlspecialchars(format_credential_text($s['email'], 'email', 'students'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(($s['whatsapp_country_code'] ?: '+91') . ' ' . format_credential_text($s['whatsapp_number'], 'phone', 'students'), ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td data-label="Course">
                        <div class="cell-main"><?= e($s['course']) ?></div>
                        <div class="cell-sub">Year: <?= e($s['pepp_academic_year'] ?? '') ?></div>
                    </td>
                    <td data-label="Progress">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                            <div style="flex:1; background:var(--border); height:6px; border-radius:3px; overflow:hidden; min-width:60px;">
                                <div style="background:var(--accent,#7c3aed); width:<?= $m['progress'] ?>%; height:100%;"></div>
                            </div>
                            <span style="font-size:0.75rem; font-weight:700;"><?= $m['progress'] ?>%</span>
                        </div>
                        <span class="badge green" style="font-size:0.65rem;"><i class="fas fa-chart-line"></i> Attendance: <?= $m['attendance'] ?>%</span>
                    </td>
                    <td data-label="Streak">
                        <span style="font-weight:700; color:#b45309; font-size:0.85rem;">🔥 <?= $m['streak'] ?> / <?= $m['streak_target'] ?> Days</span>
                    </td>
                    <td data-label="Last Call">
                        <div class="cell-sub" style="font-size:0.8rem;">
                            <?= $m['last_call_time'] ? date('d M Y, h:i A', strtotime($m['last_call_time'])) : 'Never' ?><br>
                            <span class="badge <?= $m['last_call_time'] ? 'blue' : 'gray' ?>" style="font-size:0.65rem; margin-top:2px; display:inline-block;"><?= $m['last_called_status'] ?></span>
                        </div>
                    </td>
                    <td class="actions-cell" style="text-align:right; white-space:nowrap;">
                        <a href="student-study-reports.php?source=courses&student_id=<?= urlencode($s['user_id']) ?>" target="_blank" class="btn btn-sm btn-soft-violet" title="View Student Report"><i class="fas fa-chart-line"></i> Report</a>
                        <button type="button" class="btn btn-sm btn-soft-blue" onclick="openCall('<?= e($s['user_id']) ?>', '<?= e($s['full_name']) ?>', '<?= e(($s['whatsapp_country_code'] ?: '+91') . ' ' . format_credential_text($s['whatsapp_number'], 'phone', 'students')) ?>', '<?= e(preg_replace('/\D/', '', ($s['whatsapp_country_code'] ?: '+91') . $s['whatsapp_number'])) ?>')" title="Log Call"><i class="fas fa-phone"></i> Log Call</button>
                        <?php if (can_admin_whatsapp_chat()): ?>
                            <a href="https://wa.me/<?= $wa_phone ?>" target="_blank" class="btn btn-sm btn-whatsapp" title="WhatsApp Chat"><i class="fab fa-whatsapp"></i> Chat</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-whatsapp" title="WhatsApp Chat (Restricted)" style="opacity:0.6; cursor:not-allowed;" disabled><i class="fab fa-whatsapp"></i> Chat (Restricted)</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline" onclick="openRemark('<?= e($s['user_id']) ?>', '<?= e($s['full_name']) ?>')" title="Add/View Remarks"><i class="fas fa-comment-dots"></i> Remarks (<?= $m['remarks_count'] ?>)</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($tab === 'calls'): ?>
<?php if ($selected_course_id === 0): ?>
    <div class="panel" style="padding: 2.5rem; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 12px;">📞</div>
        <h3 style="margin-bottom: 6px; color: var(--text);">Select a course to view call logs</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Choose a PEPP course above to view and manage call logs.</p>
    </div>
<?php else: ?>
<!-- Call Logs -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--green-soft);color:var(--green-ink);"><i class="fas fa-phone"></i></span>
        <h2>Call Logs (<?= e($selected_course_name) ?>)</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($call_logs)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">No call logs yet for this course.</div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Called By</th>
                    <th>Time</th>
                    <th>Notes</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($call_logs as $cl): ?>
            <tr>
                <td class="cell-main">
                    <div class="cell-main">
                        <?php if (!empty($cl['student_user_id'])): ?>
                            <a href="student-study-reports.php?source=courses&student_id=<?php echo urlencode($cl['student_user_id']); ?>" target="_blank" style="color:var(--accent); font-weight:700; text-decoration:none;">
                                <?php echo e($cl['student_name'] ?: 'Unknown (' . $cl['student_user_id'] . ')'); ?>
                            </a>
                        <?php else: ?>
                            <?php echo e($cl['student_name'] ?: 'Unknown (' . $cl['student_user_id'] . ')'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="cell-sub"><?php echo e(($cl['whatsapp_country_code'] ?: '+91') . ' ' . format_credential_text($cl['whatsapp_number'], 'phone', 'students')); ?></div>
                </td>
                <td class="cell-sub"><?php echo e($cl['admin_username']); ?></td>
                <td class="cell-sub"><?php echo date('d M Y, h:i A', strtotime($cl['call_timestamp'])); ?></td>
                <td style="max-width:300px;"><?php echo e($cl['notes'] ?? '—'); ?></td>
                <td style="text-align:right; white-space:nowrap;">
                    <?php
                    $cl_wa = preg_replace('/\D/', '', ($cl['whatsapp_country_code'] ?: '+91') . $cl['whatsapp_number']);
                    if ($cl_wa):
                    ?>
                        <?php if (can_admin_whatsapp_chat()): ?>
                            <a href="https://wa.me/<?php echo $cl_wa; ?>" target="_blank" class="btn btn-sm btn-whatsapp" title="WhatsApp Chat"><i class="fab fa-whatsapp"></i> Chat</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-whatsapp" title="WhatsApp Chat (Restricted)" style="opacity:0.6; cursor:not-allowed;" disabled><i class="fab fa-whatsapp"></i> Chat (Restricted)</button>
                        <?php endif; ?>

                        <?php if (can_admin_phone_call()): ?>
                            <a href="tel:<?php echo $cl_wa; ?>" class="btn btn-sm btn-outline" title="Call Student"><i class="fas fa-phone"></i></a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline" title="Call Student (Restricted)" style="opacity:0.6; cursor:not-allowed;" disabled><i class="fas fa-phone"></i></button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (is_super_admin()): ?>
                    <button type="button" class="btn btn-sm btn-outline" style="color:var(--blue-ink); border-color:var(--blue-soft); padding: 4px 8px;" onclick="openEditCall('<?php echo $cl['id']; ?>', '<?php echo e($cl['student_name'] ?: $cl['student_user_id']); ?>', '<?php echo $cl['call_timestamp']; ?>', '<?php echo e($cl['notes']); ?>')" title="Edit Log"><i class="fas fa-edit"></i></button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this call log?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_call_log">
                        <input type="hidden" name="log_id" value="<?php echo $cl['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline" style="color:var(--red-ink); border-color:var(--red-soft); padding: 4px 8px;" title="Delete Log"><i class="fas fa-trash-can"></i></button>
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
<?php endif; ?>

<?php elseif ($tab === 'remarks'): ?>
<?php if ($selected_course_id === 0): ?>
    <div class="panel" style="padding: 2.5rem; text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 12px;">💬</div>
        <h3 style="margin-bottom: 6px; color: var(--text);">Select a course to view remarks</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Choose a PEPP course above to view and manage student remarks.</p>
    </div>
<?php else: ?>
<!-- Remarks -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--amber-soft);color:var(--amber-ink);"><i class="fas fa-comment-dots"></i></span>
        <h2>Student Remarks (<?= e($selected_course_name) ?>)</h2>
    </div>
    <div class="panel-body flush table-wrap">
        <?php if (empty($remarks_list)): ?>
            <div style="padding:2rem;text-align:center;color:var(--text-muted);">No remarks yet for this course.</div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>By</th>
                    <th>Remark</th>
                    <th>Date</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($remarks_list as $rm): ?>
            <tr>
                <td class="cell-main">
                    <div class="cell-main">
                        <?php if (!empty($rm['student_user_id'])): ?>
                            <a href="student-study-reports.php?source=courses&student_id=<?php echo urlencode($rm['student_user_id']); ?>" target="_blank" style="color:var(--accent); font-weight:700; text-decoration:none;">
                                <?php echo e($rm['student_name'] ?: 'Unknown (' . $rm['student_user_id'] . ')'); ?>
                            </a>
                        <?php else: ?>
                            <?php echo e($rm['student_name'] ?: 'Unknown (' . $rm['student_user_id'] . ')'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="cell-sub"><?php echo e(($rm['whatsapp_country_code'] ?: '+91') . ' ' . format_credential_text($rm['whatsapp_number'], 'phone', 'students')); ?></div>
                </td>
                <td class="cell-sub"><?php echo e($rm['admin_username']); ?></td>
                <td style="max-width:350px;"><?php echo e($rm['remark']); ?></td>
                <td class="cell-sub"><?php echo date('d M Y, h:i A', strtotime($rm['created_at'])); ?></td>
                <td style="text-align:right; white-space:nowrap;">
                    <?php
                    $rm_wa = preg_replace('/\D/', '', ($rm['whatsapp_country_code'] ?: '+91') . $rm['whatsapp_number']);
                    if ($rm_wa):
                    ?>
                        <?php if (can_admin_whatsapp_chat()): ?>
                            <a href="https://wa.me/<?php echo $rm_wa; ?>" target="_blank" class="btn btn-sm btn-whatsapp" title="WhatsApp Chat"><i class="fab fa-whatsapp"></i> Chat</a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-whatsapp" title="WhatsApp Chat (Restricted)" style="opacity:0.6; cursor:not-allowed;" disabled><i class="fab fa-whatsapp"></i> Chat (Restricted)</button>
                        <?php endif; ?>

                        <?php if (can_admin_phone_call()): ?>
                            <a href="tel:<?php echo $rm_wa; ?>" class="btn btn-sm btn-outline" title="Call Student"><i class="fas fa-phone"></i></a>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline" title="Call Student (Restricted)" style="opacity:0.6; cursor:not-allowed;" disabled><i class="fas fa-phone"></i></button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (is_super_admin()): ?>
                    <button type="button" class="btn btn-sm btn-outline" style="color:var(--blue-ink); border-color:var(--blue-soft); padding: 4px 8px;" onclick="openEditRemark('<?php echo $rm['id']; ?>', '<?php echo e($rm['student_name'] ?: $rm['student_user_id']); ?>', '<?php echo e($rm['remark']); ?>')" title="Edit Remark"><i class="fas fa-edit"></i></button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this remark?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_remark">
                        <input type="hidden" name="remark_id" value="<?php echo $rm['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline" style="color:var(--red-ink); border-color:var(--red-soft); padding: 4px 8px;" title="Delete Remark"><i class="fas fa-trash-can"></i></button>
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
<?php endif; ?>

<?php elseif ($tab === 'assignments' && is_super_admin()): ?>
<!-- Mentor Assignments (Super Admin) -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--red-soft);color:var(--red-ink);"><i class="fas fa-link"></i></span>
        <h2>Mentor ↔ Course Assignments</h2>
        <div class="head-right">
            <button class="btn btn-sm btn-primary" onclick="openModal('assignModal')"><i class="fas fa-plus"></i> Assign Mentor</button>
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
<div id="assignModal" class="modal-backdrop">
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
            <div style="font-size:.7rem;color:var(--text-muted);margin-top:4px;">Super Admin can assign any active PEPP course.</div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('assignModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Assign</button>
        </div>
    </form>
</div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Log Call Modal -->
<div id="callModal" class="modal-backdrop">
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
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('callModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#22c55e;border-color:#22c55e;"><i class="fas fa-phone"></i> Log Call</button>
        </div>
    </form>
</div>
</div>

<!-- Add Remark Modal -->
<div id="remarkModal" class="modal-backdrop">
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
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('remarkModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#f59e0b;border-color:#f59e0b;"><i class="fas fa-comment-dots"></i> Save Remark</button>
        </div>
    </form>
    <div id="previousRemarksList"></div>
</div>
</div>

<!-- Edit Call Modal -->
<div id="editCallModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:440px;width:100%;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-edit" style="color:#3b82f6;"></i> Edit Call Log</h3>
    <p id="editCallStudentName" style="margin-bottom:1rem;color:var(--text-muted);font-size:.85rem;"></p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="edit_call_log">
        <input type="hidden" name="log_id" id="editCallLogId">
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Call Time</label>
            <input type="datetime-local" name="call_timestamp" id="editCallTimestamp" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);">
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Notes</label>
            <textarea name="call_notes" id="editCallNotes" rows="3" placeholder="Call summary, follow-up needed, etc." style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);resize:vertical;"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('editCallModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#3b82f6;border-color:#3b82f6;"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </form>
</div>
</div>

<!-- Edit Remark Modal -->
<div id="editRemarkModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:440px;width:100%;">
    <h3 style="margin-bottom:1rem;"><i class="fas fa-edit" style="color:#3b82f6;"></i> Edit Student Remark</h3>
    <p id="editRemarkStudentName" style="margin-bottom:1rem;color:var(--text-muted);font-size:.85rem;"></p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="edit_remark">
        <input type="hidden" name="remark_id" id="editRemarkId">
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Remark *</label>
            <textarea name="remark_text" id="editRemarkText" rows="4" required placeholder="Progress notes, concerns, praise, etc." style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);resize:vertical;"></textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('editRemarkModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" style="background:#3b82f6;border-color:#3b82f6;"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </form>
</div>
</div>

<script>
const isCredentialRestricted = <?php echo is_credential_restricted('students') ? 'true' : 'false'; ?>;
const canWhatsappChat = <?php echo can_admin_whatsapp_chat() ? 'true' : 'false'; ?>;
const canPhoneCall = <?php echo can_admin_phone_call() ? 'true' : 'false'; ?>;
const canAccessStudents = <?php echo can_access('students') ? 'true' : 'false'; ?>;

function openCall(id, name, displayNum, rawNum) {
    document.getElementById('callStudentId').value = id;
    let html = 'Student: ' + name + ' (' + id + ')';
    if (canPhoneCall) {
        html += '<br><a href="tel:' + rawNum + '" style="color:var(--accent); font-weight:700; text-decoration:underline; margin-top:4px; display:inline-block;"><i class="fas fa-phone-volume"></i> Click to Call: ' + displayNum + '</a>';
    } else {
        html += '<br><span style="color:var(--text-muted); font-size:0.75rem; margin-top:4px; display:inline-block;"><i class="fas fa-phone-slash"></i> Call (Restricted): ' + displayNum + '</span>';
    }
    document.getElementById('callStudentName').innerHTML = html;
    openModal('callModal');
}
function openRemark(id, name) {
    document.getElementById('remarkStudentId').value = id;
    document.getElementById('remarkStudentName').textContent = 'Student: ' + name + ' (' + id + ')';

    const remarksContainer = document.getElementById('previousRemarksList');
    if (remarksContainer) {
        remarksContainer.innerHTML = '<div style="font-size:0.75rem; color:var(--text-muted); text-align:center; padding:8px;"><i class="fas fa-spinner fa-spin"></i> Loading previous remarks...</div>';
    }

    fetch(`student-mentoring.php?get_remarks_student_user_id=${encodeURIComponent(id)}`)
        .then(r => r.json())
        .then(res => {
            if (remarksContainer) {
                if (res.success && res.remarks && res.remarks.length > 0) {
                    let html = '<div style="margin-top:12px; border-top:1px solid var(--border); padding-top:12px; max-height:200px; overflow-y:auto; display:flex; flex-direction:column; gap:8px;">';
                    html += '<h5 style="margin:0 0 6px; font-weight:600; font-size:0.75rem; color:var(--text);">Previous Remarks (' + res.remarks.length + ')</h5>';
                    res.remarks.forEach(r => {
                        const dateStr = new Date(r.created_at).toLocaleString([], {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
                        html += `
                            <div style="background:var(--card-sub, #f8fafc); border:1px solid var(--border); border-radius:8px; padding:8px; font-size:0.75rem; border-left:3px solid var(--amber-soft, #f59e0b); text-align:left;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:4px; font-size:0.7rem; color:var(--text-muted);">
                                    <strong>By: ${escapeHtmlJS(r.admin_username)}</strong>
                                    <span>${dateStr}</span>
                                </div>
                                <div style="white-space:pre-wrap; color:var(--text);">${escapeHtmlJS(r.remark)}</div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    remarksContainer.innerHTML = html;
                } else {
                    remarksContainer.innerHTML = '<div style="font-size:0.75rem; color:var(--text-muted); text-align:center; padding:8px; margin-top:12px; border-top:1px solid var(--border);">No previous remarks.</div>';
                }
            }
        })
        .catch(err => {
            if (remarksContainer) {
                remarksContainer.innerHTML = '<div style="font-size:0.75rem; color:var(--red-ink); text-align:center; padding:8px; margin-top:12px; border-top:1px solid var(--border);">Failed to load remarks.</div>';
            }
        });

    openModal('remarkModal');
}
function openEditCall(logId, studentName, timestamp, notes) {
    document.getElementById('editCallLogId').value = logId;
    document.getElementById('editCallStudentName').textContent = 'Student: ' + studentName;
    document.getElementById('editCallTimestamp').value = timestamp.replace(' ', 'T').substring(0, 16);
    document.getElementById('editCallNotes').value = notes;
    openModal('editCallModal');
}
function openEditRemark(remarkId, studentName, remarkText) {
    document.getElementById('editRemarkId').value = remarkId;
    document.getElementById('editRemarkStudentName').textContent = 'Student: ' + studentName;
    document.getElementById('editRemarkText').value = remarkText;
    openModal('editRemarkModal');
}
// Escape key to close open modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['callModal', 'remarkModal', 'assignModal', 'editCallModal', 'editRemarkModal'].forEach(id => {
            const m = document.getElementById(id);
            if (m && m.classList.contains('open')) {
                closeModal(id);
            }
        });
    }
});

function applyStudentFilters() {
    const q = document.getElementById('student-search-input') ? document.getElementById('student-search-input').value.toLowerCase().trim() : '';
    const perf = document.getElementById('filter-performance') ? document.getElementById('filter-performance').value : 'ALL';
    const streak = document.getElementById('filter-streak') ? document.getElementById('filter-streak').value : 'ALL';
    const completed = document.getElementById('filter-completed') ? document.getElementById('filter-completed').value : 'ALL';
    const pending = document.getElementById('filter-pending') ? document.getElementById('filter-pending').value : 'ALL';
    const overdue = document.getElementById('filter-overdue') ? document.getElementById('filter-overdue').value : 'ALL';
    const attendance = document.getElementById('filter-attendance') ? document.getElementById('filter-attendance').value : 'ALL';

    const rows = document.querySelectorAll('.student-row');
    rows.forEach(row => {
        const name = row.dataset.name || '';
        const email = row.dataset.email || '';
        const mobile = row.dataset.mobile || '';
        const userId = row.dataset.userId || '';
        const progress = parseInt(row.dataset.progress) || 0;
        const streakVal = parseInt(row.dataset.streak) || 0;
        const completedVal = parseInt(row.dataset.completed) || 0;
        const pendingVal = parseInt(row.dataset.pending) || 0;
        const overdueVal = parseInt(row.dataset.overdue) || 0;
        const attendanceVal = parseInt(row.dataset.attendance) || 0;

        let show = true;

        // Search filter
        if (q && !name.includes(q) && !email.includes(q) && !mobile.includes(q) && !userId.includes(q)) {
            show = false;
        }

        // Performance filter
        if (show && perf !== 'ALL') {
            if (perf === 'EXCELLENT' && progress < 85) show = false;
            else if (perf === 'GOOD' && (progress < 70 || progress >= 85)) show = false;
            else if (perf === 'AVERAGE' && (progress < 50 || progress >= 70)) show = false;
            else if (perf === 'NEEDS_IMPROVEMENT' && progress >= 50) show = false;
        }

        // Streak filter
        if (show && streak !== 'ALL') {
            if (streak === 'ACTIVE' && streakVal <= 0) show = false;
            else if (streak === 'HIGH' && streakVal < 5) show = false;
            else if (streak === 'NONE' && streakVal > 0) show = false;
        }

        // Completed Tasks filter
        if (show && completed !== 'ALL') {
            if (completed === 'HIGH' && completedVal < 10) show = false;
            else if (completed === 'SOME' && (completedVal < 1 || completedVal >= 10)) show = false;
            else if (completed === 'NONE' && completedVal > 0) show = false;
        }

        // Pending Tasks filter
        if (show && pending !== 'ALL') {
            if (pending === 'YES' && pendingVal <= 0) show = false;
            else if (pending === 'NONE' && pendingVal > 0) show = false;
        }

        // Overdue Tasks filter
        if (show && overdue !== 'ALL') {
            if (overdue === 'YES' && overdueVal <= 0) show = false;
            else if (overdue === 'NONE' && overdueVal > 0) show = false;
        }

        // Attendance Rate filter
        if (show && attendance !== 'ALL') {
            if (attendance === 'HIGH' && attendanceVal < 75) show = false;
            else if (attendance === 'LOW' && attendanceVal >= 75) show = false;
        }

        row.style.display = show ? '' : 'none';
    });
}

function resetStudentFilters() {
    if (document.getElementById('student-search-input')) document.getElementById('student-search-input').value = '';
    if (document.getElementById('filter-performance')) document.getElementById('filter-performance').value = 'ALL';
    if (document.getElementById('filter-streak')) document.getElementById('filter-streak').value = 'ALL';
    if (document.getElementById('filter-completed')) document.getElementById('filter-completed').value = 'ALL';
    if (document.getElementById('filter-pending')) document.getElementById('filter-pending').value = 'ALL';
    if (document.getElementById('filter-overdue')) document.getElementById('filter-overdue').value = 'ALL';
    if (document.getElementById('filter-attendance')) document.getElementById('filter-attendance').value = 'ALL';
    applyStudentFilters();
}

function escapeHtmlJS(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

<?php include 'includes/admin_footer.php'; ?>
