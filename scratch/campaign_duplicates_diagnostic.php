<?php
/**
 * Diagnostic script to audit existing duplicate campaign-recipient rows in PEPP ERP database.
 */
require_once __DIR__ . '/../config/database.php';

try {
    // Check if table exists first
    $tableExists = $pdo->query("SHOW TABLES LIKE 'communication_campaign_recipients'")->fetch();
    if (!$tableExists) {
        echo json_encode(['success' => false, 'message' => 'Table communication_campaign_recipients does not exist yet.']);
        exit;
    }

    // Find duplicate groups of campaign_id + recipient
    $dupQuery = $pdo->query("
        SELECT campaign_id, recipient, COUNT(*) as group_count 
        FROM communication_campaign_recipients 
        GROUP BY campaign_id, recipient 
        HAVING COUNT(*) > 1
    ");
    $duplicates = $dupQuery->fetchAll();

    $report = [];
    foreach ($duplicates as $d) {
        // Fetch details of all rows within this duplicate group
        $stmtDetails = $pdo->prepare("
            SELECT id, queue_id, status 
            FROM communication_campaign_recipients 
            WHERE campaign_id = ? AND recipient = ?
        ");
        $stmtDetails->execute([$d['campaign_id'], $d['recipient']]);
        $rows = $stmtDetails->fetchAll();

        $ids = array_map(function($r) { return $r['id']; }, $rows);
        $queueIds = array_filter(array_map(function($r) { return $r['queue_id']; }, $rows));
        $statuses = array_map(function($r) { return $r['status']; }, $rows);

        $report[] = [
            'campaign_id' => $d['campaign_id'],
            'recipient' => $d['recipient'],
            'count' => $d['group_count'],
            'ids' => implode(', ', $ids),
            'has_queue_id' => count($queueIds) > 0 ? 'Yes (' . implode(', ', $queueIds) . ')' : 'No',
            'statuses' => implode(', ', $statuses)
        ];
    }

    echo json_encode([
        'success' => true,
        'duplicate_groups_count' => count($report),
        'groups' => $report
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
