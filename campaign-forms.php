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
    
    <a href="campaign-form-builder.php" class="btn btn-primary" style="padding:0.75rem 1.5rem; border-radius:12px; font-weight:700;"><i class="fas fa-plus"></i> Create Custom Form</a>
</div>

<!-- ── FORMS LISTING ── -->
<div class="card" style="padding:0; overflow:hidden;">
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
            ?>
                <tr style="border-bottom:1px solid var(--border); transition:background 0.2s;" class="table-row">
                    <td style="padding:15px; max-width:240px;">
                        <div style="font-weight:700; font-size:0.95rem; margin-bottom:2px;"><?php echo htmlspecialchars($f['title']); ?></div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">
                            Created: <?php echo date('d M Y', strtotime($f['created_at'])); ?> by <?php echo htmlspecialchars($f['created_by']); ?>
                        </div>
                    </td>
                    <td style="padding:15px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <a href="<?php echo $public_url; ?>" target="_blank" style="font-family:monospace; font-size:0.85rem; color:var(--accent); text-decoration:none;">
                                /<?php echo htmlspecialchars($f['slug']); ?>
                            </a>
                            <button class="btn btn-sm btn-soft-blue" title="Copy clean link" onclick="copyLink('<?php echo $_SERVER['HTTP_HOST'] . '/admissions/' . $public_url; ?>', this)">
                                <i class="fas fa-copy"></i>
                            </button>
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
                            <a href="campaign-form-builder.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-soft-violet" title="Edit Form"><i class="fas fa-edit"></i></a>
                            <a href="campaign-form-responses.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-soft-green" title="View Responses"><i class="fas fa-list"></i></a>
                            <a href="campaign-form-analytics.php?id=<?php echo $f['id']; ?>" class="btn btn-sm btn-soft-blue" title="View Analytics"><i class="fas fa-chart-pie"></i></a>
                            
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
</script>

<?php include 'includes/admin_footer.php'; ?>
