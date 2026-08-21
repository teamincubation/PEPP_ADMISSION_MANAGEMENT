<?php
require_once __DIR__ . '/CommunicationProviderInterface.php';

class WhatsAppCloudProvider implements CommunicationProviderInterface {
    private $businessId;
    private $phoneId;
    private $accessToken;
    private $apiVersion;
    private $lastError = '';
    private $appId = null;

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
            
            // Build Header parameters if present in templateData
            if (!empty($templateData['header_type']) && !empty($templateData['header_parameters'])) {
                $hType = strtoupper($templateData['header_type']);
                $hParams = (array)$templateData['header_parameters'];
                
                $hComponent = [
                    'type' => 'header',
                    'parameters' => []
                ];
                
                if ($hType === 'TEXT') {
                    foreach ($hParams as $val) {
                        $hComponent['parameters'][] = [
                            'type' => 'text',
                            'text' => (string)$val
                        ];
                    }
                } elseif ($hType === 'IMAGE') {
                    $hComponent['parameters'][] = [
                        'type' => 'image',
                        'image' => [
                            'link' => (string)$hParams[0]
                        ]
                    ];
                } elseif ($hType === 'VIDEO') {
                    $hComponent['parameters'][] = [
                        'type' => 'video',
                        'video' => [
                            'link' => (string)$hParams[0]
                        ]
                    ];
                } elseif ($hType === 'DOCUMENT') {
                    $hComponent['parameters'][] = [
                        'type' => 'document',
                        'document' => [
                            'link' => (string)$hParams[0],
                            'filename' => $templateData['header_document_filename'] ?? 'document.pdf'
                        ]
                    ];
                }
                
                if (!empty($hComponent['parameters'])) {
                    $components[] = $hComponent;
                }
            } else {
                // Fallback to legacy document attachments mapping
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

    /**
     * Submits a WhatsApp message template to Meta for approval.
     * 
     * @param string $name
     * @param string $category
     * @param string $language
     * @param array $components
     * @return array|false Response array on success, false on failure
     */
    public function createTemplate($name, $category, $language, array $components) {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->businessId}/message_templates";
        
        $payload = [
            'name' => $name,
            'category' => $category,
            'language' => $language,
            'components' => $components
        ];
        
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
            error_log("createTemplate CURL Error: " . $err);
            return false;
        }
        
        $respDecoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'id' => $respDecoded['id'] ?? null,
                'response' => $respDecoded
            ];
        } else {
            $errDetails = $respDecoded['error']['message'] ?? 'Unknown Meta API Error';
            $errCode = $respDecoded['error']['code'] ?? '';
            $errSubcode = $respDecoded['error']['error_subcode'] ?? '';
            $fbtraceId = $respDecoded['error']['fbtrace_id'] ?? '';
            
            $this->lastError = "Meta rejected template submission: {$errDetails} (Code: {$errCode}, Subcode: {$errSubcode}, Trace ID: {$fbtraceId})";
            
            // Safe logging without credentials
            $sanitizedPayload = $payload;
            error_log("createTemplate Meta API Error [{$httpCode}]: " . json_encode([
                'error' => $respDecoded['error'] ?? null,
                'payload' => $sanitizedPayload
            ]));
            return false;
        }
    }

    /**
     * Dynamically fetches the Meta App ID associated with the current access token.
     * 
     * @return string|false The App ID on success, false on failure
     */
    public function getMetaAppId() {
        $url = "https://graph.facebook.com/{$this->apiVersion}/app";
        
        $headers = [
            "Authorization: Bearer {$this->accessToken}"
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            $this->lastError = "Get App ID CURL Error: " . $err;
            return false;
        }
        
        $respDecoded = json_decode($response, true);
        $appId = $respDecoded['id'] ?? null;
        
        if ($httpCode < 200 || $httpCode >= 300 || empty($appId)) {
            $errDetails = $respDecoded['error']['message'] ?? 'Failed to retrieve Meta App ID';
            $errCode = $respDecoded['error']['code'] ?? '';
            $errSubcode = $respDecoded['error']['error_subcode'] ?? '';
            $this->lastError = "Get App ID Error [{$httpCode}]: {$errDetails} (Code: {$errCode}, Subcode: {$errSubcode})";
            error_log("Get App ID Meta API Error [{$httpCode}]: " . $response);
            return false;
        }
        
        return $appId;
    }

    /**
     * Uploads local media file using Meta's Resumable Upload API to get a header handle.
     * 
     * @param string $filePath Absolute local path to the media file
     * @return string|false The media handle on success, false on failure
     */
    public function uploadSampleMedia($filePath) {
        if (!file_exists($filePath)) {
            $this->lastError = "Local file not found: {$filePath}";
            return false;
        }
        
        $appId = $this->appId;
        if (empty($appId)) {
            $appId = $this->getMetaAppId();
            if (!$appId) {
                return false;
            }
            $this->appId = $appId;
        }
        
        $fileSize = filesize($filePath);
        $mimeType = mime_content_type($filePath);
        $fileName = basename($filePath);
        
        // Step 1: Create Upload Session using App ID
        $sessionUrl = "https://graph.facebook.com/{$this->apiVersion}/{$appId}/uploads";
        $sessionPayload = json_encode([
            'file_length' => $fileSize,
            'file_type' => $mimeType,
            'file_name' => $fileName
        ]);
        
        $headers = [
            "Authorization: OAuth {$this->accessToken}",
            "Content-Type: application/json"
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sessionUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $sessionPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            $this->lastError = "Upload Session CURL Error: " . $err;
            error_log("Upload Session CURL Error: " . $err);
            return false;
        }
        
        $respDecoded = json_decode($response, true);
        $uploadId = $respDecoded['id'] ?? null;
        
        if ($httpCode < 200 || $httpCode >= 300 || empty($uploadId)) {
            $errDetails = $respDecoded['error']['message'] ?? 'Failed to create upload session';
            $errCode = $respDecoded['error']['code'] ?? '';
            $errSubcode = $respDecoded['error']['error_subcode'] ?? '';
            $fbtraceId = $respDecoded['error']['fbtrace_id'] ?? '';
            $this->lastError = "Upload Session Error [{$httpCode}]: {$errDetails} (Code: {$errCode}, Subcode: {$errSubcode}, Trace ID: {$fbtraceId})";
            
            // Safe logging without credentials
            error_log("Upload Session Meta API Error [{$httpCode}]: " . json_encode([
                'endpoint' => "POST /{$this->apiVersion}/{$appId}/uploads",
                'error' => $respDecoded['error'] ?? null,
                'object_id' => $appId,
                'object_type' => 'App ID'
            ]));
            return false;
        } else {
            error_log("Upload Session Created Successfully: upload ID = {$uploadId} (Meta App ID: {$appId})");
        }
        
        // Step 2: Upload File Data
        $uploadUrl = "https://graph.facebook.com/{$this->apiVersion}/{$uploadId}";
        $fileData = file_get_contents($filePath);
        
        $chUpload = curl_init();
        curl_setopt($chUpload, CURLOPT_URL, $uploadUrl);
        curl_setopt($chUpload, CURLOPT_POST, true);
        curl_setopt($chUpload, CURLOPT_POSTFIELDS, $fileData);
        curl_setopt($chUpload, CURLOPT_HTTPHEADER, [
            "Authorization: OAuth {$this->accessToken}",
            "file_offset: 0",
            "Content-Type: {$mimeType}"
        ]);
        curl_setopt($chUpload, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chUpload, CURLOPT_SSL_VERIFYPEER, true);
        
        $uploadResponse = curl_exec($chUpload);
        $uploadHttpCode = curl_getinfo($chUpload, CURLINFO_HTTP_CODE);
        $uploadErr = curl_error($chUpload);
        curl_close($chUpload);
        
        if ($uploadErr) {
            $this->lastError = "File Upload CURL Error: " . $uploadErr;
            error_log("File Upload CURL Error: " . $uploadErr);
            return false;
        }
        
        $uploadDecoded = json_decode($uploadResponse, true);
        $handle = $uploadDecoded['h'] ?? null;
        
        if ($uploadHttpCode < 200 || $uploadHttpCode >= 300 || empty($handle)) {
            $errDetails = $uploadDecoded['error']['message'] ?? 'Failed to upload media data';
            $errCode = $uploadDecoded['error']['code'] ?? '';
            $errSubcode = $uploadDecoded['error']['error_subcode'] ?? '';
            $fbtraceId = $uploadDecoded['error']['fbtrace_id'] ?? '';
            $this->lastError = "File Upload Error [{$uploadHttpCode}]: {$errDetails} (Code: {$errCode}, Subcode: {$errSubcode}, Trace ID: {$fbtraceId})";
            
            error_log("File Upload Meta API Error [{$uploadHttpCode}]: " . json_encode([
                'endpoint' => "POST /{$this->apiVersion}/{$uploadId}",
                'error' => $uploadDecoded['error'] ?? null
            ]));
            return false;
        } else {
            error_log("File Upload Succeeded: handle generated = " . substr($handle, 0, 20) . "...");
        }
        
        return $handle;
    }

    /**
     * Deletes a WhatsApp message template from Meta.
     * 
     * @param string $name
     * @return bool True on success, false on failure
     */
    public function deleteTemplate($name) {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->businessId}/message_templates?name=" . urlencode($name);
        
        $headers = [
            "Authorization: Bearer {$this->accessToken}"
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
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
            error_log("deleteTemplate CURL Error: " . $err);
            return false;
        }
        
        $respDecoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($respDecoded['success']) && $respDecoded['success'] === true) {
            return true;
        } else {
            $errDetails = $respDecoded['error']['message'] ?? 'Unknown Meta API Error';
            $this->lastError = "Meta API Error [{$httpCode}]: {$errDetails}";
            error_log("deleteTemplate Meta API Error: " . $response);
            return false;
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

