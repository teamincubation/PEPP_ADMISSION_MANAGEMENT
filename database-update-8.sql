-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 8 : ALUMNI · PEPPIANS PORTAL · REFERRAL
--                                    PROGRAM · DISCOUNT COUPONS · GOOGLE LOGIN
-- Run ONCE in phpMyAdmin (after updates 1-7). Safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Admin Google sign-in: store the Google email on each admin account.
-- ----------------------------------------------------------------------------
ALTER TABLE `admins`
  ADD COLUMN IF NOT EXISTS `google_email` varchar(190) DEFAULT NULL AFTER `email`;

-- ----------------------------------------------------------------------------
-- 2. Alumni database (uploaded/managed by Super Admin in Settings).
--    Duplicate phone/email on import is folded into the secondary fields.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alumni` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `course_name` varchar(255) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `secondary_email` varchar(190) DEFAULT NULL,
  `mobile` varchar(20) NOT NULL,
  `secondary_mobile` varchar(20) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_alum_mobile` (`mobile`),
  KEY `idx_alum_email` (`email`),
  KEY `idx_alum_year` (`academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. PEPPians — alumni portal accounts (separate from admin/users).
--    Username = signup email. May sign up via password or Google.
--    verified = linked to an alumni record by the Super Admin's database.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `peppians` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT '',
  `google_id` varchar(100) DEFAULT NULL,
  `auth_provider` enum('password','google') NOT NULL DEFAULT 'password',
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `linked_alumni_id` int(11) DEFAULT NULL,
  `linked_courses` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pep_email` (`email`),
  KEY `idx_pep_whatsapp` (`whatsapp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Referral earning program — one config row per academic year.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referral_programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(20) NOT NULL,
  `user_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `alumni_earning` decimal(10,2) NOT NULL DEFAULT 0.00,
  `once_per_user` tinyint(1) NOT NULL DEFAULT 1,
  `partial_credit` tinyint(1) NOT NULL DEFAULT 0,
  `terms` text DEFAULT NULL,
  `id_prefix` varchar(20) NOT NULL DEFAULT 'PEPPREF',
  `id_start` int(11) NOT NULL DEFAULT 1001,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_refprog_year` (`academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Referees — a PEPPian enrolled in a referral program (their referral id).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_id` int(11) NOT NULL,
  `peppian_id` int(11) NOT NULL,
  `referral_code` varchar(40) NOT NULL,
  `payout_method` varchar(30) DEFAULT NULL,
  `payout_details` varchar(255) DEFAULT NULL,
  `terms_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ref_code` (`referral_code`),
  UNIQUE KEY `uniq_prog_peppian` (`program_id`, `peppian_id`),
  KEY `idx_ref_peppian` (`peppian_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Referral earnings — one row per referred student, credited progressively.
--    status: pending (joined, not approved/onboarded) | half (50% on partial)
--          | credited (full earning due) | paid (alumni has been paid out)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referral_earnings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `referee_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `user_id` varchar(20) DEFAULT NULL,
  `student_name` varchar(150) DEFAULT NULL,
  `full_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `credited_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','half','credited','paid') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_re_referee` (`referee_id`),
  KEY `idx_re_user` (`user_id`),
  KEY `idx_re_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. Referral payouts — money actually paid to a referee (with proof).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referral_payouts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `referee_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_date` date DEFAULT NULL,
  `payment_account_id` int(11) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rp_referee` (`referee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. Discount coupons — generic coupons applied at registration.
--    type: flat | percent. scope_year limits to one academic year (optional).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coupons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `discount_type` enum('flat','percent') NOT NULL DEFAULT 'flat',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_discount` decimal(10,2) DEFAULT NULL,
  `scope_year` varchar(20) DEFAULT NULL,
  `scope_course` varchar(255) DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `per_user_once` tinyint(1) NOT NULL DEFAULT 1,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_coupon_code` (`code`),
  KEY `idx_coupon_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coupon redemptions (for per-user-once + usage tracking)
CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `coupon_code` varchar(40) NOT NULL,
  `user_id` varchar(20) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `discount_applied` decimal(10,2) NOT NULL DEFAULT 0.00,
  `redeemed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cr_code` (`coupon_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. Registration: store applied coupon / referral on the user row.
-- ----------------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `applied_coupon` varchar(40) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `referral_code` varchar(40) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `coupon_discount` decimal(10,2) DEFAULT 0.00;

-- ----------------------------------------------------------------------------
-- 10. Register new admin pages: 'alumni' (settings sub), 'marketing'.
--     (Permission keys live in includes/auth.php registry.)
-- ----------------------------------------------------------------------------

-- ============================================================================
-- DONE. After running:
--   • Settings → Alumni Database (Super Admin) to add/import alumni.
--   • CRM → Marketing for the referral program + discount coupons.
--   • Public: alumni-portal.php (PEPPian registration & referral dashboard).
--   • Admins can sign in with Google (their admins.email or google_email).
-- ============================================================================
