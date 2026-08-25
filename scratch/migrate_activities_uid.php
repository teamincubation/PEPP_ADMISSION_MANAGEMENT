<?php
/**
 * PEPP Learning — Study Plan Activity Legacy Data Migration & Backfill
 * Runs on CLI: php scratch/migrate_activities_uid.php
 */
require_once __DIR__ . '/../config/database.php';

if (php_sapi_name() !== 'cli') {
    die("This script can only be run via CLI.\n");
}

function generate_activity_uid() {
    return 'SPA-' . bin2hex(random_bytes(10));
}

try {
    echo "========================================================================\n";
    echo "PEPP STUDY PLAN ACTIVITY IDENTITY MIGRATION & BACKFILL SYSTEM\n";
    echo "========================================================================\n\n";

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database Driver Detected: " . strtoupper($driver) . "\n\n";

    // 1. Audit Phase
    echo "--- AUDIT PHASE ---\n";
    
    // Check activities
    $total_activities = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities")->fetchColumn();
    $no_uid_activities = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_activities WHERE activity_uid IS NULL OR activity_uid = ''")->fetchColumn();
    echo "Total Study Plan Activities: $total_activities\n";
    echo "Activities without UID: $no_uid_activities\n";

    // Check analytics
    $total_analytics = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_analytics")->fetchColumn();
    $no_uid_analytics = (int)$pdo->query("SELECT COUNT(*) FROM study_plan_analytics WHERE activity_uid IS NULL OR activity_uid = ''")->fetchColumn();
    echo "Total Analytics Records: $total_analytics\n";
    echo "Analytics without UID: $no_uid_analytics\n";

    // Find orphans
    $orphan_stmt = $pdo->query("
        SELECT DISTINCT an.activity_id 
        FROM study_plan_analytics an 
        LEFT JOIN study_plan_activities act ON an.activity_id = act.id 
        WHERE act.id IS NULL AND an.activity_id IS NOT NULL AND an.activity_id != 0
    ");
    $orphan_ids = $orphan_stmt->fetchAll(PDO::FETCH_COLUMN);
    $total_orphans = count($orphan_ids);
    echo "Orphan Completion Records (referencing non-existent activity IDs): $total_orphans\n";
    if ($total_orphans > 0) {
        echo "WARNING: Orphan activity IDs found: " . implode(', ', $orphan_ids) . "\n";
    }
    echo "\n";

    // 2. Migration Execution Phase
    echo "--- MIGRATION & BACKFILL EXECUTION PHASE ---\n";
    $pdo->beginTransaction();
    echo "Transaction started.\n";

    // Backfill activities
    $stmt_no_uid = $pdo->query("SELECT id FROM study_plan_activities WHERE activity_uid IS NULL OR activity_uid = ''");
    $ids_to_update = $stmt_no_uid->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt_upd_act = $pdo->prepare("UPDATE study_plan_activities SET activity_uid = ? WHERE id = ?");
    $uid_map = []; // id => uid mapping for analytics update

    $backfilled_uids_count = 0;
    foreach ($ids_to_update as $id) {
        // Ensure generated UID is unique in this run
        do {
            $uid = generate_activity_uid();
        } while (in_array($uid, $uid_map));
        
        $stmt_upd_act->execute([$uid, $id]);
        $uid_map[$id] = $uid;
        $backfilled_uids_count++;
    }
    echo "Successfully generated and saved $backfilled_uids_count UIDs for activities.\n";

    // Fetch all active mappings (including already existing ones)
    $stmt_all_uids = $pdo->query("SELECT id, activity_uid FROM study_plan_activities WHERE activity_uid IS NOT NULL AND activity_uid != ''");
    while ($row = $stmt_all_uids->fetch(PDO::FETCH_ASSOC)) {
        $uid_map[(int)$row['id']] = $row['activity_uid'];
    }

    // Backfill analytics
    $stmt_anal_no_uid = $pdo->query("SELECT id, activity_id FROM study_plan_analytics WHERE activity_uid IS NULL OR activity_uid = ''");
    $anal_rows = $stmt_anal_no_uid->fetchAll(PDO::FETCH_ASSOC);

    $backfilled_anal_count = 0;
    $unresolved_orphans_count = 0;

    $stmt_upd_anal = $pdo->prepare("
        UPDATE study_plan_analytics 
        SET activity_uid = ?, 
            activity_title_snapshot = ?,
            activity_type_snapshot = ?,
            activity_date_snapshot = ?,
            day_number_snapshot = ?,
            chapter_snapshot = ?,
            subject_snapshot = ?,
            topic_snapshot = ?
        WHERE id = ?
    ");

    $stmt_get_act_meta = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ?");

    foreach ($anal_rows as $row) {
        $anal_id = (int)$row['id'];
        $act_id = (int)$row['activity_id'];

        if (isset($uid_map[$act_id])) {
            $uid = $uid_map[$act_id];
            
            // Get activity metadata to freeze in snapshot fields
            $stmt_get_act_meta->execute([$act_id]);
            $act_meta = $stmt_get_act_meta->fetch(PDO::FETCH_ASSOC);

            if ($act_meta) {
                $stmt_upd_anal->execute([
                    $uid,
                    $act_meta['activity_title'],
                    $act_meta['activity_type'],
                    $act_meta['activity_date'],
                    (int)$act_meta['day_number'],
                    $act_meta['chapter'],
                    $act_meta['subject'],
                    $act_meta['topic'],
                    $anal_id
                ]);
            } else {
                // If metadata missing but UID mapped (edge case)
                $stmt_upd_anal->execute([$uid, null, null, null, null, null, null, null, $anal_id]);
            }
            $backfilled_anal_count++;
        } else {
            $unresolved_orphans_count++;
        }
    }
    echo "Successfully updated UIDs and snapshots for $backfilled_anal_count analytics completion records.\n";
    echo "Preserved $unresolved_orphans_count orphan analytics records (no UID mapping possible, preserved for history).\n";

    // 3. Uniqueness and Constraints Phase
    echo "\n--- UNIQUE CONSTRAINT PHASE ---\n";
    
    // Check for duplicate UIDs in database just to be absolutely sure
    $dup_count = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT activity_uid FROM study_plan_activities 
            WHERE activity_uid IS NOT NULL AND activity_uid != '' 
            GROUP BY activity_uid HAVING COUNT(*) > 1
        ) AS dups
    ")->fetchColumn();

    if ($dup_count > 0) {
        throw new Exception("CRITICAL ERROR: Duplicate UIDs detected. Aborting transaction!");
    }
    echo "UID uniqueness validated.\n";

    $pdo->commit();
    echo "Transaction successfully committed to the database.\n";

    // Add unique constraint dynamically based on driver
    if ($driver === 'mysql') {
        // Check if constraint already exists
        $has_uq = $pdo->query("
            SELECT COUNT(*) FROM information_schema.statistics 
            WHERE table_schema = DATABASE() AND table_name = 'study_plan_activities' AND index_name = 'uq_activity_uid'
        ")->fetchColumn();

        if (!$has_uq) {
            echo "Adding UNIQUE index constraint to study_plan_activities(activity_uid) on MySQL...\n";
            $pdo->exec("ALTER TABLE study_plan_activities ADD UNIQUE INDEX uq_activity_uid (activity_uid)");
            echo "UNIQUE index constraint successfully applied.\n";
        } else {
            echo "UNIQUE index constraint uq_activity_uid already exists in MySQL.\n";
        }
    } else {
        // SQLite
        echo "Creating UNIQUE index constraint in SQLite...\n";
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_activity_uid ON study_plan_activities (activity_uid)");
        echo "UNIQUE index constraint successfully created in SQLite.\n";
    }

    echo "\n========================================================================\n";
    echo "MIGRATION COMPLETED SUCCESSFULLY!\n";
    echo "========================================================================\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
        echo "TRANSACTION ROLLED BACK due to error: " . $e->getMessage() . "\n";
    } else {
        echo "MIGRATION FAILED: " . $e->getMessage() . "\n";
    }
    exit(1);
}
