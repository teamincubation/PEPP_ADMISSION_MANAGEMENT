-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 32 : STUDY PLAN ACTIVITY PERSISTENCE & INTEGRITY
-- Migration #32 (Idempotent for Hostinger MySQL/MariaDB)
-- Date: 2026-08-25
-- ============================================================================

-- Stored procedure to safely add columns to an existing table
DROP PROCEDURE IF EXISTS AddColumnSafe;
DELIMITER //
CREATE PROCEDURE AddColumnSafe(
    IN tableName VARCHAR(100),
    IN columnName VARCHAR(100),
    IN colDef VARCHAR(500)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
          AND table_name = tableName 
          AND column_name = columnName
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tableName, '` ADD COLUMN `', columnName, '` ', colDef);
        PREPARE stmt FROM @s;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Stored procedure to safely add indexes to an existing table
DROP PROCEDURE IF EXISTS AddIndexSafe;
DELIMITER //
CREATE PROCEDURE AddIndexSafe(
    IN tableName VARCHAR(100),
    IN indexName VARCHAR(100),
    IN indexDef VARCHAR(500)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics 
        WHERE table_schema = DATABASE() 
          AND table_name = tableName 
          AND index_name = indexName
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tableName, '` ADD INDEX `', indexName, '` ', indexDef);
        PREPARE stmt FROM @s;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- 1. Alter study_plan_activities table to add permanent UID and soft-delete columns
CALL AddColumnSafe('study_plan_activities', 'activity_uid', 'VARCHAR(100) DEFAULT NULL');
CALL AddColumnSafe('study_plan_activities', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL AddColumnSafe('study_plan_activities', 'deleted_at', 'DATETIME DEFAULT NULL');
CALL AddColumnSafe('study_plan_activities', 'deleted_by', 'VARCHAR(100) DEFAULT NULL');
CALL AddColumnSafe('study_plan_activities', 'deletion_reason', 'TEXT DEFAULT NULL');

-- 2. Alter study_plan_analytics table to add completion snapshots and permanent UID tracking
CALL AddColumnSafe('study_plan_analytics', 'activity_uid', 'VARCHAR(100) DEFAULT NULL');
CALL AddColumnSafe('study_plan_analytics', 'activity_title_snapshot', 'VARCHAR(255) DEFAULT NULL');
CALL AddColumnSafe('study_plan_analytics', 'activity_type_snapshot', 'VARCHAR(100) DEFAULT NULL');
CALL AddColumnSafe('study_plan_analytics', 'activity_date_snapshot', 'DATE DEFAULT NULL');
CALL AddColumnSafe('study_plan_analytics', 'day_number_snapshot', 'INT DEFAULT NULL');
CALL AddColumnSafe('study_plan_analytics', 'chapter_snapshot', 'VARCHAR(255) DEFAULT NULL');
CALL AddColumnSafe('study_plan_analytics', 'subject_snapshot', 'VARCHAR(255) DEFAULT NULL');
CALL AddColumnSafe('study_plan_analytics', 'topic_snapshot', 'VARCHAR(255) DEFAULT NULL');

-- 3. Create study_plan_activity_versions table for chronological revision history
CREATE TABLE IF NOT EXISTS `study_plan_activity_versions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `activity_id` INT NOT NULL,
  `activity_uid` VARCHAR(100) NOT NULL,
  `study_plan_id` INT NOT NULL,
  `version_number` INT NOT NULL,
  `activity_date` DATE NOT NULL,
  `day_number` INT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `chapter` VARCHAR(255) DEFAULT NULL,
  `subject` VARCHAR(255) DEFAULT NULL,
  `topic` VARCHAR(255) DEFAULT NULL,
  `subtopic` VARCHAR(255) DEFAULT NULL,
  `activity_title` VARCHAR(255) NOT NULL,
  `activity_description` TEXT DEFAULT NULL,
  `activity_type` VARCHAR(100) NOT NULL,
  `faculty` VARCHAR(255) DEFAULT NULL,
  `mentor` VARCHAR(255) DEFAULT NULL,
  `estimated_duration` INT DEFAULT NULL,
  `priority` VARCHAR(50) NOT NULL DEFAULT 'medium',
  `difficulty_level` VARCHAR(50) NOT NULL DEFAULT 'medium',
  `resource_links` TEXT DEFAULT NULL,
  `custom_activity_badge` VARCHAR(100) DEFAULT NULL,
  `custom_activity_color` VARCHAR(50) DEFAULT NULL,
  `custom_activity_icon` VARCHAR(100) DEFAULT NULL,
  `created_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `change_type` VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Create indexes safely
CALL AddIndexSafe('study_plan_activities', 'idx_spa_uid', '(activity_uid)');
CALL AddIndexSafe('study_plan_activities', 'idx_spa_plan_date', '(study_plan_id, activity_date)');
CALL AddIndexSafe('study_plan_analytics', 'idx_anal_uid', '(activity_uid)');
-- Fix error #1089: activity_uid is VARCHAR(100), so activity_uid(191) is invalid.
CALL AddIndexSafe('study_plan_analytics', 'idx_anal_email_uid', '(student_email(191), activity_uid)');

-- 5. Add version column and soft delete to study_plans
CALL AddColumnSafe('study_plans', 'version', 'INT NOT NULL DEFAULT 1');
CALL AddColumnSafe('study_plans', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL AddColumnSafe('study_plans', 'deleted_at', 'DATETIME DEFAULT NULL');
CALL AddColumnSafe('study_plans', 'deleted_by', 'VARCHAR(100) DEFAULT NULL');
CALL AddColumnSafe('study_plans', 'deletion_reason', 'TEXT DEFAULT NULL');
CALL AddIndexSafe('study_plans', 'idx_study_plans_deleted', '(is_deleted)');

-- 6. Add soft delete to study_plan_assignments
CALL AddColumnSafe('study_plan_assignments', 'is_deleted', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL AddColumnSafe('study_plan_assignments', 'deleted_at', 'DATETIME DEFAULT NULL');
CALL AddColumnSafe('study_plan_assignments', 'deleted_by', 'VARCHAR(100) DEFAULT NULL');
CALL AddIndexSafe('study_plan_assignments', 'idx_assignments_deleted', '(is_deleted)');

-- Clean up stored procedures
DROP PROCEDURE IF EXISTS AddColumnSafe;
DROP PROCEDURE IF EXISTS AddIndexSafe;
