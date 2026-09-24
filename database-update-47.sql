-- Database Migration 47: Student Birthday Reward System Phase 2
-- Introduces immutable reward versions, claim snapshots, permanent instruction tokens,
-- and post-claim WhatsApp communication event mapping.
--
-- Prerequisites:
--   - Migration 46 (birthday reward settings & claims) must be applied first.
--   - Backup database before executing.

-- 1. Birthday reward version history (immutable audit log of reward configurations)
CREATE TABLE IF NOT EXISTS `birthday_reward_versions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `version_number` INT NOT NULL,
    `reward_title` VARCHAR(255) NOT NULL DEFAULT 'Birthday Reward',
    `coupon_code` VARCHAR(100) DEFAULT NULL,
    `valid_till` DATE DEFAULT NULL,
    `reward_description` TEXT DEFAULT NULL,
    `instructions` MEDIUMTEXT DEFAULT NULL,
    `terms` MEDIUMTEXT DEFAULT NULL,
    `claim_message` MEDIUMTEXT DEFAULT NULL,
    `birthday_header_image` VARCHAR(500) DEFAULT NULL,
    `reward_voucher_image` VARCHAR(500) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` VARCHAR(100) NOT NULL DEFAULT 'system',
    `change_notes` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_brv_version` (`version_number`),
    KEY `idx_brv_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Stored procedure to safely extend birthday_reward_claims with immutable snapshot columns
DROP PROCEDURE IF EXISTS MigrateBirthdayClaimsSnapshotPhase2;
DELIMITER //
CREATE PROCEDURE MigrateBirthdayClaimsSnapshotPhase2()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims') THEN
        -- reward_version_id
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'reward_version_id') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `reward_version_id` INT DEFAULT NULL AFTER `reward_setting_id`;
        END IF;

        -- instruction_token
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'instruction_token') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `instruction_token` VARCHAR(64) DEFAULT NULL AFTER `reward_version_id`;
        END IF;

        -- coupon_valid_till
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'coupon_valid_till') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `coupon_valid_till` DATE DEFAULT NULL AFTER `coupon_code`;
        END IF;

        -- reward_title
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'reward_title') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `reward_title` VARCHAR(255) DEFAULT NULL AFTER `coupon_valid_till`;
        END IF;

        -- reward_description
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'reward_description') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `reward_description` TEXT DEFAULT NULL AFTER `reward_title`;
        END IF;

        -- instructions
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'instructions') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `instructions` MEDIUMTEXT DEFAULT NULL AFTER `reward_description`;
        END IF;

        -- terms
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'terms') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `terms` MEDIUMTEXT DEFAULT NULL AFTER `instructions`;
        END IF;

        -- claim_message
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'claim_message') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `claim_message` MEDIUMTEXT DEFAULT NULL AFTER `terms`;
        END IF;

        -- voucher_image
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'voucher_image') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `voucher_image` VARCHAR(500) DEFAULT NULL AFTER `claim_message`;
        END IF;

        -- claim_whatsapp_queue_id
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'claim_whatsapp_queue_id') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `claim_whatsapp_queue_id` INT DEFAULT NULL AFTER `claimed_at`;
        END IF;

        -- claim_whatsapp_status
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND column_name = 'claim_whatsapp_status') THEN
            ALTER TABLE `birthday_reward_claims` ADD COLUMN `claim_whatsapp_status` VARCHAR(50) NOT NULL DEFAULT 'not_queued' AFTER `claim_whatsapp_queue_id`;
        END IF;

        -- Ensure unique index on instruction_token
        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'birthday_reward_claims' AND index_name = 'idx_brc_instruction_token') THEN
            ALTER TABLE `birthday_reward_claims` ADD UNIQUE KEY `idx_brc_instruction_token` (`instruction_token`);
        END IF;
    END IF;
END //
DELIMITER ;

CALL MigrateBirthdayClaimsSnapshotPhase2();
DROP PROCEDURE IF EXISTS MigrateBirthdayClaimsSnapshotPhase2;

-- 3. Seed Version 1 from existing birthday_reward_settings if birthday_reward_versions is currently empty
INSERT INTO `birthday_reward_versions` (
    `version_number`, `reward_title`, `coupon_code`, `valid_till`,
    `reward_description`, `instructions`, `terms`, `claim_message`,
    `birthday_header_image`, `reward_voucher_image`, `is_active`,
    `created_by`, `change_notes`, `created_at`
)
SELECT
    1,
    COALESCE(s.`reward_title`, 'Birthday Reward'),
    s.`coupon_code`,
    s.`valid_till`,
    s.`reward_description`,
    s.`instructions`,
    s.`terms`,
    s.`claim_message`,
    s.`birthday_header_image`,
    s.`reward_voucher_image`,
    s.`is_active`,
    'system_seed',
    'Initial seed from birthday_reward_settings',
    NOW()
FROM `birthday_reward_settings` s
WHERE NOT EXISTS (SELECT 1 FROM `birthday_reward_versions` LIMIT 1)
ORDER BY s.`id` DESC
LIMIT 1;

-- 4. Register 'birthday_reward_claimed' event in communication_event_mappings if not present
-- Canonical Meta Template: pepp_birthday_reward_claimed
-- Language: en | Category: MARKETING | Header: None
-- Body variables: {{1}}=student_name, {{2}}=coupon_code, {{3}}=valid_until
-- Dynamic URL button: Read Instructions -> https://pepplearning.in/admissions/birthday-instructions.php/{{1}} (parameter: instruction_token)
INSERT INTO `communication_event_mappings` (`event_name`, `template_name`, `parameter_mappings`, `is_active`, `created_at`, `updated_at`)
SELECT 'birthday_reward_claimed', '', '[]', 0, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `communication_event_mappings` WHERE `event_name` = 'birthday_reward_claimed'
);
