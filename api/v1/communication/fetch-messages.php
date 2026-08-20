<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
require_permission('communication');

header('Content-Type: application/json');

$convId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;

if ($convId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid conversation_id']);
    exit;
}

function get_resolved_message_text($pdo, $row) {
    $text = $row['message_text'] ?? '';
    
    // Check if it's a legacy technical parameter dump
    if (strpos($text, 'WhatsApp Template: ') === 0) {
        $rawPayload = json_decode($row['raw_payload'] ?? '', true);
        if ($rawPayload && isset($rawPayload['name']) && isset($rawPayload['parameters'])) {
            $tplName = $rawPayload['name'];
            $params = $rawPayload['parameters'];
            
            static $tplCache = [];
            if (!isset($tplCache[$tplName])) {
                $stmt = $pdo->prepare("SELECT meta_data, updated_at FROM communication_templates WHERE template_name = ? LIMIT 1");
                $stmt->execute([$tplName]);
                $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
                $tplCache[$tplName] = $tpl ?: false;
            }
            
            $tpl = $tplCache[$tplName];
            if ($tpl) {
                // Ensure the template has not been updated since the message was created
                $msgTime = strtotime($row['created_at']);
                $tplUpdateTime = strtotime($tpl['updated_at']);
                
                if ($tplUpdateTime <= $msgTime) {
                    $meta = json_decode($tpl['meta_data'] ?? '', true) ?: [];
                    $bodyText = $meta['body_text'] ?? '';
                    if (!empty($bodyText)) {
                        // Count expected placeholders
                        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);
                        $expectedParamsCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
                        
                        // Only reconstruct if parameters count matches expectation
                        if (count($params) >= $expectedParamsCount) {
                            $compiled = $bodyText;
                            foreach ($params as $idx => $val) {
                                $placeholder = '{{' . ($idx + 1) . '}}';
                                $compiled = str_replace($placeholder, $val, $compiled);
                            }
                            return $compiled;
                        }
                    }
                }
            }
        }
    }
    return $text;
}

try {
    // 1. Reset unread count inside the ERP (marks the thread as read) only if explicitly requested
    $markRead = isset($_GET['mark_read']) && $_GET['mark_read'] === '1';
    if ($markRead) {
        $upd = $pdo->prepare("UPDATE whatsapp_conversations SET unread_count = 0, updated_at = NOW() WHERE id = ?");
        $upd->execute([$convId]);
    }

    // 2. Fetch message history
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE conversation_id = ? ORDER BY created_at ASC");
    $stmt->execute([$convId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($messages as &$m) {
        $m['message_text'] = get_resolved_message_text($pdo, $m);
    }
    unset($m);

    echo json_encode(['success' => true, 'messages' => $messages]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
