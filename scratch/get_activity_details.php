<?php
$_SERVER['SERVER_NAME'] = 'pepplearning.in';
require_once __DIR__ . '/../config/database.php';

echo "<pre>";
echo "=== Database Debug Info ===\n\n";

// 1. Show pepp_courses
echo "--- pepp_courses ---\n";
$stmt = $pdo->query("SELECT id, course_name, academic_year, status FROM pepp_courses");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// 2. Show assessment_result_batches where status = 'published'
echo "\n--- assessment_result_batches ---\n";
$stmt2 = $pdo->query("SELECT id, activity_id, study_plan_id, academic_year, course_id, course_name, activity_title_snapshot, status, version FROM assessment_result_batches");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

// 3. Show study_plan_activities for specific IDs
echo "\n--- study_plan_activities for IDs 26305, 26335, 26343 ---\n";
$stmt3 = $pdo->query("SELECT id, study_plan_id, activity_title, activity_type, chapter FROM study_plan_activities WHERE id IN (26305, 26335, 26343)");
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));

echo "</pre>";
