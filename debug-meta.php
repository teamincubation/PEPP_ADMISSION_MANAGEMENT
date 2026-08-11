<?php
require_once 'config/database.php';

try {
    $stmt = $pdo->prepare("SELECT meta_data FROM communication_templates WHERE template_name = ? LIMIT 1");
    $stmt->execute(['pepp_admission_approved']);
    $meta_json = $stmt->fetchColumn();
    
    if ($meta_json) {
        $meta = json_decode($meta_json, true);
        $found = false;
        foreach ($meta['components'] ?? [] as $comp) {
            if (($comp['type'] ?? '') === 'BUTTONS' && isset($comp['buttons']) && is_array($comp['buttons'])) {
                foreach ($comp['buttons'] as $btn) {
                    if (($btn['type'] ?? '') === 'URL') {
                        echo "URL IS: " . ($btn['url'] ?? 'NOT_FOUND') . "\n";
                        $found = true;
                    }
                }
            }
        }
        if (!$found) {
            echo "URL NOT FOUND IN BUTTONS COMPONENT\n";
        }
    } else {
        echo "TEMPLATE NOT FOUND IN DB\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

