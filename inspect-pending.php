<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "=== PENDING QUEUE INSPECTION (READ-ONLY) ===\n";
echo "Time: " . date('Y-m-d H:i:s T') . "\n\n";

$stmt = $pdo->query("
    SELECT id, channel, recipient, recipient_name, subject, template_name, 
           status, priority, retry_count, next_attempt_at, message_id, 
           error_message, sent_by, created_at, updated_at, student_uid, event_name
    FROM communication_queue 
    WHERE status = 'pending'
    ORDER BY id ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total pending: " . count($rows) . "\n\n";

foreach ($rows as $i => $r) {
    echo "--- RECORD " . ($i+1) . " ---\n";
    echo "  queue_id:       " . $r['id'] . "\n";
    echo "  event_name:     " . ($r['event_name'] ?: '(none)') . "\n";
    echo "  template_name:  " . ($r['template_name'] ?: '(none)') . "\n";
    echo "  student_uid:    " . ($r['student_uid'] ?: '(none)') . "\n";
    echo "  recipient:      " . ($r['recipient'] ?: '(none)') . "\n";
    echo "  recipient_name: " . ($r['recipient_name'] ?: '(none)') . "\n";
    echo "  subject:        " . ($r['subject'] ?: '(none)') . "\n";
    echo "  sent_by:        " . ($r['sent_by'] ?: '(none)') . "\n";
    echo "  channel:        " . $r['channel'] . "\n";
    echo "  created_at:     " . $r['created_at'] . "\n";
    echo "  updated_at:     " . $r['updated_at'] . "\n";
    echo "  next_attempt:   " . $r['next_attempt_at'] . "\n";
    echo "  retry_count:    " . $r['retry_count'] . "\n";
    echo "  message_id:     " . ($r['message_id'] ?: '(none)') . "\n";
    echo "  error_message:  " . ($r['error_message'] ?: '(none)') . "\n";
    echo "\n";
}

echo "=== STATUS ENUM CHECK ===\n";
try {
    $colInfo = $pdo->query("SHOW COLUMNS FROM communication_queue LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    echo "status column type: " . $colInfo['Type'] . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
