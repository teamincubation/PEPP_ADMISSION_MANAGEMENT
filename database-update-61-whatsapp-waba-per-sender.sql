-- ======================================================================
-- PEPP Learning ERP — Database Update 61
-- Adds sender-specific WABA ID (waba_id) to whatsapp_accounts
-- Enables multi-WABA routing using single global System User Access Token
--
-- Safe, idempotent, non-destructive migration.
-- DO NOT RUN ON PRODUCTION WITHOUT AUTHORIZATION.
-- ======================================================================

DROP PROCEDURE IF EXISTS MigrateWhatsAppWabaPerSender61;
DELIMITER //
CREATE PROCEDURE MigrateWhatsAppWabaPerSender61()
BEGIN
    -- 1. Check if whatsapp_accounts table exists
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'whatsapp_accounts') THEN

        -- 2. Add waba_id column if it doesn't already exist
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'whatsapp_accounts' AND column_name = 'waba_id') THEN
            ALTER TABLE `whatsapp_accounts`
            ADD COLUMN `waba_id` VARCHAR(100) DEFAULT NULL AFTER `phone_number_id`;
        END IF;

        -- 3. Add index on waba_id if it doesn't exist
        IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'whatsapp_accounts' AND index_name = 'idx_wa_waba_id') THEN
            ALTER TABLE `whatsapp_accounts`
            ADD KEY `idx_wa_waba_id` (`waba_id`);
        END IF;

        -- 4. Seed/normalize authoritative WABA IDs:
        -- Account 1: PEPP Learning (admissions) -> WABA 1410328164305566
        UPDATE `whatsapp_accounts`
        SET `waba_id` = '1410328164305566'
        WHERE (`sender_key` = 'admissions' OR `id` = 1)
          AND (`waba_id` IS NULL OR `waba_id` = '');

        -- Account 3: PEPP Updates (notifications) -> WABA 1099020233033644
        UPDATE `whatsapp_accounts`
        SET `waba_id` = '1099020233033644'
        WHERE (`sender_key` = 'notifications' OR `id` = 3)
          AND (`waba_id` IS NULL OR `waba_id` = '');

    END IF;
END //
DELIMITER ;

CALL MigrateWhatsAppWabaPerSender61();
DROP PROCEDURE IF EXISTS MigrateWhatsAppWabaPerSender61;
