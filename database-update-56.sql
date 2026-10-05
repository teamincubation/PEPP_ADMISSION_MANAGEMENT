-- ============================================================================
-- PEPP Learning ERP — Database Update 56
-- Staff architecture: per-application-type uniqueness + type-scoped custom fields
-- ============================================================================
--
-- PURPOSE:
-- 1. employees: replace the GLOBAL  UNIQUE(email)  (uq_emp_email) with
--    UNIQUE(email, application_for)  (uq_emp_email_type).
--    One person may legitimately hold Employee + Faculty + Intern records with the
--    same email, but the same email can never appear twice for the same type.
--    (Fixes: "Approval failed: ... 1062 Duplicate entry '...' for key 'uq_emp_email'")
-- 2. employee_custom_fields: add `application_for` (employee|faculty|intern).
--    Legacy fields are defaulted to 'employee'. No field or value is deleted.
-- 3. Supporting index on employees(application_for, status) for the isolation filters.
--
-- SAFETY / IDEMPOTENCY:
-- - Every step is guarded by an information_schema check; safe to re-run.
-- - The new scoped UNIQUE key is created BEFORE the old one is dropped, so
--   duplicate protection is never absent at any point.
-- - Because the old key was UNIQUE(email), no (email, application_for) duplicates can
--   exist; the pre-check below is still performed and, if anything is found, the old
--   key is KEPT and nothing is deleted.
-- - No rows are ever deleted or modified (other than defaulting the new column).
--
-- PRE-CHECK (informational, run manually if desired):
--   SELECT email, application_for, COUNT(*) FROM employees
--   GROUP BY email, application_for HAVING COUNT(*) > 1;
-- ============================================================================

DROP PROCEDURE IF EXISTS MigrateStaffTypeScoping56;
DELIMITER //
CREATE PROCEDURE MigrateStaffTypeScoping56()
BEGIN
    DECLARE dup_count INT DEFAULT 0;

    -- ── 1. employees: UNIQUE(email) -> UNIQUE(email, application_for) ───────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'employees') THEN

        SELECT COUNT(*) INTO dup_count FROM (
            SELECT email, application_for FROM employees
            GROUP BY email, application_for HAVING COUNT(*) > 1
        ) d;

        IF dup_count = 0 THEN
            -- 1a. Create the scoped key first (only if missing)
            IF NOT EXISTS (
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'employees' AND index_name = 'uq_emp_email_type'
            ) THEN
                ALTER TABLE `employees` ADD UNIQUE KEY `uq_emp_email_type` (`email`, `application_for`);
            END IF;

            -- 1b. Drop the old global key only if it exists AND the scoped key is in place
            IF EXISTS (
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'employees' AND index_name = 'uq_emp_email_type'
            ) AND EXISTS (
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'employees' AND index_name = 'uq_emp_email'
            ) THEN
                ALTER TABLE `employees` DROP INDEX `uq_emp_email`;
            END IF;
        END IF;
        -- If dup_count > 0 the old key is left untouched and nothing is deleted.

        -- 1c. Isolation filter index
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND index_name = 'idx_emp_type_status'
        ) THEN
            ALTER TABLE `employees` ADD INDEX `idx_emp_type_status` (`application_for`, `status`);
        END IF;
    END IF;

    -- ── 2. employee_custom_fields.application_for ───────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'employee_custom_fields') THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employee_custom_fields' AND column_name = 'application_for'
        ) THEN
            ALTER TABLE `employee_custom_fields`
            ADD COLUMN `application_for` VARCHAR(20) NOT NULL DEFAULT 'employee';
        END IF;

        -- Legacy / blank rows -> employee (non-destructive)
        UPDATE `employee_custom_fields` SET `application_for` = 'employee'
        WHERE `application_for` IS NULL OR `application_for` = '';

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'employee_custom_fields' AND index_name = 'idx_ecf_type'
        ) THEN
            ALTER TABLE `employee_custom_fields` ADD INDEX `idx_ecf_type` (`application_for`, `status`);
        END IF;
    END IF;
END //
DELIMITER ;

CALL MigrateStaffTypeScoping56();
DROP PROCEDURE IF EXISTS MigrateStaffTypeScoping56;
