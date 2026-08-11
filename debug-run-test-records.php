<?php
require_once 'config/database.php';

header('Content-Type: text/plain');

echo "=== TEST RECORDS RUN AND METRICS ===\n\n";

$ids = [45, 46, 47];

foreach ($ids as $id) {
    try {
        echo "========================================\n";
        echo "Processing ID #{$id}...\n";
        
        $stmt = $pdo->prepare("SELECT * FROM communication_queue WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        
        if (!$item) {
            echo "Record #{$id} not found in database.\n";
            continue;
        }
        
        echo "Recipient: " . $item['recipient'] . "\n";
        echo "Template Name: " . ($item['template_name'] ?: 'NONE') . "\n";
        echo "Event Name: " . ($item['event_name'] ?: 'NONE') . "\n";
        echo "Student UID: " . ($item['student_uid'] ?? '-') . "\n";
        echo "Invoice ID: " . ($item['invoice_id'] ?? '-') . "\n";
        echo "Status before attempt: " . $item['status'] . "\n";
        
        // Fetch WhatsApp Settings
        $settStmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
        $settings = $settStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $businessId  = $settings['whatsapp_business_id'] ?? '';
        $phoneId     = $settings['whatsapp_phone_id'] ?? '';
        $accessToken = $settings['whatsapp_access_token'] ?? '';
        $apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';
        
        if (empty($phoneId) || empty($accessToken)) {
            echo "ERROR: WhatsApp configuration missing or incomplete in admin_settings.\n";
            continue;
        }
        
        // 1. Recipient number validation & format check
        $recipient = $item['recipient'];
        $cleanPhone = preg_replace('/\D/', '', $recipient);
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone;
        }
        echo "Recipient clean number format: {$cleanPhone}\n";
        
        // 2. Prepare payload
        $templateData = $item['template_data'] ? json_decode($item['template_data'], true) : [];
        $attachments = $item['attachments'] ? json_decode($item['attachments'], true) : [];
        $bodyText = $item['body_text'];
        $bodyHtml = $item['body_html'];
        
        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneId}/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $cleanPhone
        ];
        
        if (!empty($item['template_name'])) {
            $components = [];
            
            // Document header (invoice pdf)
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
                                    'filename' => $doc['name'] ?? 'invoice.pdf'
                                ]
                            ]
                        ]
                    ];
                }
            }
            
            // Body text variables
            if (isset($templateData['parameters']) && is_array($templateData['parameters'])) {
                $params = [];
                foreach ($templateData['parameters'] as $val) {
                    $params[] = ['type' => 'text', 'text' => (string)$val];
                }
                if (!empty($params)) {
                    $components[] = [
                        'type' => 'body',
                        'parameters' => $params
                    ];
                }
            }
            
            // URL Dynamic button suffix
            if (isset($templateData['button_parameters']) && is_array($templateData['button_parameters'])) {
                $btnIndex = 0;
                foreach ($templateData['button_parameters'] as $val) {
                    $components[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => (string)$btnIndex,
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => (string)$val
                            ]
                        ]
                    ];
                    $btnIndex++;
                }
            }
            
            $payload['type'] = 'template';
            $payload['template'] = [
                'name' => $item['template_name'],
                'language' => ['code' => $templateData['language'] ?? 'en']
            ];
            if (!empty($components)) {
                $payload['template']['components'] = $components;
            }
        } else {
            // Free-form text message
            $payload['type'] = 'text';
            $payload['text'] = [
                'preview_url' => false,
                'body' => $bodyText ?: strip_tags($bodyHtml)
            ];
        }
        
        echo "Request Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";
        
        // Execute request
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
            echo "cURL error occurred: {$err}\n";
            continue;
        }
        
        echo "Meta HTTP Status Code: {$httpCode}\n";
        echo "Meta Response Body:\n{$response}\n\n";
        
        $respDecoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($respDecoded['messages'][0]['id'])) {
            $msgId = $respDecoded['messages'][0]['id'];
            echo "SUCCESS: Message dispatched successfully! Message ID: {$msgId}\n";
            
            // Update queue record status
            $pdo->prepare("UPDATE communication_queue SET status = 'sent', message_id = ?, error_message = NULL, updated_at = NOW() WHERE id = ?")
                ->execute([$msgId, $id]);
            
            // Sync to legacy log
            if (strpos((string)$item['error_message'], 'legacy_id:') === 0) {
                $legacyId = (int)substr((string)$item['error_message'], 10);
                $pdo->prepare("UPDATE whatsapp_notifications SET status = 'sent', updated_at = NOW() WHERE id = ?")->execute([$legacyId]);
            }
        } else {
            $errDetails = $respDecoded['error']['message'] ?? 'Unknown API Error';
            $errCode = $respDecoded['error']['code'] ?? 'N/A';
            $errSubcode = $respDecoded['error']['error_subcode'] ?? 'N/A';
            echo "FAILED: Meta API error details: Code={$errCode}, Subcode={$errSubcode}, Message: {$errDetails}\n";
            
            // Update database status to failed and increment retry_count
            $retryCount = $item['retry_count'] + 1;
            $pdo->prepare("UPDATE communication_queue SET status = 'failed', retry_count = ?, error_message = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$retryCount, "HTTP {$httpCode}: {$errDetails} (Code {$errCode}, Subcode {$errSubcode})", $id]);
            
            // Sync to legacy log
            if (strpos((string)$item['error_message'], 'legacy_id:') === 0) {
                $legacyId = (int)substr((string)$item['error_message'], 10);
                $pdo->prepare("UPDATE whatsapp_notifications SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$legacyId]);
            }
        }
        
    } catch (Exception $e) {
        echo "Exception for ID #{$id}: " . $e->getMessage() . "\n";
    }
}
