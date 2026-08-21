<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    // Reset status to pending and queue_id to NULL for campaign 'Sample Test'
    $stmt = $pdo->prepare("
        UPDATE communication_campaign_recipients 
        SET status = 'pending', queue_id = NULL, sent_at = NULL, error_message = NULL
        WHERE campaign_id = (SELECT id FROM communication_campaigns WHERE name = 'Sample Test')
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();

    // Also delete any existing queue records for this campaign
    $pdo->prepare("
        DELETE FROM communication_queue 
        WHERE subject = 'Sample Test'
    ")->execute();

    // Reset campaign status to 'active'
    $pdo->prepare("
        UPDATE communication_campaigns 
        SET status = 'active' 
        WHERE name = 'Sample Test'
    ")->execute();

    echo json_encode([
        'success' => true,
        'message' => "Campaign 'Sample Test' reset successfully.",
        'affected_rows' => $affected
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
