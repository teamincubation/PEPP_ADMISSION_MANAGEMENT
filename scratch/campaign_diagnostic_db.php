<?php
/**
 * Temporary Read-Only Diagnostic Database Script.
 * Extracts details about Campaign "Sample 4", its recipients, and queue items.
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$results = [];

try {
    // 1. Query Campaign details for "Sample 4"
    $stmtCamp = $pdo->prepare("SELECT * FROM communication_campaigns WHERE name = 'Sample 4' LIMIT 1");
    $stmtCamp->execute();
    $results['campaign'] = $stmtCamp->fetch(PDO::FETCH_ASSOC);

    $campId = $results['campaign']['id'] ?? null;

    if ($campId) {
        // 2. Query Campaign Recipients for "Sample 4"
        $stmtRec = $pdo->prepare("SELECT * FROM communication_campaign_recipients WHERE campaign_id = ?");
        $stmtRec->execute([$campId]);
        $results['recipients'] = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. Query specific Queue items 300, 302 and any other related to Campaign "Sample 4"
    $stmtQueue = $pdo->query("SELECT * FROM communication_queue WHERE id IN (300, 302) OR subject = 'Sample 4'");
    $results['queue_items'] = $stmtQueue->fetchAll(PDO::FETCH_ASSOC);

    // 4. Query recent logs/activity to trace if cron is running
    // Let's query recent queue execution status in communication_queue
    $stmtQueueRecent = $pdo->query("SELECT * FROM communication_queue ORDER BY id DESC LIMIT 10");
    $results['recent_queue'] = $stmtQueueRecent->fetchAll(PDO::FETCH_ASSOC);

    // Query last processed items (sent/failed)
    $stmtLastProcessed = $pdo->query("SELECT id, status, error_message, updated_at FROM communication_queue WHERE status IN ('sent', 'failed') ORDER BY id DESC LIMIT 5");
    $results['last_processed'] = $stmtLastProcessed->fetchAll(PDO::FETCH_ASSOC);

    // Let's query admin_settings for cron worker key to verify it is configured
    $stmtSettings = $pdo->query("SELECT * FROM admin_settings WHERE setting_name IN ('whatsapp_cron_worker_key', 'whatsapp_app_secret')");
    $results['admin_settings'] = $stmtSettings->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $results
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
