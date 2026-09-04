<?php
/**
 * PEPP Learning - Alumni Portal (public).
 * PEPPian registration (password or Google) → WhatsApp completion (Google) →
 * alumni verification against the Super Admin's alumni database → referral
 * dashboard (apply for the active program, get a referral code & shareable
 * link/coupon, track earnings).
 */
session_start();
require_once 'config/database.php';
require_once 'includes/referral_helper.php';
require_once 'includes/peppian_notify.php';
require_once 'includes/file_helper.php';

function pep_e($s) {
    $str = (string)$s;
    if (strpos($str, 'uploads/') === 0) {
        $str = '../' . $str;
    }
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
function pep_csrf() {
    if (empty($_SESSION['pep_csrf'])) $_SESSION['pep_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['pep_csrf'];
}
function pep_csrf_ok() { return isset($_POST['csrf']) && hash_equals($_SESSION['pep_csrf'] ?? '', $_POST['csrf']); }
function pep_norm_phone($p) {
    $d = preg_replace('/\D/', '', (string)$p);   // keep digits only (drops +, spaces, dashes)
    if ($d === '') return '';
    // Drop a leading country code so 10-digit, 12-digit (+91) and 0-prefixed all match
    if (strlen($d) > 10) $d = substr($d, -10);
    return $d;
}

function pep_referral_link($code) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'pepplearning.in';
    $scriptDir = !empty($_SERVER['SCRIPT_NAME']) ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') : '/admissions';
    if ($scriptDir === '' || $scriptDir === '.') {
        $scriptDir = '/admissions';
    }
    $baseUrl = $scheme . '://' . $host . $scriptDir;
    return $baseUrl . '/register.php?ref=' . urlencode((string)$code);
}

function sync_peppian_to_alumni($pdo, $peppian_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM peppians WHERE id = ?");
        $stmt->execute([$peppian_id]);
        $peppian = $stmt->fetch();
        if ($peppian && $peppian['verified'] && $peppian['linked_alumni_id']) {
            $alumni_id = $peppian['linked_alumni_id'];
            
            $prof_details = null;
            if ($peppian['current_status'] || $peppian['current_profession'] || $peppian['working_institute']) {
                $prof_details = json_encode([
                    'status' => $peppian['current_status'],
                    'profession' => $peppian['current_profession'],
                    'working_institute' => $peppian['working_institute']
                ]);
            }
            
            $stmt = $pdo->prepare("
                UPDATE alumni SET 
                    academic_track_after_pepp = ?,
                    current_profession_details = ?,
                    profile_photo = ?,
                    is_verified = 1,
                    synced_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $peppian['academic_tracks'],
                $prof_details,
                $peppian['profile_picture'],
                $alumni_id
            ]);
        }
    } catch (Exception $e) {
        error_log("Failed to sync peppian {$peppian_id} to alumni: " . $e->getMessage());
    }
}


$DEFAULT_HOW_TO_EARN = "1. Share your unique referral link or coupon card with prospective learners who want to join PEPP.\n"
    . "2. Ensure they apply your referral code during their registration on PEPP.\n"
    . "3. Once their registration is approved by the admin and onboarding checklist is completed, your referral earning is credited to your wallet.\n"
    . "4. Request a payout from your wallet balance to receive the money directly into your bank account or UPI ID.";

$portal_ready = pepp_tables_exist($pdo, ['peppians', 'alumni']);

// Self-healing migration for how_to_earn column
if ($portal_ready) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM referral_programs LIKE 'how_to_earn'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE referral_programs ADD COLUMN how_to_earn TEXT DEFAULT NULL");
        }
    } catch (Exception $e) {}
}

$msg = ''; $err = '';
if (isset($_GET['err']) && $_GET['err'] === 'google') $err = 'Google sign-in failed. Please try again.';

/* ── Logout ── */
if (isset($_GET['logout'])) { unset($_SESSION['peppian_id'], $_SESSION['peppian_email']); header('Location: alumni-portal.php'); exit(); }

/* ── POST actions ── */
if ($portal_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!pep_csrf_ok()) { $err = 'Session expired. Please try again.'; }
    else {
        $act = $_POST['act'] ?? '';
        try {
            if ($act === 'register') {
                $name = trim($_POST['full_name'] ?? '');
                $email = strtolower(trim($_POST['email'] ?? ''));
                $wa = trim($_POST['whatsapp'] ?? '');
                $pw = $_POST['password'] ?? '';
                $pw2 = $_POST['confirm'] ?? '';
                if ($name === '' || $email === '' || $wa === '' || $pw === '') { $err = 'Please fill all fields.'; }
                elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Enter a valid email.'; }
                elseif ($pw !== $pw2) { $err = 'Passwords do not match.'; }
                elseif (strlen($pw) < 6) { $err = 'Password must be at least 6 characters.'; }
                else {
                    $eStmt = $pdo->prepare("SELECT email FROM peppians WHERE email LIKE ?"); $eStmt->execute([$email]);
                    $emailTaken = false; foreach ($eStmt->fetchAll(PDO::FETCH_COLUMN) as $ev) { if (strtolower((string)$ev) === $email) { $emailTaken = true; break; } }
                    $wStmt = $pdo->prepare("SELECT COUNT(*) FROM peppians WHERE whatsapp = ?"); $wStmt->execute([$wa]);
                    if ($emailTaken) { $err = 'An account with this email already exists. Please sign in.'; }
                    elseif ((int)$wStmt->fetchColumn() > 0) { $err = 'This WhatsApp number is already registered.'; }
                    else {
                        $pdo->prepare("INSERT INTO peppians (full_name, email, whatsapp, password_hash, auth_provider, verified, created_at) VALUES (?,?,?,?, 'password', 0, NOW())")
                            ->execute([$name, $email, $wa, password_hash($pw, PASSWORD_DEFAULT)]);
                        session_regenerate_id(true);
                        $_SESSION['peppian_id'] = (int)$pdo->lastInsertId();
                        $_SESSION['peppian_email'] = $email;
                        header('Location: alumni-portal.php'); exit();
                    }
                }
            } elseif ($act === 'login') {
                $email = strtolower(trim($_POST['email'] ?? ''));
                $pw = $_POST['password'] ?? '';
                $stmt = $pdo->prepare("SELECT * FROM peppians WHERE email LIKE ? LIMIT 5"); $stmt->execute([$email]);
                $p = null; foreach ($stmt->fetchAll() as $cand) { if (strtolower((string)$cand['email']) === $email) { $p = $cand; break; } }
                if ($p && $p['password_hash'] !== '' && password_verify($pw, $p['password_hash'])) {
                    $pdo->prepare("UPDATE peppians SET last_login_at = NOW() WHERE id = ?")->execute([$p['id']]);
                    session_regenerate_id(true);
                    $_SESSION['peppian_id'] = (int)$p['id']; $_SESSION['peppian_email'] = $email;
                    header('Location: alumni-portal.php'); exit();
                } else { $err = 'Invalid email or password.'; }
            } elseif ($act === 'complete_profile' && !empty($_SESSION['peppian_id'])) {
                $wa = trim($_POST['whatsapp'] ?? '');
                $name = trim($_POST['full_name'] ?? '');
                if ($wa === '') { $err = 'WhatsApp number is required.'; }
                else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM peppians WHERE whatsapp = ? AND id <> ?");
                    $stmt->execute([$wa, $_SESSION['peppian_id']]);
                    if ($stmt->fetchColumn() > 0) { $err = 'This WhatsApp number is already registered.'; }
                    else {
                        $pdo->prepare("UPDATE peppians SET whatsapp = ?, full_name = COALESCE(NULLIF(?,''), full_name) WHERE id = ?")
                            ->execute([$wa, $name, $_SESSION['peppian_id']]);
                        $msg = 'Profile completed.';
                    }
                }
            } elseif ($act === 'verify_alumni' && !empty($_SESSION['peppian_id'])) {
                $idv = trim($_POST['identifier'] ?? '');
                if ($idv === '') { $err = 'Enter your PEPP email or mobile number.'; }
                else {
                    try {
                        $isEmail = (strpos($idv, '@') !== false);
                        $matches = [];
                        if ($isEmail) {
                            $em = strtolower($idv);
                            // LIKE prefilter + PHP confirm avoids cross-collation comparison
                            $stmt = $pdo->prepare("SELECT * FROM alumni WHERE email LIKE ? OR secondary_email LIKE ?");
                            $stmt->execute(['%' . $em, '%' . $em]);
                            foreach ($stmt->fetchAll() as $row) {
                                if (strtolower((string)$row['email']) === $em || strtolower((string)$row['secondary_email']) === $em) $matches[] = $row;
                            }
                        } else {
                            // Phone: normalise to last 10 digits and compare in PHP so any
                            // stored format (+91…, 0…, spaces, dashes) still matches.
                            $p10 = pep_norm_phone($idv);
                            if ($p10 === '' || strlen($p10) < 10) {
                                $err = 'Please enter a valid 10-digit mobile number (with or without +91).';
                            } else {
                                // Pull candidate rows whose mobile/secondary ends in the last 6 digits
                                // (cheap pre-filter), then confirm the full 10 digits in PHP.
                                $tail6 = substr($p10, -6);
                                $like = '%' . $tail6;
                                $stmt = $pdo->prepare("SELECT * FROM alumni WHERE mobile LIKE ? OR secondary_mobile LIKE ?");
                                $stmt->execute([$like, $like]);
                                foreach ($stmt->fetchAll() as $row) {
                                    if (pep_norm_phone($row['mobile']) === $p10 || pep_norm_phone($row['secondary_mobile']) === $p10) {
                                        $matches[] = $row;
                                    }
                                }
                            }
                        }

                        if (!isset($err) || $err === '') {
                            // Exclude matches that belong ONLY to an active academic year
                            $active_years = [];
                            try { $active_years = $pdo->query("SELECT year FROM academic_years WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
                            $valid = array_filter($matches, function ($m) use ($active_years) { return !in_array($m['academic_year'], $active_years, true); });

                            if (empty($valid)) {
                                $err = empty($matches)
                                    ? 'We could not find this in our PEPP alumni records. Try another email or mobile number you used with PEPP, or contact support below.'
                                    : 'Your batch is still active. The alumni portal opens to your batch once it is marked complete by PEPP.';
                            } else {
                                $courses = [];
                                foreach ($valid as $m) $courses[] = trim(($m['course_name'] ?: 'PEPP Course') . ' (' . ($m['academic_year'] ?: '-') . ')');
                                $courses = array_values(array_unique($courses));
                                $first = reset($valid);
                                $pdo->prepare("UPDATE peppians SET verified = 1, linked_alumni_id = ?, linked_courses = ? WHERE id = ?")
                                    ->execute([$first['id'], implode('; ', $courses), $_SESSION['peppian_id']]);
                                sync_peppian_to_alumni($pdo, $_SESSION['peppian_id']);
                                $msg = 'Verified! Your PEPP alumni account is now linked.';
                                try {
                                    $vstmt = $pdo->prepare("SELECT * FROM peppians WHERE id = ?"); $vstmt->execute([$_SESSION['peppian_id']]);
                                    $vp = $vstmt->fetch();
                                    if ($vp) {
                                        notify_peppian_verified($pdo, $vp);
                                        if (!empty($vp['whatsapp'])) {
                                            try {
                                                require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
                                                $commEngine = CommunicationEngine::getInstance($pdo);
                                                $wa_recipient = CommunicationEngine::normalizePhone($vp['whatsapp']);
                                                if (!empty($wa_recipient)) {
                                                    $context = [
                                                        'alumni_name'  => $vp['full_name'],
                                                        'student_name' => $vp['full_name'],
                                                        'peppian_id'   => $vp['id'],
                                                        'student_uid'  => 'peppian_' . $vp['id']
                                                    ];
                                                    $commEngine->sendEventNotification('alumni_verification_completed', $wa_recipient, $context, 'system_alumni');
                                                } else {
                                                    error_log("Alumni verification WhatsApp notification skipped: invalid phone for peppian_id=" . $vp['id']);
                                                }
                                            } catch (Exception $we) {
                                                error_log("Alumni verification WhatsApp notification failed: " . $we->getMessage());
                                            }
                                        }
                                    }
                                } catch (Exception $e) { error_log('verify notify: ' . $e->getMessage()); }
                            }
                        }
                    } catch (Exception $ve) {
                        error_log('alumni verify: ' . $ve->getMessage());
                        $err = 'Verification could not be completed right now. Please try again, or contact support below.';
                    }
                }
            } elseif ($act === 'save_profile' && !empty($_SESSION['peppian_id'])) {
                $status = in_array($_POST['current_status'] ?? '', ['student', 'professional'], true) ? $_POST['current_status'] : null;
                // Academic tracks: parallel arrays course[] + institute[]
                $tracks = [];
                $tc = (array)($_POST['track_course'] ?? []);
                $ti = (array)($_POST['track_institute'] ?? []);
                foreach ($tc as $k => $cv) {
                    $cv = trim($cv); $iv = trim($ti[$k] ?? '');
                    if ($cv !== '' || $iv !== '') $tracks[] = ['course' => mb_substr($cv, 0, 150), 'institute' => mb_substr($iv, 0, 190)];
                }
                $profession = ($status === 'professional') ? trim($_POST['current_profession'] ?? '') : '';
                $working = ($status === 'professional') ? trim($_POST['working_institute'] ?? '') : '';

                // Profile picture upload (optional)
                $pic = $me['profile_picture'] ?? null;
                $uploaded_pic = handle_file_upload_with_replace('profile_picture', 'peppians', $pic, ['jpg', 'jpeg', 'png', 'webp']);
                if ($uploaded_pic !== null) {
                    $pic = $uploaded_pic;
                }

                // Completion: status + >=1 track + (professional ? profession+working : true) + picture
                $complete = ($status !== null) && (count($tracks) >= 1)
                    && ($status === 'student' || ($profession !== '' && $working !== ''))
                    && !empty($pic) ? 1 : 0;

                $pdo->prepare("UPDATE peppians SET current_status=?, academic_tracks=?, current_profession=?, working_institute=?, profile_picture=?, profile_completed=? WHERE id=?")
                    ->execute([$status, json_encode($tracks), $profession ?: null, $working ?: null, $pic, $complete, $_SESSION['peppian_id']]);
                sync_peppian_to_alumni($pdo, $_SESSION['peppian_id']);
                $msg = $complete ? 'Profile completed - thank you!' : 'Profile saved. Add the remaining details to reach 100%.';
            } elseif ($act === 'apply_referral' && !empty($_SESSION['peppian_id'])) {
                $method = $_POST['payout_method'] ?? '';
                $details = trim($_POST['payout_details'] ?? '');
                $terms = isset($_POST['terms']);
                $year = trim($_POST['program_year'] ?? '');
                if (!$terms) { $err = 'You must accept the terms & conditions.'; }
                elseif ($details === '') { $err = 'Enter your bank/UPI payout details.'; }
                else {
                    $stmt = $pdo->prepare("SELECT * FROM referral_programs WHERE academic_year = ? AND status='active' LIMIT 1");
                    $stmt->execute([$year]); $prog = $stmt->fetch();
                    if (!$prog) { $err = 'This referral program is not active.'; }
                    else {
                        // Already a referee for this program?
                        $stmt = $pdo->prepare("SELECT id FROM referees WHERE program_id = ? AND peppian_id = ?");
                        $stmt->execute([$prog['id'], $_SESSION['peppian_id']]);
                        if ($stmt->fetchColumn()) { $err = 'You have already joined this program.'; }
                        else {
                            // Generate a unique referral code from prefix + next sequence
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM referees WHERE program_id = ?");
                            $stmt->execute([$prog['id']]);
                            $seq = (int)$prog['id_start'] + (int)$stmt->fetchColumn();
                            $code = strtoupper($prog['id_prefix']) . $seq;
                            // Ensure uniqueness
                            $tries = 0;
                            while ($tries < 50) {
                                $c = $pdo->prepare("SELECT COUNT(*) FROM referees WHERE referral_code = ?");
                                $c->execute([$code]);
                                if ((int)$c->fetchColumn() === 0) break;
                                $seq++; $code = strtoupper($prog['id_prefix']) . $seq; $tries++;
                            }
                            $pdo->prepare("INSERT INTO referees (program_id, peppian_id, referral_code, payout_method, payout_details, terms_accepted, status, created_at) VALUES (?,?,?,?,?,1,'active',NOW())")
                                ->execute([$prog['id'], $_SESSION['peppian_id'], $code, $method, $details]);
                            $new_referee_id = (int)$pdo->lastInsertId();
                            $msg = 'You are registered! Your referral code is ' . $code;
                            try { marketing_flag($pdo, 'referral', 'New referee joined: ' . $code); } catch (Exception $e) {}

                            try {
                                $pepStmt = $pdo->prepare("SELECT * FROM peppians WHERE id = ? LIMIT 1");
                                $pepStmt->execute([$_SESSION['peppian_id']]);
                                $currPeppian = $pepStmt->fetch();
                                if ($currPeppian && !empty($currPeppian['whatsapp'])) {
                                    require_once __DIR__ . '/includes/communication/CommunicationEngine.php';
                                    $commEngine = CommunicationEngine::getInstance($pdo);
                                    $wa_recipient = CommunicationEngine::normalizePhone($currPeppian['whatsapp']);
                                    if (!empty($wa_recipient)) {
                                        $referralLink = pep_referral_link($code);
                                        $context = [
                                            'alumni_name'   => $currPeppian['full_name'],
                                            'referral_code' => $code,
                                            'referral_link' => $referralLink,
                                            'peppian_id'    => $currPeppian['id'],
                                            'referee_id'    => $new_referee_id,
                                            'student_uid'   => 'referee_' . $new_referee_id
                                        ];
                                        $commEngine->sendEventNotification('alumni_referral_code_generated', $wa_recipient, $context, 'system_referral');
                                    } else {
                                        error_log("Alumni referral WhatsApp notification skipped: invalid phone for peppian_id=" . $currPeppian['id']);
                                    }
                                }
                            } catch (Exception $rwe) {
                                error_log("Alumni referral WhatsApp notification failed: " . $rwe->getMessage());
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) { error_log('alumni portal POST: ' . $e->getMessage()); $err = 'Something went wrong. Please try again.'; }
    }
}

/* ── Load current PEPPian ── */
$me = null;
if ($portal_ready && !empty($_SESSION['peppian_id'])) {
    try { $stmt = $pdo->prepare("SELECT * FROM peppians WHERE id = ?"); $stmt->execute([$_SESSION['peppian_id']]); $me = $stmt->fetch(); }
    catch (Exception $e) {}
    if (!$me) { unset($_SESSION['peppian_id']); }
}

/* Determine current step */
$step = 'auth';
if ($me) {
    if (empty($me['whatsapp'])) $step = 'complete';
    elseif (!$me['verified']) $step = 'verify';
    else $step = 'dashboard';
}

/* Dashboard data */
$active_programs = []; $my_referees = []; $base_url = '';
if ($step === 'dashboard') {
    try {
        $today = date('Y-m-d');
        $active_programs = $pdo->query("SELECT * FROM referral_programs WHERE status='active' AND (end_date IS NULL OR end_date >= '$today')")->fetchAll();
        $stmt = $pdo->prepare("SELECT r.*, p.academic_year, p.user_discount, p.alumni_earning, p.end_date, p.terms, p.how_to_earn
                               FROM referees r JOIN referral_programs p ON p.id = r.program_id WHERE r.peppian_id = ? ORDER BY r.id DESC");
        $stmt->execute([$me['id']]);
        foreach ($stmt->fetchAll() as $r) { $r['wallet'] = referee_wallet($pdo, $r['id']); $my_referees[] = $r; }
    } catch (Exception $e) { error_log('alumni dashboard: ' . $e->getMessage()); }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'pepplearning.in') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

    // Profile completion percentage (status, >=1 track, prof details if professional, picture)
    $profile_tracks = [];
    if (!empty($me['academic_tracks'])) { $dec = json_decode($me['academic_tracks'], true); if (is_array($dec)) $profile_tracks = $dec; }
    $pc_items = [
        'status'  => !empty($me['current_status']),
        'track'   => count($profile_tracks) >= 1,
        'picture' => !empty($me['profile_picture']),
    ];
    if (($me['current_status'] ?? '') === 'professional') {
        $pc_items['profession'] = !empty($me['current_profession']);
        $pc_items['working'] = !empty($me['working_institute']);
    }
    $pc_done = count(array_filter($pc_items));
    $pc_total = count($pc_items);
    $profile_pct = $pc_total ? (int)round($pc_done / $pc_total * 100) : 0;
}

// Programs the alumnus has NOT yet joined (can apply)
$joinable = [];
if ($step === 'dashboard') {
    $joined_ids = array_map(function ($r) { return (int)$r['program_id']; }, $my_referees);
    foreach ($active_programs as $p) if (!in_array((int)$p['id'], $joined_ids, true)) $joinable[] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PEPP Alumni Portal - PEPPians</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root {
    --gold: #d4a13a; 
    --gold-d: #b8861f; 
    --gold-l: #fcd34d;
    --gold-metal: linear-gradient(135deg, #fef08a 0%, #d4a13a 50%, #b8861f 100%);
    --bg-dark: #0a0609;
    --bg-surface: #140d12;
    --bg-surface-elevated: #1e141b;
    --border-gold: rgba(212, 161, 58, 0.25);
    --border-gold-focus: rgba(212, 161, 58, 0.65);
    --ink: #f5f5f4;
    --muted: #a8a29e;
    --card-bg: rgba(20, 13, 18, 0.75);
    --radius: 20px;
    --shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    --ok: #10b981;
    --ok-bg: rgba(16, 185, 129, 0.12);
    --err: #ef4444;
    --err-bg: rgba(239, 68, 68, 0.12);
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    color: var(--ink);
    background: 
        radial-gradient(circle at 10% 20%, rgba(212, 161, 58, 0.08) 0%, transparent 40%),
        radial-gradient(circle at 90% 80%, rgba(122, 43, 79, 0.12) 0%, transparent 45%),
        var(--bg-dark);
    min-height: 100vh;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

/* Scrollbar styling */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--bg-dark); }
::-webkit-scrollbar-thumb { background: var(--bg-surface-elevated); border-radius: 4px; border: 2px solid var(--bg-dark); }
::-webkit-scrollbar-thumb:hover { background: var(--gold); }

/* Mobile first layout wrapping */
.wrap {
    width: 100%;
    max-width: 640px; /* target optimal mobile reading width */
    margin: 0 auto;
    padding: 24px 16px 80px;
}

@media (min-width: 768px) {
    .wrap {
        max-width: 720px;
        padding: 40px 24px 100px;
    }
}

/* Top bar glassmorphism */
.topbar {
    background: rgba(10, 6, 9, 0.82);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border-gold);
    padding: 14px 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 20;
}

@media (min-width: 520px) {
    .topbar {
        flex-direction: row;
        justify-content: space-between;
        padding: 16px 32px;
    }
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 1.35rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.5px;
}

.brand .logo-badge {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.brand .logo-badge img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.brand small {
    display: block;
    font-weight: 600;
    font-size: 0.58rem;
    letter-spacing: 2px;
    color: var(--muted);
    margin-top: -2px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: center;
}

.who {
    font-size: 0.8rem;
    color: var(--muted);
}
.who strong {
    color: #fff;
    font-weight: 600;
}

/* Premium Card Panels */
.card {
    background: var(--card-bg);
    border: 1px solid var(--border-gold);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    animation: rise 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@media (min-width: 768px) {
    .card {
        padding: 32px;
        margin-bottom: 24px;
    }
}

@keyframes rise {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: none; }
}

.card h2 {
    font-size: 1.25rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.3px;
    margin-bottom: 6px;
}

.card .sub {
    color: var(--muted);
    font-size: 0.88rem;
    margin-bottom: 20px;
}

/* Fields & Inputs */
.field {
    margin-bottom: 16px;
}

.field label {
    display: block;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--gold);
    margin-bottom: 6px;
}

.field input, .field select, .field textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid var(--border-gold);
    border-radius: 12px;
    font-size: 0.92rem;
    font-family: inherit;
    color: #fff;
    background: rgba(255, 255, 255, 0.02);
    transition: all 0.2s;
}

.field input:focus, .field select:focus, .field textarea:focus {
    outline: none;
    border-color: var(--border-gold-focus);
    box-shadow: 0 0 0 4px rgba(212, 161, 58, 0.15);
    background: rgba(255, 255, 255, 0.05);
}

.field input::placeholder {
    color: #57534e;
}

.grid2 {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

@media (min-width: 480px) {
    .grid2 {
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: linear-gradient(135deg, var(--gold), var(--gold-d));
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 12px 24px;
    font-weight: 700;
    font-size: 0.92rem;
    font-family: inherit;
    cursor: pointer;
    text-decoration: none !important;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(212, 161, 58, 0.2);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(212, 161, 58, 0.35);
    filter: brightness(1.06);
}

.btn:active {
    transform: translateY(0);
}

.btn-block {
    width: 100%;
}

.btn-ghost {
    background: rgba(255, 255, 255, 0.03);
    color: #fff;
    border: 1.5px solid var(--border-gold);
    box-shadow: none;
}

.btn-ghost:hover {
    background: rgba(255, 255, 255, 0.07);
    border-color: var(--gold);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.btn-google {
    background: #fff;
    color: #1f2937;
    border: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    font-weight: 600;
}

.btn-google:hover {
    background: #f5f5f4;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
}

.or {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 16px 0;
    color: var(--muted);
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.or::before, .or::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border-gold);
}

/* Alerts */
.alert {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 0.88rem;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    gap: 8px;
    align-items: center;
}

.alert.ok { background: var(--ok-bg); color: var(--ok); border: 1px solid rgba(16, 185, 129, 0.3); }
.alert.err { background: var(--err-bg); color: var(--err); border: 1px solid rgba(239, 68, 68, 0.3); }
.alert.info { background: rgba(212, 161, 58, 0.08); color: var(--gold-l); border: 1px solid var(--border-gold); }

/* Navigation Tab Bar */
.tab-row {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    background: rgba(255, 255, 255, 0.03);
    padding: 4px;
    border-radius: 14px;
    border: 1px solid var(--border-gold);
}

.tab-row button {
    flex: 1;
    padding: 10px;
    border: none;
    background: transparent;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.85rem;
    cursor: pointer;
    color: var(--muted);
    transition: all 0.2s;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.tab-row button.on {
    background: linear-gradient(135deg, var(--gold), var(--gold-d));
    color: #fff;
    box-shadow: 0 4px 12px rgba(212, 161, 58, 0.2);
}

/* Stats view */
.stat-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 16px;
}

@media (min-width: 480px) {
    .stat-row {
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }
}

.stat {
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid var(--border-gold);
    border-radius: 14px;
    padding: 14px 10px;
    text-align: center;
    transition: all 0.2s ease;
}

.stat:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.04);
    border-color: var(--gold);
}

.stat .v {
    font-size: 1.45rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.5px;
}

.stat .l {
    font-size: 0.62rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
    font-weight: 700;
}

/* Chips and badges */
.chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(212, 161, 58, 0.15);
    color: var(--gold-l);
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 50px;
    font-size: 0.78rem;
    border: 1px solid var(--border-gold);
}

.pill-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.pill {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border-gold);
    border-radius: 50px;
    padding: 6px 14px;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--muted);
}

.ref-link {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.ref-link input {
    flex: 1;
    padding: 10px 12px;
    border: 1.5px solid var(--border-gold);
    border-radius: 10px;
    font-size: 0.84rem;
    background: rgba(255, 255, 255, 0.02);
    color: #fff;
    font-weight: 600;
}

/* Hero Section */
.hero {
    position: relative;
    overflow: hidden;
    color: #fff;
    border-radius: var(--radius);
    padding: 32px 24px;
    margin-bottom: 20px;
    background: linear-gradient(135deg, var(--plum) 0%, var(--plum-2) 50%, var(--plum-3) 100%);
    border: 1px solid var(--border-gold);
    box-shadow: var(--shadow-lg);
}

@media (min-width: 768px) {
    .hero {
        padding: 42px 38px;
        margin-bottom: 26px;
    }
}

.hero::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: 
        radial-gradient(circle 280px at 100% 0%, rgba(212, 161, 58, 0.22) 0%, transparent 60%),
        radial-gradient(circle 220px at 0% 120%, rgba(240, 213, 149, 0.1) 0%, transparent 55%);
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: var(--gold-l);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    padding: 4px 12px;
    border-radius: 50px;
    margin-bottom: 12px;
    position: relative;
    z-index: 1;
}

.hero h1 {
    font-size: 1.6rem;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin-bottom: 8px;
    position: relative;
    z-index: 1;
    line-height: 1.2;
}

@media (min-width: 520px) {
    .hero h1 {
        font-size: 2rem;
    }
}

.hero p {
    opacity: 0.85;
    font-size: 0.92rem;
    max-width: 560px;
    position: relative;
    z-index: 1;
}

.hero .perks {
    display: flex;
    gap: 12px 20px;
    flex-wrap: wrap;
    margin-top: 16px;
    position: relative;
    z-index: 1;
}

.hero .perk {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #fff;
}

.hero .perk i {
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gold-l);
    font-size: 0.8rem;
}

/* Premium Credit Card Coupon Design - Scale-based Responsive */
.coupon-card-container {
    width: 100%;
    max-width: 480px;
    margin: 0 auto;
    position: relative;
}

.coupon-card-wrapper {
    width: 100%;
    position: relative;
    overflow: hidden;
}

.coupon-card {
    position: absolute;
    top: 0;
    left: 0;
    width: 800px;
    height: 450px;
    border-radius: 28px; /* scaled proportionally */
    background-image: url('assets/img/pepp-referral-coupon-template.jpg');
    background-size: cover;
    background-position: center;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
    overflow: hidden;
    box-sizing: border-box;
    transform-origin: top left;
}

.coupon-card .cc-exp {
    position: absolute;
    top: 55px;
    left: 80px;
    font-size: 28px;
    font-weight: 700;
    color: #1c1917;
    letter-spacing: 0.5px;
}

.coupon-card .cc-name {
    position: absolute;
    top: 195px;
    left: 80px;
    font-size: 36px;
    font-weight: 800;
    color: #0c0a09;
    letter-spacing: -0.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 640px;
}

.coupon-card .cc-role {
    position: absolute;
    top: 242px;
    left: 80px;
    font-size: 22px;
    font-weight: 700;
    color: #44403c;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.coupon-card .cc-qr-wrap {
    position: absolute;
    bottom: 40px;
    left: 80px;
    width: 110px;
    height: 110px;
    background: #fff;
    padding: 8px;
    border-radius: 8px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
}

.coupon-card .cc-qr-wrap canvas, .coupon-card .cc-qr-wrap img {
    width: 100% !important;
    height: 100% !important;
    display: block;
}

.coupon-card .cc-code-wrap {
    position: absolute;
    bottom: 40px;
    right: 70px;
    width: 340px;
    height: 110px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}

.coupon-card .cc-code {
    font-size: 46px;
    font-weight: 900;
    color: #0c0a09;
    letter-spacing: 2px;
    text-align: center;
}

/* Tutorial Section (How to Earn) */
.tutorial-card {
    background: rgba(20, 13, 18, 0.65);
    border: 1px solid var(--border-gold);
    border-radius: var(--radius);
    padding: 22px;
    margin-bottom: 20px;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    transition: all 0.3s ease;
}

.tutorial-toggle {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    background: none;
    border: none;
    padding: 0;
    color: #fff;
    cursor: pointer;
    font-family: inherit;
    outline: none;
}

.tutorial-toggle h2 {
    font-size: 1.15rem;
    font-weight: 800;
    margin: 0;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 10px;
}

.tutorial-chevron {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    color: var(--gold);
    font-size: 1.15rem;
}

.tutorial-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.tutorial-steps {
    padding-top: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.tutorial-step {
    display: flex;
    gap: 14px;
    align-items: flex-start;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity 0.35s ease, transform 0.35s ease;
}

.step-number {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--gold), var(--gold-d));
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.8rem;
    flex-shrink: 0;
    box-shadow: 0 3px 8px rgba(212, 161, 58, 0.25);
}

.step-text {
    font-size: 0.88rem;
    color: var(--ink);
    line-height: 1.55;
    padding-top: 2px;
}

/* Section elements */
.section-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--gold);
    margin-bottom: 10px;
}

.divider {
    height: 1px;
    background: var(--border-gold);
    margin: 20px 0;
}

.foot {
    text-align: center;
    color: var(--muted);
    font-size: 0.78rem;
    margin-top: 32px;
}

.foot .fb {
    font-weight: 800;
    color: #fff;
}

/* Terms box */
.terms-box {
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid var(--border-gold);
    border-radius: 12px;
    padding: 14px;
    font-size: 0.82rem;
    white-space: pre-wrap;
    margin-bottom: 14px;
    max-height: 180px;
    overflow: auto;
    color: var(--muted);
}

.tc-check {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    font-size: 0.84rem;
    margin: 8px 0 16px;
    color: var(--muted);
}

.tc-check input {
    margin-top: 2px;
    width: 16px;
    height: 16px;
    accent-color: var(--gold);
}

/* Profile cards & items */
.profile-card .profile-head {
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
}

.profile-pic {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    background: linear-gradient(135deg, var(--gold), var(--gold-d));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1.4rem;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(212, 161, 58, 0.25);
}

.profile-pic img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.pc-meter {
    height: 8px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border-gold);
    border-radius: 50px;
    overflow: hidden;
    margin-bottom: 6px;
}

.pc-fill {
    height: 100%;
    border-radius: 50px;
    background: linear-gradient(90deg, #f59e0b, #d4a13a);
    transition: width 1.1s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
}

.pc-fill::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    animation: pc-shine 2s linear infinite;
}

.pc-fill.done {
    background: linear-gradient(90deg, #10b981, #34d399);
}

@keyframes pc-shine {
    from { transform: translateX(-100%); }
    to { transform: translateX(100%); }
}

.pc-label {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--gold-l);
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1.5px solid var(--border-gold);
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.86rem;
    cursor: pointer;
    color: var(--muted);
    transition: all 0.2s;
}

.status-pill:has(input:checked) {
    border-color: var(--gold);
    background: rgba(212, 161, 58, 0.1);
    color: #fff;
}

.status-pill input {
    display: none;
}

.track-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
    align-items: center;
}

.track-row input {
    flex: 1;
    padding: 10px 12px;
    border: 1.5px solid var(--border-gold);
    border-radius: 10px;
    font-size: 0.88rem;
    background: rgba(255, 255, 255, 0.02);
    color: #fff;
}

.track-del {
    background: var(--err-bg);
    color: var(--err);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 9px;
    width: 36px;
    height: 36px;
    cursor: pointer;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.track-del:hover {
    background: rgba(239, 68, 68, 0.22);
}

.loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(10, 6, 9, 0.95);
    backdrop-filter: blur(8px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 200;
    flex-direction: column;
    color: #fff;
}

.spinner {
    width: 50px;
    height: 50px;
    border: 4px solid rgba(255, 255, 255, 0.1);
    border-top-color: var(--gold);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 16px;
}

.loading-overlay .lt { font-weight: 800; font-size: 1.05rem; color: #fff; }
.loading-overlay .ls { font-size: 0.84rem; color: var(--muted); margin-top: 4px; }
</style>
</head>
<body>
<div class="topbar">
    <div class="brand">
        <span class="logo-badge"><img src="assets/img/pepp-logo-icon.png" alt="PEPP Logo"></span>
        <span>pepp<small>LEARNING · ALUMNI</small></span>
    </div>
    <div class="topbar-right">
        <?php if ($me): ?>
            <span class="who">Signed in as <strong><?php echo pep_e($me['email']); ?></strong></span>
            <a href="?logout=1" class="btn btn-ghost" style="padding:9px 18px;"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
        <?php endif; ?>
    </div>
</div>

<div class="wrap">
<?php if (!$portal_ready): ?>
    <div class="card"><div class="alert err"><i class="fas fa-triangle-exclamation"></i> The alumni portal is not set up yet. Please check back soon.</div></div>

<?php elseif ($step === 'auth'): ?>
    <div class="hero">
        <span class="hero-badge"><i class="fas fa-star"></i> PEPPians Community</span>
        <h1>Welcome back, PEPP Alumni</h1>
        <p>Reconnect with PEPP Learning, unlock referral earnings, and be first in line for exclusive alumni benefits.</p>
        <div class="perks">
            <div class="perk"><i class="fas fa-indian-rupee-sign"></i> Earn on every referral</div>
            <div class="perk"><i class="fas fa-share-nodes"></i> Share your unique link</div>
            <div class="perk"><i class="fas fa-gift"></i> Future community perks</div>
        </div>
    </div>
    <?php if ($err): ?><div class="alert err"><i class="fas fa-circle-exclamation"></i> <?php echo pep_e($err); ?></div><?php endif; ?>
    <div class="card">
        <div class="tab-row">
            <button id="t-signin" class="on" onclick="swTab('signin')">Sign In</button>
            <button id="t-signup" onclick="swTab('signup')">Create Account</button>
        </div>

        <a href="alumni-google-callback.php?start=1" class="btn btn-google btn-block" style="margin-bottom:4px;">
            <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.5 0 10.5-2.1 14.3-5.6l-6.6-5.6c-2 1.5-4.6 2.4-7.7 2.4-5.2 0-9.6-3.3-11.2-8l-6.5 5C9.6 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.1-4 5.4l6.6 5.6C40.9 36.7 44 31 44 24c0-1.3-.1-2.3-.4-3.5z"/></svg>
            Continue with Google
        </a>
        <div class="or"><span>or</span></div>

        <form method="POST" id="form-signin">
            <input type="hidden" name="csrf" value="<?php echo pep_csrf(); ?>"><input type="hidden" name="act" value="login">
            <div class="field"><label>Email</label><input type="email" name="email" required></div>
            <div class="field"><label>Password</label><input type="password" name="password" required></div>
            <button class="btn btn-block" type="submit">Sign In</button>
        </form>

        <form method="POST" id="form-signup" style="display:none;">
            <input type="hidden" name="csrf" value="<?php echo pep_csrf(); ?>"><input type="hidden" name="act" value="register">
            <div class="field"><label>Full Name</label><input type="text" name="full_name" required></div>
            <div class="field"><label>Email (your username)</label><input type="email" name="email" required></div>
            <div class="field"><label>WhatsApp Number</label><input type="tel" name="whatsapp" required></div>
            <div class="grid2">
                <div class="field"><label>Password</label><input type="password" name="password" required></div>
                <div class="field"><label>Confirm Password</label><input type="password" name="confirm" required></div>
            </div>
            <button class="btn btn-block" type="submit">Create Account</button>
        </form>
    </div>

<?php elseif ($step === 'complete'): ?>
    <?php if ($err): ?><div class="alert err"><i class="fas fa-circle-exclamation"></i> <?php echo pep_e($err); ?></div><?php endif; ?>
    <div class="card">
        <h2>One more step</h2>
        <p class="sub">Please add your WhatsApp number to continue.</p>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo pep_csrf(); ?>"><input type="hidden" name="act" value="complete_profile">
            <div class="field"><label>Full Name</label><input type="text" name="full_name" value="<?php echo pep_e($me['full_name']); ?>" required></div>
            <div class="field"><label>WhatsApp Number</label><input type="tel" name="whatsapp" required></div>
            <button class="btn btn-block" type="submit">Continue</button>
        </form>
    </div>

<?php elseif ($step === 'verify'): ?>
    <?php if ($err): ?><div class="alert err"><i class="fas fa-circle-exclamation"></i> <?php echo pep_e($err); ?></div><?php endif; ?>
    <div class="hero">
        <span class="hero-badge"><i class="fas fa-shield-halved"></i> One quick check</span>
        <h1>Verify your PEPP history</h1>
        <p>Confirm you studied at PEPP to unlock your PEPPian dashboard and referral earnings.</p>
    </div>
    <div class="card">
        <h2>Alumni Verification</h2>
        <p class="sub">Enter any email or mobile number you used when you studied at PEPP. We'll match it with our records instantly.</p>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo pep_csrf(); ?>"><input type="hidden" name="act" value="verify_alumni">
            <div class="field"><label>PEPP Email or Mobile Number</label><input type="text" name="identifier" placeholder="e.g. you@example.com or 9XXXXXXXXX" required></div>
            <button class="btn btn-block" type="submit"><i class="fas fa-shield-halved"></i> Verify Now</button>
        </form>
        <div class="alert info" style="margin-top:16px;"><i class="fas fa-circle-info"></i> Currently active batches can't register yet - the portal opens to a batch once it's marked complete by PEPP.</div>
        <div class="divider"></div>
        <div style="text-align:center;">
            <p style="font-size:.88rem;color:var(--muted);margin-bottom:12px;">Can't verify your account? Our team will help you within minutes.</p>
            <?php
                $support_msg = rawurlencode("Hello PEPP Administration Desk, I'm trying to verify my PEPP alumni account on the alumni portal but it's not matching my details.\n\nMy portal email: " . ($me['email'] ?? '') . "\nName: " . ($me['full_name'] ?? '') . "\n\nCould you please help me verify and link my alumni account? Thank you.");
            ?>
            <a class="btn btn-ghost" href="https://wa.me/919567276458?text=<?php echo $support_msg; ?>" target="_blank" rel="noopener">
                <i class="fab fa-whatsapp" style="color:#25D366;"></i> Contact Support (PEPP Admin Desk)
            </a>
            <p style="font-size:.78rem;color:var(--muted);margin-top:10px;">PEPP Administration Desk · +91 95672 76458</p>
        </div>
    </div>

<?php else: /* dashboard */ ?>
    <?php if ($msg): ?><div class="alert ok"><i class="fas fa-circle-check"></i> <?php echo pep_e($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert err"><i class="fas fa-circle-exclamation"></i> <?php echo pep_e($err); ?></div><?php endif; ?>

    <div class="hero">
        <span class="hero-badge"><i class="fas fa-circle-check"></i> Verified PEPPian</span>
        <h1>Hi <?php echo pep_e(explode(' ', $me['full_name'])[0] ?: 'PEPPian'); ?>!</h1>
        <p>Welcome to your alumni dashboard.<?php echo $me['linked_courses'] ? ' You studied: ' . pep_e($me['linked_courses']) . '.' : ''; ?></p>
        <?php if ($me['linked_courses']): ?>
        <div class="perks"><?php foreach (array_filter(array_map('trim', explode(';', $me['linked_courses']))) as $lc): ?><div class="perk"><i class="fas fa-book"></i> <?php echo pep_e($lc); ?></div><?php endforeach; ?></div>
        <?php endif; ?>
    </div>

    <!-- Dashboard Sub-Navigation Tabs -->
    <div class="tab-row">
        <button id="db-tab-referral" class="on" onclick="switchDashboardTab('referral')"><i class="fas fa-bullhorn"></i> Referrals</button>
        <button id="db-tab-profile" onclick="switchDashboardTab('profile')"><i class="fas fa-user-gear"></i> My Profile</button>
    </div>

    <!-- Tab 1: Referral Content -->
    <div id="db-content-referral">
        <?php
        $how_to_earn_text = '';
        if (!empty($my_referees) && !empty($my_referees[0]['how_to_earn'])) {
            $how_to_earn_text = $my_referees[0]['how_to_earn'];
        } elseif (!empty($active_programs) && !empty($active_programs[0]['how_to_earn'])) {
            $how_to_earn_text = $active_programs[0]['how_to_earn'];
        } else {
            $how_to_earn_text = $DEFAULT_HOW_TO_EARN ?? '';
        }
        if (!empty($how_to_earn_text)):
        ?>
        <div class="tutorial-card">
            <button class="tutorial-toggle" onclick="toggleTutorial()">
                <h2><i class="fas fa-circle-question" style="color: var(--gold);"></i> How to earn using this referral?</h2>
                <i class="fas fa-chevron-down tutorial-chevron"></i>
            </button>
            <div class="tutorial-content">
                <div class="tutorial-steps">
                    <?php
                    $steps = array_filter(array_map('trim', explode("\n", $how_to_earn_text)));
                    $step_num = 1;
                    foreach ($steps as $step_line):
                        $cleaned_step = preg_replace('/^\d+[\.\-\s)]+\s*/', '', $step_line);
                        if ($cleaned_step === '') continue;
                    ?>
                    <div class="tutorial-step">
                        <div class="step-number"><?php echo $step_num++; ?></div>
                        <div class="step-text"><?php echo pep_e($cleaned_step); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Apply for joinable programs -->
        <?php foreach ($joinable as $p): ?>
        <div class="card">
            <div class="section-tag"><i class="fas fa-bullhorn"></i> Now Open</div>
            <span class="chip"><i class="fas fa-gift"></i> Referral Program - <?php echo pep_e($p['academic_year']); ?></span>
            <h2 style="margin-top:14px;">Earn ₹<?php echo number_format((float)$p['alumni_earning'], 0); ?> per referral</h2>
            <p class="sub">Invite new learners to PEPP for the <?php echo pep_e($p['academic_year']); ?> batch. They save ₹<?php echo number_format((float)$p['user_discount'], 0); ?>, and you earn ₹<?php echo number_format((float)$p['alumni_earning'], 0); ?> for each one who joins.<?php echo $p['end_date'] ? ' Apply before ' . date('d M Y', strtotime($p['end_date'])) . '.' : ''; ?></p>
            <form method="POST" onsubmit="return showLoading();">
                <input type="hidden" name="csrf" value="<?php echo pep_csrf(); ?>"><input type="hidden" name="act" value="apply_referral"><input type="hidden" name="program_year" value="<?php echo pep_e($p['academic_year']); ?>">
                <div class="grid2">
                    <div class="field"><label>Payout Method</label><select name="payout_method"><option value="UPI">UPI / GPay / PhonePe</option><option value="Bank">Bank Account</option></select></div>
                    <div class="field"><label>Payout Details (UPI ID or Account no.)</label><input type="text" name="payout_details" required placeholder="name@upi or A/C + IFSC"></div>
                </div>
                <label class="tc-check">
                    <input type="checkbox" name="terms" required>
                    <span>I have read and accept the <a href="#" onclick="document.getElementById('terms-<?php echo (int)$p['id']; ?>').style.display='block';return false;">referral terms &amp; conditions</a>.</span>
                </label>
                <div id="terms-<?php echo (int)$p['id']; ?>" class="terms-box" style="display:none;"><?php echo pep_e($p['terms'] ?: 'Standard referral terms apply.'); ?></div>
                <button class="btn" type="submit"><i class="fas fa-rocket"></i> Apply &amp; Get Code</button>
            </form>
        </div>
        <?php endforeach; ?>

        <?php if (empty($joinable) && empty($my_referees)): ?>
        <div class="card"><div class="alert info"><i class="fas fa-circle-info"></i> No active referral program right now. Check back when PEPP opens the next one.</div></div>
        <?php endif; ?>

        <!-- My referral codes + wallets -->
        <?php foreach ($my_referees as $r): $w = $r['wallet'];
            $link = pep_referral_link($r['referral_code']); ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <h2>Referral - <?php echo pep_e($r['academic_year']); ?></h2>
                <span class="chip">Code: <?php echo pep_e($r['referral_code']); ?></span>
            </div>
            <div class="stat-row" style="margin-top:16px;">
                <div class="stat"><div class="v"><?php echo (int)$w['joined']; ?></div><div class="l">Joined</div></div>
                <div class="stat"><div class="v">₹<?php echo number_format($w['credited'], 0); ?></div><div class="l">Credited</div></div>
                <div class="stat"><div class="v">₹<?php echo number_format($w['paid'], 0); ?></div><div class="l">Paid</div></div>
                <div class="stat"><div class="v">₹<?php echo number_format($w['balance'], 0); ?></div><div class="l">Balance</div></div>
            </div>
            <?php if ($w['pending'] > 0): ?><div class="alert info"><i class="fas fa-hourglass-half"></i> ₹<?php echo number_format($w['pending'], 0); ?> pending - from referred learners on instalment plans, credited as their dues clear.</div><?php endif; ?>

            <label style="font-size:.82rem;font-weight:600;">Your shareable referral link</label>
            <div class="ref-link">
                <input type="text" id="link-<?php echo (int)$r['id']; ?>" value="<?php echo pep_e($link); ?>" readonly>
                <button class="btn" type="button" onclick="copyLink(<?php echo (int)$r['id']; ?>)"><i class="fas fa-copy"></i></button>
            </div>
            <?php if (!empty($r['end_date'])): ?><p style="font-size:.8rem;color:var(--muted);margin-top:8px;">Expires <?php echo date('d M Y', strtotime($r['end_date'])); ?></p><?php endif; ?>

            <!-- Downloadable referral coupon -->
            <div style="margin-top:18px;">
                <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:8px;">Your referral coupon</label>
                <?php
                $display_name = $me['full_name'];
                if (mb_strlen($display_name) > 25) {
                    $display_name = mb_substr($display_name, 0, 22) . '...';
                }
                ?>
                <div class="coupon-card-container">
                    <div class="coupon-card-wrapper">
                        <div class="coupon-card" id="coupon-<?php echo (int)$r['id']; ?>">
                            <div class="cc-exp">VALID UNTIL: <?php echo !empty($r['end_date']) ? date('M d, Y', strtotime($r['end_date'])) : 'PERMANENT'; ?></div>
                            <div class="cc-name"><?php echo pep_e($display_name); ?></div>
                            <div class="cc-role">ALUMNI REFERRAL</div>
                            <div class="cc-qr-wrap" data-link="<?php echo pep_e($link); ?>"></div>
                            <div class="cc-code-wrap">
                                <div class="cc-code"><?php echo pep_e($r['referral_code']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <button class="btn btn-ghost" type="button" style="margin-top:10px; width: 100%; max-width: 400px; display: block; margin-left: auto; margin-right: auto;" onclick="downloadCoupon(<?php echo (int)$r['id']; ?>)"><i class="fas fa-download"></i> Download Coupon</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div> <!-- End of db-content-referral -->

    <!-- Tab 2: Profile Content -->
    <div id="db-content-profile" style="display: none;">
        <!-- Alumni Profile + completion meter -->
        <div class="card profile-card">
            <div class="profile-head">
                <div class="profile-pic">
                    <?php if (!empty($me['profile_picture'])): ?>
                        <img src="<?php echo pep_e($me['profile_picture']); ?>" alt="Profile">
                    <?php else: ?>
                        <span><?php echo strtoupper(substr($me['full_name'], 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div style="flex:1;">
                    <h2 style="margin-bottom:2px;">My Alumni Profile</h2>
                    <p class="sub" style="margin-bottom:10px;">A complete profile unlocks future PEPP alumni benefits, community access and priority offers.</p>
                    <div class="pc-meter"><div class="pc-fill <?php echo $profile_pct >= 100 ? 'done' : ''; ?>" style="width:<?php echo (int)$profile_pct; ?>%;"></div></div>
                    <div class="pc-label"><span id="pc-num"><?php echo (int)$profile_pct; ?></span>% complete <?php echo $profile_pct >= 100 ? '<i class="fas fa-circle-check" style="color:var(--ok);"></i>' : '- finish it to earn your benefits!'; ?></div>
                </div>
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('profile-form').style.display=(document.getElementById('profile-form').style.display==='none'?'block':'none');"><i class="fas fa-pen"></i> Edit</button>
            </div>

            <form method="POST" enctype="multipart/form-data" id="profile-form" style="display:<?php echo $profile_pct >= 100 ? 'none' : 'block'; ?>; margin-top:20px;">
                <input type="hidden" name="csrf" value="<?php echo pep_csrf(); ?>"><input type="hidden" name="act" value="save_profile">
                <div class="field">
                    <label>Current status</label>
                    <div style="display:flex; gap:12px;">
                        <label class="status-pill"><input type="radio" name="current_status" value="student" <?php echo ($me['current_status'] ?? '')==='student'?'checked':''; ?> onclick="toggleProf(false)"> Student</label>
                        <label class="status-pill"><input type="radio" name="current_status" value="professional" <?php echo ($me['current_status'] ?? '')==='professional'?'checked':''; ?> onclick="toggleProf(true)"> Professional</label>
                    </div>
                </div>

                <label style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b6357;margin:6px 0 7px;display:block;">Academic tracks after PEPP (most recent first)</label>
                <div id="tracks-wrap">
                    <?php
                    $exist_tracks = $profile_tracks ?: [['course'=>'','institute'=>'']];
                    foreach ($exist_tracks as $t): ?>
                    <div class="track-row">
                        <input type="text" name="track_course[]" placeholder="Course / Degree" value="<?php echo pep_e($t['course'] ?? ''); ?>">
                        <input type="text" name="track_institute[]" placeholder="University / Institute" value="<?php echo pep_e($t['institute'] ?? ''); ?>">
                        <button type="button" class="track-del" onclick="this.parentNode.remove()"><i class="fas fa-xmark"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-ghost" style="padding:8px 16px;font-size:.85rem;margin-bottom:14px;" onclick="addTrack()"><i class="fas fa-plus"></i> Add another</button>

                <div id="prof-fields" style="display:<?php echo ($me['current_status'] ?? '')==='professional'?'block':'none'; ?>;">
                    <div class="grid2">
                        <div class="field"><label>Current profession</label><input type="text" name="current_profession" value="<?php echo pep_e($me['current_profession'] ?? ''); ?>" placeholder="e.g. Clinical Psychologist"></div>
                        <div class="field"><label>Working institute / company</label><input type="text" name="working_institute" value="<?php echo pep_e($me['working_institute'] ?? ''); ?>" placeholder="Where you work"></div>
                    </div>
                </div>

                <div class="field"><label>Profile picture</label><input type="file" name="profile_picture" accept="image/*"></div>
                <button class="btn" type="submit"><i class="fas fa-floppy-disk"></i> Save Profile</button>
            </form>
        </div>
    </div> <!-- End of db-content-profile -->
<?php endif; ?>

    <div class="foot">&copy; <?php echo date('Y'); ?> PEPP Learning - Labinc Education Pvt. Ltd.</div>
</div>

<div class="loading-overlay" id="loading"><div class="spinner"></div><div class="lt">Setting up your referral account</div><div class="ls">Generating your unique referral code…</div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
function swTab(t) {
    document.getElementById('t-signin').classList.toggle('on', t==='signin');
    document.getElementById('t-signup').classList.toggle('on', t==='signup');
    document.getElementById('form-signin').style.display = t==='signin'?'block':'none';
    document.getElementById('form-signup').style.display = t==='signup'?'block':'none';
}
function switchDashboardTab(tab) {
    const isReferral = tab === 'referral';
    const tabRef = document.getElementById('db-tab-referral');
    const tabProf = document.getElementById('db-tab-profile');
    const contentRef = document.getElementById('db-content-referral');
    const contentProf = document.getElementById('db-content-profile');
    
    if (tabRef) tabRef.classList.toggle('on', isReferral);
    if (tabProf) tabProf.classList.toggle('on', !isReferral);
    if (contentRef) contentRef.style.display = isReferral ? 'block' : 'none';
    if (contentProf) contentProf.style.display = isReferral ? 'none' : 'block';
    
    sessionStorage.setItem('alumni_active_tab', tab);
}
function copyLink(id) {
    var el = document.getElementById('link-'+id); el.select(); el.setSelectionRange(0,99999);
    navigator.clipboard.writeText(el.value).then(function(){ alert('Referral link copied!'); });
}
function showLoading() { document.getElementById('loading').style.display='flex'; return true; }
function addTrack() {
    var w = document.getElementById('tracks-wrap');
    var d = document.createElement('div'); d.className = 'track-row';
    d.innerHTML = '<input type="text" name="track_course[]" placeholder="Course / Degree">' +
                  '<input type="text" name="track_institute[]" placeholder="University / Institute">' +
                  '<button type="button" class="track-del" onclick="this.parentNode.remove()"><i class="fas fa-xmark"></i></button>';
    w.appendChild(d);
}
function toggleProf(show) { var el = document.getElementById('prof-fields'); if (el) el.style.display = show ? 'block' : 'none'; }

function toggleTutorial() {
    var content = document.querySelector('.tutorial-content');
    var chevron = document.querySelector('.tutorial-chevron');
    var steps = document.querySelectorAll('.tutorial-step');
    
    if (content.style.maxHeight && content.style.maxHeight !== '0px') {
        content.style.maxHeight = '0px';
        chevron.style.transform = 'rotate(0deg)';
        steps.forEach(function(step) {
            step.style.opacity = '0';
            step.style.transform = 'translateY(8px)';
        });
    } else {
        content.style.maxHeight = content.scrollHeight + 'px';
        chevron.style.transform = 'rotate(180deg)';
        steps.forEach(function(step, index) {
            setTimeout(function() {
                step.style.opacity = '1';
                step.style.transform = 'translateY(0)';
            }, index * 100);
        });
    }
}

function scaleCoupons() {
    document.querySelectorAll('.coupon-card-container').forEach(function(container) {
        var wrapper = container.querySelector('.coupon-card-wrapper');
        var card = container.querySelector('.coupon-card');
        if (!wrapper || !card) return;
        var containerWidth = container.offsetWidth;
        var scale = containerWidth / 800;
        card.style.transform = 'scale(' + scale + ')';
        wrapper.style.height = (450 * scale) + 'px';
    });
}

// Animate completion meter, load active tab, generate QR codes, and scale coupons
window.addEventListener('load', function () {
    var fill = document.querySelector('.pc-fill');
    if (fill) { var w = fill.style.width; fill.style.width = '0%'; setTimeout(function(){ fill.style.width = w; }, 200); }
    
    const activeTab = sessionStorage.getItem('alumni_active_tab') || 'referral';
    if (activeTab === 'profile') {
        switchDashboardTab('profile');
    }
    
    // Generate QR codes for all coupons
    document.querySelectorAll('.cc-qr-wrap').forEach(function(el) {
        var link = el.getAttribute('data-link');
        new QRCode(el, {
            text: link,
            width: 128,
            height: 128,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });
    });
    
    // Initial card scaling calculation
    scaleCoupons();
});

window.addEventListener('resize', scaleCoupons);

function downloadCoupon(id) {
    var node = document.getElementById('coupon-'+id);
    var wrapper = node.closest('.coupon-card-wrapper');
    var oldTransform = node.style.transform;
    var oldHeight = wrapper ? wrapper.style.height : '';
    
    node.style.transform = 'none'; // Temporarily disable scale transform for rendering
    if (wrapper) wrapper.style.height = '450px'; // Set to full size for capture
    
    html2canvas(node, {
        scale: 2, 
        backgroundColor: null, 
        useCORS: true, 
        allowTaint: true, 
        logging: false
    }).then(function(canvas){
        node.style.transform = oldTransform; // Restore original scale
        if (wrapper) wrapper.style.height = oldHeight;
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = 'pepp-referral-coupon.png';
        a.click();
    });
}
</script>
</body>
</html>
