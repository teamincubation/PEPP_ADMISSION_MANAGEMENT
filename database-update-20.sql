-- Database update 20: WhatsApp Outbound Mode Toggle
-- Creates audit table, sets default mode, registers onboarding_app_access event mapping

CREATE TABLE IF NOT EXISTS `whatsapp_mode_audit` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `old_mode` VARCHAR(20) NOT NULL,
    `new_mode` VARCHAR(20) NOT NULL,
    `changed_by` VARCHAR(100) NOT NULL,
    `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default to 'manual' to preserve existing behavior until admin explicitly enables META API
INSERT INTO `admin_settings` (`setting_name`, `setting_value`, `updated_at`)
VALUES ('whatsapp_outbound_mode', 'manual', NOW())
ON DUPLICATE KEY UPDATE `setting_name` = `setting_name`;

-- Register onboarding_app_access event mapping with empty template (preparation only)
INSERT INTO `communication_event_mappings` (`event_name`, `template_name`, `parameter_mappings`)
VALUES ('onboarding_app_access', '', '{}')
ON DUPLICATE KEY UPDATE `event_name` = `event_name`;
