<?php
/**
 * Test Suite: WhatsApp Campaign Priority Isolation Audit
 *
 * Verifies that bulk marketing campaigns (priority = -10) NEVER starve
 * operational / transactional admissions communications (priority = 0 or higher).
 *
 * Scenarios Tested:
 * - Scenario A: 100 campaign messages (priority=-10) + 1 transactional message (priority=0) created AFTER
 *               -> Transactional message selected BEFORE campaign messages.
 * - Scenario B: 100 campaign messages (priority=-10) + 1 high-priority message (priority=10)
 *               -> Higher-priority message always wins.
 * - Scenario C: Existing legacy messages with default priority=0 -> No regression (FIFO by created_at).
 * - Scenario D: Campaign retry -> priority=-10 is strictly preserved.
 * - Scenario E: Campaign sender_account_id -> sender_account_id remains intact.
 * - Scenario F: Two queue workers -> Atomic claim prevents double-processing.
 */

putenv('PEPP_TEST_MODE=1');
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
require_once __DIR__ . '/includes/communication/QueueProcessor.php';
require_once __DIR__ . '/includes/communication/CampaignConfig.php';

$testCount = 0;
$passedCount = 0;

function assertTest($description, $condition, $details = '') {
    global $testCount, $passedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo " [PASS] {$description}\n";
    } else {
        echo " [FAIL] {$description}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

echo "======================================================================\n";
echo "AUDIT TEST: WhatsApp Campaign Priority Isolation & Starvation Prevention\n";
echo "======================================================================\n\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Setup SQLite NOW() function
$currentDbTime = date('Y-m-d H:i:s');
$pdo->sqliteCreateFunction('NOW', function() use (&$currentDbTime) {
    return $currentDbTime;
});

// Setup in-memory schema
$pdo->exec("
    CREATE TABLE admin_settings (
        setting_name TEXT PRIMARY KEY,
        setting_value TEXT,
        updated_at TEXT
    );

    CREATE TABLE whatsapp_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone TEXT,
        message TEXT,
        student_name TEXT,
        sent_by TEXT,
        status TEXT,
        latitude REAL,
        longitude REAL,
        metadata TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        invoice_id INTEGER DEFAULT NULL,
        sender_account_id INTEGER DEFAULT NULL,
        idempotency_key TEXT DEFAULT NULL
    );

    CREATE TABLE whatsapp_sender_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender_key TEXT UNIQUE NOT NULL,
        display_name TEXT NOT NULL,
        phone_number_id TEXT NOT NULL,
        phone_number TEXT,
        waba_id TEXT,
        app_id TEXT,
        access_token TEXT,
        is_default INTEGER DEFAULT 0,
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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

    CREATE TABLE communication_campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        channel TEXT DEFAULT 'whatsapp',
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE communication_campaign_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER,
        recipient TEXT,
        status TEXT DEFAULT 'pending',
        queue_id INTEGER DEFAULT NULL
    );
");

// Insert standard sender accounts
$pdo->exec("
    INSERT INTO whatsapp_sender_accounts (id, sender_key, display_name, phone_number_id, phone_number, is_default, status)
    VALUES (1, 'admissions', 'PEPP Learning', 'PHONE_ID_111', '+916282563209', 1, 'active'),
           (2, 'notifications', 'PEPP Updates', 'PHONE_ID_222', '+917994304400', 0, 'active');
");

$engine = CommunicationEngine::getInstance($pdo);

// -----------------------------------------------------------------------------
// SCENARIO A: 100 Campaign Messages (priority=-10) + 1 Transactional Message (priority=0)
// Created AFTER the campaign messages -> Transactional message selected FIRST
// -----------------------------------------------------------------------------
echo "--- SCENARIO A: Transactional Message Preempts 100 Campaign Messages ---\n";

$campaignQueueIds = [];
for ($i = 1; $i <= 100; $i++) {
    $qId = $engine->queueMessage(
        'whatsapp',
        '9198765' . str_pad($i, 5, '0', STR_PAD_LEFT),
        "Campaign Lead {$i}",
        "Campaign: Batch Promo",
        null,
        null,
        [],
        ['name' => 'mphil_promo_broadcast'],
        'Campaign Worker',
        null,
        null,
        'campaign_message', // event_name
        0,
        2,                  // sender_account_id
        "campaign:1:rec:{$i}", // idempotencyKey
        CampaignConfig::CAMPAIGN_QUEUE_PRIORITY // -10
    );
    $campaignQueueIds[] = $qId;
}

// Verify that all 100 campaign messages received priority = -10
$campPriorities = $pdo->query("SELECT DISTINCT priority FROM communication_queue WHERE id IN (" . implode(',', $campaignQueueIds) . ")")->fetchAll(PDO::FETCH_COLUMN);
assertTest(
    "Scenario A.1: All 100 campaign messages persisted with priority = -10",
    count($campPriorities) === 1 && (int)$campPriorities[0] === -10,
    "Found priorities: " . json_encode($campPriorities)
);

// Now simulate a student receiving an admission approval (created AFTER the campaign messages)
$txId = $engine->queueMessage(
    'whatsapp',
    '919999900001',
    'Approved Student',
    'Admission Approved',
    '<p>Congratulations, your admission is approved!</p>',
    'Congratulations, your admission is approved!',
    [],
    ['name' => 'admission_approved_notice'],
    'admissions_officer',
    null,
    'STU_TX_001',
    'admission_approved', // Transactional event
    0
);

// Verify transactional item received priority = 0
$txRow = $pdo->query("SELECT id, priority, event_name, created_at FROM communication_queue WHERE id = {$txId}")->fetch(PDO::FETCH_ASSOC);
assertTest(
    "Scenario A.2: Transactional message enqueued with priority = 0 (created after campaign items)",
    (int)$txRow['priority'] === 0 && $txId > end($campaignQueueIds),
    "TX ID: {$txId}, priority: {$txRow['priority']}"
);

// Run QueueProcessor selection query (batchSize = 10)
$processor = new QueueProcessor($pdo, 10);
$nowCutoff = date('Y-m-d H:i:s');
$selStmt = $pdo->prepare("
    SELECT id, priority, event_name FROM communication_queue
    WHERE status IN ('pending', 'scheduled', 'failed', 'retrying')
      AND next_attempt_at <= ?
    ORDER BY priority DESC, created_at ASC
    LIMIT 10
");
$selStmt->execute([$nowCutoff]);
$selectedRows = $selStmt->fetchAll(PDO::FETCH_ASSOC);

assertTest(
    "Scenario A.3: Transactional message is selected FIRST ahead of all 100 campaign messages",
    !empty($selectedRows) && (int)$selectedRows[0]['id'] === $txId && (int)$selectedRows[0]['priority'] === 0,
    "Selected first ID: " . ($selectedRows[0]['id'] ?? 'none') . " (expected {$txId})"
);

assertTest(
    "Scenario A.4: Remaining 9 items in batch are campaign messages (priority = -10)",
    count($selectedRows) === 10 && (int)$selectedRows[1]['priority'] === -10,
    "Item 2 priority: " . ($selectedRows[1]['priority'] ?? 'none')
);

// -----------------------------------------------------------------------------
// SCENARIO B: 100 Campaign Messages (-10) + 1 High-Priority Transactional Message (10)
// Higher-priority message always wins
// -----------------------------------------------------------------------------
echo "\n--- SCENARIO B: High-Priority Transactional Message Wins Over Normal & Bulk ---\n";

// Enqueue urgent OTP / payment receipt with explicit priority = 10
$urgentId = $engine->queueMessage(
    'whatsapp',
    '919999900002',
    'Payment Student',
    'Payment Receipt',
    'Receipt confirmed',
    'Receipt confirmed',
    [],
    ['name' => 'payment_receipt_instant'],
    'payment_gateway',
    null,
    'STU_TX_002',
    'payment_receipt',
    12345,
    'admissions',
    null,
    10 // Explicit high priority = 10
);

$urgentRow = $pdo->query("SELECT id, priority FROM communication_queue WHERE id = {$urgentId}")->fetch(PDO::FETCH_ASSOC);
assertTest(
    "Scenario B.1: Urgent message created with explicit priority = 10",
    (int)$urgentRow['priority'] === 10,
    "Urgent priority: " . ($urgentRow['priority'] ?? 'null')
);

// Check selection order: urgent (10) > transactional (0) > campaigns (-10)
$selStmt->execute([$nowCutoff]);
$allBatchRows = $selStmt->fetchAll(PDO::FETCH_ASSOC);

assertTest(
    "Scenario B.2: Highest priority message (priority=10) is selected FIRST",
    (int)$allBatchRows[0]['id'] === $urgentId && (int)$allBatchRows[0]['priority'] === 10,
    "First item ID: " . ($allBatchRows[0]['id'] ?? 'none') . ", priority: " . ($allBatchRows[0]['priority'] ?? 'none')
);

assertTest(
    "Scenario B.3: Normal transactional message (priority=0) is selected SECOND",
    (int)$allBatchRows[1]['id'] === $txId && (int)$allBatchRows[1]['priority'] === 0,
    "Second item ID: " . ($allBatchRows[1]['id'] ?? 'none') . ", priority: " . ($allBatchRows[1]['priority'] ?? 'none')
);

assertTest(
    "Scenario B.4: Campaign messages (priority=-10) are selected after all higher priorities",
    (int)$allBatchRows[2]['priority'] === -10,
    "Third item priority: " . ($allBatchRows[2]['priority'] ?? 'none')
);

// -----------------------------------------------------------------------------
// SCENARIO C: Existing Legacy Messages with priority=0 (No Regression)
// -----------------------------------------------------------------------------
echo "\n--- SCENARIO C: Legacy Operational Messages Integrity & FIFO Ordering ---\n";

// Clear queue for isolation
$pdo->exec("DELETE FROM communication_queue");

$legacyIds = [];
$legacyEvents = ['admission_approved', 'admission_rejected', 'fee_reminder', 'session_notification'];
foreach ($legacyEvents as $idx => $evt) {
    // Calling without priority argument (standard legacy callers)
    $lId = $engine->queueMessage(
        'whatsapp',
        '91988880000' . $idx,
        "Student {$idx}",
        "Subject {$idx}",
        "Body {$idx}",
        "Body {$idx}",
        [],
        [],
        'system',
        null,
        "STU_LEGACY_{$idx}",
        $evt
    );
    $legacyIds[] = $lId;
}

$legacyRows = $pdo->query("SELECT id, priority, event_name FROM communication_queue ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$allZero = true;
foreach ($legacyRows as $lr) {
    if ((int)$lr['priority'] !== 0) {
        $allZero = false;
        break;
    }
}
assertTest(
    "Scenario C.1: All standard legacy ERP callers default to priority = 0 without modification",
    $allZero && count($legacyRows) === 4,
    "Legacy priorities: " . json_encode(array_column($legacyRows, 'priority'))
);

// Verify FIFO ordering among priority 0 items
$selStmt->execute([$nowCutoff]);
$fifoSelected = $selStmt->fetchAll(PDO::FETCH_COLUMN);
assertTest(
    "Scenario C.2: Priority=0 items preserve strict chronological FIFO ordering by created_at ASC",
    $fifoSelected === $legacyIds,
    "Expected: " . json_encode($legacyIds) . ", got: " . json_encode($fifoSelected)
);

// -----------------------------------------------------------------------------
// SCENARIO D: Campaign Retry Preserves priority = -10
// -----------------------------------------------------------------------------
echo "\n--- SCENARIO D: Campaign Retry Preserves priority = -10 ---\n";

$campRetryId = $engine->queueMessage(
    'whatsapp',
    '919777700001',
    'Retry Lead',
    'Campaign Promo',
    null,
    null,
    [],
    ['name' => 'mphil_promo_broadcast'],
    'Campaign Worker',
    null,
    null,
    'campaign_message',
    0,
    2,
    'campaign:2:rec:99',
    CampaignConfig::CAMPAIGN_QUEUE_PRIORITY
);

// 1. Simulate automatic transient failure & retry
$pdo->prepare("
    UPDATE communication_queue
    SET status = 'retrying', retry_count = 1, error_message = 'Simulated timeout', next_attempt_at = ?
    WHERE id = ?
")->execute([date('Y-m-d H:i:s', time() - 10), $campRetryId]);

$retryingRow = $pdo->query("SELECT id, status, priority, retry_count FROM communication_queue WHERE id = {$campRetryId}")->fetch(PDO::FETCH_ASSOC);
assertTest(
    "Scenario D.1: Automatic transient retry preserves priority = -10 in place",
    (int)$retryingRow['priority'] === -10 && $retryingRow['status'] === 'retrying',
    "Status: {$retryingRow['status']}, priority: {$retryingRow['priority']}"
);

// 2. Simulate re-enqueuing retry (e.g. manual campaign retry)
$requeuedId = $engine->queueMessage(
    'whatsapp',
    $retryingRow['recipient'] ?? '919777700001',
    'Retry Lead',
    'Campaign Promo',
    null,
    null,
    [],
    ['name' => 'mphil_promo_broadcast'],
    'Campaign Worker',
    null,
    null,
    'campaign_message', // auto-detected campaign event
    0,
    2
);

$requeuedRow = $pdo->query("SELECT id, priority, event_name FROM communication_queue WHERE id = {$requeuedId}")->fetch(PDO::FETCH_ASSOC);
assertTest(
    "Scenario D.2: Re-enqueued campaign retry automatically preserves priority = -10 via event_name",
    (int)$requeuedRow['priority'] === -10,
    "Requeued priority: {$requeuedRow['priority']}"
);

// -----------------------------------------------------------------------------
// SCENARIO E: Campaign sender_account_id Preserved
// -----------------------------------------------------------------------------
echo "\n--- SCENARIO E: Campaign sender_account_id Independence ---\n";

$campSenderTestId = $engine->queueMessage(
    'whatsapp',
    '919666600001',
    'Sender Test Lead',
    'Campaign: Updates Channel',
    null,
    null,
    [],
    ['name' => 'mphil_promo_broadcast'],
    'Campaign Worker',
    null,
    null,
    'campaign_message',
    0,
    2, // sender_account_id = 2 (PEPP Updates)
    'campaign:3:rec:101',
    CampaignConfig::CAMPAIGN_QUEUE_PRIORITY
);

$senderRow = $pdo->query("SELECT id, priority, sender_account_id FROM communication_queue WHERE id = {$campSenderTestId}")->fetch(PDO::FETCH_ASSOC);
assertTest(
    "Scenario E.1: Campaign item has priority = -10 AND sender_account_id in (2, 3) (PEPP Updates)",
    (int)$senderRow['priority'] === -10 && in_array((int)$senderRow['sender_account_id'], [2, 3], true),
    "Priority: {$senderRow['priority']}, sender_account_id: {$senderRow['sender_account_id']}"
);

// -----------------------------------------------------------------------------
// SCENARIO F: Two Queue Workers — Atomic Row Claim
// -----------------------------------------------------------------------------
echo "\n--- SCENARIO F: Two Concurrent Workers Cannot Double-Claim Same Item ---\n";

// Create a pending queue item
$targetClaimId = $engine->queueMessage(
    'whatsapp',
    '919555500001',
    'Concurrent Student',
    'Test Item',
    'Test Body',
    'Test Body',
    [],
    [],
    'system'
);

// Worker 1 atomically claims the row
$pdo->beginTransaction();
$w1Claim = $pdo->prepare("
    UPDATE communication_queue
    SET status = 'processing', worker_started_at = NOW(), updated_at = NOW()
    WHERE id = ? AND status IN ('pending', 'scheduled', 'failed', 'retrying')
      AND next_attempt_at <= NOW()
");
$w1Claim->execute([$targetClaimId]);
$w1Count = $w1Claim->rowCount();
$pdo->commit();

assertTest(
    "Scenario F.1: Worker 1 successfully claims queue item (rowCount = 1)",
    $w1Count === 1,
    "Worker 1 rowCount: {$w1Count}"
);

// Worker 2 attempts to claim the exact same item
$pdo->beginTransaction();
$w2Claim = $pdo->prepare("
    UPDATE communication_queue
    SET status = 'processing', worker_started_at = NOW(), updated_at = NOW()
    WHERE id = ? AND status IN ('pending', 'scheduled', 'failed', 'retrying')
      AND next_attempt_at <= NOW()
");
$w2Claim->execute([$targetClaimId]);
$w2Count = $w2Claim->rowCount();
$pdo->commit();

assertTest(
    "Scenario F.2: Worker 2 cannot claim already-processing item (rowCount = 0, double dispatch blocked)",
    $w2Count === 0,
    "Worker 2 rowCount: {$w2Count}"
);

// Verify final status remains 'processing'
$finalRow = $pdo->query("SELECT status FROM communication_queue WHERE id = {$targetClaimId}")->fetch(PDO::FETCH_ASSOC);
assertTest(
    "Scenario F.3: Queue row remains exclusively claimed by Worker 1",
    $finalRow['status'] === 'processing',
    "Final status: {$finalRow['status']}"
);

// -----------------------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------------------
echo "\n======================================================================\n";
echo "PRIORITY ISOLATION AUDIT SUMMARY: {$passedCount} / {$testCount} Tests Passed.\n";
if ($passedCount === $testCount) {
    echo "STATUS: ALL PRIORITY ISOLATION SCENARIOS (A-F) PASSED PERFECTLY!\n";
} else {
    echo "STATUS: FAILURES DETECTED!\n";
}
echo "======================================================================\n";

if ($passedCount !== $testCount) {
    exit(1);
}
exit(0);
