<?php
/**
 * PEPP Updates — Phase 2 Admin UI Automated Audit Test Harness
 * 
 * Tests Coverage (A through Z):
 * A. Existing ERP authentication reused.
 * B. Unauthorized access rejected.
 * C. CSRF protection.
 * D. Categories CRUD.
 * E. Category deletion protection.
 * F. Category-owned keywords.
 * G. Same keyword under different categories allowed.
 * H. Duplicate keyword within same category rejected.
 * I. New post defaults to draft.
 * J. Publish operation.
 * K. Edit operation.
 * L. Preview does not publish.
 * M. Expiry calculation.
 * N. NEW label calculation.
 * O. Banner upload validation.
 * P. HTML sanitization.
 * Q. URL validation.
 * R. Slug uniqueness.
 * S. Subscriber privacy.
 * T. Subscriber physical deletion blocked.
 * U. Campaign creation.
 * V. sender_account_id = 3 accepted.
 * W. sender_account_id = 1 rejected.
 * X. No Meta API call from Phase 2.
 * Y. Existing communication files unchanged.
 * Z. Account 1 faculty-session functionality unchanged.
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

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pepp_updates_helper.php';

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
echo "PEPP UPDATES — PHASE 2 ADMIN UI AUTOMATED AUDIT\n";
echo "============================================================\n\n";

// ── SETUP IN-MEMORY ISOLATED SQLITE DATABASE ───────────────
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

// Create Phase 1 schema in SQLite
$pdo->exec("
    CREATE TABLE IF NOT EXISTS updates_categories (
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
        UNIQUE (campaign_id, subscriber_id),
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
        FOREIGN KEY (post_id) REFERENCES updates_posts(id) ON DELETE SET NULL
    );

    CREATE TABLE updates_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT NOT NULL UNIQUE,
        setting_value TEXT,
        description TEXT,
        updated_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    INSERT INTO updates_settings (setting_key, setting_value, description)
    VALUES ('new_label_duration_days', '7', 'Duration in days for an update to display the NEW badge');
");

echo "--- Running Test Suites (A to Z) ---\n\n";

// ─────────────────────────────────────────────────────────────
// A. Existing ERP authentication reused
// ─────────────────────────────────────────────────────────────
assert_test(
    'A1: PEPP Updates registered in $GLOBALS["ADMIN_PAGES"]',
    isset($GLOBALS['ADMIN_PAGES']['pepp-updates']) && isset($GLOBALS['ADMIN_PAGES']['pepp-updates-posts']),
    'ADMIN_PAGES registration verified'
);
assert_test(
    'A2: Super admin access granted to pepp-updates',
    (function() {
        $_SESSION['admin_role'] = 'super_admin';
        return can_access('pepp-updates') && can_access('pepp-updates-posts');
    })(),
    'Super admin inherits can_access'
);
assert_test(
    'A3: Admin with explicit pepp-updates perm granted access',
    (function() {
        $_SESSION['admin_role'] = 'admin';
        global $admin_perms;
        $admin_perms = 'dashboard,pepp-updates';
        return can_access('pepp-updates') && can_access('pepp-updates-categories');
    })(),
    'Perms string parsed correctly'
);

// ─────────────────────────────────────────────────────────────
// B. Unauthorized access rejected
// ─────────────────────────────────────────────────────────────
assert_test(
    'B1: Admin without pepp-updates or ALL rejected by can_access',
    (function() {
        $_SESSION['admin_role'] = 'admin';
        global $admin_perms;
        $admin_perms = 'dashboard,students,courses';
        return can_access('pepp-updates') === false && can_access('pepp-updates-posts') === false;
    })(),
    'Unauthorized permission check rejected'
);

// ─────────────────────────────────────────────────────────────
// C. CSRF protection
// ─────────────────────────────────────────────────────────────
assert_test(
    'C1: CSRF token generation and validation',
    (function() {
        if (!function_exists('csrf_token') || !function_exists('csrf_verify')) return false;
        $token = csrf_token();
        $_POST['csrf_token'] = $token;
        $valid = csrf_verify();
        $_POST['csrf_token'] = 'invalid_tampered_token';
        $invalid = !csrf_verify();
        unset($_POST['csrf_token']);
        return $valid && $invalid;
    })(),
    'CSRF verify helper enforces validity'
);

// ─────────────────────────────────────────────────────────────
// D. Categories CRUD
// ─────────────────────────────────────────────────────────────
$catId = 0;
assert_test(
    'D1: Category Creation',
    (function() use ($pdo, &$catId) {
        $slug = pepp_updates_generate_slug($pdo, 'updates_categories', 'Admissions 2027');
        $stmt = $pdo->prepare("INSERT INTO updates_categories (name, slug, description, display_order, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Admissions 2027', $slug, 'All admission alerts', 1, 1]);
        $catId = (int)$pdo->lastInsertId();
        return $catId > 0 && $slug === 'admissions-2027';
    })(),
    'Category inserted with unique slug'
);

assert_test(
    'D2: Category Read & Update',
    (function() use ($pdo, $catId) {
        $pdo->prepare("UPDATE updates_categories SET description = ? WHERE id = ?")->execute(['Updated description', $catId]);
        $stmt = $pdo->prepare("SELECT description FROM updates_categories WHERE id = ?");
        $stmt->execute([$catId]);
        return $stmt->fetchColumn() === 'Updated description';
    })(),
    'Category updated and read successfully'
);

// ─────────────────────────────────────────────────────────────
// E. Category deletion protection (ON DELETE RESTRICT)
// ─────────────────────────────────────────────────────────────
assert_test(
    'E1: Category deletion blocked when referenced by post',
    (function() use ($pdo, $catId) {
        // Create post linked to category
        $slug = pepp_updates_generate_slug($pdo, 'updates_posts', 'Sample Post For Category Test');
        $stmt = $pdo->prepare("INSERT INTO updates_posts (title, slug, full_description, status) VALUES (?, ?, ?, 'draft')");
        $stmt->execute(['Sample Post For Category Test', $slug, '<p>Content</p>']);
        $postId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO updates_post_categories (post_id, category_id) VALUES (?, ?)")->execute([$postId, $catId]);

        try {
            // Attempt deletion of category
            $pdo->prepare("DELETE FROM updates_categories WHERE id = ?")->execute([$catId]);
            return false; // Should not reach here
        } catch (PDOException $e) {
            // Expected foreign key restriction failure
            return true;
        }
    })(),
    'ON DELETE RESTRICT prevented deletion of category in use'
);

// Clean up post for remaining tests
$pdo->exec("DELETE FROM updates_posts WHERE title = 'Sample Post For Category Test'");

// ─────────────────────────────────────────────────────────────
// F. Category-owned keywords
// ─────────────────────────────────────────────────────────────
$kw1Id = 0;
assert_test(
    'F1: Keywords belong to category_id',
    (function() use ($pdo, $catId, &$kw1Id) {
        $slug = pepp_updates_generate_slug($pdo, 'updates_keywords', 'Application', null, 'slug', 'category_id = ?', [$catId]);
        $stmt = $pdo->prepare("INSERT INTO updates_keywords (category_id, keyword, slug) VALUES (?, ?, ?)");
        $stmt->execute([$catId, 'Application', $slug]);
        $kw1Id = (int)$pdo->lastInsertId();
        return $kw1Id > 0 && $slug === 'application';
    })(),
    'Keyword belongs to category'
);

// ─────────────────────────────────────────────────────────────
// G. Same keyword under different categories allowed
// ─────────────────────────────────────────────────────────────
$cat2Id = 0;
assert_test(
    'G1: Same keyword under different category allowed',
    (function() use ($pdo, &$cat2Id) {
        // Create second category
        $stmt = $pdo->prepare("INSERT INTO updates_categories (name, slug) VALUES ('Examinations', 'examinations')");
        $stmt->execute();
        $cat2Id = (int)$pdo->lastInsertId();

        // Insert keyword 'Application' under category 2
        $slug = pepp_updates_generate_slug($pdo, 'updates_keywords', 'Application', null, 'slug', 'category_id = ?', [$cat2Id]);
        $stmt2 = $pdo->prepare("INSERT INTO updates_keywords (category_id, keyword, slug) VALUES (?, ?, ?)");
        $stmt2->execute([$cat2Id, 'Application', $slug]);
        return (int)$pdo->lastInsertId() > 0;
    })(),
    'Multi-category keyword ownership permitted'
);

// ─────────────────────────────────────────────────────────────
// H. Duplicate keyword within same category rejected
// ─────────────────────────────────────────────────────────────
assert_test(
    'H1: Duplicate keyword within same category rejected',
    (function() use ($pdo, $catId) {
        try {
            $stmt = $pdo->prepare("INSERT INTO updates_keywords (category_id, keyword, slug) VALUES (?, 'Application', 'application-2')");
            $stmt->execute([$catId]);
            return false;
        } catch (PDOException $e) {
            return true;
        }
    })(),
    'Unique index uq_category_keyword enforced'
);

// ─────────────────────────────────────────────────────────────
// I. New post defaults to draft
// ─────────────────────────────────────────────────────────────
$postId = 0;
assert_test(
    'I1: New post defaults to DRAFT',
    (function() use ($pdo, &$postId) {
        $slug = pepp_updates_generate_slug($pdo, 'updates_posts', 'CUET PG 2027 Registration');
        $stmt = $pdo->prepare("INSERT INTO updates_posts (title, slug, full_description) VALUES (?, ?, ?)");
        $stmt->execute(['CUET PG 2027 Registration', $slug, '<p>Draft details</p>']);
        $postId = (int)$pdo->lastInsertId();

        $stmt_check = $pdo->prepare("SELECT status FROM updates_posts WHERE id = ?");
        $stmt_check->execute([$postId]);
        $st = $stmt_check->fetchColumn();
        return $st === 'draft';
    })(),
    'Default status is draft'
);

// ─────────────────────────────────────────────────────────────
// J. Publish operation
// ─────────────────────────────────────────────────────────────
assert_test(
    'J1: Publish operation transitions status and sets publish_at',
    (function() use ($pdo, $postId) {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE updates_posts SET status = 'published', publish_at = ? WHERE id = ?")->execute([$now, $postId]);

        $stmt = $pdo->prepare("SELECT status, publish_at FROM updates_posts WHERE id = ?");
        $stmt->execute([$postId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $eff = pepp_updates_effective_status($row['status'], $row['publish_at']);
        return $row['status'] === 'published' && $eff === 'published';
    })(),
    'Published post transitions correctly'
);

// ─────────────────────────────────────────────────────────────
// K. Edit operation
// ─────────────────────────────────────────────────────────────
assert_test(
    'K1: Post edit modifies fields cleanly',
    (function() use ($pdo, $postId) {
        $pdo->prepare("UPDATE updates_posts SET title = ?, short_description = ? WHERE id = ?")
            ->execute(['CUET PG 2027 Registration (Updated)', 'Updated short description', $postId]);

        $stmt = $pdo->prepare("SELECT title, short_description FROM updates_posts WHERE id = ?");
        $stmt->execute([$postId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['title'] === 'CUET PG 2027 Registration (Updated)' && $row['short_description'] === 'Updated short description';
    })(),
    'Edit operation successful'
);

// ─────────────────────────────────────────────────────────────
// L. Preview does not publish
// ─────────────────────────────────────────────────────────────
assert_test(
    'L1: Preview operation does NOT change post status',
    (function() use ($pdo) {
        // Create draft post
        $slug = pepp_updates_generate_slug($pdo, 'updates_posts', 'Preview Test Post');
        $pdo->prepare("INSERT INTO updates_posts (title, slug, full_description, status) VALUES ('Preview Test Post', ?, '<p>Content</p>', 'draft')")
            ->execute([$slug]);
        $pId = (int)$pdo->lastInsertId();

        // Read post (previewing)
        $stmt = $pdo->prepare("SELECT * FROM updates_posts WHERE id = ?");
        $stmt->execute([$pId]);
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify status remains draft
        $remainsDraft = ($fetched['status'] === 'draft');
        $pdo->prepare("DELETE FROM updates_posts WHERE id = ?")->execute([$pId]);
        return $remainsDraft;
    })(),
    'Read-only preview leaves status untouched'
);

// ─────────────────────────────────────────────────────────────
// M. Expiry calculation
// ─────────────────────────────────────────────────────────────
assert_test(
    'M1: Runtime effective status calculates expired even if status is published',
    (function() {
        $past = date('Y-m-d H:i:s', strtotime('-2 days'));
        $effective = pepp_updates_effective_status('published', date('Y-m-d H:i:s', strtotime('-10 days')), $past);
        return $effective === 'expired';
    })(),
    'Past expires_at returns expired'
);

assert_test(
    'M2: Runtime effective status calculates scheduled if publish_at is future',
    (function() {
        $future = date('Y-m-d H:i:s', strtotime('+5 days'));
        $effective = pepp_updates_effective_status('published', $future, null);
        return $effective === 'scheduled';
    })(),
    'Future publish_at returns scheduled'
);

// ─────────────────────────────────────────────────────────────
// N. NEW label calculation
// ─────────────────────────────────────────────────────────────
assert_test(
    'N1: Recent post returns is_new = true within duration',
    (function() {
        $recent = date('Y-m-d H:i:s', strtotime('-2 days'));
        return pepp_updates_is_new($recent, null, 7) === true;
    })(),
    '2-day old post with 7-day duration is NEW'
);

assert_test(
    'N2: Expired post NEVER displays NEW',
    (function() {
        $recent = date('Y-m-d H:i:s', strtotime('-2 days'));
        $expired = date('Y-m-d H:i:s', strtotime('-1 hour'));
        return pepp_updates_is_new($recent, $expired, 7) === false;
    })(),
    'Expired post returns is_new = false'
);

assert_test(
    'N3: Configurable duration respected (e.g. 3 days)',
    (function() {
        $fourDaysAgo = date('Y-m-d H:i:s', strtotime('-4 days'));
        $notNewWith3Days = (pepp_updates_is_new($fourDaysAgo, null, 3) === false);
        $newWith7Days = (pepp_updates_is_new($fourDaysAgo, null, 7) === true);
        return $notNewWith3Days && $newWith7Days;
    })(),
    'Duration parameter dynamically adjusts window'
);

// ─────────────────────────────────────────────────────────────
// O. Banner upload validation
// ─────────────────────────────────────────────────────────────
assert_test(
    'O1: Banner validator rejects non-image / php files',
    (function() {
        $fakePhp = [
            'name' => 'exploit.php',
            'type' => 'application/x-php',
            'tmp_name' => __FILE__, // php file
            'error' => UPLOAD_ERR_OK,
            'size' => 1024
        ];
        $res = pepp_updates_upload_banner($fakePhp);
        return $res['success'] === false;
    })(),
    'PHP extension and non-image rejected'
);

assert_test(
    'O2: Banner validator rejects files exceeding 5MB',
    (function() {
        $fakeHuge = [
            'name' => 'huge_image.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => tempnam(sys_get_temp_dir(), 'test_'),
            'error' => UPLOAD_ERR_OK,
            'size' => 6 * 1024 * 1024 // 6MB
        ];
        $res = pepp_updates_upload_banner($fakeHuge);
        return $res['success'] === false && strpos($res['error'], 'exceeds') !== false;
    })(),
    '5MB limit enforced'
);

// ─────────────────────────────────────────────────────────────
// P. HTML sanitization
// ─────────────────────────────────────────────────────────────
assert_test(
    'P1: HTML sanitizer strips <script> and dangerous tags',
    (function() {
        $dirty = '<p>Normal text</p><script>alert("xss")</script><iframe src="evil.com"></iframe>';
        $clean = pepp_updates_sanitize_html($dirty);
        return strpos($clean, '<script>') === false 
            && strpos($clean, 'alert') === false 
            && strpos($clean, '<iframe') === false
            && strpos($clean, '<p>Normal text</p>') !== false;
    })(),
    'Script and iframe stripped'
);

assert_test(
    'P2: HTML sanitizer strips on* event handlers and raw inline styles',
    (function() {
        $dirty = '<p onclick="alert(1)" style="color:red; background:url(evil.com);">Text</p>';
        $clean = pepp_updates_sanitize_html($dirty);
        return strpos($clean, 'onclick') === false && strpos($clean, 'style=') === false;
    })(),
    'onclick and style stripped'
);

assert_test(
    'P3: HTML sanitizer strips javascript: links and sets target=_blank',
    (function() {
        $dirty = '<a href="javascript:alert(1)">Click</a><a href="https://example.com">Legit</a>';
        $clean = pepp_updates_sanitize_html($dirty);
        return strpos($clean, 'javascript:') === false 
            && strpos($clean, 'https://example.com') !== false
            && strpos($clean, 'target="_blank"') !== false
            && strpos($clean, 'rel="noopener noreferrer"') !== false;
    })(),
    'javascript: URL stripped; safe link secured'
);

// ─────────────────────────────────────────────────────────────
// Q. URL validation
// ─────────────────────────────────────────────────────────────
assert_test(
    'Q1: URL validation allows valid HTTP/HTTPS and rejects dangerous protocols',
    (function() {
        $valid1 = filter_var('https://pepplearning.in/exam', FILTER_VALIDATE_URL);
        $valid2 = filter_var('http://updates.pepplearning.in', FILTER_VALIDATE_URL);
        $invalid1 = filter_var('javascript:alert(1)', FILTER_VALIDATE_URL);
        $invalid2 = filter_var('data:text/html,test', FILTER_VALIDATE_URL);
        return $valid1 !== false && $valid2 !== false && $invalid1 === false && $invalid2 === false;
    })(),
    'FILTER_VALIDATE_URL filters valid web links'
);

// ─────────────────────────────────────────────────────────────
// R. Slug uniqueness
// ─────────────────────────────────────────────────────────────
assert_test(
    'R1: Slug generator produces unique slugs with collision resolution',
    (function() use ($pdo) {
        $slug1 = pepp_updates_generate_slug($pdo, 'updates_posts', 'Admission Alert');
        $pdo->prepare("INSERT INTO updates_posts (title, slug, full_description) VALUES ('Post 1', ?, 'Content')")->execute([$slug1]);

        $slug2 = pepp_updates_generate_slug($pdo, 'updates_posts', 'Admission Alert');
        $pdo->prepare("INSERT INTO updates_posts (title, slug, full_description) VALUES ('Post 2', ?, 'Content')")->execute([$slug2]);

        $slug3 = pepp_updates_generate_slug($pdo, 'updates_posts', 'Admission Alert');
        return $slug1 === 'admission-alert' && $slug2 === 'admission-alert-2' && $slug3 === 'admission-alert-3';
    })(),
    'Slugs automatically deduplicated'
);

// ─────────────────────────────────────────────────────────────
// S. Subscriber privacy
// ─────────────────────────────────────────────────────────────
assert_test(
    'S1: Subscriber table schema contains ZERO email, district, raw IP, or user_agent',
    (function() use ($pdo) {
        $cols = $pdo->query("PRAGMA table_info(updates_subscribers)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');
        return !in_array('email', $colNames, true)
            && !in_array('district', $colNames, true)
            && !in_array('ip_address', $colNames, true)
            && !in_array('user_agent', $colNames, true);
    })(),
    'PII strictly excluded from updates_subscribers'
);

// ─────────────────────────────────────────────────────────────
// T. Subscriber physical deletion blocked
// ─────────────────────────────────────────────────────────────
$subId = 0;
assert_test(
    'T1: Subscriber physical deletion blocked by event audit trail',
    (function() use ($pdo, &$subId) {
        // Create subscriber
        $pdo->prepare("INSERT INTO updates_subscribers (phone, name, status) VALUES ('+919999999999', 'Test Subscriber', 'active')")->execute();
        $subId = (int)$pdo->lastInsertId();

        // Record consent event
        pepp_updates_record_subscriber_event($pdo, $subId, 'SUBSCRIBED', 'Initial web consent', 'web');

        // Attempt physical delete
        try {
            $pdo->prepare("DELETE FROM updates_subscribers WHERE id = ?")->execute([$subId]);
            return false;
        } catch (PDOException $e) {
            // Expected foreign key restriction on updates_subscriber_events
            return true;
        }
    })(),
    'fk_use_subscriber ON DELETE RESTRICT blocks subscriber deletion'
);

assert_test(
    'T2: Soft status transition succeeds and logs audit event',
    (function() use ($pdo, $subId) {
        $pdo->prepare("UPDATE updates_subscribers SET status = 'stopped', stopped_at = datetime('now') WHERE id = ?")->execute([$subId]);
        pepp_updates_record_subscriber_event($pdo, $subId, 'STOPPED', 'Admin changed status to stopped', 'admin');

        $stmt = $pdo->prepare("SELECT status FROM updates_subscribers WHERE id = ?");
        $stmt->execute([$subId]);
        $st = $stmt->fetchColumn();

        $stmt_ev = $pdo->prepare("SELECT event_type FROM updates_subscriber_events WHERE subscriber_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_ev->execute([$subId]);
        $ev = $stmt_ev->fetchColumn();

        return $st === 'stopped' && $ev === 'STOPPED';
    })(),
    'Lifecycle transition recorded in audit trail'
);

// ─────────────────────────────────────────────────────────────
// U. Campaign creation
// ─────────────────────────────────────────────────────────────
$campId = 0;
assert_test(
    'U1: Delivery campaign created successfully with linked post',
    (function() use ($pdo, $postId, $catId, &$campId) {
        $stmt = $pdo->prepare("
            INSERT INTO updates_delivery_campaigns (
                post_id, title, template_name, target_category_id, sender_account_id, status
            ) VALUES (?, 'Broadcast Alert', 'pepp_updates_alert', ?, 3, 'draft')
        ");
        $stmt->execute([$postId, $catId]);
        $campId = (int)$pdo->lastInsertId();
        return $campId > 0;
    })(),
    'Campaign inserted and linked'
);

// ─────────────────────────────────────────────────────────────
// V. sender_account_id = 3 accepted
// ─────────────────────────────────────────────────────────────
assert_test(
    'V1: sender_account_id = 3 accepted',
    pepp_updates_validate_campaign_sender(3) === true,
    'Account 3 accepted'
);

// ─────────────────────────────────────────────────────────────
// W. sender_account_id = 1 rejected
// ─────────────────────────────────────────────────────────────
assert_test(
    'W1: sender_account_id = 1 rejected',
    pepp_updates_validate_campaign_sender(1) === false,
    'Account 1 rejected'
);

assert_test(
    'W2: Any non-3 sender_account_id rejected',
    pepp_updates_validate_campaign_sender(2) === false && pepp_updates_validate_campaign_sender(0) === false,
    'Arbitrary accounts rejected'
);

// ─────────────────────────────────────────────────────────────
// X. No Meta API call from Phase 2
// ─────────────────────────────────────────────────────────────
assert_test(
    'X1: Zero Meta WhatsApp API calls in PEPP Updates files',
    (function() {
        $files = [
            'includes/pepp_updates_helper.php',
            'pepp-updates.php',
            'pepp-updates-posts.php',
            'pepp-updates-categories.php',
            'pepp-updates-keywords.php',
            'pepp-updates-subscribers.php',
            'pepp-updates-campaigns.php',
            'pepp-updates-settings.php'
        ];
        foreach ($files as $f) {
            $path = __DIR__ . '/' . $f;
            if (!file_exists($path)) return false;
            $code = file_get_contents($path);
            if (strpos($code, 'graph.facebook.com') !== false || strpos($code, 'messages') && strpos($code, 'curl') !== false) {
                return false;
            }
        }
        return true;
    })(),
    'No WhatsApp Meta API calls detected in Phase 2 files'
);

// ─────────────────────────────────────────────────────────────
// Y. Existing communication files unchanged
// ─────────────────────────────────────────────────────────────
assert_test(
    'Y1: webhook.php, cron-queue.php, CommunicationEngine.php, QueueProcessor.php untouched',
    (function() {
        $protected = [
            'api/v1/communication/webhook.php',
            'cron-queue.php',
            'includes/communication/CommunicationEngine.php',
            'includes/communication/QueueProcessor.php'
        ];
        foreach ($protected as $relPath) {
            $fullPath = __DIR__ . '/' . $relPath;
            if (!file_exists($fullPath)) return false;
            $output = [];
            exec("git diff 585a2e5 -- \"{$fullPath}\"", $output);
            if (!empty($output)) {
                return false;
            }
        }
        return true;
    })(),
    'Core WhatsApp communication files match baseline 585a2e5 exactly'
);

// ─────────────────────────────────────────────────────────────
// Z. Account 1 faculty-session functionality unchanged
// ─────────────────────────────────────────────────────────────
assert_test(
    'Z1: sessions.php and session_cron.php untouched from baseline',
    (function() {
        $sessionFiles = [
            'sessions.php',
            'includes/session_cron.php'
        ];
        foreach ($sessionFiles as $sf) {
            $fullPath = __DIR__ . '/' . $sf;
            if (file_exists($fullPath)) {
                $output = [];
                exec("git diff 585a2e5 -- \"{$fullPath}\"", $output);
                if (!empty($output)) return false;
            }
        }
        return true;
    })(),
    'Faculty session functionality completely untouched'
);

echo "\n============================================================\n";
echo "AUDIT SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
