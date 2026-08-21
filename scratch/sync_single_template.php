<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {
    // Load WhatsApp settings
    $settStmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
    $settings = $settStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $businessId  = $settings['whatsapp_business_id'] ?? '';
    $accessToken = $settings['whatsapp_access_token'] ?? '';
    $apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';

    if (empty($businessId) || empty($accessToken)) {
        echo json_encode(['success' => false, 'message' => 'Settings missing.']);
        exit;
    }

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

    if ($err) {
        echo json_encode(['success' => false, 'message' => 'CURL Error: ' . $err]);
        exit;
    }

    $data = json_decode($response, true);
    $foundMetaTemplate = null;

    if (isset($data['data'])) {
        foreach ($data['data'] as $tpl) {
            if ($tpl['name'] === 'm_clin_psy_rci_admission_started') {
                $foundMetaTemplate = $tpl;
                break;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'found_in_meta' => $foundMetaTemplate,
        'all_templates_meta' => isset($data['data']) ? array_map(function($t) { return $t['name']; }, $data['data']) : []
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
