<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');
require_once 'config/database.php';

$registration_id = $_GET['id'] ?? $_SESSION['last_registered_id'] ?? $_SESSION['last_registered_user_id'] ?? null;

if (!$registration_id) {
    header('Location: register.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? OR user_id = ? LIMIT 1");
    $stmt->execute([$registration_id, $registration_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header('Location: register.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: register.php');
    exit;
}

$registration_time = !empty($user['submit_datetime']) ? date('d M Y, h:i A', strtotime($user['submit_datetime'])) : date('d M Y, h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful — PEPP Learning</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="apple-touch-icon" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background-color: #181003;
            background-image:
                radial-gradient(ellipse 80% 60% at 10% 10%, rgba(232,152,12,.20) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 90% 90%, rgba(240,165,17,.14) 0%, transparent 55%),
                url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23e8980c' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1rem;
        }

        .success-card {
            background: #ffffff;
            border-radius: 28px;
            overflow: hidden;
            max-width: 640px;
            width: 100%;
            box-shadow:
                0 0 0 1px rgba(255,255,255,.06),
                0 8px 32px rgba(0,0,0,.4),
                0 32px 80px rgba(0,0,0,.35);
        }

        /* ── HERO HEADER (matches register.php) ── */
        .success-hero {
            background: linear-gradient(135deg, #2a1a03 0%, #4a2e04 45%, #6b4205 100%);
            padding: 2.5rem 2rem 0;
            position: relative;
            overflow: hidden;
            text-align: center;
        }
        .success-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle 420px at 110% 0%, rgba(250,204,21,.22) 0%, transparent 60%),
                radial-gradient(circle 300px at -10% 110%, rgba(232,152,12,.18) 0%, transparent 50%);
            pointer-events: none;
        }

        .brand-mark {
            width: 72px;
            height: 72px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,.35);
            overflow: hidden;
            position: relative;
            z-index: 1;
            box-shadow: 0 4px 18px rgba(0,0,0,.35);
        }
        .brand-mark img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

        .check-badge {
            position: relative;
            z-index: 1;
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            background: linear-gradient(135deg, #16a34a, #4ade80);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.9rem;
            box-shadow: 0 8px 24px rgba(22,163,74,.45);
            animation: pop .55s cubic-bezier(.18,1.4,.4,1) both;
        }
        @keyframes pop {
            0% { transform: scale(0); }
            100% { transform: scale(1); }
        }

        .success-hero h1 {
            position: relative;
            z-index: 1;
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.4px;
            margin-bottom: 6px;
        }
        .success-hero .sub {
            position: relative;
            z-index: 1;
            font-size: 0.875rem;
            color: rgba(251,211,141,.9);
            font-weight: 500;
            max-width: 440px;
            margin: 0 auto;
            line-height: 1.6;
        }
        .success-hero .sub strong { color: #fde68a; }

        .hero-wave { position: relative; z-index: 1; margin-top: 1.75rem; line-height: 0; }
        .hero-wave svg { display: block; width: 100%; }

        /* ── BODY ── */
        .success-body {
            background: #f5f6fa;
            padding: 1.75rem 1.75rem 2.25rem;
        }

        .details-card {
            background: #fff;
            border: 1px solid #e8eaf0;
            border-radius: 18px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 4px rgba(0,0,0,.04), 0 4px 16px rgba(180,83,9,.06);
        }
        .details-head {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid #f0f2f8;
            position: relative;
        }
        .details-head::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            border-radius: 0 4px 4px 0;
            background: linear-gradient(180deg, #b45309, #f59e0b);
        }
        .details-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #b45309, #f59e0b);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
        }
        .details-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #3b2604;
            margin-bottom: 2px;
        }
        .details-subtitle {
            font-size: 0.72rem;
            color: #94a3b8;
            font-weight: 500;
        }

        .detail-list { padding: 0.5rem 1.4rem; }
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 11px 0;
            border-bottom: 1px solid #f0f2f8;
        }
        .detail-item:last-child { border-bottom: none; }
        .detail-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            flex-shrink: 0;
        }
        .detail-value {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
            text-align: right;
            word-break: break-word;
        }
        .detail-value.uid {
            background: #fef3c7;
            color: #92400e;
            border-radius: 50px;
            padding: 3px 14px;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        /* ── BUTTONS ── */
        .button-group {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border: none;
            border-radius: 50px;
            padding: 0.85rem 1.9rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 0.2px;
            cursor: pointer;
            text-decoration: none;
            transition: all .25s ease;
            color: #fff;
        }
        .btn-action:hover { transform: translateY(-3px); }
        .btn-action:active { transform: translateY(-1px); }

        .notify-btn {
            background: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);
            box-shadow: 0 6px 20px rgba(217,119,6,.45), 0 2px 8px rgba(0,0,0,.12);
        }
        .notify-btn:hover { box-shadow: 0 12px 32px rgba(217,119,6,.5), 0 4px 12px rgba(0,0,0,.18); }

        .back-btn {
            background: #fff;
            color: #92400e;
            border: 1.5px solid #fcd34d;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .back-btn:hover {
            background: #fffbeb;
            box-shadow: 0 8px 20px rgba(217,119,6,.18);
        }

        /* ── FOOTER STRIP ── */
        .success-footer {
            background: #2a1a03;
            padding: 1rem 2rem;
            text-align: center;
        }
        .success-footer p {
            font-size: 0.72rem;
            color: rgba(251,211,141,.6);
            font-weight: 500;
        }

        @media (max-width: 600px) {
            body { padding: 1rem 0.5rem; }
            .success-card { border-radius: 20px; }
            .success-hero { padding: 2rem 1.25rem 0; }
            .success-body { padding: 1.25rem 1rem 1.75rem; }
            .detail-list { padding: 0.4rem 1rem; }
            .button-group { flex-direction: column; }
            .btn-action { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="success-card">

        <!-- Hero -->
        <div class="success-hero">
            <div class="brand-mark">
                <img src="logo.png" alt="PEPP Learning logo">
            </div>
            <div class="check-badge">
                <i class="fas fa-check"></i>
            </div>
            <h1>Registration Successful!</h1>
            <p class="sub">
                Thank you, <strong><?php echo htmlspecialchars($user['name']); ?></strong>! Your registration with PEPP Learning has been submitted successfully and is now under review.
            </p>
            <div class="hero-wave">
                <svg viewBox="0 0 1440 52" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M0,52 C360,0 1080,52 1440,12 L1440,52 Z" fill="#f5f6fa"/>
                </svg>
            </div>
        </div>

        <!-- Body -->
        <div class="success-body">

            <div class="details-card">
                <div class="details-head">
                    <div class="details-icon"><i class="fas fa-id-card"></i></div>
                    <div>
                        <p class="details-title">Registration Details</p>
                        <p class="details-subtitle">Keep your User ID safe for future reference</p>
                    </div>
                </div>
                <div class="detail-list">
                    <div class="detail-item">
                        <span class="detail-label">User ID</span>
                        <span class="detail-value uid"><?php echo htmlspecialchars($user['user_id']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Student Name</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['name']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['email']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">WhatsApp Number</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['whatsapp_country_code'] . ' ' . $user['whatsapp_number']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">PEPP Course</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['pepp_course']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Academic Year</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user['pepp_academic_year']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Submitted on</span>
                        <span class="detail-value"><?php echo $registration_time; ?> (IST)</span>
                    </div>
                </div>
            </div>

            <div class="button-group">
                <button class="btn-action notify-btn" onclick="requestFastApproval()">
                    <i class="fas fa-rocket"></i>
                    Request Fast Approval!
                </button>
                <a href="register.php" class="btn-action back-btn">
                    <i class="fas fa-arrow-left"></i>
                    New Registration
                </a>
            </div>

        </div>

        <!-- Footer -->
        <div class="success-footer">
            <p>&copy; <?php echo date('Y'); ?> PEPP Learning &mdash; All rights reserved. Student Admission Portal.</p>
        </div>

    </div>

    <script>
        function requestFastApproval() {
            const studentName = '<?php echo addslashes($user['name']); ?>';
            const userId = '<?php echo $user['user_id']; ?>';
            const timestamp = '<?php echo $registration_time; ?> (IST)';
            
            const message = `Hello Admin,\n\nI have just completed my registration. Please verify and approve my admission at the earliest.\n\n📋 *Registration Details:*\n• Name: ${studentName}\n• User ID: ${userId}\n• Submitted: ${timestamp}\n\nThank you for your prompt attention.\n\nBest regards,\n${studentName}`;
            
            const adminWhatsApp = '917025000444';
            const whatsappUrl = `https://api.whatsapp.com/send/?phone=${adminWhatsApp}&text=${encodeURIComponent(message)}`;
            window.open(whatsappUrl, '_blank');
        }

        function sendWhatsAppNotification() {
            const userId = '<?php echo $user['user_id']; ?>';
            const studentName = '<?php echo addslashes($user['name']); ?>';
            const course = '<?php echo addslashes($user['pepp_course']); ?>';
            const academicYear = '<?php echo addslashes($user['pepp_academic_year']); ?>';
            const timestamp = '<?php echo $registration_time; ?> (IST)';
            const whatsappNumber = '<?php echo $user['whatsapp_country_code'] . $user['whatsapp_number']; ?>';
            
            const formattedPhone = formatPhoneForWhatsApp(whatsappNumber);
            const whatsappUrl = `https://wa.me/${formattedPhone}?text=${encodeURIComponent(message)}`;
            window.open(whatsappUrl, '_blank');
        }

        function formatPhoneForWhatsApp(phoneNumber, countryCode = '+91') {
            let cleanPhone = phoneNumber.replace(/[^\d]/g, '');
            let cleanCountryCode = countryCode.replace(/[^\d]/g, '');
            
            if (cleanPhone.startsWith(cleanCountryCode)) {
                return cleanPhone;
            }
            
            if (cleanPhone.length === 10) {
                return cleanCountryCode + cleanPhone;
            }
            
            if (cleanPhone.length > 10 && !cleanPhone.startsWith(cleanCountryCode)) {
                return cleanCountryCode + cleanPhone;
            }
            
            return cleanCountryCode + cleanPhone;
        }
    </script>
</body>
</html>
