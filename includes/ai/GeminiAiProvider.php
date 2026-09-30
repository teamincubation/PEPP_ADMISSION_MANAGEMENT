<?php
/**
 * PEPP Learning ERP — Google Gemini AI Provider
 *
 * Implements server-side AI evaluation using the Google Gemini REST API.
 * Follows strict security controls:
 * - Credentials loaded only from database/env, never hardcoded.
 * - API keys are never exposed in HTML, JS, logs, or error responses.
 * - Enforces structured JSON output and schema validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';

class GeminiAiProvider implements AiProviderInterface {
    private ?string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, string $model = 'gemini-3.5-flash') {
        $this->apiKey = $apiKey ?: self::resolveApiKey();
        $this->model = $model;
    }

    public static function resolveApiKey(?PDO $pdo = null): ?string {
        // 1. Check application secret constant (config/secrets.php pattern)
        if (defined('GEMINI_API_KEY')) {
            $constKey = constant('GEMINI_API_KEY');
            if (!empty($constKey) && is_string($constKey)) {
                return trim($constKey);
            }
        }

        // 2. Check environment variable
        $envKey = getenv('GEMINI_API_KEY');
        if (empty($envKey)) {
            $envKey = $_ENV['GEMINI_API_KEY'] ?? ($_SERVER['GEMINI_API_KEY'] ?? null);
        }
        if (!empty($envKey) && is_string($envKey)) {
            return trim($envKey);
        }

        // 3. Check admin_settings if PDO available (backward compatibility fallback)
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'gemini_api_key' LIMIT 1");
                $stmt->execute();
                $dbKey = $stmt->fetchColumn();
                if (!empty($dbKey) && is_string($dbKey)) {
                    return trim($dbKey);
                }
            } catch (Exception $e) {
                // Ignore DB read failure
            }
        }

        return null;
    }


    public function isConfigured(): bool {
        return !empty($this->apiKey);
    }

    public function getProviderName(): string {
        return 'gemini';
    }

    public function getModelName(): string {
        return $this->model;
    }

    public function analyzeAssessment(array $summaryMetrics, array $assessmentRows): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Gemini API key is not configured.");
        }

        $systemPrompt = <<<PROMPT
You are an expert Learning & Development Quality Evaluation AI for PEPP Learning ERP.
Your task is to analyze intern-submitted lecture assessment data for an academic course.

IMPORTANT LIMITATION & TRUTH IN REPORTING:
You are analyzing the intern's submitted structured assessment records, NOT independently watching the video files.
Do NOT claim you independently viewed the audio, video, or slides.
Do NOT claim you verified the actual PEPP Learning App course content or confirmed that all course lectures were assessed.
State clearly that your analysis is synthesized from the submitted intern evaluations and computed statistics.

EVALUATION SCALE:
1 = Good
2 = Okay
3 = To be improved

You will be provided with:
1. Deterministically calculated summary statistics (row counts, duration, grade distribution, computed metrics).
2. The normalized rows containing: Chapter, Lecture Title, Language, Faculty, Duration, Content Grade/Remark, Video Grade/Remark, Audio Grade/Remark, Slide Grade/Remark, Assessment Time.

OUTPUT REQUIREMENTS:
You MUST respond with a single, valid, raw JSON object (without markdown code blocks, backticks, or preamble) matching this schema:
{
  "overall_grade": 1|2|3,
  "overall_classification": "Good"|"Okay"|"To be improved",
  "executive_summary": "Concise high-level summary of the overall assessment findings.",
  "aspect_analysis": {
    "content_quality": { "grade": 1|2|3, "summary": "...", "key_observations": ["..."] },
    "video_quality": { "grade": 1|2|3, "summary": "...", "key_observations": ["..."] },
    "audio_quality": { "grade": 1|2|3, "summary": "...", "key_observations": ["..."] },
    "slide_quality": { "grade": 1|2|3, "summary": "...", "key_observations": ["..."] }
  },
  "patterns_detected": {
    "repeated_issues": ["..."],
    "faculty_patterns": ["..."],
    "language_patterns": ["..."],
    "chapter_patterns": ["..."]
  },
  "priority_issues": [
    { "source_row": 1, "lecture_title": "...", "aspect": "Content|Video|Audio|Slide", "grade": 3, "remark": "...", "severity": "High" }
  ],
  "recommendations": {
    "immediate_actions": ["..."],
    "video_re_recording_candidates": ["..."],
    "audio_review_candidates": ["..."],
    "slide_redesign_candidates": ["..."],
    "manual_verification_candidates": ["..."]
  },
  "reverification_list": [
    { "source_row": 1, "lecture_title": "...", "reason": "..." }
  ],
  "data_quality_observations": ["..."]
}
PROMPT;

        $userPayload = [
            'summary_metrics' => $summaryMetrics,
            'assessment_sample_and_rows' => $assessmentRows
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($this->model) . ':generateContent';

        $body = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt . "\n\nINPUT DATA:\n" . json_encode($userPayload, JSON_UNESCAPED_SLASHES)]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json'
            ]
        ];

        $maxAttempts = 3;
        $retryDelays = [1 => 3, 2 => 5]; // Seconds to sleep after attempt 1 and attempt 2
        $response = null;
        $httpCode = 0;
        $curlError = '';
        $payloadJson = json_encode($body, JSON_UNESCAPED_SLASHES);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey
                ],
                CURLOPT_TIMEOUT => 35,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = (string)curl_error($ch);
            curl_close($ch);

            // If success, immediately stop retrying and proceed to parser
            if ($httpCode === 200 && !empty($response)) {
                break;
            }

            // Retry ONLY on transient HTTP status codes (503 Service Unavailable, 429 Too Many Requests)
            $isTransient = ($httpCode === 503 || $httpCode === 429);
            if ($isTransient && $attempt < $maxAttempts) {
                $sleepSeconds = $retryDelays[$attempt] ?? 3;
                sleep($sleepSeconds);
                continue;
            }

            // Do NOT retry non-transient status codes (400, 401, 403, 404, etc.) or transport connection errors
            break;
        }

        if ($curlError) {
            throw new RuntimeException("Gemini API connection error: " . $curlError);
        }

        if ($httpCode !== 200 || !$response) {
            $safeSnippet = is_string($response) ? substr($response, 0, 300) : '';
            $safeSnippet = preg_replace('/AIza[0-9A-Za-z_-]{20,}/', '[REDACTED]', $safeSnippet);
            throw new RuntimeException("Gemini API returned HTTP status {$httpCode}: " . $safeSnippet);
        }

        $resJson = json_decode($response, true);
        $candidateText = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!$candidateText) {
            throw new RuntimeException("Gemini API returned empty candidate response.");
        }

        // Clean out any accidental markdown code fences
        $cleanJson = trim($candidateText);
        if (str_starts_with($cleanJson, '```json')) {
            $cleanJson = substr($cleanJson, 7);
        } elseif (str_starts_with($cleanJson, '```')) {
            $cleanJson = substr($cleanJson, 3);
        }
        if (str_ends_with($cleanJson, '```')) {
            $cleanJson = substr($cleanJson, 0, -3);
        }
        $cleanJson = trim($cleanJson);

        $parsed = json_decode($cleanJson, true);
        if (!is_array($parsed) || !isset($parsed['overall_grade']) || !isset($parsed['executive_summary'])) {
            throw new RuntimeException("Gemini API response failed schema validation: " . substr($cleanJson, 0, 200));
        }

        return $parsed;
    }
}
