<?php
/**
 * PEPP ERP Task Reminders — Sidebar Badges, Soft Delete & Interaction Audit Suite
 * Tests all 4 new requirements in isolated in-memory environment.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "====================================================================\n";
echo "PEPP ERP Task Reminders — Sidebar Badges, Soft Delete & Interaction Audit\n";
echo "====================================================================\n\n";

require_once __DIR__ . '/includes/reminders_helper.php';

// Setup isolated SQLite memory database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

task_reminders_ensure_schema($pdo);

// Seed Mock Admins
$pdo->exec("
    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(100) NOT NULL UNIQUE,
        full_name VARCHAR(100) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'admin',
        status VARCHAR(50) NOT NULL DEFAULT 'active'
    );
    INSERT INTO admins (id, username, full_name, role, status) VALUES
    (1, 'superadmin', 'Super Admin User', 'superadmin', 'active'),
    (2, 'admin_a', 'Admin Alice', 'admin', 'active'),
    (3, 'admin_b', 'Admin Bob', 'admin', 'active'),
    (4, 'admin_c', 'Admin Charlie', 'admin', 'active');
");

// Seed Task Types
$pdo->exec("
    INSERT INTO task_reminder_types (name, description, is_active) VALUES
    ('Fee Follow-up', 'Fee installment call', 1),
    ('Document Verification', 'Verify documents', 1),
    ('Parent Counseling', 'Parent counseling session', 1);
");

// Track test assertions
$passed = 0;
$failed = 0;

function assert_true($cond, $desc) {
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] $desc\n";
        $passed++;
    } else {
        echo "[FAIL] $desc\n";
        $failed++;
    }
}

function assert_equals($actual, $expected, $desc) {
    global $passed, $failed;
    if ($actual === $expected) {
        echo "[PASS] $desc (Value: " . json_encode($actual) . ")\n";
        $passed++;
    } else {
        echo "[FAIL] $desc (Expected: " . json_encode($expected) . ", Got: " . json_encode($actual) . ")\n";
        $failed++;
    }
}

// -------------------------------------------------------------------------
// Requirement 1: Sidebar Task Reminder Badges (Red Actionable & Green Completed)
// -------------------------------------------------------------------------
echo "\n--- [TEST GROUP 1] Sidebar Badges (Actionable RED & Completed GREEN) ---\n";

// Scenario: Admin A creates Task 1 assigned to Admin B
$resCreate = task_reminders_create($pdo, [
    'title' => 'Follow up with Student Rahul',
    'task_type_id' => 1,
    'assigned_to' => 'admin_b',
    'remind_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
    'notes' => 'Discuss installment payment schedule'
], 2, 'admin_a', false);

assert_true($resCreate['success'], "Admin A successfully creates Task 1 assigned to Admin B");
$task1Id = (int)$resCreate['task_id'];

// Check summaries:
// Admin B should have actionable_count = 1, unread_completions_count = 0
$sumB = task_reminders_get_summary($pdo, 3, 'admin_b', false);
assert_equals($sumB['actionable_count'], 1, "Admin B actionable_count is 1 (pending assigned task)");
assert_equals($sumB['pending_count'], 1, "Admin B pending_count is 1");
assert_equals($sumB['unread_completions_count'], 0, "Admin B unread_completions_count is 0");

// Admin A (assigner) should have actionable_count = 0, assigned_by_me_pending = 1, unread_completions_count = 0
$sumA = task_reminders_get_summary($pdo, 2, 'admin_a', false);
assert_equals($sumA['actionable_count'], 0, "Admin A actionable_count is 0 (task is not assigned to Admin A)");
assert_equals($sumA['assigned_by_me_pending'], 1, "Admin A delegated monitoring count is 1");
assert_equals($sumA['unread_completions_count'], 0, "Admin A unread_completions_count is 0");

// Super Admin should have actionable_count = 0 (not assigned to Super Admin)
$sumSuper = task_reminders_get_summary($pdo, 1, 'superadmin', true);
assert_equals($sumSuper['actionable_count'], 0, "Super Admin actionable_count is 0 when task is assigned to Admin B");
assert_equals($sumSuper['assigned_by_me_pending'], 1, "Super Admin global monitoring count is 1");

// Admin B starts work (in_progress)
$resProg = task_reminders_update_status($pdo, $task1Id, 'in_progress', 'Started working on call', 3, 'admin_b', false);
assert_true($resProg['success'], "Admin B updates Task 1 to in_progress");

$sumB = task_reminders_get_summary($pdo, 3, 'admin_b', false);
assert_equals($sumB['actionable_count'], 1, "Admin B actionable_count is still 1 during in_progress");
assert_equals($sumB['in_progress_count'], 1, "Admin B in_progress_count is 1");
assert_equals($sumB['pending_count'], 0, "Admin B pending_count is 0");

// Admin B completes Task 1
$resComp = task_reminders_update_status($pdo, $task1Id, 'completed', 'Student agreed to pay on Friday', 3, 'admin_b', false);
assert_true($resComp['success'], "Admin B marks Task 1 as completed");

// Admin B's actionable badge should now be 0 (disappears)
$sumB = task_reminders_get_summary($pdo, 3, 'admin_b', false);
assert_equals($sumB['actionable_count'], 0, "Admin B actionable_count drops to 0 after completion");
assert_equals($sumB['unread_completions_count'], 0, "Admin B unread_completions_count remains 0");

// Admin A (assigner) should now receive GREEN badge (unread_completions_count = 1)
$sumA = task_reminders_get_summary($pdo, 2, 'admin_a', false);
assert_equals($sumA['unread_completions_count'], 1, "Admin A receives 1 unread completion notification (GREEN badge)");
assert_equals($sumA['actionable_count'], 0, "Admin A actionable_count remains 0");

// Unrelated Admin C should have 0 unread completions
$sumC = task_reminders_get_summary($pdo, 4, 'admin_c', false);
assert_equals($sumC['unread_completions_count'], 0, "Admin C has 0 unread completion notifications");

// Super Admin (who was not assigner) should have 0 unread completions
$sumSuper = task_reminders_get_summary($pdo, 1, 'superadmin', true);
assert_equals($sumSuper['unread_completions_count'], 0, "Super Admin has 0 unread completion notifications when not actual assigner");

// Admin A visits task-reminders.php (marks TASK_COMPLETED notifications as read)
$resRead = task_reminders_mark_notifications_read($pdo, 2, 'admin_a', 'TASK_COMPLETED');
assert_true($resRead, "task_reminders_mark_notifications_read succeeds for Admin A");

$sumA_after = task_reminders_get_summary($pdo, 2, 'admin_a', false);
assert_equals($sumA_after['unread_completions_count'], 0, "Admin A unread_completions_count cleared to 0 after visiting page");

// -------------------------------------------------------------------------
// Requirement 2: Super Admin Soft Delete
// -------------------------------------------------------------------------
echo "\n--- [TEST GROUP 2] Super Admin Soft Delete ---\n";

// Create Task 2
$resCreate2 = task_reminders_create($pdo, [
    'title' => 'Document Verification Task 2',
    'task_type_id' => 2,
    'assigned_to' => 'admin_b',
    'remind_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
    'notes' => 'Original task notes'
], 2, 'admin_a', false);
$task2Id = (int)$resCreate2['task_id'];

// Normal Admin A attempts to delete Task 2 -> MUST BE REJECTED
$resDelAdminA = task_reminders_delete($pdo, $task2Id, 'Attempted deletion', 2, 'admin_a', false);
assert_true(!$resDelAdminA['success'], "Normal Admin A is blocked from deleting tasks (Unauthorized)");

// Normal Admin B attempts to delete Task 2 -> MUST BE REJECTED
$resDelAdminB = task_reminders_delete($pdo, $task2Id, 'Attempted deletion', 3, 'admin_b', false);
assert_true(!$resDelAdminB['success'], "Normal Admin B is blocked from deleting tasks (Unauthorized)");

// Super Admin soft-deletes Task 2 with reason
$resDelSuper = task_reminders_delete($pdo, $task2Id, 'Duplicate task created by mistake', 1, 'superadmin', true);
assert_true($resDelSuper['success'], "Super Admin successfully soft-deletes Task 2");

// Verify DB row was NOT physically removed
$stmtChk = $pdo->prepare("SELECT * FROM reminders WHERE id = ?");
$stmtChk->execute([$task2Id]);
$row2 = $stmtChk->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($row2), "Task 2 row still physically exists in database (zero-delete preserved)");
assert_equals($row2['status'], 'deleted', "Task 2 status is updated to 'deleted'");
assert_true(!empty($row2['deleted_at']), "Task 2 deleted_at timestamp is populated");
assert_equals((int)$row2['deleted_by_admin_id'], 1, "Task 2 deleted_by_admin_id is 1");
assert_equals($row2['deleted_by_username'], 'superadmin', "Task 2 deleted_by_username is 'superadmin'");

// Verify permanent audit trail history record
$stmtHist = $pdo->prepare("SELECT * FROM task_reminder_status_history WHERE task_id = ? AND event_type = 'DELETED'");
$stmtHist->execute([$task2Id]);
$histRow = $stmtHist->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($histRow), "Permanent DELETED event record exists in task_reminder_status_history");
assert_equals($histRow['changed_by_username'], 'superadmin', "History event records superadmin as actor");
assert_true(strpos($histRow['remarks'], 'Duplicate task') !== false, "History event records Super Admin deletion reason");

// Verify soft-deleted task is excluded from operational queries
$myTasksB = task_reminders_list_my_tasks($pdo, 3, 'admin_b', ['status' => 'all'], false);
$hasTask2InMyTasks = false;
foreach ($myTasksB as $t) { if ((int)$t['id'] === $task2Id) $hasTask2InMyTasks = true; }
assert_true(!$hasTask2InMyTasks, "Deleted Task 2 is completely excluded from list_my_tasks");

$assignedA = task_reminders_list_assigned_by_me($pdo, 2, 'admin_a', ['status' => 'all'], false);
$hasTask2InAssigned = false;
foreach ($assignedA as $t) { if ((int)$t['id'] === $task2Id) $hasTask2InAssigned = true; }
assert_true(!$hasTask2InAssigned, "Deleted Task 2 is completely excluded from list_assigned_by_me");

$sumBAfterDel = task_reminders_get_summary($pdo, 3, 'admin_b', false);
assert_equals($sumBAfterDel['actionable_count'], 0, "Deleted Task 2 does not contribute to Admin B actionable count");

// Soft delete on a Recurring Series Parent
$resSeries = task_reminders_create($pdo, [
    'title' => 'Daily Standup Recurring Reminder',
    'task_type_id' => 1,
    'assigned_to' => 'admin_b',
    'recurrence_type' => 'daily',
    'recurrence_start_date' => date('Y-m-d'),
    'remind_at' => date('Y-m-d') . ' 09:00:00',
    'notes' => 'Recurring parent'
], 2, 'admin_a', false);
assert_true($resSeries['success'], "Created recurring daily series parent");
$seriesParentId = (int)$resSeries['task_id'];

// Soft delete recurring series parent as Super Admin
$resDelSeries = task_reminders_delete($pdo, $seriesParentId, 'Cancelling entire series', 1, 'superadmin', true);
assert_true($resDelSeries['success'], "Super Admin soft-deletes recurring series parent");

$stmtSeriesParent = $pdo->prepare("SELECT * FROM reminders WHERE id = ?");
$stmtSeriesParent->execute([$seriesParentId]);
$parentRow = $stmtSeriesParent->fetch(PDO::FETCH_ASSOC);
assert_equals($parentRow['status'], 'deleted', "Series parent status is 'deleted'");
assert_true(!empty($parentRow['recurrence_stopped_at']), "Series parent recurrence_stopped_at is populated");

// Verify materializer skips deleted series parent (creates 0 new occurrences)
$createdOcc = task_reminders_materialize_occurrences($pdo);
assert_equals($createdOcc, 0, "Materializer created 0 new occurrences for soft-deleted series");

// Verify series listing excludes deleted series
$seriesList = task_reminders_list_series($pdo, 1, 'superadmin', [], true);
$hasDelSeries = false;
foreach ($seriesList as $s) { if ((int)$s['id'] === $seriesParentId) $hasDelSeries = true; }
assert_true(!$hasDelSeries, "Deleted recurring series is excluded from list_series");

// -------------------------------------------------------------------------
// Requirement 3: My Tasks Default Subfilter = 'Pending'
// -------------------------------------------------------------------------
echo "\n--- [TEST GROUP 3] My Tasks Default Subfilter (Pending) ---\n";

$taskRemindersPhp = file_get_contents(__DIR__ . '/task-reminders.php');

assert_true(
    strpos($taskRemindersPhp, '<span class="subfilter-pill active" onclick="filterMyTasks(\'pending\', this)">Pending</span>') !== false,
    "task-reminders.php renders Pending subfilter pill as 'active' by default"
);

assert_true(
    strpos($taskRemindersPhp, '<span class="subfilter-pill" onclick="filterMyTasks(\'all\', this)">All</span>') !== false,
    "task-reminders.php renders All subfilter pill as inactive"
);

assert_true(
    strpos($taskRemindersPhp, "var currentMyTasksStatusFilter = 'pending';") !== false,
    "task-reminders.php initializes currentMyTasksStatusFilter variable to 'pending'"
);

// -------------------------------------------------------------------------
// Requirement 4: Header Bell Direct Task Details & Permissions
// -------------------------------------------------------------------------
echo "\n--- [TEST GROUP 4] Header Bell Direct Details & Permissions ---\n";

$adminFooterPhp = file_get_contents(__DIR__ . '/includes/admin_footer.php');

assert_true(
    strpos($adminFooterPhp, 'openTaskDetailsModalFromHeader(') !== false,
    "admin_footer.php header dropdown items call openTaskDetailsModalFromHeader"
);

assert_true(
    strpos($adminFooterPhp, 'function openTaskDetailsModalFromHeader(taskId)') !== false,
    "admin_footer.php defines openTaskDetailsModalFromHeader function"
);

assert_true(
    strpos($adminFooterPhp, 'function openTaskDetailsModal(taskId)') !== false,
    "admin_footer.php defines global openTaskDetailsModal function"
);

assert_true(
    strpos($adminFooterPhp, 'id="task-details-modal"') !== false,
    "admin_footer.php includes global #task-details-modal markup"
);

assert_true(
    strpos($adminFooterPhp, 'sidebar-task-actionable-badge') !== false,
    "admin_footer.php updates #sidebar-task-actionable-badge dynamically"
);

assert_true(
    strpos($adminFooterPhp, 'sidebar-task-completed-badge') !== false,
    "admin_footer.php updates #sidebar-task-completed-badge dynamically"
);

// Create Task 3 for permission and IDOR verification
$resCreate3 = task_reminders_create($pdo, [
    'title' => 'Counseling for Sneha',
    'task_type_id' => 3,
    'assigned_to' => 'admin_b',
    'remind_at' => date('Y-m-d H:i:s', strtotime('+3 hours')),
    'notes' => 'Special parent counseling notes'
], 2, 'admin_a', false);
$task3Id = (int)$resCreate3['task_id'];

// Details access check:
// 1. Assignee (Admin B) can view details
$detB = task_reminders_get_details($pdo, $task3Id, 3, 'admin_b', false);
assert_true(!empty($detB), "Assignee (Admin B) can view Task 3 details");
assert_equals($detB['can_delete'], false, "Assignee (Admin B) cannot delete task (can_delete = false)");

// 2. Assigner (Admin A) can view details
$detA = task_reminders_get_details($pdo, $task3Id, 2, 'admin_a', false);
assert_true(!empty($detA), "Assigner (Admin A) can view Task 3 details");
assert_equals($detA['can_delete'], false, "Assigner (Admin A) cannot delete task (can_delete = false)");

// 3. Super Admin can view details and delete
$detSuper = task_reminders_get_details($pdo, $task3Id, 1, 'superadmin', true);
assert_true(!empty($detSuper), "Super Admin can view Task 3 details");
assert_equals($detSuper['can_delete'], true, "Super Admin has can_delete = true");

// 4. Unrelated Admin C is blocked by IDOR protection
$detC = task_reminders_get_details($pdo, $task3Id, 4, 'admin_c', false);
assert_true($detC === null, "Unrelated Admin C receives null (IDOR protected)");

// -------------------------------------------------------------------------
// Summary of Results
// -------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "AUDIT RESULTS: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
