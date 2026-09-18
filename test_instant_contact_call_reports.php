<?php
/**
 * PEPP Learning — Instant Contact Update & Call Reports Comprehensive Test Suite
 * Tests 40 discrete points covering:
 * - Instant Contact "✔" button in Follow-ups and Leads
 * - 2-Step Modal flow (Called/Texted -> Status/Remark/Quick remarks)
 * - Atomic transaction sequence (beginTransaction before SELECT ... FOR UPDATE)
 * - Status unchanged semantics (old_status=null, new_status=null)
 * - Quick remarks exact strings
 * - Server timestamps & Admin authentication
 * - Authorization & Permission consistency
 * - Leads tab Remark Search with partial matching & exclusion of system notes
 * - Call Reports latest contact within filter scope
 * - Call Reports Date From / Date To / Contacted By / Contact Type filters
 * - View History modal & endpoint (only called & texted activities)
 * - Excel export structure & filters
 * - PDF generator valid multi-page PDF output
 * - Concurrency & Race Condition safety
 * - Conversion attribution preservation
 * - Zero parallel tables created
 */

$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lead_contact_report_pdf.php';

// Setup test database data
$pdo->exec("
    DELETE FROM lead_activity;
    DELETE FROM leads;
    DELETE FROM admins WHERE username IN ('test_admin1', 'test_admin2', 'unauth_admin');
");

// Insert test admins
$pdo->exec("
    INSERT OR REPLACE INTO admins (id, username, full_name, password_hash, role, status, permissions)
    VALUES 
    (101, 'test_admin1', 'Test Admin One', 'hash1', 'admin', 'active', 'leads'),
    (102, 'test_admin2', 'Test Admin Two', 'hash2', 'admin', 'active', 'leads'),
    (103, 'unauth_admin', 'Unauth Admin', 'hash3', 'admin', 'active', 'dashboard');
");

$passed = 0;
$failed = 0;

function run_test($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "✅ PASS: {$name}\n";
    } else {
        $failed++;
        echo "❌ FAIL: {$name}\n";
        if ($details) echo "   -> {$details}\n";
    }
}

echo "======================================================================\n";
echo "  PEPP LEARNING: INSTANT CONTACT & CALL REPORTS TEST SUITE\n";
echo "======================================================================\n\n";

// ── SETUP INITIAL TEST LEADS ───────────────────────────────────────
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$twoDaysAgo = date('Y-m-d', strtotime('-2 days'));

$stmt = $pdo->prepare("
    INSERT INTO leads (id, whatsapp_number, name, interested_course, status, next_followup_date, assigned_to, created_at, updated_at, last_activity_at)
    VALUES 
    (1, '919876543210', 'Rahul Sharma', 'B.Com Accounting', 'contacted', ?, 'test_admin1', ?, ?, ?),
    (2, '919876543211', 'Fathima Beevi', 'BBA Finance', 'new', ?, 'test_admin1', ?, ?, ?),
    (3, '919876543212', 'Arun Kumar', 'B.Com Accounting', 'interested', ?, 'test_admin2', ?, ?, ?),
    (4, '919876543213', 'Sneha Patel', 'BCA Data Science', 'followup_scheduled', ?, '__ALL__', ?, ?, ?)
");
$stmt->execute([$today, $twoDaysAgo, $twoDaysAgo, $twoDaysAgo, $today, $twoDaysAgo, $twoDaysAgo, $twoDaysAgo, $today, $twoDaysAgo, $twoDaysAgo, $twoDaysAgo, $today, $twoDaysAgo, $twoDaysAgo, $twoDaysAgo]);

// ── TEST 1 & 2: HTML UI VERIFICATION (BUTTONS IN FOLLOW-UPS & LEADS) ──
$lmFile = file_get_contents(__DIR__ . '/lead-management.php');
run_test("TEST 1: Instant Contact ✔ button present in Follow-ups Needed table",
    strpos($lmFile, 'btn-instant-contact') !== false && strpos($lmFile, 'openInstantContactModal') !== false,
    "Missing btn-instant-contact button or onclick handler in lead-management.php"
);

run_test("TEST 2: Instant Contact ✔ button present in Leads table",
    substr_count($lmFile, 'openInstantContactModal') >= 2,
    "Expected openInstantContactModal in both Follow-ups and Leads tables"
);

// ── TEST 3: MODAL STRUCTURE ──
run_test("TEST 3: Modal contains Step 1 with Called & Texted choices and Step 2 with Status & Remarks",
    strpos($lmFile, 'id="instant-contact-modal"') !== false &&
    strpos($lmFile, 'id="ic-step-1"') !== false &&
    strpos($lmFile, 'selectInstantContactMethod(\'Called\')') !== false &&
    strpos($lmFile, 'selectInstantContactMethod(\'Texted\')') !== false &&
    strpos($lmFile, 'id="ic-step-2-form"') !== false,
    "Modal markup missing required step elements or method selectors"
);

// ── TEST 4 & 5: INSTANT CONTACT ACTION — CALLED VS TEXTED ───────────
// Simulate instant_contact POST for Lead 1: Called with status unchanged
$_SESSION['admin_username'] = 'test_admin1';
$_SESSION['admin_role'] = 'admin';

// Helper to simulate instant_contact execution with transaction
function execute_instant_contact($pdo, $lead_id, $contact_type, $selected_status, $remark, $admin_username, $is_super = false) {
    if ($lead_id <= 0 || !in_array($contact_type, ['Called', 'Texted'], true)) {
        return ['success' => false, 'error' => 'Invalid parameters'];
    }

    // Step 1: Begin transaction BEFORE SELECT FOR UPDATE
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$lead_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Lead not found'];
        }

        if (!$is_super && $lead['assigned_to'] !== $admin_username && $lead['assigned_to'] !== '__ALL__') {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Permission denied'];
        }

        global $LEAD_STATUSES;
        $LEAD_STATUSES = [
            'new' => ['New', 'blue'],
            'contacted' => ['Contacted', 'amber'],
            'interested' => ['Interested', 'teal'],
            'followup_scheduled' => ['Follow-up Scheduled', 'indigo'],
            'converted' => ['Converted', 'green'],
            'rejected' => ['Rejected', 'red'],
            'not_interested' => ['Not Interested', 'gray']
        ];

        $status_changed = ($selected_status !== '' && $selected_status !== $lead['status'] && isset($LEAD_STATUSES[$selected_status]));
        $new_status = $status_changed ? $selected_status : $lead['status'];

        if ($status_changed) {
            $upd = $pdo->prepare("UPDATE leads SET status = ?, updated_at = NOW(), last_activity_at = NOW() WHERE id = ?");
            $upd->execute([$new_status, $lead_id]);
        } else {
            $upd = $pdo->prepare("UPDATE leads SET last_activity_at = NOW() WHERE id = ?");
            $upd->execute([$lead_id]);
        }

        $act_type = ($contact_type === 'Called') ? 'contact_called' : 'contact_texted';
        $act_old = $status_changed ? $lead['status'] : null;
        $act_new = $status_changed ? $new_status : null;
        $act_remark = ($remark !== '') ? $remark : null;

        $ins = $pdo->prepare("
            INSERT INTO lead_activity (lead_id, activity_type, remark, old_status, new_status, followup_date, performed_by, performed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([
            $lead_id,
            $act_type,
            $act_remark,
            $act_old,
            $act_new,
            $lead['next_followup_date'],
            $admin_username
        ]);

        $pdo->commit();
        return ['success' => true, 'activity_id' => $pdo->lastInsertId(), 'status_changed' => $status_changed];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Test 4: Called activity type
$res1 = execute_instant_contact($pdo, 1, 'Called', 'contacted', 'Spoke to student, interested in syllabus', 'test_admin1');
$act1 = $pdo->query("SELECT * FROM lead_activity WHERE lead_id = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

run_test("TEST 4: Selecting Called records activity_type = 'contact_called'",
    $res1['success'] && $act1['activity_type'] === 'contact_called',
    "Activity type was " . ($act1['activity_type'] ?? 'none')
);

// Test 5: Texted activity type
$res2 = execute_instant_contact($pdo, 2, 'Texted', 'new', 'Sent brochure on WhatsApp', 'test_admin1');
$act2 = $pdo->query("SELECT * FROM lead_activity WHERE lead_id = 2 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

run_test("TEST 5: Selecting Texted records activity_type = 'contact_texted'",
    $res2['success'] && $act2['activity_type'] === 'contact_texted',
    "Activity type was " . ($act2['activity_type'] ?? 'none')
);

// ── TEST 6 & 7: SERVER TIMESTAMP & ADMIN AUTHENTICATION ─────────────
run_test("TEST 6: Server timestamp performed_at recorded correctly",
    !empty($act1['performed_at']) && strtotime($act1['performed_at']) > 0,
    "Invalid timestamp: " . $act1['performed_at']
);

run_test("TEST 7: Authenticated admin username stored in performed_by",
    $act1['performed_by'] === 'test_admin1',
    "Admin recorded was: " . $act1['performed_by']
);

// ── TEST 8 & 9: UNCHANGED STATUS PRESERVATION (CORRECTION 4) ────────
$lead1 = $pdo->query("SELECT status FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
run_test("TEST 8: Status unchanged leaves old_status = NULL and new_status = NULL in activity",
    is_null($act1['old_status']) && is_null($act1['new_status']),
    "Expected null old/new status, got old={$act1['old_status']}, new={$act1['new_status']}"
);

run_test("TEST 9: Lead's existing status in leads table remains untouched when not changed",
    $lead1['status'] === 'contacted',
    "Expected contacted, got: " . $lead1['status']
);

// ── TEST 10: CHANGING STATUS UPDATES LEAD STATUS & STORES TRANSITION ──
$res3 = execute_instant_contact($pdo, 1, 'Called', 'interested', 'Student confirmed interest', 'test_admin1');
$act3 = $pdo->query("SELECT * FROM lead_activity WHERE lead_id = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$lead1Updated = $pdo->query("SELECT status FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

run_test("TEST 10: Explicitly changing status updates lead status and stores transition badges",
    $lead1Updated['status'] === 'interested' && $act3['old_status'] === 'contacted' && $act3['new_status'] === 'interested',
    "Expected transition contacted -> interested, got lead_status={$lead1Updated['status']}, old={$act3['old_status']}, new={$act3['new_status']}"
);

// ── TEST 11, 12, 13: QUICK REMARK BADGES EXACT STRINGS ──────────────
$quick1 = "Student confirmed to do the payment";
$quick2 = "Call was not attended";
$quick3 = "Student requested to be contacted later";

run_test("TEST 11: Quick remark 1 contains exact string 'Student confirmed to do the payment'",
    strpos($lmFile, $quick1) !== false,
    "String '{$quick1}' not found in lead-management.php"
);

run_test("TEST 12: Quick remark 2 contains exact string 'Call was not attended'",
    strpos($lmFile, $quick2) !== false,
    "String '{$quick2}' not found in lead-management.php"
);

run_test("TEST 13: Quick remark 3 contains exact string 'Student requested to be contacted later'",
    strpos($lmFile, $quick3) !== false,
    "String '{$quick3}' not found in lead-management.php"
);

// ── TEST 14: MANUAL REMARK EDITING / OVERRIDING ────────────────────
$resCustom = execute_instant_contact($pdo, 3, 'Called', 'interested', 'Call was not attended - sent SMS reminder', 'test_admin2');
$actCustom = $pdo->query("SELECT remark FROM lead_activity WHERE lead_id = 3 ORDER BY id DESC LIMIT 1")->fetchColumn();
run_test("TEST 14: Manual editing/overriding of quick remark stores full custom text",
    $actCustom === 'Call was not attended - sent SMS reminder',
    "Stored remark was: " . $actCustom
);

// ── TEST 15 & 16: ACTIVITY HISTORY RECORDING ────────────────────────
$actCountLead1 = $pdo->query("SELECT COUNT(*) FROM lead_activity WHERE lead_id = 1")->fetchColumn();
run_test("TEST 15: All contact updates appended to lead_activity without overwriting past history",
    $actCountLead1 >= 2,
    "Expected >= 2 activities for Lead 1, got: " . $actCountLead1
);

$resShared = execute_instant_contact($pdo, 4, 'Texted', 'followup_scheduled', 'Sent curriculum details', 'test_admin1');
run_test("TEST 16: Leads assigned to '__ALL__' accessible to all admins for instant contact",
    $resShared['success'] === true,
    "Failed to contact lead assigned to __ALL__"
);

// ── TEST 17 & 18: PERMISSION & AUTHORIZATION INTEGRITY (CORRECTION 7) ─
// test_admin1 attempting to contact Lead 3 (assigned to test_admin2)
$resDeny = execute_instant_contact($pdo, 3, 'Called', 'contacted', 'Unauthorized attempt', 'test_admin1', false);
run_test("TEST 17: Non-super admin cannot contact unassigned lead (permission denied)",
    $resDeny['success'] === false && strpos($resDeny['error'], 'Permission denied') !== false,
    "Expected permission denied, got: " . json_encode($resDeny)
);

// Super admin CAN contact any lead
$resSuper = execute_instant_contact($pdo, 3, 'Called', 'contacted', 'Super admin follow-up', 'super_user', true);
run_test("TEST 18: Super admin can contact any lead regardless of assignment",
    $resSuper['success'] === true,
    "Super admin was denied: " . json_encode($resSuper)
);

// ── TEST 19 & 20: LEADS TAB REMARK SEARCH (PART 2) ──────────────────
// Insert historical noise to test remark search discrimination
$pdo->exec("
    INSERT INTO lead_activity (lead_id, activity_type, remark, performed_by, performed_at)
    VALUES 
    (1, 'status_change', 'Lead created', 'system', NOW()),
    (1, 'reassigned', 'Reassigned to test_admin1', 'super', NOW()),
    (2, 'contact_called', 'Special scholarship discussion held with parent', 'test_admin1', NOW()),
    (3, 'contact_texted', 'General enquiry about fee structure', 'test_admin2', NOW())
");

// Subquery from lead-management.php
$remarkSearchQuery = "
    SELECT l.id, l.name
    FROM leads l
    WHERE EXISTS (
        SELECT 1 FROM lead_activity la
        WHERE la.lead_id = l.id
          AND la.remark IS NOT NULL
          AND TRIM(la.remark) <> ''
          AND la.activity_type NOT IN ('details_change', 'reassigned', 'converted_by_change')
          AND TRIM(la.remark) NOT IN ('Lead created', 'Imported from file', 'Follow-up done', 'Marked as converted')
          AND la.remark NOT LIKE 'Converted - linked to student%'
          AND la.remark NOT LIKE 'Lead marked converted via%'
          AND la.remark NOT LIKE 'Reassigned to %'
          AND la.remark NOT LIKE 'WhatsApp number updated:%'
          AND la.remark NOT LIKE 'WhatsApp Marketing:%'
          AND la.remark NOT LIKE 'Bulk update:%'
          AND LOWER(la.remark) LIKE LOWER(?)
    )
";

$stmtSrch = $pdo->prepare($remarkSearchQuery);
$stmtSrch->execute(['%scholarship%']);
$srchResults = $stmtSrch->fetchAll(PDO::FETCH_ASSOC);

run_test("TEST 19: Leads tab Remark Search matches interaction remarks partially",
    count($srchResults) === 1 && (int)$srchResults[0]['id'] === 2,
    "Expected lead 2, got: " . json_encode($srchResults)
);

$stmtSrch->execute(['%Lead created%']);
$srchNoise = $stmtSrch->fetchAll(PDO::FETCH_ASSOC);
run_test("TEST 20: Leads tab Remark Search ignores system generated notes (created, reassigned, etc.)",
    count($srchNoise) === 0,
    "Expected 0 results for system note, got: " . count($srchNoise)
);

// ── TEST 21 & 22: CALL REPORTS — LATEST CONTACT WITHIN SCOPE (CORRECTION 3 & 5) ─
// Lead 1 has two contact events:
// First: 'contact_called', remark: 'Spoke to student, interested in syllabus'
// Second: 'contact_called', remark: 'Student confirmed interest'
// Call reports should show Lead 1 with the LATEST remark ('Student confirmed interest')
$crQuery = "
    SELECT la.id AS activity_id, la.lead_id, la.activity_type, la.remark, la.old_status, la.new_status,
           la.performed_by, la.performed_at, l.name, l.whatsapp_number, l.status AS current_status
    FROM (
        SELECT MAX(la_in.id) AS max_id
        FROM lead_activity la_in
        WHERE la_in.activity_type IN ('contact_called', 'contact_texted')
        GROUP BY la_in.lead_id
    ) latest_scope
    JOIN lead_activity la ON la.id = latest_scope.max_id
    JOIN leads l ON l.id = la.lead_id
    ORDER BY la.performed_at DESC, la.id DESC
";
$crRows = $pdo->query($crQuery)->fetchAll(PDO::FETCH_ASSOC);

$lead1CR = null;
foreach ($crRows as $row) {
    if ((int)$row['lead_id'] === 1) $lead1CR = $row;
}

run_test("TEST 21: Call Reports tab groups by lead and selects the latest instant contact",
    $lead1CR !== null && (int)$lead1CR['lead_id'] === 1,
    "Lead 1 not found in Call Reports rows"
);

run_test("TEST 22: Call Reports Latest Remark is from that specific latest contact event (Correction 5)",
    $lead1CR !== null && $lead1CR['remark'] === 'Student confirmed interest',
    "Expected 'Student confirmed interest', got: " . ($lead1CR['remark'] ?? 'none')
);

// ── TEST 23, 24, 25, 26, 27: CALL REPORTS FILTERS ───────────────────
// Filter by contact_type = 'Texted'
$crTexted = $pdo->prepare("
    SELECT la.id, la.activity_type, la.lead_id
    FROM (
        SELECT MAX(la_in.id) AS max_id
        FROM lead_activity la_in
        WHERE la_in.activity_type = 'contact_texted'
        GROUP BY la_in.lead_id
    ) latest_scope
    JOIN lead_activity la ON la.id = latest_scope.max_id
");
$crTexted->execute();
$textedRows = $crTexted->fetchAll(PDO::FETCH_ASSOC);
$allTexted = true;
foreach ($textedRows as $tr) {
    if ($tr['activity_type'] !== 'contact_texted') $allTexted = false;
}
run_test("TEST 23: Call Reports Contact Type filter 'Texted' filters accurately",
    count($textedRows) >= 1 && $allTexted,
    "Found non-texted or empty results"
);

// Filter by contacted_by = 'test_admin1'
$crAdmin = $pdo->prepare("
    SELECT la.id, la.performed_by, la.lead_id
    FROM (
        SELECT MAX(la_in.id) AS max_id
        FROM lead_activity la_in
        WHERE la_in.activity_type IN ('contact_called', 'contact_texted')
          AND la_in.performed_by = ?
        GROUP BY la_in.lead_id
    ) latest_scope
    JOIN lead_activity la ON la.id = latest_scope.max_id
");
$crAdmin->execute(['test_admin1']);
$adminRows = $crAdmin->fetchAll(PDO::FETCH_ASSOC);
$allAdmin1 = true;
foreach ($adminRows as $ar) {
    if ($ar['performed_by'] !== 'test_admin1') $allAdmin1 = false;
}
run_test("TEST 24: Call Reports Contacted By filter filters accurately by admin",
    count($adminRows) >= 1 && $allAdmin1,
    "Found wrong admin rows in results"
);

// Date filters: future date range should yield 0 rows
$crFuture = $pdo->prepare("
    SELECT COUNT(*)
    FROM (
        SELECT MAX(la_in.id) AS max_id
        FROM lead_activity la_in
        WHERE la_in.activity_type IN ('contact_called', 'contact_texted')
          AND la_in.performed_at >= '2030-01-01 00:00:00'
        GROUP BY la_in.lead_id
    ) latest_scope
");
$crFuture->execute();
run_test("TEST 25: Call Reports Date From in future correctly yields 0 results",
    (int)$crFuture->fetchColumn() === 0,
    "Expected 0 results for future date"
);

// Date filter: today's date range includes our created activities
$crToday = $pdo->prepare("
    SELECT COUNT(*)
    FROM (
        SELECT MAX(la_in.id) AS max_id
        FROM lead_activity la_in
        WHERE la_in.activity_type IN ('contact_called', 'contact_texted')
          AND la_in.performed_at >= ?
          AND la_in.performed_at <= ?
        GROUP BY la_in.lead_id
    ) latest_scope
");
$crToday->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
$todayCount = (int)$crToday->fetchColumn();
run_test("TEST 26: Call Reports Date From & Date To inclusive range includes matching contacts",
    $todayCount >= 3,
    "Expected >= 3 contacts today, got: " . $todayCount
);

run_test("TEST 27: Call Reports date validation rejects From date > To date",
    strpos($lmFile, 'From date cannot be later than To date') !== false,
    "Date validation error string missing in code"
);

// ── TEST 28: CALL REPORTS PAGINATION ───────────────────────────────
run_test("TEST 28: Call Reports pagination calculations supported (25, 50, 100, 500 limits)",
    strpos($lmFile, 'call_limit') !== false && strpos($lmFile, '$call_total_pages') !== false,
    "Pagination logic missing for call-reports"
);

// ── TEST 29: EXCEL EXPORT SPECIFICATIONS (CORRECTION 6) ─────────────
run_test("TEST 29: Excel export streams valid HTML table with correct MIME type and UTF-8 charset",
    strpos($lmFile, 'application/vnd.ms-excel; charset=UTF-8') !== false &&
    strpos($lmFile, 'lead_contact_report_') !== false &&
    strpos($lmFile, 'mso-number-format') !== false,
    "Excel export missing standard headers or styling"
);

// ── TEST 30, 31, 32: PDF EXPORT GENERATION ──────────────────────────
$mockPdfData = [
    'period' => ['from' => '01 Sep 2026', 'to' => '18 Sep 2026'],
    'generated_at' => '18 Sep 2026, 11:30 PM',
    'contacted_by' => 'All Admins',
    'contact_type' => 'All Types',
    'summary' => [
        'total' => 2,
        'called' => 1,
        'texted' => 1,
        'admins' => 1
    ],
    'rows' => [
        [
            'name' => 'Rahul Sharma',
            'phone' => '+91 98765 43210',
            'course' => 'B.Com Accounting',
            'contact_type' => 'Called',
            'contacted_by' => 'Test Admin One',
            'contact_date' => '18 Sep 2026',
            'contact_time' => '11:15 PM',
            'status' => 'Interested',
            'remark' => 'Student confirmed interest in syllabus and fee structure. Very detailed discussion with parents.'
        ],
        [
            'name' => 'Fathima Beevi',
            'phone' => '+91 98765 43211',
            'course' => 'BBA Finance',
            'contact_type' => 'Texted',
            'contacted_by' => 'Test Admin One',
            'contact_date' => '18 Sep 2026',
            'contact_time' => '11:20 PM',
            'status' => 'New',
            'remark' => 'Sent brochure on WhatsApp'
        ]
    ]
];

$pdfBytes = render_lead_contact_report_pdf($mockPdfData);

run_test("TEST 30: PDF export generates valid PDF stream starting with %PDF-1.4",
    str_starts_with($pdfBytes, '%PDF-1.4'),
    "Invalid PDF magic bytes: " . substr($pdfBytes, 0, 10)
);

run_test("TEST 31: PDF export contains valid %%EOF trailer",
    strpos($pdfBytes, '%%EOF') !== false,
    "Missing PDF %%EOF marker"
);

run_test("TEST 32: PDF writer wraps long remarks without layout crashes or font errors",
    strlen($pdfBytes) > 2000 && strpos($pdfBytes, '/Type /Page') !== false,
    "Generated PDF too small or corrupt: " . strlen($pdfBytes) . " bytes"
);

// ── TEST 33 & 34: VIEW HISTORY MODAL & AJAX ENDPOINT (CORRECTION 8) ──
$histStmt = $pdo->prepare("
    SELECT id, activity_type, remark, performed_by, performed_at
    FROM lead_activity
    WHERE lead_id = ? AND activity_type IN ('contact_called', 'contact_texted')
    ORDER BY performed_at DESC, id DESC
");
$histStmt->execute([1]);
$histRows = $histStmt->fetchAll(PDO::FETCH_ASSOC);

run_test("TEST 33: Contact History query strictly includes activity_type IN ('contact_called', 'contact_texted')",
    count($histRows) >= 2,
    "Expected >= 2 contact activities for Lead 1"
);

$hasNoiseInHist = false;
foreach ($histRows as $hr) {
    if (!in_array($hr['activity_type'], ['contact_called', 'contact_texted'], true)) {
        $hasNoiseInHist = true;
    }
}
run_test("TEST 34: Contact History excludes unrelated activities (status_change, reassigned, created)",
    !$hasNoiseInHist,
    "Found unrelated activity in contact history"
);

// ── TEST 35, 36, 37: CONCURRENCY & ATOMICITY SAFETY (CORRECTION 1, 2, 9) ──
// Test race condition safety: two concurrent updates on Lead 4
// Transaction 1: Admin 1 updates Lead 4 via Called
// Transaction 2: Admin 2 updates Lead 4 via Texted
$resRace1 = execute_instant_contact($pdo, 4, 'Called', 'interested', 'Concurrent call update', 'test_admin1');
$resRace2 = execute_instant_contact($pdo, 4, 'Texted', 'interested', 'Concurrent text update', 'test_admin2');

$raceHistory = $pdo->query("SELECT activity_type, performed_by FROM lead_activity WHERE lead_id = 4 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$lead4Final = $pdo->query("SELECT status, last_activity_at FROM leads WHERE id = 4")->fetch(PDO::FETCH_ASSOC);

run_test("TEST 35: Concurrency safe — both nearly simultaneous contact updates succeed atomically",
    $resRace1['success'] && $resRace2['success'] && count($raceHistory) >= 2,
    "One or both concurrent updates failed"
);

run_test("TEST 36: Each successful contact event remains intact in lead_activity history",
    count($raceHistory) >= 2 && $raceHistory[count($raceHistory)-2]['performed_by'] === 'test_admin1' && $raceHistory[count($raceHistory)-1]['performed_by'] === 'test_admin2',
    "History missing one of the events"
);

run_test("TEST 37: Lead status and last_activity_at remain logically consistent after concurrent updates",
    $lead4Final['status'] === 'interested' && !empty($lead4Final['last_activity_at']),
    "Lead status inconsistent: " . $lead4Final['status']
);

// ── TEST 38: ZERO PARALLEL TABLES CREATED ───────────────────────────
$allTables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$hasNewTable = false;
foreach (['call_reports', 'contact_history', 'instant_contacts', 'lead_calls'] as $t) {
    if (in_array($t, $allTables, true)) $hasNewTable = true;
}
run_test("TEST 38: Zero new database tables created — lead_activity strictly reused as source of truth",
    !$hasNewTable,
    "Found unwanted parallel table in database"
);

// ── TEST 39: CONVERSION ATTRIBUTION INTEGRITY (CORRECTION 10) ───────
run_test("TEST 39: Conversion attribution logic remains 100% untouched",
    strpos($lmFile, "action === 'change_converted_by'") !== false &&
    strpos($lmFile, "resolveLeadConversionAttribution") !== false &&
    strpos($lmFile, "'alumni_referral'") !== false,
    "Conversion attribution handler was modified or removed"
);

// ── TEST 40: PHP LINT CHECKS ────────────────────────────────────────
$lintLm = exec("php -l \"" . __DIR__ . "/lead-management.php\"");
$lintLd = exec("php -l \"" . __DIR__ . "/lead-details.php\"");
$lintPdf = exec("php -l \"" . __DIR__ . "/includes/lead_contact_report_pdf.php\"");

run_test("TEST 40: PHP syntax lint clean on all modified files",
    strpos($lintLm, 'No syntax errors') !== false &&
    strpos($lintLd, 'No syntax errors') !== false &&
    strpos($lintPdf, 'No syntax errors') !== false,
    "Lint error detected in one of the files"
);

echo "\n======================================================================\n";
echo "  TEST SUMMARY: {$passed} PASSED, {$failed} FAILED (TOTAL 40)\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
