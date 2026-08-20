<?php
require_once __DIR__ . '/CommunicationProviderInterface.php';

class WhatsAppCloudProvider implements CommunicationProviderInterface {
    private $businessId;
    private $phoneId;
    private $accessToken;
    private $apiVersion;
    private $lastError = '';

    public function __construct($businessId, $phoneId, $accessToken, $apiVersion = 'v20.0') {
        $this->businessId = trim($businessId);
        $this->phoneId = trim($phoneId);
        $this->accessToken = trim($accessToken);
        $this->apiVersion = trim($apiVersion);
    }

    public function sendMessage($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], array $templateData = []) {
        // Meta expects phone numbers without leading '+' or special chars.
        $cleanPhone = preg_replace('/\D/', '', $to);
        
        // Indian numbers default check (if 10 digits, prepend 91)
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneId}/messages";
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $cleanPhone
        ];

        // Determine if it is a Meta Template or a direct text message
        if (!empty($templateData['name'])) {
            $templateName = $templateData['name'];
            $langCode = $templateData['language'] ?? 'en';
            
            $components = [];
            
            // Build Document Header if pdf attachment is present and has public URL
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

            // Build Body parameters
            if (isset($templateData['parameters']) && is_array($templateData['parameters'])) {
                $params = [];
                foreach ($templateData['parameters'] as $val) {
                    $params[] = [
                        'type' => 'text',
                        'text' => (string)$val
                    ];
                }
                if (!empty($params)) {
                    $components[] = [
                        'type' => 'body',
                        'parameters' => $params
                    ];
                }
            }

            // Build Button parameters (dynamic URL suffixes)
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
                'name' => $templateName,
                'language' => [
                    'code' => $langCode
                ]
            ];
            if (!empty($components)) {
                $payload['template']['components'] = $components;
            }
        } else if (($templateData['type'] ?? '') === 'interactive') {
            $payload['type'] = 'interactive';
            $payload['interactive'] = [
                'type' => $templateData['interactive_type'] ?? 'cta_url',
                'body' => [
                    'text' => $templateData['interactive_body'] ?? ($bodyText ?: strip_tags($bodyHtml))
                ],
                'action' => [
                    'name' => $templateData['interactive_type'] ?? 'cta_url',
                    'parameters' => [
                        'display_text' => $templateData['interactive_button_text'] ?? 'Click Here',
                        'url' => $templateData['interactive_button_url'] ?? ''
                    ]
                ]
            ];
            if (!empty($templateData['interactive_header'])) {
                $payload['interactive']['header'] = [
                    'type' => 'text',
                    'text' => $templateData['interactive_header']
                ];
            }
            if (!empty($templateData['interactive_footer'])) {
                $payload['interactive']['footer'] = [
                    'text' => $templateData['interactive_footer']
                ];
            }
        } else {
            // Send as simple free-form text message (for customer responses / status updates within 24h window)
            $payload['type'] = 'text';
            $payload['text'] = [
                'preview_url' => false,
                'body' => $bodyText ?: strip_tags($bodyHtml)
            ];
        }

        $headers = [
            "Authorization: Bearer {$this->accessToken}",
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
            $this->lastError = "CURL Error: " . $err;
            error_log("WhatsApp API CURL Error: " . $err);
            return false;
        }

        $respDecoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($respDecoded['messages'][0]['id'])) {
            return [
                'success' => true,
                'message_id' => $respDecoded['messages'][0]['id'],
                'response' => $respDecoded
            ];
        } else {
            $errDetails = $respDecoded['error']['message'] ?? 'Unknown API Error';
            $this->lastError = "HTTP {$httpCode}: {$errDetails}";
            error_log("WhatsApp API Error Details: Code {$httpCode} - Response " . $response);
            return false;
        }
    }

    public function downloadMedia($mediaId) {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$mediaId}";
        
        $headers = [
            "Authorization: Bearer {$this->accessToken}"
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            $this->lastError = "CURL Error: " . $err;
            return false;
        }
        
        $data = json_decode($response, true);
        if ($httpCode !== 200 || !isset($data['url'])) {
            $this->lastError = "Meta Media API Error: " . ($data['error']['message'] ?? 'Unknown Error');
            return false;
        }
        
        $mediaUrl = $data['url'];
        $mimeType = $data['mime_type'] ?? 'image/jpeg';
        
        // Download the actual file binary
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $mediaUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $binary = curl_exec($ch);
        $binaryHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $binaryErr = curl_error($ch);
        curl_close($ch);
        
        if ($binaryErr) {
            $this->lastError = "Media Download CURL Error: " . $binaryErr;
            return false;
        }
        
        if ($binaryHttpCode !== 200) {
            $this->lastError = "Media Download HTTP Error: " . $binaryHttpCode;
            return false;
        }
        
        return [
            'mime_type' => $mimeType,
            'data' => $binary
        ];
    }

    public function getLastError() {
        return $this->lastError;
    }
}
