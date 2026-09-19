<?php
/**
 * PEPP Admissions — Card Template Background Helper
 * Standalone utility for classifying, canonicalizing, resolving, and rendering
 * Card Template backgrounds across cards.php, cards-edit.php, cards-generate.php,
 * and cards-result-designer.php.
 *
 * ZERO dependencies on file_helper.php or any other admissions module.
 */

if (!function_exists('get_card_bg_type')) {
    /**
     * Classifies the type of card template background.
     * Returns one of: 'gradient', 'color', 'url', 'empty'.
     */
    function get_card_bg_type(?string $bg): string {
        if ($bg === null) return 'empty';
        $bg = trim($bg);
        if ($bg === '') return 'empty';

        // Gradients: linear, radial, conic, repeating variants
        if (preg_match('/^(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/i', $bg) || strpos($bg, 'gradient') !== false) {
            return 'gradient';
        }

        // CSS Solid Colors: #hex, rgb(...), rgba(...), hsl(...), hsla(...)
        if (preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $bg)
            || preg_match('/^(?:rgb|rgba|hsl|hsla)\s*\(/i', $bg)
            || in_array(strtolower($bg), ['transparent', 'white', 'black'], true)) {
            return 'color';
        }

        return 'url';
    }
}

if (!function_exists('canonicalize_card_bg_for_db')) {
    /**
     * Normalizes a card template background string to its canonical database representation.
     *
     * Rules:
     * - Gradients, colors, absolute URLs (http/https), and data URLs are preserved as-is.
     * - Local upload paths in permitted forms:
     *     uploads/card_templates/{filename}
     *     ../uploads/card_templates/{filename}
     *     /uploads/card_templates/{filename}
     *   are canonicalized strictly to:
     *     uploads/card_templates/{filename}
     * - Any unsafe or traversal paths (e.g. ../../config.php, ../other/file.php) are rejected -> return ''.
     */
    function canonicalize_card_bg_for_db(?string $bg): string {
        if ($bg === null) return '';
        $bg = trim($bg);
        if ($bg === '') return '';

        $type = get_card_bg_type($bg);
        if ($type === 'gradient' || $type === 'color') {
            return $bg;
        }

        // Absolute external URL
        if (preg_match('/^https?:\/\//i', $bg)) {
            return $bg;
        }

        // Data URL
        if (strpos($bg, 'data:') === 0) {
            return $bg;
        }

        // Local card template upload path validation:
        // Must match optional (../ or /) + uploads/card_templates/ + clean filename
        if (preg_match('#^(?:\.\./|/)?uploads/card_templates/([a-zA-Z0-9_\.\-]+)$#', $bg, $matches)) {
            $filename = basename($matches[1]);
            // Reject traversal tokens
            if ($filename === '.' || $filename === '..' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
                return '';
            }
            return 'uploads/card_templates/' . $filename;
        }

        // Unrecognized or unsafe local path
        return '';
    }
}

if (!function_exists('resolve_card_bg_browser_url')) {
    /**
     * Resolves a card template background to a browser-accessible URL from the /admissions/ context.
     *
     * Rules:
     * - Gradients & colors: returned as-is (non-image assets)
     * - Absolute URLs (https://, http://) & Data URLs: returned as-is
     * - Root-relative URLs (/uploads/...): returned as-is
     * - Already-prefixed relative URLs (../uploads/...): returned as-is (never double-prepends ../)
     * - Canonical local uploads (uploads/card_templates/...): prepended with '../' -> ../uploads/card_templates/...
     * - Unsafe / traversal paths: returned as ''
     */
    function resolve_card_bg_browser_url(?string $bg): string {
        if ($bg === null) return '';
        $bg = trim($bg);
        if ($bg === '') return '';

        $type = get_card_bg_type($bg);
        if ($type === 'gradient' || $type === 'color') {
            return $bg;
        }

        // Absolute external URL
        if (preg_match('/^https?:\/\//i', $bg)) {
            return $bg;
        }

        // Data URL
        if (strpos($bg, 'data:') === 0) {
            return $bg;
        }

        // Root-relative URL
        if (preg_match('#^/uploads/card_templates/([a-zA-Z0-9_\.\-]+)$#', $bg, $matches)) {
            $filename = basename($matches[1]);
            if ($filename === '.' || $filename === '..' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
                return '';
            }
            return '/uploads/card_templates/' . $filename;
        }

        // Already relative with ../
        if (preg_match('#^\.\./uploads/card_templates/([a-zA-Z0-9_\.\-]+)$#', $bg, $matches)) {
            $filename = basename($matches[1]);
            if ($filename === '.' || $filename === '..' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
                return '';
            }
            return '../uploads/card_templates/' . $filename;
        }

        // Canonical stored local upload path
        if (preg_match('#^uploads/card_templates/([a-zA-Z0-9_\.\-]+)$#', $bg, $matches)) {
            $filename = basename($matches[1]);
            if ($filename === '.' || $filename === '..' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
                return '';
            }
            return '../uploads/card_templates/' . $filename;
        }

        // Unsafe or unrecognized path
        return '';
    }
}

if (!function_exists('resolve_card_bg_disk_path')) {
    /**
     * Resolves the server filesystem path for an uploaded card template background.
     * Strictly restricted to files located in the card-template upload directory.
     * Prevents all directory traversal.
     *
     * Returns null if not a local card template file, or if traversal is detected.
     */
    function resolve_card_bg_disk_path(?string $bg, ?string $admissions_dir = null): ?string {
        if ($bg === null) return null;
        $bg = trim($bg);
        if ($bg === '') return null;

        $type = get_card_bg_type($bg);
        if ($type !== 'url') return null;

        // External or data URLs are not local disk files
        if (preg_match('/^https?:\/\//i', $bg) || strpos($bg, 'data:') === 0) {
            return null;
        }

        // Must match permitted card_templates local path format
        if (!preg_match('#^(?:\.\./|/)?uploads/card_templates/([a-zA-Z0-9_\.\-]+)$#', $bg, $matches)) {
            return null;
        }

        $filename = basename($matches[1]);
        if ($filename === '.' || $filename === '..' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return null;
        }

        $adm_dir = $admissions_dir ? rtrim($admissions_dir, '/\\') : dirname(__DIR__);
        $target_dir = $adm_dir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'card_templates';
        
        $real_target_dir = realpath($target_dir);
        $base_dir = $real_target_dir ?: str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target_dir);

        $target_file = $base_dir . DIRECTORY_SEPARATOR . $filename;

        // If file exists, verify its realpath is strictly within base_dir
        if (file_exists($target_file)) {
            $real_file = realpath($target_file);
            if ($real_file === false) return null;
            $normalized_base = rtrim($base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (strpos($real_file, $normalized_base) !== 0 && $real_file !== $base_dir . DIRECTORY_SEPARATOR . $filename) {
                return null;
            }
            return $real_file;
        }

        return $target_file;
    }
}

if (!function_exists('get_card_bg_css_style')) {
    /**
     * Generates a safe, complete CSS inline style string for card previews/thumbnails.
     * Guarantees that gradients are NEVER wrapped in url(), and protects against CSS breakout.
     */
    function get_card_bg_css_style(?string $bg): string {
        if ($bg === null) return 'background-color: #f1f5f9;';
        $bg = trim($bg);
        if ($bg === '') return 'background-color: #f1f5f9;';

        $type = get_card_bg_type($bg);
        if ($type === 'gradient') {
            // Escape quotes and semicolons to prevent CSS breakout
            $safe_gradient = str_replace([';', '"', "'"], '', $bg);
            return 'background: ' . htmlspecialchars($safe_gradient, ENT_QUOTES, 'UTF-8') . ';';
        }

        if ($type === 'color') {
            $safe_color = str_replace([';', '"', "'"], '', $bg);
            return 'background-color: ' . htmlspecialchars($safe_color, ENT_QUOTES, 'UTF-8') . ';';
        }

        $browser_url = resolve_card_bg_browser_url($bg);
        if ($browser_url === '') {
            return 'background-color: #f1f5f9;';
        }

        if (strpos($browser_url, 'data:') === 0) {
            $safe_url = str_replace(['"', "'", ')', '(', "\r", "\n", '\\'], '', $browser_url);
        } else {
            $safe_url = str_replace([';', '"', "'", ')', '(', "\r", "\n", '\\'], '', $browser_url);
        }
        return 'background-image: url("' . htmlspecialchars($safe_url, ENT_QUOTES, 'UTF-8') . '"); background-size: cover; background-position: center;';
    }
}

if (!function_exists('get_upload_error_message')) {
    /**
     * Converts a PHP file upload error code to a clear human-readable string.
     */
    function get_upload_error_message(int $error_code): string {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize limit in php.ini.';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the form.';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was selected for upload.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server configuration error: missing temporary upload folder.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server error: failed to write uploaded file to disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload.';
            default:
                return 'Unknown upload error (code ' . $error_code . ').';
        }
    }
}

if (!function_exists('render_card_bg_js_helper')) {
    /**
     * Emits client-side JavaScript functions that mirror the PHP classification,
     * normalization, and resolution contracts.
     */
    function render_card_bg_js_helper(): void {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
?>
<script>
function getCardBgType(bg) {
    if (!bg) return 'empty';
    bg = String(bg).trim();
    if (!bg) return 'empty';
    if (/^(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/i.test(bg) || bg.includes('gradient')) {
        return 'gradient';
    }
    if (/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(bg) ||
        /^(?:rgb|rgba|hsl|hsla)\s*\(/i.test(bg) ||
        ['transparent', 'white', 'black'].includes(bg.toLowerCase())) {
        return 'color';
    }
    return 'url';
}

function canonicalizeCardBgForDb(bg) {
    if (!bg) return '';
    bg = String(bg).trim();
    if (!bg) return '';
    var type = getCardBgType(bg);
    if (type === 'gradient' || type === 'color') {
        return bg;
    }
    if (bg.startsWith('data:') || /^https?:\/\//i.test(bg)) {
        return bg;
    }
    var m = bg.match(/^(?:\.\.\/|\/)?uploads\/card_templates\/([a-zA-Z0-9_\.\-]+)$/);
    if (m) {
        var filename = m[1];
        if (filename === '.' || filename === '..' || filename.includes('/') || filename.includes('\\')) {
            return '';
        }
        return 'uploads/card_templates/' + filename;
    }
    return '';
}

function resolveCardBgUrl(bg) {
    if (!bg) return '';
    bg = String(bg).trim();
    if (!bg) return '';
    var type = getCardBgType(bg);
    if (type === 'gradient' || type === 'color') {
        return bg;
    }
    if (bg.startsWith('data:') || /^https?:\/\//i.test(bg)) {
        return bg;
    }
    // Root-relative
    var mRoot = bg.match(/^\/uploads\/card_templates\/([a-zA-Z0-9_\.\-]+)$/);
    if (mRoot) return '/uploads/card_templates/' + mRoot[1];
    
    // Already relative with ../
    var mRel = bg.match(/^\.\.\/uploads\/card_templates\/([a-zA-Z0-9_\.\-]+)$/);
    if (mRel) return '../uploads/card_templates/' + mRel[1];

    // Canonical local uploads
    var mCanon = bg.match(/^uploads\/card_templates\/([a-zA-Z0-9_\.\-]+)$/);
    if (mCanon) return '../uploads/card_templates/' + mCanon[1];

    return '';
}

function applyCardBgToElement(element, bg) {
    if (!element) return;
    if (!bg) {
        element.style.backgroundColor = '#f1f5f9';
        element.style.backgroundImage = 'none';
        return;
    }
    var type = getCardBgType(bg);
    if (type === 'gradient') {
        element.style.background = bg;
    } else if (type === 'color') {
        element.style.backgroundColor = bg;
        element.style.backgroundImage = 'none';
    } else {
        var resolvedUrl = resolveCardBgUrl(bg);
        if (resolvedUrl) {
            element.style.background = 'none';
            element.style.backgroundImage = 'url("' + resolvedUrl + '")';
            element.style.backgroundSize = '100% 100%';
        } else {
            element.style.backgroundColor = '#f1f5f9';
            element.style.backgroundImage = 'none';
        }
    }
}

function drawBackgroundOnCanvasCtx(ctx, bgStr, w, h) {
    if (!bgStr) {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        return;
    }
    var type = getCardBgType(bgStr);
    if (type === 'color') {
        ctx.fillStyle = bgStr;
        ctx.fillRect(0, 0, w, h);
    } else if (type === 'gradient') {
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
    } else {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
    }
}
</script>
<?php
    }
}
