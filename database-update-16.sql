-- Database update script for Communication Engine

CREATE TABLE IF NOT EXISTS `communication_queue` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  `recipient` VARCHAR(100) NOT NULL,
  `recipient_name` VARCHAR(255) DEFAULT NULL,
  `subject` VARCHAR(255) DEFAULT NULL,
  `body_html` LONGTEXT DEFAULT NULL,
  `body_text` LONGTEXT DEFAULT NULL,
  `template_name` VARCHAR(100) DEFAULT NULL,
  `template_data` LONGTEXT DEFAULT NULL, -- JSON serialized string
  `attachments` LONGTEXT DEFAULT NULL,   -- JSON serialized array of file details
  `status` ENUM('pending', 'processing', 'sent', 'delivered', 'read', 'failed', 'cancelled', 'scheduled') NOT NULL DEFAULT 'pending',
  `priority` INT NOT NULL DEFAULT 0,
  `retry_count` INT NOT NULL DEFAULT 0,
  `last_retry_at` DATETIME DEFAULT NULL,
  `next_attempt_at` DATETIME NOT NULL,
  `message_id` VARCHAR(255) DEFAULT NULL, -- Meta message ID or Mailer ID
  `error_message` TEXT DEFAULT NULL,
  `sent_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_cq_status_next` (`status`, `next_attempt_at`),
  KEY `idx_cq_message_id` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `communication_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  `template_name` VARCHAR(100) NOT NULL,
  `language` VARCHAR(10) NOT NULL DEFAULT 'en',
  `status` VARCHAR(20) NOT NULL DEFAULT 'approved',
  `category` VARCHAR(50) DEFAULT NULL,
  `meta_data` LONGTEXT DEFAULT NULL, -- JSON mapping metadata
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_template_channel_name` (`channel`, `template_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `communication_campaigns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  `template_name` VARCHAR(100) DEFAULT NULL,
  `segment_criteria` LONGTEXT DEFAULT NULL, -- JSON definition of targets
  `status` ENUM('draft', 'scheduled', 'active', 'paused', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
  `scheduled_at` DATETIME DEFAULT NULL,
  `created_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_cc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `communication_campaign_recipients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT NOT NULL,
  `recipient` VARCHAR(100) NOT NULL,
  `recipient_name` VARCHAR(255) DEFAULT NULL,
  `queue_id` INT DEFAULT NULL,
  `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_ccr_campaign` (`campaign_id`),
  CONSTRAINT `fk_ccr_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `communication_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `communication_webhook_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `provider` VARCHAR(20) NOT NULL DEFAULT 'meta',
  `event_type` VARCHAR(50) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `processed` TINYINT NOT NULL DEFAULT 0,
  `processed_at` DATETIME DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_cwe_processed` (`processed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
