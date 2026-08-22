<?php
/**
 * Read-only script to inspect course names and formats across leads, users, and pepp_courses.
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');

try {
    echo "========================================================\n";
    echo "            COURSES INTEGRITY & MATCHING AUDIT\n";
    echo "========================================================\n\n";

    // 1. Fetch distinct courses from pepp_courses
    $stmt1 = $pdo->query("SELECT id, course_name, course_code FROM pepp_courses ORDER BY id ASC");
    $peppCourses = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    echo "--- CANONICAL COURSES (pepp_courses) ---\n";
    foreach ($peppCourses as $pc) {
        echo "[ID {$pc['id']}] Name: '{$pc['course_name']}' | Code: '{$pc['course_code']}'\n";
    }
    echo "\n";

    // 2. Fetch distinct courses from users (admissions)
    $stmt2 = $pdo->query("SELECT DISTINCT pepp_course FROM users WHERE pepp_course IS NOT NULL AND pepp_course <> '' ORDER BY pepp_course ASC");
    $usersCourses = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    echo "--- ACTIVE ADMISSION COURSES (users.pepp_course) ---\n";
    foreach ($usersCourses as $uc) {
        echo "- '{$uc}'\n";
    }
    echo "\n";

    // 3. Fetch distinct courses from leads
    $stmt3 = $pdo->query("SELECT DISTINCT interested_course FROM leads WHERE interested_course IS NOT NULL AND interested_course <> '' ORDER BY interested_course ASC");
    $leadsCourses = $stmt3->fetchAll(PDO::FETCH_COLUMN);
    echo "--- ACTIVE LEAD INTERESTED COURSES (leads.interested_course) ---\n";
    foreach ($leadsCourses as $lc) {
        echo "- '{$lc}'\n";
    }
    echo "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
