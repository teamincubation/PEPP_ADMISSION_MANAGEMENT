<?php
/**
 * PEPP Learning — Task Reminders AJAX Endpoint
 * Provides authenticated, CSRF & IDOR protected services for Task Reminders.
 * Strictly NO delete endpoints.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reminders_helper.php';

try {
    // Authentication Check
    if (!is_admin_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Please log in to access task reminders.']);
        exit;
    }

    $current_username = get_admin_user();
    $admin_identity = task_reminder_get_admin_identity($pdo, $current_username);
    $current_admin_id = $admin_identity['id'];
    $is_super = is_super_admin();

    $action = trim($_GET['action'] ?? $_POST['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Explicit Protection: Zero Delete API
    if ($method === 'DELETE' || in_array(strtolower($action), ['delete', 'delete_task', 'delete_reminder', 'remove_task', 'remove_reminder'], true)) {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Task Reminders cannot be deleted. All records and history are permanent.']);
        exit;
    }

    // CSRF validation for modifying requests
    if ($method === 'POST') {
        $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verify_csrf_token($csrf_token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Please refresh the page.']);
            exit;
        }
    }

    switch ($action) {
        // 1. Lightweight Polling Summary (<2ms)
        case 'get_summary':
            $summary = task_reminders_get_summary($pdo, $current_admin_id, $current_username);
            echo json_encode(['success' => true, 'summary' => $summary]);
            exit;

        // 2. Authoritative Due Alert Revalidation
        case 'verify_due_alert':
            $task_id = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
            if ($task_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid task ID.']);
                exit;
            }
            $task = task_reminders_verify_due_alert($pdo, $task_id, $current_admin_id, $current_username);
            if ($task) {
                echo json_encode(['success' => true, 'valid' => true, 'task' => $task]);
            } else {
                echo json_encode(['success' => true, 'valid' => false, 'message' => 'Task is no longer due or assigned to you.']);
            }
            exit;

        // 3. Unread Notifications for Current Admin
        case 'get_unread_notifications':
            $notifications = task_reminders_get_unread_notifications($pdo, $current_admin_id, $current_username);
            echo json_encode(['success' => true, 'notifications' => $notifications]);
            exit;

        // 4. List My Tasks (Tab 1)
        case 'list_my_tasks':
            $filters = [
                'status' => $_GET['status'] ?? '',
                'task_type_id' => $_GET['task_type_id'] ?? '',
                'search' => $_GET['search'] ?? ''
            ];
            $tasks = task_reminders_list_my_tasks($pdo, $current_admin_id, $current_username, $filters);
            echo json_encode(['success' => true, 'tasks' => $tasks]);
            exit;

        // 5. List Assigned by Me (Tab 2 — Admin A monitoring Admin B)
        case 'list_assigned_by_me':
            $filters = [
                'status' => $_GET['status'] ?? '',
                'assigned_to_username' => $_GET['assigned_to_username'] ?? '',
                'task_type_id' => $_GET['task_type_id'] ?? '',
                'search' => $_GET['search'] ?? '',
                'all_assigned' => !empty($_GET['all_assigned'])
            ];
            $tasks = task_reminders_list_assigned_by_me($pdo, $current_admin_id, $current_username, $filters, $is_super);
            echo json_encode(['success' => true, 'tasks' => $tasks]);
            exit;

        // 6. List History (Tab 3 — Comprehensive lifecycle timeline)
        case 'list_history':
            $filters = [
                'event_type' => $_GET['event_type'] ?? '',
                'task_id' => $_GET['task_id'] ?? '',
                'admin' => $_GET['admin'] ?? '',
                'date_from' => $_GET['date_from'] ?? '',
                'date_to' => $_GET['date_to'] ?? ''
            ];
            $limit = min(200, max(10, (int)($_GET['limit'] ?? 100)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $history = task_reminders_list_history($pdo, $filters, $limit, $offset);
            echo json_encode(['success' => true, 'history' => $history]);
            exit;

        // 7. Get Task Details & Timeline (Strict IDOR Protected)
        case 'get_details':
            $task_id = (int)($_GET['task_id'] ?? 0);
            if ($task_id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid task ID.']);
                exit;
            }
            $details = task_reminders_get_details($pdo, $task_id, $current_admin_id, $current_username, $is_super);
            if (!$details) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Task not found or access denied.']);
                exit;
            }
            echo json_encode(['success' => true, 'details' => $details]);
            exit;

        // 8. Create Task (POST, Mandatory Task Type)
        case 'create_task':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                exit;
            }
            $res = task_reminders_create($pdo, $_POST, $current_admin_id, $current_username);
            if ($res['success']) {
                echo json_encode($res);
            } else {
                http_response_code(422);
                echo json_encode($res);
            }
            exit;

        // 9. Edit Task Details (POST, Creator / Super Admin)
        case 'edit_task':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                exit;
            }
            $task_id = (int)($_POST['task_id'] ?? 0);
            $res = task_reminders_edit($pdo, $task_id, $_POST, $current_admin_id, $current_username, $is_super);
            if ($res['success']) {
                echo json_encode($res);
            } else {
                http_response_code(422);
                echo json_encode($res);
            }
            exit;

        // 10. Update Status (POST, Start, Complete, Cancel)
        case 'update_status':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                exit;
            }
            $task_id = (int)($_POST['task_id'] ?? 0);
            $new_status = trim($_POST['status'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');
            $res = task_reminders_update_status($pdo, $task_id, $new_status, $remarks, $current_admin_id, $current_username, $is_super);
            if ($res['success']) {
                echo json_encode($res);
            } else {
                http_response_code(422);
                echo json_encode($res);
            }
            exit;

        // 11. Postpone Task (POST, Presets & Custom)
        case 'postpone':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                exit;
            }
            $task_id = (int)($_POST['task_id'] ?? 0);
            $new_remind_at = trim($_POST['remind_at'] ?? '');
            $preset = trim($_POST['preset'] ?? '');
            $reason = trim($_POST['reason'] ?? '');

            if ($preset) {
                $now = time();
                if ($preset === '+15m') {
                    $new_remind_at = date('Y-m-d H:i:s', $now + (15 * 60));
                } elseif ($preset === '+30m') {
                    $new_remind_at = date('Y-m-d H:i:s', $now + (30 * 60));
                } elseif ($preset === '+1h') {
                    $new_remind_at = date('Y-m-d H:i:s', $now + (60 * 60));
                } elseif ($preset === 'tomorrow') {
                    $new_remind_at = date('Y-m-d 09:00:00', strtotime('+1 day'));
                }
            }

            $res = task_reminders_postpone($pdo, $task_id, $new_remind_at, $reason, $current_admin_id, $current_username, $is_super);
            if ($res['success']) {
                echo json_encode($res);
            } else {
                http_response_code(422);
                echo json_encode($res);
            }
            exit;

        // 12. Reassign Task (POST, Creator / Super Admin)
        case 'reassign':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                exit;
            }
            $task_id = (int)($_POST['task_id'] ?? 0);
            $new_assignee = trim($_POST['assigned_to'] ?? '');
            $res = task_reminders_reassign($pdo, $task_id, $new_assignee, $current_admin_id, $current_username, $is_super);
            if ($res['success']) {
                echo json_encode($res);
            } else {
                http_response_code(422);
                echo json_encode($res);
            }
            exit;

        // 13. Dismiss Notification
        case 'dismiss_notification':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                exit;
            }
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            $ok = task_reminders_dismiss_notification($pdo, $notification_id, $current_admin_id, $current_username);
            echo json_encode(['success' => $ok]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
            exit;
    }
} catch (Throwable $e) {
    error_log("api/task-reminders.php error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing task reminders.']);
    exit;
}
