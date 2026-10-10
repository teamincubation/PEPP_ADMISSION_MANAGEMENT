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

            <h2>1. Visitor & Interaction Analytics</h2>
            <p>To measure audience reach, ensure operational security, and deliver relevant educational updates, PEPP Updates collects visitor and interaction analytics:</p>
            <ul>
                <li><strong>Visitor Analytics &amp; IP Addresses:</strong> When you visit the portal, our servers record visitor analytics including page views, timestamps, referer headers, user agent device information, and your IP address for security monitoring and regional traffic analysis.</li>
                <li><strong>Session &amp; Visitor Telemetry:</strong> We use a first-party session identifier to correlate visits within a session, track aggregate read counts, and prevent redundant prompt displays.</li>
                <li><strong>Button &amp; Interaction Analytics:</strong> Interactions with call-to-action (CTA) buttons, external application links, and update sharing options are recorded to measure student engagement with specific alerts.</li>
                <li><strong>Optional Browser Geolocation:</strong> To offer district-specific admission and exam notifications, PEPP Updates may display a voluntary location request. If and only if you explicitly choose "Allow" and grant browser permission, approximate latitude, longitude, and accuracy coordinates are collected. Geolocation is entirely optional, and the portal operates with full functionality if permission is denied or dismissed. No coordinates are collected without explicit user consent.</li>
                <li><strong>Restricted Administrator Access:</strong> All visitor records, telemetry, interaction data, and analytics are confidential and accessible solely to authorized PEPP Updates administrators through authenticated administration panels.</li>
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
