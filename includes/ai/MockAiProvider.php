<?php
/**
 * PEPP Learning ERP — Mock AI Provider for Isolated Testing & Auditing
 *
 * Implements AiProviderInterface without making any external network requests.
 * Used for unit testing, test fixtures, and fallback demonstrations.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiProviderInterface.php';

class MockAiProvider implements AiProviderInterface {
    private bool $configured;
    private bool $shouldFail;
    private ?string $failureMessage;
    private ?array $customResponse;

    public function __construct(
        bool $configured = true,
        bool $shouldFail = false,
        ?string $failureMessage = null,
        ?array $customResponse = null
    ) {
        $this->configured = $configured;
        $this->shouldFail = $shouldFail;
        $this->failureMessage = $failureMessage;
        $this->customResponse = $customResponse;
    }

    public function isConfigured(): bool {
        return $this->configured;
    }

    public function getProviderName(): string {
        return 'mock';
    }

    public function getModelName(): string {
        return 'mock-quality-eval-v1';
    }

    public function setConfigured(bool $configured): void {
        $this->configured = $configured;
    }

    public function setShouldFail(bool $shouldFail, ?string $message = null): void {
        $this->shouldFail = $shouldFail;
        $this->failureMessage = $message;
    }

    public function setCustomResponse(?array $response): void {
        $this->customResponse = $response;
    }

    public function analyzeAssessment(array $summaryMetrics, array $assessmentRows): array {
        if (!$this->configured) {
            throw new RuntimeException("Mock AI Provider is not configured.");
        }

        if ($this->shouldFail) {
            throw new RuntimeException($this->failureMessage ?: "Simulated Mock AI generation error.");
        }

        if ($this->customResponse !== null) {
            return $this->customResponse;
        }

        // Generate high-fidelity deterministic response based on metrics
        $totalLectures = (int)($summaryMetrics['total_lectures'] ?? count($assessmentRows));
        $dist = $summaryMetrics['overall_grade_distribution'] ?? ['1' => 0, '2' => 0, '3' => 0];
        $countG3 = (int)($dist['3'] ?? 0);
        $countG2 = (int)($dist['2'] ?? 0);

        $overallGrade = 1;
        $classification = 'Good';
        if ($countG3 > 0 || ($totalLectures > 0 && ($countG2 / $totalLectures) > 0.4)) {
            $overallGrade = 3;
            $classification = 'To be improved';
        } elseif ($countG2 > 0) {
            $overallGrade = 2;
            $classification = 'Okay';
        }

        $priorityIssues = [];
        $reverificationList = [];
        foreach ($assessmentRows as $row) {
            $rNum = $row['source_row_number'] ?? 0;
            $title = $row['lecture_title'] ?? 'Lecture';
            $aspects = [
                'Content' => [(int)($row['content_grade'] ?? 1), (string)($row['content_remark'] ?? '')],
                'Video'   => [(int)($row['video_grade'] ?? 1), (string)($row['video_remark'] ?? '')],
                'Audio'   => [(int)($row['audio_grade'] ?? 1), (string)($row['audio_remark'] ?? '')],
                'Slide'   => [(int)($row['slide_grade'] ?? 1), (string)($row['slide_remark'] ?? '')]
            ];

            foreach ($aspects as $aspectName => [$g, $rem]) {
                if ($g === 3) {
                    $priorityIssues[] = [
                        'source_row' => $rNum,
                        'lecture_title' => $title,
                        'aspect' => $aspectName,
                        'grade' => 3,
                        'remark' => $rem,
                        'severity' => 'High'
                    ];
                    $reverificationList[] = [
                        'source_row' => $rNum,
                        'lecture_title' => $title,
                        'reason' => "Grade 3 assigned for {$aspectName} quality: " . ($rem ?: 'Needs immediate revision')
                    ];
                }
            }

            if ((int)($row['assessment_minutes'] ?? 0) > 120) {
                $reverificationList[] = [
                    'source_row' => $rNum,
                    'lecture_title' => $title,
                    'reason' => "Unusually high assessment duration of " . $row['assessment_minutes'] . " minutes."
                ];
            }
        }

        return [
            'overall_grade' => $overallGrade,
            'overall_classification' => $classification,
            'executive_summary' => "Evaluated {$totalLectures} lectures across submitted assessment data. Overall rating determined as {$classification} based on " . count($priorityIssues) . " critical item(s) and grade distributions.",
            'aspect_analysis' => [
                'content_quality' => [
                    'grade' => ($summaryMetrics['aspect_breakdown']['content']['grade_3'] ?? 0) > 0 ? 3 : (($summaryMetrics['aspect_breakdown']['content']['grade_2'] ?? 0) > 0 ? 2 : 1),
                    'summary' => 'Analysis of academic content clarity and curriculum alignment.',
                    'key_observations' => ['Content generally adheres to learning objectives.']
                ],
                'video_quality' => [
                    'grade' => ($summaryMetrics['aspect_breakdown']['video']['grade_3'] ?? 0) > 0 ? 3 : (($summaryMetrics['aspect_breakdown']['video']['grade_2'] ?? 0) > 0 ? 2 : 1),
                    'summary' => 'Analysis of video framing, lighting, and camera resolution.',
                    'key_observations' => ['Video resolution stable across evaluated segments.']
                ],
                'audio_quality' => [
                    'grade' => ($summaryMetrics['aspect_breakdown']['audio']['grade_3'] ?? 0) > 0 ? 3 : (($summaryMetrics['aspect_breakdown']['audio']['grade_2'] ?? 0) > 0 ? 2 : 1),
                    'summary' => 'Analysis of microphone clarity, background noise, and vocal volume.',
                    'key_observations' => ['Audio clarity monitored across faculty presentations.']
                ],
                'slide_quality' => [
                    'grade' => ($summaryMetrics['aspect_breakdown']['slide']['grade_3'] ?? 0) > 0 ? 3 : (($summaryMetrics['aspect_breakdown']['slide']['grade_2'] ?? 0) > 0 ? 2 : 1),
                    'summary' => 'Analysis of slide typography, readability, and PEPP branding guidelines.',
                    'key_observations' => ['Slides maintain adequate font contrast.']
                ]
            ],
            'patterns_detected' => [
                'repeated_issues' => count($priorityIssues) > 0 ? ['Recurring quality grade 3 flagged in specific sessions'] : ['No pervasive recurring defects found'],
                'faculty_patterns' => ['Consistency maintained across core faculty members'],
                'language_patterns' => ['Both ML and EN language tracks evaluated with standard benchmarks'],
                'chapter_patterns' => ['Foundational chapters demonstrate satisfactory initial pass rate']
            ],
            'priority_issues' => $priorityIssues,
            'recommendations' => [
                'immediate_actions' => count($priorityIssues) > 0 ? ['Review high-priority flagged lectures with concerned faculty'] : ['Proceed with standard module publishing'],
                'video_re_recording_candidates' => array_column(array_filter($priorityIssues, fn($p) => $p['aspect'] === 'Video'), 'lecture_title'),
                'audio_review_candidates' => array_column(array_filter($priorityIssues, fn($p) => $p['aspect'] === 'Audio'), 'lecture_title'),
                'slide_redesign_candidates' => array_column(array_filter($priorityIssues, fn($p) => $p['aspect'] === 'Slide'), 'lecture_title'),
                'manual_verification_candidates' => array_column($reverificationList, 'lecture_title')
            ],
            'reverification_list' => array_values(array_unique($reverificationList, SORT_REGULAR)),
            'data_quality_observations' => [
                'Intern submitted structured assessment adhering to standard column definitions.',
                'AI evaluation generated from intern assessments and computed statistics.'
            ]
        ];
    }
}
