<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/file_helper.php';

require_permission('cards');

$success_message = '';
$error_message = '';

// ── Action: Upload Custom Font ────────────
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
}

// ── Action: Toggle / Delete Template ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_template') {
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
    }
}

// ── Load Templates, Categories, and Fonts ────────────
$active_tab = $_GET['tab'] ?? 'generate'; // 'generate' or 'templates'
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
    <a href="cards.php?tab=generate" class="tab-btn <?php echo $active_tab === 'generate' ? 'active' : ''; ?>"><i class="fas fa-magic"></i> Generate Cards</a>
    <a href="cards.php?tab=templates" class="tab-btn <?php echo $active_tab === 'templates' ? 'active' : ''; ?>"><i class="fas fa-layer-group"></i> Card Templates</a>
</div>

<div class="cards-layout">
    <!-- Left Area: Active View -->
    <div>
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

        <!-- tab: Generate -->
        <?php if ($active_tab === 'generate'): ?>
            <?php if (empty($active_templates)): ?>
                <div class="empty-state" style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:40px;">
                    <i class="fas fa-id-card"></i>
                    <p>No active templates found. Go to <strong>Card Templates</strong> to create one.</p>
                </div>
            <?php else: ?>
                <div class="templates-grid">
                    <?php foreach ($active_templates as $tpl): ?>
                        <div class="tpl-card">
                            <div class="tpl-preview" style="background-image: url('../<?php echo htmlspecialchars($tpl['bg_image']); ?>');">
                                <span class="tpl-badge"><?php echo htmlspecialchars($categories[$tpl['category']] ?? $tpl['category']); ?></span>
                            </div>
                            <div class="tpl-details">
                                <div>
                                    <h3 class="tpl-title"><?php echo htmlspecialchars($tpl['title']); ?></h3>
                                    <p class="tpl-desc"><?php echo htmlspecialchars($tpl['description'] ?: 'Personalized promotional flyer.'); ?></p>
                                </div>
                                <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:#64748b;">
                                    <span><?php echo $tpl['canvas_width'] . 'x' . $tpl['canvas_height']; ?> px</span>
                                    <a href="cards-generate.php?template_id=<?php echo (int)$tpl['id']; ?>" class="btn btn-sm btn-primary" style="padding: 4px 10px; font-size: 0.75rem;"><i class="fas fa-magic"></i> Generate</a>
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
                    <?php foreach ($all_templates as $tpl): ?>
                        <div class="tpl-card">
                            <div class="tpl-preview" style="background-image: url('../<?php echo htmlspecialchars($tpl['bg_image']); ?>');">
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
                                    <a href="cards-edit.php?id=<?php echo (int)$tpl['id']; ?>" class="btn btn-sm btn-outline" style="padding:4px 8px; font-size:0.72rem; flex:1; text-align:center;"><i class="fas fa-edit"></i> Edit</a>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this template?');" style="flex:1;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_template">
                                        <input type="hidden" name="template_id" value="<?php echo (int)$tpl['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-soft-red" style="padding:4px 8px; font-size:0.72rem; width:100%;"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

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
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
