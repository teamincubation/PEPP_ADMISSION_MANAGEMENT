<?php
/**
 * WhatsApp Inbox E2E Verification & Simulation Suite
 * Protected script - self-deletes after execution (currently disabled for debugging).
 */

// 1. Protection from public execution
if (php_sapi_name() !== 'cli' && (empty($_GET['token']) || $_GET['token'] !== 'PEPP_INBOX_TEST_SECRET_TOKEN_2026')) {
    http_response_code(403);
    die("Forbidden - Authorized execution only");
}

header('Content-Type: text/plain');

try {
    echo "=== STARTING WHATSAPP INBOX E2E VERIFICATION TEST SUITE ===\n\n";

    require_once 'config/database.php';

    $testPhone = "916282563209";
    $testPhoneWeird = " +91 62825-63209 ";

    // Clear previous test data
    $pdo->prepare("DELETE FROM users WHERE whatsapp_number = '6282563209' OR user_id = 'PEPP2026INBOX'")->execute();
    $pdo->prepare("DELETE FROM whatsapp_messages WHERE wa_message_id LIKE 'wamid_test_%'")->execute();
    $pdo->prepare("DELETE FROM whatsapp_conversations WHERE wa_phone_number IN ('916282563209', '918888888888', '919567276458')")->execute();

    // Setup test student
    $stmtUser = $pdo->prepare("
        INSERT INTO users (user_id, name, whatsapp_country_code, whatsapp_number, status, pepp_course, pepp_academic_year, total_fee, paid_amount, created_at, updated_at)
        VALUES ('PEPP2026INBOX', 'PEPP E2E Test Student', '+91', '6282563209', 'approved', 'MA/MSc Psychology (Premium)', '2026-27', 45000, 5000, NOW(), NOW())
    ");
    $stmtUser->execute();
    $studentId = $pdo->lastInsertId();
    echo "1. Set up test student 'PEPP E2E Test Student'. Database ID: {$studentId}, UID: PEPP2026INBOX\n\n";

    // Query app secret for webhook signature simulation
    $stmtSecret = $pdo->query("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_app_secret' LIMIT 1");
    $appSecret = trim($stmtSecret->fetchColumn() ?: '');

    // Helper function to send simulated webhook requests
    function sendWebhookPayload($payload, $appSecret) {
        $rawPayload = json_encode($payload);
        $headers = ['Content-Type: application/json'];
        if ($appSecret !== '') {
            $signature = 'sha256=' . hash_hmac('sha256', $rawPayload, $appSecret);
            $headers[] = 'X-Hub-Signature-256: ' . $signature;
        }
        
        $ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/webhook.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    // A. Simulate Inbound Text Message
    echo "2. Simulating inbound text message via webhook...\n";
    $inboundPayload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'WABA_ID',
                'changes' => [
                    [
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'display_phone_number' => '916282563209',
                                'phone_number_id' => 'PN_ID'
                            ],
                            'contacts' => [
                                [
                                    'profile' => ['name' => 'PEPP E2E Test Profile'],
                                    'wa_id' => $testPhone
                                ]
                            ],
                            'messages' => [
                                [
                                    'from' => $testPhone,
                                    'id' => 'wamid_test_inbound_1',
                                    'timestamp' => time(),
                                    'type' => 'text',
                                    'text' => ['body' => 'Simulation: Inbound Message PEPP']
                                ]
                            ]
                        ],
                        'field' => 'messages'
                    ]
                ]
            ]
        ]
    ];

    $res = sendWebhookPayload($inboundPayload, $appSecret);
    echo "   Webhook response: " . trim($res) . "\n";

    // Verify DB Insertion & Matching
    $stmtConv = $pdo->prepare("SELECT * FROM whatsapp_conversations WHERE wa_phone_number = ? LIMIT 1");
    $stmtConv->execute([$testPhone]);
    $conv = $stmtConv->fetch(PDO::FETCH_ASSOC);

    if ($conv && $conv['student_uid'] === 'PEPP2026INBOX') {
        echo "   PASSED: Conversation created and linked successfully to student PEPP2026INBOX.\n";
        echo "   Last message: \"{$conv['last_message_text']}\" at {$conv['last_message_at']}. Unread count: {$conv['unread_count']}\n\n";
    } else {
        echo "   FAILED: Conversation was not correctly created or linked.\n\n";
    }

    // B. Duplicate Webhook Payload Check (Idempotency)
    echo "3. Testing duplicate webhook delivery (idempotency)...\n";
    sendWebhookPayload($inboundPayload, $appSecret);

    $stmtMsgCount = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE wa_message_id = 'wamid_test_inbound_1'");
    $stmtMsgCount->execute();
    $mCount = $stmtMsgCount->fetchColumn();
    echo "   Message count for ID 'wamid_test_inbound_1': {$mCount} (Should be 1)\n";
    if ((int)$mCount === 1) {
        echo "   PASSED: Duplicate webhook delivery ignored successfully.\n\n";
    } else {
        echo "   FAILED: Duplicate messages stored in database.\n\n";
    }

    // C. Selective Unread Count Reset Check (Safeguard 3)
    echo "4. Testing Selective Unread Count Reset (Safeguard 3)...\n";

    // Start a session and save admin details to get a valid PHPSESSID cookie
    session_start();
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = 'admin_tester';
    $_SESSION['admin_role'] = 'super_admin';
    $_SESSION['csrf_token'] = 'pepp_inbox_csrf_test';
    $_SESSION['last_activity'] = time();
    $cookieStr = session_name() . '=' . session_id() . ';';
    session_write_close();

    // C1. Background Polling (mark_read = 0)
    echo "   Simulating background polling (mark_read=0)...\n";
    $ch = curl_init("https://pepplearning.in/admissions/api/v1/communication/fetch-messages.php?conversation_id=" . $conv['id'] . "&mark_read=0");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
    curl_exec($ch);
    curl_close($ch);

    $stmtConv->execute([$testPhone]);
    $convPoll = $stmtConv->fetch(PDO::FETCH_ASSOC);
    echo "   Unread Count after polling: {$convPoll['unread_count']} (Should still be 1)\n";
    if ((int)$convPoll['unread_count'] === 1) {
        echo "   PASSED: Background polling did NOT reset unread count.\n";
    } else {
        echo "   FAILED: Background polling reset unread count.\n";
    }

    // C2. Explicit Open (mark_read = 1)
    echo "   Simulating explicit open (mark_read=1)...\n";
    $ch = curl_init("https://pepplearning.in/admissions/api/v1/communication/fetch-messages.php?conversation_id=" . $conv['id'] . "&mark_read=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
    curl_exec($ch);
    curl_close($ch);

    $stmtConv->execute([$testPhone]);
    $convOpen = $stmtConv->fetch(PDO::FETCH_ASSOC);
    echo "   Unread Count after explicit open: {$convOpen['unread_count']} (Should be 0)\n";
    if ((int)$convOpen['unread_count'] === 0) {
        echo "   PASSED: Explicit open reset unread count to 0.\n\n";
    } else {
        echo "   FAILED: Explicit open did not reset unread count.\n\n";
    }

    // D. Admin Replies from ERP (Free-form message, Safeguard 4)
    echo "5. Simulating admin reply via send-reply.php...\n";
    $replyPayload = [
        'conversation_id' => $conv['id'],
        'message_text' => 'Hello student, this is a simulated admin response!',
        'template_name' => ''
    ];
    $ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/send-reply.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($replyPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-CSRF-Token: pepp_inbox_csrf_test'
    ]);
    curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resReply = curl_exec($ch);
    curl_close($ch);

    $replyRes = json_decode($resReply, true);
    echo "   Reply response: " . json_encode($replyRes) . "\n";

    if ($replyRes && isset($replyRes['success'])) {
        $qId = $replyRes['queue_id'];
        echo "   PASSED: Admin reply enqueued successfully in queue ID #{$qId}.\n";
        
        // Process the queue item using the existing CLI worker process (Safeguard 4)
        echo "   Processing queue item via CLI worker...\n";
        @exec("php cron-queue.php {$qId}");
        
        // Fetch stored outbound message
        $stmtOut = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE conversation_id = ? AND direction = 'outbound' ORDER BY id DESC LIMIT 1");
        $stmtOut->execute([$conv['id']]);
        $outMsg = $stmtOut->fetch(PDO::FETCH_ASSOC);
        
        if ($outMsg && $outMsg['status'] === 'sent' && !empty($outMsg['wa_message_id'])) {
            echo "   PASSED: Outbound message stored in whatsapp_messages with status 'sent' and Meta ID '{$outMsg['wa_message_id']}'.\n\n";
            
            // E. Meta Webhook Status Updates: Delivered & Read
            $waMsgId = $outMsg['wa_message_id'];
            echo "6. Simulating Meta delivery status callback (delivered)...\n";
            $deliveredPayload = [
                'object' => 'whatsapp_business_account',
                'entry' => [[
                    'id' => 'WABA_ID',
                    'changes' => [[
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'statuses' => [[
                                'id' => $waMsgId,
                                'status' => 'delivered',
                                'timestamp' => time(),
                                'recipient_id' => $testPhone
                            ]]
                        ],
                        'field' => 'messages'
                    ]]
                ]]
            ];
            
            sendWebhookPayload($deliveredPayload, $appSecret);
            
            $stmtOut->execute([$conv['id']]);
            $outMsgDelivered = $stmtOut->fetch(PDO::FETCH_ASSOC);
            echo "   Message status: {$outMsgDelivered['status']}, Delivered At: {$outMsgDelivered['delivered_at']}\n";
            if ($outMsgDelivered['status'] === 'delivered' && !empty($outMsgDelivered['delivered_at'])) {
                echo "   PASSED: Outbound message status updated to 'delivered' and timestamp set.\n\n";
            } else {
                echo "   FAILED: Outbound message status was not updated to 'delivered'.\n\n";
            }
            
            echo "7. Simulating Meta read status callback (read)...\n";
            $readPayload = [
                'object' => 'whatsapp_business_account',
                'entry' => [[
                    'id' => 'WABA_ID',
                    'changes' => [[
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'statuses' => [[
                                'id' => $waMsgId,
                                'status' => 'read',
                                'timestamp' => time(),
                                'recipient_id' => $testPhone
                            ]]
                        ],
                        'field' => 'messages'
                    ]]
                ]]
            ];
            
            sendWebhookPayload($readPayload, $appSecret);
            
            $stmtOut->execute([$conv['id']]);
            $outMsgRead = $stmtOut->fetch(PDO::FETCH_ASSOC);
            echo "   Message status: {$outMsgRead['status']}, Read At: {$outMsgRead['read_at']}\n";
            if ($outMsgRead['status'] === 'read' && !empty($outMsgRead['read_at'])) {
                echo "   PASSED: Outbound message status updated to 'read' and timestamp set.\n\n";
            } else {
                echo "   FAILED: Outbound message status was not updated to 'read'.\n\n";
            }
        } else {
            echo "   FAILED: Outbound message was not logged or status is not 'sent'. Stored Msg: " . json_encode($outMsg) . "\n\n";
        }
    } else {
        echo "   FAILED: Admin reply failed.\n\n";
    }

    // F. Test Unknown WhatsApp Number
    echo "8. Simulating inbound message from Unknown WhatsApp Number (918888888888)...\n";
    $unknownPayload = $inboundPayload;
    $unknownPayload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] = '918888888888';
    $unknownPayload['entry'][0]['changes'][0]['value']['messages'][0]['from'] = '918888888888';
    $unknownPayload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid_test_unknown_1';

    sendWebhookPayload($unknownPayload, $appSecret);

    $stmtConv->execute(['918888888888']);
    $convUnknown = $stmtConv->fetch(PDO::FETCH_ASSOC);
    if ($convUnknown) {
        echo "   PASSED: Conversation created for unknown sender. Contact Name: \"{$convUnknown['contact_name']}\"\n";
        echo "   Student UID is NULL: " . (is_null($convUnknown['student_uid']) ? 'YES' : 'NO') . "\n\n";
    } else {
        echo "   FAILED: Unknown sender conversation was not created.\n\n";
    }

    // G. Test Weird Phone Formatting Matching
    echo "9. Simulating matching with weirdly formatted student phone number...\n";
    // Update student number in DB to weird format
    $pdo->prepare("UPDATE users SET whatsapp_country_code = ' +91 ', whatsapp_number = ' 62825-63209 ' WHERE user_id = 'PEPP2026INBOX'")->execute();

    $weirdPayload = $inboundPayload;
    $weirdPayload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid_test_weird_1';

    // Clear old conversation to trigger match logic again
    $pdo->prepare("DELETE FROM whatsapp_conversations WHERE wa_phone_number = ?")->execute([$testPhone]);

    sendWebhookPayload($weirdPayload, $appSecret);

    $stmtConv->execute([$testPhone]);
    $convWeird = $stmtConv->fetch(PDO::FETCH_ASSOC);
    if ($convWeird && $convWeird['student_uid'] === 'PEPP2026INBOX') {
        echo "   PASSED: Successfully matched student even with weird phone formatting in database.\n\n";
    } else {
        echo "   FAILED: Matching failed with weird phone formatting.\n\n";
    }

    // H. Test Unsupported Message Type
    echo "10. Simulating unsupported message type (e.g., sticker)...\n";
    $unsupportedPayload = $inboundPayload;
    $unsupportedPayload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid_test_unsupported_1';
    $unsupportedPayload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'sticker';
    unset($unsupportedPayload['entry'][0]['changes'][0]['value']['messages'][0]['text']);

    sendWebhookPayload($unsupportedPayload, $appSecret);

    $stmtMsg = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE wa_message_id = 'wamid_test_unsupported_1' LIMIT 1");
    $stmtMsg->execute();
    $unMsg = $stmtMsg->fetch(PDO::FETCH_ASSOC);

    if ($unMsg) {
        echo "   PASSED: Webhook processed successfully. Stored message: \"{$unMsg['message_text']}\"\n\n";
    } else {
        echo "   FAILED: Unsupported message type caused webhook failure or was not logged.\n\n";
    }

    // Clean up simulation records from DB (leaves the student record PEPP2026INBOX for the real WhatsApp test)
    $pdo->prepare("DELETE FROM whatsapp_conversations WHERE wa_phone_number IN ('916282563209', '918888888888')")->execute();

    echo "=== E2E TEST SUITE RUN COMPLETED ===\n";

} catch (Throwable $t) {
    echo "\n\n=== FATAL ERROR IN VERIFICATION SUITE ===\n";
    echo "Error Message: " . $t->getMessage() . "\n";
    echo "File: " . $t->getFile() . " on line " . $t->getLine() . "\n";
    echo "Trace:\n" . $t->getTraceAsString() . "\n";
}
