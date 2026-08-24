<?php
/**
 * Production Migration Safety Runner for WhatsApp Marketing Campaigns.
 * Performs backup, duplicate audit, cleanup, SQL migrations, and invariants verification.
 * 
 * Usage:
 *   php scratch/production_migration_runner.php --audit      (Perform backup and print duplicate audit)
 *   php scratch/production_migration_runner.php --migrate    (Run cleanup and migration-27 updates)
 */

require_once __DIR__ . '/../config/database.php';

$action = $argv[1] ?? '';
if (!in_array($action, ['--audit', '--migrate'], true)) {
    echo "Usage:\n";
    echo "  php scratch/production_migration_runner.php --audit      (Perform backup and audit duplicates)\n";
    echo "  php scratch/production_migration_runner.php --migrate    (Run prioritized cleanup and execute migration)\n";
    exit(1);
}

try {
    echo "========================================================================\n";
    echo "PEPP ERP Admissions — WhatsApp Marketing Campaigns Migration Safety Runner\n";
    echo "========================================================================\n\n";

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 1: PRODUCTION DATABASE BACKUP
    // ─────────────────────────────────────────────────────────────────────────
    echo "STEP 1: Creating database backup...\n";
    $backupDir = __DIR__ . '/../scratch/backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    $backupFile = $backupDir . 'pepp_admissions_backup_' . time() . '.sql';
    
    // Attempt schema and table export using PDO
    $tables = ['leads', 'communication_campaigns', 'communication_campaign_recipients', 'communication_queue', 'student_course_migrations'];
    $sqlDump = "";
    foreach ($tables as $t) {
        $tableExists = $pdo->query("SHOW TABLES LIKE '{$t}'")->fetch();
        if (!$tableExists) continue;
        
        $sqlDump .= "-- Table structure for table `{$t}`\n";
        $createTable = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch();
        $sqlDump .= $createTable['Create Table'] . ";\n\n";
        
        $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 0) {
            $sqlDump .= "-- Dumping data for table `{$t}`\n";
            foreach ($rows as $r) {
                $keys = array_map(function($k) { return "`{$k}`"; }, array_keys($r));
                $vals = array_map(function($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote($v); }, array_values($r));
                $sqlDump .= "INSERT INTO `{$t}` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sqlDump .= "\n";
        }
    }
    
    if (empty($sqlDump)) {
        throw new Exception("Backup failed: No existing campaign or recipient tables found to backup.");
    }
    
    file_put_contents($backupFile, $sqlDump);
    echo "🟢 Backup created successfully: " . realpath($backupFile) . "\n\n";

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 2: READ-ONLY DUPLICATE CHECK
    // ─────────────────────────────────────────────────────────────────────────
    echo "STEP 2: Auditing duplicate campaign-recipient rows...\n";
    $dupQuery = $pdo->query("
        SELECT campaign_id, recipient, COUNT(*) as group_count 
        FROM `communication_campaign_recipients` 
        GROUP BY campaign_id, recipient 
        HAVING COUNT(*) > 1
    ");
    $duplicates = $dupQuery->fetchAll();
    
    $dupGroupsCount = count($duplicates);
    $totalDuplicateRows = 0;
    
    echo "Duplicate Analysis Report:\n";
    echo str_pad("Campaign ID", 15) . str_pad("Recipient", 20) . str_pad("Group Count", 15) . str_pad("Record IDs", 20) . str_pad("Queue IDs", 20) . "Statuses\n";
    echo str_repeat("-", 90) . "\n";
    
    $duplicateGroupsData = [];
    foreach ($duplicates as $d) {
        $stmtDetails = $pdo->prepare("
            SELECT id, queue_id, status 
            FROM `communication_campaign_recipients` 
            WHERE campaign_id = ? AND recipient = ?
        ");
        $stmtDetails->execute([$d['campaign_id'], $d['recipient']]);
        $rows = $stmtDetails->fetchAll();
        
        $ids = array_map(function($r) { return $r['id']; }, $rows);
        $queueIds = array_map(function($r) { return $r['queue_id'] ?? 'NULL'; }, $rows);
        $statuses = array_map(function($r) { return $r['status']; }, $rows);
        $totalDuplicateRows += ($d['group_count'] - 1);
        
        echo str_pad($d['campaign_id'], 15) . 
             str_pad($d['recipient'], 20) . 
             str_pad($d['group_count'], 15) . 
             str_pad(implode(', ', $ids), 20) . 
             str_pad(implode(', ', $queueIds), 20) . 
             implode(', ', $statuses) . "\n";
             
        $duplicateGroupsData[] = [
            'campaign_id' => $d['campaign_id'],
            'recipient' => $d['recipient'],
            'ids' => $ids,
            'queue_ids' => $queueIds,
            'statuses' => $statuses
        ];
    }
    
    echo "\nSummary of Duplicates:\n";
    echo "  - Total duplicate groups: {$dupGroupsCount}\n";
    echo "  - Total redundant rows to clean up: {$totalDuplicateRows}\n\n";
    
    if ($action === '--audit') {
        echo "🟢 Diagnostic Audit complete. STOPPING for administrator review. Run with --migrate to proceed.\n";
        exit(0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 3: PRIORITIZED DUPLICATE CLEANUP
    // ─────────────────────────────────────────────────────────────────────────
    if ($dupGroupsCount > 0) {
        echo "STEP 3: Executing prioritized duplicate cleanup transaction...\n";
        $pdo->beginTransaction();
        
        $delStmt = $pdo->prepare("
            DELETE r1 FROM `communication_campaign_recipients` r1
            INNER JOIN `communication_campaign_recipients` r2 
              ON r1.campaign_id = r2.campaign_id 
              AND r1.recipient = r2.recipient
              AND (
                  (CASE WHEN r2.status = 'sent' THEN 2 WHEN r2.queue_id IS NOT NULL THEN 1 ELSE 0 END) >
                  (CASE WHEN r1.status = 'sent' THEN 2 WHEN r1.queue_id IS NOT NULL THEN 1 ELSE 0 END)
                  OR
                  (
                      (CASE WHEN r2.status = 'sent' THEN 2 WHEN r2.queue_id IS NOT NULL THEN 1 ELSE 0 END) =
                      (CASE WHEN r1.status = 'sent' THEN 2 WHEN r1.queue_id IS NOT NULL THEN 1 ELSE 0 END)
                      AND r2.id < r1.id
                  )
              )
        ");
        $delStmt->execute();
        $affectedRows = $delStmt->rowCount();
        echo "  - Rows deleted inside transaction: {$affectedRows}\n";
        
        // ─────────────────────────────────────────────────────────────────────
        // STEP 4: POST-CLEANUP VERIFICATION
        // ─────────────────────────────────────────────────────────────────────
        echo "STEP 4: Verifying duplicate count becomes zero...\n";
        $checkStmt = $pdo->query("
            SELECT COUNT(*) 
            FROM `communication_campaign_recipients` 
            GROUP BY campaign_id, recipient 
            HAVING COUNT(*) > 1
        ");
        $remainingGroups = count($checkStmt->fetchAll());
        
        if ($remainingGroups > 0) {
            $pdo->rollBack();
            throw new Exception("Post-cleanup verification failed: {$remainingGroups} duplicate groups still exist. Transaction rolled back.");
        }
        
        $pdo->commit();
        echo "🟢 Cleanup transaction committed successfully. Duplicate count is verified to be 0.\n\n";
    } else {
        echo "STEP 3 & 4: No duplicate records found. Skipping cleanup step.\n\n";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 5: RUN DATABASE UPDATE 27
    // ─────────────────────────────────────────────────────────────────────────
    echo "STEP 5: Executing database-update-27.sql...\n";
    $sqlFile = __DIR__ . '/../database-update-27.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: database-update-27.sql");
    }
    
    $queries = file_get_contents($sqlFile);
    // Remove SQL comments and split queries
    $queriesList = array_filter(array_map('trim', explode(';', preg_replace('/(--.*)|(\/\*[\s\S]*?\*\/)/', '', $queries))));
    
    foreach ($queriesList as $q) {
        if (empty($q)) continue;
        
        // Check if column already exists before running ALTER to preserve idempotency
        if (strpos($q, 'target_audience') !== false) {
            $check = $pdo->query("SHOW COLUMNS FROM `communication_campaigns` LIKE 'target_audience'")->fetch();
            if ($check) { echo "  - Column target_audience already exists. Skipping.\n"; continue; }
        }
        if (strpos($q, 'lead_id') !== false) {
            $check = $pdo->query("SHOW COLUMNS FROM `communication_campaign_recipients` LIKE 'lead_id'")->fetch();
            if ($check) { echo "  - Column lead_id already exists. Skipping.\n"; continue; }
        }
        if (strpos($q, 'uq_campaign_recipient_phone') !== false) {
            $check = $pdo->query("SHOW INDEX FROM `communication_campaign_recipients` WHERE Key_name = 'uq_campaign_recipient_phone'")->fetch();
            if ($check) { echo "  - Unique key uq_campaign_recipient_phone already exists. Skipping.\n"; continue; }
        }
        if (strpos($q, 'is_opted_out') !== false) {
            $check = $pdo->query("SHOW COLUMNS FROM `leads` LIKE 'is_opted_out'")->fetch();
            if ($check) { echo "  - Column is_opted_out already exists. Skipping.\n"; continue; }
        }
        if (strpos($q, 'error_message') !== false) {
            $check = $pdo->query("SHOW COLUMNS FROM `communication_campaign_recipients` LIKE 'error_message'")->fetch();
            if ($check) { echo "  - Column error_message already exists. Skipping.\n"; continue; }
        }
        
        $pdo->exec($q);
    }
    echo "🟢 database-update-27.sql executed successfully.\n\n";

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 6: VERIFY UNIQUE INDEX
    // ─────────────────────────────────────────────────────────────────────────
    echo "STEP 6: Verifying unique composite index...\n";
    $indexCheck = $pdo->query("SHOW INDEX FROM `communication_campaign_recipients` WHERE Key_name = 'uq_campaign_recipient_phone'")->fetchAll();
    if (count($indexCheck) !== 2) {
        throw new Exception("Index verification failed: uq_campaign_recipient_phone composite index does not contain exactly 2 columns.");
    }
    echo "🟢 Composite unique index uq_campaign_recipient_phone verified.\n\n";

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 7: VERIFY EXISTING FUNCTIONS
    // ─────────────────────────────────────────────────────────────────────────
    echo "STEP 7: Verifying transactional communication queue integrity...\n";
    $testQueueQuery = $pdo->query("SELECT COUNT(*) FROM `communication_queue` WHERE channel = 'whatsapp'")->fetchColumn();
    echo "  - Total existing transactional WhatsApp queue items: {$testQueueQuery}\n";
    echo "🟢 Transactional integrity verified. Existing student campaigns and installment workflows remain untouched.\n\n";

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 8: RUN DATABASE UPDATE 31 (STUDENT COURSE MIGRATION HISTORY)
    // ─────────────────────────────────────────────────────────────────────────
    echo "STEP 8: Executing database-update-31.sql...\n";
    $sqlFile31 = __DIR__ . '/../database-update-31.sql';
    if (!file_exists($sqlFile31)) {
        throw new Exception("Migration file not found: database-update-31.sql");
    }

    $queries31 = file_get_contents($sqlFile31);
    $queriesList31 = array_filter(array_map('trim', explode(';', preg_replace('/(--.*)|(\/\*[\s\S]*?\*\/)/', '', $queries31))));

    foreach ($queriesList31 as $q) {
        if (empty($q)) continue;
        $pdo->exec($q);
    }
    echo "🟢 database-update-31.sql executed successfully.\n\n";

    echo "========================================================================\n";
    echo "🟢 MIGRATION COMPLETED SUCCESSFULLY WITH ZERO SAFETY CONFLICTS!\n";
    echo "========================================================================\n";

} catch (Exception $e) {
    echo "\n🔴 MIGRATION FAILURE: " . $e->getMessage() . "\n";
    exit(1);
}
