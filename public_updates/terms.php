<?php
/**
 * PEPP Updates Public Portal — Terms of Service
 *
 * Route: /terms or /terms.php
 */

require_once __DIR__ . '/includes/bootstrap.php';

$pageSeo = [
    'title'       => 'Terms of Service — PEPP Updates',
    'description' => 'Terms and conditions governing the use of PEPP Updates portal and notification services.',
    'canonical'   => pepp_seo_get_base_url() . '/terms',
    'og_type'     => 'website',
];

$activeNav = '';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding-top:2.5rem;padding-bottom:4rem;max-width:800px;">
    <!-- Breadcrumbs -->
    <nav class="breadcrumbs" aria-label="Breadcrumbs">
        <a href="/">Home</a>
        <span>/</span>
        <span style="color:var(--foreground);">Terms of Service</span>
    </nav>

    <article style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:2.5rem;box-shadow:var(--shadow-sm);">
        <h1 style="font-size:2.2rem;font-weight:800;color:var(--foreground);line-height:1.2;margin-bottom:0.5rem;">Terms of Service</h1>
        <p style="font-size:0.86rem;color:var(--muted);margin-bottom:2rem;">Last Updated: October 2026</p>

        <div class="rich-content">
            <p>Welcome to <strong>PEPP Updates</strong>. By accessing or using this website (<a href="https://updates.pepplearning.in">updates.pepplearning.in</a>) and our WhatsApp update services, you agree to comply with and be bound by the following terms and conditions.</p>

            <h2>1. Informational Purpose</h2>
            <p>The information, exam dates, syllabus updates, and admission notifications provided on PEPP Updates are curated for educational guidance. While PEPP Learning takes utmost care to ensure the accuracy and timeliness of all published announcements, candidates are strongly advised to verify critical application deadlines with the respective official university or examination authority portals.</p>

            <h2>2. WhatsApp Updates Service</h2>
            <p>Our WhatsApp update service is provided free of charge for students and candidates. Messages will be sent only to users who explicitly opt in. Message frequency depends on active admission schedules and official examination announcements.</p>

            <h2>3. Intellectual Property</h2>
            <p>The branding, design, logos, and original editorial content published on PEPP Updates are the intellectual property of PEPP Learning. Third-party examination logos, trademarks, and university names remain the property of their respective owners and are referenced solely for identification and educational reporting purposes.</p>

            <h2>4. Limitation of Liability</h2>
            <p>PEPP Learning shall not be held liable for any missed deadlines, technical difficulties on third-party examination portals, or decisions made based upon published informational updates.</p>

            <h2>5. Changes to Terms</h2>
            <p>PEPP Learning reserves the right to revise these Terms of Service at any time. Any changes will be posted directly on this page.</p>
        </div>
    </article>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
