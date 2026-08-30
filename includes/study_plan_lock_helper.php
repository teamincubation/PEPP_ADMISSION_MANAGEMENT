<?php
/**
 * PEPP Study Plan Designer — Strict Server-Side Single-Admin Edit Lock Helper
 * 
 * Provides atomic, race-condition-free lock acquisition, heartbeat renewal,
 * safe stale-lock reclaiming, and release mechanisms.
 */

declare(strict_types=1);

if (!defined('STUDY_PLAN_LOCK_TIMEOUT_SECONDS')) {
    define('STUDY_PLAN_LOCK_TIMEOUT_SECONDS', 120); // 2 minutes stale timeout
}

/**
 * Resolves current admin's identity from session and database
 */
function get_current_admin_identity(PDO $pdo): array {
    $admin_username = $_SESSION['admin_username'] ?? 'Admin';
    $admin_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $session_token = $_SESSION['session_ref'] ?? (session_id() ?: ('sess_' . md5($admin_username)));
    $admin_name = $_SESSION['admin_name'] ?? '';

    if (empty($admin_name) && $admin_id) {
        try {
            $stmt = $pdo->prepare("SELECT full_name FROM admins WHERE id = ? LIMIT 1");
            $stmt->execute([$admin_id]);
            $fn = $stmt->fetchColumn();
            if ($fn) {
                $admin_name = $fn;
            }
        } catch (Exception $e) {}
    }

    if (empty($admin_name)) {
        $admin_name = $admin_username;
    }

    return [
        'admin_id' => $admin_id,
        'admin_username' => $admin_username,
        'admin_name' => $admin_name,
        'session_token' => $session_token
    ];
}

/**
 * Resolves safe staff profile photo or initials avatar from employees.admin_id
 */
function resolve_admin_photo_and_initials(PDO $pdo, ?int $admin_id, string $admin_username, string $admin_name): array {
    $photo_url = null;
    if ($admin_id && $admin_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT photo FROM employees WHERE admin_id = ? AND is_deleted = 0 LIMIT 1");
            $stmt->execute([$admin_id]);
            $photo = $stmt->fetchColumn();
            if ($photo && strpos($photo, '..') === false) {
                $photo_url = htmlspecialchars($photo, ENT_QUOTES, 'UTF-8');
            }
        } catch (Exception $e) {}
    }

    $displayName = !empty($admin_name) ? $admin_name : $admin_username;
    $initials = strtoupper(substr(trim($displayName) ?: 'A', 0, 1));

    return [
        'photo_url' => $photo_url,
        'initials'  => $initials,
        'name'      => $admin_name,
        'username'  => $admin_username
    ];
}

/**
 * Ensures the study_plan_edit_locks table exists (both MySQL and SQLite)
 */
function ensure_study_plan_edit_locks_table(PDO $pdo, bool $force = false): void {
    static $ensured = false;
    if ($ensured && !$force) return;

    // Never execute DDL statements inside an active transaction (in MySQL, DDL causes implicit commit)
    if ($pdo->inTransaction()) {
        return;
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS study_plan_edit_locks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    study_plan_id INTEGER NOT NULL UNIQUE,
                    admin_id INTEGER,
                    admin_username TEXT NOT NULL,
                    admin_name TEXT NOT NULL,
                    session_token TEXT NOT NULL,
                    locked_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    last_heartbeat_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    released_at TEXT,
                    is_active INTEGER DEFAULT 1
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `study_plan_edit_locks` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `study_plan_id` INT NOT NULL,
                    `admin_id` INT DEFAULT NULL,
                    `admin_username` VARCHAR(100) NOT NULL,
                    `admin_name` VARCHAR(150) NOT NULL,
                    `session_token` VARCHAR(100) NOT NULL,
                    `locked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `last_heartbeat_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `released_at` DATETIME DEFAULT NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    UNIQUE KEY `uniq_active_plan_lock` (`study_plan_id`),
                    INDEX `idx_spel_admin` (`admin_id`, `admin_username`),
                    INDEX `idx_spel_heartbeat` (`last_heartbeat_at`, `is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
        $ensured = true;
    } catch (Exception $e) {
        error_log("Failed to ensure study_plan_edit_locks table: " . $e->getMessage());
    }
}

/**
 * Atomically acquires or verifies edit lock for a study plan with FAIL-CLOSED guarantees
 */
function acquire_or_check_study_plan_lock(PDO $pdo, int $plan_id, array $admin_info, int $retry_count = 0): array {
    if ($plan_id <= 0) {
        return [
            'success' => true,
            'locked' => false,
            'is_owner' => true,
            'lock_available' => true,
            'lock_unavailable' => false,
            'is_new' => true
        ];
    }

    ensure_study_plan_edit_locks_table($pdo);

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $in_external_tx = $pdo->inTransaction();

    if (!$in_external_tx) {
        $pdo->beginTransaction();
    }

    try {
        if ($driver === 'mysql') {
            $query = "
                SELECT *, TIMESTAMPDIFF(SECOND, last_heartbeat_at, NOW()) AS heartbeat_age_seconds 
                FROM study_plan_edit_locks 
                WHERE study_plan_id = ? FOR UPDATE
            ";
        } else {
            $query = "
                SELECT *, CAST((strftime('%s', 'now') - strftime('%s', last_heartbeat_at)) AS INTEGER) AS heartbeat_age_seconds 
                FROM study_plan_edit_locks 
                WHERE study_plan_id = ?
            ";
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute([$plan_id]);
        $lock = $stmt->fetch(PDO::FETCH_ASSOC);

        $timeout = STUDY_PLAN_LOCK_TIMEOUT_SECONDS;

        if (!$lock) {
            // No lock exists -> Acquire exclusively
            $stmt_ins = $pdo->prepare("
                INSERT INTO study_plan_edit_locks 
                (study_plan_id, admin_id, admin_username, admin_name, session_token, locked_at, last_heartbeat_at, is_active) 
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)
            ");
            $stmt_ins->execute([
                $plan_id,
                $admin_info['admin_id'] ?? null,
                $admin_info['admin_username'] ?? 'Admin',
                $admin_info['admin_name'] ?? 'Admin',
                $admin_info['session_token'] ?? ''
            ]);

            log_study_plan_lock_audit($pdo, $plan_id, $admin_info['admin_username'] ?? 'Admin', 'study_plan_edit_lock_acquired', 'Acquired exclusive study plan edit lock');

            if (!$in_external_tx) {
                $pdo->commit();
            }
            return [
                'success' => true,
                'locked' => false,
                'is_owner' => true,
                'lock_available' => true,
                'lock_unavailable' => false
            ];
        }

        // Calculate age using database-computed diff (immune to PHP/MySQL timezone skew)
        $age = isset($lock['heartbeat_age_seconds']) && $lock['heartbeat_age_seconds'] !== null
            ? (int)$lock['heartbeat_age_seconds']
            : (time() - strtotime((string)$lock['last_heartbeat_at']));

        // Lock is stale if age > timeout, or age is negative (future clock anomaly), or is_active is 0, or last_heartbeat_at is empty
        $is_stale = ((int)$lock['is_active'] === 0 || $age > $timeout || $age < 0 || empty($lock['last_heartbeat_at']));

        // Check if current user is the owner (case-insensitive username or matching admin_id)
        $is_owner = false;
        if (!empty($admin_info['admin_username']) && !empty($lock['admin_username'])) {
            if (strcasecmp((string)$lock['admin_username'], (string)$admin_info['admin_username']) === 0) {
                $is_owner = true;
            }
        }
        if (!$is_owner && !empty($admin_info['admin_id']) && !empty($lock['admin_id'])) {
            if ((int)$admin_info['admin_id'] === (int)$lock['admin_id']) {
                $is_owner = true;
            }
        }

        if ($is_owner) {
            // Same admin -> Refresh heartbeat & session token
            $stmt_up = $pdo->prepare("
                UPDATE study_plan_edit_locks 
                SET session_token = ?, last_heartbeat_at = CURRENT_TIMESTAMP, is_active = 1, released_at = NULL 
                WHERE study_plan_id = ?
            ");
            $stmt_up->execute([$admin_info['session_token'] ?? '', $plan_id]);

            if (!$in_external_tx) {
                $pdo->commit();
            }
            return [
                'success' => true,
                'locked' => false,
                'is_owner' => true,
                'lock_available' => true,
                'lock_unavailable' => false
            ];
        }

        if ($is_stale) {
            // Stale lock from another admin -> Safely expire and acquire
            $stmt_up = $pdo->prepare("
                UPDATE study_plan_edit_locks 
                SET admin_id = ?, admin_username = ?, admin_name = ?, session_token = ?, locked_at = CURRENT_TIMESTAMP, last_heartbeat_at = CURRENT_TIMESTAMP, is_active = 1, released_at = NULL 
                WHERE study_plan_id = ?
            ");
            $stmt_up->execute([
                $admin_info['admin_id'] ?? null,
                $admin_info['admin_username'] ?? 'Admin',
                $admin_info['admin_name'] ?? 'Admin',
                $admin_info['session_token'] ?? '',
                $plan_id
            ]);

            log_study_plan_lock_audit($pdo, $plan_id, $admin_info['admin_username'] ?? 'Admin', 'study_plan_edit_lock_expired', "Previous lock by {$lock['admin_username']} expired; reacquired by {$admin_info['admin_username']}");

            if (!$in_external_tx) {
                $pdo->commit();
            }
            return [
                'success' => true,
                'locked' => false,
                'is_owner' => true,
                'lock_available' => true,
                'lock_unavailable' => false
            ];
        }

        // Fresh lock held by ANOTHER admin -> DENY EDIT ACCESS
        if (!$in_external_tx) {
            $pdo->commit();
        }

        $avatar_info = resolve_admin_photo_and_initials(
            $pdo,
            !empty($lock['admin_id']) ? (int)$lock['admin_id'] : 0,
            $lock['admin_username'],
            $lock['admin_name']
        );

        return [
            'success' => false,
            'locked' => true,
            'is_owner' => false,
            'lock_available' => true,
            'lock_unavailable' => false,
            'locked_by' => [
                'admin_id' => $lock['admin_id'],
                'admin_name' => $lock['admin_name'],
                'admin_username' => $lock['admin_username'],
                'locked_at' => $lock['locked_at'],
                'last_heartbeat_at' => $lock['last_heartbeat_at'],
                'photo_url' => $avatar_info['photo_url'],
                'initials' => $avatar_info['initials']
            ]
        ];
    } catch (Exception $e) {
        if (!$in_external_tx && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Exception $rbEx) {}
        }
        error_log("acquire_or_check_study_plan_lock error on plan #{$plan_id}: " . $e->getMessage());

        // Self-heal table and retry once
        if ($retry_count === 0) {
            try {
                ensure_study_plan_edit_locks_table($pdo, true);
                return acquire_or_check_study_plan_lock($pdo, $plan_id, $admin_info, 1);
            } catch (Exception $e2) {
                error_log("acquire_or_check_study_plan_lock retry failed: " . $e2->getMessage());
            }
        }

        // FAIL CLOSED: NEVER grant edit ownership if lock service is unavailable
        return [
            'success' => false,
            'locked' => false,
            'is_owner' => false,
            'lock_available' => false,
            'lock_unavailable' => true,
            'error_code' => 'EDIT_LOCK_UNAVAILABLE',
            'message' => 'Edit protection is temporarily unavailable. Please wait a moment and try again.'
        ];
    }
}

/**
 * Updates last_heartbeat_at for active lock owned by current admin
 */
function heartbeat_study_plan_lock(PDO $pdo, int $plan_id, string $admin_username, string $session_token, ?int $admin_id = null): array {
    if ($plan_id <= 0) {
        return ['success' => true];
    }

    try {
        ensure_study_plan_edit_locks_table($pdo);

        if ($admin_id && $admin_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE study_plan_edit_locks 
                SET last_heartbeat_at = CURRENT_TIMESTAMP 
                WHERE study_plan_id = ? AND (admin_username = ? OR admin_id = ? OR LOWER(admin_username) = LOWER(?)) AND is_active = 1
            ");
            $stmt->execute([$plan_id, $admin_username, $admin_id, strtolower($admin_username)]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE study_plan_edit_locks 
                SET last_heartbeat_at = CURRENT_TIMESTAMP 
                WHERE study_plan_id = ? AND (admin_username = ? OR LOWER(admin_username) = LOWER(?)) AND is_active = 1
            ");
            $stmt->execute([$plan_id, $admin_username, strtolower($admin_username)]);
        }

        if ($stmt->rowCount() > 0) {
            return ['success' => true];
        }

        // Lock was not found for this admin / plan -> lost or overtaken
        return [
            'success' => false,
            'lock_lost' => true,
            'message' => 'Edit lock has expired or been released.'
        ];
    } catch (Exception $e) {
        error_log("heartbeat_study_plan_lock error on plan #{$plan_id}: " . $e->getMessage());
        return [
            'success' => false,
            'lock_lost' => false,
            'lock_unavailable' => true,
            'message' => 'Lock heartbeat temporarily unavailable.'
        ];
    }
}

/**
 * Releases edit lock when editor leaves, cancels, or exits edit mode
 */
function release_study_plan_lock(PDO $pdo, int $plan_id, string $admin_username, bool $is_super_admin = false, ?int $admin_id = null): array {
    if ($plan_id <= 0) {
        return ['success' => true];
    }

    try {
        ensure_study_plan_edit_locks_table($pdo);

        if ($is_super_admin) {
            $stmt = $pdo->prepare("DELETE FROM study_plan_edit_locks WHERE study_plan_id = ?");
            $stmt->execute([$plan_id]);
        } else {
            if ($admin_id && $admin_id > 0) {
                $stmt = $pdo->prepare("
                    DELETE FROM study_plan_edit_locks 
                    WHERE study_plan_id = ? AND (admin_username = ? OR admin_id = ? OR LOWER(admin_username) = LOWER(?))
                ");
                $stmt->execute([$plan_id, $admin_username, $admin_id, strtolower($admin_username)]);
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM study_plan_edit_locks 
                    WHERE study_plan_id = ? AND (admin_username = ? OR LOWER(admin_username) = LOWER(?))
                ");
                $stmt->execute([$plan_id, $admin_username, strtolower($admin_username)]);
            }
        }

        if ($stmt->rowCount() > 0) {
            log_study_plan_lock_audit($pdo, $plan_id, $admin_username, 'study_plan_edit_lock_released', 'Released study plan edit lock');
        }

        return ['success' => true];
    } catch (Exception $e) {
        error_log("release_study_plan_lock error on plan #{$plan_id}: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Strictly verifies whether the current admin owns the active edit lock (FAIL CLOSED)
 */
function verify_study_plan_edit_lock_permission(PDO $pdo, int $plan_id, string $admin_username, ?int $admin_id = null): bool {
    if ($plan_id <= 0) {
        return true;
    }

    try {
        ensure_study_plan_edit_locks_table($pdo);

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("
                SELECT *, TIMESTAMPDIFF(SECOND, last_heartbeat_at, NOW()) AS heartbeat_age_seconds 
                FROM study_plan_edit_locks 
                WHERE study_plan_id = ? AND is_active = 1
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT *, CAST((strftime('%s', 'now') - strftime('%s', last_heartbeat_at)) AS INTEGER) AS heartbeat_age_seconds 
                FROM study_plan_edit_locks 
                WHERE study_plan_id = ? AND is_active = 1
            ");
        }
        $stmt->execute([$plan_id]);
        $lock = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lock) {
            return true; // No active lock -> permitted
        }

        $age = isset($lock['heartbeat_age_seconds']) && $lock['heartbeat_age_seconds'] !== null
            ? (int)$lock['heartbeat_age_seconds']
            : (time() - strtotime((string)$lock['last_heartbeat_at']));

        if ($age > STUDY_PLAN_LOCK_TIMEOUT_SECONDS || $age < 0 || empty($lock['last_heartbeat_at'])) {
            return true; // Stale lock -> permitted
        }

        if (strcasecmp((string)$lock['admin_username'], (string)$admin_username) === 0) {
            return true;
        }
        if ($admin_id && !empty($lock['admin_id']) && (int)$admin_id === (int)$lock['admin_id']) {
            return true;
        }

        return false;
    } catch (Exception $e) {
        error_log("verify_study_plan_edit_lock_permission error on plan #{$plan_id}: " . $e->getMessage());
        // FAIL CLOSED: do NOT permit modification if lock status cannot be verified
        return false;
    }
}

/**
 * Logs meaningful lock state transitions to audit log without spamming heartbeats
 */
function log_study_plan_lock_audit(PDO $pdo, int $plan_id, string $admin_username, string $action, string $details): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO study_plan_audit_logs (study_plan_id, admin_username, action, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$plan_id, $admin_username, $action, $details]);
    } catch (Exception $e) {}
}
