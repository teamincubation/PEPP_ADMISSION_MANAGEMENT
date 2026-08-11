<?php
require_once 'config/database.php';

try {
    $stmt = $pdo->prepare("SELECT meta_data FROM communication_templates WHERE template_name = ? LIMIT 1");
    $stmt->execute(['pepp_admission_approved']);
    $meta_json = $stmt->fetchColumn();
    
    if ($meta_json) {
        $meta = json_decode($meta_json, true);
        $buttons = [];
        foreach ($meta['components'] ?? [] as $comp) {
            if (($comp['type'] ?? '') === 'BUTTONS') {
                $buttons[] = $comp;
            }
        }
        echo base64_encode(json_encode($buttons));
    } else {
        echo "TEMPLATE NOT FOUND";
    }
} catch (Exception $e) {
    echo "ERROR: " . base64_encode($e->getMessage());
}
