-- database-update-57.sql
-- PEPP Learning ERP: Bank Credential Visibility Permissions on admins table
-- Idempotent schema migration

DELIMITER $$

DROP PROCEDURE IF EXISTS apply_update_57$$
CREATE PROCEDURE apply_update_57()
BEGIN
    -- 1. Ensure can_view_bank_credentials column exists on admins
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'admins'
          AND column_name = 'can_view_bank_credentials'
    ) THEN
        ALTER TABLE `admins` ADD COLUMN `can_view_bank_credentials` TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    -- 2. Ensure can_copy_bank_credentials column exists on admins
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'admins'
          AND column_name = 'can_copy_bank_credentials'
    ) THEN
        ALTER TABLE `admins` ADD COLUMN `can_copy_bank_credentials` TINYINT(1) NOT NULL DEFAULT 0;
    END IF;

    -- 3. Super Admin role automatically gets both bank permissions
    UPDATE `admins`
    SET `can_view_bank_credentials` = 1,
        `can_copy_bank_credentials` = 1
    WHERE `role` = 'super_admin';

END$$

DELIMITER ;

CALL apply_update_57();
DROP PROCEDURE IF EXISTS apply_update_57;
