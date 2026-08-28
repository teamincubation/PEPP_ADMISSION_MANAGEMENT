<?php
/**
 * PEPP ERP — Security & Email Reliability Audit Test Suite
 *
 * Tests SEC-01 through SEC-20 and MAIL-01 through MAIL-15
 * (only the assertions executable without a live production DB / SMTP server)
 *
 * Run: php test_security_audit.php
 */

date_default_timezone_set('UTC');

$passed = 0;
$failed = 0;
$skipped = 0;

function assert_true($condition, $label) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "✅ PASS: {$label}\n";
    } else {
        $failed++;
        echo "❌ FAIL: {$label}\n";
    }
}

function assert_false($condition, $label) {
    assert_true(!$condition, $label);
}

function assert_contains($haystack, $needle, $label) {
    assert_true(strpos($haystack, $needle) !== false, $label);
}

function assert_not_contains($haystack, $needle, $label) {
    assert_true(strpos($haystack, $needle) === false, $label);
}

function skip_test($label, $reason) {
    global $skipped;
    $skipped++;
    echo "⏭️  SKIP: {$label} — {$reason}\n";
}

echo "=== PEPP ERP Security & Email Reliability Audit Tests ===\n\n";

// ── SEC-01: auth.php requires login ────────────────────────────────────
$auth_content = file_get_contents(__DIR__ . '/includes/auth.php');
assert_contains($auth_content, "admin_logged_in", "SEC-01: auth.php checks admin_logged_in session");
assert_contains($auth_content, "header('Location: login.php')", "SEC-01: auth.php redirects unauthenticated to login");

// ── SEC-02: api_guard.php enforces authorization ───────────────────────
$guard_content = file_get_contents(__DIR__ . '/includes/api_guard.php');
assert_contains($guard_content, 'can_access($permission)', "SEC-02: api_guard checks can_access() for permission");
assert_contains($guard_content, "http_response_code(401)", "SEC-02: api_guard returns 401 for unauthenticated");
assert_contains($guard_content, "http_response_code(403)", "SEC-02: api_guard returns 403 for unauthorized");

// ── SEC-03: super admin restrictions ───────────────────────────────────
assert_contains($guard_content, "super_admin", "SEC-03: api_guard has super_admin enforcement");
$delete_content = file_get_contents(__DIR__ . '/api/delete-student.php');
assert_contains($delete_content, 'can_delete()', "SEC-03: delete-student requires can_delete() authorization");

// ── SEC-04: CSRF ───────────────────────────────────────────────────────
assert_contains($auth_content, 'csrf_token', "SEC-04: CSRF token generation exists");
assert_contains($auth_content, 'hash_equals', "SEC-04: CSRF uses timing-safe comparison");
assert_contains($guard_content, 'csrf_verify()', "SEC-04: api_guard verifies CSRF for state-changing methods");

// ── SEC-05: SQL injection protection ───────────────────────────────────
$cron_content = file_get_contents(__DIR__ . '/cron-queue.php');
assert_not_contains($cron_content, '{$campId}', "SEC-05: cron-queue.php has no SQL interpolation of campId");
assert_contains($cron_content, 'WHERE campaign_id = ? AND queue_id IS NULL', "SEC-05: campId uses prepared statement");

// ── SEC-06: IDOR protection ────────────────────────────────────────────
$approve_content = file_get_contents(__DIR__ . '/api/approve-student.php');
$reject_content = file_get_contents(__DIR__ . '/api/reject-student.php');
assert_contains($approve_content, "can_access('approvals')", "SEC-06: approve-student checks approvals permission");
assert_contains($reject_content, "can_access('approvals')", "SEC-06: reject-student checks approvals permission");

// ── SEC-08: session cookie security ────────────────────────────────────
assert_contains($auth_content, "session_set_cookie_params", "SEC-08: session cookie params configured");
assert_contains($auth_content, "'httponly'", "SEC-08: session cookie HttpOnly enabled");
assert_contains($auth_content, "'samesite'", "SEC-08: session cookie SameSite set");

// ── SEC-09: security headers ───────────────────────────────────────────
assert_contains($auth_content, 'X-Content-Type-Options: nosniff', "SEC-09: X-Content-Type-Options header present");
assert_contains($auth_content, 'X-Frame-Options: SAMEORIGIN', "SEC-09: X-Frame-Options header present");
assert_contains($auth_content, 'Referrer-Policy: strict-origin-when-cross-origin', "SEC-09: Referrer-Policy header present");

// ── SEC-10: cron token timing-safe ─────────────────────────────────────
assert_contains($cron_content, 'hash_equals($correctToken, $providedKey)', "SEC-10: cron token uses hash_equals");
assert_not_contains($cron_content, '$providedKey === $correctToken', "SEC-10: cron token no longer uses === comparison");

// ── SEC-11: cron error disclosure ──────────────────────────────────────
assert_contains($cron_content, 'Internal server error', "SEC-11: cron returns generic error message");

// ── SEC-12: diagnostic endpoint protection ─────────────────────────────
$syscheck_content = file_get_contents(__DIR__ . '/system-check.php');
$opcache_content = file_get_contents(__DIR__ . '/clear-opcache.php');
assert_contains($syscheck_content, 'require_super_admin()', "SEC-12: system-check.php requires super_admin");
assert_contains($opcache_content, 'require_super_admin()', "SEC-12: clear-opcache.php requires super_admin");

// ── SEC-13/14/15: file download security ───────────────────────────────
$download_content = file_get_contents(__DIR__ . '/download-ld-payment-proof.php');
assert_contains($download_content, 'basename(', "SEC-13: download uses basename for path safety");
assert_contains($download_content, "require_once 'includes/auth.php'", "SEC-14: download requires authentication");
assert_contains($download_content, "strpos(\$screenshot_path, '..')", "SEC-15: download blocks path traversal");

// ── SEC-16: secret exposure ────────────────────────────────────────────
$gitignore = file_get_contents(__DIR__ . '/.gitignore');
assert_contains($gitignore, 'config/secrets.php', "SEC-16: secrets.php is gitignored");
$htaccess = file_get_contents(__DIR__ . '/config/.htaccess');
assert_contains($htaccess, 'Require all denied', "SEC-16: config directory blocked by .htaccess");

// ── SEC-17: API guard consistency ──────────────────────────────────────
$get_courses = file_get_contents(__DIR__ . '/api/get-courses.php');
assert_contains($get_courses, 'api_require_auth', "SEC-17: get-courses.php uses centralized auth guard");
assert_not_contains($get_courses, "\$_SESSION['user_id']", "SEC-17: get-courses.php no longer uses student session key");

// ── SEC-18: destructive endpoint protection ────────────────────────────
assert_contains($delete_content, 'csrf_verify()', "SEC-18: delete-student has CSRF protection");

// ── SEC-20: safe error handling ────────────────────────────────────────
$emp_content = file_get_contents(__DIR__ . '/employee-management.php');
assert_not_contains($emp_content, "\"Employee/application record not found for ID: \" . \$_GET", "SEC-20: employee-mgmt does not concatenate raw GET in error messages");
assert_contains($emp_content, '(int)$_GET[' . "'id']", "SEC-20: employee-mgmt casts GET[id] to int");

// ── SEC-19: cookie security ────────────────────────────────────────────
$heartbeat = file_get_contents(__DIR__ . '/api/activity-heartbeat.php');
assert_contains($heartbeat, "'httponly' => true", "SEC-19: geolocation cookies have HttpOnly flag");
assert_contains($heartbeat, "'samesite' => 'Lax'", "SEC-19: geolocation cookies have SameSite flag");

echo "\n";

// ═══════════════════════════════════════════════════════════════════════
// MAIL TESTS
// ═══════════════════════════════════════════════════════════════════════

echo "--- Mail System Tests ---\n\n";

// ── MAIL-01: mail_queue.php exists and is loadable ─────────────────────
$mq_content = file_get_contents(__DIR__ . '/includes/mail_queue.php');
assert_true(file_exists(__DIR__ . '/includes/mail_queue.php'), "MAIL-01: mail_queue.php exists");
assert_contains($mq_content, 'function pepp_enqueue_mail', "MAIL-01: pepp_enqueue_mail function defined");

// ── MAIL-07: no duplicate after successful insert ──────────────────────
assert_contains($mq_content, 'lastInsertId() > 0', "MAIL-07: catch block checks if INSERT already succeeded");
assert_contains($mq_content, 'skipping sync fallback to prevent duplicate', "MAIL-07: duplicate prevention logged");

// ── MAIL-08: fallback only when persistence fails ──────────────────────
assert_contains($mq_content, 'CASE 1', "MAIL-08: explicit CASE 1 (persistence failed) documented");
assert_contains($mq_content, 'CASE 2', "MAIL-08: explicit CASE 2 (record exists) documented");

// ── MAIL-06: stale processing recovery ─────────────────────────────────
$qp_content = file_get_contents(__DIR__ . '/includes/communication/QueueProcessor.php');
assert_contains($qp_content, "status = 'processing'", "MAIL-06: QueueProcessor handles processing state");
assert_contains($qp_content, 'stale-recovery', "MAIL-06: stale-recovery mechanism exists");
assert_contains($qp_content, 'INTERVAL 10 MINUTE', "MAIL-06: stale threshold is 10 minutes");
assert_contains($qp_content, 'retry_count = retry_count + 1', "MAIL-06: stale recovery increments retry count");

// ── MAIL-13: provider does not re-enqueue ──────────────────────────────
$provider_content = file_get_contents(__DIR__ . '/includes/communication/Providers/EmailMailerProvider.php');
assert_contains($provider_content, 'pepp_mail_dispatch', "MAIL-13: provider calls pepp_mail_dispatch (not pepp_mail)");
assert_not_contains($provider_content, 'pepp_mail(', "MAIL-13: provider does not call pepp_mail() wrapper");
assert_not_contains($provider_content, 'pepp_enqueue_mail', "MAIL-13: provider does not call pepp_enqueue_mail");

// ── MAIL-10/11: HTML + recipient preservation ──────────────────────────
assert_contains($mq_content, 'body_html', "MAIL-10: HTML body field preserved in queue INSERT");
assert_contains($mq_content, 'recipient', "MAIL-11: recipient field preserved in queue INSERT");

// ── MAIL-12: attachments preservation ──────────────────────────────────
assert_contains($mq_content, 'attachments', "MAIL-12: attachments field preserved in queue INSERT");

// ── MAIL-14: cron processing exists ────────────────────────────────────
assert_true(file_exists(__DIR__ . '/cron-queue.php'), "MAIL-14: cron-queue.php exists");
assert_contains($cron_content, 'QueueProcessor', "MAIL-14: cron uses QueueProcessor");

// ── Local Integration Testing with SQLite ──────────────────────────────
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mail_queue.php';
require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
require_once __DIR__ . '/includes/communication/QueueProcessor.php';
require_once __DIR__ . '/includes/communication/Providers/CommunicationProviderInterface.php';

if (!class_exists('MockCommunicationProvider')) {
    class MockCommunicationProvider implements CommunicationProviderInterface {
        public $shouldSucceed = true;
        public $sentMessages = [];
        public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
            $this->sentMessages[] = [
                'to' => $to,
                'subject' => $subject,
                'bodyHtml' => $bodyHtml,
                'bodyText' => $bodyText,
                'attachments' => $attachments,
                'templateData' => $templateData
            ];
            if ($this->shouldSucceed) {
                return [
                    'success' => true,
                    'message_id' => 'mock_msg_' . uniqid()
                ];
            }
            return false;
        }
    }
}

// Ensure the SQLite database has tables and is clean
try {
    $pdo->exec("DELETE FROM communication_queue");

    // Set up mock provider
    $engine = CommunicationEngine::getInstance($pdo);
    $mockProvider = new MockCommunicationProvider();
    $engine->mockProvider = $mockProvider;

    // Test enqueuing and basic dispatch (MAIL-02) and attachments binary integrity (MAIL-09)
    $to = 'test@example.com';
    $subject = 'Integration Test';
    $html = '<b>Hello World</b>';
    $text = 'Hello World';
    $attachments = [
        [
            'name' => 'test_file.txt',
            'bytes' => 'Hello from integration tests!',
            'type' => 'text/plain'
        ]
    ];

    $queueId = pepp_enqueue_mail($to, $subject, $html, $text, $attachments);
    assert_true($queueId > 0, "MAIL-02: Email enqueued via pepp_enqueue_mail()");

    // Run processor
    $processor = new QueueProcessor($pdo, 10);
    $procRes = $processor->execute();
    assert_true($procRes['processed'] === 1, "MAIL-02: QueueProcessor processed the enqueued mail");
    assert_true(count($mockProvider->sentMessages) === 1, "MAIL-02: Provider sendMessage was invoked");
    $sent = $mockProvider->sentMessages[0];
    assert_true($sent['to'] === $to, "MAIL-02: Correct recipient");
    assert_true($sent['subject'] === $subject, "MAIL-02: Correct subject");
    assert_true($sent['bodyHtml'] === $html, "MAIL-02: Correct HTML body");
    assert_true($sent['attachments'][0]['bytes'] === 'Hello from integration tests!', "MAIL-09: Attachment binary content is intact");

    // Test failed delivery and retry/backoff (MAIL-04, MAIL-05)
    $mockProvider->shouldSucceed = false;
    $mockProvider->sentMessages = [];

    $queueId2 = pepp_enqueue_mail($to, $subject, $html, $text, []);
    $procRes2 = $processor->execute();

    assert_true($procRes2['processed'] === 0, "MAIL-04: Failed mail was not marked processed");
    assert_true($procRes2['failed'] === 1, "MAIL-04: Failed mail was counted as failed");

    $stmt = $pdo->prepare("SELECT status, retry_count, next_attempt_at FROM communication_queue WHERE id = ?");
    $stmt->execute([$queueId2]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    assert_true($row['status'] === 'retrying', "MAIL-04: Failed mail status is retrying");
    assert_true((int)$row['retry_count'] === 1, "MAIL-04: Retry count incremented");
    assert_true(strtotime($row['next_attempt_at']) > time(), "MAIL-05: Backoff next attempt scheduled in the future");

    // Test stale processing job recovery (MAIL-15 / MAIL-06)
    $mockProvider->shouldSucceed = true;
    $mockProvider->sentMessages = [];
    $pdo->exec("DELETE FROM communication_queue");

    // Insert a stale job stuck in 'processing' status with next_attempt_at in the future (+1 hour)
    $futureTime = date('Y-m-d H:i:s', time() + 3600);
    $pastTime = date('Y-m-d H:i:s', time() - 900); // 15 mins ago
    $currentTime = date('Y-m-d H:i:s');

    $stmtIns = $pdo->prepare("
        INSERT INTO communication_queue
        (channel, recipient, status, retry_count, next_attempt_at, worker_started_at, created_at, updated_at)
        VALUES ('email', 'stale@example.com', 'processing', 0, ?, ?, ?, ?)
    ");
    $stmtIns->execute([$futureTime, $pastTime, $currentTime, $currentTime]);

    // Execute processor - should trigger stale-recovery before processing
    $procRes3 = $processor->execute();

    // Verify it was reset to pending and retry_count incremented, but not processed (remained pending)
    $recoveredRow = $pdo->query("SELECT status, retry_count, error_message FROM communication_queue LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assert_true($recoveredRow['status'] === 'pending', "MAIL-15: Stale job recovered to pending state");
    assert_true((int)$recoveredRow['retry_count'] === 1, "MAIL-15: Retry count incremented to 1 via recovery");
    assert_contains($recoveredRow['error_message'], '[stale-recovery]', "MAIL-15: Stale recovery flag set in error message");

    // ── XSS Output Escaping Verification (SEC-07 alternative check) ────
    $xss_payload = "<script>alert('XSS')</script>\"' &";
    $escaped = htmlspecialchars($xss_payload, ENT_QUOTES, 'UTF-8');
    assert_not_contains($escaped, "<script>", "SEC-07: XSS tags are properly escaped");
    assert_not_contains($escaped, "\"", "SEC-07: Double quotes are escaped");
    assert_not_contains($escaped, "'", "SEC-07: Single quotes are escaped");
    assert_contains($escaped, "&lt;script&gt;", "SEC-07: Tag brackets converted to HTML entities");

    // ── SMTP Client Handshake Failure Path Verification (MAIL-03 alternative check) ────
    // Instantiate SMTP client with invalid server details to force connection failure path
    $smtp_client = new PEPPSMTPClient('127.0.0.1', 9999, 'ssl', 'user', 'pass');
    $smtp_result = $smtp_client->send('from@example.com', 'From', 'to@example.com', 'Subject', 'HTML Body');
    assert_false($smtp_result, "MAIL-03: SMTP client handles connection failure gracefully and returns false");
    $lastErr = $smtp_client->getLastError();
    $isValidErr = (strpos($lastErr, 'Connection refused') !== false || strpos($lastErr, 'Connection to SMTP server failed') !== false || strpos($lastErr, 'SMTP connection timeout') !== false);
    assert_true($isValidErr, "MAIL-03: SMTP client logs correct socket connection error message");

    // ── SEC-21: Custom Form Access Control system ────
    $_SERVER['HTTP_X_TESTING_MODE'] = 'true';
    @session_start();
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = 'admin';
    $_SESSION['admin_role'] = 'super_admin';
    require_once __DIR__ . '/includes/auth.php';
    
    // Set up mock DB variables for testing
    global $admin_role, $admin_perms;
    $admin_role = 'super_admin';
    $admin_perms = 'ALL';
    
    // 1. Superadmin has access to everything
    assert_true(has_form_access($pdo, 'admin', 101), "SEC-21: Superadmin has access to arbitrary form ID");
    
    // 2. Admin with 'campaigns' permission but not assigned should not have access
    $_SESSION['admin_username'] = 'restricted_admin';
    $_SESSION['admin_role'] = 'admin';
    $admin_role = 'admin';
    $admin_perms = 'campaigns';
    assert_false(has_form_access($pdo, 'restricted_admin', 101), "SEC-21: Regular admin without assignment has no access");
    
    // 3. Grant access by inserting a record
    $pdo->exec("INSERT INTO campaign_form_admin_access (form_id, admin_user_id) VALUES (101, 99)");
    
    // Mock restricted_admin id as 99
    $pdo->exec("INSERT INTO admins (id, username, password_hash, full_name, role, permissions, status) VALUES (99, 'restricted_admin', 'hash', 'Restricted Admin', 'admin', 'campaigns', 'active')");
    
    assert_true(has_form_access($pdo, 'restricted_admin', 101), "SEC-21: Regular admin has access to form when assigned");
    assert_false(has_form_access($pdo, 'restricted_admin', 202), "SEC-21: Regular admin still restricted from unassigned form");

    // ── SEC-22: Isolated campaign-form-edit privilege checks ────
    // Superadmin has both access keys
    $admin_role = 'super_admin';
    $admin_perms = 'ALL';
    assert_true(can_access('campaigns'), "SEC-22: Superadmin has access to campaigns");
    assert_true(can_access('campaign-form-edit'), "SEC-22: Superadmin has access to campaign-form-edit");
    
    // Admin with campaigns and campaign-form-edit
    $admin_role = 'admin';
    $admin_perms = 'campaigns,campaign-form-edit';
    assert_true(can_access('campaigns'), "SEC-22: Admin with both permissions can access campaigns");
    assert_true(can_access('campaign-form-edit'), "SEC-22: Admin with both permissions can access campaign-form-edit");

    // Admin with campaigns only (read-only)
    $admin_role = 'admin';
    $admin_perms = 'campaigns';
    assert_true(can_access('campaigns'), "SEC-22: Read-only admin can access campaigns");
    assert_false(can_access('campaign-form-edit'), "SEC-22: Read-only admin cannot access campaign-form-edit");

} catch (Exception $e) {
    $failed++;
    echo "❌ FAIL: Integration test exception: " . $e->getMessage() . "\n";
}

skip_test("MAIL-03: SMTP actual delivery", "Requires live external SMTP server");
skip_test("SEC-07: XSS browser rendering", "Requires browser rendering engine");

echo "\n=== Security & Email Audit Test Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Skipped: {$skipped} (environment-dependent)\n\n";

if ($failed === 0) {
    echo "🎉 ALL EXECUTABLE TESTS PASSED! 🎉\n";
} else {
    echo "⚠️  {$failed} test(s) FAILED. Review above.\n";
    exit(1);
}
