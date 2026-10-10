<?php
/**
 * PEPP Updates Public Portal — Core Helper Functions
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
        return htmlspecialchars($html);
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // Wrap with UTF-8 meta to preserve Unicode Malayalam, Hindi, Arabic, emojis
    $wrapped = '<?xml encoding="utf-8" ?><div id="__pepp_root__">' . $html . '</div>';
    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $dom->getElementById('__pepp_root__');
    if (!$root) {
        return htmlspecialchars(strip_tags($html));
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
                    // Disallow all on* handlers and raw style
                    if (str_starts_with($attrName, 'on') || $attrName === 'style') {
                        $attrsToRemove[] = $attrName;
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

if (!function_exists('pepp_updates_table_exists')) {
    /**
     * Checks if a specific table exists.
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
        $calc = pepp_public_calculate_status($post, $newDuration);
        $post['is_expired'] = $calc['is_expired'];
        $post['is_new']     = $calc['is_new'];
    }
    unset($post);

    return $posts;
}
