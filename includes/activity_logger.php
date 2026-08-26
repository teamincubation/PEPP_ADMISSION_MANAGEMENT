<?php
/**
 * PEPP ERP - Centralized Activity & Audit Logger Helper
 * Ensures all events are tracked safely and never interrupts main operations.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Log a generic activity event to the database.
 * Fails gracefully and logs errors to server logs.
 */
function log_activity_event($pdo, $params = []) {
    try {
        // Derive admin username and id from session
        $username = $params['admin_username'] ?? ($_SESSION['admin_username'] ?? 'System');
        $admin_id = $params['admin_id'] ?? ($_SESSION['admin_id'] ?? null);
        $session_id = $params['session_id'] ?? ($_SESSION['session_ref'] ?? null);

        $action_type = $params['action_type'] ?? 'unknown';
        $details = $params['details'] ?? null;
        $module = $params['module'] ?? null;
        $page = $params['page'] ?? null;
        $section = $params['section'] ?? null;
        $target_type = $params['target_type'] ?? null;
        $target_id = $params['target_id'] ?? null;
        $request_method = $params['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $request_uri = $params['request_uri'] ?? ($_SERVER['REQUEST_URI'] ?? null);
        $referrer = $params['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? null);
        $is_heartbeat = isset($params['is_heartbeat']) ? (int)$params['is_heartbeat'] : 0;
        $is_idle = isset($params['is_idle']) ? (int)$params['is_idle'] : 0;

        $ip = $params['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $location = $params['location'] ?? ($_SESSION['admin_location'] ?? null);
        $user_agent = $params['user_agent'] ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $lat = isset($params['latitude']) ? $params['latitude'] : (isset($_COOKIE['pepp_lat']) && is_numeric($_COOKIE['pepp_lat']) ? (float)$_COOKIE['pepp_lat'] : null);
        $lng = isset($params['longitude']) ? $params['longitude'] : (isset($_COOKIE['pepp_lng']) && is_numeric($_COOKIE['pepp_lng']) ? (float)$_COOKIE['pepp_lng'] : null);
        $metadata = $params['metadata'] ?? ($_COOKIE['pepp_meta'] ?? null);

        // SQLite vs MySQL drivers compatibility check
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_log (
                admin_id, admin_username, session_id, action_type, module, page, section,
                target_type, target_id, details, request_method, request_uri, referrer,
                ip_address, location, user_agent, latitude, longitude, is_heartbeat, is_idle, metadata, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($driver === 'sqlite' ? "datetime('now', 'localtime')" : "NOW()") . ")
        ");

        $stmt->execute([
            $admin_id, $username, $session_id, $action_type, $module, $page, $section,
            $target_type, $target_id, $details, $request_method, $request_uri, $referrer,
            $ip, $location, $user_agent, $lat, $lng, $is_heartbeat, $is_idle, $metadata
        ]);

    } catch (Exception $e) {
        error_log("log_activity_event failed: " . $e->getMessage());
    }
}

/**
 * Log a page view.
 */
function log_page_view($pdo, $page, $module, $section) {
    // Only log if username is set in session
    if (empty($_SESSION['admin_username'])) {
        return;
    }

    log_activity_event($pdo, [
        'action_type' => 'page_view',
        'details' => "Visited page: $page",
        'module' => $module,
        'page' => $page,
        'section' => $section
    ]);
}

/**
 * Log a successful login.
 */
function log_login($pdo, $username, $admin_id, $session_ref) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $location = $_SESSION['admin_location'] ?? 'Unknown';
    $role = $_SESSION['admin_role'] ?? 'admin';

    log_activity_event($pdo, [
        'admin_username' => $username,
        'admin_id' => $admin_id,
        'session_id' => $session_ref,
        'action_type' => 'login',
        'details' => "Signed in (" . ($role === 'super_admin' ? 'Super Admin' : 'Admin') . ")",
        'module' => 'Administration',
        'page' => 'login.php',
        'section' => 'Admin Panel',
        'ip_address' => $ip,
        'location' => $location
    ]);
}

/**
 * Log a failed login.
 */
function log_failed_login($pdo, $username, $reason) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    log_activity_event($pdo, [
        'admin_username' => $username ?: 'Guest',
        'action_type' => 'failed_login',
        'details' => "Failed login attempt: $reason",
        'module' => 'Administration',
        'page' => 'login.php',
        'section' => 'Admin Panel',
        'ip_address' => $ip
    ]);
}

/**
 * Log manual logout.
 */
function log_logout($pdo, $username, $session_ref, $reason = 'Manual logout') {
    log_activity_event($pdo, [
        'admin_username' => $username,
        'session_id' => $session_ref,
        'action_type' => 'logout',
        'details' => $reason,
        'module' => 'Administration',
        'page' => 'logout',
        'section' => 'Admin Panel'
    ]);
}

/**
 * Log automatic/forced logout.
 */
function log_auto_logout($pdo, $username, $session_ref, $reason) {
    log_activity_event($pdo, [
        'admin_username' => $username,
        'session_id' => $session_ref,
        'action_type' => 'auto_logout',
        'details' => $reason,
        'module' => 'Administration',
        'page' => 'timeout',
        'section' => 'Admin Panel'
    ]);
}

/**
 * Log a heartbeat check if states change or to reconstruct session.
 * For performance: we avoid duplicate heartbeat logs.
 * We only log if it's the first heartbeat or if the page/active status changed.
 */
function log_heartbeat($pdo, $page, $module, $section, $is_idle) {
    if (empty($_SESSION['admin_username'])) {
        return;
    }

    $session_id = $_SESSION['session_ref'] ?? null;
    if (!$session_id) return;

    // Check last logged heartbeat state in session to prevent database flooding
    $last_hb_page = $_SESSION['last_hb_page'] ?? '';
    $last_hb_idle = $_SESSION['last_hb_idle'] ?? -1;

    if ($last_hb_page !== $page || $last_hb_idle !== (int)$is_idle) {
        log_activity_event($pdo, [
            'action_type' => 'heartbeat',
            'details' => $is_idle ? "User is idle on page: $page" : "User is active on page: $page",
            'module' => $module,
            'page' => $page,
            'section' => $section,
            'is_heartbeat' => 1,
            'is_idle' => (int)$is_idle
        ]);

        $_SESSION['last_hb_page'] = $page;
        $_SESSION['last_hb_idle'] = (int)$is_idle;
    }
}

/**
 * Update the presence state of the admin.
 */
function update_presence_state($pdo, $page, $module, $section, $is_idle) {
    try {
        $username = $_SESSION['admin_username'] ?? null;
        if (!$username) return;

        $session_id = $_SESSION['session_ref'] ?? null;
        $login_time = isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $lat = isset($_COOKIE['pepp_lat']) && is_numeric($_COOKIE['pepp_lat']) ? (float)$_COOKIE['pepp_lat'] : null;
        $lng = isset($_COOKIE['pepp_lng']) && is_numeric($_COOKIE['pepp_lng']) ? (float)$_COOKIE['pepp_lng'] : null;

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO admin_presence (username, current_page, current_section, last_seen, login_time, latitude, longitude, ip_address, session_id, is_idle)
                VALUES (?, ?, ?, datetime('now', 'localtime'), ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$username, $page, $section, $login_time, $lat, $lng, $ip, $session_id, (int)$is_idle]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO admin_presence (username, current_page, current_section, last_seen, login_time, latitude, longitude, ip_address, session_id, is_idle)
                VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    current_page = VALUES(current_page),
                    current_section = VALUES(current_section),
                    last_seen = NOW(),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    ip_address = VALUES(ip_address),
                    session_id = VALUES(session_id),
                    is_idle = VALUES(is_idle)
            ");
            $stmt->execute([$username, $page, $section, $login_time, $lat, $lng, $ip, $session_id, (int)$is_idle]);
        }
    } catch (Exception $e) {
        error_log("update_presence_state failed: " . $e->getMessage());
    }
}

/**
 * Get current session ref.
 */
function get_activity_session_ref() {
    return $_SESSION['session_ref'] ?? null;
}
