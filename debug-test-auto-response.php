<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';

try {
    echo "=== SIMULATING INBOUND WEBHOOK FOR AUTO-RESPONSE TEST ===\n";
    
    // Generate unique wamid to ensure non-duplicate test
    $testWamid = "wamid.TEST_AUTO_RESP_" . time() . "_" . rand(1000, 9999);
    $testFrom = "919567276458"; // Designated test phone number
    
    $payload = [
        "object" => "whatsapp_business_account",
        "entry" => [
            [
                "id" => "10000000000",
                "changes" => [
                    [
                        "value" => [
                            "messaging_product" => "whatsapp",
                            "metadata" => [
                                "display_phone_number" => "916282563209",
                                "phone_number_id" => "1229563296908445"
                            ],
                            "contacts" => [
                                [
                                    "profile" => ["name" => "Adnan Test AutoResponse"],
                                    "wa_id" => $testFrom
                                ]
                            ],
                            "messages" => [
                                [
                                    "from" => $testFrom,
                                    "id" => $testWamid,
                                    "timestamp" => (string)time(),
                                    "text" => [
                                        "body" => "Testing interactive auto response CTA button"
                                    ],
                                    "type" => "text"
                                ]
                            ]
                        ],
                        "field" => "messages"
                    ]
                ]
            ]
        ]
    ];

    // Reset cooldown for conversation to test fresh trigger
    $pdo->exec("UPDATE whatsapp_messages SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE message_text LIKE '%Thank you for contacting PEPP Learning%'");

    $jsonPayload = json_encode($payload);
    
    // Make curl POST request to live webhook.php locally on server
    $ch = curl_init("https://pepplearning.in/admissions/api/v1/communication/webhook.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Webhook response HTTP $code: $res\n\n";

    // Sleep 2 seconds for async worker execution
    sleep(2);

    // Check latest communication queue item
    $stmtQ = $pdo->query("SELECT * FROM communication_queue WHERE channel = 'whatsapp' ORDER BY id DESC LIMIT 1");
    $qItem = $stmtQ->fetch(PDO::FETCH_ASSOC);

    echo "=== LATEST QUEUE ITEM ===\n";
    print_r($qItem);

    // Check latest message in whatsapp_messages
    $stmtM = $pdo->query("SELECT * FROM whatsapp_messages ORDER BY id DESC LIMIT 2");
    $mItems = $stmtM->fetchAll(PDO::FETCH_ASSOC);

    echo "\n=== LATEST MESSAGES IN INBOX ===\n";
    print_r($mItems);

} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n";
}
exit;
