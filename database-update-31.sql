-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 31 : STUDENT COURSE MIGRATION HISTORY
-- Migration #31
-- Date: 2026-08-25
-- ============================================================================

CREATE TABLE IF NOT EXISTS `student_course_migrations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(50) NOT NULL,
    `old_course` VARCHAR(255) NOT NULL,
    `old_course_id` INT NULL,
    `old_course_fee` DECIMAL(10,2) NOT NULL,
    `new_course` VARCHAR(255) NOT NULL,
    `new_course_id` INT NULL,
    `new_course_fee` DECIMAL(10,2) NOT NULL,
    `payment_plan` VARCHAR(50) NOT NULL,
    `paid_amount_at_migration` DECIMAL(10,2) NOT NULL,
    `outstanding_before` DECIMAL(10,2) NOT NULL,
    `outstanding_after` DECIMAL(10,2) NOT NULL,
    `upgrade_amount` DECIMAL(10,2) NOT NULL,
    `migration_reason` TEXT NOT NULL,
    `migrated_by` VARCHAR(100) NOT NULL,
    `migrated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` VARCHAR(30) NOT NULL DEFAULT 'completed',
    KEY `idx_scm_uid` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
