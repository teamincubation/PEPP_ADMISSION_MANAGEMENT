-- Database update script to add performance latency metrics to communication_queue
ALTER TABLE `communication_queue`
  ADD COLUMN `worker_started_at` DATETIME DEFAULT NULL AFTER `last_retry_at`,
  ADD COLUMN `api_requested_at` DATETIME DEFAULT NULL AFTER `worker_started_at`,
  ADD COLUMN `api_responded_at` DATETIME DEFAULT NULL AFTER `api_requested_at`,
  ADD COLUMN `delivered_at` DATETIME DEFAULT NULL AFTER `api_responded_at`;
