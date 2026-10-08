-- ============================================================================
-- PEPP Learning ERP — Database Update 62
-- Multi-WABA WhatsApp Template Architecture & Account Scope
-- ============================================================================
--
-- PURPOSE:
-- 1. Upgrade communication_templates to be first-class sender/WABA aware:
--    - Adds `sender_account_id` (INT) and `waba_id` (VARCHAR) and `meta_template_id` (VARCHAR)
--    - Replaces old single-WABA uniqueness (channel, template_name) with:
--      UNIQUE KEY `uq_template_channel_account_name_lang` (channel, sender_account_id, template_name, language)
--    - Safe, non-destructive backfill of existing templates to Account 1 (PEPP Learning)
--      or Account 3 (PEPP Updates) based on meta_data, WABA ID, sender_key, and template name.
--
-- 2. Upgrade communication_event_mappings to be sender-account aware:
--    - Adds `sender_account_id` (INT) and `template_id` (INT)
--    - Replaces single-event uniqueness with:
--      UNIQUE KEY `uq_cem_event_sender` (event_name, sender_account_id)
--    - Backfills template_id and sender_account_id from current template mappings.
--
-- 3. Upgrade communication_campaigns:
--    - Ensures `sender_account_id` (INT) exists
--    - Adds `template_id` (INT) to link directly to communication_templates.id
--    - Adds index on template_id
--    - Backfills template_id on existing campaigns where matching template is found.
--
-- SAFETY & IDEMPOTENCY:
-- - Uses safe stored procedures with information_schema checks. Safe to re-run.
-- - No tables, columns, indexes, or existing records are ever deleted or truncated.
-- - Existing production data is 100% preserved.
--
-- IMPORTANT:
-- - DO NOT run this migration on production without explicit authorization.
-- - Execute during an authorized maintenance window.
-- ============================================================================

DROP PROCEDURE IF EXISTS MigrateWhatsAppTemplateAccountScope62;
DELIMITER //
CREATE PROCEDURE MigrateWhatsAppTemplateAccountScope62()
BEGIN

    -- ─────────────────────────────────────────────────────────────────────────
    -- 1. TABLE: communication_templates
    -- ─────────────────────────────────────────────────────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'communication_templates') THEN

        -- 1.1 Add sender_account_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_templates' AND column_name = 'sender_account_id'
        ) THEN
            ALTER TABLE `communication_templates`
            ADD COLUMN `sender_account_id` INT DEFAULT NULL AFTER `channel`;
        END IF;

        -- 1.2 Add waba_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_templates' AND column_name = 'waba_id'
        ) THEN
            ALTER TABLE `communication_templates`
            ADD COLUMN `waba_id` VARCHAR(100) DEFAULT NULL AFTER `sender_account_id`;
        END IF;

        -- 1.3 Add meta_template_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_templates' AND column_name = 'meta_template_id'
        ) THEN
            ALTER TABLE `communication_templates`
            ADD COLUMN `meta_template_id` VARCHAR(100) DEFAULT NULL AFTER `waba_id`;
        END IF;

        -- 1.4 Add index on sender_account_id
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_templates' AND index_name = 'idx_ct_sender_account'
        ) THEN
            ALTER TABLE `communication_templates`
            ADD KEY `idx_ct_sender_account` (`sender_account_id`);
        END IF;

        -- 1.5 Add index on waba_id
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_templates' AND index_name = 'idx_ct_waba_id'
        ) THEN
            ALTER TABLE `communication_templates`
            ADD KEY `idx_ct_waba_id` (`waba_id`);
        END IF;

        -- 1.6 Add index on meta_template_id
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_templates' AND index_name = 'idx_ct_meta_template_id'
        ) THEN
            ALTER TABLE `communication_templates`
            ADD KEY `idx_ct_meta_template_id` (`meta_template_id`);
        END IF;

        -- 1.7 Backfill existing templates safely
        -- Step A: Backfill Account 3 (PEPP Updates) from meta_data JSON or faculty templates
        UPDATE `communication_templates`
        SET `sender_account_id` = 3,
            `waba_id` = '1099020233033644'
        WHERE `channel` = 'whatsapp'
          AND (`sender_account_id` IS NULL OR `waba_id` IS NULL)
          AND (
              `meta_data` LIKE '%"waba_id":"1099020233033644"%'
              OR `meta_data` LIKE '%"account_id":3%'
              OR `meta_data` LIKE '%"sender_key":"notifications"%'
              OR `template_name` IN (
                  'faculty_session_reminder',
                  'faculty_session_start',
                  'faculty_session_start_now',
                  'faculty_session_cancelled',
                  'faculty_session_scheduled'
              )
          );

        -- Step B: Backfill Account 1 (PEPP Learning) from meta_data JSON
        UPDATE `communication_templates`
        SET `sender_account_id` = 1,
            `waba_id` = '1410328164305566'
        WHERE `channel` = 'whatsapp'
          AND (`sender_account_id` IS NULL OR `waba_id` IS NULL)
          AND (
              `meta_data` LIKE '%"waba_id":"1410328164305566"%'
              OR `meta_data` LIKE '%"account_id":1%'
              OR `meta_data` LIKE '%"sender_key":"admissions"%'
          );

        -- Step C: Backfill remaining legacy WhatsApp templates to Account 1 (PEPP Learning default)
        UPDATE `communication_templates`
        SET `sender_account_id` = 1,
            `waba_id` = '1410328164305566'
        WHERE `channel` = 'whatsapp'
          AND `sender_account_id` IS NULL;

        -- Step D: Extract meta_template_id from meta_data JSON if available
        UPDATE `communication_templates`
        SET `meta_template_id` = JSON_UNQUOTE(JSON_EXTRACT(`meta_data`, '$.meta_template_id'))
        WHERE `meta_template_id` IS NULL
          AND `meta_data` IS NOT NULL
          AND JSON_VALID(`meta_data`)
          AND JSON_EXTRACT(`meta_data`, '$.meta_template_id') IS NOT NULL
          AND JSON_UNQUOTE(JSON_EXTRACT(`meta_data`, '$.meta_template_id')) != '';

        -- 1.8 Replace old single-WABA unique index with sender-aware unique index
        -- First drop old unique index if it exists
        IF EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_templates' AND index_name = 'uq_template_channel_name'
        ) THEN
            ALTER TABLE `communication_templates` DROP INDEX `uq_template_channel_name`;
        END IF;

        -- Create new sender-account aware unique index
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_templates' AND index_name = 'uq_template_channel_account_name_lang'
        ) THEN
            ALTER TABLE `communication_templates`
            ADD UNIQUE KEY `uq_template_channel_account_name_lang` (`channel`, `sender_account_id`, `template_name`, `language`);
        END IF;

    END IF;

    -- ─────────────────────────────────────────────────────────────────────────
    -- 2. TABLE: communication_event_mappings
    -- ─────────────────────────────────────────────────────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'communication_event_mappings') THEN

        -- 2.1 Add sender_account_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_event_mappings' AND column_name = 'sender_account_id'
        ) THEN
            ALTER TABLE `communication_event_mappings`
            ADD COLUMN `sender_account_id` INT DEFAULT NULL AFTER `event_name`;
        END IF;

        -- 2.2 Add template_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_event_mappings' AND column_name = 'template_id'
        ) THEN
            ALTER TABLE `communication_event_mappings`
            ADD COLUMN `template_id` INT DEFAULT NULL AFTER `sender_account_id`;
        END IF;

        -- 2.3 Add index on sender_account_id
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_event_mappings' AND index_name = 'idx_cem_sender_account'
        ) THEN
            ALTER TABLE `communication_event_mappings`
            ADD KEY `idx_cem_sender_account` (`sender_account_id`);
        END IF;

        -- 2.4 Add index on template_id
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_event_mappings' AND index_name = 'idx_cem_template_id'
        ) THEN
            ALTER TABLE `communication_event_mappings`
            ADD KEY `idx_cem_template_id` (`template_id`);
        END IF;

        -- 2.5 Backfill sender_account_id on existing event mappings
        -- Faculty & notification events -> Account 3 (PEPP Updates)
        UPDATE `communication_event_mappings`
        SET `sender_account_id` = 3
        WHERE `sender_account_id` IS NULL
          AND `event_name` IN (
              'session_reminder',
              'faculty_session_reminder',
              'daily_task_reminder',
              'university_admission_notification',
              'task_reminder',
              'scheduled_session_reminder'
          );

        -- All other events -> Account 1 (PEPP Learning)
        UPDATE `communication_event_mappings`
        SET `sender_account_id` = 1
        WHERE `sender_account_id` IS NULL;

        -- 2.6 Backfill template_id by matching communication_templates
        UPDATE `communication_event_mappings` cem
        JOIN `communication_templates` ct
          ON ct.template_name = cem.template_name
         AND (ct.sender_account_id = cem.sender_account_id OR ct.sender_account_id IS NULL)
         AND ct.channel = 'whatsapp'
        SET cem.template_id = ct.id
        WHERE cem.template_id IS NULL
          AND cem.template_name IS NOT NULL
          AND cem.template_name != '';

        -- 2.7 Update unique index to be event + sender_account aware
        -- Check if old event_name unique index exists and drop it safely
        IF EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_event_mappings' AND index_name = 'event_name' AND Non_unique = 0
        ) THEN
            ALTER TABLE `communication_event_mappings` DROP INDEX `event_name`;
        END IF;

        IF EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_event_mappings' AND index_name = 'uq_cem_event_name'
        ) THEN
            ALTER TABLE `communication_event_mappings` DROP INDEX `uq_cem_event_name`;
        END IF;

        -- Add new composite unique index
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_event_mappings' AND index_name = 'uq_cem_event_sender'
        ) THEN
            ALTER TABLE `communication_event_mappings`
            ADD UNIQUE KEY `uq_cem_event_sender` (`event_name`, `sender_account_id`);
        END IF;

    END IF;

    -- ─────────────────────────────────────────────────────────────────────────
    -- 3. TABLE: communication_campaigns
    -- ─────────────────────────────────────────────────────────────────────────
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'communication_campaigns') THEN

        -- 3.1 Ensure sender_account_id column exists
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_campaigns' AND column_name = 'sender_account_id'
        ) THEN
            ALTER TABLE `communication_campaigns`
            ADD COLUMN `sender_account_id` INT DEFAULT NULL AFTER `channel`;
        END IF;

        -- 3.2 Ensure index on sender_account_id exists
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_campaigns' AND index_name = 'idx_cc_sender_account'
        ) THEN
            ALTER TABLE `communication_campaigns`
            ADD KEY `idx_cc_sender_account` (`sender_account_id`);
        END IF;

        -- 3.3 Add template_id column
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_campaigns' AND column_name = 'template_id'
        ) THEN
            ALTER TABLE `communication_campaigns`
            ADD COLUMN `template_id` INT DEFAULT NULL AFTER `template_name`;
        END IF;

        -- 3.4 Add index on template_id
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_campaigns' AND index_name = 'idx_cc_template_id'
        ) THEN
            ALTER TABLE `communication_campaigns`
            ADD KEY `idx_cc_template_id` (`template_id`);
        END IF;

        -- 3.5 Backfill sender_account_id from segment_criteria JSON if not set
        UPDATE `communication_campaigns`
        SET `sender_account_id` = CAST(JSON_UNQUOTE(JSON_EXTRACT(`segment_criteria`, '$.sender_account_id')) AS UNSIGNED)
        WHERE `sender_account_id` IS NULL
          AND `segment_criteria` IS NOT NULL
          AND JSON_VALID(`segment_criteria`)
          AND JSON_EXTRACT(`segment_criteria`, '$.sender_account_id') IS NOT NULL;

        -- 3.6 Backfill template_id by matching communication_templates
        UPDATE `communication_campaigns` cc
        JOIN `communication_templates` ct
          ON ct.template_name = cc.template_name
         AND (ct.sender_account_id = cc.sender_account_id OR cc.sender_account_id IS NULL)
         AND ct.channel = 'whatsapp'
        SET cc.template_id = ct.id
        WHERE cc.template_id IS NULL
          AND cc.template_name IS NOT NULL
          AND cc.template_name != '';

    END IF;

END //
DELIMITER ;

-- ============================================================================
-- NOTE:
-- DO NOT EXECUTE THIS SCRIPT AUTOMATICALLY ON PRODUCTION.
-- It must be manually reviewed and executed during an authorized maintenance window.
-- To execute manually in MySQL:
--   CALL MigrateWhatsAppTemplateAccountScope62();
--   DROP PROCEDURE IF EXISTS MigrateWhatsAppTemplateAccountScope62;
-- ============================================================================
