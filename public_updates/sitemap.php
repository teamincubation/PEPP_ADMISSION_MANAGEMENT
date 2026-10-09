<?php
/**
 * PEPP Updates Public Portal — Dynamic XML Sitemap
 *
 * Route: /sitemap.xml or /sitemap.php
 *
 * Dynamically lists:
 * - Homepage
 * - Active Category Pages
 * - Published Update Pages
 *
 * STRICTLY EXCLUDES:
 * - drafts, scheduled, or unpublished updates
 * - admin pages or private URLs
 * - subscriber or campaign entities
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: application/xml; charset=utf-8');
}

$baseUrl = pepp_seo_get_base_url();
$driver  = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$nowSql  = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <!-- Homepage -->
    <url>
        <loc><?php echo htmlspecialchars($baseUrl . '/'); ?></loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    <!-- Categories Index -->
    <url>
        <loc><?php echo htmlspecialchars($baseUrl . '/categories'); ?></loc>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>

    <!-- Active Categories -->
    <?php
    try {
        $stmtCats = $pdo->query("SELECT slug, updated_at FROM updates_categories WHERE is_active = 1 ORDER BY display_order ASC");
        while ($cat = $stmtCats->fetch(PDO::FETCH_ASSOC)) {
            $catUrl = $baseUrl . pepp_public_url('category', $cat['slug']);
            $lastmod = !empty($cat['updated_at']) ? date('c', strtotime($cat['updated_at'])) : date('c');
            ?>
    <url>
        <loc><?php echo htmlspecialchars($catUrl); ?></loc>
        <lastmod><?php echo htmlspecialchars($lastmod); ?></lastmod>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>
            <?php
        }
    } catch (Throwable $e) {
        // Silently skip if query fails
    }
    ?>

    <!-- Published Updates -->
    <?php
    try {
        $sqlPosts = "
            SELECT slug, updated_at, publish_at 
            FROM updates_posts 
            WHERE status = 'published' 
              AND publish_at IS NOT NULL 
              AND publish_at <= {$nowSql} 
            ORDER BY publish_at DESC
        ";
        $stmtPosts = $pdo->query($sqlPosts);
        while ($post = $stmtPosts->fetch(PDO::FETCH_ASSOC)) {
            $postUrl = $baseUrl . pepp_public_url('update', $post['slug']);
            $lastmod = !empty($post['updated_at']) ? date('c', strtotime($post['updated_at'])) : date('c', strtotime($post['publish_at']));
            ?>
    <url>
        <loc><?php echo htmlspecialchars($postUrl); ?></loc>
        <lastmod><?php echo htmlspecialchars($lastmod); ?></lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.9</priority>
    </url>
            <?php
        }
    } catch (Throwable $e) {
        // Silently skip if query fails
    }
    ?>

    <!-- Legal Pages -->
    <url>
        <loc><?php echo htmlspecialchars($baseUrl . '/privacy'); ?></loc>
        <changefreq>monthly</changefreq>
        <priority>0.3</priority>
    </url>
    <url>
        <loc><?php echo htmlspecialchars($baseUrl . '/terms'); ?></loc>
        <changefreq>monthly</changefreq>
        <priority>0.3</priority>
    </url>
</urlset>
