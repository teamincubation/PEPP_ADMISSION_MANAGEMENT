<?php
/**
 * PEPP Updates Public Portal — Category Updates Page
 *
 * Route: /category/{slug} or /category.php?slug={slug}
 */

require_once __DIR__ . '/includes/bootstrap.php';

$slug = trim($_GET['slug'] ?? '');

if ($slug === '') {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Fetch active category by slug
$category = pepp_public_get_category_by_slug($pdo, $slug);

if (!$category) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Pagination
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;

// Fetch published updates for this category
$updatesData = pepp_public_get_posts($pdo, [
    'category_id' => (int)$category['id'],
    'page'        => $page,
    'limit'       => $limit,
]);

$posts      = $updatesData['posts'];
$totalPosts = $updatesData['total'];
$totalPages = $updatesData['total_pages'];

// SEO
$currentUrl = pepp_seo_get_base_url() . pepp_public_url('category', $category['slug']);
$pageSeo = [
    'title'       => $category['name'] . ' Updates — PEPP Updates',
    'description' => $category['description'] ?: "Official {$category['name']} entrance exam notifications and admission alerts from PEPP Learning.",
    'canonical'   => $currentUrl,
    'og_type'     => 'website',
];

$activeNav = 'categories';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding-top:2rem;padding-bottom:4rem;">
    <!-- Breadcrumbs -->
    <nav class="breadcrumbs" aria-label="Breadcrumbs">
        <a href="/">Home</a>
        <span>/</span>
        <a href="/categories">Categories</a>
        <span>/</span>
        <span style="color:var(--foreground);"><?php echo htmlspecialchars($category['name']); ?></span>
    </nav>

    <!-- Category Header -->
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:2rem;margin-bottom:2.5rem;box-shadow:var(--shadow-sm);">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:0.75rem;">
            <span class="badge-pill" style="background:var(--primary-light);color:var(--primary);font-size:0.75rem;font-weight:700;">CATEGORY</span>
            <span style="font-size:0.85rem;color:var(--muted);font-weight:600;"><?php echo $totalPosts; ?> total updates</span>
        </div>
        <h1 style="font-size:clamp(1.75rem, 3.5vw, 2.25rem);font-weight:800;color:var(--foreground);line-height:1.2;margin-bottom:0.75rem;">
            <?php echo htmlspecialchars($category['name']); ?>
        </h1>
        <?php if (!empty($category['description'])): ?>
            <p style="font-size:1rem;color:var(--secondary);max-width:700px;line-height:1.6;">
                <?php echo htmlspecialchars($category['description']); ?>
            </p>
        <?php endif; ?>

        <!-- Category Keywords Tags -->
        <?php if (!empty($category['keywords'])): ?>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:1.25rem;">
                <span style="font-size:0.8rem;font-weight:700;color:var(--muted);margin-right:4px;">Topics:</span>
                <?php foreach ($category['keywords'] as $kw): ?>
                    <a href="/search?q=<?php echo urlencode($kw); ?>" class="chip-tag" style="font-size:0.78rem;">
                        #<?php echo htmlspecialchars($kw); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Posts Grid -->
    <div class="section-head">
        <div>
            <h2 class="section-title">Published Notifications</h2>
            <p class="section-desc">Latest updates for <?php echo htmlspecialchars($category['name']); ?></p>
        </div>
    </div>

    <?php if (empty($posts)): ?>
        <div style="text-align:center;padding:4rem 1rem;background:var(--surface);border-radius:var(--radius-lg);border:1px dashed var(--border);">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom:1rem;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <h3 style="font-size:1.2rem;font-weight:700;color:var(--foreground);margin-bottom:0.5rem;">No Updates in this Category</h3>
            <p style="color:var(--muted);max-width:400px;margin:0 auto 1.5rem;">There are currently no active published updates in <?php echo htmlspecialchars($category['name']); ?>.</p>
            <a href="/" class="btn-nav-cta" style="padding:8px 18px;">View All Latest Updates</a>
        </div>
    <?php else: ?>
        <div class="updates-grid">
            <?php foreach ($posts as $post): ?>
                <?php
                $postUrl   = pepp_public_url('update', $post['slug']);
                $bannerUrl = pepp_public_resolve_banner($post['banner_image']);
                $isExpired = !empty($post['is_expired']);
                $isNew     = !empty($post['is_new']);
                ?>
                <article class="update-card <?php echo $isExpired ? 'is-expired' : ''; ?>">
                    <div class="card-media">
                        <?php if ($bannerUrl): ?>
                            <img src="<?php echo htmlspecialchars($bannerUrl); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" loading="lazy" width="640" height="360">
                        <?php else: ?>
                            <div class="card-media-placeholder">PEPP Updates</div>
                        <?php endif; ?>
                        <div class="card-badges">
                            <?php if ($isNew): ?><span class="badge-pill new">NEW</span><?php endif; ?>
                            <?php if ($isExpired): ?><span class="badge-pill expired">EXPIRED</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="card-date"><?php echo date('M d, Y', strtotime($post['publish_at'])); ?></div>
                        <h3 class="card-title">
                            <a href="<?php echo htmlspecialchars($postUrl); ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                        </h3>
                        <p class="card-desc"><?php echo htmlspecialchars(pepp_seo_clean_text($post['short_description'], 130)); ?></p>
                        <div class="card-foot">
                            <a href="<?php echo htmlspecialchars($postUrl); ?>" class="card-read-link">Read Update &rarr;</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Category pagination">
                <?php if ($page > 1): ?>
                    <a href="?slug=<?php echo urlencode($category['slug']); ?>&page=<?php echo ($page - 1); ?>" class="page-btn">&laquo;</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="page-btn active"><?php echo $i; ?></span>
                    <?php elseif ($i <= 3 || $i >= $totalPages - 1 || abs($i - $page) <= 1): ?>
                        <a href="?slug=<?php echo urlencode($category['slug']); ?>&page=<?php echo $i; ?>" class="page-btn"><?php echo $i; ?></a>
                    <?php elseif ($i == 4 && $page > 4): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?slug=<?php echo urlencode($category['slug']); ?>&page=<?php echo ($page + 1); ?>" class="page-btn">&raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
