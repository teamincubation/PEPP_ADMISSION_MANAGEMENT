<?php
session_start();
header('Content-Type: text/plain');

require_once 'config/database.php';

try {
    // Modify course_id in study_plan_chapters to be VARCHAR(255)
    $pdo->exec("ALTER TABLE study_plan_chapters MODIFY COLUMN course_id VARCHAR(255) NOT NULL");
    echo "DATABASE_MIGRATION_SUCCESSFUL\n";
} catch (Exception $e) {
    echo "DATABASE_MIGRATION_FAILED: " . $e->getMessage() . "\n";
}
