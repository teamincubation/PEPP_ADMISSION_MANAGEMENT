<?php
/**
 * PEPP Updates — AI Full Description Generation Service
 *
 * Analyzes Title, Short Description, and Banner Image (OCR/Vision) using Gemini AI
 * to generate a comprehensive, structured, factually cautious full description for updates.
 *
 * Security & Reliability:
 * - Credentials resolved securely via GeminiAiProvider::resolveApiKey()
 * - Strictly avoids fabricating dates, fees, links, or requirements not present in source inputs
 * - Generates clean HTML, sanitized through pepp_updates_sanitize_html()
 * - Never returns simulated data when unconfigured; clearly reports missing configuration
 */

declare(strict_types=1);

require_once __DIR__ . '/GeminiAiProvider.php';
require_once dirname(__DIR__) . '/pepp_updates_helper.php';

class PeppUpdatesAiService {
    private ?PDO $pdo;
    private ?string $apiKey;
    private string $model;
    private ?object $customProvider;

    public function __construct(?PDO $pdo = null, ?object $customProvider = null, ?string $model = null) {
        $this->pdo = $pdo;
        $this->customProvider = $customProvider;
        $this->model = self::resolveModel($pdo, $model);
        $this->apiKey = GeminiAiProvider::resolveApiKey($pdo);
    }

    /**
     * Resolves the Gemini model from settings, constants, environment, or default standard.
     */
    public static function resolveModel(?PDO $pdo = null, ?string $explicitModel = null): string {
        if (!empty($explicitModel) && is_string($explicitModel)) {
            return trim($explicitModel);
        }

        // 1. Check admin_settings if PDO available
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'pepp_updates_gemini_model' LIMIT 1");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if (!empty($val) && is_string($val)) {
                    return trim($val);
                }
            } catch (Throwable $e) {
                // Ignore DB error
            }

            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'gemini_model' LIMIT 1");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if (!empty($val) && is_string($val)) {
                    return trim($val);
                }
            } catch (Throwable $e) {
                // Ignore DB error
            }
        }

        // 2. Application constant
        if (defined('GEMINI_MODEL')) {
            $constModel = constant('GEMINI_MODEL');
            if (!empty($constModel) && is_string($constModel)) {
                return trim($constModel);
            }
        }

        // 3. Environment variable
        $envModel = getenv('GEMINI_MODEL');
        if (empty($envModel)) {
            $envModel = $_ENV['GEMINI_MODEL'] ?? ($_SERVER['GEMINI_MODEL'] ?? null);
        }
        if (!empty($envModel) && is_string($envModel)) {
            return trim($envModel);
        }

        // 4. Default: gemini-3.8-flash (stable multimodal Flash standard)
        return 'gemini-3.8-flash';
    }

    /**
     * Checks if AI generation credentials are validly configured.
     */
    public function isConfigured(): bool {
        if ($this->customProvider !== null) {
            if (method_exists($this->customProvider, 'isConfigured')) {
                return (bool)$this->customProvider->isConfigured();
            }
            return true;
        }
        return !empty($this->apiKey);
    }

    /**
     * Returns the model being used.
     */
    public function getModelName(): string {
        if ($this->customProvider !== null && method_exists($this->customProvider, 'getModelName')) {
            return $this->customProvider->getModelName();
        }
        return $this->model;
    }

    /**
     * Generates a factually cautious full description from title, short description, and banner image.
     *
     * @param string $title Post title.
     * @param string $shortDescription Post short description.
     * @param string|null $imageMime MIME type of banner (e.g. image/jpeg, image/png, image/webp).
     * @param string|null $imageBytes Raw binary bytes of banner image.
     * @return array Result with success flag, HTML content, or clear error message.
     */
    public function generateDescription(
        string $title,
        string $shortDescription,
        ?string $imageMime = null,
        ?string $imageBytes = null
    ): array {
        $title = trim($title);
        $shortDescription = trim($shortDescription);

        // 1. Input validations
        if ($title === '') {
            return [
                'success' => false,
                'error'   => 'Update title is required to generate description.'
            ];
        }

        if ($shortDescription === '') {
            return [
                'success' => false,
                'error'   => 'Short description is required to generate description.'
            ];
        }

        if (empty($imageBytes) || empty($imageMime)) {
            return [
                'success' => false,
                'error'   => 'Banner image is required for AI analysis and text extraction.'
            ];
        }

        // Validate image MIME type
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array(strtolower($imageMime), $allowedMimes, true)) {
            return [
                'success' => false,
                'error'   => 'Invalid image type. Supported formats: JPEG, PNG, WebP.'
            ];
        }

        // 2. Check configuration (Never simulate success or fabricate text when unconfigured)
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error'   => 'Gemini AI API key is not configured. Please set the GEMINI_API_KEY environment variable or configure gemini_api_key in admin settings.'
            ];
        }

        // 3. Handle custom / mock provider for unit testing
        if ($this->customProvider !== null) {
            if (method_exists($this->customProvider, 'generateDescription')) {
                return $this->customProvider->generateDescription($title, $shortDescription, $imageMime, $imageBytes);
            }
            if (method_exists($this->customProvider, 'generateContentRaw')) {
                $raw = $this->customProvider->generateContentRaw('Generate description', ['title' => $title, 'short_desc' => $shortDescription]);
                $clean = pepp_updates_sanitize_html(is_string($raw) ? $raw : json_encode($raw));
                return [
                    'success'          => true,
                    'full_description' => $clean,
                    'model'            => $this->getModelName()
                ];
            }
        }

        // 4. Formulate Truth-Enforcing, Factually Cautious System Prompt
        $systemPrompt = <<<PROMPT
You are an expert editorial assistant for PEPP Updates, an official education and entrance examination alert portal.
Your task is to synthesize a structured, professional, and comprehensive Full Description in clean HTML format based on:
1. The update title.
2. The short description.
3. The attached banner image text (perform OCR to extract key details).

STRICT FACTUAL CAUTION RULES:
- Do NOT invent, assume, or hallucinate dates, deadlines, registration fees, eligibility criteria, application links, or contact numbers.
- Prefer exact information extracted directly from the title, short description, and banner image.
- If specific details (e.g. application deadline, official portal URL, or examination fee) are NOT visible in the banner image or text inputs, explicitly advise candidates: "Candidates are advised to check the official notification or portal for complete schedule, eligibility, and submission guidelines."
- Structure the content logically using HTML:
  - <h2> for main section headings (e.g., "Overview", "Key Highlights", "Important Dates & Details", "Eligibility & Next Steps").
  - <p> for readable paragraphs.
  - <ul> and <li> for lists of key highlights, required steps, or subjects.
  - <strong> for critical highlights.
- Do NOT wrap your output in markdown code blocks like ```html. Output raw HTML directly.
PROMPT;

        $promptText = $systemPrompt . "\n\n" .
                      "UPDATE TITLE: " . $title . "\n" .
                      "SHORT DESCRIPTION: " . $shortDescription . "\n\n" .
                      "Please inspect the banner image carefully, extract any visible dates, courses, exams, or instructions, and generate the complete HTML full description.";

        // 5. Execute Gemini Multimodal Vision API call with transient retry and 404 fallback
        try {
            $base64Data = base64_encode($imageBytes);

            $payload = [
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [
                            [
                                'text' => $promptText
                            ],
                            [
                                'inlineData' => [
                                    'mimeType' => $imageMime,
                                    'data'     => $base64Data
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 2048,
                ]
            ];

            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

            // Candidate models: Primary model followed by verified, currently supported Flash fallback candidates
            $candidateModels = [$this->model];
            foreach (['gemini-3.8-flash', 'gemini-3.6-flash', 'gemini-3.5-flash-lite'] as $fb) {
                if (!in_array($fb, $candidateModels, true)) {
                    $candidateModels[] = $fb;
                }
            }

            $lastHttpCode = 0;
            $lastSafeCategory = 'Unknown Error';
            $lastErrSnippet = '';
            $successfulResponse = null;
            $successfulModel = null;

            foreach ($candidateModels as $candidateModel) {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($candidateModel) . ':generateContent';

                $maxAttempts = 3;
                $retryDelays = [1 => 2, 2 => 4];
                $response = null;
                $httpCode = 0;
                $curlError = '';

                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $payloadJson,
                        CURLOPT_HTTPHEADER     => [
                            'Content-Type: application/json',
                            'x-goog-api-key: ' . $this->apiKey
                        ],
                        CURLOPT_TIMEOUT        => 35,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => true
                    ]);

                    $response = curl_exec($ch);
                    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = (string)curl_error($ch);
                    curl_close($ch);

                    if ($httpCode === 200 && !empty($response)) {
                        break;
                    }

                    // Retry ONLY on transient HTTP status codes (503 Service Unavailable, 429 Too Many Requests)
                    $isTransient = ($httpCode === 503 || $httpCode === 429);
                    if ($isTransient && $attempt < $maxAttempts) {
                        $sleepSec = $retryDelays[$attempt] ?? 2;
                        sleep($sleepSec);
                        continue;
                    }

                    break;
                }

                if ($curlError !== '') {
                    return [
                        'success' => false,
                        'error'   => 'AI service network connection error: ' . htmlspecialchars($curlError)
                    ];
                }

                if ($httpCode === 200 && !empty($response)) {
                    $successfulResponse = $response;
                    $successfulModel = $candidateModel;
                    break;
                }

                $lastHttpCode = $httpCode;

                // Safe diagnostic error categorization
                $errJson = json_decode(is_string($response) ? $response : '', true);
                $apiError = $errJson['error'] ?? null;
                $apiStatus = is_array($apiError) ? ($apiError['status'] ?? '') : '';
                $apiMsg = is_array($apiError) ? ($apiError['message'] ?? '') : '';

                if ($httpCode === 401 || $apiStatus === 'UNAUTHENTICATED') {
                    $safeCategory = 'Invalid / Unauthenticated API Key';
                } elseif ($httpCode === 403 || $apiStatus === 'PERMISSION_DENIED') {
                    $safeCategory = 'Project / Model Access Restricted or Permission Denied';
                } elseif ($httpCode === 404 || $apiStatus === 'NOT_FOUND') {
                    $safeCategory = 'Model Unavailable or Not Supported';
                } elseif ($httpCode === 429 || $apiStatus === 'RESOURCE_EXHAUSTED') {
                    $safeCategory = 'Quota / Rate Limit Exceeded';
                } elseif ($httpCode === 500 || $apiStatus === 'INTERNAL') {
                    $safeCategory = 'Google AI Internal Server Error';
                } elseif ($httpCode === 503 || $apiStatus === 'UNAVAILABLE') {
                    $safeCategory = 'Google AI Service Unavailable';
                } elseif ($httpCode === 400 || $apiStatus === 'INVALID_ARGUMENT') {
                    $safeCategory = 'Invalid Request Arguments';
                } else {
                    $safeCategory = 'API Error (HTTP ' . $httpCode . ')';
                }

                $lastSafeCategory = $safeCategory;

                $errSnippet = !empty($apiMsg) ? $apiMsg : (is_string($response) ? substr($response, 0, 200) : '');
                $errSnippet = preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[REDACTED]', $errSnippet);
                $errSnippet = preg_replace('/https?:\/\/[^\s]+/i', '[URL]', $errSnippet);
                $lastErrSnippet = trim($errSnippet);

                // If HTTP 404 (Model Not Found or Unavailable to project/API version), try next candidate model
                if ($httpCode === 404) {
                    continue;
                }

                // For other non-transient errors (e.g. 400, 401, 403), stop immediately with clear diagnostic
                break;
            }

            if ($successfulResponse === null) {
                $errDiagnostic = "AI service error [Category: {$lastSafeCategory}] (HTTP {$lastHttpCode})";
                if ($lastHttpCode === 404) {
                    $errDiagnostic .= " for model(s): " . implode(', ', $candidateModels) . ". The model may be unavailable or restricted for your Google project API key.";
                } elseif ($lastErrSnippet !== '') {
                    $errDiagnostic .= ": " . htmlspecialchars($lastErrSnippet);
                }
                return [
                    'success' => false,
                    'error'   => $errDiagnostic
                ];
            }

            $resJson = json_decode($successfulResponse, true);
            $candidateText = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (trim($candidateText) === '') {
                return [
                    'success' => false,
                    'error'   => 'AI service returned an empty response. Please retry.'
                ];
            }

            // Strip accidental code block markdown
            $cleanText = trim($candidateText);
            if (str_starts_with($cleanText, '```html')) {
                $cleanText = substr($cleanText, 7);
            } elseif (str_starts_with($cleanText, '```')) {
                $cleanText = substr($cleanText, 3);
            }
            if (str_ends_with($cleanText, '```')) {
                $cleanText = substr($cleanText, 0, -3);
            }
            $cleanText = trim($cleanText);

            // Sanitize through PEPP Updates HTML sanitizer
            $sanitizedHtml = pepp_updates_sanitize_html($cleanText);

            return [
                'success'          => true,
                'full_description' => $sanitizedHtml,
                'model'            => $successfulModel ?? $this->model
            ];
        } catch (Throwable $e) {
            $msg = preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[REDACTED]', $e->getMessage());
            return [
                'success' => false,
                'error'   => 'AI generation failure: ' . htmlspecialchars($msg)
            ];
        }
    }
}
