-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 33 : L&D INTERN PAYMENTS & SNAPSHOT MODULE
-- Migration #33 (Idempotent for Hostinger MySQL/MariaDB)
-- Date: 2026-08-27
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ld_intern_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `voucher_no` VARCHAR(50) NOT NULL UNIQUE,
  `intern_id` INT NOT NULL,
  `intern_username_snapshot` VARCHAR(100) NOT NULL,
  `intern_name_snapshot` VARCHAR(255) NOT NULL,
  `period_start_date` DATE NOT NULL,
  `period_end_date` DATE NOT NULL,
  `expected_amount` DECIMAL(10,2) NOT NULL,
  `adjustment_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(10,2) NOT NULL,
  `payment_account_id` INT DEFAULT NULL,
  `payment_account_name_snapshot` VARCHAR(255) DEFAULT NULL,
  `paid_date` DATE NOT NULL,
  `screenshot_path` VARCHAR(255) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Completed',
  `created_by` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`payment_account_id`) REFERENCES `payment_accounts` (`id`) ON DELETE SET NULL,
  INDEX `idx_intern_period` (`intern_id`, `period_start_date`, `period_end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ld_intern_payment_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `payment_id` INT NOT NULL,
  `work_mode_id` INT NOT NULL,
  `work_mode_name_snapshot` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL,
  `quantity_label_snapshot` VARCHAR(100) DEFAULT NULL,
  FOREIGN KEY (`payment_id`) REFERENCES `ld_intern_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alter expenses table to add column and foreign key constraint pointing to L&D payments
ALTER TABLE `expenses`
  ADD COLUMN `ld_payment_id` INT DEFAULT NULL;

ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expense_ld_payment`
  FOREIGN KEY (`ld_payment_id`)
  REFERENCES `ld_intern_payments` (`id`)
  ON DELETE SET NULL;


