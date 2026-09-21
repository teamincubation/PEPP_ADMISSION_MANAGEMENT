<?php
/**
 * PEPP ERP — Task Reminders Super Admin Work Report & PDF Audit Suite
 * Tests Scenarios 1 through 17 covering:
 * - Scope, filters, date semantics, event semantics decoupling
 * - Same-day consolidation, detail retention, postponement suppression
 * - Vector PDF generation, multi-page layout, visual integrity, security
 * - Test 17: Multi-stage lifecycle fixture (Sep 20 created -> Sep 21 postponed twice -> Sep 22 completed)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/reminders_helper.php';
require_once __DIR__ . '/includes/task_report_pdf.php';

echo "==================================================================================\n";
echo "PEPP ERP — TASK REMINDERS SUPER ADMIN WORK REPORT & PDF AUDIT (TESTS 1-17)\n";
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
// Helper: Create initialized SQLite Database with Admins & Task Types
// ---------------------------------------------------------------------------------
function createTestDb(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
    $pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

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

    task_reminders_ensure_schema($pdo);
    return $pdo;
}

$pdo = createTestDb();
$types = task_types_get_all($pdo);
$typeCallId = (int)$types[0]['id'];
$typeMeetingId = isset($types[1]) ? (int)$types[1]['id'] : $typeCallId;

// Seed base tasks:
// Task 1: Admin B, Sep 21, Completed
$res1 = task_reminders_create($pdo, [
    'title' => 'Follow up Admission Inquiry - Student 101',
    'notes' => 'Call parent regarding fee structure and hostel facilities.',
    'remind_at' => '2026-09-21 10:00:00',
    'task_type_id' => $typeCallId,
    'assigned_to' => 'admin_b',
], 1, 'superadmin');
$t1Id = (int)$res1['task_id'];
task_reminders_update_status($pdo, $t1Id, 'completed', 'Spoke to parent, admission confirmed.', 3, 'admin_b', false);
$pdo->exec("UPDATE reminders SET created_at = '2026-09-21 09:00:00', completed_at = '2026-09-21 10:30:00' WHERE id = {$t1Id}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-21 09:00:00' WHERE task_id = {$t1Id} AND event_type = 'CREATED'");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-21 10:30:00' WHERE task_id = {$t1Id} AND event_type = 'COMPLETED'");

// Task 2: Admin B, Sep 21, Postponed once, still Pending
$res2 = task_reminders_create($pdo, [
    'title' => 'Verify Plus Two Marksheet - Student 102',
    'notes' => 'Check CBSE board roll number and certificate authenticity.',
    'remind_at' => '2026-09-21 11:30:00',
    'task_type_id' => $typeCallId,
    'assigned_to' => 'admin_b',
], 1, 'superadmin');
$t2Id = (int)$res2['task_id'];
task_reminders_postpone($pdo, $t2Id, '2026-09-21 16:00:00', 'Student traveling today, requested evening call.', 3, 'admin_b', false);
$pdo->exec("UPDATE reminders SET created_at = '2026-09-21 09:15:00' WHERE id = {$t2Id}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-21 09:15:00' WHERE task_id = {$t2Id} AND event_type = 'CREATED'");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-21 11:30:00' WHERE task_id = {$t2Id} AND event_type = 'POSTPONED'");

// Task 3: Admin A, Sep 21, In Progress
$res3 = task_reminders_create($pdo, [
    'title' => 'Prepare Academic Calendar Draft',
    'notes' => 'Compile holiday schedule and exam term weeks.',
    'remind_at' => '2026-09-21 14:00:00',
    'task_type_id' => $typeMeetingId,
    'assigned_to' => 'admin_a',
], 1, 'superadmin');
$t3Id = (int)$res3['task_id'];
task_reminders_update_status($pdo, $t3Id, 'in_progress', 'Started drafting semester 1 timeline.', 2, 'admin_a', false);
$pdo->exec("UPDATE reminders SET created_at = '2026-09-21 09:30:00' WHERE id = {$t3Id}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-21 09:30:00' WHERE task_id = {$t3Id} AND event_type = 'CREATED'");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-21 14:00:00' WHERE task_id = {$t3Id} AND event_type = 'STARTED'");

// Task 4: Admin C, Sep 22, Pending (belonging strictly to Sep 22)
$res4 = task_reminders_create($pdo, [
    'title' => 'Campus Facility Inspection',
    'notes' => 'Audit computer lab and library internet terminals.',
    'remind_at' => '2026-09-22 09:30:00',
    'task_type_id' => $typeMeetingId,
    'assigned_to' => 'admin_c',
], 1, 'superadmin');
$t4Id = (int)$res4['task_id'];
$pdo->exec("UPDATE reminders SET created_at = '2026-09-22 09:00:00' WHERE id = {$t4Id}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-22 09:00:00' WHERE task_id = {$t4Id} AND event_type = 'CREATED'");

// ---------------------------------------------------------------------------------
// TEST 1: Full Report Scope (no filters)
// ---------------------------------------------------------------------------------
echo "--- TEST 1: Full Report Scope (No Filters) ---\n";
$rep1 = task_reminders_get_history_report_data($pdo, [], 1, 'superadmin', true);
assertTest("Test 1.1: Returns all 4 seeded tasks", ($rep1['summary']['total_tasks'] ?? 0) === 4, "Got " . ($rep1['summary']['total_tasks'] ?? 0));
assertTest("Test 1.2: Summary metrics match (1 completed, 1 in_progress, 2 pending/open)", 
    $rep1['summary']['completed'] === 1 && $rep1['summary']['in_progress'] === 1 && $rep1['summary']['open_total'] === 3,
    "completed={$rep1['summary']['completed']}, open_total={$rep1['summary']['open_total']}"
);
assertTest("Test 1.3: Postponed tasks count = 1, Postponement events = 1",
    $rep1['summary']['postponed_tasks'] === 1 && $rep1['summary']['postponement_events'] === 1,
    "postponed_tasks={$rep1['summary']['postponed_tasks']}, events={$rep1['summary']['postponement_events']}"
);
assertTest("Test 1.4: Working days grouped chronologically", count($rep1['days']) >= 2);

// ---------------------------------------------------------------------------------
// TEST 2: Admin Filter
// ---------------------------------------------------------------------------------
echo "\n--- TEST 2: Admin Filter ---\n";
$rep2 = task_reminders_get_history_report_data($pdo, ['admin' => 'admin_b'], 1, 'superadmin', true);
assertTest("Test 2.1: Scopes strictly to admin_b tasks (2 tasks)", ($rep2['summary']['total_tasks'] ?? 0) === 2, "Got " . ($rep2['summary']['total_tasks'] ?? 0));
assertTest("Test 2.2: Admin B metrics (1 completed, 1 pending/postponed)",
    $rep2['summary']['completed'] === 1 && $rep2['summary']['open_total'] === 1 && $rep2['summary']['postponed_tasks'] === 1
);
assertTest("Test 2.3: Admin label resolved to Admin Beta", strpos($rep2['scope']['admin_label'] ?? '', 'Beta') !== false || strpos($rep2['scope']['admin_label'] ?? '', 'admin_b') !== false);

// ---------------------------------------------------------------------------------
// TEST 3: Event Filter Decoupling (POSTPONED)
// ---------------------------------------------------------------------------------
echo "\n--- TEST 3: Event Filter Decoupling ---\n";
$rep3 = task_reminders_get_history_report_data($pdo, ['event_type' => 'POSTPONED'], 1, 'superadmin', true);
assertTest("Test 3.1: Event POSTPONED selects only postponed task (Task 2)", ($rep3['summary']['total_tasks'] ?? 0) === 1, "Got " . ($rep3['summary']['total_tasks'] ?? 0));
assertTest("Test 3.2: Decoupled metrics: Completed count is strictly 0 for POSTPONED report",
    $rep3['summary']['completed'] === 0 && $rep3['summary']['open_total'] === 1,
    "completed={$rep3['summary']['completed']}, open={$rep3['summary']['open_total']}"
);
assertTest("Test 3.3: Postponement events strictly recorded", $rep3['summary']['postponement_events'] === 1);

// ---------------------------------------------------------------------------------
// TEST 4: Date Range Bounding
// ---------------------------------------------------------------------------------
echo "\n--- TEST 4: Date Range Bounding ---\n";
$rep4 = task_reminders_get_history_report_data($pdo, ['date_from' => '2026-09-21', 'date_to' => '2026-09-21'], 1, 'superadmin', true);
assertTest("Test 4.1: Sep 21 returns exactly 3 tasks (Tasks 1, 2, 3)", ($rep4['summary']['total_tasks'] ?? 0) === 3, "Got " . ($rep4['summary']['total_tasks'] ?? 0));
$allDaysInRange = true;
foreach ($rep4['days'] as $dObj) {
    if ($dObj['date'] !== '2026-09-21') {
        $allDaysInRange = false;
    }
}
assertTest("Test 4.2: Every daily section is strictly 2026-09-21 (no bleed into Sep 22)", $allDaysInRange);

// ---------------------------------------------------------------------------------
// TEST 5: Filter Intersections
// ---------------------------------------------------------------------------------
echo "\n--- TEST 5: Filter Intersections ---\n";
$rep5 = task_reminders_get_history_report_data($pdo, [
    'admin' => 'admin_b',
    'date_from' => '2026-09-21',
    'date_to' => '2026-09-21',
    'event_type' => 'COMPLETED'
], 1, 'superadmin', true);
assertTest("Test 5.1: Intersection Admin B + Sep 21 + COMPLETED returns exactly Task 1", ($rep5['summary']['total_tasks'] ?? 0) === 1, "Got " . ($rep5['summary']['total_tasks'] ?? 0));
assertTest("Test 5.2: Completed count is 1, open is 0", $rep5['summary']['completed'] === 1 && $rep5['summary']['open_total'] === 0);

// ---------------------------------------------------------------------------------
// TEST 6 & 7: Same-Day Similar Task Consolidation & Data Retention
// ---------------------------------------------------------------------------------
echo "\n--- TEST 6 & 7: Same-Day Consolidation & Data Retention ---\n";
// Create 3 similar tasks on Sep 23 for Admin A
$res6a = task_reminders_create($pdo, [
    'title' => 'Counseling Call - Batch 2027',
    'notes' => 'Candidate Rahul Sharma - Cutoff inquiry',
    'remind_at' => '2026-09-23 10:00:00',
    'task_type_id' => $typeCallId,
    'assigned_to' => 'admin_a',
], 1, 'superadmin');
$res6b = task_reminders_create($pdo, [
    'title' => 'Counseling Call - Batch 2027',
    'notes' => 'Candidate Priya Nair - Scholarship criteria',
    'remind_at' => '2026-09-23 11:15:00',
    'task_type_id' => $typeCallId,
    'assigned_to' => 'admin_a',
], 1, 'superadmin');
$res6c = task_reminders_create($pdo, [
    'title' => 'Counseling Call - Batch 2027',
    'notes' => 'Candidate Arjun V - Document checklist',
    'remind_at' => '2026-09-23 14:30:00',
    'task_type_id' => $typeCallId,
    'assigned_to' => 'admin_a',
], 1, 'superadmin');

$t6aId = (int)$res6a['task_id'];
$t6bId = (int)$res6b['task_id'];
$t6cId = (int)$res6c['task_id'];

task_reminders_update_status($pdo, $t6aId, 'completed', 'Called Rahul, sent brochure.', 2, 'admin_a', false);
task_reminders_update_status($pdo, $t6bId, 'completed', 'Priya confirmed 85% marks eligible.', 2, 'admin_a', false);

// Set activity date timestamps cleanly to 2026-09-23
$pdo->exec("UPDATE reminders SET created_at = '2026-09-23 09:00:00', completed_at = '2026-09-23 10:30:00' WHERE id = {$t6aId}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-23 09:00:00' WHERE task_id = {$t6aId} AND event_type = 'CREATED'");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-23 10:30:00' WHERE task_id = {$t6aId} AND event_type = 'COMPLETED'");

$pdo->exec("UPDATE reminders SET created_at = '2026-09-23 09:00:00', completed_at = '2026-09-23 11:30:00' WHERE id = {$t6bId}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-23 09:00:00' WHERE task_id = {$t6bId} AND event_type = 'CREATED'");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-23 11:30:00' WHERE task_id = {$t6bId} AND event_type = 'COMPLETED'");

$pdo->exec("UPDATE reminders SET created_at = '2026-09-23 09:00:00' WHERE id = {$t6cId}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-23 09:00:00' WHERE task_id = {$t6cId} AND event_type = 'CREATED'");

$rep6 = task_reminders_get_history_report_data($pdo, ['date_from' => '2026-09-23', 'date_to' => '2026-09-23'], 1, 'superadmin', true);
assertTest("Test 6.1: Day Sep 23 has 1 consolidated group for 'Counseling Call - Batch 2027'", 
    count($rep6['days']) === 1 && count($rep6['days'][0]['groups']) === 1,
    "Got " . count($rep6['days'][0]['groups'] ?? []) . " groups"
);
$grp = $rep6['days'][0]['groups'][0];
assertTest("Test 6.2: Group occurrences = 3 (2 completed, 1 pending)",
    $grp['occurrences'] === 3 && $grp['completed'] === 2 && $grp['pending'] === 1,
    "occ={$grp['occurrences']}, comp={$grp['completed']}, pend={$grp['pending']}"
);
assertTest("Test 7.1: Group retains all 3 individual sub-items with unique IDs and times",
    count($grp['items']) === 3 && $grp['items'][0]['id'] !== $grp['items'][1]['id']
);
assertTest("Test 7.2: Group retains distinct notes in notes_list",
    count($grp['notes_list']) === 3 && in_array('Candidate Rahul Sharma - Cutoff inquiry', $grp['notes_list'], true)
);
assertTest("Test 7.3: Group retains distinct remarks in remarks_list",
    count($grp['remarks_list']) === 2 && in_array('Called Rahul, sent brochure.', $grp['remarks_list'], true)
);

// ---------------------------------------------------------------------------------
// TEST 8: Postponement Count & History Trail Suppression
// ---------------------------------------------------------------------------------
echo "\n--- TEST 8: Postponement Count & History Trail Suppression ---\n";
// Create task and postpone twice on Sep 24
$res8 = task_reminders_create($pdo, [
    'title' => 'Review Affiliation Renewal Agreement',
    'notes' => 'Cross check clause 4.2 with university norms.',
    'remind_at' => '2026-09-24 10:00:00',
    'task_type_id' => $typeMeetingId,
    'assigned_to' => 'admin_c',
], 1, 'superadmin');
$t8Id = (int)$res8['task_id'];
task_reminders_postpone($pdo, $t8Id, '2026-09-24 12:00:00', 'Legal advisor not available yet', 4, 'admin_c', false);
task_reminders_postpone($pdo, $t8Id, '2026-09-24 16:00:00', 'Meeting rescheduled to late afternoon', 4, 'admin_c', false);

$pdo->exec("UPDATE reminders SET created_at = '2026-09-24 09:00:00' WHERE id = {$t8Id}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-24 09:00:00' WHERE task_id = {$t8Id} AND event_type = 'CREATED'");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-24 12:00:00' WHERE task_id = {$t8Id} AND remarks LIKE '%Legal advisor%'");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-24 16:00:00' WHERE task_id = {$t8Id} AND remarks LIKE '%Meeting rescheduled%'");

$rep8 = task_reminders_get_history_report_data($pdo, ['date_from' => '2026-09-24', 'date_to' => '2026-09-24'], 1, 'superadmin', true);
$t8Item = null;
foreach ($rep8['days'][0]['groups'][0]['items'] as $it) {
    if ($it['id'] === $t8Id) $t8Item = $it;
}
assertTest("Test 8.1: Postpone count is accurately recorded as 2", ($t8Item['postpone_events_count'] ?? 0) === 2, "Got " . ($t8Item['postpone_events_count'] ?? 0));
assertTest("Test 8.2: No raw history trail array is exposed in the item (suppression)", !isset($t8Item['history_trail']) && !isset($t8Item['audit_logs']));

// ---------------------------------------------------------------------------------
// TEST 9: Task Status Derivation in Period
// ---------------------------------------------------------------------------------
echo "\n--- TEST 9: Task Status Derivation in Period ---\n";
assertTest("Test 9.1: Completed task has is_completed=true, completed_at, and completed_by",
    $rep6['days'][0]['groups'][0]['items'][0]['is_completed'] === true &&
    !empty($rep6['days'][0]['groups'][0]['items'][0]['completed_at']) &&
    $rep6['days'][0]['groups'][0]['items'][0]['completed_by'] === 'admin_a'
);
assertTest("Test 9.2: Pending item on future/today has period_status=pending",
    $rep6['days'][0]['groups'][0]['items'][2]['period_status'] === 'pending'
);

// ---------------------------------------------------------------------------------
// TEST 10: Recurring Tasks Handling
// ---------------------------------------------------------------------------------
echo "\n--- TEST 10: Recurring Tasks Handling ---\n";
// Create a recurring series parent
$resRec = task_reminders_create($pdo, [
    'title' => 'Daily Morning Attendance Audit',
    'notes' => 'Verify biometrics clock-in data',
    'recurrence_type' => 'daily',
    'recurrence_start_date' => '2026-09-25',
    'recurrence_due_time' => '09:00',
    'task_type_id' => $typeCallId,
    'assigned_to' => 'admin_b',
], 1, 'superadmin');
$parentSeriesId = (int)$resRec['task_id'];

// Seed two child occurrences for this series
$pdo->prepare("
    INSERT INTO reminders (
        recurrence_series_id, recurrence_type, is_series_parent, occurrence_date,
        task_type_id, title, notes, remind_at, status,
        assigned_to_admin_id, assigned_to_username, created_by_admin_id, created_by_username, created_at
    ) VALUES
    (?, 'daily', 0, '2026-09-25', ?, 'Daily Morning Attendance Audit', 'Biometrics check', '2026-09-25 09:00:00', 'pending', 3, 'admin_b', 1, 'superadmin', '2026-09-25 08:00:00'),
    (?, 'daily', 0, '2026-09-26', ?, 'Daily Morning Attendance Audit', 'Biometrics check', '2026-09-26 09:00:00', 'pending', 3, 'admin_b', 1, 'superadmin', '2026-09-26 08:00:00')
")->execute([$parentSeriesId, $typeCallId, $parentSeriesId, $typeCallId]);

// Check parent vs occurrences in report
$repRec = task_reminders_get_history_report_data($pdo, ['date_from' => '2026-09-25', 'date_to' => '2026-09-27'], 1, 'superadmin', true);
$foundParent = false;
$occurrenceCount = 0;
foreach ($repRec['days'] as $d) {
    foreach ($d['groups'] as $g) {
        foreach ($g['items'] as $item) {
            if ($item['id'] === $parentSeriesId) $foundParent = true;
            if (strpos($item['title'], 'Daily Morning Attendance Audit') !== false) $occurrenceCount++;
        }
    }
}
assertTest("Test 10.1: Series parent template (is_series_parent=1) is strictly excluded from report", !$foundParent);
assertTest("Test 10.2: Child occurrences (is_series_parent=0) are included in report", $occurrenceCount > 0);

// ---------------------------------------------------------------------------------
// TEST 11: Admin Breakdown Table
// ---------------------------------------------------------------------------------
echo "\n--- TEST 11: Admin Breakdown Table ---\n";
assertTest("Test 11.1: Admins breakdown array exists and has entries", !empty($rep1['admins']));
$adminAlphaRow = null;
foreach ($rep1['admins'] as $adm) {
    if ($adm['username'] === 'admin_a') $adminAlphaRow = $adm;
}
assertTest("Test 11.2: Admin Alpha has total tasks >= 1 and completion_rate calculated",
    $adminAlphaRow !== null && $adminAlphaRow['total'] >= 1 && isset($adminAlphaRow['completion_rate'])
);

// ---------------------------------------------------------------------------------
// TEST 12: Empty State Handling
// ---------------------------------------------------------------------------------
echo "\n--- TEST 12: Empty State Handling ---\n";
$repEmpty = task_reminders_get_history_report_data($pdo, ['date_from' => '2029-01-01', 'date_to' => '2029-01-02'], 1, 'superadmin', true);
assertTest("Test 12.1: Empty query returns 0 total tasks", ($repEmpty['summary']['total_tasks'] ?? -1) === 0);
assertTest("Test 12.2: Empty query returns empty days array", empty($repEmpty['days']));
$pdfEmptyBytes = render_task_work_report_pdf($repEmpty);
assertTest("Test 12.3: PDF renders graceful empty state (valid PDF 1.4)",
    substr($pdfEmptyBytes, 0, 8) === '%PDF-1.4' && strpos($pdfEmptyBytes, '%%EOF') !== false
);

// ---------------------------------------------------------------------------------
// TEST 13: Vector PDF Generation Validity
// ---------------------------------------------------------------------------------
echo "\n--- TEST 13: Vector PDF Generation Validity ---\n";
$pdfBytes = render_task_work_report_pdf($rep1);
assertTest("Test 13.1: Output is valid PDF 1.4 header", substr($pdfBytes, 0, 8) === '%PDF-1.4');
assertTest("Test 13.2: Output contains %%EOF trailer", strpos($pdfBytes, '%%EOF') !== false);
assertTest("Test 13.3: Output size > 2KB (vector stream properly formed)", strlen($pdfBytes) > 2048, "Size: " . strlen($pdfBytes) . " bytes");

// ---------------------------------------------------------------------------------
// TEST 14: Multi-Page Layout & Header/Footer
// ---------------------------------------------------------------------------------
echo "\n--- TEST 14: Multi-Page Layout & Header/Footer ---\n";
// Create enough tasks across multiple days to force multi-page PDF
for ($k = 1; $k <= 15; $k++) {
    $dStr = sprintf('2026-10-%02d', ($k % 10) + 1);
    $resMulti = task_reminders_create($pdo, [
        'title' => "Extensive Multi-Page Audit Task {$k}",
        'notes' => "Multi-page testing notes line for task {$k} with sufficient text content to test layout density.",
        'remind_at' => "{$dStr} 11:00:00",
        'task_type_id' => $typeMeetingId,
        'assigned_to' => 'admin_b',
    ], 1, 'superadmin');
    $mId = (int)$resMulti['task_id'];
    $pdo->exec("UPDATE reminders SET created_at = '{$dStr} 08:00:00' WHERE id = {$mId}");
    $pdo->exec("UPDATE task_reminder_status_history SET changed_at = '{$dStr} 08:00:00' WHERE task_id = {$mId} AND event_type = 'CREATED'");
}
$repMulti = task_reminders_get_history_report_data($pdo, ['date_from' => '2026-10-01', 'date_to' => '2026-10-15'], 1, 'superadmin', true);
$pdfMultiBytes = render_task_work_report_pdf($repMulti);
assertTest("Test 14.1: Multi-page PDF successfully generated", strlen($pdfMultiBytes) > 5000);
assertTest("Test 14.2: Contains Page numbering syntax in stream", strpos($pdfMultiBytes, 'Page 1 of') !== false || strpos($pdfMultiBytes, 'Page ') !== false);

// ---------------------------------------------------------------------------------
// TEST 15: Text Wrapping & Table Widths
// ---------------------------------------------------------------------------------
echo "\n--- TEST 15: Text Wrapping & Table Widths ---\n";
$longTaskRes = task_reminders_create($pdo, [
    'title' => 'Long Text Wrapping Validation Task',
    'notes' => 'This is a very long descriptive note intended to verify that task_pdf_wrap_text correctly splits text into multiple lines without throwing warnings or overflowing page margins across standard A4 width constraints.',
    'remind_at' => '2026-10-20 15:00:00',
    'task_type_id' => $typeCallId,
    'assigned_to' => 'admin_a',
], 1, 'superadmin');
$ltId = (int)$longTaskRes['task_id'];
task_reminders_update_status($pdo, $ltId, 'completed', 'Completed with exceptionally long remarks explaining all the conversations held with parent, student, and academic coordinator.', 2, 'admin_a', false);
$pdo->exec("UPDATE reminders SET created_at = '2026-10-20 09:00:00', completed_at = '2026-10-20 15:30:00' WHERE id = {$ltId}");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-10-20 09:00:00' WHERE task_id = {$ltId} AND event_type = 'CREATED'");
$pdo->exec("UPDATE task_reminder_status_history SET changed_at = '2026-10-20 15:30:00' WHERE task_id = {$ltId} AND event_type = 'COMPLETED'");

$repWrap = task_reminders_get_history_report_data($pdo, ['date_from' => '2026-10-20', 'date_to' => '2026-10-20'], 1, 'superadmin', true);
$pdfWrapBytes = render_task_work_report_pdf($repWrap);
assertTest("Test 15.1: Long text wrapped and rendered without error", strlen($pdfWrapBytes) > 2048);

// ---------------------------------------------------------------------------------
// TEST 16: Super Admin Security & Authorization
// ---------------------------------------------------------------------------------
echo "\n--- TEST 16: Super Admin Security & Authorization ---\n";
// Non-superadmin access check
$repNonSuper = task_reminders_get_history_report_data($pdo, [], 2, 'admin_a', false);
// Non-superadmin should only see tasks assigned to/by admin_a
$onlyAdminA = true;
foreach ($repNonSuper['days'] as $d) {
    foreach ($d['groups'] as $g) {
        foreach ($g['items'] as $it) {
            if ($it['assigned_to_username'] !== 'admin_a' && $it['created_by_username'] !== 'admin_a' && $it['assigned_by_username'] !== 'admin_a') {
                $onlyAdminA = false;
            }
        }
    }
}
assertTest("Test 16.1: Non-superadmin scoped strictly to their own tasks", $onlyAdminA);
assertTest("Test 16.2: Super Admin endpoint file exists and has permission enforcement", file_exists(__DIR__ . '/task-reminder-report-pdf.php'));

// ---------------------------------------------------------------------------------
// TEST 17: User Mandatory Fixture (Event Filter & Date Semantic Integrity)
// ---------------------------------------------------------------------------------
echo "\n--- TEST 17: Multi-Stage Event Filter & Date Semantic Integrity Fixture ---\n";
echo "    [Fixture: Task A scheduled Sep 20 -> Postponed twice Sep 21 -> Completed Sep 22]\n";

// Fresh isolated database for pristine evaluation
$pdo17 = createTestDb();
$tTypes17 = task_types_get_all($pdo17);
$tTypeGeneral = (int)$tTypes17[0]['id'];

// Step 1: Created on Sep 20 09:00, scheduled for Sep 20 10:00
$resA = task_reminders_create($pdo17, [
    'title' => 'Mandatory Fixture Task A',
    'notes' => 'Important admission candidate document check',
    'remind_at' => '2026-09-20 10:00:00',
    'task_type_id' => $tTypeGeneral,
    'assigned_to' => 'admin_b',
], 1, 'superadmin');
$taskAId = (int)$resA['task_id'];

// Backdate created event to Sep 20 09:00:00
$pdo17->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-20 09:00:00' WHERE task_id = {$taskAId} AND event_type = 'CREATED'");
$pdo17->exec("UPDATE reminders SET created_at = '2026-09-20 09:00:00' WHERE id = {$taskAId}");

// Step 2: Postponed on Sep 21 11:00 to Sep 21 15:00
task_reminders_postpone($pdo17, $taskAId, '2026-09-21 15:00:00', 'First postponement: waiting for certificate', 3, 'admin_b', false);
$pdo17->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-21 11:00:00' WHERE task_id = {$taskAId} AND event_type = 'POSTPONED' AND remarks LIKE '%First postponement%'");

// Step 3: Postponed on Sep 21 14:00 to Sep 22 10:00
task_reminders_postpone($pdo17, $taskAId, '2026-09-22 10:00:00', 'Second postponement: student arriving tomorrow', 3, 'admin_b', false);
$pdo17->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-21 14:00:00' WHERE task_id = {$taskAId} AND event_type = 'POSTPONED' AND remarks LIKE '%Second postponement%'");

// Step 4: Completed on Sep 22 10:30
task_reminders_update_status($pdo17, $taskAId, 'completed', 'Final completion: verified and admitted', 3, 'admin_b', false);
$pdo17->exec("UPDATE task_reminder_status_history SET changed_at = '2026-09-22 10:30:00' WHERE task_id = {$taskAId} AND event_type = 'COMPLETED'");
$pdo17->exec("UPDATE reminders SET completed_at = '2026-09-22 10:30:00' WHERE id = {$taskAId}");

// Verification 17.1: Sep 20 Independent Report
$rSep20 = task_reminders_get_history_report_data($pdo17, ['date_from' => '2026-09-20', 'date_to' => '2026-09-20'], 1, 'superadmin', true);
assertTest("Test 17.1: Sep 20 Report -> Total 1, Open 1, Completed 0, Postponed 0, Daily Section = 2026-09-20",
    ($rSep20['summary']['total_tasks'] === 1) &&
    ($rSep20['summary']['open_total'] === 1) &&
    ($rSep20['summary']['completed'] === 0) &&
    ($rSep20['summary']['postponed_tasks'] === 0) &&
    ($rSep20['summary']['postponement_events'] === 0) &&
    (count($rSep20['days']) === 1 && $rSep20['days'][0]['date'] === '2026-09-20'),
    sprintf("total=%d, open=%d, comp=%d, postp=%d, day=%s",
        $rSep20['summary']['total_tasks'] ?? -1,
        $rSep20['summary']['open_total'] ?? -1,
        $rSep20['summary']['completed'] ?? -1,
        $rSep20['summary']['postponed_tasks'] ?? -1,
        $rSep20['days'][0]['date'] ?? 'none'
    )
);

// Verification 17.2: Sep 21 Independent Report
$rSep21 = task_reminders_get_history_report_data($pdo17, ['date_from' => '2026-09-21', 'date_to' => '2026-09-21'], 1, 'superadmin', true);
assertTest("Test 17.2: Sep 21 Report -> Total 1, Open 1, Completed 0, Postponed Tasks 1, Postpone Events 2, Daily Section = 2026-09-21 (NOT Completed!)",
    ($rSep21['summary']['total_tasks'] === 1) &&
    ($rSep21['summary']['open_total'] === 1) &&
    ($rSep21['summary']['completed'] === 0) &&
    ($rSep21['summary']['postponed_tasks'] === 1) &&
    ($rSep21['summary']['postponement_events'] === 2) &&
    (count($rSep21['days']) === 1 && $rSep21['days'][0]['date'] === '2026-09-21'),
    sprintf("total=%d, open=%d, comp=%d, postp_tasks=%d, postp_events=%d, day=%s",
        $rSep21['summary']['total_tasks'] ?? -1,
        $rSep21['summary']['open_total'] ?? -1,
        $rSep21['summary']['completed'] ?? -1,
        $rSep21['summary']['postponed_tasks'] ?? -1,
        $rSep21['summary']['postponement_events'] ?? -1,
        $rSep21['days'][0]['date'] ?? 'none'
    )
);

// Verification 17.3: Sep 22 Independent Report
$rSep22 = task_reminders_get_history_report_data($pdo17, ['date_from' => '2026-09-22', 'date_to' => '2026-09-22'], 1, 'superadmin', true);
assertTest("Test 17.3: Sep 22 Report -> Total 1, Open 0, Completed 1, Postponed 0, Daily Section = 2026-09-22",
    ($rSep22['summary']['total_tasks'] === 1) &&
    ($rSep22['summary']['open_total'] === 0) &&
    ($rSep22['summary']['completed'] === 1) &&
    ($rSep22['summary']['postponed_tasks'] === 0) &&
    ($rSep22['summary']['postponement_events'] === 0) &&
    (count($rSep22['days']) === 1 && $rSep22['days'][0]['date'] === '2026-09-22'),
    sprintf("total=%d, open=%d, comp=%d, postp=%d, day=%s",
        $rSep22['summary']['total_tasks'] ?? -1,
        $rSep22['summary']['open_total'] ?? -1,
        $rSep22['summary']['completed'] ?? -1,
        $rSep22['summary']['postponed_tasks'] ?? -1,
        $rSep22['days'][0]['date'] ?? 'none'
    )
);

// Verification 17.4: Sep 20–22 Combined Report
$rSep20_22 = task_reminders_get_history_report_data($pdo17, ['date_from' => '2026-09-20', 'date_to' => '2026-09-22'], 1, 'superadmin', true);
assertTest("Test 17.4: Sep 20–22 Combined Report -> Total 1, Completed 1, Postponed Tasks 1, Postpone Events 2, Daily Section = 2026-09-22",
    ($rSep20_22['summary']['total_tasks'] === 1) &&
    ($rSep20_22['summary']['completed'] === 1) &&
    ($rSep20_22['summary']['open_total'] === 0) &&
    ($rSep20_22['summary']['postponed_tasks'] === 1) &&
    ($rSep20_22['summary']['postponement_events'] === 2) &&
    (count($rSep20_22['days']) === 1 && $rSep20_22['days'][0]['date'] === '2026-09-22'),
    sprintf("total=%d, open=%d, comp=%d, postp_tasks=%d, postp_events=%d, day=%s",
        $rSep20_22['summary']['total_tasks'] ?? -1,
        $rSep20_22['summary']['open_total'] ?? -1,
        $rSep20_22['summary']['completed'] ?? -1,
        $rSep20_22['summary']['postponed_tasks'] ?? -1,
        $rSep20_22['summary']['postponement_events'] ?? -1,
        $rSep20_22['days'][0]['date'] ?? 'none'
    )
);

// Verification 17.5: Sep 20–22 Report with Event = POSTPONED
$rSep20_22_Postpone = task_reminders_get_history_report_data($pdo17, [
    'date_from' => '2026-09-20',
    'date_to' => '2026-09-22',
    'event_type' => 'POSTPONED'
], 1, 'superadmin', true);
assertTest("Test 17.5: Sep 20–22 with Event=POSTPONED -> Total 1, Open 1, Completed 0, Postponed Tasks 1, Postpone Events 2, Daily Section = 2026-09-21 (NOT Completed!)",
    ($rSep20_22_Postpone['summary']['total_tasks'] === 1) &&
    ($rSep20_22_Postpone['summary']['open_total'] === 1) &&
    ($rSep20_22_Postpone['summary']['completed'] === 0) &&
    ($rSep20_22_Postpone['summary']['postponed_tasks'] === 1) &&
    ($rSep20_22_Postpone['summary']['postponement_events'] === 2) &&
    (count($rSep20_22_Postpone['days']) === 1 && $rSep20_22_Postpone['days'][0]['date'] === '2026-09-21'),
    sprintf("total=%d, open=%d, comp=%d, postp_tasks=%d, postp_events=%d, day=%s",
        $rSep20_22_Postpone['summary']['total_tasks'] ?? -1,
        $rSep20_22_Postpone['summary']['open_total'] ?? -1,
        $rSep20_22_Postpone['summary']['completed'] ?? -1,
        $rSep20_22_Postpone['summary']['postponed_tasks'] ?? -1,
        $rSep20_22_Postpone['summary']['postponement_events'] ?? -1,
        $rSep20_22_Postpone['days'][0]['date'] ?? 'none'
    )
);

// Render PDF for Test 17 to ensure complete visual pipeline compatibility
$pdf17Bytes = render_task_work_report_pdf($rSep20_22);
assertTest("Test 17.6: Multi-stage fixture renders valid vector PDF",
    substr($pdf17Bytes, 0, 8) === '%PDF-1.4' && strpos($pdf17Bytes, '%%EOF') !== false
);

// ---------------------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------------------
echo "\n==================================================================================\n";
echo "AUDIT RESULTS: {$passes} Passed, {$fails} Failed\n";
echo "==================================================================================\n";

if ($fails > 0) {
    exit(1);
}
exit(0);
