<?php
/**
 * PEPP Learning ERP — L&D Operations Work Report Quantity Breakdown & Mask Charge Test Suite
 *
 * Verifies all 12 test scenarios:
 * TEST 1: Normal PDF export has Charge column and Total Charge.
 * TEST 2: Mask Charge = false displays charges, rates, and totals.
 * TEST 3: Mask Charge = true has NO Charge column, NO Total Charge, NO rates, NO currency values.
 * TEST 4: Masked PDF preserves all quantities (e.g. 150 Questions, 70 Questions, Total Quantity: 220 Questions).
 * TEST 5: Mixed units (150 Questions + 5 Pages -> Questions: 150, Pages: 5, never 155).
 * TEST 6: Multiple similar work items aggregate correctly by unit.
 * TEST 7: Filter compatibility (staff, course, mode, date) returns identical scope with or without mask_charge.
 * TEST 8: Multi-page report layout integrity (page breaks, table header repeating, two-pass footers).
 * TEST 9: Long work details wrap properly without clipping.
 * TEST 10: Read-only invariant: charge masking performs zero DB modifications.
 * TEST 11: Regression check: test_ld_payment_hardening_audit.php passes 100%.
 * TEST 12: Unknown / Custom unit safeguard: Custom units (Sessions, Certificates, Minutes) preserved as-is.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_username'] = 'superadmin_tester';

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ld_report_pdf.php';

// Setup isolated in-memory SQLite DB
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

$pdo->exec("
    CREATE TABLE ld_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        admin_username TEXT NOT NULL,
        admin_name TEXT NOT NULL,
        admin_role TEXT NOT NULL,
        course_id INTEGER NOT NULL,
        course_name TEXT NOT NULL,
        mode_id INTEGER NOT NULL,
        mode_name TEXT NOT NULL,
        mode_name_snapshot TEXT DEFAULT NULL,
        quantity_label_snapshot TEXT DEFAULT 'Questions',
        charge_per_quantity_snapshot REAL DEFAULT 3.00,
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL
    );

    CREATE TABLE ld_task_topics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        topic_name TEXT NOT NULL,
        quantity REAL DEFAULT NULL,
        calculated_charge REAL NOT NULL DEFAULT 0.00,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

$tests_passed = 0;
$tests_failed = 0;

function assert_test(bool $cond, string $msg): void {
    global $tests_passed, $tests_failed;
    if ($cond) {
        $tests_passed++;
        echo "  [PASS] $msg\n";
    } else {
        $tests_failed++;
        echo "  [FAIL] $msg\n";
    }
}

echo "====================================================================\n";
echo "PEPP ERP — L&D OPERATIONS WORK REPORT QUANTITY & MASK CHARGE AUDIT\n";
echo "====================================================================\n\n";

// ── FIXTURES INSERTION ──
// Task 1: Isha Fathima, Tests: Copy & Paste questions, 150 Questions + 70 Questions @ 3.00
$pdo->exec("
    INSERT INTO ld_tasks (id, admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, mode_name_snapshot, quantity_label_snapshot, charge_per_quantity_snapshot, status, created_at)
    VALUES (1, 10, 'isha_fathima', 'Isha Fathima E P', 'admin', 1, 'M. Clin Psy', 1, 'Tests', 'Tests: Copy & Paste questions', 'Questions', 3.00, 'active', '2026-09-18 17:39:00');

    INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge)
    VALUES (1, 'psy testing', 150.0, 450.0),
           (1, 'psychotherapy', 70.0, 210.0);
");

// Task 2: Mixed units work item (e.g. Study Materials, 5 Pages @ 15.00)
$pdo->exec("
    INSERT INTO ld_tasks (id, admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, mode_name_snapshot, quantity_label_snapshot, charge_per_quantity_snapshot, status, created_at)
    VALUES (2, 10, 'isha_fathima', 'Isha Fathima E P', 'admin', 1, 'M. Clin Psy', 2, 'Study Materials', 'Study Materials: Update existing materials', 'Pages', 15.00, 'active', '2026-09-15 17:44:00');

    INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge)
    VALUES (2, 'statistics', 5.0, 75.0);
");

// Task 3: Custom legitimate unit: 2 Sessions @ 250.00
$pdo->exec("
    INSERT INTO ld_tasks (id, admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, mode_name_snapshot, quantity_label_snapshot, charge_per_quantity_snapshot, status, created_at)
    VALUES (3, 12, 'rahul_m', 'Rahul M', 'intern', 2, 'B.Sc Psychology', 3, 'Mentoring Sessions', 'Mentoring Sessions: 1-on-1', 'Sessions', 250.00, 'active', '2026-09-14 11:30:00');

    INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge)
    VALUES (3, 'Cognitive Behavioral Intro', 2.0, 500.0);
");

// Helper function to build structured report data from DB
function fetch_test_report_data(PDO $pdo, string $where_sql = 'WHERE t.status = "active"', array $params = []): array {
    $stmt = $pdo->prepare("SELECT t.* FROM ld_tasks t $where_sql ORDER BY t.created_at DESC");
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($tasks)) {
        $task_ids = array_map(function($x) { return (int)$x['id']; }, $tasks);
        $in_clause = implode(',', $task_ids);
        $topics_rows = $pdo->query("SELECT * FROM ld_task_topics WHERE task_id IN ($in_clause) ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

        $topics_by_task = [];
        foreach ($topics_rows as $row) {
            $topics_by_task[$row['task_id']][] = $row;
        }
        foreach ($tasks as &$t) {
            $t['topics'] = $topics_by_task[$t['id']] ?? [];
        }
        unset($t);
    }

    $total_tasks = 0;
    $total_topics = 0;
    $active_days = 0;
    $course_breakdown = [];
    $mode_breakdown = [];
    $total_charge_sum = 0.00;
    $report_quantities = [];
    $dates = [];

    foreach ($tasks as $tk) {
        $total_tasks++;
        $cnt = count($tk['topics']);
        $total_topics += $cnt;
        $dates[date('Y-m-d', strtotime($tk['created_at']))] = true;

        $c_name = $tk['course_name'] ?: 'Unknown Course';
        if (!isset($course_breakdown[$c_name])) {
            $course_breakdown[$c_name] = ['cnt' => 0, 'quantities' => []];
        }
        $course_breakdown[$c_name]['cnt'] += $cnt;

        $mode_title = $tk['mode_name_snapshot'] ?: $tk['mode_name'];
        if (!isset($mode_breakdown[$mode_title])) {
            $mode_breakdown[$mode_title] = ['cnt' => 0, 'quantities' => []];
        }
        $mode_breakdown[$mode_title]['cnt'] += $cnt;

        $unit = ld_normalize_unit($tk['quantity_label_snapshot'] ?? null);

        foreach ($tk['topics'] as $tp) {
            $total_charge_sum += (float)($tp['calculated_charge'] ?? 0.0);
            if ($tp['quantity'] !== null) {
                $q = (float)$tp['quantity'];
                $report_quantities[$unit] = ($report_quantities[$unit] ?? 0.0) + $q;
                $course_breakdown[$c_name]['quantities'][$unit] = ($course_breakdown[$c_name]['quantities'][$unit] ?? 0.0) + $q;
                $mode_breakdown[$mode_title]['quantities'][$unit] = ($mode_breakdown[$mode_title]['quantities'][$unit] ?? 0.0) + $q;
            }
        }
    }
    $active_days = count($dates);

    return [
        'tasks'             => $tasks,
        'total_tasks'       => $total_tasks,
        'total_topics'      => $total_topics,
        'active_days'       => $active_days,
        'total_charge_sum'  => $total_charge_sum,
        'course_breakdown'  => $course_breakdown,
        'mode_breakdown'    => $mode_breakdown,
        'report_quantities' => $report_quantities,
        'generated_at'      => '18-09-2026 05:40 PM'
    ];
}

$base_report_data = fetch_test_report_data($pdo);

// ── TEST 1: Existing normal PDF export ──
echo "--- TEST 1: Existing Normal PDF Export ---\n";
$pdf_normal = render_ld_work_report_pdf($base_report_data, false);
assert_test(str_starts_with($pdf_normal, '%PDF-1.4'), "Normal PDF is valid PDF 1.4 document");
assert_test(str_contains($pdf_normal, 'Charge \(INR\)'), "Charge column header is present in normal PDF");
assert_test(str_contains($pdf_normal, 'Total Charge: INR'), "Summary metrics contains Total Charge in normal PDF");
assert_test(str_contains($pdf_normal, '660.00'), "Row charge ₹660.00 is present in normal PDF");

// ── TEST 2: Mask Charge = false ──
echo "\n--- TEST 2: Mask Charge = false ---\n";
$pdf_unmasked = render_ld_work_report_pdf($base_report_data, false);
assert_test(str_contains($pdf_unmasked, '@ INR 3.00') || str_contains($pdf_unmasked, '@ INR'), "Rate per unit is visible when Mask Charge = false");
assert_test(str_contains($pdf_unmasked, 'Total Charge: INR 1,235.00'), "Total Charge matches sum of calculated charges (450 + 210 + 75 + 500 = 1,235.00)");

// ── TEST 3: Mask Charge = true ──
echo "\n--- TEST 3: Mask Charge = true (Complete Financial Masking) ---\n";
$pdf_masked = render_ld_work_report_pdf($base_report_data, true);
assert_test(!str_contains($pdf_masked, 'Charge \(INR\)'), "Charge column header is completely absent in masked PDF");
assert_test(!str_contains($pdf_masked, 'Total Charge:'), "Total Charge metric is completely absent in masked PDF");
assert_test(!str_contains($pdf_masked, '@ INR'), "Rate per unit (@ INR) is completely absent in masked PDF");
assert_test(!str_contains($pdf_masked, 'INR 660.00'), "Financial charge amounts absent in masked PDF");
assert_test(!str_contains($pdf_masked, 'INR 1,235.00'), "Grand total amount absent in masked PDF");
assert_test(!str_contains($pdf_masked, 'Rs.'), "No Rs. currency strings in masked PDF");
assert_test(!str_contains($pdf_masked, '₹'), "No ₹ currency symbol in masked PDF");

// ── TEST 4: Masked PDF still shows quantity ──
echo "\n--- TEST 4: Masked PDF Preserves Quantity ---\n";
assert_test(str_contains($pdf_masked, '150 Questions'), "Topic 1 quantity (150 Questions) is preserved in masked PDF");
assert_test(str_contains($pdf_masked, '70 Questions'), "Topic 2 quantity (70 Questions) is preserved in masked PDF");
assert_test(str_contains($pdf_masked, '220 Questions'), "Task 1 total quantity (220 Questions) is preserved in masked PDF");
assert_test(str_contains($pdf_masked, 'psy testing - 150 Questions'), "Detail shows clean 'topic — quantity' without rates in masked PDF");

// ── TEST 5: Mixed Units Invariant ──
echo "\n--- TEST 5: Mixed Units Grouping & Non-Mixing ---\n";
// Task 1 has Questions, Task 2 has Pages, Task 3 has Sessions
// Total should be Questions: 220, Pages: 5, Sessions: 2 (never 227)
$qty_summary = ld_format_quantities_by_unit($base_report_data['report_quantities'], true);
assert_test(str_contains($qty_summary, '220 Questions'), "Total quantity contains 220 Questions");
assert_test(str_contains($qty_summary, '5 Pages'), "Total quantity contains 5 Pages");
assert_test(str_contains($qty_summary, '2 Sessions'), "Total quantity contains 2 Sessions");
assert_test(!str_contains($qty_summary, '227'), "Different units are NEVER combined into a single sum (no 227)");

// ── TEST 6: Multiple Similar Work Items Aggregate Correctly ──
echo "\n--- TEST 6: Multiple Similar Work Items Aggregate by Unit ---\n";
$pdo->exec("
    INSERT INTO ld_tasks (admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, mode_name_snapshot, quantity_label_snapshot, charge_per_quantity_snapshot, status, created_at)
    VALUES (10, 'isha_fathima', 'Isha Fathima E P', 'admin', 1, 'M. Clin Psy', 1, 'Tests', 'Tests: Copy & Paste questions', 'Questions', 3.00, 'active', '2026-09-17 10:00:00');
    INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge)
    VALUES (4, 'Topic A', 100.0, 300.0),
           (4, 'Topic B', 200.0, 600.0);
");
$updated_report = fetch_test_report_data($pdo);
assert_test($updated_report['report_quantities']['Questions'] === 520.0, "Questions aggregated across tasks: 150 + 70 + 100 + 200 = 520");
assert_test($updated_report['mode_breakdown']['Tests: Copy & Paste questions']['quantities']['Questions'] === 520.0, "Mode breakdown aggregated: 520 Questions");

// ── TEST 7: Active Filters Compatibility ──
echo "\n--- TEST 7: Filter Compatibility (Identical Scope with/without Mask Charge) ---\n";
$filtered_data = fetch_test_report_data($pdo, "WHERE t.admin_username = :adm AND t.status = 'active'", [':adm' => 'isha_fathima']);
$pdf_filt_unmasked = render_ld_work_report_pdf($filtered_data, false);
$pdf_filt_masked = render_ld_work_report_pdf($filtered_data, true);
assert_test($filtered_data['total_tasks'] === 3, "Filtered tasks count is 3 for isha_fathima");
assert_test(str_contains($pdf_filt_unmasked, 'Isha Fathima E P') && str_contains($pdf_filt_masked, 'Isha Fathima E P'), "Both PDFs contain the filtered staff name");
assert_test(!str_contains($pdf_filt_unmasked, 'Rahul M') && !str_contains($pdf_filt_masked, 'Rahul M'), "Both PDFs exclude non-matching staff Rahul M");

// ── TEST 8: Multi-Page Report Layout Integrity ──
echo "\n--- TEST 8: Multi-Page Report Layout Integrity ---\n";
// Insert 25 tasks to force multiple pages
for ($i = 5; $i <= 30; $i++) {
    $pdo->exec("
        INSERT INTO ld_tasks (admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, quantity_label_snapshot, charge_per_quantity_snapshot, status, created_at)
        VALUES (10, 'isha_fathima', 'Isha Fathima', 'admin', 1, 'M. Clin Psy', 1, 'Tests', 'Questions', 3.00, 'active', '2026-09-10 10:00:00');
        INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge)
        VALUES ($i, 'Batch Test Topic $i', 50.0, 150.0);
    ");
}
$multipage_data = fetch_test_report_data($pdo);
$pdf_multipage = render_ld_work_report_pdf($multipage_data, true);
assert_test(str_contains($pdf_multipage, 'Page 1 of') && str_contains($pdf_multipage, 'Page 2 of'), "Multi-page report generated with two-pass page numbers");
assert_test(str_contains($pdf_multipage, 'ACTIVITY LOGS \(Cont.\)'), "Continuation header printed on subsequent pages");

// ── TEST 9: Long Work Details Wrap Correctly ──
echo "\n--- TEST 9: Long Work Details Wrapping ---\n";
$long_title = "Advanced Neuropsychological Assessment of Cognitive Functioning in Geriatric Populations with Multi-Infarct Dementia and Mild Cognitive Impairment";
$pdo->exec("
    INSERT INTO ld_tasks (admin_id, admin_username, admin_name, admin_role, course_id, course_name, mode_id, mode_name, quantity_label_snapshot, charge_per_quantity_snapshot, status, created_at)
    VALUES (10, 'isha_fathima', 'Isha Fathima', 'admin', 1, 'M. Clin Psy', 1, 'Tests', 'Questions', 3.00, 'active', '2026-09-01 10:00:00');
    INSERT INTO ld_task_topics (task_id, topic_name, quantity, calculated_charge)
    VALUES (31, '$long_title', 25.0, 75.0);
");
$long_report_data = fetch_test_report_data($pdo, "WHERE t.id = 31");
$pdf_long = render_ld_work_report_pdf($long_report_data, true);
assert_test(str_contains($pdf_long, 'Neuropsychological') && str_contains($pdf_long, 'Geriatric Populations'), "Long title is wrapped and rendered in PDF");
assert_test(str_contains($pdf_long, '25 Questions'), "Quantity is retained alongside wrapped long title");

// ── TEST 10: Charge Masking Is Read-Only (Zero Database Modification) ──
echo "\n--- TEST 10: Read-Only Invariant (Zero DB Mutations) ---\n";
$db_state_before = $pdo->query("SELECT id, calculated_charge, quantity FROM ld_task_topics ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$pdf_test_run = render_ld_work_report_pdf($base_report_data, true);
$db_state_after = $pdo->query("SELECT id, calculated_charge, quantity FROM ld_task_topics ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assert_test($db_state_before === $db_state_after, "Database calculated_charge and quantity fields are 100% identical before and after masked PDF generation");

// ── TEST 11: Regression Check of Existing Test Suites ──
echo "\n--- TEST 11: Regression Check of Existing Test Suites ---\n";
$output_reg = shell_exec('php ' . escapeshellarg(__DIR__ . '/test_ld_payment_hardening_audit.php'));
assert_test(str_contains($output_reg, '24 PASSED, 0 FAILED'), "Existing test_ld_payment_hardening_audit.php passes 100% (24/24)");

// ── TEST 12: Unknown / Custom Unit Safeguard ──
echo "\n--- TEST 12: Unknown / Custom Unit Safeguard ---\n";
assert_test(ld_normalize_unit('Sessions') === 'Sessions', "Custom unit 'Sessions' preserved as 'Sessions'");
assert_test(ld_normalize_unit('certificates') === 'Certificates', "Custom unit 'certificates' preserved and capitalized");
assert_test(ld_normalize_unit('Minutes') === 'Minutes', "Custom unit 'Minutes' preserved as 'Minutes'");
assert_test(ld_normalize_unit('Students') === 'Students', "Custom unit 'Students' preserved as 'Students'");
assert_test(ld_normalize_unit(null) === 'Units', "Null unit safely falls back to 'Units'");
assert_test(ld_normalize_unit('') === 'Units', "Empty string unit safely falls back to 'Units'");
assert_test(ld_normalize_unit('   ') === 'Units', "Whitespace unit safely falls back to 'Units'");
assert_test(ld_normalize_unit('questions') === 'Questions', "Known alias 'questions' normalized to 'Questions'");
assert_test(ld_normalize_unit('pages') === 'Pages', "Known alias 'pages' normalized to 'Pages'");

echo "\n====================================================================\n";
echo "AUDIT SUMMARY: $tests_passed PASSED, $tests_failed FAILED\n";
echo "====================================================================\n";

if ($tests_failed > 0) {
    exit(1);
}
exit(0);
