-- Database update 14: Add latitude and longitude to study_plan_analytics
ALTER TABLE `study_plan_analytics` 
ADD COLUMN `latitude` varchar(50) DEFAULT NULL,
ADD COLUMN `longitude` varchar(50) DEFAULT NULL;
