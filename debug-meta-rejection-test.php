<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $accessToken    = $settings['whatsapp_access_token'] ?? '';
    $phoneNumberId  = $settings['whatsapp_phone_id'] ?? '';
    $apiVersion     = $settings['whatsapp_api_version'] ?? 'v20.0';
    
    $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";
    $headers = [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ];
    
    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => "919567276458",
        "type" => "template",
        "template" => [
            "name" => "pepp_admission_rejected",
            "language" => [
                "code" => "en"
            ],
            "components" => [
                [
                    "type" => "body",
                    "parameters" => [
                        ["type" => "text", "text" => "Adnan Test"],
                        ["type" => "text", "text" => "MA/MSc Psychology (Standard)"],
                        ["type" => "text", "text" => "2026-27"]
                    ]
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP CODE: {$httpCode}\n";
    echo "ERROR: {$err}\n";
    echo "RESPONSE: " . $response . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
