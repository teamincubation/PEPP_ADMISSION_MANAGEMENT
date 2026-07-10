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

$active_page = 'cards';
$page_title  = 'Generate Card from Template';
$page_sub    = 'Fill in personalization details and preview card before generating';
include 'includes/admin_nav.php';
?>

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
}
.canvas-element.selected {
    outline: 2px dashed var(--accent);
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
                <div class="form-field-group">
                    <div class="form-field-title"><?php echo htmlspecialchars($el['name']); ?></div>
                    <?php if ($el['type'] === 'text'): ?>
                        <input type="text" data-id="<?php echo $el['id']; ?>" value="<?php echo htmlspecialchars($el['textContent'] ?? ''); ?>" oninput="updateFieldText(<?php echo $el['id']; ?>, this.value)" class="field-input" style="width:100%;" required>
                    <?php elseif ($el['type'] === 'photo'): ?>
                        <input type="file" data-id="<?php echo $el['id']; ?>" accept="image/*" onchange="loadPhotoPlaceholder(<?php echo $el['id']; ?>, this)" class="field-file-input" style="width:100%;" required>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

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
        <div class="canvas-container" id="generator-canvas" style="background-image: url('../<?php echo htmlspecialchars($tpl['bg_image']); ?>');">
            <!-- Rendered layers -->
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

<script>
var bgW = <?php echo $canvas_w; ?>;
var bgH = <?php echo $canvas_h; ?>;
var bgUrl = '../<?php echo htmlspecialchars($tpl['bg_image']); ?>';
var elements = <?php echo json_encode($elements); ?>;
var photos = {}; // Cache for base64 uploaded photo blobs
var activeId = null;

// Initialize Google Fonts used in the template
elements.forEach(function(el) {
    if (el.type === 'text' && el.fontFamily) {
        loadFont(el.fontFamily);
    }
});

function loadFont(fontFamily) {
    if (!fontFamily) return;
    var id = 'font-' + fontFamily.replace(/\s+/g, '-').toLowerCase();
    if (document.getElementById(id)) return;
    var link = document.createElement('link');
    link.id = id;
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fontFamily) + '&display=swap';
    document.head.appendChild(link);
}

function initCanvas() {
    var container = document.getElementById('generator-canvas');
    if (!container) return;
    
    var parent = container.parentElement;
    var maxW = parent.clientWidth - 40;
    var maxH = parent.clientHeight - 40;
    var scale = Math.min(maxW / bgW, maxH / bgH, 1);
    
    container.style.width = bgW + 'px';
    container.style.height = bgH + 'px';
    container.style.transform = 'scale(' + scale + ')';
    container.style.transformOrigin = 'center center';
    
    drawElements();
}

function drawElements() {
    var container = document.getElementById('generator-canvas');
    container.innerHTML = '';
    
    elements.forEach(function(el, idx) {
        var div = document.createElement('div');
        div.className = 'canvas-element' + (activeId === el.id ? ' selected' : '');
        div.style.left = el.left + '%';
        div.style.top = el.top + '%';
        div.style.width = el.width + '%';
        div.style.height = el.height + '%';
        div.style.opacity = el.opacity ?? 1;
        div.style.transform = 'rotate(' + (el.rotate ?? 0) + 'deg)';
        div.style.zIndex = idx + 1;
        
        if (el.type === 'text') {
            div.textContent = el.textContent || '';
            div.style.fontFamily = el.fontFamily || 'Arial';
            div.style.fontSize = el.fontSize + 'px';
            div.style.fontWeight = el.fontWeight || 'normal';
            div.style.color = el.color || '#000000';
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
            if (photos[el.id]) {
                div.style.backgroundImage = 'url("' + photos[el.id] + '")';
                div.style.backgroundSize = 'cover';
                div.style.backgroundPosition = 'center';
                div.style.backgroundRepeat = 'no-repeat';
            } else {
                div.style.background = '#e2e8f0 url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' width=\'24\' height=\'24\'%3E%3Cpath fill=\'%2364748b\' d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z\'/%3E%3C/svg%3E") no-repeat center';
                div.style.backgroundSize = '40px';
            }
            
            // Mask shapes
            if (el.mask === 'circle') div.style.borderRadius = '50%';
            else if (el.mask === 'oval') div.style.borderRadius = '50% / 30%';
            else if (el.mask === 'hexagon') div.style.clipPath = 'polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%)';
            else if (el.mask === 'diamond') div.style.clipPath = 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)';
            else if (el.mask === 'rounded') div.style.borderRadius = '12px';
            else { div.style.borderRadius = '0'; div.style.clipPath = 'none'; }
        }
        
        // Let user select and slide coordinates on preview temporarily
        div.addEventListener('mousedown', function(e) {
            e.stopPropagation();
            activeId = el.id;
            // Redraw selection outline
            elements.forEach((o, i) => {
                var item = container.children[i];
                if (item) {
                    if (o.id === el.id) item.classList.add('selected');
                    else item.classList.remove('selected');
                }
            });
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

function updateFieldText(id, val) {
    var el = elements.find(e => e.id === id);
    if (el) {
        el.textContent = val;
        drawElements();
    }
}

function loadPhotoPlaceholder(id, input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            photos[id] = e.target.result;
            drawElements();
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ── native Canvas Rendering ────────────
function triggerGeneration(e) {
    e.preventDefault();
    
    // Check if background image is loaded
    var bgImg = new Image();
    bgImg.crossOrigin = "anonymous";
    bgImg.src = bgUrl;
    
    bgImg.onload = function() {
        // Wait for all fonts to be loaded before rendering to canvas
        document.fonts.ready.then(function() {
            var canvas = document.getElementById('native-resolution-canvas');
            var ctx = canvas.getContext('2d');
            
            // Set canvas bounds to native template dimensions
            canvas.width = bgW;
            canvas.height = bgH;
            
            // Draw background
            ctx.drawImage(bgImg, 0, 0, bgW, bgH);
            
            // Render elements sequentially
            var promises = elements.map(function(el) {
                return new Promise(function(resolve) {
                    ctx.save();
                    ctx.globalAlpha = el.opacity ?? 1;
                    
                    var x = (el.left / 100) * bgW;
                    var y = (el.top / 100) * bgH;
                    var w = (el.width / 100) * bgW;
                    var h = (el.height / 100) * bgH;
                    
                    // Rotation helper
                    if (el.rotate) {
                        ctx.translate(x + w/2, y + h/2);
                        ctx.rotate((el.rotate * Math.PI) / 180);
                        ctx.translate(-(x + w/2), -(y + h/2));
                    }
                    
                    if (el.type === 'text') {
                        // Set typography properties
                        ctx.fillStyle = el.color || '#000000';
                        var weight = el.fontWeight || 'normal';
                        ctx.font = weight + ' ' + el.fontSize + 'px "' + (el.fontFamily || 'Arial') + '"';
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
                        
                        ctx.fillText(el.textContent || '', textX, y + h / 2);
                        ctx.restore();
                        resolve();
                    } else if (el.type === 'photo') {
                        if (photos[el.id]) {
                            var studentImg = new Image();
                            studentImg.src = photos[el.id];
                            studentImg.onload = function() {
                                // Helper to define the mask/border path
                                function definePath() {
                                    ctx.beginPath();
                                    if (el.mask === 'circle') {
                                        ctx.arc(x + w/2, y + h/2, Math.min(w, h)/2, 0, Math.PI * 2);
                                    } else if (el.mask === 'oval') {
                                        ctx.ellipse(x + w/2, y + h/2, w/2, h/2, 0, 0, Math.PI * 2);
                                    } else if (el.mask === 'hexagon') {
                                        ctx.moveTo(x + w*0.25, y);
                                        ctx.lineTo(x + w*0.75, y);
                                        ctx.lineTo(x + w, y + h*0.5);
                                        ctx.lineTo(x + w*0.75, y + h);
                                        ctx.lineTo(x + w*0.25, y + h);
                                        ctx.lineTo(x, y + h*0.5);
                                        ctx.closePath();
                                    } else if (el.mask === 'diamond') {
                                        ctx.moveTo(x + w*0.5, y);
                                        ctx.lineTo(x + w, y + h*0.5);
                                        ctx.lineTo(x + w*0.5, y + h);
                                        ctx.lineTo(x, y + h*0.5);
                                        ctx.closePath();
                                    } else if (el.mask === 'rounded') {
                                        var radius = 12;
                                        ctx.moveTo(x + radius, y);
                                        ctx.lineTo(x + w - radius, y);
                                        ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
                                        ctx.lineTo(x + w, y + h - radius);
                                        ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
                                        ctx.lineTo(x + radius, y + h);
                                        ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
                                        ctx.lineTo(x, y + radius);
                                        ctx.quadraticCurveTo(x, y, x + radius, y);
                                        ctx.closePath();
                                    } else {
                                        // Rectangle (none)
                                        ctx.rect(x, y, w, h);
                                    }
                                }
                                
                                // 1. Clip and draw image using CSS cover/center logic
                                ctx.save();
                                if (el.mask && el.mask !== 'none') {
                                    definePath();
                                    ctx.clip();
                                }
                                
                                var imgW = studentImg.width;
                                var imgH = studentImg.height;
                                var imgRatio = imgW / imgH;
                                var boxRatio = w / h;
                                
                                var sx = 0, sy = 0, sw = imgW, sh = imgH;
                                if (imgRatio > boxRatio) {
                                    sw = imgH * boxRatio;
                                    sx = (imgW - sw) / 2;
                                } else if (imgRatio < boxRatio) {
                                    sh = imgW / boxRatio;
                                    sy = (imgH - sh) / 2;
                                }
                                
                                ctx.drawImage(studentImg, sx, sy, sw, sh, x, y, w, h);
                                ctx.restore(); // Restore context to remove clipping mask
                                
                                // 2. Draw border outside of clipping mask
                                if (el.borderWidth > 0) {
                                    ctx.save();
                                    definePath();
                                    ctx.strokeStyle = el.borderColor || '#000';
                                    ctx.lineWidth = el.borderWidth;
                                    ctx.stroke();
                                    ctx.restore();
                                }
                                
                                ctx.restore(); // Restore the outer save()
                                resolve();
                            };
                        } else {
                            // Background placeholder
                            ctx.fillStyle = '#e2e8f0';
                            ctx.fillRect(x, y, w, h);
                            ctx.restore();
                            resolve();
                        }
                    }
                });
            });
            
            Promise.all(promises).then(function() {
                var format = document.getElementById('download-format').value;
                var dataUrl = canvas.toDataURL('image/' + (format === 'pdf' ? 'jpeg' : format), 1.0);
                
                if (format === 'pdf') {
                    if (typeof window.jspdf === 'undefined') {
                        var script = document.createElement('script');
                        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
                        script.onload = function() {
                            generatePDF(dataUrl);
                        };
                        document.head.appendChild(script);
                    } else {
                        generatePDF(dataUrl);
                    }
                } else {
                    document.getElementById('df-image-data').value = dataUrl;
                    document.getElementById('df-format').value = format;
                    document.getElementById('download-form').submit();
                }
            });
        });
    };
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

window.addEventListener('resize', initCanvas);
window.addEventListener('DOMContentLoaded', initCanvas);
</script>

<?php include 'includes/admin_footer.php'; ?>
