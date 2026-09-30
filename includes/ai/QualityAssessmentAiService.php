<?php
/**
 * PEPP Learning ERP — Lecture Quality Assessment AI Service
 *
 * Coordinates deterministic quality guardrails and AI evaluation.
 * Critical Principles:
 * - Deterministic arithmetic: System itself computes grade counts, averages,
 *   durations, and charges. The AI model is never allowed to do basic arithmetic.
 * - Safe degradation: If AI is unconfigured or encounters an error, the assessment
 *   upload and financial records are preserved intact.
 * - Versioned reports: Analysis is stored in `ld_quality_assessment_ai_reports`.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/GeminiAiProvider.php';
require_once __DIR__ . '/MockAiProvider.php';

class QualityAssessmentAiService {
    private ?PDO $pdo = null;
    private AiProviderInterface $provider;

    public function __construct($pdoOrProvider = null, ?AiProviderInterface $provider = null) {
        if ($pdoOrProvider instanceof PDO) {
            $this->pdo = $pdoOrProvider;
            $this->provider = $provider ?: new GeminiAiProvider(GeminiAiProvider::resolveApiKey($pdoOrProvider));
        } elseif ($pdoOrProvider instanceof AiProviderInterface) {
            $this->provider = $pdoOrProvider;
        } elseif ($provider instanceof AiProviderInterface) {
            $this->provider = $provider;
        } else {
            // Unconfigured/null fallback provider
            $this->provider = new class implements AiProviderInterface {
                public function isConfigured(): bool { return false; }
                public function getProviderName(): string { return 'None'; }
                public function getModelName(): string { return 'none'; }
                public function analyzeAssessment(array $stats, array $rows): array {
                    return ['success' => false, 'error' => 'AI Provider credentials not configured'];
                }
            };
        }
    }

    public function analyzeAssessmentReport(PDO $pdo, int $reportId, bool $forceRetry = false): array {
        $this->pdo = $pdo;
        $res = $this->processReport($reportId, $forceRetry);
        if ($res['status'] === 'pending_config' && !isset($res['error'])) {
            $res['error'] = 'AI Provider credentials not configured';
        }
        return $res;
    }

    public function getProvider(): AiProviderInterface {
        return $this->provider;
    }

    public function setProvider(AiProviderInterface $provider): void {
        $this->provider = $provider;
    }

    /**
     * Computes trusted deterministic guardrails and statistical summaries from assessment items.
     */
    public static function calculateDeterministicMetrics(array $items, float $hourlyRate): array {
        $totalLectures = count($items);
        $totalDuration = 0;
        $totalAssessmentMinutes = 0;

        $aspectCounts = [
            'content' => [1 => 0, 2 => 0, 3 => 0],
            'video'   => [1 => 0, 2 => 0, 3 => 0],
            'audio'   => [1 => 0, 2 => 0, 3 => 0],
            'slide'   => [1 => 0, 2 => 0, 3 => 0]
        ];

        $overallDist = [1 => 0, 2 => 0, 3 => 0];
        $facultyStats = [];
        $chapterStats = [];
        $uniqueChapters = [];
        $languageStats = ['ML' => 0, 'EN' => 0];

        foreach ($items as $item) {
            $dur = (int)($item['lecture_duration_minutes'] ?? 0);
            $assMin = (int)($item['assessment_minutes'] ?? 0);
            $totalDuration += $dur;
            $totalAssessmentMinutes += $assMin;

            $ch = trim((string)($item['chapter'] ?? ''));
            if ($ch !== '') {
                $uniqueChapters[$ch] = true;
                if (!isset($chapterStats[$ch])) {
                    $chapterStats[$ch] = ['topics' => 0, 'duration' => 0, 'assessment_mins' => 0];
                }
                $chapterStats[$ch]['topics']++;
                $chapterStats[$ch]['duration'] += $dur;
                $chapterStats[$ch]['assessment_mins'] += $assMin;
            }

            $cg = (int)($item['content_grade'] ?? 1);
            $vg = (int)($item['video_grade'] ?? 1);
            $ag = (int)($item['audio_grade'] ?? 1);
            $sg = (int)($item['slide_grade'] ?? 1);

            if (isset($aspectCounts['content'][$cg])) $aspectCounts['content'][$cg]++;
            if (isset($aspectCounts['video'][$vg])) $aspectCounts['video'][$vg]++;
            if (isset($aspectCounts['audio'][$ag])) $aspectCounts['audio'][$ag]++;
            if (isset($aspectCounts['slide'][$sg])) $aspectCounts['slide'][$sg]++;

            // Lecture-level worst grade
            $worstGrade = max($cg, $vg, $ag, $sg);
            if (isset($overallDist[$worstGrade])) $overallDist[$worstGrade]++;

            $fac = trim((string)($item['faculty_name'] ?? 'Unknown'));
            if (!isset($facultyStats[$fac])) {
                $facultyStats[$fac] = ['count' => 0, 'grade_3_count' => 0, 'grade_2_count' => 0];
            }
            $facultyStats[$fac]['count']++;
            if ($worstGrade === 3) $facultyStats[$fac]['grade_3_count']++;
            if ($worstGrade === 2) $facultyStats[$fac]['grade_2_count']++;

            $lang = strtoupper(trim((string)($item['language'] ?? 'EN')));
            if (!isset($languageStats[$lang])) $languageStats[$lang] = 0;
            $languageStats[$lang]++;
        }

        $totalHours = $totalAssessmentMinutes / 60.0;
        $charge = round($totalHours * $hourlyRate, 2);

        return [
            'total_chapters' => count($uniqueChapters),
            'total_lectures' => $totalLectures,
            'total_lecture_duration_minutes' => $totalDuration,
            'average_lecture_duration_minutes' => $totalLectures > 0 ? round($totalDuration / $totalLectures, 1) : 0.0,
            'total_assessment_minutes' => $totalAssessmentMinutes,
            'total_assessment_hours' => round($totalHours, 4),
            'average_assessment_minutes_per_topic' => $totalLectures > 0 ? round($totalAssessmentMinutes / $totalLectures, 1) : 0.0,
            'hourly_rate' => $hourlyRate,
            'calculated_charge' => $charge,
            'overall_grade_distribution' => [
                '1' => $overallDist[1],
                '2' => $overallDist[2],
                '3' => $overallDist[3]
            ],
            'aspect_breakdown' => [
                'content' => [
                    'grade_1' => $aspectCounts['content'][1],
                    'grade_2' => $aspectCounts['content'][2],
                    'grade_3' => $aspectCounts['content'][3]
                ],
                'video' => [
                    'grade_1' => $aspectCounts['video'][1],
                    'grade_2' => $aspectCounts['video'][2],
                    'grade_3' => $aspectCounts['video'][3]
                ],
                'audio' => [
                    'grade_1' => $aspectCounts['audio'][1],
                    'grade_2' => $aspectCounts['audio'][2],
                    'grade_3' => $aspectCounts['audio'][3]
                ],
                'slide' => [
                    'grade_1' => $aspectCounts['slide'][1],
                    'grade_2' => $aspectCounts['slide'][2],
                    'grade_3' => $aspectCounts['slide'][3]
                ]
            ],
            'chapter_summary' => $chapterStats,
            'faculty_summary' => $facultyStats,
            'language_distribution' => $languageStats
        ];
    }

    /**
     * Executes AI analysis for a saved quality assessment report.
     *
     * @param int $reportId The ID of ld_quality_assessment_reports.
     * @param bool $forceRetry Whether to bypass the 'completed' guard for explicit re-evaluation.
     * @return array [success => bool, status => string, message => string]
     */
    public function processReport(int $reportId, bool $forceRetry = false): array {
        $stmt = $this->pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            return ['success' => false, 'status' => 'not_found', 'message' => 'Report not found'];
        }

        // Duplicate processing protection: if already completed and not force-retried, do not duplicate
        if ($report['ai_status'] === 'completed' && !$forceRetry) {
            return [
                'success' => true,
                'status' => 'completed',
                'message' => 'AI quality evaluation already completed for this report.'
            ];
        }

        // In-flight concurrency protection: if already processing and not force-retried, do not run parallel requests
        if ($report['ai_status'] === 'processing' && !$forceRetry) {
            return [
                'success' => false,
                'status' => 'already_processing',
                'message' => 'AI evaluation is already in progress for this report.'
            ];
        }

        // Fetch assessment items
        $stmt = $this->pdo->prepare("SELECT * FROM ld_quality_assessment_items WHERE report_id = ? ORDER BY source_row_number ASC");
        $stmt->execute([$reportId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return ['success' => false, 'status' => 'no_items', 'message' => 'No items found for assessment report'];
        }

        // 1. Calculate deterministic guardrails
        $metrics = self::calculateDeterministicMetrics($items, (float)$report['hourly_rate_snapshot']);

        // Check if provider is configured
        if (!$this->provider->isConfigured()) {
            $stmt = $this->pdo->prepare("
                UPDATE ld_quality_assessment_reports
                SET ai_status = 'pending_config',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reportId]);

            return [
                'success' => true,
                'status' => 'pending_config',
                'message' => 'AI evaluation provider is not configured. Assessment saved without AI synthesis.'
            ];
        }

        try {
            // Atomic state transition: claim processing lock
            $allowedStatuses = $forceRetry
                ? "('pending', 'pending_config', 'failed', 'completed')"
                : "('pending', 'pending_config', 'failed')";

            $stmtClaim = $this->pdo->prepare("
                UPDATE ld_quality_assessment_reports 
                SET ai_status = 'processing', updated_at = NOW() 
                WHERE id = ? AND ai_status IN {$allowedStatuses}
            ");
            $stmtClaim->execute([$reportId]);

            if ($stmtClaim->rowCount() === 0 && !$forceRetry) {
                // Another process or thread claimed this report in the interim
                $stmtLatest = $this->pdo->prepare("SELECT ai_status FROM ld_quality_assessment_reports WHERE id = ?");
                $stmtLatest->execute([$reportId]);
                $latestStatus = (string)$stmtLatest->fetchColumn();
                if ($latestStatus === 'processing') {
                    return [
                        'success' => false,
                        'status' => 'already_processing',
                        'message' => 'AI evaluation is already in progress for this report.'
                    ];
                }
                if ($latestStatus === 'completed') {
                    return [
                        'success' => true,
                        'status' => 'completed',
                        'message' => 'AI quality evaluation already completed for this report.'
                    ];
                }
            }

            // Call provider
            $aiResult = $this->provider->analyzeAssessment($metrics, $items);
            if (!empty($aiResult['error']) || (isset($aiResult['success']) && !$aiResult['success'])) {
                throw new RuntimeException($aiResult['error'] ?? 'Malformed or unparseable AI provider response.');
            }

            $overallGrade = (int)($aiResult['overall_grade'] ?? 1);
            if ($overallGrade < 1 || $overallGrade > 3) {
                $overallGrade = 1;
            }
            $classification = (string)($aiResult['overall_classification'] ?? ($overallGrade === 3 ? 'To be improved' : ($overallGrade === 2 ? 'Okay' : 'Good')));
            $summary = (string)($aiResult['executive_summary'] ?? 'Quality evaluation completed.');
            $aspectJson = json_encode($aiResult['aspect_analysis'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $recomJson = json_encode($aiResult['recommendations'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $reverJson = json_encode($aiResult['reverification_list'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $rawJson = json_encode($aiResult, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

            $this->pdo->beginTransaction();

            // Insert into ld_quality_assessment_ai_reports
            $stmt = $this->pdo->prepare("
                INSERT INTO ld_quality_assessment_ai_reports (
                    report_id, provider, model, prompt_version, overall_grade, summary,
                    aspect_analysis_json, recommendations_json, reverification_items_json,
                    raw_structured_result, status, error_message, generated_at, created_at
                ) VALUES (
                    ?, ?, ?, 'v1', ?, ?,
                    ?, ?, ?,
                    ?, 'completed', NULL, NOW(), NOW()
                )
            ");
            $stmt->execute([
                $reportId,
                $this->provider->getProviderName(),
                $this->provider->getModelName(),
                $classification,
                $summary,
                $aspectJson,
                $recomJson,
                $reverJson,
                $rawJson
            ]);

            // Update main report
            $stmt = $this->pdo->prepare("
                UPDATE ld_quality_assessment_reports
                SET ai_status = 'completed',
                    ai_overall_grade = ?,
                    ai_summary = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$overallGrade, $summary, $reportId]);

            $this->pdo->commit();

            return [
                'success' => true,
                'status' => 'completed',
                'message' => 'AI quality evaluation synthesized successfully.',
                'result' => $aiResult
            ];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $errMsg = $e->getMessage();
            error_log("QualityAssessmentAiService error for report {$reportId}: " . $errMsg);

            // Record failure in AI reports table
            try {
                $this->pdo->prepare("
                    INSERT INTO ld_quality_assessment_ai_reports (
                        report_id, provider, model, prompt_version, overall_grade, summary, status, error_message, generated_at, created_at
                    ) VALUES (
                        ?, ?, ?, 'v1', 'Failed', ?, 'failed', ?, NOW(), NOW()
                    )
                ")->execute([
                    $reportId,
                    $this->provider->getProviderName(),
                    $this->provider->getModelName(),
                    $errMsg,
                    $errMsg
                ]);
            } catch (Exception $inner) {
                error_log("Failed to insert AI failure record: " . $inner->getMessage());
            }

            try {
                $this->pdo->prepare("
                    UPDATE ld_quality_assessment_reports
                    SET ai_status = 'failed',
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$reportId]);
            } catch (Exception $inner) {
                error_log("Failed to update report ai_status: " . $inner->getMessage());
            }

            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'AI analysis failed: ' . $errMsg,
                'error' => $errMsg
            ];
        }
    }
}
