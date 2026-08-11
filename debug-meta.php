<?php
require_once 'config/database.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT meta_data FROM communication_templates WHERE template_name = ? LIMIT 1");
    $stmt->execute(['pepp_admission_approved']);
    $meta_json = $stmt->fetchColumn();
    
    if ($meta_json) {
        $meta = json_decode($meta_json, true);
        echo json_encode([
            'success' => true,
            'template_name' => 'pepp_admission_approved',
            'components' => $meta['components'] ?? [],
            'body_text' => $meta['body_text'] ?? ''
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Template pepp_admission_approved not found in database.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
