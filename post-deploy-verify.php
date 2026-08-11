<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "=== POST-DEPLOYMENT VERIFICATION ===\n";
echo "Time: " . date('Y-m-d H:i:s T') . "\n\n";

// A. No stale legacy pending records
echo "A. Pending queue records: ";
$pending = $pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status = 'pending'")->fetchColumn();
echo $pending . "\n";

// B. Cancelled legacy records count
echo "B. Cancelled legacy records: ";
$cancelled = $pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status = 'cancelled' AND error_message LIKE '%pre_mode_toggle_cleanup%'")->fetchColumn();
echo $cancelled . "\n";

// C. Current mode
echo "C. Current mode: ";
$stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode'");
$stmt->execute();
echo $stmt->fetchColumn() . "\n";

// D. Audit table
echo "D. Audit records: ";
echo $pdo->query("SELECT COUNT(*) FROM whatsapp_mode_audit")->fetchColumn() . "\n";

// E. Mode-era guard present (check file)
echo "E. Mode-era guard: ";
$engineCode = file_get_contents(__DIR__ . '/includes/communication/CommunicationEngine.php');
echo (strpos($engineCode, 'MODE-ERA GUARD') !== false) ? "PRESENT\n" : "MISSING\n";

// F. Transaction in dashboard
echo "F. Transaction safety: ";
$dashCode = file_get_contents(__DIR__ . '/communication-dashboard.php');
echo (strpos($dashCode, 'beginTransaction') !== false && strpos($dashCode, 'rollBack') !== false) ? "PRESENT\n" : "MISSING\n";

// G. Queue status breakdown
echo "\nG. Queue status breakdown:\n";
$stats = $pdo->query("SELECT status, COUNT(*) as c FROM communication_queue GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($stats as $s) {
    echo "  " . $s['status'] . ": " . $s['c'] . "\n";
}

echo "\n=== VERIFICATION COMPLETE ===\n";
