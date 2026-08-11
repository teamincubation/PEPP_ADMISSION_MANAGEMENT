<?php
require_once 'config/database.php';
require_once 'includes/communication/CommunicationEngine.php';

header('Content-Type: text/plain');

echo "=== VERBOSE QUEUE ITEM PROCESSOR ===\n\n";

try {
    // Find the next item that the processor would select
    $stmt = $pdo->prepare("
        SELECT id FROM communication_queue 
        WHERE status IN ('pending', 'failed') 
          AND next_attempt_at <= NOW() 
          AND retry_count < 3
        ORDER BY priority DESC, created_at ASC 
        LIMIT 1
    ");
    $stmt->execute();
    $id = $stmt->fetchColumn();
    
    if (!$id) {
        echo "No pending or failed items due for retry in the queue.\n";
        exit;
    }
    
    echo "Selected Queue ID: #{$id}\n";
    
    // Inspect the record before processing
    $item = $pdo->query("SELECT * FROM communication_queue WHERE id = {$id}")->fetch();
    echo "Before Run: Status = {$item['status']}, Retries = {$item['retry_count']}, Error = " . ($item['error_message'] ?? '-') . "\n";
    
    // Let's run a verbose custom execution to capture cURL details
    $channel = $item['channel'];
    $recipient = $item['recipient'];
    $subject = $item['subject'];
    $bodyHtml = $item['body_html'];
    $bodyText = $item['body_text'];
    $attachments = $item['attachments'] ? json_decode($item['attachments'], true) : [];
    $templateData = $item['template_data'] ? json_decode($item['template_data'], true) : [];
    
    echo "Channel: {$channel}\n";
    echo "Recipient: {$recipient}\n";
    echo "Template Name: " . ($item['template_name'] ?: 'NONE') . "\n";
    
    // Load WhatsApp settings
    $settStmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
    $settings = $settStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $businessId  = $settings['whatsapp_business_id'] ?? '';
    $phoneId     = $settings['whatsapp_phone_id'] ?? '';
    $accessToken = $settings['whatsapp_access_token'] ?? '';
    $apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';
    
    echo "Meta Phone ID: " . ($phoneId ?: 'MISSING') . "\n";
    echo "Meta Access Token length: " . strlen($accessToken) . "\n";
    
    // Construct Meta request payload manually to print it
    $cleanPhone = preg_replace('/\D/', '', $recipient);
    if (strlen($cleanPhone) === 10) {
        $cleanPhone = '91' . $cleanPhone;
    }
    
    $url = "https://graph.facebook.com/{$apiVersion}/{$phoneId}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $cleanPhone
    ];
    
    if (!empty($templateData['name'])) {
        $components = [];
        if (!empty($attachments)) {
            $doc = $attachments[0];
            if (!empty($doc['url'])) {
                $components[] = [
                    'type' => 'header',
                    'parameters' => [
                        [
                            'type' => 'document',
                            'document' => [
                                'link' => $doc['url'],
                                'filename' => $doc['name'] ?? 'document.pdf'
                            ]
                        ]
                    ]
                ];
            }
        }
        if (isset($templateData['parameters']) && is_array($templateData['parameters'])) {
            $params = [];
            foreach ($templateData['parameters'] as $val) {
                $params[] = ['type' => 'text', 'text' => (string)$val];
            }
            if (!empty($params)) {
                $components[] = ['type' => 'body', 'parameters' => $params];
            }
        }
        if (isset($templateData['button_parameters']) && is_array($templateData['button_parameters'])) {
            $btnIndex = 0;
            foreach ($templateData['button_parameters'] as $val) {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => (string)$btnIndex,
                    'parameters' => [['type' => 'text', 'text' => (string)$val]]
                ];
                $btnIndex++;
            }
        }
        $payload['type'] = 'template';
        $payload['template'] = [
            'name' => $templateData['name'],
            'language' => ['code' => $templateData['language'] ?? 'en']
        ];
        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }
    } else {
        $payload['type'] = 'text';
        $payload['text'] = [
            'preview_url' => false,
            'body' => $bodyText ?: strip_tags($bodyHtml)
        ];
    }
    
    echo "HTTP Post URL: {$url}\n";
    echo "HTTP Post Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";
    
    // Execute actual Curl request with detailed headers/response trace
    echo "--- Executing Curl Request to Meta API ---\n";
    $headers = [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        echo "Curl Error occurred: {$err}\n";
    } else {
        echo "HTTP Status Code: {$httpCode}\n";
        echo "Meta API Response:\n" . $response . "\n\n";
        
        $respDecoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($respDecoded['messages'][0]['id'])) {
            $msgId = $respDecoded['messages'][0]['id'];
            echo "SUCCESS: Message accepted by Meta. Message ID: {$msgId}\n";
            
            // Update queue record status
            $pdo->prepare("UPDATE communication_queue SET status = 'sent', message_id = ?, error_message = NULL, updated_at = NOW() WHERE id = ?")
                ->execute([$msgId, $id]);
            echo "Database updated to status = 'sent' for record #{$id}.\n";
        } else {
            $errDetails = $respDecoded['error']['message'] ?? 'Unknown API Error';
            echo "FAILED: Meta API error: {$errDetails}\n";
            
            // Update database status to failed and increment retry_count
            $retryCount = $item['retry_count'] + 1;
            $pdo->prepare("UPDATE communication_queue SET status = 'failed', retry_count = ?, error_message = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$retryCount, "HTTP {$httpCode}: {$errDetails}", $id]);
            echo "Database updated to status = 'failed' for record #{$id}. Retry count incremented to {$retryCount}.\n";
        }
    }

} catch (Exception $e) {
    echo "CRITICAL SYSTEM ERROR: " . $e->getMessage() . "\n";
}
