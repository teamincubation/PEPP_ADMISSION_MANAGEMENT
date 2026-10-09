<?php
/**
 * PEPP Updates Public Portal — All Categories Index
 *
 * Route: /categories or /categories.php
 */

require_once __DIR__ . '/includes/bootstrap.php';

// Fetch all active categories
$categories = pepp_public_get_categories($pdo);

// Page SEO
$pageSeo = [
    'title'       => 'Categories — PEPP Updates',
    'description' => 'Explore educational categories including entrance exams, admissions, psychology alerts, and academic notifications from PEPP Learning.',
    'canonical'   => pepp_seo_get_base_url() . '/categories',
    'og_type'     => 'website',
];

$activeNav = 'categories';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding-top:2.5rem;padding-bottom:4rem;">
    <!-- Breadcrumbs -->
    <nav class="breadcrumbs" aria-label="Breadcrumbs">
        <a href="/">Home</a>
        <span>/</span>
        <span style="color:var(--foreground);">Categories</span>
    </nav>

    <div class="section-head" style="margin-bottom:2rem;">
        <div>
            <h1 class="section-title">Educational Update Categories</h1>
            <p class="section-desc">Select a category to view specialized entrance exam, admission, and career announcements</p>
        </div>
    </div>

    <?php if (empty($categories)): ?>
        <div style="text-align:center;padding:4rem 1rem;background:var(--surface);border-radius:var(--radius-lg);border:1px dashed var(--border);">
            <h3 style="font-size:1.2rem;font-weight:700;color:var(--foreground);">No Categories Available</h3>
            <p style="color:var(--muted);margin-top:0.5rem;">Categories will appear here once published by the administration.</p>
        </div>
    <?php else: ?>
        <div class="categories-grid">
            <?php foreach ($categories as $cat): ?>
                <a href="<?php echo htmlspecialchars(pepp_public_url('category', $cat['slug'])); ?>" class="category-card">
                    <div class="category-card-head">
                        <span class="category-card-title"><?php echo htmlspecialchars($cat['name']); ?></span>
                        <span class="category-card-count"><?php echo (int)$cat['post_count']; ?> updates</span>
                    </div>
                    <?php if (!empty($cat['description'])): ?>
                        <p class="category-card-desc"><?php echo htmlspecialchars($cat['description']); ?></p>
                    <?php else: ?>
                        <p class="category-card-desc" style="color:var(--muted);font-style:italic;">Official notifications and announcements for <?php echo htmlspecialchars($cat['name']); ?>.</p>
                    <?php endif; ?>
                    <div style="margin-top:auto;padding-top:10px;display:flex;align-items:center;gap:4px;font-size:0.84rem;font-weight:700;color:var(--primary);">
                        <span>Browse updates</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
