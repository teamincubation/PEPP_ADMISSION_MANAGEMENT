-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 5 : ADMIN CONTACT FIELDS + REMINDERS
-- Run ONCE in phpMyAdmin (after updates 1-4). Safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Admin contact details (used for reminder email notifications).
-- ----------------------------------------------------------------------------
ALTER TABLE `admins`
  ADD COLUMN IF NOT EXISTS `email` varchar(190) DEFAULT NULL AFTER `full_name`,
  ADD COLUMN IF NOT EXISTS `phone` varchar(20) DEFAULT NULL AFTER `email`;

-- ----------------------------------------------------------------------------
-- 2. Reminders / tasks. Assigned to a specific admin, or to ALL admins
--    (assigned_to = '__ALL__'). When the scheduled time arrives the admin
--    sees an urgent animated alert and receives a no-reply email once.
--    status: pending | completed | dismissed
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `remind_at` datetime NOT NULL,
  `assigned_to` varchar(100) NOT NULL DEFAULT '__ALL__',
  `status` enum('pending','completed','dismissed') NOT NULL DEFAULT 'pending',
  `created_by` varchar(100) DEFAULT NULL,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `completed_by` varchar(100) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rem_assigned` (`assigned_to`),
  KEY `idx_rem_status` (`status`),
  KEY `idx_rem_time` (`remind_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DONE. After running:
--   • Admin Management now has Email and Phone fields (add/edit).
--   • A Reminders button appears in the top bar of every admin page.
-- ============================================================================
