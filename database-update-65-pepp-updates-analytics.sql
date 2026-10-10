-- ==============================================================================
-- Migration 65: PEPP Updates Advanced Visitor Location & Interaction Analytics
-- Module: PEPP Updates Public & Admin Portal
-- Engine: MySQL 8.0+ / MariaDB 10.4+ / InnoDB
-- Character Set: utf8mb4 / utf8mb4_unicode_ci
-- Date: October 2026
--
-- Safety & Idempotency:
-- - Uses CREATE TABLE IF NOT EXISTS for updates_clicks
-- - Uses a self-cleaning stored procedure for safe, repeatable ALTER TABLE additions
-- - Foreign key uses ON DELETE SET NULL to preserve click stats if a post is deleted
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Create updates_clicks Table
CREATE TABLE IF NOT EXISTS `updates_clicks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `post_id` INT DEFAULT NULL,
  `action_name` VARCHAR(100) NOT NULL,
  `button_name` VARCHAR(150) DEFAULT NULL,
  `target_url` VARCHAR(500) DEFAULT NULL,
  `session_id` VARCHAR(64) DEFAULT NULL,
  `ip_hash` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_uc_post` (`post_id`),
  KEY `idx_uc_action` (`action_name`),
  KEY `idx_uc_created_at` (`created_at`),
  CONSTRAINT `fk_uc_post` FOREIGN KEY (`post_id`) REFERENCES `updates_posts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Idempotent Column Additions to updates_visits
DELIMITER $$
DROP PROCEDURE IF EXISTS `pepp_add_column_if_not_exists`$$
CREATE PROCEDURE `pepp_add_column_if_not_exists`(
    IN target_table VARCHAR(100),
    IN target_column VARCHAR(100),
    IN column_definition VARCHAR(255)
)
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = target_table
      AND COLUMN_NAME = target_column;
      
    IF col_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', target_table, '` ADD COLUMN `', target_column, '` ', column_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL pepp_add_column_if_not_exists('updates_visits', 'session_id', 'VARCHAR(64) DEFAULT NULL AFTER `referer`');
CALL pepp_add_column_if_not_exists('updates_visits', 'ip_address', 'VARCHAR(45) DEFAULT NULL AFTER `ip_hash`');
CALL pepp_add_column_if_not_exists('updates_visits', 'latitude', 'DECIMAL(10, 7) DEFAULT NULL AFTER `session_id`');
CALL pepp_add_column_if_not_exists('updates_visits', 'longitude', 'DECIMAL(10, 7) DEFAULT NULL AFTER `latitude`');
CALL pepp_add_column_if_not_exists('updates_visits', 'accuracy', 'DECIMAL(8, 2) DEFAULT NULL AFTER `longitude`');
CALL pepp_add_column_if_not_exists('updates_visits', 'location_status', "ENUM('prompt', 'granted', 'denied', 'unavailable') DEFAULT 'prompt' AFTER `accuracy`");

DROP PROCEDURE IF EXISTS `pepp_add_column_if_not_exists`;

SET FOREIGN_KEY_CHECKS = 1;
