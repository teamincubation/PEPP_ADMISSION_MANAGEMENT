<?php
/**
 * PEPP Updates — Posts / Updates Management
 * 
 * Full CRUD, category-aware keyword tagging, Quill rich text editor,
 * banner upload, lifecycle management, preview modal, and server-side pagination.
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates — Posts';
$page_sub = 'Manage career alerts, admission notifications, exam updates, and publication lifecycle';
$active_page = 'pepp-updates-posts';

$action = $_GET['action'] ?? 'list';
if ($action === 'new') {
    $action = 'create';
}
$flash_success = '';
$flash_error = '';

$new_duration = (int)pepp_updates_get_setting($pdo, 'new_label_duration_days', 7);

// Fetch categories for forms and filters
$all_categories = [];
$all_keywords = [];
if (pepp_updates_tables_exist($pdo)) {
    try {
        $stmt_cat = $pdo->query("SELECT id, name, slug FROM updates_categories WHERE is_active = 1 ORDER BY display_order ASC, name ASC");
        $all_categories = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);

        $stmt_kw = $pdo->query("
            SELECT k.id, k.keyword, k.category_id, c.name AS category_name 
            FROM updates_keywords k
            JOIN updates_categories c ON c.id = k.category_id
            WHERE c.is_active = 1
            ORDER BY c.name ASC, k.keyword ASC
        ");
        $all_keywords = $stmt_kw->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Category/Keyword fetch error: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
// POST SUBMISSIONS (Create / Edit)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_action_submit'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF). Please reload and try again.';
    } else {
        $post_id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $title = trim($_POST['title'] ?? '');
        $custom_slug = trim($_POST['slug'] ?? '');
        $short_desc = trim($_POST['short_description'] ?? '');
        $raw_full_desc = $_POST['full_description'] ?? '';
        $action_btn_text = trim($_POST['action_button_text'] ?? '');
        $action_btn_url = trim($_POST['action_button_url'] ?? '');
        $publish_at = !empty($_POST['publish_at']) ? date('Y-m-d H:i:s', strtotime($_POST['publish_at'])) : null;
        $expires_at = !empty($_POST['expires_at']) ? date('Y-m-d H:i:s', strtotime($_POST['expires_at'])) : null;
        $submit_type = $_POST['submit_type'] ?? 'draft'; // 'draft' or 'publish'
        
        $selected_categories = isset($_POST['categories']) && is_array($_POST['categories']) 
            ? array_map('intval', $_POST['categories']) 
            : [];
        $selected_keywords = isset($_POST['keywords']) && is_array($_POST['keywords']) 
            ? array_map('intval', $_POST['keywords']) 
            : [];

        // Determine intended status
        if ($submit_type === 'publish') {
            $status = 'published';
            if (empty($publish_at)) {
                $publish_at = date('Y-m-d H:i:s');
            } elseif (strtotime($publish_at) > time()) {
                $status = 'scheduled';
            }
        } else {
            // Saving as draft
            $status = 'draft';
        }

        // Validations
        if ($title === '') {
            $flash_error = 'Title is required.';
        } elseif (!empty($action_btn_url) && !filter_var($action_btn_url, FILTER_VALIDATE_URL) && strpos($action_btn_url, '/') !== 0) {
            $flash_error = 'Action button URL must be a valid HTTP/HTTPS link or absolute path.';
        } elseif ($publish_at && $expires_at && strtotime($expires_at) <= strtotime($publish_at)) {
            $flash_error = 'Expiry date must be after the publish date.';
        } else {
            // Sanitize full description HTML
            $clean_full_desc = pepp_updates_sanitize_html($raw_full_desc);

            // Banner upload handling
            $banner_path = null;
            if (!empty($_FILES['banner']['name']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
                $old_banner = $_POST['existing_banner'] ?? null;
                $upload_res = pepp_updates_upload_banner($_FILES['banner'], $old_banner);
                if ($upload_res['success']) {
                    $banner_path = $upload_res['path'];
                } else {
                    $flash_error = 'Banner upload failed: ' . $upload_res['error'];
                }
            } else {
                $banner_path = !empty($_POST['existing_banner']) ? trim($_POST['existing_banner']) : null;
            }

            if (empty($flash_error)) {
                try {
                    $pdo->beginTransaction();

                    if ($post_id) {
                        // Updating existing post
                        // Determine slug: if custom slug changed, generate safe unique; otherwise keep existing
                        $stmt_curr = $pdo->prepare("SELECT slug FROM updates_posts WHERE id = ?");
                        $stmt_curr->execute([$post_id]);
                        $curr_slug = $stmt_curr->fetchColumn();

                        $slug_to_use = $curr_slug;
                        if ($custom_slug !== '' && $custom_slug !== $curr_slug) {
                            $slug_to_use = pepp_updates_generate_slug($pdo, 'updates_posts', $custom_slug, $post_id);
                        }

                        $stmt_up = $pdo->prepare("
                            UPDATE updates_posts SET
                                title = ?,
                                slug = ?,
                                short_description = ?,
                                full_description = ?,
                                banner_image = ?,
                                action_button_text = ?,
                                action_button_url = ?,
                                status = ?,
                                publish_at = ?,
                                expires_at = ?,
                                updated_by = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt_up->execute([
                            $title,
                            $slug_to_use,
                            $short_desc ?: null,
                            $clean_full_desc,
                            $banner_path,
                            $action_btn_text ?: null,
                            $action_btn_url ?: null,
                            $status,
                            $publish_at,
                            $expires_at,
                            $admin_username,
                            $post_id
                        ]);

                        $saved_post_id = $post_id;
                        $flash_success = 'Update post #' . $post_id . ' saved successfully.';
                    } else {
                        // Inserting new post
                        $slug_text = ($custom_slug !== '') ? $custom_slug : $title;
                        $new_slug = pepp_updates_generate_slug($pdo, 'updates_posts', $slug_text);

                        $stmt_ins = $pdo->prepare("
                            INSERT INTO updates_posts (
                                title, slug, short_description, full_description,
                                banner_image, action_button_text, action_button_url,
                                status, publish_at, expires_at, created_by, updated_by, created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?,
                                ?, ?, ?,
                                ?, ?, ?, ?, ?, NOW(), NOW()
                            )
                        ");
                        $stmt_ins->execute([
                            $title,
                            $new_slug,
                            $short_desc ?: null,
                            $clean_full_desc,
                            $banner_path,
                            $action_btn_text ?: null,
                            $action_btn_url ?: null,
                            $status,
                            $publish_at,
                            $expires_at,
                            $admin_username,
                            $admin_username
                        ]);

                        $saved_post_id = (int)$pdo->lastInsertId();
                        $flash_success = 'New update post #' . $saved_post_id . ' created successfully (' . strtoupper($status) . ').';
                    }

                    // Sync Categories Junction (updates_post_categories)
                    $pdo->prepare("DELETE FROM updates_post_categories WHERE post_id = ?")->execute([$saved_post_id]);
                    if (!empty($selected_categories)) {
                        $stmt_cat_ins = $pdo->prepare("INSERT INTO updates_post_categories (post_id, category_id) VALUES (?, ?)");
                        foreach ($selected_categories as $cat_id) {
                            $stmt_cat_ins->execute([$saved_post_id, $cat_id]);
                        }
                    }

                    // Sync Keywords Junction (updates_post_keywords)
                    // Validate keyword belongs to selected categories
                    $pdo->prepare("DELETE FROM updates_post_keywords WHERE post_id = ?")->execute([$saved_post_id]);
                    if (!empty($selected_keywords) && !empty($selected_categories)) {
                        $placeholders = implode(',', array_fill(0, count($selected_categories), '?'));
                        $stmt_valid_kw = $pdo->prepare("
                            SELECT id FROM updates_keywords 
                            WHERE id = ? AND category_id IN ($placeholders)
                        ");

                        $stmt_kw_ins = $pdo->prepare("INSERT INTO updates_post_keywords (post_id, keyword_id) VALUES (?, ?)");
                        foreach ($selected_keywords as $kw_id) {
                            $check_params = array_merge([$kw_id], $selected_categories);
                            $stmt_valid_kw->execute($check_params);
                            if ($stmt_valid_kw->fetchColumn()) {
                                $stmt_kw_ins->execute([$saved_post_id, $kw_id]);
                            }
                        }
                    }

                    $pdo->commit();
                    $action = 'list';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Post save error: ' . $e->getMessage());
                    $flash_error = 'Database error while saving post: ' . $e->getMessage();
                }
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────
// STATE CHANGE: QUICK PUBLISH / DRAFT TOGGLE
// ─────────────────────────────────────────────────────────────
if ($action === 'toggle_publish' && isset($_GET['id']) && isset($_GET['csrf_token'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token.';
    } else {
        $toggle_id = (int)$_GET['id'];
        try {
            $stmt = $pdo->prepare("SELECT status, publish_at FROM updates_posts WHERE id = ?");
            $stmt->execute([$toggle_id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($current) {
                if ($current['status'] === 'published') {
                    $pdo->prepare("UPDATE updates_posts SET status = 'draft', updated_by = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$admin_username, $toggle_id]);
                    $flash_success = "Post #{$toggle_id} moved back to DRAFT.";
                } else {
                    $new_pub = empty($current['publish_at']) ? date('Y-m-d H:i:s') : $current['publish_at'];
                    $pdo->prepare("UPDATE updates_posts SET status = 'published', publish_at = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$new_pub, $admin_username, $toggle_id]);
                    $flash_success = "Post #{$toggle_id} is now PUBLISHED.";
                }
            }
        } catch (Throwable $e) {
            $flash_error = 'Error changing post status: ' . $e->getMessage();
        }
    }
    $action = 'list';
}

// ─────────────────────────────────────────────────────────────
// DELETE POST
// ─────────────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id']) && isset($_GET['csrf_token'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token.';
    } else {
        $del_id = (int)$_GET['id'];
        try {
            // Check if post is referenced in delivery campaigns (fk_udc_post ON DELETE RESTRICT)
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM updates_delivery_campaigns WHERE post_id = ?");
            $stmt_check->execute([$del_id]);
            $camp_count = (int)$stmt_check->fetchColumn();

            if ($camp_count > 0) {
                $flash_error = "Cannot delete update post #{$del_id}: it is referenced by {$camp_count} delivery campaign(s). Remove or reassign the campaigns first.";
            } else {
                // Fetch banner to clean up file
                $stmt_b = $pdo->prepare("SELECT banner_image FROM updates_posts WHERE id = ?");
                $stmt_b->execute([$del_id]);
                $banner_file = $stmt_b->fetchColumn();

                $pdo->prepare("DELETE FROM updates_posts WHERE id = ?")->execute([$del_id]);

                if ($banner_file && strpos($banner_file, 'uploads/updates/banners/') === 0) {
                    $full_banner_path = dirname(__DIR__) . '/' . $banner_file;
                    if (file_exists($full_banner_path) && is_file($full_banner_path)) {
                        @unlink($full_banner_path);
                    }
                }
                $flash_success = "Post #{$del_id} deleted successfully.";
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $flash_error = "Cannot delete update post #{$del_id} because it is referenced by another record.";
            } else {
                $flash_error = 'Database error: ' . $e->getMessage();
            }
        }
    }
    $action = 'list';
}

// ─────────────────────────────────────────────────────────────
// LOAD DATA FOR EDIT FORM
// ─────────────────────────────────────────────────────────────
$edit_post = null;
$edit_category_ids = [];
$edit_keyword_ids = [];

if ($action === 'edit' && !empty($_GET['id'])) {
    $edit_id = (int)$_GET['id'];
    try {
        $stmt_e = $pdo->prepare("SELECT * FROM updates_posts WHERE id = ?");
        $stmt_e->execute([$edit_id]);
        $edit_post = $stmt_e->fetch(PDO::FETCH_ASSOC);

        if ($edit_post) {
            $stmt_ec = $pdo->prepare("SELECT category_id FROM updates_post_categories WHERE post_id = ?");
            $stmt_ec->execute([$edit_id]);
            $edit_category_ids = $stmt_ec->fetchAll(PDO::FETCH_COLUMN);

            $stmt_ek = $pdo->prepare("SELECT keyword_id FROM updates_post_keywords WHERE post_id = ?");
            $stmt_ek->execute([$edit_id]);
            $edit_keyword_ids = $stmt_ek->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $flash_error = 'Post not found.';
            $action = 'list';
        }
    } catch (Throwable $e) {
        $flash_error = 'Error loading post: ' . $e->getMessage();
        $action = 'list';
    }
}

// ─────────────────────────────────────────────────────────────
// LIST VIEW WITH PAGINATION, SEARCH, AND FILTERS
// ─────────────────────────────────────────────────────────────
$posts_list = [];
$total_posts_count = 0;
$limit = 15;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $limit;

$filter_search = trim($_GET['search'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_category = !empty($_GET['category']) ? (int)$_GET['category'] : 0;

if ($action === 'list' && pepp_updates_tables_exist($pdo)) {
    try {
        $where_clauses = ['1=1'];
        $params = [];

        if ($filter_search !== '') {
            $where_clauses[] = "(p.title LIKE ? OR p.short_description LIKE ?)";
            $params[] = "%{$filter_search}%";
            $params[] = "%{$filter_search}%";
        }

        if ($filter_status !== '') {
            if ($filter_status === 'draft') {
                $where_clauses[] = "p.status = 'draft'";
            } elseif ($filter_status === 'archived') {
                $where_clauses[] = "p.status = 'archived'";
            } elseif ($filter_status === 'expired') {
                $where_clauses[] = "(p.status = 'expired' OR (p.status = 'published' AND p.expires_at IS NOT NULL AND p.expires_at <= NOW()))";
            } elseif ($filter_status === 'scheduled') {
                $where_clauses[] = "(p.status = 'scheduled' OR (p.status = 'published' AND p.publish_at IS NOT NULL AND p.publish_at > NOW()))";
            } elseif ($filter_status === 'published') {
                $where_clauses[] = "(p.status = 'published' AND (p.publish_at IS NULL OR p.publish_at <= NOW()) AND (p.expires_at IS NULL OR p.expires_at > NOW()))";
            }
        }

        if ($filter_category > 0) {
            $where_clauses[] = "p.id IN (SELECT post_id FROM updates_post_categories WHERE category_id = ?)";
            $params[] = $filter_category;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Count total
        $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM updates_posts p WHERE {$where_sql}");
        $stmt_cnt->execute($params);
        $total_posts_count = (int)$stmt_cnt->fetchColumn();

        // Fetch paginated rows with category names and view count
        $sql = "
            SELECT p.*,
                   GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS categories_list,
                   COUNT(DISTINCT v.id) AS visit_count
            FROM updates_posts p
            LEFT JOIN updates_post_categories upc ON upc.post_id = p.id
            LEFT JOIN updates_categories c ON c.id = upc.category_id
            LEFT JOIN updates_visits v ON v.post_id = p.id
            WHERE {$where_sql}
            GROUP BY p.id
            ORDER BY p.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt_p = $pdo->prepare($sql);
        $stmt_p->execute($params);
        $posts_list = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('List posts query error: ' . $e->getMessage());
    }
}

$total_pages = ceil($total_posts_count / $limit);

// Include Quill CSS/JS in extra_head for form view
$extra_head = '';
if ($action === 'create' || $action === 'edit') {
    $extra_head = '
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <style>
        .quill-editor-container { min-height: 280px; background: #fff; border-radius: 0 0 9px 9px; font-size: 0.9rem; font-family: inherit; }
        .ql-toolbar.ql-snow { border-radius: 9px 9px 0 0; background: var(--card); border-color: var(--border); }
        .ql-container.ql-snow { border-color: var(--border); border-bottom-left-radius: 9px; border-bottom-right-radius: 9px; }
        .category-chip-label { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: 1.5px solid var(--border); background: var(--surface); cursor: pointer; user-select: none; font-size: 0.84rem; font-weight: 500; transition: all .15s ease; }
        .category-chip-label:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-dark); font-weight: 600; }
        .keyword-tag-label { display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface); cursor: pointer; user-select: none; font-size: 0.78rem; transition: all .15s ease; }
        .keyword-tag-label:has(input:checked) { border-color: var(--teal); background: var(--teal-soft); color: var(--teal-ink); font-weight: 600; }
    </style>
    ';
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

<?php if ($action === 'create' || $action === 'edit'): ?>
    <!-- ─────────────────────────────────────────────────────────────
         CREATE / EDIT UPDATE POST FORM
         ───────────────────────────────────────────────────────────── -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 4px;">
                <?php echo ($action === 'edit') ? 'Edit Update Post #' . (int)$edit_post['id'] : 'Create New Update Post'; ?>
            </h2>
            <div style="font-size: 0.8rem; color: var(--muted-foreground);">
                All newly created posts default safely to <strong>DRAFT</strong>. You can publish whenever ready.
            </div>
        </div>
        <div>
            <a href="pepp-updates-posts.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Updates
            </a>
        </div>
    </div>

    <form method="POST" action="pepp-updates-posts.php" enctype="multipart/form-data" id="postForm" onsubmit="prepareQuillSubmit()">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="post_action_submit" value="1">
        <input type="hidden" name="id" value="<?php echo $edit_post['id'] ?? ''; ?>">
        <input type="hidden" name="existing_banner" value="<?php echo htmlspecialchars($edit_post['banner_image'] ?? ''); ?>">
        <input type="hidden" name="full_description" id="full_description_input">
        <input type="hidden" name="submit_type" id="submit_type_input" value="draft">

        <div style="display: grid; grid-template-columns: 2.2fr 1fr; gap: 20px;">
            <!-- Left Column: Content Fields -->
            <div style="display: flex; flex-direction: column; gap: 18px;">
                
                <!-- Title & Slug -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="head-icon"><i class="fas fa-heading"></i></div>
                        <h2>Title &amp; URL Slug</h2>
                    </div>
                    <div class="panel-body">
                        <div class="field" style="margin-bottom: 14px;">
                            <label>Update Title <span class="req">*</span></label>
                            <input type="text" name="title" id="postTitle" required 
                                   value="<?php echo htmlspecialchars($edit_post['title'] ?? ''); ?>" 
                                   placeholder="e.g. CUET PG 2027 Registration Officially Opened" 
                                   style="font-size: 1rem; font-weight: 600;">
                        </div>
                        <div class="field">
                            <label>URL Slug (Optional / Auto-Generated)</label>
                            <input type="text" name="slug" id="postSlug" 
                                   value="<?php echo htmlspecialchars($edit_post['slug'] ?? ''); ?>" 
                                   placeholder="cuet-pg-2027-registration-officially-opened" 
                                   style="font-family: monospace; font-size: 0.85rem;">
                            <div class="help">Leave empty on creation to generate cleanly from title. Existing slug remains stable on edit unless changed.</div>
                        </div>
                    </div>
                </div>

                <!-- Short Description -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="head-icon"><i class="fas fa-align-left"></i></div>
                        <h2>Short Summary / Excerpt</h2>
                    </div>
                    <div class="panel-body">
                        <div class="field">
                            <label>Short Description (Used in cards, listings &amp; WhatsApp broadcasts)</label>
                            <textarea name="short_description" rows="3" maxlength="500" 
                                      placeholder="Brief 1-2 sentence overview of this notification or career alert..."><?php echo htmlspecialchars($edit_post['short_description'] ?? ''); ?></textarea>
                            <div class="help">Plain text summary. Maximum 500 characters.</div>
                        </div>
                    </div>
                </div>

                <!-- Full Description with Quill Rich Text Editor -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="head-icon"><i class="fas fa-file-lines"></i></div>
                        <h2>Full Details (Rich Text)</h2>
                    </div>
                    <div class="panel-body">
                        <div class="field">
                            <label>Full Content (Career / Exam / Notification Details)</label>
                            <div id="quillEditor" class="quill-editor-container"><?php echo $edit_post['full_description'] ?? ''; ?></div>
                            <div class="help" style="margin-top: 8px;">
                                Supported: Headings, Bold, Italic, Lists, Tables, Links, and Text Alignment. Content is strictly sanitized on server.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="head-icon"><i class="fas fa-hand-pointer"></i></div>
                        <h2>Call To Action Button (Optional)</h2>
                    </div>
                    <div class="panel-body">
                        <div class="form-grid">
                            <div class="field">
                                <label>Action Button Text</label>
                                <input type="text" name="action_button_text" 
                                       value="<?php echo htmlspecialchars($edit_post['action_button_text'] ?? ''); ?>" 
                                       placeholder="e.g. Apply Online / Download Notification">
                            </div>
                            <div class="field">
                                <label>Action Button URL</label>
                                <input type="text" name="action_button_url" 
                                       value="<?php echo htmlspecialchars($edit_post['action_button_url'] ?? ''); ?>" 
                                       placeholder="https://example.com/apply">
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column: Meta, Categories, Keywords, Banner, Lifecycle -->
            <div style="display: flex; flex-direction: column; gap: 18px;">

                <!-- Publishing Actions Box -->
                <div class="panel" style="border: 1.5px solid var(--accent-soft);">
                    <div class="panel-head" style="background: var(--card);">
                        <div class="head-icon" style="background: var(--surface);"><i class="fas fa-flag"></i></div>
                        <h2>Publishing Status</h2>
                    </div>
                    <div class="panel-body">
                        <div style="font-size: 0.85rem; margin-bottom: 14px;">
                            Current Status: 
                            <span class="badge <?php echo ($edit_post['status'] ?? 'draft') === 'published' ? 'green' : 'amber'; ?>">
                                <?php echo strtoupper($edit_post['status'] ?? 'draft'); ?>
                            </span>
                        </div>

                        <div class="field" style="margin-bottom: 14px;">
                            <label>Publish Date / Time</label>
                            <input type="datetime-local" name="publish_at" 
                                   value="<?php echo !empty($edit_post['publish_at']) ? date('Y-m-d\TH:i', strtotime($edit_post['publish_at'])) : ''; ?>">
                            <div class="help">Leave empty to use current time on publish. Set future date to schedule.</div>
                        </div>

                        <div class="field" style="margin-bottom: 18px;">
                            <label>Expiry Date / Time</label>
                            <input type="datetime-local" name="expires_at" 
                                   value="<?php echo !empty($edit_post['expires_at']) ? date('Y-m-d\TH:i', strtotime($edit_post['expires_at'])) : ''; ?>">
                            <div class="help">Optional. After this timestamp, update automatically enters EXPIRED state.</div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <button type="button" class="btn btn-primary btn-block" onclick="submitFormWithAction('publish')">
                                <i class="fas fa-paper-plane"></i> <?php echo ($edit_post && $edit_post['status'] === 'published') ? 'Update &amp; Keep Published' : 'Publish Update'; ?>
                            </button>
                            <button type="button" class="btn btn-outline btn-block" onclick="submitFormWithAction('draft')">
                                <i class="fas fa-floppy-disk"></i> Save as Draft
                            </button>
                            <button type="button" class="btn btn-secondary btn-block" onclick="previewCurrentForm()" style="background: rgba(124, 58, 237, 0.1); color: #6d28d9; border: 1px solid rgba(124, 58, 237, 0.25);">
                                <i class="fas fa-eye"></i> Preview Draft
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Banner Image Upload -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="head-icon"><i class="fas fa-image"></i></div>
                        <h2>Banner Image</h2>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($edit_post['banner_image'])): ?>
                            <div style="margin-bottom: 12px; text-align: center;">
                                <img src="<?php echo htmlspecialchars($edit_post['banner_image']); ?>" alt="Current Banner" 
                                     style="max-width: 100%; max-height: 160px; border-radius: 8px; border: 1px solid var(--border); object-fit: cover;">
                                <div style="font-size: 0.72rem; color: var(--muted-foreground); margin-top: 4px;">Current Banner Image</div>
                            </div>
                        <?php endif; ?>

                        <div class="field">
                            <label><?php echo !empty($edit_post['banner_image']) ? 'Replace Banner Image' : 'Upload Banner Image'; ?></label>
                            <input type="file" name="banner" accept="image/jpeg,image/png,image/webp">
                            <div class="help">JPG, PNG, or WebP. Maximum size: 5 MB. Stored in <code>/uploads/updates/banners/</code>.</div>
                        </div>
                    </div>
                </div>

                <!-- Categories Junction Selection -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="head-icon"><i class="fas fa-folder-tree"></i></div>
                        <h2>Categories</h2>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($all_categories)): ?>
                            <div style="color: var(--muted-foreground); font-size: 0.85rem;">
                                No active categories found. <a href="pepp-updates-categories.php">Create a category first &rarr;</a>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach ($all_categories as $cat): ?>
                                    <?php $checked = in_array((int)$cat['id'], $edit_category_ids, true); ?>
                                    <label class="category-chip-label">
                                        <input type="checkbox" name="categories[]" value="<?php echo (int)$cat['id']; ?>" <?php echo $checked ? 'checked' : ''; ?> onchange="filterKeywordsByCategory()">
                                        <span><?php echo htmlspecialchars($cat['name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Category-Aware Keywords Selection -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="head-icon"><i class="fas fa-tags"></i></div>
                        <h2>Keywords</h2>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($all_keywords)): ?>
                            <div style="color: var(--muted-foreground); font-size: 0.85rem;">
                                No keywords found. <a href="pepp-updates-keywords.php">Manage keywords &rarr;</a>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 0.76rem; color: var(--muted-foreground); margin-bottom: 8px;">
                                Keywords belong to categories. Selecting categories above highlights matching keywords:
                            </div>
                            <div id="keywordsContainer" style="display: flex; flex-wrap: wrap; gap: 6px;">
                                <?php foreach ($all_keywords as $kw): ?>
                                    <?php 
                                    $kw_checked = in_array((int)$kw['id'], $edit_keyword_ids, true); 
                                    ?>
                                    <label class="keyword-tag-label" data-category-id="<?php echo (int)$kw['category_id']; ?>">
                                        <input type="checkbox" name="keywords[]" value="<?php echo (int)$kw['id']; ?>" <?php echo $kw_checked ? 'checked' : ''; ?>>
                                        <span><?php echo htmlspecialchars($kw['keyword']); ?></span>
                                        <small style="opacity: 0.6; font-size: 0.68rem;">(<?php echo htmlspecialchars($kw['category_name']); ?>)</small>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </form>

    <script>
    var quill = new Quill('#quillEditor', {
        theme: 'snow',
        placeholder: 'Write the complete career / admission / exam update content...',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                [{ 'align': [] }],
                ['blockquote', 'link'],
                ['clean']
            ]
        }
    });

    function submitFormWithAction(type) {
        document.getElementById('submit_type_input').value = type;
        document.getElementById('full_description_input').value = quill.root.innerHTML;
        document.getElementById('postForm').submit();
    }

    function prepareQuillSubmit() {
        document.getElementById('full_description_input').value = quill.root.innerHTML;
    }

    function filterKeywordsByCategory() {
        var selectedCats = [];
        document.querySelectorAll('input[name="categories[]"]:checked').forEach(function(cb) {
            selectedCats.push(parseInt(cb.value));
        });

        document.querySelectorAll('#keywordsContainer .keyword-tag-label').forEach(function(tag) {
            var catId = parseInt(tag.getAttribute('data-category-id'));
            if (selectedCats.length === 0 || selectedCats.includes(catId)) {
                tag.style.display = 'inline-flex';
            } else {
                tag.style.display = 'none';
                tag.querySelector('input').checked = false;
            }
        });
    }

    function previewCurrentForm() {
        var title = document.querySelector('input[name="title"]') ? document.querySelector('input[name="title"]').value : '';
        var shortDesc = document.querySelector('textarea[name="short_description"]') ? document.querySelector('textarea[name="short_description"]').value : '';
        var fullDesc = (typeof quill !== 'undefined' && quill) ? quill.root.innerHTML : '';
        var btnText = document.querySelector('input[name="action_button_text"]') ? document.querySelector('input[name="action_button_text"]').value : '';
        var btnUrl = document.querySelector('input[name="action_button_url"]') ? document.querySelector('input[name="action_button_url"]').value : '';
        var pubAt = document.querySelector('input[name="publish_at"]') ? document.querySelector('input[name="publish_at"]').value : '';
        var expAt = document.querySelector('input[name="expires_at"]') ? document.querySelector('input[name="expires_at"]').value : '';
        var banner = document.querySelector('input[name="existing_banner"]') ? document.querySelector('input[name="existing_banner"]').value : '';

        var catNames = [];
        document.querySelectorAll('input[name="categories[]"]:checked').forEach(function(cb) {
            var label = cb.closest('label');
            if (label) {
                var span = label.querySelector('span');
                if (span) catNames.push(span.textContent.trim());
            }
        });

        openPostPreview({
            title: title || 'Draft Update Preview',
            short_desc: shortDesc,
            full_desc: fullDesc,
            btn_text: btnText,
            btn_url: btnUrl,
            publish_at: pubAt,
            expires_at: expAt,
            banner: banner,
            status: 'draft',
            categories: catNames.join(', ')
        });
    }

    // Run keyword filter on page load
    document.addEventListener('DOMContentLoaded', filterKeywordsByCategory);
    </script>

<?php else: ?>
    <!-- ─────────────────────────────────────────────────────────────
         LIST VIEW WITH SEARCH, FILTERS, PAGINATION, AND ACTIONS
         ───────────────────────────────────────────────────────────── -->
    <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 4px;">All Updates &amp; Notifications</h2>
            <div style="font-size: 0.8rem; color: var(--muted-foreground);">
                Total: <strong><?php echo number_format($total_posts_count); ?></strong> update posts
            </div>
        </div>
        <div>
            <a href="pepp-updates-posts.php?action=create" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add New Update
            </a>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="panel" style="margin-bottom: 20px;">
        <div class="panel-body" style="padding: 14px 18px;">
            <form method="GET" action="pepp-updates-posts.php" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                <div style="flex: 2; min-width: 220px;">
                    <label style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--secondary); margin-bottom: 4px; display: block;">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Search by title or summary..." style="padding: 7px 12px; font-size: 0.86rem;">
                </div>

                <div style="flex: 1; min-width: 140px;">
                    <label style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--secondary); margin-bottom: 4px; display: block;">Status</label>
                    <select name="status" style="padding: 7px 12px; font-size: 0.86rem;">
                        <option value="">All Statuses</option>
                        <option value="published" <?php echo $filter_status === 'published' ? 'selected' : ''; ?>>Published</option>
                        <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                        <option value="expired" <?php echo $filter_status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="archived" <?php echo $filter_status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                    </select>
                </div>

                <div style="flex: 1.2; min-width: 160px;">
                    <label style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--secondary); margin-bottom: 4px; display: block;">Category</label>
                    <select name="category" style="padding: 7px 12px; font-size: 0.86rem;">
                        <option value="0">All Categories</option>
                        <?php foreach ($all_categories as $cat): ?>
                            <option value="<?php echo (int)$cat['id']; ?>" <?php echo $filter_category === (int)$cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn btn-outline" style="padding: 8px 16px;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <?php if ($filter_search !== '' || $filter_status !== '' || $filter_category > 0): ?>
                        <a href="pepp-updates-posts.php" class="btn btn-outline" title="Reset Filters" style="padding: 8px 12px;">
                            <i class="fas fa-rotate-left"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Posts Table -->
    <div class="panel">
        <div class="panel-body flush">
            <?php if (empty($posts_list)): ?>
                <div class="empty-state">
                    <i class="fas fa-newspaper"></i>
                    <p>No update posts match your criteria.</p>
                    <a href="pepp-updates-posts.php?action=create" class="btn btn-sm btn-primary" style="margin-top: 10px;">
                        <i class="fas fa-plus"></i> Create New Update
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
                                <th>Publish Date</th>
                                <th>Expiry Date</th>
                                <th>Views</th>
                                <th>Author</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posts_list as $p): ?>
                                <?php
                                $eff = pepp_updates_effective_status($p['status'], $p['publish_at'], $p['expires_at']);
                                $is_new = pepp_updates_is_new($p['publish_at'], $p['expires_at'], $new_duration);
                                
                                $badge_style = 'gray';
                                if ($eff === 'published') $badge_style = 'green';
                                elseif ($eff === 'draft') $badge_style = 'amber';
                                elseif ($eff === 'scheduled') $badge_style = 'blue';
                                elseif ($eff === 'expired') $badge_style = 'red';
                                elseif ($eff === 'archived') $badge_style = 'gray';
                                ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <?php if (!empty($p['banner_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($p['banner_image']); ?>" alt="" 
                                                     style="width: 44px; height: 44px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); flex-shrink: 0;">
                                            <?php else: ?>
                                                <div style="width: 44px; height: 44px; border-radius: 8px; background: var(--card); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; color: var(--muted-foreground); font-size: 1rem; flex-shrink: 0;">
                                                    <i class="fas fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="cell-main" style="max-width: 260px;">
                                                    <?php echo htmlspecialchars($p['title']); ?>
                                                </div>
                                                <div class="cell-sub" style="margin-top: 2px;">
                                                    <?php if ($is_new): ?>
                                                        <span class="badge violet" style="font-size: 0.6rem; padding: 1px 5px; margin-right: 4px;">NEW</span>
                                                    <?php endif; ?>
                                                    <code>/<?php echo htmlspecialchars($p['slug']); ?></code>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="cell-sub">
                                            <?php echo !empty($p['categories_list']) ? htmlspecialchars($p['categories_list']) : '<em>Uncategorized</em>'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $badge_style; ?>">
                                            <?php echo strtoupper($eff); ?>
                                        </span>
                                        <?php if ($eff === 'expired'): ?>
                                            <div style="font-size: 0.68rem; color: var(--red-ink); margin-top: 2px;">Ended</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="cell-sub">
                                            <?php echo !empty($p['publish_at']) ? date('d M Y, h:i A', strtotime($p['publish_at'])) : '—'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="cell-sub">
                                            <?php echo !empty($p['expires_at']) ? date('d M Y, h:i A', strtotime($p['expires_at'])) : 'Never'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge gray">
                                            <i class="fas fa-eye" style="font-size: 0.65rem;"></i> <?php echo number_format((int)($p['visit_count'] ?? 0)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="cell-sub">
                                            <?php echo !empty($p['created_by']) ? htmlspecialchars($p['created_by']) : 'Admin'; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px; align-items: center;">
                                            <!-- Read-only Preview Modal Button -->
                                            <button type="button" class="btn btn-sm btn-outline" title="Preview Update" 
                                                    onclick="openPostPreview(<?php echo htmlspecialchars(json_encode([
                                                        'id' => $p['id'],
                                                        'title' => $p['title'],
                                                        'banner' => $p['banner_image'],
                                                        'short_desc' => $p['short_description'],
                                                        'full_desc' => $p['full_description'],
                                                        'categories' => $p['categories_list'],
                                                        'status' => $eff,
                                                        'publish_at' => $p['publish_at'] ? date('d M Y, h:i A', strtotime($p['publish_at'])) : 'N/A',
                                                        'expires_at' => $p['expires_at'] ? date('d M Y, h:i A', strtotime($p['expires_at'])) : 'None',
                                                        'btn_text' => $p['action_button_text'],
                                                        'btn_url' => $p['action_button_url']
                                                    ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <!-- Edit Post -->
                                            <a href="pepp-updates-posts.php?action=edit&id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline" title="Edit Post">
                                                <i class="fas fa-pen"></i>
                                            </a>

                                            <!-- Publish / Draft Quick Toggle -->
                                            <?php if ($p['status'] === 'published'): ?>
                                                <a href="pepp-updates-posts.php?action=toggle_publish&id=<?php echo (int)$p['id']; ?>&csrf_token=<?php echo csrf_token(); ?>" 
                                                   class="btn btn-sm btn-soft-amber" title="Revert to Draft" 
                                                   onclick="return confirm('Revert post #<?php echo (int)$p['id']; ?> back to DRAFT?');">
                                                    <i class="fas fa-pause"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="pepp-updates-posts.php?action=toggle_publish&id=<?php echo (int)$p['id']; ?>&csrf_token=<?php echo csrf_token(); ?>" 
                                                   class="btn btn-sm btn-soft-green" title="Publish Post Now" 
                                                   onclick="return confirm('Publish post #<?php echo (int)$p['id']; ?> immediately?');">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Delete Post -->
                                            <a href="pepp-updates-posts.php?action=delete&id=<?php echo (int)$p['id']; ?>&csrf_token=<?php echo csrf_token(); ?>" 
                                               class="btn btn-sm btn-soft-red" title="Delete Post" 
                                               onclick="return confirm('Are you sure you want to permanently delete post #<?php echo (int)$p['id']; ?>? This action cannot be undone.');">
                                                <i class="fas fa-trash"></i>
                                            </a>
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
                                <a href="pepp-updates-posts.php?p=<?php echo $page - 1; ?>&search=<?php echo urlencode($filter_search); ?>&status=<?php echo urlencode($filter_status); ?>&category=<?php echo $filter_category; ?>" class="btn btn-sm btn-outline">
                                    &larr; Prev
                                </a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="pepp-updates-posts.php?p=<?php echo $i; ?>&search=<?php echo urlencode($filter_search); ?>&status=<?php echo urlencode($filter_status); ?>&category=<?php echo $filter_category; ?>" 
                                   class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="pepp-updates-posts.php?p=<?php echo $page + 1; ?>&search=<?php echo urlencode($filter_search); ?>&status=<?php echo urlencode($filter_status); ?>&category=<?php echo $filter_category; ?>" class="btn btn-sm btn-outline">
                                    Next &rarr;
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<!-- ─────────────────────────────────────────────────────────────
     READ-ONLY PREVIEW MODAL
     ───────────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="previewModal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-box" style="background: var(--surface); border-radius: 14px; max-width: 680px; width: 100%; max-height: 88vh; overflow-y: auto; box-shadow: 0 20px 45px rgba(0,0,0,0.2); border: 1px solid var(--border);">
        <div class="modal-head" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 22px; border-bottom: 1px solid var(--border);">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="head-icon"><i class="fas fa-eye"></i></div>
                <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700;">Update Post Preview</h3>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="closePostPreview()" style="font-size: 1rem; padding: 4px 10px;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 22px;">
            <div id="pvBannerWrap" style="margin-bottom: 16px; display: none; text-align: center;">
                <img id="pvBanner" src="" alt="Banner" style="max-width: 100%; max-height: 240px; border-radius: 10px; object-fit: cover; border: 1px solid var(--border);">
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px;">
                <span id="pvStatusBadge" class="badge gray">DRAFT</span>
                <span id="pvCategories" style="font-size: 0.78rem; color: var(--secondary);"></span>
            </div>

            <h1 id="pvTitle" style="font-size: 1.35rem; font-weight: 700; line-height: 1.35; margin-bottom: 12px; color: var(--foreground);"></h1>

            <div id="pvShortDesc" style="font-size: 0.92rem; color: var(--secondary); margin-bottom: 16px; padding: 10px 14px; background: var(--card); border-radius: 8px; border-left: 3px solid var(--accent); line-height: 1.5;"></div>

            <div id="pvFullDesc" style="font-size: 0.9rem; line-height: 1.65; color: var(--foreground); margin-bottom: 20px;"></div>

            <div id="pvActionWrap" style="margin-bottom: 20px; display: none;">
                <a id="pvActionBtn" href="#" target="_blank" class="btn btn-primary" style="padding: 10px 22px;"></a>
            </div>

            <div style="border-top: 1px solid var(--border); padding-top: 12px; font-size: 0.75rem; color: var(--muted-foreground); display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <div>Publish Date: <span id="pvPublishAt"></span></div>
                <div>Expiry: <span id="pvExpiresAt"></span></div>
            </div>
        </div>
        <div class="modal-foot" style="padding: 12px 22px; border-top: 1px solid var(--border); text-align: right; background: var(--card);">
            <button type="button" class="btn btn-outline" onclick="closePostPreview()">Close Preview</button>
        </div>
    </div>
</div>

<script>
function openPostPreview(data) {
    document.getElementById('pvTitle').textContent = data.title || '';
    
    var bannerWrap = document.getElementById('pvBannerWrap');
    var bannerImg = document.getElementById('pvBanner');
    if (data.banner) {
        bannerImg.src = data.banner;
        bannerWrap.style.display = 'block';
    } else {
        bannerWrap.style.display = 'none';
    }

    var stBadge = document.getElementById('pvStatusBadge');
    stBadge.textContent = (data.status || 'draft').toUpperCase();
    stBadge.className = 'badge ' + (data.status === 'published' ? 'green' : (data.status === 'scheduled' ? 'blue' : (data.status === 'expired' ? 'red' : 'amber')));

    document.getElementById('pvCategories').textContent = data.categories ? ('in ' + data.categories) : '';

    var sDesc = document.getElementById('pvShortDesc');
    if (data.short_desc) {
        sDesc.textContent = data.short_desc;
        sDesc.style.display = 'block';
    } else {
        sDesc.style.display = 'none';
    }

    document.getElementById('pvFullDesc').innerHTML = data.full_desc || '';

    var actWrap = document.getElementById('pvActionWrap');
    var actBtn = document.getElementById('pvActionBtn');
    if (data.btn_text && data.btn_url) {
        actBtn.textContent = data.btn_text;
        actBtn.href = data.btn_url;
        actWrap.style.display = 'block';
    } else {
        actWrap.style.display = 'none';
    }

    document.getElementById('pvPublishAt').textContent = data.publish_at || 'N/A';
    document.getElementById('pvExpiresAt').textContent = data.expires_at || 'Never';

    var modal = document.getElementById('previewModal');
    modal.style.display = 'flex';
}

function closePostPreview() {
    document.getElementById('previewModal').style.display = 'none';
}
</script>

<?php
include 'includes/admin_footer.php';
?>
