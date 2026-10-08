<?php
/**
 * PEPP Learning Admin - Side-Effect-Free Student Status Helpers.
 *
 * This file provides canonical student lifecycle status resolution without
 * triggering administrative session initialization, HTTP redirects, or auth guards.
 * Safe to include in webhooks, public APIs, background workers, and admin pages.
 */

if (!function_exists('get_student_status')) {
    /**
     * Canonical helper: Get student's lifecycle status.
     * Returns normalized string: 'active', 'suspended', 'inactive', 'dropout', 'completed', or 'unknown'.
     *
     * @param PDO|null $pdo
     * @param string|int|null $student_user_id_or_email
     * @return string
     */
    function get_student_status($pdo, $student_user_id_or_email): string {
        if (!$pdo || empty($student_user_id_or_email)) return 'unknown';
        try {
            $stmt = $pdo->prepare("SELECT student_status, status FROM users WHERE user_id = ? OR email = ? LIMIT 1");
            $stmt->execute([$student_user_id_or_email, $student_user_id_or_email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return 'unknown';
            if ($row['status'] !== 'approved') {
                return strtolower(trim((string)$row['status'])) ?: 'unknown';
            }
            $st = strtolower(trim((string)$row['student_status']));
            $valid_statuses = ['active', 'suspended', 'inactive', 'dropout', 'completed'];
            return in_array($st, $valid_statuses, true) ? $st : 'unknown';
        } catch (Exception $e) {
            error_log('get_student_status error: ' . $e->getMessage());
            return 'unknown';
        }
    }
}

if (!function_exists('is_student_active')) {
    /**
     * Canonical helper: Is the student strictly active?
     * Only students with status = 'approved' AND student_status = 'active' are active.
     *
     * @param PDO|null $pdo
     * @param string|int|null $student_user_id_or_email
     * @return bool
     */
    function is_student_active($pdo, $student_user_id_or_email): bool {
        return (get_student_status($pdo, $student_user_id_or_email) === 'active');
    }
}

if (!function_exists('get_student_status_reason')) {
    /**
     * Canonical helper: Retrieve the exact status reason stored in student_status_log.
     *
     * @param PDO|null $pdo
     * @param string|int|null $student_user_id_or_email
     * @param string|null $target_status
     * @return string|null
     */
    function get_student_status_reason($pdo, $student_user_id_or_email, $target_status = null): ?string {
        if (!$pdo || empty($student_user_id_or_email)) return null;
        try {
            $user_id = $student_user_id_or_email;
            if (strpos((string)$student_user_id_or_email, '@') !== false) {
                $stmt_u = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
                $stmt_u->execute([$student_user_id_or_email]);
                $resolved = $stmt_u->fetchColumn();
                if ($resolved) $user_id = $resolved;
            }

            if ($target_status !== null) {
                $stmt = $pdo->prepare("
                    SELECT reason FROM student_status_log
                    WHERE user_id = ? AND LOWER(new_status) = LOWER(?)
                    ORDER BY changed_at DESC, id DESC LIMIT 1
                ");
                $stmt->execute([$user_id, $target_status]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT reason FROM student_status_log
                    WHERE user_id = ?
                    ORDER BY changed_at DESC, id DESC LIMIT 1
                ");
                $stmt->execute([$user_id]);
            }
            $reason = $stmt->fetchColumn();
            return ($reason && trim((string)$reason) !== '') ? trim((string)$reason) : null;
        } catch (Exception $e) {
            error_log('get_student_status_reason error: ' . $e->getMessage());
            return null;
        }
    }
}
