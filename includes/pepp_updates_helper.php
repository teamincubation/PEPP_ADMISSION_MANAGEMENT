<?php
/**
 * PEPP Updates Module Helper Functions
 * 
 * Provides isolated utility functions for PEPP Updates module in PEPP ERP:
 * - Table status check
 * - Metrics & statistics
 * - HTML sanitization (Quill allowlist)
 * - Banner image upload & validation
 * - Category-aware slug generation
 * - Post lifecycle and runtime status calculation
 * - Configurable NEW label duration
 * - Account 3 broadcast campaign sender isolation
 * - Subscriber consent & audit trail logging
 */

if (!defined('PEPP_UPDATES_BANNER_DIR')) {
    define('PEPP_UPDATES_BANNER_DIR', dirname(__DIR__) . '/uploads/updates/banners');
}

/**
 * Checks if PEPP Updates tables exist (graceful fallback/testing).
 */
function pepp_updates_tables_exist(PDO $pdo): bool {
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $exists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='updates_posts'")->fetchColumn();
        } else {
            $exists = (bool)$pdo->query("SHOW TABLES LIKE 'updates_posts'")->fetchColumn();
        }
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Checks if a specific PEPP Updates table exists.
 */
function pepp_updates_table_exists(PDO $pdo, string $tableName): bool {
    static $cache = [];
    if (isset($cache[$tableName])) return $cache[$tableName];
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
            $stmt->execute([$tableName]);
            return $cache[$tableName] = (bool)$stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return $cache[$tableName] = (bool)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        return $cache[$tableName] = false;
    }
}

if (!function_exists('pepp_updates_column_exists')) {
    /**
     * Checks if a specific column exists in a PEPP Updates table.
     */
    function pepp_updates_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
        static $cache = [];
        $k = "{$tableName}.{$columnName}";
        if (isset($cache[$k])) return $cache[$k];
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->query("PRAGMA table_info({$tableName})");
                $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($cols as $col) {
                    if (strcasecmp($col['name'] ?? '', $columnName) === 0) {
                        return $cache[$k] = true;
                    }
                }
                return $cache[$k] = false;
            } else {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE ?");
                $stmt->execute([$columnName]);
                return $cache[$k] = (bool)$stmt->fetch();
            }
        } catch (Throwable $e) {
            return $cache[$k] = false;
        }
    }
}

/**
 * Returns PEPP Updates 9 Dashboard statistics using lightweight indexed queries.
 */
function pepp_updates_stats(PDO $pdo): array {
    $stats = [
        'total_updates'       => 0,
        'published'           => 0,
        'draft'               => 0,
        'scheduled'           => 0,
        'expired'             => 0,
        'total_views'         => 0,
        'active_subscribers'  => 0,
        'stopped_subscribers' => 0,
        'delivery_campaigns'  => 0,
    ];

    if (!pepp_updates_tables_exist($pdo)) {
        return $stats;
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

        // 1. Total Updates
        $stats['total_updates'] = (int)$pdo->query("SELECT COUNT(*) FROM updates_posts")->fetchColumn();

        // 2. Published (status = published, publish_at in past or null, expires_at in future or null)
        $stats['published'] = (int)$pdo->query("
            SELECT COUNT(*) FROM updates_posts 
            WHERE status = 'published' 
              AND (publish_at IS NULL OR publish_at <= {$nowSql}) 
              AND (expires_at IS NULL OR expires_at > {$nowSql})
        ")->fetchColumn();

        // 3. Draft
        $stats['draft'] = (int)$pdo->query("SELECT COUNT(*) FROM updates_posts WHERE status = 'draft'")->fetchColumn();

        // 4. Scheduled (status = scheduled OR (status = published AND publish_at > NOW()))
        $stats['scheduled'] = (int)$pdo->query("
            SELECT COUNT(*) FROM updates_posts 
            WHERE (status = 'scheduled' OR (status = 'published' AND publish_at IS NOT NULL AND publish_at > {$nowSql}))
              AND status != 'draft'
        ")->fetchColumn();

        // 5. Expired (status = expired OR (status = published AND expires_at IS NOT NULL AND expires_at <= NOW()))
        $stats['expired'] = (int)$pdo->query("
            SELECT COUNT(*) FROM updates_posts 
            WHERE status = 'expired' 
               OR (status = 'published' AND expires_at IS NOT NULL AND expires_at <= {$nowSql})
        ")->fetchColumn();

        // 6. Total Views
        try {
            $stats['total_views'] = (int)$pdo->query("SELECT COUNT(*) FROM updates_visits")->fetchColumn();
        } catch (Throwable $e) {
            $stats['total_views'] = 0;
        }

        // 7. Active WhatsApp Subscribers
        $stats['active_subscribers'] = (int)$pdo->query("SELECT COUNT(*) FROM updates_subscribers WHERE status = 'active'")->fetchColumn();

        // 8. Stopped / Suppressed Subscribers
        $stats['stopped_subscribers'] = (int)$pdo->query("SELECT COUNT(*) FROM updates_subscribers WHERE status IN ('stopped', 'suppressed', 'unsubscribed')")->fetchColumn();

        // 9. Delivery Campaigns
        $stats['delivery_campaigns'] = (int)$pdo->query("SELECT COUNT(*) FROM updates_delivery_campaigns")->fetchColumn();

    } catch (Throwable $e) {
        error_log('pepp_updates_stats error: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * Retrieves a PEPP Updates setting from updates_settings table.
 * Supports canonical key precedence with safe compatibility aliases.
 */
function pepp_updates_get_setting(PDO $pdo, string $key, $default = null) {
    if (!pepp_updates_tables_exist($pdo)) return $default;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM updates_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            return $val;
        }

        // Safe alias fallback: if canonical key not found in DB, check legacy alias
        if ($key === 'ad_leads_auto_convert_enabled') {
            $stmt->execute(['ad_lead_auto_convert_global']);
            $aliasVal = $stmt->fetchColumn();
            if ($aliasVal !== false && $aliasVal !== null) {
                return $aliasVal;
            }
        } elseif ($key === 'ad_lead_auto_convert_global') {
            $stmt->execute(['ad_leads_auto_convert_enabled']);
            $canonicalVal = $stmt->fetchColumn();
            if ($canonicalVal !== false && $canonicalVal !== null) {
                return $canonicalVal;
            }
        }

        return $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Sets a PEPP Updates setting in updates_settings table.
 */
function pepp_updates_set_setting(PDO $pdo, string $key, $value, ?string $description = null, ?string $updatedBy = null): bool {
    if (!pepp_updates_tables_exist($pdo)) return false;
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO updates_settings (setting_key, setting_value, description, updated_by, updated_at)
                VALUES (?, ?, ?, ?, datetime('now'))
                ON CONFLICT(setting_key) DO UPDATE SET 
                    setting_value = excluded.setting_value,
                    description = COALESCE(excluded.description, updates_settings.description),
                    updated_by = excluded.updated_by,
                    updated_at = datetime('now')
            ");
            return $stmt->execute([$key, (string)$value, $description, $updatedBy]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO updates_settings (setting_key, setting_value, description, updated_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value),
                    description = COALESCE(VALUES(description), description),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            return $stmt->execute([$key, (string)$value, $description, $updatedBy]);
        }
    } catch (Throwable $e) {
        error_log('pepp_updates_set_setting error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Evaluates whether an update qualifies for the NEW label.
 * Must respect configurable setting new_label_duration_days from updates_settings.
 * Expired updates must NEVER display NEW.
 */
function pepp_updates_is_new(?string $publishAt, ?string $expiresAt = null, int $durationDays = 7): bool {
    if (empty($publishAt)) return false;
    $pubTime = strtotime($publishAt);
    if ($pubTime === false) return false;

    $now = time();
    // Cannot be NEW if scheduled in the future
    if ($pubTime > $now) return false;

    // Expired updates must never display NEW
    if (!empty($expiresAt)) {
        $expTime = strtotime($expiresAt);
        if ($expTime !== false && $expTime <= $now) {
            return false;
        }
    }

    $cutoff = strtotime("+{$durationDays} days", $pubTime);
    return $now <= $cutoff;
}

/**
 * Calculates effective runtime status of a post based on status, publish_at, and expires_at.
 */
function pepp_updates_effective_status(string $status, ?string $publishAt = null, ?string $expiresAt = null): string {
    $status = strtolower(trim($status));
    $now = time();

    // Draft and archived are explicit manual states
    if ($status === 'draft' || $status === 'archived') {
        return $status;
    }

    // Check expiration first
    if (!empty($expiresAt)) {
        $expTime = strtotime($expiresAt);
        if ($expTime !== false && $expTime <= $now) {
            return 'expired';
        }
    }

    // Check scheduled future publish
    if (!empty($publishAt)) {
        $pubTime = strtotime($publishAt);
        if ($pubTime !== false && $pubTime > $now) {
            return 'scheduled';
        }
    }

    if ($status === 'scheduled') {
        // If scheduled time has passed and not expired, it has become published
        if (!empty($publishAt) && strtotime($publishAt) <= $now) {
            return 'published';
        }
        return 'scheduled';
    }

    return $status;
}

/**
 * Generates clean, collision-safe, URL-friendly slug.
 */
function pepp_updates_generate_slug(PDO $pdo, string $table, string $text, ?int $ignoreId = null, string $slugCol = 'slug', ?string $extraWhere = null, array $extraParams = []): string {
    // Transliterate / sanitize
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'item-' . time();
    }

    $baseSlug = $slug;
    $suffix = 1;

    while (true) {
        $candidate = ($suffix === 1) ? $baseSlug : "{$baseSlug}-{$suffix}";
        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$slugCol}` = ?";
        $params = [$candidate];

        if ($ignoreId !== null) {
            $sql .= " AND id != ?";
            $params[] = $ignoreId;
        }

        if ($extraWhere) {
            $sql .= " AND ({$extraWhere})";
            foreach ($extraParams as $p) {
                $params[] = $p;
            }
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $count = (int)$stmt->fetchColumn();

        if ($count === 0) {
            return $candidate;
        }

        $suffix++;
        if ($suffix > 200) {
            return $baseSlug . '-' . bin2hex(random_bytes(4));
        }
    }
}

/**
 * Allowlist-based HTML sanitizer for Quill content in full_description.
 * 
 * Allowed tags:
 * p, br, strong, b, em, i, u, s, strike, ul, ol, li, h1, h2, h3, h4, h5, h6,
 * a, span, blockquote, table, thead, tbody, tr, th, td
 * 
 * Security rules:
 * - Strips all script, style, iframe, object, embed, svg, math, form, input, button, textarea, select, link, meta, img
 * - Strips all on* event handlers (onclick, onerror, onload, etc.)
 * - Strips raw style attributes
 * - Validates <a> links: only http:// and https:// allowed (no javascript:, data:, vbscript:)
 * - Sets target="_blank" and rel="noopener noreferrer" on external links
 * - Preserves Quill class alignment (ql-align-*)
 */
function pepp_updates_sanitize_html(?string $html): string {
    if ($html === null || trim($html) === '') return '';
    $raw = trim($html);

    if (strpos($raw, '<') === false) {
        $clean = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $raw);
        return nl2br(htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    $libxmlErrors = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapped = '<?xml encoding="utf-8" ?><div>' . $raw . '</div>';
    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($libxmlErrors);

    $allowedTags = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
        'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'a', 'span', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td'
    ];
    $dangerousTags = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'applet', 'base', 'img'
    ];

    $cleanNode = function($node) use (&$cleanNode, $allowedTags, $dangerousTags, &$container) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            if ($node === $container) {
                $children = [];
                foreach ($node->childNodes as $child) {
                    $children[] = $child;
                }
                foreach ($children as $child) {
                    $cleanNode($child);
                }
                return;
            }

            $tag = strtolower($node->nodeName);
            if (in_array($tag, $dangerousTags, true)) {
                $node->parentNode->removeChild($node);
                return;
            }

            // Recurse children first
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                $cleanNode($child);
            }

            if (!in_array($tag, $allowedTags, true)) {
                while ($node->firstChild) {
                    $node->parentNode->insertBefore($node->firstChild, $node);
                }
                $node->parentNode->removeChild($node);
                return;
            }

            // Sanitize element attributes
            if ($node->hasAttributes()) {
                $attrsToRemove = [];
                foreach ($node->attributes as $attr) {
                    $attrName = strtolower($attr->name);
                    // Disallow all on* handlers
                    if (str_starts_with($attrName, 'on')) {
                        $attrsToRemove[] = $attrName;
                        continue;
                    }

                    // Handle inline styles: allow only safe color and background-color declarations
                    if ($attrName === 'style') {
                        $styleVal = trim($attr->value);
                        if (preg_match('/url|expression|javascript|behavior|-moz-binding|@import/i', $styleVal)) {
                            $attrsToRemove[] = $attrName;
                            continue;
                        }
                        $declarations = explode(';', $styleVal);
                        $safeDecls = [];
                        foreach ($declarations as $decl) {
                            $decl = trim($decl);
                            if ($decl === '') continue;
                            if (preg_match('/^(color|background-color)\s*:\s*(#[0-9a-fA-F]{3,8}|rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)|rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*[\d.]+\s*\)|[a-zA-Z]+)$/i', $decl, $m)) {
                                $safeDecls[] = strtolower($m[1]) . ': ' . $m[2];
                            }
                        }
                        if (!empty($safeDecls)) {
                            $attr->value = implode('; ', $safeDecls);
                        } else {
                            $attrsToRemove[] = $attrName;
                        }
                        continue;
                    }

                    if ($tag === 'a') {
                        if ($attrName === 'href') {
                            $href = trim($attr->value);
                            if (!preg_match('/^https?:\/\//i', $href)) {
                                $attrsToRemove[] = $attrName;
                            }
                        } elseif (!in_array($attrName, ['target', 'rel', 'title', 'class'], true)) {
                            $attrsToRemove[] = $attrName;
                        }
                    } elseif ($attrName === 'class') {
                        // Allow safe Quill alignment and formatting classes
                        $val = trim($attr->value);
                        if (!preg_match('/^[a-zA-Z0-9_\-\s]+$/', $val)) {
                            $attrsToRemove[] = $attrName;
                        }
                    } elseif (in_array($tag, ['td', 'th'], true) && in_array($attrName, ['colspan', 'rowspan'], true)) {
                        if (!ctype_digit(trim($attr->value))) {
                            $attrsToRemove[] = $attrName;
                        }
                    } else {
                        $attrsToRemove[] = $attrName;
                    }
                }

                foreach ($attrsToRemove as $a) {
                    $node->removeAttribute($a);
                }

                if ($tag === 'a' && $node->hasAttribute('href')) {
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
    };

    $container = $dom->getElementsByTagName('div')->item(0);
    if ($container) {
        $cleanNode($container);
        $result = '';
        foreach ($container->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }
        $result = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $result);
        return trim($result);
    }

    return '';
}

/**
 * Validates and uploads a banner image to /uploads/updates/banners/.
 * 
 * Allowed formats: JPEG, PNG, WebP.
 * Size limit: 5MB.
 * MIME validated server-side. Collision-safe unique filename.
 */
function pepp_updates_upload_banner(array $file, ?string $oldDbPath = null): array {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No file uploaded or file upload error.'];
    }

    // 5MB file-size limit
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'Banner image exceeds maximum allowed size (5 MB).'];
    }

    // Extension check
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['success' => false, 'error' => 'Invalid file extension. Allowed formats: JPG, PNG, WebP.'];
    }

    // Server-side MIME verification using finfo
    $tmpPath = $file['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/webp' => ['webp']
    ];

    if (!isset($allowedMimes[$detectedMime])) {
        return ['success' => false, 'error' => 'Invalid image MIME type: ' . htmlspecialchars($detectedMime)];
    }

    if (!in_array($ext, $allowedMimes[$detectedMime], true)) {
        // Normalize extension to match detected MIME
        $ext = $allowedMimes[$detectedMime][0];
    }

    // Verify image dimensions / integrity
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return ['success' => false, 'error' => 'Uploaded file is not a valid image.'];
    }

    // Target directory
    $targetDir = PEPP_UPDATES_BANNER_DIR;
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return ['success' => false, 'error' => 'Failed to initialize banner storage directory.'];
        }
    }

    // Generate collision-safe filename
    $uniqueName = 'banner_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $targetPath = $targetDir . '/' . $uniqueName;
    $dbPath = 'uploads/updates/banners/' . $uniqueName;

    // Use compress_image if available in file_helper, else move_uploaded_file
    $uploaded = false;
    if (function_exists('compress_image')) {
        $uploaded = compress_image($tmpPath, $targetPath, 85);
    } else {
        $uploaded = @move_uploaded_file($tmpPath, $targetPath);
    }

    if (!$uploaded && file_exists($tmpPath)) {
        $uploaded = @copy($tmpPath, $targetPath);
    }

    if ($uploaded) {
        // Clean up previous banner file if replacing and safe
        if ($oldDbPath && strpos($oldDbPath, 'uploads/updates/banners/') === 0) {
            $oldFullPath = dirname(__DIR__) . '/' . $oldDbPath;
            if (file_exists($oldFullPath) && is_file($oldFullPath)) {
                @unlink($oldFullPath);
            }
        }
        return ['success' => true, 'path' => $dbPath];
    }

    return ['success' => false, 'error' => 'Failed to save banner image to destination.'];
}

/**
 * Validates broadcast campaign sender account ID.
 * PEPP Updates strictly requires Account 3 (ID: 3). Account 1 or any other account MUST be rejected.
 */
function pepp_updates_validate_campaign_sender(int $senderAccountId): bool {
    return $senderAccountId === 3;
}

/**
 * Records subscriber lifecycle / consent event in updates_subscriber_events table.
 */
function pepp_updates_record_subscriber_event(PDO $pdo, int $subscriberId, string $eventType, ?string $details = null, string $source = 'admin'): bool {
    if (!pepp_updates_tables_exist($pdo)) return false;

    $allowedEvents = [
        'SUBSCRIBED', 'STOPPED', 'RESUBSCRIBED', 
        'CATEGORY_CHANGED', 'PROFILE_UPDATED', 'SUPPRESSED'
    ];
    if (!in_array($eventType, $allowedEvents, true)) {
        return false;
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

        $stmt = $pdo->prepare("
            INSERT INTO updates_subscriber_events (subscriber_id, event_type, details, source, created_at)
            VALUES (?, ?, ?, ?, {$nowSql})
        ");
        return $stmt->execute([$subscriberId, $eventType, $details, $source]);
    } catch (Throwable $e) {
        error_log('pepp_updates_record_subscriber_event error: ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// AD MANAGEMENT & LEAD CAPTURE HELPERS (Phase 2 Foundation)
// ─────────────────────────────────────────────────────────────

/**
 * Retrieves active courses from pepp_courses for canonical course mapping.
 */
function pepp_updates_get_courses(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT id, course_name, course_code FROM pepp_courses WHERE status = 'active' ORDER BY course_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Retrieves published posts for ad targeting selection.
 */
function pepp_updates_get_published_posts(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT id, title, slug FROM updates_posts WHERE status = 'published' ORDER BY title ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Evaluates whether automatic conversion to CRM leads is permitted.
 * Precedence Rule:
 * 1. Global setting `ad_leads_auto_convert_enabled` MUST be '1'.
 * 2. Per-ad setting `auto_convert_crm` MUST be 1.
 * 3. Returns TRUE only when BOTH conditions are met. Otherwise FALSE.
 */
function pepp_updates_is_auto_convert_allowed(PDO $pdo, ?array $ad = null): bool {
    $globalSetting = (string)pepp_updates_get_setting($pdo, 'ad_leads_auto_convert_enabled', '0');
    if ($globalSetting !== '1') {
        return false;
    }
    if ($ad !== null) {
        return ((int)($ad['auto_convert_crm'] ?? 0)) === 1;
    }
    return true;
}

/**
 * Retrieves all ads with optional filtering.
 */
function pepp_updates_get_ads(PDO $pdo, array $filters = []): array {
    if (!pepp_updates_table_exists($pdo, 'updates_ads')) {
        return [];
    }

    try {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(a.title LIKE ? OR a.headline LIKE ?)';
            $searchParam = '%' . trim($filters['search']) . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['status']) && in_array($filters['status'], ['draft', 'active', 'paused', 'archived'], true)) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                a.*,
                pc.course_name AS mapped_course_name,
                (SELECT COUNT(*) FROM updates_ad_leads al WHERE al.ad_id = a.id) AS total_leads_count,
                (SELECT COUNT(*) FROM updates_ad_leads al WHERE al.ad_id = a.id AND al.status = 'converted') AS converted_leads_count,
                (SELECT COUNT(*) FROM updates_ad_posts ap WHERE ap.ad_id = a.id) AS targeted_posts_count
            FROM updates_ads a
            LEFT JOIN pepp_courses pc ON a.target_course_id = pc.id
            WHERE {$whereSql}
            ORDER BY a.display_order ASC, a.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('pepp_updates_get_ads error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Retrieves a single ad by ID with its linked post IDs and custom fields.
 */
function pepp_updates_get_ad(PDO $pdo, int $adId): ?array {
    if (!pepp_updates_table_exists($pdo, 'updates_ads')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT a.*, pc.course_name AS mapped_course_name
            FROM updates_ads a
            LEFT JOIN pepp_courses pc ON a.target_course_id = pc.id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([$adId]);
        $ad = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ad) {
            return null;
        }

        // Linked posts
        $stmtPosts = $pdo->prepare("SELECT post_id FROM updates_ad_posts WHERE ad_id = ?");
        $stmtPosts->execute([$adId]);
        $ad['target_post_ids'] = $stmtPosts->fetchAll(PDO::FETCH_COLUMN);

        // Ordered custom fields
        $stmtFields = $pdo->prepare("SELECT * FROM updates_ad_fields WHERE ad_id = ? ORDER BY sort_order ASC, id ASC");
        $stmtFields->execute([$adId]);
        $ad['fields'] = $stmtFields->fetchAll(PDO::FETCH_ASSOC);

        return $ad;
    } catch (Throwable $e) {
        error_log('pepp_updates_get_ad error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Creates or updates an ad transactionally.
 */
function pepp_updates_save_ad(PDO $pdo, array $adData, array $postIds = [], array $fields = [], ?string $adminUser = null): array {
    if (!pepp_updates_table_exists($pdo, 'updates_ads')) {
        return ['success' => false, 'error' => 'Ad tables have not been created yet.'];
    }

    $id = !empty($adData['id']) ? (int)$adData['id'] : null;
    $title = trim($adData['title'] ?? '');
    $headline = trim($adData['headline'] ?? '');
    $subheadline = trim($adData['subheadline'] ?? '');
    $imageUrl = trim($adData['image_url'] ?? '');
    $buttonText = trim($adData['button_text'] ?? 'Get More Info');
    $targetAllPosts = !empty($adData['target_all_posts']) ? 1 : 0;
    $popupDelaySeconds = max(1, min(600, (int)($adData['popup_delay_seconds'] ?? 15)));
    $autoConvertCrm = !empty($adData['auto_convert_crm']) ? 1 : 0;
    $targetCourseId = !empty($adData['target_course_id']) ? (int)$adData['target_course_id'] : null;
    $statusRaw = $adData['status'] ?? 'draft';
    $status = in_array($statusRaw, ['draft', 'active', 'paused', 'archived'], true) ? $statusRaw : 'draft';
    $displayOrder = (int)($adData['display_order'] ?? 0);
    $slugCandidate = trim($adData['slug'] ?? '');
    if ($slugCandidate === '') {
        $slugCandidate = $title;
    }

    if ($title === '') {
        return ['success' => false, 'error' => 'Ad title is required.'];
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

    $pdo->beginTransaction();
    try {
        $slug = pepp_updates_generate_slug($pdo, 'updates_ads', $slugCandidate, $id);

        if ($id) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE updates_ads SET
                    title = ?,
                    slug = ?,
                    headline = ?,
                    subheadline = ?,
                    image_url = ?,
                    button_text = ?,
                    target_all_posts = ?,
                    popup_delay_seconds = ?,
                    auto_convert_crm = ?,
                    target_course_id = ?,
                    status = ?,
                    display_order = ?,
                    updated_by = ?,
                    updated_at = {$nowSql}
                WHERE id = ?
            ");
            $stmt->execute([
                $title, $slug, $headline, $subheadline, $imageUrl, $buttonText,
                $targetAllPosts, $popupDelaySeconds, $autoConvertCrm, $targetCourseId,
                $status, $displayOrder, $adminUser, $id
            ]);
            $adId = $id;
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO updates_ads (
                    title, slug, headline, subheadline, image_url, button_text,
                    target_all_posts, popup_delay_seconds, auto_convert_crm,
                    target_course_id, status, display_order, created_by, updated_by,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    {$nowSql}, {$nowSql}
                )
            ");
            $stmt->execute([
                $title, $slug, $headline, $subheadline, $imageUrl, $buttonText,
                $targetAllPosts, $popupDelaySeconds, $autoConvertCrm,
                $targetCourseId, $status, $displayOrder, $adminUser, $adminUser
            ]);
            $adId = (int)$pdo->lastInsertId();
        }

        // Synchronize target posts
        $pdo->prepare("DELETE FROM updates_ad_posts WHERE ad_id = ?")->execute([$adId]);
        if (!$targetAllPosts && !empty($postIds)) {
            $stmtInsertPost = $pdo->prepare("INSERT INTO updates_ad_posts (ad_id, post_id, created_at) VALUES (?, ?, {$nowSql})");
            foreach ($postIds as $pId) {
                $pId = (int)$pId;
                if ($pId > 0) {
                    $stmtInsertPost->execute([$adId, $pId]);
                }
            }
        }

        // Synchronize custom fields
        $pdo->prepare("DELETE FROM updates_ad_fields WHERE ad_id = ?")->execute([$adId]);
        if (!empty($fields)) {
            $stmtInsertField = $pdo->prepare("
                INSERT INTO updates_ad_fields (
                    ad_id, field_name, field_label, field_type, is_required,
                    field_options, sort_order, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, {$nowSql}, {$nowSql})
            ");
            $sortIndex = 0;
            $usedFieldNames = [];
            foreach ($fields as $field) {
                $fLabel = trim($field['field_label'] ?? '');
                $rawCandidate = trim($field['field_name'] ?? '');
                $fName = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $rawCandidate)));
                if ($fName === '') {
                    $fName = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $fLabel)));
                }
                $fName = trim(preg_replace('/_+/', '_', $fName), '_');
                if ($fLabel === '' || $fName === '') {
                    continue;
                }

                // Deterministic collision handling (e.g. college_name, college_name_2, college_name_3)
                $baseName = $fName;
                $counter = 2;
                while (isset($usedFieldNames[$fName])) {
                    $fName = $baseName . '_' . $counter;
                    $counter++;
                }
                $usedFieldNames[$fName] = true;

                $fTypeRaw = $field['field_type'] ?? 'text';
                $fType = in_array($fTypeRaw, ['text', 'tel', 'email', 'select', 'textarea'], true) ? $fTypeRaw : 'text';
                $fRequired = !empty($field['is_required']) ? 1 : 0;
                $fOptions = trim($field['field_options'] ?? '');
                $fSort = isset($field['sort_order']) ? (int)$field['sort_order'] : $sortIndex;

                $stmtInsertField->execute([
                    $adId, $fName, $fLabel, $fType, $fRequired, $fOptions, $fSort
                ]);
                $sortIndex++;
            }
        }

        $pdo->commit();
        return ['success' => true, 'id' => $adId, 'slug' => $slug];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('pepp_updates_save_ad error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Database error saving ad: ' . $e->getMessage()];
    }
}

/**
 * Transitions an ad's status (active, paused, archived, draft).
 */
function pepp_updates_toggle_ad_status(PDO $pdo, int $adId, string $newStatus, ?string $adminUser = null): bool {
    if (!pepp_updates_table_exists($pdo, 'updates_ads')) {
        return false;
    }
    if (!in_array($newStatus, ['draft', 'active', 'paused', 'archived'], true)) {
        return false;
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

        $stmt = $pdo->prepare("UPDATE updates_ads SET status = ?, updated_by = ?, updated_at = {$nowSql} WHERE id = ?");
        return $stmt->execute([$newStatus, $adminUser, $adId]);
    } catch (Throwable $e) {
        error_log('pepp_updates_toggle_ad_status error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Deletes an ad safely. Prevents deletion if leads exist.
 */
function pepp_updates_delete_ad(PDO $pdo, int $adId): array {
    if (!pepp_updates_table_exists($pdo, 'updates_ads')) {
        return ['success' => false, 'error' => 'Ad table not found.'];
    }

    try {
        // Pre-check lead count
        if (pepp_updates_table_exists($pdo, 'updates_ad_leads')) {
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM updates_ad_leads WHERE ad_id = ?");
            $stmtCount->execute([$adId]);
            $leadCount = (int)$stmtCount->fetchColumn();

            if ($leadCount > 0) {
                return [
                    'success' => false,
                    'error'   => "Cannot delete ad: it contains {$leadCount} captured lead(s). Archive the ad instead to preserve lead records and audit history."
                ];
            }
        }

        $stmt = $pdo->prepare("DELETE FROM updates_ads WHERE id = ?");
        $stmt->execute([$adId]);
        return ['success' => true];
    } catch (Throwable $e) {
        error_log('pepp_updates_delete_ad error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Database error deleting ad: ' . $e->getMessage()];
    }
}

/**
 * Retrieves summary metrics for captured Ad Leads.
 */
function pepp_updates_get_ad_lead_stats(PDO $pdo): array {
    $stats = [
        'total'     => 0,
        'new'       => 0,
        'converted' => 0,
        'failed'    => 0,
        'blocked'   => 0
    ];

    if (!pepp_updates_table_exists($pdo, 'updates_ad_leads')) {
        return $stats;
    }

    try {
        $stmt = $pdo->query("
            SELECT status, COUNT(*) AS cnt
            FROM updates_ad_leads
            GROUP BY status
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $st = $r['status'] ?? '';
            $cnt = (int)($r['cnt'] ?? 0);
            $stats['total'] += $cnt;
            if (isset($stats[$st])) {
                $stats[$st] = $cnt;
            }
        }
    } catch (Throwable $e) {
        error_log('pepp_updates_get_ad_lead_stats error: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * Retrieves paginated Ad Leads with joined entity titles.
 */
function pepp_updates_get_ad_leads(PDO $pdo, array $filters = [], int $page = 1, int $limit = 25): array {
    if (!pepp_updates_table_exists($pdo, 'updates_ad_leads')) {
        return ['leads' => [], 'total' => 0, 'pages' => 0];
    }

    try {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(al.name LIKE ? OR al.phone LIKE ? OR a.title LIKE ?)';
            $searchParam = '%' . trim($filters['search']) . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['ad_id'])) {
            $where[] = 'al.ad_id = ?';
            $params[] = (int)$filters['ad_id'];
        }

        if (!empty($filters['status']) && in_array($filters['status'], ['new', 'converted', 'failed', 'blocked'], true)) {
            $where[] = 'al.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        // Count total
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM updates_ad_leads al
            LEFT JOIN updates_ads a ON al.ad_id = a.id
            WHERE {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pages = $limit > 0 ? (int)ceil($total / $limit) : 0;
        $offset = max(0, ($page - 1) * $limit);

        $sql = "
            SELECT
                al.*,
                a.title AS ad_title,
                p.title AS post_title,
                p.slug AS post_slug,
                pc.course_name AS mapped_course_name
            FROM updates_ad_leads al
            LEFT JOIN updates_ads a ON al.ad_id = a.id
            LEFT JOIN updates_posts p ON al.post_id = p.id
            LEFT JOIN pepp_courses pc ON al.course_id = pc.id
            WHERE {$whereSql}
            ORDER BY al.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'leads' => $leads,
            'total' => $total,
            'pages' => $pages,
            'page'  => $page
        ];
    } catch (Throwable $e) {
        error_log('pepp_updates_get_ad_leads error: ' . $e->getMessage());
        return ['leads' => [], 'total' => 0, 'pages' => 0];
    }
}
