<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $stmt = $pdo->prepare("SELECT * FROM communication_event_mappings WHERE event_name = 'student_rejection'");
    $stmt->execute();
    $map = $stmt->fetch();
    
    if (!$map) {
        echo "Mapping not found.";
    } else {
        echo "Event Name: {$map['event_name']}\n";
        echo "Template Name: " . ($map['template_name'] ?: 'NONE') . "\n";
        echo "Parameter Mappings: " . ($map['parameter_mappings'] ?: 'NONE') . "\n";
        echo "Updated At: {$map['updated_at']}\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
