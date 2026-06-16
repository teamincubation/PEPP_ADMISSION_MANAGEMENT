-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 4 : LEAD MANAGEMENT (CRM)
-- Run ONCE in phpMyAdmin (after updates 1, 2 and 3). Safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Leads — prospective students captured before they register.
--    whatsapp_number is mandatory. next_followup_date is required until the
--    lead is marked 'converted' or 'rejected'. status uses a standard CRM
--    pipeline. Every change is recorded in lead_activity (below).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `whatsapp_number` varchar(20) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `interested_course` varchar(255) DEFAULT NULL,
  `last_institute` varchar(255) DEFAULT NULL,
  `last_course` varchar(255) DEFAULT NULL,
  `is_fyugp` enum('yes','no') DEFAULT NULL,
  `year_of_study` enum('First Year','Second Year','Third Year','Fourth Year','Completed') DEFAULT NULL,
  `status` enum('new','contacted','follow_up','interested','not_interested','converted','rejected') NOT NULL DEFAULT 'new',
  `next_followup_date` date DEFAULT NULL,
  `followup_count` int(11) NOT NULL DEFAULT 0,
  `assigned_to` varchar(100) DEFAULT NULL,
  `source` varchar(60) DEFAULT 'manual',
  `converted_user_id` varchar(20) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `last_activity_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lead_status` (`status`),
  KEY `idx_lead_followup` (`next_followup_date`),
  KEY `idx_lead_assigned` (`assigned_to`),
  KEY `idx_lead_whatsapp` (`whatsapp_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Lead activity — full timeline of every remark, status change, follow-up
--    and reassignment, with the acting admin and timestamp. This is what powers
--    "show all remarks and number of follow-ups, managed admin details, and
--    date & time of each update" on the lead detail view.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `activity_type` varchar(40) NOT NULL DEFAULT 'remark',
  `remark` text DEFAULT NULL,
  `old_status` varchar(40) DEFAULT NULL,
  `new_status` varchar(40) DEFAULT NULL,
  `followup_date` date DEFAULT NULL,
  `performed_by` varchar(100) DEFAULT NULL,
  `performed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_la_lead` (`lead_id`),
  KEY `idx_la_time` (`performed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Register the new page so the Super Admin can grant access to it from
--    Admin Management (the page registry in includes/auth.php also lists it).
-- ----------------------------------------------------------------------------
-- (No data needed — access is controlled by the 'leads' permission key.)

-- ============================================================================
-- DONE. After running:
--   • Upload lead-management.php, lead-details.php and the updated includes.
--   • Super Admin → Admin Management to grant the "Lead Management" page to
--     the admins who should handle leads.
--   • Leads → Add Lead, or bulk-import an Excel/CSV file.
-- ============================================================================
