-- ==============================================================================
-- Migration 66: PEPP Updates Ad Management & Lead Capture Schema
-- Module: PEPP Updates (https://updates.pepplearning.in)
-- Engine: MySQL 8.0+ / MariaDB 10.4+ / InnoDB
-- Character Set: utf8mb4 / utf8mb4_unicode_ci
-- Date: October 2026
--
-- Safety & Idempotency:
-- - Uses CREATE TABLE IF NOT EXISTS for all 4 isolated tables
-- - Zero alteration to existing core ERP tables (leads, users, pepp_courses)
-- - Foreign keys use safe CASCADE on ad child tables and SET NULL on course/post
-- - Global auto-convert setting defaults to OFF ('0')
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Updates Ads (Entity Definition & Targeting Rules)
CREATE TABLE IF NOT EXISTS `updates_ads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL COMMENT 'Internal reference title',
  `slug` VARCHAR(255) NOT NULL COMMENT 'URL/tracking slug',
  `headline` VARCHAR(255) DEFAULT NULL COMMENT 'Public modal title',
  `subheadline` TEXT DEFAULT NULL COMMENT 'Modal subtitle or bullet points',
  `image_url` VARCHAR(500) DEFAULT NULL COMMENT 'Banner or promotional graphic',
  `button_text` VARCHAR(100) NOT NULL DEFAULT 'Get More Info' COMMENT 'Submit CTA label',
  `target_all_posts` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=All published updates, 0=Selected posts',
  `popup_delay_seconds` INT NOT NULL DEFAULT 15 COMMENT 'Active visible-page delay in seconds before popup triggers (1-600s)',
  `auto_convert_crm` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=Manual review only, 1=Auto-convert to CRM lead (requires global setting ON)',
  `target_course_id` INT DEFAULT NULL COMMENT 'Canonical course mapping to pepp_courses.id',
  `status` ENUM('draft', 'active', 'paused', 'archived') NOT NULL DEFAULT 'draft',
  `display_order` INT NOT NULL DEFAULT 0 COMMENT 'Sorting priority',
  `created_by` VARCHAR(100) DEFAULT NULL,
  `updated_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ua_slug` (`slug`),
  KEY `idx_ua_status` (`status`),
  KEY `idx_ua_course` (`target_course_id`),
  KEY `idx_ua_display_order` (`display_order`),
  KEY `idx_ua_created_at` (`created_at`),
  CONSTRAINT `chk_ua_popup_delay` CHECK (`popup_delay_seconds` >= 1),
  CONSTRAINT `fk_ua_course` FOREIGN KEY (`target_course_id`) REFERENCES `pepp_courses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Updates Ad Posts Junction (Specific Post Targeting when target_all_posts = 0)
CREATE TABLE IF NOT EXISTS `updates_ad_posts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ad_id` INT NOT NULL,
  `post_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_uap_ad_post` (`ad_id`, `post_id`),
  KEY `idx_uap_post` (`post_id`),
  CONSTRAINT `fk_uap_ad` FOREIGN KEY (`ad_id`) REFERENCES `updates_ads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uap_post` FOREIGN KEY (`post_id`) REFERENCES `updates_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Updates Ad Fields (Ordered Custom Field Definitions for Popup Form)
CREATE TABLE IF NOT EXISTS `updates_ad_fields` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ad_id` INT NOT NULL,
  `field_name` VARCHAR(64) NOT NULL COMMENT 'Programmatic field key (e.g. qualification, district)',
  `field_label` VARCHAR(128) NOT NULL COMMENT 'Display label',
  `field_type` ENUM('text', 'tel', 'email', 'select', 'textarea') NOT NULL DEFAULT 'text',
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `field_options` TEXT DEFAULT NULL COMMENT 'JSON array or newline options for select dropdowns',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_uaf_ad_field` (`ad_id`, `field_name`),
  KEY `idx_uaf_ad_sort` (`ad_id`, `sort_order`),
  CONSTRAINT `fk_uaf_ad` FOREIGN KEY (`ad_id`) REFERENCES `updates_ads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Updates Ad Leads (Dedicated Ad Lead Capture & Conversion State)
CREATE TABLE IF NOT EXISTS `updates_ad_leads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ad_id` INT NOT NULL,
  `post_id` INT DEFAULT NULL COMMENT 'Originating update post where student submitted popup',
  `name` VARCHAR(150) NOT NULL COMMENT 'Full name',
  `phone` VARCHAR(25) NOT NULL COMMENT 'Normalized contact number',
  `course_id` INT DEFAULT NULL COMMENT 'Canonical course mapping to pepp_courses.id',
  `submitted_data` JSON DEFAULT NULL COMMENT 'Structured payload of all submitted form values',
  `ip_hash` VARCHAR(64) DEFAULT NULL COMMENT 'Salted hash for abuse prevention without raw IP storage',
  `status` ENUM('new', 'converted', 'failed', 'blocked') NOT NULL DEFAULT 'new' COMMENT 'new=Pending conversion, converted=CRM lead created, failed=Error, blocked=Duplicate/Suppressed',
  `converted_lead_id` INT DEFAULT NULL COMMENT 'Linked CRM leads.id when conversion succeeds',
  `conversion_notes` TEXT DEFAULT NULL COMMENT 'Detailed log of conversion attempt or duplicate match',
  `conversion_attempted_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_ual_ad_id` (`ad_id`),
  KEY `idx_ual_post_id` (`post_id`),
  KEY `idx_ual_phone` (`phone`),
  KEY `idx_ual_status` (`status`),
  KEY `idx_ual_course_id` (`course_id`),
  KEY `idx_ual_converted_lead_id` (`converted_lead_id`),
  KEY `idx_ual_created_at` (`created_at`),
  CONSTRAINT `fk_ual_ad` FOREIGN KEY (`ad_id`) REFERENCES `updates_ads` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ual_post` FOREIGN KEY (`post_id`) REFERENCES `updates_posts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ual_course` FOREIGN KEY (`course_id`) REFERENCES `pepp_courses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seed Global Default Setting (Default OFF)
INSERT INTO `updates_settings` (`setting_key`, `setting_value`, `description`, `created_at`, `updated_at`)
VALUES ('ad_leads_auto_convert_enabled', '0', 'Global toggle for auto-converting Ad Leads into CRM Leads (0=OFF, 1=ON)', NOW(), NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
