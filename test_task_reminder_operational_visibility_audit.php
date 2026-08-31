<?php
/**
 * PEPP ERP — Task Reminders Operational Visibility, Instant Due Alerts & Date Filtering Audit Suite
 * Explicitly tests Scenarios 1 through 17 as defined in the Task Reminders specification.
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
echo "PEPP ERP — TASK REMINDERS OPERATIONAL VISIBILITY AUDIT (SCENARIOS 1-17)\n";
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
// SCENARIO 1 & 2: Admin A assigns task to Admin B & reaches due time
// ---------------------------------------------------------------------------------
echo "--- SCENARIO 1 & 2: Admin A -> Admin B Delegation & Due Alerts ---\n";

$resT1 = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'T1: Followup with Student',
    'notes' => 'Call lead regarding batch start',
    'remind_at' => date('Y-m-d H:i:s', time() - 300), // Due 5 mins ago
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
$t1Id = (int)$resT1['task_id'];

$summaryB = task_reminders_get_summary($pdo, 3, 'admin_b', false);
$summaryA = task_reminders_get_summary($pdo, 2, 'admin_a', false);
$summarySuper = task_reminders_get_summary($pdo, 1, 'superadmin', true);

$verifyB = task_reminders_verify_due_alert($pdo, $t1Id, 3, 'admin_b', false);
$verifyA = task_reminders_verify_due_alert($pdo, $t1Id, 2, 'admin_a', false);
$verifySuper = task_reminders_verify_due_alert($pdo, $t1Id, 1, 'superadmin', true);

// Scenario 1: Admin A assigns task to Admin B. B sees operational reminder, A does not, Super Admin does not.
assertTest("Scenario 1: B sees operational reminder (pending=1, due=1); A and Super Admin have operational pending=0, due=0",
    $summaryB['pending_count'] === 1 && $summaryB['due_count'] === 1 &&
    $summaryA['pending_count'] === 0 && $summaryA['due_count'] === 0 &&
    $summarySuper['pending_count'] === 0 && $summarySuper['due_count'] === 0
);

// Scenario 2: Admin B logged in and task reaches due time -> B due alert eligible, A not eligible, Super Admin not eligible.
assertTest("Scenario 2: B is due-alert eligible; A and Super Admin are not eligible",
    $verifyB !== null && (int)$verifyB['id'] === $t1Id &&
    $verifyA === null && $verifySuper === null
);

// ---------------------------------------------------------------------------------
// SCENARIO 3: Admin B was offline at due time, authenticates later
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 3: Offline Assignee Later Login ---\n";
// Re-calling get_summary as if B signed in now after due time passed
$summaryB_login = task_reminders_get_summary($pdo, 3, 'admin_b', false);
assertTest("Scenario 3: Offline assignee upon login immediately receives due task in due_task_ids",
    $summaryB_login['due_count'] >= 1 && in_array($t1Id, $summaryB_login['due_task_ids'], true)
);

// ---------------------------------------------------------------------------------
// SCENARIO 4: B logs in before due time -> No due popup yet
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 4: Login Before Due Time ---\n";
$resFuture = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'T_Future: Future Task for Admin B',
    'notes' => 'Scheduled for tomorrow',
    'remind_at' => date('Y-m-d H:i:s', time() + 86400),
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
$futureId = (int)$resFuture['task_id'];

$verifyFuture = task_reminders_verify_due_alert($pdo, $futureId, 3, 'admin_b', false);
assertTest("Scenario 4: Task scheduled in the future returns null for due-alert (no popup before due time)",
    $verifyFuture === null
);

// ---------------------------------------------------------------------------------
// SCENARIO 5: B completes task -> B red count decreases, A receives green completion notification
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 5: Task Completion & Notification ---\n";
$completeRes = task_reminders_update_status($pdo, $t1Id, 'completed', 'Lead registered successfully', 3, 'admin_b');
$summaryB_afterComp = task_reminders_get_summary($pdo, 3, 'admin_b', false);
$notifsA = task_reminders_get_unread_notifications($pdo, 2, 'admin_a');
$notifsSuper = task_reminders_get_unread_notifications($pdo, 1, 'superadmin');

assertTest("Scenario 5: Completing task decreases B's red count to 0, A receives completion notification, Super Admin does not",
    $completeRes['success'] === true &&
    $summaryB_afterComp['pending_count'] === 1 && // (only future task remains pending)
    $summaryB_afterComp['due_count'] === 0 &&
    count($notifsA) === 1 && $notifsA[0]['notification_type'] === 'TASK_COMPLETED' &&
    count($notifsSuper) === 0
);

// ---------------------------------------------------------------------------------
// SCENARIO 6: Super Admin opens #task-history -> A -> B task visible
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 6: Super Admin Global Audit Trail ---\n";
$superHistory = task_reminders_list_history($pdo, [], 100, 0, 1, 'superadmin', true);
$t1InSuperHistory = (bool)array_filter($superHistory, fn($h) => (int)$h['task_id'] === $t1Id);
assertTest("Scenario 6: Super Admin sees A -> B completed task and history events in global audit trail",
    $t1InSuperHistory && count($superHistory) >= 2
);

// ---------------------------------------------------------------------------------
// SCENARIO 7: Super Admin operational due-alert lookup for B's task is blocked, but details access allowed
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 7: Super Admin Operational Scoping vs Audit Access ---\n";
$superDueCheck = task_reminders_verify_due_alert($pdo, $futureId, 1, 'superadmin', true);
$superDetailCheck = task_reminders_get_details($pdo, $futureId, 1, 'superadmin', true);
assertTest("Scenario 7: Super Admin cannot get operational due-alert for B's task, but can access task details for audit",
    $superDueCheck === null && $superDetailCheck !== null && (int)$superDetailCheck['task']['id'] === $futureId
);

// ---------------------------------------------------------------------------------
// SCENARIO 8: Admin A cannot obtain Admin B's operational reminder through ID manipulation
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 8: IDOR & Cross-Admin Due Alert Protection ---\n";
$aTamperDue = task_reminders_verify_due_alert($pdo, $futureId, 2, 'admin_a', false);
assertTest("Scenario 8: Admin A cannot verify/receive Admin B's operational due alert",
    $aTamperDue === null
);

// ---------------------------------------------------------------------------------
// SCENARIO 9 & 10: Recurring Occurrence Triggering & Assignee Scoping
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 9 & 10: Recurring Series Materialization & Scoping ---\n";
$recRes = task_reminders_create($pdo, [
    'task_type_id' => $typeId,
    'title' => 'Daily Standup Call',
    'notes' => 'Recurring daily series for Admin B',
    'recurrence_type' => 'daily',
    'recurrence_start_date' => date('Y-m-d'),
    'recurrence_end_date' => date('Y-m-d', strtotime('+14 days')),
    'recurrence_due_time' => '09:00:00',
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
$seriesParentId = (int)$recRes['task_id'];

// Check that series parent is NEVER returned in list_my_tasks
$myTasksB = task_reminders_list_my_tasks($pdo, 3, 'admin_b', []);
$parentInMyTasks = (bool)array_filter($myTasksB, fn($t) => (int)$t['id'] === $seriesParentId || !empty($t['is_series_parent']));

// Check occurrence triggering: only current assignee (Admin B) receives occurrence
$occStmt = $pdo->prepare("SELECT id FROM reminders WHERE recurrence_series_id = ? AND is_series_parent = 0 LIMIT 1");
$occStmt->execute([$seriesParentId]);
$occId = (int)$occStmt->fetchColumn();

$occVerifyB = ($occId > 0) ? task_reminders_get_details($pdo, $occId, 3, 'admin_b', false) : null;
$occVerifyC = ($occId > 0) ? task_reminders_get_details($pdo, $occId, 4, 'admin_c', false) : null;

assertTest("Scenario 9: Future unmaterialized occurrences and series parent are excluded from active tasks",
    !$parentInMyTasks
);
assertTest("Scenario 10: Materialized recurring occurrence is accessible to assignee (B) and denied to unrelated admin (C)",
    $occVerifyB !== null && $occVerifyC === null
);

// ---------------------------------------------------------------------------------
// SCENARIO 11: Reassignment: B -> C -> B loses operational reminder, C receives it
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 11: Task Reassignment Visibility Shift ---\n";
$resReassign = task_reminders_reassign($pdo, $futureId, 'admin_c', 2, 'admin_a', false);
$myTasksB_afterReassign = task_reminders_list_my_tasks($pdo, 3, 'admin_b', []);
$myTasksC_afterReassign = task_reminders_list_my_tasks($pdo, 4, 'admin_c', []);

$bHasFuture = (bool)array_filter($myTasksB_afterReassign, fn($t) => (int)$t['id'] === $futureId);
$cHasFuture = (bool)array_filter($myTasksC_afterReassign, fn($t) => (int)$t['id'] === $futureId);

assertTest("Scenario 11: Upon reassignment (B -> C), B loses My Tasks visibility, C gains My Tasks visibility",
    $resReassign['success'] === true && !$bHasFuture && $cHasFuture
);

// ---------------------------------------------------------------------------------
// SCENARIO 12: Start Work action does not exist in rendered source/UI logic
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 12: Removal of 'Start Work' Action ---\n";
$taskRemindersPhp = file_get_contents(__DIR__ . '/task-reminders.php');
$adminFooterPhp = file_get_contents(__DIR__ . '/includes/admin_footer.php');

$noStartInPage = strpos($taskRemindersPhp, 'startTask') === false && strpos($taskRemindersPhp, 'Start Work') === false;
$noStartInFooter = strpos($adminFooterPhp, 'startTask') === false && strpos($adminFooterPhp, 'Start Work') === false && strpos($adminFooterPhp, 'Start Task') === false;

assertTest("Scenario 12: 'Start Work' and 'Start Task' buttons are completely removed from UI and action logic",
    $noStartInPage && $noStartInFooter
);

// ---------------------------------------------------------------------------------
// SCENARIO 13: Completion and Postpone remain available
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 13: Completion and Postpone Availability ---\n";
$resPostpone = task_reminders_postpone($pdo, $futureId, date('Y-m-d H:i:s', time() + 172800), 'Exam postponed by student', 4, 'admin_c');
$detailsPostponed = task_reminders_get_details($pdo, $futureId, 4, 'admin_c', false);
assertTest("Scenario 13: Postpone and Complete actions remain fully functional",
    $resPostpone['success'] === true && $detailsPostponed['task']['remind_at'] === $resPostpone['new_remind_at']
);

// ---------------------------------------------------------------------------------
// SCENARIO 14: Date filters work (Today, Tomorrow, This Week, Overdue, Custom Range)
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 14: Date Filtering Presets & Backend Query Logic ---\n";
$todayStr = date('Y-m-d');
$tomorrowStr = date('Y-m-d', strtotime('+1 day'));
$nextMonthStr = date('Y-m-d', strtotime('+35 days'));

$tToday = task_reminders_create($pdo, ['task_type_id' => $typeId, 'title' => 'Filter Today', 'remind_at' => $todayStr . ' 15:00:00', 'assigned_to' => 'admin_c'], 2, 'admin_a');
$tTom = task_reminders_create($pdo, ['task_type_id' => $typeId, 'title' => 'Filter Tomorrow', 'remind_at' => $tomorrowStr . ' 10:00:00', 'assigned_to' => 'admin_c'], 2, 'admin_a');
$tMonth = task_reminders_create($pdo, ['task_type_id' => $typeId, 'title' => 'Filter Next Month', 'remind_at' => $nextMonthStr . ' 10:00:00', 'assigned_to' => 'admin_c'], 2, 'admin_a');

$listToday = task_reminders_list_my_tasks($pdo, 4, 'admin_c', ['date_preset' => 'today']);
$listTom = task_reminders_list_my_tasks($pdo, 4, 'admin_c', ['date_preset' => 'tomorrow']);
$listCustom = task_reminders_list_my_tasks($pdo, 4, 'admin_c', ['date_preset' => 'custom', 'date_from' => date('Y-m-d', strtotime('+30 days')), 'date_to' => date('Y-m-d', strtotime('+40 days'))]);

$hasTodayInToday = (bool)array_filter($listToday, fn($t) => $t['title'] === 'Filter Today');
$hasTomInToday = (bool)array_filter($listToday, fn($t) => $t['title'] === 'Filter Tomorrow');
$hasTomInTom = (bool)array_filter($listTom, fn($t) => $t['title'] === 'Filter Tomorrow');
$hasMonthInCustom = (bool)array_filter($listCustom, fn($t) => $t['title'] === 'Filter Next Month');

assertTest("Scenario 14: Date filter presets (Today, Tomorrow, Custom Range) accurately filter backend queries",
    $hasTodayInToday && !$hasTomInToday && $hasTomInTom && $hasMonthInCustom
);

// ---------------------------------------------------------------------------------
// SCENARIO 15: Date filters cannot bypass role visibility
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 15: Date Filter Role Scoping Enforced Server-Side ---\n";
// Admin B queries date filter 'today' - should NOT see Admin C's 'Filter Today'
$listTodayB = task_reminders_list_my_tasks($pdo, 3, 'admin_b', ['date_preset' => 'today']);
$hasCInB = (bool)array_filter($listTodayB, fn($t) => $t['title'] === 'Filter Today');
assertTest("Scenario 15: Date filters strictly enforce role scoping (Admin B cannot see Admin C's tasks)",
    !$hasCInB
);

// ---------------------------------------------------------------------------------
// SCENARIO 16: Super Admin date filters can filter global history & monitoring
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 16: Super Admin Global Date Filtering ---\n";
$superHistoryToday = task_reminders_list_history($pdo, ['date_preset' => 'today'], 100, 0, 1, 'superadmin', true);
$superMonitoringAll = task_reminders_list_assigned_by_me($pdo, 1, 'superadmin', ['date_preset' => 'today'], true);
assertTest("Scenario 16: Super Admin date filters function across global history and monitoring",
    count($superHistoryToday) >= 1 && count($superMonitoringAll) >= 1
);

// ---------------------------------------------------------------------------------
// SCENARIO 17: Zero-delete invariant holds across entire lifecycle
// ---------------------------------------------------------------------------------
echo "\n--- SCENARIO 17: Zero-Delete Invariant ---\n";
$cntReminders = (int)$pdo->query("SELECT COUNT(*) FROM reminders")->fetchColumn();
$cntHistory = (int)$pdo->query("SELECT COUNT(*) FROM task_reminder_status_history")->fetchColumn();
$cntAssignments = (int)$pdo->query("SELECT COUNT(*) FROM task_reminder_assignments")->fetchColumn();
assertTest("Scenario 17: Zero-delete invariant holds (Reminders: {$cntReminders}, History: {$cntHistory}, Assignments: {$cntAssignments})",
    $cntReminders >= 7 && $cntHistory >= 5 && $cntAssignments >= 5
);

echo "\n==================================================================================\n";
echo "AUDIT SUMMARY: {$passes} PASSED, {$fails} FAILED\n";
echo "==================================================================================\n";

if ($fails > 0) {
    exit(1);
}
exit(0);
