<?php
/**
 * PEPP Updates Public Portal — 500 Server Error Page
 */

if (!headers_sent()) {
    http_response_code(500);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error — PEPP Updates</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/png" href="/assets/images/logo.png">
    <link rel="apple-touch-icon" href="/assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans+Flex:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1.5rem;background:#f8fafc;font-family:'Google Sans Flex', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:3rem 2rem;max-width:540px;text-align:center;box-shadow:0 10px 25px -5px rgba(0,0,0,0.08);">
        <div style="font-size:3.5rem;font-weight:800;color:#6d28d9;margin-bottom:0.5rem;">500</div>
        <h1 style="font-size:1.5rem;font-weight:800;color:#0f172a;margin-bottom:0.75rem;">Service Temporarily Unavailable</h1>
        <p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin-bottom:2rem;">
            We are experiencing a temporary technical glitch. Our technical team has been alerted. Please try refreshing or visit again in a few moments.
        </p>
        <a href="/" style="display:inline-flex;align-items:center;gap:6px;background:#6d28d9;color:#ffffff;text-decoration:none;font-weight:700;padding:10px 22px;border-radius:9999px;">
            Return to Homepage
        </a>
    </div>
</body>
</html>
