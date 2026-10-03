-- ============================================================================
-- PEPP Learning ERP — Database Update 53
-- Google Workspace Calendar & Google Meet Integration for Live Sessions
-- ============================================================================
--
-- PURPOSE:
-- 1. Extends `sessions` table with Google Calendar and Google Meet metadata:
--      - google_integrated: Flag indicating if the session is Google Workspace integrated
--      - google_calendar_event_id: Unique event ID in organizer's primary calendar
--      - google_calendar_id: Calendar identifier ('primary' for admin@pepponline.in)
--      - google_meet_space_name: Authoritative Meet space resource name (spaces/{space})
--      - google_meet_uri: Direct Google Meet video conference link
--      - google_meet_code: Google Meet convenience meeting code (e.g. xxx-yyyy-zzz)
--      - google_integration_status: Status ('synced', 'failed', 'pending')
--      - google_last_sync_at: Timestamp of last calendar/attendance/artifact sync
--      - google_error_message: Detailed diagnostic error message if integration failed
-- 2. Creates `session_attendance` table:
--      Dedicated scheduled session attendance tracking mapping Google Meet conference
--      records and participant sessions to active PEPP ERP learners with duration,
--      status ('invited', 'joined', 'partial attendance', 'full attendance', 'absent'),
--      and raw timestamps.
-- 3. Creates `session_google_artifacts` table:
--      Stores Google Meet conference artifacts (recordings, transcripts, smart notes)
--      as references/links to organizer's Google Drive without downloading heavy files.
-- 4. Seeds default Google Workspace operational settings in `admin_settings`.
--
-- IDEMPOTENCY & SAFETY GUARANTEES:
-- - All operations execute via safe conditional checks against `information_schema`.
-- - No tables, columns, indexes, or existing records are ever removed or wiped.
-- - Existing non-Google sessions and manual meet_link values remain 100% backward compatible.
-- - Safe to re-run multiple times on MySQL/MariaDB with zero errors.
-- ============================================================================

DROP PROCEDURE IF EXISTS MigrateGoogleWorkspaceIntegration53;
DELIMITER //
CREATE PROCEDURE MigrateGoogleWorkspaceIntegration53()
BEGIN
    -- ── 1. EXTEND sessions TABLE WITH GOOGLE WORKSPACE METADATA ───────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sessions') THEN

        -- Add google_integrated column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_integrated'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_integrated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `course_csv`;
        END IF;

        -- Add google_calendar_event_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_calendar_event_id'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_calendar_event_id` VARCHAR(255) DEFAULT NULL AFTER `google_integrated`;
        END IF;

        -- Add google_calendar_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_calendar_id'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_calendar_id` VARCHAR(255) DEFAULT 'primary' AFTER `google_calendar_event_id`;
        END IF;

        -- Add google_meet_space_name column (authoritative resource name: spaces/{space})
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_meet_space_name'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_meet_space_name` VARCHAR(255) DEFAULT NULL AFTER `google_calendar_id`;
        END IF;

        -- Add google_meet_uri column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_meet_uri'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_meet_uri` VARCHAR(500) DEFAULT NULL AFTER `google_meet_space_name`;
        END IF;

        -- Add google_meet_code column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_meet_code'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_meet_code` VARCHAR(50) DEFAULT NULL AFTER `google_meet_uri`;
        END IF;

        -- Add google_integration_status column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_integration_status'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_integration_status` VARCHAR(50) DEFAULT NULL AFTER `google_meet_code`;
        END IF;

        -- Add google_last_sync_at column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_last_sync_at'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_last_sync_at` DATETIME DEFAULT NULL AFTER `google_integration_status`;
        END IF;

        -- Add google_error_message column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND column_name = 'google_error_message'
        ) THEN
            ALTER TABLE `sessions`
            ADD COLUMN `google_error_message` TEXT DEFAULT NULL AFTER `google_last_sync_at`;
        END IF;

        -- Add Indexes on sessions
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND index_name = 'idx_sess_g_event'
        ) THEN
            ALTER TABLE `sessions` ADD KEY `idx_sess_g_event` (`google_calendar_event_id`);
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND index_name = 'idx_sess_g_space'
        ) THEN
            ALTER TABLE `sessions` ADD KEY `idx_sess_g_space` (`google_meet_space_name`);
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'sessions' AND index_name = 'idx_sess_g_status'
        ) THEN
            ALTER TABLE `sessions` ADD KEY `idx_sess_g_status` (`google_integration_status`);
        END IF;

    END IF;

    -- ── 2. CREATE session_attendance TABLE ────────────────────────────────────
    CREATE TABLE IF NOT EXISTS `session_attendance` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `session_id` INT(11) NOT NULL,
        `user_id` VARCHAR(50) DEFAULT NULL,
        `google_participant_name` VARCHAR(255) DEFAULT NULL,
        `google_participant_email` VARCHAR(190) DEFAULT NULL,
        `google_participant_resource` VARCHAR(255) DEFAULT NULL,
        `first_join_time` DATETIME DEFAULT NULL,
        `last_leave_time` DATETIME DEFAULT NULL,
        `total_duration_seconds` INT(11) NOT NULL DEFAULT 0,
        `attendance_status` ENUM('invited','joined','partial attendance','full attendance','absent','unknown/unmatched') NOT NULL DEFAULT 'invited',
        `sync_status` VARCHAR(50) NOT NULL DEFAULT 'synced',
        `google_conference_record` VARCHAR(255) DEFAULT NULL,
        `google_participant_session` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_sess_user_email` (`session_id`, `google_participant_email`),
        KEY `idx_sa_session` (`session_id`),
        KEY `idx_sa_user` (`user_id`),
        KEY `idx_sa_status` (`attendance_status`),
        KEY `idx_sa_email` (`google_participant_email`),
        CONSTRAINT `fk_sa_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- ── 3. CREATE session_google_artifacts TABLE ──────────────────────────────
    CREATE TABLE IF NOT EXISTS `session_google_artifacts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `session_id` INT(11) NOT NULL,
        `artifact_type` ENUM('recording','transcript','smart_notes') NOT NULL,
        `google_resource_name` VARCHAR(255) NOT NULL,
        `drive_file_id` VARCHAR(255) DEFAULT NULL,
        `artifact_state` VARCHAR(50) NOT NULL DEFAULT 'active',
        `artifact_url` VARCHAR(500) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_sess_artifact` (`session_id`, `artifact_type`, `google_resource_name`),
        KEY `idx_sga_session` (`session_id`),
        KEY `idx_sga_type` (`artifact_type`),
        CONSTRAINT `fk_sga_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- ── 4. SEED DEFAULT GOOGLE WORKSPACE SETTINGS ─────────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_settings') THEN
        INSERT IGNORE INTO `admin_settings` (`setting_name`, `setting_value`, `created_at`, `updated_at`) VALUES
        ('google_workspace_enabled', '1', NOW(), NOW()),
        ('google_workspace_organizer_email', 'admin@pepponline.in', NOW(), NOW()),
        ('google_workspace_project_id', 'pepp-live-sessions', NOW(), NOW()),
        ('google_workspace_service_account', 'pepp-erp-google-workspace@pepp-live-sessions.iam.gserviceaccount.com', NOW(), NOW()),
        ('google_attendance_threshold_percent', '50', NOW(), NOW()),
        ('google_auto_record', '1', NOW(), NOW()),
        ('google_auto_transcribe', '1', NOW(), NOW()),
        ('google_auto_smart_notes', '1', NOW(), NOW()),
        ('google_guest_privacy_enforced', '1', NOW(), NOW());
    END IF;

END //
DELIMITER ;

-- Execute the migration safely
CALL MigrateGoogleWorkspaceIntegration53();

-- Clean up the temporary procedure
DROP PROCEDURE IF EXISTS MigrateGoogleWorkspaceIntegration53;
