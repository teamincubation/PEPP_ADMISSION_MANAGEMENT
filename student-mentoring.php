<?php
/**
 * PEPP Learning ERP — Student Mentoring Page
 * - View assigned students (based on mentor course assignments)
 * - Log calls, add remarks, set reminders
 * - Track student progress, streaks, and study completion
 */
require_once 'includes/auth.php';
require_permission('student-mentoring');
require_once 'includes/StudentStudyPlanAnalytics.php';

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

// AJAX call to fetch students belonging to a course for mentor assignment (Super Admin only)
if (isset($_GET['get_course_students'])) {
    header('Content-Type: application/json');
    if (!is_super_admin()) {
        echo json_encode(['success' => false, 'error' => 'Access Denied: Super Admin required']);
        exit;
    }
    $course_name = trim($_GET['course_name'] ?? '');
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.user_id, u.name AS full_name, u.email, u.created_at, u.pepp_course
            FROM users u
            WHERE u.pepp_course = ? AND u.status IN ('approved', 'active')
            ORDER BY u.created_at ASC, u.id ASC
        ");
        $stmt->execute([$course_name]);
        $course_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $active_mentors = [];
        if (!empty($course_students)) {
            $uids = array_column($course_students, 'user_id');
            $placeholders = implode(',', array_fill(0, count($uids), '?'));
            $stmt_m = $pdo->prepare("
                SELECT msa.student_user_id, msa.admin_id, a.username, a.full_name
                FROM mentor_student_assignments msa
                JOIN admins a ON msa.admin_id = a.id
                WHERE msa.status = 'active' AND msa.student_user_id IN ($placeholders)
            ");
            $stmt_m->execute($uids);
            while ($m_row = $stmt_m->fetch(PDO::FETCH_ASSOC)) {
                $active_mentors[$m_row['student_user_id']] = [
                    'admin_id' => (int)$m_row['admin_id'],
                    'mentor_username' => $m_row['username'],
                    'mentor_name' => $m_row['full_name'] ?: $m_row['username']
                ];
            }
        }

        $out = [];
        foreach ($course_students as $st) {
            $uid = $st['user_id'];
            $m_info = $active_mentors[$uid] ?? null;
            $joined_str = !empty($st['created_at']) ? date('d M Y', strtotime($st['created_at'])) : '—';
            $out[] = [
                'user_id' => $uid,
                'full_name' => $st['full_name'],
                'joined_date' => $joined_str,
                'current_mentor_id' => $m_info ? $m_info['admin_id'] : null,
                'current_mentor_name' => $m_info ? $m_info['mentor_name'] : 'Not Assigned',
                'has_mentor' => ($m_info !== null)
            ];
        }

        echo json_encode(['success' => true, 'students' => $out]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX call to fetch complete mentor assignment history for a student
if (isset($_GET['get_student_assignment_history'])) {
    header('Content-Type: application/json');
    $student_user_id = trim($_GET['get_student_assignment_history']);
    $cur_admin_id = $admin_row['id'] ?? 0;
    if (!is_super_admin() && !is_student_assigned_to_mentor($pdo, $student_user_id, $cur_admin_id)) {
        echo json_encode(['success' => false, 'error' => 'Access Denied: You do not have permission to view this history']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT msa.id, msa.student_user_id, msa.admin_id, msa.course_name, msa.assigned_by,
                   msa.assigned_at, msa.ended_at, msa.status,
                   a.username AS mentor_username, a.full_name AS mentor_full_name
            FROM mentor_student_assignments msa
            LEFT JOIN admins a ON msa.admin_id = a.id
            WHERE msa.student_user_id = ?
            ORDER BY msa.assigned_at DESC, msa.id DESC
        ");
        $stmt->execute([$student_user_id]);
        $raw_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $history = [];
        foreach ($raw_history as $h) {
            $history[] = [
                'id' => (int)$h['id'],
                'mentor_id' => (int)$h['admin_id'],
                'mentor_username' => $h['mentor_username'] ?? 'Unknown',
                'mentor_name' => $h['mentor_full_name'] ?: ($h['mentor_username'] ?? 'Unknown'),
                'course_name' => $h['course_name'],
                'assigned_by' => $h['assigned_by'],
                'assigned_at' => $h['assigned_at'] ? date('d M Y, h:i A', strtotime($h['assigned_at'])) : '—',
                'ended_at' => $h['ended_at'] ? date('d M Y, h:i A', strtotime($h['ended_at'])) : null,
                'status' => $h['status']
            ];
        }
        echo json_encode(['success' => true, 'history' => $history]);
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

// Check if mentoring tables exist & auto-install mentor_student_assignments if missing
function mentor_tables_exist($pdo) {
    static $ok = null;
    if ($ok === null) {
        try {
            $driver = '';
            try { $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Exception $ed) {}
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS mentor_student_assignments (
                      id INTEGER PRIMARY KEY AUTOINCREMENT,
                      student_user_id TEXT NOT NULL,
                      admin_id INTEGER NOT NULL,
                      course_name TEXT NOT NULL,
                      assigned_by TEXT NOT NULL,
                      assigned_at DATETIME NOT NULL,
                      ended_at DATETIME DEFAULT NULL,
                      status TEXT NOT NULL DEFAULT 'active',
                      created_at DATETIME NOT NULL,
                      updated_at DATETIME DEFAULT NULL,
                      active_student_key TEXT GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN student_user_id ELSE NULL END) VIRTUAL,
                      UNIQUE (active_student_key)
                    );
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS mentor_student_assignments (
                      id INT AUTO_INCREMENT PRIMARY KEY,
                      student_user_id VARCHAR(50) NOT NULL,
                      admin_id INT NOT NULL,
                      course_name VARCHAR(255) NOT NULL,
                      assigned_by VARCHAR(100) NOT NULL,
                      assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      ended_at DATETIME DEFAULT NULL,
                      status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                      active_student_key VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN student_user_id ELSE NULL END) VIRTUAL,
                      UNIQUE KEY uq_msa_active_student (active_student_key),
                      KEY idx_msa_student (student_user_id),
                      KEY idx_msa_admin (admin_id),
                      KEY idx_msa_status (status),
                      KEY idx_msa_course (course_name),
                      KEY idx_msa_student_status (student_user_id, status)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
            }
            $ok = true;
        } catch (Exception $e) {
            try {
                $ok = (bool)$pdo->query("SELECT 1 FROM mentor_student_assignments LIMIT 1");
            } catch (Exception $e2) {
                $ok = false;
            }
        }
    }
    return $ok;
}

/** Get mentoring metrics (progress, attendance, streak, call and remark details) for a student. */
function get_student_mentoring_details($pdo, $student) {
    $email = $student['email'];
    $user_id = $student['user_id'];
    $course = $student['course'];

    // Get Course level analytics using the canonical helper
    $course_analytics = StudentStudyPlanAnalytics::getCourseAnalytics($pdo, $email, $course);

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
        'progress' => $course_analytics['completion_percentage'],
        'attendance' => $course_analytics['attendance_rate'],
        'attended_sessions' => $course_analytics['attended_sessions'] ?? 0,
        'total_sessions' => $course_analytics['total_sessions'] ?? 0,
        'streak' => $course_analytics['active_streak'],
        'streak_target' => $course_analytics['longest_streak'],
        'total_plan_calendar_days' => $course_analytics['total_plan_calendar_days'] ?? 0,
        'last_call_time' => $last_call_time,
        'last_called_status' => $last_called_status,
        'remarks_count' => $remarks_count,
        'total_tasks' => $course_analytics['total_tasks'],
        'completed_tasks' => $course_analytics['completed_tasks'],
        'pending_tasks' => $course_analytics['pending_tasks'],
        'overdue_tasks' => $course_analytics['overdue_tasks']
    ];
}

// ── POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    // Log a call (Authorized: Super Admin or currently active mentor)
    if ($action === 'log_call' && mentor_tables_exist($pdo)) {
        $student_id = trim($_POST['student_user_id'] ?? '');
        $notes = trim($_POST['call_notes'] ?? '');
        $call_time = trim($_POST['call_timestamp'] ?? date('Y-m-d H:i:s'));
        if (!$student_id) {
            $error_message = 'Student ID is required.';
        } elseif (!is_super_admin() && !is_student_assigned_to_mentor($pdo, $student_id, $admin_id)) {
            $error_message = 'Access Denied: You are not the active mentor for this student.';
        } else {
            try {
                $pdo->prepare("INSERT INTO mentor_call_logs (student_user_id, admin_id, admin_username, call_timestamp, notes) VALUES (?,?,?,?,?)")
                    ->execute([$student_id, $admin_id, $admin_username, $call_time, $notes ?: null]);
                log_admin_activity($pdo, $admin_username, 'mentor_call', "Logged call for student {$student_id}");
                $success_message = 'Call logged successfully.';
            } catch (Exception $e) { $error_message = 'Error logging call: ' . $e->getMessage(); }
        }
    }

    // Add remark (Authorized: Super Admin or currently active mentor)
    elseif ($action === 'add_remark' && mentor_tables_exist($pdo)) {
        $student_id = trim($_POST['student_user_id'] ?? '');
        $remark = trim($_POST['remark_text'] ?? '');
        if (!$student_id || !$remark) {
            $error_message = 'Student ID and remark text are required.';
        } elseif (!is_super_admin() && !is_student_assigned_to_mentor($pdo, $student_id, $admin_id)) {
            $error_message = 'Access Denied: You are not the active mentor for this student.';
        } else {
            try {
                $pdo->prepare("INSERT INTO mentor_remarks (student_user_id, admin_id, admin_username, remark) VALUES (?,?,?,?)")
                    ->execute([$student_id, $admin_id, $admin_username, $remark]);
                log_admin_activity($pdo, $admin_username, 'mentor_remark', "Added remark for student {$student_id}");
                $success_message = 'Remark added.';
            } catch (Exception $e) { $error_message = 'Error: ' . $e->getMessage(); }
        }
    }

    // Assign student(s) to mentor (Super Admin only - Atomic Transaction)
    elseif ($action === 'assign_students' && is_super_admin() && mentor_tables_exist($pdo)) {
        $mentor_admin_id = (int)($_POST['mentor_admin_id'] ?? 0);
        $course = trim($_POST['course_name'] ?? '');
        $raw_student_ids = $_POST['student_user_ids'] ?? [];
        if (!is_array($raw_student_ids)) {
            $raw_student_ids = array_filter(explode(',', (string)$raw_student_ids));
        }
        $student_user_ids = array_values(array_unique(array_filter(array_map('trim', $raw_student_ids))));

        if (!$mentor_admin_id || empty($course) || empty($student_user_ids)) {
            $error_message = 'Please select a mentor, a course, and at least one student.';
        } else {
            $pdo->beginTransaction();
            try {
                // Verify mentor
                $stmt_adm = $pdo->prepare("SELECT id, username, full_name FROM admins WHERE id = ? AND status = 'active'");
                $stmt_adm->execute([$mentor_admin_id]);
                $mentor_row = $stmt_adm->fetch(PDO::FETCH_ASSOC);
                if (!$mentor_row) {
                    throw new Exception('Invalid or inactive mentor selected.');
                }
                $mentor_disp = $mentor_row['full_name'] ?: $mentor_row['username'];

                // Verify course exists
                $stmt_crs = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE course_name = ? AND status = 'active'");
                $stmt_crs->execute([$course]);
                if (!$stmt_crs->fetchColumn()) {
                    $stmt_crs_u = $pdo->prepare("SELECT COUNT(*) FROM users WHERE pepp_course = ?");
                    $stmt_crs_u->execute([$course]);
                    if ($stmt_crs_u->fetchColumn() == 0) {
                        throw new Exception("Invalid course '{$course}'.");
                    }
                }

                $now = date('Y-m-d H:i:s');
                $assigned_count = 0;
                $reassigned_count = 0;

                $stmt_chk_st = $pdo->prepare("SELECT user_id, name, pepp_course FROM users WHERE user_id = ? AND status IN ('approved', 'active')");
                $stmt_get_active = $pdo->prepare("SELECT id, admin_id FROM mentor_student_assignments WHERE student_user_id = ? AND status = 'active'");
                $stmt_close_active = $pdo->prepare("UPDATE mentor_student_assignments SET status = 'inactive', ended_at = ? WHERE id = ?");
                $stmt_insert_active = $pdo->prepare("INSERT INTO mentor_student_assignments (student_user_id, admin_id, course_name, assigned_by, assigned_at, status) VALUES (?, ?, ?, ?, ?, 'active')");

                foreach ($student_user_ids as $st_id) {
                    // 1. Verify student exists and belongs to course
                    $stmt_chk_st->execute([$st_id]);
                    $st_row = $stmt_chk_st->fetch(PDO::FETCH_ASSOC);
                    if (!$st_row) {
                        throw new Exception("Student ID '{$st_id}' does not exist or is not approved.");
                    }
                    if ($st_row['pepp_course'] !== $course) {
                        throw new Exception("Student '{$st_row['name']}' ({$st_id}) belongs to '{$st_row['pepp_course']}', not '{$course}'.");
                    }

                    // 2. Check active assignment
                    $stmt_get_active->execute([$st_id]);
                    $cur_act = $stmt_get_active->fetch(PDO::FETCH_ASSOC);

                    if ($cur_act) {
                        if ((int)$cur_act['admin_id'] === $mentor_admin_id) {
                            continue; // already assigned to this mentor
                        }
                        // Close previous active assignment
                        $stmt_close_active->execute([$now, $cur_act['id']]);
                        $reassigned_count++;
                    } else {
                        $assigned_count++;
                    }

                    // 3. Insert new active assignment
                    $stmt_insert_active->execute([$st_id, $mentor_admin_id, $course, $admin_username, $now]);
                }

                $total_done = $assigned_count + $reassigned_count;
                if ($total_done > 0) {
                    log_admin_activity($pdo, $admin_username, 'mentor_students_assigned', "Assigned {$total_done} student(s) ({$assigned_count} new, {$reassigned_count} reassigned) from '{$course}' to mentor {$mentor_row['username']} (#{$mentor_admin_id})");
                }

                $pdo->commit();
                $success_message = "Successfully assigned {$total_done} student(s) to mentor {$mentor_disp}.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_message = 'Error assigning students: ' . $e->getMessage();
            }
        }
    }

    // Unassign student (Super Admin only)
    elseif ($action === 'unassign_student' && is_super_admin() && mentor_tables_exist($pdo)) {
        $student_id = trim($_POST['student_user_id'] ?? '');
        if ($student_id) {
            $pdo->beginTransaction();
            try {
                $now = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("UPDATE mentor_student_assignments SET status = 'inactive', ended_at = ? WHERE student_user_id = ? AND status = 'active'");
                $stmt->execute([$now, $student_id]);
                log_admin_activity($pdo, $admin_username, 'mentor_student_unassigned', "Unassigned mentor for student {$student_id}");
                $pdo->commit();
                $success_message = "Mentor unassigned for student {$student_id}.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_message = 'Error unassigning mentor: ' . $e->getMessage();
            }
        } else {
            $error_message = 'Student ID is required.';
        }
    }

    // Legacy Course Assignment (Super Admin only)
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

    // Remove Legacy Course Assignment (Super Admin only)
    elseif ($action === 'remove_assignment' && is_super_admin() && mentor_tables_exist($pdo)) {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        if ($assignment_id) {
            try {
                $pdo->prepare("DELETE FROM mentor_course_assignments WHERE id = ?")->execute([$assignment_id]);
                log_admin_activity($pdo, $admin_username, 'mentor_unassigned', "Removed mentor course assignment #{$assignment_id}");
                $success_message = 'Course assignment removed.';
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
                SELECT DISTINCT pc.id, pc.course_name
                FROM pepp_courses pc
                WHERE pc.status = 'active'
                  AND (
                    pc.course_name IN (SELECT course_name FROM mentor_student_assignments WHERE admin_id = ? AND status = 'active')
                    OR pc.course_name IN (SELECT course_name FROM mentor_course_assignments WHERE admin_id = ?)
                  )
                ORDER BY pc.course_name
            ");
            $stmt->execute([$admin_id, $admin_id]);
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
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM mentor_student_assignments WHERE admin_id = ? AND course_name = ? AND status = 'active'");
                    $chk->execute([$admin_id, $selected_course_name]);
                    if ($chk->fetchColumn() > 0) {
                        $authorized = true;
                    } else {
                        // Fallback check legacy mentor_course_assignments
                        $chk2 = $pdo->prepare("SELECT COUNT(*) FROM mentor_course_assignments WHERE admin_id = ? AND course_name = ?");
                        $chk2->execute([$admin_id, $selected_course_name]);
                        if ($chk2->fetchColumn() > 0) {
                            $authorized = true;
                        }
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

    // If super admin, show all student mentor assignments
    if (is_super_admin()) {
        try {
            if ($selected_course_name !== '') {
                $stmt = $pdo->prepare("
                    SELECT msa.*, u.name AS student_name, u.email AS student_email, u.whatsapp_country_code, u.whatsapp_number,
                           a.username AS mentor_username, a.full_name AS mentor_full_name
                    FROM mentor_student_assignments msa
                    LEFT JOIN users u ON msa.student_user_id = u.user_id
                    LEFT JOIN admins a ON msa.admin_id = a.id
                    WHERE msa.course_name = ?
                    ORDER BY (msa.status = 'active') DESC, msa.assigned_at DESC
                ");
                $stmt->execute([$selected_course_name]);
                $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $assignments = $pdo->query("
                    SELECT msa.*, u.name AS student_name, u.email AS student_email, u.whatsapp_country_code, u.whatsapp_number,
                           a.username AS mentor_username, a.full_name AS mentor_full_name
                    FROM mentor_student_assignments msa
                    LEFT JOIN users u ON msa.student_user_id = u.user_id
                    LEFT JOIN admins a ON msa.admin_id = a.id
                    ORDER BY (msa.status = 'active') DESC, msa.assigned_at DESC
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {}
    }

    // Load data only if course is selected
    if ($selected_course_name !== '') {
        try {
            if (is_super_admin()) {
                $stmt = $pdo->prepare("
                    SELECT u.user_id, u.name AS full_name, u.email, u.whatsapp_country_code, u.whatsapp_number, u.pepp_course AS course, u.status, u.pepp_academic_year, u.created_at
                    FROM users u
                    WHERE u.pepp_course = ? AND u.status IN ('approved','active')
                    ORDER BY u.created_at ASC, u.id ASC
                ");
                $stmt->execute([$selected_course_name]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT u.user_id, u.name AS full_name, u.email, u.whatsapp_country_code, u.whatsapp_number, u.pepp_course AS course, u.status, u.pepp_academic_year, u.created_at
                    FROM users u
                    JOIN mentor_student_assignments msa ON u.user_id = msa.student_user_id
                    WHERE u.pepp_course = ? AND u.status IN ('approved','active')
                      AND msa.admin_id = ? AND msa.status = 'active'
                    ORDER BY u.created_at ASC, u.id ASC
                ");
                $stmt->execute([$selected_course_name, $admin_id]);
            }
            $raw_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Prepare bulk inputs
            $bulk_students = [];
            $student_ids = [];
            foreach ($raw_students as $s) {
                $bulk_students[] = [
                    'email' => $s['email'],
                    'user_id' => $s['user_id'],
                    'pepp_academic_year' => $s['pepp_academic_year'],
                    'pepp_course' => $s['course']
                ];
                $uid = trim($s['user_id']);
                if ($uid !== '') {
                    $student_ids[] = $uid;
                }
            }

            // Fetch course analytics in bulk (no N+1 queries!)
            $bulk_analytics = StudentStudyPlanAnalytics::getCourseAnalyticsBulk($pdo, $bulk_students, $selected_course_name);

            // Fetch call logs, remarks counts, and active mentors in bulk
            $last_calls_by_student = [];
            $remarks_count_by_student = [];
            $active_mentors_by_student = [];

            if (!empty($student_ids)) {
                $id_placeholders = implode(',', array_fill(0, count($student_ids), '?'));

                // Fetch last call timestamp
                $call_stmt = $pdo->prepare("
                    SELECT student_user_id, MAX(call_timestamp) as last_call
                    FROM mentor_call_logs
                    WHERE student_user_id IN ($id_placeholders)
                    GROUP BY student_user_id
                ");
                $call_stmt->execute($student_ids);
                $calls = $call_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($calls as $c) {
                    $last_calls_by_student[$c['student_user_id']] = $c['last_call'];
                }

                // Fetch remarks counts
                $remark_stmt = $pdo->prepare("
                    SELECT student_user_id, COUNT(*) as remarks_cnt
                    FROM mentor_remarks
                    WHERE student_user_id IN ($id_placeholders)
                    GROUP BY student_user_id
                ");
                $remark_stmt->execute($student_ids);
                $rems = $remark_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rems as $r) {
                    $remarks_count_by_student[$r['student_user_id']] = (int)$r['remarks_cnt'];
                }

                // Fetch active mentor assignments
                $stmt_m_active = $pdo->prepare("
                    SELECT msa.student_user_id, msa.admin_id, a.username, a.full_name
                    FROM mentor_student_assignments msa
                    JOIN admins a ON msa.admin_id = a.id
                    WHERE msa.status = 'active' AND msa.student_user_id IN ($id_placeholders)
                ");
                $stmt_m_active->execute($student_ids);
                while ($m_row = $stmt_m_active->fetch(PDO::FETCH_ASSOC)) {
                    $active_mentors_by_student[$m_row['student_user_id']] = [
                        'admin_id' => (int)$m_row['admin_id'],
                        'mentor_username' => $m_row['username'],
                        'mentor_name' => $m_row['full_name'] ?: $m_row['username']
                    ];
                }
            }

            $students_with_metrics = [];
            foreach ($raw_students as $s) {
                $email_key = strtolower(trim($s['email']));
                $course_analytics = $bulk_analytics[$email_key] ?? [
                    'completion_percentage' => 0,
                    'attendance_rate' => null,
                    'attended_sessions' => 0,
                    'total_sessions' => 0,
                    'active_streak' => 0,
                    'longest_streak' => 0,
                    'total_plan_calendar_days' => 0,
                    'total_tasks' => 0,
                    'completed_tasks' => 0,
                    'pending_tasks' => 0,
                    'overdue_tasks' => 0
                ];

                $last_call_time = $last_calls_by_student[$s['user_id']] ?? null;
                $last_called_status = 'Never Called';
                if ($last_call_time) {
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

                $remarks_count = $remarks_count_by_student[$s['user_id']] ?? 0;
                $s['active_mentor_id'] = $active_mentors_by_student[$s['user_id']]['admin_id'] ?? null;
                $s['active_mentor_name'] = $active_mentors_by_student[$s['user_id']]['mentor_name'] ?? null;

                $s['metrics'] = [
                    'progress' => $course_analytics['completion_percentage'],
                    'attendance' => $course_analytics['attendance_rate'],
                    'attended_sessions' => $course_analytics['attended_sessions'] ?? 0,
                    'total_sessions' => $course_analytics['total_sessions'] ?? 0,
                    'streak' => $course_analytics['active_streak'],
                    'streak_target' => $course_analytics['longest_streak'],
                    'total_plan_calendar_days' => $course_analytics['total_plan_calendar_days'] ?? 0,
                    'last_call_time' => $last_call_time,
                    'last_called_status' => $last_called_status,
                    'remarks_count' => $remarks_count,
                    'total_tasks' => $course_analytics['total_tasks'],
                    'completed_tasks' => $course_analytics['completed_tasks'],
                    'pending_tasks' => $course_analytics['pending_tasks'],
                    'overdue_tasks' => $course_analytics['overdue_tasks']
                ];
                $students_with_metrics[] = $s;
            }

            // Sort by completion percentage (progress) descending
            usort($students_with_metrics, function($a, $b) {
                return $b['metrics']['progress'] <=> $a['metrics']['progress'];
            });

            $students = $students_with_metrics;
        } catch (Exception $e) {}

        // Load call logs for selected course (Mentors see calls for currently assigned students)
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
                    JOIN mentor_student_assignments msa ON u.user_id = msa.student_user_id
                    WHERE u.pepp_course = ? AND msa.admin_id = ? AND msa.status = 'active'
                    ORDER BY mcl.call_timestamp DESC LIMIT 50
                ");
                $stmt->execute([$selected_course_name, $admin_id]);
            }
            $call_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // Load remarks for selected course (Mentors see remarks for currently assigned students)
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
                    JOIN mentor_student_assignments msa ON u.user_id = msa.student_user_id
                    WHERE u.pepp_course = ? AND msa.admin_id = ? AND msa.status = 'active'
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

<!-- CSS styles for responsive mobile cards and student assignment multi-select -->
<style>
.mentoring-table th {
    font-size: 0.8rem;
    text-transform: uppercase;
    color: var(--text-muted);
}
.assign-student-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    background: var(--card, #fff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.assign-student-item:hover {
    background: var(--card-sub, #f1f5f9);
    border-color: var(--accent, #7c3aed);
}
.assign-student-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: var(--accent, #7c3aed);
}
.timeline-item {
    position: relative;
    padding-left: 20px;
    margin-bottom: 16px;
    border-left: 2px solid var(--border, #e2e8f0);
}
.timeline-item:last-child {
    border-left-color: transparent;
    margin-bottom: 0;
}
.timeline-dot {
    position: absolute;
    left: -7px;
    top: 2px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--accent, #7c3aed);
    border: 2px solid #fff;
}
.timeline-dot.active {
    background: #22c55e;
}
.timeline-dot.inactive {
    background: #94a3b8;
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
        <a href="?tab=assignments<?= $selected_course_id ? '&course_id=' . $selected_course_id : '' ?>" class="btn btn-sm <?php echo $tab==='assignments' ? 'btn-primary' : 'btn-outline'; ?>"><i class="fas fa-user-gear"></i> Assignments (<?php echo count($assignments); ?>)</a>
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
            <h2><?= is_super_admin() ? 'Students' : 'My Students' ?> (<?= e($selected_course_name) ?>)</h2>
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
                        <th>Mentor</th>
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
                    <td data-label="Mentor">
                        <?php if (!empty($s['active_mentor_name'])): ?>
                            <span class="badge blue" style="font-weight:700; font-size:0.75rem;"><i class="fas fa-user-tie"></i> <?= e($s['active_mentor_name']) ?></span>
                        <?php else: ?>
                            <span class="badge gray" style="font-size:0.72rem; color:var(--text-muted);">Not Assigned</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Progress">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                            <div style="flex:1; background:var(--border); height:6px; border-radius:3px; overflow:hidden; min-width:60px;">
                                <div style="background:var(--accent,#7c3aed); width:<?= $m['progress'] ?>%; height:100%;"></div>
                            </div>
                            <span style="font-size:0.75rem; font-weight:700;"><?= $m['progress'] ?>%</span>
                        </div>
                        <?php if ((int)($m['total_sessions'] ?? 0) > 0): ?>
                            <span class="badge green" style="font-size:0.65rem;"><i class="fas fa-chart-line"></i> Assessment Attendance: <?= (int)$m['attended_sessions'] ?>/<?= (int)$m['total_sessions'] ?> (<?= (int)$m['attendance'] ?>%)</span>
                        <?php else: ?>
                            <span class="badge gray" style="font-size:0.65rem;"><i class="fas fa-chart-line"></i> Assessment Attendance: No assessment data</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Streak">
                        <?php
                            $plan_days = (int)($m['total_plan_calendar_days'] ?? 0);
                            $streak_display = $plan_days > 0 ? ($m['streak'] . ' / ' . $plan_days . ' Days') : ($m['streak'] . ' / 0 Days');
                        ?>
                        <span style="font-weight:700; color:#b45309; font-size:0.85rem;" title="Current Streak: <?= $m['streak'] ?> Days | Longest Streak: <?= $m['streak_target'] ?> Days | Plan Duration: <?= $plan_days ?> Days">🔥 <?= $streak_display ?></span>
                    </td>
                    <td data-label="Last Call">
                        <div class="cell-sub" style="font-size:0.8rem;">
                            <?= $m['last_call_time'] ? date('d M Y, h:i A', strtotime($m['last_call_time'])) : 'Never' ?><br>
                            <span class="badge <?= $m['last_call_time'] ? 'blue' : 'gray' ?>" style="font-size:0.65rem; margin-top:2px; display:inline-block;"><?= $m['last_called_status'] ?></span>
                        </div>
                    </td>
                    <td class="actions-cell" style="text-align:right; white-space:nowrap;">
                        <a href="student-study-reports.php?source=mentoring&student_id=<?= urlencode($s['user_id']) ?>" target="_blank" class="btn btn-sm btn-soft-violet" title="View Student Report"><i class="fas fa-chart-line"></i> Report</a>
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
                            <a href="student-study-reports.php?source=mentoring&student_id=<?php echo urlencode($cl['student_user_id']); ?>" target="_blank" style="color:var(--accent); font-weight:700; text-decoration:none;">
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
                            <a href="student-study-reports.php?source=mentoring&student_id=<?php echo urlencode($rm['student_user_id']); ?>" target="_blank" style="color:var(--accent); font-weight:700; text-decoration:none;">
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
<!-- Mentor Student Assignments (Super Admin) -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--blue-soft);color:var(--blue-ink);"><i class="fas fa-user-gear"></i></span>
        <h2>Student Mentor Assignments <?= $selected_course_name ? '(' . e($selected_course_name) . ')' : '' ?></h2>
        <div class="head-right">
            <button class="btn btn-sm btn-primary" onclick="openAssignModal()"><i class="fas fa-user-plus"></i> Assign Students</button>
        </div>
    </div>

    <!-- Filters Toolbar for Assignments -->
    <div class="panel-body" style="padding:15px; border-bottom:1px solid var(--border); background:#f8fafc;">
        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <!-- Search Box -->
            <div style="flex-grow:1; min-width:200px; position:relative;">
                <i class="fas fa-search" style="position:absolute; left:12px; top:12px; color:var(--text-muted); font-size:0.85rem;"></i>
                <input type="text" id="assignment-search-input" onkeyup="applyAssignmentFilters()" placeholder="Search student name, ID, mentor, course..." style="width:100%; padding:8px 12px 8px 34px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text);">
            </div>
            <!-- Status Filter -->
            <select id="filter-assignment-status" onchange="applyAssignmentFilters()" style="padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-size:0.85rem; background:#fff; color:var(--text); cursor:pointer;">
                <option value="ALL">All Statuses (Active &amp; Inactive)</option>
                <option value="ACTIVE" selected>Active Only</option>
                <option value="INACTIVE">Inactive / Past Only</option>
            </select>
        </div>
    </div>

    <div class="panel-body flush table-wrap">
        <?php if (empty($assignments)): ?>
            <div style="padding:2.5rem;text-align:center;color:var(--text-muted);">
                <div style="font-size:2.5rem;margin-bottom:8px;">👥</div>
                <h3>No student mentor assignments</h3>
                <p style="font-size:0.85rem;">Use the button above to assign students to mentors.</p>
            </div>
        <?php else: ?>
        <table class="data-table mentoring-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Course</th>
                    <th>Mentor</th>
                    <th>Status</th>
                    <th>Assigned By</th>
                    <th>Assigned At</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assignments as $as):
                $isActive = ($as['status'] === 'active');
                $mentorName = $as['mentor_full_name'] ?: $as['mentor_username'];
            ?>
            <tr class="assignment-row"
                data-student="<?= e(strtolower($as['student_name'] ?? '')) ?>"
                data-student-id="<?= e(strtolower($as['student_user_id'])) ?>"
                data-mentor="<?= e(strtolower($mentorName)) ?>"
                data-course="<?= e(strtolower($as['course_name'])) ?>"
                data-status="<?= e(strtoupper($as['status'])) ?>">
                <td data-label="Student">
                    <div class="cell-main"><?= e($as['student_name'] ?? 'Unknown Student') ?></div>
                    <div class="cell-sub"><span class="badge gray" style="font-size:0.7rem;"><?= e($as['student_user_id']) ?></span> · <?= htmlspecialchars(format_credential_text($as['student_email'] ?? '', 'email', 'students'), ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td data-label="Course">
                    <span class="badge blue"><?= e($as['course_name']) ?></span>
                </td>
                <td data-label="Mentor">
                    <span class="badge blue" style="font-weight:700;"><i class="fas fa-user-tie"></i> <?= e($mentorName) ?></span>
                </td>
                <td data-label="Status">
                    <?php if ($isActive): ?>
                        <span class="badge green"><i class="fas fa-check-circle"></i> Active</span>
                    <?php else: ?>
                        <span class="badge gray">Inactive</span>
                    <?php endif; ?>
                </td>
                <td data-label="Assigned By" class="cell-sub">
                    <?= e($as['assigned_by']) ?>
                </td>
                <td data-label="Assigned At" class="cell-sub">
                    <?= $as['assigned_at'] ? date('d M Y, h:i A', strtotime($as['assigned_at'])) : '—' ?>
                    <?php if (!$isActive && $as['ended_at']): ?>
                        <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">Ended: <?= date('d M Y, h:i A', strtotime($as['ended_at'])) ?></div>
                    <?php endif; ?>
                </td>
                <td class="actions-cell" style="text-align:right; white-space:nowrap;">
                    <button type="button" class="btn btn-sm btn-soft-violet" onclick="openHistoryModal('<?= e($as['student_user_id']) ?>', '<?= e($as['student_name'] ?? '') ?>')" title="Assignment History"><i class="fas fa-history"></i> History</button>
                    <?php if ($isActive): ?>
                        <button type="button" class="btn btn-sm btn-soft-blue" onclick="openReassignModal('<?= e($as['student_user_id']) ?>', '<?= e($as['course_name']) ?>', <?= (int)$as['admin_id'] ?>)" title="Reassign Mentor"><i class="fas fa-exchange-alt"></i> Reassign</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Unassign mentor for <?= e($as['student_name'] ?? $as['student_user_id']) ?>? The assignment will be closed while preserving history.');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="unassign_student">
                            <input type="hidden" name="student_user_id" value="<?= e($as['student_user_id']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline" style="color:var(--red-ink); border-color:var(--border);" title="Unassign"><i class="fas fa-user-xmark"></i></button>
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

<!-- Assign Modal -->
<div id="assignModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:540px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);">
    <h3 style="margin-bottom:1rem;display:flex;align-items:center;gap:8px;"><i class="fas fa-user-plus" style="color:var(--accent,#7c3aed);"></i> Assign Students to Mentor</h3>
    <form method="POST" id="assignStudentsForm">
        <?= csrf_field(); ?>
        <input type="hidden" name="action" value="assign_students">
        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Admin / Mentor *</label>
            <select name="mentor_admin_id" id="assignMentorAdminId" required style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:.85rem;">
                <option value="">— Select Admin/Mentor —</option>
                <?php foreach ($all_admins as $adm): ?>
                <option value="<?= $adm['id']; ?>"><?= e($adm['username']); ?> (<?= e($adm['full_name'] ?? ''); ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:4px;">Course *</label>
            <select name="course_name" id="assignCourseName" required onchange="loadCourseStudentsForAssignment(this.value)" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:.85rem;">
                <option value="">— Select Course —</option>
                <?php foreach ($all_courses as $c): ?>
                <option value="<?= e($c); ?>"><?= e($c); ?></option>
                <?php endforeach; ?>
            </select>
            <div style="font-size:.7rem;color:var(--text-muted);margin-top:4px;">Selecting a course dynamically loads its enrolled students ordered by first joined.</div>
        </div>

        <!-- Student Selector Section -->
        <div id="assignStudentsContainer" style="display:none; margin-bottom:14px;">
            <label style="display:block;font-size:.8rem;font-weight:600;margin-bottom:6px;">Select Students *</label>
            <input type="text" id="assignStudentSearch" placeholder="🔍 Search students by name, ID, mentor..." onkeyup="filterAssignStudentsList()" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:.85rem;margin-bottom:8px;">

            <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 2px;margin-bottom:4px;">
                <label style="font-size:0.75rem;font-weight:600;display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text);">
                    <input type="checkbox" id="assignSelectAllCheckbox" onchange="toggleSelectAllAssignStudents(this.checked)"> Select All (<span id="assignVisibleCount">0</span>)
                </label>
                <span id="assignSelectedCountBadge" class="badge blue" style="font-size:0.72rem;">0 selected</span>
            </div>

            <div id="assignStudentList" style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;padding:6px;background:var(--card-sub,#f8fafc);border:1px solid var(--border);border-radius:8px;"></div>

            <!-- Reassignment Warning Banner -->
            <div class="alert alert-warn" id="reassignWarningBanner" style="display:none;margin-top:10px;padding:8px 12px;font-size:0.75rem;">
                <i class="fas fa-triangle-exclamation"></i>
                <span><strong>Reassignment notice:</strong> <span id="reassignCountText">0</span> student(s) currently have an active mentor. Reassigning will close previous assignments and create new active ones, while permanently preserving all mentoring history.</span>
            </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('assignModal')">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" id="btnSubmitAssign"><i class="fas fa-check"></i> Assign Selected Students</button>
        </div>
    </form>
</div>
</div>

<!-- History Modal -->
<div id="historyModal" class="modal-backdrop">
<div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.6rem;max-width:480px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 10px 10px -5px rgba(0,0,0,0.04);">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.6rem;">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px;"><i class="fas fa-history" style="color:var(--accent,#7c3aed);"></i> Assignment History</h3>
        <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('historyModal')" style="padding:2px 8px;font-size:0.8rem;">✕</button>
    </div>
    <p id="historyStudentTitle" style="color:var(--text-muted);font-size:.8rem;margin-bottom:1rem;"></p>
    <div id="historyTimelineContent" style="max-height:340px;overflow-y:auto;padding-right:4px;"></div>
    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-sm btn-outline" onclick="closeModal('historyModal')">Close</button>
    </div>
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

// Assignment Modal functions
function openAssignModal() {
    if (document.getElementById('assignMentorAdminId')) document.getElementById('assignMentorAdminId').value = '';
    if (document.getElementById('assignCourseName')) document.getElementById('assignCourseName').value = '';
    if (document.getElementById('assignStudentsContainer')) document.getElementById('assignStudentsContainer').style.display = 'none';
    if (document.getElementById('assignStudentList')) document.getElementById('assignStudentList').innerHTML = '';
    if (document.getElementById('reassignWarningBanner')) document.getElementById('reassignWarningBanner').style.display = 'none';
    if (document.getElementById('assignSelectAllCheckbox')) document.getElementById('assignSelectAllCheckbox').checked = false;
    updateAssignSelectedSummary();
    openModal('assignModal');
}

function openReassignModal(studentUserId, courseName, currentMentorId) {
    openModal('assignModal');
    if (document.getElementById('assignMentorAdminId')) document.getElementById('assignMentorAdminId').value = '';
    if (document.getElementById('assignCourseName')) document.getElementById('assignCourseName').value = courseName;
    loadCourseStudentsForAssignment(courseName, studentUserId);
}

function loadCourseStudentsForAssignment(courseName, preSelectUserId = null) {
    const container = document.getElementById('assignStudentsContainer');
    const list = document.getElementById('assignStudentList');
    const search = document.getElementById('assignStudentSearch');
    const warning = document.getElementById('reassignWarningBanner');
    if (!container || !list) return;

    if (!courseName) {
        container.style.display = 'none';
        list.innerHTML = '';
        if (warning) warning.style.display = 'none';
        updateAssignSelectedSummary();
        return;
    }
    container.style.display = 'block';
    list.innerHTML = '<div style="font-size:0.75rem; color:var(--text-muted); text-align:center; padding:12px;"><i class="fas fa-spinner fa-spin"></i> Loading students for ' + escapeHtmlJS(courseName) + '...</div>';
    if (search) search.value = '';

    fetch(`student-mentoring.php?get_course_students=1&course_name=${encodeURIComponent(courseName)}`)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.students) {
                if (res.students.length === 0) {
                    list.innerHTML = '<div style="font-size:0.75rem; color:var(--text-muted); text-align:center; padding:12px;">No approved/active students found in this course.</div>';
                    if (document.getElementById('assignVisibleCount')) document.getElementById('assignVisibleCount').textContent = '0';
                    updateAssignSelectedSummary();
                    return;
                }

                let html = '';
                res.students.forEach(st => {
                    const isPreselected = (preSelectUserId && st.user_id === preSelectUserId);
                    const mentorBadge = st.has_mentor
                        ? `<span class="badge amber" style="font-size:0.65rem; margin-left:4px;" title="Currently assigned to ${escapeHtmlJS(st.current_mentor_name)}"><i class="fas fa-user-tie"></i> ${escapeHtmlJS(st.current_mentor_name)} (REASSIGNMENT)</span>`
                        : `<span class="badge gray" style="font-size:0.65rem; margin-left:4px;">Not Assigned</span>`;

                    html += `
                        <label class="assign-student-item" data-id="${escapeHtmlJS(st.user_id.toLowerCase())}" data-name="${escapeHtmlJS(st.full_name.toLowerCase())}" data-has-mentor="${st.has_mentor ? '1' : '0'}" data-mentor-name="${escapeHtmlJS(st.current_mentor_name)}">
                            <input type="checkbox" name="student_user_ids[]" value="${escapeHtmlJS(st.user_id)}" onchange="onAssignStudentCheckChange()" ${isPreselected ? 'checked' : ''}>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                                    <strong style="font-size:0.85rem; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtmlJS(st.full_name)}</strong>
                                    <span class="badge gray" style="font-size:0.7rem; flex-shrink:0;">${escapeHtmlJS(st.user_id)}</span>
                                </div>
                                <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px; display:flex; flex-wrap:wrap; align-items:center; gap:4px;">
                                    <span>Joined: ${escapeHtmlJS(st.joined_date)}</span> ·
                                    <span>Mentor:</span> ${mentorBadge}
                                </div>
                            </div>
                        </label>
                    `;
                });
                list.innerHTML = html;
                if (document.getElementById('assignVisibleCount')) document.getElementById('assignVisibleCount').textContent = res.students.length;
                updateAssignSelectedSummary();
            } else {
                list.innerHTML = '<div style="font-size:0.75rem; color:var(--red-ink); text-align:center; padding:12px;">' + escapeHtmlJS(res.error || 'Failed to load students') + '</div>';
                updateAssignSelectedSummary();
            }
        })
        .catch(err => {
            list.innerHTML = '<div style="font-size:0.75rem; color:var(--red-ink); text-align:center; padding:12px;">Network error loading students.</div>';
            updateAssignSelectedSummary();
        });
}

function filterAssignStudentsList() {
    const q = document.getElementById('assignStudentSearch') ? document.getElementById('assignStudentSearch').value.toLowerCase().trim() : '';
    const items = document.querySelectorAll('.assign-student-item');
    let visibleCount = 0;
    items.forEach(item => {
        const id = item.dataset.id || '';
        const name = item.dataset.name || '';
        const mentor = (item.dataset.mentorName || '').toLowerCase();
        let show = true;
        if (q && !id.includes(q) && !name.includes(q) && !mentor.includes(q)) {
            show = false;
        }
        item.style.display = show ? 'flex' : 'none';
        if (show) visibleCount++;
    });
    const countEl = document.getElementById('assignVisibleCount');
    if (countEl) countEl.textContent = visibleCount;
}

function toggleSelectAllAssignStudents(checked) {
    const items = document.querySelectorAll('.assign-student-item');
    items.forEach(item => {
        if (item.style.display !== 'none') {
            const cb = item.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = checked;
        }
    });
    updateAssignSelectedSummary();
}

function onAssignStudentCheckChange() {
    updateAssignSelectedSummary();
}

function updateAssignSelectedSummary() {
    const checkboxes = document.querySelectorAll('#assignStudentList input[type="checkbox"]:checked');
    const totalSelected = checkboxes.length;
    const badge = document.getElementById('assignSelectedCountBadge');
    if (badge) {
        badge.textContent = totalSelected + ' selected';
    }

    let reassignCount = 0;
    checkboxes.forEach(cb => {
        const item = cb.closest('.assign-student-item');
        if (item && item.dataset.hasMentor === '1') {
            reassignCount++;
        }
    });

    const banner = document.getElementById('reassignWarningBanner');
    const bannerText = document.getElementById('reassignCountText');
    if (banner && bannerText) {
        if (reassignCount > 0) {
            bannerText.textContent = reassignCount;
            banner.style.display = 'flex';
        } else {
            banner.style.display = 'none';
        }
    }
}

// Assignment History Modal
function openHistoryModal(studentUserId, studentName) {
    if (document.getElementById('historyStudentTitle')) {
        document.getElementById('historyStudentTitle').textContent = 'Student: ' + (studentName ? studentName + ' ' : '') + '(' + studentUserId + ')';
    }
    const content = document.getElementById('historyTimelineContent');
    if (content) {
        content.innerHTML = '<div style="font-size:0.75rem; color:var(--text-muted); text-align:center; padding:16px;"><i class="fas fa-spinner fa-spin"></i> Loading assignment history...</div>';
    }
    openModal('historyModal');

    fetch(`student-mentoring.php?get_student_assignment_history=${encodeURIComponent(studentUserId)}`)
        .then(r => r.json())
        .then(res => {
            if (!content) return;
            if (res.success && res.history) {
                if (res.history.length === 0) {
                    content.innerHTML = '<div style="font-size:0.8rem; color:var(--text-muted); text-align:center; padding:16px;">No mentor assignments found for this student.</div>';
                    return;
                }
                let html = '<div style="padding:4px 0;">';
                res.history.forEach(h => {
                    const isActive = (h.status === 'active');
                    const dotClass = isActive ? 'active' : 'inactive';
                    const statusBadge = isActive
                        ? '<span class="badge green" style="font-size:0.7rem; font-weight:700;"><i class="fas fa-check-circle"></i> Active</span>'
                        : '<span class="badge gray" style="font-size:0.7rem;">Inactive</span>';
                    const period = isActive
                        ? `${escapeHtmlJS(h.assigned_at)} → Present`
                        : `${escapeHtmlJS(h.assigned_at)} → ${escapeHtmlJS(h.ended_at || 'Ended')}`;

                    html += `
                        <div class="timeline-item">
                            <div class="timeline-dot ${dotClass}"></div>
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                                <div>
                                    <div style="font-size:0.9rem; font-weight:700; color:var(--text);"><i class="fas fa-user-tie"></i> ${escapeHtmlJS(h.mentor_name)}</div>
                                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Course: <strong style="color:var(--text);">${escapeHtmlJS(h.course_name)}</strong></div>
                                </div>
                                <div>${statusBadge}</div>
                            </div>
                            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:6px; background:var(--card-sub, #f8fafc); padding:6px 10px; border-radius:6px; border:1px solid var(--border);">
                                <div><i class="fas fa-calendar-alt"></i> <strong>Period:</strong> ${period}</div>
                                <div style="margin-top:2px;"><i class="fas fa-user-shield"></i> <strong>Assigned by:</strong> ${escapeHtmlJS(h.assigned_by)}</div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                content.innerHTML = html;
            } else {
                content.innerHTML = '<div style="font-size:0.8rem; color:var(--red-ink); text-align:center; padding:16px;">' + escapeHtmlJS(res.error || 'Failed to load history') + '</div>';
            }
        })
        .catch(err => {
            if (content) {
                content.innerHTML = '<div style="font-size:0.8rem; color:var(--red-ink); text-align:center; padding:16px;">Network error loading history.</div>';
            }
        });
}

function applyAssignmentFilters() {
    const q = document.getElementById('assignment-search-input') ? document.getElementById('assignment-search-input').value.toLowerCase().trim() : '';
    const status = document.getElementById('filter-assignment-status') ? document.getElementById('filter-assignment-status').value : 'ALL';

    const rows = document.querySelectorAll('.assignment-row');
    rows.forEach(row => {
        const student = (row.dataset.student || '').toLowerCase();
        const studentId = (row.dataset.studentId || '').toLowerCase();
        const mentor = (row.dataset.mentor || '').toLowerCase();
        const course = (row.dataset.course || '').toLowerCase();
        const rowStatus = (row.dataset.status || '').toUpperCase();

        let show = true;
        if (q && !student.includes(q) && !studentId.includes(q) && !mentor.includes(q) && !course.includes(q)) {
            show = false;
        }
        if (show && status !== 'ALL' && rowStatus !== status) {
            show = false;
        }
        row.style.display = show ? '' : 'none';
    });
}

// Escape key to close open modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['callModal', 'remarkModal', 'assignModal', 'historyModal', 'editCallModal', 'editRemarkModal'].forEach(id => {
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
