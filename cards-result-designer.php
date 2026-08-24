<?php
/**
 * PEPP ERP — Test Result Card Designer
 * Designs and exports rank announcement cards based on published test batch results.
 */
require_once 'includes/auth.php';
require_once 'config/database.php';

require_permission('cards');

$success_message = '';
$error_message = '';

// ── GET parameters (New Design or Saved Design) ────────────
$saved_id    = (int)($_GET['id'] ?? 0);
$year        = $_GET['year'] ?? '';
$course_id   = (int)($_GET['course_id'] ?? 0);
$plan_id     = (int)($_GET['plan_id'] ?? 0);
$activity_id = (int)($_GET['activity_id'] ?? 0);
$template_id = (int)($_GET['template_id'] ?? 0);

$saved_design = null;
$tpl = null;
$activity = null;
$ranking_list = [];

// ── Action: AJAX search student override ────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_students') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (empty($q)) { echo json_encode([]); exit; }
    try {
        $stmt = $pdo->prepare("SELECT user_id, name, email, college_school, user_photo FROM users WHERE (name LIKE ? OR email LIKE ? OR user_id LIKE ?) AND status = 'approved' LIMIT 20");
        $stmt->execute(["%$q%", "%$q%", "%$q%"]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// ── Action: Save Design (POST) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_design') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
        exit;
    }

    $design_id      = (int)($_POST['id'] ?? 0);
    $design_title   = trim($_POST['design_title'] ?? 'Untitled Design');
    $year           = $_POST['academic_year'] ?? '';
    $course_id      = (int)($_POST['course_id'] ?? 0);
    $plan_id        = (int)($_POST['plan_id'] ?? 0);
    $activity_id    = (int)($_POST['activity_id'] ?? 0);
    $template_id    = (int)($_POST['template_id'] ?? 0);
    $output_format  = $_POST['output_format'] ?? 'png';
    $mappings_json  = $_POST['student_rank_mappings'] ?? '{}';
    $config_json    = $_POST['design_config'] ?? '{}';
    $image_data     = $_POST['image_data'] ?? ''; // base64 string

    if (empty($design_title)) {
        echo json_encode(['success' => false, 'message' => 'Design title is required.']);
        exit;
    }

    $file_path = null;
    if ($image_data && preg_match('/^data:image\/(\w+);base64,/', $image_data, $type)) {
        $data = substr($image_data, strpos($image_data, ',') + 1);
        $data = base64_decode($data);
        $ext = strtolower($type[1]); // png or jpeg
        if (in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            $base_dir = __DIR__ . '/../uploads/generated_cards';
            if (!is_dir($base_dir)) {
                @mkdir($base_dir, 0755, true);
            }
            $filename = 'result_card_' . uniqid() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            $target_path = $base_dir . '/' . $filename;
            if (@file_put_contents($target_path, $data)) {
                $file_path = 'uploads/generated_cards/' . $filename;
            }
        }
    }

    try {
        if ($design_id) {
            // Delete old file if updating
            $stmt_old = $pdo->prepare("SELECT output_file FROM test_result_cards WHERE id = ?");
            $stmt_old->execute([$design_id]);
            $old_file = $stmt_old->fetchColumn();
            if ($old_file && $file_path) {
                @unlink(__DIR__ . '/../' . $old_file);
            }

            $upd = $pdo->prepare("
                UPDATE test_result_cards
                SET design_title = ?, output_format = ?, output_file = COALESCE(?, output_file),
                    student_rank_mappings = ?, design_config = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $upd->execute([$design_title, $output_format, $file_path, $mappings_json, $config_json, $design_id]);
            $id = $design_id;
        } else {
            $ins = $pdo->prepare("
                INSERT INTO test_result_cards
                (academic_year, course_id, study_plan_id, activity_id, template_id, design_title, output_format, output_file, student_rank_mappings, design_config, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([$year, $course_id, $plan_id, $activity_id, $template_id, $design_title, $output_format, $file_path, $mappings_json, $config_json, $admin_username]);
            $id = $pdo->lastInsertId();
        }

        log_admin_activity($pdo, $admin_username, 'result_card_saved', "Saved test result card design: {$design_title} (ID #{$id})");
        echo json_encode(['success' => true, 'id' => $id, 'file_url' => $file_path ? '../' . $file_path : null]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Load Existing Card Design Configuration if ID is provided ────────────
if ($saved_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM test_result_cards WHERE id = ?");
        $stmt->execute([$saved_id]);
        $saved_design = $stmt->fetch();
        if ($saved_design) {
            $year        = $saved_design['academic_year'];
            $course_id   = (int)$saved_design['course_id'];
            $plan_id     = (int)$saved_design['study_plan_id'];
            $activity_id = (int)$saved_design['activity_id'];
            $template_id = (int)$saved_design['template_id'];
        }
    } catch (Exception $e) {
        $error_message = 'Failed to load saved card config: ' . $e->getMessage();
    }
}

// ── Check selection parameters ────────────
if (!$activity_id || !$course_id || !$template_id) {
    header('Location: cards.php?tab=test_results');
    exit;
}

// ── Load Template ────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM card_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $tpl = $stmt->fetch();
} catch (Exception $e) {}

if (!$tpl) {
    header('Location: cards.php?tab=test_results');
    exit;
}

// ── Load Test/Activity snapshot ────────────
try {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ?");
    $stmt->execute([$activity_id]);
    $activity = $stmt->fetch();
} catch (Exception $e) {}

if (!$activity) {
    header('Location: cards.php?tab=test_results');
    exit;
}

// ── Load Student Rankings from Published Batches ────────────
try {
    $batch_ids = [];
    if ($course_id > 0) {
        $stmt_cn = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ?");
        $stmt_cn->execute([$course_id]);
        $course_name = $stmt_cn->fetchColumn();

        $stmt_batch = $pdo->prepare("
            SELECT id FROM assessment_result_batches
            WHERE activity_id = ? AND (course_id = ? OR (course_name = ? AND course_name != '')) AND status = 'published'
            ORDER BY version DESC LIMIT 1
        ");
        $stmt_batch->execute([$activity_id, $course_id, $course_name]);
        $bid = $stmt_batch->fetchColumn();
        if ($bid) {
            $batch_ids[] = (int)$bid;
        }
    } else {
        // Merged mode - Load all published batches for the activity
        $stmt_batches = $pdo->prepare("
            SELECT id FROM assessment_result_batches
            WHERE activity_id = ? AND status = 'published'
        ");
        $stmt_batches->execute([$activity_id]);
        $batch_ids = $stmt_batches->fetchAll(PDO::FETCH_COLUMN);
    }

    $results = [];
    if (!empty($batch_ids)) {
        $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
        $stmt_results = $pdo->prepare("
            SELECT ar.student_email, ar.score, ar.attendance_status,
                   COALESCE(u.name, ar.src_name) AS name,
                   COALESCE(u.college_school, '-') AS college_school,
                   u.user_photo, u.user_id, u.pepp_course AS course_name
            FROM assessment_results ar
            LEFT JOIN users u ON (ar.user_id = u.user_id OR LOWER(ar.student_email) = LOWER(u.email))
            WHERE ar.batch_id IN ($placeholders)
        ");
        $stmt_results->execute($batch_ids);
        $results = $stmt_results->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty($results)) {
        // Deduplicate and filter attended students
        $merged = [];
        foreach ($results as $r) {
            if ($r['attendance_status'] === 'attended' && $r['score'] !== null) {
                $uid = !empty($r['user_id']) ? $r['user_id'] : $r['student_email'];
                if (empty($uid)) continue;

                // Retain only highest score if duplicates exist
                if (!isset($merged[$uid]) || $r['score'] > $merged[$uid]['score']) {
                    $merged[$uid] = $r;
                }
            }
        }

        $rankable = array_values($merged);
        usort($rankable, function($a, $b) { return ($b['score'] ?? 0) <=> ($a['score'] ?? 0); });

        $prev_score = null; $rank = 0; $count = 0;
        foreach ($rankable as $r) {
            $count++;
            if ($r['score'] !== $prev_score) { $rank = $count; }
            $r['computed_rank'] = $rank;
            $ranking_list[] = $r;
            $prev_score = $r['score'];
        }
    }
} catch (Exception $e) {
    $error_message = 'Failed to load test ranking results: ' . $e->getMessage();
}

$fonts = [];
try { $fonts = $pdo->query("SELECT * FROM custom_fonts ORDER BY font_name ASC")->fetchAll(); } catch (Exception $e) {}

$active_page = 'cards';
$page_title  = 'Test Result Card Designer';
$page_sub    = 'Live canvas editor to layout top ranking students for ' . htmlspecialchars($activity['activity_title']);
include 'includes/admin_nav.php';
?>

<!-- Load Google Sans Flex and CropperJS CDN -->
<link href="https://fonts.googleapis.com/css2?family=Google+Sans+Flex:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" />

<style>
<?php foreach ($fonts as $f): ?>
@font-face {
    font-family: '<?php echo addslashes($f['font_name']); ?>';
    src: url('../<?php echo $f['font_file']; ?>');
}
<?php endforeach; ?>

.designer-workspace {
    display: grid;
    grid-template-columns: 240px 1fr 340px;
    gap: 20px;
    height: calc(100vh - 160px);
    min-height: 650px;
}
@media (max-width: 1200px) {
    .designer-workspace {
        grid-template-columns: 1fr 340px;
    }
    .layers-sidebar {
        display: none;
    }
}
.sidebar-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    height: 100%;
}
.sidebar-head {
    padding: 14px 18px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    font-weight: 700;
    font-size: 0.88rem;
    color: #1e293b;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.sidebar-body {
    padding: 16px;
    overflow-y: auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.canvas-viewport {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    display: flex;
    justify-content: center;
    align-items: center;
    overflow: auto;
    padding: 20px;
    position: relative;
    height: 100%;
}
.canvas-wrapper {
    position: relative;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    background-color: #fff;
    flex-shrink: 0;
    flex-grow: 0;
}
.canvas-container {
    position: absolute;
    top: 0;
    left: 0;
    transform-origin: top left;
    background-color: #fff;
    overflow: hidden;
}
.canvas-element {
    position: absolute;
    cursor: move;
    user-select: none;
    box-sizing: border-box;
    display: flex;
    align-items: center;
}
.canvas-element.selected {
    outline: 2px dashed var(--accent, #7c3aed);
    outline-offset: -1px;
}
.canvas-element .resize-handle {
    position: absolute;
    width: 10px;
    height: 10px;
    background: #ffffff;
    border: 2px solid var(--accent, #7c3aed);
    border-radius: 50%;
    z-index: 100;
    display: none;
}
.canvas-element.selected .resize-handle {
    display: block;
}
.canvas-element .resize-handle.nw { top: -5px; left: -5px; cursor: nwse-resize; }
.canvas-element .resize-handle.ne { top: -5px; right: -5px; cursor: nesw-resize; }
.canvas-element .resize-handle.se { bottom: -5px; right: -5px; cursor: nwse-resize; }
.canvas-element .resize-handle.sw { bottom: -5px; left: -5px; cursor: nesw-resize; }

/* Masking Shapes */
.mask-circle { border-radius: 50%; }
.mask-rounded { border-radius: 12%; }
.mask-hexagon { clip-path: polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%); }
.mask-diamond { clip-path: polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%); }

.layer-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: 0.15s;
}
.layer-item:hover, .layer-item.active {
    background: rgba(139,92,246,0.06);
    border-color: var(--accent);
}
.prop-section {
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 14px;
    margin-bottom: 14px;
}
.prop-section:last-child {
    border-bottom: none;
}
.prop-title {
    font-size: 0.75rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 8px;
}
.loader-overlay {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(255, 255, 255, 0.9);
    z-index: 9999;
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.spinner {
    width: 50px;
    height: 50px;
    border: 4px solid #e2e8f0;
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<!-- Main Workspace -->
<div class="designer-workspace">
    <!-- Left Panel: Layers / Elements -->
    <div class="sidebar-panel layers-sidebar">
        <div class="sidebar-head">
            <span>Layers</span>
            <button class="btn btn-sm btn-outline" style="padding: 2px 8px; font-size: 0.7rem;" onclick="addNewTextElement()">+ Text</button>
        </div>
        <div class="sidebar-body" id="layers-list" style="gap: 8px;">
            <!-- Rendered dynamically -->
        </div>
    </div>

    <!-- Center Workspace Canvas -->
    <div class="canvas-viewport" id="canvas-parent">
        <div class="canvas-wrapper" id="canvas-wrapper">
            <div class="canvas-container" id="designer-canvas">
                <!-- Background Image & Elements render here -->
            </div>
        </div>
    </div>

    <!-- Right Panel: Properties Controls -->
    <div class="sidebar-panel">
        <div class="sidebar-head">
            <span>Properties</span>
            <div style="display:flex; gap:6px;">
                <button class="btn btn-sm btn-outline" style="padding:4px 8px;" onclick="undo()" title="Undo (Ctrl+Z)"><i class="fas fa-undo"></i></button>
                <button class="btn btn-sm btn-outline" style="padding:4px 8px;" onclick="redo()" title="Redo (Ctrl+Y)"><i class="fas fa-redo"></i></button>
            </div>
        </div>
        <div class="sidebar-body">
            <!-- Global Card Properties -->
            <div class="prop-section">
                <div class="prop-title">Card Settings</div>
                <div class="field full" style="margin-bottom:8px;">
                    <label>Design Title</label>
                    <input type="text" id="prop-design-title" value="<?php echo htmlspecialchars($saved_design['design_title'] ?? $activity['activity_title'] . ' Card'); ?>" oninput="saveHistoryState()">
                </div>
                <div class="field-row">
                    <div class="field" style="margin:0;">
                        <label>Ranks Count</label>
                        <select id="prop-ranks-count" onchange="changeRanksCount(this.value)">
                            <option value="3">Top 3 Ranks</option>
                            <option value="4">Top 4 Ranks</option>
                            <option value="5">Top 5 Ranks</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>
                    <div class="field" style="margin:0;">
                        <label>Export Format</label>
                        <select id="prop-export-format">
                            <option value="png">PNG</option>
                            <option value="jpeg">JPEG</option>
                        </select>
                    </div>
                </div>
                <div class="field-row" style="margin-top: 8px;">
                    <div class="field" style="margin:0; grid-column: span 2;">
                        <label>Preview Zoom</label>
                        <select id="zoom-control" onchange="initCanvasSize()">
                            <option value="fit" selected>Fit to Workspace</option>
                            <option value="fit-width">Fit Width</option>
                            <option value="50">50%</option>
                            <option value="75">75%</option>
                            <option value="100">100% (Actual Size)</option>
                        </select>
                    </div>
                </div>
                <button class="btn btn-sm btn-outline" style="width: 100%; margin-top: 8px;" onclick="addNewStudentRankBlock()">+ Add Student Rank</button>
            </div>

            <!-- Active Selected Element Properties -->
            <div id="element-properties-panel" style="display: none;">
                <div class="prop-section">
                    <div class="prop-title" id="prop-element-header">Element Properties</div>

                    <div class="field-row">
                        <div class="field" style="margin:0;">
                            <label>Position X (px)</label>
                            <input type="number" id="prop-el-x" oninput="updateActiveElementFromProps()">
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Position Y (px)</label>
                            <input type="number" id="prop-el-y" oninput="updateActiveElementFromProps()">
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field" style="margin:0;">
                            <label>Width (px)</label>
                            <input type="number" id="prop-el-w" oninput="updateActiveElementFromProps()">
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Height (px)</label>
                            <input type="number" id="prop-el-h" oninput="updateActiveElementFromProps()">
                        </div>
                    </div>
                </div>

                <!-- Text specific settings -->
                <div class="prop-section" id="prop-text-settings" style="display:none;">
                    <div class="prop-title">Text Settings</div>
                    <div class="field full" style="margin-bottom:8px;">
                        <label>Text Content</label>
                        <textarea id="prop-text-content" rows="2" style="width:100%; resize:vertical; font-size:0.8rem;" oninput="updateActiveElementFromProps()"></textarea>
                    </div>
                    <div class="field-row">
                        <div class="field" style="margin:0;">
                            <label>Font Size (px)</label>
                            <input type="number" id="prop-text-size" oninput="updateActiveElementFromProps()">
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Font Weight</label>
                            <select id="prop-text-weight" onchange="updateActiveElementFromProps()">
                                <option value="400">Regular (400)</option>
                                <option value="500">Medium (500)</option>
                                <option value="600">Semi Bold (600)</option>
                                <option value="700">Bold (700)</option>
                            </select>
                        </div>
                    </div>
                    <div class="field-row" style="margin-top:8px;">
                        <div class="field" style="margin:0;">
                            <label>Font Family</label>
                            <select id="prop-text-font" onchange="updateActiveElementFromProps()">
                                <option value="Google Sans Flex">Google Sans Flex</option>
                                <option value="Arial">Arial</option>
                                <option value="Times New Roman">Times New Roman</option>
                                <?php foreach ($fonts as $f): ?>
                                    <option value="<?php echo htmlspecialchars($f['font_name']); ?>"><?php echo htmlspecialchars($f['font_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Color</label>
                            <input type="color" id="prop-text-color" style="height:32px; padding:0; border:none; width:100%;" oninput="updateActiveElementFromProps()">
                        </div>
                    </div>
                    <div class="field-row" style="margin-top:8px;">
                        <div class="field" style="margin:0;">
                            <label>Alignment</label>
                            <select id="prop-text-align" onchange="updateActiveElementFromProps()">
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Letter Spacing (px)</label>
                            <input type="number" id="prop-text-spacing" step="0.5" value="0" oninput="updateActiveElementFromProps()">
                    </div>
                </div>

                <!-- Rank Marker Settings -->
                <div class="prop-section" id="prop-marker-settings" style="display:none; margin-top:12px;">
                    <div class="prop-title">Circular Marker Settings</div>
                    <div class="field-row">
                        <div class="field" style="margin:0;">
                            <label>Show Rank Marker</label>
                            <select id="prop-marker-show" onchange="updateActiveElementFromProps()">
                                <option value="false">OFF</option>
                                <option value="true">ON</option>
                            </select>
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Marker Color</label>
                            <input type="color" id="prop-marker-color" style="height:32px; padding:0; border:none; width:100%;" oninput="updateActiveElementFromProps()">
                        </div>
                    </div>
                    <div class="field-row" style="margin-top:8px;">
                        <div class="field" style="margin:0;">
                            <label>Border Width (px)</label>
                            <input type="number" id="prop-marker-border-w" min="0" max="20" value="0" oninput="updateActiveElementFromProps()">
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Border Color</label>
                            <input type="color" id="prop-marker-border-c" style="height:32px; padding:0; border:none; width:100%;" oninput="updateActiveElementFromProps()">
                        </div>
                    </div>
                </div>

                <!-- Photo specific settings -->
                <div class="prop-section" id="prop-photo-settings" style="display:none;">
                    <div class="prop-title">Photo Settings</div>
                    <div class="field full" style="margin-bottom:8px;">
                        <label>Assigned Student</label>
                        <select id="prop-photo-student" style="width:100%; font-size:0.8rem;" onchange="updateStudentAssignOverride(this.value)">
                            <option value="">— Select Student —</option>
                            <?php foreach ($ranking_list as $rl): ?>
                                <option value="<?php echo htmlspecialchars($rl['user_id'] ?: $rl['student_email']); ?>">
                                    Rank <?php echo $rl['computed_rank']; ?>: <?php echo htmlspecialchars($rl['name']); ?> (<?php echo $rl['score']; ?> pts)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:8px;">
                        <div style="font-size:0.7rem; font-weight:700; color:#64748b; margin-bottom:6px;">Photo Upload Override</div>
                        <input type="file" id="prop-photo-upload" accept="image/*" style="font-size:0.75rem;" onchange="uploadPhotoOverride(this)">
                    </div>
                    <div class="field full" style="margin-bottom:8px;">
                        <label>Mask Shape</label>
                        <select id="prop-photo-mask" onchange="updateActiveElementFromProps()">
                            <option value="none">None (Square)</option>
                            <option value="circle">Circle</option>
                            <option value="rounded">Rounded Box</option>
                            <option value="hexagon">Hexagon</option>
                            <option value="diamond">Diamond</option>
                        </select>
                    </div>
                    <div class="field full">
                        <label>Zoom (<span id="lbl-zoom-val">100</span>%)</label>
                        <input type="range" id="prop-photo-zoom" min="50" max="400" value="100" oninput="updateActivePhotoTransform()">
                    </div>
                    <div class="field-row" style="margin-top:8px;">
                        <div class="field" style="margin:0;">
                            <label>Pan X (px)</label>
                            <input type="number" id="prop-photo-panx" oninput="updateActivePhotoTransform()">
                        </div>
                        <div class="field" style="margin:0;">
                            <label>Pan Y (px)</label>
                            <input type="number" id="prop-photo-pany" oninput="updateActivePhotoTransform()">
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:6px; margin-top:10px;">
                        <button class="btn btn-sm btn-outline" style="padding:4px;" onclick="nudgePhoto(0, -5)" title="Pan Up"><i class="fas fa-chevron-up"></i></button>
                        <button class="btn btn-sm btn-outline" style="padding:4px;" onclick="nudgePhoto(0, 5)" title="Pan Down"><i class="fas fa-chevron-down"></i></button>
                        <button class="btn btn-sm btn-outline" style="padding:4px;" onclick="nudgePhoto(-5, 0)" title="Pan Left"><i class="fas fa-chevron-left"></i></button>
                        <button class="btn btn-sm btn-outline" style="padding:4px;" onclick="nudgePhoto(5, 0)" title="Pan Right"><i class="fas fa-chevron-right"></i></button>
                        <button class="btn btn-sm btn-outline" style="padding:4px;" onclick="zoomPhoto(10)" title="Zoom In"><i class="fas fa-magnifying-glass-plus"></i></button>
                        <button class="btn btn-sm btn-outline" style="padding:4px;" onclick="zoomPhoto(-10)" title="Zoom Out"><i class="fas fa-magnifying-glass-minus"></i></button>
                        <button class="btn btn-sm btn-outline" style="padding:4px; grid-column:span 3;" onclick="resetPhotoTransform()">Reset Trans</button>
                    </div>
                </div>

                <!-- Element Actions -->
                <div style="display:flex; gap:6px;">
                    <button class="btn btn-sm btn-soft-violet" style="flex:1;" onclick="duplicateActiveElement()">Duplicate</button>
                    <button class="btn btn-sm btn-soft-red" style="flex:1;" onclick="deleteActiveElement()">Delete</button>
                </div>
            </div>

            <div id="no-element-selected-message" style="text-align:center; padding:30px; color:#94a3b8; font-size:0.8rem;">
                Select an element on canvas to modify its properties.
            </div>

            <!-- Footer Save and Export -->
            <div style="margin-top:auto; padding-top:14px; border-top:1px solid #e2e8f0; display:flex; flex-direction:column; gap:8px;">
                <button class="btn btn-outline" style="width:100%;" onclick="saveDesign(false)"><i class="fas fa-floppy-disk"></i> Save Design Config</button>
                <button class="btn btn-primary" style="width:100%;" onclick="saveDesign(true)"><i class="fas fa-circle-down"></i> Generate & Download Card</button>
                <a href="cards.php?tab=test_results" class="btn btn-soft" style="width:100%; text-align:center;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
    </div>
</div>

<!-- High Resolution Render Canvas (hidden) -->
<canvas id="native-resolution-canvas" style="display: none;"></canvas>

<!-- Loading Overlay -->
<div class="loader-overlay" id="generation-loader">
    <div class="spinner"></div>
    <h3 style="margin-top: 15px; font-weight: 700; color: #1e293b;" id="loader-message">Saving Design Config...</h3>
</div>

<script>
// Binary array conversion helpers for physical DPI metadata injection
function dataURLToArrayBuffer(dataUrl) {
    const base64 = dataUrl.split(',')[1];
    const binary = atob(base64);
    const array = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        array[i] = binary.charCodeAt(i);
    }
    return array.buffer;
}

function arrayBufferToDataURL(arrayBuffer, mimeType) {
    const bytes = new Uint8Array(arrayBuffer);
    let binary = '';
    const chunkSize = 8192;
    for (let i = 0; i < bytes.length; i += chunkSize) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
    }
    return `data:${mimeType};base64,` + btoa(binary);
}

// CRC-32 for PNG chunks
let crcTable = null;
function makeCrcTable() {
    const table = new Int32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) {
            if (c & 1) {
                c = 0xedb88320 ^ (c >>> 1);
            } else {
                c = c >>> 1;
            }
        }
        table[n] = c;
    }
    return table;
}

function crc32(bytes) {
    if (!crcTable) crcTable = makeCrcTable();
    let crc = 0xffffffff;
    for (let i = 0; i < bytes.length; i++) {
        crc = crcTable[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
    }
    return (crc ^ 0xffffffff) >>> 0;
}

// Inject PNG pHYs chunk setting physical resolution to 11811 pixels/meter (300 DPI)
function injectPNGDPI(arrayBuffer) {
    const view = new DataView(arrayBuffer);
    const bytes = new Uint8Array(arrayBuffer);

    if (bytes[0] !== 0x89 || bytes[1] !== 0x50 || bytes[2] !== 0x4e || bytes[3] !== 0x47) {
        return arrayBuffer; // Not PNG
    }

    let insertIdx = 33; // After standard IHDR chunk
    let hasPHYs = false;

    // Check if pHYs already exists
    const chunkType = String.fromCharCode(bytes[37], bytes[38], bytes[39], bytes[40]);
    if (chunkType === 'pHYs') {
        hasPHYs = true;
    }

    const pHYsChunk = new Uint8Array(21);
    const pHYsView = new DataView(pHYsChunk.buffer);

    pHYsView.setUint32(0, 9); // Chunk Length is 9 bytes
    pHYsChunk[4] = 112; // p
    pHYsChunk[5] = 72;  // H
    pHYsChunk[6] = 89;  // Y
    pHYsChunk[7] = 115; // s

    pHYsView.setUint32(8, 11811);  // X pixels per meter
    pHYsView.setUint32(12, 11811); // Y pixels per meter
    pHYsChunk[16] = 1;             // Unit specifier: meter (1)

    const crc = crc32(pHYsChunk.subarray(4, 17));
    pHYsView.setUint32(17, crc);

    let newBytes;
    if (hasPHYs) {
        newBytes = new Uint8Array(bytes.length);
        newBytes.set(bytes.subarray(0, insertIdx));
        newBytes.set(pHYsChunk, insertIdx);
        newBytes.set(bytes.subarray(insertIdx + 21), insertIdx + 21);
    } else {
        newBytes = new Uint8Array(bytes.length + 21);
        newBytes.set(bytes.subarray(0, insertIdx));
        newBytes.set(pHYsChunk, insertIdx);
        newBytes.set(bytes.subarray(insertIdx), insertIdx + 21);
    }

    return newBytes.buffer;
}

// Inject/Update JPEG APP0 JFIF density block setting units to DPI (1) and density to 300
function injectJPEGDPI(arrayBuffer) {
    const bytes = new Uint8Array(arrayBuffer);

    if (bytes[0] !== 0xff || bytes[1] !== 0xd8) {
        return arrayBuffer; // Not JPEG
    }

    let app0Idx = -1;
    for (let i = 2; i < bytes.length - 10; i++) {
        if (bytes[i] === 0xff && bytes[i+1] === 0xe0) {
            if (bytes[i+4] === 0x4a && bytes[i+5] === 0x46 && bytes[i+6] === 0x49 && bytes[i+7] === 0x46 && bytes[i+8] === 0x00) {
                app0Idx = i;
                break;
            }
        }
    }

    if (app0Idx !== -1) {
        // Overwrite standard JFIF density fields
        bytes[app0Idx + 11] = 1;    // Density units = dots per inch (1)
        bytes[app0Idx + 12] = 0x01; // X density High
        bytes[app0Idx + 13] = 0x2c; // X density Low (300)
        bytes[app0Idx + 14] = 0x01; // Y density High
        bytes[app0Idx + 15] = 0x2c; // Y density Low (300)
        return arrayBuffer;
    } else {
        // Insert standard APP0 header after SOI
        const app0Header = new Uint8Array([
            0xff, 0xe0,
            0x00, 0x10,
            0x4a, 0x46, 0x49, 0x46, 0x00,
            0x01, 0x01,
            0x01,
            0x01, 0x2c,
            0x01, 0x2c,
            0x00, 0x00
        ]);
        const newBytes = new Uint8Array(bytes.length + app0Header.length);
        newBytes.set(bytes.subarray(0, 2));
        newBytes.set(app0Header, 2);
        newBytes.set(bytes.subarray(2), 2 + app0Header.length);
        return newBytes.buffer;
    }
}

// ── Canvas coordinate configurations ────────────
const bgW = <?php echo (int)$tpl['canvas_width']; ?>;
const bgH = <?php echo (int)$tpl['canvas_height']; ?>;
const bgUrl = '<?php echo addslashes($tpl['bg_image']); ?>';

// Loaded database data
const rankingList = <?php echo json_encode($ranking_list); ?>;
const templateElements = <?php echo $tpl['elements_json'] ?: '[]'; ?>;

// Saved design data if editing
const savedDesignId = <?php echo $saved_id; ?>;
const savedConfig = <?php echo $saved_design ? $saved_design['design_config'] : 'null'; ?>;
const savedMappings = <?php echo $saved_design ? $saved_design['student_rank_mappings'] : 'null'; ?>;

// Editor State variables
let elements = [];
let studentRankMappings = {}; // mapping elementId/rank -> { student_uid, zoom, panX, panY, photo_override }
let activeId = null;

// Undo/Redo Stacks
let undoStack = [];
let redoStack = [];

// Drag state helper
let dragStartCoords = null;
let dragElementState = null;
let isDraggingPhoto = false;

let resolvedBgUrl = bgUrl.startsWith('linear-gradient') || bgUrl.startsWith('radial-gradient') || bgUrl.startsWith('#') || bgUrl.startsWith('http') || bgUrl.startsWith('../') ? bgUrl : '../' + bgUrl;

// Function to load template background image
function loadBackgroundImage(url) {
    return new Promise((resolve, reject) => {
        if (url.startsWith('linear-gradient') || url.startsWith('radial-gradient') || url.startsWith('#')) {
            // Gradient/Color background doesn't need an image load
            resolve({ naturalWidth: bgW, naturalHeight: bgH });
            return;
        }
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.src = url;
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error("Failed to load image from URL: " + url));
    });
}

// ── 1. Page Initialization ────────────
document.addEventListener('DOMContentLoaded', async function() {
    // Show generation loader while loading background template image
    const loader = document.getElementById('generation-loader');
    const loaderMsg = document.getElementById('loader-message');
    if (loader) {
        if (loaderMsg) loaderMsg.textContent = 'Loading background template...';
        loader.style.display = 'flex';
    }

    try {
        const bgImg = await loadBackgroundImage(resolvedBgUrl);

        if (loader) loader.style.display = 'none';

        // Check if template explicitly defines native coordinate mode (metadata node with coordinate_mode: native)
        const isNative = templateElements.some(el => el.id === 'metadata' && el.coordinate_mode === 'native');

        if (savedDesignId && savedConfig) {
            elements = savedConfig.elements || [];
            studentRankMappings = savedMappings || {};
            document.getElementById('prop-ranks-count').value = savedConfig.ranksCount || '4';
            document.getElementById('prop-export-format').value = '<?php echo $saved_design ? addslashes($saved_design['output_format']) : "png"; ?>';

            // Self-heal saved design elements that were previously corrupted/oversized by the percentage conversion bug
            if (isNative) {
                elements = elements.map(function(el) {
                    const tplEl = templateElements.find(t => t.id === el.id);
                    if (tplEl && el.type === 'text') {
                        // Restore raw heights if they were converted and scaled to giant dimensions (> 2x raw height)
                        if (el.height > tplEl.height * 2) {
                            el.height = tplEl.height;
                        }
                        // Restore raw widths if they were converted and scaled to giant dimensions (> 2x raw width)
                        if (el.width > tplEl.width * 2) {
                            el.width = tplEl.width;
                        }
                    }
                    return el;
                });
            }
        } else {
            // Initial setup from template
            elements = JSON.parse(JSON.stringify(templateElements));

            if (!isNative) {
                // Convert percentage-based templates to native pixel coordinates
                elements = elements.map(function(el) {
                    el.left = Math.round((el.left / 100) * bgW);
                    el.top = Math.round((el.top / 100) * bgH);
                    el.width = Math.round((el.width / 100) * bgW);
                    el.height = Math.round((el.height / 100) * bgH);
                    return el;
                });
            } else {
                // Remove the metadata node from visual layers array so it's not rendered
                elements = elements.filter(el => el.id !== 'metadata');
            }

            // Auto assign students to rank photo placeholders
            rankingList.forEach(function(student, index) {
                const rankNum = student.computed_rank;
                const photoEl = elements.find(el => el.id === 'rank_photo_' + rankNum);

                if (photoEl && !studentRankMappings[photoEl.id]) {
                    studentRankMappings[photoEl.id] = {
                        student_uid: student.user_id || student.student_email,
                        zoom: 100,
                        panX: 0,
                        panY: 0,
                        photo_override: null
                    };
                }
            });

            // Auto fill chapter name and test number
            const testNumEl = elements.find(el => el.id === 'test_number');
            if (testNumEl) {
                testNumEl.textContent = '<?php echo addslashes($activity['day_number'] ?: '1'); ?>';
            }
            const chapterEl = elements.find(el => el.id === 'chapter_name');
            if (chapterEl) {
                chapterEl.textContent = '<?php echo addslashes($activity['activity_title']); ?>';
            }
        }

        // Initialize size and register resize listener
        initCanvasSize();
        saveHistoryState(true); // Save initial state
        window.addEventListener('resize', initCanvasSize);

    } catch (err) {
        console.error(err);
        if (loader) loader.style.display = 'none';

        // Render full-screen error display in place of the canvas
        const parent = document.getElementById('canvas-parent');
        if (parent) {
            parent.innerHTML = `
                <div style="background: #fee2e2; border: 1px solid #fca5a5; padding: 24px; border-radius: 12px; max-width: 600px; color: #991b1b; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin: auto;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 16px; color: #dc2626;"></i>
                    <h3 style="font-weight: 700; margin-bottom: 8px;">Template Background Load Failed</h3>
                    <p style="font-size: 0.9rem; margin-bottom: 16px; line-height: 1.5;">The visual designer could not render the background template image. Please verify that the image exists at the path specified below.</p>
                    <div style="background: #fff; border: 1px solid #f3f4f6; padding: 12px; border-radius: 8px; font-family: monospace; font-size: 0.8rem; text-align: left; word-break: break-all; margin-bottom: 16px;">
                        <strong>Resolved Path:</strong> ${resolvedBgUrl}<br>
                        <strong>Template ID:</strong> <?php echo $template_id; ?><br>
                        <strong>Template Name:</strong> <?php echo addslashes($tpl['title']); ?><br>
                        <strong>Database Path:</strong> ${bgUrl}<br>
                        <strong>Error Message:</strong> ${err.message}
                    </div>
                    <a href="cards.php?tab=test_results" class="btn btn-sm btn-outline" style="color: #991b1b; border-color: #fca5a5; background: #fff; text-decoration: none; display: inline-block; padding: 8px 16px; border-radius: 6px;">Back to Dashboard</a>
                </div>
            `;
        }
    }
});

// Calculate CSS Scale transforms to scale canvas visually inside viewport
function initCanvasSize() {
    const parent = document.getElementById('canvas-parent'); // .canvas-viewport
    const wrapper = document.getElementById('canvas-wrapper');
    const container = document.getElementById('designer-canvas'); // .canvas-container

    if (!parent || !wrapper || !container) return;

    // Get parent available width and height minus padding
    const viewportW = parent.clientWidth - 40;
    const viewportH = parent.clientHeight - 40;

    // Read Zoom control
    const zoomSelect = document.getElementById('zoom-control');
    let scale = Math.min(viewportW / bgW, viewportH / bgH, 1);

    if (zoomSelect) {
        const val = zoomSelect.value;
        if (val === 'fit') {
            scale = Math.min(viewportW / bgW, viewportH / bgH, 1);
        } else if (val === 'fit-width') {
            scale = viewportW / bgW;
        } else {
            scale = parseFloat(val) / 100;
        }
    }

    // Scale wrapper to exactly the scaled width and height of the template
    const visualWidth = bgW * scale;
    const visualHeight = bgH * scale;

    wrapper.style.width = Math.round(visualWidth) + 'px';
    wrapper.style.height = Math.round(visualHeight) + 'px';

    // Set the native canvas width and height
    container.style.width = bgW + 'px';
    container.style.height = bgH + 'px';

    // Apply scaling transform on the native canvas
    container.style.transform = 'scale(' + scale + ')';
    container.style.transformOrigin = 'top left';

    drawElements();
}

// ── 2. Render Canvas & Preview Elements ────────────
function drawElements() {
    const canvas = document.getElementById('designer-canvas');
    canvas.innerHTML = '';

    // Render Template background image first
    const bg = document.createElement('div');
    bg.className = 'canvas-bg';
    bg.style.position = 'absolute';
    bg.style.top = '0';
    bg.style.left = '0';
    bg.style.width = bgW + 'px';
    bg.style.height = bgH + 'px';
    bg.style.backgroundImage = 'url("' + resolvedBgUrl + '")';
    bg.style.backgroundSize = '100% 100%';
    bg.style.zIndex = '1';
    bg.style.pointerEvents = 'none';
    canvas.appendChild(bg);

    elements.forEach(function(el, idx) {
        if (el.visible === false) return;

        // Resolve dynamic text replacements if mapped to student rankings
        let textContent = el.textContent || '';
        let photoSrc = null;

        const rankMatch = String(el.id || '').match(/^rank_(name|institute|photo|badge)_(\d+)$/);
        if (rankMatch) {
            const field = rankMatch[1];
            const rankNum = parseInt(rankMatch[2]);

            // Find student mapped to this rank block photo
            const photoElId = 'rank_photo_' + rankNum;
            const mapping = studentRankMappings[photoElId];

            if (mapping && mapping.student_uid) {
                const student = rankingList.find(s => (s.user_id === mapping.student_uid || s.student_email === mapping.student_uid));
                if (student) {
                    if (field === 'name') textContent = student.name;
                    else if (field === 'institute') textContent = student.college_school;
                    else if (field === 'badge') textContent = student.computed_rank + (student.computed_rank === 1 ? 'st' : (student.computed_rank === 2 ? 'nd' : (student.computed_rank === 3 ? 'rd' : 'th')));
                    else if (field === 'photo') {
                        photoSrc = mapping.photo_override || (student.user_photo ? '../' + student.user_photo : null);
                    }
                }
            }
        }

        const div = document.createElement('div');
        div.className = 'canvas-element' + (activeId === el.id ? ' selected' : '');
        div.style.left = el.left + 'px';
        div.style.top = el.top + 'px';
        div.style.width = el.width + 'px';
        div.style.height = el.height + 'px';
        div.style.zIndex = idx + 10;
        div.style.opacity = el.opacity ?? 1;
        div.style.transform = 'rotate(' + (el.rotate ?? 0) + 'deg)';

        if (el.type === 'text') {
            div.textContent = textContent;
            div.style.fontFamily = el.fontFamily || 'Google Sans Flex';
            div.style.fontSize = el.fontSize + 'px';
            div.style.fontWeight = el.fontWeight || '700';
            div.style.color = el.color || '#1e293b';
            div.style.whiteSpace = 'pre-wrap';
            div.style.lineHeight = el.lineHeight || 1.2;
            div.style.letterSpacing = (el.letterSpacing || 0) + 'px';

            const align = el.textAlign || 'left';
            div.style.textAlign = align;
            if (align === 'left') div.style.justifyContent = 'flex-start';
            else if (align === 'right') div.style.justifyContent = 'flex-end';
            else div.style.justifyContent = 'center';

            if (el.showMarker) {
                div.style.backgroundColor = el.markerColor || '#eab308';
                div.style.borderRadius = '50%';
                if (el.markerBorderWidth && el.markerBorderColor) {
                    div.style.border = `${el.markerBorderWidth}px solid ${el.markerBorderColor}`;
                } else {
                    div.style.border = 'none';
                }
            } else {
                div.style.backgroundColor = 'transparent';
                div.style.borderRadius = '0';
                div.style.border = 'none';
            }
        } else if (el.type === 'photo') {
            const wrapper = document.createElement('div');
            wrapper.style.position = 'absolute';
            wrapper.style.top = '0';
            wrapper.style.left = '0';
            wrapper.style.width = '100%';
            wrapper.style.height = '100%';
            wrapper.style.overflow = 'hidden';
            wrapper.style.pointerEvents = 'none';
            wrapper.className = 'mask-' + (el.mask || 'rounded');

            if (photoSrc) {
                const img = document.createElement('img');
                img.src = photoSrc;
                img.style.width = '100%';
                img.style.height = '100%';
                img.style.objectFit = 'cover';
                img.style.pointerEvents = 'none';

                const mapping = studentRankMappings[el.id] || { zoom: 100, panX: 0, panY: 0 };
                const scaleFactor = (mapping.zoom || 100) / 100;
                img.style.transform = 'scale(' + scaleFactor + ') translate(' + mapping.panX + 'px, ' + mapping.panY + 'px)';
                img.style.transformOrigin = 'center center';

                wrapper.appendChild(img);
            } else {
                // Render placeholder SVG avatar
                wrapper.style.background = '#e2e8f0 url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' width=\'24\' height=\'24\'%3E%3Cpath fill=\'%2364748b\' d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z\'/%3E%3C/svg%3E") no-repeat center';
                wrapper.style.backgroundSize = '40%';
            }
            div.appendChild(wrapper);
        }

        // Drag handler listeners
        div.addEventListener('mousedown', function(e) {
            e.stopPropagation();
            selectElement(el.id);
            startDraggingElement(e, el);
        });

        // Display selection resize handles
        if (activeId === el.id) {
            ['nw', 'ne', 'se', 'sw'].forEach(function(dir) {
                const h = document.createElement('div');
                h.className = 'resize-handle ' + dir;
                h.addEventListener('mousedown', function(e) {
                    e.stopPropagation();
                    startResizingElement(e, dir, el);
                });
                div.appendChild(h);
            });
        }

        canvas.appendChild(div);
    });

    renderLayersSidebar();
}

// ── 3. Selection and Drag Logic ────────────
function selectElement(id) {
    activeId = id;
    drawElements();
    updatePropertiesPanel();
}

function startDraggingElement(e, el) {
    const parent = document.getElementById('designer-canvas');
    // Get CSS Scale factor
    const transform = window.getComputedStyle(parent).transform;
    const scale = transform && transform !== 'none' ? parseFloat(transform.split(',')[0].split('(')[1]) : 1;

    dragStartCoords = { x: e.clientX, y: e.clientY };
    dragElementState = { left: el.left, top: el.top };

    // Check if dragging inside photo pan override mode
    isDraggingPhoto = e.altKey || e.shiftKey;

    const onMouseMove = function(event) {
        const deltaX = (event.clientX - dragStartCoords.x) / scale;
        const deltaY = (event.clientY - dragStartCoords.y) / scale;

        if (isDraggingPhoto && el.type === 'photo') {
            if (!studentRankMappings[el.id]) studentRankMappings[el.id] = { zoom: 100, panX: 0, panY: 0 };
            const m = studentRankMappings[el.id];
            m.panX = Math.round((m.panX || 0) + deltaX / 5);
            m.panY = Math.round((m.panY || 0) + deltaY / 5);
            dragStartCoords = { x: event.clientX, y: event.clientY };
            drawElements();
        } else {
            el.left = Math.round(dragElementState.left + deltaX);
            el.top = Math.round(dragElementState.top + deltaY);
            drawElements();
        }
        updatePropertiesPanel();
    };

    const onMouseUp = function() {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
        saveHistoryState();
    };

    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
}

function startResizingElement(e, dir, el) {
    const parent = document.getElementById('designer-canvas');
    const transform = window.getComputedStyle(parent).transform;
    const scale = transform && transform !== 'none' ? parseFloat(transform.split(',')[0].split('(')[1]) : 1;

    const startX = e.clientX;
    const startY = e.clientY;
    const startW = el.width;
    const startH = el.height;
    const startLeft = el.left;
    const startTop = el.top;

    const onMouseMove = function(event) {
        const deltaX = (event.clientX - startX) / scale;
        const deltaY = (event.clientY - startY) / scale;

        if (dir.includes('e')) {
            el.width = Math.max(20, Math.round(startW + deltaX));
        }
        if (dir.includes('s')) {
            el.height = Math.max(20, Math.round(startH + deltaY));
        }
        if (dir.includes('w')) {
            const newW = Math.max(20, Math.round(startW - deltaX));
            el.left = Math.round(startLeft + (startW - newW));
            el.width = newW;
        }
        if (dir.includes('n')) {
            const newH = Math.max(20, Math.round(startH - deltaY));
            el.top = Math.round(startTop + (startH - newH));
            el.height = newH;
        }

        drawElements();
        updatePropertiesPanel();
    };

    const onMouseUp = function() {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
        saveHistoryState();
    };

    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
}

// ── 4. Undo / Redo History Management ────────────
function saveHistoryState(isInitial = false) {
    const state = {
        elements: JSON.parse(JSON.stringify(elements)),
        studentRankMappings: JSON.parse(JSON.stringify(studentRankMappings)),
        ranksCount: document.getElementById('prop-ranks-count').value
    };

    // Prevent duplicate states
    if (undoStack.length > 0) {
        const last = JSON.parse(undoStack[undoStack.length - 1]);
        if (JSON.stringify(last.elements) === JSON.stringify(state.elements) &&
            JSON.stringify(last.studentRankMappings) === JSON.stringify(state.studentRankMappings) &&
            last.ranksCount === state.ranksCount) {
            return;
        }
    }

    undoStack.push(JSON.stringify(state));
    if (!isInitial) {
        redoStack = []; // Reset redo on new action
    }
}

function undo() {
    if (undoStack.length <= 1) return;
    const current = undoStack.pop();
    redoStack.push(current);

    const state = JSON.parse(undoStack[undoStack.length - 1]);
    restoreState(state);
}

function redo() {
    if (redoStack.length === 0) return;
    const next = redoStack.pop();
    undoStack.push(next);

    const state = JSON.parse(next);
    restoreState(state);
}

function restoreState(state) {
    elements = state.elements;
    studentRankMappings = state.studentRankMappings;
    document.getElementById('prop-ranks-count').value = state.ranksCount;
    drawElements();
    updatePropertiesPanel();
}

// ── 5. Property Panel Update Bindings ────────────
function updatePropertiesPanel() {
    const propPanel = document.getElementById('element-properties-panel');
    const noSelectMsg = document.getElementById('no-element-selected-message');

    if (!activeId) {
        propPanel.style.display = 'none';
        noSelectMsg.style.display = 'block';
        return;
    }

    propPanel.style.display = 'block';
    noSelectMsg.style.display = 'none';

    const el = elements.find(item => item.id === activeId);
    if (!el) return;

    document.getElementById('prop-element-header').textContent = el.name;
    document.getElementById('prop-el-x').value = el.left;
    document.getElementById('prop-el-y').value = el.top;
    document.getElementById('prop-el-w').value = el.width;
    document.getElementById('prop-el-h').value = el.height;

    const textSettings = document.getElementById('prop-text-settings');
    const photoSettings = document.getElementById('prop-photo-settings');
    const markerSettings = document.getElementById('prop-marker-settings');

    if (el.type === 'text') {
        textSettings.style.display = 'block';
        photoSettings.style.display = 'none';

        document.getElementById('prop-text-content').value = el.textContent || '';
        document.getElementById('prop-text-size').value = el.fontSize || 30;
        document.getElementById('prop-text-weight').value = el.fontWeight || '400';
        document.getElementById('prop-text-font').value = el.fontFamily || 'Google Sans Flex';
        document.getElementById('prop-text-color').value = el.color || '#1e293b';
        document.getElementById('prop-text-align').value = el.textAlign || 'left';
        document.getElementById('prop-text-spacing').value = el.letterSpacing || 0;

        if (el.id && el.id.startsWith('rank_badge_')) {
            markerSettings.style.display = 'block';
            document.getElementById('prop-marker-show').value = el.showMarker ? 'true' : 'false';
            document.getElementById('prop-marker-color').value = el.markerColor || '#eab308';
            document.getElementById('prop-marker-border-w').value = el.markerBorderWidth || 0;
            document.getElementById('prop-marker-border-c').value = el.markerBorderColor || '#ffffff';
        } else {
            markerSettings.style.display = 'none';
        }

    } else if (el.type === 'photo') {
        textSettings.style.display = 'none';
        markerSettings.style.display = 'none';
        photoSettings.style.display = 'block';

        const mapping = studentRankMappings[el.id] || { student_uid: '', zoom: 100, panX: 0, panY: 0 };
        document.getElementById('prop-photo-student').value = mapping.student_uid || '';
        document.getElementById('prop-photo-mask').value = el.mask || 'rounded';
        document.getElementById('prop-photo-zoom').value = mapping.zoom || 100;
        document.getElementById('lbl-zoom-val').textContent = mapping.zoom || 100;
        document.getElementById('prop-photo-panx').value = mapping.panX || 0;
        document.getElementById('prop-photo-pany').value = mapping.panY || 0;
    }
}

function updateActiveElementFromProps() {
    if (!activeId) return;
    const el = elements.find(item => item.id === activeId);
    if (!el) return;

    el.left = parseInt(document.getElementById('prop-el-x').value) || 0;
    el.top = parseInt(document.getElementById('prop-el-y').value) || 0;
    el.width = parseInt(document.getElementById('prop-el-w').value) || 20;
    el.height = parseInt(document.getElementById('prop-el-h').value) || 20;

    if (el.type === 'text') {
        el.textContent = document.getElementById('prop-text-content').value;
        el.fontSize = parseInt(document.getElementById('prop-text-size').value) || 12;
        el.fontWeight = document.getElementById('prop-text-weight').value;
        el.fontFamily = document.getElementById('prop-text-font').value;
        el.color = document.getElementById('prop-text-color').value;
        el.textAlign = document.getElementById('prop-text-align').value;
        el.letterSpacing = parseFloat(document.getElementById('prop-text-spacing').value) || 0;

        if (el.id && el.id.startsWith('rank_badge_')) {
            el.showMarker = document.getElementById('prop-marker-show').value === 'true';
            el.markerColor = document.getElementById('prop-marker-color').value;
            el.markerBorderWidth = parseInt(document.getElementById('prop-marker-border-w').value) || 0;
            el.markerBorderColor = document.getElementById('prop-marker-border-c').value;
        }
    } else if (el.type === 'photo') {
        el.mask = document.getElementById('prop-photo-mask').value;
    }

    drawElements();
    saveHistoryState();
}

// ── 6. Photo Panning and Zooming Controls ────────────
function updateActivePhotoTransform() {
    if (!activeId) return;
    if (!studentRankMappings[activeId]) {
        studentRankMappings[activeId] = { student_uid: '', zoom: 100, panX: 0, panY: 0, photo_override: null };
    }
    const mapping = studentRankMappings[activeId];
    mapping.zoom = parseInt(document.getElementById('prop-photo-zoom').value) || 100;
    document.getElementById('lbl-zoom-val').textContent = mapping.zoom;

    mapping.panX = parseInt(document.getElementById('prop-photo-panx').value) || 0;
    mapping.panY = parseInt(document.getElementById('prop-photo-pany').value) || 0;

    drawElements();
    saveHistoryState();
}

function nudgePhoto(x, y) {
    if (!activeId) return;
    if (!studentRankMappings[activeId]) return;
    const mapping = studentRankMappings[activeId];
    mapping.panX = (mapping.panX || 0) + x;
    mapping.panY = (mapping.panY || 0) + y;

    document.getElementById('prop-photo-panx').value = mapping.panX;
    document.getElementById('prop-photo-pany').value = mapping.panY;
    drawElements();
    saveHistoryState();
}

function zoomPhoto(factor) {
    if (!activeId) return;
    if (!studentRankMappings[activeId]) return;
    const mapping = studentRankMappings[activeId];
    mapping.zoom = Math.max(50, Math.min(400, (mapping.zoom || 100) + factor));

    document.getElementById('prop-photo-zoom').value = mapping.zoom;
    document.getElementById('lbl-zoom-val').textContent = mapping.zoom;
    drawElements();
    saveHistoryState();
}

function resetPhotoTransform() {
    if (!activeId) return;
    if (!studentRankMappings[activeId]) return;
    const mapping = studentRankMappings[activeId];
    mapping.zoom = 100;
    mapping.panX = 0;
    mapping.panY = 0;

    document.getElementById('prop-photo-zoom').value = 100;
    document.getElementById('lbl-zoom-val').textContent = 100;
    document.getElementById('prop-photo-panx').value = 0;
    document.getElementById('prop-photo-pany').value = 0;
    drawElements();
    saveHistoryState();
}

function uploadPhotoOverride(input) {
    if (!activeId) return;
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (!studentRankMappings[activeId]) {
                studentRankMappings[activeId] = { student_uid: '', zoom: 100, panX: 0, panY: 0 };
            }
            studentRankMappings[activeId].photo_override = e.target.result; // Save base64 image string
            drawElements();
            saveHistoryState();
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function updateStudentAssignOverride(studentUid) {
    if (!activeId) return;
    if (!studentRankMappings[activeId]) {
        studentRankMappings[activeId] = { student_uid: '', zoom: 100, panX: 0, panY: 0, photo_override: null };
    }
    studentRankMappings[activeId].student_uid = studentUid;
    drawElements();
    saveHistoryState();
}

// ── 7. Adding / Deleting Rank Block Groups ────────────
function changeRanksCount(count) {
    if (count === 'custom') return;
    const targetCount = parseInt(count);

    // Hide/show default rank elements based on count
    for (let r = 1; r <= 5; r++) {
        const visible = r <= targetCount;
        ['badge', 'photo', 'name', 'institute'].forEach(field => {
            const elId = `rank_${field}_${r}`;
            const el = elements.find(item => item.id === elId);
            if (el) {
                el.visible = visible;
            }
        });
    }
    drawElements();
    saveHistoryState();
}

function addNewStudentRankBlock() {
    saveHistoryState();

    const count = elements.filter(el => el.id.startsWith('rank_photo_')).length;
    const nextRank = count + 1;
    const suffix = (nextRank === 1) ? 'st' : ((nextRank === 2) ? 'nd' : ((nextRank === 3) ? 'rd' : 'th'));
    const yOffset = 470 + (nextRank - 1) * 200;

    const markerColors = {1: '#eab308', 2: '#94a3b8', 3: '#cd7f32', 4: '#64748b'};
    const markerColor = markerColors[nextRank] || '#64748b';

    const newItems = [
        {
            "id": "rank_badge_" + nextRank,
            "name": "Rank " + nextRank + " Badge",
            "type": "text",
            "textContent": nextRank + suffix,
            "left": 125,
            "top": yOffset + 40,
            "width": 90,
            "height": 90,
            "fontFamily": "Google Sans Flex",
            "fontSize": 36,
            "fontWeight": "700",
            "color": "#ffffff",
            "textAlign": "center",
            "lineHeight": 1.0,
            "letterSpacing": 0,
            "opacity": 1,
            "rotate": 0,
            "showMarker": true,
            "markerColor": markerColor
        },
        {
            "id": "rank_photo_" + nextRank,
            "name": "Rank " + nextRank + " Photo",
            "type": "photo",
            "left": 260,
            "top": yOffset,
            "width": 170,
            "height": 170,
            "borderWidth": 0,
            "borderColor": "#fecaca",
            "mask": "rounded",
            "opacity": 1,
            "rotate": 0
        },
        {
            "id": "rank_name_" + nextRank,
            "name": "Rank " + nextRank + " Student Name",
            "type": "text",
            "textContent": "Student Name",
            "left": 480,
            "top": yOffset + 25,
            "width": 800,
            "height": 55,
            "fontFamily": "Google Sans Flex",
            "fontSize": 42,
            "fontWeight": "700",
            "color": "#1e293b",
            "textAlign": "left",
            "lineHeight": 1.2,
            "letterSpacing": 0,
            "opacity": 1,
            "rotate": 0
        },
        {
            "id": "rank_institute_" + nextRank,
            "name": "Rank " + nextRank + " Institute",
            "type": "text",
            "textContent": "College Name",
            "left": 480,
            "top": yOffset + 85,
            "width": 800,
            "height": 45,
            "fontFamily": "Google Sans Flex",
            "fontSize": 30,
            "fontWeight": "400",
            "color": "#64748b",
            "textAlign": "left",
            "lineHeight": 1.2,
            "letterSpacing": 0,
            "opacity": 1,
            "rotate": 0
        }
    ];

    elements.push(...newItems);
    drawElements();
    selectElement("rank_photo_" + nextRank);
}

function addNewTextElement() {
    saveHistoryState();
    const id = 'text_' + Date.now();
    const newText = {
        "id": id,
        "name": "Custom Text Element",
        "type": "text",
        "textContent": "Custom Text Label",
        "left": 400,
        "top": 600,
        "width": 500,
        "height": 80,
        "fontFamily": "Google Sans Flex",
        "fontSize": 36,
        "fontWeight": "700",
        "color": "#1e293b",
        "textAlign": "left",
        "lineHeight": 1.2,
        "letterSpacing": 0,
        "opacity": 1,
        "rotate": 0
    };
    elements.push(newText);
    drawElements();
    selectElement(id);
}

function deleteActiveElement() {
    if (!activeId) return;
    saveHistoryState();
    elements = elements.filter(item => item.id !== activeId);
    activeId = null;
    drawElements();
    updatePropertiesPanel();
}

function duplicateActiveElement() {
    if (!activeId) return;
    saveHistoryState();
    const el = elements.find(item => item.id === activeId);
    if (!el) return;

    const clone = JSON.parse(JSON.stringify(el));
    clone.id = clone.id + '_copy';
    clone.name = clone.name + ' (Copy)';
    clone.left += 40;
    clone.top += 40;
    elements.push(clone);
    drawElements();
    selectElement(clone.id);
}

// ── 8. Render layers list in sidebar ────────────
function renderLayersSidebar() {
    const list = document.getElementById('layers-list');
    list.innerHTML = '';

    // Display elements in reverse order (top layer first)
    [...elements].reverse().forEach(function(el) {
        if (el.visible === false) return;
        const item = document.createElement('div');
        item.className = 'layer-item' + (activeId === el.id ? ' active' : '');
        item.onclick = () => selectElement(el.id);

        item.innerHTML = `
            <span><i class="fas ${el.type === 'text' ? 'fa-font' : 'fa-image'}" style="margin-right:6px; color:#64748b;"></i> ${el.name}</span>
            <div style="display:flex; gap:6px;">
                <button style="border:none; background:none; cursor:pointer; color:#64748b;" onclick="event.stopPropagation(); deleteElementById('${el.id}')" title="Delete"><i class="fas fa-trash-can"></i></button>
            </div>
        `;
        list.appendChild(item);
    });
}

function deleteElementById(id) {
    saveHistoryState();
    elements = elements.filter(item => item.id !== id);
    if (activeId === id) activeId = null;
    drawElements();
    updatePropertiesPanel();
}

// ── 9. Keyboard Shortcuts nudge support ────────────
window.addEventListener('keydown', function(e) {
    if (!activeId) return;

    // Disable nudge triggers when focused inside textareas/inputs
    const activeTag = document.activeElement.tagName.toLowerCase();
    if (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select') return;

    const el = elements.find(item => item.id === activeId);
    if (!el) return;

    let step = 1;
    if (e.shiftKey) step = 10;

    let moved = false;
    if (e.key === 'ArrowUp') {
        el.top -= step; moved = true;
    } else if (e.key === 'ArrowDown') {
        el.top += step; moved = true;
    } else if (e.key === 'ArrowLeft') {
        el.left -= step; moved = true;
    } else if (e.key === 'ArrowRight') {
        el.left += step; moved = true;
    } else if (e.key === 'Delete' || e.key === 'Backspace') {
        deleteActiveElement();
        e.preventDefault();
        return;
    }

    if (moved) {
        e.preventDefault();
        drawElements();
        updatePropertiesPanel();
        saveHistoryState();
    }
});

// Copy / Paste / Cut element bindings
let clipBoardElement = null;
window.addEventListener('keydown', function(e) {
    const activeTag = document.activeElement.tagName.toLowerCase();
    if (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select') return;

    if (e.ctrlKey || e.metaKey) {
        if (e.key.toLowerCase() === 'z') {
            e.preventDefault(); undo();
        } else if (e.key.toLowerCase() === 'y') {
            e.preventDefault(); redo();
        } else if (e.key.toLowerCase() === 'c' && activeId) {
            e.preventDefault();
            const el = elements.find(item => item.id === activeId);
            if (el) clipBoardElement = JSON.parse(JSON.stringify(el));
        } else if (e.key.toLowerCase() === 'x' && activeId) {
            e.preventDefault();
            const el = elements.find(item => item.id === activeId);
            if (el) {
                clipBoardElement = JSON.parse(JSON.stringify(el));
                deleteActiveElement();
            }
        } else if (e.key.toLowerCase() === 'v' && clipBoardElement) {
            e.preventDefault();
            saveHistoryState();
            const clone = JSON.parse(JSON.stringify(clipBoardElement));
            clone.id = clone.id + '_copy_' + Date.now();
            clone.name = clone.name + ' (Copy)';
            clone.left += 30;
            clone.top += 30;
            elements.push(clone);
            drawElements();
            selectElement(clone.id);
        }
    }
});

// ── 10. High Resolution Canvas Export and Save ────────────
function saveDesign(isExporting = false) {
    const loader = document.getElementById('generation-loader');
    const loaderMsg = document.getElementById('loader-message');

    loaderMsg.textContent = isExporting ? 'Generating high-resolution card image...' : 'Saving design configuration...';
    loader.style.display = 'flex';

    const designTitle = document.getElementById('prop-design-title').value;
    const format = document.getElementById('prop-export-format').value;

    // Draw on hidden high-resolution canvas
    const canvas = document.getElementById('native-resolution-canvas');
    const ctx = canvas.getContext('2d');

    canvas.width = bgW;
    canvas.height = bgH;

    ctx.clearRect(0, 0, bgW, bgH);

    // Load background image
    const bgImg = new Image();
    bgImg.crossOrigin = 'anonymous';
    bgImg.src = resolvedBgUrl;

    bgImg.onload = function() {
        ctx.drawImage(bgImg, 0, 0, bgW, bgH);

        // Render elements sequentially to preserve order
        const elementPromises = elements.map(function(el, idx) {
            return new Promise((resolve) => {
                if (el.visible === false) { resolve(); return; }

                let textContent = el.textContent || '';
                let photoSrc = null;

                const rankMatch = String(el.id || '').match(/^rank_(name|institute|photo|badge)_(\d+)$/);
                if (rankMatch) {
                    const field = rankMatch[1];
                    const rankNum = parseInt(rankMatch[2]);
                    const photoElId = 'rank_photo_' + rankNum;
                    const mapping = studentRankMappings[photoElId];
                    if (mapping && mapping.student_uid) {
                        const student = rankingList.find(s => (s.user_id === mapping.student_uid || s.student_email === mapping.student_uid));
                        if (student) {
                            if (field === 'name') textContent = student.name;
                            else if (field === 'institute') textContent = student.college_school;
                            else if (field === 'badge') textContent = student.computed_rank + (student.computed_rank === 1 ? 'st' : (student.computed_rank === 2 ? 'nd' : (student.computed_rank === 3 ? 'rd' : 'th')));
                            else if (field === 'photo') {
                                photoSrc = mapping.photo_override || (student.user_photo ? '../' + student.user_photo : null);
                            }
                        }
                    }
                }

                if (el.type === 'text') {
                    ctx.save();
                    ctx.globalAlpha = el.opacity ?? 1;

                    const fontName = el.fontFamily || 'Google Sans Flex';
                    ctx.font = `${el.fontWeight || '700'} ${el.fontSize}px "${fontName}"`;
                    ctx.fillStyle = el.color || '#1e293b';
                    ctx.textBaseline = 'top';

                    // Simple word wrapping for Chapter title / Student Name
                    const words = textContent.split(' ');
                    let linesArr = [];
                    let currentLine = '';
                    const maxW = el.width;

                    words.forEach(function(word) {
                        const testLine = currentLine ? currentLine + ' ' + word : word;
                        const metrics = ctx.measureText(testLine);
                        if (metrics.width > maxW && currentLine) {
                            linesArr.push(currentLine);
                            currentLine = word;
                        } else {
                            currentLine = testLine;
                        }
                    });
                    if (currentLine) linesArr.push(currentLine);

                    const totalTextH = linesArr.length * (el.fontSize * (el.lineHeight || 1.2));
                    let startY = el.top + (el.height - totalTextH) / 2; // Center vertically within the box bounding height

                    if (el.showMarker) {
                        const cx = el.left + el.width / 2;
                        const cy = el.top + el.height / 2;
                        const r = Math.min(el.width, el.height) / 2;

                        ctx.beginPath();
                        ctx.arc(cx, cy, r, 0, 2 * Math.PI);
                        ctx.fillStyle = el.markerColor || '#eab308';
                        ctx.fill();
                        if (el.markerBorderWidth && el.markerBorderColor) {
                            ctx.lineWidth = el.markerBorderWidth;
                            ctx.strokeStyle = el.markerBorderColor;
                            ctx.stroke();
                        }
                        // Restore fillStyle back to text color
                        ctx.fillStyle = el.color || '#1e293b';
                    }

                    linesArr.forEach(function(line, lIdx) {
                        const lineW = ctx.measureText(line).width;
                        let startX = el.left;
                        if (el.textAlign === 'center') {
                            startX = el.left + (el.width - lineW) / 2;
                        } else if (el.textAlign === 'right') {
                            startX = el.left + (el.width - lineW);
                        }

                        ctx.fillText(line, startX, startY + lIdx * (el.fontSize * (el.lineHeight || 1.2)));
                    });

                    ctx.restore();
                    resolve();
                } else if (el.type === 'photo') {
                    if (photoSrc) {
                        const studentImg = new Image();
                        studentImg.crossOrigin = 'anonymous';
                        studentImg.src = photoSrc;
                        studentImg.onload = function() {
                            ctx.save();
                            ctx.globalAlpha = el.opacity ?? 1;

                            // Clip photo to shape mask
                            ctx.beginPath();
                            if (el.mask === 'circle') {
                                ctx.arc(el.left + el.width/2, el.top + el.height/2, el.width/2, 0, Math.PI * 2);
                                ctx.clip();
                            } else if (el.mask === 'hexagon') {
                                const side = el.width / 4;
                                ctx.moveTo(el.left + side, el.top);
                                ctx.lineTo(el.left + el.width - side, el.top);
                                ctx.lineTo(el.left + el.width, el.top + el.height/2);
                                ctx.lineTo(el.left + el.width - side, el.top + el.height);
                                ctx.lineTo(el.left + side, el.top + el.height);
                                ctx.lineTo(el.left, el.top + el.height/2);
                                ctx.closePath();
                                ctx.clip();
                            } else if (el.mask === 'diamond') {
                                ctx.moveTo(el.left + el.width/2, el.top);
                                ctx.lineTo(el.left + el.width, el.top + el.height/2);
                                ctx.lineTo(el.left + el.width/2, el.top + el.height);
                                ctx.lineTo(el.left, el.top + el.height/2);
                                ctx.closePath();
                                ctx.clip();
                            } else if (el.mask === 'rounded') {
                                const r = el.width * 0.12; // 12% rounded corners
                                ctx.roundRect(el.left, el.top, el.width, el.height, r);
                                ctx.clip();
                            }

                            const mapping = studentRankMappings[el.id] || { zoom: 100, panX: 0, panY: 0 };
                            const scaleFactor = (mapping.zoom || 100) / 100;

                            // Compute zoom cover dimensions
                            const imgW = studentImg.width;
                            const imgH = studentImg.height;
                            const scaleCover = Math.max(el.width / imgW, el.height / imgH);
                            const drawW = imgW * scaleCover * scaleFactor;
                            const drawH = imgH * scaleCover * scaleFactor;

                            // Translate and draw scaled, panned student photo
                            ctx.translate(el.left + el.width/2, el.top + el.height/2);
                            ctx.translate(mapping.panX, mapping.panY);
                            ctx.drawImage(studentImg, -drawW/2, -drawH/2, drawW, drawH);

                            ctx.restore();
                            resolve();
                        };
                        studentImg.onerror = function() { resolve(); };
                    } else {
                        resolve();
                    }
                }
            });
        });

        Promise.all(elementPromises).then(function() {
            // Generate standard raw dataUrl
            let rawDataUrl = canvas.toDataURL(format === 'jpeg' ? 'image/jpeg' : 'image/png', format === 'jpeg' ? 0.95 : 1.0);

            // Post-export physical DPI metadata injection
            let finalDataUrl = rawDataUrl;
            try {
                const buffer = dataURLToArrayBuffer(rawDataUrl);
                const dpiBuffer = (format === 'jpeg') ? injectJPEGDPI(buffer) : injectPNGDPI(buffer);
                finalDataUrl = arrayBufferToDataURL(dpiBuffer, format === 'jpeg' ? 'image/jpeg' : 'image/png');
            } catch (e) {
                console.error("DPI Injection failed:", e);
            }

            const payload = new FormData();
            payload.append('csrf_token', '<?php echo csrf_token(); ?>');
            payload.append('action', 'save_design');
            payload.append('id', savedDesignId);
            payload.append('design_title', designTitle);
            payload.append('academic_year', '<?php echo htmlspecialchars($year); ?>');
            payload.append('course_id', <?php echo $course_id; ?>);
            payload.append('study_plan_id', <?php echo $plan_id; ?>);
            payload.append('activity_id', <?php echo $activity_id; ?>);
            payload.append('template_id', <?php echo $template_id; ?>);
            payload.append('output_format', format);
            payload.append('student_rank_mappings', JSON.stringify(studentRankMappings));
            payload.append('design_config', JSON.stringify({
                elements: elements,
                ranksCount: document.getElementById('prop-ranks-count').value
            }));

            if (isExporting) {
                payload.append('image_data', finalDataUrl);
            }

            fetch('cards-result-designer.php', {
                method: 'POST',
                body: payload
            })
            .then(r => r.json())
            .then(res => {
                loader.style.display = 'none';
                if (res.success) {
                    if (isExporting && res.file_url) {
                        // Create download anchor trigger
                        const a = document.createElement('a');
                        a.href = finalDataUrl;
                        a.download = designTitle.replace(/\s+/g, '_').toLowerCase() + '.' + (format === 'jpeg' ? 'jpg' : 'png');
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }
                    alert('Design configuration saved successfully.');
                    if (!savedDesignId) {
                        window.location.href = 'cards-result-designer.php?id=' + res.id;
                    }
                } else {
                    alert('Failed to save: ' + res.message);
                }
            })
            .catch(err => {
                loader.style.display = 'none';
                alert('Save failed: network or connection error.');
            });
        });
    };
    bgImg.onerror = function() {
        loader.style.display = 'none';
        alert('Failed to load background template image.');
    };
}
</script>

<?php include 'includes/admin_footer.php'; ?>
