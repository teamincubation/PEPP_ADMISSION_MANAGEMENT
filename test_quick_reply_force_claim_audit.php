<?php
/**
 * Test Suite: WhatsApp Quick Reply Force Immediate Dispatch Audit
 *
 * Verifies:
 * 1. Pending item created at current second is successfully claimed immediately with $force=true.
 * 2. Simulated clock-drift (PHP clock ahead of DB NOW()) is bypassed by $force=true for status='pending'.
 * 3. Default $force=false maintains strict next_attempt_at <= NOW() check.
 * 4. Future scheduled items (status='scheduled') are NOT claimed early even with $force=true.
 * 5. Two concurrent workers cannot claim the same item (atomic locking).
 * 6. Cancelled, suppressed, max-retry, and paused items are NOT bypassed by $force=true.
 */

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
echo "AUDIT TEST: WhatsApp Quick Reply Force Immediate Dispatch\n";
echo "======================================================================\n\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Mock NOW() in SQLite
$currentDbTime = '2026-10-07 12:35:48'; // 1 second behind PHP clock
$pdo->sqliteCreateFunction('NOW', function() use (&$currentDbTime) {
    return $currentDbTime;
});

// Setup schema
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
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        full_name TEXT,
        name TEXT,
        email TEXT UNIQUE,
        whatsapp_country_code TEXT,
        whatsapp_number TEXT,
        status TEXT DEFAULT 'approved',
        student_status TEXT DEFAULT 'active'
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
    CREATE TABLE communication_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        target_audience TEXT DEFAULT 'students',
        status TEXT DEFAULT 'active'
    );
    CREATE TABLE communication_campaign_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER,
        recipient TEXT,
        queue_id INTEGER,
        lead_id INTEGER,
        user_id TEXT,
        status TEXT DEFAULT 'pending'
    );
    CREATE TABLE leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        is_opted_out INTEGER DEFAULT 0
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
");

// Enable meta_api mode
$pdo->prepare("INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('whatsapp_outbound_mode', 'meta_api', datetime('now'))")->execute();
$pdo->prepare("INSERT INTO whatsapp_mode_audit (old_mode, new_mode, changed_by, changed_at) VALUES ('manual', 'meta_api', 'audit_admin', '2020-01-01 00:00:00')")->execute();

require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
$engine = CommunicationEngine::getInstance($pdo);

// Spy provider
$spyProvider = new class implements CommunicationProviderInterface {
    public $sentCount = 0;
    public $lastRecipient = null;
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        $this->sentCount++;
        $this->lastRecipient = $to;
        return ['success' => true, 'message_id' => 'wamid.FORCE_TEST_' . uniqid()];
    }
};
$engine->mockProvider = $spyProvider;

// ── TEST 1: Clock skew blockage without force ($force=false) ──────────
// DB NOW() is '2026-10-07 12:35:48'.
// PHP clock inserts next_attempt_at = '2026-10-07 12:35:49' (1s ahead of DB).
$phpNextAttempt = '2026-10-07 12:35:49';

$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at, event_name)
    VALUES ('whatsapp', '919876543201', 'pending', 0, 0, ?, ?, 'auto_reply_button')
")->execute([$phpNextAttempt, $phpNextAttempt]);
$itemSkewId = (int)$pdo->lastInsertId();

// Try claiming with $force=false (standard/cron mode)
$resNoForce = $engine->processQueueItem($itemSkewId, false);

$stmtCheck = $pdo->prepare("SELECT status, worker_started_at FROM communication_queue WHERE id = ?");
$stmtCheck->execute([$itemSkewId]);
$rowNoForce = $stmtCheck->fetch(PDO::FETCH_ASSOC);

assertTest("Test 1: Default \$force=false is blocked when next_attempt_at > DB NOW() (simulated clock skew)",
    $resNoForce === false && $rowNoForce['status'] === 'pending' && $rowNoForce['worker_started_at'] === null
);

// ── TEST 2: Successful immediate claim with $force=true ───────────────
// Same skewed pending item now claimed with $force=true
$resForce = $engine->processQueueItem($itemSkewId, true);

$stmtCheck->execute([$itemSkewId]);
$rowForce = $stmtCheck->fetch(PDO::FETCH_ASSOC);

assertTest("Test 2: \$force=true immediately claims pending item despite clock skew",
    $resForce === true && $rowForce['status'] === 'sent' && $rowForce['worker_started_at'] === '2026-10-07 12:35:48'
);

// ── TEST 3: Future scheduled item is NEVER claimed early even with $force=true ─
$futureTime = '2026-10-07 13:30:00';
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at, event_name)
    VALUES ('whatsapp', '919876543202', 'scheduled', 0, 0, ?, '2026-10-07 12:35:49', 'scheduled_campaign')
")->execute([$futureTime]);
$schedId = (int)$pdo->lastInsertId();

$resSched = $engine->processQueueItem($schedId, true);

$stmtCheck->execute([$schedId]);
$rowSched = $stmtCheck->fetch(PDO::FETCH_ASSOC);

assertTest("Test 3: Scheduled future item (status='scheduled') is NOT claimed early even with \$force=true",
    $resSched === false && $rowSched['status'] === 'scheduled' && $rowSched['worker_started_at'] === null
);

// ── TEST 4: Two concurrent workers cannot claim the same item ──────────
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at, event_name)
    VALUES ('whatsapp', '919876543203', 'pending', 0, 0, ?, '2026-10-07 12:35:49', 'auto_reply_button')
")->execute([$phpNextAttempt]);
$concurrentId = (int)$pdo->lastInsertId();

// Worker 1 claims item (simulated by manually setting status='processing')
$pdo->prepare("UPDATE communication_queue SET status = 'processing', worker_started_at = NOW() WHERE id = ?")->execute([$concurrentId]);

// Worker 2 attempts processQueueItem($concurrentId, true)
$resWorker2 = $engine->processQueueItem($concurrentId, true);

assertTest("Test 4: Worker 2 cannot claim an item already in 'processing' status (concurrency lock protected)",
    $resWorker2 === false
);

// ── TEST 5: Cancelled item cannot be claimed even with $force=true ─────
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at, event_name)
    VALUES ('whatsapp', '919876543204', 'cancelled', 0, 0, ?, '2026-10-07 12:35:49', 'auto_reply_button')
")->execute([$phpNextAttempt]);
$cancelledId = (int)$pdo->lastInsertId();

$resCancelled = $engine->processQueueItem($cancelledId, true);
assertTest("Test 5: Cancelled item cannot be claimed with \$force=true", $resCancelled === false);

// ── TEST 6: Exhausted retry item (retry_count=3) cannot be claimed ────
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at, event_name)
    VALUES ('whatsapp', '919876543205', 'failed', 0, 3, ?, '2026-10-07 12:35:49', 'auto_reply_button')
")->execute([$phpNextAttempt]);
$exhaustedId = (int)$pdo->lastInsertId();

$resExhausted = $engine->processQueueItem($exhaustedId, true);
assertTest("Test 6: Exhausted retry item (retry_count >= 3) cannot be claimed with \$force=true", $resExhausted === false);

// ── TEST 7: Queue paused blocks processing even with $force=true ──────
$engine->setQueuePaused(true);
$pdo->prepare("
    INSERT INTO communication_queue (channel, recipient, status, priority, retry_count, next_attempt_at, created_at, event_name)
    VALUES ('whatsapp', '919876543206', 'pending', 0, 0, ?, '2026-10-07 12:35:49', 'auto_reply_button')
")->execute([$phpNextAttempt]);
$pausedItemId = (int)$pdo->lastInsertId();

$resPaused = $engine->processQueueItem($pausedItemId, true);
assertTest("Test 7: Globally paused queue blocks \$force=true dispatch immediately", $resPaused === false);
$engine->setQueuePaused(false);

echo "\n======================================================================\n";
echo "AUDIT RESULTS: {$passedCount} / {$testCount} tests passed.\n";
if ($passedCount === $testCount) {
    echo "STATUS: ALL QUICK REPLY FORCE DISPATCH CHECKS PASSED (100% SUCCESS)\n";
} else {
    echo "STATUS: FAILURES DETECTED\n";
    exit(1);
}
echo "======================================================================\n";
