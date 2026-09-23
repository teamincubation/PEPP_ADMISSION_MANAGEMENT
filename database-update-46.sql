-- Database Migration 46: Student Birthday Reward System
-- Creates tables for birthday reward configuration, claim tracking, and notification idempotency.
--
-- Prerequisites:
--   - Migration 45 (instalment_details unique key) must be applied first.
--   - Backup database before executing.

-- Birthday reward settings (admin-configurable)
CREATE TABLE IF NOT EXISTS `birthday_reward_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `reward_title` VARCHAR(255) NOT NULL DEFAULT 'Birthday Reward',
    `reward_description` TEXT,
    `birthday_header_image` VARCHAR(500) DEFAULT NULL,
    `reward_voucher_image` VARCHAR(500) DEFAULT NULL,
    `coupon_code` VARCHAR(100) DEFAULT NULL,
    `valid_till` DATE DEFAULT NULL,
    `instructions` TEXT,
    `terms` TEXT,
    `claim_message` TEXT,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Birthday reward claim tracking (one claim per person identity per birthday date)
CREATE TABLE IF NOT EXISTS `birthday_reward_claims` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_identity` VARCHAR(255) NOT NULL,
    `student_id` VARCHAR(20) NOT NULL,
    `birthday_date` DATE NOT NULL,
    `reward_setting_id` INT DEFAULT NULL,
    `coupon_code` VARCHAR(100) DEFAULT NULL,
    `claimed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_person_birthday` (`person_identity`, `birthday_date`),
    KEY `idx_student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Birthday notification idempotency tracking (one notification per person identity per birthday date)
CREATE TABLE IF NOT EXISTS `birthday_notifications_sent` (
    `person_identity` VARCHAR(255) NOT NULL,
    `student_id` VARCHAR(20) NOT NULL,
    `birthday_date` DATE NOT NULL,
    `queue_id` INT DEFAULT NULL,
    `status` ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`person_identity`, `birthday_date`),
    KEY `idx_student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe migration for existing tables if created in an earlier preview without person_identity
DROP PROCEDURE IF EXISTS MigrateBirthdayTablesPersonDedup;
DELIMITER //
CREATE PROCEDURE MigrateBirthdayTablesPersonDedup()
BEGIN
    -- 1. Ensure person_identity in birthday_notifications_sent
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'birthday_notifications_sent') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_notifications_sent' AND column_name = 'person_identity') THEN
            ALTER TABLE `birthday_notifications_sent` ADD COLUMN `person_identity` VARCHAR(255) NULL FIRST;
            -- Backfill legacy records if any exist
            UPDATE `birthday_notifications_sent` SET `person_identity` = CONCAT('legacy:', `student_id`) WHERE `person_identity` IS NULL;
            ALTER TABLE `birthday_notifications_sent` MODIFY COLUMN `person_identity` VARCHAR(255) NOT NULL;
            -- Drop old primary key and add new composite primary key
            ALTER TABLE `birthday_notifications_sent` DROP PRIMARY KEY, ADD PRIMARY KEY (`person_identity`, `birthday_date`);
        END IF;
    END IF;

    -- 2. Ensure person_identity in birthday_reward_claims
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims') THEN
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'person_identity') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `person_identity` VARCHAR(255) NULL AFTER `id`;
            -- Backfill legacy records if any exist
            UPDATE `birthday_reward_claims` SET `person_identity` = CONCAT('legacy:', `student_id`) WHERE `person_identity` IS NULL;
            ALTER TABLE `birthday_reward_claims` MODIFY COLUMN `person_identity` VARCHAR(255) NOT NULL;
            -- Ensure unique constraint on (person_identity, birthday_date)
            ALTER TABLE `birthday_reward_claims` ADD UNIQUE KEY `unique_person_birthday` (`person_identity`, `birthday_date`);
        END IF;
    END IF;
END //
DELIMITER ;

CALL MigrateBirthdayTablesPersonDedup();
DROP PROCEDURE IF EXISTS MigrateBirthdayTablesPersonDedup;

-- Seed initial (inactive) reward settings row (if none exists)
INSERT INTO `birthday_reward_settings` (`reward_title`, `reward_description`, `is_active`)
SELECT 'PEPP Birthday Reward', 'A special birthday reward for our valued PEPP students!', 0
WHERE NOT EXISTS (SELECT 1 FROM `birthday_reward_settings` LIMIT 1);

-- Register birthday_greeting event mapping (template must be created & approved in Meta first)
INSERT INTO `communication_event_mappings` (`event_name`, `template_name`, `parameter_mappings`)
VALUES ('birthday_greeting', 'pepp_birthday_greeting', '{"1":{"type":"variable","value":"student_name"}}')
ON DUPLICATE KEY UPDATE `event_name` = `event_name`;
