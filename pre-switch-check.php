<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "=== PRE-SWITCH SAFETY VERIFICATION ===\n";
echo "Database: " . DB_NAME . "\nTime: " . date('Y-m-d H:i:s T') . "\n\n";

// 1A. whatsapp_mode_audit table
echo "1A. whatsapp_mode_audit table: ";
echo $pdo->query("SHOW TABLES LIKE 'whatsapp_mode_audit'")->fetchColumn() ? "EXISTS\n" : "MISSING\n";

// 1B. whatsapp_outbound_mode setting
echo "1B. whatsapp_outbound_mode: ";
$stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name='whatsapp_outbound_mode'");
$stmt->execute();
$mode = $stmt->fetchColumn();
echo $mode !== false ? $mode . "\n" : "MISSING\n";

// 1C. onboarding_app_access mapping
echo "1C. onboarding_app_access: ";
$stmt = $pdo->prepare("SELECT template_name, parameter_mappings FROM communication_event_mappings WHERE event_name='onboarding_app_access'");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row ? "EXISTS (template=" . ($row['template_name'] ?: '(empty)') . ")\n" : "MISSING\n";

// 9. Pending communication_queue records
echo "\n--- PENDING QUEUE ANALYSIS ---\n";
try {
    $qStats = $pdo->query("SELECT status, COUNT(*) as c FROM communication_queue GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);
    $totalPending = 0;
    foreach ($qStats as $qs) {
        echo "  " . $qs['status'] . ": " . $qs['c'] . "\n";
        if (in_array($qs['status'], ['pending', 'processing', 'scheduled'])) {
            $totalPending += (int)$qs['c'];
        }
    }
    echo "  TOTAL ACTIONABLE (pending/processing/scheduled): " . $totalPending . "\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// Check if any scheduled items could auto-dispatch
echo "\n--- SCHEDULED/PENDING ITEMS (next 5) ---\n";
try {
    $pendingItems = $pdo->query("
        SELECT id, channel, recipient_name, status, event_name, template_name, next_attempt_at, created_at
        FROM communication_queue 
        WHERE status IN ('pending', 'processing', 'scheduled')
        ORDER BY id ASC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($pendingItems)) {
        echo "  (none)\n";
    } else {
        foreach ($pendingItems as $pi) {
            echo "  ID=" . $pi['id'] . " status=" . $pi['status'] . " event=" . ($pi['event_name'] ?: '-') . " tpl=" . ($pi['template_name'] ?: '-') . " next=" . $pi['next_attempt_at'] . "\n";
        }
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// Check audit table is empty (no prior switches)
echo "\n--- AUDIT TABLE RECORDS ---\n";
try {
    $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_mode_audit")->fetchColumn();
    echo "  Records: " . $auditCount . "\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== VERIFICATION COMPLETE ===\n";
