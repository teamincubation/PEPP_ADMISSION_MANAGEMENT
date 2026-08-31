-- ====================================================================
-- PEPP Learning — Database Update 42: Recurring Tasks Engine Schema
-- Idempotent schema migration for MySQL 5.7+ / 8.0+ / MariaDB
-- (Also automatically self-healed by config/database.php)
-- ====================================================================

-- 1. Add recurrence fields to reminders table if missing
SET @dbname = DATABASE();

-- recurrence_type
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'recurrence_type');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `recurrence_type` VARCHAR(20) NOT NULL DEFAULT \'none\'', 'SELECT \'Column recurrence_type already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- recurrence_weekdays
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'recurrence_weekdays');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `recurrence_weekdays` VARCHAR(50) NULL', 'SELECT \'Column recurrence_weekdays already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- recurrence_month_days
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'recurrence_month_days');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `recurrence_month_days` VARCHAR(100) NULL', 'SELECT \'Column recurrence_month_days already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- recurrence_start_date
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'recurrence_start_date');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `recurrence_start_date` DATE NULL', 'SELECT \'Column recurrence_start_date already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- recurrence_end_date
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'recurrence_end_date');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `recurrence_end_date` DATE NULL', 'SELECT \'Column recurrence_end_date already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- recurrence_series_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'recurrence_series_id');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `recurrence_series_id` INT NULL', 'SELECT \'Column recurrence_series_id already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- recurrence_stopped_at
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'recurrence_stopped_at');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `recurrence_stopped_at` DATETIME NULL', 'SELECT \'Column recurrence_stopped_at already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- occurrence_date
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'occurrence_date');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `occurrence_date` DATE NULL', 'SELECT \'Column occurrence_date already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- is_series_parent
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'is_series_parent');
SET @stmt = IF(@col_exists = 0, 'ALTER TABLE `reminders` ADD COLUMN `is_series_parent` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT \'Column is_series_parent already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Indexes and Unique Constraints
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND INDEX_NAME = 'idx_rem_series');
SET @stmt = IF(@idx_exists = 0, 'ALTER TABLE `reminders` ADD INDEX `idx_rem_series` (`recurrence_series_id`)', 'SELECT \'Index idx_rem_series already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND INDEX_NAME = 'idx_rem_occurrence');
SET @stmt = IF(@idx_exists = 0, 'ALTER TABLE `reminders` ADD INDEX `idx_rem_occurrence` (`occurrence_date`)', 'SELECT \'Index idx_rem_occurrence already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND INDEX_NAME = 'idx_rem_parent');
SET @stmt = IF(@idx_exists = 0, 'ALTER TABLE `reminders` ADD INDEX `idx_rem_parent` (`is_series_parent`, `recurrence_type`)', 'SELECT \'Index idx_rem_parent already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'reminders' AND INDEX_NAME = 'uniq_series_occurrence');
SET @stmt = IF(@idx_exists = 0, 'ALTER TABLE `reminders` ADD UNIQUE KEY `uniq_series_occurrence` (`recurrence_series_id`, `occurrence_date`)', 'SELECT \'Unique key uniq_series_occurrence already exists\'');
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
