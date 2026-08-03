<?php
require_once 'includes/auth.php';
require_permission('campaigns');
require_once 'config/database.php';

$active_page = 'campaigns';
$page_title  = 'Custom Form Builder';
$page_sub    = 'Design custom forms, configure validations, access controls, themes and integrations';

$form_id = (int)($_GET['id'] ?? 0);
$form_data = null;
$fields_data = [];

if ($form_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM campaign_forms WHERE id = ?");
        $stmt->execute([$form_id]);
        $form_data = $stmt->fetch();

        if ($form_data) {
            $stmt = $pdo->prepare("SELECT * FROM campaign_form_fields WHERE form_id = ? ORDER BY sort_order ASC");
            $stmt->execute([$form_id]);
            $fields_data = $stmt->fetchAll();
        } else {
            $form_id = 0; // fallback to new form if not found
        }
    } catch (Exception $e) {
        $error_msg = "Database error: " . $e->getMessage();
    }
}

// Map database fields to editor fields
$js_fields = [];
foreach ($fields_data as $f) {
    $js_fields[] = [
        'id' => (int)$f['id'],
        'type' => $f['type'],
        'label' => $f['label'],
        'placeholder' => $f['placeholder'],
        'default_value' => $f['default_value'],
        'field_name' => $f['field_name'],
        'is_required' => (bool)$f['is_required'],
        'validation_rules' => !empty($f['validation_rules']) ? json_decode($f['validation_rules'], true) : new stdClass(),
        'choices' => !empty($f['choices']) ? json_decode($f['choices'], true) : [],
        'conditional_logic' => !empty($f['conditional_logic']) ? json_decode($f['conditional_logic'], true) : new stdClass(),
        'error_message' => $f['error_message']
    ];
}

include 'includes/admin_nav.php';
?>

<style>
    /* Builder Panel Layout */
    .builder-container {
        display: grid;
        grid-template-columns: 1fr 340px;
        gap: 1.5rem;
        align-items: start;
        margin-top: 1rem;
    }

    @media (max-width: 1024px) {
        .builder-container {
            grid-template-columns: 1fr;
        }
    }

    /* Tab styles */
    .tabs-nav {
        display: flex;
        gap: 8px;
        border-bottom: 2px solid var(--border);
        margin-bottom: 1.5rem;
        padding-bottom: 2px;
    }

    .tab-btn {
        background: transparent;
        border: none;
        border-bottom: 3px solid transparent;
        padding: 0.75rem 1.2rem;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        color: var(--text-muted);
        transition: all 0.2s ease;
    }

    .tab-btn.active {
        color: var(--accent);
        border-color: var(--accent);
    }

    .tab-btn:hover:not(.active) {
        color: var(--text-main);
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    /* Visual editor canvas */
    .editor-canvas {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 1.5rem;
        min-height: 400px;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .canvas-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex: 1;
        color: var(--text-muted);
        padding: 3rem;
        text-align: center;
        border: 2px dashed var(--border);
        border-radius: 12px;
    }

    .canvas-empty i {
        font-size: 3rem;
        margin-bottom: 12px;
    }

    /* Form Fields Styling */
    .field-item {
        background: var(--input-bg);
        border: 1.5px solid var(--border);
        border-radius: 12px;
        padding: 1rem 1.2rem;
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: 12px;
        cursor: grab;
        transition: all 0.2s ease;
        position: relative;
    }

    .field-item.selected {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(232, 152, 12, 0.12);
        background: var(--card-bg);
    }

    .field-item.dragging {
        opacity: 0.4;
        border-style: dashed;
    }

    .field-drag-handle {
        color: var(--text-muted);
        cursor: grab;
        font-size: 1.1rem;
    }

    .field-details {
        flex: 1;
    }

    .field-title {
        font-weight: 700;
        font-size: 0.95rem;
        margin-bottom: 3px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .field-type-badge {
        font-size: 0.7rem;
        background: var(--border);
        color: var(--text-muted);
        padding: 2px 6px;
        border-radius: 4px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .field-sub {
        font-size: 0.78rem;
        color: var(--text-muted);
    }

    .field-actions {
        display: flex;
        gap: 6px;
    }

    /* Properties Panel */
    .panel {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 1.2rem;
    }

    .panel-scrollable {
        max-height: 420px;
        overflow-y: auto;
        padding-right: 6px;
    }
    
    .panel-scrollable::-webkit-scrollbar,
    .builder-sidebar::-webkit-scrollbar {
        width: 5px;
    }
    .panel-scrollable::-webkit-scrollbar-track,
    .builder-sidebar::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.05);
        border-radius: 10px;
    }
    .panel-scrollable::-webkit-scrollbar-thumb,
    .builder-sidebar::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 10px;
    }
    .panel-scrollable::-webkit-scrollbar-thumb:hover,
    .builder-sidebar::-webkit-scrollbar-thumb:hover {
        background: var(--accent);
    }

    .builder-sidebar {
        position: sticky;
        top: 20px;
        display: flex;
        flex-direction: column;
        gap: 1.2rem;
        max-height: calc(100vh - 40px);
        overflow-y: auto;
        padding-right: 4px;
    }

    .panel-title {
        font-size: 1rem;
        font-weight: 800;
        margin-bottom: 1rem;
        padding-bottom: 8px;
        border-bottom: 1.5px solid var(--border);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .properties-form {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .prop-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .prop-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-muted);
    }

    /* Enhanced Textbox UI/UX styling */
    .prop-input, .form-input {
        background: var(--input-bg);
        border: 1.5px solid var(--border);
        border-radius: 10px;
        padding: 0.65rem 0.9rem;
        font-family: inherit;
        font-size: 0.88rem;
        color: var(--text-main);
        width: 100%;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .prop-input:hover, .form-input:hover {
        border-color: var(--accent);
    }

    .prop-input:focus, .form-input:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(232, 152, 12, 0.15);
        background: var(--card-bg);
    }

    /* Slug input states */
    .slug-wrapper {
        position: relative;
    }
    .slug-status {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.9rem;
    }

    /* Choice Stack builder */
    .choices-builder {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .choice-builder-row {
        display: flex;
        gap: 6px;
    }
</style>

<!-- ── HEADER ACTIONS ── -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:12px;">
    <div>
        <h2 style="font-weight:800; margin:0;"><?php echo $form_id > 0 ? 'Edit Campaign Form' : 'Create Custom Form'; ?></h2>
        <p style="font-size:0.875rem; color:var(--text-muted);"><?php echo $form_id > 0 ? 'Modify form metadata, layout fields, and settings' : 'Build a beautiful customized campaign data form'; ?></p>
    </div>
    
    <div style="display:flex; gap:10px;">
        <span id="autosave-indicator" style="font-size:0.8rem; color:var(--text-muted); align-self:center; display:none;">
            <i class="fas fa-arrows-spin fa-spin"></i> Autosaving...
        </span>
        <span id="saved-indicator" style="font-size:0.8rem; color:#16a34a; align-self:center; display:none;">
            <i class="fas fa-circle-check"></i> All changes saved
        </span>
        <a href="campaign-forms.php" class="btn btn-secondary" style="padding:0.6rem 1.2rem;"><i class="fas fa-arrow-left"></i> Back</a>
        <button class="btn btn-primary" onclick="saveForm(false)" style="padding:0.6rem 1.4rem;"><i class="fas fa-save"></i> Save Form Settings</button>
    </div>
</div>

<!-- ── TAB BAR ── -->
<div class="tabs-nav">
    <button class="tab-btn active" onclick="switchTab('builder')"><i class="fab fa-wpforms"></i> Visual Form Editor</button>
    <button class="tab-btn" onclick="switchTab('access')"><i class="fas fa-lock"></i> Access Controls</button>
    <button class="tab-btn" onclick="switchTab('integrations')"><i class="fas fa-circle-nodes"></i> Emails &amp; Webhooks</button>
    <button class="tab-btn" onclick="switchTab('thankyou')"><i class="fas fa-face-smile"></i> Thank You Configuration</button>
</div>

<!-- ── MAIN CONTAINER ── -->
<div class="builder-container">
    
    <!-- LEFT PANEL: TABS CONTENT -->
    <div style="display:flex; flex-direction:column; gap:1rem;">
        
        <!-- Tab 1: Visual Form Editor -->
        <div id="tab-builder" class="tab-content active">
            <!-- Form Title & Description Card -->
            <div class="card" style="margin-bottom:1rem; padding:1.2rem;">
                <div class="form-group" style="margin-bottom:1rem;">
                    <label>Form Title</label>
                    <input type="text" id="form-title" class="form-input" style="font-size:1.2rem; font-weight:700; margin-bottom:0;" placeholder="Enter Form Title" value="<?php echo htmlspecialchars($form_data['title'] ?? 'Untitled Form'); ?>" oninput="onTitleInput()">
                </div>
                <div class="form-group" style="margin-bottom:0.8rem;">
                    <label>Form Description / Subtitle</label>
                    <textarea id="form-description" class="form-input" style="margin-bottom:0;" rows="2" placeholder="Describe the purpose of this form for visitors"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0; margin-top:0.8rem;">
                    <label style="display:flex; justify-content:space-between; align-items:center;">
                        <span>Form Header Banner Image (Optional)</span>
                        <span style="font-size:0.75rem; color:var(--accent); font-weight:700;"><i class="fas fa-ruler-combined"></i> Aspect Ratio: 3:1 or 16:9 (1200×400px / 1200×675px)</span>
                    </label>

                    <div style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">
                        <input type="text" id="form-banner-image" class="form-input" style="margin-bottom:0; flex:1;" placeholder="Upload image file or enter URL..." value="<?php echo htmlspecialchars($form_data['banner_image'] ?? ''); ?>" oninput="previewBannerImage(this.value)">
                        <label class="btn btn-secondary" style="margin-bottom:0; cursor:pointer; padding:0.65rem 1.1rem; border-radius:10px; font-size:0.85rem; font-weight:700; white-space:nowrap;" id="btn-upload-banner-label">
                            <i class="fas fa-upload"></i> Upload Image
                            <input type="file" accept="image/*" style="display:none;" onchange="uploadBannerFile(this)">
                        </label>
                    </div>
                    
                    <div id="banner-aspect-hint" style="font-size:0.75rem; color:var(--text-muted); background:var(--input-bg); padding:8px 12px; border-radius:8px; border:1px dashed var(--border);">
                        <i class="fas fa-circle-info" style="color:var(--accent);"></i> Recommended Banner Design: <strong>1200px width × 400px height</strong> (3:1 aspect ratio) or <strong>1200px width × 675px height</strong> (16:9 ratio). Max size: 5MB. Supported Formats: PNG, JPG, WEBP.
                    </div>

                    <div id="banner-preview-box" style="margin-top:10px; display:<?php echo !empty($form_data['banner_image']) ? 'block' : 'none'; ?>;">
                        <img id="banner-preview-img" src="<?php echo htmlspecialchars($form_data['banner_image'] ?? ''); ?>" style="max-height:140px; width:100%; object-fit:cover; border-radius:10px; border:1px solid var(--border);">
                    </div>
                </div>
            </div>

            <!-- Fields Editor Workspace -->
            <div class="editor-canvas" id="canvas-workspace" ondragover="allowDrop(event)" ondrop="handleDrop(event)">
                <!-- Fields render dynamically here -->
            </div>
        </div>

        <!-- Tab 2: Access Controls -->
        <div id="tab-access" class="tab-content card">
            <h3 style="font-size:1.1rem; font-weight:800; border-bottom:1.5px solid var(--border); padding-bottom:8px; margin-bottom:1.2rem;">Form Access &amp; Submission Rules</h3>
            
            <div class="form-grid">
                <div class="field">
                    <label>Form Custom Clean Slug (PURL)</label>
                    <div class="slug-wrapper">
                        <input type="text" id="form-slug" class="form-input" placeholder="e.g. psychology-camp-2026" value="<?php echo htmlspecialchars($form_data['slug'] ?? ''); ?>" oninput="checkSlugAvailability()">
                        <span id="slug-indicator" class="slug-status"></span>
                    </div>
                    <small style="color:var(--text-muted); font-size:0.75rem; display:block; margin-top:0.25rem;">Custom URL: https://pepplearning.in/admissions/f.php?s=<strong id="preview-slug">slug</strong></small>
                </div>
                
                <div class="field">
                    <label>Publishing Status</label>
                    <select id="form-status" class="form-input">
                        <option value="draft" <?php echo ($form_data['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft (Admin only)</option>
                        <option value="published" <?php echo ($form_data['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published / Active</option>
                    </select>
                </div>

                <div class="field">
                    <label>Access Mode</label>
                    <select id="form-public" class="form-input" onchange="toggleAccessMode()">
                        <option value="1" <?php echo ($form_data['is_public'] ?? 1) == 1 ? 'selected' : ''; ?>>Public (Anyone can submit)</option>
                        <option value="0" <?php echo ($form_data['is_public'] ?? 1) == 0 ? 'selected' : ''; ?>>Restricted (Authorized Emails list)</option>
                    </select>
                </div>

                <div class="field" id="wrap-allowed-emails" style="display:none;">
                    <label>Authorized Emails list / Domain wildcard</label>
                    <input type="text" id="form-allowed-emails" class="form-input" placeholder="e.g. user@gmail.com, @pepp.com, @labinc.in" value="<?php echo htmlspecialchars($form_data['allowed_emails'] ?? ''); ?>">
                    <small style="color:var(--text-muted); font-size:0.72rem; display:block; margin-top:2px;">Comma-separated emails, or use domain wildcard starts with @ (e.g. @pepp.com)</small>
                </div>

                <div class="field">
                    <label>Scheduled Publish Date (Optional)</label>
                    <input type="datetime-local" id="form-schedule-start" class="form-input" value="<?php echo $form_data['publish_schedule_start'] ? date('Y-m-d\TH:i', strtotime($form_data['publish_schedule_start'])) : ''; ?>">
                </div>

                <div class="field">
                    <label>Scheduled Close Date (Optional)</label>
                    <input type="datetime-local" id="form-schedule-end" class="form-input" value="<?php echo $form_data['publish_schedule_end'] ? date('Y-m-d\TH:i', strtotime($form_data['publish_schedule_end'])) : ''; ?>">
                </div>

                <div class="field">
                    <label>Submission Limit per Respondent</label>
                    <input type="number" id="form-limit-user" class="form-input" placeholder="0 = No limit" min="0" value="<?php echo (int)($form_data['limit_per_user'] ?? 0); ?>">
                </div>

                <div class="field">
                    <label>Maximum Submissions Limit (Total)</label>
                    <input type="number" id="form-limit-total" class="form-input" placeholder="0 = Unlimited" min="0" value="<?php echo (int)($form_data['submission_limit'] ?? 0); ?>">
                </div>

                <div class="field">
                    <label>Form Access Password (Optional)</label>
                    <input type="password" id="form-password" class="form-input" placeholder="Leave blank to keep current password, or enter new password">
                    <?php if (!empty($form_data['password'])): ?>
                        <small style="color:#16a34a; font-weight:700;"><i class="fas fa-check-circle"></i> Password protected</small>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label>Enable Server-side CAPTCHA challenge</label>
                    <select id="form-captcha" class="form-input">
                        <option value="0" <?php echo ($form_data['enable_captcha'] ?? 0) == 0 ? 'selected' : ''; ?>>No CAPTCHA</option>
                        <option value="1" <?php echo ($form_data['enable_captcha'] ?? 0) == 1 ? 'selected' : ''; ?>>Required (Protects from Spam)</option>
                    </select>
                </div>

                <div class="field">
                    <label>Branded Theme / Style</label>
                    <select id="form-theme" class="form-input">
                        <option value="default" <?php echo ($form_data['theme'] ?? 'default') === 'default' ? 'selected' : ''; ?>>Default PEPP Amber Theme</option>
                        <option value="sunset" <?php echo ($form_data['theme'] ?? '') === 'sunset' ? 'selected' : ''; ?>>Sunset Orange</option>
                        <option value="minimal" <?php echo ($form_data['theme'] ?? '') === 'minimal' ? 'selected' : ''; ?>>Minimalist Clean Light</option>
                        <option value="glass" <?php echo ($form_data['theme'] ?? '') === 'glass' ? 'selected' : ''; ?>>Indigo Glassmorphism</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Tab 3: Integrations & Webhooks -->
        <div id="tab-integrations" class="tab-content card">
            <h3 style="font-size:1.1rem; font-weight:800; border-bottom:1.5px solid var(--border); padding-bottom:8px; margin-bottom:1.2rem;">Emails Notifications &amp; Webhook Integration</h3>
            
            <div class="form-grid">
                <div class="field full">
                    <label>Webhook endpoint URL (Post JSON Payload)</label>
                    <input type="url" id="form-webhook" class="form-input" placeholder="e.g. https://api.crm.com/webhooks/form-data" value="<?php echo htmlspecialchars($form_data['webhook_url'] ?? ''); ?>">
                    <small style="color:var(--text-muted); font-size:0.75rem;">Triggers instantly upon successful submission with a structured JSON post request containing respondent email, timestamps, and answer key-value pairs.</small>
                </div>

                <div class="field full">
                    <label>Admin Notification Emails (comma-separated)</label>
                    <input type="text" id="form-notify-emails" class="form-input" placeholder="e.g. info@pepplearning.com, accounts@pepp.com" value="<?php echo htmlspecialchars($form_data['notify_emails'] ?? ''); ?>">
                </div>

                <div class="field full">
                    <h4 style="font-size:0.95rem; font-weight:700; margin-top:1.2rem; margin-bottom:0.5rem; color:var(--accent);">Respondent Confirmation Email template</h4>
                    <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:1rem;">Send automated copy of responses back to respondent. Triggered only if they entered a valid email address.</p>
                </div>

                <div class="field full">
                    <label>Email Subject</label>
                    <input type="text" id="form-confirm-subject" class="form-input" placeholder="e.g. We have received your application for PEPP!" value="<?php echo htmlspecialchars($form_data['confirmation_email_subject'] ?? ''); ?>">
                </div>

                <div class="field full">
                    <label>Email Body (HTML allowed)</label>
                    <textarea id="form-confirm-body" class="form-input" rows="8" placeholder="Hi {name},&#10;&#10;Thank you for submitting the form. Below are details of your submission:&#10;&#10;{answers}"><?php echo htmlspecialchars($form_data['confirmation_email_body'] ?? ''); ?></textarea>
                    <small style="color:var(--text-muted); font-size:0.75rem;">Supported tags: <code>{form_title}</code>, <code>{answers}</code> (inserts structured table of question and answers)</small>
                </div>
            </div>
        </div>

        <!-- Tab 4: Thank You Configurations -->
        <div id="tab-thankyou" class="tab-content card">
            <h3 style="font-size:1.1rem; font-weight:800; border-bottom:1.5px solid var(--border); padding-bottom:8px; margin-bottom:1.2rem;">Post-Submission Thank You Configuration</h3>
            
            <div class="form-group">
                <label>Thank You Page Header Title</label>
                <input type="text" id="form-thankyou-title" class="form-input" placeholder="e.g. Submission successful!" value="<?php echo htmlspecialchars($form_data['thank_you_title'] ?? 'Thank You!'); ?>">
            </div>
            
            <div class="form-group">
                <label>Thank You Page Description / Message</label>
                <textarea id="form-thankyou-text" class="form-input" rows="4" placeholder="Write instructions or next steps for the respondent."><?php echo htmlspecialchars($form_data['thank_you_text'] ?? 'Your response has been recorded.'); ?></textarea>
            </div>

            <!-- WhatsApp Group Auto-Redirect Toggle -->
            <div class="form-group" style="margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1.2rem;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:700; text-transform:none;">
                    <input type="checkbox" id="form-redirect-whatsapp" <?php echo ($form_data['auto_redirect_whatsapp'] ?? 0) == 1 ? 'checked' : ''; ?> onchange="toggleWhatsappRedirect()">
                    <i class="fab fa-whatsapp" style="color:#25D366; font-size:1.2rem;"></i> Auto Redirect to WhatsApp Group after submission
                </label>
            </div>

            <div class="form-group" id="wrap-whatsapp-link" style="display:<?php echo ($form_data['auto_redirect_whatsapp'] ?? 0) == 1 ? 'block' : 'none'; ?>;">
                <label>WhatsApp Group Link</label>
                <input type="url" id="form-whatsapp-link" class="form-input" placeholder="https://chat.whatsapp.com/L1234567890abcdef" value="<?php echo htmlspecialchars($form_data['whatsapp_group_link'] ?? ''); ?>">
                <small style="color:var(--text-muted); font-size:0.75rem; display:block; margin-top:4px;">Respondents will see a 3-second countdown before automatically being redirected to join this WhatsApp Group.</small>
            </div>
        </div>

    </div>

    <!-- RIGHT PANEL: ADD FIELD DRAWER & FIELD PROPERTIES -->
    <div class="builder-sidebar">
        
        <!-- Add field toolbox -->
        <div class="panel panel-scrollable">
            <div class="panel-title"><i class="fas fa-plus" style="color:var(--accent);"></i> Add Fields</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <button class="btn btn-sm btn-secondary" onclick="addField('short_text')"><i class="fas fa-font"></i> Short Text</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('long_text')"><i class="fas fa-align-left"></i> Long Text</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('number')"><i class="fas fa-hashtag"></i> Number</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('email')"><i class="fas fa-envelope"></i> Email</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('phone')"><i class="fas fa-phone"></i> Phone</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('whatsapp')"><i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('location')"><i class="fas fa-location-dot" style="color:#ef4444;"></i> Location</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('url')"><i class="fas fa-link"></i> URL Link</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('dropdown')"><i class="fas fa-caret-down"></i> Dropdown</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('multiselect')"><i class="fas fa-list-ul"></i> Multi Select</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('checkboxes')"><i class="fas fa-square-check"></i> Checkboxes</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('radio')"><i class="fas fa-circle-dot"></i> Radio Btns</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('date')"><i class="fas fa-calendar"></i> Date</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('time')"><i class="fas fa-clock"></i> Time</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('datetime')"><i class="fas fa-calendar-days"></i> DateTime</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('file')"><i class="fas fa-cloud-arrow-up"></i> File Upload</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('rating')"><i class="fas fa-star"></i> Star Rating</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('toggle')"><i class="fas fa-toggle-on"></i> Toggle Switch</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('hidden')"><i class="fas fa-eye-slash"></i> Hidden Field</button>
                <button class="btn btn-sm btn-secondary" onclick="addField('section')" style="grid-column: span 2; background:rgba(232,152,12,0.06); border-color:rgba(232,152,12,0.3); color:var(--accent);"><i class="fas fa-page-break"></i> Section Break (Multi-page)</button>
            </div>
        </div>

        <!-- Field Property Inspector Panel -->
        <div class="panel panel-scrollable" id="inspector-panel" style="display:none;">
            <div class="panel-title"><i class="fas fa-gears" style="color:var(--accent);"></i> Field Properties</div>
            
            <div class="properties-form">
                <div class="prop-group">
                    <label class="prop-label">Field Label</label>
                    <input type="text" id="prop-label" class="prop-input" oninput="updateActiveField('label', this.value)">
                </div>

                <div class="prop-group">
                    <label class="prop-label">Placeholder Text</label>
                    <input type="text" id="prop-placeholder" class="prop-input" oninput="updateActiveField('placeholder', this.value)">
                </div>

                <div class="prop-group">
                    <label class="prop-label">Default Value</label>
                    <input type="text" id="prop-default" class="prop-input" oninput="updateActiveField('default_value', this.value)">
                </div>

                <div class="prop-group">
                    <label class="prop-label" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" id="prop-required" onchange="updateActiveField('is_required', this.checked)">
                        Required Field
                    </label>
                </div>

                <div class="prop-group" id="prop-choices-group" style="display:none;">
                    <label class="prop-label">Configure Options / Choices</label>
                    <div class="choices-builder" id="choices-container"></div>
                    <button class="btn btn-sm btn-secondary" style="margin-top:5px; font-size:0.75rem;" onclick="addChoiceOption()"><i class="fas fa-plus"></i> Add Choice</button>
                </div>

                <!-- Numerical Constraints -->
                <div class="prop-group" id="prop-num-constraints" style="display:none; grid-template-columns:1fr 1fr; gap:6px;">
                    <div>
                        <label class="prop-label">Min Value</label>
                        <input type="number" id="prop-num-min" class="prop-input" oninput="updateValidationRules('min', this.value)">
                    </div>
                    <div>
                        <label class="prop-label">Max Value</label>
                        <input type="number" id="prop-num-max" class="prop-input" oninput="updateValidationRules('max', this.value)">
                    </div>
                </div>

                <!-- Text Character limit -->
                <div class="prop-group" id="prop-text-constraints" style="display:none;">
                    <label class="prop-label">Maximum Characters</label>
                    <input type="number" id="prop-char-limit" class="prop-input" oninput="updateValidationRules('max_chars', this.value)">
                </div>

                <!-- File Upload Constraints -->
                <div class="prop-group" id="prop-file-constraints" style="display:none;">
                    <div class="prop-group" style="margin-bottom:8px;">
                        <label class="prop-label">Allowed File Extension Types</label>
                        <input type="text" id="prop-file-types" class="prop-input" placeholder="jpg, jpeg, png, pdf, zip" oninput="updateValidationRules('file_types', this.value)">
                    </div>
                    <div class="prop-group">
                        <label class="prop-label">Max File Size (MB)</label>
                        <input type="number" id="prop-file-size" class="prop-input" value="5" oninput="updateValidationRules('max_size', this.value)">
                    </div>
                </div>

                <!-- Custom validation error message -->
                <div class="prop-group">
                    <label class="prop-label">Custom Error Message</label>
                    <input type="text" id="prop-error-msg" class="prop-input" placeholder="Shown if validation fails" oninput="updateActiveField('error_message', this.value)">
                </div>

                <!-- Conditional Logic inspector -->
                <div class="prop-group" style="border-top:1.5px solid var(--border); padding-top:10px; margin-top:8px;">
                    <label class="prop-label" style="font-weight:800; color:var(--accent); margin-bottom:8px;">Conditional Show/Hide Logic</label>
                    
                    <div class="prop-group" style="margin-bottom:6px;">
                        <label class="prop-label">Show this field if:</label>
                        <select id="prop-logic-field" class="prop-input" onchange="updateLogic('field', this.value)">
                            <option value="">(No logic conditions)</option>
                        </select>
                    </div>

                    <div id="prop-logic-details" style="display:none;">
                        <div class="prop-group" style="margin-bottom:6px;">
                            <label class="prop-label">Operator</label>
                            <select id="prop-logic-operator" class="prop-input" onchange="updateLogic('operator', this.value)">
                                <option value="=">Is Equal To</option>
                                <option value="!=">Is Not Equal To</option>
                                <option value="empty">Is Empty</option>
                                <option value="not_empty">Is Not Empty</option>
                            </select>
                        </div>
                        <div class="prop-group" id="prop-logic-val-wrap">
                            <label class="prop-label">Value</label>
                            <input type="text" id="prop-logic-value" class="prop-input" oninput="updateLogic('value', this.value)">
                        </div>
                    </div>
                </div>

                <button class="btn btn-sm btn-soft-red" style="margin-top:10px;" onclick="deleteActiveField()"><i class="fas fa-trash-can"></i> Remove Field</button>
            </div>
        </div>

    </div>
</div>

<script>
    var formId = <?php echo $form_id; ?>;
    var formFields = <?php echo json_encode($js_fields); ?>;
    var selectedFieldId = null;
    var hasChanges = false;
    var slugUnique = true;
    var isSaving = false;

    document.addEventListener('DOMContentLoaded', function() {
        renderFields();
        toggleAccessMode();
        
        // Auto-check slug on page load
        if (document.getElementById('form-slug').value) {
            checkSlugAvailability();
        }

        // Setup Autosave Interval (every 30 seconds if changed)
        setInterval(function() {
            if (hasChanges && !isSaving) {
                saveForm(true);
            }
        }, 30000);

        // Warn before leaving unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if (hasChanges) {
                var confirmationMessage = 'You have unsaved changes in the form builder. Are you sure you want to leave?';
                (e || window.event).returnValue = confirmationMessage;
                return confirmationMessage;
            }
        });
    });

    function switchTab(tabKey) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        // find active tab
        event.currentTarget.classList.add('active');
        document.getElementById('tab-' + tabKey).classList.add('active');
    }

    function toggleAccessMode() {
        var isPublic = document.getElementById('form-public').value;
        document.getElementById('wrap-allowed-emails').style.display = (isPublic == '0') ? 'block' : 'none';
    }

    function onTitleInput() {
        var title = document.getElementById('form-title').value;
        var slugInput = document.getElementById('form-slug');
        if (formId === 0 && !slugInput.value) { // only autogenerate slug for new forms
            var clean = title.toLowerCase().replace(/[^a-z0-9\s\-]/g, '').replace(/\s+/g, '-');
            slugInput.value = clean;
            checkSlugAvailability();
        }
        hasChanges = true;
    }

    function checkSlugAvailability() {
        var slug = document.getElementById('form-slug').value.trim();
        var indicator = document.getElementById('slug-indicator');
        var preview = document.getElementById('preview-slug');
        
        preview.textContent = slug || 'slug';

        if (slug.length === 0) {
            indicator.innerHTML = '';
            slugUnique = false;
            return;
        }

        var fd = new FormData();
        fd.append('action', 'check_slug');
        fd.append('slug', slug);
        fd.append('form_id', formId);

        fetch('api/campaign-forms.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                if (res.unique) {
                    indicator.innerHTML = '<i class="fas fa-circle-check" style="color:#16a34a;"></i>';
                    slugUnique = true;
                } else {
                    indicator.innerHTML = '<i class="fas fa-circle-xmark" style="color:#ef4444;" title="Slug already taken"></i>';
                    slugUnique = false;
                }
                document.getElementById('form-slug').value = res.slug;
            }
        });
        hasChanges = true;
    }

    function renderFields() {
        var workspace = document.getElementById('canvas-workspace');
        workspace.innerHTML = '';

        if (formFields.length === 0) {
            workspace.innerHTML = `
                <div class="canvas-empty">
                    <i class="fab fa-wpforms"></i>
                    <h3 style="font-weight:700; margin-bottom:5px;">This Form has no fields yet.</h3>
                    <p>Click any field type in the right sidebar toolbox to build your form configuration!</p>
                </div>`;
            return;
        }

        formFields.forEach(function(field, idx) {
            var isSelected = (selectedFieldId === field.field_name);
            var selectClass = isSelected ? 'selected' : '';
            
            var div = document.createElement('div');
            div.className = 'field-item ' + selectClass;
            div.draggable = true;
            div.setAttribute('data-index', idx);
            div.setAttribute('data-fieldname', field.field_name);
            
            // Drag events
            div.addEventListener('dragstart', handleDragStart);
            div.addEventListener('dragend', handleDragEnd);

            // Click selects field
            div.addEventListener('click', function(e) {
                if (e.target.closest('.field-actions') || e.target.closest('button')) return;
                selectField(field.field_name);
            });

            var isReqLabel = field.is_required ? '<span style="color:#ef4444;">*</span>' : '';
            var placeholderSnippet = field.placeholder ? ' <span style="opacity:0.6; font-style:italic;">(' + field.placeholder + ')</span>' : '';

            // Layout elements
            div.innerHTML = `
                <div class="field-drag-handle"><i class="fas fa-grip-vertical"></i></div>
                <div class="field-details">
                    <div class="field-title">
                        <span>${escapeHtml(field.label)}${isReqLabel}</span>
                        <span class="field-type-badge">${field.type.replace('_', ' ')}</span>
                    </div>
                    <div class="field-sub">${escapeHtml(field.field_name)}${placeholderSnippet}</div>
                </div>
                <div class="field-actions">
                    <button class="btn btn-sm btn-secondary" style="padding:4px 8px;" onclick="moveField(${idx}, -1)" ${idx === 0 ? 'disabled' : ''}><i class="fas fa-chevron-up"></i></button>
                    <button class="btn btn-sm btn-secondary" style="padding:4px 8px;" onclick="moveField(${idx}, 1)" ${idx === formFields.length - 1 ? 'disabled' : ''}><i class="fas fa-chevron-down"></i></button>
                </div>
            `;
            
            workspace.appendChild(div);
        });
    }

    function addField(type) {
        var num = formFields.filter(f => f.type === type).length + 1;
        var label = type.charAt(0).toUpperCase() + type.slice(1).replace('_', ' ') + ' ' + num;
        var name = type + '_' + Math.random().toString(36).substr(2, 9);

        var newField = {
            id: 0,
            type: type,
            label: label,
            placeholder: '',
            default_value: '',
            field_name: name,
            is_required: false,
            validation_rules: {},
            choices: ['Option 1', 'Option 2', 'Option 3'],
            conditional_logic: {},
            error_message: ''
        };

        formFields.push(newField);
        hasChanges = true;
        renderFields();
        selectField(name);
    }

    function selectField(name) {
        selectedFieldId = name;
        renderFields();
        
        var field = formFields.find(f => f.field_name === name);
        if (!field) {
            document.getElementById('inspector-panel').style.display = 'none';
            return;
        }

        // Show properties inspector panel
        document.getElementById('inspector-panel').style.display = 'block';
        
        // Populate inputs
        document.getElementById('prop-label').value = field.label;
        document.getElementById('prop-placeholder').value = field.placeholder;
        document.getElementById('prop-default').value = field.default_value;
        document.getElementById('prop-required').checked = field.is_required;
        document.getElementById('prop-error-msg').value = field.error_message || '';

        // Handle field specific properties view
        var choicesGroup = document.getElementById('prop-choices-group');
        var numConstraints = document.getElementById('prop-num-constraints');
        var textConstraints = document.getElementById('prop-text-constraints');
        var fileConstraints = document.getElementById('prop-file-constraints');

        choicesGroup.style.display = ['dropdown', 'multiselect', 'checkboxes', 'radio'].includes(field.type) ? 'block' : 'none';
        numConstraints.style.display = (field.type === 'number') ? 'grid' : 'none';
        textConstraints.style.display = ['short_text', 'long_text'].includes(field.type) ? 'block' : 'none';
        fileConstraints.style.display = (field.type === 'file') ? 'block' : 'none';

        // Load choice rows
        if (choicesGroup.style.display === 'block') {
            renderChoicesBuilder(field);
        }

        // Load specific rules
        if (field.type === 'number') {
            document.getElementById('prop-num-min').value = field.validation_rules.min || '';
            document.getElementById('prop-num-max').value = field.validation_rules.max || '';
        } else if (['short_text', 'long_text'].includes(field.type)) {
            document.getElementById('prop-char-limit').value = field.validation_rules.max_chars || '';
        } else if (field.type === 'file') {
            document.getElementById('prop-file-types').value = field.validation_rules.file_types || '';
            document.getElementById('prop-file-size').value = field.validation_rules.max_size || '5';
        }

        // Populate Logic targets list (excl. hidden, section, and current field)
        var logicSelect = document.getElementById('prop-logic-field');
        logicSelect.innerHTML = '<option value="">(No logic conditions)</option>';
        formFields.forEach(f => {
            if (f.field_name !== field.field_name && f.type !== 'section' && f.type !== 'hidden') {
                var sel = (field.conditional_logic.field === f.field_name) ? 'selected' : '';
                logicSelect.innerHTML += `<option value="${f.field_name}" ${sel}>${escapeHtml(f.label)}</option>`;
            }
        });

        // Trigger logic UI show
        updateLogicView(field.conditional_logic);
    }

    function updateActiveField(key, value) {
        var field = formFields.find(f => f.field_name === selectedFieldId);
        if (field) {
            field[key] = value;
            hasChanges = true;
            renderFields();
        }
    }

    function updateValidationRules(ruleKey, value) {
        var field = formFields.find(f => f.field_name === selectedFieldId);
        if (field) {
            if (!field.validation_rules) field.validation_rules = {};
            field.validation_rules[ruleKey] = value;
            hasChanges = true;
        }
    }

    function deleteActiveField() {
        if (!confirm('Are you sure you want to remove this field?')) return;
        formFields = formFields.filter(f => f.field_name !== selectedFieldId);
        selectedFieldId = null;
        hasChanges = true;
        renderFields();
        document.getElementById('inspector-panel').style.display = 'none';
    }

    // Choices stack editor
    function renderChoicesBuilder(field) {
        var container = document.getElementById('choices-container');
        container.innerHTML = '';
        var list = field.choices || [];
        
        list.forEach(function(choice, idx) {
            var div = document.createElement('div');
            div.className = 'choice-builder-row';
            div.innerHTML = `
                <input type="text" class="prop-input" style="flex:1;" value="${escapeHtml(choice)}" oninput="updateChoiceValue(${idx}, this.value)">
                <button class="btn btn-sm btn-secondary" onclick="removeChoiceOption(${idx})"><i class="fas fa-trash-can" style="color:#ef4444;"></i></button>
            `;
            container.appendChild(div);
        });
    }

    function updateChoiceValue(idx, val) {
        var field = formFields.find(f => f.field_name === selectedFieldId);
        if (field) {
            field.choices[idx] = val;
            hasChanges = true;
        }
    }

    function addChoiceOption() {
        var field = formFields.find(f => f.field_name === selectedFieldId);
        if (field) {
            if (!field.choices) field.choices = [];
            field.choices.push('Option ' + (field.choices.length + 1));
            hasChanges = true;
            renderChoicesBuilder(field);
        }
    }

    function removeChoiceOption(idx) {
        var field = formFields.find(f => f.field_name === selectedFieldId);
        if (field && field.choices.length > 1) {
            field.choices.splice(idx, 1);
            hasChanges = true;
            renderChoicesBuilder(field);
        }
    }

    // Conditional logic inspector updates
    function updateLogic(key, value) {
        var field = formFields.find(f => f.field_name === selectedFieldId);
        if (field) {
            if (!field.conditional_logic) field.conditional_logic = {};
            field.conditional_logic[key] = value;
            hasChanges = true;
            updateLogicView(field.conditional_logic);
        }
    }

    function updateLogicView(logic) {
        var wrapper = document.getElementById('prop-logic-details');
        if (logic && logic.field) {
            wrapper.style.display = 'block';
            document.getElementById('prop-logic-operator').value = logic.operator || '=';
            document.getElementById('prop-logic-value').value = logic.value || '';
            document.getElementById('prop-logic-val-wrap').style.display = ['empty', 'not_empty'].includes(logic.operator) ? 'none' : 'block';
        } else {
            wrapper.style.display = 'none';
        }
    }

    // Sorting buttons
    function moveField(idx, dir) {
        var targetIdx = idx + dir;
        if (targetIdx < 0 || targetIdx >= formFields.length) return;
        
        // Swap positions
        var temp = formFields[idx];
        formFields[idx] = formFields[targetIdx];
        formFields[targetIdx] = temp;
        
        hasChanges = true;
        renderFields();
    }

    // Drag-and-drop sort events
    var dragIdx = null;

    function handleDragStart(e) {
        dragIdx = parseInt(this.getAttribute('data-index'));
        this.classList.add('dragging');
    }

    function handleDragEnd(e) {
        this.classList.remove('dragging');
    }

    function allowDrop(e) {
        e.preventDefault();
    }

    function handleDrop(e) {
        e.preventDefault();
        var targetEl = e.target.closest('.field-item');
        if (!targetEl) return;
        
        var dropIdx = parseInt(targetEl.getAttribute('data-index'));
        if (dragIdx === null || dragIdx === dropIdx) return;

        // Reorder array
        var draggedItem = formFields.splice(dragIdx, 1)[0];
        formFields.splice(dropIdx, 0, draggedItem);
        
        hasChanges = true;
        renderFields();
    }

    function toggleWhatsappRedirect() {
        var chk = document.getElementById('form-redirect-whatsapp').checked;
        document.getElementById('wrap-whatsapp-link').style.display = chk ? 'block' : 'none';
        hasChanges = true;
    }

    // Submit payload to REST API
    function saveForm(isAutosave) {
        if (isSaving) return;
        
        var title = document.getElementById('form-title').value.trim();
        var slug = document.getElementById('form-slug').value.trim();
        
        if (title.length === 0) {
            if (!isAutosave) alert('Form Title is required.');
            return;
        }

        if (!slugUnique) {
            if (!isAutosave) alert('Form custom URL slug already exists. Please pick a unique slug.');
            return;
        }

        isSaving = true;
        
        if (isAutosave) {
            document.getElementById('autosave-indicator').style.display = 'inline-block';
            document.getElementById('saved-indicator').style.display = 'none';
        }

        // Build Payload
        var payload = {
            id: formId,
            title: title,
            description: document.getElementById('form-description').value.trim(),
            slug: slug,
            status: document.getElementById('form-status').value,
            is_public: document.getElementById('form-public').value,
            allowed_emails: document.getElementById('form-allowed-emails').value,
            publish_schedule_start: document.getElementById('form-schedule-start').value,
            publish_schedule_end: document.getElementById('form-schedule-end').value,
            limit_per_user: document.getElementById('form-limit-user').value,
            submission_limit: document.getElementById('form-limit-total').value,
            theme: document.getElementById('form-theme').value,
            thank_you_title: document.getElementById('form-thankyou-title').value,
            thank_you_text: document.getElementById('form-thankyou-text').value,
            auto_redirect_whatsapp: document.getElementById('form-redirect-whatsapp').checked ? 1 : 0,
            whatsapp_group_link: document.getElementById('form-whatsapp-link').value.trim(),
            banner_image: document.getElementById('form-banner-image').value.trim(),
            webhook_url: document.getElementById('form-webhook').value,
            enable_captcha: document.getElementById('form-captcha').value,
            notify_emails: document.getElementById('form-notify-emails').value,
            confirmation_email_subject: document.getElementById('form-confirm-subject').value,
            confirmation_email_body: document.getElementById('form-confirm-body').value,
            fields: formFields
        };

        var pass = document.getElementById('form-password').value;
        if (pass) {
            payload.password = pass;
        } else {
            payload.password_kept = true; // flag API to keep existing pw if set
        }

        fetch('api/campaign-forms.php?action=save_form', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: jsonStringifyCustom(payload)
        })
        .then(r => r.json())
        .then(res => {
            isSaving = false;
            document.getElementById('autosave-indicator').style.display = 'none';
            if (res.success) {
                formId = res.id;
                hasChanges = false;
                
                document.getElementById('saved-indicator').style.display = 'inline-block';
                setTimeout(() => {
                    document.getElementById('saved-indicator').style.display = 'none';
                }, 3000);

                if (!isAutosave) {
                    alert(res.message);
                    window.location.href = 'campaign-forms.php';
                }
            } else {
                if (!isAutosave) alert('Failed to save: ' + res.message);
            }
        })
        .catch(err => {
            isSaving = false;
            document.getElementById('autosave-indicator').style.display = 'none';
            if (!isAutosave) alert('Error: ' + err);
        });
    }

    function uploadBannerFile(input) {
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        
        if (file.size > 5 * 1024 * 1024) {
            alert('File size exceeds allowed limit of 5MB.');
            return;
        }

        var fd = new FormData();
        fd.append('action', 'upload_banner');
        fd.append('banner_file', file);

        var lbl = document.getElementById('btn-upload-banner-label');
        var originalHtml = '<i class="fas fa-upload"></i> Upload Image<input type="file" accept="image/*" style="display:none;" onchange="uploadBannerFile(this)">';
        lbl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

        fetch('api/campaign-forms.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            lbl.innerHTML = originalHtml;
            if (res.success) {
                document.getElementById('form-banner-image').value = res.url;
                previewBannerImage(res.url);
                markChanged();
            } else {
                alert(res.message || 'Upload failed');
            }
        })
        .catch(err => {
            lbl.innerHTML = originalHtml;
            alert('Upload failed due to network error.');
        });
    }

    function previewBannerImage(url) {
        var box = document.getElementById('banner-preview-box');
        var img = document.getElementById('banner-preview-img');
        if (url && url.trim().length > 0) {
            img.src = url.trim();
            box.style.display = 'block';
        } else {
            box.style.display = 'none';
        }
    }

    // Helper functions
    function escapeHtml(text) {
        if (!text) return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>'"]/g, function(m) { return map[m]; });
    }

    // JSON.stringify handles stdClass placeholder for empty rules/logic
    function jsonStringifyCustom(obj) {
        return JSON.stringify(obj, function(k, v) {
            if (v && typeof v === 'object' && Object.keys(v).length === 0 && !Array.isArray(v)) {
                return {};
            }
            return v;
        });
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
