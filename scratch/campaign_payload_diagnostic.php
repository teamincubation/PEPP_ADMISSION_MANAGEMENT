<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? LIMIT 1");
    $stmt->execute(['m_clin_psy_rci_admission_started']);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($template) {
        $template['meta_data_decoded'] = json_decode($template['meta_data'], true);
    }

    echo json_encode([
        'success' => true,
        'template' => $template
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
