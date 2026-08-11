<?php
/**
 * One-time migration runner for database-update-20.sql
 * Executes the 3 SQL statements and verifies results.
 * DELETE THIS FILE AFTER USE.
 */
require_once 'config/database.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== Executing database-update-20.sql ===\n";
echo "Database: " . DB_NAME . "\n";
echo "Timestamp: " . date('Y-m-d H:i:s T') . "\n\n";

$errors = [];

// Statement 1: Create whatsapp_mode_audit table
echo "--- STMT 1: CREATE TABLE whatsapp_mode_audit ---\n";
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `whatsapp_mode_audit` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `old_mode` VARCHAR(20) NOT NULL,
            `new_mode` VARCHAR(20) NOT NULL,
            `changed_by` VARCHAR(100) NOT NULL,
            `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $exists = $pdo->query("SHOW TABLES LIKE 'whatsapp_mode_audit'")->fetchColumn();
    echo $exists ? "OK Table created\n" : "FAIL Table not found after CREATE\n";
} catch (Exception $e) {
    echo "FAIL " . $e->getMessage() . "\n";
    $errors[] = $e->getMessage();
}

// Statement 2: Insert whatsapp_outbound_mode setting
echo "\n--- STMT 2: INSERT admin_settings.whatsapp_outbound_mode ---\n";
try {
    $pdo->exec("
        INSERT INTO `admin_settings` (`setting_name`, `setting_value`, `updated_at`)
        VALUES ('whatsapp_outbound_mode', 'manual', NOW())
        ON DUPLICATE KEY UPDATE `setting_name` = `setting_name`
    ");
    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    echo $val ? "OK Value = " . $val . "\n" : "FAIL Row not found after INSERT\n";
} catch (Exception $e) {
    echo "FAIL " . $e->getMessage() . "\n";
    $errors[] = $e->getMessage();
}

// Statement 3: Insert onboarding_app_access event mapping
echo "\n--- STMT 3: INSERT communication_event_mappings.onboarding_app_access ---\n";
try {
    $pdo->exec("
        INSERT INTO `communication_event_mappings` (`event_name`, `template_name`, `parameter_mappings`)
        VALUES ('onboarding_app_access', '', '{}')
        ON DUPLICATE KEY UPDATE `event_name` = `event_name`
    ");
    $stmt = $pdo->prepare("SELECT event_name, template_name FROM communication_event_mappings WHERE event_name = 'onboarding_app_access'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "OK event_name = " . $row['event_name'] . ", template_name = " . ($row['template_name'] ?: '(empty)') . "\n";
    } else {
        echo "FAIL Row not found after INSERT\n";
    }
} catch (Exception $e) {
    echo "FAIL " . $e->getMessage() . "\n";
    $errors[] = $e->getMessage();
}

echo "\n=== RESULT: " . (empty($errors) ? "ALL 3 STATEMENTS SUCCEEDED" : count($errors) . " ERROR(S)") . " ===\n";
