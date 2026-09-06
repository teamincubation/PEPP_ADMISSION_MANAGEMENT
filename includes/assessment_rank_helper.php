<?php
/**
 * PEPP Admissions - Assessment Ranking & Canonical Result Helper
 * 
 * Provides unified canonical student resolution, result ranking,
 * display profile metadata selection, and not-attended filtering across:
 * - download-rank-list-pdf.php
 * - assessment-results.php (get_merged_results)
 * - cards-result-designer.php
 */

class AssessmentRankHelper {
    /**
     * Disjoint-Set Find with path compression.
     */
    private static function findRoot(array &$parent, string $item): string {
        if (!isset($parent[$item])) {
            $parent[$item] = $item;
            return $item;
        }
        if ($parent[$item] !== $item) {
            $parent[$item] = self::findRoot($parent, $parent[$item]);
        }
        return $parent[$item];
    }

    /**
     * Disjoint-Set Union with deterministic root selection.
     */
    private static function unionSets(array &$parent, string $a, string $b): void {
        $rootA = self::findRoot($parent, $a);
        $rootB = self::findRoot($parent, $b);
        if ($rootA !== $rootB) {
            if (strcmp($rootA, $rootB) < 0) {
                $parent[$rootB] = $rootA;
            } else {
                $parent[$rootA] = $rootB;
            }
        }
    }

    /**
     * Check if a photo path exists on disk.
     */
    public static function photoExists(?string $rawPath, string $baseDir = ''): bool {
        if (empty($rawPath)) return false;
        if (empty($baseDir)) {
            $baseDir = dirname(__DIR__);
        }
        if (file_exists($rawPath) && is_file($rawPath)) return true;
        $p1 = rtrim($baseDir, '/\\') . '/' . ltrim($rawPath, '/\\');
        if (file_exists($p1) && is_file($p1)) return true;
        $p2 = dirname($baseDir) . '/' . ltrim($rawPath, '/\\');
        if (file_exists($p2) && is_file($p2)) return true;
        return false;
    }

    /**
     * Resolve canonical test rankings for a set of published batches.
     *
     * @param PDO $pdo Active database connection
     * @param array $batch_ids List of batch IDs for the test
     * @param string $target_academic_year Academic year (e.g. '2026-27')
     * @param string $baseDir Base directory for checking photo existence
     * @return array Array with keys:
     *               'ranked_list' => array of deduplicated, ranked attended students
     *               'canonical_attended_keys' => set of canonical keys that attended
     *               'union_find_parents' => union-find parent array
     */
    public static function getCanonicalTestResults(PDO $pdo, array $batch_ids, string $target_academic_year = '', string $baseDir = ''): array {
        if (empty($batch_ids)) {
            return [
                'ranked_list' => [],
                'canonical_attended_keys' => [],
                'union_find_parents' => []
            ];
        }

        if (empty($baseDir)) {
            $baseDir = dirname(__DIR__);
        }

        $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));

        // 1. Fetch raw assessment_results directly WITHOUT joining users table to avoid Cartesian products
        $stmt_res = $pdo->prepare("
            SELECT ar.id, ar.batch_id, ar.student_email, ar.user_id, ar.score, ar.total_score,
                   ar.attendance_status, ar.src_name, ar.src_attempt
            FROM assessment_results ar
            WHERE ar.batch_id IN ($placeholders)
            ORDER BY ar.id ASC
        ");
        $stmt_res->execute(array_values($batch_ids));
        $raw_results = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

        // 2. Collect identity tokens from assessment results
        $parent = [];
        $search_uids = [];
        $search_emails = [];

        foreach ($raw_results as $r) {
            $raw_email = trim($r['student_email'] ?? '');
            $raw_uid   = trim($r['user_id'] ?? '');

            $emailToken = !empty($raw_email) ? 'email:' . strtolower($raw_email) : null;
            $uidToken   = !empty($raw_uid) ? 'uid:' . $raw_uid : null;

            if ($emailToken && $uidToken) {
                self::unionSets($parent, $uidToken, $emailToken);
                $search_emails[strtolower($raw_email)] = true;
                $search_uids[$raw_uid] = true;
            } elseif ($emailToken) {
                self::findRoot($parent, $emailToken);
                $search_emails[strtolower($raw_email)] = true;
            } elseif ($uidToken) {
                self::findRoot($parent, $uidToken);
                $search_uids[$raw_uid] = true;
            }
        }

        // 3. Fetch candidate user profiles from users table
        $user_profiles = [];
        $where_clauses = [];
        $params = [];

        if (!empty($search_uids)) {
            $uList = array_keys($search_uids);
            $ph_u = implode(',', array_fill(0, count($uList), '?'));
            $where_clauses[] = "u.user_id IN ($ph_u)";
            $params = array_merge($params, $uList);
        }

        if (!empty($search_emails)) {
            $eList = array_keys($search_emails);
            $ph_e = implode(',', array_fill(0, count($eList), '?'));
            $where_clauses[] = "LOWER(u.email) IN ($ph_e)";
            $params = array_merge($params, $eList);
        }

        if (!empty($where_clauses)) {
            $sql_users = "
                SELECT u.user_id, u.name, u.email, u.college_school, u.pepp_course AS course_name,
                       u.pepp_academic_year, u.status, u.student_status, u.user_photo
                FROM users u
                WHERE (" . implode(' OR ', $where_clauses) . ")
            ";
            $stmt_u = $pdo->prepare($sql_users);
            $stmt_u->execute($params);
            $user_profiles = $stmt_u->fetchAll(PDO::FETCH_ASSOC);

            // 4. Union identity tokens from users table to support transitive linking
            foreach ($user_profiles as $u) {
                $u_email = trim($u['email'] ?? '');
                $u_uid   = trim($u['user_id'] ?? '');

                $emailToken = !empty($u_email) ? 'email:' . strtolower($u_email) : null;
                $uidToken   = !empty($u_uid) ? 'uid:' . $u_uid : null;

                if ($emailToken && $uidToken) {
                    self::unionSets($parent, $uidToken, $emailToken);
                }
            }
        }

        // 5. Result Selection (strictly from assessment_results)
        // Group results by canonical root
        $canonical_results = [];
        foreach ($raw_results as $r) {
            if (($r['attendance_status'] ?? '') !== 'attended' || $r['score'] === null) {
                continue;
            }

            $key = null;
            if (!empty($r['user_id'])) {
                $key = self::findRoot($parent, 'uid:' . trim($r['user_id']));
            } elseif (!empty($r['student_email'])) {
                $key = self::findRoot($parent, 'email:' . strtolower(trim($r['student_email'])));
            }
            if (!$key) continue;

            if (!isset($canonical_results[$key])) {
                $canonical_results[$key] = $r;
            } else {
                // If multiple batches exist for the same student, preserve the legitimate result
                // (if scores match, keep existing; if different batches, retain consistent result)
                if ($r['score'] > $canonical_results[$key]['score']) {
                    $canonical_results[$key] = $r;
                }
            }
        }

        // 6. Display Profile Selection (separated from result selection)
        // Group candidate profiles by canonical root
        $canonical_profiles = [];
        foreach ($user_profiles as $u) {
            $key = null;
            if (!empty($u['user_id'])) {
                $key = self::findRoot($parent, 'uid:' . trim($u['user_id']));
            } elseif (!empty($u['email'])) {
                $key = self::findRoot($parent, 'email:' . strtolower(trim($u['email'])));
            }
            if ($key) {
                $canonical_profiles[$key][] = $u;
            }
        }

        // 7. Combine Result with Best Profile Metadata
        $ranked_list = [];
        $canonical_attended_keys = [];

        foreach ($canonical_results as $canonKey => $res) {
            $canonical_attended_keys[$canonKey] = true;
            $profiles = $canonical_profiles[$canonKey] ?? [];

            // Sort candidate profiles by display preference:
            // 1. Matching target academic year
            // 2. Approved status
            // 3. Non-empty photo on disk
            // 4. Active / completed student status
            // 5. Deterministic fallback (lexicographical user_id DESC)
            usort($profiles, function($a, $b) use ($target_academic_year, $baseDir) {
                $aYear = (!empty($target_academic_year) && ($a['pepp_academic_year'] ?? '') === $target_academic_year) ? 1 : 0;
                $bYear = (!empty($target_academic_year) && ($b['pepp_academic_year'] ?? '') === $target_academic_year) ? 1 : 0;
                if ($aYear !== $bYear) return $bYear <=> $aYear;

                $aApp = (($a['status'] ?? '') === 'approved') ? 1 : 0;
                $bApp = (($b['status'] ?? '') === 'approved') ? 1 : 0;
                if ($aApp !== $bApp) return $bApp <=> $aApp;

                $aPhoto = self::photoExists($a['user_photo'] ?? '', $baseDir) ? 1 : 0;
                $bPhoto = self::photoExists($b['user_photo'] ?? '', $baseDir) ? 1 : 0;
                if ($aPhoto !== $bPhoto) return $bPhoto <=> $aPhoto;

                $aAct = in_array($a['student_status'] ?? '', ['active', 'completed']) ? 1 : 0;
                $bAct = in_array($b['student_status'] ?? '', ['active', 'completed']) ? 1 : 0;
                if ($aAct !== $bAct) return $bAct <=> $aAct;

                return strcmp($b['user_id'] ?? '', $a['user_id'] ?? '');
            });

            $bestProfile = $profiles[0] ?? [];

            $displayName = !empty($bestProfile['name']) ? $bestProfile['name'] : (!empty($res['src_name']) ? $res['src_name'] : 'Unknown Student');
            $displayCollege = !empty($bestProfile['college_school']) ? $bestProfile['college_school'] : '-';
            $displayCourse = !empty($bestProfile['course_name']) ? $bestProfile['course_name'] : '-';
            $displayPhoto = !empty($bestProfile['user_photo']) ? $bestProfile['user_photo'] : null;
            $displayUid = !empty($bestProfile['user_id']) ? $bestProfile['user_id'] : ($res['user_id'] ?? '');
            $displayEmail = !empty($res['student_email']) ? $res['student_email'] : ($bestProfile['email'] ?? '');

            $ranked_list[] = [
                'canonical_key'    => $canonKey,
                'student_email'    => $displayEmail,
                'user_id'          => $displayUid,
                'name'             => $displayName,
                'score'            => (float)$res['score'],
                'total_score'      => isset($res['total_score']) ? (float)$res['total_score'] : null,
                'attendance_status'=> $res['attendance_status'],
                'college_school'   => $displayCollege,
                'course_name'      => $displayCourse,
                'user_photo'       => $displayPhoto,
                'src_attempt'      => $res['src_attempt'] ?? null
            ];
        }

        // 8. Sort Globally by Score DESC
        usort($ranked_list, function($a, $b) {
            return ($b['score'] <=> $a['score']);
        });

        // 9. Standard Competition Ranking (1224 order)
        $count = 0;
        $rank = 0;
        $prev_score = null;
        foreach ($ranked_list as &$item) {
            $count++;
            if ($item['score'] !== $prev_score) {
                $rank = $count;
            }
            $item['computed_rank'] = $rank;
            $prev_score = $item['score'];
        }
        unset($item);

        return [
            'ranked_list' => $ranked_list,
            'canonical_attended_keys' => $canonical_attended_keys,
            'union_find_parents' => $parent
        ];
    }

    /**
     * Resolve eligible Not-Attended students for a Study Plan.
     *
     * @param PDO $pdo Active database connection
     * @param int $plan_id Study Plan ID
     * @param string $target_academic_year Academic Year (e.g. '2026-27')
     * @param array $canonical_attended_keys Set of canonical keys that already attended
     * @param array $union_find_parents Active union-find parents map
     * @param array $fallback_courses Fallback course names if no assignments exist
     * @return array List of Not-Attended students sorted alphabetically by name
     */
    public static function getNotAttendedStudents(
        PDO $pdo,
        int $plan_id,
        string $target_academic_year,
        array $canonical_attended_keys,
        array &$union_find_parents,
        array $fallback_courses = []
    ): array {
        $eligible_courses = [];

        try {
            $stmt_assign = $pdo->prepare("
                SELECT assignment_type, assigned_value
                FROM study_plan_assignments
                WHERE study_plan_id = ?
            ");
            $stmt_assign->execute([$plan_id]);
            $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);

            $is_all = false;
            $assigned_names = [];
            foreach ($assignments as $asg) {
                if ($asg['assignment_type'] === 'all') {
                    $is_all = true;
                    break;
                } elseif ($asg['assignment_type'] === 'course') {
                    $assigned_names[] = $asg['assigned_value'];
                }
            }

            if ($is_all) {
                $stmt_c = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active'");
                $stmt_c->execute([$target_academic_year]);
                $eligible_courses = $stmt_c->fetchAll(PDO::FETCH_COLUMN);
            } elseif (!empty($assigned_names)) {
                $ph_c = implode(',', array_fill(0, count($assigned_names), '?'));
                $stmt_c = $pdo->prepare("
                    SELECT course_name FROM pepp_courses
                    WHERE academic_year = ? AND status = 'active' AND course_name IN ($ph_c)
                ");
                $stmt_c->execute(array_merge([$target_academic_year], $assigned_names));
                $eligible_courses = $stmt_c->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Exception $e) {}

        if (empty($eligible_courses) && !empty($fallback_courses)) {
            $eligible_courses = $fallback_courses;
        }

        if (empty($eligible_courses)) {
            return [];
        }

        $ph_courses = implode(',', array_fill(0, count($eligible_courses), '?'));
        $stmt_stud = $pdo->prepare("
            SELECT user_id, name, email, college_school, pepp_course AS course_name, user_photo
            FROM users
            WHERE status = 'approved'
              AND student_status IN ('active', 'completed')
              AND pepp_academic_year = ?
              AND LOWER(TRIM(pepp_course)) IN ($ph_courses)
        ");
        $stmt_stud->execute(array_merge(
            [$target_academic_year],
            array_map(fn($c) => strtolower(trim($c)), $eligible_courses)
        ));
        $all_eligible = $stmt_stud->fetchAll(PDO::FETCH_ASSOC);

        $not_attended_list = [];
        $seen_not_attended_keys = [];

        foreach ($all_eligible as $student) {
            $uidToken = !empty($student['user_id']) ? 'uid:' . trim($student['user_id']) : null;
            $emailToken = !empty($student['email']) ? 'email:' . strtolower(trim($student['email'])) : null;

            if ($uidToken && $emailToken) {
                self::unionSets($union_find_parents, $uidToken, $emailToken);
            }

            $canonKey = null;
            if ($uidToken) {
                $canonKey = self::findRoot($union_find_parents, $uidToken);
            } elseif ($emailToken) {
                $canonKey = self::findRoot($union_find_parents, $emailToken);
            }

            if (!$canonKey) continue;

            // Check if this student attended under any alias/profile
            if (isset($canonical_attended_keys[$canonKey])) {
                continue;
            }

            // Avoid duplicate entries in not_attended
            if (isset($seen_not_attended_keys[$canonKey])) {
                continue;
            }
            $seen_not_attended_keys[$canonKey] = true;

            $student['canonical_key'] = $canonKey;
            $student['student_email'] = $student['email'] ?? '';
            $student['score'] = null;
            $student['computed_rank'] = 'Not Attended';
            $not_attended_list[] = $student;
        }

        // Sort alphabetically by student name (trimmed, case-insensitive)
        usort($not_attended_list, function($a, $b) {
            return strcasecmp(trim($a['name'] ?? ''), trim($b['name'] ?? ''));
        });

        return $not_attended_list;
    }
}
