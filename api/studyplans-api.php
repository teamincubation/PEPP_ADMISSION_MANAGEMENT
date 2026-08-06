<?php
session_start();
header('Content-Type: application/json');

// Check authorization
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../config/database.php';
require_once '../includes/auth.php';

if (!can_access('studyplans')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden access']);
    exit();
}

$action = $_REQUEST['action'] ?? '';
$admin_username = $_SESSION['admin_username'] ?? 'Admin';

try {
    if ($action === 'save_plan') {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        
        $id = (int)($data['id'] ?? 0);
        $title = trim($data['title'] ?? 'New Study Plan');
        $academic_year = trim($data['academic_year'] ?? '');
        $course_id = !empty($data['course_id']) ? (int)$data['course_id'] : null;
        $description = trim($data['description'] ?? '');
        $cover_image = trim($data['cover_image'] ?? '');
        $theme = trim($data['theme'] ?? 'default');
        $layout = trim($data['layout'] ?? 'timeline');
        $start_date = trim($data['start_date'] ?? '');
        $end_date = trim($data['end_date'] ?? '');
        $status = trim($data['status'] ?? 'draft');
        $is_template = isset($data['is_template']) ? (int)$data['is_template'] : 0;
        
        $plan_type = trim($data['plan_type'] ?? 'date_wise');
        $total_days = !empty($data['total_days']) ? (int)$data['total_days'] : null;
        
        $custom_settings = isset($data['custom_settings']) ? json_encode($data['custom_settings']) : null;
        
        if (empty($title) || empty($academic_year) || empty($start_date) || empty($end_date)) {
            echo json_encode(['success' => false, 'message' => 'Title, Academic Year, Start Date, and End Date are required.']);
            exit();
        }
        
        $pdo->beginTransaction();
        
        if ($id > 0) {
            // Update plan
            $stmt = $pdo->prepare("
                UPDATE study_plans SET
                    title = ?, academic_year = ?, course_id = ?, description = ?,
                    cover_image = ?, theme = ?, layout = ?, start_date = ?, end_date = ?,
                    status = ?, is_template = ?, custom_settings = ?, plan_type = ?, total_days = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $title, $academic_year, $course_id, $description,
                $cover_image, $theme, $layout, $start_date, $end_date,
                $status, $is_template, $custom_settings, $plan_type, $total_days, $id
            ]);
            $plan_id = $id;
            
            // Log audit
            $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'update_plan', ?)");
            $stmt_audit->execute([$plan_id, $admin_username, "Updated study plan: '{$title}'"]);
        } else {
            // Insert plan
            $stmt = $pdo->prepare("
                INSERT INTO study_plans (
                    title, academic_year, course_id, description,
                    cover_image, theme, layout, start_date, end_date,
                    status, is_template, custom_settings, plan_type, total_days, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $title, $academic_year, $course_id, $description,
                $cover_image, $theme, $layout, $start_date, $end_date,
                $status, $is_template, $custom_settings, $plan_type, $total_days, $admin_username
            ]);
            $plan_id = $pdo->lastInsertId();
            
            // Log audit
            $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'create_plan', ?)");
            $stmt_audit->execute([$plan_id, $admin_username, "Created new study plan: '{$title}'"]);
        }
        
        // Auto-generate missing daily dates between start_date and end_date
        $curr = new DateTime($start_date);
        $end = new DateTime($end_date);
        $day_num = 1;
        
        // Fetch existing activity dates for this plan to prevent duplicates
        $stmt_exists = $pdo->prepare("SELECT DISTINCT activity_date FROM study_plan_activities WHERE study_plan_id = ?");
        $stmt_exists->execute([$plan_id]);
        $existing_dates = $stmt_exists->fetchAll(PDO::FETCH_COLUMN);
        
        $stmt_insert_day = $pdo->prepare("
            INSERT INTO study_plan_activities (study_plan_id, activity_date, day_number, sort_order, activity_title, activity_type)
            VALUES (?, ?, ?, 0, 'Rest Day / Self Study', 'Revision')
        ");
        
        while ($curr <= $end) {
            $formatted_date = $curr->format('Y-m-d');
            if (!in_array($formatted_date, $existing_dates)) {
                $stmt_insert_day->execute([$plan_id, $formatted_date, $day_num]);
            }
            $curr->modify('+1 day');
            $day_num++;
        }
        
        // Save assignments (course, batch, students etc.)
        if (isset($data['assignments']) && is_array($data['assignments'])) {
            $pdo->prepare("DELETE FROM study_plan_assignments WHERE study_plan_id = ?")->execute([$plan_id]);
            $stmt_assign = $pdo->prepare("INSERT INTO study_plan_assignments (study_plan_id, assignment_type, assigned_value, created_at) VALUES (?, ?, ?, NOW())");
            foreach ($data['assignments'] as $assign) {
                if (!empty($assign['type']) && !empty($assign['value'])) {
                    $stmt_assign->execute([$plan_id, $assign['type'], $assign['value']]);
                }
            }
        }
        
        $pdo->commit();
        
        log_admin_activity($pdo, $admin_username, 'studyplan_saved', "Saved study plan #{$plan_id} '{$title}'");
        
        echo json_encode(['success' => true, 'plan_id' => $plan_id, 'message' => 'Study plan saved successfully.']);
        exit();
    }
    
    if ($action === 'duplicate_plan') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
            exit();
        }
        
        $pdo->beginTransaction();
        
        // Fetch plan
        $stmt = $pdo->prepare("SELECT * FROM study_plans WHERE id = ?");
        $stmt->execute([$id]);
        $plan = $stmt->fetch();
        if (!$plan) {
            echo json_encode(['success' => false, 'message' => 'Study plan not found']);
            exit();
        }
        
        // Copy plan
        $title = $plan['title'] . ' (Copy)';
        $stmt_ins = $pdo->prepare("
            INSERT INTO study_plans (
                title, academic_year, course_id, description,
                cover_image, theme, layout, start_date, end_date,
                status, is_template, custom_settings, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt_ins->execute([
            $title, $plan['academic_year'], $plan['course_id'], $plan['description'],
            $plan['cover_image'], $plan['theme'], $plan['layout'], $plan['start_date'], $plan['end_date'],
            'draft', $plan['is_template'], $plan['custom_settings'], $admin_username
        ]);
        $new_id = $pdo->lastInsertId();
        
        // Copy activities
        $stmt_act = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? ORDER BY activity_date ASC, sort_order ASC");
        $stmt_act->execute([$id]);
        $activities = $stmt_act->fetchAll();
        
        $stmt_act_ins = $pdo->prepare("
            INSERT INTO study_plan_activities (
                study_plan_id, activity_date, day_number, sort_order, chapter, subject,
                topic, subtopic, activity_title, activity_description, activity_type,
                faculty, mentor, estimated_duration, priority, difficulty_level, resource_links,
                custom_activity_badge, custom_activity_color, custom_activity_icon
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($activities as $act) {
            $stmt_act_ins->execute([
                $new_id, $act['activity_date'], $act['day_number'], $act['sort_order'], $act['chapter'], $act['subject'],
                $act['topic'], $act['subtopic'], $act['activity_title'], $act['activity_description'], $act['activity_type'],
                $act['faculty'], $act['mentor'], $act['estimated_duration'], $act['priority'], $act['difficulty_level'], $act['resource_links'],
                $act['custom_activity_badge'], $act['custom_activity_color'], $act['custom_activity_icon']
            ]);
        }
        
        // Audit
        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'duplicate_plan', ?)");
        $stmt_audit->execute([$id, $admin_username, "Duplicated study plan #{$id} into #{$new_id}"]);
        
        $pdo->commit();
        
        log_admin_activity($pdo, $admin_username, 'studyplan_duplicated', "Duplicated study plan #{$id} to #{$new_id}");
        
        echo json_encode(['success' => true, 'new_id' => $new_id]);
        exit();
    }
    
    if ($action === 'archive_plan') {
        $id = (int)($_POST['id'] ?? 0);
        $archive = isset($_POST['archive']) ? (int)$_POST['archive'] : 1;
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
            exit();
        }
        
        $status = $archive ? 'archived' : 'draft';
        $stmt = $pdo->prepare("UPDATE study_plans SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $id]);
        
        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'archive_plan', ?)");
        $stmt_audit->execute([$id, $admin_username, $archive ? "Archived study plan" : "Restored study plan to draft"]);
        
        log_admin_activity($pdo, $admin_username, 'studyplan_archived', ($archive ? "Archived" : "Restored") . " study plan #{$id}");
        
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($action === 'delete_plan') {
        $id = (int)($_POST['id'] ?? 0);
        $confirm = trim($_POST['confirm'] ?? '');
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
            exit();
        }
        
        if ($confirm !== 'DELETE') {
            echo json_encode(['success' => false, 'message' => 'Please type "DELETE" to confirm.']);
            exit();
        }
        
        // Fetch title for activity log
        $stmt = $pdo->prepare("SELECT title FROM study_plans WHERE id = ?");
        $stmt->execute([$id]);
        $title = $stmt->fetchColumn() ?: 'Plan #' . $id;
        
        $stmt = $pdo->prepare("DELETE FROM study_plans WHERE id = ?");
        $stmt->execute([$id]);
        
        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (admin_username, action, details) VALUES (?, 'delete_plan', ?)");
        $stmt_audit->execute([$admin_username, "Deleted study plan: '{$title}'"]);
        
        log_admin_activity($pdo, $admin_username, 'studyplan_deleted', "Deleted study plan #{$id} '{$title}'");
        
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($action === 'save_activities') {
        $data = json_decode(file_get_contents('php://input'), true);
        $plan_id = (int)($data['study_plan_id'] ?? 0);
        $activities = $data['activities'] ?? [];
        
        if ($plan_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Study Plan ID']);
            exit();
        }
        
        $pdo->beginTransaction();
        
        // Delete all current activities and insert fresh updated listing
        // This makes updating, dragging-and-dropping and reordering completely synchronized!
        $stmt_del = $pdo->prepare("DELETE FROM study_plan_activities WHERE study_plan_id = ?");
        $stmt_del->execute([$plan_id]);
        
        $stmt_ins = $pdo->prepare("
            INSERT INTO study_plan_activities (
                study_plan_id, activity_date, day_number, sort_order, chapter, subject,
                topic, subtopic, activity_title, activity_description, activity_type,
                faculty, mentor, estimated_duration, priority, difficulty_level, resource_links,
                custom_activity_badge, custom_activity_color, custom_activity_icon
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($activities as $act) {
            $stmt_ins->execute([
                $plan_id,
                $act['activity_date'],
                (int)$act['day_number'],
                (int)$act['sort_order'],
                trim($act['chapter'] ?? ''),
                trim($act['subject'] ?? ''),
                trim($act['topic'] ?? ''),
                trim($act['subtopic'] ?? ''),
                trim($act['activity_title'] ?? 'Self Study'),
                trim($act['activity_description'] ?? ''),
                trim($act['activity_type'] ?? 'Revision'),
                trim($act['faculty'] ?? ''),
                trim($act['mentor'] ?? ''),
                !empty($act['estimated_duration']) ? (int)$act['estimated_duration'] : null,
                trim($act['priority'] ?? 'medium'),
                trim($act['difficulty_level'] ?? 'medium'),
                trim($act['resource_links'] ?? ''),
                trim($act['custom_activity_badge'] ?? ''),
                trim($act['custom_activity_color'] ?? ''),
                trim($act['custom_activity_icon'] ?? '')
            ]);
        }
        
        // Update plan version
        $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = NOW() WHERE id = ?")->execute([$plan_id]);
        
        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'save_activities', ?)");
        $stmt_audit->execute([$plan_id, $admin_username, "Saved and reordered study plan activities (Count: " . count($activities) . ")"]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($action === 'save_custom_activity_type') {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? 'fa-book-open');
        $color = trim($_POST['color'] ?? '#E8980C');
        $badge = trim($_POST['badge'] ?? '');
        
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Activity Name is required.']);
            exit();
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO study_plan_custom_types (name, icon, color, badge, created_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE icon = VALUES(icon), color = VALUES(color), badge = VALUES(badge)
        ");
        $stmt->execute([$name, $icon, $color, $badge, $admin_username]);
        
        echo json_encode(['success' => true, 'message' => 'Custom activity type saved successfully.']);
        exit();
    }
    
    if ($action === 'import_activities') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Please upload a valid CSV/Excel file.']);
            exit();
        }
        
        $file_tmp = $_FILES['file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'csv') {
            echo json_encode(['success' => false, 'message' => 'Only CSV file format is supported currently.']);
            exit();
        }
        
        $rows = [];
        if (($handle = fopen($file_tmp, "r")) !== FALSE) {
            // Read headers
            $headers = fgetcsv($handle, 1000, ",");
            // clean headers
            $headers = array_map(function($h) { return strtolower(trim(str_replace([' ', '_', '.'], '', $h))); }, $headers);
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $rows[] = $data;
            }
            fclose($handle);
        }
        
        // Auto-mapping columns
        $mapping = [
            'date' => -1,
            'day' => -1,
            'chapter' => -1,
            'subject' => -1,
            'topic' => -1,
            'subtopic' => -1,
            'title' => -1,
            'description' => -1,
            'type' => -1,
            'faculty' => -1,
            'mentor' => -1,
            'duration' => -1,
            'priority' => -1,
            'difficulty' => -1,
            'resource' => -1
        ];
        
        foreach ($headers as $index => $h) {
            if (strpos($h, 'date') !== false) $mapping['date'] = $index;
            elseif (strpos($h, 'day') !== false) $mapping['day'] = $index;
            elseif (strpos($h, 'chap') !== false) $mapping['chapter'] = $index;
            elseif (strpos($h, 'subj') !== false) $mapping['subject'] = $index;
            elseif (strpos($h, 'top') !== false) $mapping['topic'] = $index;
            elseif (strpos($h, 'subt') !== false) $mapping['subtopic'] = $index;
            elseif (strpos($h, 'titl') !== false || strpos($h, 'name') !== false) $mapping['title'] = $index;
            elseif (strpos($h, 'desc') !== false) $mapping['description'] = $index;
            elseif (strpos($h, 'type') !== false || strpos($h, 'act') !== false) $mapping['type'] = $index;
            elseif (strpos($h, 'fac') !== false) $mapping['faculty'] = $index;
            elseif (strpos($h, 'ment') !== false) $mapping['mentor'] = $index;
            elseif (strpos($h, 'dur') !== false || strpos($h, 'time') !== false) $mapping['duration'] = $index;
            elseif (strpos($h, 'prio') !== false) $mapping['priority'] = $index;
            elseif (strpos($h, 'diff') !== false) $mapping['difficulty'] = $index;
            elseif (strpos($h, 'link') !== false || strpos($h, 'res') !== false) $mapping['resource'] = $index;
        }
        
        // Parse rows and validate
        $parsed = [];
        $errors = [];
        $row_index = 2; // CSV is 1-indexed, headers are row 1
        
        foreach ($rows as $r) {
            $date_val = $mapping['date'] >= 0 ? trim($r[$mapping['date']]) : '';
            $title_val = $mapping['title'] >= 0 ? trim($r[$mapping['title']]) : 'Self Study';
            $type_val = $mapping['type'] >= 0 ? trim($r[$mapping['type']]) : 'Revision';
            
            // Format check on date
            $date_clean = '';
            if (!empty($date_val)) {
                $time_chk = strtotime($date_val);
                if ($time_chk) {
                    $date_clean = date('Y-m-d', $time_chk);
                } else {
                    $errors[] = "Row {$row_index}: Invalid date format '{$date_val}'.";
                }
            }
            
            $parsed[] = [
                'activity_date' => $date_clean,
                'day_number' => $mapping['day'] >= 0 ? (int)$r[$mapping['day']] : 1,
                'chapter' => $mapping['chapter'] >= 0 ? trim($r[$mapping['chapter']]) : '',
                'subject' => $mapping['subject'] >= 0 ? trim($r[$mapping['subject']]) : '',
                'topic' => $mapping['topic'] >= 0 ? trim($r[$mapping['topic']]) : '',
                'subtopic' => $mapping['subtopic'] >= 0 ? trim($r[$mapping['subtopic']]) : '',
                'activity_title' => $title_val,
                'activity_description' => $mapping['description'] >= 0 ? trim($r[$mapping['description']]) : '',
                'activity_type' => $type_val,
                'faculty' => $mapping['faculty'] >= 0 ? trim($r[$mapping['faculty']]) : '',
                'mentor' => $mapping['mentor'] >= 0 ? trim($r[$mapping['mentor']]) : '',
                'estimated_duration' => $mapping['duration'] >= 0 ? (int)$r[$mapping['duration']] : 60,
                'priority' => $mapping['priority'] >= 0 ? strtolower(trim($r[$mapping['priority']])) : 'medium',
                'difficulty_level' => $mapping['difficulty'] >= 0 ? strtolower(trim($r[$mapping['difficulty']])) : 'medium',
                'resource_links' => $mapping['resource'] >= 0 ? trim($r[$mapping['resource']]) : ''
            ];
            
            $row_index++;
        }
        
        echo json_encode([
            'success' => count($errors) === 0,
            'parsed' => $parsed,
            'errors' => $errors,
            'message' => count($errors) === 0 ? 'File parsed successfully' : 'Validation errors occurred'
        ]);
        exit();
    }
    
    if ($action === 'send_email_notification') {
        $plan_id = (int)($_POST['plan_id'] ?? 0);
        if ($plan_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Study Plan ID']);
            exit();
        }
        
        // Fetch plan details
        $stmt = $pdo->prepare("SELECT title, academic_year, course_id FROM study_plans WHERE id = ?");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch();
        if (!$plan) {
            echo json_encode(['success' => false, 'message' => 'Plan not found']);
            exit();
        }
        
        // Fetch assignments
        $stmt = $pdo->prepare("SELECT * FROM study_plan_assignments WHERE study_plan_id = ?");
        $stmt->execute([$plan_id]);
        $assignments = $stmt->fetchAll();
        
        if (empty($assignments)) {
            echo json_encode(['success' => false, 'message' => 'This plan is not assigned to any courses or students.']);
            exit();
        }
        
        // Resolve student list
        $emails = [];
        $where_clauses = [];
        $params = [];
        
        foreach ($assignments as $assign) {
            if ($assign['assignment_type'] === 'all') {
                $where_clauses[] = "status = 'approved'";
            } elseif ($assign['assignment_type'] === 'course') {
                $where_clauses[] = "(pepp_course = ? AND status = 'approved')";
                $params[] = $assign['assigned_value'];
            } elseif ($assign['assignment_type'] === 'batch') {
                $where_clauses[] = "(academic_year = ? AND status = 'approved')";
                $params[] = $assign['assigned_value'];
            } elseif ($assign['assignment_type'] === 'student') {
                $where_clauses[] = "(user_id = ? AND status = 'approved')";
                $params[] = $assign['assigned_value'];
            }
        }
        
        if (!empty($where_clauses)) {
            $sql = "SELECT name, email FROM users WHERE " . implode(' OR ', $where_clauses);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $students = $stmt->fetchAll();
        }
        
        if (empty($students)) {
            echo json_encode(['success' => false, 'message' => 'No active student matches the assignments criteria.']);
            exit();
        }
        
        // Queue/Send emails manually
        require_once '../includes/peppian_notify.php';
        $sent_count = 0;
        
        foreach ($students as $stud) {
            if (empty($stud['email'])) continue;
            
            $subject = "Your Study Plan Update: " . $plan['title'];
            $heading = "Study Plan Published";
            $body = "<p>Dear {$stud['name']},</p>
                     <p>A new study plan <strong>\"{$plan['title']}\"</strong> has been published for your enrolled courses.</p>
                     <p>Log in to your student portal or track your daily learning journey at: <a href='https://pepplearning.in/admissions/studyplans' target='_blank'>PEPP Learning Study Plans Portal</a></p>
                     <p>Best wishes,<br>PEPP Learning Team</p>";
            
            peppian_send_email($stud['email'], $subject, $heading, $body, false);
            $sent_count++;
        }
        
        // Audit
        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'send_notification', ?)");
        $stmt_audit->execute([$plan_id, $admin_username, "Manually dispatched update email notification to {$sent_count} students"]);
        
        echo json_encode(['success' => true, 'message' => "Manual update emails successfully dispatched to {$sent_count} students."]);
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
