<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    echo "=== SYNCHRONIZED TEMPLATE METADATA ===\n\n";
    $stmt = $pdo->query("SELECT * FROM communication_templates WHERE template_name IN ('pepp_admission_rejected', 'pepp_installment_reminder')");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($templates as $tpl) {
        echo "Template Name: {$tpl['template_name']}\n";
        echo "  Status: {$tpl['status']}\n";
        echo "  Category: {$tpl['category']}\n";
        echo "  Language: {$tpl['language']}\n";
        
        $meta = json_decode($tpl['meta_data'], true) ?: [];
        $body = $meta['body_text'] ?? '';
        echo "  Body:\n  \"" . str_replace("\n", "\n  ", $body) . "\"\n";
        
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        $expectedParamsCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
        echo "  Parameters count: {$expectedParamsCount}\n\n";
    }
    
    echo "=== CURRENT ERP EVENT MAPPINGS ===\n\n";
    $stmt2 = $pdo->query("SELECT * FROM communication_event_mappings WHERE event_name IN ('student_rejection', 'installment_reminder')");
    $mappings = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($mappings as $map) {
        echo "Event Name: {$map['event_name']}\n";
        echo "  Mapped Template: " . ($map['template_name'] ?: 'NONE') . "\n";
        echo "  Parameter Mappings: " . ($map['parameter_mappings'] ?: 'NONE') . "\n\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
