-- ==============================================================================
-- Rollback 65: PEPP Updates Advanced Visitor Location & Interaction Analytics
-- Module: PEPP Updates Public & Admin Portal
-- Date: October 2026
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Drop updates_clicks table
DROP TABLE IF EXISTS `updates_clicks`;

-- 2. Drop added columns from updates_visits safely
DELIMITER $$
DROP PROCEDURE IF EXISTS `pepp_drop_column_if_exists`$$
CREATE PROCEDURE `pepp_drop_column_if_exists`(
    IN target_table VARCHAR(100),
    IN target_column VARCHAR(100)
)
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = target_table
      AND COLUMN_NAME = target_column;
      
    IF col_count > 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', target_table, '` DROP COLUMN `', target_column, '`');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL pepp_drop_column_if_exists('updates_visits', 'session_id');
CALL pepp_drop_column_if_exists('updates_visits', 'ip_address');
CALL pepp_drop_column_if_exists('updates_visits', 'latitude');
CALL pepp_drop_column_if_exists('updates_visits', 'longitude');
CALL pepp_drop_column_if_exists('updates_visits', 'accuracy');
CALL pepp_drop_column_if_exists('updates_visits', 'location_status');

DROP PROCEDURE IF EXISTS `pepp_drop_column_if_exists`;

SET FOREIGN_KEY_CHECKS = 1;
