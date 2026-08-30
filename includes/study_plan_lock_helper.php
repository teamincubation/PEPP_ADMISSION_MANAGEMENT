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
 * Ensures the study_plan_edit_locks table exists (with SQLite test fallback)
 */
function ensure_study_plan_edit_locks_table(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;

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
    }
    $ensured = true;
}

/**
 * Atomically acquires or verifies edit lock for a study plan
 */
function acquire_or_check_study_plan_lock(PDO $pdo, int $plan_id, array $admin_info): array {
    if ($plan_id <= 0) {
        return ['success' => true, 'locked' => false, 'is_owner' => true, 'is_new' => true];
    }

    ensure_study_plan_edit_locks_table($pdo);

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $in_external_tx = $pdo->inTransaction();

    if (!$in_external_tx) {
        $pdo->beginTransaction();
    }

    try {
        $query = "SELECT * FROM study_plan_edit_locks WHERE study_plan_id = ?" . ($driver === 'mysql' ? " FOR UPDATE" : "");
        $stmt = $pdo->prepare($query);
        $stmt->execute([$plan_id]);
        $lock = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
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
                $admin_info['admin_id'],
                $admin_info['admin_username'],
                $admin_info['admin_name'],
                $admin_info['session_token']
            ]);

            log_study_plan_lock_audit($pdo, $plan_id, $admin_info['admin_username'], 'study_plan_edit_lock_acquired', 'Acquired exclusive study plan edit lock');

            if (!$in_external_tx) {
                $pdo->commit();
            }
            return ['success' => true, 'locked' => false, 'is_owner' => true];
        }

        $last_hb = strtotime($lock['last_heartbeat_at']);
        $is_stale = ($last_hb === false || ($now - $last_hb) > $timeout || (int)$lock['is_active'] === 0);

        if ($lock['admin_username'] === $admin_info['admin_username']) {
            // Same admin -> Refresh heartbeat & session token
            $stmt_up = $pdo->prepare("
                UPDATE study_plan_edit_locks 
                SET session_token = ?, last_heartbeat_at = CURRENT_TIMESTAMP, is_active = 1, released_at = NULL 
                WHERE study_plan_id = ?
            ");
            $stmt_up->execute([$admin_info['session_token'], $plan_id]);

            if (!$in_external_tx) {
                $pdo->commit();
            }
            return ['success' => true, 'locked' => false, 'is_owner' => true];
        }

        if ($is_stale) {
            // Stale lock from another admin -> Safely expire and acquire
            $stmt_up = $pdo->prepare("
                UPDATE study_plan_edit_locks 
                SET admin_id = ?, admin_username = ?, admin_name = ?, session_token = ?, locked_at = CURRENT_TIMESTAMP, last_heartbeat_at = CURRENT_TIMESTAMP, is_active = 1, released_at = NULL 
                WHERE study_plan_id = ?
            ");
            $stmt_up->execute([
                $admin_info['admin_id'],
                $admin_info['admin_username'],
                $admin_info['admin_name'],
                $admin_info['session_token'],
                $plan_id
            ]);

            log_study_plan_lock_audit($pdo, $plan_id, $admin_info['admin_username'], 'study_plan_edit_lock_expired', "Previous lock by {$lock['admin_username']} expired; reacquired by {$admin_info['admin_username']}");

            if (!$in_external_tx) {
                $pdo->commit();
            }
            return ['success' => true, 'locked' => false, 'is_owner' => true];
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
            $pdo->rollBack();
        }
        return ['success' => false, 'locked' => true, 'is_owner' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Updates last_heartbeat_at for active lock owned by current admin
 */
function heartbeat_study_plan_lock(PDO $pdo, int $plan_id, string $admin_username, string $session_token): array {
    if ($plan_id <= 0) {
        return ['success' => true];
    }

    ensure_study_plan_edit_locks_table($pdo);

    $stmt = $pdo->prepare("
        UPDATE study_plan_edit_locks 
        SET last_heartbeat_at = CURRENT_TIMESTAMP 
        WHERE study_plan_id = ? AND admin_username = ? AND is_active = 1
    ");
    $stmt->execute([$plan_id, $admin_username]);

    if ($stmt->rowCount() > 0) {
        return ['success' => true];
    }

    // Lock was not found for this admin / plan -> lost or overtaken
    return [
        'success' => false,
        'lock_lost' => true,
        'message' => 'Edit lock has expired or been released.'
    ];
}

/**
 * Releases edit lock when editor leaves, cancels, or exits edit mode
 */
function release_study_plan_lock(PDO $pdo, int $plan_id, string $admin_username, bool $is_super_admin = false): array {
    if ($plan_id <= 0) {
        return ['success' => true];
    }

    ensure_study_plan_edit_locks_table($pdo);

    if ($is_super_admin) {
        $stmt = $pdo->prepare("DELETE FROM study_plan_edit_locks WHERE study_plan_id = ?");
        $stmt->execute([$plan_id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM study_plan_edit_locks WHERE study_plan_id = ? AND admin_username = ?");
        $stmt->execute([$plan_id, $admin_username]);
    }

    if ($stmt->rowCount() > 0) {
        log_study_plan_lock_audit($pdo, $plan_id, $admin_username, 'study_plan_edit_lock_released', 'Released study plan edit lock');
    }

    return ['success' => true];
}

/**
 * Strictly verifies whether the current admin owns the active edit lock
 */
function verify_study_plan_edit_lock_permission(PDO $pdo, int $plan_id, string $admin_username): bool {
    if ($plan_id <= 0) {
        return true;
    }

    ensure_study_plan_edit_locks_table($pdo);

    $stmt = $pdo->prepare("SELECT * FROM study_plan_edit_locks WHERE study_plan_id = ? AND is_active = 1");
    $stmt->execute([$plan_id]);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lock) {
        return true; // No active lock -> permitted
    }

    $last_hb = strtotime($lock['last_heartbeat_at']);
    if ($last_hb !== false && (time() - $last_hb) > STUDY_PLAN_LOCK_TIMEOUT_SECONDS) {
        return true; // Stale lock -> permitted
    }

    return ($lock['admin_username'] === $admin_username);
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
