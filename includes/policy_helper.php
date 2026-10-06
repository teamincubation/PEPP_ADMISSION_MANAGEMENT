<?php
/**
 * PEPP Learning ERP — Policy & Terms Management Helper.
 *
 * Provides:
 *  1. Whitelisted policy keys & mapping from staff application types.
 *  2. DOMDocument allowlist HTML sanitizer for rich text (preventing XSS).
 *  3. Policy retrieval with fallback defaults (schema-tolerant pre-migration).
 *  4. Version-aware policy storage (policy_documents + policy_versions).
 *  5. Immutable policy acceptance logging (policy_acceptances) tied to registrations.
 */

if (!defined('POLICY_KEY_FACULTY')) {
    define('POLICY_KEY_FACULTY', 'faculty_policy');
}
if (!defined('POLICY_KEY_GUEST_FACULTY')) {
    define('POLICY_KEY_GUEST_FACULTY', 'guest_faculty_policy');
}
if (!defined('POLICY_KEY_EMPLOYEE')) {
    define('POLICY_KEY_EMPLOYEE', 'employee_staff_terms');
}
if (!defined('POLICY_KEY_INTERN')) {
    define('POLICY_KEY_INTERN', 'internship_policy');
}

if (!defined('ALLOWED_POLICY_KEYS')) {
    define('ALLOWED_POLICY_KEYS', [
        POLICY_KEY_FACULTY,
        POLICY_KEY_GUEST_FACULTY,
        POLICY_KEY_EMPLOYEE,
        POLICY_KEY_INTERN,
    ]);
}

/**
 * Maps staff registration application_for to its mandatory policy key.
 */
function policy_key_from_application_type(string $application_for): string {
    $v = strtolower(trim($application_for));
    switch ($v) {
        case 'faculty':
            return POLICY_KEY_FACULTY;
        case 'guest_faculty':
            return POLICY_KEY_GUEST_FACULTY;
        case 'employee':
            return POLICY_KEY_EMPLOYEE;
        case 'intern':
            return POLICY_KEY_INTERN;
        default:
            return '';
    }
}

/**
 * Standard readable label for each policy key.
 */
function policy_title_default(string $policy_key): string {
    switch ($policy_key) {
        case POLICY_KEY_FACULTY:
            return 'Faculty Policy';
        case POLICY_KEY_GUEST_FACULTY:
            return 'Guest Faculty Policy';
        case POLICY_KEY_EMPLOYEE:
            return 'Employee & Staff Terms & Conditions';
        case POLICY_KEY_INTERN:
            return 'Internship Policy';
        default:
            return 'Institutional Policy';
    }
}

/**
 * Default fallback content when database tables are pre-migration.
 */
function policy_default_documents(): array {
    return [
        POLICY_KEY_FACULTY => [
            'policy_key'      => POLICY_KEY_FACULTY,
            'title'           => 'Faculty Policy',
            'current_version' => '1.0',
            'content'         => '<h3>1. Academic Excellence & Professional Conduct</h3><p>All faculty members appointed to PEPP Learning are committed to upholding the highest standards of academic integrity, pedagogical excellence, and student mentorship.</p><h3>2. Session Delivery & Preparedness</h3><p>Faculty members agree to conduct scheduled sessions punctually and provide structured academic content aligned with PEPP syllabus requirements.</p><h3>3. Confidentiality & Intellectual Property</h3><p>Teaching materials, questions, notes, and session recordings prepared for PEPP Learning remain the intellectual property of Labinc Education Pvt. Ltd.</p>',
            'status'          => 'active',
            'updated_by'      => 'System Initializer',
            'updated_at'      => '2026-01-01 00:00:00',
            'created_at'      => '2026-01-01 00:00:00',
        ],
        POLICY_KEY_GUEST_FACULTY => [
            'policy_key'      => POLICY_KEY_GUEST_FACULTY,
            'title'           => 'Guest Faculty Policy',
            'current_version' => '1.0',
            'content'         => '<h3>1. Scope of Engagement</h3><p>Invited guest faculty collaborate with PEPP Learning on a visiting or session-by-session basis. Submission and acceptance of this registration does not constitute regular employment or tenure.</p><h3>2. Remuneration & Session Modes</h3><p>Guest faculty honorarium or session compensation is determined per agreed session rates (Live, QPD, Recorded, or Offline) or designated as honorary/pro bono upon administrative review.</p><h3>3. Conduct & Academic Ethics</h3><p>Guest lecturers are expected to foster an inclusive, respectful, and academically rigorous learning environment during all interactive and recorded engagements.</p>',
            'status'          => 'active',
            'updated_by'      => 'System Initializer',
            'updated_at'      => '2026-01-01 00:00:00',
            'created_at'      => '2026-01-01 00:00:00',
        ],
        POLICY_KEY_EMPLOYEE => [
            'policy_key'      => POLICY_KEY_EMPLOYEE,
            'title'           => 'Employee & Staff Terms & Conditions',
            'current_version' => '1.0',
            'content'         => '<h3>1. Employment Terms & Duties</h3><p>Staff members agree to perform duties assigned by PEPP Learning diligently and in accordance with institutional policies and departmental requirements.</p><h3>2. Code of Workplace Conduct</h3><p>Staff shall maintain professional ethics, respectful interpersonal conduct, and protect company assets, learner information, and operational confidentiality.</p><h3>3. Attendance & Timeliness</h3><p>Employees are expected to adhere to allocated working hours, report absences in advance, and maintain accurate attendance logs.</p>',
            'status'          => 'active',
            'updated_by'      => 'System Initializer',
            'updated_at'      => '2026-01-01 00:00:00',
            'created_at'      => '2026-01-01 00:00:00',
        ],
        POLICY_KEY_INTERN => [
            'policy_key'      => POLICY_KEY_INTERN,
            'title'           => 'Internship Policy',
            'current_version' => '1.0',
            'content'         => '<h3>1. Purpose of Internship</h3><p>The internship program at PEPP Learning offers practical learning, skill development, and supervised organizational exposure.</p><h3>2. Intern Responsibilities</h3><p>Interns must comply with project timelines, complete assigned learning modules, and uphold data security policies.</p><h3>3. Completion & Evaluation</h3><p>Internship completion certificates and any agreed stipends are subject to satisfactory performance, attendance, and mentor evaluation upon conclusion of the internship term.</p>',
            'status'          => 'active',
            'updated_by'      => 'System Initializer',
            'updated_at'      => '2026-01-01 00:00:00',
            'created_at'      => '2026-01-01 00:00:00',
        ],
    ];
}

/**
 * Checks whether policy tables are present in the active database.
 */
function policy_tables_exist(PDO $pdo): bool {
    static $cached = [];
    $key = spl_object_hash($pdo);
    if (isset($cached[$key])) return $cached[$key];
    try {
        $st = $pdo->query("SELECT 1 FROM policy_documents LIMIT 0");
        $cached[$key] = true;
    } catch (Throwable $e) {
        $cached[$key] = false;
    }
    return $cached[$key];
}

/**
 * Allowlist-based HTML sanitizer for Policy rich text content.
 * Allows safe tags and safe links, strips script, iframe, objects, on* events.
 */
function policy_sanitize_html(?string $html): string {
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
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'span', 'blockquote',
        'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div'
    ];
    $dangerousTags = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'form', 'input', 'button', 'textarea', 'select', 'link', 'meta',
        'applet', 'base', 'frame', 'frameset'
    ];

    $cleanNode = function($node) use (&$cleanNode, $allowedTags, $dangerousTags) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
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
                // Unwrap disallowed element into its children
                $fragment = $node->ownerDocument->createDocumentFragment();
                while ($node->childNodes->length > 0) {
                    $fragment->appendChild($node->childNodes->item(0));
                }
                $node->parentNode->replaceChild($fragment, $node);
                return;
            }

            // Strip disallowed attributes & check links
            $attrsToRemove = [];
            foreach ($node->attributes as $attr) {
                $attrName = strtolower($attr->name);
                if (strpos($attrName, 'on') === 0 || $attrName === 'style') {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }
                if ($tag === 'a' && $attrName === 'href') {
                    $href = trim($attr->value);
                    if (!preg_match('#^(https?://|mailto:)#i', $href)) {
                        $attrsToRemove[] = $attr->name;
                    }
                }
            }
            foreach ($attrsToRemove as $attrName) {
                $node->removeAttribute($attrName);
            }
        }
    };

    if ($dom->documentElement) {
        $cleanNode($dom->documentElement);
        $body = $dom->saveHTML($dom->documentElement);
        // Remove enclosing wrapper <div>...</div>
        if (preg_match('#^<div>(.*)</div>$#s', $body, $matches)) {
            return trim($matches[1]);
        }
        return trim($body);
    }
    return '';
}

/**
 * Fetch a policy by key (optionally a specific historical version).
 */
function policy_get(PDO $pdo, string $policy_key, ?string $version = null): ?array {
    $policy_key = trim($policy_key);
    if (!in_array($policy_key, ALLOWED_POLICY_KEYS, true)) {
        return null;
    }

    if (policy_tables_exist($pdo)) {
        try {
            if ($version !== null && $version !== '') {
                $st = $pdo->prepare("SELECT policy_key, version AS current_version, title, content, created_by AS updated_by, created_at AS updated_at, 'active' AS status FROM policy_versions WHERE policy_key = ? AND version = ? LIMIT 1");
                $st->execute([$policy_key, $version]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) return $row;
            }
            $st = $pdo->prepare("SELECT * FROM policy_documents WHERE policy_key = ? LIMIT 1");
            $st->execute([$policy_key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (Throwable $e) {}
    }

    // Fallback to seeded memory defaults
    $defs = policy_default_documents();
    return $defs[$policy_key] ?? null;
}

/**
 * List all four policies for admin management.
 * Returns an associative array keyed by policy_key, with each policy guaranteed
 * to have 'policy_key' populated and matching ALLOWED_POLICY_KEYS.
 *
 * @return array<string, array>
 */
function policy_list_all(PDO $pdo): array {
    $defs = policy_default_documents();
    if (!policy_tables_exist($pdo)) {
        return $defs;
    }

    try {
        $st = $pdo->query("SELECT * FROM policy_documents ORDER BY id ASC");
        $db_rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $indexed = [];
        foreach ($db_rows as $r) {
            if (!empty($r['policy_key'])) {
                $indexed[$r['policy_key']] = $r;
            }
        }

        $result = [];
        foreach (ALLOWED_POLICY_KEYS as $k) {
            $row = $indexed[$k] ?? ($defs[$k] ?? null);
            if (!$row) {
                $row = [
                    'policy_key'      => $k,
                    'title'           => policy_title_default($k),
                    'current_version' => '1.0',
                    'content'         => '',
                    'status'          => 'active',
                    'updated_by'      => 'System Initializer',
                    'updated_at'      => date('Y-m-d H:i:s'),
                    'created_at'      => date('Y-m-d H:i:s'),
                ];
            }
            $row['policy_key'] = $k;
            $result[$k] = $row;
        }
        return $result;
    } catch (Throwable $e) {
        return $defs;
    }
}

/**
 * Save an updated policy document and record a version snapshot.
 *
 * @return array ['success' => bool, 'error' => ?string, 'version' => string]
 */
function policy_save(
    PDO $pdo,
    string $policy_key,
    string $title,
    string $content,
    string $admin_user = 'admin',
    string $bump_type = 'none',
    string $status = 'active'
): array {
    $policy_key = trim($policy_key);
    $title = trim($title);
    $status = trim($status) ?: 'active';

    if (!in_array($policy_key, ALLOWED_POLICY_KEYS, true)) {
        return ['success' => false, 'error' => 'Invalid policy key.', 'version' => ''];
    }
    if ($title === '') {
        return ['success' => false, 'error' => 'Policy title is required.', 'version' => ''];
    }
    if (trim($content) === '') {
        return ['success' => false, 'error' => 'Policy content cannot be empty.', 'version' => ''];
    }

    $clean_content = policy_sanitize_html($content);

    // Fetch existing document to check for substantive content changes
    $cur_doc = policy_get($pdo, $policy_key);
    $cur_version = $cur_doc ? (string)$cur_doc['current_version'] : '1.0';
    $cur_content = $cur_doc ? trim((string)$cur_doc['content']) : '';

    $is_existing_doc = ($cur_doc !== null && policy_tables_exist($pdo));
    // Normalize HTML tags/whitespace for comparison (both sanitized)
    $cur_norm = preg_replace('/\s+/', ' ', trim(policy_sanitize_html($cur_content)));
    $new_norm = preg_replace('/\s+/', ' ', trim($clean_content));
    $content_changed = ($is_existing_doc && $cur_norm !== '' && $cur_norm !== $new_norm);

    // Support both signatures:
    // (a) ($pdo, $key, $title, $content, $admin_user, $bump_type, $status)
    // (b) ($pdo, $key, $title, $content, $version, $admin_user)
    if (preg_match('/^\d+(\.\d+)+$/', $admin_user) && !preg_match('/^\d+(\.\d+)+$/', $bump_type)) {
        $version = $admin_user;
        $admin_user = trim($bump_type) ?: 'admin';
    } else {
        if ($content_changed && $bump_type === 'none') {
            return [
                'success' => false,
                'error'   => 'Substantive policy content changes require a Minor (+0.1) or Major (+1.0) version bump to preserve audit integrity. Existing policy snapshots cannot be modified.',
                'version' => ''
            ];
        }

        if ($bump_type === 'major' || $bump_type === 'minor') {
            $version = policy_next_version($cur_version, $bump_type);
        } elseif (preg_match('/^\d+(\.\d+)+$/', $bump_type)) {
            $version = $bump_type;
        } else {
            $version = $cur_version;
        }
    }

    if (!preg_match('/^\d+(\.\d+)+$/', $version)) {
        return ['success' => false, 'error' => 'Version must be formatted like 1.0, 1.1, or 2.0.', 'version' => ''];
    }

    try {
        $pdo->beginTransaction();

        $now = date('Y-m-d H:i:s');

        // 1. Update or Insert policy_documents
        $chk = $pdo->prepare("SELECT id FROM policy_documents WHERE policy_key = ? LIMIT 1");
        $chk->execute([$policy_key]);
        $existing_id = $chk->fetchColumn();

        if ($existing_id) {
            $st_doc = $pdo->prepare("
                UPDATE policy_documents
                SET title = ?, current_version = ?, content = ?, status = ?, updated_by = ?, updated_at = ?
                WHERE policy_key = ?
            ");
            $st_doc->execute([$title, $version, $clean_content, $status, $admin_user, $now, $policy_key]);
        } else {
            $st_doc = $pdo->prepare("
                INSERT INTO policy_documents
                    (policy_key, title, current_version, content, status, updated_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st_doc->execute([$policy_key, $title, $version, $clean_content, $status, $admin_user, $now, $now]);
        }

        // 2. Insert or preserve policy_versions snapshot
        // If this is a new version or no version snapshot exists yet, insert it.
        // Once a policy_versions row exists for (policy_key, version), it is a completely immutable historical snapshot.
        // NEVER UPDATE: title, content, status, created_by, or created_at on policy_versions.
        // Metadata-only edits (title, status) update policy_documents only, preserving historical snapshots intact.
        $ver_exists = false;
        try {
            $st_chk = $pdo->prepare("SELECT 1 FROM policy_versions WHERE policy_key = ? AND version = ? LIMIT 1");
            $st_chk->execute([$policy_key, $version]);
            $ver_exists = (bool)$st_chk->fetchColumn();
        } catch (Throwable $e) {}

        if (!$ver_exists) {
            $st_ver = $pdo->prepare("
                INSERT INTO policy_versions (policy_key, version, title, content, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $st_ver->execute([$policy_key, $version, $title, $clean_content, $status, $admin_user, $now]);
        }

        $pdo->commit();
        return ['success' => true, 'error' => null, 'version' => $version];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('policy_save error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage(), 'version' => ''];
    }
}

/**
 * Record an immutable policy acceptance for a registration request.
 *
 * In pre-migration environments (where policy tables do not exist), it falls back
 * gracefully to allow registration continuity.
 * Once Migration 60 is active, any failure to record policy acceptance returns false
 * so the caller transaction can rollback immediately.
 *
 * @return bool True on successful acceptance record (or pre-migration continuity).
 *              False on failure.
 */
function policy_record_acceptance(PDO $pdo, int $reg_request_id, string $policy_key, string $policy_version, ?string $ip, ?string $ua): bool {
    if ($reg_request_id <= 0 || !in_array($policy_key, ALLOWED_POLICY_KEYS, true)) {
        return false;
    }

    // Graceful fallback for pre-migration environments where policy tables are not yet present
    if (!policy_tables_exist($pdo)) {
        return true;
    }

    $ip = substr(trim((string)$ip), 0, 45);
    $ua = substr(trim((string)$ua), 0, 500);
    $now = date('Y-m-d H:i:s');

    try {
        $st = $pdo->prepare("
            INSERT INTO policy_acceptances
                (registration_request_id, policy_key, policy_version, accepted_at, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ok = $st->execute([$reg_request_id, $policy_key, $policy_version, $now, $ip, $ua, $now]);
        if (!$ok) {
            error_log("policy_record_acceptance: execute failed for request ID {$reg_request_id}");
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('policy_record_acceptance error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch acceptance evidence for an application reference / request ID.
 */
function policy_get_acceptance(PDO $pdo, int $reg_request_id): ?array {
    if ($reg_request_id <= 0) return null;
    try {
        $st = $pdo->prepare("
            SELECT pa.*, pd.title AS policy_title
            FROM policy_acceptances pa
            LEFT JOIN policy_documents pd ON pd.policy_key = pa.policy_key
            WHERE pa.registration_request_id = ?
            ORDER BY pa.id DESC LIMIT 1
        ");
        $st->execute([$reg_request_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Throwable $e) {}
    return null;
}

/**
 * Compute the next version string based on current version.
 */
function policy_next_version(string $current_version, string $bump_type = 'minor'): string {
    $parts = explode('.', trim($current_version));
    $major = (int)($parts[0] ?? 1);
    $minor = (int)($parts[1] ?? 0);

    if ($bump_type === 'major') {
        return ($major + 1) . '.0';
    }
    return $major . '.' . ($minor + 1);
}
