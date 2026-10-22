<?php
/**
 * PEPP Updates Public Portal - Core Helper Functions
 *
 * Implements read-only queries, safe HTML sanitization, runtime lifecycle logic,
 * NEW label duration calculations, privacy-conscious hashed visit counting,
 * and clean URL generation.
 */

if (!defined('PEPP_PUBLIC_UPDATES_PORTAL')) {
    define('PEPP_PUBLIC_UPDATES_PORTAL', true);
}

/**
 * Retrieves a setting from updates_settings.
 */
function pepp_public_get_setting(PDO $pdo, string $key, $default = null) {
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
 * Checks whether the public WhatsApp updates subscription section is enabled.
 * Defaults to true (1) to preserve existing behavior.
 */
function pepp_public_is_whatsapp_section_enabled(PDO $pdo): bool {
    $val = pepp_public_get_setting($pdo, 'whatsapp_updates_section_enabled', '1');
    return (string)$val !== '0';
}

if (!function_exists('pepp_updates_column_exists')) {
    function pepp_updates_column_exists(PDO $pdo, string $table, string $column): bool {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
                $stmt = $pdo->query("PRAGMA table_info(" . $cleanTable . ")");
                if ($stmt) {
                    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($cols as $c) {
                        if (strcasecmp($c['name'], $column) === 0) return true;
                    }
                }
                return false;
            }
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('pepp_updates_table_exists')) {
    function pepp_updates_table_exists(PDO $pdo, string $table): bool {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
                $stmt->execute([$table]);
                return (bool)$stmt->fetchColumn();
            }
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * Returns the configurable duration (in days) for the NEW badge.
 * Defaults to 7 days if setting is missing or invalid.
 */
function pepp_public_get_new_duration(PDO $pdo): int {
    $days = (int)pepp_public_get_setting($pdo, 'new_label_duration_days', 7);
    return ($days > 0 && $days <= 90) ? $days : 7;
}

/**
 * Calculates runtime public visibility and status for an update post:
 * - is_public: true only if status == 'published' AND publish_at <= now()
 * - is_expired: true if expires_at is set AND expires_at <= now()
 * - is_new: true if is_public == true AND is_expired == false AND publish_at >= (now - duration)
 * - display_status: 'NEW', 'PUBLISHED', 'EXPIRED', 'UNPUBLISHED'
 */
function pepp_public_calculate_status(array $post, int $newDurationDays): array {
    $now = time();
    $status = $post['status'] ?? 'draft';
    $publishAt = !empty($post['publish_at']) ? strtotime($post['publish_at']) : null;
    $expiresAt = !empty($post['expires_at']) ? strtotime($post['expires_at']) : null;

    $isPublic = ($status === 'published') && ($publishAt !== null) && ($publishAt <= $now);
    $isExpired = ($expiresAt !== null) && ($expiresAt <= $now);

    $isNew = false;
    if ($isPublic && !$isExpired && $publishAt !== null) {
        $cutoff = strtotime("-{$newDurationDays} days", $now);
        $isNew = ($publishAt >= $cutoff);
    }

    $displayStatus = 'UNPUBLISHED';
    if ($isPublic) {
        if ($isExpired) {
            $displayStatus = 'EXPIRED';
        } elseif ($isNew) {
            $displayStatus = 'NEW';
        } else {
            $displayStatus = 'PUBLISHED';
        }
    }

    return [
        'is_public'      => $isPublic,
        'is_expired'     => $isExpired,
        'is_new'         => $isNew,
        'display_status' => $displayStatus,
    ];
}

/**
 * Generates portal URLs with clean mod_rewrite support or query string fallback.
 */
function pepp_public_url(string $type, $param = null): string {
    $cleanUrls = true; // Can be toggled or detected
    switch ($type) {
        case 'home':
            return '/';
        case 'update':
            return $cleanUrls ? ('/update/' . rawurlencode((string)$param)) : ('/update.php?slug=' . urlencode((string)$param));
        case 'categories':
            return $cleanUrls ? '/categories' : '/categories.php';
        case 'category':
            return $cleanUrls ? ('/category/' . rawurlencode((string)$param)) : ('/category.php?slug=' . urlencode((string)$param));
        case 'search':
            return $param !== null ? ('/search.php?q=' . urlencode((string)$param)) : '/search.php';
        case 'subscribe':
            return $cleanUrls ? '/subscribe' : '/subscribe.php';
        case 'privacy':
            return $cleanUrls ? '/privacy' : '/privacy.php';
        case 'terms':
            return $cleanUrls ? '/terms' : '/terms.php';
        default:
            return '/';
    }
}

/**
 * Resolves banner image path to a safe, valid web URL.
 */
function pepp_public_resolve_banner(?string $bannerPath): string {
    if (empty($bannerPath)) {
        return '';
    }

    $bannerPath = trim($bannerPath);
    if ($bannerPath === '') {
        return '';
    }

    // Protocol-relative URL
    if (strpos($bannerPath, '//') === 0) {
        return 'https:' . $bannerPath;
    }

    // Full HTTP/HTTPS URL
    if (strpos($bannerPath, 'http://') === 0 || strpos($bannerPath, 'https://') === 0) {
        return $bannerPath;
    }
    
    // Relative upload path, e.g. uploads/updates/banners/banner_xyz.jpg
    $cleanPath = ltrim($bannerPath, '/');
    
    // In PEPP ERP on production Hostinger, all uploaded assets reside under
    // /public_html/admissions/uploads/updates/banners/...
    // Canonical web URL is https://pepplearning.in/admissions/uploads/...
    if (strpos($cleanPath, 'admissions/') === 0) {
        return 'https://pepplearning.in/' . $cleanPath;
    }

    if (strpos($cleanPath, 'uploads/') === 0) {
        return 'https://pepplearning.in/admissions/' . $cleanPath;
    }

    return 'https://pepplearning.in/admissions/uploads/' . $cleanPath;
}

/**
 * Sanitizes rich text HTML for safe public rendering.
 * Preserves headings, lists, paragraphs, formatted text, tables, and sanitized links.
 * Strips scripts, iframes, styles, and event handlers.
 */
function pepp_public_sanitize_html(?string $html): string {
    if (!$html || trim($html) === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        $plain = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $html);
        return htmlspecialchars($plain);
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // Wrap with UTF-8 meta to preserve Unicode Malayalam, Hindi, Arabic, emojis
    $wrapped = '<?xml encoding="utf-8" ?><div id="__pepp_root__">' . $html . '</div>';
    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $dom->getElementById('__pepp_root__');
    if (!$root) {
        $plain = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $html);
        return htmlspecialchars(strip_tags($plain));
    }

    $allowedTags = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'strike',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'code', 'pre',
        'ul', 'ol', 'li', 'a',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'div', 'span'
    ];

    $cleanNode = function ($node) use (&$cleanNode, $allowedTags, $root) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);

            // Process children first (depth-first)
            $children = [];
            for ($i = 0; $i < $node->childNodes->length; $i++) {
                $children[] = $node->childNodes->item($i);
            }
            foreach ($children as $child) {
                $cleanNode($child);
            }

            if ($node === $root) {
                return;
            }

            if (!in_array($tag, $allowedTags, true)) {
                // Unwrap or remove tag
                while ($node->firstChild) {
                    $node->parentNode->insertBefore($node->firstChild, $node);
                }
                $node->parentNode->removeChild($node);
                return;
            }

            // Sanitize attributes
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
                            if (!preg_match('/^https?:\/\//i', $href) && strpos($href, '/') !== 0 && strpos($href, '#') !== 0) {
                                $attrsToRemove[] = $attrName;
                            }
                        } elseif (!in_array($attrName, ['target', 'rel', 'title', 'class'], true)) {
                            $attrsToRemove[] = $attrName;
                        }
                    } elseif ($tag === 'table' || $tag === 'th' || $tag === 'td') {
                        if (!in_array($attrName, ['class', 'colspan', 'rowspan', 'scope'], true)) {
                            $attrsToRemove[] = $attrName;
                        }
                    } else {
                        if (!in_array($attrName, ['class', 'id', 'title'], true)) {
                            $attrsToRemove[] = $attrName;
                        }
                    }
                }
                foreach ($attrsToRemove as $removeName) {
                    $node->removeAttribute($removeName);
                }
            }

            // Harden external links with rel="noopener noreferrer"
            if ($tag === 'a' && $node->hasAttribute('href')) {
                $href = $node->getAttribute('href');
                if (preg_match('/^https?:\/\//i', $href)) {
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
    };

    $cleanNode($root);

    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $dom->saveHTML($child);
    }
    $result = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $result);
    return trim($result);
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
 * Privacy-conscious visitor logging.
 * Uses SHA-256 daily hashed IP. Never stores raw IP by default. Deduplicates daily per post.
 */
function pepp_public_log_visit(PDO $pdo, ?int $postId = null): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $today = date('Y-m-d');
        // Salt with static key + today's date so cross-day visitor profiling is impossible
        $salt = 'pepp_public_visit_salt_' . date('Y-m');
        $ipHash = hash('sha256', $ip . '_' . $today . '_' . $salt);

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        // Check if already visited today
        if ($postId !== null) {
            $stmtChk = $pdo->prepare("SELECT id FROM updates_visits WHERE post_id = ? AND ip_hash = ? AND visit_date = ? LIMIT 1");
            $stmtChk->execute([$postId, $ipHash, $today]);
        } else {
            $stmtChk = $pdo->prepare("SELECT id FROM updates_visits WHERE post_id IS NULL AND ip_hash = ? AND visit_date = ? LIMIT 1");
            $stmtChk->execute([$ipHash, $today]);
        }
        
        if ($stmtChk->fetchColumn()) {
            return; // Already counted today
        }

        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 150);
        $ref = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 250);

        // Visitor session ID
        $sessionId = $_COOKIE['pepp_session_id'] ?? null;
        if (!$sessionId || !preg_match('/^[a-f0-9]{32,64}$/i', $sessionId)) {
            $sessionId = bin2hex(random_bytes(16));
            if (!headers_sent()) {
                setcookie('pepp_session_id', $sessionId, time() + 86400 * 30, '/', '', false, true);
            }
        }

        // Check if extended analytics columns exist (Migration 65)
        $hasSess = pepp_updates_column_exists($pdo, 'updates_visits', 'session_id');
        $hasLoc = pepp_updates_column_exists($pdo, 'updates_visits', 'location_status');
        $hasIp = pepp_updates_column_exists($pdo, 'updates_visits', 'ip_address');

        // Check user location in session
        $locData = $_SESSION['pepp_user_location'] ?? null;
        $locStatus = $locData['status'] ?? null;
        $lat = !empty($locData['lat']) ? (float)$locData['lat'] : null;
        $lng = !empty($locData['lng']) ? (float)$locData['lng'] : null;
        $acc = !empty($locData['accuracy']) ? (float)$locData['accuracy'] : null;

        if ($hasSess || $hasLoc || $hasIp) {
            $cols = ['post_id', 'ip_hash', 'user_agent', 'referer', 'visit_date'];
            $vals = [$postId, $ipHash, $ua ?: null, $ref ?: null, $today];
            $placeholders = ['?', '?', '?', '?', '?'];

            if ($hasSess) {
                $cols[] = 'session_id';
                $vals[] = $sessionId;
                $placeholders[] = '?';
            }
            if ($hasIp) {
                $cols[] = 'ip_address';
                $vals[] = $ip;
                $placeholders[] = '?';
            }
            if ($hasLoc && $locStatus) {
                $cols[] = 'location_status';
                $vals[] = $locStatus;
                $placeholders[] = '?';

                $cols[] = 'latitude';
                $vals[] = $lat;
                $placeholders[] = '?';

                $cols[] = 'longitude';
                $vals[] = $lng;
                $placeholders[] = '?';

                $cols[] = 'accuracy';
                $vals[] = $acc;
                $placeholders[] = '?';
            }

            $cols[] = 'created_at';
            $nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";
            $sqlIns = "INSERT INTO updates_visits (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ", {$nowFunc})";
            $stmtIns = $pdo->prepare($sqlIns);
            $stmtIns->execute($vals);
        } else {
            // Standard baseline insert
            $stmtIns = $pdo->prepare("
                INSERT INTO updates_visits (post_id, ip_hash, user_agent, referer, visit_date, created_at)
                VALUES (?, ?, ?, ?, ?, " . ($driver === 'sqlite' ? "datetime('now')" : "NOW()") . ")
            ");
            $stmtIns->execute([$postId, $ipHash, $ua ?: null, $ref ?: null, $today]);
        }
    } catch (Throwable $e) {
        error_log('pepp_public_log_visit error: ' . $e->getMessage());
    }
}

/**
 * Generates social sharing URLs.
 */
function pepp_public_share_links(string $url, string $title, ?string $summary = null): array {
    $encodedUrl   = urlencode($url);
    $encodedTitle = urlencode($title);
    $whatsAppText = urlencode($title . "\n" . ($summary ? ($summary . "\n") : '') . $url);

    return [
        'whatsapp' => "https://api.whatsapp.com/send?text={$whatsAppText}",
        'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encodedUrl}",
        'twitter'  => "https://twitter.com/intent/tweet?text={$encodedTitle}&url={$encodedUrl}&via=pepplearning",
        'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url={$encodedUrl}",
        'copy'     => $url,
    ];
}

/**
 * Retrieves paginated list of published updates with category tags and keywords.
 */
function pepp_public_get_posts(PDO $pdo, array $options = []): array {
    $page     = max(1, (int)($options['page'] ?? 1));
    $limit    = max(1, min(50, (int)($options['limit'] ?? 12)));
    $offset   = ($page - 1) * $limit;
    $catId    = !empty($options['category_id']) ? (int)$options['category_id'] : null;
    $catSlug  = !empty($options['category_slug']) ? trim($options['category_slug']) : null;
    $search   = !empty($options['search']) ? trim($options['search']) : null;
    $driver   = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

    $where = ["p.status = 'published'", "p.publish_at IS NOT NULL", "p.publish_at <= {$nowSql}"];
    $params = [];

    if ($catId !== null) {
        $where[] = "p.id IN (SELECT f_upc.post_id FROM updates_post_categories f_upc WHERE f_upc.category_id = ?)";
        $params[] = $catId;
    } elseif ($catSlug !== null) {
        $where[] = "p.id IN (
            SELECT f_upc.post_id FROM updates_post_categories f_upc
            JOIN updates_categories f_uc ON f_uc.id = f_upc.category_id
            WHERE f_uc.slug = ? AND f_uc.is_active = 1
        )";
        $params[] = $catSlug;
    }

    if ($search !== null && $search !== '') {
        $where[] = "(p.title LIKE ? OR p.short_description LIKE ? OR p.full_description LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereClause = implode(' AND ', $where);

    // Group concat for SQLite vs MySQL
    $catConcatSql = ($driver === 'sqlite') 
        ? "GROUP_CONCAT(DISTINCT c.name)" 
        : "GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ')";

    $sql = "
        SELECT p.*,
               (SELECT COUNT(*) FROM updates_visits v WHERE v.post_id = p.id) AS view_count,
               {$catConcatSql} AS category_names
        FROM updates_posts p
        LEFT JOIN updates_post_categories upc ON upc.post_id = p.id
        LEFT JOIN updates_categories c ON c.id = upc.category_id AND c.is_active = 1
        WHERE {$whereClause}
        GROUP BY p.id
        ORDER BY p.publish_at DESC, p.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $sqlCount = "
        SELECT COUNT(DISTINCT p.id)
        FROM updates_posts p
        WHERE {$whereClause}
    ";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $totalCount = (int)$stmtCount->fetchColumn();

    $newDuration = pepp_public_get_new_duration($pdo);
    foreach ($posts as &$post) {
        if (isset($post['title'])) {
            $post['title'] = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $post['title']);
        }
        if (isset($post['short_description'])) {
            $post['short_description'] = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $post['short_description']);
        }
        $calc = pepp_public_calculate_status($post, $newDuration);
        $post['is_expired']     = $calc['is_expired'];
        $post['is_new']         = $calc['is_new'];
        $post['display_status'] = $calc['display_status'];
    }
    unset($post);

    return [
        'posts'       => $posts,
        'total'       => $totalCount,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => ceil($totalCount / $limit),
    ];
}

/**
 * Retrieves a single published update post by slug.
 * Returns null if not found or if not publicly published.
 */
function pepp_public_get_post_by_slug(PDO $pdo, string $slug): ?array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

    $sql = "
        SELECT p.*,
               (SELECT COUNT(*) FROM updates_visits v WHERE v.post_id = p.id) AS view_count
        FROM updates_posts p
        WHERE p.slug = ?
          AND p.status = 'published'
          AND p.publish_at IS NOT NULL
          AND p.publish_at <= {$nowSql}
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$slug]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        return null;
    }

    // Fetch linked categories
    $stmtCats = $pdo->prepare("
        SELECT c.id, c.name, c.slug
        FROM updates_categories c
        JOIN updates_post_categories upc ON upc.category_id = c.id
        WHERE upc.post_id = ? AND c.is_active = 1
        ORDER BY c.display_order ASC, c.name ASC
    ");
    $stmtCats->execute([$post['id']]);
    $post['categories'] = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

    // Fetch linked keywords
    $stmtKws = $pdo->prepare("
        SELECT k.id, k.keyword, k.category_id
        FROM updates_keywords k
        JOIN updates_post_keywords upk ON upk.keyword_id = k.id
        WHERE upk.post_id = ?
        ORDER BY k.keyword ASC
    ");
    $stmtKws->execute([$post['id']]);
    $post['keywords'] = $stmtKws->fetchAll(PDO::FETCH_ASSOC);

    if (isset($post['title'])) {
        $post['title'] = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $post['title']);
    }
    if (isset($post['short_description'])) {
        $post['short_description'] = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $post['short_description']);
    }

    // Calculate runtime status
    $newDuration = pepp_public_get_new_duration($pdo);
    $calc = pepp_public_calculate_status($post, $newDuration);
    $post['is_expired']     = $calc['is_expired'];
    $post['is_new']         = $calc['is_new'];
    $post['display_status'] = $calc['display_status'];

    return $post;
}

/**
 * Retrieves all active categories with published updates count.
 */
function pepp_public_get_categories(PDO $pdo): array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

    $sql = "
        SELECT c.id, c.name, c.slug, c.description, c.display_order,
               COUNT(DISTINCT p.id) AS post_count
        FROM updates_categories c
        LEFT JOIN updates_post_categories upc ON upc.category_id = c.id
        LEFT JOIN updates_posts p ON p.id = upc.post_id 
             AND p.status = 'published' 
             AND p.publish_at IS NOT NULL 
             AND p.publish_at <= {$nowSql}
        WHERE c.is_active = 1
        GROUP BY c.id
        ORDER BY c.display_order ASC, c.name ASC
    ";

    try {
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('pepp_public_get_categories error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Retrieves an active category by its slug.
 */
function pepp_public_get_category_by_slug(PDO $pdo, string $slug): ?array {
    $stmt = $pdo->prepare("SELECT * FROM updates_categories WHERE slug = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$slug]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cat) return null;

    // Fetch keywords belonging to this category
    $stmtK = $pdo->prepare("SELECT keyword FROM updates_keywords WHERE category_id = ? ORDER BY keyword ASC");
    $stmtK->execute([$cat['id']]);
    $cat['keywords'] = $stmtK->fetchAll(PDO::FETCH_COLUMN);

    return $cat;
}

/**
 * Retrieves related published posts from the same category.
 */
function pepp_public_get_related_posts(PDO $pdo, int $currentPostId, array $categoryIds, int $limit = 3): array {
    if (empty($categoryIds)) {
        return [];
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";
    $inPlaceholders = implode(',', array_fill(0, count($categoryIds), '?'));

    $sql = "
        SELECT DISTINCT p.id, p.title, p.slug, p.short_description, p.banner_image, p.publish_at, p.expires_at, p.status,
               (SELECT COUNT(*) FROM updates_visits v WHERE v.post_id = p.id) AS view_count
        FROM updates_posts p
        JOIN updates_post_categories upc ON upc.post_id = p.id
        WHERE upc.category_id IN ({$inPlaceholders})
          AND p.id != ?
          AND p.status = 'published'
          AND p.publish_at IS NOT NULL
          AND p.publish_at <= {$nowSql}
        ORDER BY p.publish_at DESC
        LIMIT {$limit}
    ";

    $params = array_merge($categoryIds, [$currentPostId]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $newDuration = pepp_public_get_new_duration($pdo);
    foreach ($posts as &$post) {
        if (isset($post['title'])) {
            $post['title'] = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $post['title']);
        }
        if (isset($post['short_description'])) {
            $post['short_description'] = str_replace(["\xE2\x80\x94", '&mdash;', '&#8212;', '&#x2014;'], '-', $post['short_description']);
        }
        $calc = pepp_public_calculate_status($post, $newDuration);
        $post['is_expired'] = $calc['is_expired'];
        $post['is_new']     = $calc['is_new'];
    }
    unset($post);

    return $posts;
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * PEPP Updates — Phase 3: Public Popup Ad Resolution & Lead Capture Helpers
 * ─────────────────────────────────────────────────────────────────────────────
 */

/**
 * Parses field options string into a clean array for select dropdowns.
 * Supports JSON arrays, newline-delimited strings, and comma-delimited strings.
 */
function pepp_public_parse_field_options(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return array_values(array_filter(array_map('trim', $json)));
        }
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $options = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, ',') !== false && count($lines) === 1) {
            $parts = explode(',', $line);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $options[] = $p;
            }
        } else {
            $options[] = $line;
        }
    }
    return $options;
}

/**
 * Generates a scoped, session-backed CSRF token for ad enquiry form.
 */
function pepp_public_generate_ad_token(int $adId, int $postId): string {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    $salt = defined('AUTH_SALT') ? AUTH_SALT : 'pepp_public_ad_salt_2026';
    $raw = "pepp_ad_{$adId}_post_{$postId}_" . date('Y-m-d');
    $token = hash_hmac('sha256', $raw, $salt);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['pepp_ad_token_' . $adId . '_' . $postId] = $token;
    }
    return $token;
}

/**
 * Verifies a scoped CSRF token for ad enquiry form.
 */
function pepp_public_verify_ad_token(string $token, int $adId, int $postId): bool {
    if ($token === '') return false;
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['pepp_ad_token_' . $adId . '_' . $postId])) {
        $expected = (string)$_SESSION['pepp_ad_token_' . $adId . '_' . $postId];
        if ($expected === 'USED') {
            return false;
        }
        if (hash_equals($expected, $token)) {
            return true;
        }
    }
    $salt = defined('AUTH_SALT') ? AUTH_SALT : 'pepp_public_ad_salt_2026';
    $today = hash_hmac('sha256', "pepp_ad_{$adId}_post_{$postId}_" . date('Y-m-d'), $salt);
    if (hash_equals($today, $token)) return true;
    $yesterday = hash_hmac('sha256', "pepp_ad_{$adId}_post_{$postId}_" . date('Y-m-d', strtotime('-1 day')), $salt);
    return hash_equals($yesterday, $token);
}

/**
 * Retrieves the highest priority active ad eligible for a specific published post.
 * Excludes draft, paused, and archived ads.
 * Evaluates both all-posts targeting and post-specific targeting.
 * Deterministic tie-breaking: display_order DESC, id DESC.
 */
function pepp_public_get_eligible_ad(PDO $pdo, int $postId): ?array {
    if ($postId <= 0 || !pepp_updates_table_exists($pdo, 'updates_ads')) {
        return null;
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

        // Verify originating post exists and is currently published
        if (pepp_updates_table_exists($pdo, 'updates_posts')) {
            $stmtPost = $pdo->prepare("
                SELECT id FROM updates_posts
                WHERE id = ? AND status = 'published' AND publish_at IS NOT NULL AND publish_at <= {$nowSql}
                LIMIT 1
            ");
            $stmtPost->execute([$postId]);
            if (!$stmtPost->fetchColumn()) {
                return null;
            }
        }

        $sql = "
            SELECT DISTINCT a.id, a.title, a.slug, a.headline, a.subheadline, a.image_url,
                   a.button_text, a.target_all_posts, a.popup_delay_seconds, a.auto_convert_crm,
                   a.target_course_id, a.status, a.display_order
            FROM updates_ads a
            LEFT JOIN updates_ad_posts uap ON (a.id = uap.ad_id)
            WHERE a.status = 'active'
              AND (
                a.target_all_posts = 1
                OR (a.target_all_posts = 0 AND uap.post_id = ?)
              )
            ORDER BY a.display_order DESC, a.id DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$postId]);
        $ad = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ad) {
            return null;
        }

        // Clamp delay strictly to 1 - 600s
        $ad['popup_delay_seconds'] = max(1, min(600, (int)($ad['popup_delay_seconds'] ?? 15)));

        // Retrieve custom fields
        $fields = [];
        if (pepp_updates_table_exists($pdo, 'updates_ad_fields')) {
            $stmtFields = $pdo->prepare("
                SELECT id, ad_id, field_name, field_label, field_type, is_required, field_options, sort_order
                FROM updates_ad_fields
                WHERE ad_id = ?
                ORDER BY sort_order ASC, id ASC
            ");
            $stmtFields->execute([(int)$ad['id']]);
            $rawFields = $stmtFields->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawFields as $rf) {
                $rf['options_list'] = pepp_public_parse_field_options($rf['field_options'] ?? '');
                $fields[] = $rf;
            }
        }
        $ad['fields'] = $fields;

        // Generate scoped CSRF token
        $ad['csrf_token'] = pepp_public_generate_ad_token((int)$ad['id'], $postId);

        return $ad;
    } catch (Throwable $e) {
        error_log('pepp_public_get_eligible_ad error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Rate limits public ad lead submissions: max 15 submissions per 10 minutes per IP hash.
 * Balances abuse prevention with accessibility on shared campus/hostel Wi-Fi networks.
 */
function pepp_public_rate_limit_ad_submission(PDO $pdo, string $ipHash): bool {
    if ($ipHash === '' || !pepp_updates_table_exists($pdo, 'updates_ad_leads')) {
        return true;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $windowSql = ($driver === 'sqlite') ? "datetime('now', '-10 minutes')" : "DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM updates_ad_leads
            WHERE ip_hash = ? AND created_at >= {$windowSql}
        ");
        $stmt->execute([$ipHash]);
        $count = (int)$stmt->fetchColumn();
        return $count < 15;
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Checks if identical student + phone + ad was submitted in the last 5 minutes.
 * Gracefully suppresses rapid duplicate submissions for the same student while
 * allowing legitimate distinct enquiries (e.g. siblings sharing parent phone).
 */
function pepp_public_check_recent_duplicate(PDO $pdo, int $adId, string $phone, string $studentName = ''): bool {
    if (!pepp_updates_table_exists($pdo, 'updates_ad_leads')) {
        return false;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $windowSql = ($driver === 'sqlite') ? "datetime('now', '-5 minutes')" : "DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
        $stmt = $pdo->prepare("
            SELECT id, name, created_at FROM updates_ad_leads
            WHERE ad_id = ? AND phone = ? AND created_at >= {$windowSql}
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$adId, $phone]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return false;
        }

        // If names are distinct (e.g. siblings or family members using the same contact), do not suppress
        if ($studentName !== '' && !empty($existing['name'])) {
            $n1 = mb_strtolower(trim($studentName));
            $n2 = mb_strtolower(trim($existing['name']));
            if ($n1 !== $n2 && strpos($n1, $n2) === false && strpos($n2, $n1) === false) {
                return false; // Distinct student enquiry: allow
            }
        }

        return true; // Exact duplicate student submission within 5-min window
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Validates, processes, and persists a public lead enquiry from an active popup ad.
 *
 * @param PDO $pdo Active database connection
 * @param array $input Form submission data
 * @param string|null $clientIp Client IP address for rate limiting
 * @return array Structured result containing ok (bool), http_code (int), and message/error
 */
function pepp_public_submit_ad_lead(PDO $pdo, array $input, ?string $clientIp = null): array {
    // 1. Anti-spam: Honeypot check
    $honeypot = trim((string)($input['hp_website'] ?? ''));
    if ($honeypot !== '') {
        return [
            'ok' => true,
            'http_code' => 200,
            'message' => 'Thank you! Your enquiry has been submitted.',
        ];
    }

    // 2. Anti-spam: Submission timestamp verification (reject submissions made in < 1 second)
    $formTime = (int)($input['_form_time'] ?? 0);
    $nowTime = time();
    if ($formTime > 0 && ($nowTime - $formTime) < 1) {
        return [
            'ok' => false,
            'http_code' => 429,
            'error' => 'Submission too fast. Please take your time to fill the form.',
        ];
    }

    // 3. Resolve and validate Ad ID & Post ID
    $adId = (int)($input['ad_id'] ?? 0);
    $postId = (int)($input['post_id'] ?? 0);

    if ($adId <= 0 || $postId <= 0) {
        return [
            'ok' => false,
            'http_code' => 400,
            'error' => 'Invalid ad or post reference.',
        ];
    }

    // 4. CSRF Token verification
    $csrfToken = trim((string)($input['csrf_token'] ?? ''));
    if (!pepp_public_verify_ad_token($csrfToken, $adId, $postId)) {
        return [
            'ok' => false,
            'http_code' => 403,
            'error' => 'Security token invalid or expired. Please refresh the page and try again.',
        ];
    }

    // 5. Rate Limiting via Salted Daily IP Hash
    $ip = $clientIp ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $today = date('Y-m-d');
    $salt = defined('AUTH_SALT') ? AUTH_SALT : ('pepp_public_visit_salt_' . date('Y-m'));
    $ipHash = hash('sha256', $ip . '_' . $today . '_' . $salt);

    if (!pepp_public_rate_limit_ad_submission($pdo, $ipHash)) {
        return [
            'ok' => false,
            'http_code' => 429,
            'error' => 'Too many submissions from this connection. Please try again after a few minutes.',
        ];
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

        // 6. Validate Ad state (Must be active)
        $stmtAd = $pdo->prepare("
            SELECT id, title, slug, headline, target_all_posts, status, target_course_id, auto_convert_crm
            FROM updates_ads
            WHERE id = ?
            LIMIT 1
        ");
        $stmtAd->execute([$adId]);
        $ad = $stmtAd->fetch(PDO::FETCH_ASSOC);

        if (!$ad || ($ad['status'] ?? '') !== 'active') {
            return [
                'ok' => false,
                'http_code' => 400,
                'error' => 'This announcement/ad is no longer active.',
            ];
        }

        // 7. Validate Post state (Must be published and current)
        $stmtPost = $pdo->prepare("
            SELECT id, title, status, publish_at
            FROM updates_posts
            WHERE id = ? AND status = 'published' AND publish_at IS NOT NULL AND publish_at <= {$nowSql}
            LIMIT 1
        ");
        $stmtPost->execute([$postId]);
        $post = $stmtPost->fetch(PDO::FETCH_ASSOC);

        if (!$post) {
            return [
                'ok' => false,
                'http_code' => 400,
                'error' => 'The originating article is unavailable or not published.',
            ];
        }

        // 8. Validate Targeting Eligibility
        if ((int)$ad['target_all_posts'] !== 1) {
            $stmtTarget = $pdo->prepare("
                SELECT 1 FROM updates_ad_posts
                WHERE ad_id = ? AND post_id = ?
                LIMIT 1
            ");
            $stmtTarget->execute([$adId, $postId]);
            if (!$stmtTarget->fetchColumn()) {
                return [
                    'ok' => false,
                    'http_code' => 400,
                    'error' => 'This ad is not designated for this article.',
                ];
            }
        }

        // 9. Fetch Configured Fields for this Ad
        $configuredFields = [];
        if (pepp_updates_table_exists($pdo, 'updates_ad_fields')) {
            $stmtFields = $pdo->prepare("
                SELECT id, field_name, field_label, field_type, is_required, field_options, sort_order
                FROM updates_ad_fields
                WHERE ad_id = ?
                ORDER BY sort_order ASC, id ASC
            ");
            $stmtFields->execute([$adId]);
            $configuredFields = $stmtFields->fetchAll(PDO::FETCH_ASSOC);
        }

        // 10. Extract and Validate Form Inputs
        $submittedData = [];
        $studentName = '';
        $studentPhone = '';

        if (isset($input['name'])) {
            $studentName = trim(strip_tags((string)$input['name']));
        }
        if (isset($input['phone'])) {
            $studentPhone = trim((string)$input['phone']);
        }

        foreach ($configuredFields as $field) {
            $fn = $field['field_name'];
            $fl = $field['field_label'];
            $ft = $field['field_type'];
            $req = (int)($field['is_required'] ?? 0) === 1;

            $rawVal = $input[$fn] ?? null;
            $val = is_string($rawVal) ? trim(strip_tags($rawVal)) : '';

            if ($req && ($val === '' || $rawVal === null)) {
                return [
                    'ok' => false,
                    'http_code' => 422,
                    'error' => "Please enter your {$fl}.",
                ];
            }

            if ($val !== '') {
                switch ($ft) {
                    case 'tel':
                    case 'phone':
                        $cleanPhone = preg_replace('/[^\d+]/', '', $val);
                        if (strlen($cleanPhone) < 7 || strlen($cleanPhone) > 20) {
                            return [
                                'ok' => false,
                                'http_code' => 422,
                                'error' => "Please provide a valid contact number for {$fl}.",
                            ];
                        }
                        $val = $cleanPhone;
                        if ($studentPhone === '') {
                            $studentPhone = $cleanPhone;
                        }
                        break;

                    case 'email':
                        if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                            return [
                                'ok' => false,
                                'http_code' => 422,
                                'error' => "Please provide a valid email address for {$fl}.",
                            ];
                        }
                        $val = strtolower($val);
                        break;

                    case 'number':
                        if (!is_numeric($val)) {
                            return [
                                'ok' => false,
                                'http_code' => 422,
                                'error' => "{$fl} must be a valid number.",
                            ];
                        }
                        break;

                    case 'select':
                        $options = pepp_public_parse_field_options($field['field_options'] ?? '');
                        if (!empty($options) && !in_array($val, $options, true)) {
                            return [
                                'ok' => false,
                                'http_code' => 422,
                                'error' => "Please select a valid option for {$fl}.",
                            ];
                        }
                        break;

                    case 'textarea':
                        if (mb_strlen($val) > 2000) {
                            $val = mb_substr($val, 0, 2000);
                        }
                        break;

                    default:
                        if (mb_strlen($val) > 255) {
                            $val = mb_substr($val, 0, 255);
                        }
                        if ($studentName === '' && (stripos($fn, 'name') !== false || stripos($fl, 'name') !== false)) {
                            $studentName = $val;
                        }
                        break;
                }
            }

            $submittedData[$fn] = $val;
        }

        if ($studentName === '') {
            $studentName = trim(strip_tags((string)($input['student_name'] ?? '')));
        }
        if ($studentPhone === '') {
            $studentPhone = preg_replace('/[^\d+]/', '', trim((string)($input['student_phone'] ?? '')));
        }

        if ($studentName === '') {
            $studentName = 'Student Enquiry';
        }
        if (mb_strlen($studentName) > 150) {
            $studentName = mb_substr($studentName, 0, 150);
        }

        $normalizedDigits = preg_replace('/\D/', '', $studentPhone);
        if (strlen($normalizedDigits) < 10) {
            if (strlen($studentPhone) === 0) {
                $studentPhone = '0000000000';
            } else {
                return [
                    'ok' => false,
                    'http_code' => 422,
                    'error' => 'Please enter a valid 10-digit mobile number.',
                ];
            }
        }
        if (mb_strlen($studentPhone) > 25) {
            $studentPhone = mb_substr($studentPhone, 0, 25);
        }

        // 11. Duplicate check (last 5 minutes for identical student + phone + ad)
        if ($studentPhone !== '0000000000' && pepp_public_check_recent_duplicate($pdo, $adId, $studentPhone, $studentName)) {
            return [
                'ok' => true,
                'http_code' => 200,
                'message' => 'Thank you! We have already received your enquiry and our team will get in touch soon.',
            ];
        }

        // 12. Save Lead into updates_ad_leads
        $courseId = !empty($ad['target_course_id']) ? (int)$ad['target_course_id'] : null;
        $submittedJson = !empty($submittedData) ? json_encode($submittedData, JSON_UNESCAPED_UNICODE) : null;

        $sqlInsert = "
            INSERT INTO updates_ad_leads (
                ad_id, post_id, name, phone, course_id, submitted_data, ip_hash, status, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, 'new', {$nowSql}, {$nowSql}
            )
        ";

        $stmtInsert = $pdo->prepare($sqlInsert);
        $stmtInsert->execute([
            $adId,
            $postId,
            $studentName,
            $studentPhone,
            $courseId,
            $submittedJson,
            $ipHash,
        ]);

        $newLeadId = (int)$pdo->lastInsertId();

        // Invalidate single-use session CSRF token to prevent replay
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['pepp_ad_token_' . $adId . '_' . $postId] = 'USED';
        }

        return [
            'ok' => true,
            'http_code' => 200,
            'lead_id' => $newLeadId,
            'message' => 'Thank you! Your enquiry has been received successfully.',
        ];
    } catch (Throwable $e) {
        error_log('PEPP Ad Enquiry Submission Error: ' . $e->getMessage());
        return [
            'ok' => false,
            'http_code' => 500,
            'error' => 'Unable to submit enquiry right now. Please try again.',
        ];
    }
}
