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

$GLOBALS['ADMIN_PAGES'] = [
    'dashboard'    => ['Dashboard',               'fa-gauge-high'],
    'approvals'    => ['Student Approvals',       'fa-user-check'],
    'add-student'  => ['Add Student',             'fa-user-plus'],
    'students'     => ['All Students & Profiles', 'fa-users'],
    'onboarding'   => ['Student Onboarding',      'fa-handshake'],
    'installments' => ['Installment Payments',    'fa-money-bill-wave'],
    'whatsapp'     => ['WhatsApp Messages',       'fa-comment'],
    'invoices'     => ['Invoices',                'fa-file-invoice'],
    'courses'      => ['Course Management',       'fa-book-open'],
    'faculties'    => ['Faculties',               'fa-chalkboard-user'],
    'sessions'     => ['Sessions',                'fa-video'],
    'accounts'     => ['Accounts & Expenses',     'fa-wallet'],
    'leads'        => ['Lead Management',         'fa-user-tag'],
    'marketing'    => ['Marketing',               'fa-bullhorn'],
    'alumni'       => ['Alumni Database',         'fa-user-graduate'],
    'peppkit'      => ['PEPPKIT Report',          'fa-box-open'],
    'cards'        => ['Generate Cards',          'fa-id-card'],
    'settings'     => ['Settings',                'fa-gear'],
    // Future pages: add 'key' => ['Label', 'fa-icon'] and grant in Admin Management.
];

/* ── Does the multi-admin system exist yet? (graceful pre-migration mode) ── */
function admins_table_exists($pdo) {
    static $exists = null;
    if ($exists === null) {
        try { $exists = (bool)$pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn(); }
        catch (Exception $e) { $exists = false; }
    }
    return $exists;
}
function ensure_credential_visibility_column($pdo) {
    if (!admins_table_exists($pdo)) return;
    static $ensured = false;
    if ($ensured) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM admins LIKE 'credential_visibility'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN `credential_visibility` ENUM('visible', 'hide', 'mask') NOT NULL DEFAULT 'visible'");
        }
        $ensured = true;
    } catch (Exception $e) {
        error_log("Failed to ensure admins.credential_visibility: " . $e->getMessage());
    }
}

/* ── Activity logging (logins, logouts, exports, admin events) ──────────── */
function log_admin_activity($pdo, $admin, $type, $details = '', $ip = null, $location = null) {
    try {
        if (!admins_table_exists($pdo)) return; // table ships with the same migration
        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_log (admin_username, action_type, details, ip_address, location, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $admin, $type, $details,
            $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            $location ?? ($_SESSION['admin_location'] ?? null),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    } catch (Exception $e) { error_log('admin activity log: ' . $e->getMessage()); }
}

/* ── Logout ─────────────────────────────────────────────────────────────── */
if (isset($_GET['logout'])) {
    if (!empty($_SESSION['admin_username'])) {
        log_admin_activity($pdo, $_SESSION['admin_username'], 'logout', 'Manual logout');
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
if (admins_table_exists($pdo)) {
    ensure_credential_visibility_column($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([$admin_username]);
        $admin_row = $stmt->fetch();
        if (!$admin_row || $admin_row['status'] !== 'active') {
            // Deactivated or removed while logged in → end the session
            log_admin_activity($pdo, $admin_username, 'forced_logout', 'Account inactive or removed');
            session_unset(); session_destroy();
            header('Location: login.php?expired=1');
            exit();
        }
        $admin_role  = $admin_row['role'];
        $admin_perms = (string)($admin_row['permissions'] ?? '');
        $admin_credential_visibility = $admin_row['credential_visibility'] ?? 'visible';
    } catch (Exception $e) { error_log('auth admin load: ' . $e->getMessage()); }
}
$_SESSION['admin_role'] = $admin_role;

/* ── Inactivity timeout: 20 min for admins, 2 h for the Super Admin ─────── */
$timeout = ($admin_role === 'super_admin') ? 2 * 60 * 60 : 20 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    log_admin_activity($pdo, $admin_username, 'auto_logout',
        'Automatically logged out after ' . round($timeout / 60) . ' minutes of inactivity');
    session_unset();
    session_destroy();
    header('Location: login.php?expired=1');
    exit();
}
$_SESSION['last_activity'] = time();

/* ── Role / permission helpers ──────────────────────────────────────────── */
function is_super_admin() {
    return ($_SESSION['admin_role'] ?? '') === 'super_admin';
}
function can_access($page_key) {
    global $admin_perms;
    if (is_super_admin()) return true;
    if (trim($admin_perms) === 'ALL') return true;
    return in_array($page_key, array_map('trim', explode(',', $admin_perms)), true);
}
function get_first_accessible_page_url() {
    global $admin_perms;
    if (is_super_admin() || trim($admin_perms) === 'ALL') {
        return 'dashboard.php';
    }
    $page_urls = [
        'dashboard'    => 'dashboard.php',
        'approvals'    => 'student-approval.php',
        'add-student'  => 'add-student.php',
        'students'     => 'studentpage.php',
        'onboarding'   => 'studentonboarding.php',
        'sessions'     => 'sessions.php',
        'leads'        => 'lead-management.php',
        'marketing'    => 'marketing.php',
        'alumni'       => 'alumni-database.php',
        'peppkit'      => 'peppkit-report.php',
        'cards'        => 'cards.php',
        'accounts'     => 'accounts.php',
        'installments' => 'phpinstalmentpaymentupdate.php',
        'invoices'     => 'invoices.php',
        'whatsapp'     => 'whatsapp-notification.php',
        'courses'      => 'course-management.php',
        'faculties'    => 'faculties.php',
        'settings'     => 'settings.php',
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
        $stmt = $pdo->prepare("INSERT INTO track_records (user_id, action_type, action_details, performed_by, performed_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action_type, $details, $admin]);
    } catch (Exception $ex) { error_log('track_record: ' . $ex->getMessage()); }
}

/* ── Status log helper ──────────────────────────────────────────────────── */
function status_log($pdo, $user_id, $old, $new, $reason, $admin) {
    try {
        $stmt = $pdo->prepare("INSERT INTO student_status_log (user_id, old_status, new_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $old, $new, $reason, $admin]);
    } catch (Exception $ex) { error_log('status_log: ' . $ex->getMessage()); }
}

function format_credential($value, $type) {
    global $admin_credential_visibility;
    $value = trim((string)$value);
    if ($value === '') return '';
    
    // Default visibility is 'visible' for super admins
    $vis = is_super_admin() ? 'visible' : ($admin_credential_visibility ?? 'visible');
    
    if ($vis === 'visible') {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    
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

function format_credential_text($value, $type) {
    global $admin_credential_visibility;
    $value = trim((string)$value);
    if ($value === '') return '';
    
    $vis = is_super_admin() ? 'visible' : ($admin_credential_visibility ?? 'visible');
    
    if ($vis === 'visible') {
        return $value;
    }
    
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
