<?php
/**
 * PEPP Learning — Public Staff Registration Page.
 * Allows prospective employees, faculty, and interns to submit applications.
 * NO authentication required — this is a public-facing page.
 *
 * Security: CSRF, honeypot, timing, dual-layer rate limiting (session+IP),
 *           AES-256-GCM encryption for Aadhaar and bank account.
 */
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'config/database.php';
require_once 'includes/encryption_helper.php';
require_once 'includes/file_helper.php';

// Self-healing database structure for photo columns
try {
    $pdo->query("SELECT photo FROM staff_registration_requests LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE staff_registration_requests ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER application_reference");
    } catch (Exception $ex) {
        error_log("Failed to add photo column to staff_registration_requests: " . $ex->getMessage());
    }
}
try {
    $pdo->query("SELECT photo FROM employees LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER employee_id");
    } catch (Exception $ex) {
        error_log("Failed to add photo column to employees: " . $ex->getMessage());
    }
}

// ── AJAX: PIN Code Lookup (reuse existing register.php proxy pattern) ──
if (isset($_GET['lookup_pin'])) {
    header('Content-Type: application/json');
    $pin = preg_replace('/\D/', '', $_GET['lookup_pin'] ?? '');
    if (strlen($pin) !== 6) { echo json_encode(['ok' => false]); exit; }
    $ch = curl_init("https://api.postalpincode.in/pincode/{$pin}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data[0]['PostOffice'])) {
        $po = $data[0]['PostOffice'];
        $names = array_column($po, 'Name');
        echo json_encode(['ok' => true, 'state' => $po[0]['State'] ?? '', 'places' => $names]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ── AJAX: Banks list ──
if (isset($_GET['get_banks'])) {
    header('Content-Type: application/json');
    try {
        $banks = $pdo->query("SELECT bank_name FROM indian_banks WHERE status='active' ORDER BY bank_name")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($banks);
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// ── CSRF Token ──
if (empty($_SESSION['staff_csrf_token'])) {
    $_SESSION['staff_csrf_token'] = bin2hex(random_bytes(32));
}

// ── Load active custom fields (for both form rendering and validation) ──
$active_custom_fields = [];
try {
    $active_custom_fields = $pdo->query("SELECT * FROM employee_custom_fields WHERE status = 'active' ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* Table may not exist yet */ }

// ── Process Form Submission ──
$error_msg = '';
$success_ref = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ─── Rate Limiting: RECORD ALL ATTEMPTS (including invalid/spam) ───
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // IP-based rate limiting — log BEFORE validation
    try {
        $pdo->prepare("INSERT INTO staff_registration_rate_limits (ip_address) VALUES (?)")->execute([$client_ip]);
        $pdo->exec("DELETE FROM staff_registration_rate_limits WHERE attempt_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_registration_rate_limits WHERE ip_address = ? AND attempt_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$client_ip]);
        if ((int)$stmt->fetchColumn() > 5) {
            $error_msg = 'Too many registration attempts. Please try again later.';
            goto render;
        }
    } catch (Exception $e) { /* If rate limit table doesn't exist yet, skip — non-blocking */ }

    // Session-based rate limiting
    $_SESSION['staff_reg_count'] = ($_SESSION['staff_reg_count'] ?? 0) + 1;
    if (!isset($_SESSION['staff_reg_window'])) $_SESSION['staff_reg_window'] = time();
    if (time() - $_SESSION['staff_reg_window'] > 900) {
        $_SESSION['staff_reg_count'] = 1;
        $_SESSION['staff_reg_window'] = time();
    }
    if ($_SESSION['staff_reg_count'] > 3) {
        $error_msg = 'Too many registration attempts. Please try again in 15 minutes.';
        goto render;
    }

    // CSRF check
    $token = $_POST['staff_csrf_token'] ?? '';
    if (!hash_equals($_SESSION['staff_csrf_token'] ?? '', $token)) {
        $error_msg = 'Security token mismatch. Please reload and try again.';
        goto render;
    }

    // Honeypot
    if (!empty($_POST['website'])) {
        $error_msg = 'Registration rejected.';
        goto render;
    }

    // Timing anti-spam (< 5 seconds = bot)
    $form_ts = (int)($_POST['_ts'] ?? 0);
    if ($form_ts > 0 && (time() - $form_ts) < 5) {
        $error_msg = 'Please take your time to fill out the form.';
        goto render;
    }

    // Photo Upload Validation (Mandatory)
    $uploaded_photo = $_SESSION['temp_staff_photo'] ?? '';
    $photo_error = '';
    if (isset($_FILES['photo_file']) && !empty($_FILES['photo_file']['name'])) {
        if ($_FILES['photo_file']['error'] !== UPLOAD_ERR_OK) {
            $photo_error = 'Photo file upload failed. Please try again.';
        } else {
            $new_path = handle_file_upload_with_replace('photo_file', 'photos', $uploaded_photo ?: null, ['jpg', 'jpeg', 'png', 'webp']);
            if ($new_path) {
                $uploaded_photo = $new_path;
                $_SESSION['temp_staff_photo'] = $uploaded_photo;
            } else {
                $photo_error = 'Invalid photo file. Only images (JPG, PNG, WEBP) under 5MB are allowed.';
            }
        }
    }

    // ─── Validation ───
    $full_name = trim($_POST['full_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $dob = trim($_POST['date_of_birth'] ?? '');
    $blood_group = $_POST['blood_group'] ?? '';
    $mobile_cc = trim($_POST['mobile_country_code'] ?? '+91');
    $mobile = preg_replace('/\D/', '', trim($_POST['mobile_number'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $emergency_cc = trim($_POST['emergency_country_code'] ?? '+91');
    $emergency = preg_replace('/\D/', '', trim($_POST['emergency_contact'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $pincode = preg_replace('/\D/', '', trim($_POST['pincode'] ?? ''));
    $country = trim($_POST['country'] ?? 'India');
    $state = trim($_POST['state'] ?? '');
    $place = trim($_POST['place_post_office'] ?? '');
    $aadhaar_raw = preg_replace('/\D/', '', trim($_POST['aadhaar_number'] ?? ''));
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_account_raw = preg_replace('/\s/', '', trim($_POST['bank_account_number'] ?? ''));
    $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
    $upi_id = trim($_POST['upi_id'] ?? '') ?: null;
    $application_for = $_POST['application_for'] ?? '';
    $latitude = $_POST['latitude'] ?? '';
    $longitude = $_POST['longitude'] ?? '';

    $errors = [];
    if (strlen($full_name) < 2) $errors[] = 'Full name is required.';
    if (!in_array($gender, ['Male','Female','Other','Prefer not to say'])) $errors[] = 'Select a valid gender.';
    if (!$dob || !strtotime($dob)) $errors[] = 'Valid date of birth is required.';
    if (!in_array($blood_group, ['A+','A-','B+','B-','AB+','AB-','O+','O-'])) $errors[] = 'Select a valid blood group.';
    if (strlen($mobile) < 10) $errors[] = 'Valid mobile number is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';
    if (strlen($emergency) < 10) $errors[] = 'Emergency contact is required.';
    if (strlen($address) < 5) $errors[] = 'Full address is required.';
    if (strlen($pincode) !== 6) $errors[] = 'Valid 6-digit PIN code is required.';
    if (strlen($state) < 2) $errors[] = 'State is required.';
    if (strlen($place) < 2) $errors[] = 'Place/Post Office is required.';
    if (strlen($aadhaar_raw) !== 12) $errors[] = 'Valid 12-digit Aadhaar number is required.';
    if (strlen($bank_name) < 2) $errors[] = 'Bank name is required.';
    if (strlen($bank_account_raw) < 8 || strlen($bank_account_raw) > 18) $errors[] = 'Valid bank account number is required.';
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) $errors[] = 'Valid 11-character IFSC code is required.';
    if (!in_array($application_for, ['employee','faculty','intern'])) $errors[] = 'Select a valid application type.';
    if (!is_numeric($latitude) || !is_numeric($longitude)) $errors[] = 'Geolocation is required. Please allow location access.';

    if ($photo_error) {
        $errors[] = $photo_error;
    }
    if (empty($uploaded_photo)) {
        $errors[] = 'Photo upload is required. Please upload your best quality photo.';
    }

    // ─── Validate custom fields ───
    $custom_field_data = [];
    foreach ($active_custom_fields as $cf) {
        $cf_val = trim($_POST['cf_' . $cf['id']] ?? '');
        if ($cf['is_required'] && $cf_val === '') {
            $errors[] = htmlspecialchars($cf['field_label']) . ' is required.';
            continue;
        }
        if ($cf_val === '') continue; // Optional and empty
        // Type-specific validation
        switch ($cf['field_type']) {
            case 'email':
                if (!filter_var($cf_val, FILTER_VALIDATE_EMAIL)) $errors[] = htmlspecialchars($cf['field_label']) . ': invalid email.';
                break;
            case 'number':
                if (!is_numeric($cf_val)) $errors[] = htmlspecialchars($cf['field_label']) . ': must be a number.';
                break;
            case 'date':
                if (!strtotime($cf_val)) $errors[] = htmlspecialchars($cf['field_label']) . ': invalid date.';
                break;
            case 'dropdown':
                $allowed_opts = array_map('trim', explode(',', $cf['field_options'] ?? ''));
                if (!in_array($cf_val, $allowed_opts, true)) $errors[] = htmlspecialchars($cf['field_label']) . ': invalid selection.';
                break;
            case 'phone':
                if (strlen(preg_replace('/\D/', '', $cf_val)) < 7) $errors[] = htmlspecialchars($cf['field_label']) . ': invalid phone number.';
                break;
        }
        $custom_field_data[$cf['id']] = $cf_val;
    }
    $custom_field_json = !empty($custom_field_data) ? json_encode($custom_field_data, JSON_UNESCAPED_UNICODE) : null;

    if ($errors) {
        $error_msg = implode(' ', $errors);
        goto render;
    }

    // ─── Duplicate check (blocks pending/under_review/approved) ───
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_registration_requests WHERE (email = ? OR mobile_number = ?) AND status IN ('pending','under_review','approved')");
        $stmt->execute([$email, $mobile]);
        if ((int)$stmt->fetchColumn() > 0) {
            $error_msg = 'We already have an active registration request associated with these details. Please contact PEPP Learning if you need to update your application.';
            goto render;
        }
    } catch (Exception $e) { /* If table doesn't exist yet, allow through */ }

    // ─── Encrypt Aadhaar + Bank Account ───
    try {
        $aadhaar_enc = pepp_encrypt($aadhaar_raw);
        $aadhaar_mask = mask_aadhaar($aadhaar_raw);
        $bank_account_enc = pepp_encrypt($bank_account_raw);
        $bank_account_mask = mask_bank_account($bank_account_raw);
    } catch (RuntimeException $e) {
        error_log('staff_registration encryption error: ' . $e->getMessage());
        $error_msg = 'Registration system configuration error. Please try again later or contact PEPP Learning.';
        goto render;
    }

    // ─── Generate Application Reference ───
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'staff_app_ref_seq' FOR UPDATE");
        $stmt->execute();
        $seq = (int)$stmt->fetchColumn();
        if ($seq < 1) $seq = 1;
        $fy = function_exists('get_active_academic_year_compact')
            ? get_active_academic_year_compact($pdo)
            : (function() { $m=(int)date('n'); $y=(int)date('Y'); $s=($m>=6)?$y:($y-1); return substr((string)$s,2).substr((string)($s+1),2); })();
        $app_ref = 'STAFF-' . $fy . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = NOW() WHERE setting_name = 'staff_app_ref_seq'")->execute([(string)($seq + 1)]);

        // Generate maps URL
        $maps_url = 'https://www.google.com/maps?q=' . urlencode($latitude . ',' . $longitude);

        // Insert
        $stmt = $pdo->prepare("
            INSERT INTO staff_registration_requests
            (application_reference, photo, full_name, gender, date_of_birth, blood_group,
             mobile_country_code, mobile_number, email, emergency_country_code, emergency_contact,
             address, pincode, country, state, place_post_office,
             aadhaar_encrypted, aadhaar_masked, bank_name, bank_account_encrypted, bank_account_masked, ifsc_code, upi_id,
             application_for, custom_field_values, latitude, longitude, maps_url, ip_address, user_agent, submitted_at)
            VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([
            $app_ref, $uploaded_photo, $full_name, $gender, $dob, $blood_group,
            $mobile_cc, $mobile, $email, $emergency_cc, $emergency,
            $address, $pincode, $country, $state, $place,
            $aadhaar_enc, $aadhaar_mask, $bank_name, $bank_account_enc, $bank_account_mask, $ifsc, $upi_id,
            $application_for, $custom_field_json, $latitude, $longitude, $maps_url, $client_ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
        $pdo->commit();

        // Regenerate CSRF token and clear photo session
        unset($_SESSION['temp_staff_photo']);
        $_SESSION['staff_csrf_token'] = bin2hex(random_bytes(32));

        // Redirect to success page
        header('Location: staff-registration-success.php?ref=' . urlencode($app_ref));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('staff_registration error: ' . $e->getMessage());
        $error_msg = 'An error occurred processing your registration. Please try again.';
    }
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Registration — PEPP Learning</title>
    <meta name="description" content="Join PEPP Learning as an employee, faculty member, or intern. Apply now.">
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            background-image: radial-gradient(ellipse 80% 60% at 10% 10%, rgba(99,102,241,.15) 0%, transparent 60%),
                              radial-gradient(ellipse 60% 50% at 90% 90%, rgba(139,92,246,.10) 0%, transparent 55%);
            min-height: 100vh; color: #e2e8f0; padding: 20px 0;
        }
        .container { max-width: 720px; margin: 0 auto; padding: 0 16px; }
        .header {
            text-align: center; margin-bottom: 2rem; padding: 1.5rem;
        }
        .header img { width: 64px; height: 64px; border-radius: 14px; margin-bottom: .8rem; }
        .header h1 {
            font-size: 1.6rem; font-weight: 800; color: #f1f5f9;
            background: linear-gradient(135deg, #818cf8, #a78bfa);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .header p { color: #94a3b8; font-size: .88rem; margin-top: .4rem; }
        .card {
            background: rgba(30,41,59,.85); border: 1px solid rgba(148,163,184,.12);
            border-radius: 16px; padding: 1.6rem; margin-bottom: 1.2rem;
            backdrop-filter: blur(10px);
        }
        .card-title {
            font-size: 1rem; font-weight: 700; color: #c7d2fe; margin-bottom: 1.2rem;
            display: flex; align-items: center; gap: 8px;
        }
        .card-title i {
            width: 28px; height: 28px; border-radius: 8px; display: flex;
            align-items: center; justify-content: center; font-size: .75rem;
            background: rgba(99,102,241,.15); color: #818cf8;
        }
        .row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 0; }
        .field { flex: 1; min-width: 200px; margin-bottom: 14px; }
        .field.full { min-width: 100%; }
        .field.half { flex: 0 0 calc(50% - 6px); min-width: 150px; }
        label {
            display: block; font-size: .78rem; font-weight: 600; color: #94a3b8;
            margin-bottom: 5px; letter-spacing: .02em;
        }
        label .req { color: #f87171; }
        input, select, textarea {
            width: 100%; padding: 10px 14px; border-radius: 10px;
            border: 1px solid rgba(148,163,184,.2); background: rgba(15,23,42,.6);
            color: #f1f5f9; font-family: inherit; font-size: .88rem;
            transition: border-color .2s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none; border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }
        select { cursor: pointer; }
        select option { background: #1e293b; color: #e2e8f0; }
        textarea { resize: vertical; min-height: 80px; }
        .honeypot { position: absolute; left: -9999px; }
        .error-box {
            background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.3);
            border-radius: 12px; padding: 14px; margin-bottom: 1.2rem;
            color: #fca5a5; font-size: .85rem;
        }
        .btn-submit {
            width: 100%; padding: 14px; border: none; border-radius: 12px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; font-family: inherit; font-size: 1rem; font-weight: 700;
            cursor: pointer; transition: transform .2s, box-shadow .2s;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(99,102,241,.3); }
        .btn-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; }
        .geo-status {
            font-size: .75rem; padding: 6px 10px; border-radius: 8px; margin-bottom: 14px;
        }
        .geo-ok { background: rgba(34,197,94,.1); color: #4ade80; border: 1px solid rgba(34,197,94,.2); }
        .geo-err { background: rgba(239,68,68,.1); color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }
        .geo-wait { background: rgba(251,191,36,.1); color: #fbbf24; border: 1px solid rgba(251,191,36,.2); }
        .footer-note { text-align: center; color: #64748b; font-size: .75rem; margin-top: 2rem; }
        @media (max-width: 540px) {
            .field.half { flex: 0 0 100%; }
            .container { padding: 0 12px; }
            .card { padding: 1.2rem; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="logo.png" alt="PEPP Learning" onerror="this.style.display='none'">
        <h1>Staff Registration</h1>
        <p>PEPP Learning — Employee / Faculty / Intern Application</p>
    </div>

    <?php if ($error_msg): ?>
        <div class="error-box"><i class="fas fa-exclamation-circle" style="margin-right:6px;"></i><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <form method="POST" id="staffRegForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="staff_csrf_token" value="<?php echo htmlspecialchars($_SESSION['staff_csrf_token']); ?>">
        <input type="hidden" name="_ts" value="<?php echo time(); ?>">
        <input type="hidden" name="latitude" id="reg_lat" value="">
        <input type="hidden" name="longitude" id="reg_lng" value="">
        <div class="honeypot"><label>Website<input type="text" name="website" autocomplete="off" tabindex="-1"></label></div>

        <!-- Application Type -->
        <div class="card">
            <div class="card-title"><i><i class="fas fa-briefcase"></i></i> Application Type</div>
            <div class="field full">
                <label>Applying For <span class="req">*</span></label>
                <select name="application_for" required>
                    <option value="">— Select —</option>
                    <option value="employee" <?php echo ($_POST['application_for'] ?? '') === 'employee' ? 'selected' : ''; ?>>PEPP Employee</option>
                    <option value="faculty" <?php echo ($_POST['application_for'] ?? '') === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                    <option value="intern" <?php echo ($_POST['application_for'] ?? '') === 'intern' ? 'selected' : ''; ?>>Intern</option>
                </select>
            </div>
        </div>

        <!-- Personal Details -->
        <div class="card">
            <div class="card-title"><i><i class="fas fa-user"></i></i> Personal Details</div>
            <div class="row">
                <div class="field full">
                    <label>Full Name (as per Aadhaar) <span class="req">*</span></label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field half">
                    <label>Gender <span class="req">*</span></label>
                    <select name="gender" required>
                        <option value="">— Select —</option>
                        <?php foreach (['Male','Female','Other','Prefer not to say'] as $g): ?>
                        <option value="<?php echo $g; ?>" <?php echo ($_POST['gender'] ?? '') === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field half">
                    <label>Date of Birth <span class="req">*</span></label>
                    <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field half">
                    <label>Blood Group <span class="req">*</span></label>
                    <select name="blood_group" required>
                        <option value="">— Select —</option>
                        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                        <option value="<?php echo $bg; ?>" <?php echo ($_POST['blood_group'] ?? '') === $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="field full">
                    <label>Upload Photo <span class="req">*</span> <span style="font-size:0.75rem; font-weight:normal; color:#94a3b8; display:block; margin-top:2px;">Please upload your best quality photo instead of a passport size photo. (Max 5MB, JPG/PNG/WEBP formats only)</span></label>
                    <input type="file" name="photo_file" accept="image/*" <?php echo empty($uploaded_photo) ? 'required' : ''; ?>>
                    <?php if ($uploaded_photo): ?>
                        <div style="margin-top:8px; display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-check-circle" style="color:#22c55e;"></i>
                            <span style="font-size:0.8rem; color:#4ade80;">Photo uploaded successfully.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Contact Details -->
        <div class="card">
            <div class="card-title"><i><i class="fas fa-phone"></i></i> Contact Details</div>
            <div class="row">
                <div class="field half">
                    <label>Mobile Number <span class="req">*</span></label>
                    <input type="tel" name="mobile_number" value="<?php echo htmlspecialchars($_POST['mobile_number'] ?? ''); ?>" placeholder="10-digit mobile" required>
                    <input type="hidden" name="mobile_country_code" value="+91">
                </div>
                <div class="field half">
                    <label>Email Address <span class="req">*</span></label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field half">
                    <label>Emergency Contact <span class="req">*</span></label>
                    <input type="tel" name="emergency_contact" value="<?php echo htmlspecialchars($_POST['emergency_contact'] ?? ''); ?>" placeholder="10-digit number" required>
                    <input type="hidden" name="emergency_country_code" value="+91">
                </div>
            </div>
        </div>

        <!-- Address -->
        <div class="card">
            <div class="card-title"><i><i class="fas fa-map-marker-alt"></i></i> Address</div>
            <div class="field full">
                <label>Full Address <span class="req">*</span></label>
                <textarea name="address" rows="3" required><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
            </div>
            <div class="row">
                <div class="field half">
                    <label>PIN Code <span class="req">*</span></label>
                    <input type="text" name="pincode" id="pinInput" value="<?php echo htmlspecialchars($_POST['pincode'] ?? ''); ?>" maxlength="6" pattern="[0-9]{6}" required>
                </div>
                <div class="field half">
                    <label>State <span class="req">*</span></label>
                    <input type="text" name="state" id="stateInput" value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>" required readonly style="opacity:.7">
                </div>
            </div>
            <div class="row">
                <div class="field half">
                    <label>Place / Post Office <span class="req">*</span></label>
                    <select name="place_post_office" id="placeSelect" required>
                        <option value="">— Enter PIN code —</option>
                        <?php if (!empty($_POST['place_post_office'])): ?>
                        <option value="<?php echo htmlspecialchars($_POST['place_post_office']); ?>" selected><?php echo htmlspecialchars($_POST['place_post_office']); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="field half">
                    <label>Country</label>
                    <input type="text" name="country" value="India" readonly style="opacity:.7">
                </div>
            </div>
            <div class="geo-status geo-wait" id="geoStatus">
                <i class="fas fa-spinner fa-spin"></i> Detecting location…
            </div>
        </div>

        <!-- Identity -->
        <div class="card">
            <div class="card-title"><i><i class="fas fa-fingerprint"></i></i> Identity</div>
            <div class="field full">
                <label>Aadhaar Number <span class="req">*</span></label>
                <input type="text" name="aadhaar_number" maxlength="14" placeholder="1234 5678 9012" required autocomplete="off">
                <div style="font-size:.7rem;color:#64748b;margin-top:4px;">
                    <i class="fas fa-lock"></i> Encrypted at rest with AES-256-GCM. Never stored in plaintext.
                </div>
            </div>
        </div>

        <!-- Banking -->
        <div class="card">
            <div class="card-title"><i><i class="fas fa-university"></i></i> Banking Details</div>
            <div class="row">
                <div class="field full">
                    <label>Bank Name <span class="req">*</span></label>
                    <input type="text" name="bank_name" id="bankNameInput" list="bankList" value="<?php echo htmlspecialchars($_POST['bank_name'] ?? ''); ?>" required>
                    <datalist id="bankList"></datalist>
                </div>
            </div>
            <div class="row">
                <div class="field half">
                    <label>Account Number <span class="req">*</span></label>
                    <input type="text" name="bank_account_number" maxlength="18" placeholder="Account number" required autocomplete="off">
                    <div style="font-size:.7rem;color:#64748b;margin-top:4px;">
                        <i class="fas fa-lock"></i> Encrypted at rest.
                    </div>
                </div>
                <div class="field half">
                    <label>IFSC Code <span class="req">*</span></label>
                    <input type="text" name="ifsc_code" value="<?php echo htmlspecialchars($_POST['ifsc_code'] ?? ''); ?>" maxlength="11" placeholder="e.g. SBIN0001234" required style="text-transform:uppercase;">
                </div>
            </div>
            <div class="row">
                <div class="field half">
                    <label>UPI ID (optional)</label>
                    <input type="text" name="upi_id" value="<?php echo htmlspecialchars($_POST['upi_id'] ?? ''); ?>" placeholder="yourname@bank">
                </div>
            </div>
        </div>

        <?php if (!empty($active_custom_fields)): ?>
        <!-- Dynamic Custom Fields -->
        <div class="card">
            <div class="card-title"><i><i class="fas fa-puzzle-piece"></i></i> Additional Information</div>
            <?php foreach ($active_custom_fields as $cf): ?>
            <div class="field full" style="margin-bottom:12px;">
                <label><?php echo htmlspecialchars($cf['field_label']); ?><?php if ($cf['is_required']): ?> <span class="req">*</span><?php endif; ?></label>
                <?php
                $cf_name = 'cf_' . $cf['id'];
                $cf_post = htmlspecialchars($_POST[$cf_name] ?? '');
                $cf_req = $cf['is_required'] ? 'required' : '';
                switch ($cf['field_type']):
                    case 'text': ?>
                        <input type="text" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                    <?php break; case 'number': ?>
                        <input type="number" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                    <?php break; case 'email': ?>
                        <input type="email" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                    <?php break; case 'date': ?>
                        <input type="date" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                    <?php break; case 'phone': ?>
                        <input type="tel" name="<?php echo $cf_name; ?>" value="<?php echo $cf_post; ?>" <?php echo $cf_req; ?>>
                    <?php break; case 'textarea': ?>
                        <textarea name="<?php echo $cf_name; ?>" rows="3" <?php echo $cf_req; ?>><?php echo $cf_post; ?></textarea>
                    <?php break; case 'dropdown':
                        $opts = array_map('trim', explode(',', $cf['field_options'] ?? ''));
                        ?>
                        <select name="<?php echo $cf_name; ?>" <?php echo $cf_req; ?>>
                            <option value="">— Select —</option>
                            <?php foreach ($opts as $opt): if ($opt === '') continue; ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $cf_post === htmlspecialchars($opt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php break; endswitch; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card" style="text-align:center;">
            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="fas fa-paper-plane" style="margin-right:6px;"></i> Submit Registration
            </button>
        </div>
    </form>

    <div class="footer-note">
        &copy; <?php echo date('Y'); ?> PEPP Learning (Labinc Education Pvt. Ltd.) · All Rights Reserved
    </div>
</div>

<script>
// ── Geolocation ──
(function() {
    const latEl = document.getElementById('reg_lat');
    const lngEl = document.getElementById('reg_lng');
    const geoEl = document.getElementById('geoStatus');

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => {
                latEl.value = pos.coords.latitude.toFixed(8);
                lngEl.value = pos.coords.longitude.toFixed(8);
                geoEl.className = 'geo-status geo-ok';
                geoEl.innerHTML = '<i class="fas fa-check-circle"></i> Location detected';
            },
            () => {
                geoEl.className = 'geo-status geo-err';
                geoEl.innerHTML = '<i class="fas fa-times-circle"></i> Location required. Please allow access and reload.';
            },
            { enableHighAccuracy: true, timeout: 15000 }
        );
    } else {
        geoEl.className = 'geo-status geo-err';
        geoEl.innerHTML = '<i class="fas fa-times-circle"></i> Geolocation not supported.';
    }
})();

// ── PIN code lookup ──
document.getElementById('pinInput').addEventListener('input', function() {
    const pin = this.value.replace(/\D/g, '');
    if (pin.length === 6) {
        fetch('staff-registration.php?lookup_pin=' + pin)
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    document.getElementById('stateInput').value = d.state;
                    const sel = document.getElementById('placeSelect');
                    sel.innerHTML = '';
                    d.places.forEach(p => {
                        const o = document.createElement('option');
                        o.value = p; o.textContent = p;
                        sel.appendChild(o);
                    });
                }
            }).catch(() => {});
    }
});

// ── Load banks ──
fetch('staff-registration.php?get_banks')
    .then(r => r.json())
    .then(banks => {
        const dl = document.getElementById('bankList');
        banks.forEach(b => {
            const o = document.createElement('option');
            o.value = b;
            dl.appendChild(o);
        });
    }).catch(() => {});

// ── Prevent double submit ──
document.getElementById('staffRegForm').addEventListener('submit', function() {
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
});
</script>
</body>
</html>
