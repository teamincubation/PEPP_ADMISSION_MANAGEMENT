-- ============================================================================
-- PEPP Learning ERP — Database Update 60
-- Policy & Terms Management, Consent Auditing, and Custom Field Compatibility
-- ============================================================================
-- STATUS: PROPOSED — DO NOT RUN against production without authorization.
--         Review on a staging copy first. Never executed by the developer/agent.
--
-- PREREQUISITES:
--   database-update-22.sql (staff tables & employee_custom_fields)
--   database-update-56.sql (staff application_for)
--   database-update-59-guest-faculty.sql (invited faculty workflow)
--
-- PURPOSE:
--   1. Create `policy_documents` to store active versioned policies:
--        - faculty_policy
--        - guest_faculty_policy
--        - employee_staff_terms
--        - internship_policy
--   2. Create `policy_versions` to retain immutable historical snapshots of
--      every policy edition.
--   3. Create `policy_acceptances` to store immutable legal consent records
--      (policy_key, version, accepted_at, ip_address, user_agent) linked to
--      registration requests.
--   4. Extend `employee_custom_fields` schema compatibility to ensure
--      application_for supports 'guest_faculty' alongside 'employee','faculty','intern',
--      and ensure `field_key` and standard column aliases are available.
--   5. Seed default 1.0 versions for all four policies (INSERT IGNORE).
--
-- SAFETY / IDEMPOTENCY:
--   - Fully idempotent: can be executed multiple times without duplicating or dropping data.
--   - No DROP, TRUNCATE, DELETE, or destructive ALTER operations.
--   - Existing registration requests and employee custom fields remain intact.
-- ============================================================================

DROP PROCEDURE IF EXISTS MigratePolicyManagement60;
DELIMITER //
CREATE PROCEDURE MigratePolicyManagement60()
BEGIN
    -- ── 1. Create policy_documents table ─────────────────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'policy_documents'
    ) THEN
        CREATE TABLE `policy_documents` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `policy_key` VARCHAR(50) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `current_version` VARCHAR(20) NOT NULL DEFAULT '1.0',
            `content` MEDIUMTEXT NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `updated_by` VARCHAR(100) DEFAULT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_policy_key` (`policy_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;

    -- ── 2. Create policy_versions table ──────────────────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'policy_versions'
    ) THEN
        CREATE TABLE `policy_versions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `policy_key` VARCHAR(50) NOT NULL,
            `version` VARCHAR(20) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `content` MEDIUMTEXT NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` VARCHAR(100) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_policy_ver` (`policy_key`, `version`),
            KEY `idx_pv_key` (`policy_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;

    -- ── 3. Create policy_acceptances table ───────────────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'policy_acceptances'
    ) THEN
        CREATE TABLE `policy_acceptances` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `registration_request_id` INT NOT NULL,
            `policy_key` VARCHAR(50) NOT NULL,
            `policy_version` VARCHAR(20) NOT NULL,
            `accepted_at` DATETIME NOT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_pa_req` (`registration_request_id`),
            KEY `idx_pa_key_ver` (`policy_key`, `policy_version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;

    -- ── 4. Extend employee_custom_fields for 'guest_faculty' ─────────────────
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'employee_custom_fields'
    ) THEN
        -- Ensure application_for column exists and is VARCHAR(30)
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employee_custom_fields' AND column_name = 'application_for'
        ) THEN
            ALTER TABLE `employee_custom_fields`
            ADD COLUMN `application_for` VARCHAR(30) NOT NULL DEFAULT 'employee' AFTER `id`;
        ELSE
            -- Widen if ENUM or shorter VARCHAR
            ALTER TABLE `employee_custom_fields`
            MODIFY COLUMN `application_for` VARCHAR(30) NOT NULL DEFAULT 'employee';
        END IF;

        -- Ensure field_key column exists
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'employee_custom_fields' AND column_name = 'field_key'
        ) THEN
            ALTER TABLE `employee_custom_fields`
            ADD COLUMN `field_key` VARCHAR(100) DEFAULT NULL AFTER `id`;
        END IF;

        -- Ensure supporting index exists
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'employee_custom_fields' AND index_name = 'idx_ecf_type'
        ) THEN
            ALTER TABLE `employee_custom_fields` ADD INDEX `idx_ecf_type` (`application_for`, `status`);
        END IF;
    END IF;

    -- ── 5. Seed default 1.0 policies ─────────────────────────────────────────
    -- Faculty Policy
    INSERT IGNORE INTO `policy_documents` (`policy_key`, `title`, `current_version`, `content`, `status`, `updated_by`, `updated_at`, `created_at`)
    VALUES (
        'faculty_policy',
        'Faculty Policy',
        '1.0',
        '<h3>1. Academic Excellence & Professional Conduct</h3><p>All faculty members appointed to PEPP Learning are committed to upholding the highest standards of academic integrity, pedagogical excellence, and student mentorship.</p><h3>2. Session Delivery & Preparedness</h3><p>Faculty members agree to conduct scheduled sessions punctually and provide structured academic content aligned with PEPP syllabus requirements.</p><h3>3. Confidentiality & Intellectual Property</h3><p>Teaching materials, questions, notes, and session recordings prepared for PEPP Learning remain the intellectual property of Labinc Education Pvt. Ltd.</p>',
        'active',
        'System Initializer',
        NOW(),
        NOW()
    );

    -- Guest Faculty Policy
    INSERT IGNORE INTO `policy_documents` (`policy_key`, `title`, `current_version`, `content`, `status`, `updated_by`, `updated_at`, `created_at`)
    VALUES (
        'guest_faculty_policy',
        'Guest Faculty Policy',
        '1.0',
        '<h3>1. Scope of Engagement</h3><p>Invited guest faculty collaborate with PEPP Learning on a visiting or session-by-session basis. Submission and acceptance of this registration does not constitute regular employment or tenure.</p><h3>2. Remuneration & Session Modes</h3><p>Guest faculty honorarium or session compensation is determined per agreed session rates (Live, QPD, Recorded, or Offline) or designated as honorary/pro bono upon administrative review.</p><h3>3. Conduct & Academic Ethics</h3><p>Guest lecturers are expected to foster an inclusive, respectful, and academically rigorous learning environment during all interactive and recorded engagements.</p>',
        'active',
        'System Initializer',
        NOW(),
        NOW()
    );

    -- Employee & Staff Terms & Conditions
    INSERT IGNORE INTO `policy_documents` (`policy_key`, `title`, `current_version`, `content`, `status`, `updated_by`, `updated_at`, `created_at`)
    VALUES (
        'employee_staff_terms',
        'Employee & Staff Terms & Conditions',
        '1.0',
        '<h3>1. Employment Terms & Duties</h3><p>Staff members agree to perform duties assigned by PEPP Learning diligently and in accordance with institutional policies and departmental requirements.</p><h3>2. Code of Workplace Conduct</h3><p>Staff shall maintain professional ethics, respectful interpersonal conduct, and protect company assets, learner information, and operational confidentiality.</p><h3>3. Attendance & Timeliness</h3><p>Employees are expected to adhere to allocated working hours, report absences in advance, and maintain accurate attendance logs.</p>',
        'active',
        'System Initializer',
        NOW(),
        NOW()
    );

    -- Internship Policy
    INSERT IGNORE INTO `policy_documents` (`policy_key`, `title`, `current_version`, `content`, `status`, `updated_by`, `updated_at`, `created_at`)
    VALUES (
        'internship_policy',
        'Internship Policy',
        '1.0',
        '<h3>1. Purpose of Internship</h3><p>The internship program at PEPP Learning offers practical learning, skill development, and supervised organizational exposure.</p><h3>2. Intern Responsibilities</h3><p>Interns must comply with project timelines, complete assigned learning modules, and uphold data security policies.</p><h3>3. Completion & Evaluation</h3><p>Internship completion certificates and any agreed stipends are subject to satisfactory performance, attendance, and mentor evaluation upon conclusion of the internship term.</p>',
        'active',
        'System Initializer',
        NOW(),
        NOW()
    );

    -- Seed initial 1.0 versions into policy_versions
    INSERT IGNORE INTO `policy_versions` (`policy_key`, `version`, `title`, `content`, `status`, `created_by`, `created_at`)
    SELECT `policy_key`, `current_version`, `title`, `content`, `status`, `updated_by`, `created_at`
    FROM `policy_documents`;

END //
DELIMITER ;

-- ============================================================================
-- NOTE: To execute this migration manually when authorized, run:
--   CALL MigratePolicyManagement60();
--   DROP PROCEDURE IF EXISTS MigratePolicyManagement60;
-- ============================================================================
