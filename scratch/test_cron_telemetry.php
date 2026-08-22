<?php
/**
 * PEPP Communication Engine Integration Test Suite.
 * Validates retry capping, stale-number protection, cron telemetry, and background triggers.
 */

// Enable testing mode flag for fast execution
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/communication/CommunicationEngine.php';
require_once __DIR__ . '/../includes/communication/QueueProcessor.php';

// Mock Provider for testing purposes
class MockWhatsAppProvider extends WhatsAppCloudProvider {
    public $shouldFail = false;
    public $failReason = 'Meta API Error';
    public $sentParams = null;

    public function __construct() {}

    public function getLastError() {
        return $this->failReason;
    }

    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        $this->sentParams = [
            'recipient' => $to,
            'templateData' => $templateData
        ];
        if ($this->shouldFail) {
            return false;
        }
        return [
            'success' => true,
            'message_id' => 'wamid.mock_message_' . uniqid()
        ];
    }
}

class TestSuite {
    private $pdo;
    private $engine;
    private $mockProvider;

    public function __construct($pdo) {
        $this->pdo = $pdo;

        // Register custom NOW() function for SQLite compatibility
        $this->pdo->sqliteCreateFunction('NOW', function() {
            return date('Y-m-d H:i:s');
        });

        $this->engine = CommunicationEngine::getInstance($pdo);
        $this->mockProvider = new MockWhatsAppProvider();
        
        // Inject mock provider
        $this->engine->mockProvider = $this->mockProvider;
    }

    public function initSchema() {
        // Drop simple mock tables and create complete mock schema for full compliance testing
        $this->pdo->exec("
            DROP TABLE IF EXISTS communication_queue;
            DROP TABLE IF EXISTS users;
            DROP TABLE IF EXISTS installment_whatsapp_reminders;
            DROP TABLE IF EXISTS communication_templates;
            DROP TABLE IF EXISTS admin_settings;
            DROP TABLE IF EXISTS communication_campaigns;
            DROP TABLE IF EXISTS communication_campaign_recipients;
            DROP TABLE IF EXISTS whatsapp_notifications;

            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                name TEXT,
                whatsapp_country_code TEXT,
                whatsapp_number TEXT,
                status TEXT,
                type TEXT,
                created_at TEXT
            );

            CREATE TABLE communication_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT,
                recipient TEXT,
                recipient_name TEXT,
                subject TEXT,
                body_html TEXT,
                body_text TEXT,
                template_name TEXT,
                template_data TEXT,
                attachments TEXT,
                status TEXT,
                next_attempt_at TEXT,
                sent_by TEXT,
                student_uid TEXT,
                event_name TEXT,
                invoice_id TEXT,
                error_message TEXT,
                message_id TEXT,
                retry_count INTEGER DEFAULT 0,
                last_retry_at TEXT,
                worker_started_at TEXT,
                api_requested_at TEXT,
                api_responded_at TEXT,
                created_at TEXT,
                updated_at TEXT
            );

            CREATE TABLE installment_whatsapp_reminders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                installment_id INTEGER,
                reminder_stage INTEGER,
                status TEXT,
                queue_id INTEGER
            );

            CREATE TABLE communication_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_name TEXT,
                status TEXT,
                meta_data TEXT
            );

            CREATE TABLE admin_settings (
                setting_name TEXT PRIMARY KEY,
                setting_value TEXT,
                updated_at TEXT
            );

            CREATE TABLE communication_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_name TEXT,
                status TEXT,
                target_audience TEXT,
                created_at TEXT
            );

            CREATE TABLE communication_campaign_recipients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER,
                lead_id INTEGER,
                recipient TEXT,
                recipient_name TEXT,
                queue_id INTEGER,
                status TEXT,
                created_at TEXT
            );

            CREATE TABLE whatsapp_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone TEXT,
                status TEXT,
                message TEXT,
                student_name TEXT,
                latitude TEXT,
                longitude TEXT,
                metadata TEXT,
                type TEXT,
                meta_message_id TEXT,
                template_name TEXT,
                template_data TEXT,
                error_message TEXT,
                sent_by TEXT,
                student_uid TEXT,
                event_name TEXT,
                created_at TEXT,
                updated_at TEXT
            );

            DROP TABLE IF EXISTS whatsapp_conversations;
            CREATE TABLE whatsapp_conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                wa_phone_number TEXT,
                student_uid TEXT,
                student_user_id TEXT,
                contact_name TEXT,
                last_message_text TEXT,
                last_message_at TEXT,
                unread_count INTEGER DEFAULT 0,
                status TEXT,
                created_at TEXT,
                updated_at TEXT
            );

            DROP TABLE IF EXISTS whatsapp_messages;
            CREATE TABLE whatsapp_messages (
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

            DROP TABLE IF EXISTS whatsapp_mode_audit;

            CREATE TABLE whatsapp_mode_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                new_mode TEXT,
                changed_at TEXT
            );

            INSERT INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_webhook_verify_token', 'test_verify_token');
            INSERT INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_app_secret', 'test_app_secret');
            INSERT INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_outbound_mode', 'meta_api');
            INSERT INTO admin_settings (setting_name, setting_value) VALUES ('whatsapp_cron_worker_key', 'test_cron_worker_key');
            INSERT INTO whatsapp_mode_audit (new_mode, changed_at) VALUES ('meta_api', '2000-01-01 00:00:00');
        ");
    }

    public function run() {
        echo "=== PEPP WhatsApp Communication Engine Integration Tests ===\n\n";

        $this->runTest('TEST A: Permanent Recipient Error Capping', function() {
            $this->mockProvider->shouldFail = true;
            $this->mockProvider->failReason = 'Recipient cannot be reached (Meta error 131026)';

            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test User', 'Subject', '<p>Body</p>', 'Body', [], [], 'system_test');
            
            // First attempt
            $this->engine->processQueueItem($queueId);
            
            $stmt = $this->pdo->prepare("SELECT status, retry_count, error_message FROM communication_queue WHERE id = ?");
            $stmt->execute([$queueId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assertEquals('failed', $res['status'], "Attempt 1 should mark failed");
            $this->assertEquals(1, $res['retry_count'], "Attempt 1 should increment retry_count to 1");

            // Second attempt (retry)
            $this->pdo->prepare("UPDATE communication_queue SET status='pending', next_attempt_at=NOW() WHERE id=?")->execute([$queueId]);
            $this->engine->processQueueItem($queueId);
            
            $stmt->execute([$queueId]);
            $res2 = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assertEquals('failed', $res2['status'], "Attempt 2 should mark failed");
            $this->assertEquals(3, $res2['retry_count'], "Attempt 2 should cap retries to channel maximum");
            $this->assertStringContainsString('131026', $res2['error_message'], "Error message should contain Meta error code 131026");
        });

        $this->runTest('TEST B: Template/Configuration Error Fail-Fast', function() {
            $this->mockProvider->shouldFail = true;
            $this->mockProvider->failReason = 'Parameter count mismatch: expects 3, 1 provided.';

            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test User', 'Subject', '<p>Body</p>', 'Body', [], [], 'system_test');
            $this->engine->processQueueItem($queueId);
            
            $stmt = $this->pdo->prepare("SELECT status, retry_count, error_message FROM communication_queue WHERE id = ?");
            $stmt->execute([$queueId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assertEquals('failed', $res['status'], "Config error should fail status");
            $this->assertEquals(3, $res['retry_count'], "Config error should fail-fast immediately (max retry)");
            $this->assertStringContainsString('Parameter count mismatch', $res['error_message'], "Error message should contain parameter count mismatch details");
        });

        $this->runTest('TEST C & D: Profile Number Change Sync & Pre-Send Validation', function() {
            $this->mockProvider->shouldFail = false;
            
            // Insert test user
            $userId = 'test_student_' . uniqid();
            $insUser = $this->pdo->prepare("INSERT INTO users (user_id, name, whatsapp_country_code, whatsapp_number, status, type, created_at) VALUES (?, 'Test Student', '91', '9999999999', 'approved', 'student', NOW())");
            $insUser->execute([$userId]);

            // Queue a message for the old number
            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test Student', 'Reminder', '<p>Body</p>', 'Body', [], [], 'system_scheduler', null, $userId);
            
            // Change user number in DB
            $updUser = $this->pdo->prepare("UPDATE users SET whatsapp_number = '8888888888' WHERE user_id = ?");
            $updUser->execute([$userId]);

            // Run pre-send validation by processing the queue item
            $this->engine->processQueueItem($queueId);

            // Assert old queue item is superseded
            $stmtOld = $this->pdo->prepare("SELECT status, error_message FROM communication_queue WHERE id = ?");
            $stmtOld->execute([$queueId]);
            $oldQ = $stmtOld->fetch(PDO::FETCH_ASSOC);
            
            $this->assertEquals('failed', $oldQ['status'], "Old queue item should be failed");
            $this->assertEquals('Superseded: Recipient number changed', $oldQ['error_message'], "Old queue item should show superseded message");

            // Assert new replacement queue item is created targeting new number
            $stmtNew = $this->pdo->prepare("SELECT id, recipient, status FROM communication_queue WHERE student_uid = ? AND id != ? ORDER BY id DESC LIMIT 1");
            $stmtNew->execute([$userId, $queueId]);
            $newQ = $stmtNew->fetch(PDO::FETCH_ASSOC);
            
            $this->assertNotEmpty($newQ, "Replacement queue item should exist");
            $this->assertEquals('918888888888', $newQ['recipient'], "New queue item should target the new normalized number");
            $this->assertEquals('pending', $newQ['status'], "New queue item should be pending");
        });

        $this->runTest('TEST E: Installment Tracking Queue ID Migration', function() {
            $userId = 'test_student_' . uniqid();
            $this->pdo->prepare("INSERT INTO users (user_id, name, whatsapp_country_code, whatsapp_number, status, type, created_at) VALUES (?, 'Test Student', '91', '9999999999', 'approved', 'student', NOW())")->execute([$userId]);

            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test Student', 'Reminder', '<p>Body</p>', 'Body', [], [], 'system_scheduler', null, $userId);
            
            // Mock tracking row
            $this->pdo->prepare("INSERT INTO installment_whatsapp_reminders (installment_id, reminder_stage, status, queue_id) VALUES (9999, 1, 'queued', ?)")->execute([$queueId]);

            // Change number
            $this->engine->syncStudentQueueOnNumberChange($userId, '91', '8888888888');

            // Assert tracking ID updated to new queue ID
            $stmtTrack = $this->pdo->prepare("SELECT queue_id FROM installment_whatsapp_reminders WHERE installment_id = 9999");
            $stmtTrack->execute();
            $newQueueId = (int)$stmtTrack->fetchColumn();

            $this->assertNotEquals($queueId, $newQueueId, "Tracking queue ID should be updated to new replacement queue ID");
        });

        $this->runTest('TEST F: Duplicate Protection on Number Change', function() {
            $userId = 'test_student_' . uniqid();
            $this->pdo->prepare("INSERT INTO users (user_id, name, whatsapp_country_code, whatsapp_number, status, type, created_at) VALUES (?, 'Test Student', '91', '9999999999', 'approved', 'student', NOW())")->execute([$userId]);

            // Queue reminder once
            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test Student', 'Reminder', '<p>Body</p>', 'Body', [], [], 'system_scheduler', null, $userId);
            
            // Run sync twice
            $this->engine->syncStudentQueueOnNumberChange($userId, '91', '8888888888');
            $this->engine->syncStudentQueueOnNumberChange($userId, '91', '8888888888');

            // Assert only one replacement item created
            $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM communication_queue WHERE student_uid = ? AND recipient = '918888888888'");
            $stmtCount->execute([$userId]);
            $count = (int)$stmtCount->fetchColumn();

            $this->assertEquals(1, $count, "Only one replacement queue item should be created (duplicate protected)");
        });

        $this->runTest('TEST G: Transient Error Backoff and Retry', function() {
            $this->mockProvider->shouldFail = true;
            $this->mockProvider->failReason = 'Temporary HTTP 503 Service Unavailable';

            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test User', 'Subject', '<p>Body</p>', 'Body', [], [], 'system_test');
            $this->engine->processQueueItem($queueId);
            
            $stmt = $this->pdo->prepare("SELECT status, retry_count, error_message FROM communication_queue WHERE id = ?");
            $stmt->execute([$queueId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assertEquals('failed', $res['status'], "Transient error should keep status failed for scheduling");
            $this->assertEquals(1, $res['retry_count'], "Transient error should increment retry_count to 1");
            $this->assertStringContainsString('Temporary HTTP 503', $res['error_message'], "Error message should contain transient HTTP error details");
        });

        $this->runTest('TEST H: Individual Queue Pause & Exclusions', function() {
            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test User', 'Subject', '<p>Body</p>', 'Body', [], [], 'system_test');
            $this->pdo->prepare("UPDATE communication_queue SET status = 'paused' WHERE id = ?")->execute([$queueId]);
            
            $success = $this->engine->processQueueItem($queueId);
            $this->assertEquals(false, $success, "Paused item should return false from processQueueItem");
            
            $status = $this->pdo->query("SELECT status FROM communication_queue WHERE id = {$queueId}")->fetchColumn();
            $this->assertEquals('paused', $status, "Status should remain paused");
        });

        $this->runTest('TEST I: Individual Queue Resume', function() {
            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test User', 'Subject', '<p>Body</p>', 'Body', [], [], 'system_test');
            $this->pdo->prepare("UPDATE communication_queue SET status = 'paused' WHERE id = ?")->execute([$queueId]);
            
            $this->pdo->prepare("UPDATE communication_queue SET status = 'pending', next_attempt_at = NOW() WHERE id = ?")->execute([$queueId]);
            $this->mockProvider->shouldFail = false;
            $success = $this->engine->processQueueItem($queueId);
            $this->assertEquals(true, $success, "Resumed item should process successfully");
            
            $status = $this->pdo->query("SELECT status FROM communication_queue WHERE id = {$queueId}")->fetchColumn();
            $this->assertEquals('sent', $status, "Status should update to sent");
        });

        $this->runTest('TEST J: Individual Queue Cancel & Exclusions', function() {
            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test User', 'Subject', '<p>Body</p>', 'Body', [], [], 'system_test');
            $this->pdo->prepare("UPDATE communication_queue SET status = 'cancelled', next_attempt_at = '2038-01-01 00:00:00' WHERE id = ?")->execute([$queueId]);
            
            $success = $this->engine->processQueueItem($queueId);
            $this->assertEquals(false, $success, "Cancelled item should return false from processQueueItem");
            
            $status = $this->pdo->query("SELECT status FROM communication_queue WHERE id = {$queueId}")->fetchColumn();
            $this->assertEquals('cancelled', $status, "Status should remain cancelled");
        });

        $this->runTest('TEST K: Global Queue Pause', function() {
            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test User', 'Subject', '<p>Body</p>', 'Body', [], [], 'system_test');
            $this->engine->setQueuePaused(true);
            
            $success = $this->engine->processQueueItem($queueId);
            $this->assertEquals(false, $success, "Globally paused queue should skip processQueueItem");
            
            $status = $this->pdo->query("SELECT status FROM communication_queue WHERE id = {$queueId}")->fetchColumn();
            $this->assertEquals('pending', $status, "Status should remain pending");
            
            $this->engine->setQueuePaused(false);
            $success2 = $this->engine->processQueueItem($queueId);
            $this->assertEquals(true, $success2, "Resuming globally should process queue items");
        });

        $this->runTest('TEST L: Permanent Recipient Failure Suppression', function() {
            $this->engine->queueMessage('whatsapp', '917777777777', 'Stale Recipient', 'Subject', 'Html', 'Text', [], [], 'system_test');
            $this->pdo->exec("UPDATE communication_queue SET status = 'failed', retry_count = 3, error_message = 'Meta API Error: 131026 policy block' WHERE recipient = '917777777777'");
            
            $newQueueId = $this->engine->queueMessage('whatsapp', '917777777777', 'Stale Recipient', 'Subject', 'Html', 'Text', [], [], 'system');
            
            $stmt = $this->pdo->prepare("SELECT status, retry_count, error_message FROM communication_queue WHERE id = ?");
            $stmt->execute([$newQueueId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->assertEquals('failed', $res['status'], "Suppressed item should be created in failed status");
            $this->assertEquals(3, $res['retry_count'], "Suppressed item should have max retries = 3");
            $this->assertStringContainsString('previously failed permanently', $res['error_message'], "Error message should show suppression reason");
            
            $manualQueueId = $this->engine->queueMessage('whatsapp', '917777777777', 'Stale Recipient', 'Subject', 'Html', 'Text', [], [], 'admin_username');
            $this->mockProvider->shouldFail = false;
            $success = $this->engine->processQueueItem($manualQueueId);
            $this->assertEquals(true, $success, "Manual admin queue should bypass suppression and succeed");
        });

        $this->runTest('TEST M: Pre-Send Phone Change Sync & Superseded Marker', function() {
            $userId = 'test_student_' . uniqid();
            $this->pdo->prepare("INSERT INTO users (user_id, name, whatsapp_country_code, whatsapp_number, status, type, created_at) VALUES (?, 'Test Student', '91', '9999999999', 'approved', 'student', NOW())")->execute([$userId]);

            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test Student', 'Reminder', '<p>Body</p>', 'Body', [], [], 'system_scheduler', null, $userId);
            
            // Simulating a student changing number right before Meta API send (during processing state)
            $this->pdo->prepare("UPDATE users SET whatsapp_number = '8888888888' WHERE user_id = ?")->execute([$userId]);
            
            // Claim row and process it
            $this->engine->processQueueItem($queueId);

            // Assert old queue item is superseded/failed
            $oldQ = $this->pdo->query("SELECT status, error_message FROM communication_queue WHERE id = {$queueId}")->fetch(PDO::FETCH_ASSOC);
            $this->assertEquals('failed', $oldQ['status']);
            $this->assertEquals('Superseded: Recipient number changed', $oldQ['error_message']);

            // Assert new replacement queue item is created targeting new number
            $newQ = $this->pdo->query("SELECT id, recipient, status FROM communication_queue WHERE student_uid = '{$userId}' AND id != {$queueId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertNotEmpty($newQ);
            $this->assertEquals('918888888888', $newQ['recipient']);
        });

        $this->runTest('TEST N: Race Condition Pre-Send Cancel/Pause State Abort', function() {
            $queueId = $this->engine->queueMessage('whatsapp', '919999999999', 'Test User', 'Subject', '<p>Body</p>', 'Body', [], [], 'system_test');
            $this->pdo->prepare("UPDATE communication_queue SET status = 'cancelled' WHERE id = ?")->execute([$queueId]);
            $success = $this->engine->processQueueItem($queueId);
            $this->assertEquals(false, $success, "Process should return false since it is cancelled");
        });

        echo "\n=== All Tests Completed Successfully ===\n";
    }

    private function runTest($name, $callback) {
        echo "Running {$name}... ";
        try {
            $this->initSchema();
            $callback();
            echo "[\033[32mPASS\033[0m]\n";
        } catch (Exception $e) {
            echo "[\033[31mFAIL\033[0m]\n";
            echo "Error Details: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    private function assertEquals($expected, $actual, $msg = '') {
        if ($expected !== $actual) {
            throw new Exception("Assertion failed: Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ". {$msg}");
        }
    }

    private function assertNotEmpty($value, $msg = '') {
        if (empty($value)) {
            throw new Exception("Assertion failed: Value is empty. {$msg}");
        }
    }

    private function assertNotEquals($expected, $actual, $msg = '') {
        if ($expected === $actual) {
            throw new Exception("Assertion failed: Expected not equal, both are " . var_export($expected, true) . ". {$msg}");
        }
    }

    private function assertStringContainsString($needle, $haystack, $msg = '') {
        if (strpos((string)$haystack, (string)$needle) === false) {
            throw new Exception("Assertion failed: '{$haystack}' does not contain '{$needle}'. {$msg}");
        }
    }
}

// Run the suite
$suite = new TestSuite($pdo);
$suite->run();
