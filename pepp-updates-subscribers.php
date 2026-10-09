<?php
/**
 * PEPP Updates — Subscribers Admin Management
 * 
 * View and manage opted-in WhatsApp subscribers, followed categories,
 * and immutable consent & lifecycle events.
 * 
 * PRIVACY COMPLIANT:
 * Zero raw IP, zero user agent, zero email, zero district.
 * 
 * AUDIT IMMUTABILITY:
 * Physical deletion is strictly prohibited. Only soft lifecycle transitions
 * (active, stopped, suppressed, unsubscribed) and event audit logging.
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates — Subscribers';
$page_sub = 'Manage WhatsApp update subscribers, category subscriptions, and consent event audit trail';
$active_page = 'pepp-updates-subscribers';

$flash_success = '';
$flash_error = '';

$action = $_GET['action'] ?? 'list';

// Fetch active categories for dropdowns & subscriber category management
$all_categories = [];
if (pepp_updates_tables_exist($pdo)) {
    try {
        $stmt_cat = $pdo->query("SELECT id, name FROM updates_categories WHERE is_active = 1 ORDER BY display_order ASC, name ASC");
        $all_categories = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────
// UPDATE SUBSCRIBER CATEGORY PREFERENCES
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subscriber_categories'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF).';
    } else {
        $sub_id = (int)($_POST['subscriber_id'] ?? 0);
        $new_cats = isset($_POST['categories']) && is_array($_POST['categories'])
            ? array_map('intval', $_POST['categories'])
            : [];

        try {
            $stmt_sub = $pdo->prepare("SELECT id, name, phone FROM updates_subscribers WHERE id = ?");
            $stmt_sub->execute([$sub_id]);
            $sub = $stmt_sub->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                $flash_error = 'Subscriber not found.';
            } else {
                $pdo->beginTransaction();

                // Delete current subscriptions
                $pdo->prepare("DELETE FROM updates_subscriber_categories WHERE subscriber_id = ?")->execute([$sub_id]);

                // Insert updated subscriptions
                $assigned_names = [];
                if (!empty($new_cats)) {
                    $stmt_ins = $pdo->prepare("INSERT INTO updates_subscriber_categories (subscriber_id, category_id) VALUES (?, ?)");
                    $stmt_get_name = $pdo->prepare("SELECT name FROM updates_categories WHERE id = ?");
                    foreach ($new_cats as $cid) {
                        $stmt_ins->execute([$sub_id, $cid]);
                        $stmt_get_name->execute([$cid]);
                        $cname = $stmt_get_name->fetchColumn();
                        if ($cname) $assigned_names[] = $cname;
                    }
                }

                // Log audit trail event
                $details = 'Admin (' . $admin_username . ') updated categories to: ' . (empty($assigned_names) ? 'None' : implode(', ', $assigned_names));
                pepp_updates_record_subscriber_event($pdo, $sub_id, 'CATEGORY_CHANGED', $details, 'admin');

                $pdo->commit();
                $flash_success = "Categories for subscriber #{$sub_id} updated successfully.";
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Subscriber category update error: ' . $e->getMessage());
            $flash_error = 'Failed to update subscriber categories: ' . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────────────────────
// STATUS LIFECYCLE TRANSITION (Soft Status Change ONLY)
// ─────────────────────────────────────────────────────────────
if ($action === 'change_status' && isset($_GET['id']) && isset($_GET['new_status']) && isset($_GET['csrf_token'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token.';
    } else {
        $sub_id = (int)$_GET['id'];
        $new_status = strtolower(trim($_GET['new_status']));
        $allowed = ['active', 'stopped', 'suppressed'];

        if (!in_array($new_status, $allowed, true)) {
            $flash_error = 'Invalid status transition requested.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, status FROM updates_subscribers WHERE id = ?");
                $stmt->execute([$sub_id]);
                $sub = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($sub) {
                    $stopped_at_sql = ($new_status === 'stopped' || $new_status === 'suppressed') ? "NOW()" : "NULL";
                    $pdo->prepare("
                        UPDATE updates_subscribers SET
                            status = ?,
                            stopped_at = {$stopped_at_sql},
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$new_status, $sub_id]);

                    // Map to event type
                    $event_type = 'PROFILE_UPDATED';
                    if ($new_status === 'stopped') $event_type = 'STOPPED';
                    elseif ($new_status === 'suppressed') $event_type = 'SUPPRESSED';
                    elseif ($new_status === 'active') $event_type = 'RESUBSCRIBED';

                    $details = "Admin ({$admin_username}) transitioned status from {$sub['status']} to {$new_status}";
                    pepp_updates_record_subscriber_event($pdo, $sub_id, $event_type, $details, 'admin');

                    $flash_success = "Subscriber #{$sub_id} status updated to " . strtoupper($new_status) . ".";
                }
            } catch (Throwable $e) {
                error_log('Subscriber status change error: ' . $e->getMessage());
                $flash_error = 'Error changing subscriber status: ' . $e->getMessage();
            }
        }
    }
    $action = 'list';
}

// ─────────────────────────────────────────────────────────────
// AJAX / FETCH SUBSCRIBER EVENTS & CATEGORIES FOR MODAL
// ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'subscriber_details' && !empty($_GET['id'])) {
    header('Content-Type: application/json');
    $sub_id = (int)$_GET['id'];
    try {
        $stmt_s = $pdo->prepare("SELECT id, phone, name, status, preferred_language, subscribed_at, stopped_at FROM updates_subscribers WHERE id = ?");
        $stmt_s->execute([$sub_id]);
        $subscriber = $stmt_s->fetch(PDO::FETCH_ASSOC);

        if (!$subscriber) {
            echo json_encode(['success' => false, 'error' => 'Subscriber not found.']);
            exit();
        }

        // Subscribed categories
        $stmt_c = $pdo->prepare("
            SELECT c.id, c.name 
            FROM updates_categories c
            JOIN updates_subscriber_categories usc ON usc.category_id = c.id
            WHERE usc.subscriber_id = ?
            ORDER BY c.name ASC
        ");
        $stmt_c->execute([$sub_id]);
        $sub_categories = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

        // Events audit trail (Zero raw IP, zero user agent)
        $stmt_ev = $pdo->prepare("
            SELECT id, event_type, details, source, created_at 
            FROM updates_subscriber_events 
            WHERE subscriber_id = ?
            ORDER BY id DESC
            LIMIT 50
        ");
        $stmt_ev->execute([$sub_id]);
        $events = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'subscriber' => $subscriber,
            'categories' => $sub_categories,
            'events' => $events
        ]);
        exit();
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// ─────────────────────────────────────────────────────────────
// LIST SUBSCRIBERS WITH PAGINATION AND FILTERS
// ─────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_category = !empty($_GET['category']) ? (int)$_GET['category'] : 0;

$subscribers = [];
$total_subscribers = 0;
$limit = 15;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $limit;

if (pepp_updates_tables_exist($pdo)) {
    try {
        $where_clauses = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where_clauses[] = "(s.phone LIKE ? OR s.name LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($filter_status !== '') {
            $where_clauses[] = "s.status = ?";
            $params[] = $filter_status;
        }

        if ($filter_category > 0) {
            $where_clauses[] = "s.id IN (SELECT subscriber_id FROM updates_subscriber_categories WHERE category_id = ?)";
            $params[] = $filter_category;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Count total
        $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM updates_subscribers s WHERE {$where_sql}");
        $stmt_cnt->execute($params);
        $total_subscribers = (int)$stmt_cnt->fetchColumn();

        // Fetch paginated records with concatenated category names
        $sql = "
            SELECT s.id, s.phone, s.name, s.status, s.preferred_language, s.subscribed_at, s.stopped_at,
                   GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS categories_list,
                   COUNT(DISTINCT ev.id) AS event_count
            FROM updates_subscribers s
            LEFT JOIN updates_subscriber_categories usc ON usc.subscriber_id = s.id
            LEFT JOIN updates_categories c ON c.id = usc.category_id
            LEFT JOIN updates_subscriber_events ev ON ev.subscriber_id = s.id
            WHERE {$where_sql}
            GROUP BY s.id
            ORDER BY s.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Subscriber list error: ' . $e->getMessage());
    }
}

$total_pages = ceil($total_subscribers / $limit);

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

<div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 4px;">WhatsApp Subscribers</h2>
        <div style="font-size: 0.8rem; color: var(--muted-foreground);">
            Privacy-hardened subscriber records. Audit logs are preserved via lifecycle transitions.
        </div>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <span class="badge green" style="padding: 6px 12px; font-size: 0.8rem;">
            <i class="fas fa-users"></i> <?php echo number_format($total_subscribers); ?> Total Found
        </span>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="panel" style="margin-bottom: 20px;">
    <div class="panel-body" style="padding: 12px 18px;">
        <form method="GET" action="pepp-updates-subscribers.php" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or phone (+91...)" style="flex: 2; min-width: 200px; padding: 7px 12px; font-size: 0.86rem;">

            <select name="status" style="flex: 1; min-width: 140px; padding: 7px 12px; font-size: 0.86rem;">
                <option value="">All Statuses</option>
                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="stopped" <?php echo $filter_status === 'stopped' ? 'selected' : ''; ?>>Stopped</option>
                <option value="unsubscribed" <?php echo $filter_status === 'unsubscribed' ? 'selected' : ''; ?>>Unsubscribed</option>
                <option value="suppressed" <?php echo $filter_status === 'suppressed' ? 'selected' : ''; ?>>Suppressed</option>
            </select>

            <select name="category" style="flex: 1.2; min-width: 160px; padding: 7px 12px; font-size: 0.86rem;">
                <option value="0">All Categories</option>
                <?php foreach ($all_categories as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $filter_category === (int)$c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search !== '' || $filter_status !== '' || $filter_category > 0): ?>
                <a href="pepp-updates-subscribers.php" class="btn btn-outline" title="Clear Filters"><i class="fas fa-rotate-left"></i></a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Subscribers Table -->
<div class="panel">
    <div class="panel-body flush">
        <?php if (empty($subscribers)): ?>
            <div class="empty-state">
                <i class="fas fa-users-viewfinder"></i>
                <p>No subscribers found matching your criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Subscriber</th>
                            <th>WhatsApp Number</th>
                            <th>Subscribed Categories</th>
                            <th>Language</th>
                            <th>Status</th>
                            <th>Subscribed On</th>
                            <th>Stopped On</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscribers as $s): ?>
                            <?php
                            $st_badge = 'gray';
                            if ($s['status'] === 'active') $st_badge = 'green';
                            elseif ($s['status'] === 'stopped') $st_badge = 'amber';
                            elseif ($s['status'] === 'suppressed' || $s['status'] === 'unsubscribed') $st_badge = 'red';
                            ?>
                            <tr>
                                <td>
                                    <div class="cell-main">
                                        <?php echo !empty($s['name']) ? htmlspecialchars($s['name']) : '<em>Anonymous</em>'; ?>
                                    </div>
                                    <div class="cell-sub">ID: #<?php echo (int)$s['id']; ?></div>
                                </td>
                                <td>
                                    <div style="font-family: monospace; font-size: 0.88rem; font-weight: 600;">
                                        <i class="fab fa-whatsapp" style="color: #25D366; margin-right: 4px;"></i>
                                        <?php echo htmlspecialchars($s['phone']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="cell-sub" style="max-width: 220px; display: inline-block;">
                                        <?php echo !empty($s['categories_list']) ? htmlspecialchars($s['categories_list']) : '<em>None (All updates)</em>'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge gray"><?php echo strtoupper(htmlspecialchars($s['preferred_language'])); ?></span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $st_badge; ?>">
                                        <?php echo strtoupper(htmlspecialchars($s['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="cell-sub"><?php echo date('d M Y', strtotime($s['subscribed_at'])); ?></span>
                                </td>
                                <td>
                                    <span class="cell-sub">
                                        <?php echo !empty($s['stopped_at']) ? date('d M Y', strtotime($s['stopped_at'])) : '—'; ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px; align-items: center;">
                                        <!-- View Details & History Modal -->
                                        <button type="button" class="btn btn-sm btn-outline" title="View Audit Trail &amp; Events"
                                                onclick="viewSubscriberHistory(<?php echo (int)$s['id']; ?>)">
                                            <i class="fas fa-clock-rotate-left"></i> History (<?php echo (int)$s['event_count']; ?>)
                                        </button>

                                        <!-- Edit Category Preferences -->
                                        <button type="button" class="btn btn-sm btn-outline" title="Edit Subscribed Categories"
                                                onclick="openEditSubscriberCategoriesModal(<?php echo (int)$s['id']; ?>)">
                                            <i class="fas fa-folder-tree"></i>
                                        </button>

                                        <!-- Soft Lifecycle Transition Dropdown/Buttons (NO DELETE BUTTON) -->
                                        <?php if ($s['status'] === 'active'): ?>
                                            <a href="pepp-updates-subscribers.php?action=change_status&id=<?php echo (int)$s['id']; ?>&new_status=stopped&csrf_token=<?php echo csrf_token(); ?>"
                                               class="btn btn-sm btn-soft-amber" title="Stop / Opt-out Subscriber"
                                               onclick="return confirm('Mark subscriber #<?php echo (int)$s['id']; ?> as STOPPED?');">
                                                <i class="fas fa-pause"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="pepp-updates-subscribers.php?action=change_status&id=<?php echo (int)$s['id']; ?>&new_status=active&csrf_token=<?php echo csrf_token(); ?>"
                                               class="btn btn-sm btn-soft-green" title="Re-activate / Resubscribe"
                                               onclick="return confirm('Re-activate subscriber #<?php echo (int)$s['id']; ?>?');">
                                                <i class="fas fa-play"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Footer -->
            <?php if ($total_pages > 1): ?>
                <div style="padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border);">
                    <div style="font-size: 0.8rem; color: var(--secondary);">
                        Showing page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong>
                    </div>
                    <div style="display: flex; gap: 4px;">
                        <?php if ($page > 1): ?>
                            <a href="pepp-updates-subscribers.php?p=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>&category=<?php echo $filter_category; ?>" class="btn btn-sm btn-outline">
                                &larr; Prev
                            </a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="pepp-updates-subscribers.php?p=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>&category=<?php echo $filter_category; ?>" 
                               class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="pepp-updates-subscribers.php?p=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>&category=<?php echo $filter_category; ?>" class="btn btn-sm btn-outline">
                                Next &rarr;
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     SUBSCRIBER AUDIT HISTORY MODAL (Strict Privacy Preserved)
     ───────────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="historyModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-box" style="background: var(--surface); border-radius: 14px; max-width: 640px; width: 100%; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 45px rgba(0,0,0,0.2); border: 1px solid var(--border);">
        <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border);">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="head-icon"><i class="fas fa-clock-rotate-left"></i></div>
                <h3 id="histModalTitle" style="margin: 0; font-size: 1.05rem; font-weight: 700;">Subscriber Audit History</h3>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="closeHistoryModal()" style="font-size: 1rem; padding: 4px 10px;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <div id="histSummary" style="background: var(--card); padding: 12px 16px; border-radius: 9px; margin-bottom: 16px; font-size: 0.86rem;">
                <!-- Populated dynamically via JS -->
            </div>

            <h4 style="font-size: 0.9rem; font-weight: 700; margin-bottom: 10px; color: var(--foreground);">
                Consent &amp; Lifecycle Events Trail:
            </h4>
            <div id="histTimeline" style="display: flex; flex-direction: column; gap: 10px;">
                <div style="text-align: center; color: var(--muted-foreground); padding: 20px;">Loading history...</div>
            </div>
        </div>
        <div class="modal-foot" style="padding: 12px 20px; border-top: 1px solid var(--border); text-align: right; background: var(--card);">
            <button type="button" class="btn btn-outline" onclick="closeHistoryModal()">Close</button>
        </div>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────────────
     EDIT SUBSCRIBER CATEGORIES MODAL
     ───────────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="editCatsModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-box" style="background: var(--surface); border-radius: 14px; max-width: 480px; width: 100%; box-shadow: 0 20px 45px rgba(0,0,0,0.2); border: 1px solid var(--border);">
        <form method="POST" action="pepp-updates-subscribers.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="update_subscriber_categories" value="1">
            <input type="hidden" name="subscriber_id" id="editCatSubId" value="">

            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="head-icon"><i class="fas fa-folder-tree"></i></div>
                    <h3 id="editCatModalTitle" style="margin: 0; font-size: 1.05rem; font-weight: 700;">Edit Categories</h3>
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="closeEditCatsModal()" style="font-size: 1rem; padding: 4px 10px;">&times;</button>
            </div>

            <div class="modal-body" style="padding: 20px;">
                <div style="font-size: 0.8rem; color: var(--secondary); margin-bottom: 12px;">
                    Select the update categories this subscriber should receive WhatsApp notifications for:
                </div>
                <div id="subCatsCheckboxes" style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($all_categories as $c): ?>
                        <label style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); cursor: pointer; font-size: 0.86rem; background: var(--surface);">
                            <input type="checkbox" name="categories[]" value="<?php echo (int)$c['id']; ?>" class="sub-cat-cb" id="sub_cat_<?php echo (int)$c['id']; ?>">
                            <span><?php echo htmlspecialchars($c['name']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal-foot" style="padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; background: var(--card);">
                <button type="button" class="btn btn-outline" onclick="closeEditCatsModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Preferences</button>
            </div>
        </form>
    </div>
</div>

<script>
function viewSubscriberHistory(id) {
    var modal = document.getElementById('historyModal');
    var summary = document.getElementById('histSummary');
    var timeline = document.getElementById('histTimeline');

    document.getElementById('histModalTitle').textContent = 'Subscriber Audit History #' + id;
    summary.innerHTML = 'Loading subscriber details...';
    timeline.innerHTML = '<div style="text-align: center; color: var(--muted-foreground); padding: 20px;">Loading events...</div>';
    modal.style.display = 'flex';

    fetch('pepp-updates-subscribers.php?ajax=subscriber_details&id=' + id)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success) {
                summary.innerHTML = '<span style="color:var(--red-ink);">' + data.error + '</span>';
                timeline.innerHTML = '';
                return;
            }

            var sub = data.subscriber;
            var catNames = data.categories.map(function(c) { return c.name; }).join(', ');
            if (!catNames) catNames = 'All Categories (None specific)';

            summary.innerHTML = '<strong>' + (sub.name || 'Anonymous') + '</strong> &bull; ' +
                'Phone: <code>' + sub.phone + '</code> &bull; ' +
                'Status: <span class="badge ' + (sub.status === 'active' ? 'green' : 'amber') + '">' + sub.status.toUpperCase() + '</span><br>' +
                '<span style="color:var(--secondary); font-size:0.8rem; margin-top:4px; display:inline-block;">Categories: ' + catNames + '</span>';

            if (!data.events || data.events.length === 0) {
                timeline.innerHTML = '<div style="color:var(--muted-foreground); text-align:center; padding:16px;">No recorded events yet.</div>';
            } else {
                var html = '';
                data.events.forEach(function(ev) {
                    var badgeCls = 'gray';
                    if (ev.event_type === 'SUBSCRIBED' || ev.event_type === 'RESUBSCRIBED') badgeCls = 'green';
                    else if (ev.event_type === 'STOPPED') badgeCls = 'amber';
                    else if (ev.event_type === 'SUPPRESSED') badgeCls = 'red';
                    else if (ev.event_type === 'CATEGORY_CHANGED') badgeCls = 'violet';

                    html += '<div style="padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">' +
                        '<div>' +
                            '<span class="badge ' + badgeCls + '" style="margin-right: 6px;">' + ev.event_type + '</span>' +
                            '<span style="font-size:0.75rem; color:var(--muted-foreground);">via ' + ev.source + '</span>' +
                            '<div style="font-size:0.84rem; color:var(--foreground); margin-top:4px;">' + (ev.details || '—') + '</div>' +
                        '</div>' +
                        '<div style="font-size:0.75rem; color:var(--muted-foreground); white-space:nowrap;">' + ev.created_at + '</div>' +
                    '</div>';
                });
                timeline.innerHTML = html;
            }
        })
        .catch(function(err) {
            summary.innerHTML = '<span style="color:var(--red-ink);">Failed to load history: ' + err + '</span>';
            timeline.innerHTML = '';
        });
}

function closeHistoryModal() {
    document.getElementById('historyModal').style.display = 'none';
}

function openEditSubscriberCategoriesModal(id) {
    document.getElementById('editCatSubId').value = id;
    document.getElementById('editCatModalTitle').textContent = 'Edit Subscriber #' + id + ' Categories';
    document.querySelectorAll('.sub-cat-cb').forEach(function(cb) { cb.checked = false; });

    fetch('pepp-updates-subscribers.php?ajax=subscriber_details&id=' + id)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.categories) {
                var assignedIds = data.categories.map(function(c) { return parseInt(c.id); });
                document.querySelectorAll('.sub-cat-cb').forEach(function(cb) {
                    if (assignedIds.includes(parseInt(cb.value))) {
                        cb.checked = true;
                    }
                });
            }
        });

    document.getElementById('editCatsModal').style.display = 'flex';
}

function closeEditCatsModal() {
    document.getElementById('editCatsModal').style.display = 'none';
}
</script>

<?php
include 'includes/admin_footer.php';
?>
