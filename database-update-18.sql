CREATE TABLE IF NOT EXISTS `installment_whatsapp_reminders` (
  `installment_id` INT NOT NULL,
  `reminder_stage` VARCHAR(15) NOT NULL,
  `status` ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'queued',
  `queue_id` INT DEFAULT NULL,
  `last_attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`installment_id`, `reminder_stage`),
  KEY `idx_queue_id` (`queue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
