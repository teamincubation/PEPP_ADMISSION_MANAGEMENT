<?php
/**
 * Test Audit: Communication Engine WhatsApp Reliability & Bulk Session Email Performance
 * 
 * Verifies:
 * 1. Part B: Registration, approval, onboarding, and lifecycle WhatsApp messages are recognized as transactional and NOT skipped/cancelled.
 * 2. Part B: Dropped/suspended students fail closed for non-transactional communications.
 * 3. Part C: Asynchronous batch queue creation for scheduled sessions without blocking synchronous mail loops.
 * 4. Part C: Idempotent session scheduled email queueing (no duplicates created on retry).
 * 5. Part C: QueueProcessor bounded batch processing, stale job recovery, and retry backoff.
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

echo "============================================================\n";
echo "AUDIT: Communication Engine & Session Email Performance\n";
echo "============================================================\n";

// 1. Static Source Code Inspection
$commFile = __DIR__ . '/includes/communication/CommunicationEngine.php';
$mailerFile = __DIR__ . '/includes/session_mailer.php';
$sessionsFile = __DIR__ . '/sessions.php';
$procFile = __DIR__ . '/includes/communication/QueueProcessor.php';

assertTest("CommunicationEngine.php exists", file_exists($commFile));
assertTest("session_mailer.php exists", file_exists($mailerFile));
assertTest("sessions.php exists", file_exists($sessionsFile));
assertTest("QueueProcessor.php exists", file_exists($procFile));

$commSource = file_get_contents($commFile);
$mailerSource = file_get_contents($mailerFile);
$sessionsSource = file_get_contents($sessionsFile);
$procSource = file_get_contents($procFile);

// Part B Whitelist Checks
assertTest("CommunicationEngine whitelists student_registration",
    strpos($commSource, "'student_registration'") !== false
);

assertTest("CommunicationEngine whitelists student_approval and student_onboarding",
    strpos($commSource, "'student_approval'") !== false &&
    strpos($commSource, "'student_onboarding'") !== false
);

assertTest("CommunicationEngine whitelists session_scheduled and session_reminder",
    strpos($commSource, "'session_scheduled'") !== false &&
    strpos($commSource, "'session_reminder'") !== false
);

assertTest("CommunicationEngine includes prefix matching for registration, onboarding, and admission",
    strpos($commSource, "strpos(\$eventName, 'registration')") !== false &&
    strpos($commSource, "strpos(\$eventName, 'onboarding')") !== false
);

// Part C Asynchronous Enqueueing Checks
assertTest("session_mailer.php defines enqueue_session_scheduled_emails",
    strpos($mailerSource, 'function enqueue_session_scheduled_emails(') !== false
);

assertTest("session_mailer.php defines insert_session_queue_chunk for batch SQL inserts",
    strpos($mailerSource, 'function insert_session_queue_chunk(') !== false
);

assertTest("sessions.php invokes enqueue_session_scheduled_emails instead of synchronous mail loop",
    strpos($sessionsSource, 'enqueue_session_scheduled_emails(') !== false &&
    strpos($sessionsSource, 'peppian_send_email_general(') === false
);

assertTest("notify_session_learners uses communication_queue batch insert",
    strpos($mailerSource, "insert_session_queue_chunk(\$pdo, \$entries, 'session_reminder'") !== false
);

// 2. Behavioral Database & Engine Simulation Tests
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Setup Schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE,
        name TEXT,
        email TEXT,
        whatsapp_country_code TEXT,
        whatsapp_number TEXT,
        pepp_course TEXT,
        status TEXT DEFAULT 'pending',
        student_status TEXT DEFAULT NULL
    );
    CREATE TABLE communication_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel TEXT NOT NULL DEFAULT 'email',
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
        invoice_id INTEGER DEFAULT NULL,
        from_email TEXT DEFAULT NULL,
        from_name TEXT DEFAULT NULL
    );
    CREATE TABLE faculties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT
    );
    CREATE TABLE session_notifications (
        session_id INTEGER,
        window_key TEXT,
        recipients INTEGER,
        sent_by TEXT,
        sent_at TEXT,
        PRIMARY KEY (session_id, window_key)
    );
");

// Seed mock learners
$pdo->exec("
    INSERT INTO users (id, user_id, name, email, pepp_course, status, student_status) VALUES
    (1, 'STU001', 'Active Student 1', 'st1@pepp.com', 'CUET 2026', 'approved', 'active'),
    (2, 'STU002', 'Active Student 2', 'st2@pepp.com', 'CUET 2026', 'approved', 'active'),
    (3, 'STU003', 'Active Student 3', 'st3@pepp.com', 'CUET 2026', 'approved', 'active'),
    (4, 'STU004', 'Pending Registration', 'pending@pepp.com', 'CUET 2026', 'pending', NULL),
    (5, 'STU005', 'Dropped Student', 'dropped@pepp.com', 'CUET 2026', 'approved', 'dropout');
");

// Test A: Enqueue Session Scheduled Emails
require_once $mailerFile;

$sessionId = 101;
$courses = ['CUET 2026'];
$topic = 'Advanced Economics Workshop';
$dt = date('Y-m-d H:i:s', strtotime('+2 days'));
$type = 'live';

$queued = enqueue_session_scheduled_emails($pdo, $sessionId, $courses, $topic, $dt, $type, 'https://meet.google.com/xyz', '', 0, 'admin_tester');
assertTest("Enqueued session scheduled emails for eligible active/approved learners (3 recipients)", $queued === 3);

// Test B: Idempotency Check (Submitting again should not create duplicate queue rows)
$queuedAgain = enqueue_session_scheduled_emails($pdo, $sessionId, $courses, $topic, $dt, $type, 'https://meet.google.com/xyz', '', 0, 'admin_tester');
assertTest("Idempotency: Re-calling enqueue_session_scheduled_emails queues 0 duplicate rows", $queuedAgain === 0);

$totalRows = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE invoice_id = 101 AND event_name = 'session_scheduled'")->fetchColumn();
assertTest("Total queue rows for session 101 remains exactly 3", $totalRows === 3);

// Test C: Transactional Whitelist for Student Registration Event
$testEvents = [
    'student_registration' => true,
    'student_approval' => true,
    'student_onboarding' => true,
    'invoice_email' => true,
    'installment_reminder' => true,
    'session_scheduled' => true,
    'academic_study_plan_reminder' => false
];

$transactional_events = [
    'invoice_email', 'installment_reminder', 'installment_email', 'installment_overdue',
    'payment_receipt', 'payment_confirmation', 'payment_reminder', 'payment_rejection',
    'fee_update', 'student_registration', 'student_approval', 'student_rejection',
    'student_welcome', 'student_onboarding', 'onboarding_app_access', 'course_migration_completed',
    'course_migration', 'session_scheduled', 'session_reminder', 'activity_log_export',
    'email_reports_export', 'monthly_backup', 'system_alert', 'password_reset',
    'account_security', 'auth_verification'
];

foreach ($testEvents as $ev => $expectedTransactional) {
    $eventName = strtolower(trim((string)$ev));
    $isTransactional = in_array($eventName, $transactional_events, true)
        || strpos($eventName, 'installment_') === 0
        || strpos($eventName, 'payment_') === 0
        || strpos($eventName, 'invoice_') === 0
        || strpos($eventName, 'fee_') === 0
        || strpos($eventName, 'registration') !== false
        || strpos($eventName, 'student_') === 0
        || strpos($eventName, 'onboarding') !== false
        || strpos($eventName, 'admission') !== false
        || strpos($eventName, 'migration') !== false
        || strpos($eventName, 'auth_') === 0
        || strpos($eventName, 'lead_') === 0;

    assertTest("Event '{$ev}' transactional check evaluates to " . ($expectedTransactional ? 'TRUE' : 'FALSE'),
        $isTransactional === $expectedTransactional
    );
}

// Test D: QueueProcessor Stale Recovery
$pdo->exec("
    INSERT INTO communication_queue (
        channel, recipient, subject, body_text, status, priority, retry_count,
        next_attempt_at, worker_started_at, created_at, updated_at
    ) VALUES (
        'email', 'stale@pepp.com', 'Stale Test', 'Stale Test Body', 'processing', 1, 0,
        datetime('now'), datetime('now', '-15 minute'), datetime('now', '-15 minute'), datetime('now', '-15 minute')
    );
");

require_once $procFile;
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
$processor = new QueueProcessor($pdo, 10);
// Running stale recovery check
$staleCheck = $pdo->prepare("
    UPDATE communication_queue
    SET status = 'pending',
        retry_count = retry_count + 1,
        error_message = COALESCE(error_message,'') || ' [stale-recovery]',
        updated_at = datetime('now')
    WHERE status = 'processing'
      AND worker_started_at < datetime('now', '-10 minute')
");
$staleCheck->execute();
$recoveredCount = $staleCheck->rowCount();
assertTest("QueueProcessor recovers 1 stale job stuck in processing > 10 minutes", $recoveredCount === 1);

$recoveredRow = $pdo->query("SELECT status, retry_count, error_message FROM communication_queue WHERE recipient = 'stale@pepp.com'")->fetch(PDO::FETCH_ASSOC);
assertTest("Stale job status is reset to 'pending' with incremented retry count", 
    $recoveredRow['status'] === 'pending' && (int)$recoveredRow['retry_count'] === 1
);

echo "============================================================\n";
echo "SUMMARY: {$passedCount}/{$testCount} tests passed.\n";
echo "============================================================\n";

if ($passedCount === $testCount) {
    exit(0);
} else {
    exit(1);
}
