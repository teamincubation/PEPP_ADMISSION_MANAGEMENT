-- ====================================================================
-- PEPP Learning — Database Update 41: Task Reminders & Accountability Module
-- Run ONCE in database. Safe to re-run (idempotent).
-- ====================================================================

-- 1. Task Types Management Table
CREATE TABLE IF NOT EXISTS `task_reminder_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_admin_id` INT NULL,
    `created_by_username` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_task_type_name` (`name`),
    INDEX `idx_trt_status_name` (`is_active`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Extend reminders Table with Task Accountability Fields
ALTER TABLE `reminders`
    ADD COLUMN IF NOT EXISTS `task_type_id` INT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `created_by_admin_id` INT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `created_by_username` VARCHAR(100) NULL AFTER `created_by_admin_id`,
    ADD COLUMN IF NOT EXISTS `assigned_by_admin_id` INT NULL AFTER `created_by_username`,
    ADD COLUMN IF NOT EXISTS `assigned_by_username` VARCHAR(100) NULL AFTER `assigned_by_admin_id`,
    ADD COLUMN IF NOT EXISTS `assigned_to_admin_id` INT NULL AFTER `assigned_by_username`,
    ADD COLUMN IF NOT EXISTS `assigned_to_username` VARCHAR(100) NULL AFTER `assigned_to_admin_id`,
    ADD COLUMN IF NOT EXISTS `assigned_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER `assigned_to_username`,
    ADD COLUMN IF NOT EXISTS `completed_by_admin_id` INT NULL AFTER `email_sent`,
    ADD COLUMN IF NOT EXISTS `completed_by_username` VARCHAR(100) NULL AFTER `completed_by_admin_id`,
    ADD COLUMN IF NOT EXISTS `latest_remarks` TEXT NULL AFTER `completed_at`,
    ADD COLUMN IF NOT EXISTS `last_status_updated_at` DATETIME NULL AFTER `latest_remarks`;

-- Modify status column to support standard lifecycles (pending, in_progress, completed, cancelled, dismissed)
ALTER TABLE `reminders` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'pending';

-- Add performance indexes to reminders
CREATE INDEX IF NOT EXISTS `idx_rem_type` ON `reminders` (`task_type_id`);
CREATE INDEX IF NOT EXISTS `idx_rem_assignee_status` ON `reminders` (`assigned_to_admin_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_rem_creator` ON `reminders` (`created_by_admin_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_rem_due_time` ON `reminders` (`remind_at`, `status`);

-- 3. Immutable Task Assignment History
CREATE TABLE IF NOT EXISTS `task_reminder_assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `assigned_by_admin_id` INT NULL,
    `assigned_by_username` VARCHAR(100) NULL,
    `assigned_to_admin_id` INT NULL,
    `assigned_to_username` VARCHAR(100) NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 1,
    INDEX `idx_tra_task` (`task_id`, `is_current`),
    INDEX `idx_tra_assignee` (`assigned_to_admin_id`, `is_current`),
    INDEX `idx_tra_assigned_by` (`assigned_by_admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Immutable Task Lifecycle & Status History
CREATE TABLE IF NOT EXISTS `task_reminder_status_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `event_type` VARCHAR(50) NOT NULL, -- CREATED, ASSIGNED, REASSIGNED, EDITED, STARTED, POSTPONED, COMPLETED, CANCELLED, REMARK_ADDED
    `old_status` VARCHAR(50) NULL,
    `new_status` VARCHAR(50) NULL,
    `changed_by_admin_id` INT NULL,
    `changed_by_username` VARCHAR(100) NULL,
    `remarks` TEXT NULL,
    `details_json` TEXT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_trsh_task_time` (`task_id`, `changed_at`),
    INDEX `idx_trsh_event` (`event_type`),
    INDEX `idx_trsh_changed_by` (`changed_by_admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Persistent Event-Keyed Task Notifications
CREATE TABLE IF NOT EXISTS `task_reminder_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `recipient_admin_id` INT NULL,
    `recipient_username` VARCHAR(100) NOT NULL,
    `sender_admin_id` INT NULL,
    `sender_username` VARCHAR(100) NULL,
    `notification_type` ENUM('TASK_ASSIGNED','TASK_DUE','TASK_OVERDUE','TASK_COMPLETED','TASK_REASSIGNED') NOT NULL,
    `event_key` VARCHAR(100) NOT NULL,
    `message` TEXT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `is_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read_at` DATETIME NULL,
    UNIQUE KEY `uniq_task_event_notif` (`task_id`, `recipient_username`, `notification_type`, `event_key`),
    INDEX `idx_trn_recipient_unread` (`recipient_username`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Seed Initial Task Types
INSERT IGNORE INTO `task_reminder_types` (`name`, `description`, `is_active`, `created_by_username`, `created_at`) VALUES
('Daily Task Reminder', 'Routine daily reminders and operational duties', 1, 'System', NOW()),
('Mentoring', 'Student academic mentoring, progress review and counseling sessions', 1, 'System', NOW()),
('Session Scheduling', 'Scheduling online batches, faculty lectures, and mega tests', 1, 'System', NOW()),
('Student Follow-up', 'Calling students regarding admission, attendance, and general queries', 1, 'System', NOW()),
('Payment Follow-up', 'Fee installment recovery, payment verification, and voucher review', 1, 'System', NOW()),
('Academic Task', 'Curriculum planning, question paper design, and study material uploads', 1, 'System', NOW()),
('Administrative Task', 'Office paperwork, certificate generation, and staff coordination', 1, 'System', NOW()),
('Meeting', 'Internal staff, academic committee, and management meetings', 1, 'System', NOW()),
('Documentation', 'Student records, onboarding verifications, and compliance filing', 1, 'System', NOW()),
('General Task', 'General administrative task and miscellaneous reminders', 1, 'System', NOW()),
('Other', 'Custom tasks not covered in standard categories', 1, 'System', NOW());

-- 7. In-Place Migration of Existing Reminders (Zero Data Loss)
-- Ensure 'General Task' ID is retrieved
SET @general_type_id = (SELECT `id` FROM `task_reminder_types` WHERE `name` = 'General Task' LIMIT 1);

-- Backfill task_type_id for legacy records
UPDATE `reminders` SET `task_type_id` = @general_type_id WHERE `task_type_id` IS NULL;

-- Backfill usernames and admin IDs on reminders
UPDATE `reminders` SET `created_by_username` = `created_by` WHERE `created_by_username` IS NULL AND `created_by` IS NOT NULL;
UPDATE `reminders` SET `assigned_to_username` = `assigned_to` WHERE `assigned_to_username` IS NULL AND `assigned_to` IS NOT NULL;
UPDATE `reminders` SET `assigned_by_username` = `created_by` WHERE `assigned_by_username` IS NULL AND `created_by` IS NOT NULL;
UPDATE `reminders` SET `completed_by_username` = `completed_by` WHERE `completed_by_username` IS NULL AND `completed_by` IS NOT NULL;

-- Backfill admin_ids by joining admins table
UPDATE `reminders` r JOIN `admins` a ON a.`username` = r.`created_by_username` SET r.`created_by_admin_id` = a.`id` WHERE r.`created_by_admin_id` IS NULL;
UPDATE `reminders` r JOIN `admins` a ON a.`username` = r.`assigned_by_username` SET r.`assigned_by_admin_id` = a.`id` WHERE r.`assigned_by_admin_id` IS NULL;
UPDATE `reminders` r JOIN `admins` a ON a.`username` = r.`assigned_to_username` SET r.`assigned_to_admin_id` = a.`id` WHERE r.`assigned_to_admin_id` IS NULL;
UPDATE `reminders` r JOIN `admins` a ON a.`username` = r.`completed_by_username` SET r.`completed_by_admin_id` = a.`id` WHERE r.`completed_by_admin_id` IS NULL;

-- Backfill initial assignment history for existing reminders that don't have one
INSERT INTO `task_reminder_assignments` (`task_id`, `assigned_by_admin_id`, `assigned_by_username`, `assigned_to_admin_id`, `assigned_to_username`, `assigned_at`, `is_current`)
SELECT r.`id`, r.`assigned_by_admin_id`, r.`assigned_by_username`, r.`assigned_to_admin_id`, r.`assigned_to_username`, r.`created_at`, 1
FROM `reminders` r
LEFT JOIN `task_reminder_assignments` tra ON tra.`task_id` = r.`id`
WHERE tra.`id` IS NULL;

-- Backfill initial status history for existing reminders
INSERT INTO `task_reminder_status_history` (`task_id`, `event_type`, `old_status`, `new_status`, `changed_by_admin_id`, `changed_by_username`, `remarks`, `changed_at`)
SELECT r.`id`, 'CREATED', NULL, r.`status`, r.`created_by_admin_id`, 'SYSTEM MIGRATION', 'Initial migration from legacy reminders', r.`created_at`
FROM `reminders` r
LEFT JOIN `task_reminder_status_history` trsh ON trsh.`task_id` = r.`id`
WHERE trsh.`id` IS NULL;
