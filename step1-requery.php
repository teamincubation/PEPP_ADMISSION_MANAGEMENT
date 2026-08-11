<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "=== STEP 1: RE-QUERY PENDING RECORDS ===\n";
echo "Time: " . date('Y-m-d H:i:s T') . "\n\n";

// 1. Get ALL current pending records
$stmt = $pdo->query("SELECT id, status, event_name, template_name, student_uid, recipient_name, subject, created_at FROM communication_queue WHERE status = 'pending' ORDER BY id ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total pending: " . count($rows) . "\n\n";

$ids = [];
foreach ($rows as $r) {
    $ids[] = (int)$r['id'];
    echo "ID=" . $r['id'] . " | " . $r['created_at'] . " | " . ($r['event_name'] ?: '-') . " | " . ($r['template_name'] ?: '-') . " | " . $r['recipient_name'] . " | " . $r['subject'] . "\n";
}

echo "\nPending IDs: " . implode(', ', $ids) . "\n";

// 2. Check if any NEW records were created after the mode architecture deployment (commit dd729fa was Aug 11 ~21:00 IST = ~20:57)
$cutoff = '2026-08-11 22:30:00'; // safe buffer after the last known pre-toggle record (ID 68 at 22:22)
$newRecords = $pdo->query("SELECT id, status, created_at, subject FROM communication_queue WHERE created_at >= '{$cutoff}' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "\nRecords created after {$cutoff}:\n";
if (empty($newRecords)) {
    echo "  (none)\n";
} else {
    foreach ($newRecords as $nr) {
        echo "  ID=" . $nr['id'] . " status=" . $nr['status'] . " created=" . $nr['created_at'] . " subject=" . $nr['subject'] . "\n";
    }
}

echo "\n=== DONE ===\n";
