<?php
/**
 * Offline Unit Test for WhatsApp Webhook Routing & Idempotency logic.
 * Runs inside a database transaction and rolls back at the end.
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');

echo "========================================================\n";
echo "   WHATSAPP WEBHOOK ROUTING & IDEMPOTENCY UNIT TEST\n";
echo "========================================================\n\n";

try {
    $pdo->beginTransaction();

    // 1. Prepare/Mock the templates in database
    // Delete any existing templates of same names in transaction to avoid conflicts
    $pdo->exec("DELETE FROM communication_templates WHERE template_name IN ('m_clin_psy_rci_admission_started', 'm_clin_psy_rci_basic_plan', 'm_clin_psy_rci_standard_plan')");
    $pdo->exec("DELETE FROM whatsapp_messages");
    $pdo->exec("DELETE FROM communication_queue");

    // Insert mock conversation to satisfy foreign key constraint
    $pdo->exec("
        INSERT INTO whatsapp_conversations (id, wa_phone_number, contact_name, created_at, updated_at) 
        VALUES (999999, '919567276458', 'Adnan', NOW(), NOW()) 
        ON DUPLICATE KEY UPDATE id = id
    ");

    // Insert approved source template with button routing configuration
    $stmtTpl = $pdo->prepare("
        INSERT INTO communication_templates (channel, template_name, language, status, category, meta_data, created_at, updated_at)
        VALUES ('whatsapp', ?, 'en_US', 'approved', 'MARKETING', ?, NOW(), NOW())
    ");

    $sourceMeta = json_encode([
        'components' => [
            ['type' => 'BODY', 'text' => 'Admission Started for M. Clin. Psy...'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Basic Plan'],
                ['type' => 'QUICK_REPLY', 'text' => 'Standard Plan']
            ]]
        ],
        'body_text' => 'Admission Started for M. Clin. Psy...',
        'header_text' => '',
        'footer_text' => '',
        'button_type' => 'QUICK_REPLY',
        'buttons' => [
            'button_type' => 'QUICK_REPLY',
            'quick_reply' => [
                1 => [
                    'text' => 'Basic Plan',
                    'payload' => 'rci_basic_plan',
                    'action_type' => 'SEND_TEMPLATE',
                    'target_template_name' => 'm_clin_psy_rci_basic_plan'
                ],
                2 => [
                    'text' => 'Standard Plan',
                    'payload' => 'rci_standard_plan',
                    'action_type' => 'SEND_TEMPLATE',
                    'target_template_name' => 'm_clin_psy_rci_standard_plan'
                ]
            ]
        ]
    ]);
    $stmtTpl->execute(['m_clin_psy_rci_admission_started', $sourceMeta]);

    // Insert target templates
    $stmtTpl->execute(['m_clin_psy_rci_basic_plan', json_encode(['body_text' => 'This is basic plan details', 'meta_template_id' => '12345'])]);
    $stmtTpl->execute(['m_clin_psy_rci_standard_plan', json_encode(['body_text' => 'This is standard plan details', 'meta_template_id' => '67890'])]);

    echo "✔ Mock templates successfully set up in transaction.\n\n";

    // Helper routing function representing the proposed webhook logic
    $routeWebhook = function($pdo, $msgId, $from, $type, $btnText, $buttonPayload, $replyToId, $rawPayload) {
        $cleanFrom = preg_replace('/\D/', '', $from);
        
        // 1. Idempotency Check
        $stmtCheck = $pdo->prepare("SELECT id FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1");
        $stmtCheck->execute([$msgId]);
        if ($stmtCheck->fetchColumn()) {
            return ['status' => 'ignored', 'reason' => 'Duplicate webhook event message ID'];
        }

        // Record inbound message
        $insMsg = $pdo->prepare("
            INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, reply_to_wa_message_id, status, raw_payload, created_at)
            VALUES (999999, ?, 'inbound', ?, ?, ?, 'delivered', ?, NOW())
        ");
        $text = "Student clicked button: \"{$btnText}\"";
        $insMsg->execute([$msgId, $type, $text, $replyToId, $rawPayload]);

        // 2. Resolve original/parent template context if possible
        $parentTemplateName = null;
        if (!empty($replyToId)) {
            $stmtParent = $pdo->prepare("SELECT raw_payload FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1");
            $stmtParent->execute([$replyToId]);
            $parentPayloadJson = $stmtParent->fetchColumn();
            if ($parentPayloadJson) {
                $parentPayload = json_decode($parentPayloadJson, true);
                $parentTemplateName = $parentPayload['name'] ?? null;
            }
        }

        // 3. Routing Engine logic
        $isButtonClick = ($type === 'button' || ($type === 'interactive' && $btnText !== ''));
        if ($isButtonClick) {
            $matchedAction = null;
            
            // First attempt: Match by unique payload
            if (!empty($buttonPayload)) {
                $stmtAction = $pdo->prepare("
                    SELECT template_name, meta_data 
                    FROM communication_templates 
                    WHERE channel = 'whatsapp' 
                      AND status = 'approved' 
                      AND meta_data LIKE ?
                ");
                $stmtAction->execute(['%' . $buttonPayload . '%']);
                $allActions = $stmtAction->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($allActions as $tplRow) {
                    $tplMeta = json_decode($tplRow['meta_data'], true) ?: [];
                    $actions = $tplMeta['buttons']['quick_reply'] ?? [];
                    foreach ($actions as $act) {
                        if (isset($act['payload']) && trim($act['payload']) === $buttonPayload) {
                            $matchedAction = [
                                'source_template_name' => $tplRow['template_name'],
                                'action_type' => $act['action_type'] ?? 'NONE',
                                'target_template_name' => $act['target_template_name'] ?? ''
                            ];
                            break 2;
                        }
                    }
                }
            }
            
            // Second attempt: Fallback to parent template name + button text
            if (!$matchedAction && !empty($parentTemplateName)) {
                $stmtParentTpl = $pdo->prepare("
                    SELECT template_name, meta_data 
                    FROM communication_templates 
                    WHERE template_name = ? 
                      AND channel = 'whatsapp' 
                      AND status = 'approved' 
                      LIMIT 1
                ");
                $stmtParentTpl->execute([$parentTemplateName]);
                $parentTplRow = $stmtParentTpl->fetch();
                
                if ($parentTplRow) {
                    $tplMeta = json_decode($parentTplRow['meta_data'], true) ?: [];
                    $actions = $tplMeta['buttons']['quick_reply'] ?? [];
                    foreach ($actions as $act) {
                        if (isset($act['text']) && trim($act['text']) === $btnText) {
                            $matchedAction = [
                                'source_template_name' => $parentTplRow['template_name'],
                                'action_type' => $act['action_type'] ?? 'NONE',
                                'target_template_name' => $act['target_template_name'] ?? ''
                            ];
                            break;
                        }
                    }
                }
            }

            if ($matchedAction) {
                $actionType = $matchedAction['action_type'];
                $targetTplName = $matchedAction['target_template_name'];

                if ($actionType === 'SEND_TEMPLATE' && !empty($targetTplName)) {
                    // Fetch target template to verify
                    $stmtTarget = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? AND status = 'approved' LIMIT 1");
                    $stmtTarget->execute([$targetTplName]);
                    $targetTpl = $stmtTarget->fetch();

                    if ($targetTpl) {
                        // Insert mock outbound message in communication_queue representing dispatch
                        $stmtQueue = $pdo->prepare("
                            INSERT INTO communication_queue (channel, recipient, subject, body_text, status, template_data, created_at)
                            VALUES ('whatsapp', ?, ?, 'Auto-Reply template details', 'pending', ?, NOW())
                        ");
                        $stmtQueue->execute([
                            $cleanFrom,
                            "Auto-Reply: " . $targetTplName,
                            json_encode(['name' => $targetTplName])
                        ]);
                        return [
                            'status' => 'success',
                            'action' => 'SEND_TEMPLATE',
                            'target_template' => $targetTplName,
                            'message' => "Enqueued auto-response template '{$targetTplName}' for recipient {$cleanFrom}"
                        ];
                    }
                    return ['status' => 'failed', 'reason' => "Target template '{$targetTplName}' not found in approved templates"];
                }
                return ['status' => 'ignored', 'reason' => "Action type '{$actionType}' is not SEND_TEMPLATE"];
            }
            return ['status' => 'ignored', 'reason' => "No routing mapping matched button click (Payload: '{$buttonPayload}', Text: '{$btnText}')"];
        }
        return ['status' => 'ignored', 'reason' => 'Message is not a button click'];
    };

    // 2. Insert parent message to test reply context
    $pdo->prepare("
        INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, raw_payload, created_at)
        VALUES (999999, 'parent_msg_id_123', 'outbound', 'template', 'Outbound Template Message', ?, NOW())
    ")->execute([json_encode(['name' => 'm_clin_psy_rci_admission_started'])]);

    echo "--- SIMULATIONS ---\n\n";

    // Scenario 1: Basic Plan button click (payload matches rci_basic_plan)
    echo "Scenario 1: Student clicks 'Basic Plan' (with payload)\n";
    $res1 = $routeWebhook($pdo, 'wamid.1', '919567276458', 'button', 'Basic Plan', 'rci_basic_plan', 'parent_msg_id_123', '{}');
    echo "Result: " . json_encode($res1, JSON_PRETTY_PRINT) . "\n\n";

    // Scenario 2: Standard Plan button click (No payload - matches fallback text)
    echo "Scenario 2: Student clicks 'Standard Plan' (No payload, falls back to text match)\n";
    $res2 = $routeWebhook($pdo, 'wamid.2', '919567276458', 'button', 'Standard Plan', '', 'parent_msg_id_123', '{}');
    echo "Result: " . json_encode($res2, JSON_PRETTY_PRINT) . "\n\n";

    // Scenario 3: Unknown button click
    echo "Scenario 3: Student clicks an 'Unknown Button'\n";
    $res3 = $routeWebhook($pdo, 'wamid.3', '919567276458', 'button', 'Unknown Button', 'unknown_payload', 'parent_msg_id_123', '{}');
    echo "Result: " . json_encode($res3, JSON_PRETTY_PRINT) . "\n\n";

    // Scenario 4: Duplicate webhook event for Scenario 1
    echo "Scenario 4: Duplicate webhook event received for message ID 'wamid.1'\n";
    $res4 = $routeWebhook($pdo, 'wamid.1', '919567276458', 'button', 'Basic Plan', 'rci_basic_plan', 'parent_msg_id_123', '{}');
    echo "Result: " . json_encode($res4, JSON_PRETTY_PRINT) . "\n\n";

    echo "--- DATABASE STATE VERIFICATION ---\n";
    // Check communication_queue to see if correct auto-responses were enqueued
    $queueCount = $pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();
    $queuedItems = $pdo->query("SELECT recipient, subject, template_data FROM communication_queue")->fetchAll(PDO::FETCH_ASSOC);
    echo "Total Enqueued Messages: {$queueCount}\n";
    foreach ($queuedItems as $item) {
        echo " - To: {$item['recipient']} | Subject: {$item['subject']} | Template: {$item['template_data']}\n";
    }

} catch (Exception $e) {
    echo "Error running test: " . $e->getMessage() . "\n";
} finally {
    // ALWAYS rollback transaction to leave the database completely untouched
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        echo "\nTransaction successfully rolled back. Database is clean.\n";
    }
}
