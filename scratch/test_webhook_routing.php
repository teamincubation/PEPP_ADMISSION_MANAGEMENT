<?php
/**
 * Offline Unit Test for WhatsApp Webhook Routing & Idempotency logic.
 * Runs inside a database transaction and rolls back at the end.
 */

// Enable Mock testing mode to use in-memory SQLite database
$_SERVER['HTTP_X_TESTING_MODE'] = 'true';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');

echo "========================================================\n";
echo "   WHATSAPP WEBHOOK ROUTING & IDEMPOTENCY UNIT TEST\n";
echo "========================================================\n\n";

try {
    // Register custom MySQL equivalent functions in SQLite
    $pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); }, 0);
    $pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); }, 0);

    // Create necessary table structures in SQLite
    $pdo->exec("
        DROP TABLE IF EXISTS communication_templates;
        CREATE TABLE communication_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT,
            template_name TEXT,
            language TEXT,
            status TEXT,
            category TEXT,
            meta_data TEXT,
            created_at TEXT,
            updated_at TEXT
        );
        DROP TABLE IF EXISTS whatsapp_messages;
        CREATE TABLE whatsapp_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER,
            wa_message_id TEXT,
            direction TEXT,
            message_type TEXT,
            message_text TEXT,
            raw_payload TEXT,
            reply_to_wa_message_id TEXT,
            status TEXT,
            created_at TEXT
        );
        DROP TABLE IF EXISTS whatsapp_conversations;
        CREATE TABLE whatsapp_conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            wa_phone_number TEXT,
            contact_name TEXT,
            created_at TEXT,
            updated_at TEXT
        );
        DROP TABLE IF EXISTS communication_queue;
        CREATE TABLE communication_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT,
            recipient TEXT,
            subject TEXT,
            body_text TEXT,
            status TEXT,
            template_data TEXT,
            created_at TEXT
        );
    ");

    $pdo->beginTransaction();

    // 1. Prepare/Mock the templates in database
    $pdo->exec("DELETE FROM communication_templates WHERE template_name IN ('m_clin_psy_rci_admission_started', 'm_clin_psy_rci_basic_plan', 'm_clin_psy_rci_standard_plan')");
    $pdo->exec("DELETE FROM whatsapp_messages");
    $pdo->exec("DELETE FROM communication_queue");

    // Insert mock conversation to satisfy foreign key constraint
    $pdo->exec("
        INSERT OR REPLACE INTO whatsapp_conversations (id, wa_phone_number, contact_name, created_at, updated_at) 
        VALUES (55, '917306198102', 'Test User', NOW(), NOW())
    ");
    $convId = 55;
    echo "✔ Mock conversation resolved to ID: {$convId}\n";

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

    // Helper routing function representing the webhook logic
    $routeWebhook = function($pdo, $convId, $msgObject) {
        $from = $msgObject['from'] ?? '';
        $msgId = $msgObject['id'] ?? '';
        $type = $msgObject['type'] ?? 'text';
        
        $cleanFrom = preg_replace('/\D/', '', $from);
        
        // 1. Idempotency Check
        $stmtCheck = $pdo->prepare("SELECT id FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1");
        $stmtCheck->execute([$msgId]);
        if ($stmtCheck->fetchColumn()) {
            return [
                'status' => 'ignored', 
                'reason' => 'Duplicate webhook event message ID',
                'preventAutoResponse' => false
            ];
        }

        // Parse context message ID (Fix 1)
        $replyToId = $msgObject['context']['id'] ?? $msgObject['context']['message_id'] ?? null;

        // Parse text & payloads
        $btnText = '';
        $buttonPayload = '';
        if ($type === 'button') {
            $btnText = $msgObject['button']['text'] ?? '';
            $buttonPayload = $msgObject['button']['payload'] ?? '';
        }

        // Record inbound message
        $insMsg = $pdo->prepare("
            INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, reply_to_wa_message_id, status, raw_payload, created_at)
            VALUES (?, ?, 'inbound', ?, ?, ?, 'delivered', ?, NOW())
        ");
        $text = "Student clicked button: \"{$btnText}\"";
        $insMsg->execute([$convId, $msgId, $type, $text, $replyToId, json_encode($msgObject)]);

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

        $preventAutoResponse = false;
        $matchedAction = null;

        // 3. Routing Engine logic
        $isButtonClick = ($type === 'button');
        if ($isButtonClick) {
            // First attempt: Match by unique payload (Fix 2)
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
                        if (
                            (isset($act['payload']) && trim((string)$act['payload']) === trim((string)$buttonPayload))
                            ||
                            (isset($act['text']) && trim((string)$act['text']) === trim((string)$buttonPayload))
                        ) {
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
            if (!$matchedAction && !empty($parentTemplateName) && !empty($btnText)) {
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
                        if (isset($act['text']) && trim((string)$act['text']) === trim((string)$btnText)) {
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
                    $preventAutoResponse = true;

                    // Fetch target template to verify
                    $stmtTarget = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? AND status = 'approved' LIMIT 1");
                    $stmtTarget->execute([$targetTplName]);
                    $targetTpl = $stmtTarget->fetch();

                    if ($targetTpl) {
                        // Insert outbound message in communication_queue representing dispatch
                        $stmtQueue = $pdo->prepare("
                            INSERT INTO communication_queue (channel, recipient, subject, status, template_data, created_at)
                            VALUES ('whatsapp', ?, ?, 'pending', ?, NOW())
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
                            'preventAutoResponse' => $preventAutoResponse,
                            'message' => "Enqueued auto-response template '{$targetTplName}' for recipient {$cleanFrom}"
                        ];
                    }
                    return [
                        'status' => 'failed', 
                        'reason' => "Target template '{$targetTplName}' not found in approved templates",
                        'preventAutoResponse' => $preventAutoResponse
                    ];
                }
                return [
                    'status' => 'ignored', 
                    'reason' => "Action type '{$actionType}' is not SEND_TEMPLATE",
                    'preventAutoResponse' => $preventAutoResponse
                ];
            }
        }

        // Generic fallback auto-response trigger simulations
        if (!$preventAutoResponse) {
            $stmtQueue = $pdo->prepare("
                INSERT INTO communication_queue (channel, recipient, subject, status, created_at)
                VALUES ('whatsapp', ?, 'Fallback Auto-Reply', 'pending', NOW())
            ");
            $stmtQueue->execute([$cleanFrom]);
            return [
                'status' => 'fallback_sent',
                'reason' => 'Generic auto-reply triggered',
                'preventAutoResponse' => false
            ];
        }

        return [
            'status' => 'ignored', 
            'reason' => 'Message is not a button click',
            'preventAutoResponse' => false
        ];
    };

    // 2. Insert parent message to test reply context
    $pdo->prepare("
        INSERT INTO whatsapp_messages (conversation_id, wa_message_id, direction, message_type, message_text, raw_payload, created_at)
        VALUES (?, 'parent_msg_id_123', 'outbound', 'template', 'Outbound Template Message', ?, NOW())
    ")->execute([$convId, json_encode(['name' => 'm_clin_psy_rci_admission_started'])]);

    echo "--- SIMULATIONS ---\n\n";

    // Scenario 1: Real production Meta payload (context.id and payload = text)
    echo "Scenario 1: Real production Meta payload (context.id + text payload 'Basic Plan')\n";
    $metaPayloadBasic = [
        'context' => [
            'from' => '916282563209',
            'id' => 'parent_msg_id_123'
        ],
        'from' => '917306198102',
        'id' => 'wamid.incoming_1',
        'type' => 'button',
        'button' => [
            'payload' => 'Basic Plan',
            'text' => 'Basic Plan'
        ]
    ];
    $res1 = $routeWebhook($pdo, $convId, $metaPayloadBasic);
    echo "Result: " . json_encode($res1, JSON_PRETTY_PRINT) . "\n\n";

    // Scenario 2: Standard Plan button click (text-fallback matching)
    echo "Scenario 2: Real production Meta payload (text payload 'Standard Plan')\n";
    $metaPayloadStandard = [
        'context' => [
            'from' => '916282563209',
            'id' => 'parent_msg_id_123'
        ],
        'from' => '917306198102',
        'id' => 'wamid.incoming_2',
        'type' => 'button',
        'button' => [
            'payload' => 'Standard Plan',
            'text' => 'Standard Plan'
        ]
    ];
    $res2 = $routeWebhook($pdo, $convId, $metaPayloadStandard);
    echo "Result: " . json_encode($res2, JSON_PRETTY_PRINT) . "\n\n";

    // Scenario 3: Unknown button click (expect generic auto-response)
    echo "Scenario 3: Unknown button click ('Unknown Button')\n";
    $metaPayloadUnknown = [
        'context' => [
            'from' => '916282563209',
            'id' => 'parent_msg_id_123'
        ],
        'from' => '917306198102',
        'id' => 'wamid.incoming_3',
        'type' => 'button',
        'button' => [
            'payload' => 'Unknown Button',
            'text' => 'Unknown Button'
        ]
    ];
    $res3 = $routeWebhook($pdo, $convId, $metaPayloadUnknown);
    echo "Result: " . json_encode($res3, JSON_PRETTY_PRINT) . "\n\n";

    // Scenario 4: Legacy custom payload behaviour (payload = rci_basic_plan)
    echo "Scenario 4: Legacy custom payload behaviour (payload = 'rci_basic_plan')\n";
    $metaPayloadLegacy = [
        'context' => [
            'from' => '916282563209',
            'id' => 'parent_msg_id_123'
        ],
        'from' => '917306198102',
        'id' => 'wamid.incoming_4',
        'type' => 'button',
        'button' => [
            'payload' => 'rci_basic_plan',
            'text' => 'Basic Plan'
        ]
    ];
    $res4 = $routeWebhook($pdo, $convId, $metaPayloadLegacy);
    echo "Result: " . json_encode($res4, JSON_PRETTY_PRINT) . "\n\n";

    // Scenario 5: context.message_id compatibility
    echo "Scenario 5: Legacy context.message_id compatibility\n";
    $metaPayloadMessageId = [
        'context' => [
            'from' => '916282563209',
            'message_id' => 'parent_msg_id_123'
        ],
        'from' => '917306198102',
        'id' => 'wamid.incoming_5',
        'type' => 'button',
        'button' => [
            'payload' => 'Basic Plan',
            'text' => 'Basic Plan'
        ]
    ];
    $res5 = $routeWebhook($pdo, $convId, $metaPayloadMessageId);
    echo "Result: " . json_encode($res5, JSON_PRETTY_PRINT) . "\n\n";

    // Scenario 6: Duplicate event protection (idempotency check)
    echo "Scenario 6: Duplicate event protection (re-submitting wamid.incoming_1)\n";
    $res6 = $routeWebhook($pdo, $convId, $metaPayloadBasic);
    echo "Result: " . json_encode($res6, JSON_PRETTY_PRINT) . "\n\n";


    echo "--- DATABASE STATE VERIFICATION ---\n";
    // Check communication_queue to verify correct auto-responses enqueued
    $queueCount = $pdo->query("SELECT COUNT(*) FROM communication_queue")->fetchColumn();
    $queuedItems = $pdo->query("SELECT recipient, subject, template_data FROM communication_queue")->fetchAll(PDO::FETCH_ASSOC);
    echo "Total Enqueued Messages in Queue: {$queueCount}\n";
    foreach ($queuedItems as $item) {
        $tpl = $item['template_data'] ? " | Template: " . $item['template_data'] : "";
        echo " - To: {$item['recipient']} | Subject: {$item['subject']}{$tpl}\n";
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
