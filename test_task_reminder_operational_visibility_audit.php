<?php
/**
 * PEPP ERP — Task Reminders Operational Visibility, Due Alerts & Date Filtering Audit Suite
 * Exhaustively tests:
 * 1. Operational due alert scoping (Assignee-only) vs Global Audit Oversight
 * 2. Normal Admin -> Normal Admin delegation alerts
 * 3. Super Admin -> Normal Admin delegation alerts
 * 4. Normal Admin -> Super Admin delegation alerts
 * 5. Task completion notifications to Assigner/Creator
 * 6. Authoritative due alert verification & timing
 * 7. Removal of Start Work / direct completion
 * 8. Date presets (Today, Tomorrow, This Week, Overdue, Custom Range)
 * 9. Recurring occurrence filtering & series parent exclusion
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/reminders_helper.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// SQLite datetime helpers
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

// Create schema
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `admins` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `full_name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL,
        `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
        `status` VARCHAR(20) NOT NULL DEFAULT 'active'
    );
    INSERT INTO `admins` (`id`, `username`, `full_name`, `email`, `role`, `status`) VALUES
    (1, 'superadmin', 'Super Admin', 'super@pepp.in', 'super_admin', 'active'),
    (2, 'admin_a', 'Admin Alpha', 'a@pepp.in', 'admin', 'active'),
    (3, 'admin_b', 'Admin Beta', 'b@pepp.in', 'admin', 'active'),
    (4, 'admin_c', 'Admin Charlie', 'c@pepp.in', 'admin', 'active');
");

// Initialize task reminders tables & types
task_reminders_ensure_schema($pdo);

$types = task_types_get_all($pdo);
$typeId = (int)$types[0]['id'];

echo "==================================================================================\n";
echo "PEPP ERP — OPERATIONAL VISIBILITY, DUE ALERTS & DATE FILTER AUDIT\n";
echo "==================================================================================\n\n";

$passes = 0;
$fails = 0;

function assertTest(string $name, bool $condition, string $details = '') {
    global $passes, $fails;
    if ($condition) {
        $passes++;
        echo "  [PASS] " . $name . "\n";
    } else {
        $fails++;
        echo "  [FAIL] " . $name . "\n";
        if ($details) {
            echo "         --> " . $details . "\n";
        }
    }
}

// ---------------------------------------------------------------------------------
// TEST GROUP 1: Normal Admin A -> Normal Admin B Delegation
// ---------------------------------------------------------------------------------
echo "--- TEST GROUP 1: Admin A -> Admin B Delegation (Assignee-Only Due Alerts) ---\n";

// Create due task assigned to Admin B
$resT1 = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'T1: Followup with Student',
    'notes' => 'Call lead regarding batch start',
    'remind_at' => date('Y-m-d H:i:s', time() - 300), // Due 5 mins ago
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
$t1Id = (int)$resT1['task_id'];

$summaryB = task_reminders_get_summary($pdo, 3, 'admin_b', false);
assertTest("T1.1: Admin B (Assignee) receives pending_count = 1", $summaryB['pending_count'] === 1);
assertTest("T1.2: Admin B receives due_count = 1", $summaryB['due_count'] === 1);
assertTest("T1.3: Admin B receives due_task_ids containing T1", in_array($t1Id, $summaryB['due_task_ids'], true));

$verifyB = task_reminders_verify_due_alert($pdo, $t1Id, 3, 'admin_b', false);
assertTest("T1.4: Admin B verify_due_alert succeeds and returns task", $verifyB !== null && (int)$verifyB['id'] === $t1Id);

$summaryA = task_reminders_get_summary($pdo, 2, 'admin_a', false);
assertTest("T1.5: Admin A (Assigner) has pending_count = 0 (not assigned to A)", $summaryA['pending_count'] === 0);
assertTest("T1.6: Admin A has due_count = 0 and due_task_ids = []", $summaryA['due_count'] === 0 && empty($summaryA['due_task_ids']));
assertTest("T1.7: Admin A has assigned_by_me_pending = 1 (monitoring)", $summaryA['assigned_by_me_pending'] === 1);

$verifyA = task_reminders_verify_due_alert($pdo, $t1Id, 2, 'admin_a', false);
assertTest("T1.8: Admin A verify_due_alert rejected (A is not the assignee)", $verifyA === null);

$summarySuper = task_reminders_get_summary($pdo, 1, 'superadmin', true);
assertTest("T1.9: Super Admin has pending_count = 0 for operational tasks", $summarySuper['pending_count'] === 0);
assertTest("T1.10: Super Admin has due_count = 0 and due_task_ids = []", $summarySuper['due_count'] === 0 && empty($summarySuper['due_task_ids']));
assertTest("T1.11: Super Admin has assigned_by_me_pending = 1 (global monitoring)", $summarySuper['assigned_by_me_pending'] === 1);

$verifySuper = task_reminders_verify_due_alert($pdo, $t1Id, 1, 'superadmin', true);
assertTest("T1.12: Super Admin verify_due_alert rejected (no operational due popup for B's task)", $verifySuper === null);

// ---------------------------------------------------------------------------------
// TEST GROUP 2: Super Admin -> Normal Admin B Delegation
// ---------------------------------------------------------------------------------
echo "\n--- TEST GROUP 2: Super Admin -> Admin B Delegation ---\n";

$resT2 = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'T2: Super Admin Assigned Task',
    'notes' => 'Important audit filing',
    'remind_at' => date('Y-m-d H:i:s', time() - 60),
    'assigned_to' => 'admin_b'
], 1, 'superadmin');
$t2Id = (int)$resT2['task_id'];

$summaryB_2 = task_reminders_get_summary($pdo, 3, 'admin_b', false);
assertTest("T2.1: Admin B pending_count = 2, due_count = 2", $summaryB_2['pending_count'] === 2 && $summaryB_2['due_count'] === 2);
assertTest("T2.2: Admin B verify_due_alert succeeds for T2", task_reminders_verify_due_alert($pdo, $t2Id, 3, 'admin_b', false) !== null);

$summarySuper_2 = task_reminders_get_summary($pdo, 1, 'superadmin', true);
assertTest("T2.3: Super Admin pending_count = 0, due_count = 0", $summarySuper_2['pending_count'] === 0 && $summarySuper_2['due_count'] === 0);
assertTest("T2.4: Super Admin verify_due_alert rejected for T2", task_reminders_verify_due_alert($pdo, $t2Id, 1, 'superadmin', true) === null);

// ---------------------------------------------------------------------------------
// TEST GROUP 3: Normal Admin A -> Super Admin Delegation
// ---------------------------------------------------------------------------------
echo "\n--- TEST GROUP 3: Admin A -> Super Admin Delegation (Super Admin IS Assignee) ---\n";

$resT3 = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'T3: Approval Request for Super Admin',
    'notes' => 'Please review and approve fee waiver',
    'remind_at' => date('Y-m-d H:i:s', time() - 120),
    'assigned_to' => 'superadmin'
], 2, 'admin_a');
$t3Id = (int)$resT3['task_id'];

$summarySuper_3 = task_reminders_get_summary($pdo, 1, 'superadmin', true);
assertTest("T3.1: Super Admin pending_count = 1 (assigned to Super Admin)", $summarySuper_3['pending_count'] === 1);
assertTest("T3.2: Super Admin due_count = 1 and due_task_ids = [T3]", $summarySuper_3['due_count'] === 1 && in_array($t3Id, $summarySuper_3['due_task_ids'], true));
assertTest("T3.3: Super Admin verify_due_alert succeeds for T3 (because Super Admin IS assignee)", task_reminders_verify_due_alert($pdo, $t3Id, 1, 'superadmin', true) !== null);

// ---------------------------------------------------------------------------------
// TEST GROUP 4: Completion Alerts & Scoped Audit History
// ---------------------------------------------------------------------------------
echo "\n--- TEST GROUP 4: Task Completion & Creator Notifications ---\n";

$completeRes = task_reminders_update_status($pdo, $t1Id, 'completed', 'Lead contacted and registered', 3, 'admin_b');
assertTest("T4.1: Task T1 completed successfully by Admin B", $completeRes['success'] === true);

// Check unread notifications for Admin A (Creator/Assigner)
$notifsA = task_reminders_get_unread_notifications($pdo, 2, 'admin_a');
assertTest("T4.2: Admin A receives unread completion notification", count($notifsA) === 1 && $notifsA[0]['notification_type'] === 'TASK_COMPLETED');

// Admin B should NOT receive a creator notification for completing their own task
$notifsB = task_reminders_get_unread_notifications($pdo, 3, 'admin_b');
$completionNotifsB = array_filter($notifsB, function($n) { return ($n['notification_type'] ?? '') === 'TASK_COMPLETED'; });
assertTest("T4.3: Admin B has 0 unread completion notifications", count($completionNotifsB) === 0);

// Admin A dismisses notification
$notifId = (int)$notifsA[0]['id'];
$dismissRes = task_reminders_dismiss_notification($pdo, $notifId, 2, 'admin_a');
assertTest("T4.4: Notification dismissed successfully", $dismissRes === true);
$notifsA_after = task_reminders_get_unread_notifications($pdo, 2, 'admin_a');
$completionNotifsA_after = array_filter($notifsA_after, function($n) { return ($n['notification_type'] ?? '') === 'TASK_COMPLETED'; });
assertTest("T4.5: Admin A unread completion notifications now 0", count($completionNotifsA_after) === 0);

// Super Admin views global history
$superHistory = task_reminders_list_history($pdo, [], 100, 0, 1, 'superadmin', true);
assertTest("T4.6: Super Admin sees lifecycle audit history events", count($superHistory) >= 4);

// ---------------------------------------------------------------------------------
// TEST GROUP 5: Due Timing & Future Tasks
// ---------------------------------------------------------------------------------
echo "\n--- TEST GROUP 5: Due Timing (Future Tasks vs Past Tasks) ---\n";

$resFuture = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'T_Future: Future Task for Admin C',
    'notes' => 'Scheduled for tomorrow',
    'remind_at' => date('Y-m-d H:i:s', time() + 86400),
    'assigned_to' => 'admin_c'
], 2, 'admin_a');
$futureId = (int)$resFuture['task_id'];

$summaryC = task_reminders_get_summary($pdo, 4, 'admin_c', false);
assertTest("T5.1: Admin C pending_count = 1", $summaryC['pending_count'] === 1);
assertTest("T5.2: Admin C due_count = 0 (future task)", $summaryC['due_count'] === 0);
assertTest("T5.3: Admin C due_task_ids is empty", empty($summaryC['due_task_ids']));
assertTest("T5.4: verify_due_alert returns null for future task", task_reminders_verify_due_alert($pdo, $futureId, 4, 'admin_c', false) === null);

// ---------------------------------------------------------------------------------
// TEST GROUP 6: Removal of "Start Work" & Direct Completion
// ---------------------------------------------------------------------------------
echo "\n--- TEST GROUP 6: Direct Completion without 'Start Work' ---\n";

$resT6 = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'T6: Direct Complete Test',
    'notes' => 'Pending task directly completed',
    'remind_at' => date('Y-m-d H:i:s', time() - 30),
    'assigned_to' => 'admin_c'
], 4, 'admin_c');
$t6Id = (int)$resT6['task_id'];

$compDirect = task_reminders_update_status($pdo, $t6Id, 'completed', 'Completed directly from pending', 4, 'admin_c');
assertTest("T6.1: Direct completion from pending status succeeded", $compDirect['success'] === true);

$detailsT6 = task_reminders_get_details($pdo, $t6Id, 4, 'admin_c', false);
assertTest("T6.2: Final status is completed", $detailsT6['task']['status'] === 'completed');
assertTest("T6.3: History records CREATED and COMPLETED events cleanly", count($detailsT6['history']) === 2);

// ---------------------------------------------------------------------------------
// TEST GROUP 7: Date Filters (Today, Tomorrow, This Week, Overdue, Custom Range)
// ---------------------------------------------------------------------------------
echo "\n--- TEST GROUP 7: Date Filters on Task Reminders ---\n";

$todayStr = date('Y-m-d');
$tomorrowStr = date('Y-m-d', strtotime('+1 day'));
$yesterdayStr = date('Y-m-d', strtotime('-1 day'));
$nextMonthStr = date('Y-m-d', strtotime('+35 days'));

// Seed tasks across dates for Admin B
$resDateToday = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'Date Task: Today',
    'remind_at' => $todayStr . ' 14:00:00',
    'assigned_to' => 'admin_b'
], 2, 'admin_a');

$resDateTomorrow = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'Date Task: Tomorrow',
    'remind_at' => $tomorrowStr . ' 10:00:00',
    'assigned_to' => 'admin_b'
], 2, 'admin_a');

$resDateNextMonth = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'Date Task: Next Month',
    'remind_at' => $nextMonthStr . ' 10:00:00',
    'assigned_to' => 'admin_b'
], 2, 'admin_a');

// 1. Filter Today
$listToday = task_reminders_list_my_tasks($pdo, 3, 'admin_b', ['date_preset' => 'today']);
$hasToday = false;
$hasTomorrowInToday = false;
foreach ($listToday as $t) {
    if ($t['title'] === 'Date Task: Today') $hasToday = true;
    if ($t['title'] === 'Date Task: Tomorrow') $hasTomorrowInToday = true;
}
assertTest("T7.1: Filter 'today' returns Today's task and excludes Tomorrow's task", $hasToday && !$hasTomorrowInToday);

// 2. Filter Tomorrow
$listTomorrow = task_reminders_list_my_tasks($pdo, 3, 'admin_b', ['date_preset' => 'tomorrow']);
$hasTomorrow = false;
$hasTodayInTomorrow = false;
foreach ($listTomorrow as $t) {
    if ($t['title'] === 'Date Task: Tomorrow') $hasTomorrow = true;
    if ($t['title'] === 'Date Task: Today') $hasTodayInTomorrow = true;
}
assertTest("T7.2: Filter 'tomorrow' returns Tomorrow's task and excludes Today's task", $hasTomorrow && !$hasTodayInTomorrow);

// 3. Filter This Week
$listThisWeek = task_reminders_list_my_tasks($pdo, 3, 'admin_b', ['date_preset' => 'this_week']);
$hasNextMonthInThisWeek = false;
foreach ($listThisWeek as $t) {
    if ($t['title'] === 'Date Task: Next Month') $hasNextMonthInThisWeek = true;
}
assertTest("T7.3: Filter 'this_week' excludes Next Month task", !$hasNextMonthInThisWeek);

// 4. Filter Overdue
$listOverdue = task_reminders_list_my_tasks($pdo, 3, 'admin_b', ['date_preset' => 'overdue']);
$allOverdue = true;
foreach ($listOverdue as $t) {
    if (!$t['is_overdue']) $allOverdue = false;
}
assertTest("T7.4: Filter 'overdue' returns strictly overdue tasks", count($listOverdue) > 0 && $allOverdue);

// 5. Custom Range
$listCustom = task_reminders_list_my_tasks($pdo, 3, 'admin_b', [
    'date_preset' => 'custom',
    'date_from' => date('Y-m-d', strtotime('+30 days')),
    'date_to' => date('Y-m-d', strtotime('+40 days'))
]);
assertTest("T7.5: Custom Range (+30 to +40 days) returns exactly Next Month task", count($listCustom) === 1 && $listCustom[0]['title'] === 'Date Task: Next Month');

// ---------------------------------------------------------------------------------
// TEST GROUP 8: Recurring Tasks Series & Materialization
// ---------------------------------------------------------------------------------
echo "\n--- TEST GROUP 8: Recurring Series Materialization & Parent Exclusion ---\n";

$recRes = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'Daily Standup Report',
    'notes' => 'Recurring daily series',
    'recurrence_type' => 'daily',
    'recurrence_start_date' => $todayStr,
    'recurrence_end_date' => date('Y-m-d', strtotime('+14 days')),
    'recurrence_due_time' => '09:30:00',
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
assertTest("T8.1: Recurring series created successfully", $recRes['success'] === true);

// Check that series parent is NEVER returned in list_my_tasks
$myTasksAll = task_reminders_list_my_tasks($pdo, 3, 'admin_b', []);
$parentInList = false;
foreach ($myTasksAll as $t) {
    if (!empty($t['is_series_parent'])) {
        $parentInList = true;
    }
}
assertTest("T8.2: Series parent is excluded from list_my_tasks", !$parentInList);

// Check that series parent is NEVER returned in list_assigned_by_me
$assignedAll = task_reminders_list_assigned_by_me($pdo, 2, 'admin_a', []);
$parentInAssigned = false;
foreach ($assignedAll as $t) {
    if (!empty($t['is_series_parent'])) {
        $parentInAssigned = true;
    }
}
assertTest("T8.3: Series parent is excluded from list_assigned_by_me", !$parentInAssigned);

echo "\n==================================================================================\n";
echo "AUDIT SUMMARY: {$passes} PASSED, {$fails} FAILED\n";
echo "==================================================================================\n";

if ($fails > 0) {
    exit(1);
}
exit(0);
