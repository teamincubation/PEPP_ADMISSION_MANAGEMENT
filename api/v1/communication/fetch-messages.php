<?php
require_once '../../../includes/auth.php';
require_once '../../../config/database.php';
if (!can_access('whatsapp-inbox') && !can_access('communication')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: WhatsApp Inbox permission required.']);
    exit;
}

header('Content-Type: application/json');

$convId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;

if ($convId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid conversation_id']);
    exit;
}

function extract_message_payload($rawPayload) {
    if (empty($rawPayload)) return null;
    $decoded = is_array($rawPayload) ? $rawPayload : json_decode($rawPayload, true);
    if (!is_array($decoded)) return null;
    if (isset($decoded['entry'][0]['changes'][0]['value']['messages'][0])) {
        return $decoded['entry'][0]['changes'][0]['value']['messages'][0];
    }
    return $decoded;
}

function get_resolved_message_text($pdo, $row) {
    $text = $row['message_text'] ?? '';
    $type = $row['message_type'] ?? 'text';
    $rawPayload = extract_message_payload($row['raw_payload'] ?? '');

    // 1. Resolve WhatsApp Template parameter dumps
    if (strpos($text, 'WhatsApp Template: ') === 0) {
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
                $msgTime = strtotime($row['created_at']);
                $tplUpdateTime = strtotime($tpl['updated_at']);

                if ($tplUpdateTime <= $msgTime) {
                    $meta = json_decode($tpl['meta_data'] ?? '', true) ?: [];
                    $bodyText = $meta['body_text'] ?? '';
                    if (!empty($bodyText)) {
                        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);
                        $expectedParamsCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;

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

    // 2. Resolve reaction
    if ($type === 'reaction' || strpos($text, '[Unsupported message type: reaction]') === 0) {
        $rx = $rawPayload['reaction'] ?? null;
        $emoji = $rx['emoji'] ?? '';
        return !empty($emoji) ? "Reacted {$emoji}" : "Removed reaction";
    }

    // 3. Resolve location
    if ($type === 'location' || strpos($text, '[Unsupported message type: location]') === 0) {
        $loc = $rawPayload['location'] ?? null;
        if ($loc) {
            $name = $loc['name'] ?? '';
            $addr = $loc['address'] ?? '';
            $lat  = $loc['latitude'] ?? '';
            $lng  = $loc['longitude'] ?? '';
            if ($name && $addr) return "{$name} - {$addr}";
            if ($name) return $name;
            if ($addr) return $addr;
            if ($lat !== '' && $lng !== '') return "Location ({$lat}, {$lng})";
        }
        return "Location";
    }

    // 4. Resolve contacts
    if ($type === 'contacts' || strpos($text, '[Unsupported message type: contacts]') === 0) {
        $contacts = $rawPayload['contacts'] ?? [];
        if (!empty($contacts)) {
            $name = $contacts[0]['name']['formatted_name'] ?? $contacts[0]['name']['first_name'] ?? 'Contact';
            return "Contact: {$name}";
        }
        return "Contact";
    }

    // 5. Resolve sticker
    if ($type === 'sticker' || strpos($text, '[Unsupported message type: sticker]') === 0) {
        return "Sticker";
    }

    // 6. Resolve audio
    if ($type === 'audio' && ($text === '' || strpos($text, '[Unsupported') === 0)) {
        return "Voice message";
    }

    // 7. Resolve video
    if ($type === 'video' && ($text === '' || strpos($text, '[Unsupported') === 0)) {
        return !empty($row['caption']) ? $row['caption'] : "Video";
    }

    // 8. Resolve document
    if ($type === 'document' && ($text === '' || strpos($text, '[Unsupported') === 0)) {
        return !empty($row['media_filename']) ? $row['media_filename'] : "Document";
    }

    // 9. Resolve image
    if ($type === 'image' && ($text === '' || strpos($text, '[Unsupported') === 0)) {
        return !empty($row['caption']) ? $row['caption'] : "Photo";
    }

    // 10. Clean up any remaining unsupported placeholder
    if (strpos($text, '[Unsupported message type:') === 0) {
        preg_match('/\[Unsupported message type:\s*([a-zA-Z0-9_-]+)\]/i', $text, $m);
        $typeName = !empty($m[1]) ? ucfirst($m[1]) : 'Message';
        return "[{$typeName}]";
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

    // 2. Fetch message history with failure reason using a safe correlated subquery
    $stmt = $pdo->prepare("
        SELECT wm.*, 
               (
                 SELECT error_message 
                 FROM communication_queue 
                 WHERE message_id = wm.wa_message_id 
                 LIMIT 1
               ) AS failure_reason 
        FROM whatsapp_messages wm
        WHERE wm.conversation_id = ? 
        ORDER BY wm.created_at ASC
    ");
    $stmt->execute([$convId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build lookup map by wa_message_id for cross-referencing reactions & replies
    $messagesByWaId = [];
    foreach ($messages as $m) {
        if (!empty($m['wa_message_id'])) {
            $messagesByWaId[$m['wa_message_id']] = $m;
        }
    }

    foreach ($messages as &$m) {
        $rawPayload = extract_message_payload($m['raw_payload'] ?? '');
        $m['message_text'] = get_resolved_message_text($pdo, $m);

        // Normalize message type if stored as unknown/unsupported
        $mType = $m['message_type'] ?? 'text';

        // Extract Reaction Details
        $m['reaction_emoji'] = null;
        $m['reaction_target_id'] = null;
        $m['reaction_target_snippet'] = null;

        if ($mType === 'reaction' || isset($rawPayload['reaction'])) {
            $m['message_type'] = 'reaction';
            $rx = $rawPayload['reaction'] ?? [];
            $m['reaction_emoji'] = $rx['emoji'] ?? null;
            $m['reaction_target_id'] = $rx['message_id'] ?? $m['reply_to_wa_message_id'] ?? null;

            if ($m['reaction_target_id']) {
                if (isset($messagesByWaId[$m['reaction_target_id']])) {
                    $targetMsg = $messagesByWaId[$m['reaction_target_id']];
                    $targetText = get_resolved_message_text($pdo, $targetMsg);
                    $m['reaction_target_snippet'] = mb_substr($targetText, 0, 80) . (mb_strlen($targetText) > 80 ? '...' : '');
                } else {
                    $stmtTarget = $pdo->prepare("SELECT message_text, message_type, raw_payload, created_at FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1");
                    $stmtTarget->execute([$m['reaction_target_id']]);
                    $targetRow = $stmtTarget->fetch(PDO::FETCH_ASSOC);
                    if ($targetRow) {
                        $targetText = get_resolved_message_text($pdo, $targetRow);
                        $m['reaction_target_snippet'] = mb_substr($targetText, 0, 80) . (mb_strlen($targetText) > 80 ? '...' : '');
                    }
                }
            }
        }

        // Extract Location Details
        $m['location_data'] = null;
        if ($mType === 'location' || isset($rawPayload['location'])) {
            $m['message_type'] = 'location';
            $loc = $rawPayload['location'] ?? [];
            $lat = $loc['latitude'] ?? '';
            $lng = $loc['longitude'] ?? '';
            $m['location_data'] = [
                'latitude'  => $lat,
                'longitude' => $lng,
                'name'      => $loc['name'] ?? '',
                'address'   => $loc['address'] ?? '',
                'map_url'   => ($lat !== '' && $lng !== '') ? "https://www.google.com/maps/search/?api=1&query={$lat},{$lng}" : ''
            ];
        }

        // Extract Contacts Details
        $m['contacts_data'] = null;
        if ($mType === 'contacts' || isset($rawPayload['contacts'])) {
            $m['message_type'] = 'contacts';
            $rawContacts = $rawPayload['contacts'] ?? [];
            $parsedContacts = [];
            foreach ($rawContacts as $c) {
                $name = $c['name']['formatted_name'] ?? $c['name']['first_name'] ?? 'Contact';
                $phones = [];
                if (!empty($c['phones']) && is_array($c['phones'])) {
                    foreach ($c['phones'] as $p) {
                        $phones[] = [
                            'phone' => $p['phone'] ?? ($p['wa_id'] ?? ''),
                            'type'  => $p['type'] ?? 'MOBILE'
                        ];
                    }
                }
                $parsedContacts[] = [
                    'name'   => $name,
                    'phones' => $phones
                ];
            }
            $m['contacts_data'] = $parsedContacts;
        }

        // Extract Interactive / Button Details
        $m['interactive_data'] = null;
        if ($mType === 'interactive' || $mType === 'button' || isset($rawPayload['interactive']) || isset($rawPayload['button'])) {
            if (isset($rawPayload['interactive'])) {
                $intType = $rawPayload['interactive']['type'] ?? '';
                $title = $rawPayload['interactive']['button_reply']['title'] ?? $rawPayload['interactive']['list_reply']['title'] ?? '';
                $btnId = $rawPayload['interactive']['button_reply']['id'] ?? $rawPayload['interactive']['list_reply']['id'] ?? '';
                $m['interactive_data'] = [
                    'type'  => $intType,
                    'title' => $title,
                    'id'    => $btnId
                ];
            } elseif (isset($rawPayload['button'])) {
                $m['interactive_data'] = [
                    'type'    => 'button',
                    'title'   => $rawPayload['button']['text'] ?? '',
                    'payload' => $rawPayload['button']['payload'] ?? ''
                ];
            }
        }

        // Extract Reply Context
        $m['reply_context'] = null;
        if (!empty($m['reply_to_wa_message_id']) && $mType !== 'reaction') {
            $targetWaId = $m['reply_to_wa_message_id'];
            if (isset($messagesByWaId[$targetWaId])) {
                $tMsg = $messagesByWaId[$targetWaId];
                $tText = get_resolved_message_text($pdo, $tMsg);
                $m['reply_context'] = [
                    'sender'  => ($tMsg['direction'] === 'outbound' ? 'Admin / System' : 'Student'),
                    'snippet' => mb_substr($tText, 0, 80) . (mb_strlen($tText) > 80 ? '...' : '')
                ];
            }
        }

        // Attach Secure Media Proxy URLs
        if (!empty($m['media_id'])) {
            $m['media_url'] = "api/v1/communication/media.php?id=" . (int)$m['id'];
            $m['media_download_url'] = "api/v1/communication/media.php?id=" . (int)$m['id'] . "&download=1";
        } else {
            $m['media_url'] = null;
            $m['media_download_url'] = null;
        }

        // Strict Redaction: Strip raw_payload so Meta credentials/tokens never leak to browser
        unset($m['raw_payload']);
    }
    unset($m);

    echo json_encode(['success' => true, 'messages' => $messages]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
