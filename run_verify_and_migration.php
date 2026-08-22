<?php
require_once 'config/database.php';
header('Content-Type: application/json');

try {
    $report = [];

    // 1. Inspect actual production ENUM definition
    $stmt = $pdo->query("SHOW COLUMNS FROM `communication_queue` LIKE 'status'");
    $colBefore = $stmt->fetch(PDO::FETCH_ASSOC);
    $report['enum_definition_before'] = $colBefore;

    // 2. Take a production database backup of the table before migration
    $backupTableName = 'communication_queue_backup_28';
    // Drop table if exists first for testing liveness
    $pdo->exec("DROP TABLE IF EXISTS `{$backupTableName}`");
    $pdo->exec("CREATE TABLE `{$backupTableName}` AS SELECT * FROM `communication_queue`");
    $report['backup_table_created'] = $backupTableName;

    // 3. Apply the minimal ENUM addition
    $pdo->exec("
        ALTER TABLE `communication_queue` 
        MODIFY COLUMN `status` ENUM('pending', 'processing', 'sent', 'delivered', 'read', 'failed', 'cancelled', 'scheduled', 'paused') NOT NULL DEFAULT 'pending'
    ");
    $report['alter_table_executed'] = true;

    // 4. Verify the production database ENUM definition after migration
    $stmt2 = $pdo->query("SHOW COLUMNS FROM `communication_queue` LIKE 'status'");
    $colAfter = $stmt2->fetch(PDO::FETCH_ASSOC);
    $report['enum_definition_after'] = $colAfter;

    // 5. Verify status of permanently failed recipient records (289, 297, 301)
    $stmt3 = $pdo->query("SELECT id, recipient, status, retry_count, error_message FROM `communication_queue` WHERE id IN (289, 297, 301)");
    $report['target_records'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'report' => $report
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
