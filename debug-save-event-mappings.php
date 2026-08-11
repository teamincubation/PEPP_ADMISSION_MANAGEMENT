<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    // 1. Reset / update student_registration mapping
    $stmt1 = $pdo->prepare("
        UPDATE communication_event_mappings 
        SET template_name = 'pepp_admission_received',
            parameter_mappings = ?
        WHERE event_name = 'student_registration'
    ");
    $param1 = json_encode([
        "1" => ["type" => "variable", "value" => "student_name"],
        "2" => ["type" => "variable", "value" => "course_name"],
        "3" => ["type" => "variable", "value" => "application_id"]
    ]);
    $stmt1->execute([$param1]);
    echo "Successfully updated student_registration mapping.\n";

    // 2. Set student_rejection mapping
    $stmt2 = $pdo->prepare("
        UPDATE communication_event_mappings 
        SET template_name = 'pepp_admission_rejected',
            parameter_mappings = ?
        WHERE event_name = 'student_rejection'
    ");
    $param2 = json_encode([
        "1" => ["type" => "variable", "value" => "student_name"],
        "2" => ["type" => "variable", "value" => "course_name"],
        "3" => ["type" => "variable", "value" => "academic_year"]
    ]);
    $stmt2->execute([$param2]);
    echo "Successfully updated student_rejection mapping.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
