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
    <div class="stat-card" style="cursor: pointer;" onclick="openViewAnalytics(0, 'All Updates Overview')" title="Click to view detailed visitor analytics">
        <div class="stat-top">
            <span class="stat-label">Total Views</span>
            <div class="stat-icon teal"><i class="fas fa-eye"></i></div>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_views']); ?></div>
        <div class="stat-hint"><span style="color: var(--accent); font-weight: 500;">Detailed analytics &rarr;</span></div>
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

<!-- ─────────────────────────────────────────────────────────────
     VISITOR & VIEW ANALYTICS MODAL (Section D)
     ───────────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="analyticsModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-box" style="background: var(--surface); border-radius: 14px; max-width: 900px; width: 100%; max-height: 88vh; overflow-y: auto; box-shadow: 0 20px 45px rgba(0,0,0,0.2); border: 1px solid var(--border);">
        <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 22px; border-bottom: 1px solid var(--border);">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="head-icon" style="color: var(--accent);"><i class="fas fa-chart-line"></i></div>
                <div>
                    <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700;">Visitor & View Analytics</h3>
                    <div id="analyticsModalSubtitle" style="font-size: 0.8rem; color: var(--secondary);">Loading analytics...</div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeViewAnalytics()" style="font-size: 1rem; padding: 4px 10px;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 22px;">
            <!-- Tabs: Visits vs Clicks -->
            <div style="display: flex; gap: 10px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">
                <button type="button" id="tabVisitsBtn" class="btn btn-sm btn-primary" onclick="switchAnalyticsTab('visits')">
                    <i class="fas fa-eye"></i> Page Visits (<span id="analyticsVisitsCount">0</span>)
                </button>
                <button type="button" id="tabClicksBtn" class="btn btn-sm btn-outline" onclick="switchAnalyticsTab('clicks')">
                    <i class="fas fa-hand-pointer"></i> Tracked Button Clicks (<span id="analyticsClicksCount">0</span>)
                </button>
            </div>

            <!-- Visits Tab Content -->
            <div id="analyticsVisitsTab">
                <div id="analyticsLoading" style="text-align: center; padding: 30px; color: var(--secondary);">
                    <i class="fas fa-circle-notch fa-spin"></i> Loading visitor activity...
                </div>
                <div id="analyticsVisitsEmpty" style="display: none; text-align: center; padding: 30px; color: var(--secondary);">
                    No visitor records found.
                </div>
                <div class="table-wrap" id="analyticsVisitsTableWrap" style="display: none;">
                    <table class="data-table" style="font-size: 0.82rem;">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Update Viewed</th>
                                <th>IP Address</th>
                                <th>Location Access</th>
                                <th>Coordinates / Map</th>
                                <th>Session ID</th>
                            </tr>
                        </thead>
                        <tbody id="analyticsVisitsTbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Clicks Tab Content -->
            <div id="analyticsClicksTab" style="display: none;">
                <div id="analyticsClicksEmpty" style="display: none; text-align: center; padding: 30px; color: var(--secondary);">
                    No button interaction clicks recorded yet.
                </div>
                <div class="table-wrap" id="analyticsClicksTableWrap" style="display: none;">
                    <table class="data-table" style="font-size: 0.82rem;">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Update</th>
                                <th>Action / Button</th>
                                <th>Target / Context</th>
                                <th>Session ID</th>
                            </tr>
                        </thead>
                        <tbody id="analyticsClicksTbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal-foot" style="padding: 12px 22px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--card);">
            <div style="font-size: 0.75rem; color: var(--secondary);">
                <i class="fas fa-shield-halved"></i> Privacy-conscious analytics. Raw coordinates shown only with voluntary user consent.
            </div>
            <button type="button" class="btn btn-outline" onclick="closeViewAnalytics()">Close</button>
        </div>
    </div>
</div>

<script>
function openViewAnalytics(postId, postTitle) {
    var modal = document.getElementById('analyticsModal');
    var subtitle = document.getElementById('analyticsModalSubtitle');
    var loading = document.getElementById('analyticsLoading');
    var emptyV = document.getElementById('analyticsVisitsEmpty');
    var wrapV = document.getElementById('analyticsVisitsTableWrap');
    var tbodyV = document.getElementById('analyticsVisitsTbody');
    var countV = document.getElementById('analyticsVisitsCount');
    var emptyC = document.getElementById('analyticsClicksEmpty');
    var wrapC = document.getElementById('analyticsClicksTableWrap');
    var tbodyC = document.getElementById('analyticsClicksTbody');
    var countC = document.getElementById('analyticsClicksCount');

    subtitle.textContent = postTitle ? postTitle : 'All Updates Overview';
    loading.style.display = 'block';
    emptyV.style.display = 'none';
    wrapV.style.display = 'none';
    emptyC.style.display = 'none';
    wrapC.style.display = 'none';
    tbodyV.innerHTML = '';
    tbodyC.innerHTML = '';
    countV.textContent = '0';
    countC.textContent = '0';
    switchAnalyticsTab('visits');

    modal.style.display = 'flex';

    fetch('pepp-updates-posts.php?action=analytics_data' + (postId ? ('&post_id=' + postId) : ''))
        .then(function(res) { return res.json(); })
        .then(function(data) {
            loading.style.display = 'none';
            if (!data.ok || !data.visits) {
                emptyV.style.display = 'block';
                return;
            }

            var visits = data.visits || [];
            var clicks = data.clicks || [];
            countV.textContent = visits.length;
            countC.textContent = clicks.length;

            if (visits.length === 0) {
                emptyV.style.display = 'block';
            } else {
                wrapV.style.display = 'block';
                visits.forEach(function(v) {
                    var tr = document.createElement('tr');

                    var locHtml = '<span class="badge gray">Not Shared</span>';
                    var mapHtml = '<span style="color:var(--secondary);font-size:0.75rem;">—</span>';
                    if (v.location_status === 'granted' && v.latitude && v.longitude) {
                        locHtml = '<span class="badge green"><i class="fas fa-location-dot"></i> Granted</span>';
                        var lat = parseFloat(v.latitude).toFixed(4);
                        var lng = parseFloat(v.longitude).toFixed(4);
                        var mapsUrl = 'https://www.google.com/maps?q=' + v.latitude + ',' + v.longitude;
                        mapHtml = '<a href="' + mapsUrl + '" target="_blank" class="btn btn-sm btn-outline" style="font-size:0.72rem;padding:2px 7px;display:inline-flex;align-items:center;gap:4px;" title="Open in Google Maps">' +
                                  '<i class="fas fa-map-location-dot" style="color:#ea4335;"></i> ' + lat + ', ' + lng + '</a>';
                    } else if (v.location_status === 'denied') {
                        locHtml = '<span class="badge red"><i class="fas fa-ban"></i> Denied</span>';
                    }

                    var ipDisplay = v.ip_address || (v.ip_hash ? ('Hash: ' + v.ip_hash.substring(0, 10) + '...') : 'Unknown');
                    var sessDisplay = v.session_id ? ('<code>' + v.session_id.substring(0, 8) + '...</code>') : '<span style="color:var(--secondary);">—</span>';

                    tr.innerHTML = '<td>' + (v.created_at || v.visit_date) + '</td>' +
                                   '<td><strong>' + (v.post_title || 'Homepage') + '</strong></td>' +
                                   '<td><code>' + ipDisplay + '</code></td>' +
                                   '<td>' + locHtml + '</td>' +
                                   '<td>' + mapHtml + '</td>' +
                                   '<td>' + sessDisplay + '</td>';
                    tbodyV.appendChild(tr);
                });
            }

            if (clicks.length === 0) {
                emptyC.style.display = 'block';
            } else {
                wrapC.style.display = 'block';
                clicks.forEach(function(c) {
                    var tr = document.createElement('tr');
                    var sessDisplay = c.session_id ? ('<code>' + c.session_id.substring(0, 8) + '...</code>') : '—';
                    var targetDisplay = c.target_url ? ('<a href="' + c.target_url + '" target="_blank" style="font-size:0.75rem;color:var(--accent);">' + c.target_url.substring(0, 30) + '...</a>') : '—';
                    tr.innerHTML = '<td>' + c.created_at + '</td>' +
                                   '<td>' + (c.post_title || 'General') + '</td>' +
                                   '<td><span class="badge blue">' + (c.action_name || c.button_name || 'click') + '</span></td>' +
                                   '<td>' + targetDisplay + '</td>' +
                                   '<td>' + sessDisplay + '</td>';
                    tbodyC.appendChild(tr);
                });
            }
        })
        .catch(function(err) {
            loading.style.display = 'none';
            emptyV.textContent = 'Error loading analytics: ' + err.message;
            emptyV.style.display = 'block';
        });
}

function closeViewAnalytics() {
    document.getElementById('analyticsModal').style.display = 'none';
}

function switchAnalyticsTab(tab) {
    var vTab = document.getElementById('analyticsVisitsTab');
    var cTab = document.getElementById('analyticsClicksTab');
    var vBtn = document.getElementById('tabVisitsBtn');
    var cBtn = document.getElementById('tabClicksBtn');

    if (tab === 'visits') {
        vTab.style.display = 'block';
        cTab.style.display = 'none';
        vBtn.className = 'btn btn-sm btn-primary';
        cBtn.className = 'btn btn-sm btn-outline';
    } else {
        vTab.style.display = 'none';
        cTab.style.display = 'block';
        vBtn.className = 'btn btn-sm btn-outline';
        cBtn.className = 'btn btn-sm btn-primary';
    }
}
</script>

<?php
include 'includes/admin_footer.php';
?>
