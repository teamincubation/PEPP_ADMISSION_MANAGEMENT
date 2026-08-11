<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    echo "=== PEPP COMMUNICATION EVENT MAPPINGS ===\n\n";
    
    $stmt = $pdo->query("SELECT * FROM communication_event_mappings");
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($mappings as $map) {
        echo "Event Name: {$map['event_name']}\n";
        echo "  Template Name: " . ($map['template_name'] ?: 'NONE') . "\n";
        echo "  Parameter Mappings: " . ($map['parameter_mappings'] ?: 'NONE') . "\n";
        echo "  Updated At: {$map['updated_at']}\n\n";
    }
    
    echo "=== PEPP COMMUNICATION TEMPLATES ===\n\n";
    
    $stmt2 = $pdo->query("SELECT id, template_name, channel, language, status, category FROM communication_templates");
    $templates = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($templates as $tpl) {
        echo "Template Name: {$tpl['template_name']}\n";
        echo "  Channel: {$tpl['channel']}\n";
        echo "  Language: {$tpl['language']}\n";
        echo "  Status: {$tpl['status']}\n";
        echo "  Category: {$tpl['category']}\n\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
