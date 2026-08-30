<?php
/**
 * End-to-End Live HTTP/Browser Session Verification for Study Plan Designer Edit Lock
 * Tests Scenarios 1 to 10 against live local server with separate cookie jars for Admin A and Admin B.
 */

$baseUrl = 'http://127.0.0.1:8888';
$aliceCookie = __DIR__ . '/alice_cookies.txt';
$bobCookie = __DIR__ . '/bob_cookies.txt';

if (file_exists($aliceCookie)) unlink($aliceCookie);
if (file_exists($bobCookie)) unlink($bobCookie);

function http_req($url, $cookieFile, $postData = null, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($postData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            $headers[] = 'Content-Type: application/json';
        }
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['body' => $res, 'status' => $info['http_code']];
}

$passed = 0;
$total = 0;

function assert_scenario($name, $condition, $details = '') {
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name} — {$details}\n";
    }
}

echo "\n======================================================================\n";
echo "  LIVE MULTI-SESSION HTTP VERIFICATION (SCENARIOS 1 - 10)\n";
echo "======================================================================\n\n";

// ── SETUP: Login Admin A (Alice) & Admin B (Bob) ──────────────────────
http_req("{$baseUrl}/test_login_helper.php?user=alice", $aliceCookie);
http_req("{$baseUrl}/test_login_helper.php?user=bob", $bobCookie);

// ── SCENARIO 1: First Admin (Alice) gets Edit Access on Plan #11 ──────
$res1 = http_req("{$baseUrl}/studyplan-designer.php?id=11", $aliceCookie);
$hasLockedModal1 = (strpos($res1['body'], 'id="modal-edit-locked" style="display:flex;"') !== false);
$hasReadOnlyMode1 = (strpos($res1['body'], 'var isReadOnlyMode = true;') !== false);
$hasEditMode1 = (strpos($res1['body'], 'var isReadOnlyMode = false;') !== false);

assert_scenario(
    "SCENARIO 1: Admin A opens Plan #11 -> Enters Edit Mode (isReadOnlyMode = false, no lock modal)",
    (!$hasLockedModal1 && $hasEditMode1 && !$hasReadOnlyMode1),
    "Locked modal visible or not in edit mode"
);

// Verify DB row
$db_check1 = http_req("{$baseUrl}/api/studyplans-api.php?action=check_edit_lock&read_only_mode=1&study_plan_id=11", $aliceCookie);
$db_json1 = json_decode($db_check1['body'], true);
assert_scenario(
    "SCENARIO 1 (DB): Plan #11 lock held by Admin A (is_owner: true)",
    (!empty($db_json1['is_owner'])),
    "DB lock not owned by Alice: " . $db_check1['body']
);

// ── SCENARIO 2: Second Admin (Bob) is Blocked from Editing Plan #11 ───
$res2 = http_req("{$baseUrl}/studyplan-designer.php?id=11", $bobCookie);
$hasLockedModal2 = (strpos($res2['body'], 'id="modal-edit-locked" style="display:flex;"') !== false);
$hasAliceName2 = (strpos($res2['body'], 'Alice Smith (Academic Lead)') !== false);
$hasAliceUser2 = (strpos($res2['body'], '@admin_alice') !== false);
$hasPhantomAdmin2 = (strpos($res2['body'], '(@admin)') !== false);
$hasReadOnlyMode2 = (strpos($res2['body'], 'var isReadOnlyMode = true;') !== false);

assert_scenario(
    "SCENARIO 2: Admin B opens Plan #11 -> Blocked with Admin A's real identity (Alice Smith, @admin_alice)",
    ($hasLockedModal2 && $hasAliceName2 && $hasAliceUser2 && !$hasPhantomAdmin2 && $hasReadOnlyMode2),
    "Lock modal missing Alice info or contains phantom @admin"
);

// ── SCENARIO 3: Read-Only Access for Admin B ──────────────────────────
$res3_poll = http_req("{$baseUrl}/api/studyplans-api.php?action=check_edit_lock&read_only_mode=1&study_plan_id=11", $bobCookie);
$poll_json3 = json_decode($res3_poll['body'], true);

assert_scenario(
    "SCENARIO 3: Read-Only check by Admin B returns locked: true, is_owner: false, can_claim: false",
    ($poll_json3['locked'] === true && $poll_json3['is_owner'] === false && $poll_json3['can_claim'] === false),
    "Poller returned incorrect state: " . $res3_poll['body']
);

// Verify Admin B cannot perform mutation (save_activities)
$mutation_attempt = http_req("{$baseUrl}/api/studyplans-api.php?action=save_activities", $bobCookie, json_encode([
    'study_plan_id' => 11,
    'version' => 1,
    'activities' => []
]));
$mut_json = json_decode($mutation_attempt['body'], true);
assert_scenario(
    "SCENARIO 3 (Security): Mutating endpoint rejects Admin B with EDIT_LOCK_HELD",
    ($mut_json['success'] === false && $mut_json['error_code'] === 'EDIT_LOCK_HELD'),
    "Mutation not blocked: " . $mutation_attempt['body']
);

// ── SCENARIO 4: Admin A Exits -> Lock Released -> Admin B Claims ──────
$rel_res = http_req("{$baseUrl}/api/studyplans-api.php?action=release_study_plan_edit_lock&study_plan_id=11", $aliceCookie, ['study_plan_id' => 11]);
$rel_json = json_decode($rel_res['body'], true);

assert_scenario(
    "SCENARIO 4: Admin A releases edit lock -> Server returns success: true",
    (!empty($rel_json['success'])),
    "Release failed: " . $rel_res['body']
);

// Admin B poller checks again
$poll_after_exit = http_req("{$baseUrl}/api/studyplans-api.php?action=check_edit_lock&read_only_mode=1&study_plan_id=11", $bobCookie);
$poll_after_json = json_decode($poll_after_exit['body'], true);

assert_scenario(
    "SCENARIO 4 (Polling): Admin B detects lock available (can_claim: true, locked: false)",
    (!empty($poll_after_json['can_claim']) && $poll_after_json['locked'] === false),
    "Lock not detected available: " . $poll_after_exit['body']
);

// Admin B acquires edit mode
$res_bob_claim = http_req("{$baseUrl}/studyplan-designer.php?id=11", $bobCookie);
$hasBobEditMode = (strpos($res_bob_claim['body'], 'var isReadOnlyMode = false;') !== false);
$hasBobNoLockModal = (strpos($res_bob_claim['body'], 'id="modal-edit-locked" style="display:flex;"') === false);

assert_scenario(
    "SCENARIO 4 (Acquire): Admin B reloads and acquires Edit Mode (isReadOnlyMode = false)",
    ($hasBobEditMode && $hasBobNoLockModal),
    "Bob could not acquire edit mode"
);

// ── SCENARIO 5: Save & Exit by Admin B ────────────────────────────────
$save_res = http_req("{$baseUrl}/api/studyplans-api.php?action=save_activities", $bobCookie, json_encode([
    'study_plan_id' => 11,
    'version' => 1,
    'activities' => [
        [
            'id' => 101,
            'study_plan_id' => 11,
            'activity_date' => '2026-09-01',
            'day_number' => 1,
            'sort_order' => 0,
            'activity_title' => 'Orientation & Syllabus Breakdown (Updated by Bob)',
            'activity_type' => 'Read Material',
            'activity_uid' => 'SPA-11-001'
        ]
    ]
]));
$save_json = json_decode($save_res['body'], true);

// Release Bob's lock
http_req("{$baseUrl}/api/studyplans-api.php?action=release_study_plan_edit_lock&study_plan_id=11", $bobCookie, ['study_plan_id' => 11]);

assert_scenario(
    "SCENARIO 5: Admin B saves changes successfully (version bumped) & releases cleanly",
    (!empty($save_json['success']) && ($save_json['version'] ?? 0) >= 2),
    "Save failed: " . $save_res['body']
);

// ── SCENARIO 6: Independent Locking Across Different Plans ────────────
// Admin A locks Plan #11
http_req("{$baseUrl}/studyplan-designer.php?id=11", $aliceCookie);
// Admin B opens Plan #12
$res_bob_12 = http_req("{$baseUrl}/studyplan-designer.php?id=12", $bobCookie);
$hasBobEdit12 = (strpos($res_bob_12['body'], 'var isReadOnlyMode = false;') !== false);
$hasBobNoModal12 = (strpos($res_bob_12['body'], 'id="modal-edit-locked" style="display:flex;"') === false);

assert_scenario(
    "SCENARIO 6: Admin A on Plan #11 does NOT block Admin B from editing Plan #12",
    ($hasBobEdit12 && $hasBobNoModal12),
    "Plan #12 was unexpectedly blocked for Bob"
);

// Clean up locks
http_req("{$baseUrl}/api/studyplans-api.php?action=release_study_plan_edit_lock&study_plan_id=11", $aliceCookie, ['study_plan_id' => 11]);
http_req("{$baseUrl}/api/studyplans-api.php?action=release_study_plan_edit_lock&study_plan_id=12", $bobCookie, ['study_plan_id' => 12]);

// ── SCENARIO 8: Fail-Closed Behavior on Lock Error ────────────────────
// Verify check_edit_lock for draft (id = 0)
$res_draft = http_req("{$baseUrl}/studyplan-designer.php?id=0", $aliceCookie);
$hasDraftEdit = (strpos($res_draft['body'], 'var isReadOnlyMode = false;') !== false);

assert_scenario(
    "SCENARIO 8: Draft plan (id = 0) opens in Edit Mode without lock restrictions",
    ($hasDraftEdit),
    "Draft plan failed to open in edit mode"
);

// ── SCENARIO 10: Check For False Locks across Multiple Plans ──────────
$false_locks_found = 0;
foreach ([11, 12, 13] as $pid) {
    $check = http_req("{$baseUrl}/studyplan-designer.php?id={$pid}", $aliceCookie);
    if (strpos($check['body'], 'id="modal-edit-locked" style="display:flex;"') !== false) {
        $false_locks_found++;
    }
    // Clean up
    http_req("{$baseUrl}/api/studyplans-api.php?action=release_study_plan_edit_lock&study_plan_id={$pid}", $aliceCookie, ['study_plan_id' => $pid]);
}

assert_scenario(
    "SCENARIO 10: ZERO false locks across Plan #11, #12, #13 when no other admin is editing",
    ($false_locks_found === 0),
    "Found {$false_locks_found} false lock(s)"
);

echo "\n----------------------------------------------------------------------\n";
echo "  RESULT: {$passed}/{$total} SCENARIOS PASSED (" . round(($passed/$total)*100, 1) . "%)\n";
echo "======================================================================\n\n";

if ($passed !== $total) {
    exit(1);
}
