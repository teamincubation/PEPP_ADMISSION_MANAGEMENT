<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_permission('communication');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$templateName = $_GET['template_name'] ?? '';
$studentUid = $_GET['student_uid'] ?? '';

if (empty($templateName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing template_name']);
    exit;
}

try {
    $stmtTpl = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? LIMIT 1");
    $stmtTpl->execute([$templateName]);
    $template = $stmtTpl->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        http_response_code(404);
        echo json_encode(['error' => 'Template not found']);
        exit;
    }

    require_once '../../../includes/communication/CommunicationEngine.php';
    $engine = CommunicationEngine::getInstance($pdo);

    // Find the mapped event for this template name
    $stmtMapping = $pdo->prepare("SELECT event_name FROM communication_event_mappings WHERE template_name = ? LIMIT 1");
    $stmtMapping->execute([$templateName]);
    $eventName = $stmtMapping->fetchColumn();

    if (!$eventName) {
        // Safe fallback in case mapping is missing
        $eventName = 'installment_reminder';
    }

    // Resolve parameter mappings for the specific student (Safeguard 3)
    $resolved = $engine->resolveEventTemplate($eventName, $studentUid);
    
    // Compile a rich body preview
    $meta = json_decode($template['meta_data'], true) ?: [];
    $bodyText = $meta['body_text'] ?? '';
    
    $params = $resolved['parameters'] ?? [];
    $compiledBody = $bodyText;
    foreach ($params as $idx => $val) {
        $placeholder = '{{' . ($idx + 1) . '}}';
        $compiledBody = str_replace($placeholder, '*' . $val . '*', $compiledBody);
    }

    echo json_encode([
        'success' => true,
        'template_name' => $templateName,
        'language' => $resolved['language'] ?? 'en',
        'parameters' => $params,
        'preview_body' => $compiledBody
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
