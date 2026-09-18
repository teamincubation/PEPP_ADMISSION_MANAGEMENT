<?php
/**
 * PEPP ERP - CLI/SSH-ONLY Installment Integrity Forensic Audit & Reconciliation Tool
 * 
 * STRICT SECURITY & EXECUTION RULES:
 * 1. CLI/SSH ONLY: Any HTTP/web request is immediately rejected with HTTP 403.
 * 2. ZERO URL SECRETS: No tokens or query parameters are accepted.
 * 3. STRICT READ-ONLY DRY-RUN: Default mode issues only SELECT and SHOW queries.
 * 4. SEPARATE STAGES: Reconciliation and schema constraint application are decoupled.
 * 
 * Usage:
 *   php scripts/audit_installment_integrity.php --dry-run
 *   php scripts/audit_installment_integrity.php --mysql --dry-run
 *   php scripts/audit_installment_integrity.php --mysql --execute-reconciliation
 *   php scripts/audit_installment_integrity.php --mysql --verify
 *   php scripts/audit_installment_integrity.php --mysql --apply-constraint
 */

// 1. Strict CLI-only guard
if (php_sapi_name() !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Access Denied: This script can ONLY be executed via authenticated CLI/SSH.\n";
    exit(1);
}

// 2. Options parsing
$args = $argv ?? [];
if (in_array('--mysql', $args, true)) {
    putenv('PEPP_USE_MYSQL=1');
}

$mode = 'dry-run';
if (in_array('--execute-reconciliation', $args, true)) {
    $mode = 'execute-reconciliation';
} elseif (in_array('--verify', $args, true)) {
    $mode = 'verify';
} elseif (in_array('--apply-constraint', $args, true)) {
    $mode = 'apply-constraint';
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

echo "====================================================================\n";
echo "PEPP ERP — INSTALLMENT INTEGRITY FORENSIC AUDIT & RECONCILIATION\n";
echo "====================================================================\n";
echo "Execution Mode   : " . strtoupper($mode) . "\n";
echo "Database Driver  : " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
echo "Timestamp (IST)  : " . date('Y-m-d H:i:s') . "\n";
echo "====================================================================\n\n";

// Helper for safe query execution with read-only assertion during dry-run
function safe_query(PDO $pdo, string $sql, array $params = [], bool $isDryRun = true) {
    if ($isDryRun) {
        $trimmed = trim($sql);
        if (!preg_match('/^(SELECT|SHOW|EXPLAIN|DESCRIBE)\b/i', $trimmed)) {
            throw new RuntimeException("SAFETY VIOLATION: Mutating SQL blocked during dry-run: " . substr($trimmed, 0, 50));
        }
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// Helper to check if index exists
function get_table_indexes(PDO $pdo, string $table): array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $indexes = [];
    if ($driver === 'mysql') {
        $stmt = $pdo->query("SHOW INDEX FROM `$table`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $keyName = $row['Key_name'];
            if (!isset($indexes[$keyName])) {
                $indexes[$keyName] = [
                    'name' => $keyName,
                    'unique' => (int)$row['Non_unique'] === 0,
                    'columns' => []
                ];
            }
            $indexes[$keyName]['columns'][(int)$row['Seq_in_index']] = $row['Column_name'];
        }
    } else {
        $stmt = $pdo->query("PRAGMA index_list('$table')");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['name'];
            $indexes[$name] = [
                'name' => $name,
                'unique' => (bool)$row['unique'],
                'columns' => []
            ];
            $info = $pdo->query("PRAGMA index_info('$name')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($info as $col) {
                $indexes[$name]['columns'][] = $col['name'];
            }
        }
    }
    return $indexes;
}

// ─── STAGE 1: READ-ONLY AUDIT & DRY-RUN CLASSIFICATION ─────────────────────
if ($mode === 'dry-run') {
    echo "--- 1. SCHEMA & INDEX INSPECTION ---\n";
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $createStmt = safe_query($pdo, "SHOW CREATE TABLE `instalment_details`", [], true);
        $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
        echo "Table Definition:\n" . ($createRow['Create Table'] ?? 'N/A') . "\n\n";
    }

    $indexes = get_table_indexes($pdo, 'instalment_details');
    echo "Existing Indexes on `instalment_details`:\n";
    $hasCompositeUnique = false;
    foreach ($indexes as $idx) {
        $cols = implode(', ', $idx['columns']);
        $uniqStr = $idx['unique'] ? 'UNIQUE' : 'NON-UNIQUE';
        echo "  - [{$uniqStr}] `{$idx['name']}` ({$cols})\n";
        if ($idx['unique'] && in_array('user_id', $idx['columns'], true) && in_array('instalment_number', $idx['columns'], true)) {
            $hasCompositeUnique = true;
        }
    }
    echo "Composite UNIQUE constraint (user_id, instalment_number): " . ($hasCompositeUnique ? "PRESENT" : "MISSING") . "\n\n";

    $stmtTotal = safe_query($pdo, "SELECT COUNT(*) FROM instalment_details", [], true);
    $totalRows = (int)$stmtTotal->fetchColumn();
    echo "Total Installment Rows in Table: {$totalRows}\n\n";

    echo "--- 2. IN-FLIGHT PAYMENT SUBMISSION AUDIT ---\n";
    $stmtInFlight = safe_query($pdo, "
        SELECT id, user_id, instalment_number, status, amount, paid_amount, paid_date, payment_reference, payment_mode, approved_by, approved_at
        FROM instalment_details
        WHERE status = 'pending' AND paid_date IS NOT NULL
        ORDER BY user_id, instalment_number
    ", [], true);
    $inFlightRows = $stmtInFlight->fetchAll(PDO::FETCH_ASSOC);
    echo "In-flight submitted payments awaiting review: " . count($inFlightRows) . "\n";
    foreach ($inFlightRows as $ifr) {
        echo "  - Student {$ifr['user_id']} | Inst #{$ifr['instalment_number']} (Row ID: {$ifr['id']}) | Amount: ₹{$ifr['amount']} | Paid Date: {$ifr['paid_date']} | Ref: {$ifr['payment_reference']}\n";
    }
    echo "\n";

    echo "--- 3. DUPLICATE LOGICAL INSTALLMENT ANALYSIS ---\n";
    $stmtDups = safe_query($pdo, "
        SELECT user_id, instalment_number, COUNT(*) AS cnt
        FROM instalment_details
        GROUP BY user_id, instalment_number
        HAVING COUNT(*) > 1
        ORDER BY cnt DESC, user_id, instalment_number
    ", [], true);
    $dupGroups = $stmtDups->fetchAll(PDO::FETCH_ASSOC);

    $totalDupGroups = count($dupGroups);
    $affectedStudents = [];
    $totalAffectedRows = 0;

    $classifiedGroups = [
        'A_approved_with_pending' => [], // Paid/approved + unevidenced pending duplicates
        'B_inflight_with_pending' => [], // Payment awaiting review + unevidenced pending
        'C_multiple_pending'      => [], // Multiple empty pending duplicates
        'D_manual_review_needed'  => []  // Conflicting payment evidence (multiple approved / conflicting refs)
    ];

    foreach ($dupGroups as $dg) {
        $uId = $dg['user_id'];
        $instNum = (int)$dg['instalment_number'];
        $cnt = (int)$dg['cnt'];
        $affectedStudents[$uId] = true;
        $totalAffectedRows += $cnt;

        $stmtRows = safe_query($pdo, "
            SELECT * FROM instalment_details
            WHERE user_id = ? AND instalment_number = ?
            ORDER BY id ASC
        ", [$uId, $instNum], true);
        $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

        $approvedRows = [];
        $inFlightSubmissions = [];
        $emptyPendingRows = [];

        foreach ($rows as $r) {
            if (in_array($r['status'], ['approved', 'paid'], true)) {
                $approvedRows[] = $r;
            } elseif ($r['status'] === 'pending' && !empty($r['paid_date'])) {
                $inFlightSubmissions[] = $r;
            } elseif ($r['status'] === 'pending' && empty($r['paid_date'])) {
                $emptyPendingRows[] = $r;
            } else {
                $emptyPendingRows[] = $r;
            }
        }

        $groupInfo = [
            'user_id' => $uId,
            'instalment_number' => $instNum,
            'total_count' => $cnt,
            'rows' => $rows
        ];

        if (count($approvedRows) === 1 && count($inFlightSubmissions) === 0) {
            $groupInfo['canonical_row'] = $approvedRows[0];
            $groupInfo['redundant_rows'] = $emptyPendingRows;
            $classifiedGroups['A_approved_with_pending'][] = $groupInfo;
        } elseif (count($inFlightSubmissions) === 1 && count($approvedRows) === 0) {
            $groupInfo['canonical_row'] = $inFlightSubmissions[0];
            $groupInfo['redundant_rows'] = $emptyPendingRows;
            $classifiedGroups['B_inflight_with_pending'][] = $groupInfo;
        } elseif (count($approvedRows) === 0 && count($inFlightSubmissions) === 0) {
            usort($rows, function($a, $b) {
                $aHasRemark = !empty(trim($a['admin_remarks'] ?? ''));
                $bHasRemark = !empty(trim($b['admin_remarks'] ?? ''));
                if ($aHasRemark !== $bHasRemark) {
                    return $bHasRemark <=> $aHasRemark;
                }
                return (int)$a['id'] <=> (int)$b['id'];
            });
            $groupInfo['canonical_row'] = $rows[0];
            $groupInfo['redundant_rows'] = array_slice($rows, 1);
            $classifiedGroups['C_multiple_pending'][] = $groupInfo;
        } else {
            $classifiedGroups['D_manual_review_needed'][] = $groupInfo;
        }
    }

    echo "Total Duplicate Groups Detected : {$totalDupGroups}\n";
    echo "Total Affected Students         : " . count($affectedStudents) . "\n";
    echo "Total Affected Rows             : {$totalAffectedRows}\n\n";

    echo "--- 4. DUPLICATE CLASSIFICATION BREAKDOWN & PROPOSED ACTIONS ---\n";
    echo "Group A (Approved payment + redundant pending duplicate)     : " . count($classifiedGroups['A_approved_with_pending']) . " groups\n";
    echo "Group B (In-flight review payment + redundant pending)      : " . count($classifiedGroups['B_inflight_with_pending']) . " groups\n";
    echo "Group C (Multiple empty pending rows)                       : " . count($classifiedGroups['C_multiple_pending']) . " groups\n";
    echo "Group D (CONFLICTING FINANCIAL EVIDENCE / MANUAL REVIEW)    : " . count($classifiedGroups['D_manual_review_needed']) . " groups\n\n";

    $printGroupDetails = function(string $title, array $groups, string $actionTemplate) {
        if (empty($groups)) return;
        echo "=== $title (" . count($groups) . " groups) ===\n";
        foreach ($groups as $idx => $grp) {
            $num = $idx + 1;
            echo "--------------------------------------------------------------------\n";
            echo "Group #$num: Student [{$grp['user_id']}] — Installment #{$grp['instalment_number']} ({$grp['total_count']} rows)\n";
            foreach ($grp['rows'] as $r) {
                echo "  • Row ID: " . str_pad($r['id'], 6) . 
                     " | Status: " . str_pad($r['status'], 8) . 
                     " | Amount: ₹" . str_pad(number_format((float)$r['amount'], 2), 10) . 
                     " | Paid Amt: " . ($r['paid_amount'] !== null ? "₹" . number_format((float)$r['paid_amount'], 2) : "NULL      ") . 
                     " | Paid Date: " . ($r['paid_date'] ?: "NULL      ") . 
                     " | Ref: " . ($r['payment_reference'] ?: "NULL") . 
                     " | Mode: " . ($r['payment_mode'] ?: "NULL") . 
                     " | Approved By: " . ($r['approved_by'] ?: "NULL") . 
                     " | Approved At: " . ($r['approved_at'] ?: "NULL") . 
                     " | Created: {$r['created_at']}" . 
                     " | Updated: {$r['updated_at']}\n";
            }
            if (isset($grp['canonical_row'])) {
                $canId = $grp['canonical_row']['id'];
                $redIds = implode(', ', array_column($grp['redundant_rows'], 'id'));
                echo "  >>> PROPOSED ACTION: Keep canonical Row ID $canId ({$grp['canonical_row']['status']}); Prune redundant unevidenced pending Row ID(s): [$redIds]\n";
            } else {
                echo "  >>> PROPOSED ACTION: MANUAL REVIEW REQUIRED — NO AUTOMATIC ACTION. Preserving all records untouched.\n";
            }
        }
        echo "\n";
    };

    $printGroupDetails("GROUP A: APPROVED PAYMENT + REDUNDANT PENDING DUPLICATE", $classifiedGroups['A_approved_with_pending'], "Retain canonical approved row, prune empty pending duplicate");
    $printGroupDetails("GROUP B: IN-FLIGHT REVIEW PAYMENT + REDUNDANT PENDING", $classifiedGroups['B_inflight_with_pending'], "Retain canonical in-flight review row, prune empty pending duplicate");
    $printGroupDetails("GROUP C: MULTIPLE EMPTY PENDING ROWS", $classifiedGroups['C_multiple_pending'], "Retain deterministic row (remarks first, else lowest ID), prune redundant empty pending");
    $printGroupDetails("GROUP D: CONFLICTING FINANCIAL EVIDENCE (MANUAL REVIEW)", $classifiedGroups['D_manual_review_needed'], "MANUAL REVIEW REQUIRED");

    echo "--- 5. DRY-RUN RECONCILIATION SUMMARY ---\n";
    $safePrunableRows = 0;
    foreach (['A_approved_with_pending', 'B_inflight_with_pending', 'C_multiple_pending'] as $cat) {
        foreach ($classifiedGroups[$cat] as $g) {
            $safePrunableRows += count($g['redundant_rows']);
        }
    }
    echo "Total redundant duplicate rows eligible for safe reconciliation : {$safePrunableRows}\n";
    echo "Total records requiring manual review                          : " . count($classifiedGroups['D_manual_review_needed']) . "\n";
    echo "Can UNIQUE constraint be applied now?                          : " . ($totalDupGroups === 0 ? "YES" : "NO (Reconciliation required first)") . "\n\n";
    echo "DRY-RUN COMPLETE. ZERO ROWS MODIFIED.\n";
    exit(0);
}

// ─── STAGE 2: EXECUTE RECONCILIATION ──────────────────────────────────────
if ($mode === 'execute-reconciliation') {
    echo "Starting reconciliation transaction...\n";
    $pdo->beginTransaction();
    try {
        // Find duplicate groups with row locking
        $stmtDups = $pdo->query("
            SELECT user_id, instalment_number, COUNT(*) AS cnt
            FROM instalment_details
            GROUP BY user_id, instalment_number
            HAVING COUNT(*) > 1
            ORDER BY cnt DESC, user_id, instalment_number
        ");
        $dupGroups = $stmtDups->fetchAll(PDO::FETCH_ASSOC);

        $prunedCount = 0;
        $preservedCount = 0;
        $skippedManualCount = 0;

        foreach ($dupGroups as $dg) {
            $uId = $dg['user_id'];
            $instNum = (int)$dg['instalment_number'];

            $stmtRows = $pdo->prepare("
                SELECT * FROM instalment_details
                WHERE user_id = ? AND instalment_number = ?
                ORDER BY id ASC
                FOR UPDATE
            ");
            $stmtRows->execute([$uId, $instNum]);
            $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

            $approvedRows = [];
            $inFlightRows = [];
            $emptyPending = [];

            foreach ($rows as $r) {
                if (in_array($r['status'], ['approved', 'paid'], true)) {
                    $approvedRows[] = $r;
                } elseif ($r['status'] === 'pending' && !empty($r['paid_date'])) {
                    $inFlightRows[] = $r;
                } else {
                    $emptyPending[] = $r;
                }
            }

            $canonical = null;
            $redundant = [];

            if (count($approvedRows) === 1 && count($inFlightRows) === 0) {
                // Group A
                $canonical = $approvedRows[0];
                $redundant = $emptyPending;
            } elseif (count($inFlightRows) === 1 && count($approvedRows) === 0) {
                // Group B
                $canonical = $inFlightRows[0];
                $redundant = $emptyPending;
            } elseif (count($approvedRows) === 0 && count($inFlightRows) === 0) {
                // Group C: Deterministic rule: remark first, then lowest id
                usort($rows, function($a, $b) {
                    $aHasRemark = !empty(trim($a['admin_remarks'] ?? ''));
                    $bHasRemark = !empty(trim($b['admin_remarks'] ?? ''));
                    if ($aHasRemark !== $bHasRemark) return $bHasRemark <=> $aHasRemark;
                    return (int)$a['id'] <=> (int)$b['id'];
                });
                $canonical = $rows[0];
                $redundant = array_slice($rows, 1);
            } else {
                // Group D: Conflicting financial evidence - STRICTLY PRESERVE BOTH
                $skippedManualCount++;
                echo "  [SKIPPED] Student $uId Inst #$instNum: Multiple financial records require manual review.\n";
                continue;
            }

            // Remove only unevidenced redundant rows
            foreach ($redundant as $redRow) {
                $delStmt = $pdo->prepare("DELETE FROM instalment_details WHERE id = ?");
                $delStmt->execute([$redRow['id']]);
                $prunedCount++;
                echo "  [RECONCILED] Student $uId Inst #$instNum: Kept canonical ID {$canonical['id']} ({$canonical['status']}), pruned redundant ID {$redRow['id']} ({$redRow['status']})\n";
            }
            $preservedCount++;
        }

        // Log reconciliation in admin activity log
        log_admin_activity($pdo, 'cli_reconciliation', 'installment_integrity_reconciled', "Reconciled $preservedCount installment groups, pruned $prunedCount redundant rows. Skipped $skippedManualCount conflicting groups.");

        $pdo->commit();
        echo "\nRECONCILIATION TRANSACTION COMMITTED SUCCESSFULLY.\n";
        echo "Groups Reconciled : $preservedCount\n";
        echo "Rows Pruned       : $prunedCount\n";
        echo "Groups Skipped    : $skippedManualCount\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "ERROR during reconciliation: " . $e->getMessage() . "\n";
        echo "TRANSACTION ROLLED BACK.\n";
        exit(1);
    }
    exit(0);
}

// ─── STAGE 3: VERIFY INTEGRITY ─────────────────────────────────────────────
if ($mode === 'verify') {
    $stmtDups = $pdo->query("
        SELECT user_id, instalment_number, COUNT(*) AS cnt
        FROM instalment_details
        GROUP BY user_id, instalment_number
        HAVING COUNT(*) > 1
    ");
    $remaining = $stmtDups->fetchAll(PDO::FETCH_ASSOC);
    if (count($remaining) === 0) {
        echo "✅ VERIFICATION PASSED: Zero duplicate (user_id, instalment_number) records found.\n";
        echo "The table is ready for the UNIQUE constraint.\n";
        exit(0);
    } else {
        echo "❌ VERIFICATION FAILED: " . count($remaining) . " duplicate groups still exist.\n";
        foreach ($remaining as $rem) {
            echo "  - Student {$rem['user_id']} | Inst #{$rem['instalment_number']} | Count: {$rem['cnt']}\n";
        }
        exit(1);
    }
}

// ─── STAGE 4: APPLY CONSTRAINT ─────────────────────────────────────────────
if ($mode === 'apply-constraint') {
    // 1. Verify duplicates = 0 first
    $stmtDups = $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT user_id, instalment_number
            FROM instalment_details
            GROUP BY user_id, instalment_number
            HAVING COUNT(*) > 1
        ) t
    ");
    $dupCount = (int)$stmtDups->fetchColumn();
    if ($dupCount > 0) {
        echo "❌ ABORTED: Cannot apply UNIQUE constraint while $dupCount duplicate groups exist.\n";
        exit(1);
    }

    $indexes = get_table_indexes($pdo, 'instalment_details');
    if (isset($indexes['unique_user_installment']) && $indexes['unique_user_installment']['unique']) {
        echo "✅ Constraint `unique_user_installment` is ALREADY applied.\n";
        exit(0);
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        echo "Applying `ALTER TABLE instalment_details ADD UNIQUE KEY unique_user_installment (user_id, instalment_number)`...\n";
        $pdo->exec("ALTER TABLE `instalment_details` ADD UNIQUE KEY `unique_user_installment` (`user_id`, `instalment_number`)");
    } else {
        echo "Applying `CREATE UNIQUE INDEX unique_user_installment ON instalment_details (user_id, instalment_number)`...\n";
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS `unique_user_installment` ON `instalment_details` (`user_id`, `instalment_number`)");
    }
    echo "✅ SUCCESS: Composite UNIQUE constraint `unique_user_installment` applied.\n";
    exit(0);
}
