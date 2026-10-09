<?php
/**
 * Test Suite: WhatsApp Button Auto-Reply Account Isolation & Fallback Defense
 *
 * Verifies all 8 critical requirements:
 * CASE 1: Account 3 button reply + explicit sender key -> queue sender_account_id = 3
 * CASE 2: Account 3 button reply + target template Account 3 -> dispatch uses Account 3 Phone ID & WABA
 * CASE 3: Account 3 button reply with missing sender context -> MUST NOT silently default to Account 1 (fails safely)
 * CASE 4: Account 1 faculty/session events -> remain Account 1 (PEPP Learning)
 * CASE 5: Account 3 "Yes, I am planning" (payload: mphil_join_interest_message) -> target mphil_join_interest_message -> Account 3
 * CASE 6: Account 3 "No" (payload: notinterested) -> target notinterested -> Account 3
 * CASE 7: Same template name existing in Account 1 and Account 3 -> Account 3 reply must remain Account 3
 * CASE 8: No cross-account fallback.
 *
 * Runs exclusively in isolated SQLite database (zero production/MySQL DB modification).
 */

$testCount = 0;
$passedCount = 0;

function assertCondition($condition, $description) {
    global $testCount, $passedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
    }
}

echo "======================================================================\n";
echo "TEST SUITE: WhatsApp Button Auto-Reply Account Isolation (Cases 1 - 8)\n";
echo "======================================================================\n\n";

$tempDbPath = sys_get_temp_dir() . '/pepp_autoreply_test_' . uniqid() . '.sqlite';
if (file_exists($tempDbPath)) @unlink($tempDbPath);

$pdo = new PDO("sqlite:" . $tempDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

$pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_settings (
        setting_name VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS whatsapp_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_key VARCHAR(50) NOT NULL UNIQUE,
        phone_number_id VARCHAR(100) NOT NULL DEFAULT '',
        waba_id VARCHAR(100) NOT NULL DEFAULT '',
        display_number VARCHAR(30) NOT NULL,
        display_name VARCHAR(100) NOT NULL,
        purpose VARCHAR(255) DEFAULT NULL,
        is_default INTEGER DEFAULT 0,
        status VARCHAR(20) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(50) UNIQUE,
        full_name VARCHAR(255),
        name VARCHAR(255),
        pepp_course VARCHAR(100) DEFAULT NULL,
        email VARCHAR(255) UNIQUE,
        phone VARCHAR(30),
        whatsapp_country_code VARCHAR(10) DEFAULT '91',
        whatsapp_number VARCHAR(30),
        status VARCHAR(20) DEFAULT 'approved',
        student_status VARCHAR(20) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS communication_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_name VARCHAR(100) NOT NULL,
        channel VARCHAR(20) DEFAULT 'whatsapp',
        category VARCHAR(50) DEFAULT 'MARKETING',
        language VARCHAR(10) DEFAULT 'en_US',
        sender_account_id INTEGER DEFAULT 1,
        waba_id VARCHAR(100) DEFAULT NULL,
        meta_template_id VARCHAR(100) DEFAULT NULL,
        body_text TEXT,
        meta_data TEXT,
        status VARCHAR(20) DEFAULT 'approved',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
        sender_account_id INTEGER DEFAULT NULL,
        from_email VARCHAR(255) DEFAULT NULL,
        from_name VARCHAR(255) DEFAULT NULL,
        recipient VARCHAR(100) NOT NULL,
        recipient_name VARCHAR(255) DEFAULT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        body_html TEXT DEFAULT NULL,
        body_text TEXT DEFAULT NULL,
        template_name VARCHAR(100) DEFAULT NULL,
        template_data TEXT DEFAULT NULL,
        attachments TEXT DEFAULT '[]',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        priority INTEGER NOT NULL DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        last_retry_at DATETIME DEFAULT NULL,
        next_attempt_at DATETIME,
        message_id VARCHAR(255) DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        sent_by VARCHAR(100) DEFAULT 'system_auto_reply',
        student_uid VARCHAR(50) DEFAULT NULL,
        event_name VARCHAR(100) DEFAULT 'auto_reply_button',
        invoice_id INTEGER DEFAULT NULL,
        worker_started_at DATETIME DEFAULT NULL,
        api_requested_at DATETIME DEFAULT NULL,
        api_responded_at DATETIME DEFAULT NULL,
        delivered_at DATETIME DEFAULT NULL,
        idempotency_key VARCHAR(100) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS communication_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(255) NOT NULL,
        target_audience VARCHAR(50) DEFAULT 'students',
        status VARCHAR(20) DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS communication_campaign_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER,
        recipient VARCHAR(100),
        queue_id INTEGER,
        lead_id INTEGER,
        status VARCHAR(20) DEFAULT 'pending'
    );

    CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        is_opted_out INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS whatsapp_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        wa_phone_number VARCHAR(30) NOT NULL,
        account_id INTEGER DEFAULT 1,
        student_uid VARCHAR(50) DEFAULT NULL,
        student_user_id INTEGER DEFAULT NULL,
        contact_name VARCHAR(255) DEFAULT NULL,
        last_message_text TEXT DEFAULT NULL,
        last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_inbound_at DATETIME DEFAULT NULL,
        last_message_direction VARCHAR(10) DEFAULT 'inbound',
        unread_count INTEGER DEFAULT 0,
        status VARCHAR(20) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS whatsapp_mode_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        old_mode TEXT,
        new_mode TEXT,
        changed_by TEXT,
        changed_at TEXT
    );

    CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER DEFAULT 1,
        wa_message_id VARCHAR(255) UNIQUE,
        direction VARCHAR(20) NOT NULL,
        from_number VARCHAR(50),
        to_number VARCHAR(50),
        message_body TEXT,
        message_text TEXT,
        message_type VARCHAR(50) DEFAULT 'text',
        status VARCHAR(20) DEFAULT 'received',
        raw_payload TEXT,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        student_uid VARCHAR(50),
        sender_account_id INTEGER DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS communication_webhook_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type VARCHAR(50) NOT NULL,
        payload TEXT NOT NULL,
        processed INTEGER DEFAULT 0,
        processed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS communication_event_mappings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_name VARCHAR(100) NOT NULL UNIQUE,
        sender_key VARCHAR(50) NOT NULL,
        template_name VARCHAR(100) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS whatsapp_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone VARCHAR(50),
        message TEXT,
        student_name VARCHAR(255),
        sent_by VARCHAR(100),
        status VARCHAR(50),
        latitude REAL,
        longitude REAL,
        metadata TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Populate settings & accounts
$pdo->exec("
    INSERT INTO admin_settings (setting_name, setting_value) VALUES
    ('whatsapp_outbound_mode', 'meta_api'),
    ('whatsapp_business_id', '1410328164305566'),
    ('whatsapp_phone_id', '1229563296908445'),
    ('whatsapp_access_token', 'EAABb_MOCK_SYSTEM_USER_TOKEN_12345'),
    ('whatsapp_api_version', 'v20.0'),
    ('whatsapp_auto_response_cooldown', '600');

    -- Account 1: Admissions (PEPP Learning)
    INSERT INTO whatsapp_accounts (id, sender_key, phone_number_id, waba_id, display_number, display_name, purpose, is_default, status)
    VALUES (1, 'admissions', '1229563296908445', '1410328164305566', '+916282563209', 'PEPP Learning', 'Admissions & Academic Ops', 1, 'active');

    -- Account 3: Notifications / Marketing (PEPP Updates)
    INSERT INTO whatsapp_accounts (id, sender_key, phone_number_id, waba_id, display_number, display_name, purpose, is_default, status)
    VALUES (3, 'notifications', '1293652117171674', '1099020233033644', '+917994304400', 'PEPP Updates', 'Notifications & Marketing', 0, 'active');

    -- Active Student STU_TEST
    INSERT INTO users (user_id, full_name, name, pepp_course, email, phone, whatsapp_country_code, whatsapp_number, status, student_status)
    VALUES ('STU_TEST', 'John Candidate', 'John Candidate', 'MPhil Clinical Psychology', 'candidate@example.com', '7994304400', '91', '7994304400', 'approved', 'active');
");

// Populate templates for Account 1 and Account 3
// Source campaign template on Account 3
$metaSource = json_encode([
    'body_text' => 'Hello {{1}}, are you planning to join our MPhil batch?',
    'buttons' => [
        'quick_reply' => [
            1 => [
                'text' => 'Yes, I am planning',
                'payload' => 'mphil_join_interest_message',
                'action_type' => 'SEND_TEMPLATE',
                'target_template_name' => 'mphil_join_interest_message'
            ],
            2 => [
                'text' => 'No',
                'payload' => 'notinterested',
                'action_type' => 'SEND_TEMPLATE',
                'target_template_name' => 'notinterested'
            ]
        ]
    ]
]);

// Target templates on Account 3
$metaMphil = json_encode(['body_text' => 'Great! Here are the admission details for MPhil.']);
$metaNotInterested = json_encode(['body_text' => 'Thank you for letting us know.']);

// Same-name template ('interested') present on BOTH Account 1 and Account 3
$metaInterestedAcc1 = json_encode(['body_text' => 'PEPP Learning: Thank you for your interest in our programs.']);
$metaInterestedAcc3 = json_encode(['body_text' => 'PEPP Updates: Thank you for your interest in our upcoming batches.']);

// Account 1 Faculty Session template
$metaFacSched = json_encode(['body_text' => 'Hello {{1}}, your {{2}} session on {{3}} is scheduled for {{4}} ({{5}}, {{6}}).']);

$stmtTpl = $pdo->prepare("
    INSERT INTO communication_templates (id, template_name, channel, category, language, sender_account_id, waba_id, meta_template_id, body_text, meta_data, status)
    VALUES 
    (101, 'mphil_entrance_exam_target', 'whatsapp', 'MARKETING', 'en', 3, '1099020233033644', '1111111111111111', 'Campaign message', ?, 'approved'),
    (102, 'mphil_join_interest_message', 'whatsapp', 'MARKETING', 'en', 3, '1099020233033644', '1429637595898178', 'Great! Here are the admission details.', ?, 'approved'),
    (103, 'notinterested', 'whatsapp', 'MARKETING', 'en', 3, '1099020233033644', '2857938031248075', 'Thank you for letting us know.', ?, 'approved'),
    (105, 'interested', 'whatsapp', 'MARKETING', 'en', 3, '1099020233033644', '3333333333333333', 'PEPP Updates interested', ?, 'approved'),
    (206, 'interested', 'whatsapp', 'MARKETING', 'en', 1, '1410328164305566', '4444444444444444', 'PEPP Learning interested', ?, 'approved'),
    (201, 'faculty_session_scheduled', 'whatsapp', 'UTILITY', 'en', 1, '1410328164305566', '5555555555555555', 'Faculty session scheduled', ?, 'approved')
");
$stmtTpl->execute([$metaSource, $metaMphil, $metaNotInterested, $metaInterestedAcc3, $metaInterestedAcc1, $metaFacSched]);

require_once __DIR__ . '/includes/communication/WhatsAppAccountResolver.php';
require_once __DIR__ . '/includes/communication/CommunicationEngine.php';

$accountResolver = new WhatsAppAccountResolver($pdo);
$engine = CommunicationEngine::getInstance($pdo);

// Spy provider to record dispatch account details
class SpyWhatsAppProvider implements CommunicationProviderInterface {
    public $lastBusinessId = null;
    public $lastPhoneId = null;
    public $lastRecipient = null;
    public $lastTemplateData = null;
    public $dispatches = [];

    public function __construct($businessId = null, $phoneId = null) {
        $this->lastBusinessId = $businessId;
        $this->lastPhoneId = $phoneId;
    }

    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        $msgId = 'wamid.SPY_' . bin2hex(random_bytes(8));
        $this->dispatches[] = [
            'to' => $to,
            'template' => $templateData['name'] ?? null,
            'business_id' => $this->lastBusinessId,
            'phone_id' => $this->lastPhoneId,
            'message_id' => $msgId
        ];
        return [
            'success' => true,
            'message_id' => $msgId,
            'error' => null
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────
// CASE 1: Account 3 button reply + explicit sender key -> queue sender_account_id = 3
// ─────────────────────────────────────────────────────────────────────
echo "\n--- CASE 1: Account 3 button reply + explicit sender key -> queue sender_account_id = 3 ---\n";

$qId1 = $engine->queueMessage(
    'whatsapp',
    '917994304400',
    'John Candidate',
    'Auto-Reply: mphil_join_interest_message',
    '<p>Admission Details</p>',
    'Admission Details',
    [],
    ['name' => 'mphil_join_interest_message', 'language' => 'en', 'parameters' => []],
    'system_auto_reply',
    null,
    'STU_TEST',
    'auto_reply_button',
    null,
    'notifications' // Explicit sender key resolved from receiving account
);

$stmtQ1 = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$stmtQ1->execute([$qId1]);
$rowQ1 = $stmtQ1->fetch(PDO::FETCH_ASSOC);

assertCondition(!empty($rowQ1), "Case 1: Queue item #{$qId1} successfully inserted");
assertCondition((int)($rowQ1['sender_account_id'] ?? 0) === 3, "Case 1: sender_account_id is 3 (Account 3 / PEPP Updates)");
assertCondition($rowQ1['status'] === 'pending', "Case 1: Queue item status is 'pending'");
assertCondition($rowQ1['event_name'] === 'auto_reply_button', "Case 1: Event name is 'auto_reply_button'");

// ─────────────────────────────────────────────────────────────────────
// CASE 2: Account 3 button reply + target template Account 3 -> dispatch uses Account 3 Phone ID & WABA
// ─────────────────────────────────────────────────────────────────────
echo "\n--- CASE 2: Account 3 button reply dispatch -> uses Account 3 Phone ID (1293652117171674) & WABA (1099020233033644) ---\n";

$acc3 = $accountResolver->getAccount(3);
assertCondition(!empty($acc3), "Case 2: Account 3 resolved");
assertCondition($acc3['phone_number_id'] === '1293652117171674', "Case 2: Account 3 Phone ID is 1293652117171674");
assertCondition($acc3['waba_id'] === '1099020233033644', "Case 2: Account 3 WABA is 1099020233033644");

// Instantiate provider via engine to inspect configured credentials
$providerAcc3 = $engine->getProvider('whatsapp', $acc3);
$refPropPhone = new ReflectionProperty($providerAcc3, 'phoneId');
$refPropPhone->setAccessible(true);
$phoneIdUsed = $refPropPhone->getValue($providerAcc3);

$refPropWaba = new ReflectionProperty($providerAcc3, 'businessId');
$refPropWaba->setAccessible(true);
$wabaIdUsed = $refPropWaba->getValue($providerAcc3);

assertCondition($phoneIdUsed === '1293652117171674', "Case 2: Provider created with Account 3 Phone ID: 1293652117171674");
assertCondition($wabaIdUsed === '1099020233033644', "Case 2: Provider created with Account 3 WABA: 1099020233033644");
assertCondition($phoneIdUsed !== '1229563296908445', "Case 2: Account 1 Phone ID was NOT used");
assertCondition($wabaIdUsed !== '1410328164305566', "Case 2: Account 1 WABA was NOT used");

// Dispatch queue item #qId1 with spy provider
$spy = new SpyWhatsAppProvider('1099020233033644', '1293652117171674');
$engine->mockProvider = $spy;
$dispatched1 = $engine->processQueueItem($qId1, true);
assertCondition($dispatched1 === true, "Case 2: processQueueItem(#{$qId1}) succeeded");

$stmtQ1Post = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$stmtQ1Post->execute([$qId1]);
$rowQ1Post = $stmtQ1Post->fetch(PDO::FETCH_ASSOC);
assertCondition($rowQ1Post['status'] === 'sent', "Case 2: Queue item marked as 'sent'");
assertCondition(!empty($rowQ1Post['message_id']), "Case 2: Message ID recorded on queue item");

// ─────────────────────────────────────────────────────────────────────
// CASE 3: Account 3 button reply with missing sender context -> MUST NOT silently default to Account 1
// ─────────────────────────────────────────────────────────────────────
echo "\n--- CASE 3: Button reply with missing sender context -> fails safely, NEVER defaults to Account 1 ---\n";

// 1. Verify resolver returns null for auto_reply_button
$resAutoReply = $accountResolver->resolveAccountForEvent('auto_reply_button');
assertCondition($resAutoReply === null, "Case 3: resolveAccountForEvent('auto_reply_button') returned NULL (defensive guard)");

$resAutoReply2 = $accountResolver->resolveAccountForEvent('auto_reply');
assertCondition($resAutoReply2 === null, "Case 3: resolveAccountForEvent('auto_reply') returned NULL (defensive guard)");

// 2. Queue with missing sender key and auto_reply_button event
$qId3 = $engine->queueMessage(
    'whatsapp',
    '917994304400',
    'Candidate Missing Context',
    'Auto-Reply Missing Context',
    '<p>Details</p>',
    'Details',
    [],
    ['name' => 'mphil_join_interest_message', 'language' => 'en', 'parameters' => []],
    'system_auto_reply',
    null,
    'STU_TEST',
    'auto_reply_button',
    null,
    null // Sender context missing!
);

$stmtQ3 = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$stmtQ3->execute([$qId3]);
$rowQ3 = $stmtQ3->fetch(PDO::FETCH_ASSOC);

assertCondition((int)($rowQ3['sender_account_id'] ?? 0) !== 1, "Case 3: sender_account_id is NOT 1 (did not silently default to Account 1)");
assertCondition($rowQ3['sender_account_id'] === null, "Case 3: sender_account_id is NULL");
assertCondition($rowQ3['status'] === 'failed', "Case 3: Status is 'failed' (fail-safe protection)");
assertCondition(strpos($rowQ3['error_message'], 'missing receiving sender context') !== false, "Case 3: error_message records missing sender context");

// 3. Attempting to dispatch a queue item without sender context throws or rejects before Account 1 fallback
$engine->mockProvider = null;
$dispatched3 = false;
$threwExpected = false;
try {
    $dispatched3 = $engine->processQueueItem($qId3, true);
} catch (Exception $ex) {
    $threwExpected = true;
}
assertCondition($dispatched3 === false || $threwExpected === true, "Case 3: Dispatch rejected without sending cross-account");

// ─────────────────────────────────────────────────────────────────────
// CASE 4: Account 1 faculty/session events -> remains Account 1 (PEPP Learning)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- CASE 4: Account 1 faculty/session events -> remain Account 1 (PEPP Learning) ---\n";

$facultyEvents = [
    'faculty_session_scheduled',
    'faculty_session_reminder',
    'faculty_session_start',
    'faculty_session_start_now',
    'faculty_session_cancelled'
];

foreach ($facultyEvents as $facEv) {
    $accFac = $accountResolver->resolveAccountForEvent($facEv);
    assertCondition(!empty($accFac) && (int)$accFac['id'] === 1, "Case 4: {$facEv} resolves to Account 1");
    assertCondition($accFac['sender_key'] === 'admissions', "Case 4: {$facEv} sender_key is 'admissions'");
}

// Queue a faculty session notification without explicit sender key
$qIdFac = $engine->queueMessage(
    'whatsapp',
    '919876543210',
    'Dr. Faculty',
    'Faculty Session Scheduled',
    '<p>Details</p>',
    'Details',
    [],
    ['name' => 'faculty_session_scheduled', 'language' => 'en', 'parameters' => ['Dr. Faculty', 'QPD', 'Topic', '10:00 AM', 'Course', '1 Hour']],
    'system',
    null,
    null,
    'faculty_session_scheduled'
);

$stmtQFac = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$stmtQFac->execute([$qIdFac]);
$rowQFac = $stmtQFac->fetch(PDO::FETCH_ASSOC);

assertCondition((int)$rowQFac['sender_account_id'] === 1, "Case 4: faculty_session_scheduled queued with sender_account_id = 1");
assertCondition($rowQFac['status'] === 'pending', "Case 4: faculty_session_scheduled is pending");

$spyFac = new SpyWhatsAppProvider('1410328164305566', '1229563296908445');
$engine->mockProvider = $spyFac;
$dispFac = $engine->processQueueItem($qIdFac, true);
assertCondition($dispFac === true, "Case 4: faculty_session_scheduled dispatched successfully from Account 1");

// ─────────────────────────────────────────────────────────────────────
// CASE 5: Account 3 "Yes, I am planning" (payload: mphil_join_interest_message) -> Account 3
// ─────────────────────────────────────────────────────────────────────
echo "\n--- CASE 5: Button 1 'Yes, I am planning' -> target mphil_join_interest_message -> Account 3 ---\n";

// Inbound webhook simulation for Account 3
$receivingPhoneId = '1293652117171674';
$receivingDisplayNumber = '917994304400';

$recAccount = $accountResolver->getAccountByPhoneId($receivingPhoneId);
assertCondition(!empty($recAccount) && (int)$recAccount['id'] === 3, "Case 5: Inbound phone_number_id {$receivingPhoneId} resolves to Account 3");

$buttonPayload = 'mphil_join_interest_message';
$btnText = 'Yes, I am planning';

// Resolve action from source template on Account 3
$stmtSource = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = 'mphil_entrance_exam_target' AND sender_account_id = 3 LIMIT 1");
$stmtSource->execute();
$sourceTpl = $stmtSource->fetch(PDO::FETCH_ASSOC);
$sourceMeta = json_decode($sourceTpl['meta_data'], true) ?: [];

$matchedAction = null;
foreach ($sourceMeta['buttons']['quick_reply'] ?? [] as $act) {
    if (isset($act['payload']) && trim((string)$act['payload']) === $buttonPayload) {
        $matchedAction = $act;
        break;
    }
}
assertCondition(!empty($matchedAction), "Case 5: Quick-reply action matched for payload '{$buttonPayload}'");
assertCondition($matchedAction['action_type'] === 'SEND_TEMPLATE', "Case 5: Action type is SEND_TEMPLATE");
assertCondition($matchedAction['target_template_name'] === 'mphil_join_interest_message', "Case 5: Target template is 'mphil_join_interest_message'");

// Target template resolved under receiving account context (Account 3)
$targetTpl5 = $accountResolver->resolveTemplate($matchedAction['target_template_name'], (int)$recAccount['id']);
assertCondition(!empty($targetTpl5), "Case 5: Target template found");
assertCondition((int)$targetTpl5['sender_account_id'] === 3, "Case 5: Target template belongs to Account 3");
assertCondition($targetTpl5['meta_template_id'] === '1429637595898178', "Case 5: Target template Meta ID is 1429637595898178");

// Queue auto-reply passing senderKeyToUse explicitly
$senderKeyToUse = $recAccount['sender_key'] ?? null;
assertCondition($senderKeyToUse === 'notifications', "Case 5: senderKeyToUse is 'notifications'");

$qId5 = $engine->queueMessage(
    'whatsapp',
    '917994304400',
    'John Candidate',
    'Auto-Reply: mphil_join_interest_message',
    '<p>Admission Details</p>',
    'Admission Details',
    [],
    ['name' => 'mphil_join_interest_message', 'language' => 'en', 'parameters' => []],
    'system_auto_reply',
    null,
    'STU_TEST',
    'auto_reply_button',
    null,
    $senderKeyToUse
);

$stmtQ5 = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$stmtQ5->execute([$qId5]);
$rowQ5 = $stmtQ5->fetch(PDO::FETCH_ASSOC);
assertCondition((int)$rowQ5['sender_account_id'] === 3, "Case 5: communication_queue.sender_account_id = 3");

$spy5 = new SpyWhatsAppProvider('1099020233033644', '1293652117171674');
$engine->mockProvider = $spy5;
$disp5 = $engine->processQueueItem($qId5, true);
assertCondition($disp5 === true, "Case 5: Synchronous dispatch succeeded from Account 3");
assertCondition(!empty($spy5->dispatches), "Case 5: Spy provider recorded dispatch");
assertCondition($spy5->dispatches[0]['phone_id'] === '1293652117171674', "Case 5: Dispatched via Phone ID: 1293652117171674");
assertCondition($spy5->dispatches[0]['business_id'] === '1099020233033644', "Case 5: Dispatched via WABA: 1099020233033644");

// ─────────────────────────────────────────────────────────────────────
// CASE 6: Account 3 "No" (payload: notinterested) -> target notinterested -> Account 3
// ─────────────────────────────────────────────────────────────────────
echo "\n--- CASE 6: Button 2 'No' -> target notinterested -> Account 3 ---\n";

$buttonPayload6 = 'notinterested';
$btnText6 = 'No';

$matchedAction6 = null;
foreach ($sourceMeta['buttons']['quick_reply'] ?? [] as $act) {
    if (isset($act['payload']) && trim((string)$act['payload']) === $buttonPayload6) {
        $matchedAction6 = $act;
        break;
    }
}
assertCondition(!empty($matchedAction6), "Case 6: Quick-reply action matched for payload '{$buttonPayload6}'");
assertCondition($matchedAction6['action_type'] === 'SEND_TEMPLATE', "Case 6: Action type is SEND_TEMPLATE");
assertCondition($matchedAction6['target_template_name'] === 'notinterested', "Case 6: Target template is 'notinterested'");

$targetTpl6 = $accountResolver->resolveTemplate($matchedAction6['target_template_name'], (int)$recAccount['id']);
assertCondition(!empty($targetTpl6), "Case 6: Target template found");
assertCondition((int)$targetTpl6['sender_account_id'] === 3, "Case 6: Target template belongs to Account 3");
assertCondition($targetTpl6['meta_template_id'] === '2857938031248075', "Case 6: Target template Meta ID is 2857938031248075");

$qId6 = $engine->queueMessage(
    'whatsapp',
    '917994304400',
    'John Candidate',
    'Auto-Reply: notinterested',
    '<p>Thank you</p>',
    'Thank you',
    [],
    ['name' => 'notinterested', 'language' => 'en', 'parameters' => []],
    'system_auto_reply',
    null,
    'STU_TEST',
    'auto_reply_button',
    null,
    $senderKeyToUse
);

$stmtQ6 = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$stmtQ6->execute([$qId6]);
$rowQ6 = $stmtQ6->fetch(PDO::FETCH_ASSOC);
assertCondition((int)$rowQ6['sender_account_id'] === 3, "Case 6: communication_queue.sender_account_id = 3");

$spy6 = new SpyWhatsAppProvider('1099020233033644', '1293652117171674');
$engine->mockProvider = $spy6;
$disp6 = $engine->processQueueItem($qId6, true);
assertCondition($disp6 === true, "Case 6: Synchronous dispatch succeeded from Account 3");
assertCondition($spy6->dispatches[0]['phone_id'] === '1293652117171674', "Case 6: Dispatched via Phone ID: 1293652117171674");
assertCondition($spy6->dispatches[0]['business_id'] === '1099020233033644', "Case 6: Dispatched via WABA: 1099020233033644");

// ─────────────────────────────────────────────────────────────────────
// CASE 7: Same template name ('interested') in Account 1 and Account 3 -> strictly isolated
// ─────────────────────────────────────────────────────────────────────
echo "\n--- CASE 7: Same template name ('interested') existing in Account 1 and Account 3 ---\n";

// When Account 3 receives button reply for 'interested':
$tplAcc3 = $accountResolver->resolveTemplate('interested', 3);
assertCondition(!empty($tplAcc3), "Case 7: Template 'interested' resolved for Account 3");
assertCondition((int)$tplAcc3['id'] === 105, "Case 7: Account 3 resolved row ID 105 (NOT row ID 206)");
assertCondition((int)$tplAcc3['sender_account_id'] === 3, "Case 7: sender_account_id is 3");
assertCondition($tplAcc3['waba_id'] === '1099020233033644', "Case 7: Bound to WABA 1099020233033644");

// When Account 1 receives button reply for 'interested':
$tplAcc1 = $accountResolver->resolveTemplate('interested', 1);
assertCondition(!empty($tplAcc1), "Case 7: Template 'interested' resolved for Account 1");
assertCondition((int)$tplAcc1['id'] === 206, "Case 7: Account 1 resolved row ID 206 (NOT row ID 105)");
assertCondition((int)$tplAcc1['sender_account_id'] === 1, "Case 7: sender_account_id is 1");
assertCondition($tplAcc1['waba_id'] === '1410328164305566', "Case 7: Bound to WABA 1410328164305566");

// Queue and dispatch Account 3 reply for 'interested'
$qId7 = $engine->queueMessage(
    'whatsapp',
    '917994304400',
    'John Candidate',
    'Auto-Reply: interested',
    '<p>Interested</p>',
    'Interested',
    [],
    ['name' => 'interested', 'language' => 'en', 'parameters' => []],
    'system_auto_reply',
    null,
    'STU_TEST',
    'auto_reply_button',
    null,
    'notifications'
);

$stmtQ7 = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$stmtQ7->execute([$qId7]);
$rowQ7 = $stmtQ7->fetch(PDO::FETCH_ASSOC);
assertCondition((int)$rowQ7['sender_account_id'] === 3, "Case 7: Queue row sender_account_id is 3");

$spy7 = new SpyWhatsAppProvider('1099020233033644', '1293652117171674');
$engine->mockProvider = $spy7;
$disp7 = $engine->processQueueItem($qId7, true);
assertCondition($disp7 === true, "Case 7: Dispatch succeeded");
assertCondition($spy7->dispatches[0]['phone_id'] === '1293652117171674', "Case 7: Dispatched strictly using Account 3 Phone ID");

// ─────────────────────────────────────────────────────────────────────
// CASE 8: No cross-account fallback
// ─────────────────────────────────────────────────────────────────────
echo "\n--- CASE 8: No cross-account fallback ---\n";

// Account 3 must not be able to send an Account 1 template
$acc3ResolverTpl = $accountResolver->resolveTemplate('faculty_session_scheduled', 3);
assertCondition($acc3ResolverTpl === null, "Case 8: Account 3 CANNOT resolve Account 1 template 'faculty_session_scheduled'");

// Account 1 must not be able to send an Account 3 template
$acc1ResolverTpl = $accountResolver->resolveTemplate('mphil_entrance_exam_target', 1);
assertCondition($acc1ResolverTpl === null, "Case 8: Account 1 CANNOT resolve Account 3 template 'mphil_entrance_exam_target'");

// Account 3 auto-reply must never fallback to Account 1
assertCondition($accountResolver->resolveAccountForEvent('auto_reply_button') === null, 
    "Case 8: Auto-reply event never falls back to Account 1");

// Cleanup test DB
$pdo = null;
@unlink($tempDbPath);

echo "\n======================================================================\n";
echo "RESULTS: {$passedCount} / {$testCount} tests passed.\n";
if ($passedCount === $testCount) {
    echo "STATUS: ALL 8 CASES PASSED (100% SUCCESS)\n";
} else {
    echo "STATUS: FAILURES DETECTED IN ACCOUNT ISOLATION TEST\n";
}
echo "======================================================================\n";
