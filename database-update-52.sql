-- ============================================================================
-- PEPP Learning ERP — Database Update 52
-- L&D Lecture Video Quality Assessment System Mode & Infrastructure
-- ============================================================================
--
-- PURPOSE:
-- 1. Adds `mode_key` and `is_system` to `ld_work_modes` to support permanent
--    system-controlled work modes alongside user-configurable modes.
-- 2. Seeds the permanent system mode:
--      Mode Name:       Lectures: Quality Assessment
--      System Key:      lecture_quality_assessment
--      Quantity Label:  Hour
--      Status:          active (permanent)
-- 3. Creates `ld_quality_assessment_reports` to store detailed quality assessment
--    report metadata, file hashes, calculated hours/charge, validation state,
--    AI analysis summary, and administrative review decisions.
-- 4. Creates `ld_quality_assessment_items` to record the normalized submitted
--    lecture assessments (15 standard variables, 4 quality aspects, durations).
-- 5. Creates `ld_quality_assessment_ai_reports` to store versioned AI analysis
--    reports, aspect-wise findings, patterns, recommendations, and reverification lists.
--
-- IDEMPOTENCY & SAFETY GUARANTEES:
-- - All operations execute via safe conditional checks against `information_schema`.
-- - No tables, columns, indexes, or existing records are ever removed or wiped.
-- - No existing normal L&D modes, tasks, topics, or payments are affected.
-- - Safe to re-run multiple times on MySQL/MariaDB with zero errors.
-- ============================================================================

DROP PROCEDURE IF EXISTS MigrateLectureQualityAssessment52;
DELIMITER //
CREATE PROCEDURE MigrateLectureQualityAssessment52()
BEGIN
    -- ── 1. EXTEND ld_work_modes TABLE ──────────────────────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ld_work_modes') THEN

        -- Add mode_key column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'ld_work_modes' AND column_name = 'mode_key'
        ) THEN
            ALTER TABLE `ld_work_modes`
            ADD COLUMN `mode_key` VARCHAR(50) DEFAULT NULL AFTER `mode_name`;
        END IF;

        -- Add is_system column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'ld_work_modes' AND column_name = 'is_system'
        ) THEN
            ALTER TABLE `ld_work_modes`
            ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0 AFTER `mode_key`;
        END IF;

        -- Add UNIQUE key on mode_key
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'ld_work_modes' AND index_name = 'uq_ld_work_modes_mode_key'
        ) THEN
            ALTER TABLE `ld_work_modes`
            ADD UNIQUE KEY `uq_ld_work_modes_mode_key` (`mode_key`);
        END IF;

        -- Seed or Update Permanent System Mode: Lectures: Quality Assessment
        -- Seeded with safe unconfigured rate 0.00 as per business requirement
        IF EXISTS (SELECT 1 FROM `ld_work_modes` WHERE `mode_key` = 'lecture_quality_assessment' OR `mode_name` = 'Lectures: Quality Assessment') THEN
            UPDATE `ld_work_modes`
            SET `mode_name` = 'Lectures: Quality Assessment',
                `mode_key` = 'lecture_quality_assessment',
                `is_system` = 1,
                `quantity_label` = 'Hour',
                `status` = 'active'
            WHERE `mode_key` = 'lecture_quality_assessment' OR `mode_name` = 'Lectures: Quality Assessment';
        ELSE
            INSERT INTO `ld_work_modes` (
                `mode_name`, `mode_key`, `is_system`, `quantity_label`, `charge_per_quantity`, `status`, `sort_order`
            ) VALUES (
                'Lectures: Quality Assessment', 'lecture_quality_assessment', 1, 'Hour', 0.00, 'active', 0
            );
        END IF;

    END IF;

    -- ── 2. CREATE ld_quality_assessment_reports TABLE ─────────────────────────
    CREATE TABLE IF NOT EXISTS `ld_quality_assessment_reports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `task_id` INT DEFAULT NULL,
        `parent_report_id` INT DEFAULT NULL,
        `version` INT NOT NULL DEFAULT 1,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `admin_id` INT NOT NULL,
        `admin_username` VARCHAR(100) NOT NULL,
        `admin_name` VARCHAR(255) NOT NULL,
        `course_id` INT NOT NULL,
        `course_name_snapshot` VARCHAR(255) NOT NULL,
        `report_reference` VARCHAR(50) NOT NULL,
        `original_filename` VARCHAR(255) NOT NULL,
        `stored_path` VARCHAR(255) NOT NULL,
        `file_type` VARCHAR(10) NOT NULL,
        `file_size` INT NOT NULL,
        `sha256_hash` CHAR(64) NOT NULL,
        `row_count` INT NOT NULL DEFAULT 0,
        `total_lecture_duration_minutes` INT NOT NULL DEFAULT 0,
        `total_assessment_minutes` INT NOT NULL DEFAULT 0,
        `total_assessment_hours` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        `hourly_rate_snapshot` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `calculated_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `validation_status` VARCHAR(20) NOT NULL DEFAULT 'passed',
        `validation_notes` TEXT DEFAULT NULL,
        `ai_status` VARCHAR(30) NOT NULL DEFAULT 'pending',
        `ai_overall_grade` TINYINT DEFAULT NULL,
        `ai_summary` TEXT DEFAULT NULL,
        `admin_review_status` VARCHAR(30) NOT NULL DEFAULT 'Pending Review',
        `admin_final_grade` TINYINT DEFAULT NULL,
        `admin_review_notes` TEXT DEFAULT NULL,
        `reviewed_by` VARCHAR(100) DEFAULT NULL,
        `reviewed_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        UNIQUE KEY `uq_lqa_ref` (`report_reference`),
        KEY `idx_lqa_task` (`task_id`),
        KEY `idx_lqa_parent` (`parent_report_id`),
        KEY `idx_lqa_active` (`is_active`),
        KEY `idx_lqa_admin` (`admin_id`),
        KEY `idx_lqa_course` (`course_id`),
        KEY `idx_lqa_hash` (`sha256_hash`),
        KEY `idx_lqa_review` (`admin_review_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Ensure versioning columns exist if table was previously created
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ld_quality_assessment_reports') THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'ld_quality_assessment_reports' AND column_name = 'version'
        ) THEN
            ALTER TABLE `ld_quality_assessment_reports`
            ADD COLUMN `version` INT NOT NULL DEFAULT 1 AFTER `task_id`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'ld_quality_assessment_reports' AND column_name = 'parent_report_id'
        ) THEN
            ALTER TABLE `ld_quality_assessment_reports`
            ADD COLUMN `parent_report_id` INT DEFAULT NULL AFTER `task_id`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'ld_quality_assessment_reports' AND column_name = 'is_active'
        ) THEN
            ALTER TABLE `ld_quality_assessment_reports`
            ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `version`;
        END IF;
    END IF;

    -- ── 3. CREATE ld_quality_assessment_items TABLE ───────────────────────────
    CREATE TABLE IF NOT EXISTS `ld_quality_assessment_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `report_id` INT NOT NULL,
        `source_row_number` INT NOT NULL,
        `chapter` VARCHAR(255) NOT NULL,
        `lecture_title` VARCHAR(255) NOT NULL,
        `language` VARCHAR(10) NOT NULL,
        `faculty_name` VARCHAR(255) NOT NULL,
        `lecture_duration_minutes` INT NOT NULL,
        `content_grade` TINYINT NOT NULL,
        `content_remark` TEXT DEFAULT NULL,
        `video_grade` TINYINT NOT NULL,
        `video_remark` TEXT DEFAULT NULL,
        `audio_grade` TINYINT NOT NULL,
        `audio_remark` TEXT DEFAULT NULL,
        `slide_grade` TINYINT NOT NULL,
        `slide_remark` TEXT DEFAULT NULL,
        `assessment_minutes` INT NOT NULL,
        `normalized_lecture_key` VARCHAR(500) NOT NULL,
        `created_at` DATETIME NOT NULL,
        KEY `idx_lqi_report` (`report_id`),
        KEY `idx_lqi_key` (`normalized_lecture_key`(191)),
        CONSTRAINT `fk_lqi_report` FOREIGN KEY (`report_id`) REFERENCES `ld_quality_assessment_reports` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- ── 4. CREATE ld_quality_assessment_ai_reports TABLE ──────────────────────
    CREATE TABLE IF NOT EXISTS `ld_quality_assessment_ai_reports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `report_id` INT NOT NULL,
        `provider` VARCHAR(50) NOT NULL,
        `model` VARCHAR(100) NOT NULL,
        `prompt_version` VARCHAR(50) NOT NULL DEFAULT 'v1',
        `overall_grade` VARCHAR(20) DEFAULT NULL,
        `summary` TEXT DEFAULT NULL,
        `aspect_analysis_json` LONGTEXT DEFAULT NULL,
        `recommendations_json` LONGTEXT DEFAULT NULL,
        `reverification_items_json` LONGTEXT DEFAULT NULL,
        `raw_structured_result` LONGTEXT DEFAULT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'completed',
        `error_message` TEXT DEFAULT NULL,
        `generated_at` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL,
        KEY `idx_lqar_report` (`report_id`),
        KEY `idx_lqar_status` (`status`),
        CONSTRAINT `fk_lqar_report` FOREIGN KEY (`report_id`) REFERENCES `ld_quality_assessment_reports` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

END //
DELIMITER ;

-- Execute the migration safely
CALL MigrateLectureQualityAssessment52();

-- Clean up the temporary procedure
DROP PROCEDURE IF EXISTS MigrateLectureQualityAssessment52;
