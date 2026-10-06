<?php
/**
 * PEPP Learning ERP — Public View-Only Policy & Terms Page.
 *
 * Route: /admissions/policy-view.php?policy=<policy_key>[&version=<version>]
 *
 * Provides a responsive, branded, view-only presentation of official PEPP institutional
 * policies (Faculty Policy, Guest Faculty Policy, Employee & Staff Terms, Internship Policy).
 *
 * Security:
 *  - Whitelisted policy keys only (no arbitrary file/table/DB access).
 *  - HTML output sanitized through allowlist DOM parser.
 *  - View-only: no admin controls, no sensitive employee/bank data.
 *  - No authentication required for reading public institutional terms.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/policy_helper.php';

$requested_policy = trim((string)($_GET['policy'] ?? ''));
$requested_version = trim((string)($_GET['version'] ?? ''));

// Validate against strict whitelist
if (!in_array($requested_policy, ALLOWED_POLICY_KEYS, true)) {
    http_response_code(404);
    $page_title = 'Policy Not Found — PEPP Learning';
    $is_not_found = true;
    $policy = null;
} else {
    $policy = policy_get($pdo, $requested_policy, $requested_version ?: null);
    if (!$policy) {
        http_response_code(404);
        $page_title = 'Policy Not Found — PEPP Learning';
        $is_not_found = true;
    } else {
        $is_not_found = false;
        $page_title = htmlspecialchars($policy['title']) . ' — PEPP Learning';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="Official institutional policies and terms of service for PEPP Learning (Labinc Education Pvt. Ltd.).">
    <meta name="robots" content="noindex, follow">
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Lora:ital,wght@0,500;0,600;1,400&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg-dark: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.88);
            --card-border: rgba(148, 163, 184, 0.16);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --text-body: #cbd5e1;
            --accent-brand: #7c3aed;
            --accent-glow: rgba(124, 58, 237, 0.2);
            --accent-teal: #14b8a6;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg-dark);
            background-image:
                radial-gradient(ellipse 70% 50% at 10% 10%, rgba(124, 58, 237, 0.14) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 90% 90%, rgba(20, 184, 166, 0.12) 0%, transparent 55%);
            min-height: 100vh;
            color: var(--text-main);
            padding: 30px 16px 50px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .policy-container {
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 2rem;
            padding: 1rem 0;
        }
        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid var(--card-border);
            padding: 8px 18px;
            border-radius: 9999px;
            margin-bottom: 1.2rem;
            box-shadow: 0 4px 14px rgba(0,0,0,0.15);
        }
        .brand-badge img {
            width: 26px;
            height: 26px;
            border-radius: 6px;
        }
        .brand-badge span {
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            color: #e2e8f0;
            text-transform: uppercase;
        }
        .policy-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2.4rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(12px);
            margin-bottom: 1.8rem;
        }
        .policy-meta-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 1.4rem;
            margin-bottom: 1.8rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.15);
        }
        .policy-title-wrap h1 {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1.3;
            background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .policy-tags {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .tag {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tag-version {
            background: rgba(124, 58, 237, 0.2);
            color: #c4b5fd;
            border: 1px solid rgba(124, 58, 237, 0.35);
        }
        .tag-status {
            background: rgba(34, 197, 94, 0.15);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }
        .tag-date {
            background: rgba(148, 163, 184, 0.12);
            color: #94a3b8;
        }
        /* Rich Text Document Typography */
        .policy-content {
            font-size: 0.96rem;
            line-height: 1.75;
            color: var(--text-body);
        }
        .policy-content h1, .policy-content h2, .policy-content h3, .policy-content h4 {
            color: #f8fafc;
            font-weight: 700;
            margin-top: 1.6rem;
            margin-bottom: 0.75rem;
            letter-spacing: -0.01em;
        }
        .policy-content h1 { font-size: 1.45rem; }
        .policy-content h2 { font-size: 1.25rem; }
        .policy-content h3 { font-size: 1.1rem; }
        .policy-content h4 { font-size: 0.98rem; }
        .policy-content p {
            margin-bottom: 1.1rem;
        }
        .policy-content ul, .policy-content ol {
            margin-left: 1.5rem;
            margin-bottom: 1.2rem;
        }
        .policy-content li {
            margin-bottom: 0.4rem;
        }
        .policy-content a {
            color: #a78bfa;
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .policy-content a:hover {
            color: #c4b5fd;
        }
        .policy-content blockquote {
            border-left: 4px solid var(--accent-brand);
            padding: 8px 16px;
            margin: 1.2rem 0;
            background: rgba(124, 58, 237, 0.08);
            border-radius: 0 8px 8px 0;
            color: #e2e8f0;
            font-style: italic;
        }
        .policy-content hr {
            border: 0;
            border-top: 1px solid rgba(148, 163, 184, 0.18);
            margin: 1.8rem 0;
        }
        .policy-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.2rem 0;
            font-size: 0.88rem;
        }
        .policy-content th, .policy-content td {
            border: 1px solid rgba(148, 163, 184, 0.2);
            padding: 8px 12px;
            text-align: left;
        }
        .policy-content th {
            background: rgba(15, 23, 42, 0.6);
            color: #f1f5f9;
        }
        .footer-note {
            text-align: center;
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.6;
        }
        .print-btn {
            background: transparent;
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .print-btn:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.3);
        }
        @media print {
            body { background: #ffffff; color: #1e293b; padding: 0; }
            .policy-card { background: #ffffff; border: 0; box-shadow: none; padding: 0; color: #000; }
            .policy-title-wrap h1 { color: #000; -webkit-text-fill-color: #000; }
            .policy-content { color: #1e293b; }
            .print-btn, .brand-badge img, .brand-badge { display: none !important; }
        }
        @media (max-width: 640px) {
            .policy-card { padding: 1.5rem; }
            .policy-title-wrap h1 { font-size: 1.4rem; }
            .policy-meta-bar { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<div class="policy-container">
    <div class="header">
        <div class="brand-badge">
            <img src="logo.png" alt="PEPP Learning" onerror="this.style.display='none'">
            <span>PEPP Learning &middot; Admissions ERP</span>
        </div>
    </div>

    <?php if ($is_not_found): ?>
        <div class="policy-card" style="text-align:center; padding:3rem 1.5rem;">
            <div style="font-size:2.5rem; color:#f87171; margin-bottom:1rem;">
                <i class="fas fa-file-circle-xmark"></i>
            </div>
            <h2 style="font-size:1.5rem; font-weight:800; margin-bottom:0.6rem;">Policy Not Found</h2>
            <p style="color:var(--text-muted); max-width:480px; margin:0 auto 1.5rem; font-size:0.92rem; line-height:1.6;">
                The requested institutional policy could not be found or the link provided is invalid.
            </p>
            <a href="staff-registration.php" style="display:inline-block; background:var(--accent-brand); color:#fff; padding:8px 18px; border-radius:8px; text-decoration:none; font-size:0.85rem; font-weight:600;">
                Return to Staff Registration
            </a>
        </div>
    <?php else: ?>
        <div class="policy-card">
            <div class="policy-meta-bar">
                <div class="policy-title-wrap">
                    <h1><?php echo htmlspecialchars($policy['title']); ?></h1>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button type="button" class="print-btn" onclick="window.print()" title="Print or save as PDF">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <div style="display:flex; align-items:center; gap:8px; margin-bottom:1.5rem; flex-wrap:wrap;">
                <span class="tag tag-version"><i class="fas fa-code-branch"></i> Version <?php echo htmlspecialchars($policy['current_version']); ?></span>
                <span class="tag tag-status"><i class="fas fa-check-circle"></i> Official &amp; Active</span>
                <span class="tag tag-date"><i class="fas fa-calendar-alt"></i> Updated: <?php echo htmlspecialchars(date('F j, Y', strtotime($policy['updated_at'] ?? 'now'))); ?></span>
            </div>

            <div class="policy-content">
                <?php echo policy_sanitize_html($policy['content']); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="footer-note">
        &copy; <?php echo date('Y'); ?> PEPP Learning (Labinc Education Pvt. Ltd.) &middot; All Rights Reserved.<br>
        This document forms an integral part of the registration and onboarding agreement.
    </div>
</div>

</body>
</html>
