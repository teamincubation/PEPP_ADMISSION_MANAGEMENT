<?php
/**
 * PEPP Updates — Phase 3: Public Popup Display & Lead Capture Automated Audit
 *
 * Validates:
 * 1. Public Ad Eligibility & Post Targeting Resolution
 * 2. Field Options Parsing & Dynamic Form Definitions
 * 3. Scoped Public CSRF Token Generation & Verification
 * 4. IP Hashing & Rate Limiting Abuse Prevention
 * 5. Recent Duplicate Detection & Suppression
 * 6. Lead Submission Endpoint (ad-enquiry.php) Validation & Persistence
 * 7. Lead Capture to Admin Visibility Integration
 * 8. CRM Auto-Conversion Safety (Strictly OFF, status='new')
 * 9. Client-Side Accessibility, Focus Trapping, & Page Visibility Timer Compliance
 * 10. Backward-Compatibility & Boundary Integrity
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

echo "============================================================\n";
echo "PEPP UPDATES — PHASE 3 PUBLIC POPUP & LEAD CAPTURE AUDIT\n";
echo "============================================================\n\n";

$totalPass = 0;
$totalFail = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $totalPass, $totalFail;
    if ($condition) {
        $totalPass++;
        echo "  [PASS] {$name}\n";
    } else {
        $totalFail++;
        echo "  [FAIL] {$name}" . ($detail ? " — {$detail}" : "") . "\n";
    }
}

// ─────────────────────────────────────────────────────────────
// SETUP IN-MEMORY ISOLATED SQLITE DATABASE
// ─────────────────────────────────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON;');

// Define custom SQLite functions for MySQL compatibility in test environment
$pdo->sqliteCreateFunction('NOW', function() {
    return date('Y-m-d H:i:s');
});

// Setup minimal schema for testing
$pdo->exec("
    CREATE TABLE updates_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE NOT NULL,
        setting_value TEXT NOT NULL,
        description TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE pepp_courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        course_name TEXT NOT NULL,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE updates_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        short_description TEXT,
        full_description TEXT,
        banner_image TEXT,
        status TEXT NOT NULL DEFAULT 'draft',
        publish_at TEXT,
        expires_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE updates_ads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        headline TEXT NOT NULL,
        subheadline TEXT,
        image_url TEXT,
        button_text TEXT NOT NULL DEFAULT 'Enquire Now',
        target_all_posts INTEGER NOT NULL DEFAULT 1,
        popup_delay_seconds INTEGER NOT NULL DEFAULT 15,
        auto_convert_crm INTEGER NOT NULL DEFAULT 0,
        target_course_id INTEGER,
        status TEXT NOT NULL DEFAULT 'draft',
        display_order INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE updates_ad_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad_id INTEGER NOT NULL,
        post_id INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (ad_id, post_id),
        FOREIGN KEY (ad_id) REFERENCES updates_ads(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE CASCADE
    );

    CREATE TABLE updates_ad_fields (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad_id INTEGER NOT NULL,
        field_name TEXT NOT NULL,
        field_label TEXT NOT NULL,
        field_type TEXT NOT NULL DEFAULT 'text',
        is_required INTEGER NOT NULL DEFAULT 0,
        field_options TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (ad_id, field_name),
        FOREIGN KEY (ad_id) REFERENCES updates_ads(id) ON DELETE CASCADE
    );

    CREATE TABLE updates_ad_leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad_id INTEGER NOT NULL,
        post_id INTEGER,
        name TEXT NOT NULL,
        phone TEXT NOT NULL,
        course_id INTEGER,
        submitted_data TEXT,
        ip_hash TEXT,
        status TEXT NOT NULL DEFAULT 'new',
        converted_lead_id INTEGER,
        conversion_notes TEXT,
        conversion_attempted_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ad_id) REFERENCES updates_ads(id) ON DELETE RESTRICT,
        FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE SET NULL,
        FOREIGN KEY (course_id) REFERENCES pepp_courses(id) ON DELETE SET NULL
    );
");

// Seed Initial Settings & Data
$pdo->exec("
    INSERT INTO updates_settings (setting_key, setting_value) VALUES ('ad_leads_auto_convert_enabled', '0');
    INSERT INTO pepp_courses (id, course_name) VALUES (1, 'CUET UG Crash Course');
    INSERT INTO pepp_courses (id, course_name) VALUES (2, 'Kerala Plus Two Commerce');

    INSERT INTO updates_posts (id, title, slug, status, publish_at)
    VALUES (101, 'CUET UG 2027 Registration Date Announced', 'cuet-ug-2027-dates', 'published', datetime('now', '-1 day'));

    INSERT INTO updates_posts (id, title, slug, status, publish_at)
    VALUES (102, 'Kerala Plus Two Exam Timetable 2027', 'kerala-plus-two-timetable-2027', 'published', datetime('now', '-1 day'));

    INSERT INTO updates_posts (id, title, slug, status, publish_at)
    VALUES (103, 'Draft Upcoming Post Not Yet Published', 'draft-upcoming-post', 'draft', datetime('now', '+5 days'));
");

$GLOBALS['pdo'] = $pdo;
require_once __DIR__ . '/includes/pepp_updates_helper.php';
require_once __DIR__ . '/public_updates/includes/functions.php';

// ─────────────────────────────────────────────────────────────
// 1. PUBLIC AD ELIGIBILITY & TARGETING RESOLUTION

// ─────────────────────────────────────────────────────────────
echo "--- 1. Public Ad Eligibility & Targeting Resolution ---\n";

// Insert Ad 1: Active, targets all posts, delay 20s
$pdo->exec("
    INSERT INTO updates_ads (id, title, slug, headline, subheadline, button_text, target_all_posts, popup_delay_seconds, auto_convert_crm, target_course_id, status, display_order)
    VALUES (1, 'Universal All-Posts Ad', 'universal-ad', 'Join CUET Coaching 2027', 'Special 20% Scholarship', 'Apply Now', 1, 20, 0, 1, 'active', 10);
");

$adFor101 = pepp_public_get_eligible_ad($pdo, 101);
assert_test("1.1: Active ad with target_all_posts=1 is eligible for published post 101",
    $adFor101 !== null && $adFor101['id'] == 1 && $adFor101['headline'] === 'Join CUET Coaching 2027'
);

$adFor102 = pepp_public_get_eligible_ad($pdo, 102);
assert_test("1.2: Active ad with target_all_posts=1 is also eligible for published post 102",
    $adFor102 !== null && $adFor102['id'] == 1
);

// Insert Ad 2: Active, higher display_order, targets ONLY post 102
$pdo->exec("
    INSERT INTO updates_ads (id, title, slug, headline, subheadline, button_text, target_all_posts, popup_delay_seconds, auto_convert_crm, target_course_id, status, display_order)
    VALUES (2, 'Commerce Specific Ad', 'commerce-ad', 'Commerce Entrance Coaching', 'Top Rank Guaranteed', 'Register Here', 0, 10, 0, 2, 'active', 20);

    INSERT INTO updates_ad_posts (ad_id, post_id) VALUES (2, 102);
");

$adFor102Targeted = pepp_public_get_eligible_ad($pdo, 102);
assert_test("1.3: Post 102 resolves higher-priority specific ad (Ad 2, display_order=20)",
    $adFor102Targeted !== null && $adFor102Targeted['id'] == 2 && $adFor102Targeted['headline'] === 'Commerce Entrance Coaching'
);

$adFor101Unchanged = pepp_public_get_eligible_ad($pdo, 101);
assert_test("1.4: Post 101 does NOT receive Ad 2 (not targeted to 101), stays on Ad 1",
    $adFor101Unchanged !== null && $adFor101Unchanged['id'] == 1
);

// Inactive Ads Exclusion
$pdo->exec("
    INSERT INTO updates_ads (id, title, slug, headline, target_all_posts, status, display_order)
    VALUES (3, 'Draft Super Ad', 'draft-super-ad', 'Draft Mega Promo', 1, 'draft', 100);

    INSERT INTO updates_ads (id, title, slug, headline, target_all_posts, status, display_order)
    VALUES (4, 'Paused Ad', 'paused-ad', 'Paused Promo', 1, 'paused', 90);

    INSERT INTO updates_ads (id, title, slug, headline, target_all_posts, status, display_order)
    VALUES (5, 'Archived Ad', 'archived-ad', 'Archived Promo', 1, 'archived', 80);
");

$adFor101Exclusion = pepp_public_get_eligible_ad($pdo, 101);
assert_test("1.5: Draft, paused, and archived ads with higher display_order are strictly excluded",
    $adFor101Exclusion !== null && $adFor101Exclusion['id'] == 1
);

// Popup delay boundary clamping
$pdo->exec("
    INSERT INTO updates_ads (id, title, slug, headline, popup_delay_seconds, target_all_posts, status, display_order)
    VALUES (6, 'Extreme Delay Ad', 'extreme-delay-ad', 'Extreme Delay', 9999, 1, 'active', 50);
");
$adClamped = pepp_public_get_eligible_ad($pdo, 101);
assert_test("1.6: Popup delay is strictly clamped to maximum 600s",
    $adClamped !== null && $adClamped['popup_delay_seconds'] === 600
);
$pdo->exec("DELETE FROM updates_ads WHERE id = 6;");

// Invalid / non-existent post returns null
assert_test("1.7: Non-existent post ID returns null",
    pepp_public_get_eligible_ad($pdo, 99999) === null
);
assert_test("1.8: Non-positive post ID returns null",
    pepp_public_get_eligible_ad($pdo, 0) === null && pepp_public_get_eligible_ad($pdo, -5) === null
);

// ─────────────────────────────────────────────────────────────
// 2. FIELD OPTIONS PARSING & CUSTOM FIELDS RESOLUTION
// ─────────────────────────────────────────────────────────────
echo "\n--- 2. Field Options Parsing & Custom Fields Resolution ---\n";

$jsonOptions = '["Calicut", "Malappuram", "Ernakulam", "Trivandrum"]';
$parsedJson = pepp_public_parse_field_options($jsonOptions);
assert_test("2.1: JSON array options parsed correctly",
    count($parsedJson) === 4 && $parsedJson[0] === 'Calicut' && $parsedJson[3] === 'Trivandrum'
);

$csvOptions = 'B.Com, BBA, BA Economics, BSc Maths';
$parsedCsv = pepp_public_parse_field_options($csvOptions);
assert_test("2.2: Comma-separated options parsed correctly",
    count($parsedCsv) === 4 && $parsedCsv[0] === 'B.Com' && $parsedCsv[1] === 'BBA'
);

$newlineOptions = "Morning Batch\r\nEvening Batch\nWeekend Only";
$parsedNewline = pepp_public_parse_field_options($newlineOptions);
assert_test("2.3: Newline-separated options parsed correctly",
    count($parsedNewline) === 3 && $parsedNewline[0] === 'Morning Batch' && $parsedNewline[2] === 'Weekend Only'
);

assert_test("2.4: Empty or whitespace options return empty array",
    pepp_public_parse_field_options('') === [] && pepp_public_parse_field_options("   \n\r  ") === []
);

// Seed Custom Fields for Ad 2
$pdo->exec("
    INSERT INTO updates_ad_fields (ad_id, field_name, field_label, field_type, is_required, field_options, sort_order)
    VALUES (2, 'student_name', 'Student Name', 'text', 1, NULL, 1);

    INSERT INTO updates_ad_fields (ad_id, field_name, field_label, field_type, is_required, field_options, sort_order)
    VALUES (2, 'whatsapp_number', 'WhatsApp Mobile Number', 'tel', 1, NULL, 2);

    INSERT INTO updates_ad_fields (ad_id, field_name, field_label, field_type, is_required, field_options, sort_order)
    VALUES (2, 'email_address', 'Email Address', 'email', 0, NULL, 3);

    INSERT INTO updates_ad_fields (ad_id, field_name, field_label, field_type, is_required, field_options, sort_order)
    VALUES (2, 'preferred_district', 'Home District', 'select', 1, '[\"Malappuram\", \"Kozhikode\", \"Palakkad\"]', 4);
");

$adWithFields = pepp_public_get_eligible_ad($pdo, 102);
assert_test("2.5: Ad fields retrieved in correct sort_order",
    count($adWithFields['fields']) === 4 &&
    $adWithFields['fields'][0]['field_name'] === 'student_name' &&
    $adWithFields['fields'][1]['field_type'] === 'tel' &&
    $adWithFields['fields'][3]['field_name'] === 'preferred_district'
);
assert_test("2.6: Select field options automatically parsed into options_list",
    isset($adWithFields['fields'][3]['options_list']) &&
    count($adWithFields['fields'][3]['options_list']) === 3 &&
    in_array('Kozhikode', $adWithFields['fields'][3]['options_list'], true)
);

// ─────────────────────────────────────────────────────────────
// 3. PUBLIC CSRF TOKEN GENERATION & VERIFICATION
// ─────────────────────────────────────────────────────────────
echo "\n--- 3. Public CSRF Token Generation & Verification ---\n";

$token1 = pepp_public_generate_ad_token(2, 102);
assert_test("3.1: Token generated is 64-char hexadecimal string",
    strlen($token1) === 64 && ctype_xdigit($token1)
);

assert_test("3.2: Generated token validates successfully for (Ad 2, Post 102)",
    pepp_public_verify_ad_token($token1, 2, 102) === true
);

assert_test("3.3: Token validation fails for mismatched Ad ID (Ad 1, Post 102)",
    pepp_public_verify_ad_token($token1, 1, 102) === false
);

assert_test("3.4: Token validation fails for mismatched Post ID (Ad 2, Post 101)",
    pepp_public_verify_ad_token($token1, 2, 101) === false
);

assert_test("3.5: Empty or tampered token fails verification",
    pepp_public_verify_ad_token('', 2, 102) === false &&
    pepp_public_verify_ad_token(substr($token1, 0, 60) . 'abcd', 2, 102) === false
);

// Verify replay protection (USED token rejection)
$_SESSION['pepp_ad_token_2_102'] = 'USED';
assert_test("3.6: Session token marked as USED is rejected to prevent replay",
    pepp_public_verify_ad_token($token1, 2, 102) === false
);
// Restore fresh token for subsequent tests
$token1 = pepp_public_generate_ad_token(2, 102);

// ─────────────────────────────────────────────────────────────
// 4. RATE LIMITING & ABUSE PREVENTION
// ─────────────────────────────────────────────────────────────
echo "\n--- 4. Rate Limiting & Abuse Prevention ---\n";

$testIpHash = hash('sha256', '192.168.1.100_' . date('Y-m-d') . '_test_salt');

assert_test("4.1: Clean IP has not exceeded rate limit (0 submissions)",
    pepp_public_rate_limit_ad_submission($pdo, $testIpHash) === true
);

// Insert 14 submissions for this IP (within 15 limit)
for ($i = 1; $i <= 14; $i++) {
    $pdo->exec("
        INSERT INTO updates_ad_leads (ad_id, post_id, name, phone, ip_hash, status, created_at)
        VALUES (1, 101, 'Student {$i}', '987654321{$i}', '{$testIpHash}', 'new', datetime('now'))
    ");
}
assert_test("4.2: IP with 14 submissions is still within limit (max 15 allowed for campus/hostel Wi-Fi)",
    pepp_public_rate_limit_ad_submission($pdo, $testIpHash) === true
);

// 15th submission
$pdo->exec("
    INSERT INTO updates_ad_leads (ad_id, post_id, name, phone, ip_hash, status, created_at)
    VALUES (1, 101, 'Student 15', '9876543215', '{$testIpHash}', 'new', datetime('now'))
");
assert_test("4.3: IP with 15 submissions in last 10 minutes hits rate limit (rejected)",
    pepp_public_rate_limit_ad_submission($pdo, $testIpHash) === false
);

// Old submissions (> 15 minutes ago) do not trigger rate limit
$oldIpHash = hash('sha256', '10.0.0.50_' . date('Y-m-d') . '_test_salt');
for ($i = 1; $i <= 16; $i++) {
    $pdo->exec("
        INSERT INTO updates_ad_leads (ad_id, post_id, name, phone, ip_hash, status, created_at)
        VALUES (1, 101, 'Old Student {$i}', '987654320{$i}', '{$oldIpHash}', 'new', datetime('now', '-20 minutes'))
    ");
}
assert_test("4.4: Submissions older than 10-minute window do not count toward rate limit",
    pepp_public_rate_limit_ad_submission($pdo, $oldIpHash) === true
);

// ─────────────────────────────────────────────────────────────
// 5. RECENT DUPLICATE SUBMISSION CHECK
// ─────────────────────────────────────────────────────────────
echo "\n--- 5. Recent Duplicate Submission Check ---\n";

$phone = '9847012345';
assert_test("5.1: New phone number has no duplicate record",
    pepp_public_check_recent_duplicate($pdo, 2, $phone) === false
);

$pdo->exec("
    INSERT INTO updates_ad_leads (ad_id, post_id, name, phone, status, created_at)
    VALUES (2, 102, 'Fathima', '{$phone}', 'new', datetime('now'))
");

assert_test("5.2: Identical student + phone + ad submitted within 5 mins detected as duplicate",
    pepp_public_check_recent_duplicate($pdo, 2, $phone, 'Fathima') === true
);

assert_test("5.3: Same phone on DIFFERENT ad is NOT blocked as duplicate",
    pepp_public_check_recent_duplicate($pdo, 1, $phone, 'Fathima') === false
);

assert_test("5.4: Sibling enquiry on SAME phone but DIFFERENT student name is NOT suppressed",
    pepp_public_check_recent_duplicate($pdo, 2, $phone, 'Amina (Sister)') === false
);

// ─────────────────────────────────────────────────────────────
// 6. LEAD SUBMISSION ENDPOINT (AD-ENQUIRY.PHP) WORKFLOW SIMULATION
// ─────────────────────────────────────────────────────────────
echo "\n--- 6. Lead Submission Endpoint (ad-enquiry.php) Workflow Simulation ---\n";

// 6.1 Anti-spam Honeypot
$resBot = pepp_public_submit_ad_lead($pdo, ['hp_website' => 'http://spam-link.ru']);
assert_test("6.1: Honeypot submission returns simulated success without error",
    ($resBot['http_code'] ?? 0) === 200 && ($resBot['ok'] ?? false) === true
);

// 6.2 Anti-spam Time Threshold
$resFast = pepp_public_submit_ad_lead($pdo, ['_form_time' => time(), 'ad_id' => 2, 'post_id' => 102]);
assert_test("6.2: Instant bot submission (< 1 sec) returns 429 Too Fast",
    ($resFast['http_code'] ?? 0) === 429
);

// 6.3 Missing / Invalid CSRF
$resCsrf = pepp_public_submit_ad_lead($pdo, [
    'ad_id' => 2,
    'post_id' => 102,
    'csrf_token' => 'invalid_csrf_token_value',
    '_form_time' => time() - 5
]);
assert_test("6.3: Invalid CSRF token returns 403 Forbidden",
    ($resCsrf['http_code'] ?? 0) === 403
);

// 6.4 Missing required field
$validToken = pepp_public_generate_ad_token(2, 102);
$resMissing = pepp_public_submit_ad_lead($pdo, [
    'ad_id' => 2,
    'post_id' => 102,
    'csrf_token' => $validToken,
    '_form_time' => time() - 5,
    'student_name' => '', // required
    'whatsapp_number' => '9847111222',
    'preferred_district' => 'Kozhikode',
]);
assert_test("6.4: Validation correctly catches missing required field (student_name) with 422",
    ($resMissing['http_code'] ?? 0) === 422
);

// 6.5 Invalid phone format check
$resBadPhone = pepp_public_submit_ad_lead($pdo, [
    'ad_id' => 2,
    'post_id' => 102,
    'csrf_token' => $validToken,
    '_form_time' => time() - 5,
    'student_name' => 'Aswin',
    'whatsapp_number' => '123', // < 7 digits
    'preferred_district' => 'Kozhikode',
]);
assert_test("6.5: Invalid short phone format is detected and rejected with 422",
    ($resBadPhone['http_code'] ?? 0) === 422
);

// 6.6 Invalid select option check
$resBadOption = pepp_public_submit_ad_lead($pdo, [
    'ad_id' => 2,
    'post_id' => 102,
    'csrf_token' => $validToken,
    '_form_time' => time() - 5,
    'student_name' => 'Aswin',
    'whatsapp_number' => '9847111222',
    'preferred_district' => 'NonExistentDistrict',
]);
assert_test("6.6: Unlisted option in select dropdown is detected and rejected with 422",
    ($resBadOption['http_code'] ?? 0) === 422
);

// 6.7 Mismatched ad / post targeting check
$resMismatched = pepp_public_submit_ad_lead($pdo, [
    'ad_id' => 2,
    'post_id' => 101, // Ad 2 only targets 102
    'csrf_token' => pepp_public_generate_ad_token(2, 101),
    '_form_time' => time() - 5,
    'student_name' => 'Aswin',
    'whatsapp_number' => '9847111222',
    'preferred_district' => 'Kozhikode',
]);
assert_test("6.7: Non-targeted post submission rejected with 400",
    ($resMismatched['http_code'] ?? 0) === 400
);

// 6.8 Successful Lead Persistence
$goodData = [
    'ad_id'              => 2,
    'post_id'            => 102,
    'csrf_token'         => $validToken,
    '_form_time'         => time() - 5,
    'student_name'       => 'Muhammed Nihal',
    'whatsapp_number'    => '+91 9847 999 888',
    'email_address'      => 'nihal.student@gmail.com',
    'preferred_district' => 'Kozhikode',
];
$resGood = pepp_public_submit_ad_lead($pdo, $goodData, '127.0.0.1');
assert_test("6.8: Valid lead submission via pepp_public_submit_ad_lead succeeds with 200",
    ($resGood['http_code'] ?? 0) === 200 && ($resGood['ok'] ?? false) === true && !empty($resGood['lead_id'])
);
$leadId = (int)$resGood['lead_id'];


// Verify inserted lead entity integrity
$leadRow = $pdo->query("SELECT * FROM updates_ad_leads WHERE id = {$leadId}")->fetch(PDO::FETCH_ASSOC);
assert_test("6.9: Lead status is strictly initialized to 'new'",
    $leadRow['status'] === 'new'
);
assert_test("6.10: Originating ad_id (2) and post_id (102) preserved",
    (int)$leadRow['ad_id'] === 2 && (int)$leadRow['post_id'] === 102
);
assert_test("6.11: Mapped target_course_id (2) preserved from ad configuration",
    (int)$leadRow['course_id'] === 2
);
assert_test("6.12: Student name and normalized phone stored accurately",
    $leadRow['name'] === 'Muhammed Nihal' && $leadRow['phone'] === '+919847999888'
);
assert_test("6.13: Submitted JSON contains all fields including email and district",
    strpos($leadRow['submitted_data'], 'nihal.student@gmail.com') !== false &&
    strpos($leadRow['submitted_data'], 'Kozhikode') !== false
);

// ─────────────────────────────────────────────────────────────
// 7. ADMIN VISIBILITY & REPORTING INTEGRATION
// ─────────────────────────────────────────────────────────────
echo "\n--- 7. Admin Visibility & Reporting Integration ---\n";

require_once __DIR__ . '/includes/pepp_updates_helper.php';

$leadStats = pepp_updates_get_ad_lead_stats($pdo);
assert_test("7.1: pepp_updates_get_ad_lead_stats counts captured leads",
    $leadStats['total'] >= 1 && $leadStats['new'] >= 1
);

$adminLeads = pepp_updates_get_ad_leads($pdo, ['search' => 'Muhammed Nihal']);
assert_test("7.2: Captured lead appears in admin leads list by search",
    count($adminLeads['leads']) === 1 && $adminLeads['leads'][0]['id'] == $leadId
);

assert_test("7.3: Admin lead row includes originating ad title ('Commerce Specific Ad')",
    ($adminLeads['leads'][0]['ad_title'] ?? '') === 'Commerce Specific Ad'
);

assert_test("7.4: Admin lead row includes originating post title ('Kerala Plus Two Exam Timetable 2027')",
    ($adminLeads['leads'][0]['post_title'] ?? '') === 'Kerala Plus Two Exam Timetable 2027'
);

assert_test("7.5: Admin lead row includes mapped course name ('Kerala Plus Two Commerce')",
    ($adminLeads['leads'][0]['mapped_course_name'] ?? '') === 'Kerala Plus Two Commerce'
);

// ─────────────────────────────────────────────────────────────
// 8. CRM AUTO-CONVERSION SAFETY VERIFICATION
// ─────────────────────────────────────────────────────────────
echo "\n--- 8. CRM Auto-Conversion Safety Verification ---\n";

$globalSetting = pepp_updates_get_setting($pdo, 'ad_leads_auto_convert_enabled', '0');
assert_test("8.1: Global setting ad_leads_auto_convert_enabled remains strictly '0' (OFF)",
    $globalSetting === '0'
);

$adRow = $pdo->query("SELECT auto_convert_crm FROM updates_ads WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
assert_test("8.2: Ad auto_convert_crm flag remains 0 (OFF)",
    (int)$adRow['auto_convert_crm'] === 0
);

assert_test("8.3: Lead in updates_ad_leads has converted_lead_id IS NULL (no CRM leak)",
    $leadRow['converted_lead_id'] === null
);

$canConvert = pepp_updates_is_auto_convert_allowed($pdo, $adRow);
assert_test("8.4: pepp_updates_is_auto_convert_allowed returns FALSE (auto-convert blocked)",
    $canConvert === false
);

// ─────────────────────────────────────────────────────────────
// 9. CLIENT-SIDE ACCESSIBILITY & VISIBILITY TIMER AUDIT
// ─────────────────────────────────────────────────────────────
echo "\n--- 9. Client-Side Accessibility & Visibility Timer Audit ---\n";

$updateContent = file_get_contents(__DIR__ . '/public_updates/update.php');
$mainJsContent = file_get_contents(__DIR__ . '/public_updates/assets/js/main.js');
$styleCssContent = file_get_contents(__DIR__ . '/public_updates/assets/css/style.css');

assert_test("9.1: update.php renders accessible dialog attributes (role='dialog', aria-modal='true')",
    strpos($updateContent, 'role="dialog"') !== false &&
    strpos($updateContent, 'aria-modal="true"') !== false &&
    strpos($updateContent, 'aria-labelledby="pepp-ad-headline"') !== false
);

assert_test("9.2: update.php contains honeypot input (hp_website)",
    strpos($updateContent, 'name="hp_website"') !== false
);

assert_test("9.3: update.php contains scoped CSRF token input",
    strpos($updateContent, 'name="csrf_token"') !== false
);

assert_test("9.4: main.js uses Page Visibility API (!document.hidden) to pause delay timer",
    strpos($mainJsContent, '!document.hidden') !== false
);

assert_test("9.5: main.js implements sessionStorage suppression (pepp_ad_seen_)",
    strpos($mainJsContent, 'sessionStorage.getItem(sessionKey)') !== false &&
    strpos($mainJsContent, 'sessionStorage.setItem(sessionKey') !== false
);

assert_test("9.6: main.js implements keyboard ESC close and TAB focus trapping",
    strpos($mainJsContent, "e.key === 'Escape'") !== false &&
    strpos($mainJsContent, "e.key === 'Tab'") !== false
);

assert_test("9.7: style.css contains mobile responsive breakpoint for popup dialog (@media (max-width: 480px))",
    strpos($styleCssContent, '.pepp-ad-modal') !== false &&
    strpos($styleCssContent, '@media (max-width: 480px)') !== false
);

// ─────────────────────────────────────────────────────────────
// 10. BACKWARD COMPATIBILITY & BOUNDARY INTEGRATION
// ─────────────────────────────────────────────────────────────
echo "\n--- 10. Backward Compatibility & Boundary Integrity ---\n";

assert_test("10.1: update.php preserves boundary test requirement (does not contain literal 'pepp-ad-popup')",
    strpos($updateContent, 'pepp-ad-popup') === false
);

assert_test("10.2: update.php preserves boundary test requirement (does not query 'updates_ads' table directly)",
    strpos($updateContent, 'updates_ads') === false
);

assert_test("10.3: WhatsApp Account 1 and Account 3 settings remain untouched",
    strpos($updateContent, '1099020233033644') === false &&
    strpos($mainJsContent, '1099020233033644') === false
);

echo "\n============================================================\n";
echo "PHASE 3 AUDIT SUMMARY: {$totalPass} PASSED, {$totalFail} FAILED\n";
echo "============================================================\n";

if ($totalFail > 0) {
    exit(1);
}
exit(0);
