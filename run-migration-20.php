<?php
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "=== POST-MIGRATION VERIFICATION ===\n";
echo "Database: " . DB_NAME . "\nTime: " . date('Y-m-d H:i:s T') . "\n\n";

// A. whatsapp_mode_audit exists
echo "A. whatsapp_mode_audit table: ";
$r = $pdo->query("SHOW TABLES LIKE 'whatsapp_mode_audit'")->fetchColumn();
echo $r ? "EXISTS\n" : "MISSING\n";

// B. admin_settings.whatsapp_outbound_mode
echo "B. whatsapp_outbound_mode: ";
$stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name='whatsapp_outbound_mode'");
$stmt->execute();
$v = $stmt->fetchColumn();
echo $v !== false ? $v . "\n" : "MISSING\n";

// C. communication_event_mappings.onboarding_app_access
echo "C. onboarding_app_access mapping: ";
$stmt = $pdo->prepare("SELECT event_name, template_name, parameter_mappings FROM communication_event_mappings WHERE event_name='onboarding_app_access'");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row ? "EXISTS (template=" . ($row['template_name'] ?: '(empty)') . ", params=" . $row['parameter_mappings'] . ")\n" : "MISSING\n";

// Audit table structure
echo "\nAudit table columns: ";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM whatsapp_mode_audit")->fetchAll(PDO::FETCH_COLUMN);
    echo implode(', ', $cols) . "\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== VERIFICATION COMPLETE ===\n";
