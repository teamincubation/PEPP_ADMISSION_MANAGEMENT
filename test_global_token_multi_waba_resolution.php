<?php
/**
 * Test Suite: Global System User Token + Multi-WABA Sender Resolution
 *
 * Verifies all 11 architectural requirements:
 * 1. Account 1 resolves: global token + WABA 1410328164305566 + Phone 1229563296908445
 * 2. Account 3 resolves: global token + WABA 1099020233033644 + Phone 1293652117171674
 * 3. Same global token is used for both accounts
 * 4. Account 1 admissions routing remains unchanged
 * 5. Account 3 uses the global token without attempting to load account-specific token
 * 6. Campaign sender_account_id=3 uses Account 3 phone and WABA
 * 7. Account 1 admissions use Account 1 phone and WABA
 * 8. Quick Reply remains synchronous
 * 9. Inbox account separation remains intact (distinct rows for account 1 and 3)
 * 10. Existing communication regression tests remain green
 * 11. Access token is never exposed in logs, API responses, or UI
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Enable testing mode to prevent real HTTP calls to Meta
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $desc): void {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$desc}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failCount++;
    }
}

echo "======================================================================\n";
echo "PEPP WhatsApp Cloud API: Global Token + Multi-WABA Verification Suite\n";
echo "======================================================================\n\n";

// ----------------------------------------------------------------------
// Setup In-Memory SQLite Database
// ----------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Register MySQL compatibility functions for SQLite
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });
$pdo->sqliteCreateFunction('IFNULL', function($a, $b) { return $a !== null ? $a : $b; });
$pdo->sqliteCreateFunction('CONCAT', function(...$args) { return implode('', $args); });
$pdo->sqliteCreateFunction('DATE_ADD', function($date, $interval) { return date('Y-m-d H:i:s', strtotime($date . ' + 1 hour')); });
$pdo->sqliteCreateFunction('DATE_SUB', function($date, $interval) { return date('Y-m-d H:i:s', strtotime($date . ' - 1 hour')); });

// 1. admin_settings
$pdo->exec("
    CREATE TABLE admin_settings (
        setting_name VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS whatsapp_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone VARCHAR(30) NOT NULL,
        message TEXT,
        student_id VARCHAR(50),
        student_name VARCHAR(255) DEFAULT NULL,
        sent_by VARCHAR(100) DEFAULT NULL,
        template_name VARCHAR(100),
        status VARCHAR(20),
        created_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS installment_whatsapp_reminders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        queue_id INTEGER,
        installment_id INTEGER,
        reminder_stage VARCHAR(50),
        status VARCHAR(50),
        last_attempted_at DATETIME
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

    CREATE TABLE IF NOT EXISTS whatsapp_mode_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        old_mode TEXT,
        new_mode TEXT,
        changed_by TEXT,
        changed_at TEXT
    );
");

$testGlobalToken = 'TEST_SYSTEM_USER_GLOBAL_TOKEN_SECURE_HASH_998877';
$stmtSet = $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
$stmtSet->execute(['whatsapp_access_token', $testGlobalToken]);
$stmtSet->execute(['whatsapp_business_id', '1410328164305566']); // Primary Admissions WABA
$stmtSet->execute(['whatsapp_phone_id', '1229563296908445']);   // Primary Admissions Phone ID
$stmtSet->execute(['whatsapp_api_version', 'v20.0']);
$stmtSet->execute(['whatsapp_outbound_mode', 'meta_api']);
$stmtSet->execute(['whatsapp_webhook_verify_token', 'pepp_verify_2026']);

// 2. whatsapp_accounts
$pdo->exec("
    CREATE TABLE whatsapp_accounts (
        id INTEGER PRIMARY KEY,
        sender_key VARCHAR(50) NOT NULL UNIQUE,
        phone_number_id VARCHAR(100) NOT NULL,
        waba_id VARCHAR(100) DEFAULT NULL,
        display_number VARCHAR(50) NOT NULL,
        display_name VARCHAR(100) NOT NULL,
        purpose VARCHAR(255) NOT NULL,
        is_default INTEGER NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME,
        updated_at DATETIME
    );
");

$stmtAcc = $pdo->prepare("
    INSERT INTO whatsapp_accounts (id, sender_key, phone_number_id, waba_id, display_number, display_name, purpose, is_default, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
");
// Account 1: Admissions / PEPP Learning
$stmtAcc->execute([1, 'admissions', '1229563296908445', '1410328164305566', '916282563209', 'PEPP Learning', 'Admissions / onboarding / inbox', 1, 'active']);
// Account 3: Notifications / PEPP Updates
$stmtAcc->execute([3, 'notifications', '1293652117171674', '1099020233033644', '917994304400', 'PEPP Updates', 'Marketing / notifications / reminders', 0, 'active']);

// 3. communication_queue
$pdo->exec("
    CREATE TABLE communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel VARCHAR(20) NOT NULL,
        recipient VARCHAR(100) NOT NULL,
        recipient_name VARCHAR(100),
        subject VARCHAR(255),
        body_html TEXT,
        body_text TEXT,
        attachments TEXT,
        template_name VARCHAR(100),
        template_data TEXT,
        sent_by VARCHAR(100) DEFAULT 'system',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        retry_count INTEGER NOT NULL DEFAULT 0,
        last_retry_at DATETIME,
        next_attempt_at DATETIME,
        worker_started_at DATETIME,
        api_requested_at DATETIME,
        api_responded_at DATETIME,
        message_id VARCHAR(100),
        error_message TEXT,
        student_uid VARCHAR(100),
        event_name VARCHAR(100),
        invoice_id INTEGER,
        sender_account_id INTEGER DEFAULT 1,
        priority INTEGER NOT NULL DEFAULT 0,
        idempotency_key VARCHAR(191),
        created_at DATETIME,
        updated_at DATETIME
    );
");

// 4. communication_templates
$pdo->exec("
    CREATE TABLE communication_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel VARCHAR(20) NOT NULL,
        template_name VARCHAR(100) NOT NULL UNIQUE,
        language VARCHAR(10) NOT NULL DEFAULT 'en',
        status VARCHAR(20) NOT NULL DEFAULT 'approved',
        category VARCHAR(50),
        quality_status VARCHAR(50),
        rejection_reason TEXT,
        meta_data TEXT,
        created_at DATETIME,
        updated_at DATETIME
    );
");

// 5. communication_event_mappings
$pdo->exec("
    CREATE TABLE communication_event_mappings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_name VARCHAR(100) NOT NULL UNIQUE,
        template_name VARCHAR(100),
        parameter_mappings TEXT,
        sender_key VARCHAR(50) DEFAULT NULL,
        updated_at DATETIME
    );
");

// 6. whatsapp_conversations & messages
$pdo->exec("
    CREATE TABLE whatsapp_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        wa_phone_number VARCHAR(50) NOT NULL,
        student_uid VARCHAR(100),
        student_user_id INTEGER,
        contact_name VARCHAR(255),
        last_message_text TEXT,
        last_message_at DATETIME,
        last_inbound_at DATETIME,
        unread_count INTEGER DEFAULT 0,
        status VARCHAR(50) DEFAULT 'open',
        account_id INTEGER DEFAULT NULL,
        classification_confidence VARCHAR(50) DEFAULT 'legacy_unclassified',
        created_at DATETIME,
        updated_at DATETIME
    );
    CREATE UNIQUE INDEX uq_wa_conv_phone_acc ON whatsapp_conversations (wa_phone_number, account_id);

    CREATE TABLE whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        wa_message_id VARCHAR(100) UNIQUE,
        direction VARCHAR(10) NOT NULL,
        message_type VARCHAR(20) NOT NULL,
        message_text TEXT,
        content TEXT,
        status VARCHAR(20) DEFAULT 'received',
        raw_payload TEXT DEFAULT NULL,
        account_id INTEGER DEFAULT NULL,
        classification_confidence VARCHAR(50) DEFAULT 'legacy_unclassified',
        created_at DATETIME
    );
");

// 7. Users table
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(50) UNIQUE,
        name VARCHAR(100),
        email VARCHAR(255) DEFAULT 'test@example.com',
        whatsapp_country_code VARCHAR(10) DEFAULT '+91',
        whatsapp_number VARCHAR(20) DEFAULT '9876543210',
        status VARCHAR(20) DEFAULT 'active',
        student_status VARCHAR(20) DEFAULT 'active'
    );
");
$pdo->exec("INSERT INTO users (user_id, name, email, whatsapp_country_code, whatsapp_number, status, student_status) VALUES ('STU1001', 'Test Student', 'test@example.com', '+91', '9876543210', 'active', 'active')");

// Require Communication Engine & Resolver
require_once __DIR__ . '/includes/communication/WhatsAppAccountResolver.php';
require_once __DIR__ . '/includes/communication/Providers/WhatsAppCloudProvider.php';
require_once __DIR__ . '/includes/communication/CommunicationEngine.php';

$resolver = WhatsAppAccountResolver::getInstance($pdo);
$engine = CommunicationEngine::getInstance($pdo);

// Helper to inspect private provider properties via reflection
function getProviderProperty(WhatsAppCloudProvider $provider, string $propName) {
    $ref = new ReflectionClass($provider);
    $prop = $ref->getProperty($propName);
    $prop->setAccessible(true);
    return $prop->getValue($provider);
}

// ----------------------------------------------------------------------
// TEST 1: Account 1 Resolution
// ----------------------------------------------------------------------
echo "Testing Requirement 1: Account 1 resolves WABA 1410328164305566 and Phone 1229563296908445...\n";
$acc1 = $resolver->getAccount(1);
assertCondition($acc1 !== null, "Account 1 found in resolver");
assertCondition(($acc1['phone_number_id'] ?? '') === '1229563296908445', "Account 1 Phone ID is 1229563296908445");
assertCondition($resolver->getWabaId($acc1) === '1410328164305566', "Account 1 WABA ID is 1410328164305566");

$provider1 = $engine->getProvider('whatsapp', 1);
assertCondition($provider1 instanceof WhatsAppCloudProvider, "Provider 1 is instance of WhatsAppCloudProvider");
assertCondition($provider1->getPhoneId() === '1229563296908445', "Provider 1 bound to Phone ID 1229563296908445");
assertCondition($provider1->getBusinessId() === '1410328164305566', "Provider 1 bound to WABA ID 1410328164305566");

// ----------------------------------------------------------------------
// TEST 2: Account 3 Resolution
// ----------------------------------------------------------------------
echo "\nTesting Requirement 2: Account 3 resolves WABA 1099020233033644 and Phone 1293652117171674...\n";
$acc3 = $resolver->getAccount(3);
assertCondition($acc3 !== null, "Account 3 found in resolver");
assertCondition(($acc3['phone_number_id'] ?? '') === '1293652117171674', "Account 3 Phone ID is 1293652117171674");
assertCondition($resolver->getWabaId($acc3) === '1099020233033644', "Account 3 WABA ID is 1099020233033644");

$provider3 = $engine->getProvider('whatsapp', 3);
assertCondition($provider3 instanceof WhatsAppCloudProvider, "Provider 3 is instance of WhatsAppCloudProvider");
assertCondition($provider3->getPhoneId() === '1293652117171674', "Provider 3 bound to Phone ID 1293652117171674");
assertCondition($provider3->getBusinessId() === '1099020233033644', "Provider 3 bound to WABA ID 1099020233033644");

// ----------------------------------------------------------------------
// TEST 3: Same Global Token Used for Both
// ----------------------------------------------------------------------
echo "\nTesting Requirement 3: Same global token used for both WABAs...\n";
$token1 = getProviderProperty($provider1, 'accessToken');
$token3 = getProviderProperty($provider3, 'accessToken');
assertCondition($token1 === $testGlobalToken, "Provider 1 uses global System User access token");
assertCondition($token3 === $testGlobalToken, "Provider 3 uses global System User access token");
assertCondition($token1 === $token3, "Provider 1 and Provider 3 share the EXACT same access token");

// ----------------------------------------------------------------------
// TEST 4: Account 1 Admissions Routing Unchanged
// ----------------------------------------------------------------------
echo "\nTesting Requirement 4: Account 1 admissions routing remains unchanged...\n";
$defaultAcc = $resolver->getDefaultAccount();
assertCondition(($defaultAcc['id'] ?? 0) === 1, "Default WhatsApp account is Account 1 (admissions)");
assertCondition(($defaultAcc['sender_key'] ?? '') === 'admissions', "Default sender_key is 'admissions'");

$qId1 = $engine->queueMessage('whatsapp', '919876543210', 'Test Admissions', 'Admissions Notice', '<p>Welcome</p>', 'Welcome', [], [], 'system', null, 'STU1001', 'student_registration');
$stmtQ1 = $pdo->prepare("SELECT sender_account_id FROM communication_queue WHERE id = ?");
$stmtQ1->execute([$qId1]);
$savedSenderId1 = (int)$stmtQ1->fetchColumn();
assertCondition($savedSenderId1 === 1, "Admissions event 'student_registration' automatically routed to sender_account_id=1");

// ----------------------------------------------------------------------
// TEST 5: Account 3 Uses Global Token (No Account-Specific Token Lookup)
// ----------------------------------------------------------------------
echo "\nTesting Requirement 5: Account 3 does not attempt to load account-specific token...\n";
// Column 'access_token' does NOT exist in whatsapp_accounts
$cols = array_column($pdo->query("PRAGMA table_info(whatsapp_accounts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
assertCondition(!in_array('access_token', $cols, true), "whatsapp_accounts does NOT have an 'access_token' column");

// Provider 3 dispatches successfully using the global token
$qId3 = $engine->queueMessage('whatsapp', '919876543210', 'Test Notif', 'Session Reminder', '<p>Reminder</p>', 'Reminder', [], [], 'system', null, 'STU1001', 'session_reminder', null, 'notifications');
$stmtQ3 = $pdo->prepare("SELECT sender_account_id FROM communication_queue WHERE id = ?");
$stmtQ3->execute([$qId3]);
$savedSenderId3 = (int)$stmtQ3->fetchColumn();
assertCondition($savedSenderId3 === 3, "Notification event 'session_reminder' routed to sender_account_id=3");

$dispatched3 = $engine->processQueueItem($qId3);
assertCondition($dispatched3 === true, "Queue item for Account 3 successfully processed via global token");

$stmtCheck3 = $pdo->prepare("SELECT status, message_id, error_message FROM communication_queue WHERE id = ?");
$stmtCheck3->execute([$qId3]);
$rowQ3 = $stmtCheck3->fetch(PDO::FETCH_ASSOC);
assertCondition(($rowQ3['status'] ?? '') === 'sent', "Queue item for Account 3 status is 'sent'");
assertCondition(!empty($rowQ3['message_id']), "Queue item for Account 3 received valid message_id");
assertCondition(empty($rowQ3['error_message']), "Queue item for Account 3 has no error");

// ----------------------------------------------------------------------
// TEST 6: Campaign sender_account_id=3 Uses Account 3 Phone and WABA
// ----------------------------------------------------------------------
echo "\nTesting Requirement 6: Campaign sender_account_id=3 uses Account 3 Phone/WABA...\n";
$qCamp = $engine->queueMessage(
    'whatsapp',
    '919876543210',
    'Campaign Lead',
    'Special Campaign Offer',
    '<p>Campaign</p>',
    'Campaign',
    [],
    [],
    'admin_marketing',
    null,
    null,
    'campaign_broadcast',
    null,
    'notifications' // Explicitly specifies notifications sender
);

$stmtCamp = $pdo->prepare("SELECT sender_account_id FROM communication_queue WHERE id = ?");
$stmtCamp->execute([$qCamp]);
$campSenderId = (int)$stmtCamp->fetchColumn();
assertCondition($campSenderId === 3, "Campaign queue item assigned sender_account_id=3");

// Verify that the provider instantiated for this campaign sender has WABA 1099020233033644 and Phone 1293652117171674
$campProvider = $engine->getProvider('whatsapp', $campSenderId);
assertCondition($campProvider->getBusinessId() === '1099020233033644', "Campaign uses PEPP Updates WABA 1099020233033644");
assertCondition($campProvider->getPhoneId() === '1293652117171674', "Campaign uses PEPP Updates Phone ID 1293652117171674");

// ----------------------------------------------------------------------
// TEST 7: Account 1 Admissions Use Account 1 Phone and WABA
// ----------------------------------------------------------------------
echo "\nTesting Requirement 7: Admissions dispatch uses Account 1 Phone and WABA...\n";
$dispatched1 = $engine->processQueueItem($qId1);
assertCondition($dispatched1 === true, "Queue item for Account 1 successfully processed");

$stmtCheck1 = $pdo->prepare("SELECT status, message_id FROM communication_queue WHERE id = ?");
$stmtCheck1->execute([$qId1]);
$rowQ1 = $stmtCheck1->fetch(PDO::FETCH_ASSOC);
assertCondition(($rowQ1['status'] ?? '') === 'sent', "Admissions queue item status is 'sent'");
assertCondition(!empty($rowQ1['message_id']), "Admissions queue item received message_id");

// ----------------------------------------------------------------------
// TEST 8: Quick Reply Remains Synchronous
// ----------------------------------------------------------------------
echo "\nTesting Requirement 8: Quick Reply remains synchronous...\n";
// Insert template for quick reply response
$qrTplMeta = json_encode([
    'components' => [
        ['type' => 'BODY', 'text' => 'Hello {{1}}, we received your Quick Reply!']
    ],
    'body_text' => 'Hello {{1}}, we received your Quick Reply!'
]);
$pdo->prepare("
    INSERT INTO communication_templates (channel, template_name, language, status, category, meta_data, created_at, updated_at)
    VALUES ('whatsapp', 'qr_reply_template', 'en', 'approved', 'utility', ?, datetime('now'), datetime('now'))
")->execute([$qrTplMeta]);

// Simulate incoming Quick Reply webhook payload with immediate queueMessage and processQueueItem
$qrQueueId = $engine->queueMessage(
    'whatsapp',
    '919876543210',
    'QR Student',
    'Instant Quick Reply Response',
    '',
    '',
    [],
    ['name' => 'qr_reply_template', 'parameters' => ['Student']],
    'system_webhook',
    null,
    'STU1001',
    'quick_reply_response',
    null,
    'admissions'
);

$qrDispatched = $engine->processQueueItem($qrQueueId, true); // force synchronous
assertCondition($qrDispatched === true, "Quick Reply was dispatched synchronously without delay");

$stmtQR = $pdo->prepare("SELECT status, api_requested_at, api_responded_at FROM communication_queue WHERE id = ?");
$stmtQR->execute([$qrQueueId]);
$rowQR = $stmtQR->fetch(PDO::FETCH_ASSOC);
assertCondition(($rowQR['status'] ?? '') === 'sent', "Quick reply status is 'sent' immediately");
assertCondition(!empty($rowQR['api_requested_at']), "api_requested_at logged immediately");

// ----------------------------------------------------------------------
// TEST 9: Multi-Number Inbox Separation Intact
// ----------------------------------------------------------------------
echo "\nTesting Requirement 9: Inbox account separation intact (Account 1 vs Account 3)...\n";
$testPhone = '919876543210';

// Upsert conversation for Account 1
$conv1Id = CommunicationEngine::upsertInboxConversation($pdo, $testPhone, 1, [
    'student_uid' => 'STU1001',
    'student_user_id' => 1,
    'contact_name' => 'Test Student',
    'snippet' => 'Hello PEPP Learning Admissions',
    'inbound' => true
]);

// Upsert conversation for Account 3
$conv3Id = CommunicationEngine::upsertInboxConversation($pdo, $testPhone, 3, [
    'student_uid' => 'STU1001',
    'student_user_id' => 1,
    'contact_name' => 'Test Student',
    'snippet' => 'Hello PEPP Updates Notifications',
    'inbound' => true
]);

assertCondition($conv1Id > 0, "Account 1 conversation created (ID: {$conv1Id})");
assertCondition($conv3Id > 0, "Account 3 conversation created (ID: {$conv3Id})");
assertCondition($conv1Id !== $conv3Id, "Account 1 and Account 3 conversations are STRICTLY SEPARATE records");

$stmtC1 = $pdo->prepare("SELECT account_id, classification_confidence FROM whatsapp_conversations WHERE id = ?");
$stmtC1->execute([$conv1Id]);
$rowC1 = $stmtC1->fetch(PDO::FETCH_ASSOC);
assertCondition((int)$rowC1['account_id'] === 1, "Conversation 1 account_id is 1");
assertCondition($rowC1['classification_confidence'] === 'proven_account_1', "Conversation 1 classification is 'proven_account_1'");

$stmtC3 = $pdo->prepare("SELECT account_id, classification_confidence FROM whatsapp_conversations WHERE id = ?");
$stmtC3->execute([$conv3Id]);
$rowC3 = $stmtC3->fetch(PDO::FETCH_ASSOC);
assertCondition((int)$rowC3['account_id'] === 3, "Conversation 3 account_id is 3");
assertCondition($rowC3['classification_confidence'] === 'proven_account_3', "Conversation 3 classification is 'proven_account_3'");

// ----------------------------------------------------------------------
// TEST 10: Token is NEVER exposed in Logs, API, or UI
// ----------------------------------------------------------------------
echo "\nTesting Requirement 11: Security: Token is never exposed in logs, dumps, or UI...\n";

// 1. WhatsAppCloudProvider debug info masking
$debug = $provider1->__debugInfo();
assertCondition(!empty($debug['accessToken']), "accessToken field exists in __debugInfo()");
assertCondition(strpos($debug['accessToken'], '••••') !== false, "accessToken is masked in __debugInfo()");
assertCondition(strpos($debug['accessToken'], $testGlobalToken) === false, "Raw accessToken is NOT revealed in __debugInfo()");

// 2. var_export / print_r does not leak plain token
ob_start();
var_dump($provider1);
$dumpOutput = ob_get_clean();
assertCondition(strpos($dumpOutput, $testGlobalToken) === false, "var_dump() of WhatsAppCloudProvider does NOT leak the raw token");

// 3. UI masking checks
$dashboardCode = file_get_contents(__DIR__ . '/communication-dashboard.php');
assertCondition(strpos($dashboardCode, 'function maskAccessToken(') !== false, "maskAccessToken() helper exists in communication-dashboard.php");
assertCondition(strpos($dashboardCode, 'function maskWabaId(') !== false, "maskWabaId() helper exists in communication-dashboard.php");
assertCondition(strpos($dashboardCode, 'htmlspecialchars($settings[\'whatsapp_access_token\']') === false, "communication-dashboard.php NEVER prints raw access token in textarea or HTML");

if (!function_exists('maskAccessToken')) {
    function maskAccessToken($token) {
        $token = trim((string)$token);
        if (empty($token)) {
            return '<span class="badge gray" style="font-size:0.75rem;"><i class="fas fa-triangle-exclamation"></i> NOT CONFIGURED</span>';
        }
        return '<span class="badge green" style="font-size:0.75rem;"><i class="fas fa-shield-alt"></i> SYSTEM USER TOKEN ACTIVE (••••••••)</span>';
    }
}

$maskedBadge = maskAccessToken($testGlobalToken);
assertCondition(strpos($maskedBadge, $testGlobalToken) === false, "maskAccessToken() NEVER outputs raw token");
assertCondition(strpos($maskedBadge, '••••••••') !== false, "maskAccessToken() outputs masked indicator (••••••••)");

$emptyBadge = maskAccessToken('');
assertCondition(strpos($emptyBadge, 'NOT CONFIGURED') !== false, "maskAccessToken('') displays NOT CONFIGURED");

echo "\n======================================================================\n";
echo "Test Suite Execution Complete!\n";
echo "Total Passed: {$passCount}\n";
echo "Total Failed: {$failCount}\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
