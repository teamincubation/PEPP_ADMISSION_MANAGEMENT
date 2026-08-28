<?php
/**
 * StudentStudyPlanAnalytics — Reusable canonical calculations for student progress,
 * consecutive day streaks, real assessment attendance, and assessment performance.
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
     * Get analytics scoped strictly to a single Study Plan.
     */
    public static function getPlanAnalytics($pdo, $student_id_or_email, $study_plan_id) {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $today = $now->format('Y-m-d');

        // 1. Resolve student details (Strongest student identity rule)
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
        $stmt_val->execute([$study_plan_id, $user['pepp_academic_year'], $user['pepp_course'], $user['pepp_academic_year'], $user_id]);
        if ((int)$stmt_val->fetchColumn() === 0) {
            return self::emptyAnalytics();
        }

        // Fetch all active study plan activities
        $stmt_act = $pdo->prepare("
            SELECT act.id, act.activity_uid, act.activity_date, act.day_number, sp.plan_type, sp.start_date, sp.end_date
            FROM study_plan_activities act
            JOIN study_plans sp ON act.study_plan_id = sp.id
            WHERE act.study_plan_id = ? AND act.is_deleted = 0 AND sp.is_deleted = 0
        ");
        $stmt_act->execute([$study_plan_id]);
        $activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

        $total_tasks = count($activities);
        if ($total_tasks === 0) {
            $stmt_plan_info = $pdo->prepare("SELECT start_date, end_date FROM study_plans WHERE id = ?");
            $stmt_plan_info->execute([$study_plan_id]);
            $plan_info = $stmt_plan_info->fetch(PDO::FETCH_ASSOC);
            $total_days = $plan_info ? self::calculatePlanCalendarDays($plan_info['start_date'], $plan_info['end_date']) : 0;
            $res = self::emptyAnalytics();
            $res['total_plan_calendar_days'] = $total_days;
            return $res;
        }

        $plan_type = $activities[0]['plan_type'] ?? 'date_wise';
        $total_plan_calendar_days = self::calculatePlanCalendarDays($activities[0]['start_date'] ?? null, $activities[0]['end_date'] ?? null);

        // Fetch all completion logs (both completed and cleared) ordered by id ascending (chronological)
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

        $completion_percentage = round(($completed_tasks / $total_tasks) * 100);

        // Compile logs for streaks: only the latest completion date for each task (Phase 4 & 5 state machine)
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

        // Fetch real attendance from assessment results linked to this plan, isolated by academic year
        $stmt_att = $pdo->prepare("
            SELECT ar.batch_id, ar.attendance_status
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE ((ar.user_id IS NOT NULL AND ar.user_id = ?) OR (ar.student_email IS NOT NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.study_plan_id = ? AND arb.status = 'published'
              AND LOWER(TRIM(arb.academic_year)) = LOWER(TRIM(?))
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_att->execute([$user_id, $email, $study_plan_id, $user['pepp_academic_year'], $today]);
        $att_records = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

        // De-duplicate results by batch_id to prevent join multiplication problems
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

        // Fetch real performance score (average score on real assessments linked to this plan), isolated by academic year
        $stmt_perf = $pdo->prepare("
            SELECT ar.batch_id, ar.score, ar.total_score
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE ((ar.user_id IS NOT NULL AND ar.user_id = ?) OR (ar.student_email IS NOT NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.study_plan_id = ? AND arb.status = 'published'
              AND ar.attendance_status = 'attended' AND ar.score IS NOT NULL AND ar.total_score > 0
              AND LOWER(TRIM(arb.academic_year)) = LOWER(TRIM(?))
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_perf->execute([$user_id, $email, $study_plan_id, $user['pepp_academic_year'], $today]);
        $perf_records = $stmt_perf->fetchAll(PDO::FETCH_ASSOC);

        // De-duplicate scores by batch_id and handle invalid scores (negative or exceeding total)
        $unique_perf = [];
        foreach ($perf_records as $rec) {
            $score = (float)$rec['score'];
            $total = (float)$rec['total_score'];
            if ($score < 0 || $score > $total) {
                continue; // Skip invalid scores
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
            // Note: getPlanAnalytics already scopes by sp.academic_year internally
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

        // Compile logs for streaks: only the latest completion date for each task (Phase 4 & 5 state machine)
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

        // Real attendance from assessment results linked to this course and academic year/batch
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

        // De-duplicate by batch_id
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

        // Real performance from assessment results linked to the course and academic year
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

        // De-duplicate by batch_id and filter invalid scores
        $unique_perf = [];
        foreach ($perf_records as $rec) {
            $score = (float)$rec['score'];
            $total = (float)$rec['total_score'];
            if ($score < 0 || $score > $total) {
                continue; // Skip invalid scores
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
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'pending_tasks' => 0,
            'overdue_tasks' => 0,
            'completion_percentage' => 0,
            'total_plan_calendar_days' => 0,

            'attended_sessions' => 0,
            'total_sessions' => 0,
            'attendance_rate' => null,

            'performance_score' => null,
            'performance_label' => null,
            'performance_class' => null,

            'active_streak' => 0,
            'longest_streak' => 0,

            'first_activity' => null,
            'last_activity' => null
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

        // Group assessments by student user_id and email
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

            // Filter plan IDs assigned to this student matching academic year
            $assigned_plan_ids = [];
            foreach ($plans_metadata as $pid => $meta) {
                if (strtolower($meta['academic_year']) !== strtolower($academic_year)) {
                    continue; // Strict academic year isolation
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

            // Fetch completions logs of this student for assigned plans
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

            // Compile logs for streaks
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

            // Filter student's assessments matching the academic year
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

                // Check if assessment matches assigned plans or course
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
