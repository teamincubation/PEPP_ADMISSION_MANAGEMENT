-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 36 : STUDY ACTIVITY SUBJECT → TOPIC MIGRATION
-- Migration #36 (Idempotent for Hostinger MySQL/MariaDB)
-- Date: 2026-08-28
-- ============================================================================

-- Stored procedure to safely drop columns from an existing table
DROP PROCEDURE IF EXISTS DropColumnSafe;
DELIMITER //
CREATE PROCEDURE DropColumnSafe(
    IN tableName VARCHAR(100),
    IN columnName VARCHAR(100)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
          AND table_name = tableName 
          AND column_name = columnName
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tableName, '` DROP COLUMN `', columnName, '`');
        PREPARE stmt FROM @s;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- 1. Migrate existing Subject values into Topic where subject is not null/empty
UPDATE `study_plan_activities` 
SET `topic` = `subject` 
WHERE (`subject` IS NOT NULL AND `subject` != '');

-- 2. Drop obsolete Study Activity metadata columns: subtopic, mentor, subject
CALL DropColumnSafe('study_plan_activities', 'subtopic');
CALL DropColumnSafe('study_plan_activities', 'mentor');
CALL DropColumnSafe('study_plan_activities', 'subject');

-- 3. Migrate and clean study_plan_activity_versions if the table exists
UPDATE `study_plan_activity_versions` 
SET `topic` = `subject` 
WHERE (`subject` IS NOT NULL AND `subject` != '');

CALL DropColumnSafe('study_plan_activity_versions', 'subtopic');
CALL DropColumnSafe('study_plan_activity_versions', 'mentor');
CALL DropColumnSafe('study_plan_activity_versions', 'subject');

-- Clean up helper procedure
DROP PROCEDURE IF EXISTS DropColumnSafe;
