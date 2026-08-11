<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config/database.php';

try {
    $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $accessToken = $settings['whatsapp_access_token'] ?? '';
    $wabaId = $settings['whatsapp_business_id'] ?? '';
    
    if (empty($accessToken) || empty($wabaId)) {
        die("Missing token or WABA ID");
    }
    
    $url = "https://graph.facebook.com/v20.0/{$wabaId}/message_templates?limit=100";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}"
    ]);
    
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "STATUS: $status\n";
    $decoded = json_decode($res, true);
    if (isset($decoded['data'])) {
        foreach ($decoded['data'] as $tpl) {
            echo "Name: {$tpl['name']}\n";
            echo "Language: {$tpl['language']}\n";
            echo "Status: {$tpl['status']}\n";
            echo "Category: {$tpl['category']}\n";
            echo "Components:\n";
            print_r($tpl['components']);
            echo "---------------------------------\n\n";
        }
    } else {
        echo "RESPONSE:\n" . print_r($decoded, true) . "\n";
    }
} catch (Throwable $t) {
    echo "ERROR: " . $t->getMessage() . "\n";
}
exit;
