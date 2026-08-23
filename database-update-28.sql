-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 28 : TEST RESULT CARDS MODULE
-- Migration #28
-- Date: 2026-08-23
-- ============================================================================

CREATE TABLE IF NOT EXISTS `test_result_cards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `academic_year` VARCHAR(50) NOT NULL,
    `course_id` INT NOT NULL,
    `study_plan_id` INT NOT NULL,
    `activity_id` INT NOT NULL,
    `template_id` INT NOT NULL,
    `design_title` VARCHAR(255) NOT NULL,
    `output_format` VARCHAR(10) NULL,
    `output_file` VARCHAR(255) NULL,
    `student_rank_mappings` TEXT NULL,
    `design_config` LONGTEXT NOT NULL,
    `created_by` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_trc_activity` (`activity_id`),
    KEY `idx_trc_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
