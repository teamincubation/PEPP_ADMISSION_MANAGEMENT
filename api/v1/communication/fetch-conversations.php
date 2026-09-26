<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
if (!can_access('whatsapp-inbox') && !can_access('communication')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: WhatsApp Inbox permission required.']);
    exit;
}

header('Content-Type: application/json');

$filter = $_GET['filter'] ?? 'all'; // all, unread, students, unknown
$search = trim($_GET['search'] ?? '');

$sql = "SELECT wc.*,
               (
                   SELECT direction
                   FROM whatsapp_messages
                   WHERE conversation_id = wc.id
                   ORDER BY created_at DESC, id DESC
                   LIMIT 1
               ) AS latest_message_direction,
               (
                   SELECT status
                   FROM whatsapp_messages
                   WHERE conversation_id = wc.id
                   ORDER BY created_at DESC, id DESC
                   LIMIT 1
               ) AS latest_message_status
        FROM whatsapp_conversations wc
        WHERE 1=1";
$params = [];

if ($filter === 'unread') {
    $sql .= " AND unread_count > 0";
} elseif ($filter === 'students') {
    $sql .= " AND student_uid IS NOT NULL";
} elseif ($filter === 'unknown') {
    $sql .= " AND student_uid IS NULL";
}

if ($search !== '') {
    $sql .= " AND (contact_name LIKE ? OR wa_phone_number LIKE ? OR student_uid LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY last_message_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve legacy technical parameter dumps for sidebar snippet preview
    foreach ($conversations as &$c) {
        if (strpos($c['last_message_text'] ?? '', 'WhatsApp Template: ') === 0) {
            $stmtLastMsg = $pdo->prepare("SELECT message_text, raw_payload, created_at FROM whatsapp_messages WHERE conversation_id = ? AND direction = 'outbound' ORDER BY created_at DESC LIMIT 1");
            $stmtLastMsg->execute([$c['id']]);
            $lastMsg = $stmtLastMsg->fetch(PDO::FETCH_ASSOC);
            if ($lastMsg) {
                $rawPayload = json_decode($lastMsg['raw_payload'] ?? '', true);
                if ($rawPayload && isset($rawPayload['name']) && isset($rawPayload['parameters'])) {
                    $tplName = $rawPayload['name'];
                    $paramsList = $rawPayload['parameters'];

                    static $tplCache = [];
                    if (!isset($tplCache[$tplName])) {
                        $stmtTpl = $pdo->prepare("SELECT meta_data, updated_at FROM communication_templates WHERE template_name = ? LIMIT 1");
                        $stmtTpl->execute([$tplName]);
                        $tpl = $stmtTpl->fetch(PDO::FETCH_ASSOC);
                        $tplCache[$tplName] = $tpl ?: false;
                    }

                    $tpl = $tplCache[$tplName];
                    if ($tpl) {
                        $msgTime = strtotime($lastMsg['created_at']);
                        $tplUpdateTime = strtotime($tpl['updated_at']);
                        if ($tplUpdateTime <= $msgTime) {
                            $meta = json_decode($tpl['meta_data'] ?? '', true) ?: [];
                            $bodyText = $meta['body_text'] ?? '';
                            if (!empty($bodyText)) {
                                preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);
                                $expectedParamsCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
                                if (count($paramsList) >= $expectedParamsCount) {
                                    $compiled = $bodyText;
                                    foreach ($paramsList as $idx => $val) {
                                        $placeholder = '{{' . ($idx + 1) . '}}';
                                        $compiled = str_replace($placeholder, $val, $compiled);
                                    }
                                    $c['last_message_text'] = $compiled;
                                }
                            }
                        }
                    }
                }
            }
        } elseif (strpos($c['last_message_text'] ?? '', '[Unsupported message type:') === 0) {
            $stmtLastMsg = $pdo->prepare("SELECT message_type, raw_payload, caption, media_filename FROM whatsapp_messages WHERE conversation_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
            $stmtLastMsg->execute([$c['id']]);
            $lastMsg = $stmtLastMsg->fetch(PDO::FETCH_ASSOC);
            if ($lastMsg) {
                $mType = $lastMsg['message_type'] ?? '';
                if ($mType === 'reaction') {
                    $rawP = json_decode($lastMsg['raw_payload'] ?? '', true);
                    $rx = $rawP['entry'][0]['changes'][0]['value']['messages'][0]['reaction'] ?? $rawP['reaction'] ?? null;
                    $emoji = $rx['emoji'] ?? '';
                    $c['last_message_text'] = !empty($emoji) ? "Reacted {$emoji}" : "Removed reaction";
                } elseif ($mType === 'image') {
                    $c['last_message_text'] = !empty($lastMsg['caption']) ? "📷 " . $lastMsg['caption'] : "📷 Photo";
                } elseif ($mType === 'audio') {
                    $c['last_message_text'] = "🎙️ Voice message";
                } elseif ($mType === 'video') {
                    $c['last_message_text'] = !empty($lastMsg['caption']) ? "🎥 " . $lastMsg['caption'] : "🎥 Video";
                } elseif ($mType === 'document') {
                    $c['last_message_text'] = !empty($lastMsg['media_filename']) ? "📄 " . $lastMsg['media_filename'] : "📄 Document";
                } elseif ($mType === 'sticker') {
                    $c['last_message_text'] = "🏷️ Sticker";
                } elseif ($mType === 'location') {
                    $c['last_message_text'] = "📍 Location";
                } elseif ($mType === 'contacts') {
                    $c['last_message_text'] = "👤 Contact";
                }
            }
        }
    }
    unset($c);

    echo json_encode(['success' => true, 'conversations' => $conversations]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
