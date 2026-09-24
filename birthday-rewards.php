<?php
/**
 * PEPP Learning — Public Birthday Reward Claim Page.
 *
 * URL: birthday-rewards.php/{student_id}?token={hmac}
 * Uses native PATH_INFO for clean student ID extraction.
 * No session/auth required — HMAC + server-side validation replaces login.
 *
 * Security (10-point server-side validation):
 *  1. HMAC token verification (timing-safe)
 *  2. Student exists
 *  3. Student status = approved
 *  4. Student student_status = active
 *  5. DOB re-verified against today
 *  6. Birthday reward system is active
 *  7. Claim window check (today only)
 *  8. Duplicate claim prevention (unique constraint)
 *  9. Coupon code only revealed after claim
 * 10. No data leakage on failure
 */
date_default_timezone_set('Asia/Kolkata');
require_once 'config/database.php';
require_once 'includes/birthday_scheduler.php';

// ── Extract student ID from PATH_INFO (with FastCGI / REQUEST_URI fallbacks) ──
$pathInfo = $_SERVER['PATH_INFO'] ?? ($_SERVER['ORIG_PATH_INFO'] ?? '');
if (empty($pathInfo) && !empty($_SERVER['REQUEST_URI'])) {
    $parsedPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($parsedPath && preg_match('#birthday-rewards\.php/([^/]+)#', $parsedPath, $matches)) {
        $pathInfo = '/' . $matches[1];
    }
}
$studentId = trim($pathInfo, '/');

// Fallback: check query param (for dev/testing only)
if (empty($studentId)) {
    $studentId = trim($_GET['id'] ?? '');
}

$hmacToken = trim($_GET['token'] ?? '');
$error = '';
$claimed = false;
$alreadyClaimed = false;
$rewardData = null;
$studentName = '';

// ── Validate inputs ─────────────────────────────────────────────────────
if (empty($studentId) || empty($hmacToken)) {
    $error = 'Invalid birthday reward link.';
}

// ── HMAC verification (timing-safe) ─────────────────────────────────────
if (!$error) {
    $expectedHmac = hash_hmac('sha256', $studentId, BIRTHDAY_CLAIM_HMAC_SECRET);
    if (!hash_equals($expectedHmac, $hmacToken)) {
        $error = 'Invalid or expired birthday reward link.';
    }
}

// ── Server-side validation points 2-8 ───────────────────────────────────
$todayStr = date('Y-m-d');
$student = null;
$settings = null;
$personIdentity = null;
$activeYear = null;

if (!$error) {
    // Point 2: Student exists
    try {
        $stmt = $pdo->prepare("
            SELECT user_id, name, date_of_birth, status, student_status, pepp_academic_year,
                   whatsapp_number, whatsapp_country_code, mobile_number, email, created_at
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = 'An error occurred. Please try again later.';
    }

    if (!$student) {
        $error = 'Invalid birthday reward link.';
    }
}

if (!$error) {
    $studentName = $student['name'] ?? 'Student';

    // Point 3: Student approved
    if ($student['status'] !== 'approved') {
        $error = 'This reward link is no longer active.';
    }

    // Point 4: Student active
    if (!$error && ($student['student_status'] ?? '') !== 'active') {
        $error = 'This reward link is no longer active.';
    }

    // Point 5: Current active academic year check
    if (!$error) {
        $activeYear = get_birthday_active_academic_year($pdo);
        if (!$activeYear || ($student['pepp_academic_year'] ?? '') !== $activeYear) {
            $error = 'This reward link is no longer active.';
        }
    }

    // Point 6: DOB matches today (with Feb 29 policy)
    if (!$error) {
        if (!birthday_matches_date($student['date_of_birth'] ?? '', $todayStr)) {
            $error = 'This birthday reward can only be claimed on your birthday.';
        }
    }

    // Point 7: Canonical identity check (Non-canonical student IDs cannot claim)
    if (!$error) {
        $personIdentity = resolve_person_identity($student);
        if (!$personIdentity) {
            $error = 'Invalid birthday reward link.';
        } else {
            $canonical = get_canonical_student_record($personIdentity, $pdo, $activeYear);
            if (!$canonical || $canonical['user_id'] !== $studentId) {
                // Reject non-canonical duplicate student record
                $error = 'This reward link is not valid. Please use the official link sent to your registered contact.';
            }
        }
    }

    // Point 8: Birthday reward system is active
    if (!$error) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM birthday_reward_settings WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        if (!$settings) {
            $error = 'Birthday rewards are not currently available.';
        }
    }

    // Point 9: Claim window check (today only, verified via DOB)

    // Point 10: Check if already claimed for this person identity
    if (!$error && $personIdentity) {
        $existingClaim = get_birthday_claim_by_identity($pdo, $personIdentity, $todayStr);
        if ($existingClaim) {
            $alreadyClaimed = true;
            $rewardData = $existingClaim;
        }
    }
}

// ── Handle claim POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && !$alreadyClaimed && $settings && $personIdentity) {
    try {
        // Re-validate HMAC on POST
        $postHmac = trim($_POST['token'] ?? '');
        $postStudentId = trim($_POST['student_id'] ?? '');
        if (empty($postHmac) || empty($postStudentId) || !hash_equals(hash_hmac('sha256', $postStudentId, BIRTHDAY_CLAIM_HMAC_SECRET), $postHmac)) {
            $error = 'Invalid security token.';
        }

        if (!$error && $postStudentId !== $studentId) {
            $error = 'Invalid request parameters.';
        }

        if (!$error) {
            $pdo->beginTransaction();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $forUpdate = ($driver === 'sqlite') ? '' : ' FOR UPDATE';

            // Lock active settings to prevent race condition
            $stmt = $pdo->prepare("SELECT * FROM birthday_reward_settings WHERE is_active = 1 LIMIT 1" . $forUpdate);
            $stmt->execute();
            $lockedSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedSettings) {
                $pdo->rollBack();
                $error = 'Birthday rewards are not currently available.';
            } else {
                // Ensure current reward version exists
                $version = get_or_create_current_reward_version($pdo, 'system', 'Claim Version Initialization');
                $versionId = $version['id'] ?? null;
                $instructionToken = generate_instruction_token();

                $couponCode = $lockedSettings['coupon_code'] ?? '';
                $couponValidTill = $lockedSettings['valid_till'] ?: null;
                $rewardTitle = $lockedSettings['reward_title'] ?? 'Birthday Reward';
                $rewardDesc = $lockedSettings['reward_description'] ?? '';
                $instructions = $lockedSettings['instructions'] ?? '';
                $terms = $lockedSettings['terms'] ?? '';
                $claimMessage = $lockedSettings['claim_message'] ?? '';
                $voucherImage = $lockedSettings['reward_voucher_image'] ?? null;

                $insertIgnore = ($driver === 'sqlite') ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

                // Atomic insert with immutable snapshot
                $insertStmt = $pdo->prepare("
                    {$insertIgnore} birthday_reward_claims
                    (person_identity, student_id, birthday_date, reward_setting_id, reward_version_id,
                     coupon_code, coupon_valid_till, reward_title, reward_description,
                     instructions, terms, claim_message, voucher_image, instruction_token,
                     claim_whatsapp_status, claimed_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'not_queued', NOW(), NOW())
                ");
                $insertStmt->execute([
                    $personIdentity, $studentId, $todayStr, $lockedSettings['id'], $versionId,
                    $couponCode, $couponValidTill, $rewardTitle, $rewardDesc,
                    $instructions, $terms, $claimMessage, $voucherImage, $instructionToken
                ]);

                if ($insertStmt->rowCount() > 0) {
                    $claimId = (int)$pdo->lastInsertId();
                    $pdo->commit();
                    $claimed = true;
                    $rewardData = [
                        'id'                 => $claimId,
                        'person_identity'    => $personIdentity,
                        'student_id'         => $studentId,
                        'birthday_date'      => $todayStr,
                        'reward_setting_id'  => $lockedSettings['id'],
                        'reward_version_id'  => $versionId,
                        'coupon_code'        => $couponCode,
                        'coupon_valid_till'  => $couponValidTill,
                        'reward_title'       => $rewardTitle,
                        'reward_description' => $rewardDesc,
                        'instructions'       => $instructions,
                        'terms'              => $terms,
                        'claim_message'      => $claimMessage,
                        'voucher_image'      => $voucherImage,
                        'instruction_token'  => $instructionToken,
                        'claimed_at'         => date('Y-m-d H:i:s')
                    ];

                    // Queue post-claim WhatsApp notification AFTER commit
                    dispatch_birthday_claim_whatsapp($pdo, $rewardData, $student, $personIdentity);
                } else {
                    $pdo->rollBack();
                    $alreadyClaimed = true;
                    $rewardData = get_birthday_claim_by_identity($pdo, $personIdentity, $todayStr);
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'An error occurred while claiming the reward. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎂 Birthday Reward — PEPP Learning</title>
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
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            background: #0f0a1e;
            background-image:
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(139,92,246,0.18) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 80%, rgba(245,158,11,0.12) 0%, transparent 55%);
            color: #fff;
            width: 100%;
            overflow-x: hidden;
        }
        .card {
            max-width: 480px; width: 100%;
            background: rgba(255,255,255,0.04);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 2rem 1.75rem;
            text-align: center;
            box-shadow: 0 24px 80px rgba(0,0,0,0.3);
            margin: 0 auto;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .celebration { font-size: 3rem; margin-bottom: 12px; line-height: 1; }
        h1 { font-size: 1.5rem; font-weight: 800; color: #fff; margin-bottom: 6px; letter-spacing: -0.02em; }
        .student-name { font-size: 1.1rem; color: #c4b5fd; font-weight: 600; margin-bottom: 16px; word-break: break-word; }
        .description { font-size: 0.88rem; color: rgba(255,255,255,0.65); line-height: 1.55; margin-bottom: 20px; word-break: break-word; }
        .voucher-image { width: 100%; max-width: 100%; height: auto; object-fit: cover; border-radius: 14px; margin-bottom: 20px; box-shadow: 0 8px 30px rgba(0,0,0,0.2); }
        .coupon-box {
            background: linear-gradient(135deg, #8b5cf6, #a78bfa);
            border-radius: 14px; padding: 20px 16px; margin: 20px 0;
            position: relative; overflow: hidden;
            box-shadow: 0 8px 24px rgba(139,92,246,0.3);
        }
        .coupon-box::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: repeating-linear-gradient(90deg, transparent, transparent 10px, rgba(255,255,255,0.05) 10px, rgba(255,255,255,0.05) 12px);
        }
        .coupon-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 2px; color: rgba(255,255,255,0.7); margin-bottom: 8px; position: relative; }
        .coupon-code {
            font-size: clamp(1.4rem, 6vw, 1.85rem); font-weight: 800; color: #fff; letter-spacing: 3px; position: relative;
            font-family: 'Courier New', monospace; margin-bottom: 12px; word-break: break-all;
        }
        .copy-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 8px 20px; min-height: 40px; border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            color: #fff; font-size: 0.8rem; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            position: relative; touch-action: manipulation;
        }
        .copy-btn:hover { background: rgba(255,255,255,0.35); transform: translateY(-1px); }
        .copy-btn.copied { background: #10b981; border-color: #10b981; }

        .claim-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; max-width: 320px; min-height: 48px;
            padding: 14px 28px; border: none; border-radius: 50px;
            background: linear-gradient(135deg, #f59e0b, #f97316);
            color: #fff; font-weight: 700; font-size: 1rem; cursor: pointer;
            transition: all 0.3s; box-shadow: 0 8px 24px rgba(245,158,11,0.3);
            touch-action: manipulation;
        }
        .claim-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(245,158,11,0.4); }
        .claim-btn:active { transform: translateY(0); }

        .instructions-cta-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; min-height: 44px; padding: 12px 20px; border-radius: 12px;
            background: linear-gradient(135deg, #7c3aed, #9333ea);
            color: #fff; font-weight: 700; font-size: 0.88rem; text-decoration: none;
            margin: 16px 0; transition: all 0.2s; box-shadow: 0 6px 20px rgba(124,58,237,0.35);
            touch-action: manipulation;
        }
        .instructions-cta-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 25px rgba(124,58,237,0.45); }

        .claimed-badge {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 20px; border-radius: 50px;
            background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3);
            color: #4ade80; font-weight: 600; font-size: 0.85rem;
            max-width: 100%;
        }
        .instructions { text-align: left; margin-top: 16px; padding: 16px; background: rgba(255,255,255,0.05); border-radius: 12px; }
        .instructions h3 { font-size: 0.82rem; color: #c4b5fd; margin-bottom: 8px; }
        .instructions p, .instructions li { font-size: 0.78rem; color: rgba(255,255,255,0.6); line-height: 1.6; word-break: break-word; }
        .instructions ul, .instructions ol { padding-left: 20px; margin-bottom: 8px; }
        .terms { font-size: 0.68rem; color: rgba(255,255,255,0.35); margin-top: 16px; line-height: 1.5; text-align: left; word-break: break-word; }
        .error-icon { font-size: 2.5rem; color: #ef4444; margin-bottom: 16px; }
        .error-text { color: rgba(255,255,255,0.7); font-size: 0.9rem; line-height: 1.5; word-break: break-word; }
        .pepp-logo {
            max-width: 80px;
            height: auto;
            margin-bottom: 16px;
            border-radius: 50%;
            background: transparent;
            border: none;
            outline: none;
            box-shadow: none;
            display: inline-block;
        }
        @keyframes confetti { 0% { transform: translateY(0) rotate(0); opacity: 1; } 100% { transform: translateY(-60px) rotate(360deg); opacity: 0; } }
        .confetti-burst { position: relative; }
        .confetti-burst::after {
            content: '🎉✨🎊';
            position: absolute; top: -20px; left: 50%; transform: translateX(-50%);
            font-size: 1.5rem; animation: confetti 1.5s ease-out forwards;
            pointer-events: none;
        }

        /* Mobile Responsive Enhancements */
        @media (max-width: 520px) {
            body {
                padding: 1rem 0.75rem;
                justify-content: flex-start;
            }
            .card {
                padding: 1.5rem 1.1rem;
                border-radius: 20px;
            }
            h1 {
                font-size: 1.35rem;
            }
            .student-name {
                font-size: 1rem;
                margin-bottom: 12px;
            }
            .coupon-box {
                padding: 16px 12px;
                margin: 16px 0;
            }
            .claim-btn {
                max-width: 100%;
                font-size: 0.95rem;
                padding: 14px 20px;
            }
            .instructions-cta-btn {
                font-size: 0.82rem;
                padding: 12px 14px;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if (file_exists(__DIR__ . '/logo.png') || file_exists('logo.png')): ?>
            <img class="pepp-logo" src="/admissions/logo.png" alt="PEPP Learning">
        <?php endif; ?>

        <?php if ($error): ?>
            <!-- Error State -->
            <div class="error-icon"><i class="fas fa-circle-xmark"></i></div>
            <h1>Oops!</h1>
            <p class="error-text"><?php echo htmlspecialchars($error); ?></p>

        <?php elseif ($claimed || $alreadyClaimed): ?>
            <!-- Claimed State -->
            <div class="celebration confetti-burst">🎂</div>
            <h1>Happy Birthday!</h1>
            <p class="student-name"><?php echo htmlspecialchars($studentName); ?></p>

            <?php
            $voucherImg = $rewardData['voucher_image'] ?? ($rewardData['settings']['reward_voucher_image'] ?? '');
            if (!empty($voucherImg)): ?>
                <img class="voucher-image" src="/<?php echo ltrim(htmlspecialchars($voucherImg), '/'); ?>" alt="Birthday Voucher">
            <?php endif; ?>

            <?php if (!empty($rewardData['coupon_code'])): ?>
                <div class="coupon-box">
                    <div class="coupon-label">Your Coupon Code</div>
                    <div class="coupon-code" id="coupon-code-text"><?php echo htmlspecialchars($rewardData['coupon_code']); ?></div>
                    <button type="button" class="copy-btn" id="copy-btn" onclick="copyCouponCode()">
                        <i class="fas fa-copy"></i> <span id="copy-btn-label">Copy Code</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="claimed-badge">
                <i class="fas fa-check-circle"></i>
                <?php if ($alreadyClaimed && !$claimed): ?>
                    Reward already claimed at <?php echo date('h:i A', strtotime($rewardData['claimed_at'])); ?>
                <?php else: ?>
                    Reward claimed successfully!
                <?php endif; ?>
            </div>

            <?php if (!empty($rewardData['is_legacy'])): ?>
                <div style="background:rgba(255,255,255,0.06); border:1px dashed rgba(255,255,255,0.2); border-radius:12px; padding:14px; margin-top:16px; font-size:0.82rem; color:rgba(255,255,255,0.7); text-align:left;">
                    <div style="font-weight:700; color:#fbbf24; margin-bottom:4px;"><i class="fas fa-history"></i> Legacy Claim Record</div>
                    <p style="margin:0 0 6px 0;">This reward was claimed on <?php echo date('d M Y \a\t h:i A', strtotime($rewardData['claimed_at'])); ?> prior to the Phase 2 snapshot system.</p>
                    <p style="margin:0; font-size:0.75rem; color:rgba(255,255,255,0.5);">Historical voucher terms and instruction snapshots are preserved as originally recorded. To redeem or verify this reward, please contact PEPP Admissions directly with your student ID: <strong><?php echo htmlspecialchars($studentId); ?></strong>.</p>
                </div>
            <?php else: ?>
                <?php if (!empty($rewardData['instruction_token'])): ?>
                    <a href="/admissions/birthday-instructions.php/<?php echo urlencode($rewardData['instruction_token']); ?>" class="instructions-cta-btn" target="_blank">
                        <i class="fas fa-book-open"></i> Read Full Reward Instructions &amp; T&amp;C
                    </a>
                <?php endif; ?>

                <?php
                $claimMsg = !empty($rewardData['claim_message']) ? $rewardData['claim_message'] : null;
                if (!empty($claimMsg)): ?>
                    <div class="description" style="margin-top:16px;"><?php echo sanitize_reward_html($claimMsg); ?></div>
                <?php endif; ?>

                <?php
                $instrContent = !empty($rewardData['instructions']) ? $rewardData['instructions'] : null;
                if (!empty($instrContent)): ?>
                    <div class="instructions">
                        <h3><i class="fas fa-info-circle"></i> How to Redeem</h3>
                        <div><?php echo sanitize_reward_html($instrContent); ?></div>
                    </div>
                <?php endif; ?>

                <?php
                $termsContent = !empty($rewardData['terms']) ? $rewardData['terms'] : null;
                if (!empty($termsContent)): ?>
                    <div class="terms"><?php echo sanitize_reward_html($termsContent); ?></div>
                <?php endif; ?>

                <?php
                $validTill = !empty($rewardData['coupon_valid_till']) ? $rewardData['coupon_valid_till'] : null;
                if (!empty($validTill)): ?>
                    <p class="terms" style="margin-top:8px;">Valid until: <?php echo date('d M Y', strtotime($validTill)); ?></p>
                <?php endif; ?>
            <?php endif; ?>

        <?php else: ?>
            <!-- Unclaimed State — Show claim button -->
            <div class="celebration">🎂</div>
            <h1>Happy Birthday!</h1>
            <p class="student-name"><?php echo htmlspecialchars($studentName); ?></p>

            <?php if (!empty($settings['reward_description'])): ?>
                <p class="description"><?php echo nl2br(htmlspecialchars($settings['reward_description'])); ?></p>
            <?php endif; ?>

            <?php if (!empty($settings['reward_voucher_image'])): ?>
                <img class="voucher-image" src="/<?php echo ltrim(htmlspecialchars($settings['reward_voucher_image']), '/'); ?>" alt="Birthday Voucher">
            <?php endif; ?>

            <p class="description" style="margin-bottom:24px;">
                <i class="fas fa-gift"></i> Claim your exclusive birthday reward from PEPP Learning!
            </p>

            <form method="POST" id="claim-form">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($hmacToken); ?>">
                <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($studentId); ?>">
                <button type="submit" class="claim-btn" id="claim-btn">
                    <i class="fas fa-gift"></i> Claim My Birthday Reward
                </button>
            </form>

            <?php if (!empty($settings['terms'])): ?>
                <p class="terms" style="margin-top:16px;"><?php echo nl2br(htmlspecialchars($settings['terms'])); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
    // Prevent double-submit
    const form = document.getElementById('claim-form');
    if (form) {
        form.addEventListener('submit', function() {
            const btn = document.getElementById('claim-btn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Claiming...';
            }
        });
    }

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
