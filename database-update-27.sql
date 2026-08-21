-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 27 : WHATSAPP MARKETING CAMPAIGNS FOR LEADS
-- Migration #27
-- Date: 2026-08-22
--
-- Adds lead segmentation targeting fields, unique indexes to prevent duplicates,
-- and compliance opt-out columns.
-- ============================================================================

-- 1. Add target_audience to campaigns table to support lead targeting
ALTER TABLE `communication_campaigns` 
ADD COLUMN `target_audience` ENUM('students', 'leads') NOT NULL DEFAULT 'students' AFTER `channel`;

-- 2. Add lead_id to campaign recipients mapping
ALTER TABLE `communication_campaign_recipients` 
ADD COLUMN `lead_id` INT DEFAULT NULL AFTER `campaign_id`;

-- 3. Add foreign key relation from campaign recipients to leads
ALTER TABLE `communication_campaign_recipients` 
ADD CONSTRAINT `fk_ccr_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL;

-- 4. Add unique index to prevent duplicate messages to the same phone number in a single campaign
ALTER TABLE `communication_campaign_recipients` 
ADD UNIQUE KEY `uq_campaign_recipient_phone` (`campaign_id`, `recipient`);

-- 5. Add opt-out field to leads table for compliance
ALTER TABLE `leads` 
ADD COLUMN `is_opted_out` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

-- 6. Add index on leads.is_opted_out
ALTER TABLE `leads` 
ADD KEY `idx_lead_optout` (`is_opted_out`);

-- 7. Add error_message to record pre-queue/pre-dispatch skip reasons
ALTER TABLE `communication_campaign_recipients`
ADD COLUMN `error_message` TEXT DEFAULT NULL AFTER `sent_at`;
