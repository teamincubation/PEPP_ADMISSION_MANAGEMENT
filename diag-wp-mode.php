<?php
/**
 * READ-ONLY diagnostic: checks production DB state for WhatsApp mode toggle.
 * Does NOT modify anything. Safe to run on production.
 * Delete this file after diagnosis.
 */
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== WhatsApp Mode Toggle — Production Diagnostic ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s T') . "\n\n";

// 1. Check whatsapp_mode_audit table exists
echo "--- CHECK 1: whatsapp_mode_audit table ---\n";
try {
    $result = $pdo->query("SHOW TABLES LIKE 'whatsapp_mode_audit'")->fetchColumn();
    echo $result ? "OK Table EXISTS\n" : "FAIL Table DOES NOT EXIST\n";
} catch (Exception $e) {
    echo "FAIL Error: " . $e->getMessage() . "\n";
}

// 2. Check admin_settings for whatsapp_outbound_mode
echo "\n--- CHECK 2: admin_settings.whatsapp_outbound_mode ---\n";
try {
    $stmt = $pdo->prepare("SELECT setting_name, setting_value, updated_at FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "OK Setting EXISTS\n";
        echo "  setting_name:  " . $row['setting_name'] . "\n";
        echo "  setting_value: " . $row['setting_value'] . "\n";
        echo "  updated_at:    " . $row['updated_at'] . "\n";
    } else {
        echo "FAIL Setting DOES NOT EXIST in admin_settings\n";
    }
} catch (Exception $e) {
    echo "FAIL Error: " . $e->getMessage() . "\n";
}

// 3. Check communication_event_mappings for onboarding_app_access
echo "\n--- CHECK 3: communication_event_mappings.onboarding_app_access ---\n";
try {
    $stmt = $pdo->prepare("SELECT * FROM communication_event_mappings WHERE event_name = 'onboarding_app_access'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "OK Event mapping EXISTS\n";
        echo "  template_name: " . ($row['template_name'] ?: '(empty)') . "\n";
    } else {
        echo "FAIL Event mapping DOES NOT EXIST\n";
    }
} catch (Exception $e) {
    echo "FAIL Error: " . $e->getMessage() . "\n";
}

// 4. Simulate the switch path (read-only)
echo "\n--- CHECK 4: Simulated switch path ---\n";
echo "  Audit INSERT target: ";
try {
    $result = $pdo->query("SHOW TABLES LIKE 'whatsapp_mode_audit'")->fetchColumn();
    echo $result ? "OK table exists\n" : "FAIL TABLE MISSING - INSERT WOULD FAIL\n";
} catch (Exception $e) {
    echo "FAIL Error: " . $e->getMessage() . "\n";
}

echo "  Settings UPDATE target: ";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode'");
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    echo $count > 0 ? "OK row exists\n" : "FAIL ROW MISSING\n";
} catch (Exception $e) {
    echo "FAIL Error: " . $e->getMessage() . "\n";
}

echo "\n=== DIAGNOSIS COMPLETE ===\n";
