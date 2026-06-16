<?php
require_once 'includes/auth.php';
require_once 'includes/reminders_helper.php';

/* Reminder actions: add / complete / dismiss / postpone.
   Any logged-in admin can manage reminders assigned to them or to all admins. */

$return = $_POST['return'] ?? 'dashboard.php';
// Only allow same-site relative returns
if (!preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $return) || strpos($return, '//') !== false) {
    $return = 'dashboard.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: ' . $return); exit();
}
if (!reminders_table_exists($pdo)) {
    header('Location: ' . $return); exit();
}

$action = $_POST['action'] ?? '';
$flag = '';

/** May the current admin act on this reminder? (assignee, all-admins, super, or creator) */
function can_manage_reminder($pdo, $id, $admin) {
    $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) return false;
    if (is_super_admin()) return $r;
    if ($r['assigned_to'] === $admin || $r['assigned_to'] === '__ALL__' || $r['created_by'] === $admin) return $r;
    return false;
}

try {
    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $when  = $_POST['remind_at'] ?? '';
        $assigned = $_POST['assigned_to'] ?? $admin_username;

        // Non-super admins can only assign to themselves or all admins
        if (!is_super_admin() && $assigned !== '__ALL__') {
            $assigned = $admin_username;
        }
        // datetime-local sends 'YYYY-MM-DDTHH:MM'; normalise the T just in case
        $when = str_replace('T', ' ', trim($when));
        $ts = strtotime($when);
        if ($title !== '' && $ts) {
            $stmt = $pdo->prepare("INSERT INTO reminders (title, notes, remind_at, assigned_to, status, created_by, created_at)
                                   VALUES (?, ?, ?, ?, 'pending', ?, NOW())");
            $stmt->execute([$title, $notes ?: null, date('Y-m-d H:i:s', $ts), $assigned, $admin_username]);
            log_admin_activity($pdo, $admin_username, 'reminder_created',
                "Reminder \"{$title}\" for " . date('d M Y H:i', $ts) . ' → ' . ($assigned === '__ALL__' ? 'All Admins' : $assigned));
            $flag = 'rem_added';
        } else {
            $flag = 'rem_error';
        }
    } elseif ($action === 'complete') {
        $id = (int)($_POST['id'] ?? 0);
        if (can_manage_reminder($pdo, $id, $admin_username)) {
            $pdo->prepare("UPDATE reminders SET status = 'completed', completed_by = ?, completed_at = NOW() WHERE id = ?")
                ->execute([$admin_username, $id]);
            log_admin_activity($pdo, $admin_username, 'reminder_completed', "Completed reminder #{$id}");
            $flag = 'rem_done';
        }
    } elseif ($action === 'dismiss') {
        $id = (int)($_POST['id'] ?? 0);
        if (can_manage_reminder($pdo, $id, $admin_username)) {
            $pdo->prepare("UPDATE reminders SET status = 'dismissed', completed_by = ?, completed_at = NOW() WHERE id = ?")
                ->execute([$admin_username, $id]);
            log_admin_activity($pdo, $admin_username, 'reminder_dismissed', "Dismissed reminder #{$id}");
            $flag = 'rem_dismissed';
        }
    } elseif ($action === 'postpone') {
        $id = (int)($_POST['id'] ?? 0);
        $when = str_replace('T', ' ', trim($_POST['remind_at'] ?? ''));
        $ts = strtotime($when);
        if ($ts && can_manage_reminder($pdo, $id, $admin_username)) {
            // Reset email_sent so the new due time re-notifies
            $pdo->prepare("UPDATE reminders SET remind_at = ?, snooze_until = NULL, email_sent = 0, status = 'pending' WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', $ts), $id]);
            log_admin_activity($pdo, $admin_username, 'reminder_postponed', "Postponed reminder #{$id} to " . date('d M Y H:i', $ts));
            $flag = 'rem_postponed';
        }
    } elseif ($action === 'skip5') {
        // "Skip to 5 minutes" - snooze the popup for 5 minutes (keeps it pending)
        $id = (int)($_POST['id'] ?? 0);
        if (can_manage_reminder($pdo, $id, $admin_username)) {
            $pdo->prepare("UPDATE reminders SET snooze_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?")->execute([$id]);
            log_admin_activity($pdo, $admin_username, 'reminder_snoozed', "Skipped reminder #{$id} for 5 minutes");
            $flag = 'rem_skipped';
        }
    }
} catch (Exception $e) {
    error_log('reminders-action: ' . $e->getMessage());
}

$sep = (strpos($return, '?') !== false) ? '&' : '?';
if (!empty($flag)) { $return .= $sep . 'msg=' . $flag; }
header('Location: ' . $return);
exit();
