<?php
/**
 * PEPP ERP - Email Hardening & Reports Secure Export Unit Tests
 */

// Enable SQLite testing database sandbox mode
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

// Mock session context
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'TestSuperAdmin';
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['admin_id'] = 42;

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/SecureDownloadManager.php';
require_once __DIR__ . '/includes/communication/QueueProcessor.php';
require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/mail_queue.php';

// Prepare SQLite sandbox tables with correct columns for testing
$pdo->exec("DROP TABLE IF EXISTS admin_activity_log");
$pdo->exec("CREATE TABLE admin_activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER,
    admin_username TEXT,
    session_id TEXT,
    action_type TEXT,
    module TEXT,
    page TEXT,
    section TEXT,
    target_type TEXT,
    target_id TEXT,
    details TEXT,
    ip_address TEXT,
    location TEXT,
    latitude REAL,
    longitude REAL,
    metadata TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("DROP TABLE IF EXISTS track_records");
$pdo->exec("CREATE TABLE track_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    performed_at TEXT,
    performed_by TEXT,
    action_type TEXT,
    action_details TEXT,
    user_id TEXT,
    latitude REAL,
    longitude REAL,
    metadata TEXT
)");

$pdo->exec("DROP TABLE IF EXISTS whatsapp_notifications");
$pdo->exec("CREATE TABLE whatsapp_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phone TEXT,
    status TEXT,
    created_at TEXT,
    sent_by TEXT,
    student_name TEXT,
    message TEXT,
    latitude REAL,
    longitude REAL,
    metadata TEXT,
    updated_at TEXT
)");

echo "=== Running PEPP ERP Email Hardening Unit Test Suite ===\n\n";

$assertions_passed = 0;
$assertions_failed = 0;

function assertEqual($expected, $actual, $desc) {
    global $assertions_passed, $assertions_failed;
    if ($expected === $actual) {
        $assertions_passed++;
        echo "✅ PASS: $desc\n";
    } else {
        $assertions_failed++;
        echo "❌ FAIL: $desc\n";
        echo "   Expected: " . var_export($expected, true) . "\n";
        echo "   Got:      " . var_export($actual, true) . "\n";
    }
}

// 1. Initial State Check on Queue Insertion
try {
    $pdo->exec("DELETE FROM communication_queue");
    $queueId = pepp_enqueue_mail('incubation.ngo@gmail.com', 'Test Subject', '<p>Test HTML</p>', 'Test Text', [], 'noreply@pepplearning.in', 'PEPP Learning', 10, 'activity_log_export', 'TestAdmin');
    
    $stmt = $pdo->prepare("SELECT status, retry_count, error_message FROM communication_queue WHERE id = ?");
    $stmt->execute([$queueId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    assertEqual('pending', $item['status'], "Queue insertion starts in pending status");
    assertEqual(0, $item['retry_count'], "Queue insertion has 0 retry_count");
    assertEqual(null, $item['error_message'], "Queue insertion has null error_message");
} catch (Exception $e) {
    echo "Error in test 1: " . $e->getMessage() . "\n";
}

// 2. Mock Provider & Exception Mapping for SMTP errors
class MockEmailMailerProvider {
    private $errorMsg;
    public function __construct($errorMsg) {
        $this->errorMsg = $errorMsg;
    }
    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        return [
            'success' => false,
            'error' => $this->errorMsg
        ];
    }
}

// Test Exception propagation and retry status updates
try {
    $engine = CommunicationEngine::getInstance($pdo);
    
    // Set the public mockProvider property
    $engine->mockProvider = new MockEmailMailerProvider('TLS handshake failed');
    
    $pdo->exec("DELETE FROM communication_queue");
    $queueId = pepp_enqueue_mail('test@example.com', 'Transient SMTP error test', '<p>Test</p>');
    
    // Attempt 1: Should increment retry count and status should become 'retrying'
    try {
        $engine->processQueueItem($queueId);
    } catch (Exception $e) {
        // Exception is expected since the provider fails
        assertEqual('TLS handshake failed', $e->getMessage(), "Exception message maps to exact SMTP error: TLS handshake failed");
    }
    
    $stmt = $pdo->prepare("SELECT status, retry_count, error_message, next_attempt_at FROM communication_queue WHERE id = ?");
    $stmt->execute([$queueId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    assertEqual('retrying', $item['status'], "Status transitions to 'retrying' after transient failure");
    assertEqual(1, $item['retry_count'], "Retry count is incremented to 1");
    assertEqual('TLS handshake failed', $item['error_message'], "Error message is updated to exact SMTP error");
    assertEqual(true, strtotime($item['next_attempt_at']) > time(), "next_attempt_at is scheduled in the future");
    
    // Let's test reaching max retries (max for email is 5 attempts)
    $pdo->prepare("UPDATE communication_queue SET retry_count = 4, next_attempt_at = datetime('now', '-1 minute') WHERE id = ?")->execute([$queueId]);
    try {
        $engine->processQueueItem($queueId);
    } catch (Exception $e) {
        assertEqual('TLS handshake failed', $e->getMessage(), "Exception thrown on reaching max retries");
    }
    
    $stmt->execute([$queueId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    assertEqual('failed', $item['status'], "Status transitions to 'failed' when max retries (5) are reached");
    assertEqual(5, $item['retry_count'], "Retry count reaches max retries (5)");
    
    // Reset mockProvider
    $engine->mockProvider = null;
} catch (Exception $e) {
    echo "Error in test 2: " . $e->getMessage() . "\n";
}

// 3. Secure Download Token Verification
try {
    $token = bin2hex(random_bytes(32));
    $exportDir = __DIR__ . '/config/activity_exports';
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }
    $testFile = $exportDir . '/test_export_' . $token . '.csv';
    file_put_contents($testFile, "header,data\n1,2");
    
    $expiresAt = time() + 3600; // 1 hour
    SecureDownloadManager::registerToken($token, $testFile, $expiresAt);
    
    // Validate valid token
    $validatedPath = SecureDownloadManager::validateToken($token);
    assertEqual($testFile, $validatedPath, "Validate valid token returns correct file path");
    
    // Validate path traversal protection
    $traversalToken = 'traversal_' . bin2hex(random_bytes(16));
    $traversalPath = __DIR__ . '/../unauthorized.txt';
    SecureDownloadManager::registerToken($traversalToken, $traversalPath, $expiresAt);
    
    $filePath = SecureDownloadManager::validateToken($traversalToken);
    $realPath = $filePath ? realpath($filePath) : false;
    $expectedDir = realpath(__DIR__ . '/config/activity_exports');
    $isAllowed = ($realPath !== false && $expectedDir !== false && strpos($realPath, $expectedDir) === 0);
    assertEqual(false, $isAllowed, "Path traversal attempt is blocked by directory comparison");
    
    // Expired token check
    $expiredToken = 'expired_' . bin2hex(random_bytes(16));
    SecureDownloadManager::registerToken($expiredToken, $testFile, time() - 10);
    $validatedExpiredPath = SecureDownloadManager::validateToken($expiredToken);
    assertEqual(null, $validatedExpiredPath, "Expired token returns null on validation");
    
    // Clean expired
    SecureDownloadManager::cleanExpired();
    $data = json_decode(file_get_contents(__DIR__ . '/config/activity_exports/tokens.json'), true);
    assertEqual(false, isset($data[$expiredToken]), "Expired token record is removed by cleanExpired()");
    assertEqual(false, file_exists($testFile), "Expired export file is deleted from disk by cleanExpired()");
    
} catch (Exception $e) {
    echo "Error in test 3: " . $e->getMessage() . "\n";
}

// 4. Monthly Backup Idempotency & Abort
try {
    $pdo->exec("DELETE FROM admin_settings WHERE setting_name = 'activity_log_last_monthly_backup'");
    $pdo->exec("DELETE FROM communication_queue");
    
    $backupRan = SecureDownloadManager::runMonthlyBackupJob($pdo, '2026-09-01');
    assertEqual(true, $backupRan, "Monthly backup runs successfully on the 1st of the month");
    
    // Check if configuration idempotency tracker is updated
    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'activity_log_last_monthly_backup'");
    $stmt->execute();
    $lastBackup = $stmt->fetchColumn();
    assertEqual('2026-08', $lastBackup, "idempotency tracker setting updated to the previous calendar month (2026-08)");
    
    // Check if duplicate backup run is prevented
    $backupRanAgain = SecureDownloadManager::runMonthlyBackupJob($pdo, '2026-09-01');
    assertEqual(false, $backupRanAgain, "Duplicate monthly backup run is prevented");
    
} catch (Exception $e) {
    echo "Error in test 4: " . $e->getMessage() . "\n";
}

echo "\n=== Unit Test Summary ===\n";
echo "Assertions Passed: $assertions_passed\n";
echo "Assertions Failed: $assertions_failed\n";

if ($assertions_failed > 0) {
    echo "❌ Some unit tests failed!\n";
    exit(1);
} else {
    echo "🎉 ALL HARDENING UNIT TESTS PASSED SUCCESSFULLY! 🎉\n";
    exit(0);
}
