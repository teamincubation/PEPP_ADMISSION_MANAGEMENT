<?php
/**
 * PEPP Updates — Delivery Campaigns Management
 * 
 * Manage and prepare WhatsApp broadcast campaigns for updates.
 * 
 * HARD SECURITY ISOLATION:
 * PEPP Updates belongs EXCLUSIVELY to Account 3 (ID: 3, +91 79943 04400).
 * Server-side validation strictly enforces sender_account_id = 3 and rejects Account 1.
 * 
 * PHASE 2 SAFETY BOUNDARY:
 * Management & staging only. ZERO Meta API calls, ZERO queueing, ZERO message dispatch.
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates — Delivery Campaigns';
$page_sub = 'Prepare and schedule WhatsApp broadcast campaigns exclusively on Account 3 (+91 79943 04400)';
$active_page = 'pepp-updates-campaigns';

$flash_success = '';
$flash_error = '';

$action = $_GET['action'] ?? 'list';

// Fetch active categories and posts for dropdowns
$categories = [];
$posts = [];
if (pepp_updates_tables_exist($pdo)) {
    try {
        $stmt_c = $pdo->query("SELECT id, name FROM updates_categories ORDER BY display_order ASC, name ASC");
        $categories = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

        $stmt_p = $pdo->query("SELECT id, title, status FROM updates_posts ORDER BY id DESC LIMIT 100");
        $posts = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────
// POST SUBMISSIONS (Create / Edit Campaign)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['campaign_submit'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF).';
    } else {
        $camp_id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $post_id = (int)($_POST['post_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $template_name = trim($_POST['template_name'] ?? '');
        $target_cat_id = !empty($_POST['target_category_id']) ? (int)$_POST['target_category_id'] : null;
        $scheduled_at = !empty($_POST['scheduled_at']) ? date('Y-m-d H:i:s', strtotime($_POST['scheduled_at'])) : null;
        $sender_account_id = isset($_POST['sender_account_id']) ? (int)$_POST['sender_account_id'] : 3;

        // CRITICAL ACCOUNT 3 HARD VALIDATION (Steps 14 & 15)
        if (!pepp_updates_validate_campaign_sender($sender_account_id)) {
            $flash_error = "SECURITY VIOLATION: PEPP Updates broadcast campaigns strictly belong to Account 3. Sender Account ID {$sender_account_id} was rejected.";
        } elseif ($post_id <= 0) {
            $flash_error = 'Please select a valid update post to broadcast.';
        } elseif ($title === '') {
            $flash_error = 'Campaign title is required.';
        } elseif ($template_name === '') {
            $flash_error = 'WhatsApp template name is required.';
        } else {
            // Verify post exists
            $stmt_post_chk = $pdo->prepare("SELECT id, title FROM updates_posts WHERE id = ?");
            $stmt_post_chk->execute([$post_id]);
            if (!$stmt_post_chk->fetch()) {
                $flash_error = 'Selected update post does not exist.';
            } else {
                try {
                    $status = !empty($scheduled_at) && strtotime($scheduled_at) > time() ? 'scheduled' : 'draft';

                    if ($camp_id) {
                        $stmt = $pdo->prepare("
                            UPDATE updates_delivery_campaigns SET
                                post_id = ?,
                                title = ?,
                                template_name = ?,
                                target_category_id = ?,
                                sender_account_id = 3,
                                status = ?,
                                scheduled_at = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $post_id,
                            $title,
                            $template_name,
                            $target_cat_id,
                            $status,
                            $scheduled_at,
                            $camp_id
                        ]);
                        $flash_success = "Delivery campaign #{$camp_id} updated successfully.";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO updates_delivery_campaigns (
                                post_id, title, template_name, target_category_id,
                                sender_account_id, status, scheduled_at, created_by, created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?,
                                3, ?, ?, ?, NOW(), NOW()
                            )
                        ");
                        $stmt->execute([
                            $post_id,
                            $title,
                            $template_name,
                            $target_cat_id,
                            $status,
                            $scheduled_at,
                            $admin_username
                        ]);
                        $new_id = (int)$pdo->lastInsertId();
                        $flash_success = "Delivery campaign #{$new_id} created successfully on Account 3 (Staged).";
                    }
                    $action = 'list';
                } catch (Throwable $e) {
                    error_log('Delivery campaign save error: ' . $e->getMessage());
                    $flash_error = 'Failed to save delivery campaign: ' . $e->getMessage();
                }
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// CANCEL / DELETE DRAFT CAMPAIGN
// ─────────────────────────────────────────────────────────────
if ($action === 'cancel' && isset($_GET['id']) && isset($_GET['csrf_token'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token.';
    } else {
        $camp_id = (int)$_GET['id'];
        try {
            $pdo->prepare("UPDATE updates_delivery_campaigns SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status IN ('draft', 'scheduled')")
                ->execute([$camp_id]);
            $flash_success = "Campaign #{$camp_id} was cancelled.";
        } catch (Throwable $e) {
            $flash_error = 'Error cancelling campaign: ' . $e->getMessage();
        }
    }
    $action = 'list';
}

if ($action === 'delete' && isset($_GET['id']) && isset($_GET['csrf_token'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token.';
    } else {
        $camp_id = (int)$_GET['id'];
        try {
            $pdo->prepare("DELETE FROM updates_delivery_campaigns WHERE id = ? AND status IN ('draft', 'cancelled')")
                ->execute([$camp_id]);
            $flash_success = "Campaign #{$camp_id} deleted successfully.";
        } catch (Throwable $e) {
            $flash_error = 'Error deleting campaign: ' . $e->getMessage();
        }
    }
    $action = 'list';
}

// ─────────────────────────────────────────────────────────────
// LIST CAMPAIGNS
// ─────────────────────────────────────────────────────────────
$campaigns = [];
if (pepp_updates_tables_exist($pdo)) {
    try {
        $sql = "
            SELECT c.*, p.title AS post_title, cat.name AS category_name
            FROM updates_delivery_campaigns c
            LEFT JOIN updates_posts p ON p.id = c.post_id
            LEFT JOIN updates_categories cat ON cat.id = c.target_category_id
            ORDER BY c.id DESC
            LIMIT 50
        ";
        $stmt = $pdo->query($sql);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Delivery campaign list error: ' . $e->getMessage());
    }
}

include 'includes/admin_nav.php';
?>

<?php if ($flash_success): ?>
    <div class="alert alert-success">
        <i class="fas fa-circle-check"></i>
        <span><?php echo htmlspecialchars($flash_success); ?></span>
    </div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($flash_error); ?></span>
    </div>
<?php endif; ?>

<!-- Phase 2 Informational Safety Banner -->
<div class="alert alert-info" style="margin-bottom: 22px;">
    <i class="fas fa-shield-halved" style="font-size: 1.25rem;"></i>
    <div>
        <strong>Phase 2 Delivery Campaign Management (Staging Mode)</strong><br>
        Campaigns created and managed here are strictly assigned to <strong>Account 3 (+91 79943 04400)</strong>. 
        In Phase 2, no WhatsApp messages or Meta APIs are triggered. Actual delivery dispatching will be enabled in Phase 4 via the isolated dispatcher.
    </div>
</div>

<div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 4px;">Delivery Campaigns (Account 3)</h2>
        <div style="font-size: 0.8rem; color: var(--muted-foreground);">
            Staged broadcast campaigns for WhatsApp subscribers following specific categories.
        </div>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openAddCampaignModal()">
            <i class="fas fa-plus"></i> Prepare New Broadcast Campaign
        </button>
    </div>
</div>

<!-- Campaigns Table -->
<div class="panel">
    <div class="panel-body flush">
        <?php if (empty($campaigns)): ?>
            <div class="empty-state">
                <i class="fas fa-paper-plane"></i>
                <p>No broadcast delivery campaigns created yet.</p>
                <button type="button" class="btn btn-sm btn-primary" onclick="openAddCampaignModal()" style="margin-top: 10px;">
                    <i class="fas fa-plus"></i> Prepare First Campaign
                </button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Linked Update Post</th>
                            <th>Template</th>
                            <th>Target Category</th>
                            <th>Sender Account</th>
                            <th>Status</th>
                            <th>Scheduled Date</th>
                            <th>Metrics</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $c): ?>
                            <?php
                            $c_badge = 'gray';
                            if ($c['status'] === 'completed') $c_badge = 'green';
                            elseif ($c['status'] === 'processing') $c_badge = 'blue';
                            elseif ($c['status'] === 'scheduled') $c_badge = 'violet';
                            elseif ($c['status'] === 'draft') $c_badge = 'amber';
                            elseif ($c['status'] === 'failed' || $c['status'] === 'cancelled') $c_badge = 'red';
                            ?>
                            <tr>
                                <td>
                                    <div class="cell-main"><?php echo htmlspecialchars($c['title']); ?></div>
                                    <div class="cell-sub">ID: #<?php echo (int)$c['id']; ?> &bull; By: <?php echo htmlspecialchars($c['created_by'] ?? 'Admin'); ?></div>
                                </td>
                                <td>
                                    <span class="cell-sub" style="max-width: 200px; display: inline-block;">
                                        <a href="pepp-updates-posts.php?action=edit&id=<?php echo (int)$c['post_id']; ?>">
                                            #<?php echo (int)$c['post_id']; ?>: <?php echo htmlspecialchars($c['post_title'] ?? 'Post #' . $c['post_id']); ?>
                                        </a>
                                    </span>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($c['template_name']); ?></code>
                                </td>
                                <td>
                                    <span class="cell-sub">
                                        <?php echo !empty($c['category_name']) ? htmlspecialchars($c['category_name']) : '<em>All Subscribers</em>'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge violet" style="font-weight: 700;">
                                        Account 3 (+91 79943 04400)
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $c_badge; ?>">
                                        <?php echo strtoupper($c['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="cell-sub">
                                        <?php echo !empty($c['scheduled_at']) ? date('d M Y, h:i A', strtotime($c['scheduled_at'])) : 'Immediate (Upon dispatch)'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 0.76rem; display: flex; flex-direction: column; gap: 2px;">
                                        <span>Total: <strong><?php echo (int)$c['total_recipients']; ?></strong></span>
                                        <span style="color: var(--green-ink);">Sent: <?php echo (int)$c['sent_count']; ?></span>
                                        <?php if ((int)$c['failed_count'] > 0): ?>
                                            <span style="color: var(--red-ink);">Failed: <?php echo (int)$c['failed_count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px; align-items: center;">
                                        <?php if ($c['status'] === 'draft' || $c['status'] === 'scheduled'): ?>
                                            <a href="pepp-updates-campaigns.php?action=cancel&id=<?php echo (int)$c['id']; ?>&csrf_token=<?php echo csrf_token(); ?>" 
                                               class="btn btn-sm btn-soft-amber" title="Cancel Campaign"
                                               onclick="return confirm('Cancel delivery campaign #<?php echo (int)$c['id']; ?>?');">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($c['status'] === 'draft' || $c['status'] === 'cancelled'): ?>
                                            <a href="pepp-updates-campaigns.php?action=delete&id=<?php echo (int)$c['id']; ?>&csrf_token=<?php echo csrf_token(); ?>" 
                                               class="btn btn-sm btn-soft-red" title="Delete Staged Campaign"
                                               onclick="return confirm('Delete staged campaign #<?php echo (int)$c['id']; ?>?');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Prepare Delivery Campaign Modal -->
<div class="modal-backdrop" id="campaignModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-box" style="background: var(--surface); border-radius: 14px; max-width: 540px; width: 100%; box-shadow: 0 20px 45px rgba(0,0,0,0.2); border: 1px solid var(--border);">
        <form method="POST" action="pepp-updates-campaigns.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="campaign_submit" value="1">
            <input type="hidden" name="id" id="campModalId" value="">
            <!-- Hard-locked to Account 3 -->
            <input type="hidden" name="sender_account_id" value="3">

            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="head-icon"><i class="fas fa-paper-plane"></i></div>
                    <h3 id="campModalTitle" style="margin: 0; font-size: 1.05rem; font-weight: 700;">Prepare Broadcast Campaign</h3>
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="closeCampaignModal()" style="font-size: 1rem; padding: 4px 10px;">&times;</button>
            </div>

            <div class="modal-body" style="padding: 20px;">
                <div class="field" style="margin-bottom: 14px;">
                    <label>Update Post to Broadcast <span class="req">*</span></label>
                    <select name="post_id" id="campModalPost" required style="width: 100%;">
                        <option value="">-- Select Update Post --</option>
                        <?php foreach ($posts as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>">
                                #<?php echo (int)$p['id']; ?>: <?php echo htmlspecialchars($p['title']); ?> (<?php echo strtoupper($p['status']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Campaign Title <span class="req">*</span></label>
                    <input type="text" name="title" id="campModalTitleInput" required placeholder="e.g. Broadcast: CUET PG 2027 Alert">
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>WhatsApp Approved Template Name <span class="req">*</span></label>
                    <input type="text" name="template_name" id="campModalTemplate" required placeholder="e.g. pepp_updates_general_alert">
                    <div class="help">Must correspond to an approved template on Account 3 in Meta Business Manager.</div>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Target Category</label>
                    <select name="target_category_id" id="campModalCategory" style="width: 100%;">
                        <option value="">All Active Subscribers (Broad notification)</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">When selected, only subscribers following this category will receive the broadcast.</div>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Sender WhatsApp Account</label>
                    <input type="text" disabled value="Account 3 (PEPP Updates - +91 79943 04400)" style="background: var(--card); font-weight: 600; color: var(--accent-dark);">
                    <div class="help">Hard-isolated to Account 3. Account 1 is strictly excluded.</div>
                </div>

                <div class="field">
                    <label>Scheduled Broadcast Time (Optional)</label>
                    <input type="datetime-local" name="scheduled_at" id="campModalSched">
                    <div class="help">Leave blank to prepare as Draft for manual dispatch in Phase 4.</div>
                </div>
            </div>

            <div class="modal-foot" style="padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; background: var(--card);">
                <button type="button" class="btn btn-outline" onclick="closeCampaignModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Stage Campaign</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddCampaignModal() {
    document.getElementById('campModalId').value = '';
    document.getElementById('campModalTitleInput').value = '';
    document.getElementById('campModalTemplate').value = 'pepp_updates_alert';
    document.getElementById('campModalPost').value = '';
    document.getElementById('campModalCategory').value = '';
    document.getElementById('campModalSched').value = '';
    document.getElementById('campaignModal').style.display = 'flex';
}

function closeCampaignModal() {
    document.getElementById('campaignModal').style.display = 'none';
}
</script>

<?php
include 'includes/admin_footer.php';
?>
