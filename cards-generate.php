<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

require_permission('cards');

$template_id = (int)($_GET['template_id'] ?? 0);
if (!$template_id) {
    header('Location: cards.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM card_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $tpl = $stmt->fetch();
} catch (Exception $e) {
    $tpl = null;
}

if (!$tpl) {
    header('Location: cards.php');
    exit;
}

// Handle AJAX logger for PDF generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_generation') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
        exit;
    }
    $format = $_POST['format'] ?? '';
    $filename = $_POST['filename'] ?? '';
    try {
        track_record($pdo, 'system', 'card_generated', "Generated card template format $format: $filename", $admin_username);
        log_admin_activity($pdo, $admin_username, 'card_generated', "Generated custom card: $filename ($format)");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$elements = json_decode($tpl['elements_json'], true) ?: [];
$canvas_w = (int)$tpl['canvas_width'];
$canvas_h = (int)$tpl['canvas_height'];

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
$page_title  = 'Generate Card from Template';
$page_sub    = 'Fill in personalization details and preview card before generating';
include 'includes/admin_nav.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" />
<style>
<?php foreach ($fonts as $f): ?>
@font-face {
    font-family: '<?php echo addslashes($f['font_name']); ?>';
    src: url('../<?php echo $f['font_file']; ?>');
}
<?php endforeach; ?>

.generate-grid {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 20px;
    height: calc(100vh - 180px);
    min-height: 550px;
}
@media (max-width: 900px) {
    .generate-grid {
        grid-template-columns: 1fr;
        height: auto;
    }
}
.sidebar-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 15px;
    overflow-y: auto;
}
.preview-workspace {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;
    overflow: auto;
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
    transform-origin: center center;
}
.canvas-element.selected {
    outline: 2px dashed var(--accent, #7c3aed);
    outline-offset: -1px;
}
.canvas-element .resize-handle {
    position: absolute;
    width: 14px;
    height: 14px;
    background: #ffffff;
    border: 2.5px solid var(--accent, #7c3aed);
    border-radius: 50%;
    z-index: 100;
    display: none;
    pointer-events: auto;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    transition: transform 0.15s ease, background-color 0.15s ease;
}
.canvas-element .resize-handle:hover {
    transform: scale(1.3);
    background: var(--accent, #7c3aed);
}
.canvas-element.selected .resize-handle {
    display: block;
}
.canvas-element .resize-handle.nw { top: -7px; left: -7px; cursor: nwse-resize; }
.canvas-element .resize-handle.n  { top: -7px; left: calc(50% - 7px); cursor: ns-resize; }
.canvas-element .resize-handle.ne { top: -7px; right: -7px; cursor: nesw-resize; }
.canvas-element .resize-handle.e  { top: calc(50% - 7px); right: -7px; cursor: ew-resize; }
.canvas-element .resize-handle.se { bottom: -7px; right: -7px; cursor: nwse-resize; }
.canvas-element .resize-handle.s  { bottom: -7px; left: calc(50% - 7px); cursor: ns-resize; }
.canvas-element .resize-handle.sw { bottom: -7px; left: -7px; cursor: nesw-resize; }
.canvas-element .resize-handle.w  { top: calc(50% - 7px); left: -7px; cursor: ew-resize; }

.canvas-element .rotate-line {
    position: absolute;
    top: -22px;
    left: 50%;
    width: 2px;
    height: 22px;
    background: var(--accent, #7c3aed);
    z-index: 99;
    display: none;
}
.canvas-element .rotate-handle {
    position: absolute;
    top: -30px;
    left: calc(50% - 8px);
    width: 16px;
    height: 16px;
    background: var(--accent, #7c3aed);
    border: 2.5px solid #ffffff;
    border-radius: 50%;
    z-index: 100;
    display: none;
    cursor: grab;
    pointer-events: auto;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    transition: transform 0.15s ease;
}
.canvas-element .rotate-handle:hover {
    transform: scale(1.25);
}
.canvas-element.selected .rotate-line,
.canvas-element.selected .rotate-handle {
    display: block;
}
.form-field-group {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 12px;
    border-radius: 8px;
}
.form-field-title {
    font-size: 0.8rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: 6px;
.loader-overlay {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(8px);
    z-index: 99999;
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    transition: opacity 0.3s ease;
}
.loader-wrapper {
    position: relative;
    width: 80px;
    height: 80px;
    margin-bottom: 20px;
}
.loader-circle {
    position: absolute;
    width: 100%;
    height: 100%;
    border: 4px solid transparent;
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite;
}
.loader-circle:nth-child(2) {
    border-top-color: var(--accent);
    animation-delay: -0.3s;
    width: 80%;
    height: 80%;
    top: 10%;
    left: 10%;
}
.loader-circle:nth-child(3) {
    border-top-color: #6366f1;
    animation-delay: -0.6s;
    width: 60%;
    height: 60%;
    top: 20%;
    left: 20%;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.loader-text {
    font-weight: 800;
    font-size: 1.25rem;
    color: var(--primary);
    margin-bottom: 5px;
}
.loader-subtext {
    color: #64748b;
    font-size: 0.9rem;
    font-weight: 500;
}
</style>

<div class="generate-grid">
    <!-- Left Area: Input form & controls -->
    <div class="sidebar-panel">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:8px;">
            <h3 style="margin:0; font-size:1.05rem; font-weight:800;"><i class="fas fa-magic"></i> Card Data</h3>
            <a href="cards.php" class="btn btn-xs btn-outline">Back</a>
        </div>
        
        <form id="card-generator-form" onsubmit="triggerGeneration(event)" style="display:flex; flex-direction:column; gap:12px;">
            <!-- Dynamic Fields -->
            <?php foreach ($elements as $el): ?>
                <?php if ($el['type'] === 'image') continue; ?>
                <?php if ($el['type'] === 'dynamic_bg'): ?>
                    <?php
                    $allow_pastel = $el['allowPastel'] ?? true;
                    $allow_solid = $el['allowSolid'] ?? true;
                    $allow_custom = $el['allowCustom'] ?? true;
                    if (!$allow_pastel && !$allow_solid && !$allow_custom) {
                        $allow_pastel = $allow_solid = $allow_custom = true;
                    }
                    $active_tab = 'pastel';
                    if (!$allow_pastel) {
                        $active_tab = $allow_solid ? 'solid' : 'custom';
                    }
                    ?>
                    <div class="form-field-group" id="field-group-<?php echo $el['id']; ?>">
                        <div class="form-field-title" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <span><i class="fas fa-palette" style="color:var(--accent);"></i> <?php echo htmlspecialchars($el['name']); ?></span>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <label style="font-size:0.7rem; font-weight:600; color:#64748b; margin:0; cursor:pointer; display:inline-flex; align-items:center; gap:3px;">
                                    <input type="checkbox" id="chk-include-<?php echo $el['id']; ?>" onchange="toggleElementInclude(<?php echo $el['id']; ?>, this.checked)" checked> Include
                                </label>
                            </div>
                        </div>
                        
                        <div style="display:flex; gap:4px; margin-bottom:8px;">
                            <?php if ($allow_pastel): ?>
                                <button type="button" class="btn btn-xs <?php echo $active_tab === 'pastel' ? 'btn-primary' : 'btn-outline'; ?> lyr-bg-tab-btn-<?php echo $el['id']; ?>" id="lyr-bg-tab-btn-pastel-<?php echo $el['id']; ?>" style="flex:1; font-size:0.65rem; padding:3px;" onclick="showLyrBgTabForGen(<?php echo $el['id']; ?>, 'pastel')">Pastels</button>
                            <?php endif; ?>
                            <?php if ($allow_custom): ?>
                                <button type="button" class="btn btn-xs <?php echo $active_tab === 'custom' ? 'btn-primary' : 'btn-outline'; ?> lyr-bg-tab-btn-<?php echo $el['id']; ?>" id="lyr-bg-tab-btn-custom-<?php echo $el['id']; ?>" style="flex:1; font-size:0.65rem; padding:3px;" onclick="showLyrBgTabForGen(<?php echo $el['id']; ?>, 'custom')">Custom</button>
                            <?php endif; ?>
                            <?php if ($allow_solid): ?>
                                <button type="button" class="btn btn-xs <?php echo $active_tab === 'solid' ? 'btn-primary' : 'btn-outline'; ?> lyr-bg-tab-btn-<?php echo $el['id']; ?>" id="lyr-bg-tab-btn-solid-<?php echo $el['id']; ?>" style="flex:1; font-size:0.65rem; padding:3px;" onclick="showLyrBgTabForGen(<?php echo $el['id']; ?>, 'solid')">Solid</button>
                            <?php endif; ?>
                        </div>

                        <?php if ($allow_pastel): ?>
                            <div id="lyr-bg-tab-pastel-<?php echo $el['id']; ?>" style="display:<?php echo $active_tab === 'pastel' ? 'block' : 'none'; ?>;">
                                <div class="lyr-pastel-presets-grid-gen" data-el-id="<?php echo $el['id']; ?>" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:4px; max-height:100px; overflow-y:auto; padding:2px;">
                                    <!-- Filled dynamically -->
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($allow_custom): ?>
                            <div id="lyr-bg-tab-custom-<?php echo $el['id']; ?>" style="display:<?php echo $active_tab === 'custom' ? 'block' : 'none'; ?>; background:#f8fafc; padding:6px; border-radius:6px; border:1px solid #e2e8f0;">
                                <div style="margin-bottom:4px;">
                                    <label style="font-size:0.65rem; display:block; font-weight:700; margin-bottom:2px;">Type</label>
                                    <select id="lyr-cust-grad-type-<?php echo $el['id']; ?>" onchange="updateLyrCustomGradientForGen(<?php echo $el['id']; ?>)" style="font-size:0.7rem; padding:2px 4px; width:100%; border:1px solid #cbd5e1; border-radius:4px;">
                                        <option value="linear">Linear</option>
                                        <option value="radial">Radial</option>
                                    </select>
                                </div>
                                <div style="display:flex; gap:4px; margin-bottom:4px;">
                                    <div style="flex:1;">
                                        <label style="font-size:0.65rem; display:block; font-weight:700; margin-bottom:2px;">Start</label>
                                        <input type="color" id="lyr-cust-grad-c1-<?php echo $el['id']; ?>" value="#a1c4fd" oninput="updateLyrCustomGradientForGen(<?php echo $el['id']; ?>)" style="height:24px; width:100%; cursor:pointer; border:1px solid #cbd5e1; border-radius:4px; padding:0;">
                                    </div>
                                    <div style="flex:1;">
                                        <label style="font-size:0.65rem; display:block; font-weight:700; margin-bottom:2px;">End</label>
                                        <input type="color" id="lyr-cust-grad-c2-<?php echo $el['id']; ?>" value="#c2e9fb" oninput="updateLyrCustomGradientForGen(<?php echo $el['id']; ?>)" style="height:24px; width:100%; cursor:pointer; border:1px solid #cbd5e1; border-radius:4px; padding:0;">
                                    </div>
                                </div>
                                <div id="lyr-cust-grad-angle-block-<?php echo $el['id']; ?>" style="margin-bottom:4px;">
                                    <label style="font-size:0.65rem; display:block; font-weight:700; margin-bottom:2px;">Angle: <span id="lyr-cust-angle-val-<?php echo $el['id']; ?>">135</span>&deg;</label>
                                    <input type="range" id="lyr-cust-grad-angle-<?php echo $el['id']; ?>" min="0" max="360" value="135" oninput="updateLyrCustomGradientForGen(<?php echo $el['id']; ?>)" style="width:100%;">
                                </div>
                                <button type="button" class="btn btn-xs btn-primary" style="width:100%; font-size:0.65rem;" onclick="applyLyrCustomGradientForGen(<?php echo $el['id']; ?>)">Apply Gradient</button>
                            </div>
                        <?php endif; ?>

                        <?php if ($allow_solid): ?>
                            <div id="lyr-bg-tab-solid-<?php echo $el['id']; ?>" style="display:<?php echo $active_tab === 'solid' ? 'block' : 'none'; ?>; background:#f8fafc; padding:6px; border-radius:6px; border:1px solid #e2e8f0;">
                                <label style="font-size:0.65rem; display:block; font-weight:700; margin-bottom:2px;">Solid Color</label>
                                <input type="color" id="lyr-solid-bg-picker-<?php echo $el['id']; ?>" value="#ffffff" onchange="applyLyrSolidColorForGen(<?php echo $el['id']; ?>, this.value)" style="height:28px; width:100%; cursor:pointer; border:1px solid #cbd5e1; border-radius:4px; padding:0;">
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="form-field-group" id="field-group-<?php echo $el['id']; ?>">
                        <div class="form-field-title" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span><?php echo htmlspecialchars($el['name']); ?></span>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <button type="button" class="btn btn-xs btn-outline" style="font-size:0.65rem; padding:1px 6px; color:#ef4444; border-color:#fca5a5;" onclick="clearElementField(<?php echo $el['id']; ?>)" title="Clear &amp; Omit Field"><i class="fas fa-trash-can"></i> Omit</button>
                                <label style="font-size:0.7rem; font-weight:600; color:#64748b; margin:0; cursor:pointer; display:inline-flex; align-items:center; gap:3px;">
                                    <input type="checkbox" id="chk-include-<?php echo $el['id']; ?>" onchange="toggleElementInclude(<?php echo $el['id']; ?>, this.checked)" checked> Include
                                </label>
                            </div>
                        </div>
                        <?php if ($el['type'] === 'text'): ?>
                            <textarea data-id="<?php echo $el['id']; ?>" id="input-text-<?php echo $el['id']; ?>" oninput="updateFieldText(<?php echo $el['id']; ?>, this.value)" class="field-input" style="width:100%; resize:vertical;" rows="2" placeholder="Leave blank to omit from output..."><?php echo htmlspecialchars($el['textContent'] ?? ''); ?></textarea>
                        <?php elseif ($el['type'] === 'photo'): ?>
                            <?php 
                            $is_logo = false;
                            if (stripos($el['name'], 'logo') !== false) {
                                $is_logo = true;
                            }
                            ?>
                            <?php if ($is_logo && !empty($logos)): ?>
                                <div style="margin-bottom: 6px;">
                                    <label style="font-size: 0.75rem; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Select Preset Logo</label>
                                    <select class="field-input" id="select-preset-<?php echo $el['id']; ?>" onchange="selectPresetLogo(<?php echo $el['id']; ?>, this.value)" style="width:100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                        <option value="">-- Or Upload Custom Logo Below --</option>
                                        <?php foreach ($logos as $lg): ?>
                                            <option value="<?php echo htmlspecialchars($lg['logo_file']); ?>"><?php echo htmlspecialchars($lg['name']); ?> (<?php echo $lg['width'] . 'x' . $lg['height']; ?> px)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            
                            <input type="file" data-id="<?php echo $el['id']; ?>" id="input-file-<?php echo $el['id']; ?>" accept="image/*" onchange="loadPhotoPlaceholder(<?php echo $el['id']; ?>, this)" class="field-file-input" style="width:100%;">
                            
                            <div class="photo-controls" id="controls-<?php echo $el['id']; ?>" style="margin-top: 8px; display:none; flex-direction:column; gap:6px; background:#f8fafc; border:1px solid #e2e8f0; padding:8px; border-radius:6px;">
                                <div style="display:flex; justify-content:space-between; font-size:0.7rem; font-weight:700; color:#475569;">
                                    <span>Photo Zoom</span>
                                    <span id="zoom-val-<?php echo $el['id']; ?>">100%</span>
                                </div>
                                <input type="range" min="100" max="300" value="100" oninput="updatePhotoZoom(<?php echo $el['id']; ?>, this.value)" style="width:100%;">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:4px;">
                                    <div>
                                        <div style="font-size:0.65rem; font-weight:700; color:#475569; display:flex; justify-content:space-between;">
                                            <span>Pan X</span>
                                            <span id="panx-val-<?php echo $el['id']; ?>">0%</span>
                                        </div>
                                        <input type="range" min="-100" max="100" value="0" oninput="updatePhotoPan(<?php echo $el['id']; ?>, 'x', this.value)" style="width:100%;">
                                    </div>
                                    <div>
                                        <div style="font-size:0.65rem; font-weight:700; color:#475569; display:flex; justify-content:space-between;">
                                            <span>Pan Y</span>
                                            <span id="pany-val-<?php echo $el['id']; ?>">0%</span>
                                        </div>
                                        <input type="range" min="-100" max="100" value="0" oninput="updatePhotoPan(<?php echo $el['id']; ?>, 'y', this.value)" style="width:100%;">
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($el['type'] === 'clipart'): ?>
                            <div style="margin-bottom: 6px;">
                                <label style="font-size: 0.75rem; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Select Clipart Image</label>
                                <select class="field-input" id="select-clipart-<?php echo $el['id']; ?>" onchange="selectClipart(<?php echo $el['id']; ?>, this.value)" style="width:100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px;">
                                    <option value="">-- Choose Clipart --</option>
                                    <?php foreach ($cliparts as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c['file_path']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="background:#fff; border:1px solid #cbd5e1; padding:8px; border-radius:6px; margin-top:8px;">
                                <div style="font-size:0.7rem; font-weight:700; color:#475569; margin-bottom:6px;"><i class="fas fa-arrows-alt"></i> Adjust Position &amp; Size</div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                    <div>
                                        <label style="font-size:0.65rem; font-weight:600;">Left X (%)</label>
                                        <input type="number" step="0.1" value="<?php echo $el['left']; ?>" oninput="updateClipartProp(<?php echo $el['id']; ?>, 'left', this.value)" style="width:100%; font-size:0.75rem; padding:4px;">
                                    </div>
                                    <div>
                                        <label style="font-size:0.65rem; font-weight:600;">Top Y (%)</label>
                                        <input type="number" step="0.1" value="<?php echo $el['top']; ?>" oninput="updateClipartProp(<?php echo $el['id']; ?>, 'top', this.value)" style="width:100%; font-size:0.75rem; padding:4px;">
                                    </div>
                                    <div>
                                        <label style="font-size:0.65rem; font-weight:600;">Width (%)</label>
                                        <input type="number" step="0.1" value="<?php echo $el['width']; ?>" oninput="updateClipartProp(<?php echo $el['id']; ?>, 'width', this.value)" style="width:100%; font-size:0.75rem; padding:4px;">
                                    </div>
                                    <div>
                                        <label style="font-size:0.65rem; font-weight:600;">Height (%)</label>
                                        <input type="number" step="0.1" value="<?php echo $el['height']; ?>" oninput="updateClipartProp(<?php echo $el['id']; ?>, 'height', this.value)" style="width:100%; font-size:0.75rem; padding:4px;">
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="form-field-group">
                <div class="form-field-title" style="border-bottom:1px solid #eee; padding-bottom:6px; margin-bottom:10px;">
                    <i class="fas fa-palette" style="color:var(--accent);"></i> Canvas Background
                </div>
                <div style="display:flex; gap:4px; margin-bottom:10px;">
                    <button type="button" class="btn btn-xs btn-primary bg-tab-btn" id="bg-tab-btn-pastel" style="flex:1; font-size:0.7rem; padding:5px;" onclick="showBgTab('pastel')">Pastels</button>
                    <button type="button" class="btn btn-xs btn-outline bg-tab-btn" id="bg-tab-btn-custom" style="flex:1; font-size:0.7rem; padding:5px;" onclick="showBgTab('custom')">Custom</button>
                    <button type="button" class="btn btn-xs btn-outline bg-tab-btn" id="bg-tab-btn-solid" style="flex:1; font-size:0.7rem; padding:5px;" onclick="showBgTab('solid')">Solid</button>
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
                        <label style="font-size:0.72rem; margin-bottom:2px; display:block; font-weight:700;">Type</label>
                        <select id="cust-grad-type" onchange="updateCustomGradient()" style="font-size:0.75rem; padding:3px 6px; width:100%; border:1px solid #cbd5e1; border-radius:4px;">
                            <option value="linear">Linear Gradient</option>
                            <option value="radial">Radial Gradient</option>
                        </select>
                    </div>
                    <div style="display:flex; gap:6px; margin-bottom:6px;">
                        <div class="field" style="flex:1;">
                            <label style="font-size:0.72rem; margin-bottom:2px; display:block; font-weight:700;">Start Color</label>
                            <input type="color" id="cust-grad-c1" value="#a1c4fd" oninput="updateCustomGradient()" style="height:28px; width:100%; cursor:pointer; border:1px solid #cbd5e1; border-radius:4px; padding:0;">
                        </div>
                        <div class="field" style="flex:1;">
                            <label style="font-size:0.72rem; margin-bottom:2px; display:block; font-weight:700;">End Color</label>
                            <input type="color" id="cust-grad-c2" value="#c2e9fb" oninput="updateCustomGradient()" style="height:28px; width:100%; cursor:pointer; border:1px solid #cbd5e1; border-radius:4px; padding:0;">
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:6px;" id="cust-grad-angle-block">
                        <label style="font-size:0.72rem; margin-bottom:2px; display:block; font-weight:700;">Angle: <span id="cust-angle-val">135</span>&deg;</label>
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
                        <label style="font-size:0.72rem; margin-bottom:2px; display:block; font-weight:700;">Solid Background Color</label>
                        <input type="color" id="solid-bg-picker" value="#ffffff" onchange="applySolidColor(this.value)" style="height:32px; width:100%; cursor:pointer; border:1px solid #cbd5e1; border-radius:4px; padding:0;">
                    </div>
                </div>
            </div>

            <div class="field full">
                <label>Download Format</label>
                <select id="download-format">
                    <option value="png">PNG (Lossless High Quality)</option>
                    <option value="jpg">JPEG (Standard Photo)</option>
                    <option value="webp">WEBP (Optimized Web)</option>
                    <option value="pdf">PDF (Printable Document)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:10px;"><i class="fas fa-download"></i> Generate &amp; Download Card</button>
        </form>
    </div>

    <!-- Right Area: Design workspace / preview -->
    <div class="preview-workspace">
        <div id="canvas-viewport" style="position:relative; box-shadow: 0 10px 25px rgba(0,0,0,0.15); background-color:#fff;">
            <div class="canvas-container" id="generator-canvas" style="position:absolute; top:0; left:0; transform-origin: top left; box-shadow: none; background-color: #fff;">
                <!-- Rendered layers -->
            </div>
        </div>
    </div>
</div>

<!-- Hidden Canvas for full-resolution rendering -->
<canvas id="native-resolution-canvas" style="display:none;"></canvas>

<!-- Hidden form for triggering file attachment download -->
<form id="download-form" action="cards-download.php" method="POST" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="image_data" id="df-image-data">
    <input type="hidden" name="format" id="df-format">
    <input type="hidden" name="filename" id="df-filename" value="<?php echo htmlspecialchars(preg_replace('/[^A-Za-z0-9]/', '_', $tpl['title'])); ?>">
</form>

<!-- Generation Loader Modal -->
<div id="generation-loader" class="loader-overlay">
    <div class="loader-wrapper">
        <div class="loader-circle"></div>
        <div class="loader-circle"></div>
        <div class="loader-circle"></div>
    </div>
    <div class="loader-text">Generating Your Card...</div>
    <div class="loader-subtext">Please wait while we render high-quality graphics and typography.</div>
</div>

<!-- Cropper Modal -->
<div id="cropper-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:9999; flex-direction:column; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:20px; border-radius:12px; width:90%; max-width:700px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0; font-size:1.2rem; font-weight:800; color:#1e293b;">Crop Image</h3>
            <button type="button" onclick="closeCropperModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="flex:1; overflow:hidden; min-height:350px; max-height:60vh; background:#e2e8f0; border-radius:8px; display:flex; justify-content:center; align-items:center;">
            <img id="cropper-image" src="" style="max-width:100%; max-height:100%; display:block;">
        </div>
        <div style="margin-top:15px; display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn btn-outline" onclick="closeCropperModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="applyCrop()">Crop &amp; Apply</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
var bgW = <?php echo $canvas_w; ?>;
var bgH = <?php echo $canvas_h; ?>;
var bgUrl = '<?php echo addslashes($tpl['bg_image']); ?>';
if (bgUrl && !bgUrl.startsWith('linear-gradient') && !bgUrl.startsWith('radial-gradient') && !bgUrl.startsWith('#') && !bgUrl.startsWith('http') && !bgUrl.startsWith('../')) {
    bgUrl = '../' + bgUrl;
}
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
var elements = <?php echo json_encode($elements); ?>;
var customFonts = <?php echo json_encode($fonts); ?>;
var customFontNames = customFonts.map(f => f.font_name);
var photos = {}; // Cache for base64 uploaded photo blobs
var activeId = null;
var excludedElements = {}; // Map of excluded element IDs

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

function showLyrBgTabForGen(elId, tab) {
    document.querySelectorAll('.lyr-bg-tab-btn-' + elId).forEach(b => {
        b.classList.remove('btn-primary');
        b.classList.add('btn-outline');
    });
    var activeBtn = document.getElementById('lyr-bg-tab-btn-' + tab + '-' + elId);
    if (activeBtn) {
        activeBtn.classList.remove('btn-outline');
        activeBtn.classList.add('btn-primary');
    }
    
    ['pastel', 'custom', 'solid'].forEach(t => {
        var block = document.getElementById('lyr-bg-tab-' + t + '-' + elId);
        if (block) block.style.display = (t === tab) ? 'block' : 'none';
    });
}

function renderLyrBackgroundPresetsForGen(elId) {
    var container = document.querySelector('.lyr-pastel-presets-grid-gen[data-el-id="' + elId + '"]');
    if (!container) return;
    container.innerHTML = '';
    
    var el = elements.find(item => item.id == elId);
    if (!el) return;
    
    var allGradients = defaultPastelGradients.map(g => g.val);
    var savedCustom = getSavedCustomGradients();
    savedCustom.forEach(gVal => {
        if (!allGradients.includes(gVal)) {
            allGradients.push(gVal);
        }
    });
    
    allGradients.forEach(function(gVal) {
        var div = document.createElement('div');
        div.style.height = '20px';
        div.style.borderRadius = '4px';
        div.style.cursor = 'pointer';
        div.style.background = gVal;
        div.style.border = (el.bgValue === gVal) ? '2px solid var(--accent)' : '1px solid #cbd5e1';
        div.onclick = function() {
            applyLyrBackgroundStyleForGen(elId, gVal);
        };
        container.appendChild(div);
    });
}

function applyLyrBackgroundStyleForGen(elId, styleVal) {
    var el = elements.find(item => item.id == elId);
    if (el) {
        el.bgValue = styleVal;
        drawElements();
        renderLyrBackgroundPresetsForGen(elId);
    }
}

function updateLyrCustomGradientForGen(elId) {
    var type = document.getElementById('lyr-cust-grad-type-' + elId).value;
    var c1 = document.getElementById('lyr-cust-grad-c1-' + elId).value;
    var c2 = document.getElementById('lyr-cust-grad-c2-' + elId).value;
    var angleBlock = document.getElementById('lyr-cust-grad-angle-block-' + elId);
    var gradStr = '';
    
    if (type === 'radial') {
        if (angleBlock) angleBlock.style.display = 'none';
        gradStr = 'radial-gradient(circle, ' + c1 + ' 0%, ' + c2 + ' 100%)';
    } else {
        if (angleBlock) angleBlock.style.display = 'block';
        var angle = document.getElementById('lyr-cust-grad-angle-' + elId).value || 135;
        document.getElementById('lyr-cust-angle-val-' + elId).textContent = angle;
        gradStr = 'linear-gradient(' + angle + 'deg, ' + c1 + ' 0%, ' + c2 + ' 100%)';
    }
    return gradStr;
}

function applyLyrCustomGradientForGen(elId) {
    var gradStr = updateLyrCustomGradientForGen(elId);
    applyLyrBackgroundStyleForGen(elId, gradStr);
}

function applyLyrSolidColorForGen(elId, colorHex) {
    applyLyrBackgroundStyleForGen(elId, colorHex);
}

function toggleElementInclude(id, isIncluded) {
    excludedElements[id] = !isIncluded;
    var group = document.getElementById('field-group-' + id);
    if (group) {
        group.style.opacity = isIncluded ? '1' : '0.5';
    }
    drawElements();
}

function clearElementField(id) {
    var el = elements.find(item => item.id == id);
    if (!el) return;
    
    if (el.type === 'text') {
        el.textContent = '';
        var input = document.getElementById('input-text-' + id);
        if (input) input.value = '';
    } else if (el.type === 'photo') {
        delete photos[id];
        delete photoSettings[id];
        var fileInput = document.getElementById('input-file-' + id);
        if (fileInput) fileInput.value = '';
        var presetSelect = document.getElementById('select-preset-' + id);
        if (presetSelect) presetSelect.value = '';
        var controls = document.getElementById('controls-' + id);
        if (controls) controls.style.display = 'none';
    }
    
    excludedElements[id] = true;
    var chk = document.getElementById('chk-include-' + id);
    if (chk) chk.checked = false;
    var group = document.getElementById('field-group-' + id);
    if (group) group.style.opacity = '0.5';
    
    drawElements();
}

// Programmatically load custom fonts used in elements before generating
var fontLoadPromises = [];
elements.forEach(function(el) {
    if (el.type === 'text' && el.fontFamily) {
        var customFont = customFonts.find(f => f.font_name === el.fontFamily);
        if (customFont) {
            var fontUrl = '../' + customFont.font_file;
            var fontFace = new FontFace(el.fontFamily, 'url("' + fontUrl + '")');
            var promise = fontFace.load().then(function(loadedFace) {
                document.fonts.add(loadedFace);
            }).catch(function(err) {
                console.error("Failed to load custom font:", el.fontFamily, err);
            });
            fontLoadPromises.push(promise);
        }
    }
});

// Initialize Google Fonts used in the template
elements.forEach(function(el) {
    if (el.type === 'text' && el.fontFamily) {
        loadFont(el.fontFamily);
    }
});

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
    var container = document.getElementById('generator-canvas');
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
    elements.forEach(function(el) {
        if (el.type === 'dynamic_bg') {
            renderLyrBackgroundPresetsForGen(el.id);
        }
        var chk = document.getElementById('chk-include-' + el.id);
        if (chk) {
            excludedElements[el.id] = !chk.checked;
            if (!chk.checked) {
                var group = document.getElementById('field-group-' + el.id);
                if (group) group.style.opacity = '0.5';
            }
        }
    });
    drawElements();
}

function drawElements() {
    var container = document.getElementById('generator-canvas');
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
        var rawBg = bgUrl.startsWith('../') ? bgUrl : '../' + bgUrl;
        bgOverlay.style.backgroundImage = 'url("' + rawBg + '")';
        bgOverlay.style.backgroundSize = '100% 100%';
    }
    bgOverlay.style.zIndex = 2;
    bgOverlay.style.pointerEvents = 'none';
    container.appendChild(bgOverlay);
    
    elements.forEach(function(el, idx) {
        if (excludedElements[el.id]) return;
        if (el.type === 'text' && (!el.textContent || !el.textContent.trim())) return;
        if ((el.type === 'photo' || el.type === 'clipart') && !photos[el.id]) return;

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
        } else if (el.type === 'photo' || el.type === 'image' || el.type === 'clipart') {
            div.style.overflow = 'visible';
            
            var imgWrapper = document.createElement('div');
            imgWrapper.className = 'canvas-element-inner';
            imgWrapper.style.position = 'absolute';
            imgWrapper.style.top = '0';
            imgWrapper.style.left = '0';
            imgWrapper.style.width = '100%';
            imgWrapper.style.height = '100%';
            imgWrapper.style.overflow = 'hidden';
            imgWrapper.style.pointerEvents = 'none';
            imgWrapper.style.border = (el.borderWidth || 0) + 'px solid ' + (el.borderColor || '#000');
            imgWrapper.style.boxSizing = 'border-box';
            
            // Mask shapes applied to inner wrapper
            if (el.mask === 'circle') { imgWrapper.style.borderRadius = '50%'; imgWrapper.style.clipPath = 'none'; }
            else if (el.mask === 'oval') { imgWrapper.style.borderRadius = '50%'; imgWrapper.style.clipPath = 'none'; }
            else if (el.mask === 'hexagon') { imgWrapper.style.clipPath = 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)'; imgWrapper.style.borderRadius = '0'; }
            else if (el.mask === 'diamond') { imgWrapper.style.clipPath = 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)'; imgWrapper.style.borderRadius = '0'; }
            else if (el.mask === 'rounded') { imgWrapper.style.clipPath = 'none'; imgWrapper.style.borderRadius = '10%'; }
            else { imgWrapper.style.borderRadius = '0'; imgWrapper.style.clipPath = 'none'; }

            if (el.type === 'image' && el.imageSrc) {
                var img = document.createElement('img');
                img.src = el.imageSrc;
                img.style.width = '100%';
                img.style.height = '100%';
                img.style.objectFit = 'contain';
                img.style.pointerEvents = 'none';
                imgWrapper.appendChild(img);
            } else if (photos[el.id]) {
                var img = document.createElement('img');
                img.src = photos[el.id];
                img.style.width = '100%';
                img.style.height = '100%';
                img.style.objectFit = 'cover';
                img.style.pointerEvents = 'none';
                
                var settings = photoSettings[el.id] || { zoom: 100, panX: 0, panY: 0 };
                var zoomVal = (settings.zoom || 100) / 100;
                img.style.transform = 'scale(' + zoomVal + ') translate(' + settings.panX + '%, ' + settings.panY + '%)';
                img.style.transformOrigin = 'center center';
                imgWrapper.appendChild(img);
            } else {
                imgWrapper.style.background = '#e2e8f0 url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' width=\'24\' height=\'24\'%3E%3Cpath fill=\'%2364748b\' d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z\'/%3E%3C/svg%3E") no-repeat center';
                imgWrapper.style.backgroundSize = '40px';
            }
            
            div.appendChild(imgWrapper);
        } else if (el.type === 'dynamic_bg') {
            var bgVal = el.bgValue || 'linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%)';
            if (bgVal.includes('gradient')) {
                div.style.background = bgVal;
            } else if (bgVal.startsWith('#') || bgVal.startsWith('rgb')) {
                div.style.backgroundColor = bgVal;
                div.style.backgroundImage = 'none';
            }
        }
        
        // Add interactive rotation and 8 resize handles for selected element
        if (activeId === el.id) {
            var rLine = document.createElement('div');
            rLine.className = 'rotate-line';
            div.appendChild(rLine);

            var rHandle = document.createElement('div');
            rHandle.className = 'rotate-handle';
            rHandle.title = 'Drag to rotate element';
            rHandle.addEventListener('mousedown', function(e) {
                e.stopPropagation();
                rotateStart(e, div, el);
            });
            div.appendChild(rHandle);

            ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].forEach(function(handleDir) {
                var h = document.createElement('div');
                h.className = 'resize-handle ' + handleDir;
                h.dataset.handle = handleDir;
                h.title = 'Drag to resize (' + handleDir.toUpperCase() + ')';
                h.addEventListener('mousedown', function(e) {
                    e.stopPropagation();
                    resizeStart(e, handleDir, div, el);
                });
                div.appendChild(h);
            });
        }

        // Let user select and slide coordinates on preview temporarily
        div.addEventListener('mousedown', function(e) {
            e.stopPropagation();
            activeId = el.id;
            drawElements();
            dragStart(e, div, el);
        });
        
        container.appendChild(div);
    });
}

function dragStart(e, div, el) {
    var startX = e.clientX;
    var startY = e.clientY;
    var initLeft = (el.left / 100) * bgW;
    var initTop = (el.top / 100) * bgH;
    
    function dragMove(ev) {
        var dx = ev.clientX - startX;
        var dy = ev.clientY - startY;
        var scale = parseFloat(document.getElementById('generator-canvas').style.transform.match(/scale\(([^)]+)\)/)[1]) || 1;
        
        var newLeft = initLeft + (dx / scale);
        var newTop = initTop + (dy / scale);
        
        var pctLeft = Math.max(0, Math.min(100, (newLeft / bgW) * 100));
        var pctTop = Math.max(0, Math.min(100, (newTop / bgH) * 100));
        
        el.left = parseFloat(pctLeft.toFixed(1));
        el.top = parseFloat(pctTop.toFixed(1));
        
        div.style.left = el.left + '%';
        div.style.top = el.top + '%';
    }
    
    function dragEnd() {
        window.removeEventListener('mousemove', dragMove);
        window.removeEventListener('mouseup', dragEnd);
        drawElements();
    }
    
    window.addEventListener('mousemove', dragMove);
    window.addEventListener('mouseup', dragEnd);
}

function resizeStart(e, dir, div, el) {
    var startX = e.clientX;
    var startY = e.clientY;
    
    var initLeftPct = el.left;
    var initTopPct = el.top;
    var initWidthPct = el.width;
    var initHeightPct = el.height;
    var initFontSize = el.fontSize || 24;

    var initLeftPx = (initLeftPct / 100) * bgW;
    var initTopPx = (initTopPct / 100) * bgH;
    var initWidthPx = (initWidthPct / 100) * bgW;
    var initHeightPx = (initHeightPct / 100) * bgH;

    var rotDeg = el.rotate || 0;
    var rad = (rotDeg * Math.PI) / 180;

    function resizeMove(ev) {
        var dxScreen = ev.clientX - startX;
        var dyScreen = ev.clientY - startY;
        
        var canvasElem = document.getElementById('generator-canvas');
        var scale = 1;
        if (canvasElem && canvasElem.style.transform) {
            var match = canvasElem.style.transform.match(/scale\(([^)]+)\)/);
            if (match) scale = parseFloat(match[1]) || 1;
        }

        var dxScaled = dxScreen / scale;
        var dyScaled = dyScreen / scale;

        var localDx = dxScaled * Math.cos(-rad) - dyScaled * Math.sin(-rad);
        var localDy = dxScaled * Math.sin(-rad) + dyScaled * Math.cos(-rad);

        var newWidthPx = initWidthPx;
        var newHeightPx = initHeightPx;
        var deltaXLocal = 0;
        var deltaYLocal = 0;

        if (dir.includes('e')) {
            newWidthPx = Math.max(15, initWidthPx + localDx);
        }
        if (dir.includes('w')) {
            newWidthPx = Math.max(15, initWidthPx - localDx);
            deltaXLocal = initWidthPx - newWidthPx;
        }
        if (dir.includes('s')) {
            newHeightPx = Math.max(15, initHeightPx + localDy);
        }
        if (dir.includes('n')) {
            newHeightPx = Math.max(15, initHeightPx - localDy);
            deltaYLocal = initHeightPx - newHeightPx;
        }

        var worldDeltaX = deltaXLocal * Math.cos(rad) - deltaYLocal * Math.sin(rad);
        var worldDeltaY = deltaXLocal * Math.sin(rad) + deltaYLocal * Math.cos(rad);

        var newLeftPx = initLeftPx + worldDeltaX;
        var newTopPx = initTopPx + worldDeltaY;

        var pctLeft = (newLeftPx / bgW) * 100;
        var pctTop = (newTopPx / bgH) * 100;
        var pctWidth = (newWidthPx / bgW) * 100;
        var pctHeight = (newHeightPx / bgH) * 100;

        el.left = parseFloat(pctLeft.toFixed(1));
        el.top = parseFloat(pctTop.toFixed(1));
        el.width = parseFloat(pctWidth.toFixed(1));
        el.height = parseFloat(pctHeight.toFixed(1));

        if (el.type === 'text' && ['nw', 'ne', 'sw', 'se'].includes(dir)) {
            var scaleRatio = newWidthPx / initWidthPx;
            el.fontSize = Math.max(8, Math.round(initFontSize * scaleRatio));
            div.style.fontSize = el.fontSize + 'px';
        }

        div.style.left = el.left + '%';
        div.style.top = el.top + '%';
        div.style.width = el.width + '%';
        div.style.height = el.height + '%';
    }

    function resizeEnd() {
        window.removeEventListener('mousemove', resizeMove);
        window.removeEventListener('mouseup', resizeEnd);
        drawElements();
    }

    window.addEventListener('mousemove', resizeMove);
    window.addEventListener('mouseup', resizeEnd);
}

function rotateStart(e, div, el) {
    var rect = div.getBoundingClientRect();
    var cx = rect.left + rect.width / 2;
    var cy = rect.top + rect.height / 2;

    function rotateMove(ev) {
        var dx = ev.clientX - cx;
        var dy = ev.clientY - cy;
        var rad = Math.atan2(dy, dx);
        var deg = Math.round(rad * (180 / Math.PI) - 90);
        
        deg = ((deg + 180) % 360) - 180;
        
        el.rotate = deg;
        div.style.transform = 'rotate(' + el.rotate + 'deg)';
    }

    function rotateEnd() {
        window.removeEventListener('mousemove', rotateMove);
        window.removeEventListener('mouseup', rotateEnd);
        drawElements();
    }

    window.addEventListener('mousemove', rotateMove);
    window.addEventListener('mouseup', rotateEnd);
}

function updateFieldText(id, val) {
    var el = elements.find(e => e.id == id);
    if (el) {
        el.textContent = val;
        drawElements();
    }
}

var photoSettings = {};

function selectPresetLogo(id, logoFile) {
    // Clear file input
    var fileInput = document.querySelector('input[type="file"][data-id="' + id + '"]');
    if (fileInput) fileInput.value = '';
    
    if (!logoFile) {
        photos[id] = null;
        document.getElementById('controls-' + id).style.display = 'none';
        drawElements();
        return;
    }
    
    photos[id] = '../' + logoFile;
    photoSettings[id] = { zoom: 100, panX: 0, panY: 0 };
    
    // Reset controls input values
    var controls = document.getElementById('controls-' + id);
    if (controls) {
        controls.style.display = 'flex';
        var inputs = controls.querySelectorAll('input[type="range"]');
        inputs.forEach(input => {
            if (input.oninput.toString().includes('Zoom')) {
                input.value = 100;
                var zv = document.getElementById('zoom-val-' + id);
                if (zv) zv.textContent = '100%';
            } else {
                input.value = 0;
                if (input.oninput.toString().includes('x')) {
                    var pxv = document.getElementById('panx-val-' + id);
                    if (pxv) pxv.textContent = '0%';
                } else {
                    var pyv = document.getElementById('pany-val-' + id);
                    if (pyv) pyv.textContent = '0%';
                }
            }
        });
    }
    drawElements();
}

var cropperInstance = null;
var currentCropElId = null;

function closeCropperModal() {
    document.getElementById('cropper-modal').style.display = 'none';
    if (cropperInstance) {
        cropperInstance.destroy();
        cropperInstance = null;
    }
    if (currentCropElId) {
        var fileInput = document.getElementById('input-file-' + currentCropElId);
        if (fileInput) fileInput.value = '';
        currentCropElId = null;
    }
}

function applyCrop() {
    if (!cropperInstance || !currentCropElId) return;
    var canvas = cropperInstance.getCroppedCanvas();
    if (!canvas) return;
    
    var croppedDataUrl = canvas.toDataURL('image/png', 1.0);
    photos[currentCropElId] = croppedDataUrl;
    photoSettings[currentCropElId] = { zoom: 100, panX: 0, panY: 0 };
    
    var controls = document.getElementById('controls-' + currentCropElId);
    if (controls) {
        controls.style.display = 'flex';
        var inputs = controls.querySelectorAll('input[type="range"]');
        inputs.forEach(input => {
            if (input.oninput && input.oninput.toString().includes('Zoom')) {
                input.value = 100;
                var zv = document.getElementById('zoom-val-' + currentCropElId);
                if (zv) zv.textContent = '100%';
            } else {
                input.value = 0;
                if (input.oninput && input.oninput.toString().includes('x')) {
                    var pxv = document.getElementById('panx-val-' + currentCropElId);
                    if (pxv) pxv.textContent = '0%';
                } else if (input.oninput) {
                    var pyv = document.getElementById('pany-val-' + currentCropElId);
                    if (pyv) pyv.textContent = '0%';
                }
            }
        });
    }
    
    drawElements();
    
    document.getElementById('cropper-modal').style.display = 'none';
    if (cropperInstance) {
        cropperInstance.destroy();
        cropperInstance = null;
    }
    currentCropElId = null;
}

function loadPhotoPlaceholder(id, input) {
    // Reset preset select dropdown if any exists
    var selectPreset = document.querySelector('select[onchange*="selectPresetLogo(' + id + '"]');
    if (selectPreset) selectPreset.value = '';
    
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            currentCropElId = id;
            var el = elements.find(item => item.id == id);
            
            var modal = document.getElementById('cropper-modal');
            var img = document.getElementById('cropper-image');
            
            // Re-assign src and handle onload for initialization
            img.onload = function() {
                var boxRatio = NaN;
                if (el && el.width && el.height && bgH && bgW) {
                    boxRatio = (el.width * bgW) / (el.height * bgH);
                }
                cropperInstance = new Cropper(img, {
                    aspectRatio: boxRatio,
                    viewMode: 1,
                    autoCropArea: 1
                });
            };
            img.src = e.target.result;
            modal.style.display = 'flex';
            
            if (cropperInstance) {
                cropperInstance.destroy();
                cropperInstance = null;
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function selectClipart(id, path) {
    if (path) {
        photos[id] = '../' + path;
    } else {
        delete photos[id];
    }
    drawElements();
}

function updateClipartProp(id, prop, val) {
    var el = elements.find(function(e) { return e.id == id; });
    if (el) {
        el[prop] = parseFloat(val);
        drawElements();
    }
}

function updatePhotoZoom(id, val) {
    if (!photoSettings[id]) photoSettings[id] = { zoom: 100, panX: 0, panY: 0 };
    photoSettings[id].zoom = parseInt(val) || 100;
    document.getElementById('zoom-val-' + id).textContent = val + '%';
    drawElements();
}

function updatePhotoPan(id, axis, val) {
    if (!photoSettings[id]) photoSettings[id] = { zoom: 100, panX: 0, panY: 0 };
    if (axis === 'x') {
        photoSettings[id].panX = parseInt(val) || 0;
        document.getElementById('panx-val-' + id).textContent = val + '%';
    } else if (axis === 'y') {
        photoSettings[id].panY = parseInt(val) || 0;
        document.getElementById('pany-val-' + id).textContent = val + '%';
    }
    drawElements();
}

function getWrappedLinesOnCanvas(ctx, text, maxWidth) {
    var rawLines = (text || '').split('\n');
    var result = [];
    rawLines.forEach(function(rawLine) {
        if (!rawLine || !rawLine.trim()) {
            result.push('');
            return;
        }
        var words = rawLine.split(' ');
        var currentLine = words[0];
        for (var i = 1; i < words.length; i++) {
            var word = words[i];
            var width = ctx.measureText(currentLine + " " + word).width;
            if (width <= maxWidth) {
                currentLine += " " + word;
            } else {
                result.push(currentLine);
                currentLine = word;
            }
        }
        result.push(currentLine);
    });
    return result;
}

// ── native Canvas Rendering ────────────
function renderElementOnCanvas(ctx, el) {
    return new Promise(function(resolve) {
        if (excludedElements[el.id]) {
            resolve();
            return;
        }
        if (el.type === 'text' && (!el.textContent || !el.textContent.trim())) {
            resolve();
            return;
        }
        if ((el.type === 'photo' || el.type === 'clipart') && !photos[el.id]) {
            resolve();
            return;
        }

        var x = (el.left / 100) * bgW;
        var y = (el.top / 100) * bgH;
        var w = (el.width / 100) * bgW;
        var h = (el.height / 100) * bgH;

        if (el.type === 'text') {
            ctx.save();
            ctx.globalAlpha = el.opacity ?? 1;

            if (el.rotate) {
                ctx.translate(x + w/2, y + h/2);
                ctx.rotate((el.rotate * Math.PI) / 180);
                ctx.translate(-(x + w/2), -(y + h/2));
            }

            ctx.fillStyle = el.color || '#000000';
            var weight = el.fontWeight || 'normal';
            var size = el.fontSize || 24;
            ctx.font = weight + ' ' + size + 'px "' + (el.fontFamily || 'Arial') + '"';
            ctx.textBaseline = 'middle';

            var textX = x;
            if (el.textAlign === 'center') {
                ctx.textAlign = 'center';
                textX = x + w / 2;
            } else if (el.textAlign === 'right') {
                ctx.textAlign = 'right';
                textX = x + w;
            } else {
                ctx.textAlign = 'left';
            }

            var lines = getWrappedLinesOnCanvas(ctx, el.textContent || '', w);
            var lh = el.lineHeight || 1.2;
            var lineOffset = size * lh;

            var totalLinesHeight = (lines.length - 1) * lineOffset;
            var startY = (y + h / 2) - (totalLinesHeight / 2);

            lines.forEach(function(line, lineIdx) {
                ctx.fillText(line, textX, startY + (lineIdx * lineOffset));
            });

            ctx.restore();
            resolve();
        } else if (el.type === 'photo' || el.type === 'image' || el.type === 'clipart') {
            function definePath(inset = 0) {
                var bx = x + inset;
                var by = y + inset;
                var bw = Math.max(0, w - (inset * 2));
                var bh = Math.max(0, h - (inset * 2));
                ctx.beginPath();
                if (el.mask === 'circle') {
                    ctx.arc(bx + bw/2, by + bh/2, Math.min(bw, bh)/2, 0, Math.PI * 2);
                    ctx.closePath();
                } else if (el.mask === 'oval') {
                    ctx.ellipse(bx + bw/2, by + bh/2, bw/2, bh/2, 0, 0, Math.PI * 2);
                    ctx.closePath();
                } else if (el.mask === 'hexagon') {
                    ctx.moveTo(bx + bw*0.25, by);
                    ctx.lineTo(bx + bw*0.75, by);
                    ctx.lineTo(bx + bw, by + bh*0.5);
                    ctx.lineTo(bx + bw*0.75, by + bh);
                    ctx.lineTo(bx + bw*0.25, by + bh);
                    ctx.lineTo(bx, by + bh*0.5);
                    ctx.closePath();
                } else if (el.mask === 'diamond') {
                    ctx.moveTo(bx + bw*0.5, by);
                    ctx.lineTo(bx + bw, by + bh*0.5);
                    ctx.lineTo(bx + bw*0.5, by + bh);
                    ctx.lineTo(bx, by + bh*0.5);
                    ctx.closePath();
                } else if (el.mask === 'rounded') {
                    var r = Math.min(bw, bh) * 0.1;
                    if (r < 5) r = 5;
                    ctx.moveTo(bx + r, by);
                    ctx.arcTo(bx + bw, by, bx + bw, by + bh, r);
                    ctx.arcTo(bx + bw, by + bh, bx, by + bh, r);
                    ctx.arcTo(bx, by + bh, bx, by, r);
                    ctx.arcTo(bx, by, bx + bw, by, r);
                    ctx.closePath();
                } else {
                    ctx.rect(bx, by, bw, bh);
                }
            }

            if (el.type === 'image' && el.imageSrc) {
                var staticImg = new Image();
                staticImg.src = el.imageSrc;
                staticImg.onload = function() {
                    ctx.save();
                    ctx.globalAlpha = el.opacity ?? 1;

                    if (el.rotate) {
                        ctx.translate(x + w/2, y + h/2);
                        ctx.rotate((el.rotate * Math.PI) / 180);
                        ctx.translate(-(x + w/2), -(y + h/2));
                    }

                    var bw = el.borderWidth || 0;
                    if (el.mask && el.mask !== 'none') {
                        definePath(bw);
                        ctx.clip();
                    } else if (bw > 0) {
                        definePath(bw);
                        ctx.clip();
                    }

                    var ix = x + bw;
                    var iy = y + bw;
                    var iw = w - 2 * bw;
                    var ih = h - 2 * bw;
                    var imgRatio = staticImg.width / staticImg.height;
                    var boxRatio = iw / ih;
                    var drawW = iw, drawH = ih;
                    if (imgRatio > boxRatio) { drawH = iw / imgRatio; }
                    else { drawW = ih * imgRatio; }
                    var drawX = ix + (iw - drawW) / 2;
                    var drawY = iy + (ih - drawH) / 2;

                    ctx.drawImage(staticImg, drawX, drawY, drawW, drawH);
                    ctx.restore();

                    if (el.borderWidth > 0) {
                        ctx.save();
                        ctx.globalAlpha = el.opacity ?? 1;
                        if (el.rotate) {
                            ctx.translate(x + w/2, y + h/2);
                            ctx.rotate((el.rotate * Math.PI) / 180);
                            ctx.translate(-(x + w/2), -(y + h/2));
                        }
                        definePath(el.borderWidth / 2);
                        ctx.strokeStyle = el.borderColor || '#000';
                        ctx.lineWidth = el.borderWidth;
                        ctx.stroke();
                        ctx.restore();
                    }
                    resolve();
                };
                staticImg.onerror = function() { resolve(); };
            } else if (photos[el.id]) {
                var studentImg = new Image();
                studentImg.src = photos[el.id];
                studentImg.onload = function() {
                    ctx.save();
                    ctx.globalAlpha = el.opacity ?? 1;

                    if (el.rotate) {
                        ctx.translate(x + w/2, y + h/2);
                        ctx.rotate((el.rotate * Math.PI) / 180);
                        ctx.translate(-(x + w/2), -(y + h/2));
                    }

                    var bw = el.borderWidth || 0;
                    if (el.mask && el.mask !== 'none') {
                        definePath(bw);
                        ctx.clip();
                    } else if (bw > 0) {
                        definePath(bw);
                        ctx.clip();
                    }

                    var ix = x + bw;
                    var iy = y + bw;
                    var iw = w - 2 * bw;
                    var ih = h - 2 * bw;

                    var imgW = studentImg.width;
                    var imgH = studentImg.height;

                    var scaleCover = Math.max(iw / imgW, ih / imgH);
                    var drawW = imgW * scaleCover;
                    var drawH = imgH * scaleCover;

                    var settings = photoSettings[el.id] || { zoom: 100, panX: 0, panY: 0 };
                    var zoomFactor = (settings.zoom || 100) / 100;
                    var panXPx = (settings.panX / 100) * iw;
                    var panYPx = (settings.panY / 100) * ih;

                    ctx.translate(ix + iw / 2, iy + ih / 2);
                    ctx.scale(zoomFactor, zoomFactor);
                    ctx.translate(panXPx, panYPx);

                    ctx.drawImage(studentImg, -drawW / 2, -drawH / 2, drawW, drawH);
                    ctx.restore();

                    if (el.borderWidth > 0) {
                        ctx.save();
                        ctx.globalAlpha = el.opacity ?? 1;
                        if (el.rotate) {
                            ctx.translate(x + w/2, y + h/2);
                            ctx.rotate((el.rotate * Math.PI) / 180);
                            ctx.translate(-(x + w/2), -(y + h/2));
                        }
                        definePath(el.borderWidth / 2);
                        ctx.strokeStyle = el.borderColor || '#000';
                        ctx.lineWidth = el.borderWidth;
                        ctx.stroke();
                        ctx.restore();
                    }

                    resolve();
                };
                studentImg.onerror = function() { resolve(); };
            } else {
                resolve();
            }
        } else if (el.type === 'dynamic_bg') {
            ctx.save();
            ctx.globalAlpha = el.opacity ?? 1;
            if (el.rotate) {
                ctx.translate(x + w/2, y + h/2);
                ctx.rotate((el.rotate * Math.PI) / 180);
                ctx.translate(-(x + w/2), -(y + h/2));
            }
            var bgVal = el.bgValue || 'linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%)';
            if (bgVal.startsWith('#') || bgVal.startsWith('rgb')) {
                ctx.fillStyle = bgVal;
                ctx.fillRect(x, y, w, h);
            } else if (bgVal.includes('gradient')) {
                var isRadial = bgVal.includes('radial-gradient');
                var colors = bgVal.match(/(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\))/g) || ['#ffffff', '#f1f5f9'];
                var grad;
                if (isRadial) {
                    grad = ctx.createRadialGradient(x + w/2, y + h/2, 0, x + w/2, y + h/2, Math.max(w, h)/2);
                } else {
                    var angleMatch = bgVal.match(/(\d+)deg/);
                    var angle = angleMatch ? parseInt(angleMatch[1]) : 135;
                    var rad = (angle - 90) * Math.PI / 180;
                    var x0 = (x + w/2) - Math.cos(rad) * w/2;
                    var y0 = (y + h/2) - Math.sin(rad) * h/2;
                    var x1 = (x + w/2) + Math.cos(rad) * w/2;
                    var y1 = (y + h/2) + Math.sin(rad) * h/2;
                    grad = ctx.createLinearGradient(x0, y0, x1, y1);
                }
                colors.forEach(function(c, i) {
                    grad.addColorStop(i / (colors.length - 1 || 1), c);
                });
                ctx.fillStyle = grad;
                ctx.fillRect(x, y, w, h);
            }
            ctx.restore();
            resolve();
        } else {
            resolve();
        }
    });
}

function drawBackgroundOnCanvasCtx(ctx, bgStr, w, h) {
    if (!bgStr) {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        return;
    }
    if (bgStr.startsWith('#') || bgStr.startsWith('rgb')) {
        ctx.fillStyle = bgStr;
        ctx.fillRect(0, 0, w, h);
    } else if (bgStr.includes('gradient')) {
        var isRadial = bgStr.includes('radial-gradient');
        var colors = bgStr.match(/(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\))/g) || ['#ffffff', '#f1f5f9'];
        var grad;
        if (isRadial) {
            grad = ctx.createRadialGradient(w/2, h/2, 0, w/2, h/2, Math.max(w, h)/2);
        } else {
            var angleMatch = bgStr.match(/(\d+)deg/);
            var angle = angleMatch ? parseInt(angleMatch[1]) : 135;
            var rad = (angle - 90) * Math.PI / 180;
            var x0 = w/2 - Math.cos(rad) * w/2;
            var y0 = h/2 - Math.sin(rad) * h/2;
            var x1 = w/2 + Math.cos(rad) * w/2;
            var y1 = h/2 + Math.sin(rad) * h/2;
            grad = ctx.createLinearGradient(x0, y0, x1, y1);
        }
        if (colors.length === 1) {
            grad.addColorStop(0, colors[0]);
            grad.addColorStop(1, colors[0]);
        } else {
            for (var i = 0; i < colors.length; i++) {
                grad.addColorStop(i / (colors.length - 1), colors[i]);
            }
        }
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, w, h);
    }
}

function triggerGeneration(e) {
    e.preventDefault();
    
    // Check if all photo placeholders have an image (either preset or uploaded)
    for (var i = 0; i < elements.length; i++) {
        var el = elements[i];
        if (excludedElements[el.id]) continue;
        if (el.type === 'photo' || el.type === 'clipart') {
            if (!photos[el.id]) {
                alert('Please upload an image or select a preset for: ' + el.name);
                return;
            }
        }
    }
    
    var loader = document.getElementById('generation-loader');
    if (loader) {
        loader.style.display = 'flex';
        loader.style.opacity = '1';
    }
    
    function startCanvasRender(bgDrawFn) {
        Promise.all(fontLoadPromises).then(function() {
            document.fonts.ready.then(function() {
                var canvas = document.getElementById('native-resolution-canvas');
                var ctx = canvas.getContext('2d');
                
                // Set canvas bounds to native template dimensions
                canvas.width = bgW;
                canvas.height = bgH;
                
                // Clear canvas first
                ctx.clearRect(0, 0, bgW, bgH);
                
                // 1. Render elements that are behind the background first
                var behindPromises = elements.filter(el => el.behindBg === true).map(function(el) {
                    return renderElementOnCanvas(ctx, el);
                });
                
                Promise.all(behindPromises).then(function() {
                    // 2. Draw background
                    if (bgDrawFn) bgDrawFn(ctx);
                    
                    // 3. Render elements that are in front of the background
                    var frontPromises = elements.filter(el => el.behindBg !== true).map(function(el) {
                        return renderElementOnCanvas(ctx, el);
                    });
                    
                    Promise.all(frontPromises).then(function() {
                        var format = document.getElementById('download-format').value;
                        var dataUrl = canvas.toDataURL('image/' + (format === 'pdf' ? 'jpeg' : format), 1.0);
                        
                        function finishGeneration() {
                            if (loader) loader.style.display = 'none';
                        }

                        if (format === 'pdf') {
                            if (typeof window.jspdf === 'undefined') {
                                var script = document.createElement('script');
                                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
                                script.onload = function() {
                                    generatePDF(dataUrl);
                                    finishGeneration();
                                };
                                document.head.appendChild(script);
                            } else {
                                generatePDF(dataUrl);
                                finishGeneration();
                            }
                        } else {
                            document.getElementById('df-image-data').value = dataUrl;
                            document.getElementById('df-format').value = format;
                            document.getElementById('download-form').submit();
                            setTimeout(finishGeneration, 1000); // Hide after brief delay for non-AJAX download
                        }
                    });
                });
            });
        });
    }

    setTimeout(function() {
        if (bgUrl && (bgUrl.includes('gradient') || bgUrl.startsWith('#') || bgUrl.startsWith('rgb'))) {
            startCanvasRender(function(ctx) {
                drawBackgroundOnCanvasCtx(ctx, bgUrl, bgW, bgH);
            });
        } else {
            var bgImg = new Image();
            bgImg.crossOrigin = "anonymous";
            bgImg.src = bgUrl.startsWith('../') ? bgUrl : '../' + bgUrl;
            bgImg.onload = function() {
                startCanvasRender(function(ctx) {
                    ctx.drawImage(bgImg, 0, 0, bgW, bgH);
                });
            };
            bgImg.onerror = function() {
                startCanvasRender(function(ctx) {
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, bgW, bgH);
                });
            };
        }
    }, 50);
}

function generatePDF(dataUrl) {
    const { jsPDF } = window.jspdf;
    var orientation = bgW > bgH ? 'l' : 'p';
    var pdf = new jsPDF({
        orientation: orientation,
        unit: 'px',
        format: [bgW, bgH]
    });
    pdf.addImage(dataUrl, 'JPEG', 0, 0, bgW, bgH);
    pdf.save(document.getElementById('df-filename').value + '.pdf');
    
    // Log generation to database
    var formData = new FormData();
    formData.append('csrf_token', '<?php echo csrf_token(); ?>');
    formData.append('action', 'log_generation');
    formData.append('format', 'pdf');
    formData.append('filename', document.getElementById('df-filename').value);
    fetch('cards-generate.php?template_id=<?php echo $template_id; ?>', {
        method: 'POST',
        body: formData
    });
}

    window.addEventListener('keydown', function(e) {
        if (!activeId) return;
        
        // Skip handling keyboard movement when typing in inputs/selects/textareas
        var activeTag = document.activeElement.tagName.toLowerCase();
        if (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select') {
            return;
        }
        
        var el = elements.find(item => item.id === activeId);
        if (!el) return;
        
        var step = 0.1;
        if (e.shiftKey) {
            step = 1.0;
        }
        
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
        }
    });

    window.addEventListener('resize', initCanvas);
    window.addEventListener('DOMContentLoaded', initCanvas);
</script>

<?php include 'includes/admin_footer.php'; ?>
