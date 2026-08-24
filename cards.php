<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/file_helper.php';

if (!can_access('cards') && !can_access('card-templates')) {
    require_permission('cards');
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS clipart_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$success_message = '';
$error_message = '';

// ── Action: Upload Custom Font ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_access('card-templates')) {
        $error_message = 'Access Denied. You do not have permission to modify card templates or assets.';
        $_POST = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_font') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $font_name = trim($_POST['font_name'] ?? '');
        if (!$font_name) {
            $error_message = 'Please specify a user-friendly font name.';
        } elseif (empty($_FILES['font_file']['name']) || $_FILES['font_file']['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'Please select a valid font file.';
        } else {
            $filename = basename($_FILES['font_file']['name']);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'], true)) {
                $error_message = 'Only TTF, OTF, WOFF, and WOFF2 font formats are supported.';
            } else {
                $base_dir = __DIR__ . '/../uploads/custom_fonts';
                if (!is_dir($base_dir)) {
                    @mkdir($base_dir, 0755, true);
                }
                $safe_filename = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
                $target_path = $base_dir . '/' . $safe_filename;

                if (@move_uploaded_file($_FILES['font_file']['tmp_name'], $target_path)) {
                    $db_path = 'uploads/custom_fonts/' . $safe_filename;
                    try {
                        $stmt = $pdo->prepare("INSERT INTO custom_fonts (font_name, font_file) VALUES (?, ?)");
                        $stmt->execute([$font_name, $db_path]);
                        $success_message = "Font '{$font_name}' uploaded and registered successfully.";
                    } catch (Exception $e) {
                        @unlink($target_path);
                        $error_message = "Database error: " . $e->getMessage();
                    }
                } else {
                    $error_message = 'Failed to move uploaded font file.';
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_logo') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $univ_name = trim($_POST['univ_name'] ?? '');
        $width = (int)($_POST['univ_width'] ?? 150);
        $height = (int)($_POST['univ_height'] ?? 150);
        $dpi = (int)($_POST['univ_dpi'] ?? 72);

        if (!$univ_name) {
            $error_message = 'Please specify a university name.';
        } elseif (empty($_FILES['logo_file']['name']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'Please select a valid image logo file.';
        } else {
            $filename = basename($_FILES['logo_file']['name']);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                $error_message = 'Only PNG, JPG, and JPEG logo formats are supported.';
            } else {
                $base_dir = __DIR__ . '/../uploads/logos';
                if (!is_dir($base_dir)) {
                    @mkdir($base_dir, 0755, true);
                }
                $safe_filename = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
                $target_path = $base_dir . '/' . $safe_filename;

                if (@move_uploaded_file($_FILES['logo_file']['tmp_name'], $target_path)) {
                    $db_path = 'uploads/logos/' . $safe_filename;
                    try {
                        $stmt = $pdo->prepare("INSERT INTO university_logos (name, logo_file, width, height, dpi) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$univ_name, $db_path, $width, $height, $dpi]);
                        $success_message = "Logo for '{$univ_name}' uploaded successfully.";
                    } catch (Exception $e) {
                        @unlink($target_path);
                        $error_message = "Database error: " . $e->getMessage();
                    }
                } else {
                    $error_message = 'Failed to move uploaded logo file.';
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_clipart') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $clipart_name = trim($_POST['clipart_name'] ?? '');
        if (!$clipart_name) {
            $error_message = 'Please specify a clipart name.';
        } elseif (empty($_FILES['clipart_file']['name']) || $_FILES['clipart_file']['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'Please select a valid image file.';
        } else {
            $filename = basename($_FILES['clipart_file']['name']);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                $error_message = 'Only PNG, JPG, and JPEG formats are supported.';
            } else {
                $base_dir = __DIR__ . '/../uploads/cliparts';
                if (!is_dir($base_dir)) {
                    @mkdir($base_dir, 0755, true);
                }
                $safe_filename = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
                $target_path = $base_dir . '/' . $safe_filename;

                if (@move_uploaded_file($_FILES['clipart_file']['tmp_name'], $target_path)) {
                    $db_path = 'uploads/cliparts/' . $safe_filename;
                    try {
                        $stmt = $pdo->prepare("INSERT INTO clipart_images (name, file_path) VALUES (?, ?)");
                        $stmt->execute([$clipart_name, $db_path]);
                        $success_message = "Clipart '{$clipart_name}' uploaded successfully.";
                    } catch (Exception $e) {
                        @unlink($target_path);
                        $error_message = "Database error: " . $e->getMessage();
                    }
                } else {
                    $error_message = 'Failed to move uploaded clipart file.';
                }
            }
        }
    }
}

// ── Action: Toggle / Delete / Clone Template ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'clone_template') {
        if (!csrf_verify()) {
            $error_message = 'Security token mismatch.';
        } else {
            $tid = (int)($_POST['template_id'] ?? 0);
            $new_title = trim($_POST['new_title'] ?? '');
            if (!$tid) {
                $error_message = 'Invalid template specified for cloning.';
            } elseif (empty($new_title)) {
                $error_message = 'Please specify a title for the cloned template.';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM card_templates WHERE id = ?");
                    $stmt->execute([$tid]);
                    $orig = $stmt->fetch();
                    if (!$orig) {
                        $error_message = 'Source template not found.';
                    } else {
                        // Duplicate background image if exists
                        $new_bg_db_path = $orig['bg_image'];
                        if (!empty($orig['bg_image'])) {
                            $orig_file_path = __DIR__ . '/../' . ltrim($orig['bg_image'], '/');
                            if (file_exists($orig_file_path)) {
                                $target_dir = __DIR__ . '/../uploads/card_templates';
                                if (!is_dir($target_dir)) {
                                    @mkdir($target_dir, 0755, true);
                                }
                                $new_filename = uniqid('clone_') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($orig['bg_image']));
                                $new_file_path = $target_dir . '/' . $new_filename;
                                if (@copy($orig_file_path, $new_file_path)) {
                                    $new_bg_db_path = 'uploads/card_templates/' . $new_filename;
                                }
                            }
                        }

                        $insert_stmt = $pdo->prepare("
                            INSERT INTO card_templates
                            (title, category, description, bg_image, canvas_width, canvas_height, resolution_dpi, aspect_ratio, status, elements_json, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insert_stmt->execute([
                            $new_title,
                            $orig['category'],
                            $orig['description'],
                            $new_bg_db_path,
                            $orig['canvas_width'],
                            $orig['canvas_height'],
                            $orig['resolution_dpi'] ?? 72,
                            $orig['aspect_ratio'],
                            $orig['status'] ?? 'active',
                            $orig['elements_json'],
                            $admin_username
                        ]);
                        $success_message = "Template cloned successfully as '" . htmlspecialchars($new_title) . "'.";
                    }
                } catch (Exception $e) {
                    $error_message = "Failed to clone template: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_template') {
        if (!csrf_verify()) {
            $error_message = 'Security token mismatch.';
        } else {
            $tid = (int)($_POST['template_id'] ?? 0);
            try {
                $stmt = $pdo->prepare("SELECT bg_image FROM card_templates WHERE id = ?");
                $stmt->execute([$tid]);
                $bg = $stmt->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM card_templates WHERE id = ?");
                $stmt->execute([$tid]);

                if ($bg) {
                    $real_bg = __DIR__ . '/../' . $bg;
                    if (file_exists($real_bg)) {
                        @unlink($real_bg);
                    }
                }
                $success_message = "Template deleted successfully.";
            } catch (Exception $e) {
                $error_message = "Failed to delete template: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_font') {
        if (!csrf_verify()) {
            $error_message = 'Security token mismatch.';
        } else {
            $fid = (int)($_POST['font_id'] ?? 0);
            try {
                $stmt = $pdo->prepare("SELECT font_file FROM custom_fonts WHERE id = ?");
                $stmt->execute([$fid]);
                $file = $stmt->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM custom_fonts WHERE id = ?");
                $stmt->execute([$fid]);

                if ($file) {
                    $real_file = __DIR__ . '/../' . $file;
                    if (file_exists($real_file)) {
                        @unlink($real_file);
                    }
                }
                $success_message = "Font deleted successfully.";
            } catch (Exception $e) {
                $error_message = "Failed to delete font: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_logo') {
        if (!csrf_verify()) {
            $error_message = 'Security token mismatch.';
        } else {
            $lid = (int)($_POST['logo_id'] ?? 0);
            try {
                $stmt = $pdo->prepare("SELECT logo_file FROM university_logos WHERE id = ?");
                $stmt->execute([$lid]);
                $file = $stmt->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM university_logos WHERE id = ?");
                $stmt->execute([$lid]);

                if ($file) {
                    $real_file = __DIR__ . '/../' . $file;
                    if (file_exists($real_file)) {
                        @unlink($real_file);
                    }
                }
                $success_message = "Logo deleted successfully.";
            } catch (Exception $e) {
                $error_message = "Failed to delete logo: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_clipart') {
        if (!csrf_verify()) {
            $error_message = 'Security token mismatch.';
        } else {
            $cid = (int)($_POST['clipart_id'] ?? 0);
            try {
                $stmt = $pdo->prepare("SELECT file_path FROM clipart_images WHERE id = ?");
                $stmt->execute([$cid]);
                $file = $stmt->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM clipart_images WHERE id = ?");
                $stmt->execute([$cid]);

                if ($file) {
                    $real_file = __DIR__ . '/../' . $file;
                    if (file_exists($real_file)) {
                        @unlink($real_file);
                    }
                }
                $success_message = "Clipart deleted successfully.";
            } catch (Exception $e) {
                $error_message = "Failed to delete clipart: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_result_card') {
        if (!csrf_verify()) {
            $error_message = 'Security token mismatch.';
        } else {
            $rc_id = (int)($_POST['result_card_id'] ?? 0);
            try {
                $stmt = $pdo->prepare("SELECT output_file FROM test_result_cards WHERE id = ?");
                $stmt->execute([$rc_id]);
                $file = $stmt->fetchColumn();

                $stmt = $pdo->prepare("DELETE FROM test_result_cards WHERE id = ?");
                $stmt->execute([$rc_id]);

                if ($file) {
                    $real_file = __DIR__ . '/../' . $file;
                    if (file_exists($real_file)) {
                        @unlink($real_file);
                    }
                }
                $success_message = "Test Result Card deleted successfully.";
            } catch (Exception $e) {
                $error_message = "Failed to delete result card: " . $e->getMessage();
            }
        }
    }
}

// ── Load Templates, Categories, and Fonts ────────────
$active_tab = $_GET['tab'] ?? 'generate'; // 'generate', 'templates', or 'test_results'
if ($active_tab === 'templates' && !can_access('card-templates')) {
    $active_tab = 'generate';
}
if (($active_tab === 'generate' || $active_tab === 'test_results') && !can_access('cards')) {
    $active_tab = 'templates';
}

// Load additional lists for Test Result Cards tab
$academic_years = [];
$result_templates = [];
$saved_cards = [];

if ($active_tab === 'test_results') {
    try {
        $academic_years = $pdo->query("SELECT year FROM academic_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
    } catch(Exception $e){}
    try {
        $result_templates = $pdo->query("SELECT id, title, category FROM card_templates WHERE status = 'active' ORDER BY title ASC")->fetchAll();
    } catch(Exception $e){}
    try {
        $saved_cards = $pdo->query("
            SELECT trc.*, ct.title AS template_title
            FROM test_result_cards trc
            LEFT JOIN card_templates ct ON trc.template_id = ct.id
            ORDER BY trc.created_at DESC
        ")->fetchAll();
    } catch(Exception $e){}
}
$search_q = trim($_GET['search'] ?? '');
$cat_filter = trim($_GET['category'] ?? '');

$categories = [
    'Flyer' => 'Flyers & Postings',
    'Poster' => 'Creative Posters',
    'Greeting Card' => 'Greetings & Wishes',
    'Achievement' => 'Achievement Cards',
    'Admission' => 'Admission Flyers',
    'Alumni' => 'Alumni Identity Cards',
    'Certificate' => 'Certificates',
    'Marketing' => 'Marketing Creatives',
    'Other' => 'Other Designs'
];

// Query active templates for Generate tab
$gen_where = ["status = 'active'"];
$gen_params = [];
if ($search_q) {
    $gen_where[] = "title LIKE ?";
    $gen_params[] = "%$search_q%";
}
if ($cat_filter) {
    $gen_where[] = "category = ?";
    $gen_params[] = $cat_filter;
}
$gen_sql = "SELECT * FROM card_templates WHERE " . implode(' AND ', $gen_where) . " ORDER BY created_at DESC";
$gen_stmt = $pdo->prepare($gen_sql);
$gen_stmt->execute($gen_params);
$active_templates = $gen_stmt->fetchAll();

// Query all templates for Templates tab
$tpl_where = ["1=1"];
$tpl_params = [];
if ($search_q) {
    $tpl_where[] = "title LIKE ?";
    $tpl_params[] = "%$search_q%";
}
if ($cat_filter) {
    $tpl_where[] = "category = ?";
    $tpl_params[] = $cat_filter;
}
$tpl_sql = "SELECT * FROM card_templates WHERE " . implode(' AND ', $tpl_where) . " ORDER BY created_at DESC";
$tpl_stmt = $pdo->prepare($tpl_sql);
$tpl_stmt->execute($tpl_params);
$all_templates = $tpl_stmt->fetchAll();

// Query custom fonts
$fonts = [];
try {
    $fonts = $pdo->query("SELECT * FROM custom_fonts ORDER BY font_name ASC")->fetchAll();
} catch (Exception $e) {}

$logos = [];
try {
    $logos = $pdo->query("SELECT * FROM university_logos ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {}

$cliparts = [];
try {
    $cliparts = $pdo->query("SELECT * FROM clipart_images ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {}

$active_page = 'cards';
$page_title  = 'Generate Custom Cards';
$page_sub    = 'Design reusable flyer/certificate templates and generate personalized graphics';
include 'includes/admin_nav.php';
?>

<style>
.tab-row {
    display: flex;
    gap: 15px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 20px;
}
.tab-btn {
    padding: 10px 20px;
    font-weight: 700;
    font-size: 0.95rem;
    color: #64748b;
    text-decoration: none;
    border-bottom: 3px solid transparent;
    transition: 0.2s;
}
.tab-btn.active {
    color: var(--accent);
    border-bottom-color: var(--accent);
}
.cards-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
}
@media (max-width: 900px) {
    .cards-layout {
        grid-template-columns: 1fr;
    }
}
.templates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 20px;
}
.tpl-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    transition: transform 0.2s, box-shadow 0.2s;
}
.tpl-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
}
.tpl-preview {
    height: 180px;
    background-size: cover;
    background-position: center;
    background-color: #f1f5f9;
    position: relative;
    border-bottom: 1px solid #e2e8f0;
}
.tpl-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    background: rgba(255,255,255,0.9);
    color: #334155;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.tpl-details {
    padding: 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.tpl-title {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 6px 0;
    color: #0f172a;
}
.tpl-desc {
    font-size: 0.8rem;
    color: #64748b;
    margin-bottom: 14px;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.font-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8fafc;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    margin-bottom: 8px;
    font-size: 0.85rem;
}
</style>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="tab-row">
    <?php if (can_access('cards')): ?>
        <a href="cards.php?tab=generate" class="tab-btn <?php echo $active_tab === 'generate' ? 'active' : ''; ?>"><i class="fas fa-magic"></i> Generate Cards</a>
        <a href="cards.php?tab=test_results" class="tab-btn <?php echo $active_tab === 'test_results' ? 'active' : ''; ?>"><i class="fas fa-award"></i> Test Result Cards</a>
    <?php endif; ?>
    <?php if (can_access('card-templates')): ?>
        <a href="cards.php?tab=templates" class="tab-btn <?php echo $active_tab === 'templates' ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i> Card Templates</a>
    <?php endif; ?>
</div>

<div class="cards-layout" <?php if (!can_access('card-templates')) echo 'style="grid-template-columns: 1fr;"'; ?>>
    <!-- Left Area: Active View -->
    <div>
        <?php if ($active_tab !== 'test_results'): ?>
        <!-- Search Bar & Categories -->
        <div class="panel" style="margin-bottom: 20px;">
            <div class="panel-body">
                <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                    <div class="field grow-2" style="margin:0;">
                        <label>Search Templates</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="e.g. Certificate, Alumni Flyer...">
                    </div>
                    <div class="field" style="margin:0;">
                        <label>Category</label>
                        <select name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $ck => $cv): ?>
                                <option value="<?php echo $ck; ?>" <?php echo $cat_filter === $ck ? 'selected' : ''; ?>><?php echo $cv; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                    <a href="cards.php?tab=<?php echo htmlspecialchars($active_tab); ?>" class="btn btn-outline">Reset</a>

                    <?php if ($active_tab === 'templates'): ?>
                        <a href="cards-edit.php" class="btn btn-soft-violet" style="margin-left:auto;"><i class="fas fa-plus"></i> Create Card Template</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- tab: Test Result Cards -->
        <?php if ($active_tab === 'test_results'): ?>
            <!-- Selection Wizard -->
            <div class="panel" style="margin-bottom: 20px; overflow: visible; position: relative; z-index: 10;">
                <div class="panel-head">
                    <h3><i class="fas fa-magic" style="color:var(--accent);"></i> Design Test Result Card</h3>
                </div>
                <div class="panel-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
                        <div class="field" style="margin:0;">
                            <label>Academic Year <span style="color:#ef4444;">*</span></label>
                            <select id="sel-year" onchange="loadPublishedTests(this.value)">
                                <option value="">— Select Year —</option>
                                <?php foreach ($academic_years as $y): ?>
                                    <option value="<?php echo htmlspecialchars($y); ?>"><?php echo htmlspecialchars($y); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" style="margin:0; position: relative;">
                            <label>Published Test / Result <span style="color:#ef4444;">*</span></label>
                            <div id="searchable-test-dropdown" style="position: relative;">
                                <input type="text" id="test-search-input" placeholder="— Select Academic Year First —" disabled style="width: 100%; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 4px;" onfocus="showDropdownMenu()" oninput="filterDropdownMenu()">
                                <input type="hidden" id="sel-test" onchange="selectPublishedTest(this.value)">
                                <div class="dropdown-menu" id="test-dropdown-menu" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; max-height: 250px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 0 0 4px 4px;">
                                    <!-- Options populated dynamically -->
                                </div>
                            </div>
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Background Template <span style="color:#ef4444;">*</span></label>
                            <select id="sel-template" onchange="updateStartButton()">
                                <?php if (empty($result_templates)): ?>
                                    <option value="">No eligible card templates found. Please create a template under Card Templates.</option>
                                <?php else: ?>
                                    <option value="">— Select Template —</option>
                                    <?php foreach ($result_templates as $rt): ?>
                                        <?php
                                        $cat_label = $categories[$rt['category']] ?? $rt['category'];
                                        $option_title = $rt['title'] . ' (' . $cat_label . ')';
                                        ?>
                                        <option value="<?php echo $rt['id']; ?>" <?php echo $rt['title'] === 'Mega Test Result Template' ? 'selected' : ''; ?>><?php echo htmlspecialchars($option_title); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <button type="button" id="btn-start-design" class="btn btn-primary" onclick="startResultDesigner()" disabled>
                            <i class="fas fa-palette"></i> Start Designing
                        </button>
                    </div>
                </div>
            </div>

            <!-- Selected Test Summary -->
            <div id="selected-test-summary" class="panel" style="display: none; margin-bottom: 20px; border-left: 4px solid var(--accent);">
                <div class="panel-body" style="padding: 16px;">
                    <h4 style="margin: 0; font-size: 1.1rem; color: #1e293b; font-weight: 800;">SELECTED TEST</h4>
                    <h3 id="summary-test-title" style="margin: 4px 0 12px 0; color: var(--accent); font-size: 1.4rem; font-weight: 800;">—</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; font-size: 0.85rem; border-top: 1px solid #e2e8f0; padding-top: 12px;">
                        <div>
                            <strong>Study Plan:</strong>
                            <div id="summary-plan-title" style="margin-top: 4px; font-size: 0.95rem; font-weight: 700; color: #334155;">—</div>
                        </div>
                        <div>
                            <strong>Academic Year:</strong>
                            <div id="summary-year" style="margin-top: 4px; font-size: 0.95rem; font-weight: 700; color: #334155;">—</div>
                        </div>
                        <div>
                            <strong>Assigned Courses:</strong>
                            <ul id="summary-assigned-courses-list" style="margin: 4px 0 0 0; padding-left: 20px; color: #334155;">
                                <!-- Bullet list of courses populated dynamically -->
                            </ul>
                        </div>
                        <div>
                            <strong>Total Students:</strong>
                            <div id="summary-total-students" style="margin-top: 4px; font-size: 1.1rem; font-weight: 800; color: var(--accent);">0</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Course-wise participation summary -->
            <div id="course-participation-panel" class="panel" style="display: none; margin-bottom: 20px;">
                <div class="panel-head" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px;">
                    <h3 style="margin: 0;"><i class="fas fa-users" style="color:var(--accent);"></i> Course Participation Summary</h3>
                    <button type="button" id="btn-view-merged" class="btn btn-secondary" onclick="toggleMergedResults()" style="display: none; font-size: 0.75rem; padding: 6px 12px;">
                        <i class="fas fa-list-ol"></i> View Merged Result
                    </button>
                </div>
                <div class="panel-body" style="padding: 0; overflow-x: auto;">
                    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; text-align: left;">
                                <th style="padding: 10px 16px;">Course</th>
                                <th style="padding: 10px 16px; text-align: right;">Total Students</th>
                                <th style="padding: 10px 16px; text-align: right;">Attended</th>
                                <th style="padding: 10px 16px; text-align: right;">Unattended</th>
                                <th style="padding: 10px 16px; text-align: center;">Result Available</th>
                            </tr>
                        </thead>
                        <tbody id="course-participation-tbody">
                            <!-- Courses rows loaded dynamically -->
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8fafc; border-top: 2px solid #cbd5e1; font-weight: bold;">
                                <td style="padding: 12px 16px;">Total</td>
                                <td style="padding: 12px 16px; text-align: right;" id="total-students-sum">0</td>
                                <td style="padding: 12px 16px; text-align: right;" id="total-attended-sum">0</td>
                                <td style="padding: 12px 16px; text-align: right;" id="total-unattended-sum">0</td>
                                <td style="padding: 12px 16px;"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Merged Results Table -->
            <div id="merged-results-panel" class="panel" style="display: none; margin-bottom: 20px;">
                <div class="panel-head" style="padding: 12px 16px;">
                    <h3 style="margin: 0;"><i class="fas fa-trophy" style="color:var(--accent);"></i> Merged Ranking</h3>
                </div>
                <div class="panel-body" style="padding: 0; overflow-x: auto; max-height: 400px;">
                    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; text-align: left;">
                                <th style="padding: 10px 16px; width: 80px;">Rank</th>
                                <th style="padding: 10px 16px;">Student ID</th>
                                <th style="padding: 10px 16px;">Student Name</th>
                                <th style="padding: 10px 16px;">Course Name</th>
                                <th style="padding: 10px 16px;">College/School</th>
                                <th style="padding: 10px 16px; text-align: right; width: 100px;">Score</th>
                            </tr>
                        </thead>
                        <tbody id="merged-results-tbody">
                            <!-- Merged rows loaded dynamically -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Saved designs list -->
            <div class="panel">
                <div class="panel-head">
                    <h3><i class="fas fa-floppy-disk" style="color:var(--accent);"></i> Saved Test Result Cards</h3>
                </div>
                <div class="panel-body" style="padding: 0; overflow-x: auto;">
                    <?php if (empty($saved_cards)): ?>
                        <div style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 8px; display: block; opacity: 0.5;"></i>
                            <p>No saved result cards found. Use the filters above to design your first card.</p>
                        </div>
                    <?php else: ?>
                        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; text-align: left;">
                                    <th style="padding: 12px 16px;">Design Title</th>
                                    <th style="padding: 12px 16px;">Template</th>
                                    <th style="padding: 12px 16px;">Details</th>
                                    <th style="padding: 12px 16px;">Created By</th>
                                    <th style="padding: 12px 16px;">Created At</th>
                                    <th style="padding: 12px 16px; text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($saved_cards as $sc): ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9; vertical-align: middle;">
                                        <td style="padding: 12px 16px; font-weight: 700; color: #1e293b;">
                                            <?php echo htmlspecialchars($sc['design_title']); ?>
                                        </td>
                                        <td style="padding: 12px 16px; color: #64748b;">
                                            <?php echo htmlspecialchars($sc['template_title'] ?: 'Unknown'); ?>
                                        </td>
                                        <td style="padding: 12px 16px; color: #64748b; font-size: 0.8rem;">
                                            <strong>Year:</strong> <?php echo htmlspecialchars($sc['academic_year']); ?><br>
                                            <strong>Test ID:</strong> <?php echo $sc['activity_id']; ?>
                                        </td>
                                        <td style="padding: 12px 16px; color: #64748b;">
                                            <?php echo htmlspecialchars($sc['created_by']); ?>
                                        </td>
                                        <td style="padding: 12px 16px; color: #94a3b8; font-size: 0.8rem;">
                                            <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($sc['created_at']))); ?>
                                        </td>
                                        <td style="padding: 12px 16px; text-align: right;">
                                            <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                                <a href="cards-result-designer.php?id=<?php echo $sc['id']; ?>" class="btn btn-sm btn-outline" style="padding: 4px 8px; font-size: 0.72rem;"><i class="fas fa-edit"></i> Edit</a>
                                                <?php if ($sc['output_file'] && file_exists(__DIR__ . '/../' . $sc['output_file'])): ?>
                                                    <a href="../<?php echo htmlspecialchars($sc['output_file']); ?>" download class="btn btn-sm btn-soft-violet" style="padding: 4px 8px; font-size: 0.72rem;"><i class="fas fa-download"></i> Download</a>
                                                <?php endif; ?>
                                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this saved result card?');" style="margin:0;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_result_card">
                                                    <input type="hidden" name="result_card_id" value="<?php echo $sc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-soft-red" style="padding: 4px 8px; font-size: 0.72rem;"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- JavaScript helper for selectors -->
            <script>
            // Searchable Dropdown control
            function showDropdownMenu() {
                const year = document.getElementById('sel-year').value;
                if (year) {
                    document.getElementById('test-dropdown-menu').style.display = 'block';
                }
            }

            function hideDropdownMenu() {
                setTimeout(() => {
                    document.getElementById('test-dropdown-menu').style.display = 'none';
                }, 250);
            }

            function filterDropdownMenu() {
                const query = document.getElementById('test-search-input').value.toLowerCase().trim();
                if (!window.publishedTestsList) return;

                const filtered = window.publishedTestsList.filter(t => {
                    const title = (t.activity_title || '').toLowerCase();
                    const type = (t.activity_type || '').toLowerCase();
                    const chapter = (t.chapter || '').toLowerCase();
                    const plan = (t.plan_title || '').toLowerCase();
                    const date = (t.activity_date || '').toLowerCase();
                    const day = (t.day_number || '').toLowerCase();
                    return title.includes(query) || type.includes(query) || chapter.includes(query) || plan.includes(query) || date.includes(query) || day.includes(query);
                });
                renderDropdownOptions(filtered);
                showDropdownMenu();
            }

            function renderDropdownOptions(list) {
                const menu = document.getElementById('test-dropdown-menu');
                menu.innerHTML = '';
                if (list.length === 0) {
                    menu.innerHTML = '<div style="padding: 10px; color: #94a3b8; text-align: center;">No matching tests found</div>';
                    return;
                }
                list.forEach(t => {
                    const div = document.createElement('div');
                    div.style.padding = '10px 12px';
                    div.style.cursor = 'pointer';
                    div.style.borderBottom = '1px solid #f1f5f9';
                    div.style.fontSize = '0.85rem';

                    let dateStr = '';
                    if (t.activity_date) {
                        const d = new Date(t.activity_date);
                        dateStr = d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
                    }

                    const testNo = t.day_number ? 'Test ' + t.day_number : 'Test #' + t.activity_id;
                    const optionLabel = `${t.activity_title} | ${testNo} | ${t.plan_title}`;

                    div.innerHTML = `
                        <div style="font-weight: 700; color: #1e293b;">${optionLabel}</div>
                        <div style="font-size: 0.75rem; color: #64748b;">
                            ${t.activity_type} ${t.chapter ? '• ' + t.chapter : ''} ${dateStr ? '• ' + dateStr : ''}
                        </div>
                    `;
                    div.onclick = () => {
                        document.getElementById('test-search-input').value = optionLabel;
                        const hiddenInput = document.getElementById('sel-test');
                        const newVal = `${t.study_plan_id}_${t.activity_id}`;
                        hiddenInput.value = newVal;

                        // Trigger selectPublishedTest explicitly
                        selectPublishedTest(newVal);
                    };
                    div.onmouseenter = () => { div.style.background = '#f8fafc'; };
                    div.onmouseleave = () => { div.style.background = '#fff'; };
                    menu.appendChild(div);
                });
            }

            document.addEventListener('click', function(e) {
                const container = document.getElementById('searchable-test-dropdown');
                if (container && !container.contains(e.target)) {
                    document.getElementById('test-dropdown-menu').style.display = 'none';
                }
            });

            // Loading Published Tests
            function loadPublishedTests(year) {
                const hiddenInput = document.getElementById('sel-test');
                hiddenInput.value = '';
                document.getElementById('test-search-input').value = '';
                document.getElementById('test-search-input').placeholder = '— Loading... —';
                document.getElementById('test-search-input').disabled = true;

                // Hide panels
                document.getElementById('selected-test-summary').style.display = 'none';
                document.getElementById('course-participation-panel').style.display = 'none';
                document.getElementById('btn-view-merged').style.display = 'none';
                document.getElementById('merged-results-panel').style.display = 'none';

                updateStartButton();

                if (!year) {
                    document.getElementById('test-search-input').placeholder = '— Select Academic Year First —';
                    return;
                }

                fetch('assessment-results.php?action=get_published_tests_by_year&year=' + encodeURIComponent(year))
                    .then(r => r.json()).then(tests => {
                        const menu = document.getElementById('test-dropdown-menu');
                        menu.innerHTML = '';
                        if (tests.length === 0) {
                            menu.innerHTML = '<div style="padding: 10px; color: #94a3b8; text-align: center;">— No Published Results —</div>';
                            document.getElementById('test-search-input').placeholder = '— No Published Results —';
                            document.getElementById('test-search-input').disabled = true;
                            return;
                        }
                        document.getElementById('test-search-input').disabled = false;
                        document.getElementById('test-search-input').placeholder = '🔍 Search published tests...';
                        window.publishedTestsList = tests;
                        renderDropdownOptions(tests);
                    });
            }

            // Test selection logic
            function selectPublishedTest(val) {
                const year = document.getElementById('sel-year').value;
                document.getElementById('merged-results-panel').style.display = 'none';
                updateStartButton();

                if (!val) {
                    document.getElementById('selected-test-summary').style.display = 'none';
                    document.getElementById('course-participation-panel').style.display = 'none';
                    document.getElementById('btn-view-merged').style.display = 'none';
                    return;
                }

                const parts = val.split('_');
                const planId = parts[0];
                const activityId = parts[1];

                // Fetch Summary & Courses
                fetch(`assessment-results.php?action=get_course_participation_summary&year=${encodeURIComponent(year)}&plan_id=${planId}&activity_id=${activityId}`)
                    .then(r => r.json()).then(data => {
                        if (!data.success) {
                            alert(data.message || 'Failed to load details.');
                            return;
                        }

                        // Display Summary
                        document.getElementById('summary-test-title').innerText = data.activity_title;
                        document.getElementById('summary-plan-title').innerText = data.plan_title;
                        document.getElementById('summary-year').innerText = year;

                        // Display Table rows & Courses Bullet list
                        const tbody = document.getElementById('course-participation-tbody');
                        tbody.innerHTML = '';

                        const listUl = document.getElementById('summary-assigned-courses-list');
                        listUl.innerHTML = '';

                        let totalStud = 0, totalAtt = 0, totalUnatt = 0;

                        data.courses.forEach(c => {
                            totalStud += c.total_students;
                            totalAtt += c.attended;
                            totalUnatt += c.unattended;

                            // Add to assigned courses list
                            const li = document.createElement('li');
                            li.innerText = c.course_name;
                            listUl.appendChild(li);

                            tbody.innerHTML += `
                                <tr style="border-bottom: 1px solid #f1f5f9; vertical-align: middle;">
                                    <td style="padding: 10px 16px; font-weight: 700; color: #1e293b;">${c.course_name}</td>
                                    <td style="padding: 10px 16px; text-align: right;">${c.total_students}</td>
                                    <td style="padding: 10px 16px; text-align: right;">${c.attended}</td>
                                    <td style="padding: 10px 16px; text-align: right;">${c.unattended}</td>
                                    <td style="padding: 10px 16px; text-align: center;">
                                        <span class="badge badge-${c.result_available === 'Yes' ? 'success' : 'danger'}">${c.result_available}</span>
                                    </td>
                                </tr>
                            `;
                        });

                        document.getElementById('summary-total-students').innerText = totalStud;
                        document.getElementById('total-students-sum').innerText = totalStud;
                        document.getElementById('total-attended-sum').innerText = totalAtt;
                        document.getElementById('total-unattended-sum').innerText = totalUnatt;

                        document.getElementById('selected-test-summary').style.display = 'block';
                        document.getElementById('course-participation-panel').style.display = 'block';
                        document.getElementById('btn-view-merged').style.display = 'inline-block';
                    });
            }

            function toggleMergedResults() {
                const panel = document.getElementById('merged-results-panel');
                if (panel.style.display === 'block') {
                    panel.style.display = 'none';
                    return;
                }

                const year = document.getElementById('sel-year').value;
                const val = document.getElementById('sel-test').value;
                if (!val) return;

                const parts = val.split('_');
                const planId = parts[0];
                const activityId = parts[1];

                const tbody = document.getElementById('merged-results-tbody');
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px;">Loading merged ranking...</td></tr>';
                panel.style.display = 'block';

                fetch(`assessment-results.php?action=get_merged_results&year=${encodeURIComponent(year)}&plan_id=${planId}&activity_id=${activityId}`)
                    .then(r => r.json()).then(data => {
                        if (!data.success) {
                            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#ef4444; padding:20px;">Failed to load rankings.</td></tr>';
                            return;
                        }

                        tbody.innerHTML = '';
                        if (data.results.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No results available to display.</td></tr>';
                            return;
                        }

                        data.results.forEach(r => {
                            tbody.innerHTML += `
                                <tr style="border-bottom: 1px solid #f1f5f9; vertical-align: middle;">
                                    <td style="padding: 10px 16px; font-weight: 700; color: var(--accent);"># ${r.computed_rank}</td>
                                    <td style="padding: 10px 16px;">${r.user_id || '—'}</td>
                                    <td style="padding: 10px 16px; font-weight: 700; color: #1e293b;">${r.name || '—'}</td>
                                    <td style="padding: 10px 16px;">${r.course_name || '—'}</td>
                                    <td style="padding: 10px 16px; color:#64748b;">${r.college_school || '—'}</td>
                                    <td style="padding: 10px 16px; text-align: right; font-weight: 700; color: #0f172a;">${r.score}</td>
                                </tr>
                            `;
                        });
                    });
            }

            function updateStartButton() {
                const year = document.getElementById('sel-year').value;
                const testVal = document.getElementById('sel-test').value;
                const template = document.getElementById('sel-template').value;

                const btn = document.getElementById('btn-start-design');
                if (btn) btn.disabled = !(year && testVal && template);
            }

            function startResultDesigner() {
                const year = document.getElementById('sel-year').value;
                const testVal = document.getElementById('sel-test').value;
                const template = document.getElementById('sel-template').value;

                const parts = testVal.split('_');
                const planId = parts[0];
                const activityId = parts[1];

                // course_id is set to 0 for merged context designer loading
                window.location.href = `cards-result-designer.php?year=${encodeURIComponent(year)}&course_id=0&plan_id=${planId}&activity_id=${activityId}&template_id=${template}`;
            }
            </script>
        <?php endif; ?>

        <!-- tab: Generate -->
        <?php if ($active_tab === 'generate'): ?>
            <?php if (empty($active_templates)): ?>
                <div class="empty-state" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:40px;">
                    <i class="fas fa-id-card"></i>
                    <p>No active templates found. Go to <strong>Card Templates</strong> to create one.</p>
                </div>
            <?php else: ?>
                <div class="templates-grid">
                    <?php foreach ($active_templates as $tpl):
                        $bg_style = $tpl['bg_image'];
                        if (strpos($bg_style, 'gradient') !== false) {
                            $bg_css = "background: " . $bg_style . ";";
                        } elseif (strpos($bg_style, '#') === 0 || strpos($bg_style, 'rgb') === 0) {
                            $bg_css = "background-color: " . $bg_style . ";";
                        } else {
                            $bg_css = "background-image: url('../" . htmlspecialchars($bg_style) . "');";
                        }
                    ?>
                        <div class="tpl-card">
                            <div class="tpl-preview" style="<?php echo $bg_css; ?>">
                                <span class="tpl-badge"><?php echo htmlspecialchars($categories[$tpl['category']] ?? $tpl['category']); ?></span>
                            </div>
                            <div class="tpl-details">
                                <div>
                                    <h3 class="tpl-title"><?php echo htmlspecialchars($tpl['title']); ?></h3>
                                    <p class="tpl-desc"><?php echo htmlspecialchars($tpl['description'] ?: 'Personalized promotional flyer.'); ?></p>
                                </div>
                                <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:#64748b;">
                                    <span><?php echo $tpl['canvas_width'] . 'x' . $tpl['canvas_height']; ?> px</span>
                                    <?php if (can_access('cards')): ?>
                                        <a href="cards-generate.php?template_id=<?php echo (int)$tpl['id']; ?>" class="btn btn-sm btn-primary" style="padding: 4px 10px; font-size: 0.75rem;"><i class="fas fa-magic"></i> Generate</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- tab: Templates -->
        <?php if ($active_tab === 'templates'): ?>
            <?php if (empty($all_templates)): ?>
                <div class="empty-state" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:40px;">
                    <i class="fas fa-layer-group"></i>
                    <p>No templates created yet. Click <strong>Create Card Template</strong> above to get started.</p>
                </div>
            <?php else: ?>
                <div class="templates-grid">
                    <?php foreach ($all_templates as $tpl):
                        $bg_style = $tpl['bg_image'];
                        if (strpos($bg_style, 'gradient') !== false) {
                            $bg_css = "background: " . $bg_style . ";";
                        } elseif (strpos($bg_style, '#') === 0 || strpos($bg_style, 'rgb') === 0) {
                            $bg_css = "background-color: " . $bg_style . ";";
                        } else {
                            $bg_css = "background-image: url('../" . htmlspecialchars($bg_style) . "');";
                        }
                    ?>
                        <div class="tpl-card">
                            <div class="tpl-preview" style="<?php echo $bg_css; ?>">
                                <span class="tpl-badge"><?php echo htmlspecialchars($categories[$tpl['category']] ?? $tpl['category']); ?></span>
                                <span style="position:absolute; bottom:10px; right:10px;" class="badge <?php echo $tpl['status'] === 'active' ? 'green' : 'gray'; ?>">
                                    <?php echo ucfirst($tpl['status']); ?>
                                </span>
                            </div>
                            <div class="tpl-details">
                                <div>
                                    <h3 class="tpl-title"><?php echo htmlspecialchars($tpl['title']); ?></h3>
                                    <p class="tpl-desc"><?php echo htmlspecialchars($tpl['description'] ?: 'Personalized card design.'); ?></p>
                                </div>
                                <div style="display:flex; gap:6px; align-items:center; font-size:0.75rem;">
                                    <?php if (can_access('card-templates')): ?>
                                        <a href="cards-edit.php?id=<?php echo (int)$tpl['id']; ?>" class="btn btn-sm btn-outline" style="padding:4px 8px; font-size:0.72rem; flex:1; text-align:center;"><i class="fas fa-edit"></i> Edit</a>
                                        <button type="button" class="btn btn-sm btn-soft-violet" style="padding:4px 8px; font-size:0.72rem; flex:1; text-align:center;" onclick="openCloneModal(<?php echo (int)$tpl['id']; ?>, <?php echo htmlspecialchars(json_encode($tpl['title']), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fas fa-copy"></i> Clone</button>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this template?');" style="flex:1;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_template">
                                            <input type="hidden" name="template_id" value="<?php echo (int)$tpl['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-soft-red" style="padding:4px 8px; font-size:0.72rem; width:100%;"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (can_access('card-templates')): ?>
        <!-- Right Area: Sidebar for Fonts -->
        <div>
            <div class="panel">
                <div class="panel-head">
                    <h3><i class="fas fa-font" style="color:var(--accent);"></i> Custom Fonts</h3>
                </div>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" style="margin-bottom: 20px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="upload_font">
                        <div class="field full">
                            <label>Font Name</label>
                            <input type="text" name="font_name" placeholder="e.g. Montserrat Bold" required>
                        </div>
                        <div class="field full">
                            <label>File (.ttf, .otf, .woff)</label>
                            <input type="file" name="font_file" accept=".ttf,.otf,.woff,.woff2" required>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary" style="width:100%;"><i class="fas fa-upload"></i> Upload Font</button>
                    </form>

                    <h4 style="font-size:0.8rem; font-weight:700; color:#475569; margin:15px 0 8px 0; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">Installed Fonts</h4>
                    <?php if (empty($fonts)): ?>
                        <div style="text-align:center; padding:15px 0; font-size:0.8rem; color:#94a3b8;"><p>No custom fonts uploaded yet.</p></div>
                    <?php else: foreach ($fonts as $f): ?>
                        <div class="font-list-item">
                            <span><strong><?php echo htmlspecialchars($f['font_name']); ?></strong></span>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="font-size:0.7rem; color:#94a3b8;"><?php echo strtoupper(pathinfo($f['font_file'], PATHINFO_EXTENSION)); ?></span>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this font?');" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_font">
                                    <input type="hidden" name="font_id" value="<?php echo (int)$f['id']; ?>">
                                    <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:0; font-size:0.85rem; line-height:1;" title="Delete Font"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="panel" style="margin-top: 15px;">
                <div class="panel-head">
                    <h3><i class="fas fa-university" style="color:var(--accent);"></i> University Logos</h3>
                </div>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" style="margin-bottom: 20px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="upload_logo">
                        <div class="field full">
                            <label>University Name</label>
                            <input type="text" name="univ_name" placeholder="e.g. Calicut University" required>
                        </div>
                        <div class="field full">
                            <label>Logo File (.png, .jpg, .jpeg)</label>
                            <input type="file" name="logo_file" accept=".png,.jpg,.jpeg" required>
                        </div>
                        <div class="prop-group" style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <div class="field">
                                <label>Width (px)</label>
                                <input type="number" name="univ_width" value="150" required>
                            </div>
                            <div class="field">
                                <label>Height (px)</label>
                                <input type="number" name="univ_height" value="150" required>
                            </div>
                        </div>
                        <div class="field full" style="margin-top:6px;">
                            <label>Resolution (DPI)</label>
                            <input type="number" name="univ_dpi" value="72" required>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary" style="width:100%; margin-top:10px;"><i class="fas fa-upload"></i> Upload Logo</button>
                    </form>

                    <h4 style="font-size:0.8rem; font-weight:700; color:#475569; margin:15px 0 8px 0; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">Preset Logos</h4>
                    <?php if (empty($logos)): ?>
                        <div style="text-align:center; padding:15px 0; font-size:0.8rem; color:#94a3b8;"><p>No preset logos uploaded yet.</p></div>
                    <?php else: foreach ($logos as $l): ?>
                        <div class="font-list-item" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; padding:6px 0;">
                            <div>
                                <span style="font-size:0.8rem; font-weight:700; display:block;"><?php echo htmlspecialchars($l['name']); ?></span>
                                <span style="font-size:0.65rem; color:#94a3b8;"><?php echo $l['width'] . 'x' . $l['height'] . ' px @ ' . $l['dpi'] . ' DPI'; ?></span>
                            </div>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this logo?');" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_logo">
                                <input type="hidden" name="logo_id" value="<?php echo (int)$l['id']; ?>">
                                <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:0; font-size:0.85rem; line-height:1;" title="Delete Logo"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="panel" style="margin-top: 15px;">
                <div class="panel-head">
                    <h3><i class="fas fa-shapes" style="color:var(--accent);"></i> Clipart Images</h3>
                </div>
                <div class="panel-body">
                    <form method="POST" enctype="multipart/form-data" style="margin-bottom: 20px;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="upload_clipart">
                        <div class="field full">
                            <label>Clipart Name</label>
                            <input type="text" name="clipart_name" placeholder="e.g. Star Logo" required>
                        </div>
                        <div class="field full">
                            <label>Image File (.png, .jpg, .jpeg)</label>
                            <input type="file" name="clipart_file" accept=".png,.jpg,.jpeg" required>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary" style="width:100%; margin-top:10px;"><i class="fas fa-upload"></i> Upload Clipart</button>
                    </form>

                    <h4 style="font-size:0.8rem; font-weight:700; color:#475569; margin:15px 0 8px 0; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">Available Cliparts</h4>
                    <?php if (empty($cliparts)): ?>
                        <div style="text-align:center; padding:15px 0; font-size:0.8rem; color:#94a3b8;"><p>No cliparts uploaded yet.</p></div>
                    <?php else: foreach ($cliparts as $c): ?>
                        <div class="font-list-item" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; padding:6px 0;">
                            <div>
                                <span style="font-size:0.8rem; font-weight:700; display:block;"><?php echo htmlspecialchars($c['name']); ?></span>
                            </div>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this clipart?');" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_clipart">
                                <input type="hidden" name="clipart_id" value="<?php echo (int)$c['id']; ?>">
                                <button type="submit" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:0; font-size:0.85rem; line-height:1;" title="Delete Clipart"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
<!-- ── CLONE TEMPLATE MODAL ── -->
<div class="modal-backdrop" id="clone-modal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-head">
            <h3><i class="fas fa-copy" style="color:var(--accent);"></i> Clone Card Template</h3>
            <button type="button" class="modal-close" onclick="closeModal('clone-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clone_template">
            <input type="hidden" name="template_id" id="clone-template-id" value="">
            <div class="modal-body">
                <div class="field full" style="margin-bottom:0;">
                    <label>New Template Title <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="new_title" id="clone-template-title" placeholder="Enter new template title..." required>
                    <small style="color:#64748b; font-size:0.75rem; display:block; margin-top:6px;">
                        This will create a new copy of the template design and duplicate its background image file.
                    </small>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('clone-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-copy"></i> Clone Template</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCloneModal(id, currentTitle) {
    document.getElementById('clone-template-id').value = id;
    document.getElementById('clone-template-title').value = currentTitle + ' (Copy)';
    openModal('clone-modal');
    setTimeout(function() {
        var input = document.getElementById('clone-template-title');
        if (input) {
            input.focus();
            input.select();
        }
    }, 100);
}
</script>

<?php include 'includes/admin_footer.php'; ?>

