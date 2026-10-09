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
 */
function pepp_updates_get_setting(PDO $pdo, string $key, $default = null) {
    if (!pepp_updates_tables_exist($pdo)) return $default;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM updates_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? $val : $default;
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
        return nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
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
                    // Disallow all on* handlers and inline styles
                    if (str_starts_with($attrName, 'on') || $attrName === 'style') {
                        $attrsToRemove[] = $attrName;
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
