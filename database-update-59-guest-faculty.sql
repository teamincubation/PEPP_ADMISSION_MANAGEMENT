-- ============================================================================
-- PEPP Learning ERP — Database Update 59
-- Invited / Guest Faculty Registration  (application_for = 'guest_faculty')
-- ============================================================================
-- STATUS: PROPOSED — DO NOT RUN until approved by the Super Admin.
--         Review on a staging copy first. Never executed by the developer/agent.
--
-- PREREQUISITES (already in the repo): database-update-22 (staff tables),
--         database-update-50 (rate_* columns + faculties.employee_management_faculty_id),
--         database-update-7 (faculties).
--
-- PURPOSE
--   1. staff_registration_requests.application_for  -> accept 'guest_faculty'
--      (ENUM is widened; existing employee/faculty/intern rows are untouched.
--       If the column is a VARCHAR on your server it is widened instead.)
--   2. staff_registration_requests: relax NOT NULL on the KYC/address/bank/geo
--      columns that an invited-faculty application does not collect. Existing
--      rows keep their values; the existing staff-registration.php form still
--      supplies every one of them, so employee/faculty/intern behaviour is
--      unchanged.
--   3. staff_registration_requests: add qualifications, payment_mode
--      ('free' | 'paid' | NULL = not configured), guest_banking_submitted, photo.
--      (rate_live/qpd/recorded/offline already exist from update-50 and are reused.)
--   4. faculties: add guest_faculty_registration_id (UNIQUE, FK ->
--      staff_registration_requests.id, ON DELETE RESTRICT) and payment_mode.
--      One application <-> at most one operational faculty row, enforced by the DB.
--      Legacy and employee-linked faculties keep NULL in both new columns.
--   5. admin_settings: seed guest_faculty_banking_enabled = '0' (OFF) and
--      guest_faculty_ref_seq = '1'  (INSERT IGNORE — never overwrites).
--
-- SAFETY / IDEMPOTENCY
--   - Every step is guarded by information_schema checks; safe to re-run.
--   - No DROP, TRUNCATE, DELETE or data UPDATE statements. No row is modified.
--   - NOT NULL relaxations reuse each column's exact existing type.
--   - Rollback (manual, only if no guest_faculty rows exist yet):
--       ALTER TABLE faculties DROP FOREIGN KEY fk_fac_guest_reg;
--       ALTER TABLE faculties DROP INDEX uq_fac_guest_reg;
--       ALTER TABLE faculties DROP COLUMN guest_faculty_registration_id, DROP COLUMN payment_mode;
--       (the widened ENUM and nullable columns are harmless to leave in place)
-- ============================================================================

DROP PROCEDURE IF EXISTS MigrateGuestFaculty59;
DELIMITER //
CREATE PROCEDURE MigrateGuestFaculty59()
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE v_col VARCHAR(64);
    DECLARE v_type LONGTEXT;
    DECLARE v_dtype VARCHAR(32);
    DECLARE v_len BIGINT;

    DECLARE cur_relax CURSOR FOR
        SELECT column_name, column_type
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'staff_registration_requests'
          AND is_nullable = 'NO'
          AND column_name IN (
              'gender','date_of_birth','blood_group','emergency_contact','address',
              'pincode','state','place_post_office','aadhaar_encrypted','aadhaar_masked',
              'bank_name','bank_account_encrypted','bank_account_masked','ifsc_code',
              'latitude','longitude','maps_url'
          );
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests') THEN

        -- ── 1. application_for accepts 'guest_faculty' ─────────────────────
        SELECT data_type, character_maximum_length, column_type
          INTO v_dtype, v_len, v_type
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests'
          AND column_name = 'application_for'
        LIMIT 1;

        IF v_dtype = 'enum' AND v_type NOT LIKE '%guest_faculty%' THEN
            ALTER TABLE `staff_registration_requests`
                MODIFY COLUMN `application_for`
                ENUM('employee','faculty','intern','guest_faculty') NOT NULL;
        ELSEIF v_dtype IN ('varchar','char') AND v_len < 20 THEN
            ALTER TABLE `staff_registration_requests`
                MODIFY COLUMN `application_for` VARCHAR(20) NOT NULL;
        END IF;

        -- ── 2. Relax NOT NULL on columns guest faculty does not collect ────
        SET done = 0;
        OPEN cur_relax;
        relax_loop: LOOP
            FETCH cur_relax INTO v_col, v_type;
            IF done = 1 THEN LEAVE relax_loop; END IF;
            SET @ddl = CONCAT('ALTER TABLE `staff_registration_requests` MODIFY COLUMN `',
                              v_col, '` ', v_type, ' NULL DEFAULT NULL');
            PREPARE stmt_relax FROM @ddl;
            EXECUTE stmt_relax;
            DEALLOCATE PREPARE stmt_relax;
        END LOOP;
        CLOSE cur_relax;

        -- ── 3. New / ensured columns ───────────────────────────────────────
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND column_name = 'photo') THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `photo` VARCHAR(255) DEFAULT NULL;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND column_name = 'qualifications') THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `qualifications` TEXT DEFAULT NULL;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND column_name = 'payment_mode') THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `payment_mode` VARCHAR(10) DEFAULT NULL;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND column_name = 'guest_banking_submitted') THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `guest_banking_submitted` TINYINT(1) NOT NULL DEFAULT 0;
        END IF;
        -- rate_* normally exist from update-50; guard in case it was skipped.
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND column_name = 'rate_live') THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `rate_live` DECIMAL(10,2) NOT NULL DEFAULT 0.00;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND column_name = 'rate_qpd') THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `rate_qpd` DECIMAL(10,2) NOT NULL DEFAULT 0.00;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND column_name = 'rate_recorded') THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `rate_recorded` DECIMAL(10,2) NOT NULL DEFAULT 0.00;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND column_name = 'rate_offline') THEN
            ALTER TABLE `staff_registration_requests` ADD COLUMN `rate_offline` DECIMAL(10,2) NOT NULL DEFAULT 0.00;
        END IF;

        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()
                       AND table_name = 'staff_registration_requests' AND index_name = 'idx_srr_type_status') THEN
            ALTER TABLE `staff_registration_requests` ADD INDEX `idx_srr_type_status` (`application_for`, `status`);
        END IF;
    END IF;

    -- ── 4. faculties: dedicated 1:1 link to the guest faculty application ──
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'faculties') THEN

        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'faculties' AND column_name = 'guest_faculty_registration_id') THEN
            ALTER TABLE `faculties` ADD COLUMN `guest_faculty_registration_id` INT DEFAULT NULL;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND table_name = 'faculties' AND column_name = 'payment_mode') THEN
            ALTER TABLE `faculties` ADD COLUMN `payment_mode` VARCHAR(10) DEFAULT NULL;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()
                       AND table_name = 'faculties' AND index_name = 'uq_fac_guest_reg') THEN
            ALTER TABLE `faculties` ADD UNIQUE KEY `uq_fac_guest_reg` (`guest_faculty_registration_id`);
        END IF;
        IF EXISTS (SELECT 1 FROM information_schema.tables
                   WHERE table_schema = DATABASE() AND table_name = 'staff_registration_requests') THEN
            IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                           WHERE table_schema = DATABASE() AND table_name = 'faculties'
                             AND constraint_name = 'fk_fac_guest_reg') THEN
                ALTER TABLE `faculties`
                    ADD CONSTRAINT `fk_fac_guest_reg`
                    FOREIGN KEY (`guest_faculty_registration_id`)
                    REFERENCES `staff_registration_requests` (`id`)
                    ON DELETE RESTRICT ON UPDATE CASCADE;
            END IF;
        END IF;
    END IF;

    -- ── 5. Settings (never overwrite an existing value) ────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'admin_settings') THEN
        INSERT IGNORE INTO admin_settings (setting_name, setting_value, created_at, updated_at)
        VALUES ('guest_faculty_banking_enabled', '0', NOW(), NOW());
        INSERT IGNORE INTO admin_settings (setting_name, setting_value, created_at, updated_at)
        VALUES ('guest_faculty_ref_seq', '1', NOW(), NOW());
    END IF;
END //
DELIMITER ;

CALL MigrateGuestFaculty59();
DROP PROCEDURE IF EXISTS MigrateGuestFaculty59;
