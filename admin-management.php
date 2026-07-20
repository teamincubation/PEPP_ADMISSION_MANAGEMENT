<?php
require_once 'includes/auth.php';
require_super_admin();

/* Admin Management - Super Admin only.
   Create admin accounts, grant page/section access (current and future pages
   from the registry in includes/auth.php), activate/deactivate, reset
   passwords and remove accounts. Every change is recorded in the activity
   log. Only the Super Admin can delete data anywhere in the system. */

$success_message = '';
$error_message   = '';

if (!admins_table_exists($pdo)) {
    $active_page = 'admin-management';
    $page_title  = 'Admin Management';
    $page_sub    = '';
    include 'includes/admin_nav.php';
    echo '<div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i><span>The multi-admin system is not installed yet. Please run <strong>database-update-2.sql</strong> once in phpMyAdmin, then reload this page.</span></div>';
    include 'includes/admin_footer.php';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error_message = 'Security token mismatch. Please retry.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_admin') {
                $username = trim($_POST['username'] ?? '');
                $name     = trim($_POST['full_name'] ?? '');
                $email    = trim($_POST['email'] ?? '');
                $gemail   = trim($_POST['google_email'] ?? '');
                $phone    = trim($_POST['phone'] ?? '');
                $password = $_POST['password'] ?? '';
                $perms    = isset($_POST['perm_all'])
                    ? 'ALL'
                    : implode(',', array_intersect(array_keys($GLOBALS['ADMIN_PAGES']), (array)($_POST['perms'] ?? [])));

                if (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $username)) {
                    $error_message = 'Username must be 3-50 characters (letters, numbers, _ . @ -).';
                } elseif (strlen($password) < 8) {
                    $error_message = 'Password must be at least 8 characters.';
                } elseif ($perms === '') {
                    $error_message = 'Grant at least one page, or tick Full access.';
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetchColumn() > 0) {
                        $error_message = "Username \"{$username}\" already exists.";
                    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error_message = 'Please enter a valid email address (or leave it blank).';
                    } else {
                        $scopes = implode(',', (array)($_POST['credential_visibility_scopes'] ?? []));
                        $cred_vis = in_array($_POST['credential_visibility'] ?? 'visible', ['visible', 'hide', 'mask'], true) ? $_POST['credential_visibility'] : 'visible';
                        $stmt = $pdo->prepare("
                            INSERT INTO admins (username, password_hash, full_name, email, google_email, phone, role, permissions, status, credential_visibility, credential_visibility_scopes, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, 'admin', ?, 'active', ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $email ?: null, ($gemail ?: $email) ?: null, $phone ?: null, $perms, $cred_vis, $scopes, $admin_username]);
                        log_admin_activity($pdo, $admin_username, 'admin_created', "Created admin \"{$username}\" with access: {$perms}");
                        $success_message = "Admin \"{$username}\" created.";
                    }
                }
            } elseif ($action === 'update_perms') {
                $id = (int)($_POST['admin_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    $error_message = 'Admin not found.';
                } elseif ($target['role'] === 'super_admin') {
                    $error_message = 'The Super Admin always has full access.';
                } else {
                    $perms = isset($_POST['perm_all'])
                        ? 'ALL'
                        : implode(',', array_intersect(array_keys($GLOBALS['ADMIN_PAGES']), (array)($_POST['perms'] ?? [])));
                    $name  = trim($_POST['full_name'] ?? $target['full_name']);
                    $email = trim($_POST['email'] ?? '');
                    $phone = trim($_POST['phone'] ?? '');
                    if ($perms === '') {
                        $error_message = 'Grant at least one page, or tick Full access.';
                    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error_message = 'Please enter a valid email address (or leave it blank).';
                    } else {
                        $scopes = implode(',', (array)($_POST['credential_visibility_scopes'] ?? []));
                        $cred_vis = in_array($_POST['credential_visibility'] ?? 'visible', ['visible', 'hide', 'mask'], true) ? $_POST['credential_visibility'] : 'visible';
                        $gemail = trim($_POST['google_email'] ?? '');
                        $pdo->prepare("UPDATE admins SET permissions = ?, full_name = ?, email = ?, google_email = ?, phone = ?, credential_visibility = ?, credential_visibility_scopes = ? WHERE id = ?")
                            ->execute([$perms, $name, $email ?: null, ($gemail ?: $email) ?: null, $phone ?: null, $cred_vis, $scopes, $id]);
                        log_admin_activity($pdo, $admin_username, 'permissions_changed', "Access and visibility for \"{$target['username']}\" updated.");
                        $success_message = "Access and visibility updated for {$target['username']}.";
                    }
                }
            } elseif ($action === 'toggle_status') {
                $id = (int)($_POST['admin_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    $error_message = 'Admin not found.';
                } elseif ($target['role'] === 'super_admin') {
                    $error_message = 'The Super Admin account cannot be deactivated.';
                } else {
                    $pdo->prepare("UPDATE admins SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")->execute([$id]);
                    $new = $target['status'] === 'active' ? 'deactivated' : 'activated';
                    log_admin_activity($pdo, $admin_username, 'admin_status_changed', "Admin \"{$target['username']}\" {$new}");
                    $success_message = "Admin \"{$target['username']}\" {$new}." . ($new === 'deactivated' ? ' Their active session ends on their next click.' : '');
                }
            } elseif ($action === 'reset_password') {
                $id = (int)($_POST['admin_id'] ?? 0);
                $new_pass = $_POST['new_password'] ?? '';
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    $error_message = 'Admin not found.';
                } elseif (strlen($new_pass) < 8) {
                    $error_message = 'New password must be at least 8 characters.';
                } else {
                    $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?")
                        ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $id]);
                    log_admin_activity($pdo, $admin_username, 'password_reset', "Password reset for \"{$target['username']}\"");
                    $success_message = "Password reset for {$target['username']}.";
                }
            } elseif ($action === 'delete_admin') {
                $id = (int)($_POST['admin_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) {
                    $error_message = 'Admin not found.';
                } elseif ($target['role'] === 'super_admin') {
                    $error_message = 'The Super Admin account cannot be deleted.';
                } elseif ($target['username'] === $admin_username) {
                    $error_message = 'You cannot delete your own account.';
                } else {
                    $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
                    log_admin_activity($pdo, $admin_username, 'admin_deleted', "Deleted admin account \"{$target['username']}\" (activity history kept)");
                    $success_message = "Admin \"{$target['username']}\" deleted. Their activity history is preserved in the log.";
                }
            }
        } catch (Exception $e) {
            error_log('Admin mgmt: ' . $e->getMessage());
            $error_message = 'Database error while saving changes.';
        }
    }
}

// ── Data ────────────────────────────────────────────────────────
$admins = [];
try {
    $admins = $pdo->query("
        SELECT a.*,
               (SELECT MAX(created_at) FROM admin_activity_log l WHERE l.admin_username = a.username AND l.action_type = 'login') AS last_login_log,
               (SELECT COUNT(*) FROM admin_activity_log l WHERE l.admin_username = a.username) AS activity_count
        FROM admins a
        ORDER BY a.role = 'super_admin' DESC, a.created_at ASC
    ")->fetchAll();
} catch (Exception $e) {
    error_log('Admin list: ' . $e->getMessage());
    $error_message = $error_message ?: 'Could not load admins.';
}

$active_page = 'admin-management';
$page_title  = 'Admin Management';
$page_sub    = 'Accounts, roles & page access - Super Admin only';
include 'includes/admin_nav.php';
?>

<?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-circle-check"></i><span><?php echo e($success_message); ?></span></div><?php endif; ?>
<?php if ($error_message):   ?><div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i><span><?php echo e($error_message); ?></span></div><?php endif; ?>

<div class="alert alert-info">
    <i class="fas fa-shield-halved"></i>
    <span><strong>Role rules:</strong> Admins see only the pages you grant below and are logged out automatically after <strong>20 minutes</strong> of inactivity. Deleting data (students, courses, registrations) is reserved for the Super Admin everywhere in the system. Pages added to the system in the future appear here automatically for granting.</span>
</div>

<!-- ── ADMIN LIST ── -->
<div class="panel">
    <div class="panel-head">
        <span class="head-icon" style="background:var(--red-soft);color:var(--red-ink);"><i class="fas fa-user-shield"></i></span>
        <h2>Admin Accounts (<?php echo count($admins); ?>)</h2>
        <div class="head-right">
            <a class="btn btn-sm btn-outline" href="admin-activity.php"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>
        </div>
    </div>
    <div class="panel-body flush table-wrap">
        <table class="data-table">
            <thead><tr><th>Admin</th><th>Role</th><th>Page Access</th><th>Last Login</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($admins as $a):
                $isSuper = $a['role'] === 'super_admin';
                $isSelf  = $a['username'] === $admin_username;
                $permList = $isSuper || trim((string)$a['permissions']) === 'ALL'
                    ? null
                    : array_intersect(array_map('trim', explode(',', (string)$a['permissions'])), array_keys($GLOBALS['ADMIN_PAGES']));
            ?>
                <tr>
                    <td>
                        <div class="cell-main"><?php echo e($a['username']); ?><?php echo $isSelf ? ' <span class="badge violet">you</span>' : ''; ?></div>
                        <div class="cell-sub"><?php echo e($a['full_name'] ?: '-'); ?> · created by <?php echo e($a['created_by'] ?: '-'); ?></div>
                        <?php if (!empty($a['email']) || !empty($a['phone'])): ?>
                        <div class="cell-sub"><?php echo $a['email'] ? '<i class="fas fa-envelope"></i> ' . e($a['email']) : ''; ?><?php echo (!empty($a['email']) && !empty($a['phone'])) ? ' · ' : ''; ?><?php echo $a['phone'] ? '<i class="fas fa-phone"></i> ' . e($a['phone']) : ''; ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $isSuper ? 'red' : 'blue'; ?>"><?php echo $isSuper ? 'Super Admin' : 'Admin'; ?></span>
                        <?php if (!$isSuper): ?>
                            <div style="margin-top:4px;">
                                <span class="badge <?php echo ($a['credential_visibility'] ?? 'visible') === 'visible' ? 'green' : (($a['credential_visibility'] ?? 'visible') === 'hide' ? 'red' : 'amber'); ?>" style="font-size:0.65rem;">
                                    <?php echo ucfirst($a['credential_visibility'] ?? 'visible'); ?>
                                </span>
                            </div>
                            <?php if (($a['credential_visibility'] ?? 'visible') !== 'visible'): ?>
                            <div style="margin-top:2px; font-size:0.65rem; color:var(--text-muted);">
                                Scopes: <?php echo !empty($a['credential_visibility_scopes']) ? htmlspecialchars(str_replace(',', ', ', $a['credential_visibility_scopes'])) : 'None'; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:280px;">
                        <?php if ($permList === null): ?>
                            <span class="badge green">Full access</span>
                        <?php elseif (empty($permList)): ?>
                            <span class="badge gray">No pages</span>
                        <?php else: ?>
                            <?php foreach ($permList as $p): ?>
                                <span class="badge gray" style="margin:1px 2px;"><?php echo e($GLOBALS['ADMIN_PAGES'][$p][0]); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub">
                        <?php $ll = $a['last_login_log'] ?: $a['last_login_at'];
                        echo $ll ? date('d M Y, h:i A', strtotime($ll)) : 'Never'; ?>
                        <div><a href="admin-activity.php?admin=<?php echo urlencode($a['username']); ?>" style="font-size:.72rem;"><?php echo (int)$a['activity_count']; ?> activity records</a></div>
                    </td>
                    <td><span class="badge <?php echo $a['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                    <td style="text-align:right; white-space:nowrap;">
                        <?php if (!$isSuper): ?>
                            <button class="btn btn-sm btn-outline" title="Edit access & details" onclick='openPerms(<?php echo json_encode([
                                "id" => (int)$a["id"], "username" => $a["username"], "name" => (string)$a["full_name"],
                                "email" => (string)($a["email"] ?? ""), "phone" => (string)($a["phone"] ?? ""), "gemail" => (string)($a["google_email"] ?? ""),
                                "perms" => trim((string)$a["permissions"]),
                                "credential_visibility" => (string)($a["credential_visibility"] ?? "visible"),
                                "credential_visibility_scopes" => (string)($a["credential_visibility_scopes"] ?? ""),
                            ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fas fa-key"></i></button>
                            <button class="btn btn-sm btn-soft-blue" title="Reset password" onclick="resetPassword(<?php echo (int)$a['id']; ?>, '<?php echo e(addslashes($a['username'])); ?>')"><i class="fas fa-lock-open"></i></button>
                            <form method="POST" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="admin_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $a['status'] === 'active' ? 'btn-soft-amber' : 'btn-soft-green'; ?>" title="<?php echo $a['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>"><i class="fas fa-power-off"></i></button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete admin <?php echo e(addslashes($a['username'])); ?>? Their activity history will be kept.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_admin">
                                <input type="hidden" name="admin_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-red" title="Delete admin"><i class="fas fa-trash"></i></button>
                            </form>
                        <?php else: ?>
                            <span class="cell-sub">Manage in <a href="settings.php">Settings</a></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── ADD ADMIN ── -->
<div class="panel">
    <div class="panel-head"><span class="head-icon"><i class="fas fa-user-plus"></i></span><h2>Create Admin Account</h2></div>
    <div class="panel-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_admin">
            <div class="form-grid">
                <div class="field"><label>Username <span class="req">*</span></label>
                    <input type="text" name="username" required pattern="[A-Za-z0-9_.@-]{3,50}" placeholder="e.g. office.staff"></div>
                <div class="field"><label>Full name</label>
                    <input type="text" name="full_name" placeholder="Display name"></div>
                <div class="field"><label>Email</label>
                    <input type="email" name="email" placeholder="admin@example.com">
                    <div class="help">Used for reminders &amp; Google sign-in</div></div>
                <div class="field"><label>Google sign-in email</label>
                    <input type="email" name="google_email" placeholder="(defaults to email above)">
                    <div class="help">The Google account allowed to sign in as this admin</div></div>
                <div class="field"><label>Phone</label>
                    <input type="text" name="phone" placeholder="Mobile number"></div>
                <div class="field"><label>Credential Visibility</label>
                    <select name="credential_visibility" required>
                        <option value="visible">Visible</option>
                        <option value="hide">Hide</option>
                        <option value="mask">Mask</option>
                    </select>
                </div>
                <div class="field" style="grid-column: span 2; margin-top:-8px; margin-bottom:12px;">
                    <label style="margin-bottom:6px; display:block;">Credential Visibility Scopes</label>
                    <div style="display:flex; gap:16px; flex-wrap:wrap; background:#fafaf9; border:1px solid #e7e5e4; padding:8px 12px; border-radius:8px;">
                        <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                            <input type="checkbox" name="credential_visibility_scopes[]" value="students" style="width:16px; height:16px; accent-color:var(--accent);" checked> Students
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                            <input type="checkbox" name="credential_visibility_scopes[]" value="alumni" style="width:16px; height:16px; accent-color:var(--accent);"> Alumni Data
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                            <input type="checkbox" name="credential_visibility_scopes[]" value="faculties" style="width:16px; height:16px; accent-color:var(--accent);"> Faculties
                        </label>
                        <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                            <input type="checkbox" name="credential_visibility_scopes[]" value="leads" style="width:16px; height:16px; accent-color:var(--accent);"> Leads
                        </label>
                    </div>
                </div>
                <div class="field"><label>Password <span class="req">*</span></label>
                    <input type="password" name="password" required minlength="8" autocomplete="new-password">
                    <div class="help">Minimum 8 characters - share it securely</div></div>
            </div>
            <div class="field" style="margin-top:16px;">
                <label>Page access <span class="req">*</span></label>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:.84rem;font-weight:700;background:var(--green-soft);color:var(--green-ink);border-radius:50px;padding:7px 16px;cursor:pointer;text-transform:none;letter-spacing:0;margin-bottom:10px;">
                    <input type="checkbox" name="perm_all" value="1" onchange="toggleAll(this, 'new-perms')" style="width:16px;height:16px;accent-color:var(--green-ink);">
                    Full access (every current &amp; future page)
                </label>
                <div id="new-perms" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php foreach ($GLOBALS['ADMIN_PAGES'] as $key => [$label, $icon]): ?>
                        <label style="display:inline-flex;align-items:center;gap:7px;font-size:.8rem;font-weight:600;background:var(--card);border-radius:50px;padding:7px 14px;cursor:pointer;text-transform:none;letter-spacing:0;color:var(--foreground);">
                            <input type="checkbox" name="perms[]" value="<?php echo e($key); ?>" style="width:15px;height:15px;accent-color:var(--accent);">
                            <i class="fas <?php echo e($icon); ?>" style="color:var(--secondary);font-size:.75rem;"></i> <?php echo e($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:16px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Create Admin</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT PERMISSIONS MODAL ── -->
<div class="modal-backdrop" id="perms-modal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-head">
            <h3><i class="fas fa-key" style="color:var(--accent);"></i> Page Access</h3>
            <button class="modal-close" onclick="closeModal('perms-modal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_perms">
            <input type="hidden" name="admin_id" id="pm-id">
            <div class="modal-body">
                <p id="pm-username" style="font-weight:700; margin-bottom:12px;"></p>
                <div class="form-grid" style="margin-bottom:12px;">
                    <div class="field"><label>Full name</label><input type="text" name="full_name" id="pm-name"></div>
                    <div class="field"><label>Email</label><input type="email" name="email" id="pm-email" placeholder="admin@example.com"></div>
                    <div class="field"><label>Phone</label><input type="text" name="phone" id="pm-phone" placeholder="Mobile number"></div>
                    <div class="field"><label>Google sign-in email</label><input type="email" name="google_email" id="pm-gemail" placeholder="(defaults to email)"></div>
                    <div class="field"><label>Credential Visibility</label>
                        <select name="credential_visibility" id="pm-cred-visibility">
                            <option value="visible">Visible</option>
                            <option value="hide">Hide</option>
                            <option value="mask">Mask</option>
                        </select>
                    </div>
                    <div class="field" style="grid-column: span 2; margin-top:-4px; margin-bottom:6px;">
                        <label style="margin-bottom:6px; display:block;">Credential Visibility Scopes</label>
                        <div style="display:flex; gap:16px; flex-wrap:wrap; background:#fafaf9; border:1px solid #e7e5e4; padding:8px 12px; border-radius:8px;">
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="students" class="pm-scope" data-scope="students" style="width:16px; height:16px; accent-color:var(--accent);"> Students
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="alumni" class="pm-scope" data-scope="alumni" style="width:16px; height:16px; accent-color:var(--accent);"> Alumni Data
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="faculties" class="pm-scope" data-scope="faculties" style="width:16px; height:16px; accent-color:var(--accent);"> Faculties
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="credential_visibility_scopes[]" value="leads" class="pm-scope" data-scope="leads" style="width:16px; height:16px; accent-color:var(--accent);"> Leads
                            </label>
                        </div>
                    </div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:.84rem;font-weight:700;background:var(--green-soft);color:var(--green-ink);border-radius:50px;padding:7px 16px;cursor:pointer;margin-bottom:10px;">
                    <input type="checkbox" name="perm_all" value="1" id="pm-all" onchange="toggleAll(this, 'pm-perms')" style="width:16px;height:16px;accent-color:var(--green-ink);">
                    Full access (every current &amp; future page)
                </label>
                <div id="pm-perms" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php foreach ($GLOBALS['ADMIN_PAGES'] as $key => [$label, $icon]): ?>
                        <label style="display:inline-flex;align-items:center;gap:7px;font-size:.8rem;font-weight:600;background:var(--card);border-radius:50px;padding:7px 14px;cursor:pointer;color:var(--foreground);">
                            <input type="checkbox" name="perms[]" value="<?php echo e($key); ?>" class="pm-perm" data-key="<?php echo e($key); ?>" style="width:15px;height:15px;accent-color:var(--accent);">
                            <i class="fas <?php echo e($icon); ?>" style="color:var(--secondary);font-size:.75rem;"></i> <?php echo e($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('perms-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Access</button>
            </div>
        </form>
    </div>
</div>

<!-- ── RESET PASSWORD (hidden form) ── -->
<form method="POST" id="reset-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="admin_id" id="rp-id">
    <input type="hidden" name="new_password" id="rp-pass">
</form>

<?php
$extra_scripts = "<script>
function toggleAll(cb, boxId) {
    document.querySelectorAll('#' + boxId + ' input[type=checkbox]').forEach(c => { c.disabled = cb.checked; if (cb.checked) c.checked = false; });
}
function openPerms(a) {
    document.getElementById('pm-id').value = a.id;
    document.getElementById('pm-name').value = a.name || '';
    document.getElementById('pm-email').value = a.email || '';
    document.getElementById('pm-phone').value = a.phone || '';
    document.getElementById('pm-gemail').value = a.gemail || '';
    document.getElementById('pm-cred-visibility').value = a.credential_visibility || 'visible';
    const scopes = a.credential_visibility_scopes ? a.credential_visibility_scopes.split(',').map(s => s.trim()) : [];
    document.querySelectorAll('.pm-scope').forEach(c => {
        c.checked = scopes.includes(c.dataset.scope);
    });
    document.getElementById('pm-username').textContent = a.username;
    const isAll = (a.perms === 'ALL');
    document.getElementById('pm-all').checked = isAll;
    const granted = isAll ? [] : a.perms.split(',').map(s => s.trim());
    document.querySelectorAll('.pm-perm').forEach(c => {
        c.checked = granted.includes(c.dataset.key);
        c.disabled = isAll;
    });
    openModal('perms-modal');
}
function resetPassword(id, username) {
    const p = prompt('New password for ' + username + ' (min 8 characters):');
    if (p === null) return;
    if (p.length < 8) { alert('Password must be at least 8 characters.'); return; }
    document.getElementById('rp-id').value = id;
    document.getElementById('rp-pass').value = p;
    document.getElementById('reset-form').submit();
}
</script>";
include 'includes/admin_footer.php';
?>
