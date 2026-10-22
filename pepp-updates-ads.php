<?php
/**
 * PEPP Updates - Popup Ad Management
 *
 * Create, edit, manage, and configure lead generation popup ads:
 * - Configurable active visible-page timer delay (seconds)
 * - Canonical course mapping to pepp_courses
 * - Update-post targeting (all posts vs selected posts)
 * - Ordered custom form field definitions
 * - Per-ad automatic CRM conversion control
 * - Life-cycle status management (draft, active, paused, archived)
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates - Popup Ads';
$page_sub = 'Manage modal lead generation ads, display rules, and custom form fields';
$active_page = 'pepp-updates-ads';

$action = $_GET['action'] ?? 'list';
$flash_success = '';
$flash_error = '';

$admin_username = $_SESSION['admin_user'] ?? 'admin';

// ─────────────────────────────────────────────────────────────
// POST ACTIONS (Save / Update Ad)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ad'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF).';
    } else {
        $adId = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $headline = trim($_POST['headline'] ?? '');
        $subheadline = trim($_POST['subheadline'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');
        $buttonText = trim($_POST['button_text'] ?? 'Get More Info');
        $targetAll = isset($_POST['target_all_posts']) && (int)$_POST['target_all_posts'] === 1 ? 1 : 0;
        $popupDelay = max(1, min(600, (int)($_POST['popup_delay_seconds'] ?? 15)));
        $autoConvert = isset($_POST['auto_convert_crm']) && (int)$_POST['auto_convert_crm'] === 1 ? 1 : 0;
        $targetCourseId = !empty($_POST['target_course_id']) ? (int)$_POST['target_course_id'] : null;
        $statusRaw = $_POST['status'] ?? 'draft';
        $status = in_array($statusRaw, ['draft', 'active', 'paused', 'archived'], true) ? $statusRaw : 'draft';
        $displayOrder = (int)($_POST['display_order'] ?? 0);

        $selectedPosts = array_map('intval', $_POST['target_posts'] ?? []);

        // Parse custom fields (row-keyed for index integrity)
        $fields = [];
        $submittedFields = $_POST['fields'] ?? [];
        if (is_array($submittedFields)) {
            foreach ($submittedFields as $row) {
                if (!is_array($row)) continue;
                $label = trim($row['label'] ?? '');
                if ($label === '') continue;

                $name = trim($row['name'] ?? '');
                $type = trim($row['type'] ?? 'text');
                $req  = !empty($row['required']) ? 1 : 0;
                $opts = trim($row['options'] ?? '');

                $fields[] = [
                    'field_label'   => $label,
                    'field_name'    => $name,
                    'field_type'    => $type,
                    'is_required'   => $req,
                    'field_options' => $opts,
                    'sort_order'    => count($fields)
                ];
            }
        }

        $adPayload = [
            'id'                  => $adId,
            'title'               => $title,
            'slug'                => $slug,
            'headline'            => $headline,
            'subheadline'         => $subheadline,
            'image_url'           => $imageUrl,
            'button_text'         => $buttonText,
            'target_all_posts'    => $targetAll,
            'popup_delay_seconds' => $popupDelay,
            'auto_convert_crm'    => $autoConvert,
            'target_course_id'    => $targetCourseId,
            'status'              => $status,
            'display_order'       => $displayOrder
        ];

        $saveResult = pepp_updates_save_ad($pdo, $adPayload, $selectedPosts, $fields, $admin_username);
        if ($saveResult['success']) {
            $flash_success = $adId ? 'Ad updated successfully.' : 'New Ad created successfully.';
            $action = 'list';
        } else {
            $flash_error = $saveResult['error'] ?? 'Failed to save ad.';
        }
    }
}

// ─────────────────────────────────────────────────────────────
// POST AD ACTIONS (Activate, Pause, Archive, Delete)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ad_action'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF).';
        $action = 'list';
    } else {
        $targetId = (int)($_POST['id'] ?? 0);
        $adAction = trim($_POST['ad_action']);
        if ($adAction === 'delete') {
            $delResult = pepp_updates_delete_ad($pdo, $targetId);
            if ($delResult['success']) {
                $flash_success = 'Ad deleted successfully.';
            } else {
                $flash_error = $delResult['error'] ?? 'Failed to delete ad.';
            }
        } elseif (in_array($adAction, ['activate', 'pause', 'archive'], true)) {
            $newStatus = ($adAction === 'activate') ? 'active' : (($adAction === 'pause') ? 'paused' : 'archived');
            if (pepp_updates_toggle_ad_status($pdo, $targetId, $newStatus, $admin_username)) {
                $flash_success = "Ad status changed to '{$newStatus}'.";
            } else {
                $flash_error = 'Failed to update ad status.';
            }
        }
        $action = 'list';
    }
}

// Data prerequisites
$courses = pepp_updates_get_courses($pdo);
$publishedPosts = pepp_updates_get_published_posts($pdo);
$isGlobalAutoConvertOn = (string)pepp_updates_get_setting($pdo, 'ad_leads_auto_convert_enabled', '0') === '1';

// Edit data
$editAd = null;
if (($action === 'edit' || $action === 'create') && !empty($_GET['id'])) {
    $editAd = pepp_updates_get_ad($pdo, (int)$_GET['id']);
    if (!$editAd && $action === 'edit') {
        $flash_error = 'Ad not found.';
        $action = 'list';
    }
}

include 'includes/admin_nav.php';
?>

<div class="pepp-container" style="padding: 20px 24px; max-width: 1400px; margin: 0 auto;">

    <!-- Flash Messages -->
    <?php if ($flash_success): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 12px 16px; border-radius: 8px;">
            <i class="fas fa-circle-check"></i>
            <span><?php echo htmlspecialchars($flash_success); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($flash_error): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 12px 16px; border-radius: 8px;">
            <i class="fas fa-circle-exclamation"></i>
            <span><?php echo htmlspecialchars($flash_error); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($action === 'create' || $action === 'edit'): ?>
        <!-- ======================================================== -->
        <!-- CREATE / EDIT AD FORM                                   -->
        <!-- ======================================================== -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <a href="pepp-updates-ads.php" style="color: var(--secondary); text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                    <i class="fas fa-arrow-left"></i> Back to all ads
                </a>
                <h2 style="font-size: 1.4rem; font-weight: 800; color: var(--foreground); margin: 0;">
                    <?php echo $editAd ? 'Edit Popup Ad' : 'Create New Popup Ad'; ?>
                </h2>
            </div>
            <div style="font-size: 0.85rem; color: var(--secondary);">
                Global Auto-Convert:
                <span class="badge <?php echo $isGlobalAutoConvertOn ? 'green' : 'gray'; ?>">
                    <?php echo $isGlobalAutoConvertOn ? 'ON' : 'OFF'; ?>
                </span>
            </div>
        </div>

        <form method="POST" action="pepp-updates-ads.php" style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            <?php echo csrf_field(); ?>
            <?php if ($editAd): ?>
                <input type="hidden" name="id" value="<?php echo (int)$editAd['id']; ?>">
            <?php endif; ?>

            <!-- Left: Main Ad Content & Custom Fields -->
            <div style="display: flex; flex-direction: column; gap: 20px;">

                <!-- Core Ad Details -->
                <div class="panel" style="background: var(--card-bg, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 20px;">
                    <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; color: var(--foreground); border-bottom: 1px solid var(--border); padding-bottom: 10px;">
                        <i class="fas fa-bullhorn" style="color: var(--primary);"></i> Ad Creative &amp; Content
                    </h3>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; display: block; margin-bottom: 6px;">Ad Title (Internal Reference) <span style="color:red;">*</span></label>
                        <input type="text" name="title" required value="<?php echo htmlspecialchars($editAd['title'] ?? ''); ?>" placeholder="e.g. CUET PG Psychology Crash Course 2027" style="width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px;">
                        <div class="help" style="font-size: 0.8rem; color: var(--secondary); margin-top: 4px;">Internal identifier displayed in reporting and admin lists.</div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                        <div>
                            <label style="font-weight: 600; display: block; margin-bottom: 6px;">URL / Tracking Slug</label>
                            <input type="text" name="slug" value="<?php echo htmlspecialchars($editAd['slug'] ?? ''); ?>" placeholder="auto-generated-if-empty" style="width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px;">
                        </div>
                        <div>
                            <label style="font-weight: 600; display: block; margin-bottom: 6px;">Submit Button CTA Label <span style="color:red;">*</span></label>
                            <input type="text" name="button_text" required value="<?php echo htmlspecialchars($editAd['button_text'] ?? 'Get More Info'); ?>" placeholder="e.g. Get Free Syllabus &amp; Guidance" style="width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px;">
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; display: block; margin-bottom: 6px;">Public Modal Headline</label>
                        <input type="text" name="headline" value="<?php echo htmlspecialchars($editAd['headline'] ?? ''); ?>" placeholder="e.g. Fast-Track Your CUET PG 2027 Preparation!" style="width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; display: block; margin-bottom: 6px;">Public Subheadline / Description</label>
                        <textarea name="subheadline" rows="3" placeholder="Brief bullet points or value proposition shown to students..." style="width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit;"><?php echo htmlspecialchars($editAd['subheadline'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label style="font-weight: 600; display: block; margin-bottom: 6px;">Promotional Banner Image URL (Optional)</label>
                        <input type="url" name="image_url" value="<?php echo htmlspecialchars($editAd['image_url'] ?? ''); ?>" placeholder="https://updates.pepplearning.in/assets/... or relative path" style="width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px;">
                    </div>
                </div>

                <!-- Custom Form Fields Builder -->
                <div class="panel" style="background: var(--card-bg, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">
                        <div>
                            <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--foreground); margin: 0;">
                                <i class="fas fa-list-check" style="color: var(--primary);"></i> Popup Form Fields
                            </h3>
                            <div style="font-size: 0.8rem; color: var(--secondary); margin-top: 2px;">
                                Name and Phone are standard and always required. You can add extra custom inputs below.
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline" onclick="addCustomFieldRow()">
                            <i class="fas fa-plus"></i> Add Field
                        </button>
                    </div>

                    <!-- Standard Read-only indicators -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; font-size: 0.86rem;">
                            <span style="color: var(--secondary);">Standard Field:</span> <strong>Full Name</strong>
                            <span class="badge blue" style="font-size: 0.72rem; margin-left: 6px;">Text &bull; Required</span>
                        </div>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; font-size: 0.86rem;">
                            <span style="color: var(--secondary);">Standard Field:</span> <strong>WhatsApp Phone Number</strong>
                            <span class="badge blue" style="font-size: 0.72rem; margin-left: 6px;">Tel &bull; Required</span>
                        </div>
                    </div>

                    <!-- Dynamic Custom Fields List -->
                    <div id="customFieldsContainer" style="display: flex; flex-direction: column; gap: 12px;">
                        <?php
                        $existingFields = $editAd['fields'] ?? [];
                        if (!empty($existingFields)):
                            foreach ($existingFields as $fIdx => $fld):
                                $rowKey = 'row_' . $fIdx;
                        ?>
                            <div class="custom-field-row" style="background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 12px; display: grid; grid-template-columns: 2fr 1.5fr 1fr auto auto; gap: 10px; align-items: center;">
                                <div>
                                    <input type="text" name="fields[<?php echo $rowKey; ?>][label]" required value="<?php echo htmlspecialchars($fld['field_label']); ?>" placeholder="Field Label (e.g. Qualification)" style="width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 5px; font-size: 0.85rem;">
                                    <input type="hidden" name="fields[<?php echo $rowKey; ?>][name]" value="<?php echo htmlspecialchars($fld['field_name']); ?>">
                                </div>
                                <div>
                                    <select name="fields[<?php echo $rowKey; ?>][type]" style="width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 5px; font-size: 0.85rem;" onchange="toggleOptionsInput(this)">
                                        <option value="text" <?php echo ($fld['field_type'] === 'text') ? 'selected' : ''; ?>>Text Input</option>
                                        <option value="select" <?php echo ($fld['field_type'] === 'select') ? 'selected' : ''; ?>>Select Dropdown</option>
                                        <option value="tel" <?php echo ($fld['field_type'] === 'tel') ? 'selected' : ''; ?>>Telephone</option>
                                        <option value="email" <?php echo ($fld['field_type'] === 'email') ? 'selected' : ''; ?>>Email</option>
                                        <option value="textarea" <?php echo ($fld['field_type'] === 'textarea') ? 'selected' : ''; ?>>Textarea</option>
                                    </select>
                                </div>
                                <div>
                                    <input type="text" name="fields[<?php echo $rowKey; ?>][options]" value="<?php echo htmlspecialchars($fld['field_options'] ?? ''); ?>" placeholder="Comma options" style="width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 5px; font-size: 0.85rem; display: <?php echo ($fld['field_type'] === 'select') ? 'block' : 'none'; ?>;">
                                </div>
                                <div>
                                    <label style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                        <input type="checkbox" name="fields[<?php echo $rowKey; ?>][required]" value="1" <?php echo !empty($fld['is_required']) ? 'checked' : ''; ?>> Req
                                    </label>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline" style="color: #ef4444;" onclick="this.closest('.custom-field-row').remove()">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php
                            endforeach;
                        endif;
                        ?>
                    </div>

                    <div id="noCustomFieldsNotice" style="display: <?php echo empty($existingFields) ? 'block' : 'none'; ?>; padding: 14px; text-align: center; color: var(--secondary); font-size: 0.85rem; border: 1px dashed var(--border); border-radius: 6px; margin-top: 8px;">
                        No extra custom fields added yet. Click <strong>Add Field</strong> to request student qualification, district, or college.
                    </div>
                </div>

            </div>

            <!-- Right: Display Rules, Course Mapping & Lifecycle -->
            <div style="display: flex; flex-direction: column; gap: 20px;">

                <!-- Publishing & Status -->
                <div class="panel" style="background: var(--card-bg, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 20px;">
                    <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 14px; color: var(--foreground); border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                        <i class="fas fa-sliders" style="color: var(--primary);"></i> Lifecycle &amp; Timing
                    </h3>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; display: block; margin-bottom: 6px;">Ad Status</label>
                        <select name="status" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-weight: 600;">
                            <option value="draft" <?php echo (($editAd['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>Draft (Hidden)</option>
                            <option value="active" <?php echo (($editAd['status'] ?? '') === 'active') ? 'selected' : ''; ?>>Active (Visible on matching posts)</option>
                            <option value="paused" <?php echo (($editAd['status'] ?? '') === 'paused') ? 'selected' : ''; ?>>Paused (Temporarily disabled)</option>
                            <option value="archived" <?php echo (($editAd['status'] ?? '') === 'archived') ? 'selected' : ''; ?>>Archived (Completed campaign)</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; display: block; margin-bottom: 6px;">
                            Popup Trigger Delay (Seconds) <span style="color:red;">*</span>
                        </label>
                        <input type="number" name="popup_delay_seconds" min="1" max="600" required value="<?php echo htmlspecialchars($editAd['popup_delay_seconds'] ?? 15); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px;">
                        <div class="help" style="font-size: 0.78rem; color: var(--secondary); margin-top: 4px;">
                            Timer measures active visible-page time. If student switches tabs, timer pauses safely.
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; display: block; margin-bottom: 6px;">Display Priority Order</label>
                        <input type="number" name="display_order" value="<?php echo htmlspecialchars($editAd['display_order'] ?? 0); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px;">
                        <div class="help" style="font-size: 0.78rem; color: var(--secondary); margin-top: 4px;">Lower number = higher priority when multiple ads match.</div>
                    </div>
                </div>

                <!-- Course Mapping & CRM Auto-Convert -->
                <div class="panel" style="background: var(--card-bg, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 20px;">
                    <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 14px; color: var(--foreground); border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                        <i class="fas fa-graduation-cap" style="color: var(--primary);"></i> CRM Lead Integration
                    </h3>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; display: block; margin-bottom: 6px;">Canonical Course Mapping</label>
                        <select name="target_course_id" style="width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px;">
                            <option value="">-- No specific course (General Inquiry) --</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)($editAd['target_course_id'] ?? 0) === (int)$c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['course_name'] . ($c['course_code'] ? " ({$c['course_code']})" : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help" style="font-size: 0.78rem; color: var(--secondary); margin-top: 4px;">Maps captured leads directly to an active PEPP course in CRM.</div>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 14px;">
                        <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 0.88rem; font-weight: 600;">
                            <input type="checkbox" name="auto_convert_crm" value="1" <?php echo !empty($editAd['auto_convert_crm']) ? 'checked' : ''; ?> style="margin-top: 3px;">
                            <span>Enable Automatic CRM Lead Conversion for this Ad</span>
                        </label>
                        <div style="font-size: 0.78rem; color: var(--secondary); margin-top: 6px; line-height: 1.4;">
                            &bull; Automatic conversion operates <strong>only</strong> when both this setting <strong>AND</strong> the Global Master Switch in Settings are <strong>ON</strong>.
                            <br>&bull; Currently Global Auto-Convert is:
                            <strong style="color: <?php echo $isGlobalAutoConvertOn ? '#10b981' : '#64748b'; ?>;">
                                <?php echo $isGlobalAutoConvertOn ? 'ENABLED' : 'DISABLED (Manual Only)'; ?>
                            </strong>
                        </div>
                    </div>
                </div>

                <!-- Update-Post Targeting -->
                <div class="panel" style="background: var(--card-bg, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 20px;">
                    <h3 style="font-size: 1.05rem; font-weight: 700; margin-bottom: 14px; color: var(--foreground); border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                        <i class="fas fa-crosshairs" style="color: var(--primary);"></i> Post Targeting Rules
                    </h3>

                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px;">
                        <label style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="radio" name="target_all_posts" value="1" <?php echo ($editAd['target_all_posts'] ?? 1) == 1 ? 'checked' : ''; ?> onchange="togglePostSelector(false)">
                            <span>Display on All Published Updates</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="radio" name="target_all_posts" value="0" <?php echo ($editAd['target_all_posts'] ?? 1) == 0 ? 'checked' : ''; ?> onchange="togglePostSelector(true)">
                            <span>Target Specific Updates</span>
                        </label>
                    </div>

                    <div id="specificPostsContainer" style="display: <?php echo ($editAd['target_all_posts'] ?? 1) == 0 ? 'block' : 'none'; ?>; border: 1px solid var(--border); border-radius: 6px; max-height: 200px; overflow-y: auto; padding: 8px;">
                        <?php
                        $targetPostIds = $editAd['target_post_ids'] ?? [];
                        foreach ($publishedPosts as $p):
                        ?>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 6px 8px; font-size: 0.82rem; cursor: pointer; border-bottom: 1px solid #f1f5f9;">
                                <input type="checkbox" name="target_posts[]" value="<?php echo (int)$p['id']; ?>" <?php echo in_array($p['id'], $targetPostIds) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($p['title']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Submit Button -->
                <div>
                    <button type="submit" name="save_ad" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem; justify-content: center;">
                        <i class="fas fa-floppy-disk"></i> <?php echo $editAd ? 'Save Ad Changes' : 'Create Popup Ad'; ?>
                    </button>
                </div>

            </div>
        </form>

        <script>
        function togglePostSelector(show) {
            document.getElementById('specificPostsContainer').style.display = show ? 'block' : 'none';
        }

        function toggleOptionsInput(selectElem) {
            const optInput = selectElem.closest('.custom-field-row').querySelector('input[name$="[options]"]');
            if (optInput) {
                optInput.style.display = (selectElem.value === 'select') ? 'block' : 'none';
            }
        }

        let fieldCounter = <?php echo count($existingFields); ?>;
        function addCustomFieldRow() {
            document.getElementById('noCustomFieldsNotice').style.display = 'none';
            const container = document.getElementById('customFieldsContainer');
            const rowKey = 'new_' + Date.now() + '_' + fieldCounter;
            const row = document.createElement('div');
            row.className = 'custom-field-row';
            row.style = 'background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 12px; display: grid; grid-template-columns: 2fr 1.5fr 1fr auto auto; gap: 10px; align-items: center;';
            row.innerHTML = `
                <div>
                    <input type="text" name="fields[${rowKey}][label]" required placeholder="Field Label (e.g. Qualification)" style="width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 5px; font-size: 0.85rem;">
                    <input type="hidden" name="fields[${rowKey}][name]" value="">
                </div>
                <div>
                    <select name="fields[${rowKey}][type]" style="width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 5px; font-size: 0.85rem;" onchange="toggleOptionsInput(this)">
                        <option value="text">Text Input</option>
                        <option value="select">Select Dropdown</option>
                        <option value="tel">Telephone</option>
                        <option value="email">Email</option>
                        <option value="textarea">Textarea</option>
                    </select>
                </div>
                <div>
                    <input type="text" name="fields[${rowKey}][options]" placeholder="Comma options" style="width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 5px; font-size: 0.85rem; display: none;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                        <input type="checkbox" name="fields[${rowKey}][required]" value="1"> Req
                    </label>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-outline" style="color: #ef4444;" onclick="this.closest('.custom-field-row').remove()">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(row);
            fieldCounter++;
        }
        </script>

    <?php else: ?>
        <!-- ======================================================== -->
        <!-- ADS LISTING                                              -->
        <!-- ======================================================== -->
        <?php
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? '');
        $ads = pepp_updates_get_ads($pdo, ['search' => $search, 'status' => $statusFilter]);

        $totalAds = count($ads);
        $activeAds = 0;
        $totalLeads = 0;
        $convertedLeads = 0;
        foreach ($ads as $ad) {
            if ($ad['status'] === 'active') $activeAds++;
            $totalLeads += (int)($ad['total_leads_count'] ?? 0);
            $convertedLeads += (int)($ad['converted_leads_count'] ?? 0);
        }
        ?>

        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px;">
            <div>
                <h2 style="font-size: 1.4rem; font-weight: 800; color: var(--foreground); margin-bottom: 4px;">
                    Popup Ads Management
                </h2>
                <div style="font-size: 0.85rem; color: var(--secondary);">
                    Capture student admissions leads directly from high-intent PEPP Updates readers.
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="pepp-updates-ad-leads.php" class="btn btn-outline">
                    <i class="fas fa-bullhorn"></i> View Captured Leads
                </a>
                <a href="pepp-updates-ads.php?action=create" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create New Popup Ad
                </a>
            </div>
        </div>

        <!-- Metric Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 22px;">
            <div class="panel" style="padding: 16px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
                <div style="font-size: 0.8rem; color: var(--secondary); font-weight: 600;">Total Configured Ads</div>
                <div style="font-size: 1.8rem; font-weight: 800; color: var(--foreground); margin-top: 4px;"><?php echo $totalAds; ?></div>
            </div>
            <div class="panel" style="padding: 16px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
                <div style="font-size: 0.8rem; color: var(--secondary); font-weight: 600;">Active Live Ads</div>
                <div style="font-size: 1.8rem; font-weight: 800; color: #10b981; margin-top: 4px;"><?php echo $activeAds; ?></div>
            </div>
            <div class="panel" style="padding: 16px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
                <div style="font-size: 0.8rem; color: var(--secondary); font-weight: 600;">Total Ad Leads Captured</div>
                <div style="font-size: 1.8rem; font-weight: 800; color: var(--primary); margin-top: 4px;"><?php echo $totalLeads; ?></div>
            </div>
            <div class="panel" style="padding: 16px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
                <div style="font-size: 0.8rem; color: var(--secondary); font-weight: 600;">Converted to CRM Leads</div>
                <div style="font-size: 1.8rem; font-weight: 800; color: #0284c7; margin-top: 4px;"><?php echo $convertedLeads; ?></div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="panel" style="margin-bottom: 20px; padding: 12px 18px; border-radius: 8px; border: 1px solid var(--border); background: #fff;">
            <form method="GET" action="pepp-updates-ads.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 240px;">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search ad by title or headline..." style="width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.88rem;">
                </div>
                <div>
                    <select name="status" style="padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.88rem;">
                        <option value="">All Statuses</option>
                        <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="draft" <?php echo ($statusFilter === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="paused" <?php echo ($statusFilter === 'paused') ? 'selected' : ''; ?>>Paused</option>
                        <option value="archived" <?php echo ($statusFilter === 'archived') ? 'selected' : ''; ?>>Archived</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-outline" style="padding: 8px 16px;">
                    <i class="fas fa-search"></i> Filter
                </button>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="pepp-updates-ads.php" class="btn btn-outline" title="Clear Filters">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Ads Table -->
        <div class="panel" style="background: #fff; border: 1px solid var(--border); border-radius: 10px; overflow: hidden;">
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid var(--border); text-align: left;">
                            <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Ad Title / Headline</th>
                            <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Targeting</th>
                            <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Delay</th>
                            <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Mapped Course</th>
                            <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Auto-Convert</th>
                            <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Status</th>
                            <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary);">Leads</th>
                            <th style="padding: 12px 16px; font-weight: 700; color: var(--secondary); text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ads)): ?>
                            <tr>
                                <td colspan="8" style="padding: 36px 16px; text-align: center; color: var(--secondary);">
                                    <div style="font-size: 1.1rem; font-weight: 600; margin-bottom: 6px;">No popup ads found</div>
                                    <div style="font-size: 0.85rem; margin-bottom: 14px;">Create your first modal ad to capture student admission inquiries.</div>
                                    <a href="pepp-updates-ads.php?action=create" class="btn btn-sm btn-primary">Create New Ad</a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ads as $ad):
                                $isAdAutoConvertOn = (int)$ad['auto_convert_crm'] === 1;
                                $effectiveAutoConvert = $isAdAutoConvertOn && $isGlobalAutoConvertOn;
                            ?>
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <td style="padding: 12px 16px;">
                                        <div style="font-weight: 700; color: var(--foreground); margin-bottom: 2px;">
                                            <?php echo htmlspecialchars($ad['title']); ?>
                                        </div>
                                        <?php if (!empty($ad['headline'])): ?>
                                            <div style="font-size: 0.8rem; color: var(--secondary);">
                                                <?php echo htmlspecialchars($ad['headline']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <code style="font-size: 0.72rem; color: #64748b;">/ad/<?php echo htmlspecialchars($ad['slug']); ?></code>
                                    </td>
                                    <td style="padding: 12px 16px;">
                                        <?php if ($ad['target_all_posts']): ?>
                                            <span class="badge blue" style="font-size: 0.75rem;">All Updates</span>
                                        <?php else: ?>
                                            <span class="badge gray" style="font-size: 0.75rem;"><?php echo (int)$ad['targeted_posts_count']; ?> Selected Posts</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 16px; font-weight: 600;">
                                        <?php echo (int)$ad['popup_delay_seconds']; ?>s
                                    </td>
                                    <td style="padding: 12px 16px;">
                                        <?php if (!empty($ad['mapped_course_name'])): ?>
                                            <span class="badge green" style="font-size: 0.75rem;"><?php echo htmlspecialchars($ad['mapped_course_name']); ?></span>
                                        <?php else: ?>
                                            <span style="color: var(--secondary); font-size: 0.8rem;">General Inquiry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 16px;">
                                        <?php if ($effectiveAutoConvert): ?>
                                            <span class="badge green" title="Both Global and Per-Ad switches are ON" style="font-size: 0.75rem;"><i class="fas fa-bolt"></i> Auto (Active)</span>
                                        <?php elseif ($isAdAutoConvertOn && !$isGlobalAutoConvertOn): ?>
                                            <span class="badge orange" title="Ad switch is ON but Global master switch is OFF in Settings" style="font-size: 0.75rem;"><i class="fas fa-pause"></i> Global Paused</span>
                                        <?php else: ?>
                                            <span class="badge gray" title="Manual conversion only" style="font-size: 0.75rem;"><i class="fas fa-hand"></i> Manual Only</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 16px;">
                                        <?php
                                        $badgeColor = ($ad['status'] === 'active') ? 'green' : (($ad['status'] === 'paused') ? 'orange' : (($ad['status'] === 'archived') ? 'red' : 'gray'));
                                        ?>
                                        <span class="badge <?php echo $badgeColor; ?>" style="font-size: 0.75rem; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($ad['status']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px 16px;">
                                        <a href="pepp-updates-ad-leads.php?ad_id=<?php echo (int)$ad['id']; ?>" style="font-weight: 700; color: var(--primary); text-decoration: none;">
                                            <?php echo (int)$ad['total_leads_count']; ?>
                                        </a>
                                        <?php if ((int)$ad['converted_leads_count'] > 0): ?>
                                            <span style="font-size: 0.75rem; color: #10b981;">(<?php echo (int)$ad['converted_leads_count']; ?> crm)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px 16px; text-align: right; white-space: nowrap;">
                                        <div style="display: inline-flex; gap: 4px; align-items: center; justify-content: flex-end;">
                                            <a href="pepp-updates-ads.php?action=edit&id=<?php echo (int)$ad['id']; ?>" class="btn btn-sm btn-outline" title="Edit Ad">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <?php if ($ad['status'] === 'active'): ?>
                                                <form method="POST" action="pepp-updates-ads.php" style="display: inline-block; margin: 0;" onsubmit="return confirm('Pause this ad?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="id" value="<?php echo (int)$ad['id']; ?>">
                                                    <input type="hidden" name="ad_action" value="pause">
                                                    <button type="submit" class="btn btn-sm btn-outline" title="Pause Ad">
                                                        <i class="fas fa-pause"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="pepp-updates-ads.php" style="display: inline-block; margin: 0;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="id" value="<?php echo (int)$ad['id']; ?>">
                                                    <input type="hidden" name="ad_action" value="activate">
                                                    <button type="submit" class="btn btn-sm btn-outline" style="color: #10b981;" title="Activate Ad">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" action="pepp-updates-ads.php" style="display: inline-block; margin: 0;" onsubmit="return confirm('Archive this ad?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="id" value="<?php echo (int)$ad['id']; ?>">
                                                <input type="hidden" name="ad_action" value="archive">
                                                <button type="submit" class="btn btn-sm btn-outline" title="Archive Ad">
                                                    <i class="fas fa-box-archive"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="pepp-updates-ads.php" style="display: inline-block; margin: 0;" onsubmit="return confirm('Are you sure you want to delete this ad?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="id" value="<?php echo (int)$ad['id']; ?>">
                                                <input type="hidden" name="ad_action" value="delete">
                                                <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444;" title="Delete Ad">
                                                    <i class="fas fa-trash"></i>
                                                </button>
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

    <?php endif; ?>

</div>
