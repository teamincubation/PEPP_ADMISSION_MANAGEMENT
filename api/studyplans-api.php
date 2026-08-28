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

function log_activity_version($pdo, $activity_id, $change_type, $admin_username) {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ?");
    $stmt->execute([$activity_id]);
    $act = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$act) {
        return;
    }

    $stmt_ver = $pdo->prepare("SELECT COALESCE(MAX(version_number), 0) + 1 FROM study_plan_activity_versions WHERE activity_id = ?");
    $stmt_ver->execute([$activity_id]);
    $next_ver = (int)$stmt_ver->fetchColumn();

    $stmt_ins = $pdo->prepare("
        INSERT INTO study_plan_activity_versions (
            activity_id, activity_uid, study_plan_id, version_number, activity_date, day_number, sort_order,
            chapter, topic, activity_title, activity_description, activity_type,
            faculty, estimated_duration, priority, difficulty_level, resource_links,
            custom_activity_badge, custom_activity_color, custom_activity_icon, created_by, change_type, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt_ins->execute([
        $activity_id,
        $act['activity_uid'],
        $act['study_plan_id'],
        $next_ver,
        $act['activity_date'],
        (int)$act['day_number'],
        (int)$act['sort_order'],
        $act['chapter'] ?? null,
        !empty($act['topic']) ? $act['topic'] : ($act['subject'] ?? null),
        $act['activity_title'],
        $act['activity_description'] ?? null,
        $act['activity_type'],
        $act['faculty'] ?? null,
        !empty($act['estimated_duration']) ? (int)$act['estimated_duration'] : null,
        $act['priority'] ?? 'medium',
        $act['difficulty_level'] ?? 'medium',
        $act['resource_links'] ?? null,
        $act['custom_activity_badge'] ?? null,
        $act['custom_activity_color'] ?? null,
        $act['custom_activity_icon'] ?? null,
        $admin_username,
        $change_type
    ]);
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

        // ── OPTIMISTIC CONCURRENCY PROTECTION ────────────────────────────
        $client_version = isset($data['version']) ? (int)$data['version'] : 0;
        $db_version = false;

        if ($id > 0) {
            $query_ver = "SELECT version FROM study_plans WHERE id = ?";
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $query_ver .= " FOR UPDATE";
            }
            $stmt_ver_check = $pdo->prepare($query_ver);
            $stmt_ver_check->execute([$id]);
            $db_version = $stmt_ver_check->fetchColumn();

            if ($db_version !== false) {
                $db_version = (int)$db_version;
                if ($client_version > 0 && $client_version !== $db_version) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'error_code' => 'STALE_STUDY_PLAN',
                        'message' => 'This study plan was updated by another administrator. Please reload the latest version before saving your changes.'
                    ]);
                    exit();
                }
            }
        }

        if ($id > 0) {
            // Update plan
            $stmt = $pdo->prepare("
                UPDATE study_plans SET
                    title = ?, academic_year = ?, course_id = ?, description = ?,
                    cover_image = ?, theme = ?, layout = ?, start_date = ?, end_date = ?,
                    status = ?, is_template = ?, custom_settings = ?, plan_type = ?, total_days = ?,
                    version = version + 1, updated_at = NOW()
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
            INSERT INTO study_plan_activities (study_plan_id, activity_date, day_number, sort_order, activity_title, activity_type, activity_uid)
            VALUES (?, ?, ?, 0, 'Rest Day / Self Study', 'Revision', ?)
        ");

        while ($curr <= $end) {
            $formatted_date = $curr->format('Y-m-d');
            if (!in_array($formatted_date, $existing_dates)) {
                $uid = 'SPA-' . bin2hex(random_bytes(10));
                $stmt_insert_day->execute([$plan_id, $formatted_date, $day_num, $uid]);
                $new_act_id = $pdo->lastInsertId();
                log_activity_version($pdo, $new_act_id, 'create', $admin_username);
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

        $new_ver = ($db_version !== false) ? $db_version + 1 : 1;
        echo json_encode([
            'success' => true,
            'plan_id' => $plan_id,
            'version' => $new_ver,
            'message' => 'Study plan saved successfully.'
        ]);
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

        // Copy activities (exclude deleted ones)
        $stmt_act = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0 ORDER BY activity_date ASC, sort_order ASC");
        $stmt_act->execute([$id]);
        $activities = $stmt_act->fetchAll();

        $stmt_act_ins = $pdo->prepare("
            INSERT INTO study_plan_activities (
                study_plan_id, activity_date, day_number, sort_order, chapter,
                topic, activity_title, activity_description, activity_type,
                faculty, estimated_duration, priority, difficulty_level, resource_links,
                custom_activity_badge, custom_activity_color, custom_activity_icon, activity_uid
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($activities as $act) {
            $new_uid = 'SPA-' . bin2hex(random_bytes(10));
            $stmt_act_ins->execute([
                $new_id, $act['activity_date'], $act['day_number'], $act['sort_order'], $act['chapter'],
                !empty($act['topic']) ? $act['topic'] : ($act['subject'] ?? null), $act['activity_title'], $act['activity_description'], $act['activity_type'],
                $act['faculty'], $act['estimated_duration'], $act['priority'], $act['difficulty_level'], $act['resource_links'],
                $act['custom_activity_badge'], $act['custom_activity_color'], $act['custom_activity_icon'], $new_uid
            ]);
            $new_act_id = $pdo->lastInsertId();
            log_activity_version($pdo, $new_act_id, 'create', $admin_username);
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
        $client_version = isset($_POST['version']) ? (int)$_POST['version'] : 0;

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
            exit();
        }

        if ($confirm !== 'DELETE') {
            echo json_encode(['success' => false, 'message' => 'Please type "DELETE" to confirm.']);
            exit();
        }

        $pdo->beginTransaction();

        // Lock study plan and read version & deleted state
        $query_ver = "SELECT version, is_deleted, title FROM study_plans WHERE id = ?";
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $query_ver .= " FOR UPDATE";
        }
        $stmt_ver_check = $pdo->prepare($query_ver);
        $stmt_ver_check->execute([$id]);
        $plan_row = $stmt_ver_check->fetch(PDO::FETCH_ASSOC);

        if (!$plan_row) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Study plan not found']);
            exit();
        }

        if ((int)$plan_row['is_deleted'] === 1) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error_code' => 'PLAN_ALREADY_DELETED',
                'message' => 'This study plan is already deleted.'
            ]);
            exit();
        }

        $db_version = (int)$plan_row['version'];
        if ($client_version > 0 && $client_version !== $db_version) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error_code' => 'STALE_STUDY_PLAN',
                'message' => 'This study plan was updated by another administrator. Please reload the latest version before saving your changes.'
            ]);
            exit();
        }

        $title = $plan_row['title'];

        // ── ASSESSMENT RESULT PROTECTION ──────────────────────────────────
        // Block deletion if published assessment results exist for this plan.
        // Assessment data must never be automatically deleted.
        try {
            $arb_del_check = $pdo->prepare("
                SELECT COUNT(*) as cnt,
                       GROUP_CONCAT(DISTINCT activity_title_snapshot SEPARATOR ', ') as titles
                FROM assessment_result_batches
                WHERE study_plan_id = ? AND status IN ('published','replaced')
            ");
            $arb_del_check->execute([$id]);
            $arb_del_row = $arb_del_check->fetch(PDO::FETCH_ASSOC);
            if ($arb_del_row && (int)$arb_del_row['cnt'] > 0) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot delete this Study Plan because it has ' . (int)$arb_del_row['cnt'] . ' assessment result batch(es) linked to it (' . $arb_del_row['titles'] . '). Please manage or archive the assessment results first before deleting the Study Plan.'
                ]);
                exit();
            }
        } catch (Exception $e) {
            // If assessment tables don't exist, proceed normally
        }

        $reason = trim($_POST['deletion_reason'] ?? 'Admin deleted');

        // Perform soft delete
        $stmt_del_plan = $pdo->prepare("
            UPDATE study_plans
            SET is_deleted = 1,
                deleted_at = NOW(),
                deleted_by = ?,
                deletion_reason = ?,
                version = version + 1,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt_del_plan->execute([$admin_username, $reason, $id]);

        // Soft delete assignments associated with this plan
        $stmt_del_assign = $pdo->prepare("
            UPDATE study_plan_assignments
            SET is_deleted = 1,
                deleted_at = NOW(),
                deleted_by = ?
            WHERE study_plan_id = ? AND is_deleted = 0
        ");
        $stmt_del_assign->execute([$admin_username, $id]);

        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'delete_plan', ?)");
        $stmt_audit->execute([$id, $admin_username, "Soft-deleted study plan: '{$title}'. Reason: {$reason}"]);

        log_admin_activity($pdo, $admin_username, 'studyplan_deleted', "Deleted study plan #{$id} '{$title}'");

        $pdo->commit();

        $new_ver = $db_version + 1;
        echo json_encode(['success' => true, 'version' => $new_ver]);
        exit();
    }

    if ($action === 'restore_plan') {
        $id = (int)($_POST['id'] ?? 0);
        $client_version = isset($_POST['version']) ? (int)$_POST['version'] : 0;

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
            exit();
        }

        $pdo->beginTransaction();

        // Lock study plan and read version & deleted state
        $query_ver = "SELECT version, is_deleted, title FROM study_plans WHERE id = ?";
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $query_ver .= " FOR UPDATE";
        }
        $stmt_ver_check = $pdo->prepare($query_ver);
        $stmt_ver_check->execute([$id]);
        $plan_row = $stmt_ver_check->fetch(PDO::FETCH_ASSOC);

        if (!$plan_row) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Study plan not found']);
            exit();
        }

        if ((int)$plan_row['is_deleted'] === 0) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error_code' => 'PLAN_NOT_DELETED',
                'message' => 'This study plan is already active.'
            ]);
            exit();
        }

        $db_version = (int)$plan_row['version'];
        if ($client_version > 0 && $client_version !== $db_version) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error_code' => 'STALE_STUDY_PLAN',
                'message' => 'This study plan was updated by another administrator. Please reload the latest version before saving your changes.'
            ]);
            exit();
        }

        $title = $plan_row['title'];

        // Perform restore
        $stmt_rest_plan = $pdo->prepare("
            UPDATE study_plans
            SET is_deleted = 0,
                deleted_at = NULL,
                deleted_by = NULL,
                deletion_reason = NULL,
                version = version + 1,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt_rest_plan->execute([$id]);

        // Restore assignments that were soft-deleted as part of the plan deletion
        $stmt_rest_assign = $pdo->prepare("
            UPDATE study_plan_assignments
            SET is_deleted = 0,
                deleted_at = NULL,
                deleted_by = NULL
            WHERE study_plan_id = ? AND is_deleted = 1
        ");
        $stmt_rest_assign->execute([$id]);

        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'restore_plan', ?)");
        $stmt_audit->execute([$id, $admin_username, "Restored study plan: '{$title}'"]);

        log_admin_activity($pdo, $admin_username, 'studyplan_restored', "Restored study plan #{$id} '{$title}'");

        $pdo->commit();

        $new_ver = $db_version + 1;
        echo json_encode(['success' => true, 'version' => $new_ver]);
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

        // ── OPTIMISTIC CONCURRENCY PROTECTION ────────────────────────────
        $client_version = isset($data['version']) ? (int)$data['version'] : 0;

        $query_ver = "SELECT version FROM study_plans WHERE id = ?";
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $query_ver .= " FOR UPDATE";
        }
        $stmt_ver_check = $pdo->prepare($query_ver);
        $stmt_ver_check->execute([$plan_id]);
        $db_version = $stmt_ver_check->fetchColumn();

        if ($db_version !== false) {
            $db_version = (int)$db_version;
            if ($client_version > 0 && $client_version !== $db_version) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'error_code' => 'STALE_STUDY_PLAN',
                    'message' => 'This study plan was updated by another administrator. Please reload the latest version before saving your changes.'
                ]);
                exit();
            }
        }

        // ── CORE DATA-INTEGRITY VALIDATIONS ──────────────────────────────
        // Prevent cross-plan manipulation and ID/UID mismatch forgery.
        foreach ($activities as $act) {
            $act_id = isset($act['id']) && $act['id'] !== '' ? (int)$act['id'] : 0;
            $act_uid = isset($act['activity_uid']) && $act['activity_uid'] !== '' ? trim($act['activity_uid']) : null;

            if ($act_id > 0 || !empty($act_uid)) {
                if ($act_id > 0 && !empty($act_uid)) {
                    $stmt_v = $pdo->prepare("SELECT study_plan_id, activity_uid FROM study_plan_activities WHERE id = ?");
                    $stmt_v->execute([$act_id]);
                    $db_act = $stmt_v->fetch(PDO::FETCH_ASSOC);

                    if (!$db_act) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Security Error: Activity ID does not exist.']);
                        exit();
                    }
                    if ((int)$db_act['study_plan_id'] !== $plan_id) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Security Error: Activity belongs to another study plan.']);
                        exit();
                    }
                    if ($db_act['activity_uid'] !== $act_uid) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Security Error: Mismatched Activity ID and UID.']);
                        exit();
                    }
                } else if ($act_id > 0) {
                    $stmt_v = $pdo->prepare("SELECT study_plan_id FROM study_plan_activities WHERE id = ?");
                    $stmt_v->execute([$act_id]);
                    $db_plan_id = $stmt_v->fetchColumn();
                    if ($db_plan_id === false) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Security Error: Activity ID does not exist.']);
                        exit();
                    }
                    if ((int)$db_plan_id !== $plan_id) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Security Error: Activity belongs to another study plan.']);
                        exit();
                    }
                } else { // !empty($act_uid)
                    $stmt_v = $pdo->prepare("SELECT study_plan_id, id FROM study_plan_activities WHERE activity_uid = ?");
                    $stmt_v->execute([$act_uid]);
                    $db_act = $stmt_v->fetch(PDO::FETCH_ASSOC);
                    if (!$db_act) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Security Error: Activity UID does not exist.']);
                        exit();
                    }
                    if ((int)$db_act['study_plan_id'] !== $plan_id) {
                        $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' => 'Security Error: Activity UID belongs to another study plan.']);
                        exit();
                    }
                }
            }
        }

        $stmt_ins = $pdo->prepare("
            INSERT INTO study_plan_activities (
                study_plan_id, activity_date, day_number, sort_order, chapter,
                topic, activity_title, activity_description, activity_type,
                faculty, estimated_duration, priority, difficulty_level, resource_links,
                custom_activity_badge, custom_activity_color, custom_activity_icon, activity_uid, is_deleted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");

        $stmt_upd = $pdo->prepare("
            UPDATE study_plan_activities SET
                activity_date = ?, day_number = ?, sort_order = ?, chapter = ?,
                topic = ?, activity_title = ?, activity_description = ?, activity_type = ?,
                faculty = ?, estimated_duration = ?, priority = ?, difficulty_level = ?, resource_links = ?,
                custom_activity_badge = ?, custom_activity_color = ?, custom_activity_icon = ?, is_deleted = 0
            WHERE id = ?
        ");

        $saved_ids = [];

        foreach ($activities as $act) {
            $act_id = isset($act['id']) && $act['id'] !== '' ? (int)$act['id'] : 0;
            $act_uid = isset($act['activity_uid']) && $act['activity_uid'] !== '' ? trim($act['activity_uid']) : null;

            if ($act_id <= 0 && !empty($act_uid)) {
                $stmt_get_id = $pdo->prepare("SELECT id FROM study_plan_activities WHERE activity_uid = ?");
                $stmt_get_id->execute([$act_uid]);
                $act_id = (int)$stmt_get_id->fetchColumn();
            }

            $topic_val = trim(!empty($act['topic']) ? $act['topic'] : ($act['subject'] ?? ''));

            if ($act_id > 0) {
                // Read current record to check for changes
                $stmt_cur = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ?");
                $stmt_cur->execute([$act_id]);
                $cur = $stmt_cur->fetch(PDO::FETCH_ASSOC);

                $changed = false;
                if ($cur) {
                    if ($cur['activity_date'] !== $act['activity_date'] ||
                        (int)$cur['day_number'] !== (int)$act['day_number'] ||
                        (int)$cur['sort_order'] !== (int)$act['sort_order'] ||
                        ($cur['chapter'] ?? '') !== ($act['chapter'] ?? '') ||
                        ($cur['topic'] ?? '') !== $topic_val ||
                        ($cur['activity_title'] ?? '') !== ($act['activity_title'] ?? '') ||
                        ($cur['activity_description'] ?? '') !== ($act['activity_description'] ?? '') ||
                        ($cur['activity_type'] ?? '') !== ($act['activity_type'] ?? '') ||
                        ($cur['faculty'] ?? '') !== ($act['faculty'] ?? '') ||
                        ($cur['estimated_duration'] !== null ? (int)$cur['estimated_duration'] : null) !== (!empty($act['estimated_duration']) ? (int)$act['estimated_duration'] : null) ||
                        ($cur['priority'] ?? 'medium') !== ($act['priority'] ?? 'medium') ||
                        ($cur['difficulty_level'] ?? 'medium') !== ($act['difficulty_level'] ?? 'medium') ||
                        ($cur['resource_links'] ?? '') !== ($act['resource_links'] ?? '') ||
                        ($cur['custom_activity_badge'] ?? '') !== ($act['custom_activity_badge'] ?? '') ||
                        ($cur['custom_activity_color'] ?? '') !== ($act['custom_activity_color'] ?? '') ||
                        ($cur['custom_activity_icon'] ?? '') !== ($act['custom_activity_icon'] ?? '') ||
                        (int)$cur['is_deleted'] !== 0) {
                        $changed = true;
                    }
                } else {
                    $changed = true;
                }

                if ($changed) {
                    // Log previous state to history before editing
                    log_activity_version($pdo, $act_id, 'update', $admin_username);
                }

                $stmt_upd->execute([
                    $act['activity_date'],
                    (int)$act['day_number'],
                    (int)$act['sort_order'],
                    trim($act['chapter'] ?? ''),
                    $topic_val,
                    trim($act['activity_title'] ?? 'Self Study'),
                    trim($act['activity_description'] ?? ''),
                    trim($act['activity_type'] ?? 'Revision'),
                    trim($act['faculty'] ?? ''),
                    !empty($act['estimated_duration']) ? (int)$act['estimated_duration'] : null,
                    trim($act['priority'] ?? 'medium'),
                    trim($act['difficulty_level'] ?? 'medium'),
                    trim($act['resource_links'] ?? ''),
                    trim($act['custom_activity_badge'] ?? ''),
                    trim($act['custom_activity_color'] ?? ''),
                    trim($act['custom_activity_icon'] ?? ''),
                    $act_id
                ]);
                $saved_ids[] = $act_id;
            } else {
                // Insert brand-new activity
                $new_uid = 'SPA-' . bin2hex(random_bytes(10));
                $stmt_ins->execute([
                    $plan_id,
                    $act['activity_date'],
                    (int)$act['day_number'],
                    (int)$act['sort_order'],
                    trim($act['chapter'] ?? ''),
                    $topic_val,
                    trim($act['activity_title'] ?? 'Self Study'),
                    trim($act['activity_description'] ?? ''),
                    trim($act['activity_type'] ?? 'Revision'),
                    trim($act['faculty'] ?? ''),
                    !empty($act['estimated_duration']) ? (int)$act['estimated_duration'] : null,
                    trim($act['priority'] ?? 'medium'),
                    trim($act['difficulty_level'] ?? 'medium'),
                    trim($act['resource_links'] ?? ''),
                    trim($act['custom_activity_badge'] ?? ''),
                    trim($act['custom_activity_color'] ?? ''),
                    trim($act['custom_activity_icon'] ?? ''),
                    $new_uid
                ]);
                $new_act_id = $pdo->lastInsertId();
                log_activity_version($pdo, $new_act_id, 'create', $admin_username);
                $saved_ids[] = $new_act_id;
            }
        }

        // Update study plans version stamp
        $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = NOW() WHERE id = ?")->execute([$plan_id]);

        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'save_activities', ?)");
        $stmt_audit->execute([$plan_id, $admin_username, "Saved and reordered study plan activities (Count: " . count($activities) . ")"]);

        $pdo->commit();

        // Return updated listing with assigned database IDs/UIDs back to client
        $stmt_active_acts = $pdo->prepare("SELECT id, activity_uid, activity_date, day_number, sort_order FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0 ORDER BY activity_date ASC, sort_order ASC");
        $stmt_active_acts->execute([$plan_id]);
        $active_acts = $stmt_active_acts->fetchAll(PDO::FETCH_ASSOC);

        $new_version = ($db_version !== false) ? $db_version + 1 : 1;
        echo json_encode([
            'success' => true,
            'version' => $new_version,
            'activities' => $active_acts
        ]);
        exit();
    }

    if ($action === 'check_activity_delete') {
        $activity_id = (int)($_POST['activity_id'] ?? 0);
        if ($activity_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Activity ID']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$activity_id]);
        $act = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$act) {
            echo json_encode(['success' => false, 'message' => 'Activity not found or already deleted']);
            exit();
        }

        // Count unique students who completed this activity
        $stmt_cnt = $pdo->prepare("
            SELECT COUNT(DISTINCT student_email)
            FROM study_plan_analytics
            WHERE (activity_uid = ? OR (activity_id = ? AND (activity_uid IS NULL OR activity_uid = '')))
              AND action_type = 'complete_activity'
              AND completion_status = 'completed'
        ");
        $stmt_cnt->execute([$act['activity_uid'], $act['id']]);
        $student_count = (int)$stmt_cnt->fetchColumn();

        // Generate confirmation token
        $token = bin2hex(random_bytes(16));
        if (!isset($_SESSION['delete_tokens'])) {
            $_SESSION['delete_tokens'] = [];
        }
        $_SESSION['delete_tokens'][$activity_id] = [
            'token' => $token,
            'activity_id' => $activity_id,
            'activity_uid' => $act['activity_uid'],
            'study_plan_id' => (int)$act['study_plan_id'],
            'expires_at' => time() + 300
        ];

        echo json_encode([
            'success' => true,
            'student_count' => $student_count,
            'activity_id' => $activity_id,
            'activity_uid' => $act['activity_uid'],
            'confirmation_token' => $token
        ]);
        exit();
    }

    if ($action === 'delete_activity') {
        $activity_id = (int)($_POST['activity_id'] ?? 0);
        $token = trim($_POST['confirmation_token'] ?? '');
        $expected_count = isset($_POST['expected_count']) ? (int)$_POST['expected_count'] : 0;

        if ($activity_id <= 0 || empty($token)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters for deletion']);
            exit();
        }

        $token_data = $_SESSION['delete_tokens'][$activity_id] ?? null;
        if (!$token_data || $token_data['token'] !== $token || $token_data['expires_at'] < time()) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired confirmation token. Please try again.']);
            exit();
        }

        $pdo->beginTransaction();

        // Authoritative validation inside transaction (FOR UPDATE on MySQL only)
        $query = "SELECT * FROM study_plan_activities WHERE id = ?";
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $query .= " FOR UPDATE";
        }

        $stmt_lock = $pdo->prepare($query);
        $stmt_lock->execute([$activity_id]);
        $act = $stmt_lock->fetch(PDO::FETCH_ASSOC);

        if (!$act || (int)$act['study_plan_id'] !== (int)$token_data['study_plan_id'] || $act['activity_uid'] !== $token_data['activity_uid']) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Activity validation failed.']);
            exit();
        }

        if ((int)$act['is_deleted'] === 1) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Activity has already been deleted.']);
            exit();
        }

        $plan_id = (int)$act['study_plan_id'];

        // ── OPTIMISTIC CONCURRENCY PROTECTION ────────────────────────────
        $client_version = isset($_POST['version']) ? (int)$_POST['version'] : 0;

        $query_ver = "SELECT version FROM study_plans WHERE id = ?";
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $query_ver .= " FOR UPDATE";
        }
        $stmt_ver_check = $pdo->prepare($query_ver);
        $stmt_ver_check->execute([$plan_id]);
        $db_version = $stmt_ver_check->fetchColumn();

        if ($db_version !== false) {
            $db_version = (int)$db_version;
            if ($client_version > 0 && $client_version !== $db_version) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'error_code' => 'STALE_STUDY_PLAN',
                    'message' => 'This study plan was updated by another administrator. Please reload the latest version before saving your changes.'
                ]);
                exit();
            }
        }

        // Re-calculate completion count inside transaction
        $stmt_cnt = $pdo->prepare("
            SELECT COUNT(DISTINCT student_email)
            FROM study_plan_analytics
            WHERE (activity_uid = ? OR (activity_id = ? AND (activity_uid IS NULL OR activity_uid = '')))
              AND action_type = 'complete_activity'
              AND completion_status = 'completed'
        ");
        $stmt_cnt->execute([$act['activity_uid'], $act['id']]);
        $current_count = (int)$stmt_cnt->fetchColumn();

        if ($current_count !== $expected_count) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'count_changed' => true,
                'current_count' => $current_count,
                'message' => "The student completion count has changed in the background (was {$expected_count}, now {$current_count}). Please verify and try again."
            ]);
            exit();
        }

        // Log pre-deleted state
        log_activity_version($pdo, $activity_id, 'delete', $admin_username);

        // Perform soft delete
        $reason = trim($_POST['deletion_reason'] ?? 'Admin deleted');
        $stmt_del = $pdo->prepare("UPDATE study_plan_activities SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, deletion_reason = ? WHERE id = ?");
        $stmt_del->execute([$admin_username, $reason, $activity_id]);

        // Increment plan version
        $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = NOW() WHERE id = ?")->execute([$plan_id]);

        // Write audit log
        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'delete_activity', ?)");
        $stmt_audit->execute([
            $plan_id,
            $admin_username,
            "Soft-deleted activity '{$act['activity_title']}' (ID: {$activity_id}, UID: {$act['activity_uid']}). Reason: {$reason}"
        ]);

        unset($_SESSION['delete_tokens'][$activity_id]);

        $pdo->commit();

        $new_ver = ($db_version !== false) ? $db_version + 1 : 1;
        echo json_encode(['success' => true, 'version' => $new_ver]);
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
            'topic' => -1,
            'title' => -1,
            'description' => -1,
            'type' => -1,
            'faculty' => -1,
            'duration' => -1,
            'priority' => -1,
            'difficulty' => -1,
            'resource' => -1
        ];

        foreach ($headers as $index => $h) {
            if (strpos($h, 'date') !== false) $mapping['date'] = $index;
            elseif (strpos($h, 'day') !== false) $mapping['day'] = $index;
            elseif (strpos($h, 'chap') !== false) $mapping['chapter'] = $index;
            elseif (strpos($h, 'subj') !== false || strpos($h, 'top') !== false) $mapping['topic'] = $index;
            elseif (strpos($h, 'titl') !== false || strpos($h, 'name') !== false) $mapping['title'] = $index;
            elseif (strpos($h, 'desc') !== false) $mapping['description'] = $index;
            elseif (strpos($h, 'type') !== false || strpos($h, 'act') !== false) $mapping['type'] = $index;
            elseif (strpos($h, 'fac') !== false) $mapping['faculty'] = $index;
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
                'topic' => $mapping['topic'] >= 0 ? trim($r[$mapping['topic']]) : '',
                'activity_title' => $title_val,
                'activity_description' => $mapping['description'] >= 0 ? trim($r[$mapping['description']]) : '',
                'activity_type' => $type_val,
                'faculty' => $mapping['faculty'] >= 0 ? trim($r[$mapping['faculty']]) : '',
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
