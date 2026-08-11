<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $stmt = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = 'pepp_admission_rejected'");
    $stmt->execute();
    $tpl = $stmt->fetch();
    
    if (!$tpl) {
        echo "Template pepp_admission_rejected NOT found in communication_templates table.\n";
        
        echo "\nALL LOCAL TEMPLATES:\n";
        foreach ($pdo->query("SELECT template_name, status, category FROM communication_templates") as $r) {
            echo "- {$r['template_name']} ({$r['status']}, {$r['category']})\n";
        }
    } else {
        echo "Template Name: {$tpl['template_name']}\n";
        echo "Status: {$tpl['status']}\n";
        echo "Category: {$tpl['category']}\n";
        echo "Meta Data: " . $tpl['meta_data'] . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
