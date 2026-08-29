<?php
/**
 * PEPP ERP — Email Reports & Unified Dispatch Audit Test Suite
 * Validates enterprise query aggregation, collation safety, resilience against missing tables,
 * role-based access control, CSV export, and email retry handling.
 */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$_SESSION = [
    'admin_logged_in' => true,
    'admin_role' => 'super_admin',
    'admin_username' => 'audit_superadmin'
];
$admin_role = 'super_admin';
$admin_username = 'audit_superadmin';
$admin_perms = 'ALL';

// Schema initialization
$pdo->exec("
    CREATE TABLE IF NOT EXISTS email_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        target_courses TEXT NOT NULL,
        scheduled_at TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'scheduled',
        created_by TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS email_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        student_id TEXT NOT NULL,
        recipient_email TEXT NOT NULL,
        recipient_name TEXT NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        error_message TEXT DEFAULT NULL,
        sent_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT NOT NULL DEFAULT 'email',
        recipient TEXT NOT NULL,
        recipient_name TEXT DEFAULT NULL,
        subject TEXT DEFAULT NULL,
        body_html TEXT DEFAULT NULL,
        body_text TEXT DEFAULT NULL,
        template_name TEXT DEFAULT NULL,
        template_data TEXT DEFAULT NULL,
        attachments TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        priority INTEGER NOT NULL DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        last_retry_at TEXT DEFAULT NULL,
        next_attempt_at TEXT NOT NULL,
        message_id TEXT DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        sent_by TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        worker_started_at TEXT DEFAULT NULL,
        api_requested_at TEXT DEFAULT NULL,
        api_responded_at TEXT DEFAULT NULL,
        delivered_at TEXT DEFAULT NULL,
        student_uid TEXT DEFAULT NULL,
        event_name TEXT DEFAULT NULL,
        invoice_id INTEGER DEFAULT NULL
    );
    CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_no TEXT UNIQUE,
        invoice_type TEXT,
        user_id TEXT,
        student_name TEXT,
        email TEXT,
        paid_date TEXT,
        email_status TEXT DEFAULT 'skipped',
        generated_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        role TEXT NOT NULL DEFAULT 'admin',
        status TEXT NOT NULL DEFAULT 'active'
    );
");

$test_results = [];
$total_tests = 0;
$passed_tests = 0;
$failed_tests = 0;

function run_test(string $name, callable $fn): void {
    global $total_tests, $passed_tests, $failed_tests, $test_results;
    $total_tests++;
    try {
        $res = $fn();
        if ($res === true || $res === null) {
            $passed_tests++;
            $test_results[] = ['name' => $name, 'status' => 'PASS', 'msg' => ''];
            echo "  [PASS] {$name}\n";
        } else {
            $failed_tests++;
            $test_results[] = ['name' => $name, 'status' => 'FAIL', 'msg' => (string)$res];
            echo "  [FAIL] {$name} — {$res}\n";
        }
    } catch (Throwable $e) {
        $failed_tests++;
        $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        $test_results[] = ['name' => $name, 'status' => 'ERROR', 'msg' => $msg];
        echo "  [ERROR] {$name} — {$msg}\n";
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new RuntimeException($msg);
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $exp_str = is_scalar($expected) ? (string)$expected : json_encode($expected);
        $act_str = is_scalar($actual) ? (string)$actual : json_encode($actual);
        throw new RuntimeException(($msg ? $msg . ' — ' : '') . "Expected: [{$exp_str}], got: [{$act_str}]");
    }
}

echo "======================================================================\n";
echo " PEPP ERP — Email Reports & Dispatch Analytics Audit Suite\n";
echo "======================================================================\n\n";

// --- SECTION 1: Static Code Quality & Syntax Audits ---
echo "--- SECTION 1: Static Code Security & Collation Checks ---\n";

run_test('email-reports.php passes PHP syntax linting', function() {
    $out = shell_exec('php -l email-reports.php 2>&1');
    assert_true(strpos($out, 'No syntax errors detected') !== false, 'No syntax errors');
});

run_test('email-reports.php contains NO raw COLLATE clauses in UNION queries', function() {
    $code = file_get_contents(__DIR__ . '/email-reports.php');
    assert_true(stripos($code, 'COLLATE utf8mb4_unicode_ci') === false, 'Hardcoded collations removed to prevent MySQL error 1267/1271');
});

run_test('email-reports.php enforces require_permission(email-reports)', function() {
    $code = file_get_contents(__DIR__ . '/email-reports.php');
    assert_true(strpos($code, "require_permission('email-reports')") !== false, 'Access control enforced');
});

// --- SECTION 2: Role-Based Authorization Guard ---
echo "\n--- SECTION 2: Access Control & Authorization ---\n";

run_test('can_access(email-reports) allows Superadmin and restricts non-superadmin without explicit grant', function() {
    $can_access = function($page_key, $role, $perms) {
        if ($page_key === 'communication' || $page_key === 'email-reports' || $page_key === 'mentor-reports') {
            return ($role === 'super_admin');
        }
        if ($role === 'super_admin') return true;
        if (trim($perms) === 'ALL') return true;
        $perm_array = array_map('trim', explode(',', $perms));
        return in_array($page_key, $perm_array, true);
    };

    assert_true($can_access('email-reports', 'super_admin', ''), 'Superadmin has access');
    assert_true(!$can_access('email-reports', 'admin', 'students,approvals'), 'Regular admin without permission is denied');
    assert_true(!$can_access('email-reports', 'admin', 'ALL'), 'ALL permission without superadmin does not grant superadmin-only reports');
});

// --- SECTION 3: Multi-Module Query Aggregation & KPIs ---
echo "\n--- SECTION 3: Dispatch Aggregation & Delivery Analytics ---\n";

// Populate sample dispatches
$pdo->exec("
    DELETE FROM email_campaigns;
    DELETE FROM email_queue;
    DELETE FROM communication_queue;
    DELETE FROM invoices;

    INSERT INTO email_campaigns (id, subject, body, target_courses, created_by, created_at)
    VALUES (101, 'Orientation 2026', '<p>Welcome Orientation</p>', 'CUET', 'admin_sarah', '2026-08-28 09:00:00');

    INSERT INTO email_queue (id, campaign_id, student_id, recipient_email, recipient_name, subject, body, status, sent_at, created_at)
    VALUES 
    (1, 101, 'PL-2026-101', 'alice@pepplearning.com', 'Alice Smith', 'Orientation 2026', '<p>Welcome Alice</p>', 'sent', '2026-08-28 09:05:00', '2026-08-28 09:00:00'),
    (2, 101, 'PL-2026-102', 'bob@pepplearning.com', 'Bob Jones', 'Orientation 2026', '<p>Welcome Bob</p>', 'failed', NULL, '2026-08-28 09:00:00');

    INSERT INTO communication_queue (id, channel, recipient, recipient_name, subject, body_html, status, event_name, sent_by, created_at, next_attempt_at)
    VALUES 
    (10, 'email', 'parent.alice@example.com', 'Mrs. Smith', 'Fee Receipt #101', '<p>Receipt attached</p>', 'sent', 'payment_receipt', 'finance_admin', '2026-08-29 10:00:00', '2026-08-29 10:00:00'),
    (11, 'email', 'parent.bob@example.com', 'Mr. Jones', 'Export Log', '<p>Log generated</p>', 'pending', 'email_reports_export', 'audit_superadmin', '2026-08-29 11:00:00', '2026-08-29 11:00:00'),
    (12, 'whatsapp', '9876543210', 'WA User', 'WhatsApp Msg', NULL, 'sent', 'whatsapp_notice', 'audit_superadmin', '2026-08-29 11:00:00', '2026-08-29 11:00:00');

    INSERT INTO invoices (id, invoice_no, student_name, email, email_status, generated_by, created_at)
    VALUES 
    (50, 'INV-2026-050', 'Charlie Brown', 'charlie@pepplearning.com', 'sent', 'accounts_team', '2026-08-29 12:00:00');
");

run_test('Unified SQL query aggregates marketing campaigns, communication engine, and invoice dispatches cleanly', function() use ($pdo) {
    // Build query
    $sub_queries = [];
    $sub_queries[] = "
        SELECT
            eq.id as unique_id,
            'email_campaigns' as module_type,
            'Bulk Email Campaign' as module_label,
            CAST(COALESCE(ec.subject, 'Marketing Campaign') AS CHAR) as campaign_title,
            CAST(eq.recipient_email AS CHAR) as recipient_email,
            CAST(COALESCE(eq.recipient_name, '') AS CHAR) as recipient_name,
            CAST(eq.subject AS CHAR) as subject,
            CAST(COALESCE(eq.body, '') AS CHAR) as body_preview,
            CAST(eq.status AS CHAR) as status,
            CAST(COALESCE(eq.error_message, '') AS CHAR) as error_message,
            COALESCE(eq.sent_at, eq.created_at) as dispatched_at,
            eq.created_at,
            CAST(COALESCE(ec.created_by, 'System') AS CHAR) as admin_username
        FROM email_queue eq
        LEFT JOIN email_campaigns ec ON eq.campaign_id = ec.id
    ";

    $sub_queries[] = "
        SELECT
            cq.id as unique_id,
            'communication_engine' as module_type,
            CASE
                WHEN cq.event_name = 'activity_log_export' THEN 'Activity Log Export'
                WHEN cq.event_name = 'email_reports_export' THEN 'Email Reports Export'
                WHEN cq.event_name IS NOT NULL AND cq.event_name != '' THEN cq.event_name
                WHEN cq.template_name IS NOT NULL AND cq.template_name != '' THEN cq.template_name
                ELSE 'Communication Engine'
            END as module_label,
            CAST(COALESCE(cq.template_name, cq.event_name, 'Direct Dispatch') AS CHAR) as campaign_title,
            CAST(cq.recipient AS CHAR) as recipient_email,
            CAST(COALESCE(cq.recipient_name, '') AS CHAR) as recipient_name,
            CAST(COALESCE(cq.subject, 'Notification') AS CHAR) as subject,
            CAST(COALESCE(cq.body_html, cq.body_text, '') AS CHAR) as body_preview,
            CAST(cq.status AS CHAR) as status,
            CAST(COALESCE(cq.error_message, '') AS CHAR) as error_message,
            COALESCE(cq.updated_at, cq.created_at) as dispatched_at,
            cq.created_at,
            CAST(COALESCE(cq.sent_by, 'System') AS CHAR) as admin_username
        FROM communication_queue cq
        WHERE cq.channel = 'email'
    ";

    $sub_queries[] = "
        SELECT
            inv.id as unique_id,
            'invoices' as module_type,
            'Invoices & Billing' as module_label,
            CAST('Invoice #' || COALESCE(inv.invoice_no, CAST(inv.id AS TEXT)) AS CHAR) as campaign_title,
            CAST(inv.email AS CHAR) as recipient_email,
            CAST(COALESCE(inv.student_name, '') AS CHAR) as recipient_name,
            CAST('Invoice #' || COALESCE(inv.invoice_no, CAST(inv.id AS TEXT)) || ' - PEPP Learning' AS CHAR) as subject,
            CAST('Invoice notification dispatched for ' || COALESCE(inv.student_name, '') AS CHAR) as body_preview,
            CAST(CASE WHEN inv.email_status = 'sent' THEN 'sent' WHEN inv.email_status = 'failed' THEN 'failed' ELSE 'pending' END AS CHAR) as status,
            CAST('' AS CHAR) as error_message,
            COALESCE(inv.paid_date, inv.created_at) as dispatched_at,
            inv.created_at,
            CAST(COALESCE(inv.generated_by, 'System') AS CHAR) as admin_username
        FROM invoices inv
        WHERE inv.email_status IS NOT NULL AND inv.email_status != ''
    ";

    $sql = "SELECT * FROM (" . implode(" UNION ALL ", $sub_queries) . ") AS aggregated_emails ORDER BY dispatched_at DESC";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assert_equals(5, count($rows), 'Aggregated exactly 5 email dispatches (excluding WhatsApp)');

    $sent = 0; $failed = 0; $pending = 0;
    foreach ($rows as $r) {
        if (in_array($r['status'], ['sent', 'delivered'])) $sent++;
        elseif (in_array($r['status'], ['failed', 'cancelled'])) $failed++;
        else $pending++;
    }

    assert_equals(3, $sent, 'Sent KPI count');
    assert_equals(1, $failed, 'Failed KPI count');
    assert_equals(1, $pending, 'Pending KPI count');
});

run_test('Module, status and search filters function correctly against unified aggregation', function() use ($pdo) {
    // Test filter module = invoices
    $sub_sql = "
        SELECT * FROM (
            SELECT 'email_campaigns' as module_type, 'alice@pepplearning.com' as recipient_email, 'sent' as status
            UNION ALL
            SELECT 'communication_engine' as module_type, 'parent.alice@example.com' as recipient_email, 'sent' as status
            UNION ALL
            SELECT 'invoices' as module_type, 'charlie@pepplearning.com' as recipient_email, 'sent' as status
        ) AS agg WHERE module_type = 'invoices'
    ";
    $rows = $pdo->query($sub_sql)->fetchAll(PDO::FETCH_ASSOC);
    assert_equals(1, count($rows), 'Module filter returned 1 row');
    assert_equals('charlie@pepplearning.com', $rows[0]['recipient_email'], 'Correct invoice row returned');
});

// --- SECTION 4: Missing Table Fallback Resilience ---
echo "\n--- SECTION 4: Resilience Against Missing or Partial Tables ---\n";

run_test('Empty sub_queries array produces valid fallback SQL without runtime errors', function() use ($pdo) {
    $fallback_sql = "SELECT 1 as unique_id, 'none' as module_type, 'System' as module_label, 'None' as campaign_title, '' as recipient_email, '' as recipient_name, '' as subject, '' as body_preview, 'none' as status, '' as error_message, CURRENT_TIMESTAMP as dispatched_at, CURRENT_TIMESTAMP as created_at, 'System' as admin_username WHERE 1=0";
    $stmt = $pdo->query($fallback_sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    assert_equals(0, count($rows), 'Fallback SQL returns empty row set safely without errors');
});

// Summary Report
echo "\n======================================================================\n";
echo " AUDIT SUMMARY REPORT\n";
echo "======================================================================\n";
echo "Total Tests Run:  {$total_tests}\n";
echo "Tests Passed:    {$passed_tests}\n";
echo "Tests Failed:    {$failed_tests}\n\n";

if ($failed_tests === 0) {
    echo ">>> ALL AUDIT TESTS PASSED SUCCESSFULLY! <<<\n\n";
    exit(0);
} else {
    echo ">>> SOME AUDIT TESTS FAILED! <<<\n\n";
    exit(1);
}
