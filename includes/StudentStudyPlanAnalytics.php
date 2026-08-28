<?php
/**
 * StudentStudyPlanAnalytics — Reusable canonical calculations for student progress,
 * consecutive day streaks, real assessment attendance, and assessment performance.
 */
class StudentStudyPlanAnalytics {

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

        // 2. Validate study plan assignment for security/data-scoping
        $stmt_val = $pdo->prepare("
            SELECT COUNT(*)
            FROM study_plan_assignments sa
            JOIN study_plans sp ON sa.study_plan_id = sp.id
            WHERE sp.id = ? AND sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0 AND (
                sa.assignment_type = 'all' OR
                (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'batch' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'student' AND sa.assigned_value = ?)
            )
        ");
        $stmt_val->execute([$study_plan_id, $user['pepp_course'], $user['pepp_academic_year'], $user_id]);
        if ((int)$stmt_val->fetchColumn() === 0) {
            return self::emptyAnalytics();
        }

        // Fetch all active study plan activities
        $stmt_act = $pdo->prepare("
            SELECT act.id, act.activity_uid, act.activity_date, act.day_number, sp.plan_type
            FROM study_plan_activities act
            JOIN study_plans sp ON act.study_plan_id = sp.id
            WHERE act.study_plan_id = ? AND act.is_deleted = 0 AND sp.is_deleted = 0
        ");
        $stmt_act->execute([$study_plan_id]);
        $activities = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

        $total_tasks = count($activities);
        if ($total_tasks === 0) {
            return self::emptyAnalytics();
        }

        $plan_type = $activities[0]['plan_type'] ?? 'date_wise';

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

        // Fetch real consecutive day streaks (limited to active tasks in this plan)
        $completed_dates = [];
        foreach ($all_logs as $log) {
            $key = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
            if (isset($effective_completions[$key]) && $effective_completions[$key] === 'completed') {
                if ($log['completion_status'] === 'completed' && !empty($log['created_at'])) {
                    $log_dt = new DateTimeImmutable($log['created_at'], new DateTimeZone('Asia/Kolkata'));
                    $completed_dates[] = $log_dt->format('Y-m-d');
                }
            }
        }

        $completed_dates = array_values(array_unique($completed_dates));
        $streaks = self::calculateStreaksFromDates($completed_dates);

        // Fetch real attendance from assessment results linked to this plan
        $stmt_att = $pdo->prepare("
            SELECT ar.batch_id, ar.attendance_status
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE (ar.user_id = ? OR (ar.user_id IS NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.study_plan_id = ? AND arb.status = 'published'
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_att->execute([$user_id, $email, $study_plan_id, $today]);
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

        // Fetch real performance score (average score on real assessments linked to this plan)
        $stmt_perf = $pdo->prepare("
            SELECT ar.batch_id, ar.score, ar.total_score
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE (ar.user_id = ? OR (ar.user_id IS NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.study_plan_id = ? AND arb.status = 'published'
              AND ar.attendance_status = 'attended' AND ar.score IS NOT NULL AND ar.total_score > 0
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_perf->execute([$user_id, $email, $study_plan_id, $today]);
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

        // Find all assigned plans for this student in this course
        $stmt_plans = $pdo->prepare("
            SELECT DISTINCT sp.id
            FROM study_plans sp
            JOIN study_plan_assignments sa ON sp.id = sa.study_plan_id
            WHERE sp.status = 'published' AND sp.is_deleted = 0 AND sa.is_deleted = 0 AND (
                sa.assignment_type = 'all' OR
                (sa.assignment_type = 'course' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'batch' AND LOWER(sa.assigned_value) = LOWER(?)) OR
                (sa.assignment_type = 'student' AND sa.assigned_value = ?)
            )
        ");
        $stmt_plans->execute([$user['pepp_course'], $user['pepp_academic_year'], $user_id]);
        $plan_ids = $stmt_plans->fetchAll(PDO::FETCH_COLUMN);

        if (empty($plan_ids)) {
            return self::emptyAnalytics();
        }

        $total_tasks = 0;
        $completed_tasks = 0;
        $overdue_tasks = 0;

        foreach ($plan_ids as $plan_id) {
            $plan_data = self::getPlanAnalytics($pdo, $email, $plan_id);
            $total_tasks += $plan_data['total_tasks'];
            $completed_tasks += $plan_data['completed_tasks'];
            $overdue_tasks += $plan_data['overdue_tasks'];
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

        $completed_dates = [];
        foreach ($all_logs as $log) {
            $key = !empty($log['activity_uid']) ? $log['activity_uid'] : 'id_' . $log['activity_id'];
            if (isset($effective_completions[$key]) && $effective_completions[$key] === 'completed') {
                if ($log['completion_status'] === 'completed' && !empty($log['created_at'])) {
                    $log_dt = new DateTimeImmutable($log['created_at'], new DateTimeZone('Asia/Kolkata'));
                    $completed_dates[] = $log_dt->format('Y-m-d');
                }
            }
        }

        $completed_dates = array_values(array_unique($completed_dates));
        $streaks = self::calculateStreaksFromDates($completed_dates);

        // Real attendance from assessment results linked to this course and academic year/batch
        $stmt_att = $pdo->prepare("
            SELECT ar.batch_id, ar.attendance_status
            FROM assessment_results ar
            JOIN assessment_result_batches arb ON ar.batch_id = arb.id
            WHERE (ar.user_id = ? OR (ar.user_id IS NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.status = 'published'
              AND LOWER(TRIM(arb.course_name)) = LOWER(TRIM(?))
              AND LOWER(TRIM(arb.academic_year)) = LOWER(TRIM(?))
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_att->execute([$user_id, $email, $course_name, $user['pepp_academic_year'], $today]);
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
            WHERE (ar.user_id = ? OR (ar.user_id IS NULL AND LOWER(ar.student_email) = LOWER(?)))
              AND arb.status = 'published'
              AND LOWER(TRIM(arb.course_name)) = LOWER(TRIM(?))
              AND LOWER(TRIM(arb.academic_year)) = LOWER(TRIM(?))
              AND ar.attendance_status = 'attended' AND ar.score IS NOT NULL AND ar.total_score > 0
              AND (arb.activity_date_snapshot IS NULL OR arb.activity_date_snapshot <= ?)
        ");
        $stmt_perf->execute([$user_id, $email, $course_name, $user['pepp_academic_year'], $today]);
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
}
