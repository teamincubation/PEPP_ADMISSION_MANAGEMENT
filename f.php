<?php
session_start();
require_once 'config/database.php';
require_once 'includes/peppian_notify.php'; // For sending confirmation and notification emails

function render_public_notice($title, $message, $icon = 'fa-clock', $badge_text = 'Notice', $form = null) {
    $banner = $form['banner_image'] ?? '';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?> - PEPP Learning</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg: #0f172a;
                --card-bg: #1e293b;
                --accent: #e8980c;
                --text-main: #f8fafc;
                --text-muted: #94a3b8;
                --border: rgba(255, 255, 255, 0.1);
            }
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Outfit', sans-serif; }
            body {
                background: var(--bg);
                color: var(--text-main);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1.2rem;
            }
            .notice-card {
                background: var(--card-bg);
                border: 1px solid var(--border);
                border-radius: 24px;
                max-width: 500px;
                width: 100%;
                overflow: hidden;
                box-shadow: 0 20px 50px rgba(0,0,0,0.5);
                text-align: center;
                animation: fadeIn 0.4s ease-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(16px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .notice-banner {
                width: 100%;
                max-height: 160px;
                object-fit: cover;
                border-bottom: 1px solid var(--border);
            }
            .notice-body {
                padding: 2.2rem 1.6rem;
            }
            .notice-icon-wrapper {
                width: 60px;
                height: 60px;
                border-radius: 20px;
                background: rgba(232, 152, 12, 0.15);
                border: 1.5px solid var(--accent);
                color: var(--accent);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 1.6rem;
                margin-bottom: 1rem;
            }
            .notice-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                background: rgba(232, 152, 12, 0.15);
                color: var(--accent);
                font-size: 0.72rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                margin-bottom: 0.8rem;
            }
            .notice-title {
                font-size: 1.3rem;
                font-weight: 800;
                color: var(--text-main);
                margin-bottom: 0.6rem;
                line-height: 1.3;
            }
            .notice-message {
                font-size: 0.92rem;
                color: var(--text-muted);
                line-height: 1.6;
                margin-bottom: 1.6rem;
            }
            .btn-home {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                background: linear-gradient(135deg, #e8980c 0%, #d97706 100%);
                color: #fff;
                padding: 0.8rem 1.6rem;
                border-radius: 14px;
                text-decoration: none;
                font-weight: 700;
                font-size: 0.9rem;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .btn-home:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(232, 152, 12, 0.35);
            }
        </style>
    </head>
    <body>
        <div class="notice-card">
            <?php if (!empty($banner)): ?>
                <img src="<?php echo htmlspecialchars($banner); ?>" alt="Header Banner" class="notice-banner">
            <?php endif; ?>
            <div class="notice-body">
                <div class="notice-icon-wrapper">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <div>
                    <span class="notice-badge"><?php echo htmlspecialchars($badge_text); ?></span>
                </div>
                <h1 class="notice-title"><?php echo htmlspecialchars($title); ?></h1>
                <p class="notice-message"><?php echo htmlspecialchars($message); ?></p>
                <a href="https://pepplearning.com" class="btn-home"><i class="fas fa-globe"></i> Visit Official Website</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Retrieve slug
$slug = trim($_GET['s'] ?? $_GET['slug'] ?? '');
if (empty($slug)) {
    http_response_code(404);
    render_public_notice("Form Not Specified", "Please provide a valid campaign form link or custom slug URL.", "fa-circle-exclamation", "404 Error");
}

// Clean slug
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

try {
    // Fetch form details
    $stmt = $pdo->prepare("SELECT * FROM campaign_forms WHERE slug = ?");
    $stmt->execute([$slug]);
    $form = $stmt->fetch();

    if (!$form) {
        http_response_code(404);
        render_public_notice("Form Not Found", "The campaign form you are looking for does not exist or has been removed.", "fa-magnifying-glass-question", "404 Not Found");
    }

    $form_id = (int)$form['id'];
    $isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

    // Check status
    if ($form['status'] === 'archived') {
        die("<h3>This form has been archived and is no longer accepting submissions.</h3>");
    }
    if ($form['status'] === 'draft' && !$isAdmin) {
        render_public_notice("Form Under Maintenance", "This campaign form is currently in draft mode and can only be accessed by administrators.", "fa-lock", "Draft Mode", $form);
    }

    // Check publication schedule
    $now = date('Y-m-d H:i:s');
    if (!empty($form['publish_schedule_start']) && $now < $form['publish_schedule_start'] && !$isAdmin) {
        $open_date = date('d M Y, h:i A', strtotime($form['publish_schedule_start']));
        render_public_notice("Form Opening Soon", "This campaign form is scheduled to launch on {$open_date}. Please check back then!", "fa-clock", "Scheduled Launch", $form);
    }
    if (!empty($form['publish_schedule_end']) && $now > $form['publish_schedule_end'] && !$isAdmin) {
        $close_date = date('d M Y, h:i A', strtotime($form['publish_schedule_end']));
        render_public_notice("Form Submissions Closed", "This campaign form closed on {$close_date} and is no longer accepting new submissions. Thank you for your interest!", "fa-flag-checkered", "Closed Campaign", $form);
    }

    // Check total submission limit
    if ($form['submission_limit'] > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaign_form_submissions WHERE form_id = ?");
        $stmt->execute([$form_id]);
        $total_subs = (int)$stmt->fetchColumn();
        if ($total_subs >= $form['submission_limit'] && !$isAdmin) {
            render_public_notice("Registration Limit Reached", "This campaign form has reached its maximum capacity of allowed registrations.", "fa-users-slash", "Capacity Full", $form);
        }
    }

    // Check limit per user (via cookie/email)
    $user_cookie_name = 'form_submitted_' . $form_id;
    if ($form['limit_per_user'] > 0 && isset($_COOKIE[$user_cookie_name]) && !$isAdmin) {
        $times = (int)$_COOKIE[$user_cookie_name];
        if ($times >= $form['limit_per_user']) {
            render_public_notice("Submission Already Received", "You have already completed and submitted this campaign form the maximum number of allowed times.", "fa-circle-check", "Already Submitted", $form);
        }
    }

    // Log Form View for Analytics
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referrer = $_SERVER['HTTP_REFERER'] ?? null;
    
    // Parse device / browser simply
    $device = 'desktop';
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
        $device = 'tablet';
    } elseif (preg_match('/(mobi|ipod|phone|blackberry|opera mini|fennec)/i', $ua)) {
        $device = 'mobile';
    }
    
    $browser = 'Unknown';
    if (preg_match('/MSIE/i', $ua) && !preg_match('/Opera/i', $ua)) $browser = 'Internet Explorer';
    elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/Opera/i', $ua)) $browser = 'Opera';
    elseif (preg_match('/Netscape/i', $ua)) $browser = 'Netscape';

    // Insert view log (only once per session to avoid padding stats)
    if (!isset($_SESSION['viewed_forms']) || !in_array($form_id, $_SESSION['viewed_forms'])) {
        $_SESSION['viewed_forms'][] = $form_id;
        $stmt = $pdo->prepare("INSERT INTO campaign_form_analytics (form_id, ip_address, device, browser, referrer) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$form_id, $ip, $device, $browser, $referrer]);
    }

    // Fetch form fields
    $stmt = $pdo->prepare("SELECT * FROM campaign_form_fields WHERE form_id = ? AND is_deleted = 0 ORDER BY sort_order ASC");
    $stmt->execute([$form_id]);
    $fields = $stmt->fetchAll();

    // Access control checks
    $requires_password = !empty($form['password']);
    $unlocked = isset($_SESSION['form_unlocked_' . $form_id]) && $_SESSION['form_unlocked_' . $form_id] === true;

    $requires_email_verification = ($form['is_public'] == 0 && !empty($form['allowed_emails']));
    $email_verified = isset($_SESSION['form_email_verified_' . $form_id]) ? $_SESSION['form_email_verified_' . $form_id] : null;

    // Handle password submission
    if ($requires_password && !$unlocked && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_pass'])) {
        if (password_verify($_POST['form_pass'], $form['password'])) {
            $_SESSION['form_unlocked_' . $form_id] = true;
            $unlocked = true;
        } else {
            $pass_error = "Invalid password. Please try again.";
        }
    }

    // Handle restricted email OTP verification
    if ($requires_email_verification && !$email_verified && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email'])) {
        $email = trim($_POST['verify_email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Check if allowed
            $allowed = false;
            $domains_allowed = [];
            $individual_allowed = [];
            $list = array_map('trim', explode(',', $form['allowed_emails']));
            foreach ($list as $item) {
                if (strpos($item, '@') === 0 || strpos($item, '*') === 0) {
                    // Domain-based check
                    $domains_allowed[] = str_replace('*', '', strtolower($item));
                } else {
                    $individual_allowed[] = strtolower($item);
                }
            }

            if (in_array(strtolower($email), $individual_allowed)) {
                $allowed = true;
            } else {
                foreach ($domains_allowed as $dom) {
                    if (substr(strtolower($email), -strlen($dom)) === $dom) {
                        $allowed = true;
                        break;
                    }
                }
            }

            if ($allowed) {
                // Send simple numeric code
                $code = rand(100000, 999999);
                $_SESSION['email_verify_code_' . $form_id] = $code;
                $_SESSION['email_verify_target_' . $form_id] = $email;
                
                $subject = "Verification Code for " . $form['title'];
                $heading = "Your Access Verification Code";
                $body = "<p>Please use the following verification code to access the custom campaign form <strong>{$form['title']}</strong>:</p>
                         <h2 style='font-size:28px;letter-spacing:2px;color:#E8980C;text-align:center;'>{$code}</h2>
                         <p>If you did not request this, please ignore this email.</p>";
                peppian_send_email_general($email, $subject, $heading, $body);
                $verification_sent = true;
            } else {
                $email_error = "Access restricted. This email is not authorized to submit this form.";
            }
        } else {
            $email_error = "Please enter a valid email address.";
        }
    }

    // Verify OTP code
    if ($requires_email_verification && !$email_verified && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
        $code = trim($_POST['otp_code']);
        if ($code == $_SESSION['email_verify_code_' . $form_id]) {
            $_SESSION['form_email_verified_' . $form_id] = $_SESSION['email_verify_target_' . $form_id];
            $email_verified = $_SESSION['email_verify_target_' . $form_id];
        } else {
            $otp_error = "Incorrect verification code. Please try again.";
            $verification_sent = true; // keep showing OTP box
        }
    }

    // CAPTCHA generation
    if ($form['enable_captcha'] && (!isset($_SESSION['captcha_num1_' . $form_id]) || isset($_POST['form_submit_token']))) {
        $_SESSION['captcha_num1_' . $form_id] = rand(1, 9);
        $_SESSION['captcha_num2_' . $form_id] = rand(1, 9);
        $_SESSION['captcha_ans_' . $form_id] = $_SESSION['captcha_num1_' . $form_id] + $_SESSION['captcha_num2_' . $form_id];
    }

    // Handle main form submission
    $errors = [];
    $submitted = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_form'])) {
        // Validate password unlock and email restriction
        if ($requires_password && !$unlocked) {
            $errors[] = "Access password is required.";
        }
        if ($requires_email_verification && !$email_verified) {
            $errors[] = "Email verification is required.";
        }

        // Validate CSRF
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['form_csrf_' . $form_id] ?? '')) {
            $errors[] = "Invalid security token. Please reload and try again.";
        }

        // Rate limiting check per IP address (max 5 submissions per minute)
        try {
            $stmt_rate = $pdo->prepare("SELECT COUNT(*) FROM campaign_form_submissions WHERE ip_address = ? AND submitted_at >= (NOW() - INTERVAL 1 MINUTE)");
            $stmt_rate->execute([$ip]);
            if ($stmt_rate->fetchColumn() >= 5) {
                $errors[] = "Rate limit exceeded. Please wait 1 minute before submitting again.";
            }
        } catch (Exception $e) {}

        // Validate CAPTCHA
        if ($form['enable_captcha']) {
            $captcha_ans = isset($_POST['captcha_answer']) ? (int)$_POST['captcha_answer'] : 0;
            if ($captcha_ans !== ($_SESSION['captcha_ans_' . $form_id] ?? -1)) {
                $errors[] = "Incorrect CAPTCHA calculation. Please try again.";
            }
        }

        // Validate each field
        $answers = [];
        $files_to_upload = [];

        foreach ($fields as $field) {
            $fid = $field['id'];
            $name = $field['field_name'];
            $label = $field['label'];
            $type = $field['type'];
            
            // Skip section breaks
            if ($type === 'section') continue;

            $rules = !empty($field['validation_rules']) ? json_decode($field['validation_rules'], true) : [];
            $val = $_POST[$name] ?? '';

            // Combine country code with phone/whatsapp number
            if (($type === 'phone' || $type === 'whatsapp') && !empty($_POST[$name . '_code']) && !empty($val)) {
                $val = trim($_POST[$name . '_code']) . ' ' . trim($val);
            }

            // Handle arrays (checkboxes / multi-select)
            if (is_array($val)) {
                $val = implode(', ', $val);
            } else {
                $val = trim($val);
            }

            // Check if required
            if ($field['is_required'] && empty($val) && $type !== 'file' && $type !== 'toggle') {
                $errors[$name] = !empty($field['error_message']) ? $field['error_message'] : "{$label} is required.";
                continue;
            }

            // File upload validation & Security Sandbox
            if ($type === 'file') {
                if (isset($_FILES[$name]) && $_FILES[$name]['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file_err = $_FILES[$name]['error'];
                    if ($file_err !== UPLOAD_ERR_OK) {
                        $errors[$name] = "Error uploading file {$label}.";
                        continue;
                    }

                    $file_size = $_FILES[$name]['size'];
                    $file_name = $_FILES[$name]['name'];
                    $file_tmp = $_FILES[$name]['tmp_name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                    // Block dangerous executable scripts & web shells
                    $forbidden_exts = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'pl', 'py', 'sh', 'html', 'htm', 'js', 'svg', 'htaccess', 'cgi', 'bat', 'cmd'];
                    if (in_array($file_ext, $forbidden_exts, true)) {
                        $errors[$name] = "Security Risk: File type .{$file_ext} is strictly prohibited.";
                        continue;
                    }

                    // Default limits
                    $max_size = isset($rules['max_size']) ? (int)$rules['max_size'] * 1024 * 1024 : 5 * 1024 * 1024; // MB
                    $allowed_exts = !empty($rules['file_types']) ? array_map('trim', explode(',', strtolower($rules['file_types']))) : ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

                    if ($file_size > $max_size) {
                        $errors[$name] = "File size exceeds allowed limit (" . ($max_size / 1024 / 1024) . " MB).";
                        continue;
                    }

                    if (!in_array($file_ext, $allowed_exts)) {
                        $errors[$name] = "Format .{$file_ext} is not allowed. Allowed: " . implode(', ', $allowed_exts);
                        continue;
                    }

                    $files_to_upload[$fid] = [
                        'tmp' => $file_tmp,
                        'name' => uniqid('cf_', true) . '.' . $file_ext,
                        'original_name' => $file_name
                    ];
                } elseif ($field['is_required']) {
                    $errors[$name] = !empty($field['error_message']) ? $field['error_message'] : "File {$label} is required.";
                }
            }

            // Numeric field validation
            if ($type === 'number' && !empty($val)) {
                if (!is_numeric($val)) {
                    $errors[$name] = "Please enter a valid number.";
                    continue;
                }
                if (isset($rules['min']) && $val < $rules['min']) {
                    $errors[$name] = "Value must be at least {$rules['min']}.";
                    continue;
                }
                if (isset($rules['max']) && $val > $rules['max']) {
                    $errors[$name] = "Value must not exceed {$rules['max']}.";
                    continue;
                }
            }

            // Email format validation
            if ($type === 'email' && !empty($val) && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $errors[$name] = "Please enter a valid email address.";
                continue;
            }

            // URL format validation
            if ($type === 'url' && !empty($val) && !filter_var($val, FILTER_VALIDATE_URL)) {
                $errors[$name] = "Please enter a valid URL.";
                continue;
            }

            // Max character limit validation
            if (isset($rules['max_chars']) && strlen($val) > $rules['max_chars']) {
                $errors[$name] = "Must not exceed {$rules['max_chars']} characters.";
                continue;
            }

            $answers[$fid] = [
                'val' => $val,
                'file' => null
            ];
        }

        if (empty($errors)) {
            // Setup folder
            $upload_dir = 'uploads/campaign_files/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
                // Secure upload dir
                file_put_contents($upload_dir . '.htaccess', "Deny from all\n<Files ~ \"^\.(jpg|jpeg|png|webp|gif|pdf|docx|doc|xls|xlsx|zip)$\">\nOrder Allow,Deny\nAllow from all\n</Files>");
            }

            // Handle uploads
            foreach ($files_to_upload as $fid => $finfo) {
                $target_path = $upload_dir . $finfo['name'];
                if (move_uploaded_file($finfo['tmp'], $target_path)) {
                    $answers[$fid] = [
                        'val' => $finfo['original_name'],
                        'file' => $target_path
                    ];
                } else {
                    $errors[] = "Failed to upload file.";
                }
            }
        }

        if (empty($errors)) {
            $pdo->beginTransaction();

            $latitude = !empty($_POST['latitude']) ? trim($_POST['latitude']) : null;
            $longitude = !empty($_POST['longitude']) ? trim($_POST['longitude']) : null;

            // Insert submission with exact geolocation coordinates
            $ident = $email_verified ?: ($_POST['respondent_email'] ?? null);
            $stmt = $pdo->prepare("INSERT INTO campaign_form_submissions (form_id, respondent_identifier, ip_address, user_agent, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$form_id, $ident, $ip, $ua, $latitude, $longitude]);
            $submission_id = $pdo->lastInsertId();

            // Insert answers
            $stmt = $pdo->prepare("INSERT INTO campaign_form_answers (submission_id, field_id, answer_text, file_path) VALUES (?, ?, ?, ?)");
            foreach ($answers as $fid => $ans) {
                $stmt->execute([$submission_id, $fid, $ans['val'], $ans['file']]);
            }

            $pdo->commit();

            // Increment Limit Cookie
            $current_times = isset($_COOKIE[$user_cookie_name]) ? (int)$_COOKIE[$user_cookie_name] : 0;
            setcookie($user_cookie_name, $current_times + 1, time() + (365 * 24 * 60 * 60), "/");

            // Dispatch Notifications & Webhook
            dispatch_integrations($pdo, $form, $submission_id, $answers, $ident);

            $submitted = true;
            // Clear verification token if successful
            unset($_SESSION['captcha_ans_' . $form_id]);
        }
    }

    // CSRF token generation
    $_SESSION['form_csrf_' . $form_id] = bin2hex(random_bytes(32));

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    die("<h3>An error occurred: " . htmlspecialchars($e->getMessage()) . "</h3>");
}

// Submissions/Integrations Helper
function dispatch_integrations($pdo, $form, $submission_id, $answers, $respondent_email) {
    // Generate answers summary list
    $summary = "<table border='1' cellpadding='8' style='border-collapse:collapse;border-color:#e2e8f0;width:100%;font-size:14px;color:#334155;'>
                    <tr style='background:#f8fafc;'><th align='left'>Field</th><th align='left'>Answer</th></tr>";
    
    // Fetch labels
    $stmt = $pdo->prepare("SELECT id, label, type FROM campaign_form_fields WHERE form_id = ? AND is_deleted = 0 ORDER BY sort_order ASC");
    $stmt->execute([$form['id']]);
    $fields = $stmt->fetchAll();
    
    $payload_fields = [];
    foreach ($fields as $field) {
        if ($field['type'] === 'section') continue;
        $fid = $field['id'];
        $val = isset($answers[$fid]) ? $answers[$fid]['val'] : '';
        $file = isset($answers[$fid]) ? $answers[$fid]['file'] : null;

        $display_val = $val;
        if ($file) {
            $display_val = "<a href='https://{$_SERVER['HTTP_HOST']}/admissions/{$file}' target='_blank'>{$val}</a>";
        }
        $summary .= "<tr><td><strong>" . htmlspecialchars($field['label']) . "</strong></td><td>" . $display_val . "</td></tr>";
        $payload_fields[$field['label']] = $val;
    }
    $summary .= "</table>";

    // 1. Email admin notification
    if (!empty($form['notify_emails'])) {
        $to_list = array_map('trim', explode(',', $form['notify_emails']));
        $subject = "New Response: " . $form['title'];
        $heading = "New Form Submission Received";
        $body = "<p>A new response has been submitted to the campaign form <strong>{$form['title']}</strong>.</p>
                 <p><strong>Respondent:</strong> " . ($respondent_email ?: 'Anonymous') . "</p>
                 <p><strong>Submitted At:</strong> " . date('d M Y, h:i A') . "</p>
                 <p><strong>Details:</strong></p>" . $summary;

        foreach ($to_list as $to) {
            peppian_send_email_general($to, $subject, $heading, $body);
        }
    }

    // 2. Email respondent confirmation
    if ($respondent_email && !empty($form['confirmation_email_subject']) && !empty($form['confirmation_email_body'])) {
        $subject = $form['confirmation_email_subject'];
        $body = $form['confirmation_email_body'];
        
        // simple parsing
        $body = str_replace('{form_title}', $form['title'], $body);
        $body = str_replace('{answers}', $summary, $body);

        peppian_send_email_general($respondent_email, $subject, $form['title'], $body);
    }

    // 3. Trigger Webhook
    if (!empty($form['webhook_url'])) {
        $payload = [
            'event' => 'form_submission',
            'form_id' => $form['id'],
            'form_title' => $form['title'],
            'submission_id' => $submission_id,
            'respondent' => $respondent_email,
            'timestamp' => date('c'),
            'answers' => $payload_fields
        ];

        $ch = curl_init($form['webhook_url']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($form['title']); ?> — PEPP Learning</title>
    
    <?php
    $og_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $og_host = $_SERVER['HTTP_HOST'] ?? 'pepplearning.com';
    $og_current_url = $og_protocol . '://' . $og_host . $_SERVER['REQUEST_URI'];
    
    $og_banner = '';
    if (!empty($form['banner_image'])) {
        $og_banner = $form['banner_image'];
        if (strpos($og_banner, 'http://') !== 0 && strpos($og_banner, 'https://') !== 0) {
            $og_banner = $og_protocol . '://' . $og_host . '/admissions/' . ltrim($og_banner, '/');
        }
    } else {
        $og_banner = $og_protocol . '://' . $og_host . '/admissions/logo.png';
    }
    
    $og_desc = !empty($form['description']) ? strip_tags($form['description']) : 'Submit your response for ' . $form['title'];
    if (strlen($og_desc) > 160) {
        $og_desc = substr($og_desc, 0, 157) . '...';
    }
    ?>
    <!-- Open Graph / Facebook / WhatsApp Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($og_current_url); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($form['title']); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($og_desc); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($og_banner); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="PEPP Learning Campaign Forms">

    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?php echo htmlspecialchars($og_current_url); ?>">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($form['title']); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($og_desc); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($og_banner); ?>">

    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #E8980C;
            --primary-dark: #C27E0A;
            --bg-color: #0b0702;
            --card-bg: rgba(26, 17, 5, 0.7);
            --card-border: rgba(232, 152, 12, 0.15);
            --text-color: #e2e8f0;
            --text-muted: #94a3b8;
            --input-bg: rgba(15, 10, 3, 0.6);
            --input-border: rgba(232, 152, 12, 0.2);
        }

        /* Sunset theme */
        .theme-sunset {
            --primary: #f97316;
            --primary-dark: #ea580c;
            --bg-color: #0c0401;
            --card-bg: rgba(28, 11, 3, 0.7);
            --card-border: rgba(249, 115, 22, 0.15);
            --input-bg: rgba(17, 6, 2, 0.6);
            --input-border: rgba(249, 115, 22, 0.2);
        }

        /* Minimal clean theme (light) */
        .theme-minimal {
            --primary: #1e293b;
            --primary-dark: #0f172a;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-color: #1e293b;
            --text-muted: #64748b;
            --input-bg: #f1f5f9;
            --input-border: #cbd5e1;
        }

        /* Glassmorphism theme */
        .theme-glass {
            --primary: #facc15;
            --primary-dark: #eab308;
            --bg-color: #1e1b4b;
            --card-bg: rgba(255, 255, 255, 0.08);
            --card-border: rgba(255, 255, 255, 0.15);
            --text-color: #ffffff;
            --text-muted: #cbd5e1;
            --input-bg: rgba(255, 255, 255, 0.05);
            --input-border: rgba(255, 255, 255, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(232, 152, 12, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(232, 152, 12, 0.06) 0%, transparent 40%);
            background-size: cover;
        }

        .container {
            width: 100%;
            max-width: 680px;
            perspective: 1000px;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 2.5rem 2.2rem;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            animation: cardEntrance 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
            overflow: hidden;
            position: relative;
        }

        .form-banner-img {
            width: calc(100% + 4.4rem);
            margin: -2.5rem -2.2rem 1.8rem -2.2rem;
            max-height: 240px;
            object-fit: cover;
            display: block;
        }

        /* Mobile First Responsive View */
        @media (max-width: 640px) {
            body {
                padding: 0.8rem 0.5rem;
            }
            .card {
                padding: 1.4rem 1.1rem;
                border-radius: 18px;
            }
            .form-banner-img {
                width: calc(100% + 2.2rem);
                margin: -1.4rem -1.1rem 1.2rem -1.1rem;
                max-height: 180px;
            }
            .form-header h1 {
                font-size: 1.4rem;
            }
            .input-control {
                padding: 0.75rem 0.85rem;
                font-size: 16px; /* Prevents auto-zoom on mobile safari/chrome */
            }
            .step-footer {
                flex-direction: column-reverse;
                gap: 0.6rem;
            }
            .captcha-flex {
                flex-direction: column;
                align-items: stretch;
            }
        }

        @keyframes cardEntrance {
            from { opacity: 0; transform: translateY(40px) rotateX(-5deg); }
            to { opacity: 1; transform: translateY(0) rotateX(0); }
        }

        .form-header {
            margin-bottom: 2.2rem;
            text-align: center;
        }

        .logo-wrap {
            width: 72px;
            height: 72px;
            background: rgba(232, 152, 12, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.2rem;
            border: 1.5px solid var(--primary);
        }

        .logo-wrap i {
            font-size: 1.8rem;
            color: var(--primary);
        }

        .form-header h1 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 0.6rem;
            letter-spacing: -0.5px;
            color: var(--text-color);
        }

        .form-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        /* Verification Screen Inputs */
        .verification-box {
            text-align: center;
            padding: 1rem 0;
        }

        .form-group {
            margin-bottom: 1.6rem;
            position: relative;
        }

        label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .input-control {
            width: 100%;
            background: var(--input-bg);
            border: 1.5px solid var(--input-border);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            font-family: inherit;
            font-size: 0.95rem;
            color: var(--text-color);
            transition: all 0.25s ease;
        }

        .input-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(232, 152, 12, 0.15);
            background: rgba(26, 17, 5, 0.8);
        }

        /* Buttons */
        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.9rem 1.8rem;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            width: 100%;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(232, 152, 12, 0.25);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-detect-loc {
            background: transparent;
            border: 1.5px solid var(--primary);
            color: var(--primary);
            border-radius: 12px;
            padding: 0.8rem 1.1rem;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            flex-shrink: 0;
            width: auto !important;
        }

        .btn-detect-loc:hover {
            background: rgba(232, 152, 12, 0.15);
            color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(232, 152, 12, 0.2);
        }

        /* Alert and Errors */
        .alert {
            padding: 1rem 1.2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.88rem;
            line-height: 1.4;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-weight: 500;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #f87171;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            color: #4ade80;
        }

        .field-error-text {
            color: #f87171;
            font-size: 0.78rem;
            margin-top: 0.35rem;
            font-weight: 600;
        }

        /* Progress Bar / Section Breaks */
        .progress-container {
            margin-bottom: 2rem;
            background: var(--input-border);
            height: 6px;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar {
            background: var(--primary);
            height: 100%;
            width: 0%;
            transition: width 0.4s ease;
        }

        .step-indicators {
            display: flex;
            justify-content: space-between;
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 0.4rem;
            font-weight: 600;
        }

        .form-step {
            display: none;
            animation: stepTransition 0.5s ease both;
        }

        .form-step.active {
            display: block;
        }

        @keyframes stepTransition {
            from { opacity: 0; transform: translateX(15px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .step-footer {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn-secondary {
            background: transparent;
            border: 1.5px solid var(--input-border);
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background: var(--input-bg);
            box-shadow: none;
        }

        /* Choices Style */
        .choices-stack {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            margin-top: 0.4rem;
        }

        .choice-option {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            background: var(--input-bg);
            border: 1.5px solid var(--input-border);
            border-radius: 10px;
            padding: 0.8rem 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .choice-option:hover {
            border-color: var(--primary);
            background: rgba(232, 152, 12, 0.05);
        }

        .choice-option input[type="radio"],
        .choice-option input[type="checkbox"] {
            accent-color: var(--primary);
            width: 17px;
            height: 17px;
            cursor: pointer;
        }

        .rating-wrap {
            display: flex;
            gap: 0.8rem;
            font-size: 1.8rem;
            margin-top: 0.5rem;
        }

        .rating-star {
            cursor: pointer;
            color: var(--text-muted);
            transition: color 0.2s ease;
        }

        .rating-star.selected,
        .rating-star:hover,
        .rating-star:hover ~ .rating-star {
            color: var(--primary);
        }

        /* Toggle Switch */
        .toggle-wrap {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            cursor: pointer;
        }

        .toggle-switch {
            position: relative;
            width: 50px;
            height: 26px;
            background: var(--input-border);
            border-radius: 50px;
            transition: background 0.3s;
        }

        .toggle-switch::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            transition: left 0.3s;
        }

        input[type="checkbox"].toggle-input {
            display: none;
        }

        input[type="checkbox"].toggle-input:checked + .toggle-switch {
            background: var(--primary);
        }

        input[type="checkbox"].toggle-input:checked + .toggle-switch::after {
            left: 27px;
        }

        /* Section break custom layout */
        .section-header {
            border-bottom: 2px solid var(--input-border);
            padding-bottom: 0.5rem;
            margin: 2rem 0 1.2rem 0;
            color: var(--primary);
            font-size: 1.2rem;
            font-weight: 700;
        }

        /* CAPTCHA calculation align */
        .captcha-flex {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .captcha-eq {
            background: var(--input-bg);
            border: 1.5px solid var(--input-border);
            padding: 0.8rem 1.2rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary);
        }
    </style>
</head>
<body class="theme-<?php echo htmlspecialchars($form['theme']); ?>">

<div class="container">
    <div class="card">
        <?php if (!empty($form['banner_image'])): ?>
            <img src="<?php echo htmlspecialchars($form['banner_image']); ?>" class="form-banner-img" alt="<?php echo htmlspecialchars($form['title']); ?> Banner">
        <?php endif; ?>
        
        <?php if ($submitted): ?>
            <!-- Thank You Screen -->
            <div class="form-header" style="animation: stepTransition 0.5s ease both;">
                <div class="logo-wrap" style="border-color: #22c55e; background: rgba(34, 197, 94, 0.1);">
                    <i class="fas fa-check-circle" style="color: #22c55e; font-size: 2.2rem;"></i>
                </div>
                <h1><?php echo htmlspecialchars($form['thank_you_title'] ?: 'Thank You!'); ?></h1>
                <p><?php echo nl2br(htmlspecialchars($form['thank_you_text'] ?: 'Your response has been recorded.')); ?></p>
                
                <?php if (($form['auto_redirect_whatsapp'] ?? 0) == 1 && !empty($form['whatsapp_group_link'])): ?>
                    <div style="background:rgba(37, 211, 102, 0.12); border:1.5px solid rgba(37, 211, 102, 0.35); border-radius:16px; padding:1.5rem; margin-top:1.5rem; text-align:center;">
                        <i class="fab fa-whatsapp" style="font-size:3rem; color:#25D366; margin-bottom:0.5rem; display:block;"></i>
                        <h3 style="font-weight:800; font-size:1.15rem; margin-bottom:0.4rem; color:var(--text-color);">Joining Official WhatsApp Group</h3>
                        <p style="font-size:0.9rem; color:var(--text-muted); margin-bottom:1.2rem;">You are being automatically redirected to join our WhatsApp group in <strong id="wa-countdown" style="color:#25D366; font-size:1.3rem;">3</strong> seconds.</p>
                        <a href="<?php echo htmlspecialchars($form['whatsapp_group_link']); ?>" class="btn" style="background:#25D366; color:#ffffff; font-weight:800; border:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:0.8rem 1.8rem; text-decoration:none;"><i class="fab fa-whatsapp"></i> Join WhatsApp Group Now</a>
                    </div>
                    <script>
                        var secondsLeft = 3;
                        var waInterval = setInterval(function() {
                            secondsLeft--;
                            var countEl = document.getElementById('wa-countdown');
                            if (countEl) countEl.textContent = secondsLeft;
                            if (secondsLeft <= 0) {
                                clearInterval(waInterval);
                                window.location.href = "<?php echo htmlspecialchars($form['whatsapp_group_link']); ?>";
                            }
                        }, 1000);
                    </script>
                <?php endif; ?>
                
                <?php if ($isAdmin): ?>
                    <div style="margin-top:2.5rem; display:flex; gap:10px;">
                        <a href="campaign-form-responses.php?id=<?php echo $form_id; ?>" class="btn btn-secondary"><i class="fas fa-list"></i> View Responses</a>
                        <a href="f.php?s=<?php echo $slug; ?>" class="btn"><i class="fas fa-redo"></i> Submit Another</a>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 2.5rem;">
                        <a href="f.php?s=<?php echo $slug; ?>" class="btn btn-secondary"><i class="fas fa-redo"></i> Submit Another Response</a>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($requires_password && !$unlocked): ?>
            <!-- Password Gate -->
            <div class="form-header">
                <div class="logo-wrap"><i class="fas fa-lock"></i></div>
                <h1>Password Protected</h1>
                <p>This form requires a password to access.</p>
            </div>
            
            <?php if (isset($pass_error)): ?>
                <div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo $pass_error; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Enter Password</label>
                    <input type="password" name="form_pass" class="input-control" required placeholder="Type the access password">
                </div>
                <button type="submit" class="btn"><i class="fas fa-unlock"></i> Access Form</button>
            </form>
            
        <?php elseif ($requires_email_verification && !$email_verified): ?>
            <!-- Restricted Email Gate -->
            <div class="form-header">
                <div class="logo-wrap"><i class="fas fa-shield-halved"></i></div>
                <h1>Restricted Access</h1>
                <p>This form is restricted to verified email addresses or domains.</p>
            </div>

            <?php if (isset($email_error)): ?>
                <div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo $email_error; ?></div>
            <?php endif; ?>
            <?php if (isset($otp_error)): ?>
                <div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?php echo $otp_error; ?></div>
            <?php endif; ?>
            <?php if (isset($verification_sent) && $verification_sent): ?>
                <div class="alert alert-success"><i class="fas fa-paper-plane"></i> Verification code sent to email.</div>
            <?php endif; ?>

            <?php if (isset($verification_sent) && $verification_sent): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Enter 6-digit Verification Code</label>
                        <input type="text" name="otp_code" class="input-control" required placeholder="123456" style="text-align:center; font-size:1.4rem; letter-spacing:4px;">
                    </div>
                    <button type="submit" class="btn"><i class="fas fa-key"></i> Confirm Code</button>
                </form>
            <?php else: ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Enter Registered Email Address</label>
                        <input type="email" name="verify_email" class="input-control" required placeholder="you@domain.com">
                    </div>
                    <button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Send Verification Code</button>
                </form>
            <?php endif; ?>
            
        <?php else: ?>
            <!-- Standard Form Render -->
            <div class="form-header">
                <h1><?php echo htmlspecialchars($form['title']); ?></h1>
                <?php if (!empty($form['description'])): ?>
                    <p><?php echo nl2br(htmlspecialchars($form['description'])); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($errors) && isset($errors[0])): ?>
                <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> <?php echo $errors[0]; ?></div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" id="campaign-submit-form" onsubmit="return validateCurrentStep(true);">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['form_csrf_' . $form_id]; ?>">
                <input type="hidden" name="submit_form" value="1">
                <input type="hidden" name="latitude" id="submission-latitude" value="">
                <input type="hidden" name="longitude" id="submission-longitude" value="">
                
                <!-- Multi-page container -->
                <div class="progress-container" id="prog-wrap" style="display:none;">
                    <div class="progress-bar" id="prog-bar"></div>
                </div>
                
                <?php
                $step = 0;
                $open_step = false;
                
                foreach ($fields as $field):
                    $name = $field['field_name'];
                    $label = $field['label'];
                    $type = $field['type'];
                    $placeholder = $field['placeholder'];
                    $default = $field['default_value'];
                    $choices = !empty($field['choices']) ? json_decode($field['choices'], true) : [];
                    $rules = !empty($field['validation_rules']) ? json_decode($field['validation_rules'], true) : [];
                    
                    // Render Section Break (Splits into Steps)
                    if ($type === 'section') {
                        if ($open_step) {
                            echo '</div><!-- /form-step -->';
                        }
                        $open_step = true;
                        $step++;
                        $active_class = ($step === 1) ? 'active' : '';
                        echo "<div class='form-step {$active_class}' data-step='{$step}' data-title='" . htmlspecialchars($label) . "'>";
                        echo "<div class='section-header'>" . htmlspecialchars($label) . "</div>";
                        if (!empty($field['placeholder'])) {
                            echo "<p style='font-size:0.875rem; color:var(--text-muted); margin-bottom:1.5rem;'>" . nl2br(htmlspecialchars($field['placeholder'])) . "</p>";
                        }
                        continue;
                    }

                    // Auto-open first step if not explicitly defined by section
                    if ($step === 0) {
                        $step = 1;
                        $open_step = true;
                        echo "<div class='form-step active' data-step='1' data-title='General Details'>";
                    }

                    $req_star = $field['is_required'] ? ' <span style="color:#f87171;">*</span>' : '';
                    $req_attr = $field['is_required'] ? 'required' : '';
                    $err_text = $errors[$name] ?? '';
                    
                    // Logic rules attributes for JavaScript handling
                    $logic_attr = '';
                    if (!empty($field['conditional_logic'])) {
                        $logic_attr = "data-logic='" . htmlspecialchars($field['conditional_logic'], ENT_QUOTES, 'UTF-8') . "'";
                    }
                ?>
                    <div class="form-group field-wrap" data-field-name="<?php echo $name; ?>" <?php echo $logic_attr; ?>>
                        <label for="<?php echo $name; ?>"><?php echo htmlspecialchars($label) . $req_star; ?></label>
                        
                        <?php if ($type === 'short_text'): ?>
                            <input type="text" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?> placeholder="<?php echo htmlspecialchars($placeholder); ?>" <?php echo isset($rules['max_chars']) ? 'maxlength="'.$rules['max_chars'].'"' : ''; ?>>
                        
                        <?php elseif ($type === 'long_text'): ?>
                            <textarea name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" rows="4" <?php echo $req_attr; ?> placeholder="<?php echo htmlspecialchars($placeholder); ?>" <?php echo isset($rules['max_chars']) ? 'maxlength="'.$rules['max_chars'].'"' : ''; ?>><?php echo htmlspecialchars($_POST[$name] ?? $default); ?></textarea>
                        
                        <?php elseif ($type === 'number'): ?>
                            <input type="number" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?> placeholder="<?php echo htmlspecialchars($placeholder); ?>" <?php echo isset($rules['min']) ? 'min="'.$rules['min'].'"' : ''; ?> <?php echo isset($rules['max']) ? 'max="'.$rules['max'].'"' : ''; ?>>
                        
                        <?php elseif ($type === 'email'): ?>
                            <input type="email" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" value="<?php echo htmlspecialchars($_POST[$name] ?? $default ?: ($email_verified ?? '')); ?>" <?php echo $req_attr; ?> placeholder="<?php echo htmlspecialchars($placeholder); ?>">
                        
                        <?php elseif ($type === 'phone' || $type === 'whatsapp'): ?>
                            <div style="display:flex; gap:8px;">
                                <select name="<?php echo $name; ?>_code" id="<?php echo $name; ?>_code" class="input-control" style="width:115px; flex-shrink:0;">
                                    <option value="+91" selected>🇮🇳 +91</option>
                                    <option value="+1">🇺🇸 +1</option>
                                    <option value="+44">🇬🇧 +44</option>
                                    <option value="+971">🇦🇪 +971</option>
                                    <option value="+966">🇸🇦 +966</option>
                                    <option value="+968">🇴🇲 +968</option>
                                    <option value="+974">🇶🇦 +974</option>
                                    <option value="+965">🇰🇼 +965</option>
                                    <option value="+973">🇧🇭 +973</option>
                                    <option value="+60">🇲🇾 +60</option>
                                    <option value="+65">🇸🇬 +65</option>
                                    <option value="+61">🇦🇺 +61</option>
                                    <option value="+49">🇩🇪 +49</option>
                                    <option value="+33">🇫🇷 +33</option>
                                </select>
                                <input type="tel" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?> placeholder="<?php echo htmlspecialchars($placeholder ?: 'Enter number'); ?>">
                            </div>

                        <?php elseif ($type === 'location'): ?>
                            <div style="display:flex; flex-direction:column; gap:10px;">
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <input type="text" id="pincode_<?php echo $name; ?>" class="input-control" placeholder="Enter 6-digit PIN Code" maxlength="6" style="flex:1; min-width:140px; font-weight:600; letter-spacing:0.5px;" oninput="lookupPincode(this, '<?php echo $name; ?>')">
                                    <button type="button" class="btn-detect-loc" onclick="autoDetectLocation('<?php echo $name; ?>')">
                                        <i class="fas fa-location-crosshairs"></i> Detect Location
                                    </button>
                                </div>
                                
                                <div id="pincode_status_<?php echo $name; ?>" style="font-size:0.78rem; color:var(--text-muted); display:none;"></div>
                                
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                    <select id="place_<?php echo $name; ?>" class="input-control" onchange="syncLocationValue('<?php echo $name; ?>')">
                                        <option value="">Select Place / Post Office</option>
                                    </select>
                                    <input type="text" id="district_<?php echo $name; ?>" class="input-control" placeholder="District" readonly>
                                </div>
                                <input type="text" id="state_<?php echo $name; ?>" class="input-control" placeholder="State" readonly>

                                <input type="hidden" name="<?php echo $name; ?>" id="<?php echo $name; ?>" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?>>
                            </div>
                        
                        <?php elseif ($type === 'url'): ?>
                            <input type="url" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?> placeholder="<?php echo htmlspecialchars($placeholder); ?>">
                        
                        <?php elseif ($type === 'dropdown'): ?>
                            <select name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" <?php echo $req_attr; ?>>
                                <option value=""><?php echo htmlspecialchars($placeholder ?: 'Select Option'); ?></option>
                                <?php foreach ($choices as $choice): 
                                    $sel = (($_POST[$name] ?? $default) === $choice) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($choice); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($choice); ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($type === 'multiselect'): ?>
                            <!-- Multi-select via standard select with multiple -->
                            <select name="<?php echo $name; ?>[]" id="<?php echo $name; ?>" class="input-control" multiple style="height:auto;" <?php echo $req_attr; ?>>
                                <?php foreach ($choices as $choice): 
                                    $arr = $_POST[$name] ?? explode(', ', $default);
                                    $sel = in_array($choice, $arr) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($choice); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($choice); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:var(--text-muted); font-size:0.75rem; margin-top:0.25rem; display:block;">Hold Ctrl (Windows) / Cmd (Mac) to select multiple</small>

                        <?php elseif ($type === 'checkboxes'): ?>
                            <div class="choices-stack">
                                <?php foreach ($choices as $k => $choice): 
                                    $arr = $_POST[$name] ?? explode(', ', $default);
                                    $chk = in_array($choice, $arr) ? 'checked' : '';
                                ?>
                                    <label class="choice-option">
                                        <input type="checkbox" name="<?php echo $name; ?>[]" value="<?php echo htmlspecialchars($choice); ?>" <?php echo $chk; ?>>
                                        <span><?php echo htmlspecialchars($choice); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif ($type === 'radio'): ?>
                            <div class="choices-stack">
                                <?php foreach ($choices as $choice): 
                                    $chk = (($_POST[$name] ?? $default) === $choice) ? 'checked' : '';
                                ?>
                                    <label class="choice-option">
                                        <input type="radio" name="<?php echo $name; ?>" value="<?php echo htmlspecialchars($choice); ?>" <?php echo $chk; ?> <?php echo $req_attr; ?>>
                                        <span><?php echo htmlspecialchars($choice); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif ($type === 'date'): ?>
                            <input type="date" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?>>

                        <?php elseif ($type === 'time'): ?>
                            <input type="time" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?>>

                        <?php elseif ($type === 'datetime'): ?>
                            <input type="datetime-local" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?>>

                        <?php elseif ($type === 'file'): ?>
                            <input type="file" name="<?php echo $name; ?>" id="<?php echo $name; ?>" class="input-control" <?php echo $req_attr; ?> accept="<?php echo !empty($rules['file_types']) ? '.' . str_replace(',', ',.', $rules['file_types']) : ''; ?>">
                            <small style="color:var(--text-muted); font-size:0.75rem; margin-top:0.35rem; display:block;">
                                Allowed: <?php echo htmlspecialchars($rules['file_types'] ?? 'JPG, PNG, PDF, DOCX, ZIP'); ?> (Max: <?php echo htmlspecialchars($rules['max_size'] ?? '5'); ?> MB)
                            </small>

                        <?php elseif ($type === 'rating'): ?>
                            <div class="rating-wrap" data-name="<?php echo $name; ?>">
                                <input type="hidden" name="<?php echo $name; ?>" id="<?php echo $name; ?>" value="<?php echo htmlspecialchars($_POST[$name] ?? $default); ?>" <?php echo $req_attr; ?>>
                                <?php for ($i=1; $i<=5; $i++): 
                                    $cls = ((int)($_POST[$name] ?? $default) >= $i) ? 'selected' : '';
                                ?>
                                    <i class="rating-star fas fa-star <?php echo $cls; ?>" data-value="<?php echo $i; ?>"></i>
                                <?php endfor; ?>
                            </div>

                        <?php elseif ($type === 'toggle'): ?>
                            <label class="toggle-wrap">
                                <input type="checkbox" name="<?php echo $name; ?>" value="1" class="toggle-input" id="<?php echo $name; ?>" <?php echo ($_POST[$name] ?? $default) ? 'checked' : ''; ?>>
                                <div class="toggle-switch"></div>
                                <span style="font-size:0.9rem; font-weight:600;"><?php echo htmlspecialchars($placeholder ?: 'Enable'); ?></span>
                            </label>

                        <?php elseif ($type === 'hidden'): ?>
                            <input type="hidden" name="<?php echo $name; ?>" id="<?php echo $name; ?>" value="<?php echo htmlspecialchars($default); ?>">
                        <?php endif; ?>
                        
                        <?php if ($err_text): ?>
                            <div class="field-error-text"><i class="fas fa-circle-exclamation"></i> <?php echo $err_text; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php if ($open_step) echo '</div><!-- /form-step -->'; ?>

                <!-- Optional CAPTCHA protection -->
                <?php if ($form['enable_captcha']): ?>
                    <div class="form-group" style="margin-top: 2rem;">
                        <label>Security Verification <span style="color:#f87171;">*</span></label>
                        <div class="captcha-flex">
                            <div class="captcha-eq"><?php echo $_SESSION['captcha_num1_' . $form_id]; ?> + <?php echo $_SESSION['captcha_num2_' . $form_id]; ?> = </div>
                            <input type="number" name="captcha_answer" class="input-control" required placeholder="?" style="max-width:100px; text-align:center;">
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Step Footer Navigation -->
                <div class="step-footer">
                    <button type="button" class="btn btn-secondary" id="btn-prev" onclick="changeStep(-1)" style="display:none;"><i class="fas fa-chevron-left"></i> Back</button>
                    <button type="button" class="btn" id="btn-next" onclick="changeStep(1)">Next <i class="fas fa-chevron-right"></i></button>
                    <button type="submit" class="btn" id="btn-submit" style="display:none;"><i class="fas fa-paper-plane"></i> Submit Response</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    var currentStep = 1;
    var totalSteps = <?php echo $step; ?>;
    
    document.addEventListener('DOMContentLoaded', function() {
        // Auto fetch exact Google Maps coordinates
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                var lat = position.coords.latitude;
                var lng = position.coords.longitude;
                document.getElementById('submission-latitude').value = lat;
                document.getElementById('submission-longitude').value = lng;
            }, function(err) {
                console.log("Location access skipped: " + err.message);
            }, { enableHighAccuracy: true, timeout: 6000 });
        }

        if (totalSteps > 1) {
            document.getElementById('prog-wrap').style.display = 'block';
            updateStepView();
        } else {
            // Only 1 page, hide next/back buttons and show submit
            var btnNext = document.getElementById('btn-next');
            var btnSubmit = document.getElementById('btn-submit');
            if (btnNext && btnSubmit) {
                btnNext.style.display = 'none';
                btnSubmit.style.display = 'block';
            }
        }

        // Star Rating events
        document.querySelectorAll('.rating-star').forEach(function(star) {
            star.addEventListener('click', function() {
                var value = this.getAttribute('data-value');
                var wrap = this.parentElement;
                var inputId = wrap.getAttribute('data-name');
                document.getElementById(inputId).value = value;
                
                // Toggle active classes
                var stars = wrap.querySelectorAll('.rating-star');
                stars.forEach(function(s) {
                    if (parseInt(s.getAttribute('data-value')) <= parseInt(value)) {
                        s.classList.add('selected');
                    } else {
                        s.classList.remove('selected');
                    }
                });
            });
        });

        // Initialize conditional logic evaluation
        evaluateConditionalLogic();
        document.querySelectorAll('.input-control, input[type="radio"], input[type="checkbox"]').forEach(function(input) {
            input.addEventListener('change', evaluateConditionalLogic);
            input.addEventListener('input', evaluateConditionalLogic);
        });
    });

    function changeStep(direction) {
        if (direction === 1 && !validateCurrentStep(false)) {
            return; // fail step validation
        }

        currentStep += direction;
        updateStepView();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function updateStepView() {
        // Show/hide steps
        document.querySelectorAll('.form-step').forEach(function(stepEl) {
            var stepNum = parseInt(stepEl.getAttribute('data-step'));
            if (stepNum === currentStep) {
                stepEl.classList.add('active');
            } else {
                stepEl.classList.remove('active');
            }
        });

        // Toggle buttons
        var btnPrev = document.getElementById('btn-prev');
        var btnNext = document.getElementById('btn-next');
        var btnSubmit = document.getElementById('btn-submit');

        if (currentStep === 1) {
            btnPrev.style.display = 'none';
        } else {
            btnPrev.style.display = 'inline-flex';
        }

        if (currentStep === totalSteps) {
            btnNext.style.display = 'none';
            btnSubmit.style.display = 'inline-flex';
        } else {
            btnNext.style.display = 'inline-flex';
            btnSubmit.style.display = 'none';
        }

        // Progress bar
        var percent = ((currentStep - 1) / (totalSteps - 1)) * 100;
        document.getElementById('prog-bar').style.width = percent + '%';
    }

    function validateCurrentStep(isFinalSubmit) {
        var activeStep = document.querySelector('.form-step.active');
        if (!activeStep) return true;

        var isValid = true;
        
        // Find visible inputs in this step
        var inputs = activeStep.querySelectorAll('.input-control[required], input[type="radio"][required]');
        
        // Remove old client validations
        activeStep.querySelectorAll('.field-error-text').forEach(e => e.remove());

        inputs.forEach(function(input) {
            // Check if wrapper is visible (if conditional logic hides it, skip validation)
            var wrapper = input.closest('.field-wrap');
            if (wrapper && wrapper.style.display === 'none') {
                return;
            }

            var labelText = wrapper ? wrapper.querySelector('label').textContent.replace('*', '').trim() : 'Field';
            var errorMsg = '';

            if (input.type === 'radio') {
                var groupName = input.name;
                var checked = activeStep.querySelector('input[name="'+groupName+'"]:checked');
                if (!checked) {
                    isValid = false;
                    errorMsg = labelText + ' is required.';
                }
            } else if (!input.value.trim()) {
                isValid = false;
                errorMsg = labelText + ' is required.';
            }

            if (errorMsg) {
                // Insert error visual
                var errDiv = document.createElement('div');
                errDiv.className = 'field-error-text';
                errDiv.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + errorMsg;
                input.after(errDiv);
            }
        });

        return isValid;
    }

    // Dynamic Conditional Logic Engine
    function evaluateConditionalLogic() {
        document.querySelectorAll('.field-wrap[data-logic]').forEach(function(wrapper) {
            var logicData = JSON.parse(wrapper.getAttribute('data-logic'));
            if (!logicData || !logicData.field) return;

            var targetFieldName = logicData.field;
            var operator = logicData.operator; // '=', '!=', 'empty', 'not_empty'
            var valToCompare = logicData.value;
            
            // Find target input
            var targetInput = document.querySelector('[name="' + targetFieldName + '"], [name="' + targetFieldName + '[]"]');
            if (!targetInput) {
                // try radios
                var radioChecked = document.querySelector('input[name="' + targetFieldName + '"]:checked');
                var targetValue = radioChecked ? radioChecked.value : '';
            } else {
                var targetValue = targetInput.value;
            }

            var conditionMet = false;
            if (operator === '=') {
                conditionMet = (targetValue == valToCompare);
            } else if (operator === '!=') {
                conditionMet = (targetValue != valToCompare);
            } else if (operator === 'empty') {
                conditionMet = (targetValue.trim() === '');
            } else if (operator === 'not_empty') {
                conditionMet = (targetValue.trim() !== '');
            }

            // Show or hide wrapper based on condition met
            if (conditionMet) {
                wrapper.style.display = 'block';
                wrapper.querySelectorAll('[required]').forEach(input => input.setAttribute('required', 'required'));
            } else {
                wrapper.style.display = 'none';
                wrapper.querySelectorAll('[required]').forEach(input => input.removeAttribute('required'));
            }
        });
    }

    function lookupPincode(input, fieldName) {
        var pin = input.value.trim();
        var statusEl = document.getElementById('pincode_status_' + fieldName);
        var placeSel = document.getElementById('place_' + fieldName);
        var distEl = document.getElementById('district_' + fieldName);
        var stateEl = document.getElementById('state_' + fieldName);

        if (pin.length === 6 && /^\d+$/.test(pin)) {
            statusEl.style.display = 'block';
            statusEl.style.color = 'var(--text-muted)';
            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching location details...';

            fetch('https://api.postalpincode.in/pincode/' + pin)
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data[0] && data[0].Status === 'Success' && data[0].PostOffice) {
                        statusEl.style.color = '#22c55e';
                        statusEl.innerHTML = '<i class="fas fa-check-circle"></i> Found ' + data[0].PostOffice.length + ' post office areas';
                        
                        placeSel.innerHTML = '<option value="">Select Place / Post Office</option>';
                        data[0].PostOffice.forEach(function(po) {
                            var opt = document.createElement('option');
                            opt.value = po.Name;
                            opt.textContent = po.Name + ' (' + po.BranchType + ')';
                            placeSel.appendChild(opt);
                        });

                        if (data[0].PostOffice.length > 0) {
                            placeSel.selectedIndex = 1;
                            distEl.value = data[0].PostOffice[0].District;
                            stateEl.value = data[0].PostOffice[0].State;
                        }
                        syncLocationValue(fieldName);
                    } else {
                        statusEl.style.color = '#f87171';
                        statusEl.innerHTML = '<i class="fas fa-circle-exclamation"></i> Invalid Indian Pincode or no records found';
                    }
                })
                .catch(function(err) {
                    statusEl.style.color = '#f87171';
                    statusEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error fetching pincode details';
                });
        }
    }

    function autoDetectLocation(fieldName) {
        var statusEl = document.getElementById('pincode_status_' + fieldName);
        statusEl.style.display = 'block';
        statusEl.style.color = 'var(--text-muted)';
        statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Requesting location access...';

        if (!navigator.geolocation) {
            statusEl.style.color = '#f87171';
            statusEl.innerHTML = 'Geolocation is not supported by your browser';
            return;
        }

        navigator.geolocation.getCurrentPosition(function(pos) {
            var lat = pos.coords.latitude;
            var lon = pos.coords.longitude;
            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reverse geocoding location...';

            fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lon)
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.address) {
                        var addr = data.address;
                        var postcode = addr.postcode || '';
                        var dist = addr.state_district || addr.county || addr.city || '';
                        var state = addr.state || '';
                        var town = addr.suburb || addr.town || addr.village || addr.city || '';

                        if (postcode && /^\d{6}$/.test(postcode)) {
                            document.getElementById('pincode_' + fieldName).value = postcode;
                            lookupPincode(document.getElementById('pincode_' + fieldName), fieldName);
                        } else {
                            document.getElementById('district_' + fieldName).value = dist;
                            document.getElementById('state_' + fieldName).value = state;
                            var placeSel = document.getElementById('place_' + fieldName);
                            placeSel.innerHTML = '<option value="' + town + '" selected>' + town + '</option>';
                            syncLocationValue(fieldName);
                            statusEl.style.color = '#22c55e';
                            statusEl.innerHTML = '<i class="fas fa-check-circle"></i> Location detected';
                        }
                    }
                })
                .catch(function() {
                    statusEl.style.color = '#f87171';
                    statusEl.innerHTML = 'Reverse geocoding failed';
                });
        }, function(err) {
            statusEl.style.color = '#f87171';
            statusEl.innerHTML = 'Location permission denied or unavailable';
        });
    }

    function syncLocationValue(fieldName) {
        var pin = document.getElementById('pincode_' + fieldName).value.trim();
        var place = document.getElementById('place_' + fieldName).value;
        var dist = document.getElementById('district_' + fieldName).value;
        var state = document.getElementById('state_' + fieldName).value;
        
        var val = '';
        if (pin || place || dist || state) {
            val = [place, dist, state, pin ? 'Pincode: ' + pin : ''].filter(Boolean).join(', ');
        }
        document.getElementById(fieldName).value = val;
    }
</script>
</body>
</html>
