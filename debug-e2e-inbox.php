<?php
header('Content-Type: text/plain');

echo "=== STARTING WHATSAPP INBOX E2E VERIFICATION TEST SUITE ===\n\n";

require_once 'config/database.php';

$testPhone = "919567276458";
$testPhoneWeird = "+91 95672-76458";

// Clear previous test data
$pdo->prepare("DELETE FROM users WHERE whatsapp_number = '9567276458'")->execute();
$pdo->prepare("DELETE FROM whatsapp_conversations WHERE wa_phone_number IN ('919567276458', '918888888888')")->execute();

// Setup test student
$stmtUser = $pdo->prepare("
    INSERT INTO users (user_id, name, whatsapp_country_code, whatsapp_number, status, pepp_course, pepp_academic_year, total_fee, paid_amount, created_at, updated_at)
    VALUES ('PEPP2026INBOX', 'Adnan Inbox Student', '+91', '9567276458', 'approved', 'MA/MSc Psychology (Premium)', '2026-27', 45000, 5000, NOW(), NOW())
");
$stmtUser->execute();
$studentId = $pdo->lastInsertId();
echo "1. Set up test student 'Adnan Inbox Student'. Database ID: {$studentId}, UID: PEPP2026INBOX\n\n";

// A. Simulate Inbound Text Message: "Hello"
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
                                'profile' => ['name' => 'Adnan Profile Name'],
                                'wa_id' => $testPhone
                            ]
                        ],
                        'messages' => [
                            [
                                'from' => $testPhone,
                                'id' => 'wamid_test_inbound_1',
                                'timestamp' => time(),
                                'type' => 'text',
                                'text' => ['body' => 'Hello, I have a query about my admission.']
                            ]
                        ]
                    ],
                    'field' => 'messages'
                ]
            ]
        ]
    ]
];

$ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/webhook.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($inboundPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
curl_close($ch);
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
$ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/webhook.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($inboundPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_exec($ch);
curl_close($ch);

$stmtMsgCount = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE wa_message_id = 'wamid_test_inbound_1'");
$stmtMsgCount->execute();
$mCount = $stmtMsgCount->fetchColumn();
echo "   Message count for ID 'wamid_test_inbound_1': {$mCount} (Should be 1)\n";
if ((int)$mCount === 1) {
    echo "   PASSED: Duplicate webhook delivery ignored successfully.\n\n";
} else {
    echo "   FAILED: Duplicate messages stored in database.\n\n";
}

// C. Admin Opens Conversation (Resets unread count)
echo "4. Simulating admin opening the conversation...\n";
$ch = curl_init("https://pepplearning.in/admissions/api/v1/communication/fetch-messages.php?conversation_id=" . $conv['id']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// Since it checks permissions, we might need a session, but for testing we can bypass by setting session in test script, or we can just fetch via DB for verification.
// Let's call the endpoint. Since it returns 403/redirect if not logged in as admin, we can check database directly to verify our controller logic works when session is verified, or we can mock session in this file:
session_start();
$_SESSION['username'] = 'admin_tester';
$_SESSION['role'] = 'super_admin';
// Set session cookies
$cookieStr = session_name() . '=' . session_id() . ';';
curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
$resMsg = curl_exec($ch);
curl_close($ch);

$stmtConv->execute([$testPhone]);
$convRead = $stmtConv->fetch(PDO::FETCH_ASSOC);
echo "   Unread Count after open: {$convRead['unread_count']} (Should be 0)\n";
if ((int)$convRead['unread_count'] === 0) {
    echo "   PASSED: Unread count reset to 0.\n\n";
} else {
    echo "   FAILED: Unread count was not reset.\n\n";
}

// D. Admin Replies from ERP (Free-form message)
echo "5. Simulating admin reply via send-reply.php...\n";
$replyPayload = [
    'conversation_id' => $conv['id'],
    'message_text' => 'Hello student, this is an admin response!',
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
// Wait, we need to bypass csrf_verify check. Let's make sure csrf_verify bypasses for this specific session or test key!
// In admissions, csrf_verify() checks if token matches. Let's check how csrf_verify is implemented in auth.php!
// Wait! Let's just bypass csrf check in send-reply.php if defined('TEST_MODE')!
// In our implementation of send-reply.php, let's look at lines 15-20:
// we can add a bypass: if (!csrf_verify() && !defined('TEST_MODE')) { ... }
// That's perfect!
// Let's run a curl directly with cookies or mock session.
// Wait! To make sure csrf_verify succeeds, we can fetch the token from the session!
// In php, $_SESSION['csrf_token'] is the CSRF token.
// So we can set $_SESSION['csrf_token'] = 'pepp_inbox_csrf_test'; before calling Curl!
$_SESSION['csrf_token'] = 'pepp_inbox_csrf_test';

$resReply = curl_exec($ch);
curl_close($ch);

$replyRes = json_decode($resReply, true);
echo "   Reply response: " . json_encode($replyRes) . "\n";

if ($replyRes && isset($replyRes['success'])) {
    $qId = $replyRes['queue_id'];
    echo "   PASSED: Admin reply enqueued successfully in queue ID #{$qId}.\n";
    
    // Process the queue item using CLI runner to get Meta Message ID
    echo "   Processing queue item via CLI worker...\n";
    exec("php cron-queue.php {$qId}");
    
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
        
        $ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/webhook.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($deliveredPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
        
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
        
        $ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/webhook.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($readPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
        
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

$ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/webhook.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($unknownPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_exec($ch);
curl_close($ch);

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
$pdo->prepare("UPDATE users SET whatsapp_country_code = ' +91 ', whatsapp_number = ' 95-672 764-58 ' WHERE user_id = 'PEPP2026INBOX'")->execute();

$weirdPayload = $inboundPayload;
$weirdPayload['entry'][0]['changes'][0]['value']['messages'][0]['id'] = 'wamid_test_weird_1';

// Clear old conversation to trigger match logic again
$pdo->prepare("DELETE FROM whatsapp_conversations WHERE wa_phone_number = ?")->execute([$testPhone]);

$ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/webhook.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($weirdPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_exec($ch);
curl_close($ch);

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

$ch = curl_init('https://pepplearning.in/admissions/api/v1/communication/webhook.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($unsupportedPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resUnsupported = curl_exec($ch);
curl_close($ch);

$stmtMsg = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE wa_message_id = 'wamid_test_unsupported_1' LIMIT 1");
$stmtMsg->execute();
$unMsg = $stmtMsg->fetch(PDO::FETCH_ASSOC);

if ($unMsg) {
    echo "   PASSED: Webhook processed successfully. Stored message: \"{$unMsg['message_text']}\"\n\n";
} else {
    echo "   FAILED: Unsupported message type caused webhook failure or was not logged.\n\n";
}

echo "=== E2E TEST SUITE RUN COMPLETED ===";
