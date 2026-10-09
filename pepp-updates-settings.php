<?php
/**
 * PEPP Updates — Settings Management
 * 
 * Manage configurable settings stored in updates_settings table.
 * Supports configurable NEW label badge duration days (1-90 days).
 */

require_once 'includes/auth.php';
require_permission('pepp-updates');
require_once 'includes/pepp_updates_helper.php';

$page_title = 'PEPP Updates — Settings';
$page_sub = 'Configure update visibility, NEW badge duration, and module parameters';
$active_page = 'pepp-updates-settings';

$flash_success = '';
$flash_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!csrf_verify()) {
        $flash_error = 'Invalid security token (CSRF).';
    } else {
        $duration_input = trim($_POST['new_label_duration_days'] ?? '');

        if (!ctype_digit($duration_input)) {
            $flash_error = 'NEW badge duration must be a valid whole integer.';
        } else {
            $duration_val = (int)$duration_input;
            if ($duration_val < 1 || $duration_val > 90) {
                $flash_error = 'NEW badge duration must be between 1 and 90 days.';
            } else {
                $saved = pepp_updates_set_setting(
                    $pdo,
                    'new_label_duration_days',
                    $duration_val,
                    'Duration in days for an update to display the NEW badge',
                    $admin_username
                );

                if ($saved) {
                    $flash_success = "Settings updated successfully. NEW badge duration set to {$duration_val} days.";
                } else {
                    $flash_error = 'Failed to update settings in database.';
                }
            }
        }
    }
}

// Fetch current setting value
$current_duration = (int)pepp_updates_get_setting($pdo, 'new_label_duration_days', 7);

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
                                        <td><span class="cell-sub"><?php echo !empty($s['updated_at']) ? date('d M Y', strtotime($s['updated_at'])) : '—'; ?></span></td>
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
