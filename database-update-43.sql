-- ====================================================================
-- PEPP Learning — Database Update 43: Task Reminders Soft-Delete Schema
-- Idempotent schema migration for MySQL 5.7+ / 8.0+ / MariaDB
-- (Also automatically self-healed by includes/reminders_helper.php)
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
