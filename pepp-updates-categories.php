<?php
/**
 * PEPP Updates — Category Management
 * 
 * Create, edit, toggle active status, reorder, search, and safely delete categories.
 * Enforces database ON DELETE RESTRICT protection when category is in use by posts.
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates — Categories';
$page_sub = 'Manage update categories, display ordering, and subscriber notification topics';
$active_page = 'pepp-updates-categories';

$flash_success = '';
$flash_error = '';

$action = $_GET['action'] ?? 'list';

// ─────────────────────────────────────────────────────────────
// POST SUBMISSIONS (Add / Edit Category)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['category_submit'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF).';
    } else {
        $cat_id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $name = trim($_POST['name'] ?? '');
        $custom_slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $flash_error = 'Category name is required.';
        } else {
            try {
                if ($cat_id) {
                    // Update
                    $stmt_curr = $pdo->prepare("SELECT slug FROM updates_categories WHERE id = ?");
                    $stmt_curr->execute([$cat_id]);
                    $curr_slug = $stmt_curr->fetchColumn();

                    $slug_to_use = $curr_slug;
                    if ($custom_slug !== '' && $custom_slug !== $curr_slug) {
                        $slug_to_use = pepp_updates_generate_slug($pdo, 'updates_categories', $custom_slug, $cat_id);
                    }

                    $stmt = $pdo->prepare("
                        UPDATE updates_categories SET
                            name = ?,
                            slug = ?,
                            description = ?,
                            display_order = ?,
                            is_active = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $name,
                        $slug_to_use,
                        $description ?: null,
                        $display_order,
                        $is_active,
                        $cat_id
                    ]);
                    $flash_success = "Category '{$name}' updated successfully.";
                } else {
                    // Insert
                    $slug_text = ($custom_slug !== '') ? $custom_slug : $name;
                    $new_slug = pepp_updates_generate_slug($pdo, 'updates_categories', $slug_text);

                    $stmt = $pdo->prepare("
                        INSERT INTO updates_categories (name, slug, description, display_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $name,
                        $new_slug,
                        $description ?: null,
                        $display_order,
                        $is_active
                    ]);
                    $flash_success = "Category '{$name}' created successfully.";
                }
            } catch (Throwable $e) {
                error_log('Category save error: ' . $e->getMessage());
                $flash_error = 'Failed to save category: ' . $e->getMessage();
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// TOGGLE ACTIVE STATUS
// ─────────────────────────────────────────────────────────────
if ($action === 'toggle_active' && isset($_GET['id']) && isset($_GET['csrf_token'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token.';
    } else {
        $toggle_id = (int)$_GET['id'];
        try {
            $stmt = $pdo->prepare("SELECT name, is_active FROM updates_categories WHERE id = ?");
            $stmt->execute([$toggle_id]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cat) {
                $new_state = ((int)$cat['is_active'] === 1) ? 0 : 1;
                $pdo->prepare("UPDATE updates_categories SET is_active = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new_state, $toggle_id]);
                $flash_success = "Category '{$cat['name']}' is now " . ($new_state ? 'ACTIVE' : 'INACTIVE') . '.';
            }
        } catch (Throwable $e) {
            $flash_error = 'Error changing category status: ' . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────────────────────
// DELETE CATEGORY (Protected by ON DELETE RESTRICT)
// ─────────────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id']) && isset($_GET['csrf_token'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token.';
    } else {
        $del_id = (int)$_GET['id'];
        try {
            $stmt_name = $pdo->prepare("SELECT name FROM updates_categories WHERE id = ?");
            $stmt_name->execute([$del_id]);
            $cat_name = $stmt_name->fetchColumn();

            // Pre-check linked posts count
            $stmt_post_chk = $pdo->prepare("SELECT COUNT(*) FROM updates_post_categories WHERE category_id = ?");
            $stmt_post_chk->execute([$del_id]);
            $post_count = (int)$stmt_post_chk->fetchColumn();

            if ($post_count > 0) {
                $flash_error = "Cannot delete category '{$cat_name}': it is currently assigned to {$post_count} update post(s). Remove the category from all posts before deleting.";
            } else {
                // Attempt deletion
                $pdo->prepare("DELETE FROM updates_categories WHERE id = ?")->execute([$del_id]);
                $flash_success = "Category '{$cat_name}' was deleted successfully.";
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $flash_error = "Cannot delete category: database constraint violation. The category is referenced by existing posts or records.";
            } else {
                $flash_error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// LIST CATEGORIES WITH COUNTS
// ─────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$categories = [];

if (pepp_updates_tables_exist($pdo)) {
    try {
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where = "(c.name LIKE ? OR c.description LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql = "
            SELECT c.*,
                   COUNT(DISTINCT upc.post_id) AS post_count,
                   COUNT(DISTINCT k.id) AS keyword_count,
                   COUNT(DISTINCT usc.subscriber_id) AS subscriber_count
            FROM updates_categories c
            LEFT JOIN updates_post_categories upc ON upc.category_id = c.id
            LEFT JOIN updates_keywords k ON k.category_id = c.id
            LEFT JOIN updates_subscriber_categories usc ON usc.category_id = c.id
            WHERE {$where}
            GROUP BY c.id
            ORDER BY c.display_order ASC, c.name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Category listing error: ' . $e->getMessage());
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

<div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 4px;">Update Categories</h2>
        <div style="font-size: 0.8rem; color: var(--muted-foreground);">
            Categories are used by students for selective WhatsApp notifications and by admins to organize updates.
        </div>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openAddCategoryModal()">
            <i class="fas fa-plus"></i> Add New Category
        </button>
    </div>
</div>

<!-- Search Bar -->
<div class="panel" style="margin-bottom: 20px;">
    <div class="panel-body" style="padding: 12px 18px;">
        <form method="GET" action="pepp-updates-categories.php" style="display: flex; gap: 10px; align-items: center;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search category by name or description..." style="flex: 1; padding: 7px 12px; font-size: 0.86rem;">
            <button type="submit" class="btn btn-outline"><i class="fas fa-search"></i> Search</button>
            <?php if ($search !== ''): ?>
                <a href="pepp-updates-categories.php" class="btn btn-outline" title="Clear Search"><i class="fas fa-rotate-left"></i></a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Categories Table -->
<div class="panel">
    <div class="panel-body flush">
        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-tree"></i>
                <p>No categories found.</p>
                <button type="button" class="btn btn-sm btn-primary" onclick="openAddCategoryModal()" style="margin-top: 10px;">
                    <i class="fas fa-plus"></i> Create First Category
                </button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">Order</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Keywords</th>
                            <th>Updates</th>
                            <th>Subscribers</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td>
                                    <span class="badge gray" style="font-family: monospace; font-size: 0.75rem;">
                                        <?php echo (int)$cat['display_order']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="cell-main"><?php echo htmlspecialchars($cat['name']); ?></div>
                                    <div class="cell-sub"><code><?php echo htmlspecialchars($cat['slug']); ?></code></div>
                                </td>
                                <td>
                                    <span class="cell-sub" style="max-width: 240px; display: inline-block;">
                                        <?php echo !empty($cat['description']) ? htmlspecialchars($cat['description']) : '<em>No description</em>'; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="pepp-updates-keywords.php?category=<?php echo (int)$cat['id']; ?>" class="badge violet">
                                        <i class="fas fa-tags" style="font-size: 0.62rem;"></i> <?php echo (int)$cat['keyword_count']; ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="pepp-updates-posts.php?category=<?php echo (int)$cat['id']; ?>" class="badge blue">
                                        <i class="fas fa-newspaper" style="font-size: 0.62rem;"></i> <?php echo (int)$cat['post_count']; ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="pepp-updates-subscribers.php?category=<?php echo (int)$cat['id']; ?>" class="badge teal">
                                        <i class="fas fa-users" style="font-size: 0.62rem;"></i> <?php echo (int)$cat['subscriber_count']; ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge <?php echo ((int)$cat['is_active'] === 1) ? 'green' : 'gray'; ?>">
                                        <?php echo ((int)$cat['is_active'] === 1) ? 'ACTIVE' : 'INACTIVE'; ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px; align-items: center;">
                                        <button type="button" class="btn btn-sm btn-outline" title="Edit Category"
                                                onclick="openEditCategoryModal(<?php echo htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8'); ?>)">
                                            <i class="fas fa-pen"></i>
                                        </button>

                                        <!-- Toggle Active -->
                                        <a href="pepp-updates-categories.php?action=toggle_active&id=<?php echo (int)$cat['id']; ?>&csrf_token=<?php echo csrf_token(); ?>" 
                                           class="btn btn-sm <?php echo ((int)$cat['is_active'] === 1) ? 'btn-soft-amber' : 'btn-soft-green'; ?>" 
                                           title="<?php echo ((int)$cat['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="fas <?php echo ((int)$cat['is_active'] === 1) ? 'fa-pause' : 'fa-play'; ?>"></i>
                                        </a>

                                        <!-- Delete (Restricted if posts linked) -->
                                        <a href="pepp-updates-categories.php?action=delete&id=<?php echo (int)$cat['id']; ?>&csrf_token=<?php echo csrf_token(); ?>" 
                                           class="btn btn-sm btn-soft-red" title="Delete Category"
                                           onclick="return confirm('Are you sure you want to delete category \'<?php echo addslashes($cat['name']); ?>\'? This will fail if the category is assigned to existing update posts.');">
                                            <i class="fas fa-trash"></i>
                                        </a>
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

<!-- Add / Edit Category Modal -->
<div class="modal-backdrop" id="categoryModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-box" style="background: var(--surface); border-radius: 14px; max-width: 500px; width: 100%; box-shadow: 0 20px 45px rgba(0,0,0,0.2); border: 1px solid var(--border);">
        <form method="POST" action="pepp-updates-categories.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="category_submit" value="1">
            <input type="hidden" name="id" id="catModalId" value="">

            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="head-icon"><i class="fas fa-folder-tree"></i></div>
                    <h3 id="catModalTitle" style="margin: 0; font-size: 1.05rem; font-weight: 700;">Add New Category</h3>
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="closeCategoryModal()" style="font-size: 1rem; padding: 4px 10px;">&times;</button>
            </div>

            <div class="modal-body" style="padding: 20px;">
                <div class="field" style="margin-bottom: 14px;">
                    <label>Category Name <span class="req">*</span></label>
                    <input type="text" name="name" id="catModalName" required placeholder="e.g. CUET PG / Admissions / Scholarships">
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>URL Slug (Optional)</label>
                    <input type="text" name="slug" id="catModalSlug" placeholder="e.g. cuet-pg (leave empty to auto-generate)">
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Description</label>
                    <textarea name="description" id="catModalDesc" rows="3" placeholder="Brief summary of what this category covers..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: center;">
                    <div class="field">
                        <label>Display Order</label>
                        <input type="number" name="display_order" id="catModalOrder" value="0" min="0" step="1">
                    </div>
                    <div style="padding-top: 18px;">
                        <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" name="is_active" id="catModalActive" value="1" checked>
                            Active Category
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-foot" style="padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; background: var(--card);">
                <button type="button" class="btn btn-outline" onclick="closeCategoryModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Category</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddCategoryModal() {
    document.getElementById('catModalTitle').textContent = 'Add New Category';
    document.getElementById('catModalId').value = '';
    document.getElementById('catModalName').value = '';
    document.getElementById('catModalSlug').value = '';
    document.getElementById('catModalDesc').value = '';
    document.getElementById('catModalOrder').value = '0';
    document.getElementById('catModalActive').checked = true;
    document.getElementById('categoryModal').style.display = 'flex';
}

function openEditCategoryModal(cat) {
    document.getElementById('catModalTitle').textContent = 'Edit Category #' + cat.id;
    document.getElementById('catModalId').value = cat.id;
    document.getElementById('catModalName').value = cat.name || '';
    document.getElementById('catModalSlug').value = cat.slug || '';
    document.getElementById('catModalDesc').value = cat.description || '';
    document.getElementById('catModalOrder').value = cat.display_order || '0';
    document.getElementById('catModalActive').checked = (parseInt(cat.is_active) === 1);
    document.getElementById('categoryModal').style.display = 'flex';
}

function closeCategoryModal() {
    document.getElementById('categoryModal').style.display = 'none';
}
</script>

<?php
include 'includes/admin_footer.php';
?>
