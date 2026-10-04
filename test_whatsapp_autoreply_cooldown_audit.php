<?php
/**
 * test_whatsapp_autoreply_cooldown_audit.php
 *
 * Comprehensive Automated Audit Test Suite for WhatsApp Incoming Auto-Reply 10-Minute Cooldown.
 *
 * Verifies:
 * 1. First message → auto-reply sent.
 * 2. Second message after 1 minute → no auto-reply.
 * 3. Message after 5 minutes → no auto-reply.
 * 4. Message after 9 minutes 59 seconds → no auto-reply.
 * 5. Message after 10 minutes → auto-reply sent.
 * 6. Different phone number during another user's cooldown → auto-reply sent.
 * 7. Concurrent/duplicate incoming requests → only one auto-reply.
 * 8. Failed auto-reply API call → verify immediate retry behaviour.
 * 9. Existing faculty notifications remain unaffected.
 * 10. Existing student/payment/transactional notifications remain unaffected.
 *
 * Uses mocked in-memory database and mocked WhatsApp provider.
 * NEVER sends real WhatsApp messages.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

putenv("PEPP_USE_SQLITE=1");
$_ENV['PEPP_USE_SQLITE'] = '1';

require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
require_once __DIR__ . '/includes/communication/WhatsAppAutoReplyManager.php';
require_once __DIR__ . '/includes/communication/FacultySessionNotificationService.php';

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $scenario, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$scenario}" . ($detail ? ": {$detail}" : '') . "\n";
    } else {
        $failed++;
        echo "  [FAIL] {$scenario}" . ($detail ? ": {$detail}" : '') . "\n";
    }
}

echo "========================================================================\n";
echo " AUDIT TEST SUITE: WhatsApp Incoming Auto-Reply 10-Minute Cooldown\n";
echo "========================================================================\n\n";

// ── In-Memory Database Setup ──
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

$pdo->exec("
    CREATE TABLE admin_settings (
        setting_name TEXT PRIMARY KEY,
        setting_value TEXT
    );
    INSERT INTO admin_settings VALUES
        ('whatsapp_auto_response_cooldown', '600'),
        ('whatsapp_outbound_mode', 'automated'),
        ('whatsapp_access_token', 'EAABmock_token'),
        ('whatsapp_phone_id', '1000999888'),
        ('whatsapp_business_id', '999888777'),
        ('whatsapp_api_version', 'v20.0');

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
        next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        message_id TEXT DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        sent_by TEXT DEFAULT NULL,
        student_uid TEXT DEFAULT NULL,
        event_name TEXT DEFAULT NULL,
        invoice_id INTEGER DEFAULT NULL,
        idempotency_key TEXT DEFAULT NULL,
        worker_started_at TEXT DEFAULT NULL,
        api_requested_at TEXT DEFAULT NULL,
        api_responded_at TEXT DEFAULT NULL,
        delivered_at TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE whatsapp_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        wa_phone_number TEXT NOT NULL UNIQUE,
        student_uid TEXT DEFAULT NULL,
        student_user_id INTEGER DEFAULT NULL,
        contact_name TEXT DEFAULT NULL,
        last_message_text TEXT DEFAULT NULL,
        last_message_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_inbound_at TEXT DEFAULT NULL,
        unread_count INTEGER DEFAULT 0,
        status TEXT DEFAULT 'open',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        wa_message_id TEXT NOT NULL UNIQUE,
        direction TEXT NOT NULL,
        message_type TEXT NOT NULL DEFAULT 'text',
        message_text TEXT DEFAULT NULL,
        media_id TEXT DEFAULT NULL,
        media_mime_type TEXT DEFAULT NULL,
        media_filename TEXT DEFAULT NULL,
        caption TEXT DEFAULT NULL,
        reply_to_wa_message_id TEXT DEFAULT NULL,
        status TEXT DEFAULT NULL,
        raw_payload TEXT DEFAULT NULL,
        sent_at TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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

    CREATE TABLE communication_event_mappings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_name TEXT NOT NULL UNIQUE,
        template_name TEXT DEFAULT NULL,
        parameter_mappings TEXT DEFAULT NULL,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS whatsapp_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id TEXT,
        student_name TEXT,
        mobile TEXT,
        phone TEXT,
        message TEXT,
        message_type TEXT,
        status TEXT,
        sent_by TEXT,
        latitude TEXT,
        longitude TEXT,
        metadata TEXT,
        sent_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        response_message_id TEXT,
        error_message TEXT
    );

    CREATE TABLE IF NOT EXISTS communication_campaigns (
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

    CREATE TABLE IF NOT EXISTS communication_campaign_recipients (
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

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        full_name TEXT,
        name TEXT,
        email TEXT,
        phone TEXT,
        whatsapp_country_code TEXT DEFAULT '+91',
        whatsapp_number TEXT,
        status TEXT DEFAULT 'approved',
        student_status TEXT DEFAULT 'active',
        paid_amount REAL DEFAULT 0,
        total_fee REAL DEFAULT 50000,
        pepp_course TEXT DEFAULT 'B.Com',
        pepp_academic_year TEXT DEFAULT '2026-27'
    );

    CREATE TABLE IF NOT EXISTS whatsapp_mode_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        previous_mode TEXT,
        new_mode TEXT,
        changed_by TEXT,
        changed_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS instalment_details (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        paid_amount REAL,
        amount REAL,
        status TEXT,
        due_date TEXT
    );

    CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        invoice_no TEXT
    );

    CREATE TABLE IF NOT EXISTS student_status_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        old_status TEXT,
        new_status TEXT,
        reason TEXT,
        changed_by TEXT,
        changed_at TEXT
    );

    INSERT INTO users (user_id, full_name, name, email, phone, whatsapp_country_code, whatsapp_number, status, student_status)
    VALUES ('PEPP2026001', 'Student A', 'Student A', 'studenta@pepplearning.in', '9876543210', '+91', '9876543210', 'approved', 'active');

    INSERT INTO communication_templates (channel, template_name, language, status, meta_data) VALUES
    ('whatsapp', 'faculty_session_scheduled', 'en', 'approved', '{\"body_text\": \"Hello {{1}}, {{2}} {{3}} {{4}} {{5}} {{6}}\"}'),
    ('whatsapp', 'pepp_admission_received', 'en', 'approved', '{\"body_text\": \"Hello {{1}}, {{2}} {{3}}\"}'),
    ('whatsapp', 'pepp_payment_receipt', 'en', 'approved', '{\"body_text\": \"Hello {{1}}, {{2}} {{3}}\"}'),
    ('whatsapp', 'pepp_installment_reminder', 'en', 'approved', '{\"body_text\": \"Hello {{1}}, {{2}} {{3}}\"}');
");

// ── Mock WhatsApp Provider for Non-Network Testing ──
class MockWhatsAppCloudProvider extends WhatsAppCloudProvider {
    public array $sentMessages = [];
    public bool $simulateFailure = false;
    public string $failureReason = 'Simulated Meta Network Failure';
    public ?string $lastError = null;
    public int $lastErrorCode = 0;

    public function __construct() {
        parent::__construct('mock_biz', 'mock_phone', 'mock_token', 'v20.0');
    }

    public function sendMessage($to, $subject, $htmlBody, $plainText = '', array $attachments = [], array $templateData = []): array {
        if ($this->simulateFailure) {
            $this->lastError = $this->failureReason;
            $this->lastErrorCode = 500;
            return ['success' => false, 'error' => $this->failureReason];
        }

        $wamid = 'wamid.MOCK_' . uniqid('', true);
        $this->sentMessages[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $plainText ?: $htmlBody,
            'template' => $templateData,
            'message_id' => $wamid,
            'timestamp' => time()
        ];

        return ['success' => true, 'message_id' => $wamid];
    }
}

$mockProvider = new MockWhatsAppCloudProvider();
$engine = CommunicationEngine::getInstance($pdo);
$engine->mockProvider = $mockProvider;

$autoReplyMgr = new WhatsAppAutoReplyManager($pdo);
$autoReplyMgr->ensureTableExists();

echo "--- SECTION 1: Phone Normalization & Self-Healing ---\n";
$norm1 = WhatsAppAutoReplyManager::normalizePhone('9876543210');
$norm2 = WhatsAppAutoReplyManager::normalizePhone('+91 98765-43210');
$norm3 = WhatsAppAutoReplyManager::normalizePhone('09876543210');
assertCondition($norm1 === '919876543210', 'Normalization of 10-digit number adds 91 prefix', $norm1);
assertCondition($norm2 === '919876543210', 'Normalization of international formatted number cleans punctuation', $norm2);
assertCondition($norm3 === '919876543210', 'Normalization of 11-digit number with leading 0 cleans prefix', $norm3);

$tableExists = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='whatsapp_auto_reply_cooldown'")->fetchColumn();
assertCondition($tableExists, 'whatsapp_auto_reply_cooldown self-healing table verified in database');

echo "\n--- SECTION 2: Scenarios 1 to 5: Sequential Timeline Cooldown on Single Number ---\n";
$phoneA = '919876543210';
$t0 = 1791100000; // Reference base epoch timestamp (10:00:00)

// Helper simulating inbound auto-response trigger
$simulateInboundAutoResponse = function(string $phone, int $currentTime, string $contactName = 'Student A') use ($autoReplyMgr, $engine, $mockProvider, $pdo): array {
    $cleanPhone = WhatsAppAutoReplyManager::normalizePhone($phone);
    $cooldown = 600; // 10 minutes

    $reserved = $autoReplyMgr->checkAndReserve($cleanPhone, $cooldown, $currentTime);
    if (!$reserved) {
        return ['auto_reply_sent' => false, 'reason' => 'cooldown_active'];
    }

    $autoText = "Thank you for contacting PEPP Learning.\n\nIf you have any query or need assistance, please contact our support team.";
    $templateData = [
        'type' => 'interactive',
        'interactive_type' => 'cta_url',
        'interactive_body' => $autoText,
        'interactive_button_text' => 'Message Here',
        'interactive_button_url' => 'https://wa.me/917025000444'
    ];

    $queueId = $engine->queueMessage(
        'whatsapp',
        $cleanPhone,
        $contactName,
        'Auto Response',
        $autoText,
        $autoText,
        [],
        $templateData,
        'system_auto_response',
        null,
        null,
        'auto_response'
    );

    $dispatched = false;
    if ($queueId) {
        $dispatched = $engine->processQueueItem($queueId);
    }

    if ($dispatched) {
        $autoReplyMgr->recordSuccess($cleanPhone, $cooldown, $currentTime);
        return ['auto_reply_sent' => true, 'queue_id' => $queueId];
    } else {
        $autoReplyMgr->recordFailure($cleanPhone, $currentTime);
        return ['auto_reply_sent' => false, 'reason' => 'dispatch_failed'];
    }
};

// 1. First message at t0 (10:00:00) → auto-reply sent
$r1 = $simulateInboundAutoResponse($phoneA, $t0);
assertCondition($r1['auto_reply_sent'] === true, 'Scenario 1: First message at 10:00 triggers auto-reply');
$st1 = $autoReplyMgr->getCooldownStatus($phoneA, $t0);
assertCondition($st1['active'] === true && $st1['remaining_seconds'] === 600, 'Scenario 1: 10-minute (600s) cooldown is active');

// 2. Second message after 1 minute (t0 + 60s, 10:01:00) → NO auto-reply
$t1 = $t0 + 60;
$r2 = $simulateInboundAutoResponse($phoneA, $t1);
assertCondition($r2['auto_reply_sent'] === false && $r2['reason'] === 'cooldown_active', 'Scenario 2: Message after 1 min is suppressed by cooldown');

// 3. Third message after 5 minutes (t0 + 300s, 10:05:00) → NO auto-reply
$t2 = $t0 + 300;
$r3 = $simulateInboundAutoResponse($phoneA, $t2);
assertCondition($r3['auto_reply_sent'] === false && $r3['reason'] === 'cooldown_active', 'Scenario 3: Message after 5 min is suppressed by cooldown');

// 4. Fourth message after 9 minutes 59 seconds (t0 + 599s, 10:09:59) → NO auto-reply
$t3 = $t0 + 599;
$r4 = $simulateInboundAutoResponse($phoneA, $t3);
assertCondition($r4['auto_reply_sent'] === false && $r4['reason'] === 'cooldown_active', 'Scenario 4: Message after 9m 59s is suppressed (1s remaining)');

// 5. Fifth message after 10 minutes (t0 + 600s, 10:10:00) → auto-reply SENT
$t4 = $t0 + 600;
$r5 = $simulateInboundAutoResponse($phoneA, $t4);
assertCondition($r5['auto_reply_sent'] === true, 'Scenario 5: Message at 10m 00s triggers new auto-reply');
$st5 = $autoReplyMgr->getCooldownStatus($phoneA, $t4);
assertCondition($st5['active'] === true && $st5['remaining_seconds'] === 600, 'Scenario 5: Fresh 10-minute cooldown active after new reply');

echo "\n--- SECTION 3: Scenario 6: Independent Cooldown for Different Numbers ---\n";
$phoneB = '919845123456';
// Phone A is currently in cooldown (at t4 + 120s = 10:12:00)
$tB = $t4 + 120;
$rA_check = $simulateInboundAutoResponse($phoneA, $tB);
assertCondition($rA_check['auto_reply_sent'] === false, 'Phone A remains cooled down at 10:12');

// Phone B sends at 10:12 → must receive auto-reply!
$rB = $simulateInboundAutoResponse($phoneB, $tB, 'Faculty B');
assertCondition($rB['auto_reply_sent'] === true, 'Scenario 6: Phone B receives auto-reply during Phone A cooldown');
$stB = $autoReplyMgr->getCooldownStatus($phoneB, $tB);
assertCondition($stB['active'] === true && $stB['remaining_seconds'] === 600, 'Scenario 6: Phone B has independent 600s cooldown');

echo "\n--- SECTION 4: Scenario 7: Concurrent & Duplicate Requests Concurrency Protection ---\n";
$phoneC = '919999000111';
$tC = $t0 + 5000;

// Simulate multiple requests arriving almost simultaneously (within the same second)
$res1 = $autoReplyMgr->checkAndReserve($phoneC, 600, $tC);
$res2 = $autoReplyMgr->checkAndReserve($phoneC, 600, $tC);
$res3 = $autoReplyMgr->checkAndReserve($phoneC, 600, $tC);
$res4 = $autoReplyMgr->checkAndReserve($phoneC, 600, $tC);

assertCondition($res1 === true, 'Scenario 7: Thread 1 acquires reservation');
assertCondition($res2 === false, 'Scenario 7: Concurrent Thread 2 blocked by reservation');
assertCondition($res3 === false, 'Scenario 7: Concurrent Thread 3 blocked by reservation');
assertCondition($res4 === false, 'Scenario 7: Concurrent Thread 4 blocked by reservation');

// Confirm success on Thread 1
$autoReplyMgr->recordSuccess($phoneC, 600, $tC);
$statusC = $autoReplyMgr->getCooldownStatus($phoneC, $tC);
assertCondition($statusC['active'] === true && $statusC['status'] === 'sent', 'Scenario 7: Single successful sent state recorded');

echo "\n--- SECTION 5: Scenario 8: Failed Auto-Reply API Call Retry Behaviour ---\n";
$phoneD = '919777888999';
$tD = $t0 + 10000;

// Simulate Meta API Network/Provider Failure
$mockProvider->simulateFailure = true;
$mockProvider->failureReason = 'Meta HTTP 503 Service Unavailable';

$rFail = $simulateInboundAutoResponse($phoneD, $tD);
assertCondition($rFail['auto_reply_sent'] === false && $rFail['reason'] === 'dispatch_failed', 'Scenario 8: Auto-reply dispatch failed as simulated');

$stD = $autoReplyMgr->getCooldownStatus($phoneD, $tD);
assertCondition($stD['active'] === false && $stD['status'] === 'failed', 'Scenario 8: Cooldown is NOT locked for 10 minutes upon failure');

// Now restore Meta API service
$mockProvider->simulateFailure = false;
$tD_retry = $tD + 10; // Next incoming message 10 seconds later
$rRetry = $simulateInboundAutoResponse($phoneD, $tD_retry);
assertCondition($rRetry['auto_reply_sent'] === true, 'Scenario 8: Next inbound message successfully retries and sends auto-reply');
$stD_after = $autoReplyMgr->getCooldownStatus($phoneD, $tD_retry);
assertCondition($stD_after['active'] === true, 'Scenario 8: Cooldown active only after successful send');

echo "\n--- SECTION 6: Scenario 9: Faculty Notifications Completely Unaffected ---\n";
// Set up faculty session notification service
$facService = new FacultySessionNotificationService($pdo, $engine);

$ctx = [
    'faculty_name'     => 'Dr. Abdul Rahim',
    'session_type'     => 'QPD',
    'session_topic'    => 'Clinical Neuropsychology',
    'session_datetime' => '10 Oct 2026, 04:00 PM',
    'session_courses'  => 'M.Phil Clinical Psychology',
    'session_duration' => '1.5 hours',
];

// Parameter resolutions
$schedParams = $facService->resolveTemplateParameters('faculty_session_scheduled', $ctx);
assertCondition(count($schedParams) === 6 && $schedParams[0] === 'Dr. Abdul Rahim' && $schedParams[1] === 'QPD' && $schedParams[2] === 'Clinical Neuropsychology', 'Scenario 9: faculty_session_scheduled resolves 6 params (Type before Topic)');

$remParams = $facService->resolveTemplateParameters('faculty_session_reminder', $ctx);
assertCondition(count($remParams) === 2 && $remParams[0] === 'Dr. Abdul Rahim' && $remParams[1] === '10 Oct 2026, 04:00 PM', 'Scenario 9: faculty_session_reminder resolves exactly 2 params');

$startParams = $facService->resolveTemplateParameters('faculty_session_start', $ctx);
assertCondition(count($startParams) === 5 && $startParams[4] === '1.5 hours', 'Scenario 9: faculty_session_start resolves exactly 5 params');

$startNowParams = $facService->resolveTemplateParameters('faculty_session_start_now', $ctx);
assertCondition(count($startNowParams) === 1 && $startNowParams[0] === 'Dr. Abdul Rahim', 'Scenario 9: faculty_session_start_now resolves exactly 1 param');

$cancelParams = $facService->resolveTemplateParameters('faculty_session_cancelled', $ctx);
assertCondition(count($cancelParams) === 4 && $cancelParams[3] === 'M.Phil Clinical Psychology', 'Scenario 9: faculty_session_cancelled resolves exactly 4 params');

$meetParam = $facService->extractMeetButtonParam('https://meet.google.com/abc-defg-hij');
assertCondition($meetParam === 'abc-defg-hij', 'Scenario 9: Google Meet URL extracts dynamic code parameter without full URL');

// Send faculty notification to Phone A (even while Phone A is in auto-reply cooldown)
$qIdFac = $engine->queueMessage(
    'whatsapp',
    $phoneA,
    'Dr. Abdul Rahim',
    'Faculty Session Scheduled',
    'Session Body',
    'Session Body',
    [],
    ['name' => 'faculty_session_scheduled', 'language' => 'en', 'parameters' => $schedParams],
    'faculty_system',
    null,
    null,
    'faculty_session_scheduled'
);
$facDispatched = $engine->processQueueItem($qIdFac);
assertCondition($facDispatched === true, 'Scenario 9: Faculty notification dispatches successfully regardless of auto-reply cooldown');

echo "\n--- SECTION 7: Scenario 10: Student / Payment / Transactional Notifications Unaffected ---\n";
// Student Registration
$qIdReg = $engine->queueMessage(
    'whatsapp',
    $phoneA,
    'Student A',
    'Admission Confirmed',
    'Body',
    'Body',
    [],
    ['name' => 'pepp_admission_received', 'language' => 'en', 'parameters' => ['Student A', 'Psychology', 'PEPP2026001']],
    'system_registration',
    null,
    'PEPP2026001',
    'student_registration'
);
$regDispatched = $engine->processQueueItem($qIdReg);
assertCondition($regDispatched === true, 'Scenario 10: student_registration transactional notification sent successfully');

// Payment Receipt
$qIdPay = $engine->queueMessage(
    'whatsapp',
    $phoneA,
    'Student A',
    'Payment Receipt',
    'Body',
    'Body',
    [],
    ['name' => 'pepp_payment_receipt', 'language' => 'en', 'parameters' => ['Student A', '5000', 'Psychology']],
    'system_payment',
    null,
    'PEPP2026001',
    'payment_receipt'
);
$payDispatched = $engine->processQueueItem($qIdPay);
assertCondition($payDispatched === true, 'Scenario 10: payment_receipt transactional notification sent successfully');

// Installment Reminder
$qIdInst = $engine->queueMessage(
    'whatsapp',
    $phoneA,
    'Student A',
    'Installment Reminder',
    'Body',
    'Body',
    [],
    ['name' => 'pepp_installment_reminder', 'language' => 'en', 'parameters' => ['Student A', '2nd', '3000', 'Psychology', '15 Oct 2026']],
    'system_scheduler',
    null,
    'PEPP2026001',
    'installment_reminder'
);
$instDispatched = $engine->processQueueItem($qIdInst);
assertCondition($instDispatched === true, 'Scenario 10: installment_reminder transactional notification sent successfully');

echo "\n========================================================================\n";
echo " AUDIT RESULTS: {$passed} Passed, {$failed} Failed\n";
if ($failed === 0) {
    echo " STATUS: 100% SUCCESS — All Auto-Reply Cooldown Audit Scenarios Passed!\n";
} else {
    echo " STATUS: FAILURES DETECTED in Auto-Reply Cooldown Audit Scenarios!\n";
}
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);
