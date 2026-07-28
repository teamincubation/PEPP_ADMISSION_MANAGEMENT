<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/file_helper.php';

require_permission('cards');

$success_message = '';
$error_message = '';

$template_id = (int)($_GET['id'] ?? 0);
$tpl = null;
$bg_image_path = '';
$canvas_w = 800;
$canvas_h = 600;
$aspect_ratio = '4:3';

if ($template_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM card_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $tpl = $stmt->fetch();
        if ($tpl) {
            $bg_image_path = $tpl['bg_image'];
            $canvas_w = $tpl['canvas_width'];
            $canvas_h = $tpl['canvas_height'];
            $aspect_ratio = $tpl['aspect_ratio'];
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ── Action: Upload Background Image or Create Color/Gradient (Step 1) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_bg') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch.';
    } else {
        $title = trim($_POST['title'] ?? 'Untitled Template');
        $category = trim($_POST['category'] ?? 'Other');
        $description = trim($_POST['description'] ?? '');
        $bg_source_type = $_POST['bg_source_type'] ?? 'file';
        
        $bg_path = null;
        $width = 800;
        $height = 600;
        
        if ($bg_source_type === 'file') {
            $bg_path = handle_file_upload_with_replace('bg_file', 'card_templates', null, ['jpg', 'jpeg', 'png', 'webp']);
            if (!$bg_path) {
                $error_message = 'Please select a valid high-quality background image.';
            } else {
                $real_path = __DIR__ . '/../' . $bg_path;
                $dims = @getimagesize($real_path);
                $width = $dims ? $dims[0] : 800;
                $height = $dims ? $dims[1] : 600;
            }
        } else {
            // Gradient or Solid color background
            $bg_path = trim($_POST['bg_gradient_val'] ?? $_POST['bg_color_val'] ?? 'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)');
            $width = max(100, (int)($_POST['preset_width'] ?? 800));
            $height = max(100, (int)($_POST['preset_height'] ?? 600));
        }
        
        if ($bg_path) {
            // Calculate aspect ratio
            $gcd = function($a, $b) use (&$gcd) {
                return ($a % $b) ? $gcd($b, $a % $b) : $b;
            };
            $g = $gcd($width, $height);
            $ratio_w = $width / $g;
            $ratio_h = $height / $g;
            $ratio_str = "$ratio_w:$ratio_h";
            
            try {
                if ($template_id) {
                    // Update existing
                    $stmt = $pdo->prepare("UPDATE card_templates SET title = ?, category = ?, description = ?, bg_image = ?, canvas_width = ?, canvas_height = ?, aspect_ratio = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$title, $category, $description, $bg_path, $width, $height, $ratio_str, $template_id]);
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("INSERT INTO card_templates (title, category, description, bg_image, canvas_width, canvas_height, aspect_ratio, elements_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, '[]', ?)");
                    $stmt->execute([$title, $category, $description, $bg_path, $width, $height, $ratio_str, $admin_username]);
                    $template_id = $pdo->lastInsertId();
                }
                header("Location: cards-edit.php?id=" . $template_id);
                exit;
            } catch (Exception $e) {
                $error_message = "Failed to save template: " . $e->getMessage();
            }
        }
    }
}

// ── Action: Save Template Configurations (AJAX) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_template') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
        exit;
    }
    
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $elements_json = $_POST['elements_json'] ?? '[]';
    $manual_w = (int)($_POST['canvas_width'] ?? 0);
    $manual_h = (int)($_POST['canvas_height'] ?? 0);
    $resolution_dpi = (int)($_POST['resolution_dpi'] ?? 72);
    
    if (!$title) {
        echo json_encode(['success' => false, 'message' => 'Template title is required.']);
        exit;
    }
    
    try {
        // Optional background file upload
        $bg_path = null;
        if (!empty($_FILES['bg_file']['name']) && $_FILES['bg_file']['error'] === UPLOAD_ERR_OK) {
            // Retrieve old background image to replace
            $stmt = $pdo->prepare("SELECT bg_image FROM card_templates WHERE id = ?");
            $stmt->execute([$template_id]);
            $old_bg = $stmt->fetchColumn();
            
            $bg_path = handle_file_upload_with_replace('bg_file', 'card_templates', $old_bg, ['jpg', 'jpeg', 'png', 'webp']);
            if ($bg_path) {
                // If a new background was uploaded, set default canvas width/height to the new background's dimensions
                $real_path = __DIR__ . '/../' . $bg_path;
                $dims = @getimagesize($real_path);
                if ($dims) {
                    $manual_w = $dims[0];
                    $manual_h = $dims[1];
                }
            }
        }
        
        $bg_path_style = trim($_POST['bg_path_style'] ?? '');
        if ($bg_path) {
            $stmt = $pdo->prepare("UPDATE card_templates SET title = ?, category = ?, description = ?, status = ?, bg_image = ?, canvas_width = ?, canvas_height = ?, resolution_dpi = ?, elements_json = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$title, $category, $description, $status, $bg_path, $manual_w, $manual_h, $resolution_dpi, $elements_json, $template_id]);
        } elseif ($bg_path_style !== '') {
            $stmt = $pdo->prepare("UPDATE card_templates SET title = ?, category = ?, description = ?, status = ?, bg_image = ?, canvas_width = ?, canvas_height = ?, resolution_dpi = ?, elements_json = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$title, $category, $description, $status, $bg_path_style, $manual_w, $manual_h, $resolution_dpi, $elements_json, $template_id]);
        } else {
            if ($manual_w > 0 && $manual_h > 0) {
                $stmt = $pdo->prepare("UPDATE card_templates SET title = ?, category = ?, description = ?, status = ?, elements_json = ?, canvas_width = ?, canvas_height = ?, resolution_dpi = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$title, $category, $description, $status, $elements_json, $manual_w, $manual_h, $resolution_dpi, $template_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE card_templates SET title = ?, category = ?, description = ?, status = ?, elements_json = ?, resolution_dpi = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$title, $category, $description, $status, $elements_json, $resolution_dpi, $template_id]);
            }
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Query custom fonts
$fonts = [];
try {
    $fonts = $pdo->query("SELECT * FROM custom_fonts ORDER BY font_name ASC")->fetchAll();
} catch (Exception $e) {}

$active_page = 'cards';
$page_title  = $template_id ? 'Edit Card Template' : 'Create Card Template';
$page_sub    = 'Upload background and construct dynamic fields for customizable posters/certificates';
include 'includes/admin_nav.php';
?>

<style>
<?php foreach ($fonts as $f): ?>
@font-face {
    font-family: '<?php echo addslashes($f['font_name']); ?>';
    src: url('../<?php echo $f['font_file']; ?>');
}
<?php endforeach; ?>

.editor-grid {
    display: grid;
    grid-template-columns: 240px 1fr 280px;
    gap: 16px;
    height: calc(100vh - 180px);
    min-height: 550px;
}
@media (max-width: 992px) {
    .editor-grid {
        grid-template-columns: 1fr;
        height: auto;
    }
}
.sidebar-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    overflow-y: auto;
}
.workspace {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    position: relative;
    overflow: auto;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}
.canvas-container {
    position: relative;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    background-size: 100% 100%;
    background-repeat: no-repeat;
    background-position: center;
    background-color: #fff;
}
.canvas-element {
    position: absolute;
    cursor: move;
    user-select: none;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    white-space: nowrap;
}
.canvas-element.selected {
    outline: 2px dashed var(--accent);
    outline-offset: 1px;
}
.canvas-element .delete-handle {
    position: absolute;
    top: -10px;
    right: -10px;
    background: #ef4444;
    color: #fff;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
    cursor: pointer;
}
.canvas-element.selected .delete-handle {
    display: flex;
}
.layer-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8fafc;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    cursor: grab;
    font-size: 0.82rem;
}
.layer-row.selected {
    border-color: var(--accent);
    background: #f0fdf4;
}
.prop-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
</style>

<?php if (!$bg_image_path): ?>
    <!-- STEP 1: Template Properties & Background Selection -->
    <div class="panel" style="max-width: 600px; margin: 40px auto;">
        <div class="panel-head">
            <h3><i class="fas fa-layer-group" style="color:var(--accent);"></i> New Card Template details</h3>
        </div>
        <form method="POST" enctype="multipart/form-data" class="panel-body">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="upload_bg">
            <div class="field full">
                <label>Template Title <span class="req">*</span></label>
                <input type="text" name="title" placeholder="e.g. Batch 2026 Welcome Poster" required>
            </div>
            <div class="field full">
                <label>Category <span class="req">*</span></label>
                <select name="category">
                    <option value="Flyer">Flyers &amp; Postings</option>
                    <option value="Poster">Creative Posters</option>
                    <option value="Greeting Card">Greetings &amp; Wishes</option>
                    <option value="Achievement">Achievement Cards</option>
                    <option value="Admission">Admission Flyers</option>
                    <option value="Alumni">Alumni Identity Cards</option>
                    <option value="Certificate">Certificates</option>
                    <option value="Marketing">Marketing Creatives</option>
                    <option value="Other">Other Designs</option>
                </select>
            </div>
            <div class="field full">
                <label>Purpose / Description</label>
                <textarea name="description" rows="2" placeholder="Describe the template use case..."></textarea>
            </div>
            <div class="field full">
                <label>Background Layer Source <span class="req">*</span></label>
                <select name="bg_source_type" id="bg-source-type-select" onchange="toggleStep1BgFields(this.value)">
                    <option value="file">Upload Custom Background Image File</option>
                    <option value="gradient" selected>Pastel Gradient / Color Background</option>
                </select>
            </div>

            <div id="step1-bg-file-block" style="display:none;" class="field full">
                <label>Upload Background Image (PNG, JPG, WEBP)</label>
                <input type="file" name="bg_file" id="step1-bg-file-input" accept=".jpg,.jpeg,.png,.webp">
                <p class="cell-sub" style="margin-top: 4px;">This image acts as the template canvas background.</p>
            </div>

            <div id="step1-bg-gradient-block" class="field full">
                <label>Select Preset Pastel Gradient / Background</label>
                <input type="hidden" name="bg_gradient_val" id="step1-bg-gradient-val" value="linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)">
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(42px, 1fr)); gap:8px; margin:8px 0 14px 0;" id="step1-gradient-swatches">
                    <!-- JavaScript dynamically populates pastel swatches -->
                </div>
                <div class="prop-group">
                    <div class="field">
                        <label>Canvas Width (px)</label>
                        <input type="number" name="preset_width" value="800" required>
                    </div>
                    <div class="field">
                        <label>Canvas Height (px)</label>
                        <input type="number" name="preset_height" value="600" required>
                    </div>
                </div>
            </div>

            <div style="margin-top:20px; display:flex; gap:12px;">
                <a href="cards.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-right"></i> Next: Design Canvas</button>
            </div>
        </form>
    </div>
<?php else: ?>
    <!-- STEP 2: The WYSIWYG Template Editor -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; gap:12px; align-items:center;">
            <a href="cards.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <h2 style="margin:0; font-size:1.2rem; font-weight:800;"><?php echo htmlspecialchars($tpl['title']); ?></h2>
            <span class="badge soft-green"><?php echo htmlspecialchars($tpl['category']); ?></span>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <button type="button" id="btn-undo" class="btn btn-outline btn-sm" onclick="undo()" disabled title="Undo (Ctrl+Z)"><i class="fas fa-rotate-left"></i> Undo</button>
            <button type="button" id="btn-redo" class="btn btn-outline btn-sm" onclick="redo()" disabled title="Redo (Ctrl+Y)"><i class="fas fa-rotate-right"></i> Redo</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="openCanvasResize()"><i class="fas fa-maximize"></i> Canvas Properties</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveTemplate()"><i class="fas fa-save"></i> Save Template Configuration</button>
        </div>
    </div>

    <div class="editor-grid">
        <!-- Panel 1: Toolbar / Elements -->
        <div class="sidebar-panel">
            <h4 style="font-weight:700; border-bottom:1px solid #eee; padding-bottom:6px; margin:0 0 6px 0;">Add Element</h4>
            <button class="btn btn-sm btn-outline" style="text-align:left;" onclick="addElement('text', 'Student Name')"><i class="fas fa-user-tag"></i> Student Name</button>
            <button class="btn btn-sm btn-outline" style="text-align:left;" onclick="addElement('text', 'Alumni Name')"><i class="fas fa-user-graduate"></i> Alumni Name</button>
            <button class="btn btn-sm btn-outline" style="text-align:left;" onclick="addElement('text', 'Course Name')"><i class="fas fa-book"></i> Course Name</button>
            <button class="btn btn-sm btn-outline" style="text-align:left;" onclick="addElement('text', 'Achievement')"><i class="fas fa-trophy"></i> Achievement</button>
            <button class="btn btn-sm btn-outline" style="text-align:left;" onclick="addElement('text', 'Certificate No')"><i class="fas fa-certificate"></i> Certificate No</button>
            <button class="btn btn-sm btn-outline" style="text-align:left;" onclick="addElement('text', 'Batch Name')"><i class="fas fa-users"></i> Batch Name</button>
            <button class="btn btn-sm btn-outline" style="text-align:left;" onclick="addElement('text', 'Date')"><i class="fas fa-calendar"></i> Current Date</button>
            <button class="btn btn-sm btn-outline" style="text-align:left;" onclick="addElement('text', 'Your Custom Text')"><i class="fas fa-font"></i> Free Text field</button>
            <button class="btn btn-sm btn-soft-violet" style="text-align:left;" onclick="addElement('photo', 'Student Photo')"><i class="fas fa-image"></i> Photo Placeholder</button>
            <button class="btn btn-sm btn-soft-violet" style="text-align:left;" onclick="addElement('photo', 'University Logo')"><i class="fas fa-university"></i> University Logo</button>
            
            <h4 style="font-weight:700; border-bottom:1px solid #eee; padding-bottom:6px; margin:15px 0 6px 0;">Layers Management</h4>
            <div id="layers-list" style="display:flex; flex-direction:column; gap:6px;">
                <!-- Dynamically filled -->
            </div>

            <h4 style="font-weight:700; border-bottom:1px solid #eee; padding-bottom:6px; margin:15px 0 6px 0;"><i class="fas fa-palette" style="color:var(--accent);"></i> Canvas Background</h4>
            <div style="display:flex; gap:4px; margin-bottom:6px;">
                <button type="button" class="btn btn-xs btn-outline bg-tab-btn" id="bg-tab-btn-pastel" style="flex:1; font-size:0.7rem; padding:3px;" onclick="showBgTab('pastel')">Pastels</button>
                <button type="button" class="btn btn-xs btn-outline bg-tab-btn" id="bg-tab-btn-custom" style="flex:1; font-size:0.7rem; padding:3px;" onclick="showBgTab('custom')">Custom</button>
                <button type="button" class="btn btn-xs btn-outline bg-tab-btn" id="bg-tab-btn-solid" style="flex:1; font-size:0.7rem; padding:3px;" onclick="showBgTab('solid')">Solid</button>
            </div>

            <!-- Pastel Presets Grid -->
            <div id="bg-tab-pastel" style="display:block;">
                <div id="pastel-presets-grid" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; max-height:160px; overflow-y:auto; padding:2px;">
                    <!-- Filled dynamically -->
                </div>
            </div>

            <!-- Custom Gradient Builder -->
            <div id="bg-tab-custom" style="display:none; background:#f8fafc; padding:8px; border-radius:8px; border:1px solid #e2e8f0;">
                <div class="field" style="margin-bottom:6px;">
                    <label style="font-size:0.72rem; margin-bottom:2px;">Type</label>
                    <select id="cust-grad-type" onchange="updateCustomGradient()" style="font-size:0.75rem; padding:3px 6px;">
                        <option value="linear">Linear Gradient</option>
                        <option value="radial">Radial Gradient</option>
                    </select>
                </div>
                <div class="prop-group" style="margin-bottom:6px;">
                    <div class="field">
                        <label style="font-size:0.72rem; margin-bottom:2px;">Start Color</label>
                        <input type="color" id="cust-grad-c1" value="#a1c4fd" oninput="updateCustomGradient()" style="height:28px; width:100%; cursor:pointer;">
                    </div>
                    <div class="field">
                        <label style="font-size:0.72rem; margin-bottom:2px;">End Color</label>
                        <input type="color" id="cust-grad-c2" value="#c2e9fb" oninput="updateCustomGradient()" style="height:28px; width:100%; cursor:pointer;">
                    </div>
                </div>
                <div class="field" style="margin-bottom:6px;" id="cust-grad-angle-block">
                    <label style="font-size:0.72rem; margin-bottom:2px;">Angle: <span id="cust-angle-val">135</span>&deg;</label>
                    <input type="range" id="cust-grad-angle" min="0" max="360" value="135" oninput="updateCustomGradient()" style="width:100%;">
                </div>
                <div id="cust-grad-preview" style="height:28px; border-radius:6px; border:1px solid #cbd5e1; margin-bottom:6px; background:linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%);"></div>
                <div style="display:flex; gap:4px;">
                    <button type="button" class="btn btn-xs btn-primary" style="flex:1; font-size:0.7rem;" onclick="applyCustomGradient()"><i class="fas fa-check"></i> Apply</button>
                    <button type="button" class="btn btn-xs btn-soft-violet" style="flex:1; font-size:0.7rem;" onclick="presetNewGradient()"><i class="fas fa-bookmark"></i> Preset New</button>
                </div>
            </div>

            <!-- Solid Color Picker -->
            <div id="bg-tab-solid" style="display:none; background:#f8fafc; padding:8px; border-radius:8px; border:1px solid #e2e8f0;">
                <div class="field" style="margin-bottom:6px;">
                    <label style="font-size:0.72rem; margin-bottom:2px;">Solid Background Color</label>
                    <input type="color" id="solid-bg-picker" value="#ffffff" onchange="applySolidColor(this.value)" style="height:32px; width:100%; cursor:pointer;">
                </div>
            </div>
        </div>

        <!-- Panel 2: Design Workspace -->
        <div class="workspace">
            <div id="canvas-viewport" style="position:relative; box-shadow: 0 10px 25px rgba(0,0,0,0.15); background-color:#fff;">
                <div class="canvas-container" id="editor-canvas" style="position:absolute; top:0; left:0; transform-origin: top left; box-shadow: none; background-color: #fff;">
                    <!-- Layers drawn here -->
                </div>
            </div>
        </div>

        <!-- Panel 3: Element Config / Inspector -->
        <div class="sidebar-panel" id="inspector-panel" style="display:none;">
            <h4 style="font-weight:700; border-bottom:1px solid #eee; padding-bottom:6px; margin:0 0 6px 0;">Element Properties</h4>
            
            <!-- Shared Properties -->
            <div class="field full">
                <label>Placeholder Name / Label</label>
                <input type="text" id="prop-name" oninput="updateActiveElement('name', this.value)">
            </div>
            <div class="field full">
                <label>Label / Static Content</label>
                <textarea id="prop-text" rows="3" oninput="updateActiveElement('textContent', this.value)" style="resize:vertical;"></textarea>
            </div>
            <div class="field full" style="margin-top: 4px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                    <input type="checkbox" id="prop-behind-bg" onchange="updateActiveElement('behindBg', this.checked)">
                    Render Behind Background
                </label>
            </div>
            
            <!-- Typography block -->
            <div id="text-style-block">
                <div class="field full">
                    <label>Font Family</label>
                    <select id="prop-font-family" onchange="updateActiveElement('fontFamily', this.value)">
                        <optgroup label="Standard Fonts">
                            <option value="Arial">Arial</option>
                            <option value="Times New Roman">Times New Roman</option>
                            <option value="Courier New">Courier New</option>
                        </optgroup>
                        <optgroup label="Google Fonts" id="google-fonts-group">
                            <!-- Populated dynamically -->
                        </optgroup>
                        <optgroup label="Custom Fonts">
                            <?php foreach ($fonts as $f): ?>
                                <option value="<?php echo htmlspecialchars($f['font_name']); ?>"><?php echo htmlspecialchars($f['font_name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="prop-group">
                    <div class="field">
                        <label>Font Size (px)</label>
                        <input type="number" id="prop-font-size" oninput="updateActiveElement('fontSize', this.value)">
                    </div>
                    <div class="field">
                        <label>Font Weight</label>
                        <select id="prop-font-weight" onchange="updateActiveElement('fontWeight', this.value)">
                            <option value="normal">Normal</option>
                            <option value="bold">Bold</option>
                            <option value="300">Light</option>
                            <option value="900">Black</option>
                        </select>
                    </div>
                </div>
                <div class="prop-group">
                    <div class="field">
                        <label>Text Color</label>
                        <input type="color" id="prop-color" oninput="updateActiveElement('color', this.value)">
                    </div>
                    <div class="field">
                        <label>Alignment</label>
                        <select id="prop-align" onchange="updateActiveElement('textAlign', this.value)">
                            <option value="left">Left</option>
                            <option value="center">Center</option>
                            <option value="right">Right</option>
                        </select>
                    </div>
                </div>
                <div class="field full">
                    <label>Line Height / Leading</label>
                    <input type="number" step="0.1" min="0.5" max="3" id="prop-line-height" oninput="updateActiveElement('lineHeight', this.value)">
                </div>
            </div>

            <!-- Photo Shape Block -->
            <div id="photo-style-block" style="display:none;">
                <div class="field full">
                    <label>Clipping Mask Shape</label>
                    <select id="prop-mask" onchange="updateActiveElement('mask', this.value)">
                        <option value="none">Square (None)</option>
                        <option value="circle">Circle</option>
                        <option value="rounded">Rounded Rectangle</option>
                        <option value="oval">Oval</option>
                        <option value="hexagon">Hexagon</option>
                        <option value="diamond">Diamond</option>
                    </select>
                </div>
                <div class="prop-group">
                    <div class="field">
                        <label>Border Width (px)</label>
                        <input type="number" id="prop-border-width" oninput="updateActiveElement('borderWidth', this.value)">
                    </div>
                    <div class="field">
                        <label>Border Color</label>
                        <input type="color" id="prop-border-color" oninput="updateActiveElement('borderColor', this.value)">
                    </div>
                </div>
            </div>

            <!-- Geometric bounds -->
            <h4 style="font-weight:700; border-bottom:1px solid #eee; padding-bottom:6px; margin:15px 0 6px 0;">Dimensions</h4>
            <div class="prop-group">
                <div class="field">
                    <label>Width (%)</label>
                    <input type="number" step="0.1" id="prop-width" oninput="updateActiveElement('width', this.value)">
                </div>
                <div class="field">
                    <label>Height (%)</label>
                    <input type="number" step="0.1" id="prop-height" oninput="updateActiveElement('height', this.value)">
                </div>
            </div>
            <div class="prop-group">
                <div class="field">
                    <label>Left (%)</label>
                    <input type="number" step="0.1" id="prop-left" oninput="updateActiveElement('left', this.value)">
                </div>
                <div class="field">
                    <label>Top (%)</label>
                    <input type="number" step="0.1" id="prop-top" oninput="updateActiveElement('top', this.value)">
                </div>
            </div>
            <div class="prop-group">
                <div class="field">
                    <label>Rotation (&deg;)</label>
                    <input type="number" id="prop-rotate" oninput="updateActiveElement('rotate', this.value)">
                </div>
                <div class="field">
                    <label>Opacity (0-1)</label>
                    <input type="number" step="0.1" min="0" max="1" id="prop-opacity" oninput="updateActiveElement('opacity', this.value)">
                </div>
            </div>
            
            <div style="margin-top: 15px; display:flex; gap:8px;">
                <button class="btn btn-sm btn-outline" style="flex:1;" onclick="duplicateActiveElement()"><i class="fas fa-copy"></i> Duplicate</button>
                <button class="btn btn-sm btn-soft-red" style="flex:1;" onclick="deleteActiveElement()"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>

    <!-- Canvas Resize & Background Properties Modal -->
    <div class="modal-backdrop" id="canvas-resize-modal">
        <div class="modal" style="max-width:440px;">
            <div class="modal-head">
                <h3><i class="fas fa-sliders" style="color:var(--accent);"></i> Canvas &amp; Background Properties</h3>
                <button type="button" class="modal-close" onclick="closeModal('canvas-resize-modal')"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="modal-body" style="display:flex; flex-direction:column; gap:12px;">
                <div class="field">
                    <label>Title</label>
                    <input type="text" id="resize-title" value="<?php echo htmlspecialchars($tpl['title']); ?>">
                </div>
                <div class="field">
                    <label>Category</label>
                    <select id="resize-category">
                        <?php foreach ($categories as $ck => $cv): ?>
                            <option value="<?php echo $ck; ?>" <?php echo $tpl['category'] === $ck ? 'selected' : ''; ?>><?php echo $cv; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Status</label>
                    <select id="resize-status">
                        <option value="active" <?php echo $tpl['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $tpl['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="prop-group">
                    <div class="field">
                        <label>Width (px)</label>
                        <input type="number" id="resize-w" value="<?php echo $canvas_w; ?>">
                    </div>
                    <div class="field">
                        <label>Height (px)</label>
                        <input type="number" id="resize-h" value="<?php echo $canvas_h; ?>">
                    </div>
                </div>
                <div class="prop-group">
                    <div class="field">
                        <label>Resolution (DPI)</label>
                        <input type="number" id="resize-dpi" value="<?php echo (int)($tpl['resolution_dpi'] ?? 72); ?>">
                    </div>
                    <div class="field" style="display:flex; align-items:flex-end;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="fitToOriginalSize()" style="width:100%; height:40px; font-size:0.8rem;"><i class="fas fa-expand"></i> Fit to Original</button>
                    </div>
                </div>
                
                <hr style="border:0; border-top:1px dashed #cbd5e1; margin:4px 0;">
                
                <div class="field">
                    <label style="font-weight:700; color:#1e293b;"><i class="fas fa-image" style="color:var(--accent);"></i> Replace Canvas Background</label>
                    <select id="modal-bg-source-type" onchange="toggleModalBgSource(this.value)">
                        <option value="keep" selected>Keep Current Background</option>
                        <option value="image">Upload New Image File</option>
                        <option value="gradient">Select Pastel / Custom Gradient</option>
                        <option value="solid">Select Solid Color</option>
                    </select>
                </div>

                <!-- Image Upload Option -->
                <div id="modal-bg-image-block" style="display:none;" class="field">
                    <label>New Background Image (.png, .jpg, .webp)</label>
                    <input type="file" id="resize-bg-file" accept=".jpg,.jpeg,.png,.webp">
                </div>

                <!-- Gradient Selection Option -->
                <div id="modal-bg-gradient-block" style="display:none;" class="field">
                    <label>Pastel Gradient Presets</label>
                    <input type="hidden" id="modal-selected-gradient" value="">
                    <div id="modal-gradient-swatches" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; max-height:130px; overflow-y:auto; padding:2px;">
                        <!-- JS populated -->
                    </div>
                </div>

                <!-- Solid Color Option -->
                <div id="modal-bg-solid-block" style="display:none;" class="field">
                    <label>Solid Background Color</label>
                    <input type="color" id="modal-solid-picker" value="#ffffff" style="height:36px; width:100%; cursor:pointer;">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('canvas-resize-modal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="applyCanvasResize()"><i class="fas fa-check"></i> Apply Changes</button>
            </div>
        </div>
    </div>

    <script>
    var bgW = <?php echo (int)$canvas_w; ?>;
    var bgH = <?php echo (int)$canvas_h; ?>;
    var bgUrl = '<?php echo addslashes($bg_image_path); ?>';
    if (bgUrl && !bgUrl.startsWith('linear-gradient') && !bgUrl.startsWith('radial-gradient') && !bgUrl.startsWith('#') && !bgUrl.startsWith('http') && !bgUrl.startsWith('../')) {
        bgUrl = '../' + bgUrl;
    }
    var elements = <?php echo $tpl['elements_json'] ?: '[]'; ?>;
    var activeId = null;

    var undoStack = [];
    var redoStack = [];
    var isUndoRedoAction = false;

    var defaultPastelGradients = [
        { name: 'Sunset Pastel', val: 'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)' },
        { name: 'Soft Peach', val: 'linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%)' },
        { name: 'Ocean Breeze', val: 'linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%)' },
        { name: 'Lavender Mist', val: 'linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%)' },
        { name: 'Mint Fresh', val: 'linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%)' },
        { name: 'Cotton Candy', val: 'linear-gradient(135deg, #fbc2eb 0%, #a6c1ee 100%)' },
        { name: 'Creamy Sunshine', val: 'linear-gradient(135deg, #fff1eb 0%, #ace0f9 100%)' },
        { name: 'Morning Sky', val: 'linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%)' },
        { name: 'Rose Quartz', val: 'linear-gradient(135deg, #ffdde1 0%, #ee9ca7 100%)' },
        { name: 'Soft Emerald', val: 'linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%)' },
        { name: 'Warm Dusk', val: 'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)' },
        { name: 'Soft Lilac', val: 'linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%)' },
        { name: 'Powder Blue', val: 'linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%)' },
        { name: 'Lemon Sorbet', val: 'linear-gradient(135deg, #fef9c3 0%, #fef08a 100%)' },
        { name: 'Velvet Berry', val: 'linear-gradient(135deg, #fce7f3 0%, #fbcfe8 100%)' },
        { name: 'Minimalist Fog', val: 'linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%)' }
    ];

    function toggleStep1BgFields(val) {
        var fileBlock = document.getElementById('step1-bg-file-block');
        var fileInput = document.getElementById('step1-bg-file-input');
        var gradBlock = document.getElementById('step1-bg-gradient-block');
        if (val === 'file') {
            if (fileBlock) fileBlock.style.display = 'block';
            if (fileInput) fileInput.required = true;
            if (gradBlock) gradBlock.style.display = 'none';
        } else {
            if (fileBlock) fileBlock.style.display = 'none';
            if (fileInput) fileInput.required = false;
            if (gradBlock) gradBlock.style.display = 'block';
        }
    }

    function renderStep1GradientSwatches() {
        var container = document.getElementById('step1-gradient-swatches');
        if (!container) return;
        container.innerHTML = '';
        var inputVal = document.getElementById('step1-bg-gradient-val');
        
        defaultPastelGradients.forEach(function(g, idx) {
            var div = document.createElement('div');
            div.style.height = '36px';
            div.style.borderRadius = '8px';
            div.style.cursor = 'pointer';
            div.style.background = g.val;
            div.style.border = (idx === 0) ? '2px solid var(--accent)' : '1px solid #cbd5e1';
            div.title = g.name;
            div.onclick = function() {
                Array.from(container.children).forEach(c => c.style.border = '1px solid #cbd5e1');
                div.style.border = '2px solid var(--accent)';
                if (inputVal) inputVal.value = g.val;
            };
            container.appendChild(div);
        });
    }

    function pushState() {
        if (isUndoRedoAction) return;
        var state = JSON.stringify({
            elements: JSON.parse(JSON.stringify(elements)),
            bgUrl: bgUrl,
            bgW: bgW,
            bgH: bgH
        });
        if (undoStack.length > 0 && undoStack[undoStack.length - 1] === state) {
            return;
        }
        undoStack.push(state);
        if (undoStack.length > 50) {
            undoStack.shift();
        }
        redoStack = [];
        updateUndoRedoButtons();
    }

    function undo() {
        if (undoStack.length === 0) return;
        isUndoRedoAction = true;
        var currentState = JSON.stringify({
            elements: JSON.parse(JSON.stringify(elements)),
            bgUrl: bgUrl,
            bgW: bgW,
            bgH: bgH
        });
        redoStack.push(currentState);
        
        var state = JSON.parse(undoStack.pop());
        elements = state.elements || [];
        bgUrl = state.bgUrl || bgUrl;
        bgW = state.bgW || bgW;
        bgH = state.bgH || bgH;
        
        activeId = null;
        drawElements();
        selectElement(null);
        updateUndoRedoButtons();
        isUndoRedoAction = false;
    }

    function redo() {
        if (redoStack.length === 0) return;
        isUndoRedoAction = true;
        var currentState = JSON.stringify({
            elements: JSON.parse(JSON.stringify(elements)),
            bgUrl: bgUrl,
            bgW: bgW,
            bgH: bgH
        });
        undoStack.push(currentState);
        
        var state = JSON.parse(redoStack.pop());
        elements = state.elements || [];
        bgUrl = state.bgUrl || bgUrl;
        bgW = state.bgW || bgW;
        bgH = state.bgH || bgH;
        
        activeId = null;
        drawElements();
        selectElement(null);
        updateUndoRedoButtons();
        isUndoRedoAction = false;
    }

    function updateUndoRedoButtons() {
        var btnUndo = document.getElementById('btn-undo');
        var btnRedo = document.getElementById('btn-redo');
        if (btnUndo) btnUndo.disabled = undoStack.length === 0;
        if (btnRedo) btnRedo.disabled = redoStack.length === 0;
    }

    function showBgTab(tab) {
        document.querySelectorAll('.bg-tab-btn').forEach(b => {
            b.classList.remove('btn-primary');
            b.classList.add('btn-outline');
        });
        var activeBtn = document.getElementById('bg-tab-btn-' + tab);
        if (activeBtn) {
            activeBtn.classList.remove('btn-outline');
            activeBtn.classList.add('btn-primary');
        }
        
        ['pastel', 'custom', 'solid'].forEach(t => {
            var block = document.getElementById('bg-tab-' + t);
            if (block) block.style.display = (t === tab) ? 'block' : 'none';
        });
    }

    function getSavedCustomGradients() {
        try {
            return JSON.parse(localStorage.getItem('pepp_custom_gradients')) || [];
        } catch(e) {
            return [];
        }
    }

    function renderBackgroundPresetGrid() {
        var container = document.getElementById('pastel-presets-grid');
        if (!container) return;
        container.innerHTML = '';
        
        var allGradients = defaultPastelGradients.map(g => g.val);
        var savedCustom = getSavedCustomGradients();
        savedCustom.forEach(gVal => {
            if (!allGradients.includes(gVal)) {
                allGradients.push(gVal);
            }
        });
        
        allGradients.forEach(function(gVal) {
            var div = document.createElement('div');
            div.style.height = '30px';
            div.style.borderRadius = '6px';
            div.style.cursor = 'pointer';
            div.style.background = gVal;
            div.style.border = (bgUrl === gVal) ? '2px solid var(--accent)' : '1px solid #cbd5e1';
            div.onclick = function() {
                applyBackgroundStyle(gVal);
            };
            container.appendChild(div);
        });
    }

    function applyBackgroundStyle(styleVal) {
        pushState();
        bgUrl = styleVal;
        drawElements();
        renderBackgroundPresetGrid();
    }

    function updateCustomGradient() {
        var type = document.getElementById('cust-grad-type').value;
        var c1 = document.getElementById('cust-grad-c1').value;
        var c2 = document.getElementById('cust-grad-c2').value;
        var angleBlock = document.getElementById('cust-grad-angle-block');
        var gradStr = '';
        
        if (type === 'radial') {
            if (angleBlock) angleBlock.style.display = 'none';
            gradStr = 'radial-gradient(circle, ' + c1 + ' 0%, ' + c2 + ' 100%)';
        } else {
            if (angleBlock) angleBlock.style.display = 'block';
            var angle = document.getElementById('cust-grad-angle').value || 135;
            document.getElementById('cust-angle-val').textContent = angle;
            gradStr = 'linear-gradient(' + angle + 'deg, ' + c1 + ' 0%, ' + c2 + ' 100%)';
        }
        
        var preview = document.getElementById('cust-grad-preview');
        if (preview) preview.style.background = gradStr;
        return gradStr;
    }

    function applyCustomGradient() {
        var gradStr = updateCustomGradient();
        applyBackgroundStyle(gradStr);
    }

    function presetNewGradient() {
        var gradVal = updateCustomGradient();
        var saved = getSavedCustomGradients();
        if (!saved.includes(gradVal)) {
            saved.push(gradVal);
            localStorage.setItem('pepp_custom_gradients', JSON.stringify(saved));
        }
        renderBackgroundPresetGrid();
        applyBackgroundStyle(gradVal);
        alert('Gradient added to your saved presets!');
    }

    function applySolidColor(colorHex) {
        applyBackgroundStyle(colorHex);
    }

    var originalW = bgW;
    var originalH = bgH;
    if (bgUrl && !bgUrl.includes('gradient') && !bgUrl.startsWith('#')) {
        var tempBg = new Image();
        tempBg.src = bgUrl;
        tempBg.onload = function() {
            originalW = tempBg.naturalWidth;
            originalH = tempBg.naturalHeight;
        };
    }

    function fitToOriginalSize() {
        document.getElementById('resize-w').value = originalW;
        document.getElementById('resize-h').value = originalH;
    }

    var customFontNames = <?php echo json_encode(array_column($fonts, 'font_name')); ?>;
    // Load popular Google Fonts in select
    var popularGFonts = ['Bricolage Grotesque', 'Inter', 'Roboto', 'Outfit', 'Montserrat', 'Playfair Display', 'Oswald', 'Poppins', 'Lato', 'Open Sans', 'Lora'];
    var gGroup = document.getElementById('google-fonts-group');
    if (gGroup) {
        popularGFonts.forEach(function(f) {
            var opt = document.createElement('option');
            opt.value = f;
            opt.textContent = f;
            gGroup.appendChild(opt);
            loadFont(f);
        });
    }

    function loadFont(fontFamily) {
        if (!fontFamily) return;
        if (customFontNames.includes(fontFamily)) return; // Skip custom fonts
        var id = 'font-' + fontFamily.replace(/\s+/g, '-').toLowerCase();
        if (document.getElementById(id)) return;
        var link = document.createElement('link');
        link.id = id;
        link.rel = 'stylesheet';
        var href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fontFamily) + '&display=swap';
        if (fontFamily === 'Bricolage Grotesque') {
            href = 'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wdth,wght@24,85,700&display=swap';
        }
        link.href = href;
        document.head.appendChild(link);
    }

    function initCanvas() {
        var container = document.getElementById('editor-canvas');
        if (!container) return;
        
        var viewport = document.getElementById('canvas-viewport');
        var parent = viewport ? viewport.parentElement : container.parentElement;
        var maxW = parent.clientWidth - 40;
        var maxH = parent.clientHeight - 40;
        var scale = Math.min(maxW / bgW, maxH / bgH, 1);
        
        if (viewport) {
            viewport.style.width = (bgW * scale) + 'px';
            viewport.style.height = (bgH * scale) + 'px';
        }
        
        container.style.width = bgW + 'px';
        container.style.height = bgH + 'px';
        container.style.transform = 'scale(' + scale + ')';
        container.style.transformOrigin = 'top left';
        
        renderBackgroundPresetGrid();
        drawElements();
    }

    function drawElements() {
        var container = document.getElementById('editor-canvas');
        if (!container) return;
        container.innerHTML = '';
        
        // Draw the background overlay layer (zIndex = 2)
        var bgOverlay = document.createElement('div');
        bgOverlay.className = 'canvas-background-overlay';
        bgOverlay.style.position = 'absolute';
        bgOverlay.style.top = '0';
        bgOverlay.style.left = '0';
        bgOverlay.style.width = '100%';
        bgOverlay.style.height = '100%';
        
        if (bgUrl && (bgUrl.indexOf('linear-gradient') !== -1 || bgUrl.indexOf('radial-gradient') !== -1)) {
            bgOverlay.style.background = bgUrl;
        } else if (bgUrl && (bgUrl.startsWith('#') || bgUrl.startsWith('rgb'))) {
            bgOverlay.style.backgroundColor = bgUrl;
            bgOverlay.style.backgroundImage = 'none';
        } else {
            bgOverlay.style.backgroundImage = 'url("' + bgUrl + '")';
            bgOverlay.style.backgroundSize = '100% 100%';
        }
        bgOverlay.style.zIndex = 2;
        bgOverlay.style.pointerEvents = 'none';
        container.appendChild(bgOverlay);
        
        elements.forEach(function(el, idx) {
            var div = document.createElement('div');
            div.className = 'canvas-element' + (activeId === el.id ? ' selected' : '');
            div.style.left = el.left + '%';
            div.style.top = el.top + '%';
            div.style.width = el.width + '%';
            div.style.height = el.height + '%';
            div.style.opacity = el.opacity ?? 1;
            div.style.transform = 'rotate(' + (el.rotate ?? 0) + 'deg)';
            div.style.zIndex = el.behindBg ? 1 : (idx + 3);
            
            if (el.type === 'text') {
                div.textContent = el.textContent || '';
                div.style.fontFamily = el.fontFamily || 'Arial';
                div.style.fontSize = el.fontSize + 'px';
                div.style.fontWeight = el.fontWeight || 'normal';
                div.style.color = el.color || '#000000';
                div.style.whiteSpace = 'pre-wrap';
                div.style.lineHeight = el.lineHeight || 1.2;
                var align = el.textAlign || 'center';
                div.style.textAlign = align;
                if (align === 'left') {
                    div.style.justifyContent = 'flex-start';
                } else if (align === 'right') {
                    div.style.justifyContent = 'flex-end';
                } else {
                    div.style.justifyContent = 'center';
                }
            } else if (el.type === 'photo') {
                div.style.border = (el.borderWidth || 0) + 'px solid ' + (el.borderColor || '#000');
                div.style.background = '#e2e8f0 url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' width=\'24\' height=\'24\'%3E%3Cpath fill=\'%2364748b\' d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z\'/%3E%3C/svg%3E") no-repeat center';
                div.style.backgroundSize = '40px';
                
                // Mask shapes
                if (el.mask === 'circle') { div.style.clipPath = 'circle(50%)'; div.style.borderRadius = '0'; }
                else if (el.mask === 'oval') { div.style.clipPath = 'ellipse(50% 50%)'; div.style.borderRadius = '0'; }
                else if (el.mask === 'hexagon') { div.style.clipPath = 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)'; div.style.borderRadius = '0'; }
                else if (el.mask === 'diamond') { div.style.clipPath = 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)'; div.style.borderRadius = '0'; }
                else if (el.mask === 'rounded') { div.style.clipPath = 'none'; div.style.borderRadius = '10%'; }
                else { div.style.borderRadius = '0'; div.style.clipPath = 'none'; }
            }
            
            // Delete button handle
            var del = document.createElement('span');
            del.className = 'delete-handle';
            del.innerHTML = '<i class="fas fa-times"></i>';
            del.addEventListener('click', function(e) {
                e.stopPropagation();
                deleteActiveElement();
            });
            div.appendChild(del);
            
            // Selection event
            div.addEventListener('mousedown', function(e) {
                e.stopPropagation();
                selectElement(el.id);
                dragStart(e, div, el);
            });
            
            container.appendChild(div);
        });
        
        drawLayersList();
    }

    function drawLayersList() {
        var container = document.getElementById('layers-list');
        if (!container) return;
        container.innerHTML = '';
        
        // Reverse array for layer visualization: topmost first
        var reversed = elements.slice().reverse();
        reversed.forEach(function(el) {
            var row = document.createElement('div');
            row.className = 'layer-row' + (activeId === el.id ? ' selected' : '');
            row.innerHTML = '<span style="flex:1;"><i class="fas ' + (el.type === 'text' ? 'fa-font' : 'fa-image') + '"></i> ' + el.name + '</span>' +
                            '<div style="display:flex; gap:6px;">' +
                            '<button type="button" class="btn btn-xs btn-outline" style="padding:2px 4px;" onclick="moveLayer(' + el.id + ', 1)" title="Bring forward"><i class="fas fa-chevron-up"></i></button>' +
                            '<button type="button" class="btn btn-xs btn-outline" style="padding:2px 4px;" onclick="moveLayer(' + el.id + ', -1)" title="Send backward"><i class="fas fa-chevron-down"></i></button>' +
                            '</div>';
            row.addEventListener('click', function() { selectElement(el.id); });
            container.appendChild(row);
        });
    }

    function selectElement(id) {
        activeId = id;
        var el = elements.find(e => e.id === id);
        
        // Refresh selection styles on canvas
        var canvas = document.getElementById('editor-canvas');
        if (canvas) {
            var children = canvas.children;
            elements.forEach(function(e, i) {
                if (children[i + 1]) { // +1 offset for background overlay
                    if (e.id === id) children[i + 1].classList.add('selected');
                    else children[i + 1].classList.remove('selected');
                }
            });
        }
        
        // Refresh properties panel
        var panel = document.getElementById('inspector-panel');
        if (el && panel) {
            panel.style.display = 'flex';
            document.getElementById('prop-name').value = el.name || '';
            document.getElementById('prop-behind-bg').checked = !!el.behindBg;
            document.getElementById('prop-text').value = el.textContent || '';
            document.getElementById('prop-font-size').value = el.fontSize || 24;
            document.getElementById('prop-font-family').value = el.fontFamily || 'Arial';
            document.getElementById('prop-font-weight').value = el.fontWeight || 'normal';
            document.getElementById('prop-color').value = el.color || '#000000';
            document.getElementById('prop-align').value = el.textAlign || 'center';
            document.getElementById('prop-line-height').value = el.lineHeight || 1.2;
            document.getElementById('prop-width').value = el.width;
            document.getElementById('prop-height').value = el.height;
            document.getElementById('prop-left').value = el.left;
            document.getElementById('prop-top').value = el.top;
            document.getElementById('prop-rotate').value = el.rotate ?? 0;
            document.getElementById('prop-opacity').value = el.opacity ?? 1;
            
            // Mask options
            if (el.type === 'photo') {
                document.getElementById('photo-style-block').style.display = 'block';
                document.getElementById('text-style-block').style.display = 'none';
                document.getElementById('prop-mask').value = el.mask || 'none';
                document.getElementById('prop-border-width').value = el.borderWidth || 0;
                document.getElementById('prop-border-color').value = el.borderColor || '#000000';
            } else {
                document.getElementById('photo-style-block').style.display = 'none';
                document.getElementById('text-style-block').style.display = 'block';
            }
            
            loadFont(el.fontFamily);
        } else if (panel) {
            panel.style.display = 'none';
        }
        
        drawLayersList();
    }

    function addElement(type, name) {
        pushState();
        var id = Date.now();
        var newEl = {
            id: id,
            type: type,
            name: name,
            left: 10,
            top: 10,
            width: type === 'text' ? 40 : 15,
            height: type === 'text' ? 8 : 20,
            rotate: 0,
            opacity: 1
        };
        
        if (type === 'text') {
            newEl.textContent = name;
            newEl.fontSize = 32;
            newEl.fontFamily = 'Arial';
            newEl.fontWeight = 'normal';
            newEl.color = '#000000';
            newEl.textAlign = 'center';
            newEl.lineHeight = 1.2;
        } else if (type === 'photo') {
            newEl.mask = 'none';
            newEl.borderWidth = 0;
            newEl.borderColor = '#000000';
        }
        
        elements.push(newEl);
        drawElements();
        selectElement(id);
    }

    function updateActiveElement(prop, val) {
        if (!activeId) return;
        var el = elements.find(e => e.id === activeId);
        if (!el) return;
        
        pushState();
        if (['fontSize', 'borderWidth', 'rotate'].includes(prop)) {
            el[prop] = parseInt(val) || 0;
        } else if (['left', 'top', 'width', 'height', 'opacity', 'lineHeight'].includes(prop)) {
            el[prop] = parseFloat(val) || 0;
        } else {
            el[prop] = val;
        }
        
        drawElements();
        
        // Retain focus
        var panel = document.getElementById('inspector-panel');
        if (panel) panel.style.display = 'flex';
    }

    function deleteActiveElement() {
        if (!activeId) return;
        pushState();
        elements = elements.filter(e => e.id !== activeId);
        activeId = null;
        drawElements();
        var panel = document.getElementById('inspector-panel');
        if (panel) panel.style.display = 'none';
    }

    function duplicateActiveElement() {
        if (!activeId) return;
        var el = elements.find(e => e.id === activeId);
        if (!el) return;
        
        pushState();
        var clone = Object.assign({}, el);
        clone.id = Date.now();
        clone.left = Math.min(el.left + 5, 80);
        clone.top = Math.min(el.top + 5, 80);
        clone.name = el.name + ' copy';
        
        elements.push(clone);
        drawElements();
        selectElement(clone.id);
    }

    function moveLayer(id, delta) {
        var idx = elements.findIndex(e => e.id === id);
        if (idx === -1) return;
        
        var targetIdx = idx + delta;
        if (targetIdx < 0 || targetIdx >= elements.length) return;
        
        pushState();
        var temp = elements[idx];
        elements[idx] = elements[targetIdx];
        elements[targetIdx] = temp;
        
        drawElements();
        selectElement(id);
    }

    // Draggable Workspace element positioning
    function dragStart(e, div, el) {
        pushState();
        var startX = e.clientX;
        var startY = e.clientY;
        
        var initLeft = (el.left / 100) * bgW;
        var initTop = (el.top / 100) * bgH;
        
        function dragMove(ev) {
            var dx = ev.clientX - startX;
            var dy = ev.clientY - startY;
            
            var scale = parseFloat(document.getElementById('editor-canvas').style.transform.match(/scale\(([^)]+)\)/)[1]) || 1;
            
            var newLeft = initLeft + (dx / scale);
            var newTop = initTop + (dy / scale);
            
            var pctLeft = Math.max(0, Math.min(100, (newLeft / bgW) * 100));
            var pctTop = Math.max(0, Math.min(100, (newTop / bgH) * 100));
            
            el.left = parseFloat(pctLeft.toFixed(1));
            el.top = parseFloat(pctTop.toFixed(1));
            
            div.style.left = el.left + '%';
            div.style.top = el.top + '%';
            
            var propLeft = document.getElementById('prop-left');
            var propTop = document.getElementById('prop-top');
            if (propLeft) propLeft.value = el.left;
            if (propTop) propTop.value = el.top;
        }
        
        function dragEnd() {
            window.removeEventListener('mousemove', dragMove);
            window.removeEventListener('mouseup', dragEnd);
            drawElements();
            selectElement(el.id);
        }
        
        window.addEventListener('mousemove', dragMove);
        window.addEventListener('mouseup', dragEnd);
    }

    function openCanvasResize() {
        renderModalGradientSwatches();
        var selectEl = document.getElementById('modal-bg-source-type');
        if (selectEl) {
            selectEl.value = 'keep';
            toggleModalBgSource('keep');
        }
        openModal('canvas-resize-modal');
    }

    function toggleModalBgSource(val) {
        var imgBlock = document.getElementById('modal-bg-image-block');
        var gradBlock = document.getElementById('modal-bg-gradient-block');
        var solidBlock = document.getElementById('modal-bg-solid-block');
        if (imgBlock) imgBlock.style.display = (val === 'image') ? 'block' : 'none';
        if (gradBlock) gradBlock.style.display = (val === 'gradient') ? 'block' : 'none';
        if (solidBlock) solidBlock.style.display = (val === 'solid') ? 'block' : 'none';
    }

    function renderModalGradientSwatches() {
        var container = document.getElementById('modal-gradient-swatches');
        if (!container) return;
        container.innerHTML = '';
        
        var allGradients = defaultPastelGradients.map(g => g.val);
        var savedCustom = getSavedCustomGradients();
        savedCustom.forEach(gVal => {
            if (!allGradients.includes(gVal)) {
                allGradients.push(gVal);
            }
        });
        
        var hiddenVal = document.getElementById('modal-selected-gradient');
        allGradients.forEach(function(gVal, idx) {
            if (idx === 0 && hiddenVal && !hiddenVal.value) {
                hiddenVal.value = gVal;
            }
            var div = document.createElement('div');
            div.style.height = '28px';
            div.style.borderRadius = '6px';
            div.style.cursor = 'pointer';
            div.style.background = gVal;
            div.style.border = (hiddenVal && hiddenVal.value === gVal) ? '2px solid var(--accent)' : '1px solid #cbd5e1';
            div.onclick = function() {
                Array.from(container.children).forEach(c => c.style.border = '1px solid #cbd5e1');
                div.style.border = '2px solid var(--accent)';
                if (hiddenVal) hiddenVal.value = gVal;
            };
            container.appendChild(div);
        });
    }

    function applyCanvasResize() {
        var w = parseInt(document.getElementById('resize-w').value) || 800;
        var h = parseInt(document.getElementById('resize-h').value) || 600;
        
        pushState();
        bgW = w;
        bgH = h;
        
        var bgType = document.getElementById('modal-bg-source-type') ? document.getElementById('modal-bg-source-type').value : 'keep';
        
        if (bgType === 'image') {
            var fileInput = document.getElementById('resize-bg-file');
            if (fileInput && fileInput.files && fileInput.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    bgUrl = e.target.result;
                    drawElements();
                };
                reader.readAsDataURL(fileInput.files[0]);
            }
        } else if (bgType === 'gradient') {
            var gradVal = document.getElementById('modal-selected-gradient').value;
            if (gradVal) {
                bgUrl = gradVal;
            }
        } else if (bgType === 'solid') {
            var colorVal = document.getElementById('modal-solid-picker').value;
            if (colorVal) {
                bgUrl = colorVal;
            }
        }
        
        closeModal('canvas-resize-modal');
        initCanvas();
    }

    function saveTemplate() {
        var titleInput = document.getElementById('resize-title');
        var categoryInput = document.getElementById('resize-category');
        var statusInput = document.getElementById('resize-status');
        
        var title = titleInput ? titleInput.value : '<?php echo addslashes($tpl['title'] ?? ''); ?>';
        var category = categoryInput ? categoryInput.value : '<?php echo addslashes($tpl['category'] ?? ''); ?>';
        var status = statusInput ? statusInput.value : 'active';
        var dpiVal = document.getElementById('resize-dpi') ? parseInt(document.getElementById('resize-dpi').value) || 72 : 72;
        
        // Strip ../ prefix if saving a background image or gradient style to database
        var saveBgPath = bgUrl;
        if (saveBgPath.startsWith('../')) {
            saveBgPath = saveBgPath.replace(/^\.\.\//, '');
        }
        
        var formData = new FormData();
        formData.append('action', 'save_template');
        formData.append('title', title);
        formData.append('category', category);
        formData.append('description', '<?php echo addslashes($tpl['description'] ?? ''); ?>');
        formData.append('status', status);
        formData.append('canvas_width', bgW);
        formData.append('canvas_height', bgH);
        formData.append('resolution_dpi', dpiVal);
        formData.append('elements_json', JSON.stringify(elements));
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');
        
        var bgFileInput = document.getElementById('resize-bg-file');
        if (bgFileInput && bgFileInput.files.length > 0) {
            formData.append('bg_file', bgFileInput.files[0]);
        } else {
            // Save gradient or solid background path in bg_image parameter
            formData.append('bg_path_style', saveBgPath);
        }
        
        fetch('cards-edit.php?id=<?php echo $template_id; ?>', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Template configuration saved successfully.');
                window.location.href = 'cards.php?tab=templates';
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Failed to connect to the server.');
        });
    }

    // Keyboard Shortcuts: Ctrl+Z (Undo), Ctrl+Y (Redo), Delete/Backspace (Delete Active)
    window.addEventListener('keydown', function(e) {
        var activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        var isEditable = activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select' || (document.activeElement && document.activeElement.isContentEditable);
        
        if (isEditable) return;
        
        // Undo: Ctrl+Z or Cmd+Z (without Shift)
        if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z') && !e.shiftKey) {
            e.preventDefault();
            undo();
            return;
        }
        
        // Redo: Ctrl+Y / Cmd+Y OR Ctrl+Shift+Z / Cmd+Shift+Z
        if (((e.ctrlKey || e.metaKey) && (e.key === 'y' || e.key === 'Y')) ||
            ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'z' || e.key === 'Z'))) {
            e.preventDefault();
            redo();
            return;
        }
        
        // Delete: Delete or Backspace key when an element is active
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (activeId) {
                e.preventDefault();
                deleteActiveElement();
                return;
            }
        }
        
        if (!activeId) return;
        
        var el = elements.find(item => item.id === activeId);
        if (!el) return;
        
        var step = 0.1;
        if (e.shiftKey) step = 1.0;
        
        var moved = false;
        if (e.key === 'ArrowUp') {
            el.top = parseFloat(Math.max(0, el.top - step).toFixed(1));
            moved = true;
        } else if (e.key === 'ArrowDown') {
            el.top = parseFloat(Math.min(100, el.top + step).toFixed(1));
            moved = true;
        } else if (e.key === 'ArrowLeft') {
            el.left = parseFloat(Math.max(0, el.left - step).toFixed(1));
            moved = true;
        } else if (e.key === 'ArrowRight') {
            el.left = parseFloat(Math.min(100, el.left + step).toFixed(1));
            moved = true;
        }
        
        if (moved) {
            e.preventDefault();
            drawElements();
            selectElement(activeId);
            
            var propLeft = document.getElementById('prop-left');
            var propTop = document.getElementById('prop-top');
            if (propLeft) propLeft.value = el.left;
            if (propTop) propTop.value = el.top;
        }
    });

    window.addEventListener('resize', initCanvas);
    window.addEventListener('DOMContentLoaded', function() {
        renderStep1GradientSwatches();
        initCanvas();
    });
    </script>
<?php endif; ?>

<?php include 'includes/admin_footer.php'; ?>
