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
                   pepp_course, pepp_academic_year, student_status, user_photo, status,
                   joined_date, approval_date, created_at
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
        $liveData = $planAnalytics['live_sessions'] ?? null;
        if (!empty($liveData) && is_array($liveData) && isset($liveData['has_data'])) {
            $hasLiveSessions = (bool)$liveData['has_data'];
            $liveScheduled = (int)($liveData['scheduled_sessions'] ?? $liveData['total_sessions'] ?? 0);
            $liveEligible = (int)($liveData['eligible_sessions'] ?? $liveScheduled);
            $liveAttended = (int)($liveData['attended_sessions'] ?? 0);
            $liveMissed = (int)($liveData['missed_sessions'] ?? 0);
            $livePending = (int)($liveData['pending_sessions'] ?? 0);
            $livePreAdmission = (int)($liveData['pre_admission_sessions'] ?? 0);
            $liveAttendancePct = $liveData['attendance_percentage'] ?? null;
            $sessionsBreakdown = [];
            if (!empty($liveData['sessions']) && is_array($liveData['sessions'])) {
                foreach ($liveData['sessions'] as $s) {
                    $sessionsBreakdown[] = [
                        'title' => $s['title'] ?? 'Live Session',
                        'date' => $s['date'] ?? null,
                        'status' => $s['status'] ?? 'Pending',
                        'is_completed' => !empty($s['is_completed']),
                        'is_pre_admission' => !empty($s['is_pre_admission'])
                    ];
                }
            }
        } else {
            // Defensive fallback using learning_highlights['all_activities']
            $allActs = $planAnalytics['learning_highlights']['all_activities'] ?? [];
            $liveScheduled = 0;
            $liveEligible = 0;
            $liveAttended = 0;
            $liveMissed = 0;
            $livePending = 0;
            $livePreAdmission = 0;
            $sessionsBreakdown = [];
            foreach ($allActs as $act) {
                if (($act['type_category'] ?? '') === 'live_session') {
                    $liveScheduled++;
                    $isComp = !empty($act['is_completed']);
                    $isPre = !empty($act['is_pre_admission']);
                    $isOver = !empty($act['is_overdue']);

                    if ($isPre) {
                        $livePreAdmission++;
                        $status = $isComp ? 'Attended' : 'Pre-admission';
                    } else {
                        $liveEligible++;
                        if ($isComp) {
                            $liveAttended++;
                            $status = 'Attended';
                        } elseif ($isOver) {
                            $liveMissed++;
                            $status = 'Missed';
                        } else {
                            $livePending++;
                            $status = 'Pending';
                        }
                    }

                    $sessionsBreakdown[] = [
                        'title' => $act['activity_title'] ?? $act['topic'] ?? 'Live Session',
                        'date' => $act['activity_date'] ?? null,
                        'status' => $status,
                        'is_completed' => $isComp,
                        'is_pre_admission' => $isPre
                    ];
                }
            }
            $hasLiveSessions = ($liveScheduled > 0);
            $liveAttendancePct = ($liveEligible > 0) ? (int)round(($liveAttended / $liveEligible) * 100) : null;
        }

        $canonicalLiveSessions = [
            'has_data' => $hasLiveSessions,
            'scheduled_sessions' => $liveScheduled,
            'eligible_sessions' => $liveEligible,
            'attended_sessions' => $liveAttended,
            'missed_sessions' => $liveMissed,
            'pending_sessions' => $livePending,
            'pre_admission_sessions' => $livePreAdmission,
            'attendance_percentage' => $liveAttendancePct,
            'sessions_breakdown' => $sessionsBreakdown
        ];

        // 9. Cohort & Ranking
        $cohortRanking = $planAnalytics['cohort_ranking'] ?? null;
        $currRankStudent = $cohortRanking['current_student'] ?? null;
        $studyPlanRank = $currRankStudent['rank'] ?? null;
        $cohortSize = $currRankStudent['cohort_size'] ?? ($cohortRanking['cohort_size'] ?? null);
        $percentileText = $currRankStudent['percentile_text'] ?? null;
        $standingBadge = $currRankStudent['badge'] ?? null;

        $enrollmentContext = $planAnalytics['enrollment_context'] ?? [
            'has_data' => false,
            'joined_date' => null,
            'plan_start_date' => null,
            'plan_end_date' => null,
            'report_end_date' => null,
            'total_plan_days' => $calendarDays,
            'days_enrolled_in_plan' => $calendarDays,
            'is_partial_period_participant' => false,
            'participation_tenure_category' => 'FULL_PERIOD',
            'tenure_summary' => 'Enrolled for the full study-plan period.'
        ];

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
            'enrollment_context' => $enrollmentContext,
            'checklist_audit' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'pending_tasks' => $pendingTasks,
                'overdue_tasks' => $overdueTasks,
                'completion_percentage' => $completionPct,
                'raw_total_tasks' => (int)($planAnalytics['raw_total_tasks'] ?? $totalTasks),
                'raw_completed_tasks' => (int)($planAnalytics['raw_completed_tasks'] ?? $completedTasks),
                'raw_pending_tasks' => (int)($planAnalytics['raw_pending_tasks'] ?? $pendingTasks),
                'raw_overdue_tasks' => (int)($planAnalytics['raw_overdue_tasks'] ?? $overdueTasks),
                'eligible_tasks_since_joining' => (int)($planAnalytics['eligible_tasks_since_joining'] ?? $totalTasks),
                'completed_eligible_tasks' => (int)($planAnalytics['completed_eligible_tasks'] ?? $completedTasks),
                'pending_eligible_tasks' => (int)($planAnalytics['pending_eligible_tasks'] ?? $pendingTasks),
                'overdue_eligible_tasks' => (int)($planAnalytics['overdue_eligible_tasks'] ?? $overdueTasks),
                'pre_admission_tasks_count' => (int)($planAnalytics['pre_admission_tasks_count'] ?? 0),
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
                'pre_admission_tests' => (int)($planAnalytics['pre_admission_mega_tests'] ?? 0),
                'attendance_percentage' => $megaAttendanceRate,
                'average_score_percentage' => $megaTestAvgScore,
                'tests_breakdown' => array_slice($chapterAssessments, 0, 5)
            ],
            'live_sessions' => $canonicalLiveSessions,
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
     * Sanitizes long dashes (EM DASH U+2014, EN DASH U+2013, HORIZONTAL BAR U+2015) to ASCII hyphen.
     * Prevents any em dash characters from appearing in WhatsApp or AI outputs.
     */
    public static function sanitizeDashes(string $text): string {
        return str_replace(["\xE2\x80\x94", "\xE2\x80\x93", "\xE2\x80\x95", '—', '–', '―'], '-', $text);
    }

    /**
     * Recursively sanitizes dashes across arrays and strings.
     */
    public static function sanitizeDashesDeep($val) {
        if (is_string($val)) {
            return self::sanitizeDashes($val);
        }
        if (is_array($val)) {
            $cleaned = [];
            foreach ($val as $k => $v) {
                $cleaned[$k] = self::sanitizeDashesDeep($v);
            }
            return $cleaned;
        }
        return $val;
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
5. ABSOLUTE FORMATTING RULE: NEVER use an em dash character ("—") or en dash ("–") anywhere in your output. Always use a standard hyphen ("-") with spaces if separating thoughts.
6. ENROLLMENT TENURE AWARENESS:
   - Check the `enrollment_context` object.
   - If `is_partial_period_participant` is true:
     - The student enrolled after the study plan start date.
     - Never describe pre-admission activities as missed student obligations, overdue items, or student deficits.
     - Evaluate checklist tasks and consistency in the context of their actual enrolled participation window.
     - Do NOT display technical category names like "PARTIAL_PERIOD" to the student; use natural mentor-facing wording (e.g. "Joined on [date], actively participating for [X] days").
7. LIVE SESSION RULES:
   - Never claim a student missed a session that occurred before admission. Pre-admission sessions (marked 'Pre-admission' or `is_pre_admission = true`) are excluded from attendance denominator and missed session count.
   - Distinguish carefully:
     A. If `has_data` is false or no records exist: state "No live-session records are available for this study plan."
     B. If sessions exist but attendance data is unavailable: state "Live sessions are recorded in the study plan, but attendance data is unavailable."
     C. If sessions exist and attendance is recorded: show Attendance percentage, Attended, Missed, and Pending.
8. MEGA TESTS:
   - If `has_data` is false: state "No published mega tests for this study plan".
   - If tests occurred before admission, they are not treated as student missed tests.
9. COHORT RANKING / STANDING:
   - Do NOT invent or infer standing titles like "Elite Performer" or "Top Performer" unless explicitly given in `standing_badge`.
   - If ranking is available without a specific badge, report "Cohort Rank: #[rank] / [cohort_size]". If unavailable, state "Ranking data unavailable".
10. RECOMMENDATION CONTRADICTION PROTECTION (CRITICAL):
   - If `pending_tasks` == 0: NEVER recommend completing pending tasks.
   - If `overdue_tasks` == 0: NEVER recommend clearing overdue tasks or include overdue warnings.
   - If `missed_sessions` == 0: NEVER recommend improving live-session attendance.
   - If no live-session data exists: DO NOT imply poor attendance.
   - If no Mega Test data exists: DO NOT imply test weakness.
   - If student has a strong streak (>= 5 days): DO NOT give generic "build a streak" advice; encourage keeping the active streak going.
   - If student has high completion (>= 90% or pending == 0): recommendations must focus on the next meaningful improvement (revision, notes synthesis, preparing for upcoming assessments).
   - No generic template recommendation may contradict the KPI snapshot!
11. Output length: 500 to 900 words maximum. Be concise, highly readable, and mentor-friendly.

OUTPUT REQUIREMENTS:
You MUST respond with a single, valid raw JSON object matching this exact schema:
{
  "overall_status": "Strong Performer"|"Good"|"Average"|"Needs Attention"|"Critical",
  "status_summary": "One clear sentence classifying the student's status strictly from the data.",
  "wa_text": "The complete WhatsApp report formatted with emojis, bold (*text*), italic (_text_), bullet points (•), and line breaks. Must follow the structure below.",
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
  "recommendations": ["3 to 5 concise, prioritized, non-contradictory actions"],
  "mentor_note": "One short personalized, data-supported sentence without em dash."
}

STRUCTURE FOR `wa_text` (Must follow this exact 10-section layout):
1. Header:
🎓 *STUDENT PERFORMANCE AI ANALYSIS*

👤 *Student:* [Name]
📚 *Course:* [Course]
📅 *Study Plan:* [Selected Study Plan]
📊 *Overall Status:* [Overall Status]

2. Enrollment Context (Include ONLY when student joined after plan start date):
📅 *Enrollment Context:* Joined on [Date] ([X] days enrolled in plan window). Evaluated against eligible activities since joining.

3. Quick Performance Snapshot:
📌 *Checklist:* [XX]%
✅ Completed: [X]
⏳ Pending: [X]
⚠️ Overdue: [X]
🔥 Streak: [X] days
📅 Consistency: [XX]%

4. Key Strengths:
🟢 *Key Strengths*
• [Data-backed strength]
• [Data-backed strength]

5. Areas to Watch:
🟠 *Areas to Watch*
• [Data-backed area needing attention or "All scheduled tasks completed on track"]

6. Mega Test Performance:
📝 *MEGA TESTS*
• Attendance: [XX]% (or "No published mega tests for this study plan")
• Average Score: [XX]% (or "No assessment scores recorded")
• Study Plan Rank: [Rank or "Ranking data unavailable"]

7. Live Session Attendance:
🎥 *LIVE SESSIONS*
• Attendance: [XX]% (or "No live-session records are available for this study plan" or "Live sessions are recorded in the study plan, but attendance data is unavailable")
• Attended: [X / Y] (or omit if no records)
• Missed / Pending: [X missed, Y pending] (omit if 0 missed and 0 pending)
• Note: [Data-backed note if applicable]

8. Cohort / Overall Ranking:
🏆 *RANKING*
• Study Plan Rank: [#X / Y or "Ranking data unavailable"]
• Standing: [Standing or percentile]

9. Appreciation:
🌟 *APPRECIATION*
• [1-3 specific data-supported points]

10. Important Warnings:
⚠️ *IMPORTANT*
• [Only include if genuine overdue tasks, low attendance, or low consistency exist. If none, write "• No critical warnings at this time."]

11. Mentor Recommendations:
🎯 *RECOMMENDED ACTIONS*
1. [Action 1]
2. [Action 2]
3. [Action 3]

12. Mentor Note:
💡 *Mentor Note:*
[One short personalized, encouraging sentence without any em dash.]
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
                    $mockRes = self::sanitizeDashesDeep($mockRes);
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

            // Contradiction prevention post-check on AI-generated recommendations and warnings
            $chk = $canonicalData['checklist_audit'];
            $live = $canonicalData['live_sessions'];
            $pendingCount = $chk['pending_eligible_tasks'] ?? $chk['pending_tasks'];
            $overdueCount = $chk['overdue_eligible_tasks'] ?? $chk['overdue_tasks'];
            $missedLive = $live['missed_sessions'] ?? 0;

            if (isset($parsed['recommendations']) && is_array($parsed['recommendations'])) {
                $filteredRecs = [];
                foreach ($parsed['recommendations'] as $rec) {
                    $recLower = strtolower($rec);
                    if ($pendingCount === 0 && (str_contains($recLower, 'pending') || str_contains($recLower, 'complete pending') || str_contains($recLower, 'checklist item'))) {
                        continue;
                    }
                    if ($overdueCount === 0 && (str_contains($recLower, 'overdue') || str_contains($recLower, 'clear overdue') || str_contains($recLower, 'backlog'))) {
                        continue;
                    }
                    if ($missedLive === 0 && (str_contains($recLower, 'missed live') || str_contains($recLower, 'improve live') || str_contains($recLower, 'missed session'))) {
                        continue;
                    }
                    if (($chk['current_streak'] >= 5) && (str_contains($recLower, 'build a streak') || str_contains($recLower, 'start a streak') || str_contains($recLower, 'begin a daily streak'))) {
                        continue;
                    }
                    $filteredRecs[] = $rec;
                }
                if (empty($filteredRecs)) {
                    if ($chk['completion_percentage'] >= 90 || $pendingCount === 0) {
                        $filteredRecs[] = "Focus on comprehensive topic revision, notes consolidation, and exam preparation.";
                        $filteredRecs[] = "Review chapter summaries and attempt additional practice assessments.";
                    } else {
                        $filteredRecs[] = "Continue following your structured study plan and maintain daily learning habits.";
                    }
                }
                $parsed['recommendations'] = $filteredRecs;
            }

            if (isset($parsed['warnings']) && is_array($parsed['warnings'])) {
                $filteredWarns = [];
                foreach ($parsed['warnings'] as $w) {
                    $wLower = strtolower($w);
                    if ($overdueCount === 0 && str_contains($wLower, 'overdue')) {
                        continue;
                    }
                    if ($missedLive === 0 && str_contains($wLower, 'missed')) {
                        continue;
                    }
                    $filteredWarns[] = $w;
                }
                $parsed['warnings'] = !empty($filteredWarns) ? $filteredWarns : ["No critical warnings at this time."];
            }

            // Strict em dash sanitization across entire parsed response
            $parsed = self::sanitizeDashesDeep($parsed);
            $waText = self::sanitizeDashes(trim((string)$parsed['wa_text']));
            $parsed['wa_text'] = $waText;

            return [
                'success' => true,
                'data' => $canonicalData,
                'analysis' => $parsed,
                'wa_text' => $waText,
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
        $ec = $canonicalData['enrollment_context'] ?? [];
        $c = $canonicalData['checklist_audit'];
        $m = $canonicalData['mega_tests'];
        $l = $canonicalData['live_sessions'];
        $r = $canonicalData['cohort_ranking'];

        $overallStatus = ($c['completion_percentage'] >= 85) ? 'Strong Performer' : (($c['completion_percentage'] >= 50) ? 'Average' : 'Needs Attention');

        $megaAttText = $m['has_data'] && $m['attendance_percentage'] !== null ? "{$m['attendance_percentage']}%" : 'Not scheduled/attended';
        $megaScoreText = $m['has_data'] && $m['average_score_percentage'] !== null ? "{$m['average_score_percentage']}%" : 'No assessment scores recorded';
        $rankText = $r['has_data'] ? "#{$r['study_plan_rank']} / {$r['cohort_size']}" : 'Ranking data unavailable';

        // Live Sessions Section
        if ($l['has_data'] && $l['scheduled_sessions'] > 0) {
            $liveLines = [
                "• Attendance: {$l['attended_sessions']}/{$l['eligible_sessions']} ({$l['attendance_percentage']}%)",
                "• Attended: {$l['attended_sessions']}",
                "• Missed: {$l['missed_sessions']}",
                "• Pending: {$l['pending_sessions']}"
            ];
            if (!empty($l['pre_admission_sessions']) && $l['pre_admission_sessions'] > 0) {
                $liveLines[] = "• Pre-admission: {$l['pre_admission_sessions']} session(s) excluded from attendance calculation";
            }
            $unattendedSessions = [];
            if (!empty($l['sessions_breakdown']) && is_array($l['sessions_breakdown'])) {
                foreach ($l['sessions_breakdown'] as $sb) {
                    if (empty($sb['is_completed']) && empty($sb['is_pre_admission']) && ($sb['status'] ?? '') === 'Missed') {
                        $dtStr = !empty($sb['date']) ? " ({$sb['date']})" : '';
                        $unattendedSessions[] = "  - {$sb['title']}{$dtStr} [Missed]";
                    }
                }
            }
            if (!empty($unattendedSessions)) {
                $liveLines[] = "• Attention required on:\n" . implode("\n", array_slice($unattendedSessions, 0, 4));
            }
            $liveSectionText = implode("\n", $liveLines);
        } elseif ($l['has_data'] && $l['scheduled_sessions'] === 0) {
            $liveSectionText = "• Status: Live sessions are recorded in the study plan, but attendance data is unavailable.";
        } else {
            $liveSectionText = "• Status: No live-session records are available for this study plan.";
        }

        // Enrollment Context line (included only when student is partial-period or recently joined)
        $isPartial = !empty($ec['is_partial_period_participant']);
        $enrollmentContextLine = "";
        if ($isPartial && !empty($ec['joined_date'])) {
            $enrollmentContextLine = "📅 *Enrollment Context:* Joined on {$ec['joined_date']} ({$ec['days_enrolled_in_plan']} days enrolled in plan window). Evaluated against eligible activities since joining.\n\n";
        }

        // Key Strengths
        $strengthsList = [];
        $compEligible = $c['completed_eligible_tasks'] ?? $c['completed_tasks'];
        $eligibleTasks = $c['eligible_tasks_since_joining'] ?? $c['total_tasks'];
        if ($compEligible > 0) {
            $strengthsList[] = "• Completed {$compEligible} out of {$eligibleTasks} eligible prescribed tasks.";
        }
        if ($c['current_streak'] > 0) {
            $strengthsList[] = "• Maintained an active {$c['current_streak']}-day learning streak.";
        }
        if (empty($strengthsList)) {
            $strengthsList[] = "• Enrolled in {$p['selected_study_plan']} and ready to progress.";
        }

        // Areas to Watch
        $overdueCount = $c['overdue_eligible_tasks'] ?? $c['overdue_tasks'];
        $pendingCount = $c['pending_eligible_tasks'] ?? $c['pending_tasks'];
        $areasToWatch = [];
        if ($overdueCount > 0) {
            $areasToWatch[] = "• {$overdueCount} overdue task(s) require immediate clearance.";
        } elseif ($pendingCount > 0) {
            $areasToWatch[] = "• {$pendingCount} task(s) remaining to be completed in the study plan.";
        } else {
            $areasToWatch[] = "• All scheduled study plan tasks completed on track.";
        }

        // Important Notes / Warnings
        $warnings = [];
        if ($overdueCount > 0) {
            $warnings[] = "• Clear {$overdueCount} overdue checklist item(s) to avoid study plan backlog.";
        }
        if ($l['has_data'] && $l['missed_sessions'] > 0) {
            $warnings[] = "• {$l['missed_sessions']} live session(s) missed - review recordings.";
        }
        if (empty($warnings)) {
            $warnings[] = "• No critical warnings at this time.";
        }

        // Recommendations (STRICT CONTRADICTION PROTECTION)
        $recommendations = [];
        if ($pendingCount > 0) {
            $recommendations[] = "Complete remaining {$pendingCount} checklist item(s) in chronological sequence.";
        }
        if ($overdueCount > 0) {
            $recommendations[] = "Clear {$overdueCount} overdue task(s) promptly to stay aligned with the syllabus.";
        }
        if ($l['has_data'] && $l['missed_sessions'] > 0) {
            $recommendations[] = "Watch recordings for the {$l['missed_sessions']} missed live session(s) and attend upcoming sessions.";
        }
        if ($c['current_streak'] >= 5) {
            $recommendations[] = "Maintain your strong {$c['current_streak']}-day study streak across the upcoming study plan weeks.";
        } elseif ($c['consistency_percentage'] < 50 || $c['current_streak'] < 3) {
            $recommendations[] = "Establish a regular daily study habit to improve consistency.";
        }
        if ($c['completion_percentage'] >= 90 || $pendingCount === 0) {
            $recommendations[] = "Engage in comprehensive topic revision, notes consolidation, and exam preparation.";
            $recommendations[] = "Review chapter summaries and attempt additional practice assessments.";
        }
        if (empty($recommendations)) {
            $recommendations[] = "Continue daily structured study and track progress regularly.";
            $recommendations[] = "Review completed chapters and reinforce core concepts.";
        }

        // Limit to 3-4 distinct recommendations
        $recommendations = array_slice(array_unique($recommendations), 0, 4);

        $recLines = [];
        foreach ($recommendations as $idx => $rItem) {
            $num = $idx + 1;
            $recLines[] = "{$num}. {$rItem}";
        }

        $megaLines = [];
        if ($m['has_data']) {
            $megaLines[] = "• Attendance: {$megaAttText}";
            $megaLines[] = "• Average Score: {$megaScoreText}";
            $megaLines[] = "• Study Plan Rank: {$rankText}";
            if (!empty($m['pre_admission_tests']) && $m['pre_admission_tests'] > 0) {
                $megaLines[] = "• Note: {$m['pre_admission_tests']} pre-admission test(s) excluded from attendance calculation";
            }
        } else {
            $megaLines[] = "• Status: No published mega tests for this study plan";
        }
        $megaSectionText = implode("\n", $megaLines);

        $waText = "🎓 *STUDENT PERFORMANCE AI ANALYSIS*\n\n"
            . "👤 *Student:* {$p['name']}\n"
            . "📚 *Course:* {$p['course']}\n"
            . "📅 *Study Plan:* {$p['selected_study_plan']}\n"
            . "📊 *Overall Status:* {$overallStatus}\n\n"
            . $enrollmentContextLine
            . "📌 *Checklist:* {$c['completion_percentage']}%\n"
            . "✅ Completed: {$c['completed_tasks']}\n"
            . "⏳ Pending: {$c['pending_tasks']}\n"
            . "⚠️ Overdue: {$c['overdue_tasks']}\n"
            . "🔥 Streak: {$c['current_streak']} days\n"
            . "📅 Consistency: {$c['consistency_percentage']}%\n\n"
            . "🟢 *Key Strengths*\n"
            . implode("\n", $strengthsList) . "\n\n"
            . "🟠 *Areas to Watch*\n"
            . implode("\n", $areasToWatch) . "\n\n"
            . "📝 *MEGA TESTS*\n"
            . $megaSectionText . "\n\n"
            . "🎥 *LIVE SESSIONS*\n"
            . "{$liveSectionText}\n\n"
            . "🏆 *RANKING*\n"
            . "• Study Plan Rank: {$rankText}\n"
            . ($r['has_data'] && !empty($r['standing_badge']) ? "• Standing: {$r['standing_badge']}\n" : "")
            . "\n🌟 *APPRECIATION*\n"
            . "• Dedicated effort demonstrated in {$p['selected_study_plan']}.\n"
            . ($c['current_streak'] >= 3 ? "• Active learning streak maintained with discipline.\n" : "• Ongoing commitment to structured learning.\n")
            . "\n⚠️ *IMPORTANT*\n"
            . implode("\n", $warnings) . "\n\n"
            . "🎯 *RECOMMENDED ACTIONS*\n"
            . implode("\n", $recLines) . "\n\n"
            . "💡 *Mentor Note:*\n"
            . "Stay focused and disciplined - steady daily effort is the key to academic excellence!";

        // Final deterministic dash sanitization
        $waText = self::sanitizeDashes($waText);
        $cleanRecs = array_map([self::class, 'sanitizeDashes'], $recommendations);
        $cleanWarnings = array_map([self::class, 'sanitizeDashes'], $warnings);

        return [
            'success' => true,
            'is_fallback' => true,
            'fallback_reason' => self::sanitizeDashes($reason),
            'data' => $canonicalData,
            'analysis' => [
                'overall_status' => $overallStatus,
                'status_summary' => self::sanitizeDashes("Student has completed {$c['completed_tasks']}/{$c['total_tasks']} tasks ({$c['completion_percentage']}%) with a {$c['current_streak']}-day streak."),
                'wa_text' => $waText,
                'snapshot' => [
                    'checklist_pct' => $c['completion_percentage'],
                    'completed' => $c['completed_tasks'],
                    'pending' => $c['pending_tasks'],
                    'overdue' => $c['overdue_tasks'],
                    'streak' => $c['current_streak'],
                    'consistency_pct' => $c['consistency_percentage']
                ],
                'academic_strengths' => array_map([self::class, 'sanitizeDashes'], $strengthsList),
                'academic_weaknesses' => array_map([self::class, 'sanitizeDashes'], $areasToWatch),
                'mega_test_insights' => [$m['has_data'] ? ($megaAttText !== 'Not scheduled/attended' ? "Mega test attendance at {$megaAttText}" : "No mega tests recorded") : "No published mega tests for this study plan"],
                'live_session_insights' => [$l['has_data'] ? "Attended {$l['attended_sessions']}/{$l['eligible_sessions']} live sessions ({$l['attendance_percentage']}%)" : "No live-session records are available for this study plan"],
                'ranking_insights' => [$rankText],
                'appreciation' => [
                    "Consistent engagement with {$p['selected_study_plan']}",
                    "Active learning progress maintained"
                ],
                'warnings' => $cleanWarnings,
                'recommendations' => $cleanRecs,
                'mentor_note' => "Steady daily effort is the key to academic excellence!"
            ],
            'wa_text' => $waText,
            'model' => 'deterministic-fallback',
            'provider' => 'pepp-erp-canonical',
            'generated_at' => date('d M Y h:i A')
        ];
    }
}
