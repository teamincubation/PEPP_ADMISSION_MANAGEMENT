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

    echo json_encode(['success' => true, 'messages' => $messages]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
