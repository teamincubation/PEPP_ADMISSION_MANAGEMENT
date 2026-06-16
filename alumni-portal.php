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

$portal_ready = pepp_tables_exist($pdo, ['peppians', 'alumni']);
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
                                $msg = 'Verified! Your PEPP alumni account is now linked.';
                                try {
                                    $vstmt = $pdo->prepare("SELECT * FROM peppians WHERE id = ?"); $vstmt->execute([$_SESSION['peppian_id']]);
                                    $vp = $vstmt->fetch(); if ($vp) notify_peppian_verified($pdo, $vp);
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
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                    $imgok = @getimagesize($_FILES['profile_picture']['tmp_name']) !== false;
                    if ($imgok && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $_FILES['profile_picture']['size'] <= 4 * 1024 * 1024) {
                        $dir = __DIR__ . '/../uploads/peppians';
                        if (!is_dir($dir)) @mkdir($dir, 0755, true);
                        $fn = 'pep_' . (int)$_SESSION['peppian_id'] . '_' . time() . '.' . $ext;
                        if (@move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dir . '/' . $fn)) $pic = 'uploads/peppians/' . $fn;
                    }
                }

                // Completion: status + >=1 track + (professional ? profession+working : true) + picture
                $complete = ($status !== null) && (count($tracks) >= 1)
                    && ($status === 'student' || ($profession !== '' && $working !== ''))
                    && !empty($pic) ? 1 : 0;

                $pdo->prepare("UPDATE peppians SET current_status=?, academic_tracks=?, current_profession=?, working_institute=?, profile_picture=?, profile_completed=? WHERE id=?")
                    ->execute([$status, json_encode($tracks), $profession ?: null, $working ?: null, $pic, $complete, $_SESSION['peppian_id']]);
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
                            $msg = 'You are registered! Your referral code is ' . $code;
                            try { marketing_flag($pdo, 'referral', 'New referee joined: ' . $code); } catch (Exception $e) {}
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
        $stmt = $pdo->prepare("SELECT r.*, p.academic_year, p.user_discount, p.alumni_earning, p.end_date, p.terms
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
    --gold:#d4a13a; --gold-d:#b8861f; --gold-l:#f0d595;
    --plum:#3d1528; --plum-2:#5b1f3a; --plum-3:#7a2b4f;
    --ink:#2a2118; --muted:#8a8175; --line:#ece6dc; --bg:#f6f1e8;
    --card:#fffdf9; --ok:#1f9d63; --ok-bg:#e6f7ee; --err:#c0392b; --err-bg:#fdecea;
    --shadow:0 4px 24px rgba(61,21,40,.08); --shadow-lg:0 18px 50px rgba(61,21,40,.18);
    --radius:20px;
}
* { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body {
    font-family:'Plus Jakarta Sans','Segoe UI',system-ui,sans-serif; color:var(--ink);
    background:
        radial-gradient(ellipse 70% 55% at 8% 0%, rgba(212,161,58,.10) 0%, transparent 55%),
        radial-gradient(ellipse 60% 50% at 95% 100%, rgba(123,43,79,.10) 0%, transparent 55%),
        var(--bg);
    min-height:100vh; line-height:1.55; -webkit-font-smoothing:antialiased;
}
a { color:var(--gold-d); text-decoration:none; }
a:hover { text-decoration:underline; }

/* ── Top bar ── */
.topbar {
    background:rgba(255,253,249,.85); backdrop-filter:blur(14px);
    border-bottom:1px solid var(--line); padding:15px 26px;
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:20;
}
.brand { display:flex; align-items:center; gap:11px; font-size:1.45rem; font-weight:800; color:var(--plum-2); letter-spacing:-.6px; }
.brand .logo-badge {
    width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg, var(--gold), var(--gold-d)); color:#fff; font-size:1.05rem;
    box-shadow:0 4px 12px rgba(212,161,58,.4);
}
.brand small { display:block; font-weight:500; font-size:.6rem; letter-spacing:3px; color:var(--muted); margin-top:-2px; }
.topbar-right { display:flex; align-items:center; gap:14px; }
.who { font-size:.84rem; color:var(--muted); }
.who strong { color:var(--ink); font-weight:600; }

/* ── Layout ── */
.wrap { max-width:940px; margin:0 auto; padding:30px 18px 70px; }

/* ── Hero ── */
.hero {
    position:relative; overflow:hidden; color:#fff; border-radius:26px; padding:42px 38px;
    margin-bottom:26px;
    background:linear-gradient(135deg, var(--plum) 0%, var(--plum-2) 50%, var(--plum-3) 100%);
    box-shadow:var(--shadow-lg);
}
.hero::before {
    content:''; position:absolute; inset:0; pointer-events:none;
    background:
        radial-gradient(circle 280px at 100% 0%, rgba(212,161,58,.35) 0%, transparent 60%),
        radial-gradient(circle 220px at 0% 120%, rgba(240,213,149,.18) 0%, transparent 55%);
}
.hero::after {
    content:''; position:absolute; right:-40px; top:-40px; width:200px; height:200px; border-radius:50%;
    border:1.5px solid rgba(255,255,255,.08); pointer-events:none;
}
.hero-badge {
    display:inline-flex; align-items:center; gap:7px; background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.22); backdrop-filter:blur(8px);
    color:var(--gold-l); font-size:.74rem; font-weight:700; letter-spacing:.6px; text-transform:uppercase;
    padding:6px 14px; border-radius:50px; margin-bottom:16px; position:relative; z-index:1;
}
.hero h1 { font-size:2rem; font-weight:800; letter-spacing:-.7px; margin-bottom:10px; position:relative; z-index:1; line-height:1.15; }
.hero p { opacity:.92; font-size:1rem; max-width:560px; position:relative; z-index:1; }
.hero .perks { display:flex; gap:22px; flex-wrap:wrap; margin-top:20px; position:relative; z-index:1; }
.hero .perk { display:flex; align-items:center; gap:9px; font-size:.86rem; font-weight:500; }
.hero .perk i { width:30px; height:30px; border-radius:9px; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; color:var(--gold-l); }

/* ── Cards ── */
.card {
    background:var(--card); border:1px solid var(--line); border-radius:var(--radius);
    padding:30px; margin-bottom:22px; box-shadow:var(--shadow);
    animation:rise .5s cubic-bezier(.2,.7,.3,1) both;
}
@keyframes rise { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:none; } }
.card h2 { font-size:1.3rem; font-weight:800; letter-spacing:-.4px; margin-bottom:6px; }
.card .sub { color:var(--muted); font-size:.92rem; margin-bottom:22px; }

/* ── Fields ── */
.field { margin-bottom:16px; }
.field label { display:block; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6b6357; margin-bottom:7px; }
.field input, .field select, .field textarea {
    width:100%; padding:13px 15px; border:1.6px solid var(--line); border-radius:12px;
    font-size:.94rem; font-family:inherit; color:var(--ink); background:#fffefb; transition:border-color .18s, box-shadow .18s;
}
.field input:focus, .field select:focus, .field textarea:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 4px rgba(212,161,58,.14); }
.field input::placeholder { color:#b9b1a4; }
.grid2 { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
@media(max-width:560px){ .grid2{ grid-template-columns:1fr; } }

/* ── Buttons ── */
.btn {
    display:inline-flex; align-items:center; justify-content:center; gap:9px;
    background:linear-gradient(135deg, var(--gold), var(--gold-d)); color:#fff; border:none;
    border-radius:12px; padding:13px 26px; font-weight:700; font-size:.94rem; font-family:inherit;
    cursor:pointer; text-decoration:none; transition:transform .15s, box-shadow .15s, filter .15s;
    box-shadow:0 6px 18px rgba(184,134,31,.32);
}
.btn:hover { transform:translateY(-2px); box-shadow:0 10px 26px rgba(184,134,31,.4); text-decoration:none; filter:brightness(1.03); }
.btn:active { transform:translateY(0); }
.btn-block { width:100%; }
.btn-ghost { background:#fff; color:var(--ink); border:1.6px solid var(--line); box-shadow:none; }
.btn-ghost:hover { background:#faf7f0; box-shadow:0 4px 12px rgba(0,0,0,.05); }
.btn-google { background:#fff; color:#3c4043; border:1.6px solid var(--line); box-shadow:none; font-weight:600; }
.btn-google:hover { background:#fafafa; box-shadow:0 3px 10px rgba(0,0,0,.07); }

.or { display:flex; align-items:center; gap:14px; margin:18px 0; color:var(--muted); font-size:.76rem; font-weight:600; text-transform:uppercase; letter-spacing:.8px; }
.or::before, .or::after { content:''; flex:1; height:1px; background:var(--line); }

/* ── Alerts ── */
.alert { padding:14px 18px; border-radius:13px; font-size:.9rem; font-weight:600; margin-bottom:18px; display:flex; gap:10px; align-items:center; }
.alert.ok { background:var(--ok-bg); color:var(--ok); }
.alert.err { background:var(--err-bg); color:var(--err); }
.alert.info { background:#fdf6e7; color:#8a6d1e; font-weight:500; border:1px solid #f0e3c0; }

/* ── Tabs (auth) ── */
.tab-row { display:flex; gap:0; margin-bottom:22px; background:#f3ede2; padding:5px; border-radius:14px; }
.tab-row button { flex:1; padding:11px; border:none; background:transparent; border-radius:10px; font-weight:700; font-size:.9rem; cursor:pointer; color:var(--muted); transition:all .2s; font-family:inherit; }
.tab-row button.on { background:var(--card); color:var(--plum-2); box-shadow:0 2px 8px rgba(0,0,0,.08); }

/* ── Stats ── */
.stat-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
@media(max-width:680px){ .stat-row{ grid-template-columns:1fr 1fr; } }
.stat { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:18px; text-align:center; box-shadow:var(--shadow); transition:transform .2s; }
.stat:hover { transform:translateY(-3px); }
.stat .v { font-size:1.7rem; font-weight:800; color:var(--plum-2); letter-spacing:-.5px; }
.stat .l { font-size:.68rem; color:var(--muted); text-transform:uppercase; letter-spacing:.6px; margin-top:5px; font-weight:700; }

/* ── Chips & pills ── */
.chip { display:inline-flex; align-items:center; gap:7px; background:linear-gradient(135deg, var(--gold-l), #f7e6bd); color:#7a5a12; font-weight:700; padding:6px 15px; border-radius:50px; font-size:.82rem; }
.pill-list { display:flex; flex-wrap:wrap; gap:9px; }
.pill { background:#f3ede2; border-radius:50px; padding:7px 16px; font-size:.83rem; font-weight:600; color:#6b5d44; }

/* ── Referral link row ── */
.ref-link { display:flex; gap:9px; margin-top:11px; }
.ref-link input { flex:1; padding:12px 14px; border:1.6px solid var(--line); border-radius:11px; font-size:.86rem; background:#faf7f0; color:var(--plum-2); font-weight:600; }

/* ── Loading overlay ── */
.loading-overlay { position:fixed; inset:0; background:rgba(61,21,40,.9); backdrop-filter:blur(6px); display:none; align-items:center; justify-content:center; z-index:200; flex-direction:column; color:#fff; }
.spinner { width:58px; height:58px; border:5px solid rgba(255,255,255,.22); border-top-color:var(--gold-l); border-radius:50%; animation:spin .9s linear infinite; margin-bottom:20px; }
@keyframes spin { to { transform:rotate(360deg); } }
.loading-overlay .lt { font-weight:700; font-size:1.05rem; letter-spacing:.3px; }
.loading-overlay .ls { font-size:.85rem; opacity:.75; margin-top:6px; }

/* ── Premium referral coupon (mirrors the printed card) ── */
.coupon-card {
    position:relative; overflow:hidden; max-width:430px; aspect-ratio:1.75/1;
    border-radius:18px; padding:24px 26px; color:#7a3d00;
    background:linear-gradient(135deg,#fbe6b4 0%, #f4c66e 48%, #eaa94d 100%);
    box-shadow:0 14px 38px rgba(184,134,31,.3);
}
.coupon-card::before { content:''; position:absolute; inset:0; background:radial-gradient(circle 180px at 88% 18%, rgba(255,255,255,.4) 0%, transparent 60%); pointer-events:none; }
.coupon-card .cc-exp { font-size:.78rem; font-weight:700; opacity:.85; position:relative; z-index:1; }
.coupon-card .cc-brand { position:absolute; top:18px; right:22px; z-index:1; }
.coupon-card .cc-brand img { height:34px; width:auto; display:block; filter:drop-shadow(0 1px 2px rgba(150,90,0,.25)); }
.coupon-card .cc-tag { position:absolute; top:48px; right:24px; font-size:.62rem; font-weight:700; letter-spacing:1.5px; color:rgba(122,61,0,.7); z-index:1; }
.coupon-card .cc-gift { position:absolute; left:26px; top:48px; font-size:2.8rem; color:#e8703f; filter:drop-shadow(0 4px 6px rgba(160,60,20,.25)); z-index:1; }
.coupon-card .cc-name { font-size:1.55rem; font-weight:800; margin-top:88px; position:relative; z-index:1; font-family:Georgia,serif; }
.coupon-card .cc-phone { font-size:.82rem; font-weight:600; opacity:.8; position:relative; z-index:1; }
.coupon-card .cc-code { position:absolute; right:24px; bottom:22px; font-size:1.25rem; font-weight:800; letter-spacing:1px; border:2px dashed rgba(122,61,0,.55); padding:7px 16px; border-radius:9px; background:rgba(255,255,255,.25); z-index:1; }

/* ── Empty/section helpers ── */
.section-tag { display:inline-flex; align-items:center; gap:8px; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:var(--gold-d); margin-bottom:14px; }
.divider { height:1px; background:var(--line); margin:22px 0; }
.foot { text-align:center; color:var(--muted); font-size:.82rem; margin-top:36px; }
.foot .fb { font-weight:800; color:var(--plum-2); }

/* Terms toggle box */
.terms-box { background:#faf7f0; border:1px solid var(--line); border-radius:12px; padding:16px; font-size:.84rem; white-space:pre-wrap; margin-bottom:16px; max-height:220px; overflow:auto; color:#5c5343; }
.tc-check { display:flex; gap:10px; align-items:flex-start; font-size:.88rem; margin:8px 0 18px; }
.tc-check input { margin-top:3px; width:17px; height:17px; accent-color:var(--gold-d); }

/* Alumni profile card */
.profile-card .profile-head { display:flex; gap:18px; align-items:center; flex-wrap:wrap; }
.profile-pic { width:66px; height:66px; border-radius:50%; overflow:hidden; flex-shrink:0; background:linear-gradient(135deg,var(--gold),var(--gold-d)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.6rem; font-weight:800; box-shadow:0 6px 16px rgba(184,134,31,.3); }
.profile-pic img { width:100%; height:100%; object-fit:cover; }
.pc-meter { height:11px; background:#efe7d8; border-radius:50px; overflow:hidden; margin-bottom:6px; }
.pc-fill { height:100%; border-radius:50px; background:linear-gradient(90deg,#f0b54a,#d4a13a); transition:width 1.1s cubic-bezier(.2,.8,.3,1); position:relative; }
.pc-fill::after { content:''; position:absolute; inset:0; background:linear-gradient(90deg,transparent,rgba(255,255,255,.5),transparent); animation:pc-shine 2s linear infinite; }
.pc-fill.done { background:linear-gradient(90deg,#1f9d63,#27c27a); }
@keyframes pc-shine { from{transform:translateX(-100%);} to{transform:translateX(100%);} }
.pc-label { font-size:.84rem; font-weight:700; color:var(--gold-d); }
.status-pill { display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border:1.6px solid var(--line); border-radius:50px; font-weight:600; font-size:.88rem; cursor:pointer; }
.status-pill input { accent-color:var(--gold-d); }
.track-row { display:flex; gap:9px; margin-bottom:9px; align-items:center; }
.track-row input { flex:1; padding:11px 13px; border:1.6px solid var(--line); border-radius:11px; font-size:.9rem; }
.track-del { background:#fdecea; color:#c0392b; border:none; border-radius:9px; width:38px; height:38px; cursor:pointer; flex-shrink:0; }
.track-del:hover { background:#f8d7d2; }
</style>
</head>
<body>
<div class="topbar">
    <div class="brand">
        <span class="logo-badge"><i class="fas fa-graduation-cap"></i></span>
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
            <button class="btn" type="submit"><i class="fas fa-rocket"></i> Apply &amp; Get My Referral Code</button>
        </form>
    </div>
    <?php endforeach; ?>

    <?php if (empty($joinable) && empty($my_referees)): ?>
    <div class="card"><div class="alert info"><i class="fas fa-circle-info"></i> No active referral program right now. Check back when PEPP opens the next one.</div></div>
    <?php endif; ?>

    <!-- My referral codes + wallets -->
    <?php foreach ($my_referees as $r): $w = $r['wallet'];
        $link = $base_url . '/register.php?ref=' . urlencode($r['referral_code']); ?>
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
            <div class="coupon-card" id="coupon-<?php echo (int)$r['id']; ?>">
                <div class="cc-exp">Exp. Date <?php echo !empty($r['end_date']) ? date('F j, Y', strtotime($r['end_date'])) : '-'; ?></div>
                <div class="cc-brand"><img src="assets/img/pepp-logo-text.png" alt="PEPP" crossorigin="anonymous"></div>
                <div class="cc-tag">REFERRAL COUPON</div>
                <div class="cc-gift"><i class="fas fa-gift"></i></div>
                <div class="cc-name"><?php echo pep_e($me['full_name']); ?></div>
                <div class="cc-phone">(<?php echo pep_e($me['whatsapp']); ?>)</div>
                <div class="cc-code"><?php echo pep_e($r['referral_code']); ?></div>
            </div>
            <button class="btn btn-ghost" type="button" style="margin-top:10px;" onclick="downloadCoupon(<?php echo (int)$r['id']; ?>)"><i class="fas fa-download"></i> Download Coupon</button>
        </div>
    </div>
    <?php endforeach; ?>

<?php endif; ?>

    <div class="foot">&copy; <?php echo date('Y'); ?> PEPP Learning - Labinc Education Pvt. Ltd.</div>
</div>

<div class="loading-overlay" id="loading"><div class="spinner"></div><div class="lt">Setting up your referral account</div><div class="ls">Generating your unique referral code…</div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function swTab(t) {
    document.getElementById('t-signin').classList.toggle('on', t==='signin');
    document.getElementById('t-signup').classList.toggle('on', t==='signup');
    document.getElementById('form-signin').style.display = t==='signin'?'block':'none';
    document.getElementById('form-signup').style.display = t==='signup'?'block':'none';
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
// Animate the completion meter on load
window.addEventListener('load', function () {
    var fill = document.querySelector('.pc-fill');
    if (fill) { var w = fill.style.width; fill.style.width = '0%'; setTimeout(function(){ fill.style.width = w; }, 200); }
});
function downloadCoupon(id) {
    var node = document.getElementById('coupon-'+id);
    html2canvas(node, {scale:2, backgroundColor:null, useCORS:true, allowTaint:true, logging:false}).then(function(canvas){
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = 'pepp-referral-coupon.png';
        a.click();
    });
}
</script>
</body>
</html>
