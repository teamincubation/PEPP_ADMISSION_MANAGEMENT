<?php
/**
 * PEPP Learning — Student Course Access & Reactivation Helper
 *
 * Centralized business logic for evaluating student access validity,
 * automatic status reactivation upon payment approval / access extension,
 * and the Magic status-recovery tool.
 */

if (!function_exists('is_student_access_valid')) {
    /**
     * Evaluates whether a student's course access expiry date is currently valid.
     *
     * Rule:
     * - effective_access_date >= today: VALID (true)
     * - effective_access_date < today: EXPIRED (false)
     * - NULL or empty or unparseable: NOT VALID (false)
     *
     * @param string|null $course_duration_date Y-m-d or date string
     * @param string|null $today Optional reference date override (defaults to current server date in ERP timezone)
     * @return bool
     */
    function is_student_access_valid(?string $course_duration_date, ?string $today = null): bool {
        if ($course_duration_date === null) {
            return false;
        }
        $dateStr = trim($course_duration_date);
        if ($dateStr === '' || $dateStr === '0000-00-00') {
            return false;
        }
        $ts = strtotime($dateStr);
        if ($ts === false) {
            return false;
        }
        $accessDate = date('Y-m-d', $ts);
        $currentDate = $today ? date('Y-m-d', strtotime($today)) : date('Y-m-d');
        return ($accessDate >= $currentDate);
    }
}

if (!function_exists('get_effective_student_access_date')) {
    /**
     * Retrieves the canonical course access expiry date for a student.
     *
     * @param PDO $pdo
     * @param string $user_id
     * @return string|null
     */
    function get_effective_student_access_date($pdo, $user_id): ?string {
        if (!$pdo || empty($user_id)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT course_duration_date FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $date = $stmt->fetchColumn();
            return ($date && trim((string)$date) !== '') ? trim((string)$date) : null;
        } catch (Exception $e) {
            error_log('get_effective_student_access_date error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('reactivate_student_if_access_valid')) {
    /**
     * Evaluates a student's effective access date.
     * If currently suspended AND access date is today or future:
     * changes status to 'active' (and course_status to 'active') and writes audit logs.
     *
     * Must be called within the caller's active database transaction where applicable.
     *
     * @param PDO $pdo
     * @param string $user_id
     * @param string $admin_username
     * @param string $reason
     * @return bool True if student was reactivated; false otherwise
     */
    function reactivate_student_if_access_valid($pdo, $user_id, string $admin_username, string $reason): bool {
        if (!$pdo || empty($user_id)) {
            return false;
        }

        try {
            $isMysql = (strpos($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false);
            $lock = $isMysql ? ' FOR UPDATE' : '';

            $stmt = $pdo->prepare("
                SELECT user_id, name, student_status, course_status, course_duration_date
                FROM users
                WHERE user_id = ?
                LIMIT 1
                " . $lock
            );
            $stmt->execute([$user_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                return false;
            }

            $currentStatus = strtolower(trim((string)($student['student_status'] ?? '')));
            // ONLY reactivate if the student is currently suspended
            if ($currentStatus !== 'suspended') {
                return false;
            }

            $accessDate = $student['course_duration_date'] ?? null;
            if (!is_student_access_valid($accessDate)) {
                return false;
            }

            // Update status to active
            $upd = $pdo->prepare("
                UPDATE users
                SET student_status = 'active',
                    course_status = 'active',
                    updated_at = NOW()
                WHERE user_id = ?
            ");
            $upd->execute([$user_id]);

            // Audit logging through existing infrastructure
            if (function_exists('status_log')) {
                status_log($pdo, $user_id, 'suspended', 'active', $reason, $admin_username);
            }
            if (function_exists('track_record')) {
                track_record($pdo, $user_id, 'status_changed', "Student status: suspended → active ({$reason})", $admin_username);
            }

            return true;
        } catch (Exception $e) {
            error_log('reactivate_student_if_access_valid error: ' . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('get_eligible_magic_reactivation_students')) {
    /**
     * Returns all approved students currently suspended whose course access date is today or future.
     *
     * @param PDO $pdo
     * @return array
     */
    function get_eligible_magic_reactivation_students($pdo): array {
        if (!$pdo) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT user_id, name, email, pepp_course, course_duration_date, student_status,
                       whatsapp_country_code, whatsapp_number
                FROM users
                WHERE status = 'approved'
                  AND student_status = 'suspended'
                  AND course_duration_date IS NOT NULL
                  AND course_duration_date != ''
                  AND course_duration_date != '0000-00-00'
                  AND course_duration_date >= CURDATE()
                ORDER BY course_duration_date ASC, name ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log('get_eligible_magic_reactivation_students error: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('count_eligible_magic_reactivation_students')) {
    /**
     * Counts approved students currently suspended whose course access date is today or future.
     *
     * @param PDO $pdo
     * @return int
     */
    function count_eligible_magic_reactivation_students($pdo): int {
        if (!$pdo) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE status = 'approved'
                  AND student_status = 'suspended'
                  AND course_duration_date IS NOT NULL
                  AND course_duration_date != ''
                  AND course_duration_date != '0000-00-00'
                  AND course_duration_date >= CURDATE()
            ");
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('count_eligible_magic_reactivation_students error: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('bulk_reactivate_magic_students')) {
    /**
     * Bulk reactivates all eligible suspended students with valid access dates.
     * Re-queries and re-validates eligibility on the server inside a transaction.
     *
     * @param PDO $pdo
     * @param string $admin_username
     * @return array ['reactivated' => int, 'skipped' => int, 'errors' => int]
     */
    function bulk_reactivate_magic_students($pdo, string $admin_username): array {
        if (!$pdo) {
            return ['reactivated' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $isMysql = (strpos($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false);
        $lock = $isMysql ? ' FOR UPDATE' : '';

        $stmt = $pdo->prepare("
            SELECT user_id, name, student_status, course_duration_date
            FROM users
            WHERE status = 'approved'
              AND student_status = 'suspended'
              AND course_duration_date IS NOT NULL
              AND course_duration_date != ''
              AND course_duration_date != '0000-00-00'
              AND course_duration_date >= CURDATE()
            " . $lock
        );
        $stmt->execute();
        $eligible = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $reactivated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($eligible as $s) {
            try {
                // Re-validate row at execution time
                if ($s['student_status'] === 'suspended' && is_student_access_valid($s['course_duration_date'])) {
                    $upd = $pdo->prepare("
                        UPDATE users
                        SET student_status = 'active',
                            course_status = 'active',
                            updated_at = NOW()
                        WHERE user_id = ?
                    ");
                    $upd->execute([$s['user_id']]);

                    $reason = 'Magic reactivation — access date not expired';
                    if (function_exists('status_log')) {
                        status_log($pdo, $s['user_id'], 'suspended', 'active', $reason, $admin_username);
                    }
                    if (function_exists('track_record')) {
                        track_record($pdo, $s['user_id'], 'status_changed', "Student status: suspended → active ({$reason})", $admin_username);
                    }
                    $reactivated++;
                } else {
                    $skipped++;
                }
            } catch (Exception $rowEx) {
                error_log("Error reactivating student {$s['user_id']}: " . $rowEx->getMessage());
                $errors++;
            }
        }

        return [
            'reactivated' => $reactivated,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }
}
