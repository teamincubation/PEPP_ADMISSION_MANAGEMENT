<?php
/**
 * PEPP Updates Public Portal — SEO & Structured Data Helper
 *
 * Implements dynamic metadata, canonical links, Open Graph, Twitter cards,
 * and JSON-LD structured data schema (Article / NewsArticle / WebSite).
 */

if (!defined('PEPP_PUBLIC_UPDATES_PORTAL')) {
    define('PEPP_PUBLIC_UPDATES_PORTAL', true);
}

function pepp_seo_get_base_url(): string {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        $scheme = 'https';
    } else {
        $scheme = 'http';
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'updates.pepplearning.in';
    return $scheme . '://' . $host;
}

function pepp_seo_clean_text(?string $text, int $maxLen = 160): string {
    if (!$text) return '';
    $clean = strip_tags($text);
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = trim($clean);
    if (mb_strlen($clean) > $maxLen) {
        $clean = mb_substr($clean, 0, $maxLen - 3) . '...';
    }
    return $clean;
}

function pepp_seo_render(array $seo = []): void {
    $baseUrl     = pepp_seo_get_base_url();
    $siteName    = 'PEPP Updates';
    $defaultDesc = 'Official updates, entrance exam notifications, career alerts, admission updates, and academic notifications from PEPP Learning.';
    $defaultImg  = $baseUrl . '/assets/images/pepp-updates-og.png';

    $title       = !empty($seo['title']) ? $seo['title'] . ' | ' . $siteName : $siteName . ' — Career, Exam & Admission Notifications';
    $description = !empty($seo['description']) ? pepp_seo_clean_text($seo['description'], 160) : $defaultDesc;
    $canonical   = !empty($seo['canonical']) ? $seo['canonical'] : $baseUrl . ($_SERVER['REQUEST_URI'] ?? '/');
    $ogType      = !empty($seo['og_type']) ? $seo['og_type'] : 'website';
    $ogImage     = !empty($seo['image']) ? $seo['image'] : $defaultImg;
    
    // Ensure absolute image URL
    if (strpos($ogImage, 'http') !== 0) {
        $ogImage = $baseUrl . '/' . ltrim($ogImage, '/');
    }

    echo "<!-- SEO & Social Meta Tags -->\n";
    echo "<title>" . htmlspecialchars($title) . "</title>\n";
    echo "<meta name=\"description\" content=\"" . htmlspecialchars($description) . "\">\n";
    echo "<link rel=\"canonical\" href=\"" . htmlspecialchars($canonical) . "\">\n";
    echo "<meta name=\"robots\" content=\"index, follow\">\n";

    echo "<!-- Open Graph (Facebook, WhatsApp, LinkedIn) -->\n";
    echo "<meta property=\"og:site_name\" content=\"" . htmlspecialchars($siteName) . "\">\n";
    echo "<meta property=\"og:type\" content=\"" . htmlspecialchars($ogType) . "\">\n";
    echo "<meta property=\"og:title\" content=\"" . htmlspecialchars(!empty($seo['title']) ? $seo['title'] : $title) . "\">\n";
    echo "<meta property=\"og:description\" content=\"" . htmlspecialchars($description) . "\">\n";
    echo "<meta property=\"og:url\" content=\"" . htmlspecialchars($canonical) . "\">\n";
    echo "<meta property=\"og:image\" content=\"" . htmlspecialchars($ogImage) . "\">\n";
    echo "<meta property=\"og:locale\" content=\"en_IN\">\n";

    echo "<!-- Twitter / X Card -->\n";
    echo "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
    echo "<meta name=\"twitter:site\" content=\"@pepplearning\">\n";
    echo "<meta name=\"twitter:title\" content=\"" . htmlspecialchars(!empty($seo['title']) ? $seo['title'] : $title) . "\">\n";
    echo "<meta name=\"twitter:description\" content=\"" . htmlspecialchars($description) . "\">\n";
    echo "<meta name=\"twitter:image\" content=\"" . htmlspecialchars($ogImage) . "\">\n";

    // Structured Data JSON-LD
    if (!empty($seo['json_ld'])) {
        echo "<!-- Structured Data (JSON-LD) -->\n";
        echo "<script type=\"application/ld+json\">\n";
        echo json_encode($seo['json_ld'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "\n</script>\n";
    }
}
