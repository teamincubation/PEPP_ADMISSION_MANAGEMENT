<?php
/**
 * PEPP Learning ERP - WhatsApp Marketing Templates Management (Phase 1)
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('communication');

$active_page = 'whatsapp-marketing-templates';
$page_title  = 'WhatsApp Marketing Templates';
$page_sub    = 'Create and manage approved Meta marketing templates locally';

$success_message = '';
$error_message   = '';

// Load Meta API credentials
$stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'whatsapp_%'");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$businessId  = $settings['whatsapp_business_id'] ?? '';
$accessToken = $settings['whatsapp_access_token'] ?? '';
$apiVersion  = $settings['whatsapp_api_version'] ?? 'v20.0';

require_once 'includes/communication/Providers/WhatsAppCloudProvider.php';
$provider = new WhatsAppCloudProvider($businessId, $settings['whatsapp_phone_id'] ?? '', $accessToken, $apiVersion);

// Support list of languages
$supported_languages = [
    'en_US' => 'English (US)',
    'en_GB' => 'English (UK)',
    'ml'    => 'Malayalam',
    'hi'    => 'Hindi',
    'ar'    => 'Arabic',
    'ta'    => 'Tamil',
    'te'    => 'Telugu',
    'kn'    => 'Kannada'
];

/* ── POST Actions ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // 1. SAVE DRAFT OR SUBMIT TO META
        if ($action === 'save_template' || $action === 'submit_meta') {
            $tpl_name = strtolower(trim($_POST['template_name'] ?? ''));
            $category = trim($_POST['category'] ?? 'MARKETING');
            $language = trim($_POST['language'] ?? 'en_US');
            
            $header_type = trim($_POST['header_type'] ?? 'NONE');
            $header_text = trim($_POST['header_text'] ?? '');
            
            $body_text = trim($_POST['body_text'] ?? '');
            $footer_text = trim($_POST['footer_text'] ?? '');
            
            $button_type = trim($_POST['button_type'] ?? 'NONE');
            $buttons_input = $_POST['buttons'] ?? [];
            $var_examples = $_POST['examples'] ?? [];

            // Server-side Validations
            if (!preg_match('/^[a-z0-9_]+$/', $tpl_name)) {
                $error_message = 'Template name must contain only lowercase letters, numbers, and underscores.';
            } elseif (strlen($tpl_name) > 512) {
                $error_message = 'Template name is too long.';
            } elseif (empty($body_text)) {
                $error_message = 'Template body text cannot be empty.';
            } else {
                // Check local duplicate template name
                $stmtCheck = $pdo->prepare("SELECT id FROM communication_templates WHERE channel = 'whatsapp' AND template_name = ? AND status <> 'deleted' LIMIT 1");
                $stmtCheck->execute([$tpl_name]);
                if ($stmtCheck->fetch()) {
                    $error_message = "A local template named '{$tpl_name}' already exists.";
                } else {
                    // Extract body variables and check ordering
                    preg_match_all('/\{\{(\d+)\}\}/', $body_text, $body_matches);
                    $body_vars = !empty($body_matches[1]) ? array_map('intval', $body_matches[1]) : [];
                    
                    // Validate variables sequence (no gaps e.g. {{1}}, {{3}})
                    $vars_valid = true;
                    if (!empty($body_vars)) {
                        sort($body_vars);
                        $expected = 1;
                        foreach ($body_vars as $v) {
                            if ($v !== $expected) {
                                if ($v === $expected - 1) continue; // Allow repeats
                                $vars_valid = false;
                                break;
                            }
                            $expected++;
                        }
                    }

                    if (!$vars_valid) {
                        $error_message = 'Variables in the body must be sequential and start at {{1}} without gaps.';
                    } else {
                        // Compile meta components payload structure
                        $components = [];

                        // Header Component
                        if ($header_type !== 'NONE') {
                            $header_comp = [
                                'type' => 'HEADER',
                                'format' => $header_type
                            ];
                            if ($header_type === 'TEXT') {
                                $header_comp['text'] = $header_text;
                                // Handle header variables if present
                                preg_match_all('/\{\{(\d+)\}\}/', $header_text, $hdr_matches);
                                if (!empty($hdr_matches[1])) {
                                    $header_comp['example'] = [
                                        'header_text' => [$var_examples['header'] ?? 'Sample Header']
                                    ];
                                }
                            } else {
                                // For Image/Video/Document headers, Meta requires a public sample URL file in examples
                                $default_media_url = '';
                                if ($header_type === 'IMAGE') {
                                    $default_media_url = 'https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png';
                                } elseif ($header_type === 'VIDEO') {
                                    $default_media_url = 'https://www.w3schools.com/html/mov_bbb.mp4';
                                } elseif ($header_type === 'DOCUMENT') {
                                    $default_media_url = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';
                                }
                                $header_comp['example'] = [
                                    'header_handle' => [$default_media_url]
                                ];
                            }
                            $components[] = $header_comp;
                        }

                        // Body Component
                        $body_comp = [
                            'type' => 'BODY',
                            'text' => $body_text
                        ];
                        // Process examples if variables are detected
                        $unique_body_vars = array_unique($body_vars);
                        if (!empty($unique_body_vars)) {
                            $body_examples = [];
                            foreach ($unique_body_vars as $v_idx) {
                                $body_examples[] = !empty($var_examples[$v_idx]) ? $var_examples[$v_idx] : 'Sample Value';
                            }
                            $body_comp['example'] = [
                                'body_text' => [$body_examples]
                            ];
                        }
                        $components[] = $body_comp;

                        // Footer Component
                        if (!empty($footer_text)) {
                            $components[] = [
                                'type' => 'FOOTER',
                                'text' => $footer_text
                            ];
                        }

                        // Buttons Component
                        if ($button_type !== 'NONE') {
                            $buttons_list = [];
                            if ($button_type === 'QUICK_REPLY') {
                                foreach (array_slice($buttons_input['quick_reply'] ?? [], 0, 3) as $txt) {
                                    if (trim($txt)) {
                                        $buttons_list[] = [
                                            'type' => 'QUICK_REPLY',
                                            'text' => substr(trim($txt), 0, 25)
                                        ];
                                    }
                                }
                            } elseif ($button_type === 'CTA') {
                                // 1 Phone button max
                                $phone_txt = trim($buttons_input['phone_text'] ?? '');
                                $phone_num = trim($buttons_input['phone_number'] ?? '');
                                if (!empty($phone_txt) && !empty($phone_num)) {
                                    $buttons_list[] = [
                                        'type' => 'PHONE_NUMBER',
                                        'text' => substr($phone_txt, 0, 25),
                                        'phone_number' => $phone_num
                                    ];
                                }
                                // 1 Website link button max
                                $url_txt = trim($buttons_input['url_text'] ?? '');
                                $url_val = trim($buttons_input['url_value'] ?? '');
                                if (!empty($url_txt) && !empty($url_val)) {
                                    $buttons_list[] = [
                                        'type' => 'URL',
                                        'text' => substr($url_txt, 0, 25),
                                        'url' => $url_val
                                    ];
                                }
                            }

                            if (!empty($buttons_list)) {
                                $components[] = [
                                    'type' => 'BUTTONS',
                                    'buttons' => $buttons_list
                                ];
                            }
                        }

                        // Local serialization payload
                        $meta_data = json_encode([
                            'is_marketing'   => true,
                            'components'     => $components,
                            'body_text'      => $body_text,
                            'header_type'    => $header_type,
                            'header_text'    => $header_text,
                            'footer_text'    => $footer_text,
                            'button_type'    => $button_type,
                            'buttons'        => $buttons_input,
                            'variables'      => $var_examples
                        ]);

                        if ($action === 'save_template') {
                            // Save as Local DRAFT
                            try {
                                $stmtSave = $pdo->prepare("
                                    INSERT INTO communication_templates (channel, template_name, language, status, category, meta_data, created_at, updated_at)
                                    VALUES ('whatsapp', ?, ?, 'draft', ?, ?, NOW(), NOW())
                                ");
                                $stmtSave->execute([$tpl_name, $language, $category, $meta_data]);
                                $success_message = "Marketing template draft '{$tpl_name}' saved locally.";
                            } catch (Exception $e) {
                                $error_message = "Failed to save draft: " . $e->getMessage();
                            }
                        } elseif ($action === 'submit_meta') {
                            if (empty($businessId) || empty($accessToken)) {
                                $error_message = 'Please configure Meta Business Account ID and Access Token in settings first.';
                            } else {
                                // Execute Meta template creation call
                                $res = $provider->createTemplate($tpl_name, $category, $language, $components);
                                if ($res && !empty($res['success'])) {
                                    $meta_template_id = $res['id'] ?? null;
                                    
                                    // Update local JSON metadata to include Meta Template ID
                                    $meta_array = json_decode($meta_data, true);
                                    $meta_array['meta_template_id'] = $meta_template_id;
                                    $meta_data_updated = json_encode($meta_array);

                                    try {
                                        $stmtSave = $pdo->prepare("
                                            INSERT INTO communication_templates (channel, template_name, language, status, category, meta_data, created_at, updated_at)
                                            VALUES ('whatsapp', ?, ?, 'pending', ?, ?, NOW(), NOW())
                                        ");
                                        $stmtSave->execute([$tpl_name, $language, $category, $meta_data_updated]);
                                        $success_message = "Template successfully submitted to Meta WABA! Status: PENDING. (Meta ID: {$meta_template_id})";
                                    } catch (Exception $e) {
                                        $error_message = "API submission succeeded but saving locally failed: " . $e->getMessage();
                                    }
                                } else {
                                    $error_message = "Meta API Submission Rejected: " . $provider->getLastError();
                                }
                            }
                        }
                    }
                }
            }
        }

        // 2. SYNC FROM META
        if ($action === 'sync_all') {
            if (empty($businessId) || empty($accessToken)) {
                $error_message = 'Please configure Business ID and Access Token in settings first.';
            } else {
                $url = "https://graph.facebook.com/{$apiVersion}/{$businessId}/message_templates?limit=100";
                $headers = ["Authorization: Bearer {$accessToken}"];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                if ($err) {
                    $error_message = "Meta connection failed: " . $err;
                } else {
                    $data = json_decode($response, true);
                    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data'])) {
                        $templates = $data['data'];
                        $syncedCount = 0;

                        $pdo->beginTransaction();
                        try {
                            $stmtUpsert = $pdo->prepare("
                                INSERT INTO communication_templates (channel, template_name, language, status, category, quality_status, rejection_reason, meta_data, updated_at) 
                                VALUES ('whatsapp', ?, ?, ?, ?, ?, ?, ?, NOW()) 
                                ON DUPLICATE KEY UPDATE status = VALUES(status), category = VALUES(category), quality_status = VALUES(quality_status), rejection_reason = VALUES(rejection_reason), meta_data = VALUES(meta_data), updated_at = NOW()
                            ");

                            foreach ($templates as $tpl) {
                                $name = $tpl['name'] ?? '';
                                $lang = $tpl['language'] ?? 'en';
                                $status = strtolower($tpl['status'] ?? 'approved');
                                $category = $tpl['category'] ?? '';
                                $qualityStatus = strtolower($tpl['quality_score']['score'] ?? 'unknown');
                                $rejectedReason = $tpl['rejected_reason'] ?? null;
                                
                                $bodyText = '';
                                $headerText = '';
                                $footerText = '';
                                foreach ($tpl['components'] ?? [] as $comp) {
                                    if (($comp['type'] ?? '') === 'BODY') {
                                        $bodyText = $comp['text'] ?? '';
                                    } elseif (($comp['type'] ?? '') === 'HEADER') {
                                        $headerText = $comp['text'] ?? '';
                                    } elseif (($comp['type'] ?? '') === 'FOOTER') {
                                        $footerText = $comp['text'] ?? '';
                                    }
                                }

                                // Load existing local metadata to preserve is_marketing flag if preset
                                $stmtLocal = $pdo->prepare("SELECT meta_data FROM communication_templates WHERE template_name = ? LIMIT 1");
                                $stmtLocal->execute([$name]);
                                $existing_meta = json_decode($stmtLocal->fetchColumn() ?: '', true) ?: [];

                                $metaData = json_encode([
                                    'is_marketing' => isset($existing_meta['is_marketing']) ? $existing_meta['is_marketing'] : ($category === 'MARKETING'),
                                    'meta_template_id' => $tpl['id'] ?? null,
                                    'components' => $tpl['components'] ?? [],
                                    'body_text' => $bodyText,
                                    'header_text' => $headerText,
                                    'footer_text' => $footerText
                                ]);

                                $stmtUpsert->execute([$name, $lang, $status, $category, $qualityStatus, $rejectedReason, $metaData]);
                                $syncedCount++;
                            }

                            $pdo->commit();
                            $success_message = "Successfully synchronized {$syncedCount} templates from Meta Account.";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error_message = "Sync database write failed: " . $e->getMessage();
                        }
                    } else {
                        $error_message = "Meta API Error: " . ($data['error']['message'] ?? 'Unknown Error');
                    }
                }
            }
        }

        // 3. DELETE TEMPLATE
        if ($action === 'delete_template') {
            $tpl_name = trim($_POST['delete_name'] ?? '');
            if (empty($tpl_name)) {
                $error_message = 'Invalid template name for deletion.';
            } else {
                // Fetch local template to check status
                $stmtFind = $pdo->prepare("SELECT status FROM communication_templates WHERE template_name = ? LIMIT 1");
                $stmtFind->execute([$tpl_name]);
                $localStatus = $stmtFind->fetchColumn();

                if ($localStatus === 'draft') {
                    // Drafts are deleted locally only
                    $stmtDel = $pdo->prepare("DELETE FROM communication_templates WHERE template_name = ?");
                    $stmtDel->execute([$tpl_name]);
                    $success_message = "Draft template '{$tpl_name}' deleted locally.";
                } else {
                    if (empty($businessId) || empty($accessToken)) {
                        $error_message = 'Please configure Meta Business ID and Access Token in settings first.';
                    } else {
                        // Request deletion from Meta API
                        $res = $provider->deleteTemplate($tpl_name);
                        if ($res) {
                            // Update local record to deleted status to maintain audit trail
                            $stmtUpd = $pdo->prepare("UPDATE communication_templates SET status = 'deleted', updated_at = NOW() WHERE template_name = ?");
                            $stmtUpd->execute([$tpl_name]);
                            $success_message = "Template '{$tpl_name}' successfully deleted from Meta and marked as DELETED locally.";
                        } else {
                            $error_message = "Meta API deletion rejected: " . $provider->getLastError();
                        }
                    }
                }
            }
        }
    }
}

/* ── Query local templates list ── */
$f_search   = trim($_GET['search'] ?? '');
$f_status   = trim($_GET['status'] ?? '');
$f_category = trim($_GET['category'] ?? '');
$f_language = trim($_GET['language'] ?? '');

$where = ["channel = 'whatsapp' AND status <> 'deleted'"];
$params = [];

if ($f_search !== '') {
    $where[] = "template_name LIKE ?";
    $params[] = "%{$f_search}%";
}
if ($f_status !== '') {
    $where[] = "status = ?";
    $params[] = $f_status;
}
if ($f_category !== '') {
    $where[] = "category = ?";
    $params[] = $f_category;
}
if ($f_language !== '') {
    $where[] = "language = ?";
    $params[] = $f_language;
}

$where_sql = implode(' AND ', $where);
$stmtList = $pdo->prepare("SELECT * FROM communication_templates WHERE {$where_sql} ORDER BY id DESC");
$stmtList->execute($params);
$localTemplates = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// Map local templates to separate Marketing and Non-marketing for isolation
$marketingTemplates = [];
foreach ($localTemplates as $tpl) {
    $meta = json_decode($tpl['meta_data'], true) ?: [];
    // Identify as marketing if explicitly flagged in JSON or if Meta category is MARKETING
    if (!empty($meta['is_marketing']) || strtoupper($tpl['category']) === 'MARKETING') {
        $marketingTemplates[] = $tpl;
    }
}

include 'includes/admin_nav.php';
?>

<div class="container-fluid" style="padding:20px;">
    <?php if ($success_message): ?>
        <div class="alert alert-success" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; padding:12px 18px; border-radius:12px; margin-bottom:20px;">
            <i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger" style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 18px; border-radius:12px; margin-bottom:20px;">
            <i class="fas fa-circle-xmark"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- ── NAVIGATION TABS ── -->
    <div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid #e5e7eb; padding-bottom:8px;">
        <a href="communication-dashboard.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-gears"></i> API Settings &amp; Queue</a>
        <a href="communication-templates.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-layer-group"></i> Meta Templates Sync</a>
        <a href="whatsapp-marketing-templates.php" class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-magic"></i> Marketing Templates</a>
        <a href="communication-campaigns.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fas fa-bullhorn"></i> Bulk Campaigns</a>
        <a href="whatsapp-inbox.php" class="btn btn-sm btn-outline" style="border-radius:8px;"><i class="fab fa-whatsapp"></i> WhatsApp Inbox</a>
    </div>

    <!-- Toggle Action Panels -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
        <div>
            <h2 style="margin:0; font-size:1.4rem; font-weight:800; color:#1e293b;">WhatsApp Marketing Template Manager</h2>
            <p style="margin:4px 0 0; font-size:0.8rem; color:#64748b;">Manage approved Meta marketing templates isolated from your ERP core transactional messages.</p>
        </div>
        <div style="display:flex; gap:10px;">
            <button onclick="toggleView('list')" class="btn btn-outline" id="btn-tab-list" style="border-radius:8px; font-weight:700;"><i class="fas fa-list"></i> View Templates</button>
            <button onclick="toggleView('create')" class="btn btn-primary" id="btn-tab-create" style="border-radius:8px; font-weight:700;"><i class="fas fa-plus"></i> Create Template</button>
            <form method="POST" style="display:inline-block;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="sync_all">
                <button type="submit" class="btn btn-success" style="border-radius:8px; font-weight:700;"><i class="fas fa-arrow-rotate-forward"></i> Sync with Meta</button>
            </form>
        </div>
    </div>

    <!-- ── TAB 1: LIST VIEW ── -->
    <div id="panel-list" style="display: block;">
        <!-- Filters Row -->
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:16px; margin-bottom:20px;">
            <form method="GET" style="display:grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap:12px; align-items:end;">
                <div>
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Search Name</label>
                    <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($f_search); ?>" placeholder="e.g. cuet_pg_2027" style="border-radius:8px; font-size:0.8rem;">
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Meta Status</label>
                    <select name="status" class="form-control" style="border-radius:8px; font-size:0.8rem;">
                        <option value="">All Statuses</option>
                        <option value="draft" <?php echo $f_status === 'draft' ? 'selected' : ''; ?>>LOCAL DRAFT</option>
                        <option value="pending" <?php echo $f_status === 'pending' ? 'selected' : ''; ?>>PENDING / SUBMITTED</option>
                        <option value="approved" <?php echo $f_status === 'approved' ? 'selected' : ''; ?>>META APPROVED</option>
                        <option value="rejected" <?php echo $f_status === 'rejected' ? 'selected' : ''; ?>>META REJECTED</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Category</label>
                    <select name="category" class="form-control" style="border-radius:8px; font-size:0.8rem;">
                        <option value="">All Categories</option>
                        <option value="MARKETING" <?php echo $f_category === 'MARKETING' ? 'selected' : ''; ?>>MARKETING</option>
                        <option value="UTILITY" <?php echo $f_category === 'UTILITY' ? 'selected' : ''; ?>>UTILITY</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Language</label>
                    <select name="language" class="form-control" style="border-radius:8px; font-size:0.8rem;">
                        <option value="">All Languages</option>
                        <?php foreach ($supported_languages as $code => $lbl): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $f_language === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary" style="border-radius:8px; padding:10px 16px;"><i class="fas fa-filter"></i> Filter</button>
                    <a href="whatsapp-marketing-templates.php" class="btn btn-outline" style="border-radius:8px; padding:10px 16px;"><i class="fas fa-arrow-rotate-left"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- Templates List Table -->
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden;">
            <table class="data-table" style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                    <tr style="background:#f8fafc; text-align:left; border-bottom:1px solid #e2e8f0;">
                        <th style="padding:14px; font-weight:700; color:#475569;">Template Name</th>
                        <th style="padding:14px; font-weight:700; color:#475569;">Category</th>
                        <th style="padding:14px; font-weight:700; color:#475569;">Language</th>
                        <th style="padding:14px; font-weight:700; color:#475569;">Header Format</th>
                        <th style="padding:14px; font-weight:700; color:#475569;">Local &amp; Meta Status</th>
                        <th style="padding:14px; font-weight:700; color:#475569;">Quality Score</th>
                        <th style="padding:14px; font-weight:700; color:#475569;">Rejection Reason</th>
                        <th style="padding:14px; font-weight:700; color:#475569;">Last Updated</th>
                        <th style="padding:14px; font-weight:700; color:#475569; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($marketingTemplates)): ?>
                        <tr>
                            <td colspan="9" style="padding:40px; text-align:center; color:#94a3b8;"><i class="fas fa-layer-group" style="font-size:2rem; display:block; margin-bottom:8px; opacity:0.4;"></i> No marketing templates found. Click "Create Template" to get started.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($marketingTemplates as $tpl): ?>
                            <?php 
                                $meta = json_decode($tpl['meta_data'], true) ?: [];
                                $bodyText = $meta['body_text'] ?? '';
                                $headerType = $meta['header_type'] ?? 'NONE';
                                $qStatus = $tpl['quality_status'] ?? 'unknown';
                                
                                $statusBadge = 'gray';
                                $statusLabel = strtoupper($tpl['status']);
                                if ($tpl['status'] === 'draft') {
                                    $statusBadge = 'gray';
                                    $statusLabel = 'LOCAL DRAFT';
                                } elseif ($tpl['status'] === 'pending') {
                                    $statusBadge = 'orange';
                                    $statusLabel = 'SUBMITTED / PENDING';
                                } elseif ($tpl['status'] === 'approved') {
                                    $statusBadge = 'green';
                                    $statusLabel = 'META APPROVED';
                                } elseif ($tpl['status'] === 'rejected') {
                                    $statusBadge = 'red';
                                    $statusLabel = 'META REJECTED';
                                }
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:14px; font-weight:700; color:#1e293b;"><?php echo htmlspecialchars($tpl['template_name']); ?></td>
                                <td style="padding:14px;"><span class="badge gray" style="font-size:0.7rem; font-weight:700;"><?php echo strtoupper($tpl['category']); ?></span></td>
                                <td style="padding:14px; font-weight:600;"><?php echo htmlspecialchars($tpl['language']); ?></td>
                                <td style="padding:14px;"><span class="badge blue" style="font-size:0.7rem; font-weight:700;"><?php echo $headerType; ?></span></td>
                                <td style="padding:14px;">
                                    <span class="badge <?php echo $statusBadge; ?>" style="font-size:0.7rem; font-weight:700;">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </td>
                                <td style="padding:14px; text-transform:uppercase; font-weight:700; font-size:0.75rem; color:#4b5563;"><?php echo $qStatus; ?></td>
                                <td style="padding:14px; color:#ef4444; font-size:0.75rem; max-width:180px; word-break:break-all;"><?php echo htmlspecialchars($tpl['rejection_reason'] ?? '-'); ?></td>
                                <td style="padding:14px; color:#64748b; font-size:0.75rem;"><?php echo $tpl['updated_at']; ?></td>
                                <td style="padding:14px; text-align:right;">
                                    <div style="display:inline-flex; gap:8px;">
                                        <button class="btn btn-sm btn-outline" onclick="openVisualPreview(<?php echo htmlspecialchars(json_encode($tpl['template_name'])); ?>, <?php echo htmlspecialchars(json_encode($meta)); ?>)" style="padding:4px 8px; border-radius:6px; font-size:0.75rem;"><i class="fas fa-eye"></i> Preview</button>
                                        
                                        <form method="POST" onsubmit="return confirm('This will request deletion of the WhatsApp template from Meta. Existing historical communication records will not be deleted. Continue?');" style="display:inline-block;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_template">
                                            <input type="hidden" name="delete_name" value="<?php echo htmlspecialchars($tpl['template_name']); ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" style="padding:4px 8px; border-radius:6px; font-size:0.75rem; background:#fee2e2; border-color:#fecaca; color:#b91c1c;"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── TAB 2: CREATE BUILDER VIEW ── -->
    <div id="panel-create" style="display: none;">
        <div style="display:grid; grid-template-columns: 1.8fr 1.2fr; gap:24px; align-items:start;">
            <!-- Left: Builder Form -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden;">
                <div style="background:#f8fafc; border-bottom:1px solid #e2e8f0; padding:14px 20px;">
                    <h3 style="margin:0; font-size:1rem; font-weight:700; color:#1e293b;"><i class="fas fa-wand-magic-sparkles" style="color:#8b5cf6; margin-right:4px;"></i> Marketing Template Builder</h3>
                </div>
                
                <div style="padding:20px;">
                    <form method="POST" id="template-builder-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" id="builder-action" value="save_template">
                        
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:6px;">Template Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="template_name" id="inp-tpl-name" class="form-control" placeholder="e.g. welcome_offer_march" oninput="validateTemplateName(this.value); updatePreview();" required>
                            <span style="font-size:0.75rem; color:#94a3b8; display:block; margin-top:4px;">Must contain only lowercase letters, numbers, and underscores. No spaces.</span>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                            <div>
                                <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:6px;">Category</label>
                                <select name="category" class="form-control">
                                    <option value="MARKETING">MARKETING (Offers, Promotions, Campaign messages)</option>
                                    <option value="UTILITY">UTILITY (Receipts, Onboarding, Reminders)</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:6px;">Language</label>
                                <select name="language" class="form-control">
                                    <?php foreach ($supported_languages as $code => $lbl): ?>
                                        <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($lbl); ?> (<?php echo $code; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Header Block -->
                        <div style="border-top:1px dashed #e2e8f0; margin-top:20px; padding-top:14px; margin-bottom:16px;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#1e293b; margin-bottom:6px;"><i class="fas fa-heading"></i> Template Header</label>
                            <select name="header_type" id="sel-header-type" class="form-control" style="margin-bottom:10px;" onchange="onHeaderTypeChange(this.value)">
                                <option value="NONE">- No Header -</option>
                                <option value="TEXT">Text Header</option>
                                <option value="IMAGE">Image Header (JPEG/PNG)</option>
                                <option value="VIDEO">Video Header (MP4)</option>
                                <option value="DOCUMENT">Document Header (PDF)</option>
                            </select>
                            
                            <div id="header-text-container" style="display:none; margin-bottom:10px;">
                                <input type="text" name="header_text" id="inp-header-text" class="form-control" placeholder="Enter header text... (Supports {{1}})" oninput="detectVariables(); updatePreview();">
                            </div>
                        </div>

                        <!-- Body Block -->
                        <div style="border-top:1px dashed #e2e8f0; margin-top:20px; padding-top:14px; margin-bottom:16px;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#1e293b; margin-bottom:6px;"><i class="fas fa-align-justify"></i> Message Body <span style="color:#ef4444;">*</span></label>
                            <textarea name="body_text" id="txt-body-text" class="form-control" rows="5" placeholder="Type your marketing message here. Use {{1}}, {{2}} for dynamic parameters." oninput="detectVariables(); updatePreview();" required></textarea>
                        </div>

                        <!-- Variables Dynamic Mapping Config -->
                        <div id="variables-config-panel" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:16px;">
                            <h4 style="margin:0 0 10px; font-size:0.8rem; font-weight:700; color:#475569;"><i class="fas fa-brackets-curly" style="color:#6366f1;"></i> Meta API Requirements: Variable Sample Values</h4>
                            <div id="variables-mapping-inputs" style="display:flex; flex-direction:column; gap:10px;"></div>
                        </div>

                        <!-- Footer Block -->
                        <div style="border-top:1px dashed #e2e8f0; margin-top:20px; padding-top:14px; margin-bottom:16px;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#1e293b; margin-bottom:6px;"><i class="fas fa-paragraph"></i> Template Footer (Optional)</label>
                            <input type="text" name="footer_text" id="inp-footer-text" class="form-control" placeholder="e.g. Reply STOP to opt out" oninput="updatePreview();">
                        </div>

                        <!-- Buttons Block -->
                        <div style="border-top:1px dashed #e2e8f0; margin-top:20px; padding-top:14px; margin-bottom:20px;">
                            <label style="display:block; font-size:0.8rem; font-weight:700; color:#1e293b; margin-bottom:6px;"><i class="fas fa-square-caret-right"></i> Dynamic Template Buttons</label>
                            <select name="button_type" id="sel-button-type" class="form-control" style="margin-bottom:12px;" onchange="onButtonTypeChange(this.value)">
                                <option value="NONE">- No Buttons -</option>
                                <option value="QUICK_REPLY">Quick Reply Buttons (Max 3)</option>
                                <option value="CTA">Call to Action (CTA) Buttons (1 Url + 1 Phone)</option>
                            </select>

                            <!-- Quick Reply Section -->
                            <div id="buttons-quickreply-container" style="display:none; flex-direction:column; gap:10px; margin-bottom:10px;">
                                <input type="text" name="buttons[quick_reply][1]" class="form-control btn-qr-text" placeholder="Quick Reply Button 1 text (e.g. Enquire Now)" style="border-radius:8px;" oninput="updatePreview()">
                                <input type="text" name="buttons[quick_reply][2]" class="form-control btn-qr-text" placeholder="Quick Reply Button 2 text (Optional)" style="border-radius:8px;" oninput="updatePreview()">
                                <input type="text" name="buttons[quick_reply][3]" class="form-control btn-qr-text" placeholder="Quick Reply Button 3 text (Optional)" style="border-radius:8px;" oninput="updatePreview()">
                            </div>

                            <!-- CTA Buttons Section -->
                            <div id="buttons-cta-container" style="display:none; flex-direction:column; gap:14px; margin-bottom:10px; border:1px solid #f1f5f9; padding:12px; border-radius:12px; background:#fbfbfb;">
                                <!-- Phone -->
                                <div style="display:grid; grid-template-columns:1.2fr 2fr; gap:10px;">
                                    <input type="text" name="buttons[phone_text]" id="inp-phone-text" class="form-control" placeholder="Phone button label (e.g. Call Us)" style="border-radius:8px;" oninput="updatePreview()">
                                    <input type="text" name="buttons[phone_number]" id="inp-phone-number" class="form-control" placeholder="Phone number (e.g. +917025000444)" style="border-radius:8px;" oninput="updatePreview()">
                                </div>
                                <!-- Website -->
                                <div style="display:grid; grid-template-columns:1.2fr 2fr; gap:10px;">
                                    <input type="text" name="buttons[url_text]" id="inp-url-text" class="form-control" placeholder="Website button label (e.g. Visit Link)" style="border-radius:8px;" oninput="updatePreview()">
                                    <input type="text" name="buttons[url_value]" id="inp-url-value" class="form-control" placeholder="Website URL (e.g. https://pepplearning.in)" style="border-radius:8px;" oninput="updatePreview()">
                                </div>
                            </div>
                        </div>

                        <!-- Submission Controls -->
                        <div style="display:flex; gap:12px; justify-content:flex-end; border-top:1px solid #e2e8f0; padding-top:20px;">
                            <button type="button" onclick="triggerBuilderSubmit('save_template')" class="btn btn-outline" style="border-radius:8px; font-weight:700; padding:10px 20px;"><i class="fas fa-floppy-disk"></i> Save Local Draft</button>
                            <button type="button" onclick="triggerBuilderSubmit('submit_meta')" class="btn btn-primary" style="border-radius:8px; font-weight:700; padding:10px 20px;"><i class="fas fa-rocket"></i> Submit to Meta WABA</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right: WhatsApp Live Preview Bubble -->
            <div style="position:sticky; top:20px;">
                <div style="background:#e5ddd5; border:1px solid #cbd5e1; border-radius:16px; overflow:hidden; box-shadow:0 8px 24px rgba(0,0,0,0.06); font-family:Helvetica, Arial, sans-serif;">
                    <div style="background:#075e54; color:#fff; padding:12px 16px; display:flex; align-items:center; gap:10px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:#ece5dd; color:#128c7e; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.2rem;">P</div>
                        <div>
                            <div style="font-weight:700; font-size:0.9rem;">PEPP Learning</div>
                            <div style="font-size:0.7rem; opacity:0.8;">Meta Official Business API Channel</div>
                        </div>
                    </div>
                    
                    <div style="padding:20px; min-height:300px; display:flex; flex-direction:column; justify-content:flex-start; background-image:url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-repeat:repeat; background-size: auto;">
                        <!-- WhatsApp Message bubble card -->
                        <div style="background:#fff; border-radius:8px 8px 8px 0; max-width:85%; padding:10px; align-self:flex-start; box-shadow:0 1px 2px rgba(0,0,0,0.15); position:relative; width: 100%;">
                            <!-- Header Media preview block -->
                            <div id="preview-header-media" style="display:none; background:#ece5dd; border-radius:6px; height:120px; align-items:center; justify-content:center; font-size:1.8rem; color:#94a3b8; margin-bottom:8px;">
                                <i class="fas fa-image" id="preview-header-media-icon"></i>
                            </div>
                            
                            <!-- Header text preview -->
                            <div id="preview-header-text" style="font-weight:700; font-size:0.85rem; color:#111827; margin-bottom:6px; display:none;"></div>
                            
                            <!-- Body preview -->
                            <div id="preview-body" style="font-size:0.85rem; color:#374151; line-height:1.4; white-space:pre-wrap;">Hello, this is a live visual template preview.</div>
                            
                            <!-- Footer preview -->
                            <div id="preview-footer" style="font-size:0.7rem; color:#94a3b8; margin-top:6px; display:none; border-top:1px dashed #f1f5f9; padding-top:4px;"></div>
                            
                            <!-- Inside-bubble button pills -->
                            <div id="preview-bubble-buttons" style="display:none; flex-direction:column; gap:4px; margin-top:10px; border-top:1px solid #f1f5f9; padding-top:6px;"></div>
                        </div>
                        
                        <!-- CTA button pills (represented outside the card, floating under it) -->
                        <div id="preview-floating-buttons" style="display:none; width:85%; align-self:flex-start; margin-top:6px; flex-direction:column; gap:6px;"></div>
                    </div>
                </div>
                
                <div style="background:#fff; border:1px solid #cbd5e1; border-radius:12px; padding:12px; margin-top:12px; text-align:center;">
                    <span style="font-size:0.75rem; color:#64748b; font-weight:700;"><i class="fas fa-circle-info" style="color:#3b82f6;"></i> Preview values are simulated. Real approval status is processed asynchronously on Meta WABA dashboard.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal container for visual preview -->
<div id="preview-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5); justify-content:center; align-items:center; backdrop-filter:blur(3px);">
    <div style="background-color:#fff; border-radius:16px; max-width:460px; width:90%; padding:20px; box-shadow:0 10px 40px rgba(0,0,0,0.15); position:relative;">
        <span onclick="closePreviewModal()" style="position:absolute; right:15px; top:12px; cursor:pointer; font-size:1.6rem; color:#94a3b8; font-weight:700;">&times;</span>
        <h4 id="modal-title" style="margin-top:0; margin-bottom:15px; font-weight:700; color:#1e293b; font-size:1.05rem;">Template Inspection</h4>
        
        <!-- Mock Phone wrapper inside modal -->
        <div style="background:#e5ddd5; border:1px solid #cbd5e1; border-radius:12px; overflow:hidden; font-family:sans-serif; background-image:url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-repeat:repeat; padding:16px; min-height:220px; display:flex; flex-direction:column; justify-content:center;">
            <div style="background:#fff; border-radius:8px 8px 8px 0; max-width:90%; padding:10px; align-self:flex-start; box-shadow:0 1px 2px rgba(0,0,0,0.15); width:100%;">
                <div id="modal-header-media" style="display:none; background:#ece5dd; border-radius:6px; height:100px; align-items:center; justify-content:center; font-size:1.6rem; color:#94a3b8; margin-bottom:8px;">
                    <i class="fas fa-image" id="modal-header-media-icon"></i>
                </div>
                <div id="modal-header-text" style="font-weight:700; font-size:0.85rem; color:#111827; margin-bottom:6px; display:none;"></div>
                <div id="modal-body" style="font-size:0.85rem; color:#374151; line-height:1.4; white-space:pre-wrap;"></div>
                <div id="modal-footer" style="font-size:0.7rem; color:#94a3b8; margin-top:6px; display:none; border-top:1px dashed #f1f5f9; padding-top:4px;"></div>
                <div id="modal-bubble-buttons" style="display:none; flex-direction:column; gap:4px; margin-top:10px; border-top:1px solid #f1f5f9; padding-top:6px;"></div>
            </div>
            <div id="modal-floating-buttons" style="display:none; width:90%; align-self:flex-start; margin-top:6px; flex-direction:column; gap:6px;"></div>
        </div>
        
        <div style="text-align:right; margin-top:15px;">
            <button type="button" class="btn btn-outline" onclick="closePreviewModal()" style="border-radius:8px;">Close Inspector</button>
        </div>
    </div>
</div>

<script>
let currentView = 'list';

function toggleView(tabName) {
    currentView = tabName;
    if (tabName === 'list') {
        document.getElementById('panel-list').style.display = 'block';
        document.getElementById('panel-create').style.display = 'none';
        document.getElementById('btn-tab-list').classList.add('btn-primary');
        document.getElementById('btn-tab-list').classList.remove('btn-outline');
        document.getElementById('btn-tab-create').classList.add('btn-outline');
        document.getElementById('btn-tab-create').classList.remove('btn-primary');
    } else {
        document.getElementById('panel-list').style.display = 'none';
        document.getElementById('panel-create').style.display = 'block';
        document.getElementById('btn-tab-list').classList.add('btn-outline');
        document.getElementById('btn-tab-list').classList.remove('btn-primary');
        document.getElementById('btn-tab-create').classList.add('btn-primary');
        document.getElementById('btn-tab-create').classList.remove('btn-outline');
        
        // Trigger initial preview updates
        detectVariables();
        updatePreview();
    }
}

function triggerBuilderSubmit(actionVal) {
    const form = document.getElementById('template-builder-form');
    document.getElementById('builder-action').value = actionVal;
    
    // Verify variable mapping inputs are filled out before submitting
    const errorMsg = validateBuilderInputs();
    if (errorMsg) {
        alert(errorMsg);
        return;
    }
    
    if (form.reportValidity()) {
        form.submit();
    }
}

function validateTemplateName(val) {
    const input = document.getElementById('inp-tpl-name');
    const regex = /^[a-z0-9_]+$/;
    if (val && !regex.test(val)) {
        input.setCustomValidity('Name must be lowercase, alphanumeric, and contain only underscores (no spaces).');
    } else {
        input.setCustomValidity('');
    }
}

function onHeaderTypeChange(type) {
    const txtContainer = document.getElementById('header-text-container');
    if (type === 'TEXT') {
        txtContainer.style.display = 'block';
        document.getElementById('inp-header-text').required = true;
    } else {
        txtContainer.style.display = 'none';
        document.getElementById('inp-header-text').required = false;
        document.getElementById('inp-header-text').value = '';
    }
    detectVariables();
    updatePreview();
}

function onButtonTypeChange(type) {
    const qrContainer = document.getElementById('buttons-quickreply-container');
    const ctaContainer = document.getElementById('buttons-cta-container');
    
    if (type === 'QUICK_REPLY') {
        qrContainer.style.display = 'flex';
        ctaContainer.style.display = 'none';
    } else if (type === 'CTA') {
        qrContainer.style.display = 'none';
        ctaContainer.style.display = 'flex';
    } else {
        qrContainer.style.display = 'none';
        ctaContainer.style.display = 'none';
    }
    updatePreview();
}

function detectVariables() {
    const bodyText = document.getElementById('txt-body-text').value;
    const headerText = document.getElementById('inp-header-text').value;
    const headerType = document.getElementById('sel-header-type').value;
    
    const panel = document.getElementById('variables-config-panel');
    const container = document.getElementById('variables-mapping-inputs');
    container.innerHTML = '';
    
    // Extract unique variables
    let bodyVars = [];
    const bodyRegex = /\{\{(\d+)\}\}/g;
    let match;
    while ((match = bodyRegex.exec(bodyText)) !== null) {
        bodyVars.push(parseInt(match[1]));
    }
    bodyVars = [...new Set(bodyVars)].sort((a,b) => a - b);
    
    let hasHeaderVar = false;
    if (headerType === 'TEXT' && headerText.includes('{{1}}')) {
        hasHeaderVar = true;
    }
    
    if (bodyVars.length > 0 || hasHeaderVar) {
        panel.style.display = 'block';
        
        if (hasHeaderVar) {
            const div = document.createElement('div');
            div.style.display = 'grid';
            div.style.gridTemplateColumns = '1.2fr 2fr';
            div.style.gap = '10px';
            div.style.alignItems = 'center';
            div.innerHTML = `
                <span style="font-size:0.75rem; font-weight:700; color:#4b5563;">Header Var {{1}} Example Value:</span>
                <input type="text" name="examples[header]" class="form-control var-example-input" data-var="header" placeholder="e.g. Adnan" style="border-radius:8px; font-size:0.8rem;" oninput="updatePreview()" required>
            `;
            container.appendChild(div);
        }
        
        bodyVars.forEach(vNum => {
            const div = document.createElement('div');
            div.style.display = 'grid';
            div.style.gridTemplateColumns = '1.2fr 2fr';
            div.style.gap = '10px';
            div.style.alignItems = 'center';
            div.innerHTML = `
                <span style="font-size:0.75rem; font-weight:700; color:#4b5563;">Body Var {{${vNum}}} Example Value:</span>
                <input type="text" name="examples[${vNum}]" class="form-control var-example-input" data-var="${vNum}" placeholder="e.g. Student Name" style="border-radius:8px; font-size:0.8rem;" oninput="updatePreview()" required>
            `;
            container.appendChild(div);
        });
    } else {
        panel.style.display = 'none';
    }
}

function validateBuilderInputs() {
    const buttonType = document.getElementById('sel-button-type').value;
    if (buttonType === 'QUICK_REPLY') {
        const qrInputs = document.querySelectorAll('.btn-qr-text');
        let filled = 0;
        qrInputs.forEach(i => {
            if (i.value.trim()) filled++;
        });
        if (filled === 0) {
            return 'Please provide at least 1 Quick Reply button text.';
        }
    } else if (buttonType === 'CTA') {
        const phoneTxt = document.getElementById('inp-phone-text').value.trim();
        const phoneNum = document.getElementById('inp-phone-number').value.trim();
        const urlTxt = document.getElementById('inp-url-text').value.trim();
        const urlVal = document.getElementById('inp-url-value').value.trim();
        
        if (!phoneTxt && !phoneNum && !urlTxt && !urlVal) {
            return 'Please specify at least one Call to Action button (Phone or Website).';
        }
        if ((phoneTxt && !phoneNum) || (!phoneTxt && phoneNum)) {
            return 'Please specify both Phone Button text and Phone Number.';
        }
        if ((urlTxt && !urlVal) || (!urlTxt && urlVal)) {
            return 'Please specify both Website Button text and URL.';
        }
    }
    return '';
}

function updatePreview() {
    // 1. Header Media
    const headerType = document.getElementById('sel-header-type').value;
    const mediaBlock = document.getElementById('preview-header-media');
    const mediaIcon = document.getElementById('preview-header-media-icon');
    
    if (headerType !== 'NONE' && headerType !== 'TEXT') {
        mediaBlock.style.display = 'flex';
        if (headerType === 'IMAGE') mediaIcon.className = 'fas fa-image';
        else if (headerType === 'VIDEO') mediaIcon.className = 'fas fa-video';
        else if (headerType === 'DOCUMENT') mediaIcon.className = 'fas fa-file-pdf';
    } else {
        mediaBlock.style.display = 'none';
    }
    
    // 2. Header Text
    const headerTextVal = document.getElementById('inp-header-text').value;
    const headerPreview = document.getElementById('preview-header-text');
    if (headerType === 'TEXT' && headerTextVal.trim()) {
        headerPreview.style.display = 'block';
        // Interpolate header var {{1}}
        let compiledHeader = headerTextVal;
        const hdrExampleInput = document.querySelector('.var-example-input[data-var="header"]');
        const exampleVal = hdrExampleInput ? hdrExampleInput.value : '{{1}}';
        compiledHeader = compiledHeader.replace('{{1}}', exampleVal || '{{1}}');
        headerPreview.innerText = compiledHeader;
    } else {
        headerPreview.style.display = 'none';
    }
    
    // 3. Body Text
    let bodyTextVal = document.getElementById('txt-body-text').value;
    const bodyPreview = document.getElementById('preview-body');
    if (bodyTextVal.trim()) {
        // Interpolate body variables with example inputs dynamically
        const varInputs = document.querySelectorAll('.var-example-input:not([data-var="header"])');
        varInputs.forEach(inp => {
            const vNum = inp.getAttribute('data-var');
            const val = inp.value || `{{${vNum}}}`;
            bodyTextVal = bodyTextVal.split(`{{${vNum}}}`).join(val);
        });
        bodyPreview.innerText = bodyTextVal;
    } else {
        bodyPreview.innerText = 'Hello, this is a live visual template preview.';
    }
    
    // 4. Footer Text
    const footerTextVal = document.getElementById('inp-footer-text').value;
    const footerPreview = document.getElementById('preview-footer');
    if (footerTextVal.trim()) {
        footerPreview.style.display = 'block';
        footerPreview.innerText = footerTextVal;
    } else {
        footerPreview.style.display = 'none';
    }
    
    // 5. Buttons
    const buttonType = document.getElementById('sel-button-type').value;
    const bubbleBtnContainer = document.getElementById('preview-bubble-buttons');
    const floatBtnContainer = document.getElementById('preview-floating-buttons');
    bubbleBtnContainer.innerHTML = '';
    floatBtnContainer.innerHTML = '';
    
    if (buttonType === 'QUICK_REPLY') {
        bubbleBtnContainer.style.display = 'flex';
        floatBtnContainer.style.display = 'none';
        
        const qrInputs = document.querySelectorAll('.btn-qr-text');
        qrInputs.forEach(inp => {
            const txt = inp.value.trim();
            if (txt) {
                const btn = document.createElement('div');
                btn.style.background = '#f8fafc';
                btn.style.color = '#3b82f6';
                btn.style.padding = '8px';
                btn.style.textAlign = 'center';
                btn.style.borderRadius = '6px';
                btn.style.fontSize = '0.8rem';
                btn.style.fontWeight = '700';
                btn.style.border = '1px solid #e2e8f0';
                btn.style.cursor = 'default';
                btn.innerText = txt;
                bubbleBtnContainer.appendChild(btn);
            }
        });
    } else if (buttonType === 'CTA') {
        bubbleBtnContainer.style.display = 'none';
        floatBtnContainer.style.display = 'flex';
        
        const phoneTxt = document.getElementById('inp-phone-text').value.trim();
        const phoneNum = document.getElementById('inp-phone-number').value.trim();
        const urlTxt = document.getElementById('inp-url-text').value.trim();
        const urlVal = document.getElementById('inp-url-value').value.trim();
        
        if (phoneTxt && phoneNum) {
            const btn = document.createElement('div');
            btn.style.background = '#fff';
            btn.style.color = '#00a884';
            btn.style.padding = '10px';
            btn.style.textAlign = 'center';
            btn.style.borderRadius = '8px';
            btn.style.fontSize = '0.8rem';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
            btn.innerHTML = `<i class="fas fa-phone" style="margin-right:4px;"></i> ${phoneTxt}`;
            floatBtnContainer.appendChild(btn);
        }
        if (urlTxt && urlVal) {
            const btn = document.createElement('div');
            btn.style.background = '#fff';
            btn.style.color = '#00a884';
            btn.style.padding = '10px';
            btn.style.textAlign = 'center';
            btn.style.borderRadius = '8px';
            btn.style.fontSize = '0.8rem';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
            btn.innerHTML = `<i class="fas fa-arrow-up-right-from-square" style="margin-right:4px;"></i> ${urlTxt}`;
            floatBtnContainer.appendChild(btn);
        }
    } else {
        bubbleBtnContainer.style.display = 'none';
        floatBtnContainer.style.display = 'none';
    }
}

function openVisualPreview(tplName, meta) {
    document.getElementById('modal-title').innerText = "Inspector: " + tplName;
    
    // Header Media
    const headerType = meta.header_type || 'NONE';
    const mediaBlock = document.getElementById('modal-header-media');
    const mediaIcon = document.getElementById('modal-header-media-icon');
    if (headerType !== 'NONE' && headerType !== 'TEXT') {
        mediaBlock.style.display = 'flex';
        if (headerType === 'IMAGE') mediaIcon.className = 'fas fa-image';
        else if (headerType === 'VIDEO') mediaIcon.className = 'fas fa-video';
        else if (headerType === 'DOCUMENT') mediaIcon.className = 'fas fa-file-pdf';
    } else {
        mediaBlock.style.display = 'none';
    }
    
    // Header Text
    const headerText = meta.header_text || '';
    const headerPreview = document.getElementById('modal-header-text');
    if (headerType === 'TEXT' && headerText) {
        headerPreview.style.display = 'block';
        headerPreview.innerText = headerText;
    } else {
        headerPreview.style.display = 'none';
    }
    
    // Body Text
    document.getElementById('modal-body').innerText = meta.body_text || '';
    
    // Footer Text
    const footerText = meta.footer_text || '';
    const footerPreview = document.getElementById('modal-footer');
    if (footerText) {
        footerPreview.style.display = 'block';
        footerPreview.innerText = footerText;
    } else {
        footerPreview.style.display = 'none';
    }
    
    // Buttons
    const buttonType = meta.button_type || 'NONE';
    const bubbleBtnContainer = document.getElementById('modal-bubble-buttons');
    const floatBtnContainer = document.getElementById('modal-floating-buttons');
    bubbleBtnContainer.innerHTML = '';
    floatBtnContainer.innerHTML = '';
    
    if (buttonType === 'QUICK_REPLY' && meta.buttons && meta.buttons.quick_reply) {
        bubbleBtnContainer.style.display = 'flex';
        floatBtnContainer.style.display = 'none';
        Object.values(meta.buttons.quick_reply).forEach(txt => {
            if (txt && txt.trim()) {
                const btn = document.createElement('div');
                btn.style.background = '#f8fafc';
                btn.style.color = '#3b82f6';
                btn.style.padding = '8px';
                btn.style.textAlign = 'center';
                btn.style.borderRadius = '6px';
                btn.style.fontSize = '0.8rem';
                btn.style.fontWeight = '700';
                btn.style.border = '1px solid #e2e8f0';
                btn.innerText = txt;
                bubbleBtnContainer.appendChild(btn);
            }
        });
    } else if (buttonType === 'CTA' && meta.buttons) {
        bubbleBtnContainer.style.display = 'none';
        floatBtnContainer.style.display = 'flex';
        
        const phoneTxt = meta.buttons.phone_text;
        const phoneNum = meta.buttons.phone_number;
        const urlTxt = meta.buttons.url_text;
        const urlVal = meta.buttons.url_value;
        
        if (phoneTxt && phoneNum) {
            const btn = document.createElement('div');
            btn.style.background = '#fff';
            btn.style.color = '#00a884';
            btn.style.padding = '10px';
            btn.style.textAlign = 'center';
            btn.style.borderRadius = '8px';
            btn.style.fontSize = '0.8rem';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
            btn.innerHTML = `<i class="fas fa-phone" style="margin-right:4px;"></i> ${phoneTxt}`;
            floatBtnContainer.appendChild(btn);
        }
        if (urlTxt && urlVal) {
            const btn = document.createElement('div');
            btn.style.background = '#fff';
            btn.style.color = '#00a884';
            btn.style.padding = '10px';
            btn.style.textAlign = 'center';
            btn.style.borderRadius = '8px';
            btn.style.fontSize = '0.8rem';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
            btn.innerHTML = `<i class="fas fa-arrow-up-right-from-square" style="margin-right:4px;"></i> ${urlTxt}`;
            floatBtnContainer.appendChild(btn);
        }
    } else {
        bubbleBtnContainer.style.display = 'none';
        floatBtnContainer.style.display = 'none';
    }
    
    document.getElementById('preview-modal').style.display = 'flex';
}

function closePreviewModal() {
    document.getElementById('preview-modal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('preview-modal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
