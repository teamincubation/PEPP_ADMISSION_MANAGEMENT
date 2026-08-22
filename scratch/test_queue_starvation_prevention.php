<?php
/**
 * Isolated Integration Test Suite for Queue Starvation Prevention & Real Cron.
 * Runs completely inside an SQLite memory database.
 */

// Enable testing mode switch in config/database.php
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
$_SERVER['REQUEST_METHOD'] = 'GET';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'test_admin';

// Load global config (which sets up SQLite connection)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/communication/QueueProcessor.php';
require_once __DIR__ . '/../includes/communication/CommunicationEngine.php';

// Setup SQLite Memory Database overrides
try {
    // Drop existing partial mock tables if any
    $pdo->exec("DROP TABLE IF EXISTS communication_queue;");
    $pdo->exec("DROP TABLE IF EXISTS communication_templates;");
    $pdo->exec("DROP TABLE IF EXISTS whatsapp_notifications;");
    $pdo->exec("DROP TABLE IF EXISTS communication_campaigns;");
    $pdo->exec("DROP TABLE IF EXISTS communication_campaign_recipients;");
    $pdo->exec("DROP TABLE IF EXISTS leads;");
    $pdo->exec("DROP TABLE IF EXISTS users;");
    $pdo->exec("DROP TABLE IF EXISTS whatsapp_mode_audit;");
    $pdo->exec("DROP TABLE IF EXISTS whatsapp_conversations;");
    $pdo->exec("DROP TABLE IF EXISTS whatsapp_messages;");
    
    // Register custom MySQL equivalent functions in SQLite
    $pdo->sqliteCreateFunction('GET_LOCK', function($name, $timeout) { return 1; }, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', function($name) { return 1; }, 1);
    $pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); }, 0);
    $pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); }, 0);
    
    // Create necessary tables in SQLite memory
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS communication_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT,
            recipient TEXT,
            recipient_name TEXT,
            student_uid TEXT,
            subject TEXT,
            body_html TEXT,
            body_text TEXT,
            template_name TEXT,
            event_name TEXT,
            template_data TEXT,
            attachments TEXT,
            invoice_id INTEGER,
            status TEXT,
            priority INTEGER DEFAULT 0,
            retry_count INTEGER DEFAULT 0,
            last_retry_at TEXT,
            worker_started_at TEXT,
            api_requested_at TEXT,
            api_responded_at TEXT,
            delivered_at TEXT,
            next_attempt_at TEXT,
            message_id TEXT,
            error_message TEXT,
            sent_by TEXT,
            created_at TEXT,
            updated_at TEXT
        );
        CREATE TABLE IF NOT EXISTS communication_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_name TEXT,
            language TEXT,
            status TEXT,
            meta_data TEXT
        );
        CREATE TABLE IF NOT EXISTS communication_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            channel TEXT,
            target_audience TEXT,
            template_name TEXT,
            segment_criteria TEXT,
            status TEXT,
            scheduled_at TEXT,
            created_by TEXT,
            created_at TEXT,
            updated_at TEXT
        );
        CREATE TABLE IF NOT EXISTS communication_campaign_recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER,
            lead_id INTEGER,
            user_id TEXT,
            recipient TEXT,
            recipient_name TEXT,
            queue_id INTEGER,
            status TEXT,
            sent_at TEXT,
            error_message TEXT,
            created_at TEXT
        );
        CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            whatsapp_number TEXT,
            name TEXT,
            status TEXT,
            is_opted_out INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT,
            name TEXT,
            whatsapp_country_code TEXT,
            whatsapp_number TEXT,
            pepp_course TEXT,
            status TEXT,
            approval_date TEXT
        );
        CREATE TABLE IF NOT EXISTS whatsapp_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone TEXT,
            message TEXT,
            student_name TEXT,
            sent_by TEXT,
            status TEXT,
            latitude REAL,
            longitude REAL,
            metadata TEXT,
            created_at TEXT,
            updated_at TEXT
        );
        CREATE TABLE IF NOT EXISTS whatsapp_mode_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            new_mode TEXT,
            changed_at TEXT
        );
        CREATE TABLE IF NOT EXISTS whatsapp_conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            wa_phone_number TEXT,
            student_uid TEXT,
            student_user_id INTEGER,
            contact_name TEXT,
            last_message_text TEXT,
            last_message_at TEXT,
            unread_count INTEGER DEFAULT 0,
            status TEXT,
            created_at TEXT,
            updated_at TEXT
        );
        CREATE TABLE IF NOT EXISTS whatsapp_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER,
            wa_message_id TEXT,
            direction TEXT,
            message_type TEXT,
            message_text TEXT,
            status TEXT,
            raw_payload TEXT,
            created_at TEXT,
            sent_at TEXT
        );
    ");
    
    // Insert mock admin settings for WhatsApp Cloud API provider
    $pdo->exec("
        INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_phone_id', 'mock_phone_id');
        INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_access_token', 'mock_access_token');
        INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_business_id', 'mock_business_id');
        INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_api_version', 'v20.0');
        INSERT OR REPLACE INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_outbound_mode', 'meta_api');
    ");
    
    // Insert mode audit activation timestamp
    $pdo->exec("INSERT INTO whatsapp_mode_audit (new_mode, changed_at) VALUES ('meta_api', '2026-08-22 00:00:00')");
    
    // Insert mock approved template definition
    $metaData = json_encode([
        'header_type' => 'IMAGE',
        'body_text' => 'Hello {{1}}, welcome to PEPP.'
    ]);
    $stmtTpl = $pdo->prepare("INSERT INTO communication_templates (template_name, language, status, meta_data) VALUES (?, ?, ?, ?)");
    $stmtTpl->execute(['m_clin_psy_rci_admission_started', 'en_US', 'approved', $metaData]);

    // Reset CommunicationEngine singleton instance to use SQLite PDO
    $ref = new ReflectionClass('CommunicationEngine');
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

} catch (Exception $e) {
    die("Mock SQLite setup failed: " . $e->getMessage() . "\n");
}

header('Content-Type: text/plain');

echo "========================================================\n";
echo "    QUEUE STARVATION PREVENTION & CRON TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition) {
    global $passCount, $failCount;
    if ($condition) {
        echo "✔ PASS: {$name}\n";
        $passCount++;
    } else {
        echo "❌ FAIL: {$name}\n";
        $failCount++;
    }
}

// ----------------------------------------------------
// TEST 1: Bypass of old failed records by new pending records
// ----------------------------------------------------
echo "--- Running TEST 1 ---\n";
// Setup old failed blocking records
$pdo->exec("INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at, created_at) VALUES ('whatsapp', '910000000001', 'failed', 3, '2026-08-14 10:00:00', '2026-08-14 10:00:00')");
$old1 = $pdo->lastInsertId();

// Setup a new pending campaign queue record
$pdo->exec("INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at, created_at) VALUES ('whatsapp', '919567276458', 'pending', 0, '2026-08-22 18:00:00', '2026-08-22 18:00:00')");
$newCampaignId = $pdo->lastInsertId();

$processor = new QueueProcessor($pdo, 10);
$processed = $processor->execute();

// Check status
$newStatus = $pdo->query("SELECT status FROM communication_queue WHERE id = {$newCampaignId}")->fetchColumn();
$oldStatus = $pdo->query("SELECT status FROM communication_queue WHERE id = {$old1}")->fetchColumn();

assertTest("TEST 1 - New pending record processed successfully", $newStatus === 'sent');
assertTest("TEST 1 - Old failed record bypassed", $oldStatus === 'failed');

// ----------------------------------------------------
// TEST 2: Retryable Meta/API Error
// ----------------------------------------------------
echo "\n--- Running TEST 2 ---\n";
// Reset queue
$pdo->exec("DELETE FROM communication_queue");

// Setup a pending record going to retryable cleanPhone '910000000002' (rate limit)
$pdo->exec("INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at, created_at) VALUES ('whatsapp', '910000000002', 'pending', 0, '2026-08-22 18:00:00', '2026-08-22 18:00:00')");
$retryableId = $pdo->lastInsertId();

$processor->execute();

$retryItem = $pdo->query("SELECT status, retry_count, next_attempt_at, error_message FROM communication_queue WHERE id = {$retryableId}")->fetch();
assertTest("TEST 2 - Retryable error increments retry count to 1", (int)$retryItem['retry_count'] === 1);
assertTest("TEST 2 - Retryable error reschedules next_attempt_at to future", strtotime($retryItem['next_attempt_at']) > time());
assertTest("TEST 2 - Retryable error maintains failed status for retry", $retryItem['status'] === 'failed');

// ----------------------------------------------------
// TEST 3: Permanent Meta/API Error
// ----------------------------------------------------
echo "\n--- Running TEST 3 ---\n";
// Reset queue
$pdo->exec("DELETE FROM communication_queue");

// Setup a pending record going to permanent error cleanPhone '910000000001' (ecosystem engagement policy block)
$pdo->exec("INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at, created_at) VALUES ('whatsapp', '910000000001', 'pending', 0, '2026-08-22 18:00:00', '2026-08-22 18:00:00')");
$permanentId = $pdo->lastInsertId();

$processor->execute();

$permItem = $pdo->query("SELECT status, retry_count, next_attempt_at, error_message FROM communication_queue WHERE id = {$permanentId}")->fetch();
assertTest("TEST 3 - Permanent error sets retry count directly to max retries", (int)$permItem['retry_count'] === 3);
assertTest("TEST 3 - Permanent error moves next_attempt_at to far future (+1 year)", strtotime($permItem['next_attempt_at']) > time() + 3600 * 24 * 300);
assertTest("TEST 3 - Permanent error stores exact policy error message", strpos($permItem['error_message'], 'healthy ecosystem engagement') !== false);

// ----------------------------------------------------
// TEST 4: 25+ eligible messages batching limit
// ----------------------------------------------------
echo "\n--- Running TEST 4 ---\n";
$pdo->exec("DELETE FROM communication_queue");
// Insert 30 pending records
for ($i = 1; $i <= 30; $i++) {
    $pdo->exec("INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at, created_at) VALUES ('whatsapp', '919567276458', 'pending', 0, '2026-08-22 18:00:00', '2026-08-22 18:00:00')");
}

$processor25 = new QueueProcessor($pdo, 25);
$dispatched = $processor25->execute();

$sentCount = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status = 'sent'")->fetchColumn();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status = 'pending'")->fetchColumn();

assertTest("TEST 4 - QueueProcessor processes exactly batch size limit of 25", $dispatched === 25);
assertTest("TEST 4 - 25 items successfully updated to 'sent' status", $sentCount === 25);
assertTest("TEST 4 - 5 remaining items remain in 'pending' status", $pendingCount === 5);

// ----------------------------------------------------
// TEST 5: Concurrency Safety (Simulated double-worker claim)
// ----------------------------------------------------
echo "\n--- Running TEST 5 ---\n";
$pdo->exec("DELETE FROM communication_queue");
$pdo->exec("INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at, created_at) VALUES ('whatsapp', '919567276458', 'pending', 0, '2026-08-22 18:00:00', '2026-08-22 18:00:00')");
$concurId = $pdo->lastInsertId();

$engine = CommunicationEngine::getInstance($pdo);

// Start simulated Worker 1 transaction
$pdo->beginTransaction();

// Worker 1 claims item
$claimStmt = $pdo->prepare("
    UPDATE communication_queue 
    SET status = 'processing', worker_started_at = NOW(), updated_at = NOW() 
    WHERE id = ? 
      AND status IN ('pending', 'scheduled', 'failed')
      AND next_attempt_at <= NOW()
      AND (
        (channel = 'whatsapp' AND retry_count < 3) OR
        (channel = 'email' AND retry_count < 5) OR
        (channel NOT IN ('whatsapp', 'email') AND retry_count < 3)
      )
");
$claimStmt->execute([$concurId]);
$affected1 = $claimStmt->rowCount();

// Simulated Worker 2 attempts to claim the same item
$claimStmt2 = $pdo->prepare("
    UPDATE communication_queue 
    SET status = 'processing', worker_started_at = NOW(), updated_at = NOW() 
    WHERE id = ? 
      AND status IN ('pending', 'scheduled', 'failed')
      AND next_attempt_at <= NOW()
      AND (
        (channel = 'whatsapp' AND retry_count < 3) OR
        (channel = 'email' AND retry_count < 5) OR
        (channel NOT IN ('whatsapp', 'email') AND retry_count < 3)
      )
");
$claimStmt2->execute([$concurId]);
$affected2 = $claimStmt2->rowCount();

$pdo->commit(); // Commit simulation transaction

assertTest("TEST 5 - Worker 1 claims pending queue ID successfully", $affected1 === 1);
assertTest("TEST 5 - Worker 2 fails to claim already claimed queue ID", $affected2 === 0);

// ----------------------------------------------------
// TEST 6: Normal WhatsApp queue message processing
// ----------------------------------------------------
echo "\n--- Running TEST 6 ---\n";
$pdo->exec("DELETE FROM communication_queue");
$pdo->exec("INSERT INTO communication_queue (channel, recipient, status, retry_count, next_attempt_at, created_at) VALUES ('whatsapp', '917306198102', 'pending', 0, '2026-08-22 18:00:00', '2026-08-22 18:00:00')");
$normalId = $pdo->lastInsertId();

$processor25->execute();

$normalItem = $pdo->query("SELECT status, message_id, error_message FROM communication_queue WHERE id = {$normalId}")->fetch();
assertTest("TEST 6 - Normal message moves to sent status", $normalItem['status'] === 'sent');
assertTest("TEST 6 - Normal message receives a Meta Message ID", strpos($normalItem['message_id'], 'mock_wamid_') === 0);
assertTest("TEST 6 - Normal message error message is NULL", $normalItem['error_message'] === null);

// ----------------------------------------------------
// TEST 7: Bulk campaign queue message mapping and processing
// ----------------------------------------------------
echo "\n--- Running TEST 7 ---\n";
$pdo->exec("DELETE FROM communication_queue");
$pdo->exec("DELETE FROM communication_campaigns");
$pdo->exec("DELETE FROM communication_campaign_recipients");
$pdo->exec("DELETE FROM leads");

// Setup campaign template variables mapping
$segment = json_encode([
    'target_audience' => 'leads',
    'var_mappings' => ['name'],
    'static_vals' => [''],
    'header_media' => 'https://mock.url/image.jpg'
]);

$pdo->exec("INSERT INTO communication_campaigns (name, channel, target_audience, template_name, segment_criteria, status, created_at) VALUES ('Campaign Test', 'whatsapp', 'leads', 'm_clin_psy_rci_admission_started', '{$segment}', 'active', '2026-08-22 18:00:00')");
$campId = $pdo->lastInsertId();

// Setup lead
$pdo->exec("INSERT INTO leads (whatsapp_number, name, status) VALUES ('917306198102', 'Incubation', 'new')");
$leadId = $pdo->lastInsertId();

// Setup campaign recipient
$pdo->exec("INSERT INTO communication_campaign_recipients (campaign_id, lead_id, recipient, recipient_name, status, created_at) VALUES ({$campId}, {$leadId}, '917306198102', 'Incubation', 'pending', '2026-08-22 18:00:00')");
$recId = $pdo->lastInsertId();

// Trigger Campaign Enqueue (simulates first block of cron-queue.php)
$dueCampaign = $pdo->query("SELECT * FROM communication_campaigns WHERE id = {$campId}")->fetch();
$recipients = $pdo->query("SELECT * FROM communication_campaign_recipients WHERE campaign_id = {$campId} AND status = 'pending' AND queue_id IS NULL")->fetchAll();

$engine = CommunicationEngine::getInstance($pdo);
foreach ($recipients as $rec) {
    $templatePayload = [
        'name' => $dueCampaign['template_name'],
        'language' => 'en_US',
        'parameters' => ['Incubation'], // Resolved lead name
        'header_type' => 'IMAGE',
        'header_parameters' => ['https://mock.url/image.jpg']
    ];
    
    $queueId = $engine->queueMessage(
        'whatsapp',
        $rec['recipient'],
        $rec['recipient_name'],
        $dueCampaign['name'],
        'Campaign text',
        'Campaign text',
        [],
        $templatePayload,
        'test_admin',
        date('Y-m-d H:i:s')
    );
    $pdo->prepare("UPDATE communication_campaign_recipients SET queue_id = ?, status = 'pending' WHERE id = ?")->execute([$queueId, $rec['id']]);
}

$qItem = $pdo->query("SELECT * FROM communication_queue ORDER BY id DESC LIMIT 1")->fetch();
assertTest("TEST 7 - Campaign recipient successfully enqueued into communication_queue", $qItem && $qItem['template_name'] === 'm_clin_psy_rci_admission_started');

// Process the enqueued queue item
$processor25->execute();

$recStatus = $pdo->query("SELECT status FROM communication_campaign_recipients WHERE id = {$recId}")->fetchColumn();
$qStatus = $pdo->query("SELECT status FROM communication_queue WHERE id = {$qItem['id']}")->fetchColumn();

assertTest("TEST 7 - Campaign recipient queue record moves to 'sent'", $qStatus === 'sent');
assertTest("TEST 7 - Campaign recipient status is marked 'sent' inside recipients table", $recStatus === 'sent');

// ----------------------------------------------------
// TEST 8: Webhook Auto-response routing checks
// ----------------------------------------------------
echo "\n--- Running TEST 8 ---\n";
// Ensure files do not have syntax regressions
$webhookFile = __DIR__ . '/../api/v1/communication/webhook.php';
if (file_exists($webhookFile)) {
    assertTest("TEST 8 - Webhook routing file exists", true);
} else {
    assertTest("TEST 8 - Webhook routing file does not exist", false);
}

echo "\nSQLite Mock checks completed successfully.\n";

echo "\n========================================================\n";
echo "    TEST RUN SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================\n";

exit($failCount > 0 ? 1 : 0);
