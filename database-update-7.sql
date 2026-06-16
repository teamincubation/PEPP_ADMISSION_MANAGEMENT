-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 7 : FACULTIES · SESSIONS · ACCOUNTS/EXPENSES
--                                    + REMINDER POPUP TRACKING
-- Run ONCE in phpMyAdmin (after updates 1-6). Safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Reminder popup tracking — remember who has already SEEN a due reminder
--    so an offline admin gets it on next sign-in, and "skip 5 min" works.
-- ----------------------------------------------------------------------------
ALTER TABLE `reminders`
  ADD COLUMN IF NOT EXISTS `snooze_until` datetime DEFAULT NULL AFTER `remind_at`;

CREATE TABLE IF NOT EXISTS `reminder_seen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reminder_id` int(11) NOT NULL,
  `admin_username` varchar(100) NOT NULL,
  `seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_seen` (`reminder_id`, `admin_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Faculties — with per-session-type hourly charges.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faculties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `rate_live` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rate_qpd` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rate_recorded` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rate_offline` decimal(10,2) NOT NULL DEFAULT 0.00,
  `academic_year` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fac_status` (`status`),
  KEY `idx_fac_year` (`academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Faculty payments — money paid to a faculty, drawn from a payment account.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faculty_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_account_id` int(11) DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fp_faculty` (`faculty_id`),
  KEY `idx_fp_account` (`payment_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Sessions — classes/webinars taught by a faculty for one or more courses.
--    session_type: live | qpd | recorded | offline (matches faculty rates).
--    courses stored as a comma-separated list of course names (course_csv).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `topic` varchar(255) NOT NULL,
  `faculty_id` int(11) DEFAULT NULL,
  `session_datetime` datetime NOT NULL,
  `duration_hours` decimal(4,2) NOT NULL DEFAULT 1.00,
  `session_type` enum('live','qpd','recorded','offline') NOT NULL DEFAULT 'live',
  `meet_link` varchar(500) DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `course_csv` text DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sess_dt` (`session_datetime`),
  KEY `idx_sess_faculty` (`faculty_id`),
  KEY `idx_sess_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Session reminder log — which automatic learner emails have gone out, so
--    each window (12h / 4h / 10m / start) is sent only once per session.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `session_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `window_key` varchar(20) NOT NULL,
  `recipients` int(11) NOT NULL DEFAULT 0,
  `sent_by` varchar(100) DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sess_window` (`session_id`, `window_key`),
  KEY `idx_sn_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Expense types — managed in Settings; seeded from the supplied file.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expense_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_exp_type` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `expense_types` (`name`) VALUES
('AI & Automation Tools'),('Accounting & Bookkeeping'),('App Store & Developer Fees'),
('Bank & Payment Gateway Charges'),('Branding & Design Assets'),('CRM & Lead Management'),
('Cloud Hosting & Servers'),('Communication & Messaging'),('Compliance & Legal'),
('Content Creation & Editing Tools'),('Cybersecurity & Data Protection'),('Domain & DNS Management'),
('Electricity & Utilities'),('Email & Office Productivity'),('Employee Welfare'),
('Event & Webinar Expenses'),('Freelance & Consultant Payments'),('GST & Tax Filing'),
('HR & Payroll Expenses'),('Hardware & Office Equipment'),('Internet & Broadband'),
('Learning Management System'),('Marketing & Advertising'),('Mobile & SIM Recharges'),
('Office Rent & Workspace'),('Online Meeting Tools'),('Printing & Stationery'),
('Professional Services'),('Software Subscriptions'),('Student Support Tools'),
('Training & Skill Development'),('Travel & Local Conveyance'),('Video Editing & Media Tools'),
('Website & Landing Page Tools'),('WhatsApp & SMS Marketing');

-- ----------------------------------------------------------------------------
-- 7. Expenses — administrative spending recorded against a payment account.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purpose` varchar(255) NOT NULL,
  `expense_type` varchar(150) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `remarks` varchar(500) DEFAULT NULL,
  `payment_account_id` int(11) DEFAULT NULL,
  `spent_date` date DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exp_type` (`expense_type`),
  KEY `idx_exp_account` (`payment_account_id`),
  KEY `idx_exp_date` (`spent_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DONE. After running:
--   • Academics → Faculties, Students → Sessions, CRM → Accounts appear.
--   • Grant 'faculties', 'sessions' and 'accounts' pages to admins in
--     Admin Management (Super Admin).
--   • Settings → Expense Types to manage the categories.
-- ============================================================================
