<?php
/**
 * PEPP Updates Public Portal — Search Page
 *
 * Route: /search or /search.php?q={query}
 */

require_once __DIR__ . '/includes/bootstrap.php';

$query = trim($_GET['q'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;

$posts      = [];
$totalPosts = 0;
$totalPages = 0;

if ($query !== '') {
    $searchData = pepp_public_get_posts($pdo, [
        'search' => $query,
        'page'   => $page,
        'limit'  => $limit,
    ]);

    $posts      = $searchData['posts'];
    $totalPosts = $searchData['total'];
    $totalPages = $searchData['total_pages'];
}

// SEO
$pageTitle = ($query !== '') ? "Search: {$query} — PEPP Updates" : "Search Updates — PEPP Updates";
$pageSeo = [
    'title'       => $pageTitle,
    'description' => "Search university admissions, entrance exams, psychology updates, and career alerts on PEPP Updates.",
    'canonical'   => pepp_seo_get_base_url() . '/search',
    'og_type'     => 'website',
];

$activeNav = 'search';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding-top:2.5rem;padding-bottom:4rem;">
    <!-- Breadcrumbs -->
    <nav class="breadcrumbs" aria-label="Breadcrumbs">
        <a href="/">Home</a>
        <span>/</span>
        <span style="color:var(--foreground);">Search Updates</span>
    </nav>

    <!-- Search Header & Box -->
    <div style="max-width:700px;margin:0 auto 2.5rem;text-align:center;">
        <h1 class="section-title" style="margin-bottom:0.75rem;">Search PEPP Updates</h1>
        <p class="section-desc" style="margin-bottom:1.5rem;">Find specific entrance exams, admission guidelines, university notifications, and deadlines</p>

        <div class="hero-search-box" style="margin-bottom:1rem;">
            <form action="/search" method="GET" role="search">
                <input type="text" name="q" value="<?php echo htmlspecialchars($query); ?>" placeholder="Search entrance exams, courses, universities..." required aria-label="Search updates">
                <button type="submit">Search</button>
            </form>
        </div>
    </div>

    <!-- Results Section -->
    <?php if ($query === ''): ?>
        <div style="text-align:center;padding:3rem 1rem;background:var(--surface);border-radius:var(--radius-lg);border:1px dashed var(--border);max-width:600px;margin:0 auto;">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom:1rem;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <h3 style="font-size:1.15rem;font-weight:700;color:var(--foreground);margin-bottom:0.5rem;">Type a keyword to begin searching</h3>
            <p style="color:var(--muted);font-size:0.9rem;">Try searching for "CUET", "UGC NET", "M.Phil", "Admissions", or "Scholarship".</p>
        </div>
    <?php else: ?>
        <div class="section-head">
            <div>
                <h2 class="section-title" style="font-size:1.35rem;">Search Results for "<?php echo htmlspecialchars($query); ?>"</h2>
                <p class="section-desc">Found <?php echo $totalPosts; ?> matching notification<?php echo ($totalPosts === 1) ? '' : 's'; ?></p>
            </div>
        </div>

        <?php if (empty($posts)): ?>
            <div style="text-align:center;padding:4rem 1rem;background:var(--surface);border-radius:var(--radius-lg);border:1px dashed var(--border);">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom:1rem;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <h3 style="font-size:1.2rem;font-weight:700;color:var(--foreground);margin-bottom:0.5rem;">No Updates Found</h3>
                <p style="color:var(--muted);max-width:450px;margin:0 auto 1.5rem;">We couldn't find any published updates matching your search. Try checking your spelling or using broader search terms.</p>
                <a href="/" class="btn-nav-cta" style="padding:8px 18px;">Browse All Updates</a>
            </div>
        <?php else: ?>
            <div class="updates-grid">
                <?php foreach ($posts as $post): ?>
                    <?php
                    $postUrl   = pepp_public_url('update', $post['slug']);
                    $bannerUrl = pepp_public_resolve_banner($post['banner_image']);
                    $isExpired = !empty($post['is_expired']);
                    $isNew     = !empty($post['is_new']);
                    $catNames  = !empty($post['category_names']) ? explode(',', $post['category_names']) : [];
                    $primaryCat = !empty($catNames) ? trim($catNames[0]) : '';
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
                                <?php if ($primaryCat): ?><span class="badge-pill category"><?php echo htmlspecialchars($primaryCat); ?></span><?php endif; ?>
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
                <nav class="pagination" aria-label="Search pagination">
                    <?php if ($page > 1): ?>
                        <a href="?q=<?php echo urlencode($query); ?>&page=<?php echo ($page - 1); ?>" class="page-btn">&laquo;</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="page-btn active"><?php echo $i; ?></span>
                        <?php elseif ($i <= 3 || $i >= $totalPages - 1 || abs($i - $page) <= 1): ?>
                            <a href="?q=<?php echo urlencode($query); ?>&page=<?php echo $i; ?>" class="page-btn"><?php echo $i; ?></a>
                        <?php elseif ($i == 4 && $page > 4): ?>
                            <span class="page-btn disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?q=<?php echo urlencode($query); ?>&page=<?php echo ($page + 1); ?>" class="page-btn">&raquo;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
