<?php
/**
 * PEPP Updates - Ad Leads Management
 *
 * Central administration and inspection of student leads captured from popup ads:
 * - Search by student name, phone, or ad title
 * - Filter by ad campaign, conversion status, and date range
 * - Inspect complete submitted field payloads (JSON)
 * - Review CRM conversion status, linked CRM lead IDs, and error/duplicate notes
 * - Safe pagination with lightweight count queries
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates - Ad Leads';
$page_sub = 'Inspect and review admission leads captured from public portal popup ads';
$active_page = 'pepp-updates-ad-leads';

// Filter inputs
$search      = trim($_GET['search'] ?? '');
$adFilter    = !empty($_GET['ad_id']) ? (int)$_GET['ad_id'] : 0;
$statusFilter= trim($_GET['status'] ?? '');
$dateFrom    = trim($_GET['date_from'] ?? '');
$dateTo      = trim($_GET['date_to'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = 25;

// Load statistics
$stats = pepp_updates_get_ad_lead_stats($pdo);

// Load ads for filter dropdown
$allAds = pepp_updates_get_ads($pdo);

// Load paginated leads
$leadsData = pepp_updates_get_ad_leads($pdo, [
    'search'    => $search,
    'ad_id'     => $adFilter,
    'status'    => $statusFilter,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
], $page, $limit);

$leads      = $leadsData['leads'];
$totalLeads = $leadsData['total'];
$totalPages = $leadsData['pages'];

include 'includes/admin_nav.php';
?>

<div class="pepp-container" style="padding: 20px 24px; max-width: 1400px; margin: 0 auto;">

    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="font-size: 1.4rem; font-weight: 800; color: var(--foreground); margin-bottom: 4px;">
                Captured Ad Leads
            </h2>
            <div style="font-size: 0.85rem; color: var(--secondary);">
                Inquiries captured from high-intent students reading PEPP Updates articles.
            </div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="pepp-updates-ads.php" class="btn btn-outline">
                <i class="fas fa-rectangle-ad"></i> Manage Popup Ads
            </a>
            <a href="lead-management.php" class="btn btn-outline">
                <i class="fas fa-user-group"></i> Open CRM Leads
            </a>
        </div>
    </div>

    <!-- Metric Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 22px;">
        <div class="panel" style="padding: 16px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
            <div style="font-size: 0.8rem; color: var(--secondary); font-weight: 600;">Total Ad Leads</div>
            <div style="font-size: 1.8rem; font-weight: 800; color: var(--foreground); margin-top: 4px;"><?php echo (int)$stats['total']; ?></div>
        </div>
        <div class="panel" style="padding: 16px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
            <div style="font-size: 0.8rem; color: var(--secondary); font-weight: 600;">New / Pending Review</div>
            <div style="font-size: 1.8rem; font-weight: 800; color: #f59e0b; margin-top: 4px;"><?php echo (int)$stats['new']; ?></div>
        </div>
        <div class="panel" style="padding: 16px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
            <div style="font-size: 0.8rem; color: var(--secondary); font-weight: 600;">Converted to CRM</div>
            <div style="font-size: 1.8rem; font-weight: 800; color: #10b981; margin-top: 4px;"><?php echo (int)$stats['converted']; ?></div>
        </div>
        <div class="panel" style="padding: 16px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
            <div style="font-size: 0.8rem; color: var(--secondary); font-weight: 600;">Failed / Blocked</div>
            <div style="font-size: 1.8rem; font-weight: 800; color: #ef4444; margin-top: 4px;"><?php echo (int)($stats['failed'] + $stats['blocked']); ?></div>
        </div>
    </div>

    <!-- Filters Panel -->
    <div class="panel" style="margin-bottom: 20px; padding: 14px 18px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
        <form method="GET" action="pepp-updates-ad-leads.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">

            <div style="flex: 2; min-width: 220px;">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search student name, phone, or ad title..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.86rem;">
            </div>

            <div style="flex: 1; min-width: 180px;">
                <select name="ad_id" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.86rem;">
                    <option value="">All Popup Ads</option>
                    <?php foreach ($allAds as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>" <?php echo ($adFilter === (int)$a['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <select name="status" style="padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.86rem;">
                    <option value="">All Statuses</option>
                    <option value="new" <?php echo ($statusFilter === 'new') ? 'selected' : ''; ?>>New</option>
                    <option value="converted" <?php echo ($statusFilter === 'converted') ? 'selected' : ''; ?>>Converted</option>
                    <option value="failed" <?php echo ($statusFilter === 'failed') ? 'selected' : ''; ?>>Failed</option>
                    <option value="blocked" <?php echo ($statusFilter === 'blocked') ? 'selected' : ''; ?>>Blocked</option>
                </select>
            </div>

            <div style="display: flex; align-items: center; gap: 6px;">
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" placeholder="From" style="padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.84rem;">
                <span style="color: var(--secondary);">&ndash;</span>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" placeholder="To" style="padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.84rem;">
            </div>

            <button type="submit" class="btn btn-outline" style="padding: 8px 16px;">
                <i class="fas fa-filter"></i> Filter
            </button>

            <?php if ($search !== '' || $adFilter !== 0 || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                <a href="pepp-updates-ad-leads.php" class="btn btn-outline" title="Clear Filters">
                    <i class="fas fa-rotate-left"></i>
                </a>
            <?php endif; ?>

        </form>
    </div>

    <!-- Leads Table -->
    <div class="panel" style="background: #fff; border: 1px solid var(--border); border-radius: 10px; overflow: hidden;">
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--border); text-align: left;">
                        <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Date &amp; Time</th>
                        <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Student Details</th>
                        <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Originating Ad &amp; Post</th>
                        <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Target Course</th>
                        <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Status</th>
                        <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">CRM Lead</th>
                        <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary); text-align: right;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leads)): ?>
                        <tr>
                            <td colspan="7" style="padding: 36px 16px; text-align: center; color: var(--secondary);">
                                <div style="font-size: 1.1rem; font-weight: 600; margin-bottom: 6px;">No captured leads found</div>
                                <div style="font-size: 0.85rem;">Active popup ads on updates.pepplearning.in will populate incoming student leads here.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leads as $lead):
                            $status = $lead['status'];
                            $badgeClass = ($status === 'converted') ? 'green' : (($status === 'new') ? 'orange' : 'red');
                            $submittedJson = json_decode($lead['submitted_data'] ?? '{}', true) ?: [];
                        ?>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 12px 16px; white-space: nowrap; color: var(--secondary); font-size: 0.82rem;">
                                    <?php echo date('d M Y, h:i A', strtotime($lead['created_at'])); ?>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <div style="font-weight: 700; color: var(--foreground); margin-bottom: 2px;">
                                        <?php echo htmlspecialchars($lead['name']); ?>
                                    </div>
                                    <a href="tel:<?php echo htmlspecialchars($lead['phone']); ?>" style="color: var(--primary); text-decoration: none; font-size: 0.84rem; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-phone" style="font-size: 0.72rem;"></i> <?php echo htmlspecialchars($lead['phone']); ?>
                                    </a>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <div style="font-weight: 600; color: var(--foreground); margin-bottom: 2px;">
                                        <?php echo htmlspecialchars($lead['ad_title'] ?? 'Deleted Ad'); ?>
                                    </div>
                                    <?php if (!empty($lead['post_title'])): ?>
                                        <div style="font-size: 0.78rem; color: var(--secondary);">
                                            Post: <?php echo htmlspecialchars($lead['post_title']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <?php if (!empty($lead['mapped_course_name'])): ?>
                                        <span class="badge green" style="font-size: 0.75rem;">
                                            <?php echo htmlspecialchars($lead['mapped_course_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--secondary); font-size: 0.8rem;">General Inquiry</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <span class="badge <?php echo $badgeClass; ?>" style="font-size: 0.75rem; text-transform: uppercase;">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <?php if (!empty($lead['converted_lead_id'])): ?>
                                        <a href="lead-details.php?id=<?php echo (int)$lead['converted_lead_id']; ?>" class="badge blue" style="text-decoration: none; font-size: 0.75rem;" title="View CRM Lead Record">
                                            <i class="fas fa-arrow-up-right-from-square"></i> Lead #<?php echo (int)$lead['converted_lead_id']; ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--secondary); font-size: 0.8rem;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 16px; text-align: right;">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleDetailsRow(<?php echo (int)$lead['id']; ?>)">
                                        <i class="fas fa-chevron-down"></i> Details
                                    </button>
                                </td>
                            </tr>

                            <!-- Expandable Details Row -->
                            <tr id="details-row-<?php echo (int)$lead['id']; ?>" style="display: none; background: #f8fafc; border-bottom: 2px solid var(--border);">
                                <td colspan="7" style="padding: 16px 20px;">
                                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                                        <div>
                                            <div style="font-size: 0.82rem; font-weight: 700; color: var(--secondary); text-transform: uppercase; margin-bottom: 8px;">
                                                Submitted Form Fields
                                            </div>
                                            <?php if (empty($submittedJson)): ?>
                                                <div style="font-size: 0.85rem; color: var(--secondary);">No extra fields submitted.</div>
                                            <?php else: ?>
                                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                                                    <?php foreach ($submittedJson as $key => $val): ?>
                                                        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; font-size: 0.84rem;">
                                                            <div style="color: var(--secondary); font-size: 0.75rem; text-transform: capitalize; margin-bottom: 2px;">
                                                                <?php echo htmlspecialchars(str_replace('_', ' ', $key)); ?>
                                                            </div>
                                                            <div style="font-weight: 600; color: var(--foreground); word-break: break-word;">
                                                                <?php echo htmlspecialchars(is_array($val) ? json_encode($val) : (string)$val); ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div style="border-left: 1px solid #e2e8f0; padding-left: 16px; font-size: 0.82rem;">
                                            <div style="font-size: 0.82rem; font-weight: 700; color: var(--secondary); text-transform: uppercase; margin-bottom: 8px;">
                                                Security &amp; Conversion Audit
                                            </div>
                                            <div style="margin-bottom: 6px;">
                                                <span style="color: var(--secondary);">Salted IP Hash:</span>
                                                <code style="font-size: 0.75rem;"><?php echo htmlspecialchars($lead['ip_hash'] ?? 'N/A'); ?></code>
                                            </div>
                                            <?php if (!empty($lead['conversion_attempted_at'])): ?>
                                                <div style="margin-bottom: 6px;">
                                                    <span style="color: var(--secondary);">Conversion Attempted:</span>
                                                    <?php echo date('d M Y, h:i A', strtotime($lead['conversion_attempted_at'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($lead['conversion_notes'])): ?>
                                                <div style="margin-top: 8px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px;">
                                                    <span style="color: var(--secondary); font-size: 0.75rem; font-weight: 600;">Log / Notes:</span>
                                                    <div style="color: var(--foreground); font-size: 0.8rem; margin-top: 2px;">
                                                        <?php echo htmlspecialchars($lead['conversion_notes']); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="padding: 12px 16px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                <div style="color: var(--secondary);">
                    Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalLeads; ?> total records)
                </div>
                <div style="display: flex; gap: 6px;">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-sm btn-outline">&larr; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-sm btn-outline">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function toggleDetailsRow(id) {
    const row = document.getElementById('details-row-' + id);
    if (!row) return;
    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
}
</script>
