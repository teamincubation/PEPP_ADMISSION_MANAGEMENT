<?php
/**
 * PEPP Updates - Settings Management
 * 
 * Manage configurable settings stored in updates_settings table.
 * Supports configurable NEW label badge duration days (1-90 days).
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates - Settings';
$page_sub = 'Configure update visibility, NEW badge duration, and module parameters';
$active_page = 'pepp-updates-settings';

$flash_success = '';
$flash_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF).';
    } else {
        $duration_input = trim($_POST['new_label_duration_days'] ?? '');
        $wa_section_input = trim($_POST['whatsapp_updates_section_enabled'] ?? '1');
        $wa_section_val = ($wa_section_input === '0') ? '0' : '1';
        $auto_convert_input = trim($_POST['ad_leads_auto_convert_enabled'] ?? '0');
        $auto_convert_val = ($auto_convert_input === '1') ? '1' : '0';

        if (!ctype_digit($duration_input)) {
            $flash_error = 'NEW badge duration must be a valid whole integer.';
        } else {
            $duration_val = (int)$duration_input;
            if ($duration_val < 1 || $duration_val > 90) {
                $flash_error = 'NEW badge duration must be between 1 and 90 days.';
            } else {
                $saved1 = pepp_updates_set_setting(
                    $pdo,
                    'new_label_duration_days',
                    $duration_val,
                    'Duration in days for an update to display the NEW badge',
                    $admin_username
                );
                $saved2 = pepp_updates_set_setting(
                    $pdo,
                    'whatsapp_updates_section_enabled',
                    $wa_section_val,
                    'Toggle visibility of public WhatsApp Updates subscription section (1=ON, 0=OFF)',
                    $admin_username
                );
                $saved3 = pepp_updates_set_setting(
                    $pdo,
                    'ad_leads_auto_convert_enabled',
                    $auto_convert_val,
                    'Global toggle for auto-converting Ad Leads into CRM Leads (0=OFF, 1=ON)',
                    $admin_username
                );

                if ($saved1 && $saved2 && $saved3) {
                    $wa_label = ($wa_section_val === '1') ? 'ON' : 'OFF';
                    $ac_label = ($auto_convert_val === '1') ? 'ON' : 'OFF';
                    $flash_success = "Settings updated successfully. NEW badge: {$duration_val} days | WhatsApp Section: {$wa_label} | Global Auto-Convert: {$ac_label}.";
                } else {
                    $flash_error = 'Failed to update settings in database.';
                }
            }
        }
    }
}

// Fetch current setting value
$current_duration     = (int)pepp_updates_get_setting($pdo, 'new_label_duration_days', 7);
$current_wa_section   = (string)pepp_updates_get_setting($pdo, 'whatsapp_updates_section_enabled', '1');
$current_auto_convert = (string)pepp_updates_get_setting($pdo, 'ad_leads_auto_convert_enabled', '0');

// Fetch all settings records for display
$all_settings = [];
if (pepp_updates_tables_exist($pdo)) {
    try {
        $stmt = $pdo->query("SELECT * FROM updates_settings ORDER BY setting_key ASC");
        $all_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

include 'includes/admin_nav.php';
?>

<?php if ($flash_success): ?>
    <div class="alert alert-success">
        <i class="fas fa-circle-check"></i>
        <span><?php echo htmlspecialchars($flash_success); ?></span>
    </div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-circle-exclamation"></i>
        <span><?php echo htmlspecialchars($flash_error); ?></span>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 20px; align-items: flex-start;">

    <!-- Left: Settings Form -->
    <div class="panel">
        <div class="panel-head">
            <div class="head-icon"><i class="fas fa-sliders"></i></div>
            <h2>Module Configuration</h2>
        </div>
        <div class="panel-body">
            <form method="POST" action="pepp-updates-settings.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="save_settings" value="1">

                <div class="field" style="margin-bottom: 20px;">
                    <label>NEW Badge Visibility Duration (Days) <span class="req">*</span></label>
                    <input type="number" name="new_label_duration_days" 
                           value="<?php echo (int)$current_duration; ?>" 
                           min="1" max="90" required 
                           style="max-width: 180px; font-weight: 700; font-size: 1.1rem;">
                    <div class="help" style="margin-top: 6px;">
                        Specifies how many days after publication an update displays the vibrant <span class="badge violet" style="font-size: 0.65rem; padding: 2px 6px;">NEW</span> badge on admin and public portals.
                        <br>Sensible limits: 1 to 90 days. Default is 7 days.
                        <br><strong>Note:</strong> Expired updates will never display the NEW badge regardless of duration.
                    </div>
                </div>

                <div class="field" style="margin-bottom: 24px; padding-top: 16px; border-top: 1px solid var(--border);">
                    <label>Public "Get WhatsApp Updates" Subscription Section <span class="req">*</span></label>
                    <div style="display: flex; gap: 20px; align-items: center; margin-top: 8px;">
                        <label style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="radio" name="whatsapp_updates_section_enabled" value="1" <?php echo ($current_wa_section !== '0') ? 'checked' : ''; ?>>
                            <span class="badge green" style="font-size: 0.8rem; padding: 4px 10px;"><i class="fas fa-eye"></i> ON (Visible)</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="radio" name="whatsapp_updates_section_enabled" value="0" <?php echo ($current_wa_section === '0') ? 'checked' : ''; ?>>
                            <span class="badge red" style="font-size: 0.8rem; padding: 4px 10px;"><i class="fas fa-eye-slash"></i> OFF (Hidden)</span>
                        </label>
                    </div>
                    <div class="help" style="margin-top: 8px;">
                        Controls visibility of the "Get WhatsApp Updates" subscription section across the public portal (<code>updates.pepplearning.in</code>).
                        <br>&bull; <strong>ON (Default):</strong> Shows homepage banner CTA, header navigation buttons, and footer subscription link.
                        <br>&bull; <strong>OFF:</strong> Hides the section completely on all public pages and pauses new web opt-ins.
                        <br><strong>Note:</strong> Toggling OFF does not delete subscribers or alter WhatsApp account credentials, templates, or broadcast routing.
                    </div>
                </div>

                <!-- 3. Global Ad Leads Auto-Convert Toggle -->
                <div class="form-group" style="margin-bottom: 22px;">
                    <label style="font-weight: 700; display: block; margin-bottom: 6px;">
                        <i class="fas fa-robot"></i> Automatic CRM Lead Conversion (Global Master Toggle)
                    </label>
                    <div style="display: flex; gap: 20px; align-items: center; margin-top: 6px;">
                        <label style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="radio" name="ad_leads_auto_convert_enabled" value="0" <?php echo ($current_auto_convert !== '1') ? 'checked' : ''; ?>>
                            <span class="badge gray" style="font-size: 0.8rem; padding: 4px 10px;"><i class="fas fa-hand"></i> OFF / Manual Review Only (Default)</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="radio" name="ad_leads_auto_convert_enabled" value="1" <?php echo ($current_auto_convert === '1') ? 'checked' : ''; ?>>
                            <span class="badge green" style="font-size: 0.8rem; padding: 4px 10px;"><i class="fas fa-bolt"></i> ON (Auto-Convert Enabled)</span>
                        </label>
                    </div>
                    <div class="help" style="margin-top: 8px;">
                        Global safety master switch for automatically converting submitted popup ad leads into ERP CRM leads.
                        <br>&bull; <strong>Strict Precedence:</strong> Automatic conversion is allowed <em>only</em> when both this Global setting <strong>AND</strong> the specific Ad's auto-convert setting are <strong>ON</strong>.
                        <br>&bull; <strong>Default is OFF:</strong> Leads are stored as 'new' in <a href="pepp-updates-ad-leads.php" style="color:var(--primary);font-weight:600;">Ad Leads</a> for administrative review and manual conversion.
                    </div>
                </div>

                <div style="padding-top: 14px; border-top: 1px solid var(--border);">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-floppy-disk"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Right: Module Architecture & Account Parameters (Read-Only) -->
    <div style="display: flex; flex-direction: column; gap: 18px;">

        <div class="panel">
            <div class="panel-head">
                <div class="head-icon"><i class="fas fa-lock"></i></div>
                <h2>Account 3 Isolation Parameters</h2>
            </div>
            <div class="panel-body" style="font-size: 0.85rem;">
                <p style="color: var(--secondary); margin-bottom: 14px;">
                    PEPP Updates is strictly coupled to <strong>Account 3</strong> for WhatsApp broadcasts.
                </p>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 6px;">
                        <span style="color: var(--secondary);">ERP Account ID:</span>
                        <code>3</code>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 6px;">
                        <span style="color: var(--secondary);">WhatsApp Phone:</span>
                        <code>+91 79943 04400</code>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 6px;">
                        <span style="color: var(--secondary);">WABA ID:</span>
                        <code>1099020233033644</code>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border); padding-bottom: 6px;">
                        <span style="color: var(--secondary);">Phone ID:</span>
                        <code>1293652117171674</code>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--secondary);">Public Portal Domain:</span>
                        <code>updates.pepplearning.in</code>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div class="head-icon"><i class="fas fa-database"></i></div>
                <h2>Raw Settings Registry</h2>
            </div>
            <div class="panel-body flush">
                <?php if (empty($all_settings)): ?>
                    <div style="padding: 16px; color: var(--muted-foreground); text-align: center;">No settings stored.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Value</th>
                                    <th>Last Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_settings as $s): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($s['setting_key']); ?></code></td>
                                        <td><strong><?php echo htmlspecialchars($s['setting_value']); ?></strong></td>
                                        <td><span class="cell-sub"><?php echo !empty($s['updated_at']) ? date('d M Y', strtotime($s['updated_at'])) : '-'; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<?php
include 'includes/admin_footer.php';
?>
