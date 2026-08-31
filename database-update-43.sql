-- ====================================================================
-- PEPP Learning — Database Update 43: Task Reminders Soft-Delete Schema
-- Idempotent schema migration for MySQL 5.7+ / 8.0+ / MariaDB
-- (Also automatically self-healed by config/database.php and includes/reminders_helper.php)
-- ====================================================================

SET @dbname = DATABASE();

-- 1. deleted_at
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'deleted_at');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `deleted_at` DATETIME NULL', 'SELECT \'Column deleted_at already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. deleted_by_admin_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'deleted_by_admin_id');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `deleted_by_admin_id` INT NULL', 'SELECT \'Column deleted_by_admin_id already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. deleted_by_username
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'deleted_by_username');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `deleted_by_username` VARCHAR(100) NULL', 'SELECT \'Column deleted_by_username already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Ensure status column allows 'deleted' (VARCHAR(50) instead of restrictive ENUM)
SET @status_type = (SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'status');
SET @stmt = IF(@status_type = 'enum', 'ALTER TABLE `reminders` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT \'pending\'', 'SELECT \'Status column is already non-enum\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Safe Index for status
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND INDEX_NAME = 'idx_rem_status');
SET @stmt = IF(@idx_exists = 0, 'ALTER TABLE `reminders` ADD INDEX `idx_rem_status` (`status`)', 'SELECT \'Index idx_rem_status already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. Safe Index for deleted_at
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND INDEX_NAME = 'idx_rem_deleted');
SET @stmt = IF(@idx_exists = 0, 'ALTER TABLE `reminders` ADD INDEX `idx_rem_deleted` (`deleted_at`)', 'SELECT \'Index idx_rem_deleted already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
