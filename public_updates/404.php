<?php
/**
 * PEPP Updates Public Portal — 404 Not Found Page
 */

if (!defined('PEPP_PUBLIC_UPDATES_PORTAL')) {
    require_once __DIR__ . '/includes/bootstrap.php';
}

if (!headers_sent()) {
    http_response_code(404);
}

$pageSeo = [
    'title'       => '404 - Page Not Found — PEPP Updates',
    'description' => 'The update or page you requested could not be found on PEPP Updates.',
    'canonical'   => pepp_seo_get_base_url() . '/404.php',
    'og_type'     => 'website',
];

$activeNav = '';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding-top:4rem;padding-bottom:5rem;text-align:center;max-width:600px;">
    <div style="font-size:5rem;font-weight:800;color:var(--primary);line-height:1;margin-bottom:1rem;letter-spacing:-2px;">404</div>
    <h1 style="font-size:1.75rem;font-weight:800;color:var(--foreground);margin-bottom:0.75rem;">Page Not Found</h1>
    <p style="color:var(--secondary);font-size:1rem;line-height:1.55;margin-bottom:2rem;">
        The update, notification, or category you are looking for does not exist, may have expired, or might have been moved.
    </p>

    <!-- Search Form -->
    <div class="hero-search-box" style="margin-bottom:2rem;">
        <form action="/search" method="GET" role="search">
            <input type="text" name="q" placeholder="Search other updates..." required aria-label="Search updates">
            <button type="submit">Search</button>
        </form>
    </div>

    <div>
        <a href="/" class="btn-article-action" style="padding:10px 24px;font-size:0.95rem;">
            <span>Return to Homepage</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
