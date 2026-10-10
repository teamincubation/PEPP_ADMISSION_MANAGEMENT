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

    public function __construct(?PDO $pdo = null, ?object $customProvider = null, string $model = 'gemini-2.5-flash') {
        $this->pdo = $pdo;
        $this->customProvider = $customProvider;
        $this->model = $model;
        $this->apiKey = GeminiAiProvider::resolveApiKey($pdo);
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

        // 5. Execute Gemini Multimodal Vision API call
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
                    'temperature'     => 0.2,
                    'maxOutputTokens' => 2048,
                ]
            ];

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($this->model) . ':generateContent';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
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

            if ($curlError !== '') {
                return [
                    'success' => false,
                    'error'   => 'AI service network connection error: ' . htmlspecialchars($curlError)
                ];
            }

            if ($httpCode !== 200 || empty($response)) {
                $errSnippet = is_string($response) ? substr($response, 0, 250) : '';
                $errSnippet = preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[REDACTED]', $errSnippet);
                return [
                    'success' => false,
                    'error'   => 'AI service returned error (HTTP ' . $httpCode . '): ' . htmlspecialchars($errSnippet)
                ];
            }

            $resJson = json_decode($response, true);
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
                'model'            => $this->model
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error'   => 'AI generation failure: ' . htmlspecialchars($e->getMessage())
            ];
        }
    }
}
