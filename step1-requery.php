<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "=== STEP 1: CANCEL LEGACY PENDING RECORDS ===\n";
echo "Time: " . date('Y-m-d H:i:s T') . "\n\n";

// Explicit list of the 33 confirmed legacy pending IDs
$legacyIds = [12,13,14,15,16,17,18,19,20,21,22,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,48,68];
echo "Target IDs (" . count($legacyIds) . "): " . implode(', ', $legacyIds) . "\n\n";

// Verify all are still pending before cancellation
$placeholders = implode(',', array_fill(0, count($legacyIds), '?'));
$verifyStmt = $pdo->prepare("SELECT id, status FROM communication_queue WHERE id IN ($placeholders) ORDER BY id");
$verifyStmt->execute($legacyIds);
$verified = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);

$notPending = [];
$confirmedPending = [];
foreach ($verified as $v) {
    if ($v['status'] !== 'pending') {
        $notPending[] = $v['id'] . '(' . $v['status'] . ')';
    } else {
        $confirmedPending[] = (int)$v['id'];
    }
}

echo "Confirmed pending: " . count($confirmedPending) . "\n";
if (!empty($notPending)) {
    echo "NOT pending (SKIPPED): " . implode(', ', $notPending) . "\n";
}

if (count($confirmedPending) === 0) {
    echo "\nNo records to cancel.\n";
    exit;
}

// Cancel only confirmed pending records
$cancelPlaceholders = implode(',', array_fill(0, count($confirmedPending), '?'));
$cancelStmt = $pdo->prepare("
    UPDATE communication_queue 
    SET status = 'cancelled', 
        error_message = CONCAT(IFNULL(error_message, ''), ' | cancelled:pre_mode_toggle_cleanup_2026-08-12'), 
        updated_at = NOW() 
    WHERE id IN ($cancelPlaceholders) 
      AND status = 'pending'
");
$cancelStmt->execute($confirmedPending);
$affected = $cancelStmt->rowCount();

echo "\nCancelled: " . $affected . " records\n";

// Post-cancellation verification
$remainingStmt = $pdo->query("SELECT id, status FROM communication_queue WHERE status = 'pending' ORDER BY id");
$remaining = $remainingStmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nRemaining pending after cleanup: " . count($remaining) . "\n";
if (!empty($remaining)) {
    foreach ($remaining as $r) {
        echo "  ID=" . $r['id'] . " status=" . $r['status'] . "\n";
    }
}

// Verify cancelled records
$cancelledVerify = $pdo->prepare("SELECT id, status, error_message FROM communication_queue WHERE id IN ($cancelPlaceholders) ORDER BY id");
$cancelledVerify->execute($confirmedPending);
$cancelledRows = $cancelledVerify->fetchAll(PDO::FETCH_ASSOC);
echo "\nVerification of cancelled records (sample first 3):\n";
foreach (array_slice($cancelledRows, 0, 3) as $cr) {
    echo "  ID=" . $cr['id'] . " status=" . $cr['status'] . " error=" . $cr['error_message'] . "\n";
}

echo "\n=== STEP 1 COMPLETE ===\n";
