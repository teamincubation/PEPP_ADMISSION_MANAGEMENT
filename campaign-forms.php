<?php
require_once 'includes/auth.php';
require_permission('campaigns');
require_once 'config/database.php';

$active_page = 'campaigns';
$page_title  = 'Campaign Forms';
$page_sub    = 'Build, manage, and analyze custom data collection forms and landing pages';

// Statistics
$total_forms = 0;
$total_views = 0;
$total_submissions = 0;
$overall_conversion = 0.0;

try {
    $total_forms = (int)$pdo->query("SELECT COUNT(*) FROM campaign_forms")->fetchColumn();
    $total_views = (int)$pdo->query("SELECT COUNT(*) FROM campaign_form_analytics")->fetchColumn();
    $total_submissions = (int)$pdo->query("SELECT COUNT(*) FROM campaign_form_submissions")->fetchColumn();
    
    if ($total_views > 0) {
        $overall_conversion = round(($total_submissions / $total_views) * 100, 2);
    }
} catch (Exception $e) {
    error_log("Campaign stats error: " . $e->getMessage());
}

// Listing with search & filters
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$sql = "SELECT f.*, 
               (SELECT COUNT(*) FROM campaign_form_analytics WHERE form_id = f.id) as views,
               (SELECT COUNT(*) FROM campaign_form_submissions WHERE form_id = f.id) as submissions
        FROM campaign_forms f 
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (f.title LIKE ? OR f.description LIKE ? OR f.slug LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($status_filter)) {
    $sql .= " AND f.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY f.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $forms = $stmt->fetchAll();
} catch (Exception $e) {
    $forms = [];
    $error_msg = "Failed to load forms: " . $e->getMessage();
}

$admins_with_campaigns = [];
if (is_super_admin()) {
    try {
        $stmt_adms = $pdo->query("SELECT id, username, full_name, role, permissions FROM admins WHERE status = 'active' ORDER BY username ASC");
        $all_adms = $stmt_adms->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all_adms as $adm) {
            if ($adm['role'] === 'super_admin') continue;
            $perms = array_map('trim', explode(',', $adm['permissions']));
            if ($adm['permissions'] === 'ALL' || in_array('campaigns', $perms, true)) {
                $admins_with_campaigns[] = $adm;
            }
        }
    } catch (Exception $e) {}
}

include 'includes/admin_nav.php';
?>

<?php if (isset($error_msg)): ?>
    <div class="alert alert-warn"><i class="fas fa-triangle-exclamation"></i> <span><?php echo htmlspecialchars($error_msg); ?></span></div>
<?php endif; ?>

<!-- ── CENTRALIZED FORM STATS ── -->
<div class="stats-grid" style="margin-bottom: 2rem;">
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Total Forms Created</span>
            <span class="stat-icon violet"><i class="fab fa-wpforms"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($total_forms); ?></div>
        <div class="stat-hint">Active marketing forms</div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Total Views / Traffic</span>
            <span class="stat-icon blue"><i class="fas fa-eye"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($total_views); ?></div>
        <div class="stat-hint">Total form visits</div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Total Submissions</span>
            <span class="stat-icon green"><i class="fas fa-paper-plane"></i></span>
        </div>
        <div class="stat-value"><?php echo number_format($total_submissions); ?></div>
        <div class="stat-hint">Responses collected</div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Overall Conversion Rate</span>
            <span class="stat-icon amber"><i class="fas fa-chart-line"></i></span>
        </div>
        <div class="stat-value"><?php echo $overall_conversion; ?>%</div>
        <div class="stat-hint">Submissions per visitor</div>
    </div>
</div>

<!-- ── CONTROL BAR ── -->
<div class="action-bar" style="background:var(--card-bg); border:1px solid var(--border); padding:1.2rem; border-radius:18px; margin-bottom:1.5rem; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px;">
    <form method="GET" action="" style="display:flex; gap:10px; flex:1; max-width:600px; align-items:center;">
        <div style="position:relative; flex:1;">
            <i class="fas fa-search" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.85rem;"></i>
            <input type="text" name="search" placeholder="Search forms by title, description or slug..." class="form-input" style="margin-bottom:0; padding-left:38px; border-radius:12px;" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <select name="status" class="form-input" style="margin-bottom:0; width:160px; border-radius:12px; font-weight:600;" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
            <option value="published" <?php echo $status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
            <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
        </select>
        
        <button type="submit" class="btn btn-primary" style="padding:0.6rem 1.2rem; border-radius:12px;"><i class="fas fa-filter"></i></button>

        <?php if (!empty($search) || !empty($status_filter)): ?>
            <a href="campaign-forms.php" class="btn btn-secondary" style="padding:0.6rem 1rem; border-radius:12px;" title="Reset Filter"><i class="fas fa-xmark"></i></a>
        <?php endif; ?>
    </form>
    
    <?php if (can_access('campaign-form-edit')): ?>
        <a href="campaign-form-builder.php" class="btn btn-primary" style="padding:0.75rem 1.5rem; border-radius:12px; font-weight:700;"><i class="fas fa-plus"></i> Create Custom Form</a>
    <?php endif; ?>
</div>

<!-- ── FORMS LISTING ── -->
<div class="card" style="padding:0; overflow:visible;">
    <table class="data-table" style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="border-bottom:1px solid var(--border); text-align:left;">
                <th style="padding:15px; font-weight:700;">Form Details</th>
                <th style="padding:15px; font-weight:700;">Clean Custom URL</th>
                <th style="padding:15px; font-weight:700; text-align:center;">Views</th>
                <th style="padding:15px; font-weight:700; text-align:center;">Submissions</th>
                <th style="padding:15px; font-weight:700; text-align:center;">Conversion</th>
                <th style="padding:15px; font-weight:700;">Status</th>
                <th style="padding:15px; font-weight:700; text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($forms)): ?>
                <tr>
                    <td colspan="7" style="padding:40px; text-align:center; color:var(--text-muted);">
                        <i class="fab fa-wpforms" style="font-size:3rem; margin-bottom:12px; display:block;"></i>
                        <p>No campaign forms found. Click "Create Custom Form" to build one!</p>
                    </td>
                </tr>
            <?php else: foreach ($forms as $f): 
                $conv = $f['views'] > 0 ? round(($f['submissions'] / $f['views']) * 100, 1) : 0;
                $status_class = $f['status'] === 'published' ? 'badge green' : ($f['status'] === 'archived' ? 'badge red' : 'badge amber');
                $public_url = "f.php?s=" . $f['slug'];

                $has_access = has_form_access($pdo, $admin_username, $f['id']);
                $current_access_ids = [];
                if (is_super_admin()) {
                    try {
                        $stmt_access = $pdo->prepare("SELECT admin_user_id FROM campaign_form_admin_access WHERE form_id = ?");
                        $stmt_access->execute([$f['id']]);
                        $current_access_ids = $stmt_access->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Exception $e) {}
                }
            ?>
                <tr style="border-bottom:1px solid var(--border); transition:background 0.2s;" class="table-row">
                    <td style="padding:15px; max-width:240px;">
                        <div style="font-weight:700; font-size:0.95rem; margin-bottom:2px;">
                            <?php echo htmlspecialchars($f['title']); ?>
                            <?php if (!$has_access): ?> <i class="fas fa-lock" style="color:#ef4444; font-size:0.8rem; margin-left:4px;" title="Access Restricted"></i><?php endif; ?>
                        </div>
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:6px;">
                            Created: <?php echo date('d M Y', strtotime($f['created_at'])); ?> by <?php echo htmlspecialchars($f['created_by']); ?>
                        </div>
                        <?php if (is_super_admin()): ?>
                            <!-- Form Access Dropdown -->
                            <div class="form-access-container" style="position: relative; margin-top: 8px;">
                                <div class="dropdown" style="position: relative; display: inline-block; width: 100%; max-width: 220px;">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" onclick="toggleAccessDropdown(<?php echo (int)$f['id']; ?>)" style="width: 100%; display: flex; justify-content: space-between; align-items: center; padding: 4px 8px; font-size: 0.75rem; text-align: left; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer; color: #1e293b; font-weight: 500;">
                                        <span><?php echo count($current_access_ids); ?> Admins Selected</span>
                                        <i class="fas fa-chevron-down" style="font-size: 0.65rem; color: #64748b; margin-left: 6px;"></i>
                                    </button>
                                    <div id="access-dropdown-<?php echo (int)$f['id']; ?>" class="dropdown-menu" style="display: none; position: absolute; top: 100%; left: 0; z-index: 1000; min-width: 250px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin-top: 4px; padding: 10px; box-sizing: border-box; text-align: left;">
                                        <!-- Search Box -->
                                        <div style="margin-bottom: 8px;">
                                            <input type="text" placeholder="Search admins..." oninput="filterAdmins(this)" style="width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.75rem; box-sizing: border-box; outline: none;">
                                        </div>
                                        <!-- Select All -->
                                        <label style="display: flex; align-items: center; gap: 8px; padding: 4px; font-size: 0.75rem; font-weight: 600; color: #1e293b; cursor: pointer; border-bottom: 1px solid #f1f5f9; margin-bottom: 4px;">
                                            <input type="checkbox" class="select-all-cb" onchange="toggleSelectAll(this)" style="width: 14px; height: 14px; accent-color: var(--accent); cursor: pointer;">
                                            <span>Select All</span>
                                        </label>
                                        <!-- Admins List -->
                                        <div class="admins-list" style="max-height: 120px; overflow-y: auto; margin-bottom: 8px; display: flex; flex-direction: column; gap: 2px;">
                                            <?php foreach ($admins_with_campaigns as $adm):
                                                $checked = in_array($adm['id'], $current_access_ids) ? 'checked' : '';
                                                $displayName = ($adm['full_name'] ?: $adm['username']) . ' (' . $adm['username'] . ')';
                                            ?>
                                                <label class="admin-item" style="display: flex; align-items: center; gap: 8px; padding: 4px; font-size: 0.75rem; color: #334155; cursor: pointer; user-select: none; border-radius: 4px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                                    <input type="checkbox" name="admin_ids[]" value="<?php echo (int)$adm['id']; ?>" <?php echo $checked; ?> onchange="updateSelectedCount(this)" style="width: 14px; height: 14px; accent-color: var(--accent); cursor: pointer;">
                                                    <span><?php echo htmlspecialchars($displayName); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php if (empty($admins_with_campaigns)): ?>
                                                <div style="padding: 4px; font-size: 0.75rem; color: #94a3b8; font-style: italic;">No campaign-enabled admins</div>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Footer -->
                                        <div style="border-top: 1px solid #f1f5f9; padding-top: 8px; display: flex; justify-content: space-between; align-items: center;">
                                            <span class="selected-count" style="font-size: 0.7rem; color: #64748b; font-weight: 500;">0 selected</span>
                                            <div style="display: flex; gap: 6px;">
                                                <button type="button" onclick="closeAccessDropdown(<?php echo (int)$f['id']; ?>)" style="padding: 4px 8px; font-size: 0.7rem; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; color: #475569;">Cancel</button>
                                                <button type="button" onclick="saveFormAccessAjax(<?php echo (int)$f['id']; ?>)" style="padding: 4px 10px; font-size: 0.7rem; border-radius: 6px; background: var(--accent); color: #fff; border: none; cursor: pointer; font-weight: 600;">Save</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif (!$has_access): ?>
                            <div style="color:#ef4444; font-size:0.72rem; font-weight:700; margin-top:4px; display:flex; align-items:center; gap:4px;">
                                <i class="fas fa-lock"></i>
                                <span>Access Restricted</span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:15px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <?php if ($has_access): ?>
                                <a href="<?php echo $public_url; ?>" target="_blank" style="font-family:monospace; font-size:0.85rem; color:var(--accent); text-decoration:none;">
                                    /<?php echo htmlspecialchars($f['slug']); ?>
                                </a>
                                <button class="btn btn-sm btn-soft-blue" title="Copy clean link" onclick="copyLink('<?php echo $_SERVER['HTTP_HOST'] . '/admissions/' . $public_url; ?>', this)">
                                    <i class="fas fa-copy"></i>
                                </button>
                            <?php else: ?>
                                <span style="font-family:monospace; font-size:0.85rem; color:var(--text-muted);">/<?php echo htmlspecialchars($f['slug']); ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="padding:15px; text-align:center; font-weight:600;"><?php echo number_format($f['views']); ?></td>
                    <td style="padding:15px; text-align:center; font-weight:600;"><?php echo number_format($f['submissions']); ?></td>
                    <td style="padding:15px; text-align:center; font-weight:600; color:var(--accent);"><?php echo $conv; ?>%</td>
                    <td style="padding:15px;">
                        <span class="<?php echo $status_class; ?>"><?php echo ucfirst($f['status']); ?></span>
                    </td>
                    <td style="padding:15px; text-align:right;">
                        <div style="display:flex; justify-content:flex-end; gap:6px;">
                            <?php if ($has_access): ?>
                                <?php if (can_access('campaign-form-edit')): ?>
                                    <a href="campaign-form-builder.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-soft-violet" title="Edit Form"><i class="fas fa-edit"></i></a>
                                <?php endif; ?>
                                <a href="campaign-form-responses.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-soft-green" title="View Responses"><i class="fas fa-list"></i></a>
                                <a href="campaign-form-analytics.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-soft-blue" title="View Analytics"><i class="fas fa-chart-pie"></i></a>
                                
                                <?php if (can_access('campaign-form-edit')): ?>
                                    <button class="btn btn-sm btn-soft-amber" title="Duplicate Form" onclick="duplicateForm(<?php echo $f['id']; ?>)">
                                        <i class="fas fa-clone"></i>
                                    </button>
                                    
                                    <?php if ($f['status'] !== 'archived'): ?>
                                        <button class="btn btn-sm btn-soft-red" title="Archive Form" onclick="archiveForm(<?php echo $f['id']; ?>, 1)">
                                            <i class="fas fa-archive"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-soft-green" title="Restore Draft" onclick="archiveForm(<?php echo $f['id']; ?>, 0)">
                                            <i class="fas fa-box-open"></i>
                                        </button>
                                    <?php endif; ?>

                                    <button class="btn btn-sm btn-soft-red" title="Delete Form" onclick="deleteForm(<?php echo $f['id']; ?>)">
                                        <i class="fas fa-trash-can"></i>
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="alert('Access denied. You do not currently have permission to use this form. Please ask the Superadmin to grant you access.')" style="opacity:0.6; cursor:not-allowed;" disabled><i class="fas fa-lock"></i> Locked</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
    function copyLink(link, btn) {
        var protocol = window.location.protocol;
        var fullUrl = protocol + '//' + link;
        navigator.clipboard.writeText(fullUrl).then(function() {
            var origIcon = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.style.background = '#16a34a';
            btn.style.color = '#fff';
            setTimeout(function() {
                btn.innerHTML = origIcon;
                btn.style.background = '';
                btn.style.color = '';
            }, 1500);
        });
    }

    function duplicateForm(id) {
        if (!confirm('Are you sure you want to duplicate this form structure?')) return;
        
        var fd = new FormData();
        fd.append('action', 'duplicate');
        fd.append('id', id);
        
        fetch('api/campaign-forms.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert('Form structure duplicated successfully.');
                window.location.reload();
            } else {
                alert('Failed to duplicate: ' + res.message);
            }
        })
        .catch(err => alert('Error: ' + err));
    }

    function archiveForm(id, archive) {
        var msg = archive ? 'Are you sure you want to archive this form? Submissions will stop.' : 'Are you sure you want to restore this form to Draft status?';
        if (!confirm(msg)) return;
        
        var fd = new FormData();
        fd.append('action', 'archive');
        fd.append('id', id);
        fd.append('archive', archive);
        
        fetch('api/campaign-forms.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                window.location.reload();
            } else {
                alert('Failed to update status: ' + res.message);
            }
        })
        .catch(err => alert('Error: ' + err));
    }

    function deleteForm(id) {
        var verifyText = prompt('CRITICAL WARNING: This will permanently delete the form, all configured fields, all responses collected, and views log. This action CANNOT be undone.\n\nPlease type "DELETE" below to confirm:');
        if (verifyText !== 'DELETE') {
            alert('Deletion cancelled. Confirmation text did not match.');
            return;
        }
        
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        
        fetch('api/campaign-forms.php', {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert('Form deleted permanently.');
                window.location.reload();
            } else {
                alert('Failed to delete form: ' + res.message);
            }
        })
        .catch(err => alert('Error: ' + err));
    }

    // Access Dropdown Functions
    function filterAdmins(input) {
        const query = input.value.toLowerCase().trim();
        const dropdown = input.closest('.dropdown-menu');
        const items = dropdown.querySelectorAll('.admin-item');
        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            if (text.includes(query)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function toggleSelectAll(selectAllCb) {
        const dropdown = selectAllCb.closest('.dropdown-menu');
        const checkboxes = dropdown.querySelectorAll('.admins-list input[type="checkbox"]');
        checkboxes.forEach(cb => {
            if (cb.closest('.admin-item').style.display !== 'none') {
                cb.checked = selectAllCb.checked;
            }
        });
        updateDropdownSelectedCount(dropdown);
    }

    function updateSelectedCount(cb) {
        const dropdown = cb.closest('.dropdown-menu');
        updateDropdownSelectedCount(dropdown);
    }

    function updateDropdownSelectedCount(dropdown) {
        const checkedCount = dropdown.querySelectorAll('.admins-list input[type="checkbox"]:checked').length;
        const totalCount = dropdown.querySelectorAll('.admins-list input[type="checkbox"]').length;
        const countSpan = dropdown.querySelector('.selected-count');
        if (countSpan) {
            countSpan.textContent = checkedCount + ' selected';
        }
        const selectAllCb = dropdown.querySelector('.select-all-cb');
        if (selectAllCb) {
            selectAllCb.checked = (checkedCount === totalCount && totalCount > 0);
        }
    }

    function toggleAccessDropdown(formId) {
        const el = document.getElementById('access-dropdown-' + formId);
        if (el) {
            if (el.style.display === 'none') {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    if (menu.id !== 'access-dropdown-' + formId) {
                        menu.style.display = 'none';
                    }
                });
                el.style.display = 'block';
                updateDropdownSelectedCount(el);
            } else {
                el.style.display = 'none';
            }
        }
    }

    function closeAccessDropdown(formId) {
        const el = document.getElementById('access-dropdown-' + formId);
        if (el) {
            el.style.display = 'none';
        }
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('[id^="access-dropdown-"]').forEach(menu => {
                menu.style.display = 'none';
            });
        }
    });

    function saveFormAccessAjax(formId) {
        const dropdown = document.getElementById('access-dropdown-' + formId);
        if (!dropdown) return;

        const checkboxes = dropdown.querySelectorAll('.admins-list input[type="checkbox"]:checked');
        const adminIds = Array.from(checkboxes).map(cb => cb.value);

        const formData = new FormData();
        formData.append('action', 'save_form_access');
        formData.append('form_id', formId);
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');
        adminIds.forEach(id => {
            formData.append('admin_ids[]', id);
        });

        fetch('api/campaign-forms.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const btnSpan = dropdown.closest('.dropdown').querySelector('.dropdown-toggle span');
                if (btnSpan) {
                    btnSpan.textContent = adminIds.length + ' Admins Selected';
                }
                closeAccessDropdown(formId);
                alert('Access updated successfully!');
            } else {
                alert(data.message || 'Failed to update access.');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Network error while saving form access.');
        });
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
