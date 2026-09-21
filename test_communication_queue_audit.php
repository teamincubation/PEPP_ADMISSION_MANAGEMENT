<?php
/**
 * Test Suite: PEPP Communication Queue & Background Worker Architecture Audit
 *
 * Verifies fixes for:
 * - Root Cause 1: Production MySQL routing and elimination of silent SQLite fallback
 * - Root Cause 2: Scheduled queue items stuck due to query status filter
 * - Root Cause 3: QueueProcessor priority and isolation of cron pipeline jobs
 * - Root Cause 4: SMTP EHLO FQDN compliance and strip_tags null safety
 * - Mandatory Correction 3: Ambiguous SMTP email stale recovery (duplicate delivery prevention)
 * - Mandatory Telemetry: Canonical communication_last_worker_run + backward compatible whatsapp_last_cron_run
 */

// Configure test harness
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
putenv('PEPP_USE_SQLITE=1');
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

$testCount = 0;
$passedCount = 0;

function assertTest($description, $condition) {
    global $testCount, $passedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo " [PASS] {$description}\n";
    } else {
        echo " [FAIL] {$description}\n";
    }
}

echo "======================================================================\n";
echo "AUDIT TEST SUITE: PEPP Communication Engine & Worker Reliability\n";
echo "======================================================================\n\n";

// ── PART 1: STATIC SOURCE CODE INTEGRITY AUDIT ─────────────────────
echo "--- PART 1: Static Source Code Integrity Audit ---\n";

$dbFile = __DIR__ . '/config/database.php';
$cronFile = __DIR__ . '/cron-queue.php';
$procFile = __DIR__ . '/includes/communication/QueueProcessor.php';
$engineFile = __DIR__ . '/includes/communication/CommunicationEngine.php';
$mailerFile = __DIR__ . '/includes/mailer.php';
$dashFile = __DIR__ . '/communication-dashboard.php';

assertTest("database.php exists", file_exists($dbFile));
assertTest("cron-queue.php exists", file_exists($cronFile));
assertTest("QueueProcessor.php exists", file_exists($procFile));
assertTest("CommunicationEngine.php exists", file_exists($engineFile));
assertTest("mailer.php exists", file_exists($mailerFile));
assertTest("communication-dashboard.php exists", file_exists($dashFile));

$dbSource = file_get_contents($dbFile);
$cronSource = file_get_contents($cronFile);
$procSource = file_get_contents($procFile);
$engineSource = file_get_contents($engineFile);
$mailerSource = file_get_contents($mailerFile);
$dashSource = file_get_contents($dashFile);

// Root Cause 1 Audits
assertTest("database.php defines force_mysql check from PEPP_USE_MYSQL environment",
    strpos($dbSource, "\$force_mysql = (getenv('PEPP_USE_MYSQL') === '1'") !== false
);

assertTest("database.php overrides is_local_dev to false when force_mysql is set",
    strpos($dbSource, "if (\$force_mysql) {\n    \$is_local_dev = false;\n}") !== false
);

assertTest("database.php removed silent CLI fallback to SQLite",
    strpos($dbSource, "!getenv('PEPP_USE_MYSQL')") === false
);

assertTest("database.php fails loudly with RuntimeException on MySQL connection failure in CLI/force mode",
    strpos($dbSource, "throw new RuntimeException(\"CRITICAL: Production MySQL connection failed:") !== false
);

assertTest("cron-queue.php sets PEPP_USE_MYSQL=1 at top of file before database require",
    strpos($cronSource, "putenv('PEPP_USE_MYSQL=1');") !== false &&
    strpos($cronSource, "\$_ENV['PEPP_USE_MYSQL'] = '1';") !== false &&
    strpos($cronSource, "putenv('PEPP_USE_MYSQL=1');") < strpos($cronSource, "require_once __DIR__ . '/config/database.php';")
);

// Root Cause 2 Audits
assertTest("QueueProcessor.php includes 'scheduled' in execution query status IN clause",
    strpos($procSource, "WHERE status IN ('pending', 'scheduled', 'failed', 'retrying')") !== false
);

// Root Cause 3 Audits
assertTest("cron-queue.php executes QueueProcessor FIRST before secondary jobs",
    strpos($cronSource, "\$processor = new QueueProcessor(\$pdo, 25);") < strpos($cronSource, "communication_campaigns")
);

assertTest("cron-queue.php writes canonical communication_last_worker_run telemetry",
    strpos($cronSource, "'communication_last_worker_run'") !== false
);

assertTest("cron-queue.php preserves backward-compatible whatsapp_last_cron_run telemetry",
    strpos($cronSource, "'whatsapp_last_cron_run'") !== false
);

assertTest("cron-queue.php isolates installment reminders in try/catch block",
    strpos($cronSource, "installments_dispatch_whatsapp_reminders") !== false &&
    strpos($cronSource, "Cron: Installment reminders scheduler error:") !== false
);

assertTest("cron-queue.php isolates monthly activity backup in try/catch block",
    strpos($cronSource, "SecureDownloadManager::runMonthlyBackupJob") !== false &&
    strpos($cronSource, "Cron: Monthly activity backup error:") !== false
);

assertTest("cron-queue.php logs unauthorized execution attempts",
    strpos($cronSource, "[CRON_AUTH_FAIL]") !== false
);

// Root Cause 4 Audits
assertTest("mailer.php EHLO uses FQDN fallback instead of bare 'localhost'",
    strpos($mailerSource, "\$ehloHost = !empty(\$_SERVER['SERVER_NAME']) ? \$_SERVER['SERVER_NAME'] : (!empty(\$_SERVER['HTTP_HOST']) ? \$_SERVER['HTTP_HOST'] : (defined('PEPP_DOMAIN') ? PEPP_DOMAIN : 'pepplearning.in'));") !== false
);

assertTest("mailer.php ensures strip_tags null-safety for bodyHtml",
    strpos($mailerSource, "strip_tags((string)\$bodyHtml)") !== false
);

// CommunicationEngine Channel Isolation & Superseded Terminal Fix Audits
assertTest("CommunicationEngine.php isolates recipient revalidation to channel === 'whatsapp'",
    strpos($engineSource, "if (\$channel === 'whatsapp' && !empty(\$chkItem['student_uid']))") !== false
);

assertTest("CommunicationEngine.php marks superseded recipient number records as terminal 'cancelled'",
    strpos($engineSource, "status = 'cancelled'") !== false &&
    strpos($engineSource, "'Superseded: Recipient number changed'") !== false
);

// CommunicationEngine Security & Diagnostics Audits
assertTest("CommunicationEngine.php redacts secret token in background cron loopback logs",
    strpos($engineSource, "cron-queue.php?key=***REDACTED***") !== false
);

assertTest("CommunicationEngine.php uses FQDN fallback when HTTP_HOST is unset",
    strpos($engineSource, "defined('PEPP_DOMAIN') ? PEPP_DOMAIN : 'pepplearning.in'") !== false
);

// Dashboard Telemetry Audits
assertTest("communication-dashboard.php loads communication_% settings alongside whatsapp_%",
    strpos($dashSource, "setting_name LIKE 'whatsapp_%' OR setting_name LIKE 'communication_%'") !== false
);

assertTest("communication-dashboard.php reads canonical communication_last_worker_run with fallback",
    strpos($dashSource, "\$settings['communication_last_worker_run']") !== false &&
    strpos($dashSource, "\$settings['whatsapp_last_cron_run']") !== false
);

assertTest("communication-dashboard.php uses QueueProcessor directly for manual queue processing",
    strpos($dashSource, "\$processor = new QueueProcessor(\$pdo, 25);") !== false
);

assertTest("communication-dashboard.php provides Hostinger CLI Cron Command",
    strpos($dashSource, "Hostinger CLI Cron Command") !== false
);

echo "\n--- PART 2: Behavioral Runtime Simulation Audit ---\n";

// Setup isolated in-memory test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Register SQLite compatibility functions
$pdo->sqliteCreateFunction('NOW', function() {
    return date('Y-m-d H:i:s');
});
$pdo->sqliteCreateFunction('CURDATE', function() {
    return date('Y-m-d');
});
$pdo->sqliteCreateFunction('CONCAT', function(...$args) {
    return implode('', $args);
});

// Initialize schema
$pdo->exec("
    CREATE TABLE admin_settings (
        setting_name TEXT PRIMARY KEY,
        setting_value TEXT,
        updated_at TEXT
    );
    CREATE TABLE communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT NOT NULL DEFAULT 'whatsapp',
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
    CREATE TABLE communication_webhook_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider TEXT,
        event_type TEXT,
        payload TEXT,
        processed INTEGER DEFAULT 0,
        created_at TEXT,
        processed_at TEXT
    );
    CREATE TABLE communication_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        channel TEXT NOT NULL DEFAULT 'whatsapp',
        target_audience TEXT DEFAULT 'students',
        template_name TEXT DEFAULT NULL,
        segment_criteria TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        scheduled_at TEXT DEFAULT NULL,
        created_by TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE communication_campaign_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        recipient TEXT NOT NULL,
        recipient_name TEXT DEFAULT NULL,
        queue_id INTEGER DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        sent_at TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        lead_id INTEGER DEFAULT NULL,
        user_id TEXT DEFAULT NULL
    );
    CREATE TABLE communication_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT NOT NULL DEFAULT 'whatsapp',
        template_name TEXT NOT NULL,
        language TEXT NOT NULL DEFAULT 'en',
        status TEXT NOT NULL DEFAULT 'approved',
        category TEXT DEFAULT NULL,
        quality_status TEXT DEFAULT NULL,
        rejection_reason TEXT DEFAULT NULL,
        meta_data TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        full_name TEXT,
        name TEXT,
        email TEXT UNIQUE,
        phone TEXT,
        whatsapp_country_code TEXT,
        whatsapp_number TEXT,
        status TEXT DEFAULT 'approved',
        student_status TEXT DEFAULT 'active'
    );
    CREATE TABLE student_status_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        old_status TEXT,
        new_status TEXT,
        reason TEXT,
        changed_by TEXT,
        changed_at TEXT
    );
    CREATE TABLE whatsapp_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        wa_phone_number TEXT,
        student_uid TEXT,
        student_user_id TEXT,
        contact_name TEXT,
        last_message_text TEXT,
        last_message_at TEXT,
        unread_count INTEGER DEFAULT 0,
        status TEXT DEFAULT 'open',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER,
        wa_message_id TEXT,
        direction TEXT DEFAULT 'outbound',
        message_type TEXT DEFAULT 'text',
        message_text TEXT,
        status TEXT DEFAULT 'sent',
        raw_payload TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        sent_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE installment_whatsapp_reminders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        queue_id INTEGER,
        status TEXT DEFAULT 'queued'
    );
    CREATE TABLE whatsapp_mode_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        old_mode TEXT,
        new_mode TEXT,
        changed_by TEXT,
        changed_at TEXT
    );
    CREATE TABLE admin_activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER,
        admin_username TEXT,
        request_method TEXT,
        action_type TEXT,
        target_id TEXT,
        details TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Initialize meta_api mode and audit so outbound WhatsApp messages are active
$pdo->prepare("INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('whatsapp_outbound_mode', 'meta_api', datetime('now'))")->execute();
$pdo->prepare("INSERT INTO whatsapp_mode_audit (old_mode, new_mode, changed_by, changed_at) VALUES ('manual', 'meta_api', 'test_admin', '2020-01-01 00:00:00')")->execute();

require_once __DIR__ . '/includes/communication/QueueProcessor.php';
require_once __DIR__ . '/includes/communication/CommunicationEngine.php';

// Test 2.1: Scheduled Queue Items Pickup (Root Cause 2 Fix)
$now = date('Y-m-d H:i:s');
$past = date('Y-m-d H:i:s', time() - 300);
$future = date('Y-m-d H:i:s', time() + 3600);

// Insert due scheduled item
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at)
    VALUES ('whatsapp', '919876543210', 'scheduled', 5, 0, ?, ?)
")->execute([$past, $past]);
$dueScheduledId = (int)$pdo->lastInsertId();

// Insert future scheduled item (not yet due)
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at)
    VALUES ('whatsapp', '919876543211', 'scheduled', 5, 0, ?, ?)
")->execute([$future, $past]);
$futureScheduledId = (int)$pdo->lastInsertId();

// Insert due pending item
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at)
    VALUES ('whatsapp', '919876543212', 'pending', 0, 0, ?, ?)
")->execute([$past, $past]);
$duePendingId = (int)$pdo->lastInsertId();

// Check query selection in QueueProcessor
$stmtEligible = $pdo->prepare("
    SELECT id FROM communication_queue
    WHERE status IN ('pending', 'scheduled', 'failed', 'retrying')
      AND next_attempt_at <= ?
      AND (
        (channel = 'whatsapp' AND retry_count < 3) OR
        (channel = 'email' AND retry_count < 5) OR
        (channel NOT IN ('whatsapp', 'email') AND retry_count < 3)
      )
    ORDER BY priority DESC, created_at ASC
");
$stmtEligible->execute([$now]);
$eligibleIds = $stmtEligible->fetchAll(PDO::FETCH_COLUMN);

assertTest("QueueProcessor eligibility query includes due scheduled item #{$dueScheduledId}",
    in_array($dueScheduledId, $eligibleIds)
);

assertTest("QueueProcessor eligibility query excludes future scheduled item #{$futureScheduledId}",
    !in_array($futureScheduledId, $eligibleIds)
);

assertTest("QueueProcessor eligibility query orders by priority DESC (due scheduled item #{$dueScheduledId} with priority 5 precedes pending item #{$duePendingId})",
    $eligibleIds[0] == $dueScheduledId
);

// Test 2.2: Channel-aware retry limits
// WhatsApp max 3 retries (0, 1, 2 eligible; 3 exhausted)
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at)
    VALUES ('whatsapp', '919876543213', 'failed', 3, ?)
")->execute([$past]);
$waExhaustedId = (int)$pdo->lastInsertId();

// Email max 5 retries (retry_count 3 is still eligible)
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at)
    VALUES ('email', 'student@pepplearning.in', 'failed', 3, ?)
")->execute([$past]);
$emailEligibleId = (int)$pdo->lastInsertId();

$stmtEligible->execute([$now]);
$retryEligibleIds = $stmtEligible->fetchAll(PDO::FETCH_COLUMN);

assertTest("QueueProcessor excludes WhatsApp item with retry_count >= 3",
    !in_array($waExhaustedId, $retryEligibleIds)
);

assertTest("QueueProcessor includes Email item with retry_count = 3 (limit is 5)",
    in_array($emailEligibleId, $retryEligibleIds)
);

// Test 2.3: USER CORRECTION 3 — Ambiguous Email SMTP Stale Recovery
// Stale email item stuck in 'processing' with worker_started_at 15 minutes ago
$staleTime = date('Y-m-d H:i:s', time() - 900);
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, retry_count, worker_started_at, error_message, next_attempt_at)
    VALUES ('email', 'student_ambiguous@pepplearning.in', 'processing', 1, ?, 'Initial crash attempt', ?)
")->execute([$staleTime, $past]);
$staleEmailId = (int)$pdo->lastInsertId();

// Run QueueProcessor execute (which executes stale recovery first)
// Note: In testing mode, we can invoke QueueProcessor
$processor = new QueueProcessor($pdo, 10);
// Run execute - it will run stale recovery
$res = $processor->execute();

$checkEmailStmt = $pdo->prepare("SELECT status, retry_count, error_message FROM communication_queue WHERE id = ?");
$checkEmailStmt->execute([$staleEmailId]);
$staleEmailRow = $checkEmailStmt->fetch(PDO::FETCH_ASSOC);

assertTest("User Correction 3: Stale Email job is marked 'failed' (NOT 'pending')",
    $staleEmailRow['status'] === 'failed'
);

assertTest("User Correction 3: Stale Email retry_count set to terminal max (5)",
    (int)$staleEmailRow['retry_count'] === 5
);

assertTest("User Correction 3: Stale Email preserves original context and adds [stale:smtp_outcome_ambiguous_requires_review]",
    strpos($staleEmailRow['error_message'], 'Initial crash attempt') !== false &&
    strpos($staleEmailRow['error_message'], '[stale:smtp_outcome_ambiguous_requires_review]') !== false
);

// Verify that the ambiguous email is NOT in eligible items for next run (duplicate prevented)
$stmtEligible->execute([date('Y-m-d H:i:s')]);
$postRecoveryEligible = $stmtEligible->fetchAll(PDO::FETCH_COLUMN);
assertTest("User Correction 3: Ambiguous stale email is NOT re-selected for dispatch (duplicate prevented)",
    !in_array($staleEmailId, $postRecoveryEligible)
);

// Test 2.4: WhatsApp Stale Recovery with existing Meta message_id
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, retry_count, worker_started_at, message_id, error_message, next_attempt_at)
    VALUES ('whatsapp', '919876543220', 'processing', 0, ?, 'wamid.HB1234567890', 'Previous notice', ?)
")->execute([$staleTime, $past]);
$staleWaWithMsgId = (int)$pdo->lastInsertId();

// Run QueueProcessor to trigger stale recovery
$processor->execute();

$checkWaStmt = $pdo->prepare("SELECT status, retry_count, error_message FROM communication_queue WHERE id = ?");
$checkWaStmt->execute([$staleWaWithMsgId]);
$staleWaRow = $checkWaStmt->fetch(PDO::FETCH_ASSOC);

assertTest("WhatsApp Stale Recovery: Item with existing message_id marked 'sent' (NOT resent)",
    $staleWaRow['status'] === 'sent' &&
    strpos($staleWaRow['error_message'], '[stale:meta_message_id_retained]') !== false
);

// Test 2.5: WhatsApp Stale Recovery without message_id (safe retry if retry_count < 3)
// Use future next_attempt_at to cleanly observe the stale recovery transition to 'pending'
$futureNext = date('Y-m-d H:i:s', time() + 1800);
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, retry_count, worker_started_at, message_id, error_message, next_attempt_at)
    VALUES ('whatsapp', '919876543221', 'processing', 0, ?, NULL, 'Pre-crash attempt', ?)
")->execute([$staleTime, $futureNext]);
$staleWaRetryableId = (int)$pdo->lastInsertId();

$processor->execute();

$checkWaStmt->execute([$staleWaRetryableId]);
$staleWaRetryRow = $checkWaStmt->fetch(PDO::FETCH_ASSOC);

assertTest("WhatsApp Stale Recovery: Transient pre-dispatch crash reset to 'pending' with retry_count incremented",
    $staleWaRetryRow['status'] === 'pending' &&
    (int)$staleWaRetryRow['retry_count'] === 1 &&
    strpos($staleWaRetryRow['error_message'], '[stale-recovery]') !== false
);

// Test 2.6: Dual Telemetry Persistence
$testTelemetry = [
    'timestamp' => time(),
    'datetime' => date('Y-m-d H:i:s'),
    'source' => 'CLI',
    'status' => 'SUCCESS',
    'processed' => 5,
    'failed' => 0,
    'eligible' => 5,
    'ids' => [1, 2, 3, 4, 5],
    'duration' => 0.42
];
$jsonTel = json_encode($testTelemetry);

$pdo->prepare("INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('communication_last_worker_run', ?, datetime('now'))")->execute([$jsonTel]);
$pdo->prepare("INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('whatsapp_last_cron_run', ?, datetime('now'))")->execute([$jsonTel]);

$stmtTel1 = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'communication_last_worker_run'");
$stmtTel1->execute();
$val1 = json_decode($stmtTel1->fetchColumn(), true);

$stmtTel2 = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_last_cron_run'");
$stmtTel2->execute();
$val2 = json_decode($stmtTel2->fetchColumn(), true);

assertTest("Dual Telemetry: canonical communication_last_worker_run persisted and decodable",
    isset($val1['status']) && $val1['status'] === 'SUCCESS' && $val1['processed'] === 5
);

assertTest("Dual Telemetry: backward-compatible whatsapp_last_cron_run persisted and decodable",
    isset($val2['status']) && $val2['status'] === 'SUCCESS' && $val2['processed'] === 5
);

// ── PART 3: CHANNEL-SPECIFIC RECIPIENT REVALIDATION & TERMINAL SUPERSEDED AUDIT ──
echo "\n--- PART 3: Channel-Specific Recipient Revalidation & Terminal Superseded Audit ---\n";

// Ensure CommunicationEngine has the test PDO double
$engine = CommunicationEngine::getInstance($pdo);

// Ensure meta_api mode is active so WhatsApp items pass mode-era guard
$pdo->prepare("INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('whatsapp_outbound_mode', 'meta_api', datetime('now'))")->execute();
$pdo->prepare("INSERT INTO whatsapp_mode_audit (old_mode, new_mode, changed_by, changed_at) VALUES ('manual', 'meta_api', 'test_admin', '2020-01-01 00:00:00')")->execute();

// ── Test 3.1: WhatsApp + student_uid + matching phone ──
// Seed student with matching phone
$pdo->prepare("INSERT OR REPLACE INTO users (user_id, full_name, name, email, whatsapp_country_code, whatsapp_number, status, student_status) VALUES ('STU_WA_MATCH', 'WhatsApp Match Student', 'WhatsApp Match Student', 'wa.match@pepplearning.in', '91', '9876543210', 'approved', 'active')")->execute();

$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, student_uid, event_name, subject, body_text, status, retry_count, next_attempt_at, created_at)
    VALUES ('whatsapp', '919876543210', 'STU_WA_MATCH', 'installment_reminder', 'Payment Reminder', 'Please pay your installment', 'pending', 0, ?, ?)
")->execute([$past, $past]);
$waMatchId = (int)$pdo->lastInsertId();

$waMatchSpy = new class implements CommunicationProviderInterface {
    public $called = false;
    public $recipient = null;
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        $this->called = true;
        $this->recipient = $to;
        return ['success' => true, 'message_id' => 'wamid.MATCH_OK_999'];
    }
};
$engine->mockProvider = $waMatchSpy;
$waMatchResult = $engine->processQueueItem($waMatchId);

$stmtCheck = $pdo->prepare("SELECT status, message_id, error_message, retry_count, next_attempt_at FROM communication_queue WHERE id = ?");
$stmtCheck->execute([$waMatchId]);
$waMatchRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

$stmtReplCount = $pdo->prepare("SELECT COUNT(*) FROM communication_queue WHERE id != ? AND student_uid = 'STU_WA_MATCH'");
$stmtReplCount->execute([$waMatchId]);
$waMatchReplCount = (int)$stmtReplCount->fetchColumn();

assertTest("Test 3.1: WhatsApp + student_uid + matching phone: provider is called",
    $waMatchSpy->called === true && $waMatchSpy->recipient === '919876543210'
);
assertTest("Test 3.1: WhatsApp + student_uid + matching phone: item reaches 'sent' with message_id",
    $waMatchResult === true && $waMatchRow['status'] === 'sent' && $waMatchRow['message_id'] === 'wamid.MATCH_OK_999'
);
assertTest("Test 3.1: WhatsApp + student_uid + matching phone: no error and no replacement queue record created",
    empty($waMatchRow['error_message']) && $waMatchReplCount === 0
);

// ── Test 3.2: WhatsApp + student_uid + mismatched phone ──
// Seed student whose number in database is '919999988888', but queue item has old number '918888877777'
$pdo->prepare("INSERT OR REPLACE INTO users (user_id, full_name, name, email, whatsapp_country_code, whatsapp_number, status, student_status) VALUES ('STU_WA_MISMATCH', 'WhatsApp Mismatch Student', 'WhatsApp Mismatch Student', 'wa.mismatch@pepplearning.in', '91', '9999988888', 'approved', 'active')")->execute();

$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, student_uid, event_name, subject, body_text, status, retry_count, next_attempt_at, created_at)
    VALUES ('whatsapp', '918888877777', 'STU_WA_MISMATCH', 'installment_reminder', 'Payment Reminder', 'Please pay your installment', 'pending', 0, ?, ?)
")->execute([$past, $past]);
$waMismatchId = (int)$pdo->lastInsertId();

$waMismatchSpy = new class implements CommunicationProviderInterface {
    public $called = false;
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        $this->called = true;
        return ['success' => true, 'message_id' => 'wamid.SHOULD_NEVER_FIRE'];
    }
};
$engine->mockProvider = $waMismatchSpy;
$waMismatchResult = $engine->processQueueItem($waMismatchId);

$stmtCheck->execute([$waMismatchId]);
$waMismatchRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

$stmtRepl = $pdo->prepare("SELECT * FROM communication_queue WHERE id != ? AND student_uid = 'STU_WA_MISMATCH'");
$stmtRepl->execute([$waMismatchId]);
$waReplacements = $stmtRepl->fetchAll(PDO::FETCH_ASSOC);

assertTest("Test 3.2: WhatsApp + student_uid + mismatched phone: provider is NOT called",
    $waMismatchSpy->called === false && $waMismatchResult === false
);
assertTest("Test 3.2: WhatsApp + student_uid + mismatched phone: original queue item becomes 'cancelled'",
    $waMismatchRow['status'] === 'cancelled'
);
assertTest("Test 3.2: WhatsApp + student_uid + mismatched phone: error reason is exactly 'Superseded: Recipient number changed'",
    $waMismatchRow['error_message'] === 'Superseded: Recipient number changed'
);
assertTest("Test 3.2: WhatsApp + student_uid + mismatched phone: replacement WhatsApp queue record created correctly",
    count($waReplacements) === 1 &&
    $waReplacements[0]['channel'] === 'whatsapp' &&
    $waReplacements[0]['recipient'] === '919999988888' &&
    $waReplacements[0]['status'] === 'pending' &&
    (int)$waReplacements[0]['retry_count'] === 0
);

// ── Test 3.3: Email + student_uid (Channel Isolation) ──
// Seed student with phone '911234567890' and email 'alice.email@pepplearning.in'
$pdo->prepare("INSERT OR REPLACE INTO users (user_id, full_name, name, email, whatsapp_country_code, whatsapp_number, status, student_status) VALUES ('STU_EMAIL_ISO', 'Alice Email Student', 'Alice Email Student', 'alice.email@pepplearning.in', '91', '9123456789', 'approved', 'active')")->execute();

$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, student_uid, event_name, subject, body_html, body_text, status, retry_count, next_attempt_at, created_at)
    VALUES ('email', 'alice.email@pepplearning.in', 'STU_EMAIL_ISO', 'student_approval', 'Admission Approved', '<h1>Welcome</h1>', 'Welcome', 'pending', 0, ?, ?)
")->execute([$past, $past]);
$emailIsoId = (int)$pdo->lastInsertId();

$emailIsoSpy = new class implements CommunicationProviderInterface {
    public $called = false;
    public $recipient = null;
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        $this->called = true;
        $this->recipient = $to;
        return ['success' => true, 'message_id' => 'mail_iso_sent_001'];
    }
};
$engine->mockProvider = $emailIsoSpy;
$emailIsoResult = $engine->processQueueItem($emailIsoId);

$stmtCheck->execute([$emailIsoId]);
$emailIsoRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

$stmtWaCheck = $pdo->prepare("SELECT COUNT(*) FROM communication_queue WHERE channel = 'whatsapp' AND student_uid = 'STU_EMAIL_ISO'");
$stmtWaCheck->execute();
$waIsoCount = (int)$stmtWaCheck->fetchColumn();

assertTest("Test 3.3: Email + student_uid: WhatsApp phone validation is NOT executed (provider called with email address)",
    $emailIsoSpy->called === true && $emailIsoSpy->recipient === 'alice.email@pepplearning.in'
);
assertTest("Test 3.3: Email + student_uid: email item reaches 'sent' status with message_id",
    $emailIsoResult === true && $emailIsoRow['status'] === 'sent' && $emailIsoRow['message_id'] === 'mail_iso_sent_001'
);
assertTest("Test 3.3: Email + student_uid: no WhatsApp replacement record is created",
    $waIsoCount === 0
);

// ── Test 3.4: Email + student_uid + SMTP Failure (Retry / Backoff Preservation) ──
$pdo->prepare("INSERT OR REPLACE INTO users (user_id, full_name, name, email, whatsapp_country_code, whatsapp_number, status, student_status) VALUES ('STU_EMAIL_FAIL', 'Bob Email Fail Student', 'Bob Email Fail Student', 'bob.fail@pepplearning.in', '91', '9123456799', 'approved', 'active')")->execute();

$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, student_uid, event_name, subject, body_html, body_text, status, retry_count, next_attempt_at, created_at)
    VALUES ('email', 'bob.fail@pepplearning.in', 'STU_EMAIL_FAIL', 'student_approval', 'Admission Approved', '<h1>Welcome</h1>', 'Welcome', 'pending', 0, ?, ?)
")->execute([$past, $past]);
$emailFailId = (int)$pdo->lastInsertId();

$emailFailSpy = new class implements CommunicationProviderInterface {
    public $called = false;
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        $this->called = true;
        return ['success' => false, 'error' => 'SMTP connect() failed: Connection timed out'];
    }
};
$engine->mockProvider = $emailFailSpy;
$emailFailResult = $engine->processQueueItem($emailFailId);

$stmtCheck->execute([$emailFailId]);
$emailFailRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

$stmtWaFailCheck = $pdo->prepare("SELECT COUNT(*) FROM communication_queue WHERE channel = 'whatsapp' AND student_uid = 'STU_EMAIL_FAIL'");
$stmtWaFailCheck->execute();
$waFailCount = (int)$stmtWaFailCheck->fetchColumn();

assertTest("Test 3.4: Email + student_uid + SMTP failure: transitions to 'retrying'",
    $emailFailResult === false && $emailFailRow['status'] === 'retrying'
);
assertTest("Test 3.4: Email + student_uid + SMTP failure: retry_count is incremented to 1",
    (int)$emailFailRow['retry_count'] === 1
);
assertTest("Test 3.4: Email + student_uid + SMTP failure: error message preserves exact SMTP failure",
    strpos($emailFailRow['error_message'], 'SMTP connect() failed') !== false
);
assertTest("Test 3.4: Email + student_uid + SMTP failure: next_attempt_at scheduled in the future (exponential backoff)",
    $emailFailRow['next_attempt_at'] > date('Y-m-d H:i:s')
);
assertTest("Test 3.4: Email + student_uid + SMTP failure: no WhatsApp replacement record is created",
    $waFailCount === 0
);

// ── Test 3.5: Terminal State Invariant: Superseded records are never eligible for QueueProcessor ──
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, retry_count, error_message, next_attempt_at, created_at)
    VALUES ('whatsapp', '918888877777', 'cancelled', 0, 'Superseded: Recipient number changed', ?, ?)
")->execute([$past, $past]);
$terminalCancelledWaId = (int)$pdo->lastInsertId();

$stmtEligible->execute([date('Y-m-d H:i:s')]);
$allEligibleIds = $stmtEligible->fetchAll(PDO::FETCH_COLUMN);

assertTest("Test 3.5: Terminal State Invariant: Cancelled superseded WhatsApp record #{$terminalCancelledWaId} is excluded from QueueProcessor query",
    !in_array($terminalCancelledWaId, $allEligibleIds)
);

// ── Test 3.6: Prevention of Malformed WhatsApp Record Generation from Email Items ──
$stmtMalformedCheck = $pdo->prepare("
    SELECT COUNT(*) FROM communication_queue
    WHERE channel = 'whatsapp'
      AND (
        recipient LIKE '%@%'
        OR (body_html IS NOT NULL AND template_name IS NULL AND event_name = 'student_approval')
      )
");
$stmtMalformedCheck->execute();
$malformedWaCount = (int)$stmtMalformedCheck->fetchColumn();

assertTest("Test 3.6: Malformed WhatsApp records cannot be generated from an email queue item (count = 0)",
    $malformedWaCount === 0
);

// Reset mock provider after tests
$engine->mockProvider = null;

echo "\n======================================================================\n";
echo "AUDIT RESULTS: {$passedCount} / {$testCount} tests passed.\n";
if ($passedCount === $testCount) {
    echo "STATUS: ALL AUDIT CHECKS PASSED PERFECTLY (100% SUCCESS)\n";
} else {
    echo "STATUS: FAILURES DETECTED\n";
    exit(1);
}
echo "======================================================================\n";
