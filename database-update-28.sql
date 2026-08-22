-- Database update script to add 'paused' status to communication_queue
ALTER TABLE `communication_queue` 
  MODIFY COLUMN `status` ENUM('pending', 'processing', 'sent', 'delivered', 'read', 'failed', 'cancelled', 'scheduled', 'paused') NOT NULL DEFAULT 'pending';
