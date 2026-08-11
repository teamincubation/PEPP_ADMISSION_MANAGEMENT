<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $businessId  = $settings['whatsapp_business_id'] ?? '';
    $accessToken = $settings['whatsapp_access_token'] ?? '';
    $apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';
    
    $url = "https://graph.facebook.com/{$apiVersion}/{$businessId}/message_templates?limit=100";
    $headers = ["Authorization: Bearer {$accessToken}"];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['data'])) {
        foreach ($data['data'] as $tpl) {
            if (strpos($tpl['name'], 'pepp_') !== false || $tpl['name'] === 'hello_world') {
                echo "Template: " . $tpl['name'] . "\n";
                echo "  Status: " . $tpl['status'] . "\n";
                echo "  Category: " . $tpl['category'] . "\n";
                echo "  Language: " . $tpl['language'] . "\n\n";
            }
        }
    } else {
        echo "No data block in response: " . $response;
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
