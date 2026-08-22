<?php
/**
 * Read-only Production Diagnostic Tool for PEPP admissions.
 * Exposes webhook event details, message contexts, routing rules, and queue logs.
 * 
 * IMPORTANT: This is a read-only script. Do not write or modify database records.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once __DIR__ . '/../config/database.php';
    
    $results = [];

    // 1. Webhook payload audit for "Basic Plan" click
    $stmtWebhook = $pdo->prepare("
        SELECT id, event_type, payload, created_at 
        FROM communication_webhook_events 
        WHERE payload LIKE '%Basic Plan%' OR payload LIKE '%rci_basic_plan%'
        ORDER BY id DESC LIMIT 5
    ");
    $stmtWebhook->execute();
    $results['webhook_events'] = $stmtWebhook->fetchAll(PDO::FETCH_ASSOC);

    // 2. Inbound messages matching "Basic Plan"
    $stmtInbound = $pdo->prepare("
        SELECT id, conversation_id, wa_message_id, direction, message_type, message_text, reply_to_wa_message_id, raw_payload, created_at 
        FROM whatsapp_messages 
        WHERE message_text LIKE '%Basic Plan%' OR raw_payload LIKE '%Basic Plan%'
        ORDER BY id DESC LIMIT 5
    ");
    $stmtInbound->execute();
    $inboundMsgs = $stmtInbound->fetchAll(PDO::FETCH_ASSOC);
    $results['inbound_messages'] = $inboundMsgs;

    // 3. Parent messages (Outbound) referenced by context.message_id or manually searched
    $parentMsgs = [];
    $stmtSpecificParent = $pdo->prepare("
        SELECT id, conversation_id, wa_message_id, direction, message_type, message_text, raw_payload, status, created_at 
        FROM whatsapp_messages 
        WHERE wa_message_id = 'wamid.HBgMOTE3MzA2MTk4MTAyFQIAERgSNEU5MkM0MTYzM0E4RTc5RTUzAA=='
    ");
    $stmtSpecificParent->execute();
    $results['specific_parent_message'] = $stmtSpecificParent->fetch(PDO::FETCH_ASSOC);

    $stmtRecentOutbound = $pdo->prepare("
        SELECT id, conversation_id, wa_message_id, direction, message_type, message_text, raw_payload, status, created_at 
        FROM whatsapp_messages 
        WHERE direction = 'outbound' 
        ORDER BY id DESC LIMIT 5
    ");
    $stmtRecentOutbound->execute();
    $results['recent_outbound_messages'] = $stmtRecentOutbound->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inboundMsgs as $inMsg) {
        $replyTo = $inMsg['reply_to_wa_message_id'];
        if (!empty($replyTo)) {
            $stmtParent = $pdo->prepare("
                SELECT id, conversation_id, wa_message_id, direction, message_type, message_text, raw_payload, status, created_at 
                FROM whatsapp_messages 
                WHERE wa_message_id = ?
            ");
            $stmtParent->execute([$replyTo]);
            $parent = $stmtParent->fetch(PDO::FETCH_ASSOC);
            if ($parent) {
                $parentMsgs[$replyTo] = $parent;
            }
        }
    }
    $results['parent_messages'] = $parentMsgs;

    // 4. Template configurations
    $stmtTpl = $pdo->prepare("
        SELECT id, template_name, language, status, category, meta_data, created_at, updated_at 
        FROM communication_templates 
        WHERE template_name IN ('m_clin_psy_rci_admission_started', 'm_clin_psy_rci_basic_plan', 'm_clin_psy_rci_standard_plan')
    ");
    $stmtTpl->execute();
    $templates = $stmtTpl->fetchAll(PDO::FETCH_ASSOC);
    foreach ($templates as &$tpl) {
        $tpl['meta_data_decoded'] = json_decode($tpl['meta_data'], true);
    }
    unset($tpl);
    $results['templates'] = $templates;

    // 5. Target template communication queue items
    $stmtQueue = $pdo->prepare("
        SELECT id, channel, recipient, subject, body_text, status, retry_count, error_message, template_data, created_at, updated_at 
        FROM communication_queue 
        WHERE template_data LIKE '%m_clin_psy_rci_basic%' OR subject LIKE '%m_clin_psy_rci_basic%'
        ORDER BY id DESC LIMIT 5
    ");
    $stmtQueue->execute();
    $queueItems = $stmtQueue->fetchAll(PDO::FETCH_ASSOC);
    foreach ($queueItems as &$qi) {
        $qi['template_data_decoded'] = json_decode($qi['template_data'], true);
    }
    unset($qi);
    $results['queue_items'] = $queueItems;

    // 6. Generic response messages sent around the same time
    $stmtGeneric = $pdo->prepare("
        SELECT id, conversation_id, wa_message_id, direction, message_text, status, raw_payload, created_at 
        FROM whatsapp_messages 
        WHERE message_text LIKE '%Thank you for contacting PEPP Learning%'
        ORDER BY id DESC LIMIT 5
    ");
    $stmtGeneric->execute();
    $results['generic_responses'] = $stmtGeneric->fetchAll(PDO::FETCH_ASSOC);

    // 7. Last 5 successful outbound template queue dispatches (for comparison)
    $stmtSuccess = $pdo->prepare("
        SELECT id, channel, recipient, subject, status, retry_count, error_message, template_data, created_at, updated_at 
        FROM communication_queue 
        WHERE status = 'sent' AND channel = 'whatsapp' AND template_data IS NOT NULL
        ORDER BY id DESC LIMIT 5
    ");
    $stmtSuccess->execute();
    $successDispatches = $stmtSuccess->fetchAll(PDO::FETCH_ASSOC);
    foreach ($successDispatches as &$sd) {
        $sd['template_data_decoded'] = json_decode($sd['template_data'], true);
    }
    unset($sd);
    $results['successful_dispatches'] = $successDispatches;

    echo json_encode(['success' => true, 'data' => $results], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
