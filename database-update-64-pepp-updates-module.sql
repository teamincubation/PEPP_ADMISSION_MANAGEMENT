-- ============================================================
-- PEPP Learning — PEPP Updates Module Schema
-- Migration: database-update-64-pepp-updates-module.sql
-- Module: PEPP Updates (https://updates.pepplearning.in)
-- Engine: InnoDB
-- Charset: utf8mb4 (Full Unicode: English, Malayalam, Hindi, Arabic, Emoji)
-- Safety: Completely isolated PEPP Updates tables only.
--         Zero alterations to existing ERP tables.
-- ============================================================

-- 1. Updates Categories
CREATE TABLE IF NOT EXISTS `updates_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_updates_categories_slug` (`slug`),
  KEY `idx_updates_categories_active_order` (`is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Updates Keywords (Belong to Categories)
CREATE TABLE IF NOT EXISTS `updates_keywords` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `keyword` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_category_keyword` (`category_id`, `keyword`),
  UNIQUE KEY `uq_category_slug` (`category_id`, `slug`),
  KEY `idx_uk_category` (`category_id`),
  CONSTRAINT `fk_uk_category` FOREIGN KEY (`category_id`) REFERENCES `updates_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Updates Posts (Content Model with Semantic Fields; Zero WhatsApp Delivery Fields)
CREATE TABLE IF NOT EXISTS `updates_posts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(280) NOT NULL,
  `short_description` TEXT DEFAULT NULL,
  `full_description` LONGTEXT NOT NULL,
  `banner_image` VARCHAR(500) DEFAULT NULL,
  `action_button_text` VARCHAR(100) DEFAULT NULL,
  `action_button_url` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('draft', 'scheduled', 'published', 'expired', 'archived') NOT NULL DEFAULT 'draft',
  `publish_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `updated_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_updates_posts_slug` (`slug`),
  KEY `idx_updates_posts_status_publish_expires` (`status`, `publish_at`, `expires_at`),
  KEY `idx_updates_posts_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Updates Post Categories (Junction)
-- Safety: post deletion cascades junction row. Category deletion RESTRICTED to protect in-use categories.
CREATE TABLE IF NOT EXISTS `updates_post_categories` (
  `post_id` INT NOT NULL,
  `category_id` INT NOT NULL,
  PRIMARY KEY (`post_id`, `category_id`),
  KEY `idx_upc_category` (`category_id`),
  CONSTRAINT `fk_upc_post` FOREIGN KEY (`post_id`) REFERENCES `updates_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_upc_category` FOREIGN KEY (`category_id`) REFERENCES `updates_categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Updates Post Keywords (Junction)
CREATE TABLE IF NOT EXISTS `updates_post_keywords` (
  `post_id` INT NOT NULL,
  `keyword_id` INT NOT NULL,
  PRIMARY KEY (`post_id`, `keyword_id`),
  KEY `idx_upk_keyword` (`keyword_id`),
  CONSTRAINT `fk_upk_post` FOREIGN KEY (`post_id`) REFERENCES `updates_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_upk_keyword` FOREIGN KEY (`keyword_id`) REFERENCES `updates_keywords` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Updates Subscribers (Privacy Hardened: Zero raw IP, no email, no district, no user_agent)
CREATE TABLE IF NOT EXISTS `updates_subscribers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `phone` VARCHAR(30) NOT NULL,
  `name` VARCHAR(150) DEFAULT NULL,
  `status` ENUM('active', 'stopped', 'unsubscribed', 'suppressed') NOT NULL DEFAULT 'active',
  `preferred_language` VARCHAR(10) NOT NULL DEFAULT 'en',
  `subscribed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `stopped_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_updates_subscribers_phone` (`phone`),
  KEY `idx_updates_subscribers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Updates Subscriber Categories (Junction)
CREATE TABLE IF NOT EXISTS `updates_subscriber_categories` (
  `subscriber_id` INT NOT NULL,
  `category_id` INT NOT NULL,
  PRIMARY KEY (`subscriber_id`, `category_id`),
  KEY `idx_usc_category` (`category_id`),
  CONSTRAINT `fk_usc_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `updates_subscribers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_usc_category` FOREIGN KEY (`category_id`) REFERENCES `updates_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Updates Subscriber Events (Consent & Audit Trail: ON DELETE RESTRICT protects audit immutability)
CREATE TABLE IF NOT EXISTS `updates_subscriber_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `subscriber_id` INT NOT NULL,
  `event_type` ENUM('SUBSCRIBED', 'STOPPED', 'RESUBSCRIBED', 'CATEGORY_CHANGED', 'PROFILE_UPDATED', 'SUPPRESSED') NOT NULL,
  `details` TEXT DEFAULT NULL,
  `source` VARCHAR(50) NOT NULL DEFAULT 'web',
  `ip_hash` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_use_subscriber_event` (`subscriber_id`, `event_type`),
  KEY `idx_use_created_at` (`created_at`),
  CONSTRAINT `fk_use_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `updates_subscribers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Updates Delivery Campaigns (Authoritative WhatsApp Broadcast Entity)
-- WhatsApp broadcast isolation: sender_account_id defaults to 3 (Account 3).
CREATE TABLE IF NOT EXISTS `updates_delivery_campaigns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `post_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `template_name` VARCHAR(100) NOT NULL,
  `target_category_id` INT DEFAULT NULL,
  `sender_account_id` INT NOT NULL DEFAULT 3,
  `status` ENUM('draft', 'scheduled', 'processing', 'completed', 'paused', 'cancelled', 'failed') NOT NULL DEFAULT 'draft',
  `scheduled_at` DATETIME DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `total_recipients` INT NOT NULL DEFAULT 0,
  `sent_count` INT NOT NULL DEFAULT 0,
  `delivered_count` INT NOT NULL DEFAULT 0,
  `read_count` INT NOT NULL DEFAULT 0,
  `failed_count` INT NOT NULL DEFAULT 0,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_udc_post` (`post_id`),
  KEY `idx_udc_status_sched` (`status`, `scheduled_at`),
  KEY `idx_udc_sender_account` (`sender_account_id`),
  KEY `idx_udc_target_category` (`target_category_id`),
  CONSTRAINT `fk_udc_post` FOREIGN KEY (`post_id`) REFERENCES `updates_posts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_udc_target_category` FOREIGN KEY (`target_category_id`) REFERENCES `updates_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Updates Delivery Recipients (Per-Subscriber Delivery Records)
-- Safety: Subscriber deletion is RESTRICTED to preserve delivery audit trails.
CREATE TABLE IF NOT EXISTS `updates_delivery_recipients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT NOT NULL,
  `subscriber_id` INT NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `status` ENUM('pending', 'queued', 'sent', 'delivered', 'read', 'failed', 'cancelled', 'skipped') NOT NULL DEFAULT 'pending',
  `queue_id` INT DEFAULT NULL,
  `message_id` VARCHAR(255) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_udr_campaign_subscriber` (`campaign_id`, `subscriber_id`),
  KEY `idx_udr_queue_id` (`queue_id`),
  KEY `idx_udr_status` (`status`),
  CONSTRAINT `fk_udr_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `updates_delivery_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_udr_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `updates_subscribers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Updates Visits (Privacy-Conscious Daily Hashed Analytics)
CREATE TABLE IF NOT EXISTS `updates_visits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `post_id` INT DEFAULT NULL,
  `ip_hash` VARCHAR(64) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `referer` VARCHAR(500) DEFAULT NULL,
  `visit_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_uv_post_date` (`post_id`, `visit_date`),
  KEY `idx_uv_date` (`visit_date`),
  CONSTRAINT `fk_uv_post` FOREIGN KEY (`post_id`) REFERENCES `updates_posts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Updates Settings
CREATE TABLE IF NOT EXISTS `updates_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_updates_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Default Settings
INSERT INTO `updates_settings` (`setting_key`, `setting_value`, `description`)
VALUES ('new_label_duration_days', '7', 'Duration in days for an update to display the NEW badge')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
