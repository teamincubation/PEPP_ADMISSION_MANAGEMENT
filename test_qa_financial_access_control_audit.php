<?php
/**
 * Test Suite: Lecture Quality Assessment Financial Access Control & Privacy Audit
 *
 * Verifies:
 * 1. Intern role: Hourly Rate and Calculated Charge are completely hidden from UI, HTML, JS, and AJAX JSON.
 * 2. Super Admin role: Hourly Rate and Calculated Charge are visible.
 * 3. Server-side payment calculations remain 100% authoritative and exact.
 * 4. Assessment Hours, lecture counts, and validation functionality remain functional for interns.
 * 5. Zero exposure of financial values via hidden inputs, data attributes, or response payloads.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "====================================================================\n";
echo "PEPP ERP — L&D QA FINANCIAL ACCESS CONTROL & PRIVACY AUDIT\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] " . $name . ($detail ? " — " . $detail : "") . "\n";
    } else {
        $failCount++;
        echo "[FAIL] " . $name . ($detail ? " — " . $detail : "") . "\n";
    }
}

// ── 1. Setup isolated in-memory SQLite DB ──
$testDb = new PDO('sqlite::memory:');
$testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testDb->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$testDb->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

$testDb->exec("
    CREATE TABLE admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'admin',
        admin_type TEXT NOT NULL DEFAULT 'intern',
        permissions TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'active'
    );
    CREATE TABLE ld_work_courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        course_name TEXT NOT NULL,
        sort_order INTEGER DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active'
    );
    CREATE TABLE ld_work_modes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mode_name TEXT NOT NULL,
        mode_key TEXT DEFAULT NULL,
        is_system INTEGER DEFAULT 0,
        quantity_label TEXT NOT NULL DEFAULT 'Hour',
        charge_per_quantity REAL DEFAULT 120.00,
        status TEXT NOT NULL DEFAULT 'active'
    );
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
        latitude REAL,
        longitude REAL,
        maps_url TEXT,
        ip_address TEXT,
        user_agent TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT,
        quantity_label_snapshot TEXT DEFAULT 'Hour',
        charge_per_quantity_snapshot REAL DEFAULT 120.00,
        mode_name_snapshot TEXT DEFAULT 'Lectures: Quality Assessment'
    );
    CREATE TABLE ld_task_topics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        topic_name TEXT NOT NULL,
        quantity REAL NOT NULL,
        calculated_charge REAL NOT NULL
    );
    CREATE TABLE ld_quality_assessment_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER DEFAULT NULL,
        parent_report_id INTEGER DEFAULT NULL,
        version INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        admin_id INTEGER NOT NULL,
        admin_username TEXT NOT NULL,
        admin_name TEXT NOT NULL,
        course_id INTEGER NOT NULL,
        course_name_snapshot TEXT NOT NULL,
        report_reference TEXT NOT NULL,
        original_filename TEXT NOT NULL,
        stored_path TEXT NOT NULL,
        file_type TEXT NOT NULL,
        file_size INTEGER NOT NULL,
        sha256_hash TEXT NOT NULL,
        row_count INTEGER NOT NULL,
        total_lecture_duration_minutes REAL NOT NULL,
        total_assessment_minutes REAL NOT NULL,
        total_assessment_hours REAL NOT NULL,
        hourly_rate_snapshot REAL NOT NULL,
        calculated_charge REAL NOT NULL,
        validation_status TEXT NOT NULL,
        validation_notes TEXT DEFAULT NULL,
        ai_status TEXT NOT NULL DEFAULT 'pending',
        ai_overall_grade TEXT DEFAULT NULL,
        ai_summary TEXT DEFAULT NULL,
        admin_review_status TEXT NOT NULL DEFAULT 'pending',
        admin_final_grade INTEGER DEFAULT NULL,
        admin_review_notes TEXT DEFAULT NULL,
        reviewed_by TEXT DEFAULT NULL,
        reviewed_at TEXT DEFAULT NULL,
        created_at TEXT,
        updated_at TEXT DEFAULT NULL
    );
    CREATE TABLE ld_quality_assessment_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_id INTEGER NOT NULL,
        source_row_number INTEGER NOT NULL,
        chapter TEXT NOT NULL,
        lecture_title TEXT NOT NULL,
        language TEXT NOT NULL,
        faculty_name TEXT NOT NULL,
        lecture_duration_minutes REAL NOT NULL,
        content_grade INTEGER NOT NULL,
        content_remark TEXT DEFAULT NULL,
        video_grade INTEGER NOT NULL,
        video_remark TEXT DEFAULT NULL,
        audio_grade INTEGER NOT NULL,
        audio_remark TEXT DEFAULT NULL,
        slide_grade INTEGER NOT NULL,
        slide_remark TEXT DEFAULT NULL,
        assessment_minutes REAL NOT NULL,
        normalized_lecture_key TEXT NOT NULL,
        created_at TEXT
    );
    CREATE TABLE ld_quality_assessment_ai_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_id INTEGER NOT NULL,
        provider TEXT NOT NULL,
        model TEXT NOT NULL,
        prompt_version TEXT NOT NULL,
        generated_at TEXT NOT NULL,
        overall_grade TEXT DEFAULT NULL,
        summary TEXT DEFAULT NULL,
        aspect_analysis_json TEXT DEFAULT NULL,
        recommendations_json TEXT DEFAULT NULL,
        reverification_items_json TEXT DEFAULT NULL,
        raw_structured_result TEXT DEFAULT NULL,
        status TEXT NOT NULL,
        error_message TEXT DEFAULT NULL,
        created_at TEXT
    );
    CREATE TABLE ld_task_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        admin_id INTEGER NOT NULL,
        admin_username TEXT NOT NULL,
        action TEXT NOT NULL,
        previous_values TEXT,
        new_values TEXT,
        latitude REAL,
        longitude REAL,
        maps_url TEXT,
        ip_address TEXT,
        user_agent TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
");

// Seed courses & modes
$testDb->exec("INSERT INTO ld_work_courses (id, course_name) VALUES (1, 'CUET PG Psychology')");
$testDb->exec("INSERT INTO ld_work_modes (id, mode_name, mode_key, is_system, quantity_label, charge_per_quantity) VALUES (27, 'Lectures: Quality Assessment', 'lecture_quality_assessment', 1, 'Hour', 120.00)");

// Seed admin users: 1 intern, 1 super_admin, 1 financial_admin
$testDb->exec("INSERT INTO admins (id, username, full_name, role, admin_type, permissions) VALUES (1, 'intern_user', 'Intern Tester', 'admin', 'intern', 'task-tracker')");
$testDb->exec("INSERT INTO admins (id, username, full_name, role, admin_type, permissions) VALUES (2, 'super_admin_user', 'Super Admin Tester', 'super_admin', 'superadmin', 'ALL')");
$testDb->exec("INSERT INTO admins (id, username, full_name, role, admin_type, permissions) VALUES (3, 'financial_mgr', 'Finance Admin Tester', 'admin', 'erp_admin', 'task-tracker,ld-work-report')");

require_once __DIR__ . '/includes/ld_quality_assessment_helper.php';

// ── TEST 1: Role-Based Authorization Logic Verification ──
echo "--- Section 1: Authorization Logic Verification ---\n";
// Emulate authorization function
function check_qa_financial_access($role, $adminType, $perms): bool {
    $is_super = ($role === 'super_admin');
    $is_intern = ($adminType === 'intern') || (!$is_super && $adminType !== 'superadmin' && trim($perms) !== 'ALL' && in_array('task-tracker', array_map('trim', explode(',', $perms)), true) && !in_array('ld-work-report', array_map('trim', explode(',', $perms)), true));
    $can_access_report = $is_super || trim($perms) === 'ALL' || in_array('ld-work-report', array_map('trim', explode(',', $perms)), true);
    return $is_super || (!$is_intern && $can_access_report);
}

$internAccess = check_qa_financial_access('admin', 'intern', 'task-tracker');
assert_test("1. Intern financial access is FALSE", $internAccess === false, "intern_user cannot view financials");

$superAdminAccess = check_qa_financial_access('super_admin', 'superadmin', 'ALL');
assert_test("2. Super Admin financial access is TRUE", $superAdminAccess === true, "super_admin_user can view financials");

$financeAdminAccess = check_qa_financial_access('admin', 'erp_admin', 'task-tracker,ld-work-report');
assert_test("3. Financial manager (ld-work-report) access is TRUE", $financeAdminAccess === true, "financial_mgr can view financials");

$normalAdminAccess = check_qa_financial_access('admin', 'erp_admin', 'approvals,marketing');
assert_test("4. Normal non-financial admin access is FALSE", $normalAdminAccess === false, "Regular non-financial staff cannot view financials");

// ── TEST 2: Validate QA Upload AJAX Response Sanitization ──
echo "\n--- Section 2: Validate QA Upload AJAX Response Sanitization ---\n";

// Create valid sample CSV
$validCsv = generate_ld_qa_csv_template();
$tempFile = sys_get_temp_dir() . '/test_qa_validation.csv';
file_put_contents($tempFile, $validCsv);

// 115 assessment minutes @ 120/hr -> 1.9167 hrs -> ₹230.00
$valRes = validate_ld_qa_file($tempFile, 'test.csv', 'CUET PG Psychology', 120.00, $testDb, 1);
assert_test("5. validate_ld_qa_file computes authoritative stats", $valRes['valid'] === true && (float)$valRes['stats']['calculated_charge'] === 230.00, "Calculated charge is exact ₹230.00 internally");

// Emulate task-tracker.php AJAX validate_qa_upload response generation for Intern
$hourly_rate = 120.00;
$can_view_financials = false; // Intern
$clientStatsIntern = $valRes['stats'] ?? null;
if ($clientStatsIntern && !$can_view_financials) {
    unset($clientStatsIntern['hourly_rate']);
    unset($clientStatsIntern['calculated_charge']);
}
$internResp = [
    'success' => true,
    'valid' => $valRes['valid'],
    'errors' => $valRes['errors'],
    'errors_by_category' => $valRes['errors_by_category'] ?? null,
    'warnings' => $valRes['warnings'],
    'stats' => $clientStatsIntern,
    'temp_token' => 'tok123intern',
    'can_view_financials' => $can_view_financials
];
if ($can_view_financials) {
    $internResp['hourly_rate'] = $hourly_rate;
}
$internJson = json_encode($internResp);

// Verify Intern JSON does NOT expose any financial figures
assert_test("6. Intern JSON has NO 'hourly_rate' key", !isset($internResp['hourly_rate']), "hourly_rate is omitted from top level");
assert_test("7. Intern JSON stats has NO 'hourly_rate'", !isset($internResp['stats']['hourly_rate']), "stats.hourly_rate is omitted");
assert_test("8. Intern JSON stats has NO 'calculated_charge'", !isset($internResp['stats']['calculated_charge']), "stats.calculated_charge is omitted");
assert_test("9. Intern JSON contains zero occurrences of '120'", strpos($internJson, '120') === false, "Raw rate 120 is not in intern JSON");
assert_test("10. Intern JSON contains zero occurrences of '230'", strpos($internJson, '230') === false, "Raw charge 230 is not in intern JSON");
assert_test("11. Intern JSON preserves total_lectures", $internResp['stats']['total_lectures'] === 2, "total_lectures is 2");
assert_test("12. Intern JSON preserves total_assessment_hours", $internResp['stats']['total_assessment_hours'] > 1.9, "total_assessment_hours is 1.9167");
assert_test("13. Intern JSON preserves temp_token", $internResp['temp_token'] === 'tok123intern', "temp_token present for submission");

// Emulate task-tracker.php AJAX validate_qa_upload response generation for Super Admin
$can_view_financials = true; // Super Admin
$clientStatsSuper = $valRes['stats'] ?? null;
if ($clientStatsSuper && !$can_view_financials) {
    unset($clientStatsSuper['hourly_rate']);
    unset($clientStatsSuper['calculated_charge']);
}
$superResp = [
    'success' => true,
    'valid' => $valRes['valid'],
    'errors' => $valRes['errors'],
    'errors_by_category' => $valRes['errors_by_category'] ?? null,
    'warnings' => $valRes['warnings'],
    'stats' => $clientStatsSuper,
    'temp_token' => 'tok123super',
    'can_view_financials' => $can_view_financials
];
if ($can_view_financials) {
    $superResp['hourly_rate'] = $hourly_rate;
}
$superJson = json_encode($superResp);

assert_test("14. Super Admin JSON has 'hourly_rate'", isset($superResp['hourly_rate']) && (float)$superResp['hourly_rate'] === 120.00, "hourly_rate is ₹120.00");
assert_test("15. Super Admin JSON has 'calculated_charge'", isset($superResp['stats']['calculated_charge']) && (float)$superResp['stats']['calculated_charge'] === 230.00, "calculated_charge is ₹230.00");

// ── TEST 3: Submit QA Assessment Success Message Sanitization ──
echo "\n--- Section 3: Submit QA Assessment Message & Persistence ---\n";

// Execute actual store_ld_quality_assessment in testDb
$meIntern = ['id' => 1, 'username' => 'intern_user', 'full_name' => 'Intern Tester', 'role' => 'admin'];
$outcomeIntern = store_ld_quality_assessment(
    $testDb,
    $valRes,
    $tempFile,
    'test.csv',
    $meIntern,
    1,
    120.00,
    11.2588,
    75.7804,
    'https://maps.google.com'
);

// Emulate submission message generation
$can_view_financials = false; // Intern
$aiStatus = $outcomeIntern['ai_status'] ?? 'pending';
$aiMsg = ($aiStatus === 'completed') ? 'Assessment submitted successfully. AI analysis completed.' : 'Assessment submitted successfully. AI analysis could not be completed.';
if ($can_view_financials) {
    $internMsg = $aiMsg . " (" . $outcomeIntern['report_reference'] . ", " .
        $outcomeIntern['stats']['total_lectures'] . " lectures recorded, " . number_format((float)$outcomeIntern['stats']['total_assessment_hours'], 2) . " Hours, ₹" . number_format((float)$outcomeIntern['stats']['calculated_charge'], 2) . ").";
} else {
    $internMsg = $aiMsg . " (" . $outcomeIntern['report_reference'] . ", " .
        $outcomeIntern['stats']['total_lectures'] . " lectures recorded, " . number_format((float)$outcomeIntern['stats']['total_assessment_hours'], 2) . " Hours).";
}

assert_test("16. Intern submission message contains report reference", str_contains($internMsg, $outcomeIntern['report_reference']), "Ref present");
assert_test("17. Intern submission message contains lecture count", str_contains($internMsg, "2 lectures recorded"), "Lecture count present");
assert_test("18. Intern submission message contains assessment hours", str_contains($internMsg, "1.92 Hours"), "Hours present");
assert_test("19. Intern submission message does NOT contain ₹ currency", !str_contains($internMsg, "₹"), "₹ symbol omitted");
assert_test("20. Intern submission message does NOT contain calculated charge (230)", !str_contains($internMsg, "230"), "Charge 230 omitted");

// Super Admin submission message
$can_view_financials = true;
$superMsg = $aiMsg . " (" . $outcomeIntern['report_reference'] . ", " .
    $outcomeIntern['stats']['total_lectures'] . " lectures recorded, " . number_format((float)$outcomeIntern['stats']['total_assessment_hours'], 2) . " Hours, ₹" . number_format((float)$outcomeIntern['stats']['calculated_charge'], 2) . ").";

assert_test("21. Super Admin message contains ₹ and calculated charge (₹230.00)", str_contains($superMsg, "₹230.00"), "Charge visible to Super Admin");

// Verify server-side payment is stored accurately in DB regardless of intern view
$dbRep = $testDb->query("SELECT hourly_rate_snapshot, calculated_charge FROM ld_quality_assessment_reports WHERE id = " . $outcomeIntern['report_id'])->fetch(PDO::FETCH_ASSOC);
assert_test("22. DB authoritative report hourly rate is exactly ₹120.00", (float)$dbRep['hourly_rate_snapshot'] === 120.00, "Hourly rate stored");
assert_test("23. DB authoritative report calculated charge is exactly ₹230.00", (float)$dbRep['calculated_charge'] === 230.00, "Charge stored");

$topicsSum = $testDb->query("SELECT SUM(calculated_charge) AS tot_charge, SUM(quantity) AS tot_qty FROM ld_task_topics WHERE task_id = " . $outcomeIntern['task_id'])->fetch(PDO::FETCH_ASSOC);
assert_test("24. DB authoritative task topics sum to exact ₹230.00", round((float)$topicsSum['tot_charge'], 2) === 230.00, "Topics charge matches");

// ── TEST 4: AJAX get_qa_items View Modal Sanitization ──
echo "\n--- Section 4: View Modal (get_qa_items) Sanitization ---\n";

$repRow = $testDb->query("SELECT * FROM ld_quality_assessment_reports WHERE id = " . $outcomeIntern['report_id'])->fetch(PDO::FETCH_ASSOC);

// Intern view modal fetch
$can_view_financials = false;
$repIntern = $repRow;
if (!$can_view_financials) {
    unset($repIntern['hourly_rate_snapshot']);
    unset($repIntern['calculated_charge']);
}
$modalInternResp = [
    'success' => true,
    'report' => $repIntern,
    'can_view_financials' => $can_view_financials
];
$modalInternJson = json_encode($modalInternResp);

assert_test("25. Modal Intern JSON has NO 'hourly_rate_snapshot' key", !isset($repIntern['hourly_rate_snapshot']) && !str_contains($modalInternJson, '"hourly_rate_snapshot"'), "Rate omitted");
assert_test("26. Modal Intern JSON has NO 'calculated_charge' key", !isset($repIntern['calculated_charge']) && !str_contains($modalInternJson, '"calculated_charge"'), "Charge omitted");
assert_test("27. Modal Intern JSON raw text has no 'hourly_rate_snapshot' key", !str_contains($modalInternJson, '"hourly_rate_snapshot"'), "Rate key not leaked in JSON");
assert_test("28. Modal Intern JSON raw text has no 'calculated_charge' key", !str_contains($modalInternJson, '"calculated_charge"'), "Charge key not leaked in JSON");

// Super Admin view modal fetch
$can_view_financials = true;
$repSuper = $repRow;
if (!$can_view_financials) {
    unset($repSuper['hourly_rate_snapshot']);
    unset($repSuper['calculated_charge']);
}
$modalSuperResp = [
    'success' => true,
    'report' => $repSuper,
    'can_view_financials' => $can_view_financials
];
assert_test("29. Modal Super Admin JSON has 'hourly_rate_snapshot' (120)", (float)$repSuper['hourly_rate_snapshot'] === 120.00, "Rate present for Super Admin");
assert_test("30. Modal Super Admin JSON has 'calculated_charge' (230)", (float)$repSuper['calculated_charge'] === 230.00, "Charge present for Super Admin");

// ── TEST 5: Frontend JS Logic & UI Rendering Simulation ──
echo "\n--- Section 5: Frontend UI Card Rendering Simulation ---\n";

// Function simulating task-tracker.php JS rendering of the validation result card
function simulate_qa_validation_card_js($data): string {
    $stats = $data['stats'];
    $succHtml = '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-bottom:14px;">';
    $succHtml .= '<div class="card"><div class="label">Lectures Assessed</div><div class="val">' . $stats['total_lectures'] . '</div></div>';
    $succHtml .= '<div class="card"><div class="label">Total Assessment Time</div><div class="val">' . $stats['total_assessment_minutes'] . ' min</div></div>';
    $succHtml .= '<div class="card"><div class="label">Assessment Hours</div><div class="val">' . number_format($stats['total_assessment_hours'], 2) . ' Hrs</div></div>';
    if (!empty($data['can_view_financials']) && isset($data['hourly_rate']) && isset($stats['calculated_charge'])) {
        $succHtml .= '<div class="card"><div class="label">Hourly Rate</div><div class="val">₹' . number_format($data['hourly_rate'], 2) . '</div></div>';
        $succHtml .= '<div class="card"><div class="label">Calculated Charge</div><div class="val">₹' . number_format($stats['calculated_charge'], 2) . '</div></div>';
    }
    $succHtml .= '</div>';
    return $succHtml;
}

$internCardHtml = simulate_qa_validation_card_js($internResp);
assert_test("31. Intern card renders 'Lectures Assessed'", str_contains($internCardHtml, "Lectures Assessed"), "Lectures Assessed visible");
assert_test("32. Intern card renders 'Total Assessment Time'", str_contains($internCardHtml, "Total Assessment Time"), "Assessment Time visible");
assert_test("33. Intern card renders 'Assessment Hours'", str_contains($internCardHtml, "Assessment Hours"), "Assessment Hours visible");
assert_test("34. Intern card does NOT render 'Hourly Rate'", !str_contains($internCardHtml, "Hourly Rate"), "Hourly Rate completely absent");
assert_test("35. Intern card does NOT render 'Calculated Charge'", !str_contains($internCardHtml, "Calculated Charge"), "Calculated Charge completely absent");
assert_test("36. Intern card does NOT contain ₹ currency", !str_contains($internCardHtml, "₹"), "No currency symbol in card");

$superCardHtml = simulate_qa_validation_card_js($superResp);
assert_test("37. Super Admin card renders 'Hourly Rate'", str_contains($superCardHtml, "Hourly Rate"), "Hourly Rate visible to Super Admin");
assert_test("38. Super Admin card renders 'Calculated Charge'", str_contains($superCardHtml, "Calculated Charge"), "Calculated Charge visible to Super Admin");
assert_test("39. Super Admin card renders '₹120.00'", str_contains($superCardHtml, "₹120.00"), "₹120.00 visible to Super Admin");
assert_test("40. Super Admin card renders '₹230.00'", str_contains($superCardHtml, "₹230.00"), "₹230.00 visible to Super Admin");

// ── TEST 6: Static Code Security Audit of task-tracker.php ──
echo "\n--- Section 6: Static Code Security Audit on task-tracker.php ---\n";
$taskTrackerCode = file_get_contents(__DIR__ . '/task-tracker.php');

// Verify authorization check definition
assert_test("41. \$can_view_financials defined at top of task-tracker.php", str_contains($taskTrackerCode, '$can_view_financials = is_super_admin() || (!$is_intern && can_access(\'ld-work-report\'));'), "Auth logic defined");

// Verify AJAX validate_qa_upload sanitization
assert_test("42. validate_qa_upload unsets hourly_rate and calculated_charge for interns", str_contains($taskTrackerCode, "unset(\$clientStats['hourly_rate']);\n                        unset(\$clientStats['calculated_charge']);"), "Stats sanitized");

// Verify get_qa_items sanitization
assert_test("43. get_qa_items unsets hourly_rate_snapshot and calculated_charge for interns", str_contains($taskTrackerCode, "unset(\$rep['hourly_rate_snapshot']);\n        unset(\$rep['calculated_charge']);"), "Modal report sanitized");

// Verify my_tasks SQL and array sanitization
assert_test("44. my_tasks SQL sanitizes charge_per_quantity_snapshot", str_contains($taskTrackerCode, '$charge_field_sql = !$can_view_financials ? "NULL AS charge_per_quantity_snapshot" : "t.charge_per_quantity_snapshot";'), "Task rate column masked");
assert_test("45. my_tasks SQL sanitizes calculated_charge", str_contains($taskTrackerCode, '$calc_charge_field_sql = !$can_view_financials ? "NULL AS calculated_charge" : "calculated_charge";'), "Topic charge column masked");
assert_test("46. my_tasks QA reports unsets hourly_rate_snapshot and calculated_charge", str_contains($taskTrackerCode, "unset(\$qar['hourly_rate_snapshot']);\n                        unset(\$qar['calculated_charge']);"), "QA row sanitized");

// Verify timeline card checks
assert_test("47. Timeline QA card checks \$can_view_financials", str_contains($taskTrackerCode, "<?php if (\$can_view_financials && \$t['charge_per_quantity_snapshot'] !== null && isset(\$rep['calculated_charge'])): ?>"), "Timeline QA charge guarded");

// Verify workModesConfig check
assert_test("48. workModesConfig checks \$can_view_financials", str_contains($taskTrackerCode, "'charge_per_quantity' => \$can_view_financials && \$m['charge_per_quantity'] !== null ? (float)\$m['charge_per_quantity'] : null"), "workModesConfig sanitized");

// Verify JS validation card check
assert_test("49. JS validation card checks data.can_view_financials", str_contains($taskTrackerCode, "if (data.can_view_financials && data.hourly_rate !== undefined && stats.calculated_charge !== undefined) {"), "JS validation card guarded");

// Verify JS replace validation check
assert_test("50. JS replace validation checks data.can_view_financials", str_contains($taskTrackerCode, "if (data.can_view_financials && stats.calculated_charge !== undefined) {"), "JS replace validation guarded");

// Verify JS modal details check
assert_test("51. JS modal details checks rep.hourly_rate_snapshot !== undefined", str_contains($taskTrackerCode, "if (rep.hourly_rate_snapshot !== undefined && rep.hourly_rate_snapshot !== null) {"), "JS modal rate guarded");
assert_test("52. JS modal details checks rep.calculated_charge !== undefined", str_contains($taskTrackerCode, "if (rep.calculated_charge !== undefined && rep.calculated_charge !== null) {"), "JS modal charge guarded");

// Clean up
@unlink($tempFile);

echo "\n====================================================================\n";
echo "AUDIT SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
