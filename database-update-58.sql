-- ============================================================================
-- PEPP Learning ERP — Database Update 58 (PROPOSED / OPTIONAL)
-- Multi-Number WhatsApp Support for Communication Campaigns
-- ============================================================================
--
-- PURPOSE:
-- Adds dedicated `sender_account_id` column to `communication_campaigns` table:
--   - Links marketing / broadcast campaigns explicitly to a sender account in `whatsapp_accounts`
--     (e.g., Account 2: 'notifications' / PEPP Updates)
--   - Prevents marketing messages from accidentally falling back to admissions sender
--
-- SAFETY & IDEMPOTENCY:
-- - Uses safe stored procedure with information_schema checks. Safe to re-run.
-- - No tables, columns, indexes, or existing records are ever removed or wiped.
-- - Application code is dual-compatible and works seamlessly whether this column exists or not
--   (fallback uses segment_criteria JSON configuration).
--
-- IMPORTANT:
-- - DO NOT run this migration on production without explicit authorization.
-- - Execute during an authorized maintenance window.
-- ============================================================================

DROP PROCEDURE IF EXISTS MigrateCampaignSenderAccount58;
DELIMITER //
CREATE PROCEDURE MigrateCampaignSenderAccount58()
BEGIN
    -- 1. Ensure communication_campaigns.sender_account_id exists
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'communication_campaigns') THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_campaigns' AND column_name = 'sender_account_id'
        ) THEN
            ALTER TABLE `communication_campaigns`
            ADD COLUMN `sender_account_id` INT DEFAULT NULL AFTER `channel`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_campaigns' AND index_name = 'idx_cc_sender_account'
        ) THEN
            ALTER TABLE `communication_campaigns`
            ADD KEY `idx_cc_sender_account` (`sender_account_id`);
        END IF;
    END IF;
END //
DELIMITER ;

CALL MigrateCampaignSenderAccount58();
DROP PROCEDURE IF EXISTS MigrateCampaignSenderAccount58;
