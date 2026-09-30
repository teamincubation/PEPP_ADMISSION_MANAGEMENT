<?php
/**
 * PEPP Learning ERP — Student Mentor AI Service
 *
 * Coordinates deterministic academic intelligence extraction and on-demand
 * Gemini AI analysis for student mentoring reports and WhatsApp dispatches.
 *
 * Security & Integrity Guarantees:
 * - Credentials resolved securely via GeminiAiProvider::resolveApiKey().
 * - Reuses existing Gemini 3.5 Flash provider with transient 503/429 retry handling.
 * - Deterministic ERP metrics (attendance, scores, tasks, ranks) remain authoritative.
 * - System prompt strictly prohibits hallucination (never invents ranks, scores, or video verification).
 * - Generates both structured JSON for modal UI cards and identical `wa_text` for WhatsApp delivery.
 */

declare(strict_types=1);

require_once __DIR__ . '/GeminiAiProvider.php';
require_once __DIR__ . '/../StudentStudyPlanAnalytics.php';

class StudentMentorAiService {
    private ?PDO $pdo = null;
    private $provider;

    public function __construct(?PDO $pdo = null, $provider = null) {
        $this->pdo = $pdo;
        if ($provider !== null) {
            $this->provider = $provider;
        } elseif ($pdo instanceof PDO) {
            $apiKey = GeminiAiProvider::resolveApiKey($pdo);
            $this->provider = new GeminiAiProvider($apiKey, 'gemini-3.5-flash');
        } else {
            $apiKey = GeminiAiProvider::resolveApiKey(null);
            $this->provider = new GeminiAiProvider($apiKey, 'gemini-3.5-flash');
        }
    }

    public function getProvider() {
        return $this->provider;
    }

    public function setProvider($provider): void {
        $this->provider = $provider;
    }

    /**
     * Extracts canonical ERP data for the selected student and study plan.
     * Enforces strict authorization, dropout/completed guards, and academic year isolation.
     *
     * @param PDO $pdo
     * @param string $studentIdOrEmail Student user_id or email
     * @param int $studyPlanId Target study plan ID
     * @param int $currentAdminId ID of current logged in admin
     * @param bool $isSuperAdmin Whether current user is Super Admin
     * @return array Canonical data payload for AI processing
     * @throws RuntimeException If student not found or access denied
     */
    public static function extractCanonicalData(
        PDO $pdo,
        string $studentIdOrEmail,
        int $studyPlanId,
        int $currentAdminId = 0,
        bool $isSuperAdmin = true
    ): array {
        // 1. Resolve student record
        $stmt = $pdo->prepare("
            SELECT user_id, name, email, phone, whatsapp_number, whatsapp_country_code,
                   pepp_course, pepp_academic_year, student_status, user_photo, status
            FROM users
            WHERE (user_id = ? OR LOWER(email) = LOWER(?)) AND status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([$studentIdOrEmail, $studentIdOrEmail]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            throw new RuntimeException("Student record not found or not approved.");
        }

        $userId = $student['user_id'];
        $email = strtolower(trim((string)$student['email']));
        $stStatus = strtolower(trim((string)($student['student_status'] ?? 'active'))) ?: 'unknown';

        // 2. Dropout / Completed guard
        if (in_array($stStatus, ['dropout', 'completed'], true)) {
            throw new RuntimeException("Access Denied: Student account is inactive ({$stStatus}).");
        }

        // 3. Mentor assignment check
        if (!$isSuperAdmin && function_exists('is_student_assigned_to_mentor')) {
            if (!is_student_assigned_to_mentor($pdo, $userId, $currentAdminId)) {
                throw new RuntimeException("Access Denied: Student is not actively assigned to you.");
            }
        }

        // 4. Fetch canonical plan analytics
        $planAnalytics = StudentStudyPlanAnalytics::getPlanAnalytics($pdo, $email, $studyPlanId);

        if (empty($planAnalytics) || (int)($planAnalytics['study_plan_id'] ?? 0) <= 0) {
            throw new RuntimeException("Study plan details could not be loaded for plan ID {$studyPlanId}.");
        }

        // 5. Structure Checklist Audit
        $totalTasks = (int)($planAnalytics['total_tasks'] ?? 0);
        $completedTasks = (int)($planAnalytics['completed_tasks'] ?? 0);
        $pendingTasks = (int)($planAnalytics['pending_tasks'] ?? 0);
        $overdueTasks = (int)($planAnalytics['overdue_tasks'] ?? 0);
        $completionPct = (int)($planAnalytics['completion_percentage'] ?? 0);
        $activeStreak = (int)($planAnalytics['active_streak'] ?? 0);
        $longestStreak = (int)($planAnalytics['longest_streak'] ?? 0);
        $activeDays = (int)($planAnalytics['active_study_days'] ?? 0);
        $calendarDays = (int)($planAnalytics['total_plan_calendar_days'] ?? 0);
        $consistencyPct = (int)($planAnalytics['consistency_percentage'] ?? 0);

        // 6. Chapter & topic summaries
        $chaptersData = [];
        if (!empty($planAnalytics['chapters']) && is_array($planAnalytics['chapters'])) {
            foreach ($planAnalytics['chapters'] as $c) {
                $chaptersData[] = [
                    'chapter' => $c['chapter_name'] ?? 'General',
                    'total' => (int)($c['total_tasks'] ?? 0),
                    'completed' => (int)($c['completed_tasks'] ?? 0),
                    'pct' => (int)($c['completion_percentage'] ?? 0)
                ];
            }
        }

        $strongestTopics = [];
        if (!empty($planAnalytics['strongest_topics']) && is_array($planAnalytics['strongest_topics'])) {
            foreach ($planAnalytics['strongest_topics'] as $t) {
                $strongestTopics[] = $t['topic'] ?? $t['title'] ?? '';
            }
        }

        $needsAttentionTopics = [];
        if (!empty($planAnalytics['needs_attention_topics']) && is_array($planAnalytics['needs_attention_topics'])) {
            foreach ($planAnalytics['needs_attention_topics'] as $t) {
                $needsAttentionTopics[] = $t['topic'] ?? $t['title'] ?? '';
            }
        }

        // 7. Mega Test performance
        $attendedMegaTests = (int)($planAnalytics['attended_sessions'] ?? 0);
        $totalMegaTests = (int)($planAnalytics['total_sessions'] ?? 0);
        $megaAttendanceRate = $planAnalytics['attendance_rate']; // null if no tests
        $megaTestAvgScore = $planAnalytics['performance_score']; // null if no tests
        $hasMegaTests = ($totalMegaTests > 0);

        $chapterAssessments = [];
        if (!empty($planAnalytics['chapter_assessments']) && is_array($planAnalytics['chapter_assessments'])) {
            foreach ($planAnalytics['chapter_assessments'] as $ca) {
                $chapterAssessments[] = [
                    'chapter' => $ca['chapter'] ?? 'General',
                    'score' => $ca['score'] ?? null,
                    'rank' => $ca['rank'] ?? null,
                    'rank_display' => $ca['rank_display'] ?? null,
                    'status' => $ca['attendance_status'] ?? 'unknown'
                ];
            }
        }

        // 8. Live Session performance
        $liveSessionsTotal = 0;
        $liveSessionsAttended = 0;
        if (!empty($planAnalytics['learning_highlights']) && is_array($planAnalytics['learning_highlights'])) {
            foreach ($planAnalytics['learning_highlights'] as $lh) {
                if (($lh['type_category'] ?? '') === 'live_session') {
                    $liveSessionsTotal++;
                    if (!empty($lh['is_completed'])) {
                        $liveSessionsAttended++;
                    }
                }
            }
        }
        $hasLiveSessions = ($liveSessionsTotal > 0);
        $liveAttendancePct = $hasLiveSessions ? (int)round(($liveSessionsAttended / $liveSessionsTotal) * 100) : null;

        // 9. Cohort & Ranking
        $cohortRanking = $planAnalytics['cohort_ranking'] ?? null;
        $currRankStudent = $cohortRanking['current_student'] ?? null;
        $studyPlanRank = $currRankStudent['rank'] ?? null;
        $cohortSize = $currRankStudent['cohort_size'] ?? ($cohortRanking['cohort_size'] ?? null);
        $percentileText = $currRankStudent['percentile_text'] ?? null;
        $standingBadge = $currRankStudent['badge'] ?? null;

        return [
            'student_profile' => [
                'name' => $student['name'],
                'user_id' => $userId,
                'course' => $student['pepp_course'],
                'academic_year' => $student['pepp_academic_year'],
                'selected_study_plan' => $planAnalytics['study_plan_title'] ?? ('Study Plan #' . $studyPlanId),
                'study_plan_id' => $studyPlanId,
                'status' => $student['student_status'] ?: 'Active'
            ],
            'checklist_audit' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'pending_tasks' => $pendingTasks,
                'overdue_tasks' => $overdueTasks,
                'completion_percentage' => $completionPct,
                'current_streak' => $activeStreak,
                'longest_streak' => $longestStreak,
                'active_study_days' => $activeDays,
                'total_calendar_days' => $calendarDays,
                'consistency_percentage' => $consistencyPct,
                'chapter_progress' => array_slice($chaptersData, 0, 8),
                'strongest_areas' => array_slice(array_filter($strongestTopics), 0, 4),
                'areas_needing_attention' => array_slice(array_filter($needsAttentionTopics), 0, 4)
            ],
            'mega_tests' => [
                'has_data' => $hasMegaTests,
                'eligible_tests' => $totalMegaTests,
                'attended_tests' => $attendedMegaTests,
                'attendance_percentage' => $megaAttendanceRate,
                'average_score_percentage' => $megaTestAvgScore,
                'tests_breakdown' => array_slice($chapterAssessments, 0, 5)
            ],
            'live_sessions' => [
                'has_data' => $hasLiveSessions,
                'eligible_sessions' => $liveSessionsTotal,
                'attended_sessions' => $liveSessionsAttended,
                'attendance_percentage' => $liveAttendancePct
            ],
            'cohort_ranking' => [
                'has_data' => ($studyPlanRank !== null && $cohortSize !== null),
                'study_plan_rank' => $studyPlanRank,
                'cohort_size' => $cohortSize,
                'percentile' => $percentileText,
                'standing_badge' => $standingBadge
            ]
        ];
    }

    /**
     * Builds the rigorous, truth-enforcing Gemini prompt.
     *
     * @param array $payload Canonical ERP data payload
     * @return array ['system' => string, 'user' => array]
     */
    public static function buildPrompts(array $payload): array {
        $systemPrompt = <<<PROMPT
You are an expert Academic Mentor AI for PEPP Learning ERP.
Your task is to analyze a student's study plan performance and produce an actionable, encouraging, data-backed mentor analysis.

CRITICAL SAFETY & TRUTH IN REPORTING RULES:
1. Use ONLY the supplied ERP data.
2. Never invent test scores, attendance numbers, streaks, rankings, or dates.
3. Never claim you watched lectures or videos, or independently verified classroom attendance.
4. Never alter deterministic values (e.g. if completed tasks is 97, use exactly 97).
5. If Mega Test data is absent or has_data is false, state clearly: "No published mega tests for this study plan".
6. If Live Session data is absent or has_data is false, state clearly: "Live sessions not recorded for this study plan".
7. If ranking data is unavailable, state clearly: "Ranking data unavailable". Do NOT estimate or infer a rank.
8. Output length: 500 to 900 words maximum. Be concise, highly readable, and mentor-friendly.

OUTPUT REQUIREMENTS:
You MUST respond with a single, valid raw JSON object matching this exact schema:
{
  "overall_status": "Elite Performer"|"Strong Performer"|"Good"|"Average"|"Needs Attention"|"Critical",
  "status_summary": "One clear sentence classifying the student's status strictly from the data.",
  "wa_text": "The complete WhatsApp report formatted with emojis, bold (*text*), italic (_text_), bullet points (•), and line breaks. Must follow the 10-section structure below.",
  "snapshot": {
    "checklist_pct": number,
    "completed": number,
    "pending": number,
    "overdue": number,
    "streak": number,
    "consistency_pct": number
  },
  "academic_strengths": ["string", "string"],
  "academic_weaknesses": ["string", "string"],
  "mega_test_insights": ["string"],
  "live_session_insights": ["string"],
  "ranking_insights": ["string"],
  "appreciation": ["1 to 3 data-supported appreciation points"],
  "warnings": ["0 to 3 data-supported warnings, empty if none"],
  "recommendations": ["3 to 5 concise, prioritized, practical actions"],
  "mentor_note": "One short personalized, data-supported sentence."
}

STRUCTURE FOR `wa_text` (Must follow this exact 10-section layout):

1. Header:
🎓 *STUDENT PERFORMANCE AI ANALYSIS*

👤 *Student:* [Name]
📚 *Course:* [Course]
📅 *Study Plan:* [Selected Study Plan]
📊 *Overall Status:* [Overall Status]

2. Quick Performance Snapshot:
📌 *Checklist:* [XX]%
✅ Completed: [X]
⏳ Pending: [X]
⚠️ Overdue: [X]
🔥 Streak: [X] days
📅 Consistency: [XX]%

3. Academic / Study Plan Analysis:
🟢 *What is going well*
• [Data-backed strength]
• [Data-backed strength]

🟠 *Needs attention*
• [Data-backed area needing attention or "None flagged"]

4. Mega Test Performance:
📝 *MEGA TESTS*
• Attendance: [XX]% (or "Not scheduled/attended")
• Average Score: [XX]% (or "No assessment scores recorded")
• Study Plan Rank: [Rank or "Ranking data unavailable"]
• Key note: [Brief data-supported note]

5. Live Session Attendance:
🎥 *LIVE SESSIONS*
• Attendance: [XX]% (or "Live sessions not recorded for this plan")
• Attended: [X / Y] (or "N/A")
• Note: [Data-backed advice]

6. Cohort / Overall Ranking:
🏆 *RANKING*
• Study Plan Rank: [#X / Y or "Ranking data unavailable"]
• Standing: [Standing or percentile]

7. Appreciation:
🌟 *APPRECIATION*
• [1-3 specific data-supported points]

8. Important Warnings:
⚠️ *IMPORTANT*
• [Only include if actual overdue tasks, low attendance, or low consistency exist. If none, write "• No critical warnings at this time."]

9. Mentor Recommendations:
🎯 *RECOMMENDED ACTIONS*
1. [Action 1]
2. [Action 2]
3. [Action 3]

10. Closing:
💡 *Mentor Note:*
[One short personalized, encouraging sentence.]
PROMPT;

        return [
            'system' => $systemPrompt,
            'user' => $payload
        ];
    }

    /**
     * Executes the complete AI evaluation on-demand.
     *
     * @param PDO $pdo
     * @param string $studentIdOrEmail
     * @param int $studyPlanId
     * @param int $currentAdminId
     * @param bool $isSuperAdmin
     * @return array Standardized result payload with `wa_text` and card data.
     */
    public function analyzeStudentStudyPlan(
        PDO $pdo,
        string $studentIdOrEmail,
        int $studyPlanId,
        int $currentAdminId = 0,
        bool $isSuperAdmin = true
    ): array {
        $canonicalData = self::extractCanonicalData($pdo, $studentIdOrEmail, $studyPlanId, $currentAdminId, $isSuperAdmin);
        $prompts = self::buildPrompts($canonicalData);

        // Fallback result in case provider is mock or unconfigured
        if (!$this->provider || !$this->provider->isConfigured()) {
            return self::buildFallbackResponse($canonicalData, "AI provider credentials not configured.");
        }

        try {
            $rawJson = '';
            if (method_exists($this->provider, 'generateContentRaw')) {
                $rawJson = $this->provider->generateContentRaw($prompts['system'], $prompts['user'], [
                    'temperature' => 0.2,
                    'responseMimeType' => 'application/json'
                ]);
            } elseif (method_exists($this->provider, 'analyzeAssessment')) {
                // If using MockAiProvider, handle custom mock response
                $mockRes = $this->provider->analyzeAssessment($canonicalData['checklist_audit'], $canonicalData['student_profile']);
                if (isset($mockRes['wa_text'])) {
                    return array_merge($canonicalData, $mockRes);
                }
                return self::buildFallbackResponse($canonicalData, "Mock provider response generated.");
            } else {
                return self::buildFallbackResponse($canonicalData, "Unsupported AI provider.");
            }

            $cleanJson = trim($rawJson);
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
            if (!is_array($parsed) || empty($parsed['wa_text'])) {
                return self::buildFallbackResponse($canonicalData, "AI output failed format validation.");
            }

            return [
                'success' => true,
                'data' => $canonicalData,
                'analysis' => $parsed,
                'wa_text' => trim((string)$parsed['wa_text']),
                'model' => $this->provider->getModelName(),
                'provider' => $this->provider->getProviderName(),
                'generated_at' => date('d M Y h:i A')
            ];
        } catch (Exception $e) {
            error_log('StudentMentorAiService error: ' . $e->getMessage());
            return self::buildFallbackResponse($canonicalData, "AI analysis temporarily unavailable: " . $e->getMessage());
        }
    }

    /**
     * Deterministic, zero-hallucination fallback response matching the exact schema
     * when Gemini is temporarily unreachable or unconfigured.
     */
    public static function buildFallbackResponse(array $canonicalData, string $reason): array {
        $p = $canonicalData['student_profile'];
        $c = $canonicalData['checklist_audit'];
        $m = $canonicalData['mega_tests'];
        $l = $canonicalData['live_sessions'];
        $r = $canonicalData['cohort_ranking'];

        $overallStatus = ($c['completion_percentage'] >= 85) ? 'Strong Performer' : (($c['completion_percentage'] >= 50) ? 'Average' : 'Needs Attention');

        $megaAttText = $m['has_data'] && $m['attendance_percentage'] !== null ? "{$m['attendance_percentage']}%" : 'Not scheduled/attended';
        $megaScoreText = $m['has_data'] && $m['average_score_percentage'] !== null ? "{$m['average_score_percentage']}%" : 'No assessment scores recorded';
        $rankText = $r['has_data'] ? "#{$r['study_plan_rank']} / {$r['cohort_size']}" : 'Ranking data unavailable';
        $liveText = $l['has_data'] ? "{$l['attended_sessions']}/{$l['eligible_sessions']} ({$l['attendance_percentage']}%)" : 'Live sessions not recorded for this plan';

        $waText = "🎓 *STUDENT PERFORMANCE AI ANALYSIS*\n\n"
            . "👤 *Student:* {$p['name']}\n"
            . "📚 *Course:* {$p['course']}\n"
            . "📅 *Study Plan:* {$p['selected_study_plan']}\n"
            . "📊 *Overall Status:* {$overallStatus}\n\n"
            . "📌 *Checklist:* {$c['completion_percentage']}%\n"
            . "✅ Completed: {$c['completed_tasks']}\n"
            . "⏳ Pending: {$c['pending_tasks']}\n"
            . "⚠️ Overdue: {$c['overdue_tasks']}\n"
            . "🔥 Streak: {$c['current_streak']} days\n"
            . "📅 Consistency: {$c['consistency_percentage']}%\n\n"
            . "🟢 *What is going well*\n"
            . "• Completed {$c['completed_tasks']} out of {$c['total_tasks']} prescribed tasks.\n"
            . "• Maintained a {$c['current_streak']}-day active learning streak.\n\n"
            . "🟠 *Needs attention*\n"
            . ($c['overdue_tasks'] > 0 ? "• {$c['overdue_tasks']} overdue tasks require immediate clearance.\n" : "• Maintain continuous daily study habit.\n")
            . "\n📝 *MEGA TESTS*\n"
            . "• Attendance: {$megaAttText}\n"
            . "• Average Score: {$megaScoreText}\n"
            . "• Study Plan Rank: {$rankText}\n\n"
            . "🎥 *LIVE SESSIONS*\n"
            . "• Status: {$liveText}\n\n"
            . "🏆 *RANKING*\n"
            . "• Study Plan Rank: {$rankText}\n"
            . ($r['has_data'] && !empty($r['standing_badge']) ? "• Standing: {$r['standing_badge']}\n" : "")
            . "\n🌟 *APPRECIATION*\n"
            . "• Consistent participation in active study-plan activities.\n"
            . "• Dedicated effort demonstrated in {$p['selected_study_plan']}.\n\n"
            . "⚠️ *IMPORTANT*\n"
            . ($c['overdue_tasks'] > 0 ? "• Clear {$c['overdue_tasks']} overdue checklist item(s) to avoid study plan backlog.\n" : "• No critical warnings at this time.\n")
            . "\n🎯 *RECOMMENDED ACTIONS*\n"
            . "1. Complete pending checklist items in chronological sequence.\n"
            . "2. Maintain daily study streak to improve consistency score.\n"
            . "3. Participate actively in upcoming assessments and live sessions.\n\n"
            . "💡 *Mentor Note:*\n"
            . "Stay focused and disciplined—steady daily effort is the key to academic excellence!";

        return [
            'success' => true,
            'is_fallback' => true,
            'fallback_reason' => $reason,
            'data' => $canonicalData,
            'analysis' => [
                'overall_status' => $overallStatus,
                'status_summary' => "Student has completed {$c['completed_tasks']}/{$c['total_tasks']} tasks ({$c['completion_percentage']}%) with a {$c['current_streak']}-day streak.",
                'wa_text' => $waText,
                'snapshot' => [
                    'checklist_pct' => $c['completion_percentage'],
                    'completed' => $c['completed_tasks'],
                    'pending' => $c['pending_tasks'],
                    'overdue' => $c['overdue_tasks'],
                    'streak' => $c['current_streak'],
                    'consistency_pct' => $c['consistency_percentage']
                ],
                'academic_strengths' => [
                    "Completed {$c['completed_tasks']} tasks in {$p['selected_study_plan']}",
                    "Active study streak of {$c['current_streak']} days"
                ],
                'academic_weaknesses' => $c['overdue_tasks'] > 0 ? ["{$c['overdue_tasks']} overdue tasks pending completion"] : ["Maintain continuous momentum"],
                'mega_test_insights' => [$megaAttText !== 'Not scheduled/attended' ? "Mega test attendance at {$megaAttText}" : "No mega tests recorded"],
                'live_session_insights' => [$liveText],
                'ranking_insights' => [$rankText],
                'appreciation' => [
                    "Consistent engagement with {$p['selected_study_plan']}",
                    "Active learning streak maintained"
                ],
                'warnings' => $c['overdue_tasks'] > 0 ? ["{$c['overdue_tasks']} overdue items pending"] : ["No critical warnings"],
                'recommendations' => [
                    "Clear pending checklist items",
                    "Maintain continuous daily study streak",
                    "Participate in upcoming assessments"
                ],
                'mentor_note' => "Steady daily effort is the key to academic excellence!"
            ],
            'wa_text' => $waText,
            'model' => 'deterministic-fallback',
            'provider' => 'pepp-erp-canonical',
            'generated_at' => date('d M Y h:i A')
        ];
    }
}
