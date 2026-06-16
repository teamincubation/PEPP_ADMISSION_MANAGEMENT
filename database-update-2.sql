-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 2 : ADMIN ROLES & ACTIVITY TRACKING
-- Run ONCE in phpMyAdmin (after database-update.sql). Safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Admins table — multiple admin accounts with roles & page permissions.
--    role: 'super_admin' (full control) or 'admin' (page-restricted)
--    permissions: 'ALL' or comma-separated page keys, e.g.
--                 'dashboard,approvals,students,onboarding'
--    Page keys map to the sidebar; new pages added to the registry in
--    includes/auth.php automatically appear in Admin Management for granting.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `full_name` varchar(150) DEFAULT NULL,
  `role` enum('super_admin','admin') NOT NULL DEFAULT 'admin',
  `permissions` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Seed the Super Admin from the existing credentials.
--    • If you changed the password in Settings, that hash is copied over.
--    • Fresh installs get username 'peppadmin' with an EMPTY hash: the new
--      login.php accepts the default password (admin123@pepp) exactly once
--      for an empty hash and immediately stores a secure hash for it.
--    Runs only if no super admin exists yet.
-- ----------------------------------------------------------------------------
INSERT INTO `admins` (`username`, `password_hash`, `full_name`, `role`, `permissions`, `status`, `created_by`)
SELECT
    COALESCE((SELECT setting_value FROM admin_settings WHERE setting_name = 'admin_username' LIMIT 1), 'peppadmin'),
    COALESCE((SELECT setting_value FROM admin_settings WHERE setting_name = 'admin_password_hash' LIMIT 1), ''),
    'Super Administrator',
    'super_admin',
    'ALL',
    'active',
    'system'
WHERE NOT EXISTS (SELECT 1 FROM `admins` WHERE `role` = 'super_admin');

-- ----------------------------------------------------------------------------
-- 3. Admin activity log — logins, logouts, auto-logouts, exports and other
--    admin-level events, with IP address and approximate location.
--    (Per-student actions continue to live in track_records, with the acting
--    admin in performed_by; the Activity Log page merges both views.)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_username` varchar(100) NOT NULL,
  `action_type` varchar(60) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `location` varchar(190) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_aal_admin` (`admin_username`),
  KEY `idx_aal_type` (`action_type`),
  KEY `idx_aal_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Index for the activity report joining track_records by admin.
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_track_performed_by ON track_records (performed_by);
CREATE INDEX IF NOT EXISTS idx_track_performed_at ON track_records (performed_at);

-- ============================================================================
-- DONE. After running:
--   • log in as the Super Admin (your current credentials)
--   • System → Admin Management to create restricted admin accounts
--   • Non-super admins auto-logout after 20 minutes of inactivity
--   • System → Activity Log / Reports are visible to the Super Admin only
-- ============================================================================
