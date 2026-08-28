<?php
/**
 * StudentStudyPlanAnalytics — Reusable canonical calculations for student progress,
 * consecutive day streaks, real assessment attendance, chapter/topic breakdowns,
 * multi-plan trajectory, and study-plan based cohort ranking.
 */
class StudentStudyPlanAnalytics {

    /**
     * Calculate inclusive calendar days between start_date and end_date.
     * e.g., 2026-08-09 to 2026-08-31 => 23 days.
     */
    public static function calculatePlanCalendarDays($start_date, $end_date) {
        if (empty($start_date) || empty($end_date)) {
            return 0;
        }
        try {
            $d1 = new DateTimeImmutable($start_date);
            $d2 = new DateTimeImmutable($end_date);
            if ($d2 < $d1) {
                return 0;
            }
            return (int)$d1->diff($d2)->days + 1;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Deterministic, privacy-preserving email masking.
     * e.g., fathima@gmail.com => f*****a@gmail.com
     * If empty, null, or invalid => 'Not available'
     */
    public static function maskEmail($email) {
        $email = trim((string)$email);
        if ($email === '' || strcasecmp($email, 'null') === 0 || strpos($email, '@') === false) {
            return 'Not available';
        }
        $parts = explode('@', $email, 2);
        $name = $parts[0];
        $domain = $parts[1] ?? '';
        if ($domain === '') {
            return 'Not available';
        }
        $len = strlen($name);
        if ($len <= 1) {
            $masked_name = $name . '***';
        } elseif ($len === 2) {
            $masked_name = substr($name, 0, 1) . '***' . substr($name, -1);
        } else {
            $first = substr($name, 0, 1);
            $last = ($len > 3) ? substr($name, -1) : '';
            $star_count = max(4, $len - ($last !== '' ? 2 : 1));
            $masked_name = $first . str_repeat('*', $star_count) . $last;
        }
        return $masked_name . '@' . $domain;
    }

    /**
     * Get analytics scoped strictly to a single Study Plan with detailed chapter, topic,
     * assessment, consistency, timeline, and cohort ranking data.
     */
    public static function getPlanAnalytics($pdo, $student_id_or_email, $study_plan_id) {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $today = $now->format('Y-m-d');

        // 1. Resolve student details (Strongest student identity rule)
        $stmt_user = $pdo->prepare("
            SELECT user_id, email, name, pepp_course, pepp_academic_year, user_photo, student_status
            FROM users
            WHERE (user_id = ? OR LOWER(email) = LOWER(?)) AND status = 'approved'
            LIMIT 1
        ");
        $stmt_user->execute([$student_id_or_email, $student_id_or_email]);
        $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return self::emptyAnalytics();
        }
        $email = $user['email'];
        $user_id = $user['user_id'];
        $academic_year = $user['pepp_academic_year'];
        $course_name = $user['pepp_course'];
        $user_photo = !empty($user['user_photo']) ? trim((string)$user['user_photo']) : '';
        $student_status = !empty($user['student_status']) ? trim((string)$user['student_status']) : 'Active';
        $student_name = !empty($user['name']) ? trim((string)$user['name']) : '';
        $masked_email = self::maskEmail($email);

        // 2. Validate study plan assignment for security/data-scoping & academic year isolation
        $stmt_val = $pdo->prepare("
            SELECT COUNT(*)
            FROM study_plan_assignments sa
            JOIN study_plans sp ON sa.study_plan_id = sp.id
            WHERE sp.id = ? AND sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
              AND LOWER(sp.academic_year) = LOWER(?)
              AND (
                sa.assignment_type = 'all' OR
                (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'batch' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'student' AND sa.assigned_value = ?)
            )
        ");
        $stmt_val->execute([$study_plan_id, $academic_year, $course_name, $academic_year, $user_id]);
        if ((int)$stmt_val->fetchColumn() === 0) {
            return self::emptyAnalytics();
        }

        // Fetch plan info
        $stmt_plan_info = $pdo->prepare("SELECT id, title, plan_type, start_date, end_date, academic_year FROM study_plans WHERE id = ?");
        $stmt_plan_info->execute([$study_plan_id]);
        $plan_info = $stmt_plan_info->fetch(PDO::FETCH_ASSOC);
        $plan_type = $plan_info['plan_type'] ?? 'date_wise';
        $plan_title = $plan_info['title'] ?? ('Study Plan #' . $study_plan_id);
        $total_plan_calendar_days = self::calculatePlanCalendarDays($plan_info['start_date'] ?? null, $plan_info['end_date'] ?? null);

        // Fetch all active study plan activities
        $stmt_act = $pdo->prepare("
            SELECT act.*
            FROM study_plan_activities act
            WHERE act.study_plan_id = ? AND act.is_deleted = 0
            ORDER BY " . ($plan_type === 'date_wise' ? 'act.activity_date ASC, act.sort_order ASC, act.id ASC' : 'act.day_number ASC, act.sort_order ASC, act.id ASC')
        );
        $stmt_act->execute([$study_plan_id]);
        $activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

        $total_tasks = count($activities);
        if ($total_tasks === 0) {
            $res = self::emptyAnalytics();
            $res['total_plan_calendar_days'] = $total_plan_calendar_days;
            $res['eligible_plan_calendar_days'] = $total_plan_calendar_days;
            $res['study_plan_id'] = $study_plan_id;
            $res['study_plan_title'] = $plan_title;
            return $res;
        }

        // Fetch all completion logs for this student in this plan
        $stmt_all_logs = $pdo->prepare("
            SELECT an.id, an.activity_id, an.activity_uid, an.completion_status, an.created_at
            FROM study_plan_analytics an
            JOIN study_plan_activities act ON (
                (an.activity_uid = act.activity_uid AND act.activity_uid IS NOT NULL AND act.activity_uid != '')
                OR (an.activity_id = act.id AND (an.activity_uid IS NULL OR an.activity_uid = '' OR act.activity_uid IS NULL OR act.activity_uid = ''))
            )
            WHERE LOWER(an.student_email) = LOWER(?) AND an.study_plan_id = ? AND an.action_type = 'complete_activity' AND act.is_deleted = 0
            ORDER BY an.id ASC
        ");
        $stmt_all_logs->execute([$email, $study_plan_id]);
        $all_logs = $stmt_all_logs->fetchAll(PDO::FETCH_ASSOC);

        $effective_completions = [];
        foreach ($all_logs as $log) {
            $key = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
            $effective_completions[$key] = $log['completion_status'];
        }

        $completed_tasks = 0;
        $completed_map = [];
        foreach ($activities as $act) {
            $key = !empty($act['activity_uid']) ? $act['activity_uid'] : 'id_' . $act['id'];
            if (isset($effective_completions[$key]) && $effective_completions[$key] === 'completed') {
                $completed_tasks++;
                $completed_map[$act['id']] = true;
            }
        }

        $pending_tasks = max(0, $total_tasks - $completed_tasks);

        // Calculate overdue tasks (incomplete, past schedule date, date-wise plans only)
        $overdue_tasks = 0;
        foreach ($activities as $act) {
            if (!isset($completed_map[$act['id']])) {
                if ($plan_type === 'date_wise' && !empty($act['activity_date'])) {
                    if ($act['activity_date'] < $today) {
                        $overdue_tasks++;
                    }
                }
            }
        }

        $completion_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

        // Compile logs for streaks and active study days
        $latest_completed_dates = [];
        foreach ($all_logs as $log) {
            $key = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
            if ($log['completion_status'] === 'completed') {
                if (!empty($log['created_at'])) {
                    $latest_completed_dates[$key] = self::convertToKolkataDate($log['created_at']);
                }
            } else if ($log['completion_status'] === 'cleared') {
                unset($latest_completed_dates[$key]);
            }
        }
        $completed_dates = array_values(array_filter(array_unique(array_values($latest_completed_dates))));
        $streaks = self::calculateStreaksFromDates($completed_dates);

        $active_study_days = count($completed_dates);
        $consistency_percentage = $total_plan_calendar_days > 0 ? min(100, round(($active_study_days / $total_plan_calendar_days) * 100)) : 0;

        // Fetch real attendance and performance from assessment results linked to this plan
        $stmt_att = $pdo->prepare("
            SELECT ar.batch_id, ar.attendance_status, ar.score, ar.total_score, arb.chapter_snapshot, act.chapter as act_chapter
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            LEFT JOIN study_plan_activities act ON arb.activity_id = act.id
            WHERE ((ar.user_id IS NOT NULL AND ar.user_id = ?) OR (ar.student_email IS NOT NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.study_plan_id = ? AND arb.status = 'published'
              AND LOWER(TRIM(arb.academic_year)) = LOWER(TRIM(?))
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_att->execute([$user_id, $email, $study_plan_id, $academic_year, $today]);
        $att_records = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

        // De-duplicate results by batch_id
        $unique_att = [];
        $unique_perf = [];
        $chap_assess_map = [];

        foreach ($att_records as $rec) {
            $unique_att[$rec['batch_id']] = $rec['attendance_status'];

            $cname = trim((string)($rec['chapter_snapshot'] ?? ''));
            if ($cname === '') {
                $cname = trim((string)($rec['act_chapter'] ?? ''));
            }
            if ($cname === '') {
                $cname = 'General';
            }

            if (!isset($chap_assess_map[$cname])) {
                $chap_assess_map[$cname] = [
                    'chapter_name' => $cname,
                    'published_assessments' => 0,
                    'attended_assessments' => 0,
                    'scores' => []
                ];
            }

            if ($rec['attendance_status'] === 'attended' || $rec['attendance_status'] === 'not_attended') {
                $chap_assess_map[$cname]['published_assessments']++;
                if ($rec['attendance_status'] === 'attended') {
                    $chap_assess_map[$cname]['attended_assessments']++;
                    if ($rec['score'] !== null && (float)$rec['total_score'] > 0) {
                        $score = (float)$rec['score'];
                        $total = (float)$rec['total_score'];
                        if ($score >= 0 && $score <= $total) {
                            $pct_score = ($score / $total) * 100;
                            $unique_perf[$rec['batch_id']] = $pct_score;
                            $chap_assess_map[$cname]['scores'][] = $pct_score;
                        }
                    }
                }
            }
        }

        $attended_sessions = 0;
        $total_sessions = 0;
        foreach ($unique_att as $status) {
            if ($status === 'attended' || $status === 'not_attended') {
                $total_sessions++;
                if ($status === 'attended') {
                    $attended_sessions++;
                }
            }
        }
        $attendance_rate = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100) : null;
        $performance_score = count($unique_perf) > 0 ? round(array_sum($unique_perf) / count($unique_perf)) : null;

        $performance_label = null;
        $performance_class = null;
        if ($performance_score !== null) {
            $status_mapping = self::getPerformanceStatusMapping($performance_score);
            $performance_label = $status_mapping['label'];
            $performance_class = $status_mapping['class'];
        }

        // 3. CHAPTER PROGRESS CALCULATION (Independent of topics, retaining zero-completion chapters)
        $canonical_chapter_orders = [];
        try {
            $stmt_chaps = $pdo->query("SELECT id, chapter_name, sort_order FROM study_plan_chapters ORDER BY sort_order ASC, id ASC");
            if ($stmt_chaps) {
                $ch_rows = $stmt_chaps->fetchAll(PDO::FETCH_ASSOC);
                foreach ($ch_rows as $idx => $crow) {
                    $canonical_chapter_orders[trim($crow['chapter_name'])] = [
                        'id' => (int)$crow['id'],
                        'sort_order' => (int)$crow['sort_order'],
                        'index' => $idx
                    ];
                }
            }
        } catch (Exception $e) {}

        $chap_map = [];
        foreach ($activities as $act) {
            $cname = trim((string)($act['chapter'] ?? ''));
            if ($cname === '') {
                $cname = 'General';
            }
            if (!isset($chap_map[$cname])) {
                $chap_id = isset($canonical_chapter_orders[$cname]) ? $canonical_chapter_orders[$cname]['id'] : null;
                $chap_map[$cname] = [
                    'chapter_id' => $chap_id,
                    'chapter_name' => $cname,
                    'total_activities' => 0,
                    'completed_activities' => 0,
                    'pending_activities' => 0,
                    'overdue_activities' => 0,
                    'completion_percentage' => 0
                ];
            }
            $chap_map[$cname]['total_activities']++;
            if (isset($completed_map[$act['id']])) {
                $chap_map[$cname]['completed_activities']++;
            } else {
                $chap_map[$cname]['pending_activities']++;
                if ($plan_type === 'date_wise' && !empty($act['activity_date']) && $act['activity_date'] < $today) {
                    $chap_map[$cname]['overdue_activities']++;
                }
            }
        }

        foreach ($chap_map as $cname => &$cstats) {
            $cstats['completion_percentage'] = ($cstats['total_activities'] > 0)
                ? round(($cstats['completed_activities'] / $cstats['total_activities']) * 100)
                : 0;
        }
        unset($cstats);

        // Sort chapters according to canonical order from study_plan_chapters
        uasort($chap_map, function($a, $b) use ($canonical_chapter_orders) {
            $a_order = isset($canonical_chapter_orders[$a['chapter_name']]) ? $canonical_chapter_orders[$a['chapter_name']]['index'] : 9999;
            $b_order = isset($canonical_chapter_orders[$b['chapter_name']]) ? $canonical_chapter_orders[$b['chapter_name']]['index'] : 9999;
            if ($a_order !== $b_order) {
                return $a_order <=> $b_order;
            }
            return strcmp($a['chapter_name'], $b['chapter_name']);
        });
        $chapters = array_values($chap_map);

        // 4. CHAPTER-WISE ASSESSMENT BREAKDOWN
        $chapter_assessments = [];
        foreach ($chap_assess_map as $cname => $cdata) {
            $att_pct = $cdata['published_assessments'] > 0 ? round(($cdata['attended_assessments'] / $cdata['published_assessments']) * 100) : null;
            $avg_sc = count($cdata['scores']) > 0 ? round(array_sum($cdata['scores']) / count($cdata['scores']), 1) : null;
            $chapter_assessments[] = [
                'chapter_name' => $cname,
                'published_assessments' => $cdata['published_assessments'],
                'attended_assessments' => $cdata['attended_assessments'],
                'attendance_percentage' => $att_pct,
                'average_score' => $avg_sc
            ];
        }

        // 5. TOPIC ANALYSIS (Topic -> Subject fallback -> General)
        $topic_map = [];
        foreach ($activities as $act) {
            $raw_top = trim((string)($act['topic'] ?? ''));
            $raw_sub = trim((string)($act['subject'] ?? ''));
            $top_val = ($raw_top !== '') ? $raw_top : (($raw_sub !== '') ? $raw_sub : 'General');
            if (!isset($topic_map[$top_val])) {
                $topic_map[$top_val] = [
                    'topic_name' => $top_val,
                    'topic' => $top_val,
                    'total_activities' => 0,
                    'total' => 0,
                    'completed_activities' => 0,
                    'completed' => 0,
                    'pending_activities' => 0,
                    'pending' => 0,
                    'completion_percentage' => 0
                ];
            }
            $topic_map[$top_val]['total_activities']++;
            $topic_map[$top_val]['total']++;
            if (isset($completed_map[$act['id']])) {
                $topic_map[$top_val]['completed_activities']++;
                $topic_map[$top_val]['completed']++;
            } else {
                $topic_map[$top_val]['pending_activities']++;
                $topic_map[$top_val]['pending']++;
            }
        }

        foreach ($topic_map as $tname => &$tstats) {
            $tstats['completion_percentage'] = ($tstats['total'] > 0)
                ? round(($tstats['completed'] / $tstats['total']) * 100)
                : 0;
        }
        unset($tstats);

        $topics = array_values($topic_map);

        // Strongest Topics
        $strongest_topics = $topics;
        usort($strongest_topics, function($a, $b) {
            if ($b['completion_percentage'] !== $a['completion_percentage']) {
                return $b['completion_percentage'] <=> $a['completion_percentage'];
            }
            return $b['total'] <=> $a['total'];
        });
        $strongest_topics = array_slice($strongest_topics, 0, 5);

        // Needs Attention Topics
        $needs_attention_topics = $topics;
        usort($needs_attention_topics, function($a, $b) {
            if ($a['completion_percentage'] !== $b['completion_percentage']) {
                return $a['completion_percentage'] <=> $b['completion_percentage'];
            }
            return $b['pending'] <=> $a['pending'];
        });
        $needs_attention_topics = array_slice($needs_attention_topics, 0, 5);

        // 6. PROGRESS TIMELINE (Cumulative progression over time)
        $timeline_dates = [];
        foreach ($activities as $act) {
            if (!empty($act['activity_date'])) {
                $timeline_dates[$act['activity_date']] = true;
            }
        }
        foreach ($completed_dates as $cd) {
            $timeline_dates[$cd] = true;
        }
        $distinct_dates = array_keys($timeline_dates);
        sort($distinct_dates);

        $scheduled_by_date = [];
        foreach ($activities as $act) {
            if (!empty($act['activity_date'])) {
                $d = $act['activity_date'];
                $scheduled_by_date[$d] = ($scheduled_by_date[$d] ?? 0) + 1;
            }
        }
        $completed_by_date = [];
        foreach ($latest_completed_dates as $key => $cd) {
            $completed_by_date[$cd] = ($completed_by_date[$cd] ?? 0) + 1;
        }

        $progress_timeline = [];
        $running_scheduled = 0;
        $running_completed = 0;
        foreach ($distinct_dates as $d) {
            $sch = $scheduled_by_date[$d] ?? 0;
            $comp = $completed_by_date[$d] ?? 0;
            $running_scheduled += $sch;
            $running_completed += $comp;
            $eff_scheduled = max(1, $running_scheduled);
            $eff_comp = min($total_tasks, $running_completed);
            $pct_at_date = min(100, round(($eff_comp / $eff_scheduled) * 100));

            $progress_timeline[] = [
                'date' => $d,
                'date_formatted' => date('d M Y', strtotime($d)),
                'scheduled_activities' => $sch,
                'completed_activities' => $comp,
                'cumulative_scheduled' => $running_scheduled,
                'cumulative_completed' => $eff_comp,
                'completion_percentage' => $pct_at_date
            ];
        }

        // 7. STUDY PLAN COHORT RANKING
        $cohort_ranking = self::getCohortRanking($pdo, $study_plan_id, $academic_year, $user_id, $email);

        // 8. MENTOR INSIGHTS GENERATION
        $mentor_insights = self::generateMentorInsights([
            'total_tasks' => $total_tasks,
            'completed_tasks' => $completed_tasks,
            'pending_tasks' => $pending_tasks,
            'overdue_tasks' => $overdue_tasks,
            'completion_percentage' => $completion_percentage,
            'attendance_rate' => $attendance_rate,
            'performance_score' => $performance_score,
            'active_streak' => $streaks['current'],
            'consistency_percentage' => $consistency_percentage
        ], $chapters, $topics, $cohort_ranking);

        $student_profile = [
            'name' => $student_name,
            'student_id' => $user_id,
            'user_id' => $user_id,
            'masked_email' => $masked_email,
            'photo' => $user_photo,
            'photo_url' => $user_photo,
            'course' => $course_name,
            'academic_year' => $academic_year,
            'status' => $student_status,
            'study_plan' => $plan_title
        ];

        return [
            'study_plan_id' => $study_plan_id,
            'study_plan_title' => $plan_title,
            'academic_year' => $academic_year,

            'student_id' => $user_id,
            'user_id' => $user_id,
            'student_name' => $student_name,
            'masked_email' => $masked_email,
            'student_photo' => $user_photo,
            'student_status' => $student_status,
            'student_profile' => $student_profile,
            'student_info' => $student_profile,

            'total_tasks' => $total_tasks,
            'total_activities' => $total_tasks,
            'completed_tasks' => $completed_tasks,
            'completed_activities' => $completed_tasks,
            'pending_tasks' => $pending_tasks,
            'pending_activities' => $pending_tasks,
            'overdue_tasks' => $overdue_tasks,
            'overdue_activities' => $overdue_tasks,
            'completion_percentage' => $completion_percentage,
            'total_plan_calendar_days' => $total_plan_calendar_days,
            'eligible_plan_calendar_days' => $total_plan_calendar_days,

            'active_study_days' => $active_study_days,
            'consistency_percentage' => $consistency_percentage,

            'attended_sessions' => $attended_sessions,
            'total_sessions' => $total_sessions,
            'attendance_rate' => $attendance_rate,

            'performance_score' => $performance_score,
            'performance_label' => $performance_label,
            'performance_class' => $performance_class,

            'active_streak' => $streaks['current'],
            'current_streak' => $streaks['current'],
            'longest_streak' => $streaks['longest'],

            'first_activity' => !empty($completed_dates) ? min($completed_dates) : null,
            'last_activity' => !empty($completed_dates) ? max($completed_dates) : null,

            'chapters' => $chapters,
            'chapter_assessments' => $chapter_assessments,
            'topics' => $topics,
            'strongest_topics' => $strongest_topics,
            'needs_attention_topics' => $needs_attention_topics,
            'progress_timeline' => $progress_timeline,
            'cohort_ranking' => $cohort_ranking,
            'mentor_insights' => $mentor_insights
        ];
    }

    /**
     * Alias for detailed plan analytics
     */
    public static function getPlanDetailedAnalytics($pdo, $student_id_or_email, $study_plan_id) {
        return self::getPlanAnalytics($pdo, $student_id_or_email, $study_plan_id);
    }

    /**
     * Calculate unified Study Plan cohort ranking across all courses assigned to this Study Plan.
     * Merges multiple courses under the same Study Plan into one unified cohort.
     * Deduplicates students, activities, and assessment records.
     * Implements missing assessment weight normalization.
     */
    public static function getCohortRanking($pdo, $study_plan_id, $academic_year, $target_user_id = null, $target_email = null) {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $today = $now->format('Y-m-d');

        // 1. Fetch study plan info & verify academic year
        $stmt_plan = $pdo->prepare("SELECT id, title, academic_year, start_date, end_date, plan_type FROM study_plans WHERE id = ? AND is_deleted = 0");
        $stmt_plan->execute([$study_plan_id]);
        $plan = $stmt_plan->fetch(PDO::FETCH_ASSOC);

        if (!$plan || strtolower(trim($plan['academic_year'])) !== strtolower(trim($academic_year))) {
            return self::emptyCohortRanking($study_plan_id, $academic_year);
        }

        $total_plan_calendar_days = self::calculatePlanCalendarDays($plan['start_date'], $plan['end_date']);

        // 2. Resolve all assignments for this Study Plan
        $stmt_assign = $pdo->prepare("SELECT assignment_type, assigned_value FROM study_plan_assignments WHERE study_plan_id = ? AND is_deleted = 0");
        $stmt_assign->execute([$study_plan_id]);
        $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);

        if (empty($assignments)) {
            return self::emptyCohortRanking($study_plan_id, $academic_year);
        }

        $is_all = false;
        $assigned_courses = [];
        $assigned_students = [];
        $assigned_batches = [];

        foreach ($assignments as $asg) {
            $t = $asg['assignment_type'];
            $v = trim((string)$asg['assigned_value']);
            if ($t === 'all') {
                $is_all = true;
            } elseif ($t === 'course') {
                $assigned_courses[] = strtolower($v);
            } elseif ($t === 'student') {
                $assigned_students[] = $v;
            } elseif ($t === 'batch') {
                $assigned_batches[] = strtolower($v);
            }
        }

        // 3. Fetch all eligible approved students in this academic year matching assignments
        $query_users = "
            SELECT user_id, email, name, pepp_course, pepp_academic_year, user_photo, student_status
            FROM users
            WHERE status = 'approved'
              AND LOWER(TRIM(pepp_academic_year)) = LOWER(TRIM(?))
        ";
        $stmt_users = $pdo->prepare($query_users);
        $stmt_users->execute([$academic_year]);
        $raw_users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

        // Deduplicate students by user_id / email (a student in multiple courses appears ONCE)
        $cohort_students_map = [];
        foreach ($raw_users as $u) {
            $u_id = trim((string)($u['user_id'] ?? ''));
            $u_email = strtolower(trim((string)($u['email'] ?? '')));
            $u_course = strtolower(trim((string)($u['pepp_course'] ?? '')));
            $u_batch = strtolower(trim((string)($u['pepp_academic_year'] ?? '')));

            $eligible = false;
            if ($is_all) {
                $eligible = true;
            } elseif (in_array($u_course, $assigned_courses)) {
                $eligible = true;
            } elseif (in_array($u_id, $assigned_students)) {
                $eligible = true;
            } elseif (in_array($u_batch, $assigned_batches)) {
                $eligible = true;
            }

            if ($eligible) {
                $key = ($u_id !== '') ? $u_id : $u_email;
                if (!isset($cohort_students_map[$key])) {
                    $cohort_students_map[$key] = $u;
                }
            }
        }

        $unique_students = array_values($cohort_students_map);
        $cohort_size = count($unique_students);

        if ($cohort_size === 0) {
            return self::emptyCohortRanking($study_plan_id, $academic_year);
        }

        // 4. Fetch all active activities for this plan (Deduplicated)
        $stmt_act = $pdo->prepare("SELECT id, activity_uid FROM study_plan_activities WHERE study_plan_id = ? AND is_deleted = 0");
        $stmt_act->execute([$study_plan_id]);
        $plan_activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
        $total_plan_tasks = count($plan_activities);

        // 5. Bulk fetch all completion logs for this plan
        $stmt_logs = $pdo->prepare("
            SELECT student_email, activity_id, activity_uid, completion_status, created_at
            FROM study_plan_analytics
            WHERE study_plan_id = ? AND action_type = 'complete_activity'
            ORDER BY id ASC
        ");
        $stmt_logs->execute([$study_plan_id]);
        $all_plan_logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

        $logs_by_email = [];
        foreach ($all_plan_logs as $log) {
            $sem = strtolower(trim($log['student_email']));
            $logs_by_email[$sem][] = $log;
        }

        // 6. Bulk fetch published assessments for this plan
        $stmt_ass = $pdo->prepare("
            SELECT ar.batch_id, ar.student_email, ar.user_id, ar.attendance_status, ar.score, ar.total_score
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE arb.study_plan_id = ? AND arb.status = 'published'
              AND LOWER(TRIM(arb.academic_year)) = LOWER(TRIM(?))
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_ass->execute([$study_plan_id, $academic_year, $today]);
        $all_plan_assessments = $stmt_ass->fetchAll(PDO::FETCH_ASSOC);

        $assess_by_email = [];
        $assess_by_uid = [];
        foreach ($all_plan_assessments as $ass) {
            if (!empty($ass['user_id'])) {
                $assess_by_uid[trim($ass['user_id'])][$ass['batch_id']] = $ass;
            }
            if (!empty($ass['student_email'])) {
                $assess_by_email[strtolower(trim($ass['student_email']))][$ass['batch_id']] = $ass;
            }
        }

        // 7. Evaluate each unique student in the cohort
        $ranked_cohort = [];
        foreach ($unique_students as $st) {
            $st_email = strtolower(trim($st['email']));
            $st_uid = trim((string)$st['user_id']);

            // Evaluate completions
            $st_logs = $logs_by_email[$st_email] ?? [];
            $effective_completions = [];
            $latest_dates = [];

            foreach ($st_logs as $log) {
                $k = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
                $effective_completions[$k] = $log['completion_status'];
                if ($log['completion_status'] === 'completed' && !empty($log['created_at'])) {
                    $latest_dates[$k] = self::convertToKolkataDate($log['created_at']);
                } elseif ($log['completion_status'] === 'cleared') {
                    unset($latest_dates[$k]);
                }
            }

            $completed_tasks = 0;
            foreach ($plan_activities as $act) {
                $k = !empty($act['activity_uid']) ? $act['activity_uid'] : 'id_' . $act['id'];
                if (isset($effective_completions[$k]) && $effective_completions[$k] === 'completed') {
                    $completed_tasks++;
                }
            }

            $completion_pct = $total_plan_tasks > 0 ? min(100, round(($completed_tasks / $total_plan_tasks) * 100, 1)) : 0.0;
            $active_study_days = count(array_values(array_filter(array_unique(array_values($latest_dates)))));
            $consistency_pct = $total_plan_calendar_days > 0 ? min(100, round(($active_study_days / $total_plan_calendar_days) * 100, 1)) : 0.0;

            // Evaluate assessments (Deduplicated by batch_id)
            $st_ass_map = [];
            if ($st_uid !== '' && isset($assess_by_uid[$st_uid])) {
                foreach ($assess_by_uid[$st_uid] as $bid => $ass_rec) {
                    $st_ass_map[$bid] = $ass_rec;
                }
            }
            if (isset($assess_by_email[$st_email])) {
                foreach ($assess_by_email[$st_email] as $bid => $ass_rec) {
                    $st_ass_map[$bid] = $ass_rec;
                }
            }

            $att_total = 0;
            $att_attended = 0;
            $scores = [];

            foreach ($st_ass_map as $ass_rec) {
                $status = $ass_rec['attendance_status'];
                if ($status === 'attended' || $status === 'not_attended') {
                    $att_total++;
                    if ($status === 'attended') {
                        $att_attended++;
                        if ($ass_rec['score'] !== null && (float)$ass_rec['total_score'] > 0) {
                            $sc = (float)$ass_rec['score'];
                            $tot = (float)$ass_rec['total_score'];
                            if ($sc >= 0 && $sc <= $tot) {
                                $scores[] = ($sc / $tot) * 100;
                            }
                        }
                    }
                }
            }

            $attendance_rate = $att_total > 0 ? round(($att_attended / $att_total) * 100, 1) : null;
            $performance_score = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : null;

            // Missing Assessment Weight Normalization
            // Standard Weights: Completion 40%, Assessment Score 30%, Assessment Attendance 20%, Consistency 10%
            $available_weight = 0.40 + 0.10;
            $weighted_sum = ($completion_pct * 0.40) + ($consistency_pct * 0.10);

            if ($performance_score !== null) {
                $available_weight += 0.30;
                $weighted_sum += ($performance_score * 0.30);
            }
            if ($attendance_rate !== null) {
                $available_weight += 0.20;
                $weighted_sum += ($attendance_rate * 0.20);
            }

            $overall_performance_index = $available_weight > 0 ? round($weighted_sum / $available_weight, 1) : 0.0;

            $is_current = false;
            if ($target_user_id && $target_user_id === $st['user_id']) {
                $is_current = true;
            } elseif ($target_email && strtolower($target_email) === $st_email) {
                $is_current = true;
            }

            $ranked_cohort[] = [
                'user_id' => $st['user_id'],
                'masked_email' => self::maskEmail($st['email']),
                'name' => $st['name'],
                'course' => $st['pepp_course'],
                'academic_year' => $st['pepp_academic_year'],
                'user_photo' => $st['user_photo'],
                'completion_pct' => $completion_pct,
                'completed_tasks' => $completed_tasks,
                'total_tasks' => $total_plan_tasks,
                'active_study_days' => $active_study_days,
                'consistency_pct' => $consistency_pct,
                'attendance_rate' => $attendance_rate,
                'performance_score' => $performance_score,
                'performance_index' => $overall_performance_index,
                'is_current' => $is_current
            ];
        }

        // 8. Sort cohort descending by Performance Index, then Completion %, then Consistency %
        usort($ranked_cohort, function($a, $b) {
            if ($b['performance_index'] !== $a['performance_index']) {
                return $b['performance_index'] <=> $a['performance_index'];
            }
            if ($b['completion_pct'] !== $a['completion_pct']) {
                return $b['completion_pct'] <=> $a['completion_pct'];
            }
            if ($b['consistency_pct'] !== $a['consistency_pct']) {
                return $b['consistency_pct'] <=> $a['consistency_pct'];
            }
            return strcmp($a['user_id'], $b['user_id']);
        });

        // 9. Competition Ranking (1, 2, 2, 4) & Mutually Exclusive Percentile Badges
        $distribution_buckets = [
            '0-39' => 0,
            '40-49' => 0,
            '50-59' => 0,
            '60-69' => 0,
            '70-79' => 0,
            '80-89' => 0,
            '90-100' => 0
        ];

        $current_student_data = null;

        foreach ($ranked_cohort as $idx => &$st) {
            if ($idx > 0) {
                $prev = $ranked_cohort[$idx - 1];
                if ($st['performance_index'] === $prev['performance_index'] &&
                    $st['completion_pct'] === $prev['completion_pct'] &&
                    $st['consistency_pct'] === $prev['consistency_pct']) {
                    $st['rank'] = $prev['rank'];
                } else {
                    $st['rank'] = $idx + 1;
                }
            } else {
                $st['rank'] = 1;
            }

            $rank_val = $st['rank'];
            $st['cohort_size'] = $cohort_size;
            $st['top_percentile'] = ($cohort_size > 0) ? round(($rank_val / $cohort_size) * 100, 1) : 100.0;
            $st['percentile_text'] = 'Top ' . ceil($st['top_percentile']) . '%';

            // Percentile Badges
            if ($rank_val <= max(1, ceil(0.05 * $cohort_size))) {
                $st['badge'] = '🥇 Elite Performer';
                $st['badge_class'] = 'elite';
            } elseif ($rank_val <= max(1, ceil(0.10 * $cohort_size))) {
                $st['badge'] = '🥈 Outstanding Performer';
                $st['badge_class'] = 'outstanding';
            } elseif ($rank_val <= max(1, ceil(0.25 * $cohort_size))) {
                $st['badge'] = '🥉 High Performer';
                $st['badge_class'] = 'high';
            } elseif ($rank_val <= max(1, ceil(0.50 * $cohort_size))) {
                $st['badge'] = '⭐ Strong Performer';
                $st['badge_class'] = 'strong';
            } elseif ($st['top_percentile'] <= 75.0) {
                $st['badge'] = '📈 Developing';
                $st['badge_class'] = 'developing';
            } else {
                $st['badge'] = '🎯 Needs Attention';
                $st['badge_class'] = 'attention';
            }

            // Assign to distribution bucket
            $idx_val = $st['performance_index'];
            if ($idx_val >= 90) $bkey = '90-100';
            elseif ($idx_val >= 80) $bkey = '80-89';
            elseif ($idx_val >= 70) $bkey = '70-79';
            elseif ($idx_val >= 60) $bkey = '60-69';
            elseif ($idx_val >= 50) $bkey = '50-59';
            elseif ($idx_val >= 40) $bkey = '40-49';
            else $bkey = '0-39';

            $st['bucket'] = $bkey;
            $distribution_buckets[$bkey]++;

            if ($st['is_current']) {
                $current_student_data = $st;
            }
        }
        unset($st);

        // Format distribution for charts
        $distribution_chart = [];
        foreach ($distribution_buckets as $bname => $bcount) {
            $distribution_chart[] = [
                'bucket' => $bname,
                'count' => $bcount,
                'is_current_student_bucket' => ($current_student_data && $current_student_data['bucket'] === $bname)
            ];
        }

        // Mask emails for leaderboard privacy
        $leaderboard = [];
        foreach (array_slice($ranked_cohort, 0, 15) as $r) {
            $masked_em = $r['masked_email'] ?? self::maskEmail($r['email'] ?? '');

            $leaderboard[] = [
                'rank' => $r['rank'],
                'name' => $r['name'],
                'masked_email' => $masked_em,
                'course' => $r['course'],
                'completion_pct' => $r['completion_pct'],
                'assessment_score' => $r['performance_score'],
                'attendance_rate' => $r['attendance_rate'],
                'consistency_pct' => $r['consistency_pct'],
                'performance_index' => $r['performance_index'],
                'badge' => $r['badge'],
                'is_current' => $r['is_current']
            ];
        }

        return [
            'study_plan_id' => $study_plan_id,
            'study_plan_title' => $plan['title'],
            'academic_year' => $academic_year,
            'cohort_size' => $cohort_size,
            'current_student' => $current_student_data,
            'distribution' => $distribution_chart,
            'distribution_buckets' => $distribution_buckets,
            'leaderboard' => $leaderboard
        ];
    }

    /**
     * Empty cohort ranking fallback
     */
    private static function emptyCohortRanking($study_plan_id, $academic_year) {
        return [
            'study_plan_id' => $study_plan_id,
            'study_plan_title' => 'Study Plan',
            'academic_year' => $academic_year,
            'cohort_size' => 0,
            'current_student' => null,
            'distribution' => [],
            'distribution_buckets' => [
                '0-39' => 0, '40-49' => 0, '50-59' => 0, '60-69' => 0,
                '70-79' => 0, '80-89' => 0, '90-100' => 0
            ],
            'leaderboard' => []
        ];
    }

    /**
     * Get multi-plan comparative analytics & rank trajectory across sequential study plans for a student.
     */
    public static function getStudentMultiPlanAnalytics($pdo, $student_id_or_email, $academic_year = null) {
        // Resolve student details
        $stmt_user = $pdo->prepare("
            SELECT user_id, email, name, pepp_course, pepp_academic_year
            FROM users
            WHERE (user_id = ? OR LOWER(email) = LOWER(?)) AND status = 'approved'
            LIMIT 1
        ");
        $stmt_user->execute([$student_id_or_email, $student_id_or_email]);
        $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return [
                'plans' => [],
                'rank_trend' => [],
                'performance_trend' => [],
                'trajectory' => 'stable'
            ];
        }

        $email = $user['email'];
        $user_id = $user['user_id'];
        $course_name = $user['pepp_course'];
        $academic_year = $academic_year ?: $user['pepp_academic_year'];

        // Find all assigned published plans for this student in this academic year
        $stmt_plans = $pdo->prepare("
            SELECT DISTINCT sp.id, sp.title, sp.start_date, sp.end_date, sp.plan_type
            FROM study_plans sp
            JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
            WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
              AND LOWER(sp.academic_year) = LOWER(?)
              AND (
                sa.assignment_type = 'all' OR
                (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'batch' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'student' AND sa.assigned_value = ?)
            )
            ORDER BY sp.start_date ASC, sp.id ASC
        ");
        $stmt_plans->execute([$academic_year, $course_name, $academic_year, $user_id]);
        $assigned_plans = $stmt_plans->fetchAll(PDO::FETCH_ASSOC);

        $plans_summary = [];
        $rank_trend = [];
        $performance_trend = [];

        foreach ($assigned_plans as $p) {
            $plan_id = (int)$p['id'];
            $p_analytics = self::getPlanAnalytics($pdo, $email, $plan_id);
            $c_ranking = $p_analytics['cohort_ranking'] ?? null;
            $cur_st = $c_ranking['current_student'] ?? null;

            $rank = $cur_st['rank'] ?? null;
            $c_size = $c_ranking['cohort_size'] ?? 0;
            $perf_idx = $cur_st['performance_index'] ?? ($p_analytics['completion_percentage'] ?? 0);
            $badge = $cur_st['badge'] ?? null;

            $summary_item = [
                'study_plan_id' => $plan_id,
                'study_plan_name' => $p['title'],
                'start_date' => $p['start_date'],
                'end_date' => $p['end_date'],
                'total_tasks' => $p_analytics['total_tasks'],
                'completed_tasks' => $p_analytics['completed_tasks'],
                'completion_percentage' => $p_analytics['completion_percentage'],
                'assessment_average' => $p_analytics['performance_score'],
                'assessment_attendance' => $p_analytics['attendance_rate'],
                'consistency' => $p_analytics['consistency_percentage'],
                'performance_index' => $perf_idx,
                'rank' => $rank,
                'cohort_size' => $c_size,
                'percentile' => $cur_st['top_percentile'] ?? null,
                'badge' => $badge
            ];

            $plans_summary[] = $summary_item;

            if ($rank !== null) {
                $rank_trend[] = [
                    'study_plan_id' => $plan_id,
                    'study_plan_name' => $p['title'],
                    'rank' => $rank,
                    'cohort_size' => $c_size,
                    'performance_index' => $perf_idx
                ];
            }

            $performance_trend[] = [
                'study_plan_id' => $plan_id,
                'study_plan_name' => $p['title'],
                'performance_index' => $perf_idx,
                'completion_percentage' => $p_analytics['completion_percentage'],
                'consistency_percentage' => $p_analytics['consistency_percentage']
            ];
        }

        // Calculate overall trajectory (lower rank number is better)
        $trajectory = 'stable';
        if (count($rank_trend) >= 2) {
            $first_rank = $rank_trend[0]['rank'];
            $last_rank = $rank_trend[count($rank_trend) - 1]['rank'];
            if ($last_rank < $first_rank) {
                $trajectory = 'improving';
            } elseif ($last_rank > $first_rank) {
                $trajectory = 'declining';
            }
        }

        return [
            'plans' => $plans_summary,
            'rank_trend' => $rank_trend,
            'performance_trend' => $performance_trend,
            'trajectory' => $trajectory
        ];
    }

    /**
     * Generate automated actionable mentor insights based on actual student analytics.
     */
    public static function generateMentorInsights($analytics, $chapters, $topics, $cohort_ranking) {
        $insights = [];

        // 1. Overdue tasks alert
        if (!empty($analytics['overdue_tasks']) && (int)$analytics['overdue_tasks'] > 0) {
            $insights[] = [
                'type' => 'danger',
                'icon' => 'fa-triangle-exclamation',
                'title' => 'Overdue Activities Pending',
                'message' => "{$analytics['overdue_tasks']} activity(s) are currently overdue. Recommended to guide the student to catch up on the scheduled backlog."
            ];
        }

        // 2. Low completion chapters
        if (!empty($chapters)) {
            foreach ($chapters as $chap) {
                if ($chap['total_activities'] >= 2 && $chap['completion_percentage'] < 50 && $chap['pending_activities'] > 0) {
                    $insights[] = [
                        'type' => 'warning',
                        'icon' => 'fa-book-open-reader',
                        'title' => 'Chapter Attention Required',
                        'message' => "'{$chap['chapter_name']}' has only {$chap['completion_percentage']}% completion with {$chap['pending_activities']} pending task(s)."
                    ];
                    break;
                }
            }
        }

        // 3. Assessment vs Activity Divergence
        if ($analytics['performance_score'] !== null && $analytics['completion_percentage'] > 75) {
            $diff = $analytics['completion_percentage'] - $analytics['performance_score'];
            if ($diff >= 25) {
                $insights[] = [
                    'type' => 'warning',
                    'icon' => 'fa-chart-line-down',
                    'title' => 'Assessment Performance Divergence',
                    'message' => "Activity completion is high ({$analytics['completion_percentage']}%), but assessment average ({$analytics['performance_score']}%) indicates concept revision is needed."
                ];
            }
        }

        // 4. Low Consistency Warning
        if (isset($analytics['consistency_percentage']) && $analytics['consistency_percentage'] < 40 && ($analytics['total_tasks'] ?? 0) >= 5) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'fa-calendar-xmark',
                'title' => 'Low Study Consistency',
                'message' => "Learning consistency is {$analytics['consistency_percentage']}%. Suggest establishing a regular daily study routine."
            ];
        }

        // 5. High Mastery Praise
        if (!empty($chapters)) {
            foreach ($chapters as $chap) {
                if ($chap['total_activities'] >= 3 && $chap['completion_percentage'] === 100) {
                    $insights[] = [
                        'type' => 'success',
                        'icon' => 'fa-circle-check',
                        'title' => 'Strong Chapter Mastery',
                        'message' => "'{$chap['chapter_name']}' is 100% completed with all {$chap['total_activities']} activities fulfilled."
                    ];
                    break;
                }
            }
        }

        // 6. Cohort Rank Recognition
        if (!empty($cohort_ranking['current_student'])) {
            $cur = $cohort_ranking['current_student'];
            if ($cur['rank'] <= 3) {
                $insights[] = [
                    'type' => 'success',
                    'icon' => 'fa-trophy',
                    'title' => 'Top Cohort Performer',
                    'message' => "Ranked #{$cur['rank']} of {$cur['cohort_size']} students in the {$cohort_ranking['study_plan_title']} cohort ({$cur['badge']})."
                ];
            } elseif ($cur['top_percentile'] <= 25) {
                $insights[] = [
                    'type' => 'success',
                    'icon' => 'fa-award',
                    'title' => 'High Cohort Standing',
                    'message' => "Performing in the {$cur['percentile_text']} of the Study Plan cohort with an Overall Performance Index of {$cur['performance_index']}%."
                ];
            }
        }

        // 7. Streak Recognition
        if (!empty($analytics['active_streak']) && (int)$analytics['active_streak'] >= 5) {
            $insights[] = [
                'type' => 'info',
                'icon' => 'fa-fire',
                'title' => 'Active Learning Streak',
                'message' => "Student is maintaining an active {$analytics['active_streak']}-day learning streak."
            ];
        }

        return $insights;
    }

    /**
     * Get analytics aggregated across all study plans in a course.
     */
    public static function getCourseAnalytics($pdo, $student_id_or_email, $course_name) {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $today = $now->format('Y-m-d');

        // Resolve student details
        $stmt_user = $pdo->prepare("
            SELECT user_id, email, pepp_course, pepp_academic_year
            FROM users
            WHERE (user_id = ? OR LOWER(email) = LOWER(?)) AND status = 'approved'
            LIMIT 1
        ");
        $stmt_user->execute([$student_id_or_email, $student_id_or_email]);
        $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return self::emptyAnalytics();
        }
        $email = $user['email'];
        $user_id = $user['user_id'];

        // Find all assigned plans for this student in this course, strictly isolated by academic year
        $stmt_plans = $pdo->prepare("
            SELECT DISTINCT sp.id
            FROM study_plans sp
            JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
            WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
              AND LOWER(sp.academic_year) = LOWER(?)
              AND (
                sa.assignment_type = 'all' OR
                (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'batch' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'student' AND sa.assigned_value = ?)
            )
        ");
        $stmt_plans->execute([$user['pepp_academic_year'], $user['pepp_course'], $user['pepp_academic_year'], $user_id]);
        $plan_ids = $stmt_plans->fetchAll(PDO::FETCH_COLUMN);

        if (empty($plan_ids)) {
            return self::emptyAnalytics();
        }

        $total_tasks = 0;
        $completed_tasks = 0;
        $overdue_tasks = 0;
        $total_plan_calendar_days = 0;

        foreach ($plan_ids as $plan_id) {
            $plan_data = self::getPlanAnalytics($pdo, $email, $plan_id);
            $total_tasks += $plan_data['total_tasks'];
            $completed_tasks += $plan_data['completed_tasks'];
            $overdue_tasks += $plan_data['overdue_tasks'];
            $total_plan_calendar_days += $plan_data['total_plan_calendar_days'];
        }

        $pending_tasks = max(0, $total_tasks - $completed_tasks);
        $completion_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

        // Streak calculation across all active tasks of the course plans
        $stmt_all_logs = $pdo->prepare("
            SELECT an.id, an.activity_id, an.activity_uid, an.completion_status, an.created_at
            FROM study_plan_analytics an
            JOIN study_plan_activities act ON (
                (an.activity_uid = act.activity_uid AND act.activity_uid IS NOT NULL AND act.activity_uid != '')
                OR (an.activity_id = act.id AND (an.activity_uid IS NULL OR an.activity_uid = '' OR act.activity_uid IS NULL OR act.activity_uid = ''))
            )
            WHERE LOWER(an.student_email) = LOWER(?) AND an.action_type = 'complete_activity' AND act.is_deleted = 0
              AND an.study_plan_id IN (" . implode(',', array_map('intval', $plan_ids)) . ")
            ORDER BY an.id ASC
        ");
        $stmt_all_logs->execute([$email]);
        $all_logs = $stmt_all_logs->fetchAll(PDO::FETCH_ASSOC);

        $effective_completions = [];
        foreach ($all_logs as $log) {
            $key = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
            $effective_completions[$key] = $log['completion_status'];
        }

        $latest_completed_dates = [];
        foreach ($all_logs as $log) {
            $key = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
            if ($log['completion_status'] === 'completed') {
                if (!empty($log['created_at'])) {
                    $latest_completed_dates[$key] = self::convertToKolkataDate($log['created_at']);
                }
            } else if ($log['completion_status'] === 'cleared') {
                unset($latest_completed_dates[$key]);
            }
        }
        $completed_dates = array_values(array_filter(array_unique(array_values($latest_completed_dates))));
        $streaks = self::calculateStreaksFromDates($completed_dates);

        $active_study_days = count($completed_dates);
        $consistency_percentage = $total_plan_calendar_days > 0 ? min(100, round(($active_study_days / $total_plan_calendar_days) * 100)) : 0;

        // Real attendance from assessment results
        $plan_id_placeholders = !empty($plan_ids) ? implode(',', array_map('intval', $plan_ids)) : '0';
        $stmt_att = $pdo->prepare("
            SELECT ar.batch_id, ar.attendance_status
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE ((ar.user_id IS NOT NULL AND ar.user_id = ?) OR (ar.student_email IS NOT NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.status = 'published'
              AND LOWER(TRIM(arb.academic_year)) = LOWER(TRIM(?))
              AND (
                  (arb.study_plan_id > 0 AND arb.study_plan_id IN ($plan_id_placeholders))
                  OR (
                      (arb.study_plan_id IS NULL OR arb.study_plan_id = 0)
                      AND (
                          LOWER(TRIM(arb.course_name)) = LOWER(TRIM(?))
                          OR LOWER(TRIM(arb.course_name)) = 'all courses'
                          OR arb.course_name IS NULL
                          OR arb.course_name = ''
                      )
                  )
              )
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_att->execute([$user_id, $email, $user['pepp_academic_year'], $course_name, $today]);
        $att_records = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

        $unique_att = [];
        foreach ($att_records as $rec) {
            $unique_att[$rec['batch_id']] = $rec['attendance_status'];
        }

        $attended_sessions = 0;
        $total_sessions = 0;
        foreach ($unique_att as $status) {
            if ($status === 'attended' || $status === 'not_attended') {
                $total_sessions++;
                if ($status === 'attended') {
                    $attended_sessions++;
                }
            }
        }
        $attendance_rate = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100) : null;

        // Real performance from assessment results
        $stmt_perf = $pdo->prepare("
            SELECT ar.batch_id, ar.score, ar.total_score
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE ((ar.user_id IS NOT NULL AND ar.user_id = ?) OR (ar.student_email IS NOT NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.status = 'published'
              AND LOWER(TRIM(arb.academic_year)) = LOWER(TRIM(?))
              AND (
                  (arb.study_plan_id > 0 AND arb.study_plan_id IN ($plan_id_placeholders))
                  OR (
                      (arb.study_plan_id IS NULL OR arb.study_plan_id = 0)
                      AND (
                          LOWER(TRIM(arb.course_name)) = LOWER(TRIM(?))
                          OR LOWER(TRIM(arb.course_name)) = 'all courses'
                          OR arb.course_name IS NULL
                          OR arb.course_name = ''
                      )
                  )
              )
              AND ar.attendance_status = 'attended' AND ar.score IS NOT NULL AND ar.total_score > 0
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_perf->execute([$user_id, $email, $user['pepp_academic_year'], $course_name, $today]);
        $perf_records = $stmt_perf->fetchAll(PDO::FETCH_ASSOC);

        $unique_perf = [];
        foreach ($perf_records as $rec) {
            $score = (float)$rec['score'];
            $total = (float)$rec['total_score'];
            if ($score < 0 || $score > $total) {
                continue;
            }
            $unique_perf[$rec['batch_id']] = ($score / $total) * 100;
        }

        $performance_score = count($unique_perf) > 0 ? round(array_sum($unique_perf) / count($unique_perf)) : null;

        $performance_label = null;
        $performance_class = null;
        if ($performance_score !== null) {
            $status_mapping = self::getPerformanceStatusMapping($performance_score);
            $performance_label = $status_mapping['label'];
            $performance_class = $status_mapping['class'];
        }

        return [
            'total_tasks' => $total_tasks,
            'completed_tasks' => $completed_tasks,
            'pending_tasks' => $pending_tasks,
            'overdue_tasks' => $overdue_tasks,
            'completion_percentage' => $completion_percentage,
            'total_plan_calendar_days' => $total_plan_calendar_days,
            'eligible_plan_calendar_days' => $total_plan_calendar_days,

            'active_study_days' => $active_study_days,
            'consistency_percentage' => $consistency_percentage,

            'attended_sessions' => $attended_sessions,
            'total_sessions' => $total_sessions,
            'attendance_rate' => $attendance_rate,

            'performance_score' => $performance_score,
            'performance_label' => $performance_label,
            'performance_class' => $performance_class,

            'active_streak' => $streaks['current'],
            'longest_streak' => $streaks['longest'],

            'first_activity' => !empty($completed_dates) ? min($completed_dates) : null,
            'last_activity' => !empty($completed_dates) ? max($completed_dates) : null
        ];
    }

    private static function emptyAnalytics() {
        return [
            'student_id' => null,
            'user_id' => null,
            'student_name' => '',
            'masked_email' => 'Not available',
            'student_photo' => '',
            'student_status' => 'inactive',
            'student_profile' => [
                'name' => '',
                'student_id' => '',
                'user_id' => '',
                'masked_email' => 'Not available',
                'photo' => '',
                'photo_url' => '',
                'course' => '',
                'academic_year' => '',
                'status' => 'inactive',
                'study_plan' => ''
            ],
            'student_info' => [
                'name' => '',
                'student_id' => '',
                'user_id' => '',
                'masked_email' => 'Not available',
                'photo' => '',
                'photo_url' => '',
                'course' => '',
                'academic_year' => '',
                'status' => 'inactive',
                'study_plan' => ''
            ],
            'total_tasks' => 0,
            'total_activities' => 0,
            'completed_tasks' => 0,
            'completed_activities' => 0,
            'pending_tasks' => 0,
            'pending_activities' => 0,
            'overdue_tasks' => 0,
            'overdue_activities' => 0,
            'completion_percentage' => 0,
            'total_plan_calendar_days' => 0,
            'eligible_plan_calendar_days' => 0,

            'active_study_days' => 0,
            'consistency_percentage' => 0,

            'attended_sessions' => 0,
            'total_sessions' => 0,
            'attendance_rate' => null,

            'performance_score' => null,
            'performance_label' => null,
            'performance_class' => null,

            'active_streak' => 0,
            'current_streak' => 0,
            'longest_streak' => 0,

            'first_activity' => null,
            'last_activity' => null,

            'chapters' => [],
            'chapter_assessments' => [],
            'topics' => [],
            'strongest_topics' => [],
            'needs_attention_topics' => [],
            'progress_timeline' => [],
            'cohort_ranking' => null,
            'mentor_insights' => []
        ];
    }

    private static function calculateStreaksFromDates($dates) {
        if (empty($dates)) {
            return ['current' => 0, 'longest' => 0];
        }

        // Sort dates in descending order (newest to oldest)
        usort($dates, function($a, $b) {
            return strcmp($b, $a);
        });

        // Today and yesterday in Asia/Kolkata
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');

        $current_streak = 0;
        $expected = null;
        if ($dates[0] === $today) {
            $current_streak = 1;
            $expected = $yesterday;
        } else if ($dates[0] === $yesterday) {
            $current_streak = 1;
            $expected = $now->modify('-2 days')->format('Y-m-d');
        } else {
            $current_streak = 0;
        }

        if ($current_streak > 0) {
            for ($i = 1; $i < count($dates); $i++) {
                if ($dates[$i] === $expected) {
                    $current_streak++;
                    $expected_dt = new DateTimeImmutable($expected, new DateTimeZone('Asia/Kolkata'));
                    $expected = $expected_dt->modify('-1 day')->format('Y-m-d');
                } else {
                    break;
                }
            }
        }

        // Calculate longest streak (sort oldest to newest)
        sort($dates);
        $longest_streak = 1;
        $temp_streak = 1;
        for ($i = 1; $i < count($dates); $i++) {
            $d1 = new DateTimeImmutable($dates[$i-1], new DateTimeZone('Asia/Kolkata'));
            $d2 = new DateTimeImmutable($dates[$i], new DateTimeZone('Asia/Kolkata'));
            $diff = $d1->diff($d2)->days;
            if ($diff == 1) {
                $temp_streak++;
            } else if ($diff > 1) {
                if ($temp_streak > $longest_streak) {
                    $longest_streak = $temp_streak;
                }
                $temp_streak = 1;
            }
        }
        if ($temp_streak > $longest_streak) {
            $longest_streak = $temp_streak;
        }

        return ['current' => $current_streak, 'longest' => $longest_streak];
    }

    private static function getPerformanceStatusMapping($pct) {
        if ($pct >= 85) return ['label' => 'Excellent', 'class' => 'green', 'color' => '#10b981'];
        if ($pct >= 60) return ['label' => 'Good', 'class' => 'blue', 'color' => '#3b82f6'];
        if ($pct >= 40) return ['label' => 'Average', 'class' => 'amber', 'color' => '#f59e0b'];
        return ['label' => 'Needs Improvement', 'class' => 'red', 'color' => '#ef4444'];
    }

    public static function convertToKolkataDate($timestamp_str) {
        if (empty($timestamp_str)) {
            return null;
        }
        try {
            if (preg_match('/[TZ]|[+-]\d{2}(:\d{2})?$/i', $timestamp_str)) {
                $dt = new DateTimeImmutable($timestamp_str);
                return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
            } else {
                $dt = new DateTimeImmutable($timestamp_str, new DateTimeZone('Asia/Kolkata'));
                return $dt->format('Y-m-d');
            }
        } catch (Exception $e) {
            return null;
        }
    }

    public static function getCourseAnalyticsBulk($pdo, $students, $course_name) {
        if (empty($students)) {
            return [];
        }

        $student_emails = [];
        $student_ids = [];
        $academic_years = [];
        foreach ($students as $s) {
            $student_emails[] = strtolower(trim($s['email']));
            $uid = trim($s['user_id']);
            if ($uid !== '') {
                $student_ids[] = $uid;
            }
            if (!empty($s['pepp_academic_year'])) {
                $academic_years[] = strtolower(trim($s['pepp_academic_year']));
            }
        }
        $student_emails = array_values(array_unique($student_emails));
        $student_ids = array_values(array_unique($student_ids));
        $academic_years = array_values(array_unique($academic_years));

        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $today = $now->format('Y-m-d');

        // Fetch all published, active study plan assignments matching the course
        $stmt_plans = $pdo->prepare("
            SELECT DISTINCT sp.id, sp.academic_year, sp.start_date, sp.end_date, sa.assignment_type, sa.assigned_value
            FROM study_plans sp
            JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
            WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0
              AND (
                  sa.assignment_type = 'all' OR
                  (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                  sa.assignment_type = 'batch' OR
                  sa.assignment_type = 'student'
              )
        ");
        $stmt_plans->execute([$course_name]);
        $all_assignments = $stmt_plans->fetchAll(PDO::FETCH_ASSOC);

        // Group plans by plan_id
        $plans_metadata = [];
        foreach ($all_assignments as $assign) {
            $plans_metadata[$assign['id']]['academic_year'] = $assign['academic_year'];
            $plans_metadata[$assign['id']]['start_date'] = $assign['start_date'] ?? null;
            $plans_metadata[$assign['id']]['end_date'] = $assign['end_date'] ?? null;
            $plans_metadata[$assign['id']]['assignments'][] = $assign;
        }

        $plan_ids = array_keys($plans_metadata);
        if (empty($plan_ids)) {
            $results = [];
            foreach ($students as $s) {
                $results[$s['email']] = self::emptyAnalytics();
            }
            return $results;
        }

        // Fetch all active activities for these plans
        $in_plan_ids = implode(',', array_map('intval', $plan_ids));
        $activities = $pdo->query("
            SELECT id, study_plan_id, activity_uid, activity_date, day_number
            FROM study_plan_activities
            WHERE study_plan_id IN ($in_plan_ids) AND is_deleted = 0
        ")->fetchAll(PDO::FETCH_ASSOC);

        $activities_by_plan = [];
        foreach ($activities as $act) {
            $activities_by_plan[$act['study_plan_id']][] = $act;
        }

        // Fetch all completion logs for these students for these plans
        $email_placeholders = implode(',', array_fill(0, count($student_emails), '?'));
        $stmt_logs = $pdo->prepare("
            SELECT an.id, an.student_email, an.study_plan_id, an.activity_id, an.activity_uid, an.completion_status, an.created_at
            FROM study_plan_analytics an
            JOIN study_plan_activities act ON (
                (an.activity_uid = act.activity_uid AND act.activity_uid IS NOT NULL AND act.activity_uid != '')
                OR (an.activity_id = act.id AND (an.activity_uid IS NULL OR an.activity_uid = '' OR act.activity_uid IS NULL OR act.activity_uid = ''))
            )
            WHERE LOWER(an.student_email) IN ($email_placeholders)
              AND an.study_plan_id IN ($in_plan_ids)
              AND an.action_type = 'complete_activity'
              AND act.is_deleted = 0
            ORDER BY an.id ASC
        ");
        $stmt_logs->execute($student_emails);
        $all_logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

        $logs_by_student = [];
        foreach ($all_logs as $log) {
            $semail = strtolower($log['student_email']);
            $logs_by_student[$semail][] = $log;
        }

        // Fetch all assessment results in bulk for these students
        $id_clause = !empty($student_ids) ? "ar.user_id IN (" . implode(',', array_fill(0, count($student_ids), '?')) . ")" : "1=0";
        $email_clause = !empty($student_emails) ? "LOWER(ar.student_email) IN (" . implode(',', array_fill(0, count($student_emails), '?')) . ")" : "1=0";

        $sql_assessments = "
            SELECT ar.batch_id, ar.student_email, ar.user_id, ar.attendance_status, ar.score, ar.total_score,
                   arb.study_plan_id, arb.course_name, arb.academic_year
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE arb.status = 'published'
              AND (
                  (arb.study_plan_id > 0 AND arb.study_plan_id IN ($in_plan_ids))
                  OR (
                      (arb.study_plan_id IS NULL OR arb.study_plan_id = 0)
                      AND (
                          LOWER(TRIM(arb.course_name)) = LOWER(TRIM(?))
                          OR LOWER(TRIM(arb.course_name)) = 'all courses'
                          OR arb.course_name IS NULL
                          OR arb.course_name = ''
                      )
                  )
              )
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
              AND (
                  ($id_clause)
                  OR ($email_clause)
              )
        ";
        $params_assessments = array_merge([$course_name, $today], $student_ids, $student_emails);
        $stmt_assessments = $pdo->prepare($sql_assessments);
        $stmt_assessments->execute($params_assessments);
        $all_assessments = $stmt_assessments->fetchAll(PDO::FETCH_ASSOC);

        $assessments_by_student_id = [];
        $assessments_by_student_email = [];
        foreach ($all_assessments as $ass) {
            if (!empty($ass['user_id'])) {
                $assessments_by_student_id[$ass['user_id']][] = $ass;
            }
            if (!empty($ass['student_email'])) {
                $assessments_by_student_email[strtolower(trim($ass['student_email']))][] = $ass;
            }
        }

        // Calculate analytics for each student
        $results = [];
        foreach ($students as $s) {
            $email = strtolower(trim($s['email']));
            $user_id = trim($s['user_id']);
            $academic_year = trim($s['pepp_academic_year']);
            $course = trim($s['pepp_course']);

            $assigned_plan_ids = [];
            foreach ($plans_metadata as $pid => $meta) {
                if (strtolower($meta['academic_year']) !== strtolower($academic_year)) {
                    continue;
                }
                $assigned = false;
                foreach ($meta['assignments'] as $assign) {
                    if ($assign['assignment_type'] === 'all') {
                        $assigned = true;
                    } else if ($assign['assignment_type'] === 'course' && strtolower($assign['assigned_value']) === strtolower($course)) {
                        $assigned = true;
                    } else if ($assign['assignment_type'] === 'batch' && strtolower($assign['assigned_value']) === strtolower($academic_year)) {
                        $assigned = true;
                    } else if ($assign['assignment_type'] === 'student' && $assign['assigned_value'] === $user_id) {
                        $assigned = true;
                    }
                }
                if ($assigned) {
                    $assigned_plan_ids[] = $pid;
                }
            }

            if (empty($assigned_plan_ids)) {
                $results[$s['email']] = self::emptyAnalytics();
                continue;
            }

            $total_tasks = 0;
            $completed_tasks = 0;
            $overdue_tasks = 0;
            $total_plan_calendar_days = 0;

            $student_logs = [];
            if (isset($logs_by_student[$email])) {
                $assigned_pids_flipped = array_flip($assigned_plan_ids);
                foreach ($logs_by_student[$email] as $log) {
                    if (isset($assigned_pids_flipped[$log['study_plan_id']])) {
                        $student_logs[] = $log;
                    }
                }
            }

            $effective_completions = [];
            foreach ($student_logs as $log) {
                $key = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
                $effective_completions[$key] = $log['completion_status'];
            }

            $completed_map = [];
            foreach ($assigned_plan_ids as $pid) {
                if (isset($plans_metadata[$pid])) {
                    $total_plan_calendar_days += self::calculatePlanCalendarDays(
                        $plans_metadata[$pid]['start_date'] ?? null,
                        $plans_metadata[$pid]['end_date'] ?? null
                    );
                }
                $plan_activities = $activities_by_plan[$pid] ?? [];
                $total_tasks += count($plan_activities);
                foreach ($plan_activities as $act) {
                    $key = !empty($act['activity_uid']) ? $act['activity_uid'] : 'id_' . $act['id'];
                    if (isset($effective_completions[$key]) && $effective_completions[$key] === 'completed') {
                        $completed_tasks++;
                        $completed_map[$act['id']] = true;
                    } else {
                        if (!empty($act['activity_date']) && $act['activity_date'] < $today) {
                            $overdue_tasks++;
                        }
                    }
                }
            }

            $pending_tasks = max(0, $total_tasks - $completed_tasks);
            $completion_percentage = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

            $latest_completed_dates = [];
            foreach ($student_logs as $log) {
                $key = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
                if ($log['completion_status'] === 'completed') {
                    if (!empty($log['created_at'])) {
                        $latest_completed_dates[$key] = self::convertToKolkataDate($log['created_at']);
                    }
                } else if ($log['completion_status'] === 'cleared') {
                    unset($latest_completed_dates[$key]);
                }
            }
            $completed_dates = array_values(array_filter(array_unique(array_values($latest_completed_dates))));
            $streaks = self::calculateStreaksFromDates($completed_dates);

            $active_study_days = count($completed_dates);
            $consistency_percentage = $total_plan_calendar_days > 0 ? min(100, round(($active_study_days / $total_plan_calendar_days) * 100)) : 0;

            $matched_assessments = [];
            if ($user_id !== '' && isset($assessments_by_student_id[$user_id])) {
                foreach ($assessments_by_student_id[$user_id] as $ass) {
                    $matched_assessments[$ass['batch_id']] = $ass;
                }
            }
            if (isset($assessments_by_student_email[$email])) {
                foreach ($assessments_by_student_email[$email] as $ass) {
                    $matched_assessments[$ass['batch_id']] = $ass;
                }
            }
            $student_assessments = array_values($matched_assessments);

            $unique_att = [];
            $unique_perf = [];
            foreach ($student_assessments as $rec) {
                if (strtolower(trim($rec['academic_year'])) !== strtolower(trim($academic_year))) {
                    continue;
                }

                $matches_plan_or_course = false;
                if (!empty($rec['study_plan_id']) && (int)$rec['study_plan_id'] > 0) {
                    if (in_array((int)$rec['study_plan_id'], $assigned_plan_ids)) {
                        $matches_plan_or_course = true;
                    }
                } else if (strtolower(trim($rec['course_name'])) === strtolower(trim($course)) || strtolower(trim($rec['course_name'])) === 'all courses' || empty($rec['course_name'])) {
                    $matches_plan_or_course = true;
                }

                if (!$matches_plan_or_course) {
                    continue;
                }

                $unique_att[$rec['batch_id']] = $rec['attendance_status'];
                if ($rec['attendance_status'] === 'attended' && $rec['score'] !== null && $rec['total_score'] > 0) {
                    $score = (float)$rec['score'];
                    $total = (float)$rec['total_score'];
                    if ($score >= 0 && $score <= $total) {
                        $unique_perf[$rec['batch_id']] = ($score / $total) * 100;
                    }
                }
            }

            $attended_sessions = 0;
            $total_sessions = 0;
            foreach ($unique_att as $status) {
                if ($status === 'attended' || $status === 'not_attended') {
                    $total_sessions++;
                    if ($status === 'attended') {
                        $attended_sessions++;
                    }
                }
            }
            $attendance_rate = $total_sessions > 0 ? round(($attended_sessions / $total_sessions) * 100) : null;
            $performance_score = count($unique_perf) > 0 ? round(array_sum($unique_perf) / count($unique_perf)) : null;

            $performance_label = null;
            $performance_class = null;
            if ($performance_score !== null) {
                $status_mapping = self::getPerformanceStatusMapping($performance_score);
                $performance_label = $status_mapping['label'];
                $performance_class = $status_mapping['class'];
            }

            $results[$s['email']] = [
                'total_tasks' => $total_tasks,
                'completed_tasks' => $completed_tasks,
                'pending_tasks' => $pending_tasks,
                'overdue_tasks' => $overdue_tasks,
                'completion_percentage' => $completion_percentage,
                'total_plan_calendar_days' => $total_plan_calendar_days,
                'eligible_plan_calendar_days' => $total_plan_calendar_days,

                'active_study_days' => $active_study_days,
                'consistency_percentage' => $consistency_percentage,

                'attended_sessions' => $attended_sessions,
                'total_sessions' => $total_sessions,
                'attendance_rate' => $attendance_rate,

                'performance_score' => $performance_score,
                'performance_label' => $performance_label,
                'performance_class' => $performance_class,

                'active_streak' => $streaks['current'],
                'longest_streak' => $streaks['longest'],

                'first_activity' => !empty($completed_dates) ? min($completed_dates) : null,
                'last_activity' => !empty($completed_dates) ? max($completed_dates) : null
            ];
        }

        return $results;
    }
}
