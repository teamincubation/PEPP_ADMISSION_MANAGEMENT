<?php
/**
 * PEPP Learning - Google OAuth callback for ADMIN sign-in.
 * Only admins whose google_email (or email) matches the Google account, and
 * who are active, are allowed in. No self-registration.
 */
session_start();
require_once 'config/database.php';
require_once 'config/google_oauth.php';

$redirect_uri = pepp_base_url() . '/google-callback.php';

// Start flow (purpose=alumni for the public portal, default admin)
if (isset($_GET['start'])) {
    $startPurpose = (($_GET['purpose'] ?? '') === 'alumni') ? 'alumni' : 'admin';
    google_redirect($startPurpose, $redirect_uri);
}

// Read purpose BEFORE exchanging the code (so we use the right client + error page)
$purpose = $_SESSION['google_purpose'] ?? 'admin';
$err_target = $purpose === 'alumni' ? 'alumni-portal.php?err=google' : 'login.php?gerr=';

// Validate state
if (($_GET['state'] ?? '') === '' || ($_GET['state'] ?? '') !== ($_SESSION['google_state'] ?? '~')) {
    header('Location: ' . ($purpose === 'alumni' ? 'alumni-portal.php?err=google' : 'login.php?gerr=state')); exit();
}
unset($_SESSION['google_state']);

$code = $_GET['code'] ?? '';
if ($code === '') { header('Location: ' . ($purpose === 'alumni' ? 'alumni-portal.php?err=google' : 'login.php?gerr=nocode')); exit(); }

$profile = google_exchange($code, $redirect_uri, $purpose);
if (!$profile) { header('Location: ' . ($purpose === 'alumni' ? 'alumni-portal.php?err=google' : 'login.php?gerr=exchange')); exit(); }

$gemail = $profile['email'];
unset($_SESSION['google_purpose']);

// ── Alumni portal sign-in / sign-up via Google ──
if ($purpose === 'alumni') {
    session_regenerate_id(true);
    try {
        $stmt = $pdo->prepare("SELECT * FROM peppians WHERE email LIKE ? LIMIT 5");
        $stmt->execute([$gemail]);
        $pep = null; foreach ($stmt->fetchAll() as $cand) { if (strtolower((string)$cand['email']) === $gemail) { $pep = $cand; break; } }
        if (!$pep) {
            // Create a Google-based PEPPian; WhatsApp collected in next step
            $pdo->prepare("INSERT INTO peppians (full_name, email, password_hash, google_id, auth_provider, verified, created_at) VALUES (?,?,'',?, 'google', 0, NOW())")
                ->execute([$profile['name'] ?: $gemail, $gemail, $profile['sub']]);
            $pid = $pdo->lastInsertId();
        } else {
            $pid = $pep['id'];
            $pdo->prepare("UPDATE peppians SET google_id = ?, auth_provider = IF(auth_provider='password','password','google'), last_login_at = NOW() WHERE id = ?")
                ->execute([$profile['sub'], $pid]);
        }
        $_SESSION['peppian_id'] = (int)$pid;
        $_SESSION['peppian_email'] = $gemail;
    } catch (Exception $e) { error_log('alumni google: ' . $e->getMessage()); header('Location: alumni-portal.php?err=google'); exit(); }
    header('Location: alumni-portal.php');
    exit();
}

// ── Otherwise: ADMIN sign-in (default) ──

try {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE status = 'active' AND (LOWER(google_email) = ? OR LOWER(email) = ?) LIMIT 1");
    $stmt->execute([$gemail, $gemail]);
    $admin = $stmt->fetch();
} catch (Exception $e) { error_log('google admin lookup: ' . $e->getMessage()); $admin = null; }

if (!$admin) {
    header('Location: login.php?gerr=notregistered'); exit();
}

// Sign in
session_regenerate_id(true);
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username']  = $admin['username'];
$_SESSION['admin_role']      = $admin['role'];
$_SESSION['login_time']      = time();
$_SESSION['last_activity']   = time();
$_SESSION['login_attempts']  = 0;

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$location = 'Unknown';
if ($ip && !preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|::1)/', $ip)) {
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $geo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city", false, $ctx);
    if ($geo && ($g = json_decode($geo, true)) && ($g['status'] ?? '') === 'success') {
        $location = trim(implode(', ', array_filter([$g['city'] ?? '', $g['regionName'] ?? '', $g['country'] ?? ''])));
    }
} else { $location = 'Local / private network'; }
$_SESSION['admin_location'] = $location;

try {
    $pdo->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?")->execute([$admin['id']]);
    $pdo->prepare("INSERT INTO admin_activity_log (admin_username, action_type, details, ip_address, location, user_agent, created_at) VALUES (?, 'login', ?, ?, ?, ?, NOW())")
        ->execute([$admin['username'], 'Signed in with Google (' . ($admin['role'] === 'super_admin' ? 'Super Admin' : 'Admin') . ')', $ip, $location, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
} catch (Exception $e) { error_log('google login log: ' . $e->getMessage()); }

header('Location: dashboard.php');
exit();
