<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "=== META API ACTIVATION ===\n";
echo "Time: " . date('Y-m-d H:i:s T') . "\n\n";

// ── PRE-SWITCH CHECKS ──
echo "--- PRE-SWITCH CHECKS ---\n";

// 1. Current mode
$stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name='whatsapp_outbound_mode'");
$stmt->execute();
$currentMode = $stmt->fetchColumn();
echo "1. Current mode: " . $currentMode . "\n";
if ($currentMode !== 'manual') {
    echo "ABORT: Mode is not manual. Cannot proceed.\n";
    exit;
}

// 2. Pending records
$pending = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status = 'pending'")->fetchColumn();
echo "2. Pending queue records: " . $pending . "\n";
if ($pending > 0) {
    echo "ABORT: " . $pending . " pending records exist. Cannot proceed.\n";
    exit;
}

// 3. Deployed commit
$engineCode = file_get_contents(__DIR__ . '/includes/communication/CommunicationEngine.php');
$hasGuard = strpos($engineCode, 'MODE-ERA GUARD') !== false;
$dashCode = file_get_contents(__DIR__ . '/communication-dashboard.php');
$hasTxn = strpos($dashCode, 'beginTransaction') !== false && strpos($dashCode, 'rollBack') !== false;
echo "3. Mode-era guard deployed: " . ($hasGuard ? 'YES' : 'NO') . "\n";
echo "3. Transaction safety deployed: " . ($hasTxn ? 'YES' : 'NO') . "\n";
if (!$hasGuard || !$hasTxn) {
    echo "ABORT: Safety hardening not deployed.\n";
    exit;
}

// 4. Queue count before switch (for comparison)
$totalBefore = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();
echo "4. Total queue records before switch: " . $totalBefore . "\n";

echo "\nAll pre-switch checks PASSED.\n\n";

// ── EXECUTE SWITCH ──
echo "--- EXECUTING SWITCH: manual -> meta_api ---\n";
$admin_username = 'superadmin'; // production admin

try {
    $pdo->beginTransaction();

    $auditStmt = $pdo->prepare("INSERT INTO whatsapp_mode_audit (old_mode, new_mode, changed_by, changed_at) VALUES (?, ?, ?, NOW())");
    $auditStmt->execute(['manual', 'meta_api', $admin_username]);

    $updateStmt = $pdo->prepare("
        INSERT INTO admin_settings (setting_name, setting_value, updated_at) 
        VALUES ('whatsapp_outbound_mode', 'meta_api', NOW()) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $updateStmt->execute();

    $pdo->commit();
    echo "Switch COMMITTED successfully.\n\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Switch FAILED and ROLLED BACK: " . $e->getMessage() . "\n";
    exit;
}

// ── POST-SWITCH VERIFICATION ──
echo "--- POST-SWITCH VERIFICATION ---\n";

// 1. Confirm new mode
$stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name='whatsapp_outbound_mode'");
$stmt->execute();
$newMode = $stmt->fetchColumn();
echo "1. New mode: " . $newMode . "\n";

// 2. Audit record
$auditRow = $pdo->query("SELECT * FROM whatsapp_mode_audit ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "2. Latest audit record:\n";
echo "   id: " . $auditRow['id'] . "\n";
echo "   old_mode: " . $auditRow['old_mode'] . "\n";
echo "   new_mode: " . $auditRow['new_mode'] . "\n";
echo "   changed_by: " . $auditRow['changed_by'] . "\n";
echo "   changed_at: " . $auditRow['changed_at'] . "\n";

// 3. Queue records created by switch
$totalAfter = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();
$queueCreated = $totalAfter - $totalBefore;
echo "3. Queue records created by switch: " . $queueCreated . "\n";

// 4. Pending count
$pendingAfter = (int)$pdo->query("SELECT COUNT(*) FROM communication_queue WHERE status = 'pending'")->fetchColumn();
echo "4. Pending records after switch: " . $pendingAfter . "\n";

// 5. WhatsApp API calls (the switch handler makes zero — confirmed by code path)
echo "5. WhatsApp API calls by switch: 0 (handler contains only 2 SQL statements)\n";

echo "\n=== ACTIVATION COMPLETE ===\n";
