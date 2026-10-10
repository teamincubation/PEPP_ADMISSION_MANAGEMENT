<?php
/**
 * PEPP Updates Public Portal — Update Detail Page
 *
 * Route: /update/{slug} or /update.php?slug={slug}
 */

require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim($_GET['slug'] ?? '');

if ($slug === '') {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Fetch published update post by slug
$post = pepp_public_get_post_by_slug($pdo, $slug);

if (!$post) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Log visit for this post
pepp_public_log_visit($pdo, (int)$post['id']);

// Related posts
$catIds = array_column($post['categories'] ?? [], 'id');
$relatedPosts = pepp_public_get_related_posts($pdo, (int)$post['id'], $catIds, 3);

// URLs & Assets
$currentUrl = pepp_seo_get_base_url() . pepp_public_url('update', $post['slug']);
$bannerUrl  = pepp_public_resolve_banner($post['banner_image']);
$shareLinks = pepp_public_share_links($currentUrl, $post['title'], $post['short_description']);

$isExpired = !empty($post['is_expired']);
$isNew     = !empty($post['is_new']);

// Safe action button URL validation
$actionUrl = trim($post['action_button_url'] ?? '');
$actionText = trim($post['action_button_text'] ?? '');
$hasAction = ($actionUrl !== '' && $actionText !== '' && preg_match('/^https?:\/\//i', $actionUrl));

// SEO & JSON-LD
$pageSeo = [
    'title'       => $post['title'],
    'description' => $post['short_description'] ?: $post['title'],
    'canonical'   => $currentUrl,
    'og_type'     => 'article',
    'image'       => $bannerUrl,
    'json_ld'     => [
        '@context'      => 'https://schema.org',
        '@type'         => 'NewsArticle',
        'headline'      => $post['title'],
        'description'   => pepp_seo_clean_text($post['short_description'], 200),
        'image'         => $bannerUrl ? [$bannerUrl] : [],
        'datePublished' => date('c', strtotime($post['publish_at'])),
        'dateModified'  => date('c', strtotime($post['updated_at'] ?? $post['publish_at'])),
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => $currentUrl,
        ],
        'publisher'     => [
            '@type' => 'EducationalOrganization',
            'name'  => 'PEPP Learning',
            'url'   => 'https://pepplearning.in',
        ],
    ],
];

// Sanitize rich content and wrap raw tables in scrollable wrappers
$sanitizedContent = pepp_public_sanitize_html($post['full_description']);
// Ensure tables are wrapped in .table-wrapper for responsive horizontal scrolling
if (strpos($sanitizedContent, '<table') !== false && strpos($sanitizedContent, 'class="table-wrapper"') === false) {
    $sanitizedContent = preg_replace('/(<table\b[^>]*>.*?<\/table>)/is', '<div class="table-wrapper">$1</div>', $sanitizedContent);
}

$activeNav = '';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container single-update" style="padding-top:2rem;padding-bottom:3rem;">
    <!-- Breadcrumbs -->
    <nav class="breadcrumbs" aria-label="Breadcrumbs">
        <a href="/">Home</a>
        <span>/</span>
        <a href="/categories">Categories</a>
        <?php if (!empty($post['categories'])): ?>
            <span>/</span>
            <a href="<?php echo htmlspecialchars(pepp_public_url('category', $post['categories'][0]['slug'])); ?>">
                <?php echo htmlspecialchars($post['categories'][0]['name']); ?>
            </a>
        <?php endif; ?>
        <span>/</span>
        <span style="color:var(--foreground);"><?php echo htmlspecialchars(pepp_seo_clean_text($post['title'], 45)); ?></span>
    </nav>

    <!-- Article Header -->
    <header class="article-header">
        <h1 class="article-title"><?php echo htmlspecialchars($post['title']); ?></h1>

        <div class="article-meta-bar">
            <!-- Badges -->
            <div style="display:flex;align-items:center;gap:6px;">
                <?php if ($isNew): ?>
                    <span class="badge-pill new">NEW</span>
                <?php elseif ($isExpired): ?>
                    <span class="badge-pill expired">EXPIRED</span>
                <?php endif; ?>

                <?php foreach (($post['categories'] ?? []) as $cat): ?>
                    <a href="<?php echo htmlspecialchars(pepp_public_url('category', $cat['slug'])); ?>" class="badge-pill category">
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Date -->
            <div style="display:flex;align-items:center;gap:6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>Published on <?php echo date('F j, Y', strtotime($post['publish_at'])); ?></span>
            </div>

            <div style="display:flex;align-items:center;gap:4px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span><?php echo number_format(max(1, (int)($post['view_count'] ?? 1))); ?> views</span>
            </div>
        </div>
    </header>

    <!-- Expired Warning Notice -->
    <?php if ($isExpired): ?>
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 18px;border-radius:var(--radius);margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;font-size:0.92rem;font-weight:600;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>Notice: This notification was active until <?php echo date('M d, Y', strtotime($post['expires_at'])); ?> and is now archived for reference.</span>
        </div>
    <?php endif; ?>

    <!-- Banner Media -->
    <?php if ($bannerUrl): ?>
        <div class="article-banner-wrap <?php echo $isExpired ? 'is-expired' : ''; ?>">
            <img src="<?php echo htmlspecialchars($bannerUrl); ?>" 
                 alt="<?php echo htmlspecialchars($post['title']); ?>" 
                 loading="eager">
        </div>
    <?php endif; ?>

    <!-- Short Description Lead Block -->
    <?php if (!empty($post['short_description'])): ?>
        <div class="article-short-desc">
            <?php echo nl2br(htmlspecialchars($post['short_description'])); ?>
        </div>
    <?php endif; ?>

    <!-- Full Rich Content -->
    <div class="rich-content">
        <?php echo $sanitizedContent; ?>
    </div>

    <!-- External Action Button -->
    <?php if ($hasAction): ?>
        <div class="article-action-box">
            <a href="<?php echo htmlspecialchars($actionUrl); ?>" target="_blank" rel="noopener noreferrer" class="btn-article-action" data-track-click="action_button" data-post-id="<?php echo (int)$post['id']; ?>" data-btn-name="<?php echo htmlspecialchars($actionText); ?>" data-target-url="<?php echo htmlspecialchars($actionUrl); ?>">
                <span><?php echo htmlspecialchars($actionText); ?></span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>
        </div>
    <?php endif; ?>

    <!-- Keywords Tag Cloud -->
    <?php if (!empty($post['keywords'])): ?>
        <div style="margin:2rem 0;display:flex;flex-wrap:wrap;align-items:center;gap:6px;">
            <span style="font-size:0.84rem;font-weight:700;color:var(--muted);margin-right:4px;">Tags:</span>
            <?php foreach ($post['keywords'] as $kw): ?>
                <a href="/search?q=<?php echo urlencode($kw['keyword']); ?>" class="chip-tag">
                    #<?php echo htmlspecialchars($kw['keyword']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Social Share Toolbar -->
    <div class="share-bar">
        <div class="share-label">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            <span>Share this update:</span>
        </div>

        <div class="share-buttons">
            <a href="<?php echo htmlspecialchars($shareLinks['whatsapp']); ?>" target="_blank" rel="noopener noreferrer" class="share-btn whatsapp" aria-label="Share on WhatsApp" data-track-click="share_whatsapp" data-post-id="<?php echo (int)$post['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                <span>WhatsApp</span>
            </a>
            <a href="<?php echo htmlspecialchars($shareLinks['facebook']); ?>" target="_blank" rel="noopener noreferrer" class="share-btn facebook" aria-label="Share on Facebook" data-track-click="share_facebook" data-post-id="<?php echo (int)$post['id']; ?>">
                <span>Facebook</span>
            </a>
            <a href="<?php echo htmlspecialchars($shareLinks['twitter']); ?>" target="_blank" rel="noopener noreferrer" class="share-btn twitter" aria-label="Share on X" data-track-click="share_twitter" data-post-id="<?php echo (int)$post['id']; ?>">
                <span>X / Twitter</span>
            </a>
            <a href="<?php echo htmlspecialchars($shareLinks['linkedin']); ?>" target="_blank" rel="noopener noreferrer" class="share-btn linkedin" aria-label="Share on LinkedIn" data-track-click="share_linkedin" data-post-id="<?php echo (int)$post['id']; ?>">
                <span>LinkedIn</span>
            </a>
            <button type="button" class="share-btn copy" data-copy-link="<?php echo htmlspecialchars($currentUrl); ?>" aria-label="Copy Link" data-track-click="copy_link" data-post-id="<?php echo (int)$post['id']; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <span>Copy Link</span>
            </button>
        </div>
    </div>

    <!-- Related Updates Section -->
    <?php if (!empty($relatedPosts)): ?>
        <section style="margin-top:3.5rem;padding-top:2.5rem;border-top:1px solid var(--border);">
            <div class="section-head">
                <div>
                    <h2 class="section-title" style="font-size:1.35rem;">Related Updates</h2>
                    <p class="section-desc">More announcements from the same educational categories</p>
                </div>
            </div>

            <div class="updates-grid">
                <?php foreach ($relatedPosts as $rel): ?>
                    <?php
                    $relUrl    = pepp_public_url('update', $rel['slug']);
                    $relBanner = pepp_public_resolve_banner($rel['banner_image']);
                    $relExpired = !empty($rel['is_expired']);
                    $relNew     = !empty($rel['is_new']);
                    ?>
                    <article class="update-card <?php echo $relExpired ? 'is-expired' : ''; ?>">
                        <div class="card-media">
                            <?php if ($relBanner): ?>
                                <img src="<?php echo htmlspecialchars($relBanner); ?>" alt="<?php echo htmlspecialchars($rel['title']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="card-media-placeholder">PEPP Updates</div>
                            <?php endif; ?>
                            <div class="card-badges">
                                <?php if ($relNew): ?><span class="badge-pill new">NEW</span><?php endif; ?>
                                <?php if ($relExpired): ?><span class="badge-pill expired">EXPIRED</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="card-date"><?php echo date('M d, Y', strtotime($rel['publish_at'])); ?></div>
                            <h3 class="card-title">
                                <a href="<?php echo htmlspecialchars($relUrl); ?>"><?php echo htmlspecialchars($rel['title']); ?></a>
                            </h3>
                            <p class="card-desc"><?php echo htmlspecialchars(pepp_seo_clean_text($rel['short_description'], 110)); ?></p>
                            <div class="card-foot">
                                <a href="<?php echo htmlspecialchars($relUrl); ?>" class="card-read-link">Read Update &rarr;</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
