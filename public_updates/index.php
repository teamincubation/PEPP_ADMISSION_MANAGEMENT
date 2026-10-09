<?php
/**
 * PEPP Updates Public Portal — Homepage
 *
 * Displays hero search, active category chips, paginated latest updates grid,
 * category showcase, and WhatsApp updates subscription CTA.
 */

require_once __DIR__ . '/includes/bootstrap.php';

// Log daily privacy-hashed homepage visit
pepp_public_log_visit($pdo, null);

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;

// Fetch published updates
$updatesData = pepp_public_get_posts($pdo, [
    'page'  => $page,
    'limit' => $limit,
]);

$posts       = $updatesData['posts'];
$totalPosts  = $updatesData['total'];
$totalPages  = $updatesData['total_pages'];

// Fetch active categories
$categories = pepp_public_get_categories($pdo);

// Page SEO & JSON-LD
$pageSeo = [
    'title'       => 'PEPP Updates — Career, Exam & Admission Notifications',
    'description' => 'Stay ahead with official PEPP Learning entrance exam alerts, admission notifications, psychology updates, and career opportunities.',
    'canonical'   => pepp_seo_get_base_url() . '/',
    'og_type'     => 'website',
    'json_ld'     => [
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => 'PEPP Updates',
        'url'      => pepp_seo_get_base_url() . '/',
        'description' => 'Official career alerts, entrance exam notifications, and admission updates from PEPP Learning.',
        'publisher' => [
            '@type' => 'EducationalOrganization',
            'name'  => 'PEPP Learning',
            'url'   => 'https://pepplearning.in',
        ],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => pepp_seo_get_base_url() . '/search.php?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ],
];

$activeNav = 'home';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="hero">
    <div class="container hero-inner">
        <div class="hero-pill">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span>Official Notification Portal</span>
        </div>

        <h1 class="hero-title">Stay Updated. <span>Stay Ahead.</span></h1>
        
        <p class="hero-subtitle">
            Verified entrance exam alerts, university admission notifications, psychology updates, and educational opportunities from PEPP Learning.
        </p>

        <!-- Search Box -->
        <div class="hero-search-box">
            <form action="/search" method="GET" role="search">
                <input type="text" name="q" placeholder="Search exams, admissions, notifications..." aria-label="Search updates" required>
                <button type="submit">Search</button>
            </form>
        </div>

        <!-- Category Quick Chips -->
        <?php if (!empty($categories)): ?>
            <div class="hero-category-chips" aria-label="Popular Categories">
                <?php foreach (array_slice($categories, 0, 6) as $chip): ?>
                    <a href="<?php echo htmlspecialchars(pepp_public_url('category', $chip['slug'])); ?>" class="chip-tag">
                        <?php echo htmlspecialchars($chip['name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Latest Updates Section -->
<section class="section" id="latest">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 class="section-title">Latest Updates</h2>
                <p class="section-desc">Recent notifications and critical academic announcements</p>
            </div>
            <?php if ($totalPosts > 0): ?>
                <span class="section-desc" style="font-weight: 600;">
                    Showing <?php echo count($posts); ?> of <?php echo $totalPosts; ?> updates
                </span>
            <?php endif; ?>
        </div>

        <?php if (empty($posts)): ?>
            <div style="text-align:center;padding:4rem 1rem;background:var(--surface);border-radius:var(--radius-lg);border:1px dashed var(--border);">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" style="margin-bottom:1rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <h3 style="font-size:1.25rem;font-weight:700;color:var(--foreground);margin-bottom:0.5rem;">No Published Updates Yet</h3>
                <p style="color:var(--muted);max-width:400px;margin:0 auto;">Check back soon for new entrance exam alerts and university admission updates.</p>
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
                                <img src="<?php echo htmlspecialchars($bannerUrl); ?>" 
                                     alt="<?php echo htmlspecialchars($post['title']); ?>" 
                                     loading="lazy" 
                                     width="640" 
                                     height="360">
                            <?php else: ?>
                                <div class="card-media-placeholder">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                    <span>PEPP Updates</span>
                                </div>
                            <?php endif; ?>

                            <div class="card-badges">
                                <?php if ($isNew): ?>
                                    <span class="badge-pill new">NEW</span>
                                <?php elseif ($isExpired): ?>
                                    <span class="badge-pill expired">EXPIRED</span>
                                <?php endif; ?>

                                <?php if ($primaryCat): ?>
                                    <span class="badge-pill category"><?php echo htmlspecialchars($primaryCat); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="card-date">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span><?php echo date('M d, Y', strtotime($post['publish_at'])); ?></span>
                            </div>

                            <h3 class="card-title">
                                <a href="<?php echo htmlspecialchars($postUrl); ?>">
                                    <?php echo htmlspecialchars($post['title']); ?>
                                </a>
                            </h3>

                            <p class="card-desc">
                                <?php echo htmlspecialchars(pepp_seo_clean_text($post['short_description'], 140)); ?>
                            </p>

                            <div class="card-foot">
                                <a href="<?php echo htmlspecialchars($postUrl); ?>" class="card-read-link" aria-label="Read full update on <?php echo htmlspecialchars($post['title']); ?>">
                                    <span>Read Update</span>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                </a>
                                <?php if (!empty($post['view_count'])): ?>
                                    <span style="font-size:0.75rem;color:var(--muted);display:flex;align-items:center;gap:3px;">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php echo number_format($post['view_count']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Controlled Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Updates pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo ($page - 1); ?>#latest" class="page-btn" aria-label="Previous Page">&laquo;</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="page-btn active" aria-current="page"><?php echo $i; ?></span>
                        <?php elseif ($i <= 3 || $i >= $totalPages - 1 || abs($i - $page) <= 1): ?>
                            <a href="?page=<?php echo $i; ?>#latest" class="page-btn"><?php echo $i; ?></a>
                        <?php elseif ($i == 4 && $page > 4): ?>
                            <span class="page-btn disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo ($page + 1); ?>#latest" class="page-btn" aria-label="Next Page">&raquo;</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- WhatsApp Subscription CTA Banner -->
<section class="container">
    <div class="whatsapp-cta-banner">
        <div class="whatsapp-cta-content">
            <h3>Get Instant Alerts on WhatsApp</h3>
            <p>Never miss a registration deadline, admission announcement, or exam notification. Select your preferred categories and receive instant updates directly on WhatsApp.</p>
        </div>
        <a href="/subscribe" class="btn-whatsapp-large">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            <span>Subscribe on WhatsApp</span>
        </a>
    </div>
</section>

<!-- Explore Categories Section -->
<?php if (!empty($categories)): ?>
<section class="section" style="padding-top:1rem;">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 class="section-title">Explore Categories</h2>
                <p class="section-desc">Browse updates filtered by specialization and exam domain</p>
            </div>
            <a href="/categories" class="section-link">
                <span>View all categories</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </a>
        </div>

        <div class="categories-grid">
            <?php foreach ($categories as $cat): ?>
                <a href="<?php echo htmlspecialchars(pepp_public_url('category', $cat['slug'])); ?>" class="category-card">
                    <div class="category-card-head">
                        <span class="category-card-title"><?php echo htmlspecialchars($cat['name']); ?></span>
                        <span class="category-card-count"><?php echo (int)$cat['post_count']; ?> updates</span>
                    </div>
                    <?php if (!empty($cat['description'])): ?>
                        <p class="category-card-desc"><?php echo htmlspecialchars(pepp_seo_clean_text($cat['description'], 110)); ?></p>
                    <?php else: ?>
                        <p class="category-card-desc" style="color:var(--muted);font-style:italic;">Stay notified on <?php echo htmlspecialchars($cat['name']); ?> notifications.</p>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
