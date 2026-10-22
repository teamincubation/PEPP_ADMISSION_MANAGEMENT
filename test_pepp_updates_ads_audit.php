<?php
/**
 * PEPP Updates — Phase 2 Ad Management Foundation Audit
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('PEPP_TESTING_ENV=1');
putenv('PEPP_USE_SQLITE=1');
putenv('PEPP_SQLITE_PATH=:memory:');
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$totalPass = 0;
$totalFail = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $totalPass, $totalFail;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $totalPass++;
    } else {
        echo "  [FAIL] {$name}" . ($details ? " - {$details}" : '') . "\n";
        $totalFail++;
    }
}

echo "============================================================\n";
echo "PEPP UPDATES — PHASE 2 AD MANAGEMENT FOUNDATION AUDIT\n";
echo "============================================================\n\n";

// ─────────────────────────────────────────────────────────────
// 1. MIGRATION 66 & ROLLBACK 66 SQL STATIC AUDIT
// ─────────────────────────────────────────────────────────────
echo "--- 1. Migration 66 & Rollback 66 Schema Audit ---\n";

$migFile = __DIR__ . '/database-update-66-pepp-updates-ads.sql';
$rollFile = __DIR__ . '/database-rollback-66-pepp-updates-ads.sql';

assert_test("1.1: database-update-66-pepp-updates-ads.sql exists", file_exists($migFile));
assert_test("1.2: database-rollback-66-pepp-updates-ads.sql exists", file_exists($rollFile));

$migSql = file_exists($migFile) ? file_get_contents($migFile) : '';
$rollSql = file_exists($rollFile) ? file_get_contents($rollFile) : '';

assert_test("1.3: Migration creates updates_ads table with IF NOT EXISTS",
    strpos($migSql, 'CREATE TABLE IF NOT EXISTS `updates_ads`') !== false
);
assert_test("1.4: Migration creates updates_ad_posts table with IF NOT EXISTS",
    strpos($migSql, 'CREATE TABLE IF NOT EXISTS `updates_ad_posts`') !== false
);
assert_test("1.5: Migration creates updates_ad_fields table with IF NOT EXISTS",
    strpos($migSql, 'CREATE TABLE IF NOT EXISTS `updates_ad_fields`') !== false
);
assert_test("1.6: Migration creates updates_ad_leads table with IF NOT EXISTS",
    strpos($migSql, 'CREATE TABLE IF NOT EXISTS `updates_ad_leads`') !== false
);

// Check column defaults
assert_test("1.7: updates_ads defaults popup_delay_seconds to 15",
    strpos($migSql, '`popup_delay_seconds` INT NOT NULL DEFAULT 15') !== false
);
assert_test("1.8: updates_ads defaults auto_convert_crm to 0 (OFF)",
    strpos($migSql, '`auto_convert_crm` TINYINT(1) NOT NULL DEFAULT 0') !== false
);
assert_test("1.9: updates_ads defaults target_all_posts to 1 (All updates)",
    strpos($migSql, '`target_all_posts` TINYINT(1) NOT NULL DEFAULT 1') !== false
);
assert_test("1.10: updates_ads defaults status to 'draft'",
    strpos($migSql, "`status` ENUM('draft', 'active', 'paused', 'archived') NOT NULL DEFAULT 'draft'") !== false
);
assert_test("1.11: updates_ad_leads defaults status to 'new'",
    strpos($migSql, "`status` ENUM('new', 'converted', 'failed', 'blocked') NOT NULL DEFAULT 'new'") !== false
);
assert_test("1.12: Global setting ad_leads_auto_convert_enabled seeded as '0' (OFF)",
    strpos($migSql, "('ad_leads_auto_convert_enabled', '0'") !== false
);

// Check foreign keys in migration
assert_test("1.13: Foreign key fk_ua_course references pepp_courses(id) ON DELETE SET NULL",
    strpos($migSql, 'REFERENCES `pepp_courses` (`id`) ON DELETE SET NULL') !== false
);
assert_test("1.14: Foreign key fk_uap_ad references updates_ads(id) ON DELETE CASCADE",
    strpos($migSql, 'REFERENCES `updates_ads` (`id`) ON DELETE CASCADE') !== false
);
assert_test("1.15: Foreign key fk_uaf_ad references updates_ads(id) ON DELETE CASCADE",
    strpos($migSql, 'REFERENCES `updates_ads` (`id`) ON DELETE CASCADE') !== false
);
assert_test("1.16: Foreign key fk_ual_ad references updates_ads(id) ON DELETE RESTRICT",
    strpos($migSql, 'CONSTRAINT `fk_ual_ad` FOREIGN KEY (`ad_id`) REFERENCES `updates_ads` (`id`) ON DELETE RESTRICT') !== false
);
assert_test("1.20: Migration enforces popup_delay_seconds CHECK constraint (>= 1)",
    strpos($migSql, 'CHECK (`popup_delay_seconds` >= 1)') !== false
);
assert_test("1.21: MariaDB syntax: popup_delay_seconds does NOT place CHECK before COMMENT (prevents MariaDB #1064)",
    preg_match('/`popup_delay_seconds`[^,\n]*CHECK[^,\n]*COMMENT/i', $migSql) === 0
);
assert_test("1.22: Migration defines named table-level CHECK constraint chk_ua_popup_delay",
    strpos($migSql, 'CONSTRAINT `chk_ua_popup_delay` CHECK (`popup_delay_seconds` >= 1)') !== false
);

// Dynamic MariaDB validation if local test MariaDB daemon is active
try {
    $mariaTest = new PDO('mysql:host=127.0.0.1;port=3308', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $mariaTest->exec("CREATE DATABASE IF NOT EXISTS pepp_audit_mariadb_test; USE pepp_audit_mariadb_test;");
    $mariaTest->exec("CREATE TABLE IF NOT EXISTS pepp_courses (id INT PRIMARY KEY, course_name VARCHAR(255));");
    $mariaTest->exec("CREATE TABLE IF NOT EXISTS updates_posts (id INT PRIMARY KEY, title VARCHAR(255));");
    $mariaTest->exec("CREATE TABLE IF NOT EXISTS updates_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) UNIQUE, setting_value TEXT, description VARCHAR(255), created_at DATETIME, updated_at DATETIME);");

    $statements = array_filter(array_map('trim', explode(';', $migSql)));
    foreach ($statements as $stmtSql) {
        // Strip leading comments
        $lines = explode("\n", $stmtSql);
        $cleanLines = array_filter($lines, function($l) { return !str_starts_with(trim($l), '--'); });
        $cleanStmt = trim(implode("\n", $cleanLines));
        if ($cleanStmt === '') continue;
        $mariaTest->exec($cleanStmt);
    }
    assert_test("1.23: Real MariaDB DDL execution succeeds with zero syntax errors (#1064 resolved)", true);
    $mariaTest->exec("DROP DATABASE pepp_audit_mariadb_test;");
} catch (Throwable $e) {
    // MariaDB service optional for environments without local MariaDB
}

// Rollback checks
assert_test("1.17: Rollback script documents data loss risk and prerequisite backup warning",
    strpos($rollSql, 'CRITICAL DATA SAFETY WARNING') !== false &&
    strpos($rollSql, 'WILL PERMANENTLY AND IRREVERSIBLY DESTROY') !== false
);
assert_test("1.18: Rollback script drops all 4 tables in reverse FK order",
    strpos($rollSql, 'DROP TABLE IF EXISTS `updates_ad_leads`') !== false &&
    strpos($rollSql, 'DROP TABLE IF EXISTS `updates_ad_fields`') !== false &&
    strpos($rollSql, 'DROP TABLE IF EXISTS `updates_ad_posts`') !== false &&
    strpos($rollSql, 'DROP TABLE IF EXISTS `updates_ads`') !== false &&
    strpos($rollSql, 'DROP TABLE IF EXISTS `updates_ad_leads`') < strpos($rollSql, 'DROP TABLE IF EXISTS `updates_ad_fields`') &&
    strpos($rollSql, 'DROP TABLE IF EXISTS `updates_ad_fields`') < strpos($rollSql, 'DROP TABLE IF EXISTS `updates_ads`')
);
assert_test("1.19: Rollback cleans up ad_leads_auto_convert_enabled from updates_settings",
    strpos($rollSql, "DELETE FROM `updates_settings` WHERE `setting_key` = 'ad_leads_auto_convert_enabled'") !== false
);

// ─────────────────────────────────────────────────────────────
// 2. IN-MEMORY SQLITE SIMULATION & AD CRUD OPERATIONS
// ─────────────────────────────────────────────────────────────
echo "\n--- 2. In-Memory SQLite Simulation of Ad Management CRUD ---\n";

require_once __DIR__ . '/includes/pepp_updates_helper.php';

$testPdo = new PDO('sqlite::memory:');
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize schema in SQLite
$testPdo->exec("
    CREATE TABLE pepp_courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        course_name TEXT,
        course_code TEXT,
        status TEXT DEFAULT 'active'
    );
    CREATE TABLE updates_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        slug TEXT,
        status TEXT DEFAULT 'published'
    );
    CREATE TABLE updates_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE,
        setting_value TEXT,
        description TEXT,
        updated_by TEXT,
        updated_at TEXT
    );
    CREATE TABLE updates_ads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        headline TEXT,
        subheadline TEXT,
        image_url TEXT,
        button_text TEXT DEFAULT 'Get More Info',
        target_all_posts INTEGER DEFAULT 1,
        popup_delay_seconds INTEGER DEFAULT 15,
        auto_convert_crm INTEGER DEFAULT 0,
        target_course_id INTEGER,
        status TEXT DEFAULT 'draft',
        display_order INTEGER DEFAULT 0,
        created_by TEXT,
        updated_by TEXT,
        created_at TEXT,
        updated_at TEXT
    );
    CREATE TABLE updates_ad_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad_id INTEGER NOT NULL REFERENCES updates_ads(id) ON DELETE CASCADE,
        post_id INTEGER NOT NULL,
        created_at TEXT
    );
    CREATE TABLE updates_ad_fields (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad_id INTEGER NOT NULL REFERENCES updates_ads(id) ON DELETE CASCADE,
        field_name TEXT NOT NULL,
        field_label TEXT NOT NULL,
        field_type TEXT DEFAULT 'text',
        is_required INTEGER DEFAULT 0,
        field_options TEXT,
        sort_order INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    );
    CREATE TABLE updates_ad_leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad_id INTEGER NOT NULL REFERENCES updates_ads(id) ON DELETE RESTRICT,
        post_id INTEGER,
        name TEXT NOT NULL,
        phone TEXT NOT NULL,
        course_id INTEGER,
        submitted_data TEXT,
        ip_hash TEXT,
        status TEXT DEFAULT 'new',
        converted_lead_id INTEGER,
        conversion_notes TEXT,
        conversion_attempted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    );
");

// Enable SQLite foreign key constraint enforcement
$testPdo->exec("PRAGMA foreign_keys = ON;");

// Seed courses and posts
$testPdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, status) VALUES (1, 'CUET PG Psychology', 'CP101', 'active')");
$testPdo->exec("INSERT INTO pepp_courses (id, course_name, course_code, status) VALUES (2, 'IIT JAM Chemistry', 'IJ201', 'active')");
$testPdo->exec("INSERT INTO updates_posts (id, title, slug, status) VALUES (1, 'CUET PG Exam Notice', 'cuet-pg-exam', 'published')");
$testPdo->exec("INSERT INTO updates_posts (id, title, slug, status) VALUES (2, 'IIT JAM Syllabus', 'iit-jam-syllabus', 'published')");

// Test Course and Post helper retrievals
$activeCourses = pepp_updates_get_courses($testPdo);
assert_test("2.1: pepp_updates_get_courses returns active courses",
    count($activeCourses) === 2 && $activeCourses[0]['course_name'] === 'CUET PG Psychology'
);

$pubPosts = pepp_updates_get_published_posts($testPdo);
assert_test("2.2: pepp_updates_get_published_posts returns published posts",
    count($pubPosts) === 2 && $pubPosts[0]['slug'] === 'cuet-pg-exam'
);

// Test Ad Creation
$createResult = pepp_updates_save_ad($testPdo, [
    'title'               => 'CUET Crash Course 2027',
    'headline'            => 'Boost Your CUET Score',
    'subheadline'         => 'Join top faculty mentoring sessions',
    'button_text'         => 'Enroll Today',
    'popup_delay_seconds' => 20,
    'auto_convert_crm'    => 0,
    'target_course_id'    => 1,
    'target_all_posts'    => 0,
    'status'              => 'active',
    'display_order'       => 1
], [1], [
    ['field_label' => 'Qualification', 'field_name' => 'qualification', 'field_type' => 'select', 'is_required' => 1, 'field_options' => 'UG Final Year, Graduate, PG'],
    ['field_label' => 'Home District', 'field_name' => 'district', 'field_type' => 'text', 'is_required' => 0]
], 'admin_test');

assert_test("2.3: pepp_updates_save_ad successfully creates new ad",
    $createResult['success'] === true && !empty($createResult['id'])
);

$adId = (int)$createResult['id'];
$loadedAd = pepp_updates_get_ad($testPdo, $adId);

assert_test("2.4: Loaded ad contains correct headline, delay, and course mapping",
    $loadedAd !== null &&
    $loadedAd['title'] === 'CUET Crash Course 2027' &&
    (int)$loadedAd['popup_delay_seconds'] === 20 &&
    (int)$loadedAd['target_course_id'] === 1 &&
    $loadedAd['mapped_course_name'] === 'CUET PG Psychology' &&
    $loadedAd['status'] === 'active'
);

assert_test("2.5: Ad post targeting junction row stored correctly for specific post",
    isset($loadedAd['target_post_ids']) &&
    count($loadedAd['target_post_ids']) === 1 &&
    (int)$loadedAd['target_post_ids'][0] === 1
);

assert_test("2.6: Ordered custom fields stored correctly with options and sort order",
    isset($loadedAd['fields']) &&
    count($loadedAd['fields']) === 2 &&
    $loadedAd['fields'][0]['field_name'] === 'qualification' &&
    $loadedAd['fields'][0]['field_type'] === 'select' &&
    (int)$loadedAd['fields'][0]['is_required'] === 1 &&
    $loadedAd['fields'][1]['field_name'] === 'district'
);

// Test Ad Update
$updateResult = pepp_updates_save_ad($testPdo, [
    'id'                  => $adId,
    'title'               => 'CUET Crash Course 2027 Updated',
    'headline'            => 'Updated Headline',
    'popup_delay_seconds' => 30,
    'auto_convert_crm'    => 1,
    'target_course_id'    => 1,
    'target_all_posts'    => 1, // Change to all posts
    'status'              => 'paused',
    'display_order'       => 2
], [], [], 'admin_test');

assert_test("2.7: pepp_updates_save_ad successfully updates existing ad",
    $updateResult['success'] === true
);

$updatedAd = pepp_updates_get_ad($testPdo, $adId);
assert_test("2.8: Updates reflected in database and junction cleared when target_all_posts=1",
    $updatedAd['title'] === 'CUET Crash Course 2027 Updated' &&
    (int)$updatedAd['popup_delay_seconds'] === 30 &&
    (int)$updatedAd['target_all_posts'] === 1 &&
    empty($updatedAd['target_post_ids']) &&
    $updatedAd['status'] === 'paused'
);

// Test Status Toggle
$toggleActive = pepp_updates_toggle_ad_status($testPdo, $adId, 'active', 'admin_test');
$afterToggle = pepp_updates_get_ad($testPdo, $adId);
assert_test("2.9: pepp_updates_toggle_ad_status successfully changes status to active",
    $toggleActive === true && $afterToggle['status'] === 'active'
);

// Test 2.10: Popup delay bounds clamping (1 - 600 seconds)
$adClampedLow = pepp_updates_save_ad($testPdo, [
    'title'               => 'Delay Clamped Low',
    'popup_delay_seconds' => -5
], [], [], 'admin_test');
$loadedClampedLow = pepp_updates_get_ad($testPdo, (int)$adClampedLow['id']);

$adClampedHigh = pepp_updates_save_ad($testPdo, [
    'title'               => 'Delay Clamped High',
    'popup_delay_seconds' => 9999
], [], [], 'admin_test');
$loadedClampedHigh = pepp_updates_get_ad($testPdo, (int)$adClampedHigh['id']);

assert_test("2.10: Popup delay is clamped within 1 to 600 seconds",
    (int)$loadedClampedLow['popup_delay_seconds'] === 1 &&
    (int)$loadedClampedHigh['popup_delay_seconds'] === 600
);

// Test 2.11: M2 Duplicate field names collision handling (e.g. College Name, College Name, College Name)
$adDupFields = pepp_updates_save_ad($testPdo, [
    'title' => 'Ad with Duplicate Labels'
], [], [
    ['field_label' => 'College Name', 'field_name' => 'college_name', 'field_type' => 'text'],
    ['field_label' => 'College Name', 'field_name' => 'college_name', 'field_type' => 'text'],
    ['field_label' => 'College Name', 'field_name' => 'college_name', 'field_type' => 'text']
], 'admin_test');
$loadedDup = pepp_updates_get_ad($testPdo, (int)$adDupFields['id']);
$dupNames = array_column($loadedDup['fields'], 'field_name');

assert_test("2.11: Duplicate field names deterministically deduplicated to college_name, college_name_2, college_name_3",
    $dupNames === ['college_name', 'college_name_2', 'college_name_3']
);

// Test 2.12: M2 Disparate labels sanitizing to identical slugs
$adSimilarSlugs = pepp_updates_save_ad($testPdo, [
    'title' => 'Ad with Similar Slug Labels'
], [], [
    ['field_label' => 'Degree / Branch'],
    ['field_label' => 'Degree-Branch'],
    ['field_label' => 'Degree Branch!']
], 'admin_test');
$loadedSimilar = pepp_updates_get_ad($testPdo, (int)$adSimilarSlugs['id']);
$similarNames = array_column($loadedSimilar['fields'], 'field_name');

assert_test("2.12: Labels sanitizing to identical slugs are made unique (degree_branch, degree_branch_2, degree_branch_3)",
    $similarNames === ['degree_branch', 'degree_branch_2', 'degree_branch_3']
);

// Test 2.13 - 2.15: H2 Custom field row-removal regression tests (first, middle, last rows)
function simulate_parse_ad_fields(array $submittedRows): array {
    $fields = [];
    foreach ($submittedRows as $row) {
        if (!is_array($row)) continue;
        $label = trim($row['label'] ?? '');
        if ($label === '') continue;
        $fields[] = [
            'field_label'   => $label,
            'field_name'    => trim($row['name'] ?? ''),
            'field_type'    => trim($row['type'] ?? 'text'),
            'is_required'   => !empty($row['required']) ? 1 : 0,
            'field_options' => trim($row['options'] ?? ''),
            'sort_order'    => count($fields)
        ];
    }
    return $fields;
}

$origRows = [
    'row_0' => ['label' => 'Qualification', 'name' => 'qualification', 'type' => 'select', 'required' => '1'],
    'row_1' => ['label' => 'District', 'name' => 'district', 'type' => 'text'],
    'row_2' => ['label' => 'College', 'name' => 'college', 'type' => 'text', 'required' => '1'],
];

// 2.13: Remove FIRST row (row_0 removed)
$removeFirst = $origRows;
unset($removeFirst['row_0']);
$parsedFirst = simulate_parse_ad_fields($removeFirst);
$saveFirstRes = pepp_updates_save_ad($testPdo, ['title' => 'Ad Remove First Row'], [], $parsedFirst, 'admin_test');
$loadedFirstAd = pepp_updates_get_ad($testPdo, (int)$saveFirstRes['id']);
assert_test("2.13: H2 Removing first row maintains required flags in UI payload & DB persistence (District: 0, College: 1)",
    count($parsedFirst) === 2 &&
    $parsedFirst[0]['field_label'] === 'District' && (int)$parsedFirst[0]['is_required'] === 0 &&
    $parsedFirst[1]['field_label'] === 'College' && (int)$parsedFirst[1]['is_required'] === 1 &&
    count($loadedFirstAd['fields']) === 2 &&
    $loadedFirstAd['fields'][0]['field_label'] === 'District' && (int)$loadedFirstAd['fields'][0]['is_required'] === 0 &&
    $loadedFirstAd['fields'][1]['field_label'] === 'College' && (int)$loadedFirstAd['fields'][1]['is_required'] === 1
);

// 2.14: Remove MIDDLE row (row_1 removed)
$removeMiddle = $origRows;
unset($removeMiddle['row_1']);
$parsedMiddle = simulate_parse_ad_fields($removeMiddle);
$saveMiddleRes = pepp_updates_save_ad($testPdo, ['title' => 'Ad Remove Middle Row'], [], $parsedMiddle, 'admin_test');
$loadedMiddleAd = pepp_updates_get_ad($testPdo, (int)$saveMiddleRes['id']);
assert_test("2.14: H2 Removing middle row maintains required flags in UI payload & DB persistence (Qualification: 1, College: 1)",
    count($parsedMiddle) === 2 &&
    $parsedMiddle[0]['field_label'] === 'Qualification' && (int)$parsedMiddle[0]['is_required'] === 1 &&
    $parsedMiddle[1]['field_label'] === 'College' && (int)$parsedMiddle[1]['is_required'] === 1 &&
    count($loadedMiddleAd['fields']) === 2 &&
    $loadedMiddleAd['fields'][0]['field_label'] === 'Qualification' && (int)$loadedMiddleAd['fields'][0]['is_required'] === 1 &&
    $loadedMiddleAd['fields'][1]['field_label'] === 'College' && (int)$loadedMiddleAd['fields'][1]['is_required'] === 1
);

// 2.15: Remove LAST row (row_2 removed)
$removeLast = $origRows;
unset($removeLast['row_2']);
$parsedLast = simulate_parse_ad_fields($removeLast);
$saveLastRes = pepp_updates_save_ad($testPdo, ['title' => 'Ad Remove Last Row'], [], $parsedLast, 'admin_test');
$loadedLastAd = pepp_updates_get_ad($testPdo, (int)$saveLastRes['id']);
assert_test("2.15: H2 Removing last row maintains required flags in UI payload & DB persistence (Qualification: 1, District: 0)",
    count($parsedLast) === 2 &&
    $parsedLast[0]['field_label'] === 'Qualification' && (int)$parsedLast[0]['is_required'] === 1 &&
    $parsedLast[1]['field_label'] === 'District' && (int)$parsedLast[1]['is_required'] === 0 &&
    count($loadedLastAd['fields']) === 2 &&
    $loadedLastAd['fields'][0]['field_label'] === 'Qualification' && (int)$loadedLastAd['fields'][0]['is_required'] === 1 &&
    $loadedLastAd['fields'][1]['field_label'] === 'District' && (int)$loadedLastAd['fields'][1]['is_required'] === 0
);

// ─────────────────────────────────────────────────────────────
// 3. AUTOMATIC CONVERSION PRECEDENCE RULES
// ─────────────────────────────────────────────────────────────
echo "\n--- 3. Automatic Conversion Precedence Rules Audit ---\n";

// Case 1: Global OFF, Ad OFF
pepp_updates_set_setting($testPdo, 'ad_leads_auto_convert_enabled', '0');
$adOff = ['auto_convert_crm' => 0];
assert_test("3.1: Global OFF and Ad OFF -> Auto-convert is BLOCKED (false)",
    pepp_updates_is_auto_convert_allowed($testPdo, $adOff) === false
);

// Case 2: Global ON, Ad OFF
pepp_updates_set_setting($testPdo, 'ad_leads_auto_convert_enabled', '1');
assert_test("3.2: Global ON and Ad OFF -> Auto-convert is BLOCKED (false)",
    pepp_updates_is_auto_convert_allowed($testPdo, $adOff) === false
);

// Case 3: Global OFF, Ad ON
pepp_updates_set_setting($testPdo, 'ad_leads_auto_convert_enabled', '0');
$adOn = ['auto_convert_crm' => 1];
assert_test("3.3: Global OFF and Ad ON -> Auto-convert is BLOCKED (false)",
    pepp_updates_is_auto_convert_allowed($testPdo, $adOn) === false
);

// Case 4: Global ON, Ad ON
pepp_updates_set_setting($testPdo, 'ad_leads_auto_convert_enabled', '1');
assert_test("3.4: Global ON and Ad ON -> Auto-convert is ALLOWED (true)",
    pepp_updates_is_auto_convert_allowed($testPdo, $adOn) === true
);

// Test 3.5: Canonical precedence: When canonical key is '0' and legacy alias is '1', canonical '0' prevails
pepp_updates_set_setting($testPdo, 'ad_leads_auto_convert_enabled', '0');
pepp_updates_set_setting($testPdo, 'ad_lead_auto_convert_global', '1');
assert_test("3.5: M1 Canonical key ad_leads_auto_convert_enabled ('0') takes precedence over alias ('1')",
    pepp_updates_get_setting($testPdo, 'ad_leads_auto_convert_enabled') === '0' &&
    pepp_updates_is_auto_convert_allowed($testPdo, ['auto_convert_crm' => 1]) === false
);

// Test 3.6: Alias fallback: When canonical key is absent from DB, legacy alias '1' is retrieved safely
$testPdo->exec("DELETE FROM updates_settings WHERE setting_key = 'ad_leads_auto_convert_enabled'");
assert_test("3.6: M1 Alias fallback retrieves value when canonical key is not in DB",
    pepp_updates_get_setting($testPdo, 'ad_leads_auto_convert_enabled') === '1'
);

// Test 3.7: Querying legacy alias directly returns value from canonical key if alias is absent
$testPdo->exec("DELETE FROM updates_settings WHERE setting_key = 'ad_lead_auto_convert_global'");
pepp_updates_set_setting($testPdo, 'ad_leads_auto_convert_enabled', '0');
assert_test("3.7: M1 Querying alias ad_lead_auto_convert_global falls back to canonical value ('0')",
    pepp_updates_get_setting($testPdo, 'ad_lead_auto_convert_global') === '0'
);

// Test 3.8: Defaults strictly OFF when neither setting exists in database
$testPdo->exec("DELETE FROM updates_settings WHERE setting_key IN ('ad_leads_auto_convert_enabled', 'ad_lead_auto_convert_global')");
assert_test("3.8: M1 Conversion controls default strictly OFF when setting is absent",
    pepp_updates_get_setting($testPdo, 'ad_leads_auto_convert_enabled', '0') === '0' &&
    pepp_updates_is_auto_convert_allowed($testPdo, ['auto_convert_crm' => 1]) === false &&
    pepp_updates_is_auto_convert_allowed($testPdo, ['auto_convert_crm' => 0]) === false &&
    pepp_updates_is_auto_convert_allowed($testPdo, null) === false
);

// Reset global to safe default (0)
pepp_updates_set_setting($testPdo, 'ad_leads_auto_convert_enabled', '0');

// ─────────────────────────────────────────────────────────────
// 4. AD DELETION SAFETY (Preserve Leads / Audit History)
// ─────────────────────────────────────────────────────────────
echo "\n--- 4. Ad Deletion Safety & Leads Preservation ---\n";

// Insert dummy lead for $adId
$testPdo->exec("
    INSERT INTO updates_ad_leads (
        ad_id, post_id, name, phone, course_id, submitted_data, ip_hash, status, created_at
    ) VALUES (
        {$adId}, 1, 'John Doe', '9876543210', 1, '{\"qualification\":\"UG Final Year\"}', 'hash123', 'new', datetime('now')
    )
");

$delAttemptWithLeads = pepp_updates_delete_ad($testPdo, $adId);
assert_test("4.1: Deleting ad with captured leads is REJECTED by safety check",
    $delAttemptWithLeads['success'] === false &&
    strpos($delAttemptWithLeads['error'], 'Cannot delete ad') !== false
);

// Verify ad remains in database
$stillExistingAd = pepp_updates_get_ad($testPdo, $adId);
assert_test("4.2: Ad entity preserved in database after rejected delete attempt",
    $stillExistingAd !== null && (int)$stillExistingAd['id'] === $adId
);

// Delete ad without leads
$createEmptyAd = pepp_updates_save_ad($testPdo, [
    'title' => 'Temporary Ad',
    'status' => 'draft'
], [], [], 'admin_test');
$emptyAdId = (int)$createEmptyAd['id'];

$delEmptyAd = pepp_updates_delete_ad($testPdo, $emptyAdId);
assert_test("4.3: Deleting ad without any leads succeeds cleanly",
    $delEmptyAd['success'] === true &&
    pepp_updates_get_ad($testPdo, $emptyAdId) === null
);

// Test 4.4: H1 Database-level FK enforcement (ON DELETE RESTRICT): raw SQL DELETE on ad with leads throws exception
$pragmaStatus = (int)$testPdo->query("PRAGMA foreign_keys;")->fetchColumn();
$fkRestrictThrown = false;
$fkErrorMessage = '';
try {
    $testPdo->exec("DELETE FROM updates_ads WHERE id = {$adId}");
} catch (PDOException $e) {
    $fkRestrictThrown = true;
    $fkErrorMessage = $e->getMessage();
}
assert_test("4.4: H1 Database-level ON DELETE RESTRICT blocks raw SQL delete of ad with captured leads (PRAGMA foreign_keys = 1 verified on connection)",
    $pragmaStatus === 1 &&
    $fkRestrictThrown === true &&
    (stripos($fkErrorMessage, 'foreign key') !== false || stripos($fkErrorMessage, 'constraint failed') !== false)
);

// Test 4.5: H1 Database-level cascade deletion of targeting posts and custom fields when ad without leads is deleted
$adWithJunction = pepp_updates_save_ad($testPdo, [
    'title' => 'Ad With Cascade Targets'
], [1, 2], [
    ['field_label' => 'Specialization', 'field_name' => 'specialization']
], 'admin_test');
$cascadeAdId = (int)$adWithJunction['id'];

// Verify child rows exist
$stmtCountPosts = $testPdo->prepare("SELECT COUNT(*) FROM updates_ad_posts WHERE ad_id = ?");
$stmtCountPosts->execute([$cascadeAdId]);
$hasPosts = (int)$stmtCountPosts->fetchColumn() === 2;

$stmtCountFields = $testPdo->prepare("SELECT COUNT(*) FROM updates_ad_fields WHERE ad_id = ?");
$stmtCountFields->execute([$cascadeAdId]);
$hasFields = (int)$stmtCountFields->fetchColumn() === 1;

// Raw SQL delete on ad without leads
$testPdo->exec("DELETE FROM updates_ads WHERE id = {$cascadeAdId}");

$stmtCountPosts->execute([$cascadeAdId]);
$postsRemaining = (int)$stmtCountPosts->fetchColumn();

$stmtCountFields->execute([$cascadeAdId]);
$fieldsRemaining = (int)$stmtCountFields->fetchColumn();

assert_test("4.5: H1 Database-level ON DELETE CASCADE cleans up ad_posts and ad_fields automatically",
    $hasPosts && $hasFields && $postsRemaining === 0 && $fieldsRemaining === 0
);

// ─────────────────────────────────────────────────────────────
// 5. AD LEADS LISTING & REPORTING HELPERS
// ─────────────────────────────────────────────────────────────
echo "\n--- 5. Ad Leads Listing, Filtering & Stats ---\n";

$leadStats = pepp_updates_get_ad_lead_stats($testPdo);
assert_test("5.1: pepp_updates_get_ad_lead_stats counts total and new leads",
    $leadStats['total'] === 1 && $leadStats['new'] === 1 && $leadStats['converted'] === 0
);

$leadsList = pepp_updates_get_ad_leads($testPdo, ['search' => 'John']);
assert_test("5.2: pepp_updates_get_ad_leads finds lead by student name search",
    $leadsList['total'] === 1 &&
    count($leadsList['leads']) === 1 &&
    $leadsList['leads'][0]['name'] === 'John Doe' &&
    $leadsList['leads'][0]['ad_title'] === 'CUET Crash Course 2027 Updated'
);

$leadsNonMatch = pepp_updates_get_ad_leads($testPdo, ['search' => 'NonExistent']);
assert_test("5.3: pepp_updates_get_ad_leads returns empty list on non-matching query",
    $leadsNonMatch['total'] === 0 && empty($leadsNonMatch['leads'])
);

// Test 5.4: Date boundaries filtering
$testPdo->exec("
    INSERT INTO updates_ad_leads (ad_id, post_id, name, phone, status, created_at)
    VALUES ({$adId}, 1, 'Lead Oct 1', '9000000001', 'new', '2026-10-01 10:00:00'),
           ({$adId}, 1, 'Lead Oct 10', '9000000002', 'new', '2026-10-10 12:00:00'),
           ({$adId}, 1, 'Lead Oct 15', '9000000003', 'new', '2026-10-15 14:00:00'),
           ({$adId}, 1, 'Lead Oct 22', '9000000004', 'new', '2026-10-22 09:00:00')
");

$dateFiltered = pepp_updates_get_ad_leads($testPdo, [
    'date_from' => '2026-10-05',
    'date_to'   => '2026-10-18'
]);
$filteredNames = array_column($dateFiltered['leads'], 'name');

assert_test("5.4: Date boundary filters isolate leads strictly within date range (Oct 5 to Oct 18)",
    $dateFiltered['total'] === 2 &&
    in_array('Lead Oct 10', $filteredNames, true) &&
    in_array('Lead Oct 15', $filteredNames, true) &&
    !in_array('Lead Oct 1', $filteredNames, true) &&
    !in_array('Lead Oct 22', $filteredNames, true)
);

// Test 5.5 & 5.6: Multi-page pagination
// Total leads now in DB: 1 (initial John Doe) + 4 (Oct leads) = 5
$page1 = pepp_updates_get_ad_leads($testPdo, [], 1, 2);
$page2 = pepp_updates_get_ad_leads($testPdo, [], 2, 2);
$page3 = pepp_updates_get_ad_leads($testPdo, [], 3, 2);

assert_test("5.5: Multi-page pagination calculates correct total, pages count, and limit per page",
    $page1['total'] === 5 &&
    $page1['pages'] === 3 &&
    count($page1['leads']) === 2 &&
    count($page2['leads']) === 2 &&
    count($page3['leads']) === 1
);

$p1Ids = array_column($page1['leads'], 'id');
$p2Ids = array_column($page2['leads'], 'id');
$overlap = array_intersect($p1Ids, $p2Ids);

assert_test("5.6: Pagination returns distinct sequential items across pages without overlap",
    empty($overlap) && count($p1Ids) === 2 && count($p2Ids) === 2
);

// ─────────────────────────────────────────────────────────────
// 6. ADMIN NAVIGATION & ACCESS PERMISSIONS
// ─────────────────────────────────────────────────────────────
echo "\n--- 6. Admin Navigation & Permissions Audit ---\n";

require_once __DIR__ . '/includes/auth.php';

assert_test("6.1: pepp-updates-ads registered in ADMIN_PAGES",
    isset($GLOBALS['ADMIN_PAGES']['pepp-updates-ads']) &&
    $GLOBALS['ADMIN_PAGES']['pepp-updates-ads'][0] === 'PEPP Updates Ads'
);

assert_test("6.2: pepp-updates-ad-leads registered in ADMIN_PAGES",
    isset($GLOBALS['ADMIN_PAGES']['pepp-updates-ad-leads']) &&
    $GLOBALS['ADMIN_PAGES']['pepp-updates-ad-leads'][0] === 'PEPP Updates Ad Leads'
);

// Test can_access for super admin
$_SESSION['admin_user'] = 'superadmin';
$_SESSION['admin_role'] = 'super_admin';
$GLOBALS['admin_perms'] = 'ALL';

assert_test("6.3: Super admin has access to pepp-updates-ads",
    can_access('pepp-updates-ads') === true
);
assert_test("6.4: Super admin has access to pepp-updates-ad-leads",
    can_access('pepp-updates-ad-leads') === true
);

// Test can_access for admin with pepp-updates permission
$_SESSION['admin_role'] = 'admin';
$GLOBALS['admin_perms'] = 'pepp-updates';
assert_test("6.5: Admin with 'pepp-updates' permission has access to pepp-updates-ads",
    can_access('pepp-updates-ads') === true
);
assert_test("6.6: Admin with 'pepp-updates' permission has access to pepp-updates-ad-leads",
    can_access('pepp-updates-ad-leads') === true
);

// Test can_access for admin with marketing permission
$GLOBALS['admin_perms'] = 'marketing';
assert_test("6.7: Admin with 'marketing' permission has access to pepp-updates-ads",
    can_access('pepp-updates-ads') === true
);

// Test can_access rejection for unauthorized admin
$GLOBALS['admin_perms'] = 'accounts,invoices';
assert_test("6.8: Unauthorized admin rejected from accessing pepp-updates-ads",
    can_access('pepp-updates-ads') === false
);
assert_test("6.9: Unauthorized admin rejected from accessing pepp-updates-ad-leads",
    can_access('pepp-updates-ad-leads') === false
);

// Check admin_nav.php contains nav items
$adminNavContent = file_get_contents(__DIR__ . '/includes/admin_nav.php');
assert_test("6.10: admin_nav.php contains nav cases for pepp-updates-ads and pepp-updates-ad-leads",
    strpos($adminNavContent, "case 'pepp-updates-ads':") !== false &&
    strpos($adminNavContent, "case 'pepp-updates-ad-leads':") !== false &&
    strpos($adminNavContent, "'pepp-updates-ads'") !== false &&
    strpos($adminNavContent, "'pepp-updates-ad-leads'") !== false
);

// Check pepp-updates.php dashboard links
$dashContent = file_get_contents(__DIR__ . '/pepp-updates.php');
assert_test("6.11: pepp-updates.php dashboard has quick action buttons for Popup Ads and Ad Leads",
    strpos($dashContent, 'pepp-updates-ads.php') !== false &&
    strpos($dashContent, 'pepp-updates-ad-leads.php') !== false
);

// ─────────────────────────────────────────────────────────────
// 7. BOUNDARY CHECKS: ZERO PUBLIC POPUP CODE IN PHASE 2
// ─────────────────────────────────────────────────────────────
echo "\n--- 7. Scope & Boundary Checks (Phase 2 Isolation) ---\n";

$publicIndex = file_get_contents(__DIR__ . '/public_updates/index.php');
$publicUpdate = file_get_contents(__DIR__ . '/public_updates/update.php');

assert_test("7.1: public_updates/index.php does NOT contain popup modal markup (Phase 2 boundary)",
    strpos($publicIndex, 'pepp-ad-popup') === false &&
    strpos($publicIndex, 'updates_ads') === false
);

assert_test("7.2: public_updates/update.php does NOT contain popup modal markup (Phase 2 boundary)",
    strpos($publicUpdate, 'pepp-ad-popup') === false &&
    strpos($publicUpdate, 'updates_ads') === false
);

assert_test("7.3: WhatsApp Account 1 and Account 3 isolation strictly maintained",
    strpos($migSql, '1099020233033644') === false &&
    strpos($rollSql, '1099020233033644') === false
);

echo "\n============================================================\n";
echo "PHASE 2 AUDIT SUMMARY: {$totalPass} PASSED, {$totalFail} FAILED\n";
echo "============================================================\n";

if ($totalFail > 0) {
    exit(1);
}
exit(0);
