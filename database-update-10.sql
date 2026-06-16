-- ============================================================================
-- PEPP LEARNING - DATABASE UPDATE 10 : ALUMNI PROFILE + MARKETING UPDATES
-- Run ONCE in phpMyAdmin (after update 9). Safe to re-run.
-- ============================================================================

-- Alumni profile fields on the peppians table
ALTER TABLE `peppians`
  ADD COLUMN IF NOT EXISTS `current_status` varchar(20) DEFAULT NULL,            -- student | professional
  ADD COLUMN IF NOT EXISTS `academic_tracks` text DEFAULT NULL,                  -- JSON: [{course,institute}]
  ADD COLUMN IF NOT EXISTS `current_profession` varchar(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `working_institute` varchar(190) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `profile_picture` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `profile_completed` tinyint(1) NOT NULL DEFAULT 0;

-- Marketing unread-update flags (badges on the Marketing tab)
CREATE TABLE IF NOT EXISTS `marketing_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` enum('referral','coupon') NOT NULL DEFAULT 'referral',
  `detail` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `seen` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_mu_seen` (`seen`), KEY `idx_mu_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DONE.
-- ============================================================================
