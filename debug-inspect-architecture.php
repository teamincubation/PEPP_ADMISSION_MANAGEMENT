<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';

echo "=== EVENT MAPPINGS ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM communication_event_mappings");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo "Event: {$row['event_name']} → Template: {$row['template_name']} | Status: " . ($row['is_active'] ?? 'N/A') . "\n";
        echo "  Params: {$row['parameter_mappings']}\n\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== WHATSAPP SETTINGS ===\n";
try {
    $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%' OR setting_name LIKE 'onboarding_%' OR setting_name LIKE '%_wp_message%' OR setting_name LIKE '%_app_access%'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $val = $row['setting_value'];
        if (strlen($val) > 80) $val = substr($val, 0, 80) . '...';
        // Mask sensitive tokens
        if (strpos($row['setting_name'], 'token') !== false || strpos($row['setting_name'], 'secret') !== false) {
            $val = '***MASKED***';
        }
        echo "{$row['setting_name']}: $val\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== COMMUNICATION QUEUE COLUMNS ===\n";
try {
    $stmt = $pdo->query("DESCRIBE communication_queue");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo "{$col['Field']} ({$col['Type']})\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }

echo "\n=== STUDENT ONBOARDING TABLE COLUMNS ===\n";
try {
    $stmt = $pdo->query("DESCRIBE student_onboarding");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        echo "{$col['Field']} ({$col['Type']})\n";
    }
} catch (Throwable $t) { echo "ERROR: " . $t->getMessage() . "\n"; }
exit;
