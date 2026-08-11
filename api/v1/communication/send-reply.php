<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_permission('communication');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify CSRF
if (!csrf_verify()) {
    http_response_code(400);
    echo json_encode(['error' => 'Security token mismatch. Please reload the page and try again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$convId = isset($input['conversation_id']) ? (int)$input['conversation_id'] : 0;
$messageText = trim($input['message_text'] ?? '');
$templateName = trim($input['template_name'] ?? '');

if ($convId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid conversation_id']);
    exit;
}

try {
    // 1. Fetch conversation
    $stmtConv = $pdo->prepare("SELECT * FROM whatsapp_conversations WHERE id = ? LIMIT 1");
    $stmtConv->execute([$convId]);
    $conv = $stmtConv->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found']);
        exit;
    }

    $recipient = $conv['wa_phone_number'];
    $studentUid = $conv['student_uid'];
    $studentName = $conv['contact_name'];

    require_once '../../../includes/communication/CommunicationEngine.php';
    $engine = CommunicationEngine::getInstance($pdo);

    $templateData = [];
    $bodyText = '';

    // 2. Validate Customer Service Window (24 hours rule)
    $lastInbound = $conv['last_inbound_at'];
    $canSendFree = false;
    if ($lastInbound) {
        $canSendFree = (time() - strtotime($lastInbound)) <= 86400;
    }

    if (empty($templateName)) {
        // Free-form reply
        if (!$canSendFree) {
            http_response_code(400);
            echo json_encode(['error' => '24-hour response window has expired. You must use a template message.']);
            exit;
        }

        if (empty($messageText)) {
            http_response_code(400);
            echo json_encode(['error' => 'Message text cannot be empty']);
            exit;
        }

        $bodyText = $messageText;
    } else {
        // Template reply (Safeguard 3: Dynamically resolve parameters and prevent custom hardcoding)
        $stmtMapping = $pdo->prepare("SELECT event_name FROM communication_event_mappings WHERE template_name = ? LIMIT 1");
        $stmtMapping->execute([$templateName]);
        $eventName = $stmtMapping->fetchColumn();

        if (!$eventName) {
            $eventName = 'installment_reminder'; // Fallback
        }

        // Fetch template details to verify language & status
        $stmtTpl = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? LIMIT 1");
        $stmtTpl->execute([$templateName]);
        $template = $stmtTpl->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
            exit;
        }

        if (strtolower($template['status']) !== 'approved') {
            http_response_code(400);
            echo json_encode(['error' => 'Selected template is not approved']);
            exit;
        }

        // Resolve parameter mappings for the specific student (Safeguard 3)
        $resolved = $engine->resolveEventTemplate($eventName, $studentUid);
        if (!$resolved) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to resolve template variables for student']);
            exit;
        }

        $templateData = [
            'name' => $templateName,
            'language' => $resolved['language'] ?? 'en',
            'parameters' => $resolved['parameters'] ?? []
        ];

        // Format resolved body text snippet for local storage preview
        $meta = json_decode($template['meta_data'], true) ?: [];
        $bodyText = $meta['body_text'] ?? '';
        foreach ($templateData['parameters'] as $idx => $val) {
            $placeholder = '{{' . ($idx + 1) . '}}';
            $bodyText = str_replace($placeholder, $val, $bodyText);
        }
    }

    $adminUser = $_SESSION['username'] ?? 'admin';

    // 3. Queue the outbound message
    $queueId = $engine->queueMessage(
        'whatsapp',
        $recipient,
        $studentName,
        empty($templateName) ? "Admin Reply" : "Template Reply: {$templateName}",
        $bodyText,
        $bodyText,
        [], // attachments
        $templateData,
        $adminUser,
        null, // scheduledAt
        $studentUid,
        empty($templateName) ? 'admin_reply' : $eventName
    );

    if ($queueId) {
        // Trigger immediate background dispatch
        $engine->dispatchQueueItemAsync($queueId);

        // Reset conversation unread status when admin replies
        $updConv = $pdo->prepare("UPDATE whatsapp_conversations SET unread_count = 0, updated_at = NOW() WHERE id = ?");
        $updConv->execute([$convId]);

        echo json_encode([
            'success' => true,
            'queue_id' => $queueId,
            'message' => 'Reply queued successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to enqueue reply message']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
