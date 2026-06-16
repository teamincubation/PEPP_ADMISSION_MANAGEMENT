<?php
/**
 * PEPP Learning - Google OAuth callback for the ALUMNI PORTAL.
 * Uses the alumni OAuth client. Registered redirect URI:
 *   https://pepplearning.in/admissions/alumni-google-callback.php
 * Signs in an existing PEPPian or creates a new one (WhatsApp collected next).
 */
session_start();
require_once 'config/database.php';
require_once 'config/google_oauth.php';

$redirect_uri = pepp_base_url() . '/alumni-google-callback.php';

// Begin the consent flow
if (isset($_GET['start'])) {
    google_redirect('alumni', $redirect_uri);
}

// Validate state
if (($_GET['state'] ?? '') === '' || ($_GET['state'] ?? '') !== ($_SESSION['google_state'] ?? '~')) {
    header('Location: alumni-portal.php?err=google'); exit();
}
unset($_SESSION['google_state'], $_SESSION['google_purpose']);

$code = $_GET['code'] ?? '';
if ($code === '') { header('Location: alumni-portal.php?err=google'); exit(); }

$profile = google_exchange($code, $redirect_uri, 'alumni');
if (!$profile || empty($profile['email'])) { header('Location: alumni-portal.php?err=google'); exit(); }

$gemail = strtolower($profile['email']);

try {
    $stmt = $pdo->prepare("SELECT * FROM peppians WHERE email LIKE ? LIMIT 5");
    $stmt->execute([$gemail]);
    $pep = null; foreach ($stmt->fetchAll() as $cand) { if (strtolower((string)$cand['email']) === $gemail) { $pep = $cand; break; } }
    if (!$pep) {
        $pdo->prepare("INSERT INTO peppians (full_name, email, password_hash, google_id, auth_provider, verified, created_at) VALUES (?,?,'',?, 'google', 0, NOW())")
            ->execute([$profile['name'] ?: $gemail, $gemail, $profile['sub']]);
        $pid = (int)$pdo->lastInsertId();
    } else {
        $pid = (int)$pep['id'];
        $pdo->prepare("UPDATE peppians SET google_id = ?, last_login_at = NOW() WHERE id = ?")->execute([$profile['sub'], $pid]);
    }
    session_regenerate_id(true);
    $_SESSION['peppian_id'] = $pid;
    $_SESSION['peppian_email'] = $gemail;
} catch (Exception $e) {
    error_log('alumni google callback: ' . $e->getMessage());
    header('Location: alumni-portal.php?err=google'); exit();
}

header('Location: alumni-portal.php');
exit();
