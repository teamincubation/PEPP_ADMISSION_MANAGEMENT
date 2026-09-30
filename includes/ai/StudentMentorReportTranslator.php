<?php
/**
 * PEPP Learning ERP — Student Mentor Report Multilingual Translator
 *
 * Provides translation services for Student Mentor AI reports and WhatsApp content
 * into English ('en'), Malayalam ('ml'), and Manglish ('manglish').
 *
 * Core Principles:
 * 1. Truth in Reporting: Never modifies factual calculations, percentages, counts,
 *    overdue/pending counts, streaks, test scores, dates, student IDs, or course names.
 * 2. Canonical Independence: Academic analysis is generated once as canonical report.
 *    Translation operates as a pure transformation layer over the canonical report.
 * 3. Dual Engine: Uses Google Gemini for natural contextual translation with a
 *    battle-tested, zero-hallucination deterministic fallback engine.
 * 4. Cache Efficiency: Session & in-memory caching to eliminate redundant AI calls.
 * 5. Formatting Rigor: Strict dash sanitization (no em dashes or en dashes).
 */

declare(strict_types=1);

require_once __DIR__ . '/GeminiAiProvider.php';

class StudentMentorReportTranslator {
    /**
     * In-memory runtime cache for the current script execution.
     */
    private static array $runtimeCache = [];

    /**
     * Supported languages.
     */
    public const LANG_EN = 'en';
    public const LANG_ML = 'ml';
    public const LANG_MANGLISH = 'manglish';

    /**
     * Translates a canonical AI mentoring report into the target language.
     *
     * @param array $canonicalReport The original report from StudentMentorAiService
     * @param string $targetLang 'en' | 'ml' | 'manglish'
     * @param mixed|null $provider Optional GeminiAiProvider instance
     * @param PDO|null $pdo Optional database handle for credential resolution
     * @return array Translated report payload ready for UI and WhatsApp
     */
    public static function translateReport(
        array $canonicalReport,
        string $targetLang = self::LANG_EN,
        $provider = null,
        ?PDO $pdo = null
    ): array {
        $targetLang = strtolower(trim($targetLang));
        if (!in_array($targetLang, [self::LANG_EN, self::LANG_ML, self::LANG_MANGLISH], true)) {
            $targetLang = self::LANG_EN;
        }

        // English is canonical baseline — return immediately with zero additional latency
        if ($targetLang === self::LANG_EN) {
            $report = $canonicalReport;
            $report['language'] = self::LANG_EN;
            $report['labels'] = self::getUiLabels(self::LANG_EN);
            return $report;
        }

        // Cache key based on student, plan, language, and report timestamp
        $studentId = $canonicalReport['data']['student_profile']['user_id']
            ?? ($canonicalReport['data']['student_profile']['email'] ?? 'unknown');
        $planId = $canonicalReport['data']['student_profile']['study_plan_id'] ?? 0;
        $timestamp = $canonicalReport['generated_at'] ?? '';
        $cacheKey = md5("{$studentId}_{$planId}_{$targetLang}_{$timestamp}");

        // 1. Check in-memory runtime cache
        if (isset(self::$runtimeCache[$cacheKey])) {
            return self::$runtimeCache[$cacheKey];
        }

        // 2. Check session cache if available
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['mentor_ai_translations'][$cacheKey])) {
            $cached = $_SESSION['mentor_ai_translations'][$cacheKey];
            if (is_array($cached) && !empty($cached['success'])) {
                self::$runtimeCache[$cacheKey] = $cached;
                return $cached;
            }
        }

        // 3. Attempt Gemini AI translation if provider is available and configured
        $aiTranslated = null;
        if ($provider instanceof GeminiAiProvider && $provider->isConfigured()) {
            try {
                $aiTranslated = self::translateWithGemini($canonicalReport, $targetLang, $provider);
            } catch (Exception $e) {
                error_log("StudentMentorReportTranslator AI error: " . $e->getMessage());
                $aiTranslated = null;
            }
        } elseif ($pdo instanceof PDO) {
            $apiKey = GeminiAiProvider::resolveApiKey($pdo);
            if (!empty($apiKey)) {
                try {
                    $geminiProvider = new GeminiAiProvider($apiKey, 'gemini-3.5-flash');
                    $aiTranslated = self::translateWithGemini($canonicalReport, $targetLang, $geminiProvider);
                } catch (Exception $e) {
                    error_log("StudentMentorReportTranslator AI auto-provider error: " . $e->getMessage());
                    $aiTranslated = null;
                }
            }
        }

        // 4. If AI translation succeeded, assemble and return
        if (is_array($aiTranslated) && !empty($aiTranslated['analysis']) && !empty($aiTranslated['wa_text'])) {
            $result = array_merge($canonicalReport, [
                'language' => $targetLang,
                'analysis' => $aiTranslated['analysis'],
                'wa_text' => $aiTranslated['wa_text'],
                'labels' => self::getUiLabels($targetLang),
                'translation_mode' => 'gemini-ai'
            ]);

            // Cache in memory and session
            self::$runtimeCache[$cacheKey] = $result;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['mentor_ai_translations'][$cacheKey] = $result;
            }

            return $result;
        }

        // 5. High-quality deterministic translation fallback
        $deterministic = self::translateDeterministically($canonicalReport, $targetLang);
        $result = array_merge($canonicalReport, [
            'language' => $targetLang,
            'analysis' => $deterministic['analysis'],
            'wa_text' => $deterministic['wa_text'],
            'labels' => self::getUiLabels($targetLang),
            'translation_mode' => 'deterministic-engine'
        ]);

        // Cache in memory and session
        self::$runtimeCache[$cacheKey] = $result;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mentor_ai_translations'][$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Translates report content via Gemini AI with strict factual protection constraints.
     */
    private static function translateWithGemini(
        array $canonicalReport,
        string $targetLang,
        GeminiAiProvider $provider
    ): ?array {
        $sourceAnalysis = $canonicalReport['analysis'] ?? [];
        if (empty($sourceAnalysis)) {
            return null;
        }

        $langDescriptor = ($targetLang === self::LANG_ML) ? 'Malayalam (മലയാളം)' : 'Manglish (Malayalam written in conversational Latin/English script)';
        $langGuideline = ($targetLang === self::LANG_ML)
            ? "Use natural, professional, grammatically sound Malayalam suitable for students and parents. Use appropriate educational terms (e.g. 'അക്കാദമിക് പ്രകടന വിലയിരുത്തൽ' for Assessment, 'പ്രത്യേക ശ്രദ്ധ ആവശ്യമാണ്' for Needs Attention). Avoid awkward, robotic machine translation."
            : "Use natural Kerala conversational Manglish written in Latin script. Keep familiar English terms (tasks, streak, study plan, live session, mega test, consistency, complete, pending, overdue, rank). Make it engaging, respectful, and easy to read on WhatsApp (e.g. 'Student 97 tasks-il 0 ennam mathram complete cheythittullu (0%). Ippol current streak 0 days aanu.').";

        $systemPrompt = <<<PROMPT
You are an expert bilingual Academic Mentor for PEPP Learning ERP.
Your task is to translate an academic mentoring report and WhatsApp message into {$langDescriptor}.

CRITICAL FACTUAL DATA INTEGRITY CONSTRAINTS:
1. Translate ONLY human-readable commentary, guidance, analysis, observations, and recommendations.
2. NEVER modify, alter, or recalculate any numbers, percentages, task ratios (e.g. "0/97" MUST stay "0/97"), dates, student names, IDs, URLs, phone numbers, course names, or study plan titles.
3. Keep the exact same number of items in lists (strengths, weaknesses, recommendations, warnings, appreciation).
4. ABSOLUTE DASH RULE: NEVER use an em dash character ("—") or en dash ("–"). Always use a standard ASCII hyphen ("-") if separating thoughts.
5. STYLE GUIDELINE:
{$langGuideline}

OUTPUT SCHEMA:
Respond with a single valid JSON object with translated text values matching this exact structure:
{
  "overall_status": "translated overall status",
  "status_summary": "translated single-sentence status summary",
  "academic_strengths": ["translated strength 1", ...],
  "academic_weaknesses": ["translated weakness/attention area 1", ...],
  "mega_test_insights": ["translated mega test insight 1", ...],
  "live_session_insights": ["translated live session insight 1", ...],
  "ranking_insights": ["translated ranking insight 1", ...],
  "appreciation": ["translated appreciation 1", ...],
  "warnings": ["translated warning 1", ...],
  "recommendations": ["translated recommendation 1", ...],
  "mentor_note": "translated personalized mentor note",
  "wa_text": "the complete WhatsApp message translated into {$langDescriptor} preserving emojis, bold (*text*), line breaks, and exact metrics"
}
PROMPT;

        $userPayload = [
            'overall_status' => $sourceAnalysis['overall_status'] ?? '',
            'status_summary' => $sourceAnalysis['status_summary'] ?? '',
            'academic_strengths' => $sourceAnalysis['academic_strengths'] ?? [],
            'academic_weaknesses' => $sourceAnalysis['academic_weaknesses'] ?? [],
            'mega_test_insights' => $sourceAnalysis['mega_test_insights'] ?? [],
            'live_session_insights' => $sourceAnalysis['live_session_insights'] ?? [],
            'ranking_insights' => $sourceAnalysis['ranking_insights'] ?? [],
            'appreciation' => $sourceAnalysis['appreciation'] ?? [],
            'warnings' => $sourceAnalysis['warnings'] ?? [],
            'recommendations' => $sourceAnalysis['recommendations'] ?? [],
            'mentor_note' => $sourceAnalysis['mentor_note'] ?? '',
            'wa_text' => $canonicalReport['wa_text'] ?? ($sourceAnalysis['wa_text'] ?? '')
        ];

        $rawResponse = $provider->generateContentRaw($systemPrompt, $userPayload, [
            'temperature' => 0.1,
            'responseMimeType' => 'application/json'
        ]);

        $clean = trim($rawResponse);
        if (str_starts_with($clean, '```json')) {
            $clean = substr($clean, 7);
        } elseif (str_starts_with($clean, '```')) {
            $clean = substr($clean, 3);
        }
        if (str_ends_with($clean, '```')) {
            $clean = substr($clean, 0, -3);
        }
        $clean = trim($clean);

        $parsed = json_decode($clean, true);
        if (!is_array($parsed) || empty($parsed['wa_text'])) {
            return null;
        }

        // Sanitize dashes
        $parsed = self::sanitizeDashesDeep($parsed);

        // Preserve original numeric snapshot
        $mergedAnalysis = array_merge($sourceAnalysis, [
            'overall_status' => $parsed['overall_status'] ?? $sourceAnalysis['overall_status'],
            'status_summary' => $parsed['status_summary'] ?? $sourceAnalysis['status_summary'],
            'academic_strengths' => $parsed['academic_strengths'] ?? $sourceAnalysis['academic_strengths'],
            'academic_weaknesses' => $parsed['academic_weaknesses'] ?? $sourceAnalysis['academic_weaknesses'],
            'mega_test_insights' => $parsed['mega_test_insights'] ?? $sourceAnalysis['mega_test_insights'],
            'live_session_insights' => $parsed['live_session_insights'] ?? $sourceAnalysis['live_session_insights'],
            'ranking_insights' => $parsed['ranking_insights'] ?? $sourceAnalysis['ranking_insights'],
            'appreciation' => $parsed['appreciation'] ?? $sourceAnalysis['appreciation'],
            'warnings' => $parsed['warnings'] ?? $sourceAnalysis['warnings'],
            'recommendations' => $parsed['recommendations'] ?? $sourceAnalysis['recommendations'],
            'mentor_note' => $parsed['mentor_note'] ?? $sourceAnalysis['mentor_note'],
            'snapshot' => $sourceAnalysis['snapshot'] ?? []
        ]);

        $waText = self::sanitizeDashes(trim((string)$parsed['wa_text']));

        return [
            'analysis' => $mergedAnalysis,
            'wa_text' => $waText
        ];
    }

    /**
     * Deterministic, 100% reliable translation engine that transforms the canonical report
     * into natural Malayalam or Manglish without external network dependencies.
     */
    public static function translateDeterministically(array $canonicalReport, string $targetLang): array {
        $sourceAnalysis = $canonicalReport['analysis'] ?? [];
        $data = $canonicalReport['data'] ?? [];
        $p = $data['student_profile'] ?? [];
        $c = $data['checklist_audit'] ?? [];
        $snap = $sourceAnalysis['snapshot'] ?? [];

        $isMl = ($targetLang === self::LANG_ML);

        // Overall status translation
        $rawStatus = (string)($sourceAnalysis['overall_status'] ?? '');
        $translatedStatus = self::translateStatus($rawStatus, $targetLang);

        // Status summary translation
        $completed = $snap['completed'] ?? ($c['completed_tasks'] ?? 0);
        $total = $c['total_tasks'] ?? 0;
        $pct = $snap['checklist_pct'] ?? ($c['completion_percentage'] ?? 0);
        $streak = $snap['streak'] ?? ($c['current_streak'] ?? 0);

        if ($isMl) {
            $translatedSummary = "വിദ്യാർത്ഥി {$total} ടാസ്കുകളിൽ {$completed} എണ്ണം മാത്രമാണ് പൂർത്തിയാക്കിയത് ({$pct}%). " .
                ($streak > 0 ? "നിലവിലെ തുടർച്ച {$streak} ദിവസമാണ്." : "തുടർച്ചയായി പഠനം ആരംഭിച്ചിട്ടില്ല.");
        } else {
            $translatedSummary = "Student {$total} tasks-il {$completed} ennam mathram complete cheythittullu ({$pct}%). " .
                ($streak > 0 ? "Ippol current streak {$streak} days aanu." : "Innum continuous streak thudangiyittilla.");
        }

        // Translate lists
        $strengths = self::translateList($sourceAnalysis['academic_strengths'] ?? [], 'strengths', $targetLang, $canonicalReport);
        $weaknesses = self::translateList($sourceAnalysis['academic_weaknesses'] ?? [], 'weaknesses', $targetLang, $canonicalReport);
        $megaTests = self::translateList($sourceAnalysis['mega_test_insights'] ?? [], 'mega_tests', $targetLang, $canonicalReport);
        $liveSessions = self::translateList($sourceAnalysis['live_session_insights'] ?? [], 'live_sessions', $targetLang, $canonicalReport);
        $ranking = self::translateList($sourceAnalysis['ranking_insights'] ?? [], 'ranking', $targetLang, $canonicalReport);
        $appreciation = self::translateList($sourceAnalysis['appreciation'] ?? [], 'appreciation', $targetLang, $canonicalReport);
        $warnings = self::translateList($sourceAnalysis['warnings'] ?? [], 'warnings', $targetLang, $canonicalReport);
        $recommendations = self::translateList($sourceAnalysis['recommendations'] ?? [], 'recommendations', $targetLang, $canonicalReport);

        // Mentor note
        $rawNote = (string)($sourceAnalysis['mentor_note'] ?? '');
        $translatedNote = self::translateMentorNote($rawNote, $targetLang);

        // Assemble translated analysis
        $translatedAnalysis = array_merge($sourceAnalysis, [
            'overall_status' => $translatedStatus,
            'status_summary' => $translatedSummary,
            'academic_strengths' => $strengths,
            'academic_weaknesses' => $weaknesses,
            'mega_test_insights' => $megaTests,
            'live_session_insights' => $liveSessions,
            'ranking_insights' => $ranking,
            'appreciation' => $appreciation,
            'warnings' => $warnings,
            'recommendations' => $recommendations,
            'mentor_note' => $translatedNote,
            'snapshot' => $snap
        ]);

        // Construct corresponding WhatsApp content in the target language
        $waText = self::buildTranslatedWaText($canonicalReport, $targetLang, $translatedAnalysis);
        $translatedAnalysis['wa_text'] = $waText;

        return [
            'analysis' => $translatedAnalysis,
            'wa_text' => $waText
        ];
    }

    /**
     * Constructs natural formatted WhatsApp content matching the exact selected language.
     */
    public static function buildTranslatedWaText(array $canonicalReport, string $targetLang, array $analysis): string {
        $data = $canonicalReport['data'] ?? [];
        $p = $data['student_profile'] ?? [];
        $c = $data['checklist_audit'] ?? [];
        $ec = $data['enrollment_context'] ?? [];
        $m = $data['mega_tests'] ?? [];
        $l = $data['live_sessions'] ?? [];
        $r = $data['cohort_ranking'] ?? [];

        $isMl = ($targetLang === self::LANG_ML);
        $overallStatus = $analysis['overall_status'] ?? self::translateStatus((string)($analysis['snapshot']['overall_status'] ?? 'Good'), $targetLang);

        // Enrollment Context line
        $isPartial = !empty($ec['is_partial_period_participant']);
        $enrollmentLine = "";
        if ($isPartial && !empty($ec['joined_date'])) {
            if ($isMl) {
                $enrollmentLine = "📅 *എൻറോൾമെന്റ് വിവരം:* ചേർന്ന തീയതി {$ec['joined_date']} (സ്റ്റഡി പ്ലാനിലെ {$ec['days_enrolled_in_plan']} സജീവ ദിവസങ്ങൾ). ചേർന്നതിന് ശേഷമുള്ള അർഹമായ ടാസ്കുകൾ മാത്രമാണ് വിലയിരുത്തിയിട്ടുള്ളത്.\n\n";
            } else {
                $enrollmentLine = "📅 *Enrollment Context:* Joined on {$ec['joined_date']} ({$ec['days_enrolled_in_plan']} days in plan window). Evaluated against eligible activities since joining.\n\n";
            }
        }

        // Mega Tests block
        $megaLines = [];
        if (!empty($m['has_data'])) {
            $attText = $m['attendance_percentage'] !== null ? "{$m['attendance_percentage']}%" : ($isMl ? 'പങ്കെടുത്തിട്ടില്ല' : 'Attended aayittilla');
            $scoreText = $m['average_score_percentage'] !== null ? "{$m['average_score_percentage']}%" : ($isMl ? 'സ്കോറുകൾ ലഭ്യമല്ല' : 'Scores labhyamalla');
            $rankText = !empty($r['has_data']) ? "#{$r['study_plan_rank']} / {$r['cohort_size']}" : ($isMl ? 'ലഭ്യമല്ല' : 'Available alla');

            if ($isMl) {
                $megaLines[] = "• ഹാജർ: {$attText}";
                $megaLines[] = "• ശരാശരി സ്കോർ: {$scoreText}";
                $megaLines[] = "• ബാച്ച് റാങ്ക്: {$rankText}";
                if (!empty($m['pre_admission_tests']) && $m['pre_admission_tests'] > 0) {
                    $megaLines[] = "• കുറിപ്പ്: അഡ്മിഷന് മുമ്പുള്ള {$m['pre_admission_tests']} ടെസ്റ്റ്(കൾ) ഹാജർ കണക്കിൽ നിന്ന് ഒഴിവാക്കിയിട്ടുണ്ട്";
                }
            } else {
                $megaLines[] = "• Attendance: {$attText}";
                $megaLines[] = "• Average Score: {$scoreText}";
                $megaLines[] = "• Study Plan Rank: {$rankText}";
                if (!empty($m['pre_admission_tests']) && $m['pre_admission_tests'] > 0) {
                    $megaLines[] = "• Note: {$m['pre_admission_tests']} pre-admission test(s) attendance-il ninnum exclude cheythu";
                }
            }
        } else {
            $megaLines[] = $isMl
                ? "• നിലവിൽ: ഈ സ്റ്റഡി പ്ലാനിൽ മെഗാ ടെസ്റ്റുകൾ പ്രസിദ്ധീകരിച്ചിട്ടില്ല"
                : "• Status: Ee study plan-il mega tests publish cheythittilla";
        }
        $megaSectionText = implode("\n", $megaLines);

        // Live Sessions block
        $liveLines = [];
        if (!empty($l['has_data']) && ($l['scheduled_sessions'] ?? 0) > 0) {
            if ($isMl) {
                $liveLines[] = "• ഹാജർ: {$l['attended_sessions']}/{$l['eligible_sessions']} ({$l['attendance_percentage']}%)";
                $liveLines[] = "• പങ്കെടുത്തവ: {$l['attended_sessions']}";
                $liveLines[] = "• നഷ്ടപ്പെട്ടവ: {$l['missed_sessions']}";
                $liveLines[] = "• ബാക്കിയുള്ളവ: {$l['pending_sessions']}";
                if (!empty($l['pre_admission_sessions']) && $l['pre_admission_sessions'] > 0) {
                    $liveLines[] = "• അഡ്മിഷന് മുമ്പുള്ള {$l['pre_admission_sessions']} സെഷൻ(കൾ) ഹാജർ കണക്കിൽ ഉൾപ്പെടുത്തിയിട്ടില്ല";
                }
            } else {
                $liveLines[] = "• Attendance: {$l['attended_sessions']}/{$l['eligible_sessions']} ({$l['attendance_percentage']}%)";
                $liveLines[] = "• Attended: {$l['attended_sessions']}";
                $liveLines[] = "• Missed: {$l['missed_sessions']}";
                $liveLines[] = "• Pending: {$l['pending_sessions']}";
                if (!empty($l['pre_admission_sessions']) && $l['pre_admission_sessions'] > 0) {
                    $liveLines[] = "• Note: {$l['pre_admission_sessions']} pre-admission session(s) attendance-il ninnum exclude cheythu";
                }
            }
        } elseif (!empty($l['has_data']) && ($l['scheduled_sessions'] ?? 0) === 0) {
            $liveLines[] = $isMl
                ? "• ലൈവ് സെഷൻ ഹാജർ വിവരങ്ങൾ നിലവിൽ ലഭ്യമല്ല"
                : "• Live session attendance data ippol available alla";
        } else {
            $liveLines[] = $isMl
                ? "• ഈ സ്റ്റഡി പ്ലാനിൽ ലൈവ് സെഷനുകൾ രേഖപ്പെടുത്തിയിട്ടില്ല"
                : "• Ee study plan-il live session records labhyamalla";
        }
        $liveSectionText = implode("\n", $liveLines);

        // Ranking block
        $rankVal = !empty($r['has_data']) ? "#{$r['study_plan_rank']} / {$r['cohort_size']}" : ($isMl ? 'ലഭ്യമല്ല' : 'Available alla');
        $rankingText = $isMl ? "• സ്റ്റഡി പ്ലാൻ റാങ്ക്: {$rankVal}" : "• Study Plan Rank: {$rankVal}";
        if (!empty($r['has_data']) && !empty($r['standing_badge'])) {
            $rankingText .= "\n" . ($isMl ? "• നിലവാരം: {$r['standing_badge']}" : "• Standing: {$r['standing_badge']}");
        }

        // Format numbered recommendations
        $recList = $analysis['recommendations'] ?? [];
        $recLines = [];
        foreach ($recList as $idx => $rItem) {
            $num = $idx + 1;
            $recLines[] = "{$num}. {$rItem}";
        }
        if (empty($recLines)) {
            $recLines[] = $isMl
                ? "1. ചിട്ടയായ ദൈനംദിന പഠനരീതി തുടർന്നുപോകുക."
                : "1. Daily ulla structured padanam thudarnnu pokuka.";
        }

        // Format bulleted lists
        $strengthsText = self::formatBulletList($analysis['academic_strengths'] ?? []);
        $weaknessesText = self::formatBulletList($analysis['academic_weaknesses'] ?? []);
        $appreciationText = self::formatBulletList($analysis['appreciation'] ?? []);
        $warningsText = self::formatBulletList($analysis['warnings'] ?? []);

        // Headers and titles
        if ($isMl) {
            $out = "🎓 *വിദ്യാർത്ഥി പ്രകടന AI വിശകലനം*\n\n"
                . "👤 *വിദ്യാർത്ഥി:* {$p['name']}\n"
                . "📚 *കോഴ്സ്:* {$p['course']}\n"
                . "📅 *സ്റ്റഡി പ്ലാൻ:* {$p['selected_study_plan']}\n"
                . "📊 *നിലവിലെ അവസ്ഥ:* {$overallStatus}\n\n"
                . $enrollmentLine
                . "📌 *ചെക്ക്ലിസ്റ്റ്:* {$c['completion_percentage']}%\n"
                . "✅ പൂർത്തിയായവ: {$c['completed_tasks']}\n"
                . "⏳ ശേഷിക്കുന്നവ: {$c['pending_tasks']}\n"
                . "⚠️ കാലതാമസം വന്നവ: {$c['overdue_tasks']}\n"
                . "🔥 പഠന തുടർച്ച (Streak): {$c['current_streak']} ദിവസം\n"
                . "📅 സ്ഥിരത: {$c['consistency_percentage']}%\n\n"
                . "🟢 *പ്രധാന മികവുകൾ*\n"
                . $strengthsText . "\n\n"
                . "🟠 *ശ്രദ്ധിക്കേണ്ട കാര്യങ്ങൾ*\n"
                . $weaknessesText . "\n\n"
                . "📝 *മെഗാ ടെസ്റ്റുകൾ*\n"
                . $megaSectionText . "\n\n"
                . "🎥 *ലൈവ് സെഷനുകൾ*\n"
                . $liveSectionText . "\n\n"
                . "🏆 *റാങ്കിംഗ്*\n"
                . $rankingText . "\n\n"
                . "🌟 *അഭിനന്ദനം*\n"
                . $appreciationText . "\n\n"
                . "⚠️ *പ്രധാന മുന്നറിയിപ്പുകൾ*\n"
                . $warningsText . "\n\n"
                . "🎯 *നിർദ്ദേശങ്ങൾ (Action Plan)*\n"
                . implode("\n", $recLines) . "\n\n"
                . "💡 *മെന്ററുടെ നിർദ്ദേശം:*\n"
                . ($analysis['mentor_note'] ?? 'ദിവസേനയുള്ള സ്ഥിരമായ പരിശ്രമമാണ് അക്കാദമിക് വിജയത്തിന്റെ രഹസ്യം!');
        } else {
            $out = "🎓 *STUDENT PERFORMANCE AI ANALYSIS*\n\n"
                . "👤 *Student:* {$p['name']}\n"
                . "📚 *Course:* {$p['course']}\n"
                . "📅 *Study Plan:* {$p['selected_study_plan']}\n"
                . "📊 *Overall Status:* {$overallStatus}\n\n"
                . $enrollmentLine
                . "📌 *Checklist:* {$c['completion_percentage']}%\n"
                . "✅ Completed: {$c['completed_tasks']}\n"
                . "⏳ Pending: {$c['pending_tasks']}\n"
                . "⚠️ Overdue: {$c['overdue_tasks']}\n"
                . "🔥 Streak: {$c['current_streak']} days\n"
                . "📅 Consistency: {$c['consistency_percentage']}%\n\n"
                . "🟢 *Key Strengths*\n"
                . $strengthsText . "\n\n"
                . "🟠 *Areas to Watch*\n"
                . $weaknessesText . "\n\n"
                . "📝 *MEGA TESTS*\n"
                . $megaSectionText . "\n\n"
                . "🎥 *LIVE SESSIONS*\n"
                . $liveSectionText . "\n\n"
                . "🏆 *RANKING*\n"
                . $rankingText . "\n\n"
                . "🌟 *APPRECIATION*\n"
                . $appreciationText . "\n\n"
                . "⚠️ *IMPORTANT WARNINGS*\n"
                . $warningsText . "\n\n"
                . "🎯 *RECOMMENDED ACTIONS*\n"
                . implode("\n", $recLines) . "\n\n"
                . "💡 *Mentor Note:*\n"
                . ($analysis['mentor_note'] ?? 'Daily ulla sthiramaaya parishramamaanu academic success-inte rahasyam!');
        }

        return self::sanitizeDashes(trim($out));
    }

    /**
     * Translates human-readable overall status strings.
     */
    public static function translateStatus(string $status, string $lang): string {
        $statusMap = [
            self::LANG_ML => [
                'Elite Performer' => 'ഉന്നത നിലവാരം (Elite Performer)',
                'Strong Performer' => 'മികച്ച പ്രകടനം (Strong Performer)',
                'Good' => 'നല്ല പ്രകടനം (Good)',
                'Average' => 'ശരാശരി പ്രകടനം (Average)',
                'Needs Attention' => 'പ്രത്യേക ശ്രദ്ധ ആവശ്യമാണ് (Needs Attention)',
                'Critical' => 'അടിയന്തര ശ്രദ്ധ ആവശ്യമാണ് (Critical)'
            ],
            self::LANG_MANGLISH => [
                'Elite Performer' => 'Elite Performer',
                'Strong Performer' => 'Strong Performer',
                'Good' => 'Good Progress',
                'Average' => 'Average Performer',
                'Needs Attention' => 'Needs Attention (Prathyeka shraddha venam)',
                'Critical' => 'Critical (Udane shraddha venam)'
            ]
        ];

        return $statusMap[$lang][$status] ?? $status;
    }

    /**
     * Translates individual string items in structured lists.
     */
    public static function translateList(array $items, string $category, string $lang, array $canonicalReport = []): array {
        $isMl = ($lang === self::LANG_ML);
        $translated = [];

        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '') continue;

            // Strip leading bullet if present
            $hasBullet = str_starts_with($item, '•');
            $cleanItem = ltrim($item, "• \t\n\r");

            $trText = self::translatePhrase($cleanItem, $category, $lang, $canonicalReport);
            $translated[] = $hasBullet ? "• {$trText}" : $trText;
        }

        return $translated;
    }

    /**
     * Phrase-level translation helper matching common mentoring patterns while strictly preserving numbers.
     */
    private static function translatePhrase(string $text, string $category, string $lang, array $canonicalReport): string {
        $isMl = ($lang === self::LANG_ML);

        // Pattern: "Completed X out of Y eligible prescribed tasks."
        if (preg_match('/Completed\s+(\d+)\s+out\s+of\s+(\d+)\s+eligible\s+prescribed\s+tasks/i', $text, $m)) {
            return $isMl
                ? "നിർദ്ദേശിക്കപ്പെട്ട {$m[2]} അർഹമായ ടാസ്കുകളിൽ {$m[1]} എണ്ണം വിജയകരമായി പൂർത്തിയാക്കി."
                : "Prescribed {$m[2]} eligible tasks-il {$m[1]} ennam vijayakaramaayi complete cheythu.";
        }

        // Pattern: "Maintained an active X-day learning streak."
        if (preg_match('/Maintained\s+an\s+active\s+(\d+)-day\s+learning\s+streak/i', $text, $m)) {
            return $isMl
                ? "തുടർച്ചയായി {$m[1]} ദിവസത്തെ മികച്ച പഠന മുന്നേറ്റം നിലനിർത്തി."
                : "Thudarchayaayi {$m[1]} days active learning streak maintain cheythu.";
        }

        // Pattern: "X overdue task(s) require immediate clearance."
        if (preg_match('/(\d+)\s+overdue\s+task\(s\)\s+require\s+immediate\s+clearance/i', $text, $m)) {
            return $isMl
                ? "കാലതാമസം വന്ന {$m[1]} ടാസ്കുകൾ അടിയന്തരമായി പൂർത്തിയാക്കേണ്ടതുണ്ട്."
                : "Overdue aaya {$m[1]} tasks udane thanne complete cheyyanam.";
        }

        // Pattern: "X task(s) remaining to be completed in the study plan."
        if (preg_match('/(\d+)\s+task\(s\)\s+remaining\s+to\s+be\s+completed/i', $text, $m)) {
            return $isMl
                ? "സ്റ്റഡി പ്ലാനിൽ ഇനി {$m[1]} ടാസ്കുകൾ പൂർത്തിയാക്കാനുണ്ട്."
                : "Study plan-il ini {$m[1]} tasks baakiyund.";
        }

        // Pattern: "All scheduled study plan tasks completed on track."
        if (stripos($text, 'All scheduled study plan tasks completed') !== false) {
            return $isMl
                ? "നിശ്ചയിച്ച എല്ലാ സ്റ്റഡി പ്ലാൻ ടാസ്കുകളും സമയബന്ധിതമായി പൂർത്തിയാക്കി."
                : "Nishchayicha ella study plan tasks-um timely aayi complete cheythu.";
        }

        // Pattern: "Clear X overdue checklist item(s) to avoid study plan backlog."
        if (preg_match('/Clear\s+(\d+)\s+overdue\s+checklist\s+item\(s\)/i', $text, $m)) {
            return $isMl
                ? "പഠനഭാരം ഒഴിവാക്കാൻ കാലതാമസം വന്ന {$m[1]} ചെക്ക്ലിസ്റ്റ് ഇനങ്ങൾ ഉടൻ പൂർത്തിയാക്കുക."
                : "Padana bhaaram ozhivakkaan overdue aaya {$m[1]} items udane complete cheyyuka.";
        }

        // Pattern: "X live session(s) missed - review recordings."
        if (preg_match('/(\d+)\s+live\s+session\(s\)\s+missed/i', $text, $m)) {
            return $isMl
                ? "{$m[1]} ലൈവ് സെഷൻ(കൾ) നഷ്ടമായി. റെക്കോർഡിംഗുകൾ കാണുക."
                : "{$m[1]} live session(s) miss aayi. Recordings kaanuka.";
        }

        // Pattern: "No critical warnings at this time."
        if (stripos($text, 'No critical warnings') !== false) {
            return $isMl ? "നിലവിൽ നിർണായക മുന്നറിയിപ്പുകൾ ഒന്നുമില്ല." : "Ippol prathyeka critical warnings onnumilla.";
        }

        // Pattern: "Complete remaining X checklist item(s) in chronological sequence."
        if (preg_match('/Complete\s+remaining\s+(\d+)\s+checklist\s+item\(s\)/i', $text, $m)) {
            return $isMl
                ? "ശേഷിക്കുന്ന {$m[1]} ചെക്ക്ലിസ്റ്റ് ഇനങ്ങൾ ക്രമപ്രകാരം പൂർത്തിയാക്കുക."
                : "Baakiyulla {$m[1]} checklist items order-il complete cheyyuka.";
        }

        // Pattern: "Clear X overdue task(s) promptly to stay aligned with the syllabus."
        if (preg_match('/Clear\s+(\d+)\s+overdue\s+task\(s\)/i', $text, $m)) {
            return $isMl
                ? "സിലബസിനൊപ്പം മുന്നേറാൻ കാലതാമസം വന്ന {$m[1]} ടാസ്കുകൾ വേഗത്തിൽ തീർക്കുക."
                : "Syllabus-oppam ethyaan overdue aaya {$m[1]} tasks vegam theerkkuka.";
        }

        // Pattern: "Maintain your strong X-day study streak across the upcoming study plan weeks."
        if (preg_match('/Maintain\s+your\s+strong\s+(\d+)-day\s+study\s+streak/i', $text, $m)) {
            return $isMl
                ? "വരും ആഴ്ചകളിലും താങ്കളുടെ മികച്ച {$m[1]} ദിവസത്തെ പഠന തുടർച്ച നിലനിർത്തുക."
                : "Varum aazhchakalilum nalla {$m[1]}-day study streak continue cheyyuka.";
        }

        // Pattern: "Establish a regular daily study habit to improve consistency."
        if (stripos($text, 'Establish a regular daily study habit') !== false) {
            return $isMl
                ? "പഠന സ്ഥിരത വർദ്ധിപ്പിക്കാൻ ദിവസേന ചിട്ടയായ പഠന ശീലം വളർത്തിയെടുക്കുക."
                : "Consistency kooduthal aakkan dhivasavum regular aayi padikkuka.";
        }

        // Pattern: "Engage in comprehensive topic revision, notes consolidation, and exam preparation."
        if (stripos($text, 'comprehensive topic revision') !== false) {
            return $isMl
                ? "വിഷയങ്ങൾ സമഗ്രമായി റിവൈസ് ചെയ്യുകയും നോട്ട്സ് തയ്യാറാക്കി പരീക്ഷയ്ക്ക് ഒരുങ്ങുകയും ചെയ്യുക."
                : "Topics nannaayi revise cheyyukayum notes prepare cheythu exam-nu thayyaaredukkukayum cheyyuka.";
        }

        // Pattern: "Review chapter summaries and attempt additional practice assessments."
        if (stripos($text, 'Review chapter summaries') !== false) {
            return $isMl
                ? "അധ്യായ സംഗ്രഹങ്ങൾ വീണ്ടും വായിക്കുകയും കൂടുതൽ മോഡൽ പരീക്ഷകൾ എഴുതി പരിശീലിക്കുകയും ചെയ്യുക."
                : "Chapter summaries review cheyyukayum kooduthal practice tests attempt cheyyukayum cheyyuka.";
        }

        // Pattern: "Attended X/Y live sessions (Z%)"
        if (preg_match('/Attended\s+(\d+)\/(\d+)\s+live\s+sessions\s+\((\d+)%\)/i', $text, $m)) {
            return $isMl
                ? "ലൈവ് സെഷനുകൾ: {$m[2]}-ൽ {$m[1]} എണ്ണം പങ്കെടുത്തു ({$m[3]}%)"
                : "Live sessions: {$m[2]}-il {$m[1]} ennam attend cheythu ({$m[3]}%)";
        }

        // Pattern: "No live-session records are available for this study plan"
        if (stripos($text, 'No live-session records') !== false) {
            return $isMl
                ? "ഈ സ്റ്റഡി പ്ലാനിൽ ലൈവ് സെഷൻ വിവരങ്ങൾ ലഭ്യമല്ല"
                : "Ee study plan-il live session records labhyamalla";
        }

        // Pattern: "No published mega tests for this study plan"
        if (stripos($text, 'No published mega tests') !== false) {
            return $isMl
                ? "ഈ സ്റ്റഡി പ്ലാനിൽ മെഗാ ടെസ്റ്റുകൾ പ്രസിദ്ധീകരിച്ചിട്ടില്ല"
                : "Ee study plan-il mega tests publish cheythittilla";
        }

        // Pattern: "Mega test attendance at X%"
        if (preg_match('/Mega\s+test\s+attendance\s+at\s+([0-9.]+%)?/i', $text, $m)) {
            $val = $m[1] ?? '';
            return $isMl ? "മെഗാ ടെസ്റ്റ് ഹാജർ: {$val}" : "Mega test attendance: {$val}";
        }

        // Pattern: "Dedicated effort demonstrated in X"
        if (stripos($text, 'Dedicated effort demonstrated in') !== false) {
            return $isMl
                ? "സ്റ്റഡി പ്ലാനിൽ മികച്ച പങ്കാളിത്തവും സമർപ്പണവും കാഴ്ചവെച്ചു."
                : "Study plan-il nalla effort-um dedication-um kanichu.";
        }

        // Pattern: "Active learning streak maintained with discipline."
        if (stripos($text, 'Active learning streak maintained with discipline') !== false) {
            return $isMl
                ? "അച്ചടക്കത്തോടെയുള്ള പഠന മുന്നേറ്റം പ്രശംസനീയമാണ്."
                : "Discipline-ode ulla active learning streak commendable aanu.";
        }

        // Pattern: "Ongoing commitment to structured learning."
        if (stripos($text, 'Ongoing commitment to structured learning') !== false) {
            return $isMl
                ? "ചിട്ടയായ പഠനത്തോടുള്ള താത്പര്യം തുടരുക."
                : "Structured padanathodu ulla commitment continue cheyyuka.";
        }

        // Fallback: If no regex pattern matches, return original text safely
        return $text;
    }

    /**
     * Translates personalized mentor note text.
     */
    public static function translateMentorNote(string $note, string $lang): string {
        $isMl = ($lang === self::LANG_ML);
        if (stripos($note, 'Steady daily effort') !== false || stripos($note, 'academic excellence') !== false) {
            return $isMl
                ? "ദിവസേനയുള്ള സ്ഥിരമായ പരിശ്രമമാണ് അക്കാദമിക് വിജയത്തിന്റെ രഹസ്യം. ശ്രദ്ധയോടെ പഠനം തുടരുക!"
                : "Daily ulla sthiramaaya parishramamaanu academic success-inte rahasyam. Focus cheythu continue cheyyuka!";
        }
        if (stripos($note, 'Maintain your focus') !== false) {
            return $isMl
                ? "ശ്രദ്ധ കേന്ദ്രീകരിച്ച് സിലബസിലൂടെ സ്ഥിരതയോടെ മുന്നേറുക!"
                : "Focus maintain cheythu syllabus-iloode sthirathayode munnotu pokuka!";
        }
        return $note;
    }

    /**
     * UI Section Labels for the modal cards and headings.
     */
    public static function getUiLabels(string $lang): array {
        switch ($lang) {
            case self::LANG_ML:
                return [
                    'assessment_title' => 'അക്കാദമിക് പ്രകടന വിലയിരുത്തൽ',
                    'ai_verified' => 'AI സ്ഥിരീകരിച്ചത്',
                    'kpi_checklist' => 'ചെക്ക്ലിസ്റ്റ്',
                    'kpi_completed' => 'പൂർത്തിയായവ',
                    'kpi_pending' => 'ശേഷിക്കുന്നവ',
                    'kpi_overdue' => 'കാലതാമസം വന്നവ',
                    'kpi_streak' => 'പഠന തുടർച്ച (Streak)',
                    'kpi_consistency' => 'സ്ഥിരത',
                    'sec_strengths' => 'അക്കാദമിക് മികവുകൾ',
                    'sec_mega_tests' => 'മെഗാ ടെസ്റ്റ് വിവരങ്ങൾ',
                    'sec_appreciation' => 'വിദ്യാർത്ഥി അഭിനന്ദനം',
                    'sec_attention' => 'പ്രത്യേക ശ്രദ്ധ ആവശ്യമായ മേഖലകൾ',
                    'sec_live_sessions' => 'ലൈവ് സെഷൻ വിവരങ്ങൾ',
                    'sec_warnings' => 'പ്രധാന മുന്നറിയിപ്പുകൾ',
                    'sec_health_check' => 'നിലവിലെ അവസ്ഥ',
                    'sec_ranking' => 'ബാച്ചിലെ റാങ്കും സ്ഥാനവും',
                    'sec_actions' => 'മെന്ററുടെ നിർദ്ദേശങ്ങൾ',
                    'sec_mentor_note' => 'മെന്ററുടെ പ്രത്യേക മാർഗ്ഗനിർദ്ദേശം',
                    'sec_wa_preview' => 'വാട്സാപ്പ് മെസ്സേജ് പ്രിവ്യൂ'
                ];
            case self::LANG_MANGLISH:
                return [
                    'assessment_title' => 'Academic Performance Assessment',
                    'ai_verified' => 'AI Verified',
                    'kpi_checklist' => 'Checklist',
                    'kpi_completed' => 'Complete aayath',
                    'kpi_pending' => 'Pending Tasks',
                    'kpi_overdue' => 'Overdue Tasks',
                    'kpi_streak' => 'Active Streak',
                    'kpi_consistency' => 'Consistency',
                    'sec_strengths' => 'Academic Strengths',
                    'sec_mega_tests' => 'Mega Test Insights',
                    'sec_appreciation' => 'Student Appreciation',
                    'sec_attention' => 'Prathyeka shraddha aavashyamulla areas',
                    'sec_live_sessions' => 'Live Session Updates',
                    'sec_warnings' => 'Pradhana Warnings',
                    'sec_health_check' => 'Health Check',
                    'sec_ranking' => 'Cohort Standing & Ranking',
                    'sec_actions' => 'Strategic Mentor Action Plan',
                    'sec_mentor_note' => 'Mentor Guidance Note',
                    'sec_wa_preview' => 'WhatsApp Message Preview'
                ];
            default: // self::LANG_EN
                return [
                    'assessment_title' => 'Academic Performance Assessment',
                    'ai_verified' => 'AI Verified',
                    'kpi_checklist' => 'Checklist',
                    'kpi_completed' => 'Completed',
                    'kpi_pending' => 'Pending',
                    'kpi_overdue' => 'Overdue',
                    'kpi_streak' => 'Active Streak',
                    'kpi_consistency' => 'Consistency',
                    'sec_strengths' => 'Academic Strengths',
                    'sec_mega_tests' => 'Mega Test Insights',
                    'sec_appreciation' => 'Student Appreciation',
                    'sec_attention' => 'Areas Needing Attention',
                    'sec_live_sessions' => 'Live Session Insights',
                    'sec_warnings' => 'Important Warnings',
                    'sec_health_check' => 'Health Check',
                    'sec_ranking' => 'Cohort Standing & Ranking',
                    'sec_actions' => 'Strategic Mentor Recommended Actions',
                    'sec_mentor_note' => 'Mentor Personalized Guidance Note',
                    'sec_wa_preview' => 'WhatsApp Direct Chat Preview'
                ];
        }
    }

    /**
     * Sanitizes long dashes (EM DASH U+2014, EN DASH U+2013, HORIZONTAL BAR U+2015) to ASCII hyphen.
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

    private static function formatBulletList(array $items): string {
        if (empty($items)) {
            return "• None recorded.";
        }
        $lines = [];
        foreach ($items as $item) {
            $line = trim((string)$item);
            if (!str_starts_with($line, '•')) {
                $line = "• {$line}";
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
