<?php
/**
 * PEPP Learning — Public Invited Faculty Registration.
 *
 * Creates a staff_registration_requests row with application_for = 'guest_faculty'
 * and status = 'pending'. It NEVER creates an employee, faculty, session, payment or
 * appointment. Approval + payment terms happen later in Employee Management.
 *
 * Security: CSRF, honeypot, timing, dual-layer rate limiting (session + IP),
 *           server-side validation, secure photo upload (finfo + GD re-encode),
 *           server-side enforcement of the admin "Guest Faculty Banking Details" toggle
 *           (banking POST fields are rejected when OFF — never trusted from the client),
 *           AES-256-GCM encryption of the account number.
 * NO authentication required — public page.
 */
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
require_once 'includes/encryption_helper.php';
require_once 'includes/guest_faculty_helper.php';
require_once 'includes/staff_type_helper.php';
require_once 'includes/policy_helper.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$schema = guest_faculty_schema_status($pdo);
$form_ready = $schema['ready'];
if (!$form_ready) error_log('invited-faculty-registration: schema not ready: ' . implode(', ', $schema['missing']));

// Server-side truth for the banking toggle (evaluated per request; never from the client).
$banking_on = $form_ready && guest_faculty_banking_enabled($pdo);

// Load active guest faculty custom fields
$active_custom_fields = staff_load_active_custom_fields($pdo);
$gf_custom_fields = array_values(array_filter($active_custom_fields, fn($cf) => $cf['application_for'] === 'guest_faculty'));

if (empty($_SESSION['gf_csrf_token'])) {
    $_SESSION['gf_csrf_token'] = bin2hex(random_bytes(32));
}

$error_msg = '';
$submitted_ref = '';
if (isset($_GET['submitted']) && preg_match('/^GUEST-FACULTY-\d{4}-\d{5,}$/', (string)$_GET['submitted'])) {
    $submitted_ref = (string)$_GET['submitted'];
}

$country_codes = guest_faculty_country_codes();
$old = [
    'full_name' => '', 'mobile_country_code' => '+91', 'mobile_number' => '', 'email' => '', 'qualifications' => '',
    'bank_name' => '', 'ifsc_code' => '', 'upi_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form_ready) {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rate_key = substr('g:' . $client_ip, 0, 45);

    foreach (array_keys($old) as $k) $old[$k] = trim((string)($_POST[$k] ?? $old[$k]));
    if ($old['mobile_country_code'] === '') $old['mobile_country_code'] = '+91';

    do { // single-pass block so we can `break` to render
        // Rate limiting — record ALL attempts first (existing ERP pattern)
        try {
            $pdo->prepare("INSERT INTO staff_registration_rate_limits (ip_address) VALUES (?)")->execute([$rate_key]);
            $pdo->exec("DELETE FROM staff_registration_rate_limits WHERE attempt_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $st = $pdo->prepare("SELECT COUNT(*) FROM staff_registration_rate_limits WHERE ip_address = ? AND attempt_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $st->execute([$rate_key]);
            if ((int)$st->fetchColumn() > 5) { $error_msg = 'Too many registration attempts. Please try again later.'; break; }
        } catch (Throwable $e) { /* rate-limit table missing: non-blocking, session limit still applies */ }

        $_SESSION['gf_reg_count'] = ($_SESSION['gf_reg_count'] ?? 0) + 1;
        if (!isset($_SESSION['gf_reg_window'])) $_SESSION['gf_reg_window'] = time();
        if (time() - $_SESSION['gf_reg_window'] > 900) { $_SESSION['gf_reg_count'] = 1; $_SESSION['gf_reg_window'] = time(); }
        if ($_SESSION['gf_reg_count'] > 4) { $error_msg = 'Too many registration attempts. Please try again in 15 minutes.'; break; }

        if (!hash_equals((string)($_SESSION['gf_csrf_token'] ?? ''), (string)($_POST['gf_csrf_token'] ?? ''))) {
            $error_msg = 'Security token mismatch. Please reload the page and try again.'; break;
        }
        if (!empty($_POST['website'])) { $error_msg = 'Registration rejected.'; break; }
        $form_ts = (int)($_POST['_ts'] ?? 0);
        if ($form_ts > 0 && (time() - $form_ts) < 4) { $error_msg = 'Please take your time to fill out the form.'; break; }

        // Application type is NEVER read from the client; this page only creates guest_faculty.

        $errors = [];
        [$ok, $full_name, $e] = gf_validate_name((string)($_POST['full_name'] ?? ''));          if (!$ok) $errors[] = $e;
        [$ok, $cc, $mobile, $e] = gf_normalize_mobile((string)($_POST['mobile_country_code'] ?? '+91'), (string)($_POST['mobile_number'] ?? '')); if (!$ok) $errors[] = $e;
        [$ok, $email, $e] = gf_validate_email((string)($_POST['email'] ?? ''));                  if (!$ok) $errors[] = $e;
        [$ok, $quals, $e] = gf_validate_qualifications((string)($_POST['qualifications'] ?? '')); if (!$ok) $errors[] = $e;

        // ── Custom fields (scoped to guest_faculty) ──
        $cf_result = staff_validate_custom_fields($active_custom_fields, 'guest_faculty', $_POST);
        foreach ($cf_result['errors'] as $ce) $errors[] = $ce;
        $custom_field_json = $cf_result['json'];

        // ── Mandatory Policy Consent ──
        if (empty($_POST['policy_consent'])) {
            $errors[] = 'You must read and agree to the Guest Faculty Policy to submit your registration.';
        }
        $gf_policy_doc = policy_get($pdo, 'guest_faculty_policy');
        $gf_policy_version = $gf_policy_doc ? $gf_policy_doc['current_version'] : '1.0';

        // ── Banking: server-side gate ──
        $banking = null;
        if ($banking_on) {
            $bv = gf_validate_banking($_POST);
            foreach ($bv['errors'] as $be) $errors[] = $be;
            $banking = $bv['data'];
        } else {
            foreach (guest_faculty_banking_post_keys() as $k) {
                if (isset($_POST[$k]) && trim((string)$_POST[$k]) !== '') {
                    $errors[] = 'Banking details are not being collected on this form right now. Please reload the page and submit again without them.';
                    error_log('invited-faculty-registration: banking POST rejected while setting OFF (ip ' . $client_ip . ')');
                    break;
                }
            }
        }

        if ($errors) { $error_msg = implode(' ', array_unique($errors)); break; }

        // Duplicate check (scoped to guest_faculty)
        try {
            if (gf_has_active_duplicate($pdo, $email, $mobile)) {
                $error_msg = 'We already have an active registration associated with these details. Please contact PEPP Learning if you need to update your application.';
                break;
            }
        } catch (Throwable $e) { error_log('gf duplicate check: ' . $e->getMessage()); $error_msg = 'An error occurred. Please try again.'; break; }

        // Photo (validated only after the cheap checks pass, so rejected submissions store nothing)
        [$photo_path, $photo_err] = gf_store_photo($_FILES['photo_file'] ?? []);
        if ($photo_err !== '') { $error_msg = $photo_err; break; }

        try {
            $ref = gf_insert_application($pdo, [
                'photo' => $photo_path, 'full_name' => $full_name, 'mobile_cc' => $cc, 'mobile' => $mobile,
                'email' => $email, 'qualifications' => $quals,
                'custom_field_values' => $custom_field_json,
                'policy_version' => $gf_policy_version,
            ], $banking, 'pepp_encrypt', $client_ip, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $_SESSION['gf_csrf_token'] = bin2hex(random_bytes(32));
            header('Location: invited-faculty-registration.php?submitted=' . urlencode($ref));
            exit;
        } catch (Throwable $e) {
            // Do not leave an orphan photo if the DB insert failed.
            $orphan = __DIR__ . '/../' . $photo_path;
            if (!empty($photo_path) && is_file($orphan)) @unlink($orphan);
            error_log('invited-faculty-registration error: ' . $e->getMessage());
            $error_msg = 'An error occurred processing your registration. Please try again.';
        }
    } while (false);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invited Faculty Registration Form — PEPP Learning</title>
    <meta name="description" content="Register as an invited faculty with PEPP Learning. Submitting this form does not guarantee appointment.">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f172a;
            background-image: radial-gradient(ellipse 80% 60% at 10% 10%, rgba(20,184,166,.16) 0%, transparent 60%),
                              radial-gradient(ellipse 60% 50% at 90% 90%, rgba(99,102,241,.12) 0%, transparent 55%);
            min-height: 100vh; color: #e2e8f0; padding: 20px 0; }
        .container { max-width: 720px; margin: 0 auto; padding: 0 16px; }
        .header { text-align: center; margin-bottom: 1.6rem; padding: 1.2rem; }
        .header img { width: 64px; height: 64px; border-radius: 14px; margin-bottom: .8rem; }
        .header h1 { font-size: 1.6rem; font-weight: 800; background: linear-gradient(135deg, #2dd4bf, #818cf8); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }
        .header p { color: #94a3b8; font-size: .88rem; margin-top: .5rem; line-height: 1.55; max-width: 560px; margin-left: auto; margin-right: auto; }
        .card { background: rgba(30,41,59,.85); border: 1px solid rgba(148,163,184,.12); border-radius: 16px; padding: 1.6rem; margin-bottom: 1.2rem; backdrop-filter: blur(10px); }
        .card-title { font-size: 1rem; font-weight: 700; color: #99f6e4; margin-bottom: 1.2rem; display: flex; align-items: center; gap: 8px; }
        .card-title i { width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .75rem; background: rgba(20,184,166,.15); color: #2dd4bf; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        .field { flex: 1; min-width: 200px; margin-bottom: 14px; }
        .field.full { min-width: 100%; }
        label { display: block; font-size: .78rem; font-weight: 600; color: #94a3b8; margin-bottom: 5px; letter-spacing: .02em; }
        label .req { color: #f87171; }
        .hint { font-size: .72rem; color: #64748b; margin-top: 5px; line-height: 1.45; }
        input, select, textarea { width: 100%; padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(148,163,184,.2); background: rgba(15,23,42,.6); color: #f1f5f9; font-family: inherit; font-size: .88rem; transition: border-color .2s, box-shadow .2s; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #2dd4bf; box-shadow: 0 0 0 3px rgba(20,184,166,.16); }
        select option { background: #1e293b; color: #e2e8f0; }
        textarea { resize: vertical; min-height: 96px; }
        .phone-row { display: flex; gap: 8px; }
        .phone-row select { flex: 0 0 150px; }
        .phone-row input { flex: 1; }
        .honeypot { position: absolute; left: -9999px; }
        .error-box { background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.3); border-radius: 12px; padding: 14px; margin-bottom: 1.2rem; color: #fca5a5; font-size: .85rem; }
        .notice-box { background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.25); border-radius: 12px; padding: 14px; margin-bottom: 1.2rem; color: #fcd34d; font-size: .82rem; line-height: 1.55; }
        .success-card { text-align: center; padding: 2.2rem 1.4rem; }
        .success-card i.big { font-size: 3rem; color: #2dd4bf; margin-bottom: 1rem; }
        .success-card .ref { display: inline-block; margin: .8rem 0; padding: 8px 16px; border-radius: 10px; background: rgba(20,184,166,.12); border: 1px solid rgba(20,184,166,.3); color: #5eead4; font-weight: 700; letter-spacing: .04em; }
        .btn-submit { width: 100%; padding: 14px; border: none; border-radius: 12px; background: linear-gradient(135deg, #14b8a6, #6366f1); color: #fff; font-family: inherit; font-size: 1rem; font-weight: 700; cursor: pointer; transition: transform .2s, box-shadow .2s; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(20,184,166,.3); }
        .btn-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; }
        .footer-note { text-align: center; color: #64748b; font-size: .75rem; margin-top: 2rem; }
        .consent { font-size: .75rem; color: #94a3b8; line-height: 1.55; text-align: center; margin-top: 12px; }
        @media (max-width: 540px) { .phone-row { flex-direction: column; } .phone-row select { flex: 1 1 auto; } .card { padding: 1.2rem; } }
    </style>
</head>
<body>
<main class="container">
    <header class="header">
        <img src="logo.png" alt="PEPP Learning" onerror="this.style.display='none'">
        <h1>Invited Faculty Registration Form</h1>
        <p>Please complete your registration details below. Your photograph may be used for PEPP Learning academic/event posters and promotional materials.</p>
    </header>

<?php if ($submitted_ref !== ''): ?>
    <section class="card success-card" id="gfSuccess">
        <i class="fas fa-circle-check big"></i>
        <h2 style="font-size:1.25rem;color:#f1f5f9;">Thank you — your details have been received</h2>
        <div class="ref" id="gfRef"><?php echo htmlspecialchars($submitted_ref, ENT_QUOTES, 'UTF-8'); ?></div>
        <p style="color:#94a3b8;font-size:.85rem;line-height:1.6;">Our academic team will review your registration. Submitting this form does not by itself constitute an appointment or a commitment to any engagement or payment. We will contact you if we wish to proceed.</p>
    </section>
<?php elseif (!$form_ready): ?>
    <section class="card" id="gfUnavailable">
        <div class="notice-box"><i class="fas fa-triangle-exclamation"></i> This registration form is temporarily unavailable. Please try again later or contact PEPP Learning.</div>
    </section>
<?php else: ?>

    <?php if ($error_msg): ?>
        <div class="error-box" role="alert" id="gfError"><i class="fas fa-exclamation-circle" style="margin-right:6px;"></i><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="POST" id="gfRegForm" enctype="multipart/form-data" novalidate autocomplete="on">
        <input type="hidden" name="gf_csrf_token" value="<?php echo htmlspecialchars($_SESSION['gf_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="_ts" value="<?php echo time(); ?>">
        <div class="honeypot" aria-hidden="true"><label>Website<input type="text" name="website" autocomplete="off" tabindex="-1"></label></div>

        <section class="card">
            <div class="card-title"><i class="fas fa-user"></i> Personal Information</div>
            <div class="row">
                <div class="field full">
                    <label for="gfFullName">Full Name <span class="req">*</span></label>
                    <input type="text" id="gfFullName" name="full_name" maxlength="150" value="<?php echo htmlspecialchars($old['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="name">
                </div>
            </div>
            <div class="row">
                <div class="field full">
                    <label for="gfMobile">Mobile Number <span class="req">*</span></label>
                    <div class="phone-row">
                        <select name="mobile_country_code" id="gfCountryCode" aria-label="Country code">
                            <?php foreach ($country_codes as $code => $label): ?>
                                <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $old['mobile_country_code'] === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="tel" id="gfMobile" name="mobile_number" inputmode="numeric" maxlength="16" placeholder="Mobile number" value="<?php echo htmlspecialchars($old['mobile_number'], ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="tel-national">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="field full">
                    <label for="gfEmail">Email ID <span class="req">*</span></label>
                    <input type="email" id="gfEmail" name="email" maxlength="190" value="<?php echo htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="email">
                </div>
            </div>
            <div class="row">
                <div class="field full">
                    <label for="gfPhoto">Photo <span class="req">*</span></label>
                    <input type="file" id="gfPhoto" name="photo_file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" required>
                    <div class="hint">Please upload a clear, high-quality photograph. This photo may be used in PEPP Learning event posters and academic promotional materials. (JPG / PNG / WEBP, up to 8 MB, at least 300×300 px)</div>
                </div>
            </div>
            <div class="row">
                <div class="field full">
                    <label for="gfQualifications">Qualifications <span class="req">*</span></label>
                    <textarea id="gfQualifications" name="qualifications" maxlength="1000" required placeholder="e.g. M.Sc. Zoology, Ph.D. (University of …), NET/JRF"><?php echo htmlspecialchars($old['qualifications'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            </div>
        </section>

        <?php if ($banking_on): ?>
        <section class="card" id="gfBankingCard">
            <div class="card-title"><i class="fas fa-university"></i> Banking / Payment Details <span style="font-weight:500;color:#94a3b8;font-size:.78rem;">(optional)</span></div>
            <div class="row">
                <div class="field full">
                    <label for="gfBankName">Bank Name</label>
                    <input type="text" id="gfBankName" name="bank_name" maxlength="100" value="<?php echo htmlspecialchars($old['bank_name'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label for="gfAccount">Account Number</label>
                    <input type="text" id="gfAccount" name="bank_account_number" maxlength="24" autocomplete="off" inputmode="text">
                    <div class="hint"><i class="fas fa-lock"></i> Encrypted at rest. Never shown in lists.</div>
                </div>
                <div class="field">
                    <label for="gfIfsc">IFSC Code</label>
                    <input type="text" id="gfIfsc" name="ifsc_code" maxlength="11" placeholder="e.g. SBIN0001234" style="text-transform:uppercase;" value="<?php echo htmlspecialchars($old['ifsc_code'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                </div>
            </div>
            <div class="row">
                <div class="field full">
                    <label for="gfUpi">UPI ID / Number</label>
                    <input type="text" id="gfUpi" name="upi_id" maxlength="100" placeholder="yourname@bank or 10-digit UPI number" value="<?php echo htmlspecialchars($old['upi_id'], ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    <div class="hint">If you provide bank details, please enter Bank Name, Account Number and IFSC together.</div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($gf_custom_fields)): ?>
        <section class="card" id="gfCustomFieldsCard">
            <div class="card-title"><i class="fas fa-puzzle-piece"></i> Additional Information</div>
            <?php foreach ($gf_custom_fields as $cf):
                $cf_lbl = $cf['field_label'] ?? $cf['field_name'] ?? ('Field #' . $cf['id']);
                $cf_name = 'cf_' . $cf['id'];
                $cf_post = htmlspecialchars($_POST[$cf_name] ?? $_POST[$cf['field_key']] ?? '', ENT_QUOTES, 'UTF-8');
                $cf_req = $cf['is_required'] ? 'required' : '';
            ?>
            <div class="row">
                <div class="field full">
                    <label for="<?php echo $cf_name; ?>"><?php echo htmlspecialchars($cf_lbl, ENT_QUOTES, 'UTF-8'); ?><?php if ($cf['is_required']): ?> <span class="req">*</span><?php endif; ?></label>
                    <?php
                    switch ($cf['field_type']):
                        case 'text': ?>
                            <input type="text" id="<?php echo $cf_name; ?>" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                        <?php break; case 'number': ?>
                            <input type="number" id="<?php echo $cf_name; ?>" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                        <?php break; case 'email': ?>
                            <input type="email" id="<?php echo $cf_name; ?>" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                        <?php break; case 'date': ?>
                            <input type="date" id="<?php echo $cf_name; ?>" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                        <?php break; case 'phone': ?>
                            <input type="tel" id="<?php echo $cf_name; ?>" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                        <?php break; case 'textarea': ?>
                            <textarea id="<?php echo $cf_name; ?>" name="<?php echo $cf_name; ?>" rows="3" <?php echo $cf_req; ?>><?php echo $cf_post; ?></textarea>
                        <?php break; case 'dropdown':
                            $opts_str = (string)($cf['field_options'] ?? $cf['dropdown_options'] ?? '');
                            $opts = array_map('trim', explode(',', $opts_str));
                            ?>
                            <select id="<?php echo $cf_name; ?>" name="<?php echo $cf_name; ?>" <?php echo $cf_req; ?>>
                                <option value="">— Select —</option>
                                <?php foreach ($opts as $opt): if ($opt === '') continue; ?>
                                <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $cf_post === htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php break; endswitch; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <!-- Mandatory Guest Faculty Policy Consent -->
        <section class="card" id="gfConsentCard">
            <div class="card-title"><i class="fas fa-file-contract"></i> Policy Agreement</div>
            <div style="display:flex; align-items:flex-start; gap:12px; padding:4px 0;">
                <input type="checkbox" name="policy_consent" id="gfPolicyConsent" value="1" <?php echo !empty($_POST['policy_consent']) ? 'checked' : ''; ?> required style="width:20px; height:20px; margin-top:2px; cursor:pointer; accent-color:#2dd4bf; flex-shrink:0;">
                <label for="gfPolicyConsent" style="font-size:0.88rem; line-height:1.5; color:#e2e8f0; cursor:pointer; font-weight:normal;">
                    I have read and agree to the <a href="policy-view.php?policy=guest_faculty_policy" target="_blank" rel="noopener" style="color:#2dd4bf; font-weight:600; text-decoration:underline;">Guest Faculty Policy</a>. <span class="req">*</span>
                </label>
            </div>
        </section>

        <section class="card" style="text-align:center;">
            <button type="submit" class="btn-submit" id="gfSubmitBtn"><i class="fas fa-paper-plane" style="margin-right:6px;"></i> Submit Registration</button>
            <p class="consent">By submitting, you confirm the details are accurate and consent to your photograph being used in PEPP Learning academic/event materials. Submission does not guarantee an appointment or payment.</p>
        </section>
    </form>
<?php endif; ?>

    <div class="footer-note">&copy; <?php echo date('Y'); ?> PEPP Learning (Labinc Education Pvt. Ltd.) · All Rights Reserved</div>
</main>
<script>
(function () {
    var form = document.getElementById('gfRegForm');
    if (!form) return;
    var cc = document.getElementById('gfCountryCode');
    var mob = document.getElementById('gfMobile');
    function applyCc() {
        var india = cc.value === '+91';
        mob.maxLength = india ? 10 : 14;
        mob.placeholder = india ? '10-digit mobile number' : 'Mobile number';
    }
    cc.addEventListener('change', applyCc);
    applyCc();
    var photo = document.getElementById('gfPhoto');
    photo.addEventListener('change', function () {
        var f = photo.files && photo.files[0];
        if (f && f.size > 8 * 1024 * 1024) { alert('Photo must be 8 MB or smaller.'); photo.value = ''; }
    });
    form.addEventListener('submit', function () {
        var b = document.getElementById('gfSubmitBtn');
        b.disabled = true;
        b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
    });
})();
</script>
</body>
</html>
