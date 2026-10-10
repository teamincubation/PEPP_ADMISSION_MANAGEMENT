<?php
/**
 * PEPP Updates — View Count Schema Independence Regression Audit
 *
 * Verifies that the public portal queries and visit logging work seamlessly
 * against the production database schema where `updates_posts.view_count` does NOT exist.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($details ? " - Details: {$details}" : '') . "\n";
    }
}

echo "============================================================\n";
echo "PEPP UPDATES — VIEW COUNT REGRESSION AUDIT\n";
echo "============================================================\n\n";

// ── 1. Create In-Memory SQLite Database with Production Schema ───
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Production schema: updates_posts does NOT have view_count
$pdo->exec("
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

    CREATE TABLE updates_post_categories (
        post_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        PRIMARY KEY (post_id, category_id)
    );

    CREATE TABLE updates_keywords (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        keyword TEXT NOT NULL,
        UNIQUE (category_id, keyword)
    );

    CREATE TABLE updates_post_keywords (
        post_id INTEGER NOT NULL,
        keyword_id INTEGER NOT NULL,
        PRIMARY KEY (post_id, keyword_id)
    );

    CREATE TABLE updates_visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        ip_hash TEXT NOT NULL,
        ip_address TEXT,
        user_agent TEXT,
        referer TEXT,
        session_id TEXT,
        latitude REAL,
        longitude REAL,
        accuracy REAL,
        location_status TEXT DEFAULT 'prompt',
        visit_date TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE updates_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Verify that updates_posts has NO view_count column
$cols = $pdo->query("PRAGMA table_info(updates_posts)")->fetchAll();
$colNames = array_column($cols, 'name');
assert_test('Schema: updates_posts does NOT contain view_count column', !in_array('view_count', $colNames, true));

// Insert sample data
$pdo->exec("
    INSERT INTO updates_categories (id, name, slug) VALUES (1, 'Entrance Exams', 'entrance-exams');
    INSERT INTO updates_categories (id, name, slug) VALUES (2, 'Admissions', 'admissions');

    INSERT INTO updates_posts (id, title, slug, short_description, full_description, status, publish_at)
    VALUES (1, 'CUET PG 2027 Registration', 'cuet-pg-2027', 'Short desc 1', '<p>Full content 1</p>', 'published', '2026-01-01 00:00:00');

    INSERT INTO updates_posts (id, title, slug, short_description, full_description, status, publish_at)
    VALUES (2, 'Kerala LLB 2027 Allotment', 'kerala-llb-2027', 'Short desc 2', '<p>Full content 2</p>', 'published', '2026-01-02 00:00:00');

    INSERT INTO updates_posts (id, title, slug, short_description, full_description, status, publish_at)
    VALUES (3, 'Draft Upcoming Update', 'draft-update', 'Draft desc', '<p>Draft content</p>', 'draft', NULL);

    INSERT INTO updates_post_categories (post_id, category_id) VALUES (1, 1);
    INSERT INTO updates_post_categories (post_id, category_id) VALUES (2, 1);
    INSERT INTO updates_post_categories (post_id, category_id) VALUES (2, 2);
");

// Include public portal functions
require_once __DIR__ . '/public_updates/includes/functions.php';

// ── 2. Test Post Retrieval Queries Without view_count Column ─────
try {
    $result = pepp_public_get_posts($pdo);
    assert_test('Query: pepp_public_get_posts executes without error on schema lacking view_count', true);
    assert_test('Query: pepp_public_get_posts returns published posts', count($result['posts']) === 2);
    assert_test('View Count: Initial view_count calculated as 0 from empty visits', (int)$result['posts'][0]['view_count'] === 0);
} catch (Throwable $e) {
    assert_test('Query: pepp_public_get_posts executes without error on schema lacking view_count', false, $e->getMessage());
}

try {
    $single = pepp_public_get_post_by_slug($pdo, 'cuet-pg-2027');
    assert_test('Query: pepp_public_get_post_by_slug executes without error on schema lacking view_count', $single !== null);
    assert_test('View Count: Single post initial view_count is 0', (int)($single['view_count'] ?? -1) === 0);
} catch (Throwable $e) {
    assert_test('Query: pepp_public_get_post_by_slug executes without error on schema lacking view_count', false, $e->getMessage());
}

try {
    $related = pepp_public_get_related_posts($pdo, 1, [1]);
    assert_test('Query: pepp_public_get_related_posts executes without error on schema lacking view_count', count($related) === 1);
    assert_test('View Count: Related post initial view_count is 0', (int)($related[0]['view_count'] ?? -1) === 0);
} catch (Throwable $e) {
    assert_test('Query: pepp_public_get_related_posts executes without error on schema lacking view_count', false, $e->getMessage());
}

// ── 3. Test Visit Logging & Dynamic View Calculation ─────────────
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test Agent';

try {
    pepp_public_log_visit($pdo, 1);
    assert_test('Logging: pepp_public_log_visit executes cleanly without trying to update updates_posts.view_count', true);
} catch (Throwable $e) {
    assert_test('Logging: pepp_public_log_visit executes cleanly without trying to update updates_posts.view_count', false, $e->getMessage());
}

// Check visit row inserted in updates_visits
$visitCount = (int)$pdo->query("SELECT COUNT(*) FROM updates_visits WHERE post_id = 1")->fetchColumn();
assert_test('Logging: Visit recorded in updates_visits', $visitCount === 1);

// Now fetch single post and verify view_count is 1
$singleAfter1 = pepp_public_get_post_by_slug($pdo, 'cuet-pg-2027');
assert_test('View Count: Post view_count is dynamically calculated as 1 after visit', (int)($singleAfter1['view_count'] ?? 0) === 1);

// Fetch posts list and verify view_count is 1
$postsAfter1 = pepp_public_get_posts($pdo);
$post1 = null;
foreach ($postsAfter1['posts'] as $p) {
    if ((int)$p['id'] === 1) {
        $post1 = $p;
        break;
    }
}
assert_test('View Count: Homepage listing calculates view_count as 1', (int)($post1['view_count'] ?? 0) === 1);

// ── 4. Daily Deduplication Test ──────────────────────────────────
// Same IP on the same day visits again
pepp_public_log_visit($pdo, 1);
$visitCountDedup = (int)$pdo->query("SELECT COUNT(*) FROM updates_visits WHERE post_id = 1")->fetchColumn();
assert_test('Deduplication: Duplicate visit from same IP on same day is ignored in updates_visits', $visitCountDedup === 1);

$singleAfterDedup = pepp_public_get_post_by_slug($pdo, 'cuet-pg-2027');
assert_test('Deduplication: Dynamic view_count remains 1 after duplicate visit attempt', (int)($singleAfterDedup['view_count'] ?? 0) === 1);

// ── 5. Second Visitor on Same Day ────────────────────────────────
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
pepp_public_log_visit($pdo, 1);
$visitCount2 = (int)$pdo->query("SELECT COUNT(*) FROM updates_visits WHERE post_id = 1")->fetchColumn();
assert_test('Multi-visitor: Different IP increments updates_visits row count to 2', $visitCount2 === 2);

$singleAfterVisitor2 = pepp_public_get_post_by_slug($pdo, 'cuet-pg-2027');
assert_test('Multi-visitor: Dynamic view_count increments to 2', (int)($singleAfterVisitor2['view_count'] ?? 0) === 2);

// ── 6. Portal General Visit (No Post ID) ─────────────────────────
try {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.30';
    pepp_public_log_visit($pdo, null);
    $generalVisit = (int)$pdo->query("SELECT COUNT(*) FROM updates_visits WHERE post_id IS NULL")->fetchColumn();
    assert_test('General Visit: Portal homepage visit (null post_id) logged successfully', $generalVisit === 1);
} catch (Throwable $e) {
    assert_test('General Visit: Portal homepage visit logged successfully', false, $e->getMessage());
}

// ── 7. Direct Negative Assertion ─────────────────────────────────
// Confirm that a direct query referencing updates_posts.view_count throws PDOException
$queryFailedAsExpected = false;
try {
    $pdo->query("SELECT view_count FROM updates_posts WHERE id = 1");
} catch (PDOException $e) {
    $queryFailedAsExpected = true;
}
assert_test('Negative Proof: Direct query for updates_posts.view_count fails on test schema', $queryFailedAsExpected);

// ── Summary Report ───────────────────────────────────────────────
echo "\n============================================================\n";
echo "REGRESSION RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
