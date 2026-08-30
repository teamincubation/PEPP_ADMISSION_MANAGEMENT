<?php
/**
 * PEPP Learning — Task Reminders & Accountability Module Automated Audit Test Suite
 * Comprehensive 50+ assertion test suite covering security, idempotency,
 * lifecycle transitions, timer revalidation, normalized task types, and zero-delete retention.
 */

declare(strict_types=1);

// Configure test environment using in-memory SQLite database
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Create SQLite schema individually for each table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `admins` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `full_name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL,
        `phone` VARCHAR(20) NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
        `status` VARCHAR(20) NOT NULL DEFAULT 'active',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `task_reminder_types` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `name` VARCHAR(100) NOT NULL UNIQUE,
        `description` TEXT NULL,
        `is_active` INTEGER NOT NULL DEFAULT 1,
        `created_by_admin_id` INTEGER NULL,
        `created_by_username` VARCHAR(100) NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `reminders` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `task_type_id` INTEGER NULL,
        `title` VARCHAR(255) NOT NULL,
        `notes` TEXT NULL,
        `remind_at` DATETIME NOT NULL,
        `assigned_to` VARCHAR(100) NOT NULL,
        `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
        `created_by_admin_id` INTEGER NULL,
        `created_by_username` VARCHAR(100) NULL,
        `created_by` VARCHAR(100) NOT NULL,
        `assigned_by_admin_id` INTEGER NULL,
        `assigned_by_username` VARCHAR(100) NULL,
        `assigned_to_admin_id` INTEGER NULL,
        `assigned_to_username` VARCHAR(100) NULL,
        `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `email_sent` INTEGER DEFAULT 0,
        `completed_by_admin_id` INTEGER NULL,
        `completed_by_username` VARCHAR(100) NULL,
        `completed_by` VARCHAR(100) NULL,
        `completed_at` DATETIME NULL,
        `latest_remarks` TEXT NULL,
        `last_status_updated_at` DATETIME NULL,
        `snooze_until` DATETIME NULL,
        `student_id` VARCHAR(50) NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `task_reminder_assignments` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `task_id` INTEGER NOT NULL,
        `assigned_by_admin_id` INTEGER NULL,
        `assigned_by_username` VARCHAR(100) NULL,
        `assigned_to_admin_id` INTEGER NULL,
        `assigned_to_username` VARCHAR(100) NULL,
        `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `ended_at` DATETIME NULL,
        `is_current` INTEGER NOT NULL DEFAULT 1
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `task_reminder_status_history` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `task_id` INTEGER NOT NULL,
        `event_type` VARCHAR(50) NOT NULL,
        `old_status` VARCHAR(50) NULL,
        `new_status` VARCHAR(50) NULL,
        `changed_by_admin_id` INTEGER NULL,
        `changed_by_username` VARCHAR(100) NULL,
        `remarks` TEXT NULL,
        `details_json` TEXT NULL,
        `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `task_reminder_notifications` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `task_id` INTEGER NOT NULL,
        `recipient_admin_id` INTEGER NULL,
        `recipient_username` VARCHAR(100) NOT NULL,
        `sender_admin_id` INTEGER NULL,
        `sender_username` VARCHAR(100) NULL,
        `notification_type` VARCHAR(50) NOT NULL,
        `event_key` VARCHAR(100) NOT NULL,
        `message` TEXT NULL,
        `is_read` INTEGER NOT NULL DEFAULT 0,
        `is_dismissed` INTEGER NOT NULL DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `read_at` DATETIME NULL
    )
");

// Seed default task types
$defaultTypes = [
    ['Daily Task Reminder', 'Routine daily reminders and operational duties'],
    ['Mentoring', 'Student academic mentoring, progress review and counseling sessions'],
    ['Session Scheduling', 'Scheduling online batches, faculty lectures, and mega tests'],
    ['Student Follow-up', 'Calling students regarding admission, attendance, and general queries'],
    ['Payment Follow-up', 'Fee installment recovery, payment verification, and voucher review'],
    ['Academic Task', 'Curriculum planning, question paper design, and study material uploads'],
    ['Administrative Task', 'Office paperwork, certificate generation, and staff coordination'],
    ['Meeting', 'Internal staff, academic committee, and management meetings'],
    ['Documentation', 'Student records, onboarding verifications, and compliance filing'],
    ['General Task', 'General administrative task and miscellaneous reminders'],
    ['Other', 'Custom tasks not covered in standard categories']
];

$stmtType = $pdo->prepare("INSERT INTO `task_reminder_types` (`name`, `description`, `is_active`, `created_by_username`) VALUES (?, ?, 1, 'System')");
foreach ($defaultTypes as $dt) {
    $stmtType->execute([$dt[0], $dt[1]]);
}

// Seed test admins
$pdo->exec("
    INSERT INTO admins (username, full_name, email, password_hash, role, status) VALUES
    ('superadmin', 'Super Administrator', 'super@pepplearning.in', 'hash', 'super_admin', 'active'),
    ('admin_a', 'Admin Alice (Creator)', 'alice@pepplearning.in', 'hash', 'admin', 'active'),
    ('admin_b', 'Admin Bob (Assignee)', 'bob@pepplearning.in', 'hash', 'admin', 'active'),
    ('admin_c', 'Admin Charlie (Third Party)', 'charlie@pepplearning.in', 'hash', 'admin', 'active')
");

require_once __DIR__ . '/includes/reminders_helper.php';

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = [];

function assert_true(bool $condition, string $message) {
    global $totalAssertions, $passedAssertions, $failedAssertions;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  [PASS] {$message}\n";
    } else {
        $failedAssertions[] = $message;
        echo "  [FAIL] {$message}\n";
    }
}

function assert_equals($expected, $actual, string $message) {
    global $totalAssertions, $passedAssertions, $failedAssertions;
    $totalAssertions++;
    if ($expected === $actual) {
        $passedAssertions++;
        echo "  [PASS] {$message}\n";
    } else {
        $msg = "{$message} (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")";
        $failedAssertions[] = $msg;
        echo "  [FAIL] {$msg}\n";
    }
}

echo "\n======================================================================\n";
echo "PEPP ERP — TASK REMINDERS & ACCOUNTABILITY MODULE AUDIT TEST SUITE\n";
echo "======================================================================\n\n";

// ──────────────────────────────────────────────────────────────────────
// 1. Task Types Management & Normalized Uniqueness
// ──────────────────────────────────────────────────────────────────────
echo "TEST GROUP 1: Task Types Management & Normalized Duplicate Prevention\n";

$initialTypes = task_types_get_all($pdo, false);
assert_true(count($initialTypes) >= 10, "Default task types seeded in database (count: " . count($initialTypes) . ")");

// Add new task type
$saveRes1 = task_types_save($pdo, ['name' => 'Counseling', 'description' => '1-on-1 Student counseling', 'is_active' => 1], 1, 'superadmin');
assert_true($saveRes1['success'], "Successfully created new task type 'Counseling'");
$counselingTypeId = (int)($saveRes1['id'] ?? 0);

// Test normalized duplicate prevention: '  counseling  ' and 'COUNSELING'
$dupRes1 = task_types_save($pdo, ['name' => '  counseling  ', 'description' => 'Duplicate test'], 1, 'superadmin');
assert_true(!$dupRes1['success'], "Blocked duplicate task type with lowercase & trimmed matching");

$dupRes2 = task_types_save($pdo, ['name' => 'COUNSELING', 'description' => 'Duplicate test uppercase'], 1, 'superadmin');
assert_true(!$dupRes2['success'], "Blocked duplicate task type with uppercase matching");

// Edit task type
$editTypeRes = task_types_save($pdo, ['id' => $counselingTypeId, 'name' => 'Student Counseling', 'description' => 'Updated desc', 'is_active' => 1], 1, 'superadmin');
assert_true($editTypeRes['success'], "Successfully updated task type name to 'Student Counseling'");

// Toggle active/inactive
task_types_toggle_active($pdo, $counselingTypeId, false);
$typeCheck = task_types_get_by_id($pdo, $counselingTypeId);
assert_equals(0, (int)$typeCheck['is_active'], "Task type successfully deactivated");

// Deactivated type is excluded from active list
$activeOnlyTypes = task_types_get_all($pdo, true);
$foundInactiveInActiveList = false;
foreach ($activeOnlyTypes as $at) {
    if ($at['id'] == $counselingTypeId) $foundInactiveInActiveList = true;
}
assert_true(!$foundInactiveInActiveList, "Deactivated task type excluded from active dropdown list");

// Re-activate for subsequent tests
task_types_toggle_active($pdo, $counselingTypeId, true);

// ──────────────────────────────────────────────────────────────────────
// 2. Task Creation & Identity Records (Admin A -> Admin B)
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 2: Task Creation, Mandatory Type & Separate Identity Recording\n";

// Attempt creation without task_type_id (Must fail)
$invalidCreate = task_reminders_create($pdo, [
    'title' => 'Follow up Rahul',
    'remind_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
assert_true(!$invalidCreate['success'], "Task creation strictly rejected when task_type_id is missing");

// Valid creation by Admin A assigned to Admin B
$dueTimeFuture = date('Y-m-d H:i:s', strtotime('+2 hours'));
$createRes = task_reminders_create($pdo, [
    'task_type_id' => $counselingTypeId,
    'title' => 'Call Rahul for counseling',
    'notes' => 'Discuss admission roadmap',
    'remind_at' => $dueTimeFuture,
    'assigned_to' => 'admin_b'
], 2, 'admin_a');

assert_true($createRes['success'], "Task successfully created by Admin A assigned to Admin B");
$taskId = (int)($createRes['task_id'] ?? 0);

// Verify task record in reminders table
$taskDetails = task_reminders_get_details($pdo, $taskId, 2, 'admin_a', true);
assert_true($taskDetails !== null, "Task details fetched successfully");
$t = $taskDetails['task'] ?? [];

assert_equals('Call Rahul for counseling', $t['title'] ?? null, "Task title recorded correctly");
assert_equals($counselingTypeId, (int)($t['task_type_id'] ?? 0), "Task type ID recorded correctly");
assert_equals('admin_a', $t['created_by_username'] ?? null, "Creator username permanently recorded");
assert_equals(2, (int)($t['created_by_admin_id'] ?? 0), "Creator admin ID recorded");
assert_equals('admin_b', $t['assigned_to_username'] ?? null, "Assignee username recorded");
assert_equals(3, (int)($t['assigned_to_admin_id'] ?? 0), "Assignee admin ID recorded");
assert_equals('pending', $t['status'] ?? null, "Initial status is 'pending'");

// Verify initial assignment history
assert_equals(1, count($taskDetails['assignments'] ?? []), "Initial assignment history record created");
$ass0 = $taskDetails['assignments'][0] ?? [];
assert_equals('admin_a', $ass0['assigned_by_username'] ?? null, "Assignment by Admin A recorded");
assert_equals('admin_b', $ass0['assigned_to_username'] ?? null, "Assignment to Admin B recorded");
assert_equals(1, (int)($ass0['is_current'] ?? 0), "Assignment marked as is_current = 1");

// Verify CREATED history event
assert_true(count($taskDetails['history'] ?? []) >= 1, "Status history recorded CREATED event");
assert_equals('CREATED', $taskDetails['history'][0]['event_type'] ?? null, "First event type is 'CREATED'");

// Verify notification created for Admin B
$notifsB = task_reminders_get_unread_notifications($pdo, 3, 'admin_b');
assert_true(count($notifsB) >= 1, "Assignee Admin B received persistent notification");
assert_equals('TASK_ASSIGNED', $notifsB[0]['notification_type'] ?? null, "Notification type is 'TASK_ASSIGNED'");

// ──────────────────────────────────────────────────────────────────────
// 3. Derived Overdue State Calculation (No Persisted Mutation)
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 3: Derived OVERDUE State (Runtime display, no DB status alteration)\n";

// Create an overdue task (due 1 hour ago)
$dueTimePast = date('Y-m-d H:i:s', strtotime('-1 hour'));
$overdueTaskRes = task_reminders_create($pdo, [
    'task_type_id' => $counselingTypeId,
    'title' => 'Overdue task test',
    'notes' => 'Scheduled in past',
    'remind_at' => $dueTimePast,
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
$overdueTaskId = (int)($overdueTaskRes['task_id'] ?? 0);

// Verify runtime list derives 'overdue' display status while database stores 'pending'
$myTasksB = task_reminders_list_my_tasks($pdo, 3, 'admin_b');
$foundOverdue = null;
foreach ($myTasksB as $mt) {
    if ($mt['id'] == $overdueTaskId) $foundOverdue = $mt;
}
assert_true($foundOverdue !== null, "Overdue task listed in My Tasks for Admin B");
assert_true($foundOverdue['is_overdue'] ?? false, "Task marked is_overdue = true at runtime");
assert_equals('overdue', $foundOverdue['display_status'] ?? null, "Task display_status derived as 'overdue'");

// Verify database status remains strictly 'pending'
$dbStatus = $pdo->query("SELECT status FROM reminders WHERE id = {$overdueTaskId}")->fetchColumn();
assert_equals('pending', $dbStatus, "Database column status remains strictly 'pending' without corrupting cron mutation");

// ──────────────────────────────────────────────────────────────────────
// 4. Authoritative Due Alert Revalidation (Dual-layer Trigger)
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 4: Strict Due Alert Revalidation (Server Authoritative Verification)\n";

// Future task is NOT due
$revalFuture = task_reminders_verify_due_alert($pdo, $taskId, 3, 'admin_b');
assert_true($revalFuture === null, "Future task correctly returns null for due alert");

// Past task IS due for Admin B
$revalPast = task_reminders_verify_due_alert($pdo, $overdueTaskId, 3, 'admin_b');
assert_true($revalPast !== null, "Past task verified valid for due alert popup for Admin B");
assert_equals('Overdue task test', $revalPast['title'] ?? null, "Verified task title matches");

// Unauthorized Admin C attempting due alert verification gets rejected
$revalUnauthorized = task_reminders_verify_due_alert($pdo, $overdueTaskId, 4, 'admin_c');
assert_true($revalUnauthorized === null, "Unauthorized Admin C rejected from due alert verification");

// ──────────────────────────────────────────────────────────────────────
// 5. Task Status Transitions & Terminal State Invariants
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 5: Lifecycle Transitions (Pending -> In Progress -> Completed)\n";

// Admin B starts the task (pending -> in_progress)
$startRes = task_reminders_update_status($pdo, $taskId, 'in_progress', 'Started phone consultation', 3, 'admin_b');
assert_true($startRes['success'], "Task status updated to in_progress");

$tDetailsStarted = task_reminders_get_details($pdo, $taskId, 3, 'admin_b');
assert_equals('in_progress', $tDetailsStarted['task']['status'] ?? null, "Task status is 'in_progress'");

// Admin B completes the task with remarks
$completeRes = task_reminders_update_status($pdo, $taskId, 'completed', 'Student confirmed enrollment and batch', 3, 'admin_b');
assert_true($completeRes['success'], "Task status updated to completed");

$tDetailsCompleted = task_reminders_get_details($pdo, $taskId, 3, 'admin_b');
assert_equals('completed', $tDetailsCompleted['task']['status'] ?? null, "Task status permanently marked 'completed'");
assert_equals('Student confirmed enrollment and batch', $tDetailsCompleted['task']['latest_remarks'] ?? null, "Completion remarks saved");
assert_equals('admin_b', $tDetailsCompleted['task']['completed_by_username'] ?? null, "Completed by recorded as 'admin_b'");
assert_true(!empty($tDetailsCompleted['task']['completed_at']), "completed_at timestamp recorded");

// Terminal State Check: Cannot change status of a completed task back to pending
$reopenAttempt = task_reminders_update_status($pdo, $taskId, 'pending', 'Try reopening', 3, 'admin_b');
assert_true(!$reopenAttempt['success'], "Terminal state invariant: Completed task cannot be altered back to pending");

// Terminal State Check: Cannot edit a completed task
$editCompletedAttempt = task_reminders_edit($pdo, $taskId, ['title' => 'Changed Title'], 2, 'admin_a');
assert_true(!$editCompletedAttempt['success'], "Terminal state invariant: Completed task cannot be edited");

// ──────────────────────────────────────────────────────────────────────
// 6. Completion Notification Sent to Original Task Creator
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 6: Completion Notification to Original Creator (Admin A)\n";

$notifsA = task_reminders_get_unread_notifications($pdo, 2, 'admin_a');
$foundCompletionNotif = false;
foreach ($notifsA as $na) {
    if ($na['task_id'] == $taskId && $na['notification_type'] === 'TASK_COMPLETED') {
        $foundCompletionNotif = true;
        assert_true(strpos($na['message'], 'admin_b') !== false, "Completion notification message mentions completer 'admin_b'");
    }
}
assert_true($foundCompletionNotif, "Original Task Creator (Admin A) received persistent TASK_COMPLETED notification");

// ──────────────────────────────────────────────────────────────────────
// 7. Task Editing & Immutable Reassignment
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 7: Task Editing, Reassignment & Assignment History Immutability\n";

// Create task 3 for editing and reassignment tests
$task3Res = task_reminders_create($pdo, [
    'task_type_id' => $counselingTypeId,
    'title' => 'Initial Task 3 Title',
    'notes' => 'Initial notes',
    'remind_at' => date('Y-m-d H:i:s', strtotime('+3 hours')),
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
$task3Id = (int)($task3Res['task_id'] ?? 0);

// Edit task details (title, notes)
$editTask3Res = task_reminders_edit($pdo, $task3Id, [
    'task_type_id' => $counselingTypeId,
    'title' => 'Updated Task 3 Title for Rahul',
    'notes' => 'Updated notes with phone number 9876543210',
    'remind_at' => date('Y-m-d H:i:s', strtotime('+4 hours')),
    'assigned_to' => 'admin_b'
], 2, 'admin_a');
assert_true($editTask3Res['success'], "Task details edited successfully by creator Admin A");

$t3Details = task_reminders_get_details($pdo, $task3Id, 2, 'admin_a');
assert_equals('Updated Task 3 Title for Rahul', $t3Details['task']['title'] ?? null, "Edited title persisted");

// Reassign task from Admin B to Admin C
$reassignRes = task_reminders_reassign($pdo, $task3Id, 'admin_c', 2, 'admin_a');
assert_true($reassignRes['success'], "Task 3 reassigned from Admin B to Admin C");

$t3ReassignedDetails = task_reminders_get_details($pdo, $task3Id, 2, 'admin_a');
assert_equals('admin_c', $t3ReassignedDetails['task']['assigned_to_username'] ?? null, "Current assignee updated to 'admin_c'");

// Verify immutable assignment history (Must have 2 rows: old ended, new current)
$assHist = $t3ReassignedDetails['assignments'] ?? [];
assert_equals(2, count($assHist), "Assignment history contains both initial and new assignment rows");
assert_equals('admin_b', $assHist[0]['assigned_to_username'] ?? null, "First assignment row was to Admin B");
assert_equals(0, (int)($assHist[0]['is_current'] ?? 0), "First assignment row marked is_current = 0");
assert_true(!empty($assHist[0]['ended_at']), "First assignment row has ended_at timestamp");
assert_equals('admin_c', $assHist[1]['assigned_to_username'] ?? null, "Second assignment row is to Admin C");
assert_equals(1, (int)($assHist[1]['is_current'] ?? 0), "Second assignment row marked is_current = 1");

// ──────────────────────────────────────────────────────────────────────
// 8. Postpone with Reason & Full Audit Trail
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 8: Postpone with Preset / Custom Time & Audit Logging\n";

$newPostponeTime = date('Y-m-d H:i:s', strtotime('+6 hours'));
$postponeRes = task_reminders_postpone($pdo, $task3Id, $newPostponeTime, 'Student requested callback at 6 PM', 2, 'admin_a');
assert_true($postponeRes['success'], "Task successfully postponed");

$t3PostponedDetails = task_reminders_get_details($pdo, $task3Id, 2, 'admin_a');
assert_equals($newPostponeTime, $t3PostponedDetails['task']['remind_at'] ?? null, "New remind_at time saved");

// Check POSTPONED event in history
$lastHistory = end($t3PostponedDetails['history']);
assert_equals('POSTPONED', $lastHistory['event_type'] ?? null, "POSTPONED event logged in history");
assert_true(strpos($lastHistory['remarks'] ?? '', 'Student requested callback') !== false, "Postpone reason recorded in history remarks");

// ──────────────────────────────────────────────────────────────────────
// 9. Strict IDOR & Permission Protection
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 9: Strict IDOR & Permission Protection\n";

// Unrelated Admin B attempts to access private Task 3 (now assigned to Admin C, created by Admin A)
$idorCheck = task_reminders_get_details($pdo, $task3Id, 3, 'admin_b', false);
assert_true($idorCheck === null, "IDOR check: Unrelated Admin B blocked from viewing Task 3 details");

// Unrelated Admin B attempts to reassign Task 3
$unauthReassign = task_reminders_reassign($pdo, $task3Id, 'admin_b', 3, 'admin_b', false);
assert_true(!$unauthReassign['success'], "IDOR check: Unauthorized Admin B blocked from reassigning Task 3");

// Super Admin CAN view Task 3 details
$superAdminCheck = task_reminders_get_details($pdo, $task3Id, 1, 'superadmin', true);
assert_true($superAdminCheck !== null, "Super Admin authorized to view all task details");

// ──────────────────────────────────────────────────────────────────────
// 10. Permanent Retention & Zero DELETE API Invariant
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 10: Permanent Retention & Zero DELETE Invariant\n";

// Verify that tasks table still contains all created tasks
$allTasksCount = (int)$pdo->query("SELECT COUNT(*) FROM reminders")->fetchColumn();
assert_true($allTasksCount >= 3, "All tasks permanently retained in database (count: {$allTasksCount})");

$allAssignmentsCount = (int)$pdo->query("SELECT COUNT(*) FROM task_reminder_assignments")->fetchColumn();
assert_true($allAssignmentsCount >= 3, "All assignment history records permanently retained (count: {$allAssignmentsCount})");

$allHistoryCount = (int)$pdo->query("SELECT COUNT(*) FROM task_reminder_status_history")->fetchColumn();
assert_true($allHistoryCount >= 5, "All status history events permanently retained (count: {$allHistoryCount})");

// ──────────────────────────────────────────────────────────────────────
// 11. Legacy Data Migration Invariance
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 11: Legacy Data Migration Invariance & Attribution\n";

// Insert a mock legacy reminder record with NULL task_type_id
$pdo->exec("
    INSERT INTO reminders (title, notes, remind_at, assigned_to, created_by, status, created_at)
    VALUES ('Legacy Reminder 1999', 'Old note', '2026-08-30 10:00:00', 'admin_b', 'admin_a', 'pending', '2026-08-30 08:00:00')
");
$legacyId = (int)$pdo->lastInsertId();

// Backfill simulation
$genTypeId = (int)$pdo->query("SELECT id FROM task_reminder_types WHERE name = 'General Task' LIMIT 1")->fetchColumn();
$pdo->exec("UPDATE reminders SET task_type_id = {$genTypeId} WHERE task_type_id IS NULL");
$pdo->exec("UPDATE reminders SET created_by_username = created_by WHERE created_by_username IS NULL");
$pdo->exec("UPDATE reminders SET assigned_to_username = assigned_to WHERE assigned_to_username IS NULL");

// Verify legacy record is safely backfilled
$legacyRow = $pdo->query("SELECT * FROM reminders WHERE id = {$legacyId}")->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($legacyRow['task_type_id']), "Legacy reminder backfilled with valid task_type_id");
assert_equals('admin_a', $legacyRow['created_by_username'], "Legacy created_by_username backfilled");
assert_equals('admin_b', $legacyRow['assigned_to_username'], "Legacy assigned_to_username backfilled");

// ──────────────────────────────────────────────────────────────────────
// 12. Lightweight Summary API Performance & Output
// ──────────────────────────────────────────────────────────────────────
echo "\nTEST GROUP 12: Lightweight Summary API Structure\n";

$summaryB = task_reminders_get_summary($pdo, 3, 'admin_b');
assert_true(isset($summaryB['pending_count']), "Summary includes pending_count");
assert_true(isset($summaryB['overdue_count']), "Summary includes overdue_count");
assert_true(isset($summaryB['in_progress_count']), "Summary includes in_progress_count");
assert_true(isset($summaryB['due_count']), "Summary includes due_count");
assert_true(isset($summaryB['due_task_ids']), "Summary includes due_task_ids array");

echo "\n======================================================================\n";
echo "AUDIT TEST SUMMARY: {$passedAssertions} / {$totalAssertions} ASSERTIONS PASSED\n";
if (empty($failedAssertions)) {
    echo "ALL TASK REMINDER & ACCOUNTABILITY MODULE AUDIT TESTS PASSED! [100% OK]\n";
} else {
    echo "FAILED ASSERTIONS:\n";
    foreach ($failedAssertions as $fa) {
        echo "  - {$fa}\n";
    }
}
echo "======================================================================\n";
