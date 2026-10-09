<?php
/**
 * PEPP Updates — Admin Dashboard
 * 
 * Displays core metrics, quick actions, recent updates, and delivery campaign status.
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates Dashboard';
$page_sub = 'Real-time overview of career, exam & admission updates, subscribers and delivery campaigns';
$active_page = 'pepp-updates';

$tables_exist = pepp_updates_tables_exist($pdo);
$stats = pepp_updates_stats($pdo);
$new_duration = (int)pepp_updates_get_setting($pdo, 'new_label_duration_days', 7);

// Fetch recent updates
$recent_posts = [];
$recent_campaigns = [];

if ($tables_exist) {
    try {
        $stmt = $pdo->query("
            SELECT p.*,
                   GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS categories_list
            FROM updates_posts p
            LEFT JOIN updates_post_categories upc ON upc.post_id = p.id
            LEFT JOIN updates_categories c ON c.id = upc.category_id
            GROUP BY p.id
            ORDER BY p.id DESC
            LIMIT 6
        ");
        $recent_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt_camp = $pdo->query("
            SELECT c.*, cat.name AS category_name, p.title AS post_title
            FROM updates_delivery_campaigns c
            LEFT JOIN updates_categories cat ON cat.id = c.target_category_id
            LEFT JOIN updates_posts p ON p.id = c.post_id
            ORDER BY c.id DESC
            LIMIT 5
        ");
        $recent_campaigns = $stmt_camp->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('pepp-updates dashboard query error: ' . $e->getMessage());
    }
}

include 'includes/admin_nav.php';
?>

<?php if (!$tables_exist): ?>
    <div class="alert alert-warn" style="margin-bottom: 24px;">
        <i class="fas fa-triangle-exclamation" style="font-size: 1.2rem;"></i>
        <div>
            <strong>PEPP Updates Database Schema Not Detected</strong><br>
            The updates_* tables have not been created yet. Please apply database migration 64 (<code>database-update-64-pepp-updates-module.sql</code>).
        </div>
    </div>
<?php endif; ?>

<!-- Quick Actions Bar -->
<div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 22px;">
    <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
        <a href="pepp-updates-posts.php?action=create" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Update
        </a>
        <a href="pepp-updates-categories.php" class="btn btn-outline">
            <i class="fas fa-folder-tree"></i> Manage Categories
        </a>
        <a href="pepp-updates-keywords.php" class="btn btn-outline">
            <i class="fas fa-tags"></i> Manage Keywords
        </a>
        <a href="pepp-updates-subscribers.php" class="btn btn-outline">
            <i class="fas fa-users-viewfinder"></i> Subscribers
        </a>
        <a href="pepp-updates-campaigns.php" class="btn btn-outline">
            <i class="fas fa-paper-plane"></i> Delivery Campaigns
        </a>
    </div>
    <div>
        <a href="pepp-updates-settings.php" class="btn btn-outline" title="Module Settings">
            <i class="fas fa-sliders"></i> Settings
        </a>
    </div>
</div>

<!-- 9 Real-time Metric Cards -->
<div class="stats-grid">
    <!-- 1. Total Updates -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Total Updates</span>
            <div class="stat-icon violet"><i class="fas fa-newspaper"></i></div>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_updates']); ?></div>
        <div class="stat-hint"><a href="pepp-updates-posts.php">View all posts &rarr;</a></div>
    </div>

    <!-- 2. Published -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Published</span>
            <div class="stat-icon green"><i class="fas fa-circle-check"></i></div>
        </div>
        <div class="stat-value" style="color: var(--green-ink);"><?php echo number_format($stats['published']); ?></div>
        <div class="stat-hint"><a href="pepp-updates-posts.php?status=published">Live updates &rarr;</a></div>
    </div>

    <!-- 3. Draft -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Drafts</span>
            <div class="stat-icon amber"><i class="fas fa-pen-to-square"></i></div>
        </div>
        <div class="stat-value" style="color: var(--amber-ink);"><?php echo number_format($stats['draft']); ?></div>
        <div class="stat-hint"><a href="pepp-updates-posts.php?status=draft">Pending review &rarr;</a></div>
    </div>

    <!-- 4. Scheduled -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Scheduled</span>
            <div class="stat-icon blue"><i class="fas fa-clock"></i></div>
        </div>
        <div class="stat-value" style="color: var(--blue-ink);"><?php echo number_format($stats['scheduled']); ?></div>
        <div class="stat-hint"><a href="pepp-updates-posts.php?status=scheduled">Future releases &rarr;</a></div>
    </div>

    <!-- 5. Expired -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Expired</span>
            <div class="stat-icon red"><i class="fas fa-clock-rotate-left"></i></div>
        </div>
        <div class="stat-value" style="color: var(--red-ink);"><?php echo number_format($stats['expired']); ?></div>
        <div class="stat-hint"><a href="pepp-updates-posts.php?status=expired">Archived / ended &rarr;</a></div>
    </div>

    <!-- 6. Total Views -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Total Views</span>
            <div class="stat-icon teal"><i class="fas fa-eye"></i></div>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_views']); ?></div>
        <div class="stat-hint">Hashed privacy analytics</div>
    </div>

    <!-- 7. Active WhatsApp Subscribers -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Active Subscribers</span>
            <div class="stat-icon green"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-value" style="color: var(--green-ink);"><?php echo number_format($stats['active_subscribers']); ?></div>
        <div class="stat-hint"><a href="pepp-updates-subscribers.php?status=active">Opted-in WhatsApp recipients &rarr;</a></div>
    </div>

    <!-- 8. Stopped / Suppressed Subscribers -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Stopped / Suppressed</span>
            <div class="stat-icon amber"><i class="fas fa-user-slash"></i></div>
        </div>
        <div class="stat-value" style="color: var(--secondary);"><?php echo number_format($stats['stopped_subscribers']); ?></div>
        <div class="stat-hint"><a href="pepp-updates-subscribers.php?status=stopped">Opt-out audit preserved &rarr;</a></div>
    </div>

    <!-- 9. Delivery Campaigns -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Delivery Campaigns</span>
            <div class="stat-icon pink"><i class="fas fa-paper-plane"></i></div>
        </div>
        <div class="stat-value"><?php echo number_format($stats['delivery_campaigns']); ?></div>
        <div class="stat-hint"><a href="pepp-updates-campaigns.php">Account 3 broadcasts &rarr;</a></div>
    </div>
</div>

<!-- Two-Column Layout: Recent Posts & Recent Campaigns -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 20px;">

    <!-- Recent Updates Panel -->
    <div class="panel">
        <div class="panel-head">
            <div class="head-icon"><i class="fas fa-newspaper"></i></div>
            <h2>Recent Updates</h2>
            <div class="head-right">
                <a href="pepp-updates-posts.php" class="btn btn-sm btn-outline">View All</a>
            </div>
        </div>
        <div class="panel-body flush">
            <?php if (empty($recent_posts)): ?>
                <div class="empty-state">
                    <i class="fas fa-newspaper"></i>
                    <p>No updates created yet.</p>
                    <a href="pepp-updates-posts.php?action=create" class="btn btn-sm btn-primary" style="margin-top: 10px;">
                        <i class="fas fa-plus"></i> Create First Update
                    </a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Update</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Published</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_posts as $post): ?>
                                <?php
                                $eff_status = pepp_updates_effective_status($post['status'], $post['publish_at'], $post['expires_at']);
                                $is_new = pepp_updates_is_new($post['publish_at'], $post['expires_at'], $new_duration);
                                $badge_class = 'gray';
                                if ($eff_status === 'published') $badge_class = 'green';
                                elseif ($eff_status === 'draft') $badge_class = 'amber';
                                elseif ($eff_status === 'scheduled') $badge_class = 'blue';
                                elseif ($eff_status === 'expired') $badge_class = 'red';
                                ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <?php if (!empty($post['banner_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($post['banner_image']); ?>" alt="" style="width: 38px; height: 38px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border);">
                                            <?php else: ?>
                                                <div style="width: 38px; height: 38px; border-radius: 6px; background: var(--card); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; color: var(--muted-foreground); font-size: 0.85rem;">
                                                    <i class="fas fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="cell-main" style="max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <?php echo htmlspecialchars($post['title']); ?>
                                                </div>
                                                <div class="cell-sub">
                                                    <?php if ($is_new): ?>
                                                        <span class="badge violet" style="font-size: 0.62rem; padding: 1px 6px;">NEW</span>
                                                    <?php endif; ?>
                                                    <?php echo !empty($post['created_by']) ? htmlspecialchars($post['created_by']) : 'Admin'; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="cell-sub">
                                            <?php echo !empty($post['categories_list']) ? htmlspecialchars($post['categories_list']) : '<em>None</em>'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo strtoupper($eff_status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="cell-sub">
                                            <?php echo !empty($post['publish_at']) ? date('d M Y, h:i A', strtotime($post['publish_at'])) : '—'; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="pepp-updates-posts.php?action=edit&id=<?php echo (int)$post['id']; ?>" class="btn btn-sm btn-outline" title="Edit">
                                            <i class="fas fa-pen"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Delivery Campaigns Panel -->
    <div class="panel">
        <div class="panel-head">
            <div class="head-icon"><i class="fas fa-paper-plane"></i></div>
            <h2>Delivery Campaigns</h2>
            <div class="head-right">
                <a href="pepp-updates-campaigns.php" class="btn btn-sm btn-outline">View All</a>
            </div>
        </div>
        <div class="panel-body flush">
            <?php if (empty($recent_campaigns)): ?>
                <div class="empty-state">
                    <i class="fas fa-paper-plane"></i>
                    <p>No broadcast delivery campaigns created yet.</p>
                    <a href="pepp-updates-campaigns.php?action=create" class="btn btn-sm btn-primary" style="margin-top: 10px;">
                        <i class="fas fa-plus"></i> Prepare Campaign
                    </a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Scheduled</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_campaigns as $camp): ?>
                                <?php
                                $camp_badge = 'gray';
                                if ($camp['status'] === 'completed') $camp_badge = 'green';
                                elseif ($camp['status'] === 'processing') $camp_badge = 'blue';
                                elseif ($camp['status'] === 'scheduled') $camp_badge = 'violet';
                                elseif ($camp['status'] === 'draft') $camp_badge = 'amber';
                                elseif ($camp['status'] === 'failed' || $camp['status'] === 'cancelled') $camp_badge = 'red';
                                ?>
                                <tr>
                                    <td>
                                        <div class="cell-main" style="max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($camp['title']); ?>
                                        </div>
                                        <div class="cell-sub">
                                            Tpl: <code><?php echo htmlspecialchars($camp['template_name']); ?></code> &bull; Sender: Account 3
                                        </div>
                                    </td>
                                    <td>
                                        <span class="cell-sub">
                                            <?php echo !empty($camp['category_name']) ? htmlspecialchars($camp['category_name']) : 'All Subscribers'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $camp_badge; ?>">
                                            <?php echo strtoupper($camp['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="cell-sub">
                                            <?php echo !empty($camp['scheduled_at']) ? date('d M Y, h:i A', strtotime($camp['scheduled_at'])) : '—'; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="pepp-updates-campaigns.php" class="btn btn-sm btn-outline">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php
include 'includes/admin_footer.php';
?>
