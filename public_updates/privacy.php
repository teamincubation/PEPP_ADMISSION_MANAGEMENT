<?php
/**
 * PEPP Updates Public Portal — Privacy Policy
 *
 * Route: /privacy or /privacy.php
 */

require_once __DIR__ . '/includes/bootstrap.php';

$pageSeo = [
    'title'       => 'Privacy Policy — PEPP Updates',
    'description' => 'Learn how PEPP Learning and PEPP Updates respect student privacy, data protection, and notification preferences.',
    'canonical'   => pepp_seo_get_base_url() . '/privacy',
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
        <span style="color:var(--foreground);">Privacy Policy</span>
    </nav>

    <article style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:2.5rem;box-shadow:var(--shadow-sm);">
        <h1 style="font-size:2.2rem;font-weight:800;color:var(--foreground);line-height:1.2;margin-bottom:0.5rem;">Privacy Policy</h1>
        <p style="font-size:0.86rem;color:var(--muted);margin-bottom:2rem;">Last Updated: October 2026</p>

        <div class="rich-content">
            <p>At <strong>PEPP Updates</strong> (an initiative of PEPP Learning), accessible from <a href="https://updates.pepplearning.in">updates.pepplearning.in</a>, the privacy of our visitors and students is of paramount importance to us. This Privacy Policy document outlines the types of information that is collected and recorded by PEPP Updates and how we use it.</p>

            <h2>1. Privacy-Conscious Architecture</h2>
            <p>PEPP Updates is built with privacy-first engineering principles:</p>
            <ul>
                <li><strong>No Raw IP Storage:</strong> We do not store raw IP addresses in our visitor analytics. Visitor logs utilize daily cryptographic hashes (SHA-256) with rolling salts, rendering cross-day user tracking impossible.</li>
                <li><strong>No Invasive Cookies:</strong> The public PEPP Updates website does not employ third-party tracking cookies or advertising pixels.</li>
                <li><strong>Read-Only Public Portal:</strong> Public browsing requires no account creation, logins, or social tracking scripts.</li>
            </ul>

            <h2>2. WhatsApp Subscription Information</h2>
            <p>When you voluntarily subscribe to receive notifications on WhatsApp via our subscription page, we collect:</p>
            <ul>
                <li>Your mobile phone number</li>
                <li>Your name (optional)</li>
                <li>Your chosen educational interest categories (e.g. Entrance Exams, Admissions)</li>
                <li>A cryptographic timestamp and audit trail verifying your opt-in consent</li>
            </ul>
            <p>We do not sell, rent, or lease subscriber phone numbers to third parties. Your number is strictly used for dispatching relevant academic announcements and admission alerts from PEPP Learning.</p>

            <h2>3. Unsubscribing & Opt-Out Rights</h2>
            <p>You have the absolute right to discontinue WhatsApp notifications at any time. Subscribers can opt out by replying <code>STOP</code> to any message received from PEPP Updates, or by contacting our support team.</p>

            <h2>4. Information Security</h2>
            <p>We implement industry-standard technical measures, including prepared SQL statements, encrypted database connections, strict access controls, and parameterized queries, to protect your data against unauthorized access, alteration, or disclosure.</p>

            <h2>5. Contact Us</h2>
            <p>If you have any questions or require more information about our Privacy Policy, please contact PEPP Learning at <a href="https://pepplearning.in" target="_blank" rel="noopener noreferrer">pepplearning.in</a>.</p>
        </div>
    </article>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
