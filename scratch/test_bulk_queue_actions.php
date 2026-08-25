<?php
/**
 * Backend Functional Unit Tests for Bulk Queue Actions.
 * Evaluates bulk cancel, bulk retry, transient vs permanent skipping rules.
 */

// Enable SQLite Memory Database Testing Mode
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/communication/CommunicationHelper.php';

function assert_test($label, $assertion) {
    if ($assertion) {
        echo "✅ PASS: {$label}\n";
    } else {
        echo "❌ FAIL: {$label}\n";
        exit(1);
    }
}

global $pdo;

echo "=== Running Bulk Queue Action Tests (SQLite Mock Mode) ===\n";

try {
    // 1. Setup Test Records
    $stmt = $pdo->prepare("
        INSERT INTO communication_queue (channel, recipient, status, retry_count, error_message, updated_at)
        VALUES 
        ('whatsapp', '910000000001', 'failed', 3, '[Meta Code 131026] Permanent failure', datetime('now')),
        ('whatsapp', '910000000002', 'failed', 0, '[Meta Code 131021] Transient error', datetime('now')),
        ('whatsapp', '910000000003', 'failed', 1, 'Connection timeout error', datetime('now')),
        ('whatsapp', '910000000004', 'delivered', 0, NULL, datetime('now')),
        ('whatsapp', '910000000005', 'pending', 0, NULL, datetime('now'))
    ");
    $stmt->execute();
    
    // Retrieve the inserted IDs
    $lastId = (int)$pdo->lastInsertId();
    $id1 = $lastId - 4; // Permanent Failed
    $id2 = $lastId - 3; // Transient Failed (Meta 131021)
    $id3 = $lastId - 2; // Transient Failed (Text connection timeout)
    $id4 = $lastId - 1; // Delivered
    $id5 = $lastId;     // Pending
    
    echo "Inserted test queue records: #{$id1}, #{$id2}, #{$id3}, #{$id4}, #{$id5}\n";

    // ----------------------------------------------------
    // TEST CASE A: Bulk Cancel
    // ----------------------------------------------------
    echo "\n--- Test A: Bulk Cancel ---\n";
    // We select all 5 items to cancel.
    // Eligible items: #id1, #id2, #id3, #id5 (status not sent/delivered/read/cancelled).
    // Non-eligible items: #id4 (status delivered).
    
    $queueIds = [$id1, $id2, $id3, $id4, $id5];
    $reason = "Testing Bulk Cancel";
    
    // Run the inner cancel controller logic
    $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
    $selStmt = $pdo->prepare("SELECT id, status, error_message FROM communication_queue WHERE id IN ($placeholders)");
    $selStmt->execute($queueIds);
    $rows = $selStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $eligibleIds = [];
    $skippedCount = 0;
    foreach ($rows as $row) {
        $status = $row['status'] ?? '';
        if ($status && !in_array($status, ['sent', 'delivered', 'read', 'cancelled'], true)) {
            $eligibleIds[] = (int)$row['id'];
        } else {
            $skippedCount++;
        }
    }
    
    assert_test("Bulk cancel eligible count matches (4 expected)", count($eligibleIds) === 4);
    assert_test("Bulk cancel skipped count matches (1 expected)", $skippedCount === 1);
    
    if (!empty($eligibleIds)) {
        $placeholdersEligible = implode(',', array_fill(0, count($eligibleIds), '?'));
        $upd = $pdo->prepare("UPDATE communication_queue SET status = 'cancelled', error_message = ?, updated_at = datetime('now') WHERE id IN ($placeholdersEligible)");
        $upd->execute(array_merge(['Cancelled: ' . $reason], $eligibleIds));
    }
    
    // Verify status changes in DB
    $chkStmt = $pdo->prepare("SELECT id, status, error_message FROM communication_queue WHERE id IN ($placeholders)");
    $chkStmt->execute($queueIds);
    $chkRowsRaw = $chkStmt->fetchAll(PDO::FETCH_ASSOC);
    $chkRows = [];
    foreach ($chkRowsRaw as $r) {
        $chkRows[(int)$r['id']] = $r;
    }
    
    assert_test("#id1 is cancelled", $chkRows[$id1]['status'] === 'cancelled');
    assert_test("#id2 is cancelled", $chkRows[$id2]['status'] === 'cancelled');
    assert_test("#id3 is cancelled", $chkRows[$id3]['status'] === 'cancelled');
    assert_test("#id4 is still delivered", $chkRows[$id4]['status'] === 'delivered');
    assert_test("#id5 is cancelled", $chkRows[$id5]['status'] === 'cancelled');
    
    // ----------------------------------------------------
    // TEST CASE B: Bulk Retry (Fresh Start)
    // ----------------------------------------------------
    echo "\n--- Test B: Bulk Retry ---\n";
    // We restore status to original state for retry testing:
    $updOrig = $pdo->prepare("UPDATE communication_queue SET status = 'failed', error_message = '[Meta Code 131026] Permanent failure' WHERE id = ?");
    $updOrig->execute([$id1]);
    $updOrig = $pdo->prepare("UPDATE communication_queue SET status = 'failed', error_message = '[Meta Code 131021] Transient error' WHERE id = ?");
    $updOrig->execute([$id2]);
    $updOrig = $pdo->prepare("UPDATE communication_queue SET status = 'failed', error_message = 'Connection timeout error' WHERE id = ?");
    $updOrig->execute([$id3]);
    $updOrig = $pdo->prepare("UPDATE communication_queue SET status = 'delivered', error_message = NULL WHERE id = ?");
    $updOrig->execute([$id4]);
    $updOrig = $pdo->prepare("UPDATE communication_queue SET status = 'pending', error_message = NULL WHERE id = ?");
    $updOrig->execute([$id5]);
    
    // Select all 5 items to retry
    // #id1: failed, permanent Meta error (skip)
    // #id2: failed, transient Meta error (retry)
    // #id3: failed, transient text error (retry)
    // #id4: delivered (skip)
    // #id5: pending (skip)
    
    $selStmt->execute($queueIds);
    $rows = $selStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $eligibleRetryIds = [];
    $skippedPermanentCount = 0;
    $skippedStatusCount = 0;
    
    foreach ($rows as $row) {
        $status = $row['status'] ?? '';
        $error_message_orig = $row['error_message'] ?? '';
        
        if (!in_array($status, ['failed', 'cancelled'], true)) {
            $skippedStatusCount++;
            continue;
        }
        
        $errCode = null;
        if (!empty($error_message_orig)) {
            if (preg_match('/\[Meta Code (\d+)\]/', $error_message_orig, $matches)) {
                $errCode = (int)$matches[1];
            }
        }
        $isPermanent = CommunicationHelper::isPermanentMetaFailure($errCode, $error_message_orig);
        if ($isPermanent) {
            $skippedPermanentCount++;
            continue;
        }
        
        $eligibleRetryIds[] = (int)$row['id'];
    }
    
    assert_test("Bulk retry eligible count matches (2 expected - #id2 and #id3)", count($eligibleRetryIds) === 2);
    assert_test("Bulk retry skipped permanent count matches (1 expected - #id1)", $skippedPermanentCount === 1);
    assert_test("Bulk retry skipped status count matches (2 expected - #id4 and #id5)", $skippedStatusCount === 2);
    
    if (!empty($eligibleRetryIds)) {
        $placeholdersEligible = implode(',', array_fill(0, count($eligibleRetryIds), '?'));
        $upd = $pdo->prepare("UPDATE communication_queue SET status = 'pending', retry_count = 0, error_message = NULL, updated_at = datetime('now') WHERE id IN ($placeholdersEligible)");
        $upd->execute($eligibleRetryIds);
    }
    
    // Verify status changes in DB
    $chkStmt->execute($queueIds);
    $chkRowsRaw = $chkStmt->fetchAll(PDO::FETCH_ASSOC);
    $chkRows = [];
    foreach ($chkRowsRaw as $r) {
        $chkRows[(int)$r['id']] = $r;
    }
    
    assert_test("#id1 remains failed (skipped permanent protection)", $chkRows[$id1]['status'] === 'failed');
    assert_test("#id2 is now pending (retry successful)", $chkRows[$id2]['status'] === 'pending');
    assert_test("#id3 is now pending (retry successful)", $chkRows[$id3]['status'] === 'pending');
    assert_test("#id4 remains delivered", $chkRows[$id4]['status'] === 'delivered');
    assert_test("#id5 remains pending", $chkRows[$id5]['status'] === 'pending');
    
    echo "\n=== All Bulk Queue Action Tests Passed Successfully! ===\n";
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
