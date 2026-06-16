-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 3 : INVOICES + PER-COURSE REGISTRATION
-- Run ONCE in phpMyAdmin (after database-update.sql and database-update-2.sql).
-- Safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Invoices table — one invoice per approved payment
--    (registration payments AND installment payments).
--    invoice_type 'gst'    : payment received in the GST account (AXIS LABINC)
--                            → amount is GST-inclusive (18% = 9% CGST + 9% SGST)
--    invoice_type 'non_gst': any other account → no tax shown on the invoice.
--    UNIQUE (source, source_ref) prevents duplicate invoices for one payment:
--      registration → source_ref = users.id
--      installment  → source_ref = instalment_details.id
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(50) NOT NULL,
  `invoice_type` enum('gst','non_gst') NOT NULL DEFAULT 'non_gst',
  `user_id` varchar(20) NOT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `course` varchar(255) DEFAULT NULL,
  `payment_plan` varchar(50) DEFAULT NULL,
  `source` enum('registration','installment') NOT NULL,
  `source_ref` int(11) NOT NULL,
  `instalment_number` int(11) DEFAULT NULL,
  `gross_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `taxable_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cgst_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sgst_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `round_off` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_account_id` int(11) DEFAULT NULL,
  `payment_mode` varchar(50) DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `email_status` enum('sent','failed','skipped') NOT NULL DEFAULT 'skipped',
  `generated_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoice_no` (`invoice_no`),
  UNIQUE KEY `uniq_source` (`source`, `source_ref`),
  KEY `idx_inv_user` (`user_id`),
  KEY `idx_inv_type` (`invoice_type`),
  KEY `idx_inv_paid_date` (`paid_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Invoice settings (editable in Settings → Invoice Settings).
--    inv_gst_account_id : the payment account whose receipts carry 18% GST
--                         (auto-detected below from a name containing
--                          AXIS + LABINC; change it anytime in Settings)
--    GST series  : INV/2627/001  → prefix / financial-year code / sequence
--                  with a validity period (start–end) managed by the admin.
--    Non-GST     : INV/DDMMYY/001 → prefix / paid date / running sequence.
--                  The sequence is independent from the GST sequence.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO admin_settings (setting_name, setting_value, created_at, updated_at) VALUES
('inv_gst_account_id',
    (SELECT COALESCE((SELECT id FROM payment_accounts
                      WHERE account_name LIKE '%AXIS%' AND account_name LIKE '%LABINC%'
                      LIMIT 1), '')),
    NOW(), NOW()),
('inv_gst_prefix',  'INV',        NOW(), NOW()),
('inv_gst_fy',      '2627',       NOW(), NOW()),
('inv_gst_start',   '2026-04-01', NOW(), NOW()),
('inv_gst_end',     '2027-03-31', NOW(), NOW()),
('inv_gst_seq',     '1',          NOW(), NOW()),
('inv_nongst_prefix', 'INV',      NOW(), NOW()),
('inv_nongst_seq',  '1',          NOW(), NOW());

-- ----------------------------------------------------------------------------
-- 3. REGISTRATION RULE CHANGE: allow the SAME student (same email/WhatsApp)
--    to register for MULTIPLE COURSES within one academic year.
--    Uniqueness is now (email + course + year) and (whatsapp + course + year)
--    instead of (email + year) / (whatsapp + year).
-- ----------------------------------------------------------------------------
ALTER TABLE `users` DROP INDEX IF EXISTS `unique_email_year`;
ALTER TABLE `users` DROP INDEX IF EXISTS `unique_whatsapp_year`;
ALTER TABLE `users`
  ADD UNIQUE KEY IF NOT EXISTS `uniq_email_course_year` (`email`, `pepp_course`, `pepp_academic_year`),
  ADD UNIQUE KEY IF NOT EXISTS `uniq_whatsapp_course_year` (`whatsapp_number`, `pepp_course`, `pepp_academic_year`);

-- ============================================================================
-- DONE. After running:
--   1. Upload the updated PHP files + pepp-logo.jpg (used on PDF invoices).
--   2. Settings → Invoice Settings: confirm the GST account (AXIS LABINC)
--      and the GST series validity dates.
--   3. Payments → Invoices: use "Generate missing invoices" once to create
--      invoices for payments approved before this update.
-- ============================================================================
