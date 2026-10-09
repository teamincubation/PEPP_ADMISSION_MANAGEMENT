<?php
/**
 * PEPP Updates — Keyword Management
 * 
 * Category-owned keywords. Each keyword belongs to a specific category.
 * Enforces per-category uniqueness (same keyword can exist in multiple categories).
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates — Keywords';
$page_sub = 'Manage category-scoped tags for precise update categorization and search filtering';
$active_page = 'pepp-updates-keywords';

$flash_success = '';
$flash_error = '';

$action = $_GET['action'] ?? 'list';

// Fetch all active categories for dropdowns
$categories = [];
if (pepp_updates_tables_exist($pdo)) {
    try {
        $stmt_c = $pdo->query("SELECT id, name FROM updates_categories ORDER BY display_order ASC, name ASC");
        $categories = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────
// POST SUBMISSIONS (Add / Edit Keyword)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['keyword_submit'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF).';
    } else {
        $kw_id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $category_id = (int)($_POST['category_id'] ?? 0);
        $keyword = trim($_POST['keyword'] ?? '');
        $custom_slug = trim($_POST['slug'] ?? '');

        if ($category_id <= 0) {
            $flash_error = 'Please select a valid parent category.';
        } elseif ($keyword === '') {
            $flash_error = 'Keyword name is required.';
        } else {
            try {
                // Check per-category duplicate keyword constraint: uq_category_keyword (category_id, keyword)
                $dup_sql = "SELECT COUNT(*) FROM updates_keywords WHERE category_id = ? AND LOWER(keyword) = LOWER(?)";
                $dup_params = [$category_id, $keyword];
                if ($kw_id) {
                    $dup_sql .= " AND id != ?";
                    $dup_params[] = $kw_id;
                }
                $stmt_chk = $pdo->prepare($dup_sql);
                $stmt_chk->execute($dup_params);
                $is_duplicate = ((int)$stmt_chk->fetchColumn() > 0);

                if ($is_duplicate) {
                    $flash_error = "The keyword '{$keyword}' already exists within the selected category. Keyword names must be unique within the same category.";
                } else {
                    // Generate category-scoped slug
                    $slug_text = ($custom_slug !== '') ? $custom_slug : $keyword;
                    $safe_slug = pepp_updates_generate_slug(
                        $pdo, 
                        'updates_keywords', 
                        $slug_text, 
                        $kw_id, 
                        'slug', 
                        'category_id = ?', 
                        [$category_id]
                    );

                    if ($kw_id) {
                        $stmt = $pdo->prepare("
                            UPDATE updates_keywords SET
                                category_id = ?,
                                keyword = ?,
                                slug = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$category_id, $keyword, $safe_slug, $kw_id]);
                        $flash_success = "Keyword '{$keyword}' updated successfully.";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO updates_keywords (category_id, keyword, slug, created_at, updated_at)
                            VALUES (?, ?, ?, NOW(), NOW())
                        ");
                        $stmt->execute([$category_id, $keyword, $safe_slug]);
                        $flash_success = "Keyword '{$keyword}' created successfully.";
                    }
                }
            } catch (Throwable $e) {
                error_log('Keyword save error: ' . $e->getMessage());
                $flash_error = 'Failed to save keyword: ' . $e->getMessage();
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// DELETE KEYWORD
// ─────────────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id']) && isset($_GET['csrf_token'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token.';
    } else {
        $del_id = (int)$_GET['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM updates_keywords WHERE id = ?");
            $stmt->execute([$del_id]);
            $flash_success = "Keyword deleted successfully.";
        } catch (Throwable $e) {
            $flash_error = 'Error deleting keyword: ' . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────────────────────
// LIST KEYWORDS WITH CATEGORY & USAGE COUNTS
// ─────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$filter_cat = !empty($_GET['category']) ? (int)$_GET['category'] : 0;
$keywords_list = [];

if (pepp_updates_tables_exist($pdo)) {
    try {
        $where_clauses = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where_clauses[] = "k.keyword LIKE ?";
            $params[] = "%{$search}%";
        }

        if ($filter_cat > 0) {
            $where_clauses[] = "k.category_id = ?";
            $params[] = $filter_cat;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $sql = "
            SELECT k.*, c.name AS category_name,
                   COUNT(DISTINCT upk.post_id) AS post_usage_count
            FROM updates_keywords k
            JOIN updates_categories c ON c.id = k.category_id
            LEFT JOIN updates_post_keywords upk ON upk.keyword_id = k.id
            WHERE {$where_sql}
            GROUP BY k.id
            ORDER BY c.name ASC, k.keyword ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $keywords_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Keyword listing error: ' . $e->getMessage());
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
        <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 4px;">Update Keywords</h2>
        <div style="font-size: 0.8rem; color: var(--muted-foreground);">
            Keywords are owned by categories. The same keyword (e.g. <em>Application</em>) may belong to multiple categories.
        </div>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openAddKeywordModal()">
            <i class="fas fa-plus"></i> Add New Keyword
        </button>
    </div>
</div>

<!-- Search & Category Filter Bar -->
<div class="panel" style="margin-bottom: 20px;">
    <div class="panel-body" style="padding: 12px 18px;">
        <form method="GET" action="pepp-updates-keywords.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search keyword..." style="flex: 2; min-width: 180px; padding: 7px 12px; font-size: 0.86rem;">
            
            <select name="category" style="flex: 1.2; min-width: 180px; padding: 7px 12px; font-size: 0.86rem;">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $filter_cat === (int)$c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($search !== '' || $filter_cat > 0): ?>
                <a href="pepp-updates-keywords.php" class="btn btn-outline" title="Clear Filters"><i class="fas fa-rotate-left"></i></a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Keywords Table -->
<div class="panel">
    <div class="panel-body flush">
        <?php if (empty($keywords_list)): ?>
            <div class="empty-state">
                <i class="fas fa-tags"></i>
                <p>No keywords found matching your filter.</p>
                <button type="button" class="btn btn-sm btn-primary" onclick="openAddKeywordModal()" style="margin-top: 10px;">
                    <i class="fas fa-plus"></i> Create First Keyword
                </button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Keyword</th>
                            <th>Parent Category</th>
                            <th>Category Slug</th>
                            <th>Used in Updates</th>
                            <th>Created At</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($keywords_list as $kw): ?>
                            <tr>
                                <td>
                                    <div class="cell-main" style="display: flex; align-items: center; gap: 6px;">
                                        <i class="fas fa-tag" style="color: var(--teal); font-size: 0.75rem;"></i>
                                        <?php echo htmlspecialchars($kw['keyword']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge violet">
                                        <i class="fas fa-folder-tree" style="font-size: 0.62rem;"></i>
                                        <?php echo htmlspecialchars($kw['category_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <code class="cell-sub"><?php echo htmlspecialchars($kw['slug']); ?></code>
                                </td>
                                <td>
                                    <span class="badge gray">
                                        <?php echo (int)$kw['post_usage_count']; ?> updates
                                    </span>
                                </td>
                                <td>
                                    <span class="cell-sub"><?php echo date('d M Y', strtotime($kw['created_at'])); ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px; align-items: center;">
                                        <button type="button" class="btn btn-sm btn-outline" title="Edit Keyword"
                                                onclick="openEditKeywordModal(<?php echo htmlspecialchars(json_encode($kw), ENT_QUOTES, 'UTF-8'); ?>)">
                                            <i class="fas fa-pen"></i>
                                        </button>

                                        <a href="pepp-updates-keywords.php?action=delete&id=<?php echo (int)$kw['id']; ?>&csrf_token=<?php echo csrf_token(); ?>" 
                                           class="btn btn-sm btn-soft-red" title="Delete Keyword"
                                           onclick="return confirm('Delete keyword \'<?php echo addslashes($kw['keyword']); ?>\'?');">
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

<!-- Add / Edit Keyword Modal -->
<div class="modal-backdrop" id="keywordModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-box" style="background: var(--surface); border-radius: 14px; max-width: 480px; width: 100%; box-shadow: 0 20px 45px rgba(0,0,0,0.2); border: 1px solid var(--border);">
        <form method="POST" action="pepp-updates-keywords.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="keyword_submit" value="1">
            <input type="hidden" name="id" id="kwModalId" value="">

            <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="head-icon"><i class="fas fa-tag"></i></div>
                    <h3 id="kwModalTitle" style="margin: 0; font-size: 1.05rem; font-weight: 700;">Add New Keyword</h3>
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="closeKeywordModal()" style="font-size: 1rem; padding: 4px 10px;">&times;</button>
            </div>

            <div class="modal-body" style="padding: 20px;">
                <div class="field" style="margin-bottom: 14px;">
                    <label>Category <span class="req">*</span></label>
                    <select name="category_id" id="kwModalCat" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Keywords are strictly owned by their parent category.</div>
                </div>

                <div class="field" style="margin-bottom: 14px;">
                    <label>Keyword Name <span class="req">*</span></label>
                    <input type="text" name="keyword" id="kwModalKeyword" required placeholder="e.g. Application, Syllabus, Admit Card">
                    <div class="help">Unique inside this category. Can exist under different categories.</div>
                </div>

                <div class="field">
                    <label>Category-Scoped Slug (Optional)</label>
                    <input type="text" name="slug" id="kwModalSlug" placeholder="e.g. application (auto-generated if empty)">
                </div>
            </div>

            <div class="modal-foot" style="padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; background: var(--card);">
                <button type="button" class="btn btn-outline" onclick="closeKeywordModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Keyword</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddKeywordModal() {
    document.getElementById('kwModalTitle').textContent = 'Add New Keyword';
    document.getElementById('kwModalId').value = '';
    document.getElementById('kwModalCat').value = '<?php echo $filter_cat > 0 ? $filter_cat : ''; ?>';
    document.getElementById('kwModalKeyword').value = '';
    document.getElementById('kwModalSlug').value = '';
    document.getElementById('keywordModal').style.display = 'flex';
}

function openEditKeywordModal(kw) {
    document.getElementById('kwModalTitle').textContent = 'Edit Keyword #' + kw.id;
    document.getElementById('kwModalId').value = kw.id;
    document.getElementById('kwModalCat').value = kw.category_id || '';
    document.getElementById('kwModalKeyword').value = kw.keyword || '';
    document.getElementById('kwModalSlug').value = kw.slug || '';
    document.getElementById('keywordModal').style.display = 'flex';
}

function closeKeywordModal() {
    document.getElementById('keywordModal').style.display = 'none';
}
</script>

<?php
include 'includes/admin_footer.php';
?>
