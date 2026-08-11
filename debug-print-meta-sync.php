<?php
require_once 'config/database.php';

header('Content-Type: application/json');

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
    
    echo json_encode([
        "http_code" => $httpCode,
        "error" => $err,
        "response" => json_decode($response, true)
    ]);
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
