<?php
if (session_status() === PHP_SESSION_NONE) {
    if (!session_save_path()) {
        $sessDir = sys_get_temp_dir() . '/php_sessions';
        if (!is_dir($sessDir)) @mkdir($sessDir, 0777, true);
        if (is_dir($sessDir)) session_save_path($sessDir);
    }
    session_start();
}
require_once 'includes/activity_logger.php';

// Already logged in → redirect to first accessible page
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    require_once 'config/database.php';
    $redirect = 'dashboard.php';
    try {
        $has_admins = false;
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $has_admins = ($driver === 'sqlite')
                ? (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admins'")->fetchColumn()
                : (bool)$pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
        } catch (Exception $e) {}
        if ($has_admins) {
            $stmt = $pdo->prepare("SELECT role, permissions FROM admins WHERE username = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$_SESSION['admin_username']]);
            $row = $stmt->fetch();
            if ($row && $row['role'] !== 'super_admin' && !empty($row['permissions'])) {
                $raw_perms = $row['permissions'];
                $perms = is_array($decoded = json_decode($raw_perms, true)) ? $decoded : array_map('trim', explode(',', $raw_perms));
                if (!in_array('dashboard', $perms, true)) {
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
                        'studyplans'   => 'studyplans.php',
                        'settings'     => 'settings.php',
                    ];
                    foreach ($page_urls as $k => $u) {
                        if (in_array($k, $perms, true)) {
                            $redirect = $u;
                            break;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {}
    header('Location: ' . $redirect);
    exit();
}

$error_message = '';
$info_message  = isset($_GET['expired']) ? 'Your session expired. Please log in again.' : '';

// ── Brute-force throttle: 5 failed attempts → 10 minute lockout ──
$max_attempts = 5;
$lock_seconds = 600;
$attempts  = $_SESSION['login_attempts']  ?? 0;
$lock_time = $_SESSION['login_lock_time'] ?? 0;
$locked = ($attempts >= $max_attempts && (time() - $lock_time) < $lock_seconds);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    /*
     * Authentication order:
     * 1. admins table (multi-admin system; run database-update-2.sql once).
     *    An EMPTY password_hash means "fresh seed": the default password
     *    (admin123@pepp) is accepted ONCE and a secure hash is stored
     *    immediately, after which the default stops working.
     * 2. Legacy fallback (admins table absent): admin_settings hash or the
     *    default credentials - same behavior as before the migration.
     */
    $valid = false;
    $role  = 'super_admin';
    try {
        require_once 'config/database.php';

        $has_admins = false;
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $has_admins = ($driver === 'sqlite')
                ? (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admins'")->fetchColumn()
                : (bool)$pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
        } catch (Exception $e) {}

        if ($has_admins) {
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            if ($row) {
                if ($row['password_hash'] === '' && $row['role'] === 'super_admin') {
                    // Freshly seeded super admin: accept default once, hash it
                    if ($password === 'admin123@pepp') {
                        $valid = true;
                        $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?")
                            ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
                    }
                } elseif ($row['password_hash'] !== '' && password_verify($password, $row['password_hash'])) {
                    $valid = true;
                }
                if ($valid) {
                    $role = $row['role'];
                    $pdo->prepare("UPDATE admins SET last_login_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $row['id']]);
                }
            }
        } else {
            // Legacy single-admin mode
            $stored_user = 'peppadmin';
            $stored_hash = null;
            $stmt = $pdo->prepare("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name IN ('admin_username','admin_password_hash')");
            $stmt->execute();
            foreach ($stmt->fetchAll() as $r) {
                if ($r['setting_name'] === 'admin_username')      $stored_user = $r['setting_value'];
                if ($r['setting_name'] === 'admin_password_hash') $stored_hash = $r['setting_value'];
            }
            $valid = $stored_hash
                ? (hash_equals($stored_user, $username) && password_verify($password, $stored_hash))
                : ($username === 'peppadmin' && $password === 'admin123@pepp');
        }
    } catch (Exception $e) {
        error_log('Login DB check failed: ' . $e->getMessage());
    }

    if ($valid) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username']  = $username;
        $_SESSION['admin_role']      = $role;
        $_SESSION['admin_id']        = isset($row['id']) ? $row['id'] : null;
        $_SESSION['session_ref']     = bin2hex(random_bytes(16));
        $_SESSION['login_time']      = time();
        $_SESSION['last_activity']   = time();
        $_SESSION['login_attempts']  = 0;

        // ── Record login with IP + approximate location ──────────
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $location = 'Unknown';
        if ($ip && !preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|::1)/', $ip)) {
            $ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $geo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city", false, $ctx);
            if ($geo && ($g = json_decode($geo, true)) && ($g['status'] ?? '') === 'success') {
                $location = trim(implode(', ', array_filter([$g['city'] ?? '', $g['regionName'] ?? '', $g['country'] ?? ''])));
            }
        } else {
            $location = 'Local / private network';
        }
        $_SESSION['admin_location'] = $location;
        log_login($pdo, $username, $_SESSION['admin_id'], $_SESSION['session_ref']);

        $redirect = 'dashboard.php';
        if ($role !== 'super_admin' && isset($row) && !empty($row['permissions'])) {
            $raw_perms = $row['permissions'];
            $perms = is_array($decoded = json_decode($raw_perms, true)) ? $decoded : array_map('trim', explode(',', $raw_perms));
            if (!in_array('dashboard', $perms, true)) {
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
                    'studyplans'   => 'studyplans.php',
                    'settings'     => 'settings.php',
                ];
                foreach ($page_urls as $k => $u) {
                    if (in_array($k, $perms, true)) {
                        $redirect = $u;
                        break;
                    }
                }
            }
        }
        header('Location: ' . $redirect);
        exit();
    }

    $_SESSION['login_attempts'] = $attempts + 1;
    $_SESSION['login_lock_time'] = time();
    $remaining = $max_attempts - $_SESSION['login_attempts'];
    $error_message = $remaining > 0
        ? "Invalid username or password. {$remaining} attempt(s) remaining."
        : 'Too many failed attempts. Please wait 10 minutes and try again.';

    // Log failed login attempt
    $fail_reason = $remaining > 0 ? 'Invalid credentials' : 'Locked out due to excessive failed attempts';
    log_failed_login($pdo, $username, $fail_reason);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $locked) {
    $wait = ceil(($lock_seconds - (time() - $lock_time)) / 60);
    $error_message = "Account temporarily locked. Try again in about {$wait} minute(s).";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - PEPP Learning</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: #f8fafc;
            background-image:
                radial-gradient(ellipse 60% 50% at 12% 0%, #ede9fe 0%, transparent 55%),
                radial-gradient(ellipse 50% 45% at 95% 100%, #d1fae5 0%, transparent 50%),
                radial-gradient(ellipse 40% 40% at 80% 10%, #fef3c7 0%, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .login-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 2.5rem 2.25rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 10px 40px rgba(15,23,42,.08);
        }
        .login-brand { text-align: center; margin-bottom: 1.75rem; }
        .login-brand img {
            width: 64px; height: 64px;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: 0 4px 16px rgba(232,152,12,.3);
            margin-bottom: 0.9rem;
        }
        .login-brand h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: #1f2937;
        }
        .login-brand p { font-size: 0.8rem; color: #9ca3af; font-weight: 500; margin-top: 2px; }
        .alert {
            display: flex; gap: 9px; align-items: flex-start;
            border-radius: 10px; padding: 11px 14px;
            font-size: 0.82rem; font-weight: 500; margin-bottom: 1.1rem;
        }
        .alert i { margin-top: 2px; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-info  { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .field { margin-bottom: 1rem; }
        .field label {
            display: block;
            font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
            color: #6b7280; margin-bottom: 5px;
        }
        .field input {
            width: 100%;
            font-family: inherit; font-size: 0.9rem;
            padding: 11px 13px;
            border: 1.5px solid #e5e7eb; border-radius: 10px;
            transition: border-color .15s, box-shadow .15s;
        }
        .field input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px #ede9fe;
        }
        .btn-login {
            width: 100%;
            background: #8b5cf6; color: #fff;
            border: none; border-radius: 10px;
            padding: 12px;
            font-family: inherit; font-size: 0.92rem; font-weight: 700;
            cursor: pointer;
            transition: background .15s, transform .15s;
            margin-top: 0.4rem;
        }
        .btn-login:hover { background: #7c3aed; transform: translateY(-1px); }
        .btn-login:disabled { opacity: .55; cursor: not-allowed; }
        .login-foot {
            text-align: center;
            margin-top: 1.4rem;
            font-size: 0.72rem;
            color: #9ca3af;
        }

        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-wrapper input {
            padding-right: 42px;
        }
        .btn-toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 6px;
            cursor: pointer;
            color: #9ca3af;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color .15s ease;
            border-radius: 6px;
        }
        .btn-toggle-password:hover {
            color: #4b5563;
        }
        .btn-toggle-password:focus-visible {
            outline: 2px solid #8b5cf6;
            outline-offset: 2px;
        }
        .btn-google {
            display: flex; align-items: center; justify-content: center;
            width: 100%; padding: 12px; border-radius: 10px;
            border: 1.5px solid #e2e8f0; background: #fff; color: #1f2937;
            font-weight: 600; font-size: .92rem; text-decoration: none;
            transition: all .18s ease; margin-bottom: 4px;
        }
        .btn-google:hover { border-color: #cbd5e1; background: #f8fafc; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .login-or { display:flex; align-items:center; gap:12px; margin: 16px 0; color:#94a3b8; font-size:.78rem; }
        .login-or::before, .login-or::after { content:''; flex:1; height:1px; background:#e2e8f0; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-brand">
            <img src="logo.png" alt="PEPP Learning">
            <h1>PEPP Learning</h1>
            <p>Admin Console Sign-in</p>
        </div>

        <?php if ($info_message): ?>
            <div class="alert alert-info"><i class="fas fa-circle-info"></i><span><?php echo htmlspecialchars($info_message); ?></span></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo htmlspecialchars($error_message); ?></span></div>
        <?php endif; ?>

        <?php
        $gerrs = [
            'state' => 'Google sign-in failed (session expired). Please try again.',
            'nocode' => 'Google sign-in was cancelled.',
            'exchange' => 'Could not verify your Google account. Please try again.',
            'notregistered' => 'This Google account is not registered as an admin. Contact the Super Admin.',
        ];
        if (isset($_GET['gerr']) && isset($gerrs[$_GET['gerr']])):
        ?>
        <div class="login-error" style="margin-bottom:14px;"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($gerrs[$_GET['gerr']]); ?></div>
        <?php endif; ?>

        <a href="google-callback.php?start=1" class="btn-google" <?php echo $locked ? 'style="pointer-events:none;opacity:.5;"' : ''; ?>>
            <svg width="18" height="18" viewBox="0 0 48 48" style="vertical-align:middle;margin-right:8px;"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.5 0 10.5-2.1 14.3-5.6l-6.6-5.6c-2 1.5-4.6 2.4-7.7 2.4-5.2 0-9.6-3.3-11.2-8l-6.5 5C9.6 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.1-4 5.4l6.6 5.6C40.9 36.7 44 31 44 24c0-1.3-.1-2.3-.4-3.5z"/></svg>
            Sign in with Google
        </a>
        <div class="login-or"><span>or sign in with username</span></div>

        <form method="POST" action="" autocomplete="on">
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autocomplete="username"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" <?php echo $locked ? 'disabled' : ''; ?>>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required autocomplete="current-password" <?php echo $locked ? 'disabled' : ''; ?>>
                    <button type="button" id="toggle-password" class="btn-toggle-password" aria-label="Toggle password visibility" title="Show/Hide password" tabindex="-1" <?php echo $locked ? 'disabled' : ''; ?>>
                        <i class="fas fa-eye" id="toggle-password-icon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login" <?php echo $locked ? 'disabled' : ''; ?>>
                <i class="fas fa-right-to-bracket"></i>&nbsp; Sign in
            </button>
        </form>

        <div class="login-foot">&copy; <?php echo date('Y'); ?> PEPP Learning - Authorized personnel only</div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('toggle-password');
        const pwdInput = document.getElementById('password');
        const eyeIcon = document.getElementById('toggle-password-icon');

        if (toggleBtn && pwdInput && eyeIcon) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const isPassword = pwdInput.type === 'password';
                pwdInput.type = isPassword ? 'text' : 'password';
                
                if (isPassword) {
                    eyeIcon.classList.remove('fa-eye');
                    eyeIcon.classList.add('fa-eye-slash');
                    toggleBtn.setAttribute('aria-label', 'Hide password');
                } else {
                    eyeIcon.classList.remove('fa-eye-slash');
                    eyeIcon.classList.add('fa-eye');
                    toggleBtn.setAttribute('aria-label', 'Show password');
                }
            });
        }
    });
    </script>
</body>
</html>
