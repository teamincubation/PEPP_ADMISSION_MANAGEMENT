<?php
/**
 * PEPP Learning — Permanent Birthday Reward Instructions Page.
 *
 * URL: /admissions/birthday-instructions.php/{instruction_token}
 * Fallback: /admissions/birthday-instructions.php?token={instruction_token}
 *
 * Security & Architecture:
 *  - Permanent access decoupled from birthday date / active claim time window.
 *  - High-entropy unguessable token lookup (zero IDOR vulnerability).
 *  - Displays immutable snapshot captured at claim time.
 *  - All rich text is safely sanitized via allowlist before rendering.
 *  - Native browser clipboard "Copy Code" functionality with fallback.
 */
date_default_timezone_set('Asia/Kolkata');
require_once 'config/database.php';
require_once 'includes/birthday_scheduler.php';

// ── Extract token from PATH_INFO (with FastCGI / REQUEST_URI fallbacks) ──
$pathInfo = $_SERVER['PATH_INFO'] ?? ($_SERVER['ORIG_PATH_INFO'] ?? '');
if (empty($pathInfo) && !empty($_SERVER['REQUEST_URI'])) {
    $parsedPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($parsedPath && preg_match('#birthday-instructions\.php/([^/?]+)#', $parsedPath, $matches)) {
        $pathInfo = '/' . $matches[1];
    }
}
$token = trim($pathInfo, '/');

// Fallback: check query param
if (empty($token)) {
    $token = trim($_GET['token'] ?? '');
}

$claim = null;
$student = null;
$error = '';

if (empty($token) || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
    $error = 'Invalid reward instruction link.';
} else {
    $claim = get_birthday_claim_by_token($pdo, $token);
    if (!$claim) {
        $error = 'This reward instruction link was not found or is invalid.';
    } else {
        // Fetch student name for personalized receipt display
        try {
            $sStmt = $pdo->prepare("SELECT user_id, name, pepp_course FROM users WHERE user_id = ? LIMIT 1");
            $sStmt->execute([$claim['student_id']]);
            $student = $sStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
}

$studentName = $student['name'] ?? 'PEPP Student';
$claimedAtFormatted = !empty($claim['claimed_at']) ? date('d M Y, h:i A', strtotime($claim['claimed_at'])) : '';
$validTillFormatted = !empty($claim['coupon_valid_till']) ? date('d M Y', strtotime($claim['coupon_valid_till'])) : 'No Expiry';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎁 Birthday Reward Instructions — PEPP Learning</title>
    <link rel="icon" type="image/png" href="/admissions/logo.png">
    <link rel="apple-touch-icon" href="/admissions/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 2.5rem 1rem;
            background: #0f0a1e;
            background-image:
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(139,92,246,0.18) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 80%, rgba(245,158,11,0.12) 0%, transparent 55%);
            color: #fff;
        }
        .card {
            max-width: 520px; width: 100%;
            background: rgba(255,255,255,0.04);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 2.2rem 2rem;
            text-align: center;
            box-shadow: 0 24px 80px rgba(0,0,0,0.35);
        }
        .pepp-logo {
            max-width: 80px;
            height: auto;
            margin-bottom: 18px;
            border-radius: 50%;
            background: transparent;
            border: none;
            outline: none;
            box-shadow: none;
            display: inline-block;
        }
        .celebration-icon { font-size: 2.8rem; margin-bottom: 10px; line-height: 1; }
        h1 { font-size: 1.45rem; font-weight: 800; color: #fff; margin-bottom: 6px; letter-spacing: -0.02em; }
        .student-name { font-size: 1.05rem; color: #c4b5fd; font-weight: 600; margin-bottom: 14px; }
        .claimed-receipt-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 18px; border-radius: 50px;
            background: rgba(34,197,94,0.14); border: 1px solid rgba(34,197,94,0.35);
            color: #4ade80; font-weight: 600; font-size: 0.8rem;
            margin-bottom: 20px;
        }
        .description { font-size: 0.9rem; color: rgba(255,255,255,0.72); line-height: 1.55; margin-bottom: 20px; }
        .voucher-image { max-width: 100%; border-radius: 14px; margin-bottom: 20px; box-shadow: 0 8px 30px rgba(0,0,0,0.25); }

        .coupon-wrapper {
            background: linear-gradient(135deg, #7c3aed, #a855f7);
            border-radius: 16px; padding: 22px 20px; margin: 20px 0;
            position: relative; overflow: hidden;
            box-shadow: 0 10px 30px rgba(124,58,237,0.3);
        }
        .coupon-wrapper::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: repeating-linear-gradient(90deg, transparent, transparent 10px, rgba(255,255,255,0.06) 10px, rgba(255,255,255,0.06) 12px);
        }
        .coupon-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 2px; color: rgba(255,255,255,0.78); margin-bottom: 6px; position: relative; }
        .coupon-code {
            font-size: 1.85rem; font-weight: 800; color: #fff; letter-spacing: 4px; position: relative;
            font-family: 'Courier New', monospace; margin-bottom: 12px;
        }
        .copy-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 8px 20px; border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.18);
            color: #fff; font-size: 0.82rem; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            position: relative; backdrop-filter: blur(8px);
        }
        .copy-btn:hover { background: rgba(255,255,255,0.3); transform: translateY(-1px); }
        .copy-btn:active { transform: translateY(0); }
        .copy-btn.copied { background: #10b981; border-color: #10b981; color: #fff; }

        .validity-bar {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 0.82rem; color: #fcd34d; font-weight: 600;
            margin-bottom: 16px;
        }

        .section-block {
            text-align: left; margin-top: 18px; padding: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 14px;
        }
        .section-block h3 {
            font-size: 0.85rem; color: #c4b5fd; font-weight: 700;
            margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
        }
        .rich-content {
            font-size: 0.82rem; color: rgba(255,255,255,0.72); line-height: 1.65;
        }
        .rich-content p { margin-bottom: 8px; }
        .rich-content p:last-child { margin-bottom: 0; }
        .rich-content ul, .rich-content ol { padding-left: 20px; margin-bottom: 8px; }
        .rich-content li { margin-bottom: 4px; }
        .rich-content a { color: #a78bfa; text-decoration: underline; }
        .rich-content a:hover { color: #c4b5fd; }
        .rich-content strong, .rich-content b { color: #fff; }

        .terms-block {
            margin-top: 20px; font-size: 0.72rem; color: rgba(255,255,255,0.42); line-height: 1.55;
            text-align: left; border-top: 1px solid rgba(255,255,255,0.06); padding-top: 16px;
        }
        .terms-block h4 {
            font-size: 0.74rem; font-weight: 700; color: rgba(255,255,255,0.6);
            margin-bottom: 6px; text-transform: uppercase; letter-spacing: 1px;
        }

        .footer-note {
            margin-top: 24px; font-size: 0.75rem; color: rgba(255,255,255,0.45);
        }

        .error-icon { font-size: 2.8rem; color: #ef4444; margin-bottom: 16px; }
        .error-text { color: rgba(255,255,255,0.7); font-size: 0.95rem; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <img class="pepp-logo" src="/admissions/logo.png" alt="PEPP Learning">

        <?php if ($error): ?>
            <!-- Error State -->
            <div class="error-icon"><i class="fas fa-circle-xmark"></i></div>
            <h1>Reward Link Not Found</h1>
            <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
            <div class="footer-note" style="margin-top: 20px;">
                PEPP Learning Community
            </div>

        <?php else: ?>
            <!-- Permanent Reward Receipt & Instructions -->
            <div class="celebration-icon">🎁</div>
            <h1><?php echo htmlspecialchars($claim['reward_title'] ?: 'Birthday Reward'); ?></h1>
            <p class="student-name"><?php echo htmlspecialchars($studentName); ?></p>

            <div class="claimed-badge claimed-receipt-badge">
                <i class="fas fa-check-circle"></i>
                Reward Claimed on <?php echo htmlspecialchars($claimedAtFormatted); ?>
            </div>

            <?php if (!empty($claim['reward_description'])): ?>
                <p class="description"><?php echo nl2br(htmlspecialchars($claim['reward_description'])); ?></p>
            <?php endif; ?>

            <?php if (!empty($claim['voucher_image'])): ?>
                <img class="voucher-image" src="/<?php echo ltrim(htmlspecialchars($claim['voucher_image']), '/'); ?>" alt="Birthday Voucher">
            <?php endif; ?>

            <?php if (!empty($claim['coupon_code'])): ?>
                <div class="coupon-wrapper">
                    <div class="coupon-label">Your Claimed Coupon Code</div>
                    <div class="coupon-code" id="coupon-code-text"><?php echo htmlspecialchars($claim['coupon_code']); ?></div>
                    <button type="button" class="copy-btn" id="copy-btn" onclick="copyCouponCode()">
                        <i class="fas fa-copy"></i> <span id="copy-btn-label">Copy Code</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="validity-bar">
                <i class="fas fa-calendar-check"></i>
                Valid Until: <?php echo htmlspecialchars($validTillFormatted); ?>
            </div>

            <?php if (!empty($claim['claim_message'])): ?>
                <div class="section-block" style="background: rgba(139,92,246,0.08); border-color: rgba(139,92,246,0.2);">
                    <div class="rich-content">
                        <?php echo sanitize_reward_html($claim['claim_message']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($claim['instructions'])): ?>
                <div class="section-block">
                    <h3><i class="fas fa-book-open"></i> How to Redeem Your Reward</h3>
                    <div class="rich-content">
                        <?php echo sanitize_reward_html($claim['instructions']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($claim['terms'])): ?>
                <div class="terms-block">
                    <h4>Terms &amp; Conditions</h4>
                    <div class="rich-content" style="color: rgba(255,255,255,0.48);">
                        <?php echo sanitize_reward_html($claim['terms']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="footer-note">
                <i class="fas fa-heart" style="color: #ec4899;"></i> PEPP Learning Community
            </div>
        <?php endif; ?>
    </div>

    <script>
    function copyCouponCode() {
        const codeText = document.getElementById('coupon-code-text')?.innerText.trim();
        if (!codeText) return;

        const btn = document.getElementById('copy-btn');
        const label = document.getElementById('copy-btn-label');

        const onSuccess = () => {
            if (btn && label) {
                btn.classList.add('copied');
                label.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => {
                    btn.classList.remove('copied');
                    label.innerHTML = 'Copy Code';
                }, 2200);
            }
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(codeText)
                .then(onSuccess)
                .catch(() => fallbackCopy(codeText, onSuccess));
        } else {
            fallbackCopy(codeText, onSuccess);
        }
    }

    function fallbackCopy(text, cb) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.top = '-9999px';
        textArea.style.left = '-9999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            cb();
        } catch (e) {
            alert('Coupon Code: ' + text);
        }
        document.body.removeChild(textArea);
    }
    </script>
</body>
</html>
