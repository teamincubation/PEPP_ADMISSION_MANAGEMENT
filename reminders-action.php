<?php
/**
 * PEPP Learning — Task Reminders Form Fallback Handler
 * Handles standard non-AJAX POST forms with CSRF protection and full lifecycle logging.
 */

require_once 'includes/auth.php';
require_once 'includes/reminders_helper.php';

$return = $_POST['return'] ?? 'task-reminders.php';
// Only allow same-site relative returns
if (!preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $return) || strpos($return, '//') !== false) {
    $return = 'task-reminders.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: ' . $return);
    exit();
}

$admin_username = get_admin_user();
$admin_identity = task_reminder_get_admin_identity($pdo, $admin_username);
$admin_id = $admin_identity['id'];
$is_super = is_super_admin();

$action = trim($_POST['action'] ?? '');
$flag = '';

try {
    if ($action === 'add' || $action === 'create') {
        $task_type_id = (int)($_POST['task_type_id'] ?? 0);
        if ($task_type_id <= 0) {
            // Fallback to General Task if none provided in legacy form
            $generalTypeId = (int)$pdo->query("SELECT id FROM task_reminder_types WHERE name = 'General Task' LIMIT 1")->fetchColumn();
            $task_type_id = $generalTypeId > 0 ? $generalTypeId : 1;
        }

        $res = task_reminders_create($pdo, [
            'task_type_id' => $task_type_id,
            'title' => $_POST['title'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'remind_at' => $_POST['remind_at'] ?? '',
            'assigned_to' => $_POST['assigned_to'] ?? $admin_username
        ], $admin_id, $admin_username);

        $flag = $res['success'] ? 'rem_added' : 'rem_error';
    } elseif ($action === 'complete') {
        $id = (int)($_POST['id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? 'Marked complete via form');
        $res = task_reminders_update_status($pdo, $id, 'completed', $remarks, $admin_id, $admin_username, $is_super);
        $flag = $res['success'] ? 'rem_done' : 'rem_error';
    } elseif ($action === 'dismiss' || $action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? 'Dismissed / Cancelled via form');
        $res = task_reminders_update_status($pdo, $id, 'cancelled', $remarks, $admin_id, $admin_username, $is_super);
        $flag = $res['success'] ? 'rem_dismissed' : 'rem_error';
    } elseif ($action === 'postpone') {
        $id = (int)($_POST['id'] ?? 0);
        $when = str_replace('T', ' ', trim($_POST['remind_at'] ?? ''));
        $reason = trim($_POST['reason'] ?? 'Postponed via form');
        $res = task_reminders_postpone($pdo, $id, $when, $reason, $admin_id, $admin_username, $is_super);
        $flag = $res['success'] ? 'rem_postponed' : 'rem_error';
    }
} catch (Exception $e) {
    error_log('reminders-action: ' . $e->getMessage());
    $flag = 'rem_error';
}

$sep = (strpos($return, '?') !== false) ? '&' : '?';
if (!empty($flag)) {
    $return .= $sep . 'msg=' . $flag;
}
header('Location: ' . $return);
exit();
