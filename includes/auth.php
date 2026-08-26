<?php
/**
 * PEPP Learning Admin - shared authentication & authorization guard.
 * Include this at the top of every admin page BEFORE any output.
 *
 * Roles:
 *   super_admin - full control: every page, all deletions, admin management,
 *                 activity log, reports & exports. Session timeout: 2 hours.
 *   admin       - access only to the page keys granted in admins.permissions.
 *                 Cannot delete data. Auto-logout after 20 minutes idle.
 *
 * PAGE REGISTRY: add future pages here and they automatically appear in
 * Admin Management for the Super Admin to grant.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/activity_logger.php';

$GLOBALS['ADMIN_PAGES'] = [
    'dashboard'     => ['Dashboard',               'fa-gauge-high'],
    'approvals'     => ['Student Approvals',       'fa-user-check'],
    'add-student'   => ['Add Student',             'fa-user-plus'],
    'students'      => ['All Students & Profiles', 'fa-users'],
    'onboarding'    => ['Student Onboarding',      'fa-handshake'],
    'installments'  => ['Installment Payments',    'fa-money-bill-wave'],
    'whatsapp'      => ['Manual WP Log',           'fa-comment'],
    'whatsapp-inbox'=> ['WhatsApp Inbox',          'fa-whatsapp'],
    'invoices'      => ['Invoices',                'fa-file-invoice'],
    'communication' => ['Communication Engine',    'fa-network-wired'],
    'email-reports' => ['Email Reports',           'fa-envelope-open-text'],
    'courses'       => ['Course Management',       'fa-book-open'],
    'faculties'     => ['Faculties',               'fa-chalkboard-user'],
    'sessions'      => ['Sessions',                'fa-video'],
    'accounts'      => ['Accounts & Expenses',     'fa-wallet'],
    'leads'         => ['Lead Management',         'fa-user-tag'],
    'marketing'     => ['Marketing',               'fa-bullhorn'],
    'alumni'        => ['Alumni Database',         'fa-user-graduate'],
    'peppkit'       => ['PEPPKIT Report',          'fa-box-open'],
    'cards'         => ['Generate Cards',          'fa-id-card'],
    'card-templates'=> ['Create Card Templates',   'fa-id-card'],
    'campaigns'     => ['Campaign Forms',          'fa-wpforms'],
    'studyplans'    => ['Study Plans',             'fa-calendar-days'],
    'task-tracker'  => ['Intern Task Tracker',     'fa-list-check'],
    'ld-work-report'=> ['L&D Work Report',         'fa-chart-simple'],
    'employee-management' => ['Employee Management', 'fa-id-badge'],
    'student-mentoring'   => ['Student Mentoring',   'fa-people-arrows'],
    'assessment-results'  => ['Assessment Results',  'fa-chart-column'],
    'settings'      => ['Settings',                'fa-gear'],
];

/* ── Does the multi-admin system exist yet? (graceful pre-migration mode) ── */
function admins_table_exists($pdo) {
    static $exists = null;
    if ($exists === null) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $exists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admins'")->fetchColumn();
            } else {
                $exists = (bool)$pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
            }
        }
        catch (Exception $e) { $exists = false; }
    }
    return $exists;
}
function ensure_credential_visibility_column($pdo) {
    if (!admins_table_exists($pdo)) return;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') return;
    static $ensured = false;
    if ($ensured) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM admins LIKE 'credential_visibility'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `credential_visibility` ENUM('visible', 'hide', 'mask') NOT NULL DEFAULT 'visible'");
        }
        $cols_scopes = $pdo->query("SHOW COLUMNS FROM admins LIKE 'credential_visibility_scopes'")->fetch();
        if (!$cols_scopes) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `credential_visibility_scopes` VARCHAR(255) NOT NULL DEFAULT ''");
        }
        $cols_edit = $pdo->query("SHOW COLUMNS FROM admins LIKE 'can_edit'")->fetch();
        if (!$cols_edit) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `can_edit` TINYINT(1) NOT NULL DEFAULT 1");
        }
        $cols_delete = $pdo->query("SHOW COLUMNS FROM admins LIKE 'can_delete'")->fetch();
        if (!$cols_delete) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `can_delete` TINYINT(1) NOT NULL DEFAULT 1");
        }
        $cols_export = $pdo->query("SHOW COLUMNS FROM admins LIKE 'can_export'")->fetch();
        if (!$cols_export) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `can_export` TINYINT(1) NOT NULL DEFAULT 1");
        }
        $cols_copy_email = $pdo->query("SHOW COLUMNS FROM admins LIKE 'allow_copy_email'")->fetch();
        if (!$cols_copy_email) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `allow_copy_email` TINYINT(1) NOT NULL DEFAULT 1");
        }
        $cols_wa_chat = $pdo->query("SHOW COLUMNS FROM admins LIKE 'allow_whatsapp_chat'")->fetch();
        if (!$cols_wa_chat) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `allow_whatsapp_chat` TINYINT(1) NOT NULL DEFAULT 1");
        }
        $ensured = true;
    } catch (Exception $e) {
        error_log("Failed to ensure admins schema updates: " . $e->getMessage());
    }
}

// Action authorization capabilities check helpers
function can_admin_edit() {
    global $admin_role, $admin_row;
    if ($admin_role === 'super_admin') return true;
    return isset($admin_row['can_edit']) ? (int)$admin_row['can_edit'] === 1 : true;
}

function can_admin_copy_original_email() {
    global $admin_role, $admin_row;
    if ($admin_role === 'super_admin') return true;
    return isset($admin_row['allow_copy_email']) ? (int)$admin_row['allow_copy_email'] === 1 : true;
}

function can_admin_whatsapp_chat() {
    global $admin_role, $admin_row;
    if ($admin_role === 'super_admin') return true;
    return isset($admin_row['allow_whatsapp_chat']) ? (int)$admin_row['allow_whatsapp_chat'] === 1 : true;
}

function can_admin_delete() {
    global $admin_role, $admin_row;
    if ($admin_role === 'super_admin') return true;
    return isset($admin_row['can_delete']) ? (int)$admin_row['can_delete'] === 1 : true;
}

function can_admin_export() {
    global $admin_role, $admin_row;
    if ($admin_role === 'super_admin') return true;
    return isset($admin_row['can_export']) ? (int)$admin_row['can_export'] === 1 : true;
}

/**
 * Returns the global outbound WhatsApp messaging mode: 'meta_api' or 'manual'.
 * Cached per-request. Defaults to 'manual' for safety.
 */
function whatsapp_outbound_mode($pdo) {
    static $mode = null;
    if ($mode === null) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode' LIMIT 1");
            $stmt->execute();
            $mode = $stmt->fetchColumn() ?: 'manual';
        } catch (Exception $e) {
            $mode = 'manual';
        }
    }
    return $mode;
}

/* ── Activity logging (logins, logouts, exports, admin events) ──────────── */
function log_admin_activity($pdo, $admin, $type, $details = '', $ip = null, $location = null) {
    // Determine target entity from details or type
    $target_type = null;
    $target_id = null;

    // Auto-detect target student if student ID exists in details (e.g. PL-2026-99)
    if (preg_match('/(PL-[A-Za-z0-9\-]+)/i', $details, $matches)) {
        $target_type = 'student';
        $target_id = strtoupper($matches[1]);
    }

    // Resolve module/page based on current page
    $cur_page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $low_page = strtolower(trim($cur_page));
    $module = 'Other';
    if (in_array($low_page, ['dashboard.php'], true)) $module = 'Overview';
    elseif (in_array($low_page, ['student-approval.php', 'add-student.php'], true)) $module = 'Registrations';
    elseif (in_array($low_page, ['studentpage.php', 'studentonboarding.php', 'student-details.php'], true)) $module = 'Students';
    elseif (in_array($low_page, ['sessions.php', 'lead-management.php', 'student-mentoring.php'], true)) $module = 'Leads & Mentoring';
    elseif (in_array($low_page, ['whatsapp-notification.php', 'whatsapp-inbox.php', 'communication-dashboard.php', 'communication-campaigns.php', 'whatsapp-marketing-templates.php'], true)) $module = 'Communication';
    elseif (in_array($low_page, ['course-management.php', 'faculties.php', 'studyplans.php', 'student-study-reports.php', 'assessment-results.php', 'studyplan-designer.php', 'studyplan-chapters.php'], true)) $module = 'Academics';
    elseif (in_array($low_page, ['settings.php'], true)) $module = 'Settings';
    elseif (in_array($low_page, ['admin-management.php', 'employee-management.php', 'admin-activity.php', 'reports.php', 'email-reports.php'], true)) $module = 'Admin Panel';

    log_activity_event($pdo, [
        'admin_username' => $admin,
        'action_type' => $type,
        'details' => $details,
        'ip_address' => $ip,
        'location' => $location,
        'module' => $module,
        'page' => $cur_page,
        'section' => $module,
        'target_type' => $target_type,
        'target_id' => $target_id
    ]);
}

/* ── Logout ─────────────────────────────────────────────────────────────── */
if (isset($_GET['logout'])) {
    if (!empty($_SESSION['admin_username'])) {
        log_logout($pdo, $_SESSION['admin_username'], $_SESSION['session_ref'] ?? null, 'Manual logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit();
}

/* ── Require login ──────────────────────────────────────────────────────── */
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$admin_username = $_SESSION['admin_username'] ?? 'Admin';

/* ── Load the live admin record (role / permissions / status) ───────────── */
$admin_role  = 'super_admin';   // legacy single-admin mode default
$admin_perms = 'ALL';
$admin_credential_visibility = 'visible';
$admin_credential_visibility_scopes = '';
if (admins_table_exists($pdo)) {
    ensure_credential_visibility_column($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([$admin_username]);
        $admin_row = $stmt->fetch();
        if ($admin_row) {
            $_SESSION['admin_id'] = $admin_row['id'];
        }
        if (!$admin_row || $admin_row['status'] !== 'active') {
            // Deactivated or removed while logged in → end the session
            log_auto_logout($pdo, $admin_username, $_SESSION['session_ref'] ?? null, 'Account inactive or removed');
            session_unset(); session_destroy();
            header('Location: login.php?expired=1');
            exit();
        }
        $admin_role  = $admin_row['role'];
        $admin_perms = (string)($admin_row['permissions'] ?? '');
        $admin_credential_visibility = $admin_row['credential_visibility'] ?? 'visible';
        $admin_credential_visibility_scopes = $admin_row['credential_visibility_scopes'] ?? '';
    } catch (Exception $e) { error_log('auth admin load: ' . $e->getMessage()); }
}
$_SESSION['admin_role'] = $admin_role;

// Update admin presence for real-time tracking
try {
    if (empty($_SESSION['login_time'])) {
        $stmt_ll = $pdo->prepare("SELECT last_login_at FROM admins WHERE username = ? LIMIT 1");
        $stmt_ll->execute([$admin_username]);
        $ll = $stmt_ll->fetchColumn();
        $_SESSION['login_time'] = $ll ? strtotime($ll) : time();
    }

    // Auto-generate session_ref if not set yet (backwards compatibility)
    if (empty($_SESSION['session_ref'])) {
        $_SESSION['session_ref'] = bin2hex(random_bytes(16));
    }

    $cur_page = basename($_SERVER['SCRIPT_NAME']);

    // Resolve section
    $cur_sec = 'Other';
    $low_page = strtolower(trim($cur_page));
    if (in_array($low_page, ['dashboard.php'], true)) $cur_sec = 'Overview';
    elseif (in_array($low_page, ['student-approval.php', 'add-student.php'], true)) $cur_sec = 'Registrations';
    elseif (in_array($low_page, ['studentpage.php', 'studentonboarding.php', 'student-details.php'], true)) $cur_sec = 'Students';
    elseif (in_array($low_page, ['sessions.php', 'lead-management.php', 'student-mentoring.php'], true)) $cur_sec = 'Leads & Mentoring';
    elseif (in_array($low_page, ['whatsapp-notification.php', 'whatsapp-inbox.php', 'communication-dashboard.php', 'communication-campaigns.php', 'whatsapp-marketing-templates.php'], true)) $cur_sec = 'Communication';
    elseif (in_array($low_page, ['course-management.php', 'faculties.php', 'studyplans.php', 'student-study-reports.php', 'assessment-results.php', 'studyplan-designer.php', 'studyplan-chapters.php'], true)) $cur_sec = 'Academics';
    elseif (in_array($low_page, ['settings.php'], true)) $cur_sec = 'Settings';
    elseif (in_array($low_page, ['admin-management.php', 'employee-management.php', 'admin-activity.php', 'reports.php', 'email-reports.php'], true)) $cur_sec = 'Admin Panel';

    // Update presence
    update_presence_state($pdo, $cur_page, $cur_sec, $cur_sec, 0); // 0 = active

    // Log page view if it's a normal page load (not AJAX/API)
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json') !== false);

    $req_uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_api = (strpos($req_uri, '/api/') !== false || strpos($req_uri, 'api/v1/') !== false || strpos($req_uri, 'api-') !== false || strpos($req_uri, 'heartbeat') !== false);

    if (!$is_ajax && !$is_api) {
        log_page_view($pdo, $cur_page, $cur_sec, $cur_sec);
    }
} catch (Exception $e) {
    error_log('admin presence log failed: ' . $e->getMessage());
}

/* ── Inactivity timeout: 20 min for admins, 2 h for the Super Admin ─────── */
$timeout = ($admin_role === 'super_admin') ? 2 * 60 * 60 : 20 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    log_auto_logout($pdo, $admin_username, $_SESSION['session_ref'] ?? null,
        'Automatically logged out after ' . round($timeout / 60) . ' minutes of inactivity');
    session_unset();
    session_destroy();
    header('Location: login.php?expired=1');
    exit();
}
$_SESSION['last_activity'] = time();

// Server-side action check blocks to prevent unauthorized actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $post_action = strtolower($_POST['action'] ?? '');

    // Allow self-account updates (like change_password)
    if ($post_action !== 'change_password') {
        // Check Delete
        if (!can_admin_delete()) {
            if (strpos($post_action, 'delete') !== false ||
                strpos($post_action, 'remove') !== false ||
                strpos($post_action, 'reject') !== false ||
                strpos($post_action, 'cancel') !== false ||
                strpos($post_action, 'purge') !== false) {

                die("<div style='color:#ef4444; font-family:sans-serif; text-align:center; margin-top:50px; padding:20px; border:1px solid #fca5a5; background:#fef2f2; border-radius:12px; max-width:500px; margin-left:auto; margin-right:auto;'><h3>Access Denied</h3><p>You do not have permission to delete records.</p></div>");
            }
        }

        // Check Export
        if (!can_admin_export()) {
            if (strpos($post_action, 'export') !== false ||
                strpos($post_action, 'download') !== false ||
                strpos($post_action, 'csv') !== false ||
                strpos($post_action, 'excel') !== false ||
                strpos($post_action, 'report') !== false) {

                die("<div style='color:#ef4444; font-family:sans-serif; text-align:center; margin-top:50px; padding:20px; border:1px solid #fca5a5; background:#fef2f2; border-radius:12px; max-width:500px; margin-left:auto; margin-right:auto;'><h3>Access Denied</h3><p>You do not have permission to export data.</p></div>");
            }
        }

        // Check Edit / Create
        if (!can_admin_edit()) {
            // Block any modifications except search or details viewing
            if ($post_action !== '' &&
                $post_action !== 'search' &&
                $post_action !== 'filter' &&
                strpos($post_action, 'load') === false &&
                strpos($post_action, 'view') === false) {

                die("<div style='color:#ef4444; font-family:sans-serif; text-align:center; margin-top:50px; padding:20px; border:1px solid #fca5a5; background:#fef2f2; border-radius:12px; max-width:500px; margin-left:auto; margin-right:auto;'><h3>Access Denied</h3><p>You do not have permission to edit or create records.</p></div>");
            }
        }
    }
}

// Check GET exports and deletions
if (isset($_GET['action'])) {
    $get_action = strtolower($_GET['action']);
    if (!can_admin_export()) {
        if (strpos($get_action, 'export') !== false ||
            strpos($get_action, 'download') !== false ||
            strpos($get_action, 'csv') !== false ||
            strpos($get_action, 'excel') !== false ||
            strpos($get_action, 'report') !== false) {

            die("<div style='color:#ef4444; font-family:sans-serif; text-align:center; margin-top:50px; padding:20px; border:1px solid #fca5a5; background:#fef2f2; border-radius:12px; max-width:500px; margin-left:auto; margin-right:auto;'><h3>Access Denied</h3><p>You do not have permission to export data.</p></div>");
        }
    }
    if (!can_admin_delete()) {
        if (strpos($get_action, 'delete') !== false ||
            strpos($get_action, 'remove') !== false ||
            strpos($get_action, 'reject') !== false ||
            strpos($get_action, 'cancel') !== false ||
            strpos($get_action, 'purge') !== false) {

            die("<div style='color:#ef4444; font-family:sans-serif; text-align:center; margin-top:50px; padding:20px; border:1px solid #fca5a5; background:#fef2f2; border-radius:12px; max-width:500px; margin-left:auto; margin-right:auto;'><h3>Access Denied</h3><p>You do not have permission to delete records.</p></div>");
        }
    }
}

/* ── Role / permission helpers ──────────────────────────────────────────── */
function is_super_admin() {
    return ($_SESSION['admin_role'] ?? '') === 'super_admin';
}
function can_access($page_key) {
    global $admin_perms;
    if ($page_key === 'communication' || $page_key === 'email-reports' || $page_key === 'employee-management') {
        return is_super_admin();
    }
    if (is_super_admin()) return true;
    if (trim($admin_perms) === 'ALL') return true;

    $perms = array_map('trim', explode(',', $admin_perms));
    if (($page_key === 'whatsapp-inbox' || $page_key === 'whatsapp-marketing-templates') && in_array('communication', $perms, true)) {
        return true;
    }
    return in_array($page_key, $perms, true);
}
function get_first_accessible_page_url() {
    global $admin_perms;
    if (is_super_admin() || trim($admin_perms) === 'ALL') {
        return 'dashboard.php';
    }
    $page_urls = [
        'dashboard'     => 'dashboard.php',
        'approvals'     => 'student-approval.php',
        'add-student'   => 'add-student.php',
        'students'      => 'studentpage.php',
        'onboarding'    => 'studentonboarding.php',
        'sessions'      => 'sessions.php',
        'leads'         => 'lead-management.php',
        'marketing'     => 'marketing.php',
        'alumni'        => 'alumni-database.php',
        'peppkit'       => 'peppkit-report.php',
        'cards'         => 'cards.php',
        'card-templates'=> 'cards.php?tab=templates',
        'campaigns'     => 'campaign-forms.php',
        'accounts'      => 'accounts.php',
        'installments'  => 'phpinstalmentpaymentupdate.php',
        'invoices'      => 'invoices.php',
        'communication' => 'communication-dashboard.php',
        'email-reports' => 'email-reports.php',
        'whatsapp'      => 'whatsapp-notification.php',
        'whatsapp-inbox'=> 'whatsapp-inbox.php',
        'whatsapp-marketing-templates' => 'whatsapp-marketing-templates.php',
        'courses'       => 'course-management.php',
        'faculties'     => 'faculties.php',
        'studyplans'    => 'studyplans.php',
        'assessment-results' => 'assessment-results.php',
        'task-tracker'  => 'task-tracker.php',
        'ld-work-report'=> 'ld-work-report.php',
        'employee-management' => 'employee-management.php',
        'student-mentoring'   => 'student-mentoring.php',
        'settings'      => 'settings.php',
    ];
    $perms = array_map('trim', explode(',', $admin_perms));
    foreach ($page_urls as $key => $url) {
        if (in_array($key, $perms, true)) {
            return $url;
        }
    }
    return 'dashboard.php';
}
function require_permission($page_key) {
    if (can_access($page_key)) return;
    http_response_code(403);
    $label = $GLOBALS['ADMIN_PAGES'][$page_key][0] ?? $page_key;
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Access Restricted</title>'
       . '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">'
       . '<style>body{font-family:"DM Sans",sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}'
       . '.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:2.2rem;max-width:420px;text-align:center;box-shadow:0 10px 40px rgba(15,23,42,.08)}'
       . '.icon{width:56px;height:56px;border-radius:50%;background:#fee2e2;color:#b91c1c;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem}'
       . 'h1{font-size:1.15rem;color:#1f2937;margin:0 0 .5rem}p{font-size:.875rem;color:#6b7280;margin:0 0 1.4rem}'
       . 'a{display:inline-block;background:#8b5cf6;color:#fff;text-decoration:none;font-weight:600;font-size:.85rem;border-radius:9px;padding:10px 22px}</style></head>'
       . '<body><div class="card"><div class="icon">&#9888;</div><h1>Access Restricted</h1>'
       . '<p>Your account does not have access to <strong>' . htmlspecialchars($label) . '</strong>.<br>'
       . 'Ask the Super Admin to grant this page in Admin Management.</p>'
       . '<a href="dashboard.php">Go to Dashboard</a></div></body></html>';
    exit();
}
function require_super_admin() {
    if (is_super_admin()) return;
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Super Admin Only</title>'
       . '<style>body{font-family:sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
       . '.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:2rem;max-width:400px;text-align:center}'
       . 'a{color:#7c3aed;font-weight:600}</style></head>'
       . '<body><div class="card"><h2>Super Admin only</h2><p>This area is restricted to the Super Administrator.</p>'
       . '<a href="dashboard.php">Back to Dashboard</a></div></body></html>';
    exit();
}
/* Only the Super Admin may delete data anywhere in the system. */
function can_delete() { return is_super_admin(); }

/** Get admin_type from the loaded admin row (defaults to 'erp_admin'). */
function get_admin_type() {
    global $admin_row;
    return $admin_row['admin_type'] ?? 'erp_admin';
}

/** Checks if the currently logged-in user is eligible/assigned as an L&D intern. */
function is_ld_intern_user() {
    global $admin_role, $admin_perms;
    if (get_admin_type() === 'intern') {
        return true;
    }
    if ($admin_role === 'super_admin' || get_admin_type() === 'superadmin') {
        return false;
    }
    if (trim($admin_perms) === 'ALL') {
        return false;
    }
    $perms = array_map('trim', explode(',', $admin_perms));
    return in_array('task-tracker', $perms, true);
}

/** Checks if a given admin database row is eligible/assigned as an L&D intern. */
function is_ld_intern($admin) {
    if (!isset($admin['permissions']) || !isset($admin['role'])) {
        return false;
    }
    $admin_type = $admin['admin_type'] ?? 'erp_admin';
    if ($admin_type === 'intern') {
        return true;
    }
    if ($admin['role'] === 'super_admin' || $admin_type === 'superadmin') {
        return false;
    }
    $perms = $admin['permissions'];
    if (trim($perms) === 'ALL') {
        return false;
    }
    $perms_array = array_map('trim', explode(',', $perms));
    return in_array('task-tracker', $perms_array, true);
}

/** Get courses assigned to a mentor admin. Returns array of course_name strings. */
function get_mentor_courses($pdo, $admin_id) {
    try {
        $stmt = $pdo->prepare("SELECT course_name FROM mentor_course_assignments WHERE admin_id = ? ORDER BY course_name");
        $stmt->execute([$admin_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { return []; }
}

/** Get the active academic year in compact format (e.g. '2627'). */
function get_active_academic_year_compact($pdo) {
    try {
        $stmt = $pdo->query("SELECT year FROM academic_years WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
        $year = $stmt->fetchColumn();
        if ($year) {
            $parts = explode('-', $year);
            if (count($parts) === 2) return substr($parts[0], 2) . $parts[1];
        }
    } catch (Exception $e) {}
    $m = (int)date('n'); $y = (int)date('Y');
    $start = ($m >= 6) ? $y : ($y - 1);
    return substr((string)$start, 2) . substr((string)($start + 1), 2);
}

/* ── CSRF helpers ───────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_token()  { return $_SESSION['csrf_token']; }
function csrf_field()  { return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">'; }
function csrf_verify() {
    $t = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $t);
}

/* ── Output helper ──────────────────────────────────────────────────────── */
function e($v) {
    $str = (string)($v ?? '');
    if (strpos($str, 'uploads/') === 0) {
        $str = '../' . $str;
    }
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/* ── Photo / upload rendering ───────────────────────────────────────────────
   Handles the case where a student uploaded a PDF (or other non-image) where
   a photo was expected: instead of a broken <img>, show a red PDF icon (or a
   generic file icon) that links to the uploaded file. */
function upload_is_image($path) {
    return (bool)preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', (string)$path);
}
function upload_is_pdf($path) {
    return (bool)preg_match('/\.pdf$/i', (string)$path);
}
/** Renders a thumbnail box for an uploaded photo. $size in px. */
function render_photo_box($path, $size = 110) {
    $s = (int)$size;
    if (empty($path)) {
        return '<div class="student-photo" style="width:' . $s . 'px;height:' . $s . 'px;display:flex;align-items:center;justify-content:center;color:var(--muted-foreground);background:var(--card);border-radius:12px;"><i class="fas fa-user fa-2x"></i></div>';
    }
    $url = e($path);
    if (upload_is_image($path)) {
        return '<a href="' . $url . '" target="_blank" rel="noopener"><img class="student-photo" src="' . $url . '" alt="Photo" style="width:' . $s . 'px;height:' . $s . 'px;object-fit:cover;border-radius:12px;"></a>';
    }
    // Non-image upload (PDF or other) → icon tile, no broken image
    $isPdf = upload_is_pdf($path);
    $icon  = $isPdf ? 'fa-file-pdf' : 'fa-file-lines';
    $color = $isPdf ? '#dc2626' : '#64748b';
    $label = $isPdf ? 'PDF file' : 'File';
    $fs    = max(18, (int)round($s * 0.34));
    return '<a href="' . $url . '" target="_blank" rel="noopener" title="' . $label . ' - click to open" '
         . 'style="width:' . $s . 'px;height:' . $s . 'px;display:flex;flex-direction:column;gap:5px;align-items:center;justify-content:center;'
         . 'background:#fff5f5;border:1.5px solid #fecaca;border-radius:12px;text-decoration:none;color:' . $color . ';">'
         . '<i class="fas ' . $icon . '" style="font-size:' . $fs . 'px;"></i>'
         . '<span style="font-size:10px;font-weight:700;color:' . $color . ';">' . $label . '</span></a>';
}


/* ── Audit helper (correct track_records schema) ────────────────────────── */
function track_record($pdo, $user_id, $action_type, $details, $admin) {
    try {
        $lat = isset($_COOKIE['pepp_lat']) && is_numeric($_COOKIE['pepp_lat']) ? (float)$_COOKIE['pepp_lat'] : null;
        $lng = isset($_COOKIE['pepp_lng']) && is_numeric($_COOKIE['pepp_lng']) ? (float)$_COOKIE['pepp_lng'] : null;
        $meta = $_COOKIE['pepp_meta'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO track_records (user_id, action_type, action_details, performed_by, latitude, longitude, metadata, performed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action_type, $details, $admin, $lat, $lng, $meta]);
    } catch (Exception $ex) { error_log('track_record: ' . $ex->getMessage()); }
}

/* ── Status log helper ──────────────────────────────────────────────────── */
function status_log($pdo, $user_id, $old, $new, $reason, $admin) {
    try {
        $stmt = $pdo->prepare("INSERT INTO student_status_log (user_id, old_status, new_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $old, $new, $reason, $admin]);
    } catch (Exception $ex) { error_log('status_log: ' . $ex->getMessage()); }
}

function is_credential_restricted($scope) {
    global $admin_credential_visibility, $admin_credential_visibility_scopes;
    if (is_super_admin()) return false;

    $vis = $admin_credential_visibility ?? 'visible';
    if ($vis === 'visible') return false;

    $scopes_str = $admin_credential_visibility_scopes ?? '';
    if (trim($scopes_str) === '') return false;

    $scopes = array_map('trim', explode(',', strtolower($scopes_str)));
    return in_array(strtolower($scope), $scopes, true);
}

function format_credential($value, $type, $scope = 'students') {
    global $admin_credential_visibility;
    $value = trim((string)$value);
    if ($value === '') return '';

    if (!is_credential_restricted($scope)) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    $vis = $admin_credential_visibility ?? 'visible';

    if ($vis === 'hide') {
        $len = strlen($value);
        if ($type === 'email') {
            $obfuscated = str_repeat('x', min(6, $len)) . '@' . str_repeat('x', min(8, $len));
        } elseif ($type === 'phone') {
            $obfuscated = str_repeat('x', min(10, $len));
        } else {
            $obfuscated = str_repeat('x', min(15, $len));
        }
        return '<span style="filter: blur(4.5px); -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; pointer-events: none; display: inline-block;">' . $obfuscated . '</span>';
    }

    if ($vis === 'mask') {
        if ($type === 'email') {
            $parts = explode('@', $value);
            if (count($parts) === 2) {
                $name = $parts[0];
                $domain = $parts[1];
                $len = strlen($name);
                if ($len <= 2) {
                    $masked = str_repeat('*', $len);
                } else {
                    $masked = substr($name, 0, 2) . str_repeat('*', max(3, $len - 4)) . ($len > 4 ? substr($name, -2) : substr($name, -1));
                }
                return htmlspecialchars($masked . '@' . $domain, ENT_QUOTES, 'UTF-8');
            }
        } elseif ($type === 'phone') {
            $len = strlen($value);
            if ($len <= 5) {
                return str_repeat('*', $len);
            }
            return htmlspecialchars(substr($value, 0, 3) . str_repeat('*', $len - 5) . substr($value, -2), ENT_QUOTES, 'UTF-8');
        } elseif ($type === 'address') {
            $len = strlen($value);
            if ($len <= 10) {
                return str_repeat('*', $len);
            }
            return htmlspecialchars(substr($value, 0, 5) . str_repeat('*', $len - 10) . substr($value, -5), ENT_QUOTES, 'UTF-8');
        }
    }

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function format_credential_text($value, $type, $scope = 'students') {
    global $admin_credential_visibility;
    $value = trim((string)$value);
    if ($value === '') return '';

    if (!is_credential_restricted($scope)) {
        return $value;
    }

    $vis = $admin_credential_visibility ?? 'visible';

    if ($vis === 'hide') {
        $len = strlen($value);
        if ($type === 'email') {
            return str_repeat('x', min(6, $len)) . '@' . str_repeat('x', min(8, $len));
        } elseif ($type === 'phone') {
            return str_repeat('x', min(10, $len));
        } else {
            return str_repeat('x', min(15, $len));
        }
    }

    if ($vis === 'mask') {
        if ($type === 'email') {
            $parts = explode('@', $value);
            if (count($parts) === 2) {
                $name = $parts[0];
                $domain = $parts[1];
                $len = strlen($name);
                if ($len <= 2) {
                    $masked = str_repeat('*', $len);
                } else {
                    $masked = substr($name, 0, 2) . str_repeat('*', max(3, $len - 4)) . ($len > 4 ? substr($name, -2) : substr($name, -1));
                }
                return $masked . '@' . $domain;
            }
        } elseif ($type === 'phone') {
            $len = strlen($value);
            if ($len <= 5) {
                return str_repeat('*', $len);
            }
            return substr($value, 0, 3) . str_repeat('*', $len - 5) . substr($value, -2);
        } elseif ($type === 'address') {
            $len = strlen($value);
            if ($len <= 10) {
                return str_repeat('*', $len);
            }
            return substr($value, 0, 5) . str_repeat('*', $len - 10) . substr($value, -5);
        }
    }

    return $value;
}

function format_financial($amount, $decimals = 2, $currency = '₹', $scope = 'financials') {
    if (is_credential_restricted($scope)) {
        global $admin_credential_visibility;
        $vis = $admin_credential_visibility ?? 'visible';
        if ($vis === 'hide') {
            return '<span style="filter: blur(4.5px); -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; pointer-events: none; display: inline-block;">' . ($currency ? $currency : '') . 'xx,xxx.xx</span>';
        }
        if ($vis === 'mask') {
            return ($currency ? $currency : '') . '***';
        }
    }
    if ($amount === null || $amount === '') return '';
    $formatted = (is_numeric($amount) ? number_format((float)$amount, $decimals) : $amount);
    return ($currency ? $currency : '') . $formatted;
}


function ld_tables_exist($pdo) {
    static $exists = null;
    if ($exists === null) {
        try {
            $tables = ['ld_work_courses', 'ld_work_modes', 'ld_tasks', 'ld_task_topics', 'ld_task_audit'];
            foreach ($tables as $tbl) {
                $has = false;
                try {
                    $has = (bool)$pdo->query("SELECT 1 FROM `$tbl` LIMIT 1");
                } catch (Exception $e) {}
                if (!$has) {
                    $exists = false;
                    return false;
                }
            }

            // Helper to check if a column exists
            $has_col = function($db, $table, $column) {
                try {
                    $db->query("SELECT `$column` FROM `$table` LIMIT 1");
                    return true;
                } catch (Exception $e) {
                    return false;
                }
            };

            // Self-healing columns addition
            // 1. ld_work_modes
            if (!$has_col($pdo, 'ld_work_modes', 'quantity_label')) {
                $pdo->exec("ALTER TABLE `ld_work_modes` ADD COLUMN `quantity_label` VARCHAR(100) DEFAULT NULL");
            }
            if (!$has_col($pdo, 'ld_work_modes', 'charge_per_quantity')) {
                $pdo->exec("ALTER TABLE `ld_work_modes` ADD COLUMN `charge_per_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            }

            // 2. ld_tasks
            if (!$has_col($pdo, 'ld_tasks', 'quantity_label_snapshot')) {
                $pdo->exec("ALTER TABLE `ld_tasks` ADD COLUMN `quantity_label_snapshot` VARCHAR(100) DEFAULT NULL");
            }
            if (!$has_col($pdo, 'ld_tasks', 'charge_per_quantity_snapshot')) {
                $pdo->exec("ALTER TABLE `ld_tasks` ADD COLUMN `charge_per_quantity_snapshot` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            }
            if (!$has_col($pdo, 'ld_tasks', 'mode_name_snapshot')) {
                $pdo->exec("ALTER TABLE `ld_tasks` ADD COLUMN `mode_name_snapshot` VARCHAR(255) DEFAULT NULL");
            }

            // 3. ld_task_topics
            if (!$has_col($pdo, 'ld_task_topics', 'quantity')) {
                $pdo->exec("ALTER TABLE `ld_task_topics` ADD COLUMN `quantity` DECIMAL(10,2) DEFAULT NULL");
            }
            if (!$has_col($pdo, 'ld_task_topics', 'calculated_charge')) {
                $pdo->exec("ALTER TABLE `ld_task_topics` ADD COLUMN `calculated_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            }

            // 4. ld_intern_payments & ld_intern_payment_items self-healing check
            $has_pay_table = false;
            try {
                $has_pay_table = (bool)$pdo->query("SELECT 1 FROM `ld_intern_payments` LIMIT 1");
            } catch (Exception $e) {}
            if (!$has_pay_table) {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS ld_intern_payments (
                          id INTEGER PRIMARY KEY AUTOINCREMENT,
                          voucher_no TEXT NOT NULL UNIQUE,
                          intern_id INTEGER NOT NULL,
                          intern_username_snapshot TEXT NOT NULL,
                          intern_name_snapshot TEXT NOT NULL,
                          period_start_date TEXT NOT NULL,
                          period_end_date TEXT NOT NULL,
                          expected_amount REAL NOT NULL,
                          adjustment_amount REAL NOT NULL DEFAULT 0.00,
                          paid_amount REAL NOT NULL,
                          payment_account_id INTEGER DEFAULT NULL,
                          payment_account_name_snapshot TEXT DEFAULT NULL,
                          paid_date TEXT NOT NULL,
                          screenshot_path TEXT DEFAULT NULL,
                          remarks TEXT DEFAULT NULL,
                          status TEXT NOT NULL DEFAULT 'Completed',
                          created_by TEXT NOT NULL,
                          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          updated_at TEXT DEFAULT NULL
                        )
                    ");
                } else {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `ld_intern_payments` (
                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                          `voucher_no` VARCHAR(50) NOT NULL UNIQUE,
                          `intern_id` INT NOT NULL,
                          `intern_username_snapshot` VARCHAR(100) NOT NULL,
                          `intern_name_snapshot` VARCHAR(255) NOT NULL,
                          `period_start_date` DATE NOT NULL,
                          `period_end_date` DATE NOT NULL,
                          `expected_amount` DECIMAL(10,2) NOT NULL,
                          `adjustment_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                          `paid_amount` DECIMAL(10,2) NOT NULL,
                          `payment_account_id` INT DEFAULT NULL,
                          `payment_account_name_snapshot` VARCHAR(255) DEFAULT NULL,
                          `paid_date` DATE NOT NULL,
                          `screenshot_path` VARCHAR(255) DEFAULT NULL,
                          `remarks` TEXT DEFAULT NULL,
                          `status` VARCHAR(50) NOT NULL DEFAULT 'Completed',
                          `created_by` VARCHAR(100) NOT NULL,
                          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                          FOREIGN KEY (`payment_account_id`) REFERENCES `payment_accounts` (`id`) ON DELETE SET NULL,
                          INDEX `idx_intern_period` (`intern_id`, `period_start_date`, `period_end_date`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                }
            }

            $has_item_table = false;
            try {
                $has_item_table = (bool)$pdo->query("SELECT 1 FROM `ld_intern_payment_items` LIMIT 1");
            } catch (Exception $e) {}
            if (!$has_item_table) {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS ld_intern_payment_items (
                          id INTEGER PRIMARY KEY AUTOINCREMENT,
                          payment_id INTEGER NOT NULL,
                          work_mode_id INTEGER NOT NULL,
                          work_mode_name_snapshot TEXT NOT NULL,
                          quantity REAL NOT NULL,
                          quantity_label_snapshot TEXT DEFAULT NULL,
                          FOREIGN KEY (payment_id) REFERENCES ld_intern_payments (id) ON DELETE CASCADE
                        )
                    ");
                } else {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `ld_intern_payment_items` (
                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                          `payment_id` INT NOT NULL,
                          `work_mode_id` INT NOT NULL,
                          `work_mode_name_snapshot` VARCHAR(255) NOT NULL,
                          `quantity` DECIMAL(10,2) NOT NULL,
                          `quantity_label_snapshot` VARCHAR(100) DEFAULT NULL,
                          FOREIGN KEY (`payment_id`) REFERENCES `ld_intern_payments` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                }
            }

            // 5. self-healing ld_payment_id in expenses table & L&D Intern Payment in expense_types
            $has_expenses = false;
            try {
                $has_expenses = (bool)$pdo->query("SELECT 1 FROM `expenses` LIMIT 1");
            } catch (Exception $e) {}
            if ($has_expenses) {
                if (!$has_col($pdo, 'expenses', 'ld_payment_id')) {
                    $pdo->exec("ALTER TABLE `expenses` ADD COLUMN `ld_payment_id` INT DEFAULT NULL");
                }

                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'mysql') {
                    // Check if parent table exists
                    $has_parent = false;
                    try {
                        $has_parent = (bool)$pdo->query("SELECT 1 FROM `ld_intern_payments` LIMIT 1");
                    } catch (Exception $e) {}

                    if ($has_parent) {
                        // Check for orphaned ld_payment_id values
                        $stmt_orphan = $pdo->query("
                            SELECT COUNT(*)
                            FROM `expenses`
                            WHERE `ld_payment_id` IS NOT NULL
                              AND `ld_payment_id` NOT IN (SELECT `id` FROM `ld_intern_payments`)
                        ");
                        $orphan_count = (int)$stmt_orphan->fetchColumn();

                        if ($orphan_count > 0) {
                            error_log("L&D Migration WARNING: Found {$orphan_count} orphaned records in expenses table. Foreign key constraint 'fk_expense_ld_payment' creation deferred until resolved.");
                        } else {
                            // Check if constraint fk_expense_ld_payment already exists
                            $stmt_fk = $pdo->prepare("
                                SELECT COUNT(*)
                                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                                WHERE CONSTRAINT_NAME = 'fk_expense_ld_payment'
                                  AND TABLE_SCHEMA = DATABASE()
                            ");
                            $stmt_fk->execute();
                            $fk_exists = (int)$stmt_fk->fetchColumn();

                            if ($fk_exists === 0) {
                                $pdo->exec("
                                    ALTER TABLE `expenses`
                                    ADD CONSTRAINT `fk_expense_ld_payment`
                                    FOREIGN KEY (`ld_payment_id`)
                                    REFERENCES `ld_intern_payments` (`id`)
                                    ON DELETE SET NULL
                                ");
                            }
                        }
                    }
                }
            }

            $has_exp_types = false;
            try {
                $has_exp_types = (bool)$pdo->query("SELECT 1 FROM `expense_types` LIMIT 1");
            } catch (Exception $e) {}
            if ($has_exp_types) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM expense_types WHERE name = ?");
                $stmt->execute(['L&D Intern Payment']);
                if ((int)$stmt->fetchColumn() === 0) {
                    $pdo->prepare("INSERT INTO expense_types (name, status) VALUES (?, 'active')")->execute(['L&D Intern Payment']);
                }
            }

            $exists = true;
        } catch (Exception $e) {
            $exists = false;
        }
    }
    return $exists;
}

function is_ld_task_locked($pdo, $intern_id, $task_date) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, voucher_no, period_start_date, period_end_date
            FROM ld_intern_payments
            WHERE intern_id = ?
              AND status = 'Completed'
              AND ? BETWEEN period_start_date AND period_end_date
            LIMIT 1
        ");
        $stmt->execute([$intern_id, $task_date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Centralized helpers for Lead duplicate prevention & conversion.
 */
if (!function_exists('normalizeLeadPhone')) {
    function normalizeLeadPhone($phone) {
        $cleaned = preg_replace('/\D/', '', (string)$phone);
        if (strlen($cleaned) === 11 && strpos($cleaned, '0') === 0) {
            $cleaned = substr($cleaned, 1);
        }
        if (strlen($cleaned) === 10) {
            $cleaned = '91' . $cleaned;
        }
        return $cleaned;
    }
}

if (!function_exists('normalizeLeadCourse')) {
    function normalizeLeadCourse($course) {
        if ($course === null) return '';
        return strtolower(trim(preg_replace('/\s+/', ' ', (string)$course)));
    }
}

if (!function_exists('checkLeadDuplicate')) {
    function checkLeadDuplicate($pdo, $phone, $course, $excludeLeadId = null, $inTransaction = false) {
        $normPhone = normalizeLeadPhone($phone);
        $normCourse = normalizeLeadCourse($course);
        if (empty($normPhone)) {
            return ['count' => 0, 'matches' => []];
        }

        $last10 = substr($normPhone, -10);
        $isSqlite = false;
        try {
            $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        } catch (Exception $e) {}
        $lockSql = ($inTransaction && !$isSqlite) ? " FOR UPDATE" : "";

        // Ignore leads whose status is 'rejected'
        $stmt = $pdo->prepare("
            SELECT id, whatsapp_number, interested_course, status, name
            FROM leads
            WHERE (whatsapp_number LIKE ? OR whatsapp_number LIKE ?)
              AND status <> 'rejected'
            " . $lockSql
        );
        $stmt->execute(['%' . $last10, $normPhone]);
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matches = [];
        foreach ($leads as $l) {
            if ($excludeLeadId !== null && (int)$l['id'] === (int)$excludeLeadId) {
                continue;
            }
            if (normalizeLeadPhone($l['whatsapp_number']) === $normPhone &&
                normalizeLeadCourse($l['interested_course']) === $normCourse) {
                $matches[] = $l;
            }
        }

        return [
            'count' => count($matches),
            'matches' => $matches
        ];
    }
}

if (!function_exists('acquireLeadLock')) {
    function acquireLeadLock($pdo, $phone, $course) {
        $normPhone = normalizeLeadPhone($phone);
        $normCourse = normalizeLeadCourse($course);
        $lockName = 'lead_lock_' . $normPhone . '_' . md5($normCourse);
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, 10)");
        $stmt->execute([$lockName]);
        return (int)$stmt->fetchColumn() === 1;
    }
}

if (!function_exists('releaseLeadLock')) {
    function releaseLeadLock($pdo, $phone, $course) {
        $normPhone = normalizeLeadPhone($phone);
        $normCourse = normalizeLeadCourse($course);
        $lockName = 'lead_lock_' . $normPhone . '_' . md5($normCourse);
        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([$lockName]);
    }
}

if (!function_exists('convertLeadFromApprovedAdmission')) {
    function convertLeadFromApprovedAdmission($pdo, $leadId, $studentUserId, $adminUsername) {
        try {
            $stmtExist = $pdo->prepare("SELECT status FROM leads WHERE id = ?");
            $stmtExist->execute([$leadId]);
            $status = $stmtExist->fetchColumn();
            if ($status === 'converted') {
                return true; // Idempotent
            }

            $stmtUpdate = $pdo->prepare("
                UPDATE leads
                SET status = 'converted',
                    converted_user_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUpdate->execute([$studentUserId, $leadId]);

            // Log lead activity timeline
            if (function_exists('lead_log')) {
                lead_log($pdo, $leadId, 'status_change', "Lead marked converted via matched approved student #{$studentUserId}", $status, 'converted', null, $adminUsername);
            } else {
                $stmtAct = $pdo->prepare("
                    INSERT INTO lead_activity (lead_id, activity_type, remark, old_status, new_status, performed_by, performed_at)
                    VALUES (?, 'status_change', ?, ?, 'converted', ?, NOW())
                ");
                $stmtAct->execute([$leadId, "Lead marked converted via matched approved student #{$studentUserId}", $status, $adminUsername]);
                $pdo->prepare("UPDATE leads SET last_activity_at = NOW() WHERE id = ?")->execute([$leadId]);
            }

            // Log admin activity
            if (function_exists('log_admin_activity')) {
                log_admin_activity($pdo, $adminUsername, 'lead_converted', "Lead #{$leadId} marked converted for Student #{$studentUserId}");
            } else {
                $stmtLog = $pdo->prepare("
                    INSERT INTO admin_activity_log (username, action_type, description, ip_address, user_agent, timestamp)
                    VALUES (?, 'lead_converted', ?, ?, ?, NOW())
                ");
                $stmtLog->execute([$adminUsername, "Lead #{$leadId} marked converted for Student #{$studentUserId}", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $_SERVER['HTTP_USER_AGENT'] ?? 'CLI']);
            }

            return true;
        } catch (Exception $e) {
            error_log("Failed to convert lead #{$leadId}: " . $e->getMessage());
            return false;
        }
    }
}

function has_template_access($pdo, $admin_username, $template_id) {
    if (is_super_admin()) {
        return true;
    }
    if (!can_access('cards')) {
        return false;
    }
    if (!$pdo) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$admin_username]);
        $admin_id = $stmt->fetchColumn();
        if (!$admin_id) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM card_template_admin_access WHERE template_id = ? AND admin_user_id = ?");
        $stmt->execute([$template_id, $admin_id]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

