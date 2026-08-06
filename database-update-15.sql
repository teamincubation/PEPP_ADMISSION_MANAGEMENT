-- Database update 15: Add plan_type and total_days to study_plans table
ALTER TABLE `study_plans` 
ADD COLUMN `plan_type` ENUM('date_wise', 'day_wise') NOT NULL DEFAULT 'date_wise',
ADD COLUMN `total_days` INT DEFAULT NULL;
