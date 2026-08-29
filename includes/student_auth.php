<?php
/**
 * PEPP JOURNEY — Student Study Plan Authentication & Lifecycle Security
 *
 * Dedicated, independent student authentication system for PEPP JOURNEY:
 * - Email + Date of Birth (DOB) authentication against canonical `users.date_of_birth`
 * - Strict lifecycle security: users.status = 'approved' AND users.student_status = 'active'
 * - Strict Single-Active-Device Login: Only 1 active device per student account at a time
 * - Secure selector + hashed validator persistent remember-login tokens (`student_login_tokens`)
 * - Server-side Session Generation Tracking (`student_active_sessions`)
 * - Comprehensive Server-side Login & Device Security Audit (`student_login_audit`)
 * - Rate limiting and brute-force throttling (`student_login_attempts`)
 * - In-flight status & single-device revalidation on every protected page request and AJAX endpoint
 * - Complete isolation from admin authentication flow
 * - Strict PII and location privacy protection (no frontend leaks, no client GPS prompts)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

/**
 * Ensures all required student authentication and security audit tables exist.
 */
function ensure_student_security_tables($pdo): void {
    if (!$pdo || !($pdo instanceof PDO)) return;
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS student_login_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    student_user_id TEXT,
                    student_email TEXT NOT NULL,
                    selector TEXT NOT NULL UNIQUE,
                    token_hash TEXT NOT NULL,
                    session_id_ref TEXT,
                    created_at TEXT NOT NULL,
                    last_used_at TEXT,
                    expires_at TEXT NOT NULL,
                    revoked_at TEXT,
                    user_agent TEXT,
                    ip_address TEXT
                );

                CREATE TABLE IF NOT EXISTS student_active_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    student_user_id TEXT,
                    student_email TEXT NOT NULL UNIQUE,
                    active_session_id TEXT NOT NULL,
                    ip_address TEXT,
                    user_agent TEXT,
                    created_at TEXT NOT NULL,
                    last_activity_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS student_login_audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    student_user_id TEXT,
                    student_email TEXT NOT NULL,
                    login_timestamp TEXT NOT NULL,
                    ip_address TEXT,
                    approximate_location TEXT DEFAULT 'Unknown',
                    browser TEXT DEFAULT 'Unknown',
                    browser_version TEXT,
                    device_type TEXT DEFAULT 'Unknown',
                    operating_system TEXT DEFAULT 'Unknown',
                    os_version TEXT,
                    network_provider TEXT DEFAULT 'Unknown',
                    login_method TEXT NOT NULL,
                    session_id_ref TEXT,
                    status TEXT NOT NULL,
                    logout_timestamp TEXT,
                    forced_logout_reason TEXT,
                    revocation_timestamp TEXT,
                    created_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS student_login_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address TEXT NOT NULL,
                    student_email TEXT,
                    attempted_at TEXT NOT NULL
                );
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `student_login_tokens` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `student_user_id` VARCHAR(64) DEFAULT NULL,
                    `student_email` VARCHAR(191) NOT NULL,
                    `selector` VARCHAR(64) NOT NULL,
                    `token_hash` VARCHAR(255) NOT NULL,
                    `session_id_ref` VARCHAR(64) DEFAULT NULL,
                    `created_at` DATETIME NOT NULL,
                    `last_used_at` DATETIME DEFAULT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `revoked_at` DATETIME DEFAULT NULL,
                    `user_agent` VARCHAR(500) DEFAULT NULL,
                    `ip_address` VARCHAR(64) DEFAULT NULL,
                    UNIQUE KEY `idx_sp_token_selector` (`selector`),
                    KEY `idx_sp_token_email` (`student_email`),
                    KEY `idx_sp_token_user_id` (`student_user_id`),
                    KEY `idx_sp_token_session` (`session_id_ref`),
                    KEY `idx_sp_token_revoked` (`revoked_at`),
                    KEY `idx_sp_token_expires` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `student_active_sessions` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `student_user_id` VARCHAR(64) DEFAULT NULL,
                    `student_email` VARCHAR(191) NOT NULL,
                    `active_session_id` VARCHAR(64) NOT NULL,
                    `ip_address` VARCHAR(64) DEFAULT NULL,
                    `user_agent` VARCHAR(500) DEFAULT NULL,
                    `created_at` DATETIME NOT NULL,
                    `last_activity_at` DATETIME NOT NULL,
                    UNIQUE KEY `idx_sas_email` (`student_email`),
                    KEY `idx_sas_user_id` (`student_user_id`),
                    KEY `idx_sas_session_id` (`active_session_id`),
                    KEY `idx_sas_last_activity` (`last_activity_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `student_login_audit` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `student_user_id` VARCHAR(64) DEFAULT NULL,
                    `student_email` VARCHAR(191) NOT NULL,
                    `login_timestamp` DATETIME NOT NULL,
                    `ip_address` VARCHAR(64) DEFAULT NULL,
                    `approximate_location` VARCHAR(191) DEFAULT 'Unknown',
                    `browser` VARCHAR(100) DEFAULT 'Unknown',
                    `browser_version` VARCHAR(50) DEFAULT NULL,
                    `device_type` VARCHAR(50) DEFAULT 'Unknown',
                    `operating_system` VARCHAR(100) DEFAULT 'Unknown',
                    `os_version` VARCHAR(50) DEFAULT NULL,
                    `network_provider` VARCHAR(191) DEFAULT 'Unknown',
                    `login_method` VARCHAR(50) NOT NULL,
                    `session_id_ref` VARCHAR(64) DEFAULT NULL,
                    `status` VARCHAR(50) NOT NULL,
                    `logout_timestamp` DATETIME DEFAULT NULL,
                    `forced_logout_reason` VARCHAR(100) DEFAULT NULL,
                    `revocation_timestamp` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL,
                    KEY `idx_sla_email` (`student_email`),
                    KEY `idx_sla_user_id` (`student_user_id`),
                    KEY `idx_sla_session` (`session_id_ref`),
                    KEY `idx_sla_status` (`status`),
                    KEY `idx_sla_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `student_login_attempts` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `ip_address` VARCHAR(64) NOT NULL,
                    `student_email` VARCHAR(191) DEFAULT NULL,
                    `attempted_at` DATETIME NOT NULL,
                    KEY `idx_sla_ip_time` (`ip_address`, `attempted_at`),
                    KEY `idx_sla_email_time` (`student_email`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    } catch (Exception $e) {
        error_log('ensure_student_security_tables error: ' . $e->getMessage());
    }
}

/**
 * Backward compatibility alias.
 */
function ensure_student_login_tokens_table($pdo): void {
    ensure_student_security_tables($pdo);
}

/**
 * Normalizes user-inputted Date of Birth to standard YYYY-MM-DD format.
 * Supports HTML5 date (YYYY-MM-DD), common date formats (DD-MM-YYYY, DD/MM/YYYY, etc.).
 */
function normalize_date_input(?string $date_str): ?string {
    if ($date_str === null) return null;
    $date_str = trim($date_str);
    if ($date_str === '') return null;

    // Standard YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date_str, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
    }

    // DD-MM-YYYY, DD/MM/YYYY, DD.MM.YYYY
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $date_str, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
    }

    // Fallback: parse via strtotime
    $ts = strtotime($date_str);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

/**
 * Parses User-Agent header to extract device type, browser, and OS details for audit.
 */
function parse_user_agent_details(?string $ua): array {
    $ua = trim($ua ?? '');
    if ($ua === '') {
        return [
            'device_type' => 'Unknown',
            'browser' => 'Unknown',
            'browser_version' => null,
            'operating_system' => 'Unknown',
            'os_version' => null
        ];
    }

    // 1. Device Type
    $device_type = 'Desktop';
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
        $device_type = 'Tablet';
    } elseif (preg_match('/(mobi|iphone|ipod|blackberry|opera mini|iemobile|mobile)/i', $ua)) {
        $device_type = 'Mobile';
    }

    // 2. Operating System & Version
    $os = 'Unknown';
    $os_version = null;
    if (preg_match('/iPhone OS\s+([0-9_]+)/i', $ua, $m)) {
        $os = 'iOS';
        $os_version = str_replace('_', '.', $m[1]);
    } elseif (preg_match('/iPad;\s*CPU\s*OS\s+([0-9_]+)/i', $ua, $m)) {
        $os = 'iPadOS';
        $os_version = str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Android\s+([0-9.]+)/i', $ua, $m)) {
        $os = 'Android';
        $os_version = $m[1];
    } elseif (preg_match('/Windows NT\s+([0-9.]+)/i', $ua, $m)) {
        $os = 'Windows';
        $nt_map = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
        $os_version = $nt_map[$m[1]] ?? $m[1];
    } elseif (preg_match('/Macintosh;\s*Intel\s*Mac\s*OS\s*X\s*([0-9_]+)/i', $ua, $m)) {
        $os = 'macOS';
        $os_version = str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    } elseif (preg_match('/CrOS/i', $ua)) {
        $os = 'ChromeOS';
    }

    // 3. Browser & Version
    $browser = 'Unknown';
    $browser_version = null;
    if (preg_match('/Edg(?:e|A|iOS)?\/([0-9.]+)/i', $ua, $m)) {
        $browser = 'Edge';
        $browser_version = $m[1];
    } elseif (preg_match('/SamsungBrowser\/([0-9.]+)/i', $ua, $m)) {
        $browser = 'Samsung Internet';
        $browser_version = $m[1];
    } elseif (preg_match('/OPR\/([0-9.]+)/i', $ua, $m) || preg_match('/Opera\/([0-9.]+)/i', $ua, $m)) {
        $browser = 'Opera';
        $browser_version = $m[1];
    } elseif (preg_match('/Chrome\/([0-9.]+)/i', $ua, $m) && !preg_match('/Edg|OPR|SamsungBrowser/i', $ua)) {
        $browser = 'Chrome';
        $browser_version = $m[1];
    } elseif (preg_match('/Version\/([0-9.]+).*Safari/i', $ua, $m)) {
        $browser = 'Safari';
        $browser_version = $m[1];
    } elseif (preg_match('/Firefox\/([0-9.]+)/i', $ua, $m)) {
        $browser = 'Firefox';
        $browser_version = $m[1];
    }

    return [
        'device_type' => $device_type,
        'browser' => $browser,
        'browser_version' => $browser_version ? substr($browser_version, 0, 50) : null,
        'operating_system' => $os,
        'os_version' => $os_version ? substr($os_version, 0, 50) : null
    ];
}

/**
 * Derives approximate city/country level location from IP without GPS or blocking calls.
 */
function get_approximate_ip_location(?string $ip): string {
    $ip = trim($ip ?? '');
    if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
        return 'Localhost';
    }

    // Cloudflare / Reverse Proxy Country header
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        return strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY']));
    }
    if (!empty($_SERVER['HTTP_X_COUNTRY_CODE'])) {
        return strtoupper(trim($_SERVER['HTTP_X_COUNTRY_CODE']));
    }

    return 'Unknown';
}

/**
 * Returns network / provider information if available, or 'Unknown'.
 */
function get_network_provider_info(?string $ip): string {
    return 'Unknown';
}

// ── Rate Limiting & Throttling ───────────────────────────────────────

/**
 * Checks if login attempts for IP or email exceed security threshold in past 15 minutes.
 */
function is_student_login_throttled($pdo, string $ip, string $email): bool {
    if (!$pdo) return false;
    try {
        ensure_student_security_tables($pdo);
        $threshold_time = date('Y-m-d H:i:s', time() - 900); // 15 minutes

        // Check attempts from IP (max 10 in 15 mins)
        $stmt_ip = $pdo->prepare("
            SELECT COUNT(*) FROM student_login_attempts
            WHERE ip_address = ? AND attempted_at > ?
        ");
        $stmt_ip->execute([$ip, $threshold_time]);
        if ((int)$stmt_ip->fetchColumn() >= 10) {
            return true;
        }

        // Check attempts for email (max 5 in 15 mins)
        if (!empty($email)) {
            $stmt_em = $pdo->prepare("
                SELECT COUNT(*) FROM student_login_attempts
                WHERE LOWER(student_email) = LOWER(?) AND attempted_at > ?
            ");
            $stmt_em->execute([$email, $threshold_time]);
            if ((int)$stmt_em->fetchColumn() >= 5) {
                return true;
            }
        }

        return false;
    } catch (Exception $e) {
        error_log('is_student_login_throttled error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Records a login attempt or clears history on success.
 */
function record_student_login_attempt($pdo, string $ip, string $email, bool $success): void {
    if (!$pdo) return;
    try {
        ensure_student_security_tables($pdo);
        if ($success) {
            // Clear recent failed attempts for this email and IP
            $stmt_del = $pdo->prepare("
                DELETE FROM student_login_attempts
                WHERE ip_address = ? OR LOWER(student_email) = LOWER(?)
            ");
            $stmt_del->execute([$ip, $email]);
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO student_login_attempts (ip_address, student_email, attempted_at)
                VALUES (?, ?, ?)
            ");
            $stmt_ins->execute([$ip, $email, date('Y-m-d H:i:s')]);
        }
    } catch (Exception $e) {
        error_log('record_student_login_attempt error: ' . $e->getMessage());
    }
}

// ── Canonical Student Status Helpers ─────────────────────────────────

if (!function_exists('get_student_status')) {
    /**
     * Canonical helper: Get student's lifecycle status.
     * Returns normalized string: 'active', 'suspended', 'inactive', 'dropout', 'completed', or 'unknown'.
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
     */
    function is_student_active($pdo, $student_user_id_or_email): bool {
        return (get_student_status($pdo, $student_user_id_or_email) === 'active');
    }
}

if (!function_exists('get_student_status_reason')) {
    /**
     * Canonical helper: Retrieve the exact status reason stored in student_status_log.
     */
    function get_student_status_reason($pdo, $student_user_id_or_email, $target_status = null): ?string {
        if (!$pdo || empty($student_user_id_or_email)) return null;
        try {
            $user_id = $student_user_id_or_email;
            if (strpos($student_user_id_or_email, '@') !== false) {
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

if (!function_exists('can_student_access_study_plan')) {
    /**
     * Canonical helper: Can student access the study plan portal?
     * Enrolled students must be strictly approved and active.
     * Non-enrolled users must have a valid campaign form submission.
     */
    function can_student_access_study_plan($pdo, $student_user_id_or_email): bool {
        if (!$pdo || empty($student_user_id_or_email)) return false;
        $st_status = get_student_status($pdo, $student_user_id_or_email);
        if ($st_status !== 'unknown') {
            return ($st_status === 'active');
        }
        try {
            $has_campaign_tables = false;
            try {
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $has_campaign_tables = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='campaign_form_submissions'")->fetchColumn();
                } else {
                    $has_campaign_tables = (bool)$pdo->query("SHOW TABLES LIKE 'campaign_form_submissions'")->fetchColumn();
                }
            } catch (Exception $e) {}

            if ($has_campaign_tables) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM campaign_form_submissions s
                    LEFT JOIN campaign_form_answers a ON s.id = a.submission_id
                    WHERE (s.respondent_identifier = ? OR a.answer_text = ?) AND s.is_deleted = 0
                ");
                $stmt->execute([$student_user_id_or_email, $student_user_id_or_email]);
                return ($stmt->fetchColumn() > 0);
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('can_mentor_view_student')) {
    function can_mentor_view_student($pdo, $admin_id, $student_user_id): bool {
        if (!$pdo || empty($student_user_id) || empty($admin_id)) return false;
        $status = get_student_status($pdo, $student_user_id);
        if (in_array($status, ['dropout', 'completed', 'unknown'], true)) {
            return false;
        }
        try {
            $stmt_adm = $pdo->prepare("SELECT role FROM admins WHERE id = ? LIMIT 1");
            $stmt_adm->execute([$admin_id]);
            $role = $stmt_adm->fetchColumn();
            if ($role === 'super_admin') {
                return true;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM mentor_student_assignments
                WHERE student_user_id = ? AND admin_id = ? AND status = 'active'
            ");
            $stmt->execute([$student_user_id, $admin_id]);
            return ($stmt->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('can_send_academic_email')) {
    function can_send_academic_email($pdo, $student_user_id_or_email): bool {
        return is_student_active($pdo, $student_user_id_or_email);
    }
}

// ── Single-Device Session & Audit Management ─────────────────────────

/**
 * Logs a student login / authentication event to the server-side audit table.
 */
function log_student_login_audit($pdo, array $data): int {
    if (!$pdo) return 0;
    try {
        ensure_student_security_tables($pdo);

        $now = date('Y-m-d H:i:s');
        $ip = $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
        $ua_details = parse_user_agent_details($ua);
        $location = get_approximate_ip_location($ip);
        $network = get_network_provider_info($ip);

        $stmt = $pdo->prepare("
            INSERT INTO student_login_audit (
                student_user_id, student_email, login_timestamp, ip_address,
                approximate_location, browser, browser_version, device_type,
                operating_system, os_version, network_provider, login_method,
                session_id_ref, status, forced_logout_reason, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['student_user_id'] ?? null,
            $data['student_email'] ?? '',
            $now,
            $ip,
            $location,
            $ua_details['browser'],
            $ua_details['browser_version'],
            $ua_details['device_type'],
            $ua_details['operating_system'],
            $ua_details['os_version'],
            $network,
            $data['login_method'] ?? 'email_dob',
            $data['session_id_ref'] ?? null,
            $data['status'] ?? 'success',
            $data['forced_logout_reason'] ?? null,
            $now
        ]);

        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('log_student_login_audit error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Records logout or forced revocation in the audit log.
 */
function record_student_logout_audit($pdo, string $email, ?string $session_id = null, string $reason = 'manual_logout'): void {
    if (!$pdo || empty($email)) return;
    try {
        ensure_student_security_tables($pdo);
        $now = date('Y-m-d H:i:s');

        if (!empty($session_id)) {
            $stmt = $pdo->prepare("
                UPDATE student_login_audit
                SET logout_timestamp = ?, forced_logout_reason = ?, revocation_timestamp = ?
                WHERE session_id_ref = ? AND logout_timestamp IS NULL
            ");
            $stmt->execute([$now, $reason, $now, $session_id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE student_login_audit
                SET logout_timestamp = ?, forced_logout_reason = ?, revocation_timestamp = ?
                WHERE LOWER(student_email) = LOWER(?) AND logout_timestamp IS NULL
            ");
            $stmt->execute([$now, $reason, $now, $email]);
        }
    } catch (Exception $e) {
        error_log('record_student_logout_audit error: ' . $e->getMessage());
    }
}

/**
 * Generates a cryptographically secure active session ID for a student.
 * STRICT SINGLE-ACTIVE-DEVICE LOGIN:
 * 1. Generates new active session ID.
 * 2. Invalidates all previous active sessions and remember tokens for this student.
 * 3. Sets new active session server-side.
 */
function generate_student_active_session($pdo, ?string $user_id, string $email): string {
    ensure_student_security_tables($pdo);
    $active_session_id = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

    $in_tx = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $in_tx = true;
        }

        // 1. Record forced logout for previous session in audit log
        record_student_logout_audit($pdo, $email, null, 'single_device_conflict');

        // 2. Revoke ALL previous remember tokens for this student
        $stmt_tok = $pdo->prepare("
            UPDATE student_login_tokens
            SET revoked_at = ?
            WHERE LOWER(student_email) = LOWER(?) AND revoked_at IS NULL
        ");
        $stmt_tok->execute([$now, $email]);

        // 3. Upsert student active session
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt_upsert = $pdo->prepare("
                INSERT INTO student_active_sessions (student_user_id, student_email, active_session_id, ip_address, user_agent, created_at, last_activity_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(student_email) DO UPDATE SET
                    student_user_id = excluded.student_user_id,
                    active_session_id = excluded.active_session_id,
                    ip_address = excluded.ip_address,
                    user_agent = excluded.user_agent,
                    last_activity_at = excluded.last_activity_at
            ");
            $stmt_upsert->execute([$user_id, $email, $active_session_id, $ip, $ua, $now, $now]);
        } else {
            $stmt_upsert = $pdo->prepare("
                INSERT INTO student_active_sessions (student_user_id, student_email, active_session_id, ip_address, user_agent, created_at, last_activity_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    student_user_id = VALUES(student_user_id),
                    active_session_id = VALUES(active_session_id),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    last_activity_at = VALUES(last_activity_at)
            ");
            $stmt_upsert->execute([$user_id, $email, $active_session_id, $ip, $ua, $now, $now]);
        }

        if ($in_tx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        $_SESSION['sp_active_session_id'] = $active_session_id;
        return $active_session_id;
    } catch (Exception $e) {
        if ($in_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('generate_student_active_session error: ' . $e->getMessage());
        $_SESSION['sp_active_session_id'] = $active_session_id;
        return $active_session_id;
    }
}

/**
 * Verifies that the current session matches the single active device session in the database.
 */
function verify_student_active_session($pdo, string $email, ?string $session_id): bool {
    if (!$pdo || empty($email) || empty($session_id)) return false;
    try {
        ensure_student_security_tables($pdo);
        $stmt = $pdo->prepare("
            SELECT active_session_id FROM student_active_sessions
            WHERE LOWER(student_email) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $active_id = $stmt->fetchColumn();

        if (!$active_id) {
            return false;
        }

        if (!hash_equals((string)$active_id, (string)$session_id)) {
            return false;
        }

        // Update last activity timestamp
        $now = date('Y-m-d H:i:s');
        $stmt_act = $pdo->prepare("UPDATE student_active_sessions SET last_activity_at = ? WHERE LOWER(student_email) = LOWER(?)");
        $stmt_act->execute([$now, $email]);

        return true;
    } catch (Exception $e) {
        error_log('verify_student_active_session error: ' . $e->getMessage());
        return false;
    }
}

// ── Student Study Plan Authentication & Persistent Session ──────────

/**
 * Authenticates a student via Email and Date of Birth.
 * Enforces:
 * 1. Rate limiting / brute force protection.
 * 2. Exact match against users.date_of_birth.
 * 3. users.status = 'approved' AND users.student_status = 'active'.
 * 4. Strict Single-Device Session generation.
 * 5. Secure Audit Logging.
 */
function authenticate_student_by_credentials($pdo, string $email, string $dob_raw): array {
    $email = trim(strtolower($email));
    $dob_norm = normalize_date_input($dob_raw);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (empty($email) || empty($dob_norm)) {
        return [
            'success' => false,
            'message' => 'Please enter both your registered Email Address and Date of Birth.',
            'error_type' => 'validation'
        ];
    }

    // Rate Limiting Check
    if (is_student_login_throttled($pdo, $ip, $email)) {
        log_student_login_audit($pdo, [
            'student_email' => $email,
            'login_method' => 'email_dob',
            'status' => 'throttled',
            'forced_logout_reason' => 'rate_limited'
        ]);
        return [
            'success' => false,
            'message' => 'Too many failed login attempts. Please try again in a few minutes.',
            'error_type' => 'throttled'
        ];
    }

    try {
        // 1. Check Course Enrolled Students in users table
        $stmt = $pdo->prepare("
            SELECT * FROM users
            WHERE LOWER(email) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            // Verify Date of Birth
            $stored_dob = normalize_date_input($student['date_of_birth'] ?? '');
            if (!$stored_dob || $stored_dob !== $dob_norm) {
                record_student_login_attempt($pdo, $ip, $email, false);
                log_student_login_audit($pdo, [
                    'student_user_id' => $student['user_id'] ?? null,
                    'student_email' => $email,
                    'login_method' => 'email_dob',
                    'status' => 'failed',
                    'forced_logout_reason' => 'invalid_dob'
                ]);
                return [
                    'success' => false,
                    'message' => 'Invalid email address or date of birth. Please verify your registered details.',
                    'error_type' => 'credentials'
                ];
            }

            // Verify lifecycle status
            if ($student['status'] !== 'approved' || !is_student_active($pdo, $email)) {
                $st_status = get_student_status($pdo, $email);
                $reason = get_student_status_reason($pdo, $email, $st_status);
                log_student_login_audit($pdo, [
                    'student_user_id' => $student['user_id'] ?? null,
                    'student_email' => $email,
                    'login_method' => 'email_dob',
                    'status' => 'status_blocked',
                    'forced_logout_reason' => 'status_' . $st_status
                ]);
                return [
                    'success' => false,
                    'message' => "Your account is currently " . strtoupper($st_status) . ($reason ? " (Reason: {$reason})" : "") . ". Please contact PEPP support for assistance.",
                    'error_type' => 'status_blocked',
                    'student_status' => $st_status,
                    'status_reason' => $reason
                ];
            }

            // Authentication SUCCESS: generate single active session
            record_student_login_attempt($pdo, $ip, $email, true);
            $active_session_id = generate_student_active_session($pdo, $student['user_id'] ?? null, $email);

            // Audit log success
            log_student_login_audit($pdo, [
                'student_user_id' => $student['user_id'] ?? null,
                'student_email' => $email,
                'login_method' => 'email_dob',
                'session_id_ref' => $active_session_id,
                'status' => 'success'
            ]);

            return [
                'success' => true,
                'type' => 'user',
                'student' => $student,
                'active_session_id' => $active_session_id
            ];
        }

        // 2. Check Custom Campaign Form Submissions
        $has_campaign_tables = false;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $has_campaign_tables = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='campaign_form_submissions'")->fetchColumn()
                                    && (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='campaign_forms'")->fetchColumn();
            } else {
                $has_campaign_tables = (bool)$pdo->query("SHOW TABLES LIKE 'campaign_form_submissions'")->fetchColumn()
                                    && (bool)$pdo->query("SHOW TABLES LIKE 'campaign_forms'")->fetchColumn();
            }
        } catch (Exception $e) {}

        if ($has_campaign_tables) {
            $stmt_form = $pdo->prepare("
                SELECT DISTINCT s.*, f.title as form_title
                FROM campaign_form_submissions s
                JOIN campaign_forms f ON s.form_id = f.id
                LEFT JOIN campaign_form_answers a ON s.id = a.submission_id
                WHERE (LOWER(s.respondent_identifier) = LOWER(?) OR LOWER(a.answer_text) = LOWER(?)) AND s.is_deleted = 0
                LIMIT 1
            ");
            $stmt_form->execute([$email, $email]);
            $form_user = $stmt_form->fetch(PDO::FETCH_ASSOC);

            if ($form_user) {
                $name = $form_user['respondent_identifier'] ?: 'User';
                try {
                    $stmt_name = $pdo->prepare("
                        SELECT a.answer_text
                        FROM campaign_form_answers a
                        JOIN campaign_form_fields f ON a.field_id = f.id
                        WHERE a.submission_id = ? AND (f.label LIKE '%name%' OR f.field_name LIKE '%name%')
                        ORDER BY f.sort_order ASC
                        LIMIT 1
                    ");
                    $stmt_name->execute([$form_user['id']]);
                    $resolved = $stmt_name->fetchColumn();
                    if ($resolved) $name = $resolved;
                } catch (Exception $e) {}

                record_student_login_attempt($pdo, $ip, $email, true);
                $active_session_id = generate_student_active_session($pdo, null, $email);

                log_student_login_audit($pdo, [
                    'student_email' => $email,
                    'login_method' => 'email_dob',
                    'session_id_ref' => $active_session_id,
                    'status' => 'success'
                ]);

                return [
                    'success' => true,
                    'type' => 'campaign',
                    'student' => [
                        'name' => $name,
                        'email' => $email,
                        'user_id' => null,
                        'pepp_course' => null,
                        'pepp_academic_year' => null
                    ],
                    'active_session_id' => $active_session_id
                ];
            }
        }

        // Generic failure message (No PII leakage)
        record_student_login_attempt($pdo, $ip, $email, false);
        log_student_login_audit($pdo, [
            'student_email' => $email,
            'login_method' => 'email_dob',
            'status' => 'failed',
            'forced_logout_reason' => 'account_not_found'
        ]);

        return [
            'success' => false,
            'message' => 'Invalid email address or date of birth. Please verify your registered details.',
            'error_type' => 'not_found'
        ];
    } catch (Exception $e) {
        error_log('authenticate_student_by_credentials error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database verification error. Please try again later.',
            'error_type' => 'db_error'
        ];
    }
}

/**
 * Creates a cryptographically secure, persistent remember-login token for the student.
 * Stores only a SHA-256 hash in the database and sends selector:validator in an HttpOnly cookie.
 */
function create_student_persistent_login($pdo, ?string $user_id, string $email, ?string $session_id_ref = null): bool {
    if (!$pdo || empty($email)) return false;
    try {
        ensure_student_security_tables($pdo);

        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $validator);
        $expires_at = date('Y-m-d H:i:s', time() + (60 * 86400)); // 60 days
        $created_at = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

        if (empty($session_id_ref) && !empty($_SESSION['sp_active_session_id'])) {
            $session_id_ref = $_SESSION['sp_active_session_id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO student_login_tokens (student_user_id, student_email, selector, token_hash, session_id_ref, created_at, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $email, $selector, $token_hash, $session_id_ref, $created_at, $expires_at, $ip, $ua]);

        $cookie_value = $selector . ':' . $validator;
        $is_https = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1))
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $cookie_options = [
            'expires' => time() + (60 * 86400),
            'path' => '/',
            'secure' => $is_https,
            'httponly' => true,
            'samesite' => 'Lax'
        ];

        if (!headers_sent()) {
            @setcookie('pepp_sp_remember', $cookie_value, $cookie_options);
        }
        $_COOKIE['pepp_sp_remember'] = $cookie_value;

        return true;
    } catch (Exception $e) {
        error_log('create_student_persistent_login error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Attempts to authenticate the student from the persistent remember cookie.
 * Revalidates student database status, generates active session, and rotates the token.
 */
function authenticate_student_from_cookie($pdo): bool {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (isset($_SESSION['sp_logged_in']) && $_SESSION['sp_logged_in'] === true) {
        return true;
    }

    if (empty($_COOKIE['pepp_sp_remember'])) {
        return false;
    }

    $parts = explode(':', (string)$_COOKIE['pepp_sp_remember'], 2);
    if (count($parts) !== 2) {
        clear_student_cookie();
        return false;
    }

    list($selector, $validator) = $parts;
    if (empty($selector) || empty($validator)) {
        clear_student_cookie();
        return false;
    }

    try {
        ensure_student_security_tables($pdo);

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            SELECT * FROM student_login_tokens
            WHERE selector = ? AND revoked_at IS NULL AND expires_at > ?
            LIMIT 1
        ");
        $stmt->execute([$selector, $now]);
        $token_row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$token_row) {
            clear_student_cookie();
            return false;
        }

        $expected_hash = hash('sha256', $validator);
        if (!hash_equals($token_row['token_hash'], $expected_hash)) {
            // Potential token reuse / tampering detected: revoke token immediately
            $stmt_rev = $pdo->prepare("UPDATE student_login_tokens SET revoked_at = ? WHERE id = ?");
            $stmt_rev->execute([$now, $token_row['id']]);
            clear_student_cookie();
            return false;
        }

        $email = $token_row['student_email'];

        // Strictly revalidate student active status
        if (!can_student_access_study_plan($pdo, $email)) {
            $stmt_rev = $pdo->prepare("UPDATE student_login_tokens SET revoked_at = ? WHERE id = ?");
            $stmt_rev->execute([$now, $token_row['id']]);
            clear_student_cookie();
            return false;
        }

        // Retrieve student profile
        $stmt_u = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt_u->execute([$email]);
        $user = $stmt_u->fetch(PDO::FETCH_ASSOC);

        // Generate Single-Device Active Session for Cookie Login
        $active_session_id = generate_student_active_session($pdo, $user['user_id'] ?? null, $email);

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }

        if ($user) {
            $_SESSION['sp_logged_in'] = true;
            $_SESSION['sp_email'] = $user['email'];
            $_SESSION['sp_name'] = $user['name'];
            $_SESSION['sp_course'] = $user['pepp_course'];
            $_SESSION['sp_year'] = $user['pepp_academic_year'] ?? $user['academic_year'] ?? null;
            $_SESSION['sp_student_id'] = $user['user_id'];
            $_SESSION['sp_active_session_id'] = $active_session_id;
        } else {
            $_SESSION['sp_logged_in'] = true;
            $_SESSION['sp_email'] = $email;
            $_SESSION['sp_name'] = 'Student';
            $_SESSION['sp_course'] = null;
            $_SESSION['sp_year'] = null;
            $_SESSION['sp_student_id'] = null;
            $_SESSION['sp_active_session_id'] = $active_session_id;
        }

        // Rotate token validator for forward secrecy
        $new_validator = bin2hex(random_bytes(32));
        $new_hash = hash('sha256', $new_validator);
        $stmt_rot = $pdo->prepare("UPDATE student_login_tokens SET token_hash = ?, session_id_ref = ?, last_used_at = ? WHERE id = ?");
        $stmt_rot->execute([$new_hash, $active_session_id, $now, $token_row['id']]);

        $is_https = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1))
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $new_cookie_val = $selector . ':' . $new_validator;
        if (!headers_sent()) {
            @setcookie('pepp_sp_remember', $new_cookie_val, [
                'expires' => time() + (60 * 86400),
                'path' => '/',
                'secure' => $is_https,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        $_COOKIE['pepp_sp_remember'] = $new_cookie_val;

        // Log successful remember-token login
        log_student_login_audit($pdo, [
            'student_user_id' => $user['user_id'] ?? null,
            'student_email' => $email,
            'login_method' => 'remember_token',
            'session_id_ref' => $active_session_id,
            'status' => 'success'
        ]);

        return true;
    } catch (Exception $e) {
        error_log('authenticate_student_from_cookie error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Completely terminates the student session, revokes persistent remember token,
 * and clears server-side active device session.
 */
function logout_student($pdo, string $reason = 'manual_logout'): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }

    $email = $_SESSION['sp_email'] ?? '';
    $session_id = $_SESSION['sp_active_session_id'] ?? null;

    if (!empty($email)) {
        record_student_logout_audit($pdo, $email, $session_id, $reason);

        // Only delete active device session from DB if this was a manual logout or status downgrade.
        // If this is a superseded device being force-logged out (single_device_conflict),
        // preserve the newer device's active session in student_active_sessions.
        if ($reason !== 'single_device_conflict') {
            try {
                ensure_student_security_tables($pdo);
                $stmt_sas = $pdo->prepare("DELETE FROM student_active_sessions WHERE LOWER(student_email) = LOWER(?)");
                $stmt_sas->execute([$email]);
            } catch (Exception $e) {}
        }
    }

    if (!empty($_COOKIE['pepp_sp_remember'])) {
        $parts = explode(':', (string)$_COOKIE['pepp_sp_remember'], 2);
        if (count($parts) === 2 && !empty($parts[0])) {
            $selector = $parts[0];
            try {
                ensure_student_security_tables($pdo);
                $stmt = $pdo->prepare("UPDATE student_login_tokens SET revoked_at = ? WHERE selector = ?");
                $stmt->execute([date('Y-m-d H:i:s'), $selector]);
            } catch (Exception $e) {}
        }
    }

    clear_student_cookie();
    clear_student_session();
}

/**
 * Removes the persistent student remember cookie.
 */
function clear_student_cookie(): void {
    $is_https = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1))
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if (!headers_sent()) {
        @setcookie('pepp_sp_remember', '', [
            'expires' => time() - 86400,
            'path' => '/',
            'secure' => $is_https,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    unset($_COOKIE['pepp_sp_remember']);
}

/**
 * Clears student session variables without affecting admin sessions.
 */
function clear_student_session(): void {
    unset(
        $_SESSION['sp_logged_in'],
        $_SESSION['sp_email'],
        $_SESSION['sp_name'],
        $_SESSION['sp_course'],
        $_SESSION['sp_year'],
        $_SESSION['sp_student_id'],
        $_SESSION['sp_active_session_id']
    );
}

/**
 * Revalidates student lifecycle status and single active device session on every request.
 * If student status is downgraded OR account was logged in from another device,
 * revokes all tokens, terminates session, and returns false.
 */
function revalidate_student_study_plan_access($pdo): bool {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (empty($_SESSION['sp_logged_in']) || empty($_SESSION['sp_email'])) {
        return false;
    }

    $email = $_SESSION['sp_email'];
    $session_id = $_SESSION['sp_active_session_id'] ?? null;

    // 1. Revalidate Student Lifecycle (Approved + Active)
    if (!can_student_access_study_plan($pdo, $email)) {
        try {
            ensure_student_security_tables($pdo);
            $stmt = $pdo->prepare("UPDATE student_login_tokens SET revoked_at = ? WHERE student_email = ? AND revoked_at IS NULL");
            $stmt->execute([date('Y-m-d H:i:s'), $email]);
        } catch (Exception $e) {}

        logout_student($pdo, 'status_downgrade');
        $_SESSION['sp_force_logout_reason'] = 'status_downgrade';
        return false;
    }

    // 2. Strict Single-Active-Device Session Check
    if (!empty($session_id)) {
        $is_active_device = verify_student_active_session($pdo, $email, $session_id);
        if (!$is_active_device) {
            // This device's session has been superseded by a newer login on another device
            logout_student($pdo, 'single_device_conflict');
            $_SESSION['sp_force_logout_reason'] = 'single_device_conflict';
            return false;
        }
    }

    return true;
}
