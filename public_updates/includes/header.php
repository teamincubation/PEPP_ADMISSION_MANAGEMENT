<?php
/**
 * PEPP Updates Public Portal — Header Component
 */

if (!defined('PEPP_PUBLIC_UPDATES_PORTAL')) {
    require_once __DIR__ . '/bootstrap.php';
}

$activeNav = $activeNav ?? '';
$pageSeo = $pageSeo ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <!-- Favicon & Touch Icon (Aligned with PEPP Admissions) -->
    <link rel="icon" type="image/png" href="/assets/images/logo.png">
    <link rel="apple-touch-icon" href="/assets/images/logo.png">

    <!-- Google Fonts: Google Sans Flex -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans+Flex:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    
    <!-- SEO & Social Meta Tags -->
    <?php pepp_seo_render($pageSeo); ?>
</head>
<body>
    <a href="#mainContent" class="skip-link" style="position:absolute;top:-9999px;left:0;padding:8px;background:var(--primary);color:#fff;z-index:9999;">Skip to content</a>

    <header class="site-header">
        <div class="container header-inner">
            <a href="/" class="brand-link" aria-label="PEPP Updates Homepage">
                <img src="/assets/images/logo.png" alt="PEPP Learning Logo" class="brand-logo-img" width="40" height="40">
                <div class="brand-text">
                    <span class="brand-title">PEPP <span style="color:var(--primary);">Updates</span></span>
                    <span class="brand-sub">Career & Exam Alerts</span>
                </div>
            </a>

            <!-- Desktop Nav -->
            <nav class="nav-desktop" aria-label="Primary Navigation">
                <a href="/" class="nav-link <?php echo ($activeNav === 'home') ? 'active' : ''; ?>">Home</a>
                <a href="/categories" class="nav-link <?php echo ($activeNav === 'categories') ? 'active' : ''; ?>">Categories</a>
                <a href="/search" class="nav-link <?php echo ($activeNav === 'search') ? 'active' : ''; ?>">Search</a>
                <a href="/subscribe" class="btn-nav-cta">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    <span>Get WhatsApp Updates</span>
                </a>
            </nav>

            <!-- Mobile Hamburger Toggle -->
            <button id="mobileMenuToggle" class="hamburger-btn" aria-label="Toggle navigation menu" aria-expanded="false">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
        </div>

        <!-- Mobile Drawer Navigation -->
        <div id="primaryNav" class="mobile-menu-drawer" aria-label="Mobile Navigation">
            <div class="mobile-menu-content">
                <a href="/" class="nav-link <?php echo ($activeNav === 'home') ? 'active' : ''; ?>">Home</a>
                <a href="/categories" class="nav-link <?php echo ($activeNav === 'categories') ? 'active' : ''; ?>">Categories</a>
                <a href="/search" class="nav-link <?php echo ($activeNav === 'search') ? 'active' : ''; ?>">Search</a>
                <a href="/subscribe" class="btn-nav-cta" style="justify-content:center;margin-top:0.5rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    <span>Get WhatsApp Updates</span>
                </a>
            </div>
        </div>
    </header>

    <main id="mainContent" class="main-content">
