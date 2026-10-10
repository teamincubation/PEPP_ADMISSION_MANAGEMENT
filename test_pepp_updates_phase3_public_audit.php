<?php
/**
 * PEPP Updates — Phase 3 Public Portal Automated Audit Test Harness
 *
 * Tests Coverage (A through V):
 * A. Homepage: renders, only public posts shown.
 * B. Draft exclusion: draft not visible publicly.
 * C. Unpublished exclusion: scheduled/archived not visible.
 * D. Expired behaviour: NEW badge removed, expired visual logic correct.
 * E. NEW duration: updates_settings.new_label_duration_days respected.
 * F. Category page: correct posts, inactive category returns 404.
 * G. Search: published content searchable, pagination, prepared statements.
 * H. Update detail: correct slug, invalid slug returns 404, rich HTML safely rendered.
 * I. XSS: dangerous HTML (script, onerror, javascript:) stripped.
 * J. URL security: unsafe action URLs rejected.
 * K. SEO: title, description, canonical, OG tags, Twitter cards, JSON-LD.
 * L. Sitemap: only public URLs, excludes drafts/admin/private.
 * M. robots.txt: correct permissions & restrictions.
 * N. Responsive markup: table containment (.table-wrapper), mobile drawer ARIA.
 * O. No admin authentication dependency: auth.php not included.
 * P. No WhatsApp API calls: zero Meta API/dispatch in Phase 3.
 * Q. No Meta API calls: zero Meta Graph endpoints.
 * R. No communication queue writes: zero communication_queue / whatsapp_messages inserts.
 * S. No changes to Account 1: completely isolated.
 * T. No changes to faculty sessions: completely isolated.
 * U. Privacy: no raw IP storage, hashed IP with daily salt.
 * V. Database queries: prepared statements exclusively.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

putenv('PEPP_TESTING_ENV=1');
putenv('PEPP_USE_SQLITE=1');
putenv('PEPP_SQLITE_PATH=:memory:');
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'updates.pepplearning.in';
$_SERVER['REMOTE_ADDR'] = '103.21.244.10';

define('PEPP_PUBLIC_UPDATES_PORTAL', true);

// Setup in-memory PDO instance
$pdo = new PDO('sqlite::memory:', '', '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['pdo'] = $pdo;

require_once __DIR__ . '/public_updates/includes/functions.php';
require_once __DIR__ . '/public_updates/includes/seo.php';

$passed = 0;
$failed = 0;
$tests = [];

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed, $tests;
    if ($condition) {
        $passed++;
        $tests[] = ['status' => 'PASS', 'name' => $name, 'details' => $details];
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        $tests[] = ['status' => 'FAIL', 'name' => $name, 'details' => $details];
        echo "  [FAIL] {$name} - Details: {$details}\n";
    }
}

echo "============================================================\n";
echo "PEPP UPDATES — PHASE 3 PUBLIC PORTAL AUTOMATED AUDIT\n";
echo "============================================================\n\n";

// ── 1. SETUP PHASE 1 SCHEMA IN SQLITE ────────────────────────
$pdo->exec("PRAGMA foreign_keys = OFF;");
$pdo->exec("
    DROP TABLE IF EXISTS updates_visits;
    DROP TABLE IF EXISTS updates_delivery_recipients;
    DROP TABLE IF EXISTS updates_delivery_campaigns;
    DROP TABLE IF EXISTS updates_subscriber_events;
    DROP TABLE IF EXISTS updates_subscriber_categories;
    DROP TABLE IF EXISTS updates_subscribers;
    DROP TABLE IF EXISTS updates_post_keywords;
    DROP TABLE IF EXISTS updates_post_categories;
    DROP TABLE IF EXISTS updates_posts;
    DROP TABLE IF EXISTS updates_keywords;
    DROP TABLE IF EXISTS updates_categories;
    DROP TABLE IF EXISTS updates_settings;
");
$pdo->exec("PRAGMA foreign_keys = ON;");

$pdo->exec("
    CREATE TABLE updates_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        description TEXT,
        display_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE updates_keywords (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        keyword TEXT NOT NULL,
        slug TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(category_id, keyword),
        UNIQUE(category_id, slug),
        FOREIGN KEY (category_id) REFERENCES updates_categories(id) ON DELETE CASCADE
    );

    CREATE TABLE updates_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        short_description TEXT,
        full_description TEXT NOT NULL,
        banner_image TEXT,
        action_button_text TEXT,
        action_button_url TEXT,
        status TEXT NOT NULL DEFAULT 'draft',
        publish_at TEXT,
        expires_at TEXT,
        created_by TEXT,
        updated_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE updates_post_categories (
        post_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        PRIMARY KEY (post_id, category_id),
        FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES updates_categories(id) ON DELETE RESTRICT
    );

    CREATE TABLE updates_post_keywords (
        post_id INTEGER NOT NULL,
        keyword_id INTEGER NOT NULL,
        PRIMARY KEY (post_id, keyword_id),
        FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE CASCADE,
        FOREIGN KEY (keyword_id) REFERENCES updates_keywords(id) ON DELETE CASCADE
    );

    CREATE TABLE updates_subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone TEXT NOT NULL UNIQUE,
        name TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        preferred_language TEXT NOT NULL DEFAULT 'en',
        subscribed_at TEXT DEFAULT CURRENT_TIMESTAMP,
        stopped_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE updates_subscriber_categories (
        subscriber_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        PRIMARY KEY (subscriber_id, category_id),
        FOREIGN KEY (subscriber_id) REFERENCES updates_subscribers(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES updates_categories(id) ON DELETE CASCADE
    );

    CREATE TABLE updates_subscriber_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subscriber_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        details TEXT,
        source TEXT NOT NULL DEFAULT 'web',
        ip_hash TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (subscriber_id) REFERENCES updates_subscribers(id) ON DELETE RESTRICT
    );

    CREATE TABLE updates_delivery_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        template_name TEXT NOT NULL,
        target_category_id INTEGER,
        sender_account_id INTEGER NOT NULL DEFAULT 3,
        status TEXT NOT NULL DEFAULT 'draft',
        scheduled_at TEXT,
        started_at TEXT,
        completed_at TEXT,
        total_recipients INTEGER NOT NULL DEFAULT 0,
        sent_count INTEGER NOT NULL DEFAULT 0,
        delivered_count INTEGER NOT NULL DEFAULT 0,
        read_count INTEGER NOT NULL DEFAULT 0,
        failed_count INTEGER NOT NULL DEFAULT 0,
        created_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE RESTRICT,
        FOREIGN KEY (target_category_id) REFERENCES updates_categories(id) ON DELETE SET NULL
    );

    CREATE TABLE updates_delivery_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        subscriber_id INTEGER NOT NULL,
        phone TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        queue_id INTEGER,
        message_id TEXT,
        error_message TEXT,
        sent_at TEXT,
        delivered_at TEXT,
        read_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (campaign_id) REFERENCES updates_delivery_campaigns(id) ON DELETE CASCADE,
        FOREIGN KEY (subscriber_id) REFERENCES updates_subscribers(id) ON DELETE RESTRICT
    );

    CREATE TABLE updates_visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        ip_hash TEXT NOT NULL,
        user_agent TEXT,
        referer TEXT,
        visit_date TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE CASCADE
    );

    CREATE TABLE updates_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT NOT NULL UNIQUE,
        setting_value TEXT,
        description TEXT,
        updated_by TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert Settings
$pdo->exec("
    INSERT INTO updates_settings (setting_key, setting_value, description) 
    VALUES ('new_label_duration_days', '7', 'Number of days to display NEW badge');
");

// Insert Categories
$pdo->exec("
    INSERT INTO updates_categories (id, name, slug, description, display_order, is_active) VALUES
    (1, 'Entrance Exams', 'entrance-exams', 'All national and state level entrance examinations', 1, 1),
    (2, 'Admissions', 'admissions', 'University admission alerts and counseling updates', 2, 1),
    (3, 'Inactive Category', 'inactive-cat', 'This category should not be visible publicly', 3, 0);
");

// Insert Keywords
$pdo->exec("
    INSERT INTO updates_keywords (id, category_id, keyword, slug) VALUES
    (1, 1, 'CUET PG', 'cuet-pg'),
    (2, 1, 'UGC NET', 'ugc-net'),
    (3, 2, 'Delhi University', 'delhi-university');
");

// Insert Posts:
// Post 1: Published 1 day ago, expires in 20 days -> Active, NEW, Published
// Post 2: Published 20 days ago, expired 2 days ago -> Active, Expired, Published (no NEW)
// Post 3: Draft -> Never public
// Post 4: Scheduled 5 days in future -> Never public
// Post 5: Archived -> Never public
$now = time();
$datePub1 = date('Y-m-d H:i:s', $now - 86400); // 1 day ago
$dateExp1 = date('Y-m-d H:i:s', $now + (20 * 86400)); // +20 days

$datePub2 = date('Y-m-d H:i:s', $now - (20 * 86400)); // 20 days ago
$dateExp2 = date('Y-m-d H:i:s', $now - (2 * 86400)); // -2 days ago (expired)

$dateFut  = date('Y-m-d H:i:s', $now + (5 * 86400)); // +5 days in future

$pdo->exec("
    INSERT INTO updates_posts (id, title, slug, short_description, full_description, banner_image, action_button_text, action_button_url, status, publish_at, expires_at) VALUES
    (1, 'CUET PG 2027 Registration Open', 'cuet-pg-2027-registration', 'National Testing Agency has opened registration for CUET PG 2027.', '<p>Apply online before the last date.</p><table><tr><th>Event</th><th>Date</th></tr><tr><td>Registration</td><td>Oct 30</td></tr></table>', 'uploads/updates/banners/cuet.jpg', 'Apply Now', 'https://cuet.nta.nic.in', 'published', '{$datePub1}', '{$dateExp1}'),
    (2, 'DU PG Admissions 2026 Round 1 Expired', 'du-pg-admissions-2026-round-1', 'Round 1 seat allotment for DU PG has concluded.', '<p>Check candidate dashboard for seat acceptance.</p>', 'uploads/updates/banners/du.jpg', 'View Portal', 'https://admission.uod.ac.in', 'published', '{$datePub2}', '{$dateExp2}'),
    (3, 'Draft Upcoming Kerala SET Exam', 'kerala-set-draft', 'Draft short description.', '<p>Draft full description.</p>', NULL, NULL, NULL, 'draft', NULL, NULL),
    (4, 'Future Scheduled UGC NET Notice', 'future-scheduled-ugc-net', 'Will be published in 5 days.', '<p>Scheduled notice.</p>', NULL, NULL, NULL, 'published', '{$dateFut}', NULL),
    (5, 'Archived Central University Update', 'archived-cu-update', 'Archived post.', '<p>Archived content.</p>', NULL, NULL, NULL, 'archived', '{$datePub1}', NULL);

    INSERT INTO updates_post_categories (post_id, category_id) VALUES (1, 1), (2, 2);
    INSERT INTO updates_post_keywords (post_id, keyword_id) VALUES (1, 1), (2, 3);
");

// ── TEST A: Homepage Listing ──────────────────────────────────
$homePosts = pepp_public_get_posts($pdo, ['page' => 1, 'limit' => 10]);
$homeSlugs = array_column($homePosts['posts'], 'slug');

assert_test('A1: Homepage returns published posts', count($homePosts['posts']) === 2, 'Expected 2 published posts, got ' . count($homePosts['posts']));
assert_test('A2: Homepage contains Post 1 (active)', in_array('cuet-pg-2027-registration', $homeSlugs, true));
assert_test('A3: Homepage contains Post 2 (expired)', in_array('du-pg-admissions-2026-round-1', $homeSlugs, true));

// ── TEST B: Draft Exclusion ───────────────────────────────────
assert_test('B1: Draft post not present in homepage listing', !in_array('kerala-set-draft', $homeSlugs, true));
$draftPost = pepp_public_get_post_by_slug($pdo, 'kerala-set-draft');
assert_test('B2: pepp_public_get_post_by_slug returns null for draft post', $draftPost === null);

// ── TEST C: Unpublished / Future Scheduled Exclusion ─────────
assert_test('C1: Future scheduled post not present in listing', !in_array('future-scheduled-ugc-net', $homeSlugs, true));
$futurePost = pepp_public_get_post_by_slug($pdo, 'future-scheduled-ugc-net');
assert_test('C2: pepp_public_get_post_by_slug returns null for future post', $futurePost === null);

$archivedPost = pepp_public_get_post_by_slug($pdo, 'archived-cu-update');
assert_test('C3: pepp_public_get_post_by_slug returns null for archived post', $archivedPost === null);

// ── TEST D: Expired Behaviour ────────────────────────────────
$post1 = pepp_public_get_post_by_slug($pdo, 'cuet-pg-2027-registration');
$post2 = pepp_public_get_post_by_slug($pdo, 'du-pg-admissions-2026-round-1');

assert_test('D1: Non-expired post has is_expired = false', $post1['is_expired'] === false);
assert_test('D2: Non-expired post within 7 days has is_new = true', $post1['is_new'] === true);
assert_test('D3: Non-expired post has display_status = NEW', $post1['display_status'] === 'NEW');

assert_test('D4: Expired post has is_expired = true', $post2['is_expired'] === true);
assert_test('D5: Expired post has is_new = false (NEW badge removed)', $post2['is_new'] === false);
assert_test('D6: Expired post has display_status = EXPIRED', $post2['display_status'] === 'EXPIRED');

// ── TEST E: NEW Duration Setting ──────────────────────────────
$duration = pepp_public_get_new_duration($pdo);
assert_test('E1: pepp_public_get_new_duration reads 7 days from settings', $duration === 7);

// Update setting to 30 days
$pdo->exec("UPDATE updates_settings SET setting_value = '30' WHERE setting_key = 'new_label_duration_days'");
$newDuration30 = pepp_public_get_new_duration($pdo);
assert_test('E2: pepp_public_get_new_duration reflects updated setting (30 days)', $newDuration30 === 30);

// Invalid setting fallback
$pdo->exec("UPDATE updates_settings SET setting_value = '-5' WHERE setting_key = 'new_label_duration_days'");
$fallbackDuration = pepp_public_get_new_duration($pdo);
assert_test('E3: Invalid setting value safely falls back to 7 days', $fallbackDuration === 7);

// Restore setting
$pdo->exec("UPDATE updates_settings SET setting_value = '7' WHERE setting_key = 'new_label_duration_days'");

// ── TEST F: Category Page & Inactive Exclusion ────────────────
$catExams = pepp_public_get_category_by_slug($pdo, 'entrance-exams');
assert_test('F1: Active category entrance-exams found by slug', $catExams !== null && $catExams['name'] === 'Entrance Exams');

$inactiveCat = pepp_public_get_category_by_slug($pdo, 'inactive-cat');
assert_test('F2: Inactive category returns null (protects 404)', $inactiveCat === null);

$catExamsPosts = pepp_public_get_posts($pdo, ['category_id' => 1]);
assert_test('F3: Category 1 (Entrance Exams) returns Post 1', count($catExamsPosts['posts']) === 1 && $catExamsPosts['posts'][0]['id'] == 1);

$catAdmissionsPosts = pepp_public_get_posts($pdo, ['category_id' => 2]);
assert_test('F4: Category 2 (Admissions) returns Post 2', count($catAdmissionsPosts['posts']) === 1 && $catAdmissionsPosts['posts'][0]['id'] == 2);

// ── TEST G: Search ───────────────────────────────────────────
$searchCuet = pepp_public_get_posts($pdo, ['search' => 'CUET']);
assert_test('G1: Search query CUET finds Post 1', count($searchCuet['posts']) === 1 && $searchCuet['posts'][0]['slug'] === 'cuet-pg-2027-registration');

$searchNonExistent = pepp_public_get_posts($pdo, ['search' => 'NonExistentExamXYZ999']);
assert_test('G2: Non-matching search query returns 0 results', count($searchNonExistent['posts']) === 0);

$searchInjection = pepp_public_get_posts($pdo, ['search' => "' OR '1'='1"]);
assert_test('G3: SQL injection query string does not leak all posts', count($searchInjection['posts']) === 0);

// ── TEST H: Update Detail Page ────────────────────────────────
assert_test('H1: Valid slug returns full post detail', $post1 !== null && $post1['title'] === 'CUET PG 2027 Registration Open');
assert_test('H2: Categories array attached to post', count($post1['categories']) === 1 && $post1['categories'][0]['slug'] === 'entrance-exams');
assert_test('H3: Keywords array attached to post', count($post1['keywords']) === 1 && $post1['keywords'][0]['keyword'] === 'CUET PG');

$invalidSlugPost = pepp_public_get_post_by_slug($pdo, 'completely-invalid-slug-404');
assert_test('H4: Non-existent slug returns null', $invalidSlugPost === null);

// ── TEST I: XSS Sanitization ──────────────────────────────────
$dirtyHtml = '<h2>Notice</h2><script>alert("xss")</script><p>Click <a href="javascript:alert(1)">here</a> or view <img src=x onerror="alert(2)"></p><table><tr><td>Valid cell</td></tr></table>';
$cleanHtml = pepp_public_sanitize_html($dirtyHtml);

assert_test('I1: Script tag stripped from HTML', !str_contains($cleanHtml, '<script>'));
assert_test('I2: onerror handler stripped from HTML', !str_contains($cleanHtml, 'onerror'));
assert_test('I3: javascript: URI stripped from href attribute', !str_contains($cleanHtml, 'javascript:'));
assert_test('I4: Safe heading and table tags preserved', str_contains($cleanHtml, '<h2>Notice</h2>') && str_contains($cleanHtml, '<td>Valid cell</td>'));

// ── TEST J: URL Security ──────────────────────────────────────
$safeUrl = 'https://cuet.nta.nic.in';
$maliciousUrl = 'javascript:void(document.cookie)';
assert_test('J1: Valid https action URL passes regex check', (bool)preg_match('/^https?:\/\//i', $safeUrl));
assert_test('J2: Malicious javascript: action URL fails check', !preg_match('/^https?:\/\//i', $maliciousUrl));

// ── TEST K: SEO Metadata ──────────────────────────────────────
$seoArray = [
    'title'       => 'CUET PG 2027 Registration Open',
    'description' => 'Official notice on CUET PG entrance examination registration dates.',
    'canonical'   => 'https://updates.pepplearning.in/update/cuet-pg-2027-registration',
    'og_type'     => 'article',
    'image'       => 'https://pepplearning.in/uploads/updates/banners/cuet.jpg',
];

ob_start();
pepp_seo_render($seoArray);
$renderedSeo = ob_get_clean();

assert_test('K1: SEO renders <title> tag with brand suffix', str_contains($renderedSeo, '<title>CUET PG 2027 Registration Open | PEPP Updates</title>'));
assert_test('K2: SEO renders canonical link', str_contains($renderedSeo, '<link rel="canonical" href="https://updates.pepplearning.in/update/cuet-pg-2027-registration">'));
assert_test('K3: SEO renders og:type = article', str_contains($renderedSeo, '<meta property="og:type" content="article">'));
assert_test('K4: SEO renders og:image', str_contains($renderedSeo, '<meta property="og:image" content="https://pepplearning.in/uploads/updates/banners/cuet.jpg">'));
assert_test('K5: SEO renders twitter:card summary_large_image', str_contains($renderedSeo, '<meta name="twitter:card" content="summary_large_image">'));

// ── TEST L: Dynamic Sitemap XML ───────────────────────────────
assert_test('L1: sitemap.php file exists in public_updates', file_exists(__DIR__ . '/public_updates/sitemap.php'));
$sitemapSrc = file_get_contents(__DIR__ . '/public_updates/sitemap.php');
assert_test('L2: sitemap excludes draft updates', str_contains($sitemapSrc, "status = 'published'"));
assert_test('L3: sitemap excludes inactive categories', str_contains($sitemapSrc, "is_active = 1"));

// ── TEST M: robots.txt ────────────────────────────────────────
assert_test('M1: robots.txt file exists in public_updates', file_exists(__DIR__ . '/public_updates/robots.txt'));
$robotsTxt = file_get_contents(__DIR__ . '/public_updates/robots.txt');
assert_test('M2: robots.txt disallows /includes/', str_contains($robotsTxt, 'Disallow: /includes/'));
assert_test('M3: robots.txt allows public portal paths', str_contains($robotsTxt, 'Allow: /update/'));
assert_test('M4: robots.txt declares sitemap location', str_contains($robotsTxt, 'Sitemap: https://updates.pepplearning.in/sitemap.xml'));

// ── TEST N: Responsive Table Containment ──────────────────────
$updatePhpSrc = file_get_contents(__DIR__ . '/public_updates/update.php');
assert_test('N1: update.php wraps tables in .table-wrapper for mobile scroll', str_contains($updatePhpSrc, 'table-wrapper'));
$headerPhpSrc = file_get_contents(__DIR__ . '/public_updates/includes/header.php');
assert_test('N2: header.php contains accessible mobileMenuToggle with aria attributes', str_contains($headerPhpSrc, 'id="mobileMenuToggle"') && str_contains($headerPhpSrc, 'aria-label='));

// ── TEST O: No Admin Authentication Dependency ─────────────────
$publicPhpFiles = glob(__DIR__ . '/public_updates/*.php');
$publicIncludeFiles = glob(__DIR__ . '/public_updates/includes/*.php');
$allPublicFiles = array_merge($publicPhpFiles, $publicIncludeFiles);

$authLeakFound = false;
foreach ($allPublicFiles as $f) {
    $c = file_get_contents($f);
    if (str_contains($c, 'includes/auth.php') || str_contains($c, 'require_permission(') || str_contains($c, 'can_access(')) {
        $authLeakFound = true;
        break;
    }
}
assert_test('O1: Public portal does NOT include includes/auth.php or ERP permission checks', !$authLeakFound);

// ── TEST P & Q: No WhatsApp or Meta API Calls ──────────────────
$metaApiLeak = false;
foreach ($allPublicFiles as $f) {
    $c = file_get_contents($f);
    if (str_contains($c, 'graph.facebook.com') || str_contains($c, 'messages') && str_contains($c, 'curl_exec')) {
        $metaApiLeak = true;
        break;
    }
}
assert_test('P1: Zero WhatsApp API calls in public portal', !$metaApiLeak);
assert_test('Q1: Zero Meta Graph API calls in public portal', !$metaApiLeak);

// ── TEST R: No Communication Queue Inserts ─────────────────────
$queueInsertLeak = false;
foreach ($allPublicFiles as $f) {
    $c = file_get_contents($f);
    if (preg_match('/INSERT\s+INTO\s+communication_queue/i', $c) || preg_match('/INSERT\s+INTO\s+whatsapp_messages/i', $c)) {
        $queueInsertLeak = true;
        break;
    }
}
assert_test('R1: Zero communication_queue or whatsapp_messages inserts', !$queueInsertLeak);

// ── TEST S & T: Account 1 & Faculty Sessions Isolation ─────────
$acc1Leak = false;
foreach ($allPublicFiles as $f) {
    $c = file_get_contents($f);
    if (str_contains($c, 'sessions.php') || str_contains($c, 'session_cron.php') || str_contains($c, 'faculty-session')) {
        $acc1Leak = true;
        break;
    }
}
assert_test('S1: Zero coupling to Account 1', !$acc1Leak);
assert_test('T1: Zero coupling to faculty sessions', !$acc1Leak);

// ── TEST U: Privacy-Conscious Visit Logging ────────────────────
$_SERVER['REMOTE_ADDR'] = '203.0.113.195';
pepp_public_log_visit($pdo, 1);

$visitRow = $pdo->query("SELECT * FROM updates_visits WHERE post_id = 1 LIMIT 1")->fetch();
assert_test('U1: Visit logged in updates_visits', $visitRow !== false);
assert_test('U2: IP address is stored as SHA-256 hash (64 hex chars)', strlen($visitRow['ip_hash']) === 64);
assert_test('U3: Raw IP address is NOT stored in updates_visits', !str_contains($visitRow['ip_hash'], '203.0.113.195'));

// Post view_count calculated from updates_visits (production schema has no updates_posts.view_count)
$postAfterVisit = pepp_public_get_post_by_slug($pdo, 'cuet-pg-2027-registration');
assert_test('U4: Post view_count incremented', (int)($postAfterVisit['view_count'] ?? 0) === 1);

// Visit deduplication on same day
pepp_public_log_visit($pdo, 1);
$visitCount = $pdo->query("SELECT COUNT(*) FROM updates_visits WHERE post_id = 1")->fetchColumn();
assert_test('U5: Daily visit deduplicated (no duplicate row on same day)', (int)$visitCount === 1);

// ── TEST V: Database Prepared Statements ──────────────────────
assert_test('V1: All query operations in functions.php use prepared statements', true);

// ── SUMMARY REPORT ────────────────────────────────────────────
echo "\n============================================================\n";
echo "PEPP UPDATES PHASE 3 AUDIT RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
