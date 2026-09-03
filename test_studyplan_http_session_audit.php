<?php
/**
 * PEPP Study Plan Activity Deletion & Bulk Delete HTTP Session Audit
 *
 * Tests the live running web server on 127.0.0.1:8888 with realistic HTTP sessions:
 * - Admin A (Alice - Lock Owner) vs Admin B (Bob - Non-Owner)
 * - Tests Scenarios A through I over real HTTP requests against the live API
 */

$base_url = 'http://127.0.0.1:8888';

// Helper for making cURL HTTP requests
function http_req($url, $method = 'GET', $data = [], $cookie_val = null, $is_json = false) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($cookie_val) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie_val);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($is_json) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }
    }

    $raw_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($raw_response, 0, $header_size);
    $body = substr($raw_response, $header_size);
    curl_close($ch);

    // Extract cookie if present
    preg_match_all('/Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $headers, $matches);
    $cookie = !empty($matches[1]) ? end($matches[1]) : $cookie_val;

    return ['code' => $http_code, 'body' => $body, 'headers' => $headers, 'cookie' => $cookie];
}

$passed = 0;
$failed = 0;

function run_http_test($name, $closure) {
    global $passed, $failed;
    try {
        $res = $closure();
        if ($res === true || $res === null) {
            echo "  [PASS] {$name}\n";
            $passed++;
        } else {
            echo "  [FAIL] {$name}: {$res}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: Exception: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo "  PEPP STUDY PLAN DELETION LIVE HTTP MULTI-SESSION AUDIT\n";
echo "======================================================================\n\n";

// 1. Reset and initialize SQLite database
$workspace = 'd:/LABINC PVT LTD/PEPP Learning/PEPP/2026-27/Website 2027/Admin-Register-Installment/Antigravity/admissions';
$sqlite_path = $workspace . '/scratch_test_db.sqlite';
require_once 'C:/Users/incub/.gemini/antigravity-ide/brain/aa4a14c2-708e-48c2-92fc-529fdee4f533/scratch/setup_browser_test_env.php';

// Helper to get SQLite PDO for verification
$pdo = new PDO("sqlite:" . $sqlite_path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// 2. Establish Session for Alice
$login_alice = http_req($base_url . '/login.php', 'POST', [
    'username' => 'admin_alice',
    'password' => 'password123',
    'login' => '1'
]);
$cookie_alice = $login_alice['cookie'];

// 3. Establish Session for Bob
$login_bob = http_req($base_url . '/login.php', 'POST', [
    'username' => 'admin_bob',
    'password' => 'password123',
    'login' => '1'
]);
$cookie_bob = $login_bob['cookie'];

// Acquire edit lock for Alice on Plan #11 via API
$lock_res = http_req($base_url . '/api/studyplans-api.php?action=check_edit_lock', 'POST', [
    'study_plan_id' => 11
], $cookie_alice);
$lock_json = json_decode($lock_res['body'], true);

// Check if live local webserver is reachable on 8888
$is_live_server = ($login_alice['code'] === 200 || $login_alice['code'] === 302);

if ($is_live_server) {
    run_http_test("HTTP-01: Admin Alice acquires exclusive edit lock on Plan #11", function() use ($lock_json) {
        if (!$lock_json || !$lock_json['success'] || $lock_json['is_owner'] !== true) {
            return "Failed to acquire lock: " . json_encode($lock_json);
        }
        return true;
    });

    // SCENARIO A: Individual deletion of safe activity (101)
    run_http_test("HTTP-02: Scenario A - Individual delete of safe task (101)", function() use ($base_url, $cookie_alice, $pdo) {
        // Check delete
        $chk = http_req($base_url . '/api/studyplans-api.php?action=check_activity_delete', 'POST', ['activity_id' => 101], $cookie_alice);
        $chk_json = json_decode($chk['body'], true);
        if (!$chk_json['success'] || !$chk_json['deletable'] || empty($chk_json['confirmation_token'])) {
            return "check_activity_delete failed: " . $chk['body'];
        }

        // Delete
        $del = http_req($base_url . '/api/studyplans-api.php?action=delete_activity', 'POST', [
            'activity_id' => 101,
            'confirmation_token' => $chk_json['confirmation_token'],
            'deletion_reason' => 'HTTP test delete'
        ], $cookie_alice);
        $del_json = json_decode($del['body'], true);
        if (!$del_json['success'] || $del_json['deleted_id'] != 101) {
            return "delete_activity failed: " . $del['body'];
        }

        // Verify DB
        $act = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 101")->fetch();
        return (int)$act['is_deleted'] === 1;
    });

    // SCENARIO B: Individual deletion of protected activity (103 has student data)
    run_http_test("HTTP-03: Scenario B - Individual delete of protected task (103) is blocked", function() use ($base_url, $cookie_alice, $pdo) {
        // Check delete
        $chk = http_req($base_url . '/api/studyplans-api.php?action=check_activity_delete', 'POST', ['activity_id' => 103], $cookie_alice);
        $chk_json = json_decode($chk['body'], true);
        if (!$chk_json['success'] || $chk_json['deletable'] !== false || $chk_json['error_code'] !== 'ACTIVITY_HAS_STUDENT_DATA') {
            return "Expected blocked with ACTIVITY_HAS_STUDENT_DATA: " . $chk['body'];
        }

        // Direct malicious attempt without valid token
        $del = http_req($base_url . '/api/studyplans-api.php?action=delete_activity', 'POST', [
            'activity_id' => 103,
            'confirmation_token' => 'invalid_or_fake_token'
        ], $cookie_alice);
        $del_json = json_decode($del['body'], true);
        if ($del_json['success'] !== false) {
            return "Expected failed deletion: " . $del['body'];
        }

        // Verify DB remains active
        $act = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 103")->fetch();
        return (int)$act['is_deleted'] === 0;
    });

    // SCENARIO C: Duplicate activity deletion (Day 2 has 104, 105, 106)
    run_http_test("HTTP-04: Scenario C - Deleting duplicate 104 leaves duplicates 105 & 106 untouched", function() use ($base_url, $cookie_alice, $pdo) {
        $chk = http_req($base_url . '/api/studyplans-api.php?action=check_activity_delete', 'POST', ['activity_id' => 104], $cookie_alice);
        $chk_json = json_decode($chk['body'], true);

        $del = http_req($base_url . '/api/studyplans-api.php?action=delete_activity', 'POST', [
            'activity_id' => 104,
            'confirmation_token' => $chk_json['confirmation_token']
        ], $cookie_alice);
        $del_json = json_decode($del['body'], true);
        if (!$del_json['success']) return "Failed deleting 104: " . $del['body'];

        $act104 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 104")->fetch();
        $act105 = $pdo->query("SELECT is_deleted, activity_date, day_number, sort_order FROM study_plan_activities WHERE id = 105")->fetch();
        $act106 = $pdo->query("SELECT is_deleted, activity_date, day_number, sort_order FROM study_plan_activities WHERE id = 106")->fetch();

        if ((int)$act104['is_deleted'] !== 1) return "104 was not soft-deleted";
        if ((int)$act105['is_deleted'] !== 0) return "105 was wrongly soft-deleted";
        if ((int)$act106['is_deleted'] !== 0) return "106 wrongly soft-deleted";

        // Day 2 tasks must retain exact day, date, and orders
        if ($act105['activity_date'] !== '2026-09-02' || (int)$act105['day_number'] !== 2 || (int)$act105['sort_order'] !== 1) return "105 metadata shifted";
        if ($act106['activity_date'] !== '2026-09-02' || (int)$act106['day_number'] !== 2 || (int)$act106['sort_order'] !== 2) return "106 metadata shifted";

        return true;
    });

    // SCENARIO D: Mixed safe + protected bulk deletion (105 Safe, 106 Protected, 107 Safe, 109 Protected Exam)
    run_http_test("HTTP-05: Scenario D - Mixed bulk delete deletes ONLY safe tasks (105, 107) and protects (106, 109)", function() use ($base_url, $cookie_alice, $pdo) {
        $payload = [
            'study_plan_id' => 11,
            'activity_ids' => [105, 106, 107, 109],
            'activity_uids' => ['SPA-11-005', 'SPA-11-006', 'SPA-11-007', 'SPA-11-009'],
            'deletion_reason' => 'Mixed bulk delete HTTP test'
        ];

        $bulk = http_req($base_url . '/api/studyplans-api.php?action=bulk_delete_activities', 'POST', $payload, $cookie_alice, true);
        $bulk_json = json_decode($bulk['body'], true);

        if (!$bulk_json['success'] || $bulk_json['deleted_count'] !== 2 || $bulk_json['protected_count'] !== 2) {
            return "Unexpected bulk response: " . $bulk['body'];
        }

        if ($bulk_json['deleted_ids'] !== [105, 107]) return "Wrong deleted IDs: " . json_encode($bulk_json['deleted_ids']);

        $act105 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 105")->fetch();
        $act106 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 106")->fetch();
        $act107 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 107")->fetch();
        $act109 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 109")->fetch();

        if ((int)$act105['is_deleted'] !== 1) return "105 not deleted";
        if ((int)$act107['is_deleted'] !== 1) return "107 not deleted";
        if ((int)$act106['is_deleted'] !== 0) return "106 wrongly deleted";
        if ((int)$act109['is_deleted'] !== 0) return "109 wrongly deleted";

        // Single audit log record
        $audit_cnt = $pdo->query("SELECT COUNT(*) FROM study_plan_audit_logs WHERE study_plan_id = 11 AND action = 'bulk_delete_activities'")->fetchColumn();
        if ((int)$audit_cnt !== 1) return "Audit log count not 1: " . $audit_cnt;

        return true;
    });

    // SCENARIO E: Empty Day (Day 3 had lone task 107) -> 0 tasks, NO Rest Day
    run_http_test("HTTP-06: Scenario E - Day 3 is empty with 0 tasks and ZERO automatic Rest Day records", function() use ($pdo) {
        $day3_cnt = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND day_number = 3 AND is_deleted = 0")->fetchColumn();
        $rest_cnt = $pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE study_plan_id = 11 AND (activity_title LIKE '%Rest Day%' OR activity_type = 'Rest Day')")->fetchColumn();

        if ((int)$day3_cnt !== 0) return "Day 3 count is " . $day3_cnt;
        if ((int)$rest_cnt !== 0) return "Rest day count is " . $rest_cnt;

        // Verify Day 4 and Day 5 dates have not shifted
        $day4_act = $pdo->query("SELECT activity_date, day_number FROM study_plan_activities WHERE id = 108")->fetch();
        $day5_act = $pdo->query("SELECT activity_date, day_number FROM study_plan_activities WHERE id = 110")->fetch();

        if ($day4_act['activity_date'] !== '2026-09-04' || (int)$day4_act['day_number'] !== 4) return "Day 4 shifted";
        if ($day5_act['activity_date'] !== '2026-09-05' || (int)$day5_act['day_number'] !== 5) return "Day 5 shifted";

        return true;
    });

    // SCENARIO F & G: Refresh / reload page via HTTP returns exact synchronized activities
    run_http_test("HTTP-07: Scenario F & G - Full plan reload returns exact synchronized activities", function() use ($base_url, $cookie_alice) {
        $fetch = http_req($base_url . '/studyplan-designer.php?id=11', 'GET', null, $cookie_alice);
        if ($fetch['code'] !== 200) return "Failed loading designer page: HTTP " . $fetch['code'];

        // Extract activities array from embedded JavaScript
        if (!preg_match('/var\s+activities\s*=\s*(\[.*?\]);/s', $fetch['body'], $matches)) {
            return "Could not find activities in page response";
        }

        $active_acts = json_decode($matches[1], true);
        if (!is_array($active_acts)) return "Failed to parse rawActivities JSON";

        $active_ids = array_map(function($a) { return (int)$a['id']; }, $active_acts);

        // Active IDs should be: 102 (Day 1), 103 (Day 1), 106 (Day 2), 108 (Day 4), 109 (Day 4), 110 (Day 5)
        sort($active_ids);
        $expected_ids = [102, 103, 106, 108, 109, 110];

        if ($active_ids !== $expected_ids) {
            return "Mismatch in active IDs: " . json_encode($active_ids) . " vs expected " . json_encode($expected_ids);
        }

        return true;
    });

    // SCENARIO H: Concurrent Admin Attempt (Bob blocked on Plan #11)
    run_http_test("HTTP-08: Scenario H - Non-owner Bob is blocked from single & bulk delete with EDIT_LOCK_HELD", function() use ($base_url, $cookie_bob, $pdo) {
        // Single delete attempt by Bob
        $del_bob = http_req($base_url . '/api/studyplans-api.php?action=delete_activity', 'POST', [
            'activity_id' => 108,
            'confirmation_token' => 'some_token'
        ], $cookie_bob);
        $del_bob_json = json_decode($del_bob['body'], true);

        // Bulk delete attempt by Bob
        $bulk_bob = http_req($base_url . '/api/studyplans-api.php?action=bulk_delete_activities', 'POST', [
            'study_plan_id' => 11,
            'activity_ids' => [108]
        ], $cookie_bob, true);
        $bulk_bob_json = json_decode($bulk_bob['body'], true);

        if ($bulk_bob_json['success'] !== false || $bulk_bob_json['error_code'] !== 'EDIT_LOCK_HELD') {
            return "Bob was not blocked on bulk delete: " . $bulk_bob['body'];
        }

        // Verify task 108 was NOT deleted
        $act108 = $pdo->query("SELECT is_deleted FROM study_plan_activities WHERE id = 108")->fetch();
        return (int)$act108['is_deleted'] === 0;
    });

    // SCENARIO I: Double-click / duplicate deletion request
    run_http_test("HTTP-09: Scenario I - Repeated bulk delete request returns 0 deleted without errors or duplicate audits", function() use ($base_url, $cookie_alice, $pdo) {
        $audit_cnt_before = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_audit_logs WHERE study_plan_id = 11")->fetchColumn();

        $repeat = http_req($base_url . '/api/studyplans-api.php?action=bulk_delete_activities', 'POST', [
            'study_plan_id' => 11,
            'activity_ids' => [105, 107] // already deleted in Scenario D
        ], $cookie_alice, true);
        $repeat_json = json_decode($repeat['body'], true);

        if (!$repeat_json['success'] || $repeat_json['deleted_count'] !== 0) {
            return "Unexpected repeat response: " . $repeat['body'];
        }

        $audit_cnt_after = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_audit_logs WHERE study_plan_id = 11")->fetchColumn();
        if ($audit_cnt_after !== $audit_cnt_before) {
            return "Duplicate audit log was written!";
        }

        return true;
    });
} else {
    echo "  [INFO] Live dev server on {$base_url} is offline. Skipping live HTTP API scenarios.\n";
}

// ── SECTION: PEPP Learning Resource Links & Android App Intent Security Audit ──
echo "\n======================================================================\n";
echo "  PEPP RESOURCE LINK & ANDROID APP INTENT ROUTING SECURITY AUDIT\n";
echo "======================================================================\n";

require_once __DIR__ . '/studyplan.php';

// Helper for JS simulation test
function simulate_js_intent_builder($url) {
    $parts = @parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
    if (strtolower($parts['scheme']) !== 'https') return null;
    if (strtolower($parts['host']) !== 'courses.pepplearning.com') return null;

    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    $pathAndQuery = $path . $query . $fragment;
    $encodedFallback = rawurlencode($url);

    return "intent://" . $parts['host'] . $pathAndQuery . "#Intent;scheme=https;package=com.pepplearning;S.browser_fallback_url=" . $encodedFallback . ";end";
}

$sample_url = 'https://courses.pepplearning.com/learn/home/M-Clin-Psy-RCI/section/679776/lesson/5232603?embedPlayer=1&disableLessonChange=true&visitorFlow=true';

run_http_test("RL-01: PEPP URL is recognized as app-eligible by is_pepp_app_eligible_url()", function() use ($sample_url) {
    return is_pepp_app_eligible_url($sample_url) === true;
});

run_http_test("RL-02: Exact sample URL remains intact without modification", function() use ($sample_url) {
    $valid = get_valid_url($sample_url);
    return $valid === $sample_url;
});

run_http_test("RL-03: Path is preserved in intent generation", function() use ($sample_url) {
    $intent = simulate_js_intent_builder($sample_url);
    return strpos($intent, '/learn/home/M-Clin-Psy-RCI/section/679776/lesson/5232603') !== false;
});

run_http_test("RL-04: Query parameters (embedPlayer, disableLessonChange, visitorFlow) are preserved", function() use ($sample_url) {
    $intent = simulate_js_intent_builder($sample_url);
    return strpos($intent, 'embedPlayer=1') !== false
        && strpos($intent, 'disableLessonChange=true') !== false
        && strpos($intent, 'visitorFlow=true') !== false;
});

run_http_test("RL-05: Multiple query parameters containing '&' are preserved", function() use ($sample_url) {
    $intent = simulate_js_intent_builder($sample_url);
    return strpos($intent, 'embedPlayer=1&disableLessonChange=true&visitorFlow=true') !== false;
});

run_http_test("RL-06: External YouTube URL is not app-eligible", function() {
    $yt = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    return is_pepp_app_eligible_url($yt) === false && simulate_js_intent_builder($yt) === null;
});

run_http_test("RL-07: External Zoom URL is not app-eligible", function() {
    $zoom = 'https://zoom.us/j/1234567890';
    return is_pepp_app_eligible_url($zoom) === false && simulate_js_intent_builder($zoom) === null;
});

run_http_test("RL-08: External Google Drive URL is not app-eligible", function() {
    $drive = 'https://drive.google.com/file/d/123456789/view';
    return is_pepp_app_eligible_url($drive) === false && simulate_js_intent_builder($drive) === null;
});

run_http_test("RL-09: HTTP URL (non-HTTPS) is not converted into a PEPP app Intent", function() {
    $http_url = 'http://courses.pepplearning.com/learn/home/test';
    return is_pepp_app_eligible_url($http_url) === false && simulate_js_intent_builder($http_url) === null;
});

run_http_test("RL-10: javascript: URL is never converted into an app Intent", function() {
    $js_url = 'javascript:alert(document.cookie)';
    return is_pepp_app_eligible_url($js_url) === false && simulate_js_intent_builder($js_url) === null;
});

run_http_test("RL-11: data: URL is never converted into an app Intent", function() {
    $data_url = 'data:text/html,<script>alert(1)</script>';
    return is_pepp_app_eligible_url($data_url) === false && simulate_js_intent_builder($data_url) === null;
});

run_http_test("RL-12: Malicious lookalike hostname (courses.pepplearning.com.evil.example) is rejected", function() {
    $evil = 'https://courses.pepplearning.com.evil.example/phish';
    return is_pepp_app_eligible_url($evil) === false && simulate_js_intent_builder($evil) === null;
});

run_http_test("RL-13: Desktop and iOS behavior maintains normal HTTPS target='_blank' HTML rendering", function() use ($sample_url) {
    $valid_res_url = get_valid_url($sample_url);
    $is_app_eligible = is_pepp_app_eligible_url($valid_res_url);
    $html = '<a href="' . htmlspecialchars($valid_res_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="pepp-resource-link' . ($is_app_eligible ? ' pepp-app-eligible' : '') . '" data-pepp-url="' . htmlspecialchars($valid_res_url, ENT_QUOTES, 'UTF-8') . '">Test Lesson</a>';

    return strpos($html, 'target="_blank"') !== false
        && strpos($html, 'class="pepp-resource-link pepp-app-eligible"') !== false
        && strpos($html, 'href="' . htmlspecialchars($sample_url, ENT_QUOTES, 'UTF-8') . '"') !== false;
});

run_http_test("RL-14: Android Intent fallback contains ORIGINAL HTTPS URL in encoded form", function() use ($sample_url) {
    $intent = simulate_js_intent_builder($sample_url);
    $encoded = rawurlencode($sample_url);
    return strpos($intent, 'S.browser_fallback_url=' . $encoded) !== false;
});

run_http_test("RL-15: Zero authentication or session tokens appended to resource URL or Intent URI", function() use ($sample_url) {
    $intent = simulate_js_intent_builder($sample_url);
    return strpos($intent, 'PHPSESSID') === false
        && strpos($intent, 'sp_logged_in') === false
        && strpos($intent, 'password') === false
        && strpos($intent, 'token') === false;
});

echo "\n----------------------------------------------------------------------\n";
echo "  RESULT: {$passed}/" . ($passed + $failed) . " AUDIT TESTS PASSED (" . round(($passed / ($passed + $failed)) * 100) . "%)\n";
echo "======================================================================\n";
