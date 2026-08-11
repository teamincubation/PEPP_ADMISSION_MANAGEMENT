<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

try {
    $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $businessId  = $settings['whatsapp_business_id'] ?? '';
    $accessToken = $settings['whatsapp_access_token'] ?? '';
    $apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';
    
    if (empty($businessId) || empty($accessToken)) {
        throw new Exception("Config missing.");
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
        throw new Exception("Meta Graph API error: " . $err);
    }
    
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
        $templates = $data['data'];
        $syncedCount = 0;
        
        $pdo->beginTransaction();
        $stmtUpsert = $pdo->prepare("
            INSERT INTO communication_templates (channel, template_name, language, status, category, quality_status, rejection_reason, meta_data, updated_at) 
            VALUES ('whatsapp', ?, ?, ?, ?, ?, ?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                category = VALUES(category), 
                quality_status = VALUES(quality_status), 
                rejection_reason = VALUES(rejection_reason), 
                meta_data = VALUES(meta_data), 
                updated_at = NOW()
        ");
        
        foreach ($templates as $tpl) {
            $name = $tpl['name'] ?? '';
            $lang = $tpl['language'] ?? 'en';
            $status = strtolower($tpl['status'] ?? 'approved');
            $category = $tpl['category'] ?? '';
            $qualityStatus = strtolower($tpl['quality_score']['score'] ?? 'unknown');
            $rejectedReason = $tpl['rejected_reason'] ?? null;
            
            // Extract text body and components metadata
            $bodyText = '';
            foreach ($tpl['components'] ?? [] as $comp) {
                if (($comp['type'] ?? '') === 'BODY') {
                    $bodyText = $comp['text'] ?? '';
                }
            }
            $metaData = json_encode([
                'body_text' => $bodyText,
                'components' => $tpl['components'] ?? []
            ]);
            
            $stmtUpsert->execute([$name, $lang, $status, $category, $qualityStatus, $rejectedReason, $metaData]);
            $syncedCount++;
        }
        $pdo->commit();
        echo "Successfully synced {$syncedCount} templates from Meta Cloud API.\n\n";
    } else {
        throw new Exception("Failed response: " . $response);
    }
    
    // Print the status of newly synced templates in database
    $stmt = $pdo->query("SELECT template_name, status, category, language FROM communication_templates WHERE template_name IN ('pepp_admission_rejected', 'pepp_installment_reminder')");
    $localTpl = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "SYNCED TEMPLATES STATUS:\n";
    print_r($localTpl);
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
