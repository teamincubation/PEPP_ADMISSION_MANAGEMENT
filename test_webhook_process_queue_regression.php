<?php
/**
 * Regression Test Suite: Non-CLI Webhook & Synchronous Queue Dispatch Audit
 *
 * Verifies:
 * 1. Parity of moved student status helpers (get_student_status, is_student_active, get_student_status_reason).
 * 2. Counter-factual verification: auth.php in a non-CLI /api/ request without admin session trips 401 Unauthorized.
 * 3. CommunicationEngine does NOT require auth.php and reaches COMMIT in an unauthenticated web request.
 * 4. End-to-end execution of api/v1/communication/webhook.php via php-cgi (PHP_SAPI !== 'cli')
 *    with:
 *    - no admin session
 *    - no HTTP_X_TESTING_MODE
 *    - normal webhook environment
 *    proves:
 *    - auth.php guard does NOT terminate the request
 *    - processQueueItem() reaches COMMIT
 *    - queue row changes from pending -> processing -> sent
 *    - webhook returns HTTP 200 ({"success":true})
 *    - communication_webhook_events.processed becomes 1
 *    - No HTTP 401 Unauthorized is generated
 */

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
echo "REGRESSION TEST: Non-CLI Webhook & Synchronous Queue Dispatch Audit\n";
echo "======================================================================\n\n";

$admissionsDir = __DIR__;
$tempDbPath = sys_get_temp_dir() . '/pepp_webhook_regression_' . uniqid() . '.sqlite';
if (file_exists($tempDbPath)) @unlink($tempDbPath);

$pdo = new PDO("sqlite:" . $tempDbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA busy_timeout = 5000;");

// Setup SQLite helper functions
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

// Create required database schema
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

    CREATE TABLE IF NOT EXISTS student_status_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(50) NOT NULL,
        old_status VARCHAR(20),
        new_status VARCHAR(20) NOT NULL,
        reason TEXT,
        changed_by VARCHAR(100),
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS communication_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
        template_name VARCHAR(100) NOT NULL UNIQUE,
        language VARCHAR(10) NOT NULL DEFAULT 'en_US',
        category VARCHAR(50) DEFAULT 'MARKETING',
        status VARCHAR(20) NOT NULL DEFAULT 'approved',
        quality_status VARCHAR(50) DEFAULT 'green',
        rejection_reason TEXT DEFAULT NULL,
        body_text TEXT,
        meta_data TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
        sender_account_id INTEGER DEFAULT 1,
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
        next_attempt_at DATETIME NOT NULL,
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

    CREATE TABLE IF NOT EXISTS communication_webhook_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider VARCHAR(50) NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        payload TEXT NOT NULL,
        processed INTEGER NOT NULL DEFAULT 0,
        processed_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS whatsapp_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        wa_phone_number VARCHAR(30) NOT NULL,
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

    CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        wa_message_id VARCHAR(100) UNIQUE,
        direction VARCHAR(10) NOT NULL,
        message_type VARCHAR(20) NOT NULL,
        message_text TEXT,
        media_id VARCHAR(100) DEFAULT NULL,
        media_mime_type VARCHAR(100) DEFAULT NULL,
        media_filename VARCHAR(255) DEFAULT NULL,
        caption TEXT DEFAULT NULL,
        reply_to_wa_message_id VARCHAR(100) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'delivered',
        raw_payload TEXT,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS whatsapp_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone VARCHAR(30) NOT NULL,
        message TEXT,
        student_name VARCHAR(255) DEFAULT NULL,
        sent_by VARCHAR(100) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        latitude REAL DEFAULT NULL,
        longitude REAL DEFAULT NULL,
        metadata TEXT DEFAULT NULL,
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

    CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        is_opted_out INTEGER DEFAULT 0
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

    CREATE TABLE IF NOT EXISTS admin_activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER,
        admin_username TEXT,
        request_method TEXT,
        action_type TEXT,
        target_id TEXT,
        details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Populate settings and sender accounts
$pdo->exec("
    INSERT INTO admin_settings (setting_name, setting_value) VALUES
    ('whatsapp_outbound_mode', 'meta_api'),
    ('whatsapp_phone_number_id', 'PHONE_ID_ADMISSIONS_111'),
    ('whatsapp_business_account_id', 'WABA_ADMISSIONS_111'),
    ('whatsapp_permanent_access_token', 'TOKEN_ADMISSIONS_111'),
    ('whatsapp_webhook_verify_token', 'pepp_verify_token_2026'),
    ('whatsapp_app_secret', ''),
    ('whatsapp_auto_response_cooldown', '600');

    INSERT INTO whatsapp_accounts (id, sender_key, phone_number_id, display_number, display_name, is_default, status)
    VALUES (1, 'admissions', 'PHONE_ID_ADMISSIONS_111', '+919999900001', 'PEPP Learning', 1, 'active');

    INSERT INTO whatsapp_mode_audit (old_mode, new_mode, changed_by, changed_at)
    VALUES ('manual', 'meta_api', 'test_admin', '2020-01-01 00:00:00');
");

// Populate users and student status logs
$pdo->exec("
    INSERT INTO users (user_id, full_name, name, email, phone, whatsapp_country_code, whatsapp_number, status, student_status) VALUES
    ('STU001', 'Alice Active', 'Alice Active', 'alice@example.com', '9876543210', '91', '9876543210', 'approved', 'active'),
    ('STU002', 'Sam Suspended', 'Sam Suspended', 'sam@example.com', '9876543211', '91', '9876543211', 'approved', 'suspended'),
    ('STU003', 'Ian Inactive', 'Ian Inactive', 'ian@example.com', '9876543212', '91', '9876543212', 'approved', 'inactive'),
    ('STU004', 'David Dropout', 'David Dropout', 'david@example.com', '9876543213', '91', '9876543213', 'approved', 'dropout'),
    ('STU005', 'Cathy Completed', 'Cathy Completed', 'cathy@example.com', '9876543214', '91', '9876543214', 'approved', 'completed'),
    ('STU006', 'Peter Pending', 'Peter Pending', 'peter@example.com', '9876543215', '91', '9876543215', 'pending', 'active'),
    ('STU007', 'Ursula Unknown', 'Ursula Unknown', 'ursula@example.com', '9876543216', '91', '9876543216', 'approved', NULL);

    INSERT INTO student_status_log (user_id, old_status, new_status, reason) VALUES
    ('STU002', 'active', 'suspended', 'Installment 2 payment overdue by 14 days'),
    ('STU003', 'active', 'inactive', 'Student requested medical leave for 1 month'),
    ('STU004', 'active', 'dropout', 'Relocated to another city, course discontinued'),
    ('STU005', 'active', 'completed', 'Successfully passed final comprehensive examination');
");

// Populate templates for quick reply action
$sourceTemplateMeta = json_encode([
    'buttons' => [
        'quick_reply' => [
            [
                'text' => 'Yes, enroll me',
                'payload' => 'PAYLOAD_QUICK_REPLY_YES',
                'action_type' => 'SEND_TEMPLATE',
                'target_template_name' => 'pepp_auto_reply_confirmation'
            ]
        ]
    ]
]);

$targetTemplateMeta = json_encode([
    'meta_template_id' => 'META_TPL_CONFIRMATION_999',
    'body_text' => 'Thank you for confirming your enrollment with PEPP Learning!'
]);

$stmtTpl = $pdo->prepare("
    INSERT INTO communication_templates (template_name, channel, category, language, body_text, meta_data, status)
    VALUES 
    ('pepp_onboarding_inquiry', 'whatsapp', 'MARKETING', 'en_US', 'Would you like to enroll?', ?, 'approved'),
    ('pepp_auto_reply_confirmation', 'whatsapp', 'UTILITY', 'en_US', 'Thank you for confirming your enrollment with PEPP Learning!', ?, 'approved')
");
$stmtTpl->execute([$sourceTemplateMeta, $targetTemplateMeta]);

// ─────────────────────────────────────────────────────────────────────
// PART 1: Parity Verification for Moved Student Status Helpers
// ─────────────────────────────────────────────────────────────────────
echo "--- PART 1: Student Status Helpers Parity Verification ---\n";

require_once $admissionsDir . '/includes/student_status_helpers.php';

assertTest("Helper function get_student_status exists", function_exists('get_student_status'));
assertTest("Helper function is_student_active exists", function_exists('is_student_active'));
assertTest("Helper function get_student_status_reason exists", function_exists('get_student_status_reason'));

// Lifecycle status resolution
assertTest("get_student_status('STU001') is 'active'", get_student_status($pdo, 'STU001') === 'active');
assertTest("get_student_status('STU002') is 'suspended'", get_student_status($pdo, 'STU002') === 'suspended');
assertTest("get_student_status('STU003') is 'inactive'", get_student_status($pdo, 'STU003') === 'inactive');
assertTest("get_student_status('STU004') is 'dropout'", get_student_status($pdo, 'STU004') === 'dropout');
assertTest("get_student_status('STU005') is 'completed'", get_student_status($pdo, 'STU005') === 'completed');
assertTest("get_student_status('STU006' - pending) is 'pending'", get_student_status($pdo, 'STU006') === 'pending');
assertTest("get_student_status('STU007' - NULL status) is 'unknown'", get_student_status($pdo, 'STU007') === 'unknown');
assertTest("get_student_status('NON_EXISTENT') is 'unknown'", get_student_status($pdo, 'NON_EXISTENT') === 'unknown');
assertTest("get_student_status(null) is 'unknown'", get_student_status($pdo, null) === 'unknown');

// Active status validation
assertTest("is_student_active('STU001') is TRUE", is_student_active($pdo, 'STU001') === true);
assertTest("is_student_active('STU002') is FALSE", is_student_active($pdo, 'STU002') === false);
assertTest("is_student_active('STU003') is FALSE", is_student_active($pdo, 'STU003') === false);
assertTest("is_student_active('STU004') is FALSE", is_student_active($pdo, 'STU004') === false);
assertTest("is_student_active('STU005') is FALSE", is_student_active($pdo, 'STU005') === false);
assertTest("is_student_active('STU006') is FALSE", is_student_active($pdo, 'STU006') === false);
assertTest("is_student_active('NON_EXISTENT') is FALSE", is_student_active($pdo, 'NON_EXISTENT') === false);

// Exact reason extraction
assertTest("get_student_status_reason('STU002') retrieves suspended reason", 
    get_student_status_reason($pdo, 'STU002') === 'Installment 2 payment overdue by 14 days');
assertTest("get_student_status_reason('STU003') retrieves inactive reason", 
    get_student_status_reason($pdo, 'STU003') === 'Student requested medical leave for 1 month');
assertTest("get_student_status_reason('STU004') retrieves dropout reason", 
    get_student_status_reason($pdo, 'STU004') === 'Relocated to another city, course discontinued');
assertTest("get_student_status_reason('STU005') retrieves completed reason", 
    get_student_status_reason($pdo, 'STU005') === 'Successfully passed final comprehensive examination');
assertTest("get_student_status_reason('STU001') for active is NULL", 
    get_student_status_reason($pdo, 'STU001') === null);

// Email resolution for reason extraction
assertTest("get_student_status_reason('sam@example.com') resolves email to user_id", 
    get_student_status_reason($pdo, 'sam@example.com') === 'Installment 2 payment overdue by 14 days');

// ─────────────────────────────────────────────────────────────────────
// PART 2: Counter-Factual Proof: auth.php in Web Request Trips 401
// ─────────────────────────────────────────────────────────────────────
echo "\n--- PART 2: Counter-Factual Proof: auth.php Trips 401 Without Admin Session ---\n";

$phpCgi = dirname(PHP_BINARY) . '/php-cgi.exe';
assertTest("php-cgi binary exists on system", file_exists($phpCgi));

$authTripScript = sys_get_temp_dir() . '/test_auth_trip_' . uniqid() . '.php';
$authTripCode = '<?php
$_SERVER["SCRIPT_NAME"] = "/admissions/api/v1/communication/webhook.php";
$_SERVER["HTTP_ACCEPT"] = "application/json";
require_once "' . addslashes(str_replace('\\', '/', $admissionsDir)) . '/includes/auth.php";
echo "SHOULD_BE_UNREACHABLE";
';
file_put_contents($authTripScript, $authTripCode);

$envAuthTrip = [
    'REQUEST_METHOD' => 'POST',
    'SERVER_NAME' => 'localhost',
    'SCRIPT_FILENAME' => $authTripScript,
    'SCRIPT_NAME' => '/admissions/api/v1/communication/webhook.php',
    'HTTP_ACCEPT' => 'application/json',
    'REDIRECT_STATUS' => '200',
    'PEPP_SQLITE_PATH' => $tempDbPath,
    'SystemRoot' => getenv('SystemRoot') ?: 'C:\Windows',
    'TEMP' => sys_get_temp_dir()
];

$procTrip = proc_open('"' . $phpCgi . '" "' . $authTripScript . '"', [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
], $pipesTrip, __DIR__, $envAuthTrip);

$stdoutTrip = stream_get_contents($pipesTrip[1]);
fclose($pipesTrip[0]);
fclose($pipesTrip[1]);
fclose($pipesTrip[2]);
proc_close($procTrip);
@unlink($authTripScript);

assertTest("Counter-factual: auth.php outputs Status: 401 Unauthorized", 
    strpos($stdoutTrip, '401 Unauthorized') !== false);
assertTest("Counter-factual: auth.php outputs JSON {\"success\":false,\"message\":\"Unauthorized access\"}", 
    strpos($stdoutTrip, 'Unauthorized access') !== false);
assertTest("Counter-factual: auth.php exits before subsequent code can execute", 
    strpos($stdoutTrip, 'SHOULD_BE_UNREACHABLE') === false);

// ─────────────────────────────────────────────────────────────────────
// PART 3: CommunicationEngine & processQueueItem() in Simulated Non-CLI Environment
// ─────────────────────────────────────────────────────────────────────
echo "\n--- PART 3: CommunicationEngine processQueueItem() Web Simulation ---\n";

require_once $admissionsDir . '/includes/communication/CommunicationEngine.php';
$engine = CommunicationEngine::getInstance($pdo);

// Inject mock provider into engine to simulate successful WhatsApp Cloud API send
$mockProvider = new class implements CommunicationProviderInterface {
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        return [
            'success' => true,
            'message_id' => 'wamid.REGRESSION_MOCK_' . bin2hex(random_bytes(8)),
            'error' => null
        ];
    }
};
$engine->mockProvider = $mockProvider;

    $studentUid = 'STU001';
    $queueId = $engine->queueMessage(
        'whatsapp',
        '919876543210',
        'Alice Active',
        'Auto-Reply Test',
        '<p>Confirmation</p>',
        'Confirmation',
        [],
        ['name' => 'pepp_auto_reply_confirmation', 'language' => 'en_US', 'parameters' => []],
        'system_auto_reply',
        null,
        'STU001',
        'auto_reply_button',
        null,
        'admissions'
    );

assertTest("Direct queue item created (Queue ID: {$queueId})", $queueId > 0);

// Simulate non-CLI web environment settings
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/admissions/api/v1/communication/webhook.php';
$_SERVER['REQUEST_URI'] = '/admissions/api/v1/communication/webhook.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
unset($_SERVER['HTTP_X_TESTING_MODE']);
unset($_SESSION['admin_logged_in']);

// Execute processQueueItem with force=true (Quick Reply synchronous path)
$dispatched = $engine->processQueueItem($queueId, true);
assertTest("processQueueItem(\$queueId, true) completed without fatal exit", true);
assertTest("processQueueItem returned TRUE (successful immediate dispatch)", $dispatched === true);

// Verify database row state in communication_queue
$stmtQCheck = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
$stmtQCheck->execute([$queueId]);
$qRow = $stmtQCheck->fetch(PDO::FETCH_ASSOC);

assertTest("Queue item status reached 'sent'", $qRow['status'] === 'sent');
assertTest("Queue item has message_id populated", !empty($qRow['message_id']) && str_starts_with($qRow['message_id'], 'wamid.REGRESSION_MOCK_'));
assertTest("Queue item worker_started_at recorded", !empty($qRow['worker_started_at']));
assertTest("Queue item api_requested_at recorded", !empty($qRow['api_requested_at']));
assertTest("Queue item api_responded_at recorded", !empty($qRow['api_responded_at']));
assertTest("Queue item error_message is NULL (no transaction rollback)", $qRow['error_message'] === null);

// ─────────────────────────────────────────────────────────────────────
// PART 4: End-to-End Execution of webhook.php via php-cgi (Real Non-CLI Web Request)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- PART 4: End-to-End Webhook Execution via php-cgi (Real Non-CLI Web Request) ---\n";

$tempDbPath4 = sys_get_temp_dir() . '/pepp_webhook_reg_p4_' . uniqid() . '.sqlite';
if (file_exists($tempDbPath4)) @unlink($tempDbPath4);

$pdo4 = new PDO("sqlite:" . $tempDbPath4);
$pdo4->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo4->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo4->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

$pdo4->exec("
    CREATE TABLE admin_settings (setting_name TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT);
    CREATE TABLE whatsapp_accounts (id INTEGER PRIMARY KEY, sender_key TEXT UNIQUE, phone_number_id TEXT, display_number TEXT, display_name TEXT, is_default INTEGER, status TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE users (id INTEGER PRIMARY KEY, user_id TEXT UNIQUE, full_name TEXT, name TEXT, pepp_course TEXT, email TEXT UNIQUE, phone TEXT, whatsapp_country_code TEXT, whatsapp_number TEXT, status TEXT, student_status TEXT, created_at TEXT);
    CREATE TABLE student_status_log (id INTEGER PRIMARY KEY, user_id TEXT, old_status TEXT, new_status TEXT, reason TEXT, changed_by TEXT, changed_at TEXT);
    CREATE TABLE communication_templates (id INTEGER PRIMARY KEY, channel TEXT, template_name TEXT UNIQUE, language TEXT, category TEXT, status TEXT, quality_status TEXT, rejection_reason TEXT, body_text TEXT, meta_data TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE communication_queue (id INTEGER PRIMARY KEY, channel TEXT, sender_account_id INTEGER, from_email TEXT, from_name TEXT, recipient TEXT, recipient_name TEXT, subject TEXT, body_html TEXT, body_text TEXT, template_name TEXT, template_data TEXT, attachments TEXT, status TEXT, priority INTEGER, retry_count INTEGER, last_retry_at TEXT, next_attempt_at TEXT, message_id TEXT, error_message TEXT, sent_by TEXT, student_uid TEXT, event_name TEXT, invoice_id INTEGER, worker_started_at TEXT, api_requested_at TEXT, api_responded_at TEXT, delivered_at TEXT, idempotency_key TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE communication_webhook_events (id INTEGER PRIMARY KEY, provider TEXT, event_type TEXT, payload TEXT, processed INTEGER, processed_at TEXT, created_at TEXT);
    CREATE TABLE whatsapp_conversations (id INTEGER PRIMARY KEY, wa_phone_number TEXT, student_uid TEXT, student_user_id INTEGER, contact_name TEXT, last_message_text TEXT, last_message_at TEXT, last_inbound_at TEXT, last_message_direction TEXT, unread_count INTEGER, status TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE whatsapp_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, wa_message_id TEXT UNIQUE, direction TEXT, message_type TEXT, message_text TEXT, media_id TEXT, media_mime_type TEXT, media_filename TEXT, caption TEXT, reply_to_wa_message_id TEXT, status TEXT, raw_payload TEXT, sent_at TEXT, created_at TEXT);
    CREATE TABLE whatsapp_notifications (id INTEGER PRIMARY KEY, phone TEXT, message TEXT, student_name TEXT, sent_by TEXT, status TEXT, latitude REAL, longitude REAL, metadata TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE whatsapp_mode_audit (id INTEGER PRIMARY KEY, old_mode TEXT, new_mode TEXT, changed_by TEXT, changed_at TEXT);
    CREATE TABLE leads (id INTEGER PRIMARY KEY, is_opted_out INTEGER DEFAULT 0);
    CREATE TABLE communication_campaigns (id INTEGER PRIMARY KEY, name TEXT, target_audience TEXT, status TEXT);
    CREATE TABLE communication_campaign_recipients (id INTEGER PRIMARY KEY, campaign_id INTEGER, recipient TEXT, queue_id INTEGER, lead_id INTEGER, status TEXT);
");

$pdo4->exec("
    INSERT INTO admin_settings (setting_name, setting_value) VALUES
    ('whatsapp_outbound_mode', 'meta_api'),
    ('whatsapp_phone_number_id', 'PHONE_ID_ADMISSIONS_111'),
    ('whatsapp_business_account_id', 'WABA_ADMISSIONS_111'),
    ('whatsapp_permanent_access_token', 'TOKEN_ADMISSIONS_111'),
    ('whatsapp_webhook_verify_token', 'pepp_verify_token_2026'),
    ('whatsapp_app_secret', ''),
    ('whatsapp_auto_response_cooldown', '600');

    INSERT INTO whatsapp_accounts (id, sender_key, phone_number_id, display_number, display_name, is_default, status)
    VALUES (1, 'admissions', 'PHONE_ID_ADMISSIONS_111', '+919999900001', 'PEPP Learning', 1, 'active');

    INSERT INTO whatsapp_mode_audit (old_mode, new_mode, changed_by, changed_at)
    VALUES ('manual', 'meta_api', 'test_admin', '2020-01-01 00:00:00');

    INSERT INTO users (user_id, full_name, name, pepp_course, email, phone, whatsapp_country_code, whatsapp_number, status, student_status)
    VALUES ('STU001', 'Alice Active', 'Alice Active', 'CUET Commerce', 'alice@example.com', '9876543210', '91', '9876543210', 'approved', 'active');
");

$stmtTpl4 = $pdo4->prepare("
    INSERT INTO communication_templates (template_name, channel, category, language, body_text, meta_data, status)
    VALUES 
    ('pepp_onboarding_inquiry', 'whatsapp', 'MARKETING', 'en_US', 'Would you like to enroll?', ?, 'approved'),
    ('pepp_auto_reply_confirmation', 'whatsapp', 'UTILITY', 'en_US', 'Thank you for confirming your enrollment with PEPP Learning!', ?, 'approved')
");
$stmtTpl4->execute([$sourceTemplateMeta, $targetTemplateMeta]);
$stmtTpl4 = null;
$pdo4 = null;

$inboundWebhookPayload = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [
        [
            'id' => 'WABA_ADMISSIONS_111',
            'changes' => [
                [
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+919999900001',
                            'phone_number_id' => 'PHONE_ID_ADMISSIONS_111'
                        ],
                        'contacts' => [
                            [
                                'profile' => ['name' => 'Alice Active'],
                                'wa_id' => '919876543210'
                            ]
                        ],
                        'messages' => [
                            [
                                'from' => '919876543210',
                                'id' => 'wamid.INBOUND_BTN_' . uniqid(),
                                'timestamp' => (string)time(),
                                'type' => 'button',
                                'button' => [
                                    'text' => 'Yes, enroll me',
                                    'payload' => 'PAYLOAD_QUICK_REPLY_YES'
                                ]
                            ]
                        ]
                    ],
                    'field' => 'messages'
                ]
            ]
        ]
    ]
]);

// Write payload to temporary file for reliable stdin in php-cgi
$payloadFile = sys_get_temp_dir() . '/webhook_payload_' . uniqid() . '.json';
file_put_contents($payloadFile, $inboundWebhookPayload);

// Prepend file to inject mock provider onto CommunicationEngine singleton
$prependFile = sys_get_temp_dir() . '/webhook_prepend_' . uniqid() . '.php';
$prependContent = '<?php
require_once "' . addslashes(str_replace('\\', '/', $admissionsDir)) . '/config/database.php";
require_once "' . addslashes(str_replace('\\', '/', $admissionsDir)) . '/includes/communication/CommunicationEngine.php";

$eng = CommunicationEngine::getInstance($pdo);
$eng->mockProvider = new class implements CommunicationProviderInterface {
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = "", array $attachments = [], array $templateData = []) {
        return [
            "success" => true,
            "message_id" => "wamid.CGI_ENDTOEND_" . uniqid(),
            "error" => null
        ];
    }
};
';
file_put_contents($prependFile, $prependContent);

// Prepare CGI environment:
// - Non-CLI SAPI (cgi-fcgi)
// - NO HTTP_X_TESTING_MODE
// - NO admin session
// - PEPP_SQLITE_PATH pointing to isolated SQLite DB
$webhookPath = str_replace('\\', '/', $admissionsDir . '/api/v1/communication/webhook.php');
$cgiEnv = [
    'REQUEST_METHOD' => 'POST',
    'SERVER_NAME' => 'localhost',
    'SCRIPT_FILENAME' => $webhookPath,
    'SCRIPT_NAME' => '/admissions/api/v1/communication/webhook.php',
    'REQUEST_URI' => '/admissions/api/v1/communication/webhook.php',
    'CONTENT_TYPE' => 'application/json',
    'CONTENT_LENGTH' => (string)strlen($inboundWebhookPayload),
    'HTTP_ACCEPT' => 'application/json',
    'REDIRECT_STATUS' => '200',
    'PEPP_SQLITE_PATH' => str_replace('\\', '/', $tempDbPath4),
    'SystemRoot' => getenv('SystemRoot') ?: 'C:\Windows',
    'TEMP' => sys_get_temp_dir()
];

$prependFileNorm = str_replace('\\', '/', $prependFile);
$cmd = '"' . $phpCgi . '" -d auto_prepend_file="' . $prependFileNorm . '" "' . $webhookPath . '"';
$proc = proc_open($cmd, [
    0 => ['file', $payloadFile, 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
], $pipes, $admissionsDir, $cgiEnv);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);
@unlink($payloadFile);
@unlink($prependFile);

// Re-open PDO connection to inspect database state
$pdo4 = new PDO("sqlite:" . $tempDbPath4);
$pdo4->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo4->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo4->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });

assertTest("php-cgi execution completed with exit code 0", $exitCode === 0);

// Inspect HTTP headers and body from CGI output
$is401 = (strpos($stdout, '401 Unauthorized') !== false || strpos($stdout, '"Unauthorized access"') !== false);
assertTest("Response does NOT contain HTTP 401 Unauthorized", !$is401);
assertTest("Response does NOT contain 'Unauthorized access'", strpos($stdout, 'Unauthorized access') === false);

$is200 = (strpos($stdout, 'Status: 200 OK') !== false || strpos($stdout, 'HTTP/1.1 200') !== false || strpos($stdout, '{"success":true}') !== false);
assertTest("Webhook returned HTTP 200 with success:true", $is200 && strpos($stdout, '{"success":true}') !== false);

// Inspect database mutations from webhook execution:
// A. communication_webhook_events
$stmtEvent = $pdo4->query("SELECT * FROM communication_webhook_events ORDER BY id DESC LIMIT 1");
$eventRow = $stmtEvent->fetch(PDO::FETCH_ASSOC);
assertTest("communication_webhook_events recorded inbound event", !empty($eventRow));
assertTest("communication_webhook_events.processed is 1", (int)($eventRow['processed'] ?? 0) === 1);
assertTest("communication_webhook_events.processed_at is recorded", !empty($eventRow['processed_at']));

// B. Inbound message recorded in whatsapp_messages
$stmtInMsg = $pdo4->query("SELECT * FROM whatsapp_messages WHERE direction = 'inbound' ORDER BY id DESC LIMIT 1");
$inMsgRow = $stmtInMsg->fetch(PDO::FETCH_ASSOC);
assertTest("Inbound message recorded in whatsapp_messages", !empty($inMsgRow));

// C. Auto-reply button queue message created and synchronously dispatched to COMMIT
$stmtAutoReply = $pdo4->query("SELECT * FROM communication_queue WHERE event_name = 'auto_reply_button' ORDER BY id DESC LIMIT 1");
$autoReplyRow = $stmtAutoReply->fetch(PDO::FETCH_ASSOC);
assertTest("Auto-reply queue record was created", !empty($autoReplyRow));
assertTest("Auto-reply template is 'pepp_auto_reply_confirmation'", 
    strpos((string)$autoReplyRow['template_data'], 'pepp_auto_reply_confirmation') !== false);
assertTest("Auto-reply student_uid is STU001", $autoReplyRow['student_uid'] === 'STU001');

// Verification of synchronous dispatch reached COMMIT:
assertTest("Worker successfully claimed queue item (worker_started_at recorded)", !empty($autoReplyRow['worker_started_at']));
assertTest("Queue row committed in 'sent' status (immediate dispatch succeeded)", $autoReplyRow['status'] === 'sent');
assertTest("Queue item has message_id recorded from dispatch", !empty($autoReplyRow['message_id']) && str_starts_with($autoReplyRow['message_id'], 'wamid.CGI_ENDTOEND_'));
assertTest("Queue item api_requested_at is recorded", !empty($autoReplyRow['api_requested_at']));
assertTest("Queue item api_responded_at is recorded", !empty($autoReplyRow['api_responded_at']));
assertTest("Queue item error_message is NULL (no failure/rollback)", $autoReplyRow['error_message'] === null);

// Cleanup temporary test DBs
$stmtEvent = null;
$stmtInMsg = null;
$stmtAutoReply = null;
$pdo4 = null;
@unlink($tempDbPath4);
@unlink($tempDbPath);

echo "\n======================================================================\n";
echo "REGRESSION RESULTS: {$passedCount} / {$testCount} tests passed.\n";
if ($passedCount === $testCount) {
    echo "STATUS: ALL NON-CLI WEBHOOK REGRESSION CHECKS PASSED (100% SUCCESS)\n";
} else {
    echo "STATUS: FAILURES DETECTED IN REGRESSION AUDIT\n";
}
echo "======================================================================\n";
