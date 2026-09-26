-- ============================================================================
-- PEPP Learning ERP — Database Update 50
-- Employee Management Approval Redesign & Faculty 1:1 Integration
-- ============================================================================
--
-- PURPOSE:
-- 1. Adds dedicated 1:1 foreign key `employee_management_faculty_id` to `faculties`.
-- 2. Conditionally relaxes NOT NULL constraints on `employees` for non-employee roles
--    only if columns are currently NOT NULL (idempotent, preserves all existing data).
-- 3. Adds dedicated Intern fields (`internship_ends_on`, `internship_payment_status`,
--    `internship_payment_mode`, `internship_remuneration`).
-- 4. Adds dedicated Faculty fields (`academic_year`, `rate_live`, `rate_qpd`,
--    `rate_recorded`, `rate_offline`) to both `employees` and `staff_registration_requests`.
--
-- IDEMPOTENCY & SAFETY GUARANTEES:
-- - All operations execute via safe conditional checks against `information_schema`.
-- - No tables, columns, indexes, or existing records are ever dropped or truncated.
-- - No row data is modified, overwritten, or cleared.
-- - Safe to re-run multiple times on MySQL/MariaDB with zero errors.
-- ============================================================================

DROP PROCEDURE IF EXISTS MigrateEmployeeFacultyIntegration50;
DELIMITER //
CREATE PROCEDURE MigrateEmployeeFacultyIntegration50()
BEGIN
    -- ── 1. EXTEND faculties TABLE ───────────────────────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'faculties') THEN

        -- Add employee_management_faculty_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'faculties' AND column_name = 'employee_management_faculty_id'
        ) THEN
            ALTER TABLE `faculties`
            ADD COLUMN `employee_management_faculty_id` INT DEFAULT NULL AFTER `id`;
        END IF;

        -- Add UNIQUE key on employee_management_faculty_id
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'faculties' AND index_name = 'uq_fac_emp_faculty'
        ) THEN
            ALTER TABLE `faculties`
            ADD UNIQUE KEY `uq_fac_emp_faculty` (`employee_management_faculty_id`);
        END IF;

        -- Add Foreign Key constraint (ON DELETE SET NULL, ON UPDATE CASCADE)
        IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'employees') THEN
            IF NOT EXISTS (
                SELECT 1 FROM information_schema.table_constraints
                WHERE table_schema = DATABASE() AND table_name = 'faculties' AND constraint_name = 'fk_fac_emp_faculty'
            ) THEN
                ALTER TABLE `faculties`
                ADD CONSTRAINT `fk_fac_emp_faculty`
                FOREIGN KEY (`employee_management_faculty_id`)
                REFERENCES `employees` (`id`)
                ON DELETE SET NULL
                ON UPDATE CASCADE;
            END IF;
        END IF;
    END IF;

    -- ── 2. EXTEND employees TABLE ───────────────────────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'employees') THEN

        -- Conditionally relax department if currently NOT NULL (preserves existing data)
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'department' AND IS_NULLABLE = 'NO'
        ) THEN
            ALTER TABLE `employees` MODIFY COLUMN `department` VARCHAR(100) DEFAULT NULL;
        END IF;

        -- Conditionally relax joining_date if currently NOT NULL
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'joining_date' AND IS_NULLABLE = 'NO'
        ) THEN
            ALTER TABLE `employees` MODIFY COLUMN `joining_date` DATE DEFAULT NULL;
        END IF;

        -- Conditionally relax contract_validity_from if currently NOT NULL
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'contract_validity_from' AND IS_NULLABLE = 'NO'
        ) THEN
            ALTER TABLE `employees` MODIFY COLUMN `contract_validity_from` DATE DEFAULT NULL;
        END IF;

        -- Conditionally relax contract_validity_till if currently NOT NULL
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'contract_validity_till' AND IS_NULLABLE = 'NO'
        ) THEN
            ALTER TABLE `employees` MODIFY COLUMN `contract_validity_till` DATE DEFAULT NULL;
        END IF;

        -- Conditionally set default 0.00 on monthly_salary if default not already 0.00
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'monthly_salary'
            AND (COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT NOT IN ('0.00', '0.0', '0'))
        ) THEN
            ALTER TABLE `employees` MODIFY COLUMN `monthly_salary` DECIMAL(12,2) NOT NULL DEFAULT 0.00;
        END IF;

        -- Intern fields
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'internship_ends_on'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `internship_ends_on` DATE DEFAULT NULL AFTER `joining_date`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'internship_payment_status'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `internship_payment_status` VARCHAR(20) DEFAULT NULL AFTER `internship_ends_on`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'internship_payment_mode'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `internship_payment_mode` VARCHAR(50) DEFAULT NULL AFTER `internship_payment_status`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'internship_remuneration'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `internship_remuneration` DECIMAL(12,2) DEFAULT NULL AFTER `internship_payment_mode`;
        END IF;

        -- Faculty fields
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'academic_year'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `academic_year` VARCHAR(20) DEFAULT NULL AFTER `department`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'rate_live'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `rate_live` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `academic_year`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'rate_qpd'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `rate_qpd` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `rate_live`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'rate_recorded'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `rate_recorded` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `rate_qpd`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'rate_offline'
        ) THEN
            ALTER TABLE `employees` ADD COLUMN `rate_offline` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `rate_recorded`;
        END IF;
    END IF;

    -- ── 3. EXTEND staff_registration_requests TABLE ─────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests') THEN

        -- Intern audit fields
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'internship_ends_on'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `internship_ends_on` DATE DEFAULT NULL AFTER `joining_date`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'internship_payment_status'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `internship_payment_status` VARCHAR(20) DEFAULT NULL AFTER `internship_ends_on`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'internship_payment_mode'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `internship_payment_mode` VARCHAR(50) DEFAULT NULL AFTER `internship_payment_status`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'internship_remuneration'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `internship_remuneration` DECIMAL(12,2) DEFAULT NULL AFTER `internship_payment_mode`;
        END IF;

        -- Faculty audit fields
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'academic_year'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `academic_year` VARCHAR(20) DEFAULT NULL AFTER `department`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'rate_live'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `rate_live` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `academic_year`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'rate_qpd'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `rate_qpd` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `rate_live`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'rate_recorded'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `rate_recorded` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `rate_qpd`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests' AND column_name = 'rate_offline'
        ) THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `rate_offline` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `rate_recorded`;
        END IF;
    END IF;
END //
DELIMITER ;

CALL MigrateEmployeeFacultyIntegration50();
DROP PROCEDURE IF EXISTS MigrateEmployeeFacultyIntegration50;
