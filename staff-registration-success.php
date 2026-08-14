<?php
/**
 * PEPP Learning — Staff Registration Success Page.
 * Shows confirmation after successful staff registration submission.
 */
date_default_timezone_set('Asia/Kolkata');
require_once 'config/database.php';

if (!isset($_GET['ref'])) { header('Location: staff-registration.php'); exit; }
$ref = trim($_GET['ref']);
try {
    $stmt = $pdo->prepare("SELECT application_reference, full_name, application_for, submitted_at FROM staff_registration_requests WHERE application_reference = ? LIMIT 1");
    $stmt->execute([$ref]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) { header('Location: staff-registration.php'); exit; }
} catch (Exception $e) { header('Location: staff-registration.php'); exit; }
$sub_time = date('d M Y, h:i A', strtotime($app['submitted_at']));
$type_labels = ['employee'=>'PEPP Employee','faculty'=>'Faculty','intern'=>'Intern'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Submitted — PEPP Learning</title>
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
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
            color: #e2e8f0;
        }
        .card {
            background: rgba(30,41,59,.85); border: 1px solid rgba(148,163,184,.15);
            border-radius: 20px; padding: 2.5rem; max-width: 520px; width: 100%;
            text-align: center; backdrop-filter: blur(12px);
            box-shadow: 0 20px 60px rgba(0,0,0,.4);
        }
        .icon-circle {
            width: 72px; height: 72px; border-radius: 50%;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.2rem; font-size: 1.8rem; color: #fff;
        }
        h1 { font-size: 1.4rem; font-weight: 800; margin-bottom: .6rem; color: #f1f5f9; }
        .sub { color: #94a3b8; font-size: .88rem; margin-bottom: 1.6rem; line-height: 1.5; }
        .detail-grid { text-align: left; margin: 1.4rem 0; }
        .detail-row {
            display: flex; justify-content: space-between; padding: 10px 14px;
            border-bottom: 1px solid rgba(148,163,184,.1); font-size: .85rem;
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: #94a3b8; font-weight: 500; }
        .detail-value { color: #f1f5f9; font-weight: 700; text-align: right; }
        .badge-status {
            display: inline-block; background: rgba(251,191,36,.15); color: #fbbf24;
            padding: 4px 14px; border-radius: 20px; font-size: .78rem; font-weight: 700;
            border: 1px solid rgba(251,191,36,.3);
        }
        .note {
            background: rgba(99,102,241,.08); border: 1px solid rgba(99,102,241,.2);
            border-radius: 12px; padding: 14px; margin-top: 1.4rem;
            font-size: .8rem; color: #a5b4fc; text-align: left; line-height: 1.5;
        }
        .btn-home {
            display: inline-block; margin-top: 1.6rem; padding: 12px 28px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
            text-decoration: none; border-radius: 10px; font-weight: 700; font-size: .88rem;
            transition: transform .2s;
        }
        .btn-home:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
<div class="card">
    <div class="icon-circle"><i class="fas fa-check"></i></div>
    <h1>Registration Submitted!</h1>
    <p class="sub">Your staff registration has been received. Our team will review your application and contact you.</p>
    <div class="detail-grid">
        <div class="detail-row">
            <span class="detail-label">Application Ref</span>
            <span class="detail-value"><?php echo htmlspecialchars($app['application_reference']); ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Applicant</span>
            <span class="detail-value"><?php echo htmlspecialchars($app['full_name']); ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Applied For</span>
            <span class="detail-value"><?php echo $type_labels[$app['application_for']] ?? ucfirst($app['application_for']); ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Submitted</span>
            <span class="detail-value"><?php echo $sub_time; ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Status</span>
            <span class="detail-value"><span class="badge-status">Pending Review</span></span>
        </div>
    </div>
    <div class="note">
        <i class="fas fa-info-circle" style="margin-right:6px;"></i>
        Please save your Application Reference Number. You may be contacted for additional information during the review process.
    </div>
    <a href="https://pepplearning.in" class="btn-home">Back to PEPP Learning</a>
</div>
</body>
</html>
