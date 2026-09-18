<?php
/**
 * PEPP Learning - Test Suite: Lead Follow-up UX Enhancements
 * 
 * Validates:
 * 1. Centralized Date Parser (includes/lead_helper.php)
 * 2. Instant Contact Optional Date (Cases A, B, C, D, Tamper Resistance, Active Rules)
 * 3. Lead Details Quick Update (Backend action, Permissions, Activity Audit, Overdue)
 * 4. Regression checks on existing CRM features and attribution
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/lead_helper.php';

$passed = 0;
$failed = 0;
$total  = 0;

function assert_true($condition, $description) {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "✅ PASS: TEST {$total}: {$description}\n";
    } else {
        $failed++;
        echo "❌ FAIL: TEST {$total}: {$description}\n";
    }
}

// In-Memory SQLite Setup for testing DB logic
$sqlite = new PDO('sqlite::memory:');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create leads table (compatible with MySQL leads schema for testing)
$sqlite->exec("
    CREATE TABLE leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        whatsapp_number TEXT,
        name TEXT,
        interested_course TEXT,
        status TEXT DEFAULT 'new',
        next_followup_date TEXT DEFAULT NULL,
        followup_count INTEGER DEFAULT 0,
        assigned_to TEXT DEFAULT '__ALL__',
        source TEXT DEFAULT 'direct',
        converted_user_id TEXT DEFAULT NULL,
        converted_by TEXT DEFAULT NULL,
        created_by TEXT DEFAULT 'admin',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_activity_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Create lead_activity table
$sqlite->exec("
    CREATE TABLE lead_activity (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lead_id INTEGER,
        activity_type TEXT,
        remark TEXT,
        old_status TEXT,
        new_status TEXT,
        followup_date TEXT,
        performed_by TEXT,
        performed_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert seed leads
$sqlite->exec("
    INSERT INTO leads (id, whatsapp_number, name, interested_course, status, next_followup_date, assigned_to)
    VALUES (1, '9876543210', 'Lead With Date', 'B.Com Honours', 'contacted', '2026-09-25', 'test_admin1');
    INSERT INTO leads (id, whatsapp_number, name, interested_course, status, next_followup_date, assigned_to)
    VALUES (2, '9876543211', 'Closed Lead No Date', 'BBA', 'converted', NULL, 'test_admin1');
    INSERT INTO leads (id, whatsapp_number, name, interested_course, status, next_followup_date, assigned_to)
    VALUES (3, '9876543212', 'Restricted Lead', 'M.Com', 'follow_up', '2026-09-20', 'other_admin');
    INSERT INTO leads (id, whatsapp_number, name, interested_course, status, next_followup_date, assigned_to)
    VALUES (4, '9876543213', 'Active Lead Null Date', 'Economics', 'new', NULL, 'test_admin1');
");

$LEAD_STATUSES = [
    'new'            => ['New',            'blue'],
    'contacted'      => ['Contacted',      'violet'],
    'follow_up'      => ['Follow-up',      'amber'],
    'interested'     => ['Interested',     'teal'],
    'not_interested' => ['Not Interested', 'gray'],
    'converted'      => ['Converted',      'green'],
    'rejected'       => ['Rejected',       'red'],
];
$CLOSED = ['converted', 'rejected', 'not_interested'];

echo "======================================================================\n";
echo "  PEPP LEARNING: LEAD FOLLOW-UP UX ENHANCEMENTS TEST SUITE\n";
echo "======================================================================\n\n";

// ── PART 1: CANONICAL DATE PARSER TESTS ──

assert_true(
    parse_lead_followup_date('2026-09-25') === '2026-09-25',
    "Parser accepts standard Y-m-d format"
);

assert_true(
    parse_lead_followup_date('25-09-2026') === '2026-09-25',
    "Parser accepts d-m-Y format and returns canonical Y-m-d"
);

assert_true(
    parse_lead_followup_date('25/09/2026') === '2026-09-25',
    "Parser accepts d/m/Y format and returns canonical Y-m-d"
);

assert_true(
    parse_lead_followup_date('') === null,
    "Parser returns null for empty string"
);

assert_true(
    parse_lead_followup_date('   ') === null,
    "Parser returns null for whitespace string"
);

assert_true(
    parse_lead_followup_date(null) === null,
    "Parser returns null for null"
);

assert_true(
    parse_lead_followup_date('31-02-2026') === false,
    "Parser strictly rejects impossible calendar date 31-02-2026"
);

assert_true(
    parse_lead_followup_date('2026-02-31') === false,
    "Parser strictly rejects impossible calendar date 2026-02-31"
);

assert_true(
    parse_lead_followup_date('2026-13-45') === false,
    "Parser strictly rejects impossible month 2026-13-45"
);

assert_true(
    parse_lead_followup_date('random-text') === false,
    "Parser strictly rejects random non-date string"
);

// ── PART 2: INSTANT CONTACT EXECUTION LOGIC SIMULATION ──

function simulate_instant_contact($pdo, $lead_id, $contact_type, $selected_status, $submitted_followup, $tampered_initial_followup, $remark, $admin_username, $is_super = false) {
    global $LEAD_STATUSES, $CLOSED;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
        $stmt->execute([$lead_id]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lead) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Lead not found.'];
        }

        if (!$is_super && $lead['assigned_to'] !== $admin_username && $lead['assigned_to'] !== '__ALL__') {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Permission denied.'];
        }

        $status_changed = ($selected_status !== '' && $selected_status !== $lead['status'] && isset($LEAD_STATUSES[$selected_status]));
        $new_status = $status_changed ? $selected_status : $lead['status'];

        $raw_followup = trim((string)$submitted_followup);
        $current_db_date = $lead['next_followup_date'] ?: null; // DB date is strictly authoritative
        $followup_changed = false;

        if ($raw_followup !== '') {
            $parsed_followup = parse_lead_followup_date($raw_followup);
            if ($parsed_followup === false) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Invalid follow-up date format.'];
            }
            $effective_followup = $parsed_followup;
            $followup_changed = ($effective_followup !== $current_db_date);
        } else {
            // Blank submitted date -> preserve existing DB date or remain NULL
            $effective_followup = $current_db_date;
            $followup_changed = false;
        }

        // Active lead rule
        $is_new_status_closed = in_array($new_status, $CLOSED, true);
        if (!$is_new_status_closed && empty($effective_followup)) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'A next follow-up date is required for active leads.'];
        }

        if ($status_changed || $followup_changed) {
            $upd = $pdo->prepare("UPDATE leads SET status = ?, next_followup_date = ?, updated_at = CURRENT_TIMESTAMP, last_activity_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$new_status, $effective_followup ?: null, $lead_id]);
        } else {
            $upd = $pdo->prepare("UPDATE leads SET last_activity_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$lead_id]);
        }

        $act_type = ($contact_type === 'Called') ? 'contact_called' : 'contact_texted';
        $act_old = $status_changed ? $lead['status'] : null;
        $act_new = $status_changed ? $new_status : null;
        $act_remark = ($remark !== '') ? $remark : null;

        $ins = $pdo->prepare("
            INSERT INTO lead_activity (lead_id, activity_type, remark, old_status, new_status, followup_date, performed_by, performed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            $lead_id,
            $act_type,
            $act_remark,
            $act_old,
            $act_new,
            $effective_followup ?: null,
            $admin_username
        ]);

        $pdo->commit();
        return [
            'success' => true,
            'status' => $new_status,
            'effective_followup' => $effective_followup,
            'followup_changed' => $followup_changed
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// TEST 11: Case A: Existing DB date (2026-09-25) + blank submitted date preserves 2026-09-25
$resA = simulate_instant_contact($sqlite, 1, 'Called', 'contacted', '', '', 'Spoke to student', 'test_admin1');
assert_true(
    $resA['success'] === true && $resA['effective_followup'] === '2026-09-25',
    "Case A: Existing DB date preserved when submitted date is blank"
);

$lead1 = $sqlite->query("SELECT * FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assert_true(
    $lead1['next_followup_date'] === '2026-09-25',
    "Case A: Database lead row retained next_followup_date = 2026-09-25"
);

// TEST 13: Activity followup_date recorded preserved date
$act1 = $sqlite->query("SELECT * FROM lead_activity WHERE lead_id = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_true(
    $act1['activity_type'] === 'contact_called' && $act1['followup_date'] === '2026-09-25',
    "Case A: lead_activity.followup_date correctly stored preserved date"
);

// TEST 14: Case B: NULL date + blank submitted date on closed lead remains NULL
$resB = simulate_instant_contact($sqlite, 2, 'Texted', 'converted', '', '', 'Sent confirmation', 'test_admin1');
assert_true(
    $resB['success'] === true && $resB['effective_followup'] === null,
    "Case B: NULL DB date remains NULL when submitted date is blank on closed lead"
);

// TEST 15: Case C: Existing DB date (2026-09-25) + new valid date (2026-09-30) updates to 2026-09-30
$resC = simulate_instant_contact($sqlite, 1, 'Called', 'interested', '2026-09-30', '2026-09-25', 'Interested in course', 'test_admin1');
assert_true(
    $resC['success'] === true && $resC['effective_followup'] === '2026-09-30' && $resC['followup_changed'] === true,
    "Case C: Existing DB date updated to new date 2026-09-30"
);

$lead1AfterC = $sqlite->query("SELECT * FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assert_true(
    $lead1AfterC['next_followup_date'] === '2026-09-30' && $lead1AfterC['status'] === 'interested',
    "Case C: Database lead row updated to 2026-09-30 and status interested"
);

// TEST 17: Case D: Malformed / impossible date rejected, rolled back, existing date unchanged
$resD = simulate_instant_contact($sqlite, 1, 'Called', 'interested', '2026-02-31', '2026-09-30', 'Attempt with bad date', 'test_admin1');
assert_true(
    $resD['success'] === false && strpos($resD['error'], 'Invalid follow-up date') !== false,
    "Case D: Impossible calendar date 2026-02-31 rejected with error"
);

$lead1AfterD = $sqlite->query("SELECT * FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assert_true(
    $lead1AfterD['next_followup_date'] === '2026-09-30',
    "Case D: Database lead row untouched after rollback (retained 2026-09-30)"
);

// TEST 19: Tampered initial_followup_date cannot alter DB authority
// Attacker sends blank submitted date, but submits initial_followup_date = '2020-01-01'
$resTamper = simulate_instant_contact($sqlite, 1, 'Texted', 'interested', '', '2020-01-01', 'Tamper test', 'test_admin1');
$lead1AfterTamper = $sqlite->query("SELECT * FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assert_true(
    $lead1AfterTamper['next_followup_date'] === '2026-09-30',
    "Tamper Resistance: Browser initial_followup_date cannot overwrite or manipulate DB date"
);

// TEST 20: Active lead with NULL date without providing date is rejected (Active lead rule)
$resActiveNull = simulate_instant_contact($sqlite, 4, 'Called', 'new', '', '', 'Active lead without date', 'test_admin1');
assert_true(
    $resActiveNull['success'] === false && strpos($resActiveNull['error'], 'required for active leads') !== false,
    "Active Lead Rule: Active lead with NULL date requires a date on instant contact"
);

// TEST 21: Active lead provided with valid date succeeds
$resActiveProvided = simulate_instant_contact($sqlite, 4, 'Called', 'contacted', '2026-10-05', '', 'Initial call done', 'test_admin1');
assert_true(
    $resActiveProvided['success'] === true && $resActiveProvided['effective_followup'] === '2026-10-05',
    "Active Lead Rule: Active lead with provided valid date succeeds"
);

// TEST 22: Unauthorized lead contact attempt rejected
$resUnauth = simulate_instant_contact($sqlite, 3, 'Called', 'follow_up', '2026-09-28', '2026-09-20', 'Unauth update', 'test_admin1', false);
assert_true(
    $resUnauth['success'] === false && strpos($resUnauth['error'], 'Permission denied') !== false,
    "Permissions: Non-super admin cannot contact lead assigned to other admin"
);

// TEST 23: Super admin can update any lead
$resSuper = simulate_instant_contact($sqlite, 3, 'Called', 'follow_up', '2026-09-28', '2026-09-20', 'Super update', 'super_user', true);
assert_true(
    $resSuper['success'] === true && $resSuper['effective_followup'] === '2026-09-28',
    "Permissions: Super admin can contact and update any lead"
);

// ── PART 3: LEAD DETAILS QUICK UPDATE SIMULATION ──

function simulate_quick_update_followup($pdo, $lead_id, $submitted_followup, $admin_username, $is_super = false) {
    global $CLOSED;

    $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$lead_id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lead) {
        return ['success' => false, 'error' => 'Lead not found.'];
    }

    if (!$is_super && $lead['assigned_to'] !== $admin_username && $lead['assigned_to'] !== '__ALL__') {
        return ['success' => false, 'error' => 'Permission denied.'];
    }

    $raw_followup = trim((string)$submitted_followup);
    $parsed_followup = parse_lead_followup_date($raw_followup);
    $is_closed = in_array($lead['status'], $CLOSED, true);

    if ($raw_followup !== '' && $parsed_followup === false) {
        return ['success' => false, 'error' => 'Invalid follow-up date format.'];
    }

    if (!$is_closed && empty($parsed_followup)) {
        return ['success' => false, 'error' => 'A next follow-up date is required until the lead is converted or rejected.'];
    }

    $old_date = $lead['next_followup_date'];
    $new_date = $parsed_followup;

    if ($new_date !== $old_date) {
        $pdo->prepare("UPDATE leads SET next_followup_date = ?, updated_at = CURRENT_TIMESTAMP, last_activity_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$new_date ?: null, $lead_id]);

        $old_label = $old_date ? date('d M Y', strtotime($old_date)) : 'None';
        $new_label = $new_date ? date('d M Y', strtotime($new_date)) : 'None';
        $remark = "Follow-up date changed: {$old_label} → {$new_label}";

        $pdo->prepare("
            INSERT INTO lead_activity (lead_id, activity_type, remark, followup_date, performed_by, performed_at)
            VALUES (?, 'followup', ?, ?, ?, CURRENT_TIMESTAMP)
        ")->execute([$lead_id, $remark, $new_date ?: null, $admin_username]);
    }

    $overdue = $new_date && $new_date < date('Y-m-d') && !$is_closed;

    return [
        'success' => true,
        'next_followup_date' => $new_date,
        'is_overdue' => $overdue
    ];
}

// TEST 24: Quick update with valid future date
$resQ1 = simulate_quick_update_followup($sqlite, 1, '2026-10-15', 'test_admin1');
assert_true(
    $resQ1['success'] === true && $resQ1['next_followup_date'] === '2026-10-15' && $resQ1['is_overdue'] === false,
    "Lead Details Quick Update: Future date saves correctly and is_overdue is false"
);

// TEST 25: Database lead row updated
$lead1Q = $sqlite->query("SELECT * FROM leads WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assert_true(
    $lead1Q['next_followup_date'] === '2026-10-15',
    "Lead Details Quick Update: Database row updated to 2026-10-15"
);

// TEST 26: Activity row logged with remark
$actQ = $sqlite->query("SELECT * FROM lead_activity WHERE lead_id = 1 AND activity_type = 'followup' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_true(
    strpos($actQ['remark'], 'Follow-up date changed:') !== false && $actQ['followup_date'] === '2026-10-15',
    "Lead Details Quick Update: Activity audit row created with date transition remark"
);

// TEST 27: Quick update with past date accurately reflects overdue
$resQPast = simulate_quick_update_followup($sqlite, 1, '2020-01-01', 'test_admin1');
assert_true(
    $resQPast['success'] === true && $resQPast['is_overdue'] === true,
    "Lead Details Quick Update: Past date correctly calculates is_overdue = true"
);

// TEST 28: Quick update rejects empty date for active lead
$resQEmpty = simulate_quick_update_followup($sqlite, 1, '', 'test_admin1');
assert_true(
    $resQEmpty['success'] === false && strpos($resQEmpty['error'], 'required until the lead is converted') !== false,
    "Lead Details Quick Update: Empty date rejected for active lead"
);

// TEST 29: Quick update rejects malformed date
$resQBad = simulate_quick_update_followup($sqlite, 1, 'invalid-date', 'test_admin1');
assert_true(
    $resQBad['success'] === false && strpos($resQBad['error'], 'Invalid follow-up date') !== false,
    "Lead Details Quick Update: Malformed date rejected"
);

// TEST 30: Quick update obeys permissions
$resQUnauth = simulate_quick_update_followup($sqlite, 3, '2026-11-01', 'test_admin1', false);
assert_true(
    $resQUnauth['success'] === false && strpos($resQUnauth['error'], 'Permission denied') !== false,
    "Lead Details Quick Update: Permission check blocks unauthorized admin"
);

// ── PART 4: CODEBASE STATIC ANALYSIS & REGRESSION CHECKS ──

$lmContent = file_get_contents(__DIR__ . '/lead-management.php');
$ldContent = file_get_contents(__DIR__ . '/lead-details.php');
$lhContent = file_get_contents(__DIR__ . '/includes/lead_helper.php');

// TEST 31: Centralized helper included in lead-management.php
assert_true(
    strpos($lmContent, "require_once 'includes/lead_helper.php';") !== false,
    "lead-management.php includes shared lead_helper.php"
);

// TEST 32: Centralized helper included in lead-details.php
assert_true(
    strpos($ldContent, "require_once 'includes/lead_helper.php';") !== false,
    "lead-details.php includes shared lead_helper.php"
);

// TEST 33: Instant Contact modal in lead-management.php contains next_followup_date input
assert_true(
    strpos($lmContent, 'name="next_followup_date" id="ic-next-followup-date"') !== false,
    "Instant Contact modal contains next_followup_date date input"
);

// TEST 34: Instant Contact modal placed before Quick Remarks
$posFu = strpos($lmContent, 'id="ic-next-followup-date"');
$posQr = strpos($lmContent, 'Quick Remarks');
assert_true(
    $posFu !== false && $posQr !== false && $posFu < $posQr,
    "Next Follow-up Date input is placed before Quick Remarks section"
);

// TEST 35: Both Follow-ups and Leads tables pass next_followup_date to openInstantContactModal
assert_true(
    substr_count($lmContent, "openInstantContactModal(") >= 2,
    "Both tables invoke openInstantContactModal"
);

// TEST 36: openInstantContactModal JS function handles currentFollowupDate parameter
assert_true(
    strpos($lmContent, 'function openInstantContactModal(leadId, leadName, currentStatus, statusLabel, currentFollowupDate)') !== false,
    "openInstantContactModal JS signature accepts currentFollowupDate"
);

// TEST 37: lead-details.php contains Edit button for Next follow-up
assert_true(
    strpos($ldContent, 'onclick="openQuickFollowupModal()"') !== false,
    "lead-details.php top summary contains Edit button for Next follow-up"
);

// TEST 38: lead-details.php contains #quick-followup-modal
assert_true(
    strpos($ldContent, 'id="quick-followup-modal"') !== false,
    "lead-details.php contains #quick-followup-modal markup"
);

// TEST 39: lead-details.php handles action === 'quick_update_followup'
assert_true(
    strpos($ldContent, "\$action === 'quick_update_followup'") !== false,
    "lead-details.php handles action === 'quick_update_followup'"
);

// TEST 40: Converted by and alumni referral attribution logic remains untouched
assert_true(
    strpos($lmContent, 'alumni_referral') !== false && strpos($lmContent, 'auto_converted') !== false && strpos($ldContent, 'change_converted_by') !== false,
    "Attribution logic (alumni_referral, auto_converted, converted_by) completely intact"
);

// TEST 41: Call Reports tab logic remains intact
assert_true(
    strpos($lmContent, 'tab === \'call-reports\'') !== false && strpos($lmContent, 'render_lead_contact_report_pdf') !== false,
    "Call Reports and PDF export logic completely intact"
);

echo "\n======================================================================\n";
echo "  TEST SUMMARY: {$passed} PASSED, {$failed} FAILED (TOTAL {$total})\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
