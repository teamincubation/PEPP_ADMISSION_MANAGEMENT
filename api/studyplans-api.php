<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/study_plan_lock_helper.php';

header('Content-Type: application/json');

// Check authorization
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

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
        $act['topic'] ?? null,
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
$admin_info = get_current_admin_identity($pdo);
$admin_username = $admin_info['admin_username'] ?? 'Admin';
$admin_id = $admin_info['admin_id'] ?? null;

try {
    if ($action === 'check_edit_lock') {
        $plan_id = (int)($_REQUEST['study_plan_id'] ?? 0);
        $read_only_check = !empty($_REQUEST['read_only_mode']);
        $admin_info = get_current_admin_identity($pdo);

        if ($read_only_check) {
            // Read-only polling: do NOT acquire or update lock, just inspect status
            ensure_study_plan_edit_locks_table($pdo);
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare("
                    SELECT *, TIMESTAMPDIFF(SECOND, last_heartbeat_at, NOW()) AS heartbeat_age_seconds 
                    FROM study_plan_edit_locks 
                    WHERE study_plan_id = ? AND is_active = 1
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT *, CAST((strftime('%s', 'now') - strftime('%s', last_heartbeat_at)) AS INTEGER) AS heartbeat_age_seconds 
                    FROM study_plan_edit_locks 
                    WHERE study_plan_id = ? AND is_active = 1
                ");
            }
            $stmt->execute([$plan_id]);
            $lock = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lock) {
                echo json_encode(['success' => true, 'locked' => false, 'is_owner' => false, 'can_claim' => true]);
                exit();
            }

            $age = isset($lock['heartbeat_age_seconds']) && $lock['heartbeat_age_seconds'] !== null
                ? (int)$lock['heartbeat_age_seconds']
                : (time() - strtotime((string)$lock['last_heartbeat_at']));

            $is_stale = ((int)$lock['is_active'] === 0 || $age > STUDY_PLAN_LOCK_TIMEOUT_SECONDS || $age < 0 || empty($lock['last_heartbeat_at']));

            if ($is_stale) {
                echo json_encode(['success' => true, 'locked' => false, 'is_owner' => false, 'can_claim' => true]);
                exit();
            }

            $is_owner = false;
            if (!empty($admin_info['admin_username']) && !empty($lock['admin_username'])) {
                if (strcasecmp((string)$lock['admin_username'], (string)$admin_info['admin_username']) === 0) {
                    $is_owner = true;
                }
            }
            if (!$is_owner && !empty($admin_info['admin_id']) && !empty($lock['admin_id'])) {
                if ((int)$admin_info['admin_id'] === (int)$lock['admin_id']) {
                    $is_owner = true;
                }
            }

            if ($is_owner) {
                echo json_encode(['success' => true, 'locked' => false, 'is_owner' => true, 'can_claim' => true]);
                exit();
            }

            $avatar_info = resolve_admin_photo_and_initials($pdo, !empty($lock['admin_id']) ? (int)$lock['admin_id'] : 0, $lock['admin_username'], $lock['admin_name']);
            echo json_encode([
                'success' => false,
                'locked' => true,
                'is_owner' => false,
                'can_claim' => false,
                'locked_by' => [
                    'admin_id' => $lock['admin_id'],
                    'admin_name' => $lock['admin_name'],
                    'admin_username' => $lock['admin_username'],
                    'locked_at' => $lock['locked_at'],
                    'last_heartbeat_at' => $lock['last_heartbeat_at'],
                    'photo_url' => $avatar_info['photo_url'],
                    'initials' => $avatar_info['initials']
                ]
            ]);
            exit();
        }

        $lock_res = acquire_or_check_study_plan_lock($pdo, $plan_id, $admin_info);
        echo json_encode($lock_res);
        exit();
    }

    if ($action === 'study_plan_edit_lock_heartbeat') {
        $plan_id = (int)($_REQUEST['study_plan_id'] ?? 0);
        $admin_info = get_current_admin_identity($pdo);
        $hb_res = heartbeat_study_plan_lock($pdo, $plan_id, $admin_info['admin_username'], $admin_info['session_token'], $admin_info['admin_id']);
        echo json_encode($hb_res);
        exit();
    }

    if ($action === 'release_study_plan_edit_lock') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;
        $plan_id = (int)($data['study_plan_id'] ?? ($_REQUEST['study_plan_id'] ?? ($_GET['study_plan_id'] ?? 0)));
        $admin_info = get_current_admin_identity($pdo);
        $rel_res = release_study_plan_lock(
            $pdo,
            $plan_id,
            $admin_info['admin_username'],
            is_super_admin(),
            $admin_info['admin_id']
        );
        echo json_encode($rel_res);
        exit();
    }

    if ($action === 'save_plan') {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $id = (int)($data['id'] ?? 0);

        // Edit Lock Enforcement
        if ($id > 0 && !verify_study_plan_edit_lock_permission($pdo, $id, $admin_username, $admin_id)) {
            echo json_encode([
                'success' => false,
                'error_code' => 'EDIT_LOCK_HELD',
                'message' => 'This Study Plan is currently locked for editing by another administrator.'
            ]);
            exit();
        }

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

        // Copy plan (strictly preserving plan_type and total_days)
        $title = $plan['title'] . ' (Copy)';
        $stmt_ins = $pdo->prepare("
            INSERT INTO study_plans (
                title, academic_year, course_id, description,
                cover_image, theme, layout, start_date, end_date,
                status, is_template, custom_settings, plan_type, total_days, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt_ins->execute([
            $title, $plan['academic_year'], $plan['course_id'], $plan['description'],
            $plan['cover_image'], $plan['theme'], $plan['layout'], $plan['start_date'], $plan['end_date'],
            'draft', $plan['is_template'], $plan['custom_settings'],
            $plan['plan_type'] ?? 'date_wise',
            !empty($plan['total_days']) ? (int)$plan['total_days'] : null,
            $admin_username
        ]);
        $new_id = $pdo->lastInsertId();

        // Copy activities (exclude deleted ones, assign new activity_uids, do not copy completion analytics)
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

        // Edit Lock Enforcement
        if ($plan_id > 0 && !verify_study_plan_edit_lock_permission($pdo, $plan_id, $admin_username, $admin_id)) {
            echo json_encode([
                'success' => false,
                'error_code' => 'EDIT_LOCK_HELD',
                'message' => 'This Study Plan is currently locked for editing by another administrator.'
            ]);
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
            WHERE id = ? AND study_plan_id = ?
        ");

        $response_activities = [];

        foreach ($activities as $act) {
            $act_id = isset($act['id']) && $act['id'] !== '' ? (int)$act['id'] : 0;
            $act_uid = isset($act['activity_uid']) && $act['activity_uid'] !== '' ? trim($act['activity_uid']) : null;
            $client_key = isset($act['client_key']) && $act['client_key'] !== '' ? trim($act['client_key']) : null;

            // Security: If UID is provided, ensure it belongs to THIS study plan
            if ($act_id <= 0 && !empty($act_uid)) {
                $stmt_get_id = $pdo->prepare("SELECT id FROM study_plan_activities WHERE activity_uid = ? AND study_plan_id = ?");
                $stmt_get_id->execute([$act_uid, $plan_id]);
                $act_id = (int)$stmt_get_id->fetchColumn();
            }

            // Security: If ID is provided, verify it belongs to THIS study plan
            if ($act_id > 0) {
                $stmt_check_plan = $pdo->prepare("SELECT id, activity_uid FROM study_plan_activities WHERE id = ? AND study_plan_id = ?");
                $stmt_check_plan->execute([$act_id, $plan_id]);
                $verified_row = $stmt_check_plan->fetch(PDO::FETCH_ASSOC);
                if (!$verified_row) {
                    // ID does not belong to this plan - reject cross-plan mutation and insert as new
                    $act_id = 0;
                    $act_uid = null;
                } else {
                    $act_uid = $verified_row['activity_uid'];
                }
            }

            $topic_val = trim(!empty($act['topic']) ? $act['topic'] : ($act['subject'] ?? ''));

            if ($act_id > 0) {
                // Read current record to check for changes
                $stmt_cur = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ? AND study_plan_id = ?");
                $stmt_cur->execute([$act_id, $plan_id]);
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
                    $act_id,
                    $plan_id
                ]);

                $response_activities[] = [
                    'id' => $act_id,
                    'activity_uid' => $act_uid,
                    'client_key' => $client_key,
                    'activity_date' => $act['activity_date'],
                    'day_number' => (int)$act['day_number'],
                    'sort_order' => (int)$act['sort_order']
                ];
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
                $new_act_id = (int)$pdo->lastInsertId();
                log_activity_version($pdo, $new_act_id, 'create', $admin_username);

                $response_activities[] = [
                    'id' => $new_act_id,
                    'activity_uid' => $new_uid,
                    'client_key' => $client_key,
                    'activity_date' => $act['activity_date'],
                    'day_number' => (int)$act['day_number'],
                    'sort_order' => (int)$act['sort_order']
                ];
            }
        }

        // Update study plans version stamp
        $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = NOW() WHERE id = ?")->execute([$plan_id]);

        $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'save_activities', ?)");
        $stmt_audit->execute([$plan_id, $admin_username, "Saved and reordered study plan activities (Count: " . count($activities) . ")"]);

        $pdo->commit();

        $new_version = ($db_version !== false) ? $db_version + 1 : 1;
        echo json_encode([
            'success' => true,
            'version' => $new_version,
            'activities' => $response_activities
        ]);
        exit();
    }

    if ($action === 'bulk_move_activities') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!$data) {
            $data = $_POST;
        }

        $plan_id = (int)($data['study_plan_id'] ?? 0);
        $client_version = (int)($data['version'] ?? 0);
        $target_date = trim($data['target_date'] ?? '');
        $target_day = (int)($data['target_day'] ?? 1);
        $activity_uids = $data['activity_uids'] ?? [];
        $activity_ids = $data['activity_ids'] ?? [];

        if ($plan_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Study Plan ID']);
            exit();
        }

        // Edit Lock Enforcement
        if ($plan_id > 0 && !verify_study_plan_edit_lock_permission($pdo, $plan_id, $admin_username, $admin_id)) {
            echo json_encode([
                'success' => false,
                'error_code' => 'EDIT_LOCK_HELD',
                'message' => 'This Study Plan is currently locked for editing by another administrator.'
            ]);
            exit();
        }

        if (empty($activity_uids) && empty($activity_ids)) {
            echo json_encode(['success' => false, 'message' => 'No activities selected to move']);
            exit();
        }

        // Fetch plan
        $stmt_plan = $pdo->prepare("SELECT * FROM study_plans WHERE id = ? AND is_deleted = 0");
        $stmt_plan->execute([$plan_id]);
        $plan = $stmt_plan->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            echo json_encode(['success' => false, 'message' => 'Study Plan not found']);
            exit();
        }

        $is_day_wise = (($plan['plan_type'] ?? 'date_wise') === 'day_wise');

        // Validate destination
        if ($is_day_wise) {
            if ($target_day < 1) $target_day = 1;
            if (!empty($plan['total_days']) && $target_day > (int)$plan['total_days']) {
                $target_day = (int)$plan['total_days'];
            }
            if (empty($target_date)) {
                $target_date = date('Y-m-d', strtotime('2000-01-01 +' . ($target_day - 1) . ' days'));
            }
        } else {
            if (empty($target_date)) {
                echo json_encode(['success' => false, 'message' => 'Target date is required for date-wise study plans']);
                exit();
            }
            if (!empty($plan['start_date'])) {
                $start_ts = strtotime($plan['start_date']);
                $target_ts = strtotime($target_date);
                if ($start_ts && $target_ts) {
                    $computed_day = (int)floor(($target_ts - $start_ts) / 86400) + 1;
                    if ($computed_day >= 1) {
                        $target_day = $computed_day;
                    }
                }
            }
        }

        $pdo->beginTransaction();

        try {
            // Find all matching activities for THIS study plan
            $selected_activities = [];

            if (!empty($activity_uids) && is_array($activity_uids)) {
                $in_placeholders = implode(',', array_fill(0, count($activity_uids), '?'));
                $stmt_uids = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND activity_uid IN ($in_placeholders) AND is_deleted = 0 ORDER BY activity_date ASC, sort_order ASC, id ASC");
                $stmt_uids->execute(array_merge([$plan_id], array_values($activity_uids)));
                $selected_activities = $stmt_uids->fetchAll(PDO::FETCH_ASSOC);

                if (count($selected_activities) !== count(array_unique($activity_uids))) {
                    throw new Exception("One or more selected activities do not exist or do not belong to this study plan.");
                }
            } elseif (!empty($activity_ids) && is_array($activity_ids)) {
                $in_placeholders = implode(',', array_fill(0, count($activity_ids), '?'));
                $stmt_ids = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND id IN ($in_placeholders) AND is_deleted = 0 ORDER BY activity_date ASC, sort_order ASC, id ASC");
                $stmt_ids->execute(array_merge([$plan_id], array_values($activity_ids)));
                $selected_activities = $stmt_ids->fetchAll(PDO::FETCH_ASSOC);

                if (count($selected_activities) !== count(array_unique($activity_ids))) {
                    throw new Exception("One or more selected activities do not exist or do not belong to this study plan.");
                }
            }

            if (empty($selected_activities)) {
                throw new Exception("No valid activities found to move.");
            }

            $moved_ids = array_column($selected_activities, 'id');
            $source_dates = [];
            $source_days = [];
            foreach ($selected_activities as $s_act) {
                if (!empty($s_act['activity_date'])) $source_dates[$s_act['activity_date']] = true;
                if (!empty($s_act['day_number'])) $source_days[$s_act['day_number']] = true;
            }

            // Determine existing tasks on target bucket (excluding moved tasks)
            if ($is_day_wise) {
                $stmt_target_exist = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND day_number = ? AND is_deleted = 0 AND id NOT IN (" . implode(',', $moved_ids) . ") ORDER BY sort_order ASC, id ASC");
                $stmt_target_exist->execute([$plan_id, $target_day]);
            } else {
                $stmt_target_exist = $pdo->prepare("SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND activity_date = ? AND is_deleted = 0 AND id NOT IN (" . implode(',', $moved_ids) . ") ORDER BY sort_order ASC, id ASC");
                $stmt_target_exist->execute([$plan_id, $target_date]);
            }
            $target_existing = $stmt_target_exist->fetchAll(PDO::FETCH_ASSOC);

            // Re-normalize existing target tasks to clean sequential sort_orders
            $stmt_update_order = $pdo->prepare("UPDATE study_plan_activities SET sort_order = ? WHERE id = ? AND study_plan_id = ?");
            $curr_sort = 0;
            foreach ($target_existing as $t_act) {
                $stmt_update_order->execute([$curr_sort, $t_act['id'], $plan_id]);
                $curr_sort++;
            }

            // Append moved tasks after existing target tasks
            $stmt_move = $pdo->prepare("UPDATE study_plan_activities SET activity_date = ?, day_number = ?, sort_order = ? WHERE id = ? AND study_plan_id = ?");
            $updated_moved_activities = [];
            foreach ($selected_activities as $m_act) {
                $stmt_move->execute([$target_date, $target_day, $curr_sort, $m_act['id'], $plan_id]);
                log_activity_version($pdo, $m_act['id'], 'update', $admin_username);
                $updated_moved_activities[] = [
                    'id' => (int)$m_act['id'],
                    'activity_uid' => $m_act['activity_uid'],
                    'activity_date' => $target_date,
                    'day_number' => $target_day,
                    'sort_order' => $curr_sort
                ];
                $curr_sort++;
            }

            // Re-normalize remaining tasks on all source days/dates
            foreach (array_keys($source_dates) as $s_date) {
                if (!$is_day_wise && $s_date === $target_date) continue;
                $stmt_source_rem = $pdo->prepare("SELECT id FROM study_plan_activities WHERE study_plan_id = ? AND activity_date = ? AND is_deleted = 0 AND id NOT IN (" . implode(',', $moved_ids) . ") ORDER BY sort_order ASC, id ASC");
                $stmt_source_rem->execute([$plan_id, $s_date]);
                $source_remaining = $stmt_source_rem->fetchAll(PDO::FETCH_COLUMN);

                $s_sort = 0;
                foreach ($source_remaining as $r_id) {
                    $stmt_update_order->execute([$s_sort, $r_id, $plan_id]);
                    $s_sort++;
                }
            }

            if ($is_day_wise) {
                foreach (array_keys($source_days) as $s_day) {
                    if ($s_day === $target_day) continue;
                    $stmt_source_rem_dw = $pdo->prepare("SELECT id FROM study_plan_activities WHERE study_plan_id = ? AND day_number = ? AND is_deleted = 0 AND id NOT IN (" . implode(',', $moved_ids) . ") ORDER BY sort_order ASC, id ASC");
                    $stmt_source_rem_dw->execute([$plan_id, $s_day]);
                    $source_remaining_dw = $stmt_source_rem_dw->fetchAll(PDO::FETCH_COLUMN);

                    $s_sort = 0;
                    foreach ($source_remaining_dw as $r_id) {
                        $stmt_update_order->execute([$s_sort, $r_id, $plan_id]);
                        $s_sort++;
                    }
                }
            }

            // Bump version
            $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = NOW() WHERE id = ?")->execute([$plan_id]);

            // Single audit log for the bulk operation
            $count_moved = count($selected_activities);
            $source_desc = $is_day_wise ? ('Day(s) ' . implode(',', array_keys($source_days))) : ('Date(s) ' . implode(',', array_keys($source_dates)));
            $dest_desc = $is_day_wise ? ("Day " . $target_day) : ("Date " . $target_date . " (Day " . $target_day . ")");
            $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'bulk_move_activities', ?)");
            $stmt_audit->execute([$plan_id, $admin_username, "Bulk moved {$count_moved} activities from [{$source_desc}] to {$dest_desc}"]);

            $pdo->commit();

            log_admin_activity($pdo, $admin_username, 'studyplan_bulk_move', "Moved {$count_moved} activities in study plan #{$plan_id} to {$dest_desc}");

            // Fetch full updated activities for the plan
            if ($is_day_wise) {
                $stmt_all = $pdo->prepare("SELECT id, activity_uid, activity_date, day_number, sort_order FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0 ORDER BY day_number ASC, sort_order ASC, id ASC");
            } else {
                $stmt_all = $pdo->prepare("SELECT id, activity_uid, activity_date, day_number, sort_order FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0 ORDER BY activity_date ASC, sort_order ASC, id ASC");
            }
            $stmt_all->execute([$plan_id]);
            $all_active = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'count' => $count_moved,
                'version' => (int)($plan['version'] + 1),
                'moved' => $updated_moved_activities,
                'activities' => $all_active,
                'message' => "{$count_moved} task(s) moved successfully."
            ]);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Exception $rbEx) {}
            }
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            exit();
        }
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

        $plan_id = (int)$act['study_plan_id'];
        $act_uid = trim($act['activity_uid'] ?? '');

        // 1. Authoritative check in study_plan_analytics
        $student_count = 0;
        try {
            $stmt_cnt = $pdo->prepare("
                SELECT COUNT(*)
                FROM study_plan_analytics
                WHERE study_plan_id = ?
                  AND (
                      (activity_uid = ? AND ? != '')
                      OR (activity_id = ? AND ? > 0)
                  )
            ");
            $stmt_cnt->execute([$plan_id, $act_uid, $act_uid, $activity_id, $activity_id]);
            $student_count += (int)$stmt_cnt->fetchColumn();
        } catch (Exception $e) {}

        // 2. Authoritative check in assessment_results via assessment_result_batches
        try {
            $stmt_att = $pdo->prepare("
                SELECT COUNT(*)
                FROM assessment_results ar
                JOIN assessment_result_batches arb ON ar.batch_id = arb.id
                WHERE arb.activity_id = ? AND (arb.is_deleted IS NULL OR arb.is_deleted = 0)
            ");
            $stmt_att->execute([$activity_id]);
            $student_count += (int)$stmt_att->fetchColumn();
        } catch (Exception $e) {}

        if ($student_count > 0) {
            // STRICT RULE: Deletion is blocked because student activity exists
            echo json_encode([
                'success' => true,
                'deletable' => false,
                'error_code' => 'ACTIVITY_HAS_STUDENT_DATA',
                'student_count' => $student_count,
                'activity_id' => $activity_id,
                'activity_uid' => $act['activity_uid'],
                'activity_title' => $act['activity_title'],
                'day_number' => (int)$act['day_number'],
                'activity_date' => $act['activity_date'],
                'message' => "This activity cannot be deleted because student activity ({$student_count} record(s)) has already been recorded."
            ]);
            exit();
        }

        // Generate confirmation token for safe deletable activity
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
            'deletable' => true,
            'student_count' => 0,
            'activity_id' => $activity_id,
            'activity_uid' => $act['activity_uid'],
            'activity_title' => $act['activity_title'],
            'day_number' => (int)$act['day_number'],
            'activity_date' => $act['activity_date'],
            'confirmation_token' => $token
        ]);
        exit();
    }

    if ($action === 'delete_activity') {
        $activity_id = (int)($_POST['activity_id'] ?? 0);
        $token = trim($_POST['confirmation_token'] ?? '');

        if ($activity_id <= 0 || empty($token)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters for deletion']);
            exit();
        }

        $token_data = $_SESSION['delete_tokens'][$activity_id] ?? null;
        if (!$token_data || $token_data['token'] !== $token || $token_data['expires_at'] < time()) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired confirmation token. Please try again.']);
            exit();
        }

        $plan_id = (int)$token_data['study_plan_id'];

        // Edit Lock Enforcement — Checked BEFORE transaction
        if ($plan_id > 0 && !verify_study_plan_edit_lock_permission($pdo, $plan_id, $admin_username, $admin_id)) {
            echo json_encode([
                'success' => false,
                'error_code' => 'EDIT_LOCK_HELD',
                'message' => 'This Study Plan is currently locked for editing by another administrator.'
            ]);
            exit();
        }

        // Fetch activity before transaction
        $stmt_check = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ? AND study_plan_id = ?");
        $stmt_check->execute([$activity_id, $plan_id]);
        $act = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$act || $act['activity_uid'] !== $token_data['activity_uid']) {
            echo json_encode(['success' => false, 'message' => 'Activity validation failed.']);
            exit();
        }

        if ((int)$act['is_deleted'] === 1) {
            echo json_encode(['success' => false, 'message' => 'Activity has already been deleted.']);
            exit();
        }

        $act_uid = trim($act['activity_uid'] ?? '');

        // Authoritative Database check: Verify ZERO student activity before deleting
        $current_student_count = 0;
        try {
            $stmt_cnt = $pdo->prepare("
                SELECT COUNT(*)
                FROM study_plan_analytics
                WHERE study_plan_id = ?
                  AND (
                      (activity_uid = ? AND ? != '')
                      OR (activity_id = ? AND ? > 0)
                  )
            ");
            $stmt_cnt->execute([$plan_id, $act_uid, $act_uid, $activity_id, $activity_id]);
            $current_student_count += (int)$stmt_cnt->fetchColumn();
        } catch (Exception $e) {}

        try {
            $stmt_att = $pdo->prepare("
                SELECT COUNT(*)
                FROM assessment_results ar
                JOIN assessment_result_batches arb ON ar.batch_id = arb.id
                WHERE arb.activity_id = ? AND (arb.is_deleted IS NULL OR arb.is_deleted = 0)
            ");
            $stmt_att->execute([$activity_id]);
            $current_student_count += (int)$stmt_att->fetchColumn();
        } catch (Exception $e) {}

        if ($current_student_count > 0) {
            unset($_SESSION['delete_tokens'][$activity_id]);
            echo json_encode([
                'success' => false,
                'error_code' => 'ACTIVITY_HAS_STUDENT_DATA',
                'student_count' => $current_student_count,
                'message' => 'This activity cannot be deleted because student activity has already been recorded.'
            ]);
            exit();
        }

        // Optimistic concurrency protection
        $client_version = isset($_POST['version']) ? (int)$_POST['version'] : 0;
        $stmt_ver = $pdo->prepare("SELECT version FROM study_plans WHERE id = ?");
        $stmt_ver->execute([$plan_id]);
        $db_version = $stmt_ver->fetchColumn();

        if ($db_version !== false) {
            $db_version = (int)$db_version;
            if ($client_version > 0 && $client_version !== $db_version) {
                echo json_encode([
                    'success' => false,
                    'error_code' => 'STALE_STUDY_PLAN',
                    'message' => 'This study plan was updated by another administrator. Please reload the latest version before saving your changes.'
                ]);
                exit();
            }
        }

        try {
            $pdo->beginTransaction();

            // Log pre-deleted state
            log_activity_version($pdo, $activity_id, 'delete', $admin_username);

            // Perform soft delete
            $reason = trim($_POST['deletion_reason'] ?? 'Admin deleted');
            $stmt_del = $pdo->prepare("UPDATE study_plan_activities SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, deletion_reason = ? WHERE id = ? AND study_plan_id = ?");
            $stmt_del->execute([$admin_username, $reason, $activity_id, $plan_id]);

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
            echo json_encode(['success' => true, 'version' => $new_ver, 'deleted_id' => $activity_id, 'deleted_uid' => $act['activity_uid']]);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Exception $rbEx) {}
            }
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'bulk_delete_activities') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data) {
            $data = $_POST;
        }

        $plan_id = (int)($data['study_plan_id'] ?? 0);
        $client_version = (int)($data['version'] ?? 0);
        $reason = trim($data['deletion_reason'] ?? 'Admin bulk delete');
        $activity_ids = $data['activity_ids'] ?? [];
        $activity_uids = $data['activity_uids'] ?? [];

        if ($plan_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Study Plan ID.']);
            exit();
        }

        if (empty($activity_ids) && empty($activity_uids)) {
            echo json_encode(['success' => false, 'message' => 'No activities selected for deletion.']);
            exit();
        }

        // 1. Edit Lock Enforcement — Checked BEFORE transaction
        if (!verify_study_plan_edit_lock_permission($pdo, $plan_id, $admin_username, $admin_id)) {
            echo json_encode([
                'success' => false,
                'error_code' => 'EDIT_LOCK_HELD',
                'message' => 'This Study Plan is currently locked for editing by another administrator.'
            ]);
            exit();
        }

        // 2. Optimistic Concurrency Check
        $stmt_ver = $pdo->prepare("SELECT version FROM study_plans WHERE id = ?");
        $stmt_ver->execute([$plan_id]);
        $db_version = $stmt_ver->fetchColumn();

        if ($db_version !== false) {
            $db_version = (int)$db_version;
            if ($client_version > 0 && $client_version !== $db_version) {
                echo json_encode([
                    'success' => false,
                    'error_code' => 'STALE_STUDY_PLAN',
                    'message' => 'This study plan was updated by another administrator. Please reload before modifying.'
                ]);
                exit();
            }
        }

        // 3. Fetch all requested activities belonging strictly to this Study Plan
        $id_list = array_values(array_filter(array_map('intval', (array)$activity_ids), function($v) { return $v > 0; }));
        $uid_list = array_values(array_filter(array_map('trim', (array)$activity_uids), function($v) { return !empty($v); }));

        $where_clauses = [];
        $params = [$plan_id];

        if (!empty($id_list)) {
            $id_placeholders = implode(',', array_fill(0, count($id_list), '?'));
            $where_clauses[] = "id IN ($id_placeholders)";
            foreach ($id_list as $id_val) $params[] = $id_val;
        }
        if (!empty($uid_list)) {
            $uid_placeholders = implode(',', array_fill(0, count($uid_list), '?'));
            $where_clauses[] = "activity_uid IN ($uid_placeholders)";
            foreach ($uid_list as $uid_val) $params[] = $uid_val;
        }

        if (empty($where_clauses)) {
            echo json_encode(['success' => false, 'message' => 'No valid activities found to delete.']);
            exit();
        }

        $sql_fetch = "SELECT * FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0 AND (" . implode(' OR ', $where_clauses) . ")";
        $stmt_act = $pdo->prepare($sql_fetch);
        $stmt_act->execute($params);
        $target_activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

        if (empty($target_activities)) {
            echo json_encode([
                'success' => true,
                'deleted_count' => 0,
                'protected_count' => 0,
                'deleted_ids' => [],
                'deleted_uids' => [],
                'protected_activities' => [],
                'message' => 'No active matching activities found for deletion.'
            ]);
            exit();
        }

        // 4. Batch query student activity / completion counts from study_plan_analytics and assessment_results
        $target_uids = [];
        $target_ids = [];
        foreach ($target_activities as $act) {
            if (!empty($act['activity_uid'])) {
                $target_uids[] = $act['activity_uid'];
            }
            if (!empty($act['id'])) {
                $target_ids[] = (int)$act['id'];
            }
        }

        $analytics_conditions = [];
        $an_params = [$plan_id];

        if (!empty($target_uids)) {
            $u_place = implode(',', array_fill(0, count($target_uids), '?'));
            $analytics_conditions[] = "activity_uid IN ($u_place)";
            foreach ($target_uids as $u) $an_params[] = $u;
        }
        if (!empty($target_ids)) {
            $i_place = implode(',', array_fill(0, count($target_ids), '?'));
            $analytics_conditions[] = "activity_id IN ($i_place)";
            foreach ($target_ids as $i) $an_params[] = $i;
        }

        $counts_by_uid = [];
        $counts_by_id = [];

        if (!empty($analytics_conditions)) {
            try {
                $sql_an = "
                    SELECT activity_uid, activity_id, COUNT(*) AS student_cnt
                    FROM study_plan_analytics
                    WHERE study_plan_id = ?
                      AND (" . implode(' OR ', $analytics_conditions) . ")
                    GROUP BY activity_uid, activity_id
                ";
                $stmt_an = $pdo->prepare($sql_an);
                $stmt_an->execute($an_params);
                $an_rows = $stmt_an->fetchAll(PDO::FETCH_ASSOC);

                foreach ($an_rows as $row) {
                    $cnt = (int)$row['student_cnt'];
                    if (!empty($row['activity_uid'])) {
                        $counts_by_uid[$row['activity_uid']] = ($counts_by_uid[$row['activity_uid']] ?? 0) + $cnt;
                    }
                    if (!empty($row['activity_id'])) {
                        $counts_by_id[(int)$row['activity_id']] = ($counts_by_id[(int)$row['activity_id']] ?? 0) + $cnt;
                    }
                }
            } catch (Exception $e) {}
        }

        // Assessment results batch query
        $assessment_counts_by_id = [];
        if (!empty($target_ids)) {
            try {
                $id_place = implode(',', array_fill(0, count($target_ids), '?'));
                $sql_ass = "
                    SELECT arb.activity_id, COUNT(*) AS assessment_cnt
                    FROM assessment_results ar
                    JOIN assessment_result_batches arb ON ar.batch_id = arb.id
                    WHERE arb.activity_id IN ($id_place) AND (arb.is_deleted IS NULL OR arb.is_deleted = 0)
                    GROUP BY arb.activity_id
                ";
                $stmt_ass = $pdo->prepare($sql_ass);
                $stmt_ass->execute($target_ids);
                $ass_rows = $stmt_ass->fetchAll(PDO::FETCH_ASSOC);
                foreach ($ass_rows as $arow) {
                    $assessment_counts_by_id[(int)$arow['activity_id']] = (int)$arow['assessment_cnt'];
                }
            } catch (Exception $e) {}
        }

        // 5. Partition activities into Deletable (0 student data) vs Protected (>0 student data)
        $deletable = [];
        $protected = [];

        foreach ($target_activities as $act) {
            $uid = $act['activity_uid'] ?? '';
            $id = (int)$act['id'];

            $cnt = 0;
            if (!empty($uid) && isset($counts_by_uid[$uid])) {
                $cnt += $counts_by_uid[$uid];
            }
            if (!empty($id) && isset($counts_by_id[$id])) {
                $cnt += $counts_by_id[$id];
            }
            if (!empty($id) && isset($assessment_counts_by_id[$id])) {
                $cnt += $assessment_counts_by_id[$id];
            }

            if ($cnt > 0) {
                $protected[] = [
                    'id' => $id,
                    'activity_uid' => $uid,
                    'activity_title' => $act['activity_title'],
                    'day_number' => (int)$act['day_number'],
                    'activity_date' => $act['activity_date'],
                    'student_count' => $cnt,
                    'reason' => "Student activity recorded ({$cnt} record(s))"
                ];
            } else {
                $deletable[] = $act;
            }
        }

        // If ALL selected activities are protected, no mutation or transaction needed
        if (empty($deletable)) {
            echo json_encode([
                'success' => true,
                'deleted_count' => 0,
                'protected_count' => count($protected),
                'deleted_ids' => [],
                'deleted_uids' => [],
                'protected_activities' => $protected,
                'version' => ($db_version !== false) ? $db_version : 1,
                'message' => "0 activities deleted. All " . count($protected) . " selected activity(ies) have recorded student data and cannot be deleted."
            ]);
            exit();
        }

        // 6. Execute bulk deletion of deletable activities in ONE atomic transaction
        try {
            $pdo->beginTransaction();

            $deletable_ids = array_map(function($a) { return (int)$a['id']; }, $deletable);
            $deletable_uids = array_map(function($a) { return $a['activity_uid']; }, $deletable);

            // Log activity versions
            foreach ($deletable_ids as $del_id) {
                log_activity_version($pdo, $del_id, 'delete', $admin_username);
            }

            // Perform batch soft-delete
            $in_placeholders = implode(',', array_fill(0, count($deletable_ids), '?'));
            $del_sql = "UPDATE study_plan_activities SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, deletion_reason = ? WHERE id IN ($in_placeholders) AND study_plan_id = ?";
            $stmt_del = $pdo->prepare($del_sql);
            $stmt_del->execute(array_merge([$admin_username, $reason], $deletable_ids, [$plan_id]));

            // Bump version
            $pdo->prepare("UPDATE study_plans SET version = version + 1, updated_at = NOW() WHERE id = ?")->execute([$plan_id]);

            // Single Audit Log record
            $count_del = count($deletable);
            $count_prot = count($protected);
            $audit_msg = "Bulk soft-deleted {$count_del} activity(ies). Protected {$count_prot} activity(ies) due to student data. Reason: {$reason}";
            $stmt_audit = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, 'bulk_delete_activities', ?)");
            $stmt_audit->execute([$plan_id, $admin_username, $audit_msg]);

            $pdo->commit();

            $new_version = ($db_version !== false) ? $db_version + 1 : 1;
            $msg = "{$count_del} activity(ies) deleted successfully.";
            if ($count_prot > 0) {
                $msg .= " {$count_prot} activity(ies) were protected because student activity exists.";
            }

            echo json_encode([
                'success' => true,
                'deleted_count' => $count_del,
                'protected_count' => $count_prot,
                'deleted_ids' => $deletable_ids,
                'deleted_uids' => $deletable_uids,
                'protected_activities' => $protected,
                'version' => $new_version,
                'message' => $msg
            ]);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Exception $rbEx) {}
            }
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
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
