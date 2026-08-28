-- PEPP ERP Database Update 35
-- Add database performance index to communication_queue

ALTER TABLE communication_queue ADD INDEX idx_status_channel_next_attempt (status, channel, next_attempt_at, created_at);
