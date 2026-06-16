<?php
/**
 * PEPP Learning — Google OAuth configuration (shared by admin login and the
 * alumni portal). The redirect URI must be registered in the Google Cloud
 * console for this client. We use the standard OAuth 2.0 web flow (no SDK).
 */
// Admin login OAuth client
define('GOOGLE_CLIENT_ID', '584799929473-017vjabprb7eb1ptbbanmlteen17avce.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');

// Alumni portal OAuth client (separate Google project/client)
define('GOOGLE_ALUMNI_CLIENT_ID', '373139526353-skuafbth6s0jp3h71l8s65tqfgk1aupe.apps.googleusercontent.com');
define('GOOGLE_ALUMNI_CLIENT_SECRET', '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');

/** Pick the right client credentials for a purpose ('admin' | 'alumni'). */
function google_client($purpose) {
    if ($purpose === 'alumni') {
        return ['id' => GOOGLE_ALUMNI_CLIENT_ID, 'secret' => GOOGLE_ALUMNI_CLIENT_SECRET];
    }
    return ['id' => GOOGLE_CLIENT_ID, 'secret' => GOOGLE_CLIENT_SECRET];
}

/** Build the absolute base URL of the /admissions/ folder. */
function pepp_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'pepplearning.in';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admissions/index.php'), '/\\');
    return $scheme . '://' . $host . $dir;
}

/** Start the Google consent flow. $purpose = 'admin' | 'alumni'. */
function google_redirect($purpose, $redirect_uri) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_state'] = $state;
    $_SESSION['google_purpose'] = $purpose;
    $cli = google_client($purpose);
    $params = [
        'client_id' => $cli['id'],
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ];
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    exit();
}

/** Exchange the code for the user's Google profile. Returns [email, sub, name] or null. */
function google_exchange($code, $redirect_uri, $purpose = 'admin') {
    $cli = google_client($purpose);
    $post = http_build_query([
        'code' => $code,
        'client_id' => $cli['id'],
        'client_secret' => $cli['secret'],
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code',
    ]);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { error_log('google token curl: ' . curl_error($ch)); curl_close($ch); return null; }
    curl_close($ch);
    $tok = json_decode($resp, true);
    if (empty($tok['access_token'])) { error_log('google token: ' . $resp); return null; }

    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['access_token']],
    ]);
    $info = curl_exec($ch);
    curl_close($ch);
    $u = json_decode($info, true);
    if (empty($u['email'])) return null;
    return ['email' => strtolower($u['email']), 'sub' => $u['id'] ?? '', 'name' => $u['name'] ?? ''];
}
