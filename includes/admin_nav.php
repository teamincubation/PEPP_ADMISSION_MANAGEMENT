<?php
/**
 * PEPP Learning Admin - shared page shell (sidebar + topbar).
 * Usage (after auth.php + database.php):
 *   $active_page = 'dashboard';   // nav key
 *   $page_title  = 'Dashboard';
 *   $page_sub    = 'Overview of your admission system';
 *   include 'includes/admin_nav.php';
 *   ... page content ...
 *   include 'includes/admin_footer.php';
 */
if (!isset($active_page)) $active_page = '';
if (!isset($page_title))  $page_title  = 'PEPP Learning Admin';
if (!isset($page_sub))    $page_sub    = '';

// Live badge counts for the sidebar (cheap, indexed queries)
$nav_pending_approvals = 0;
$nav_due_leads = 0;
try {
    if (function_exists('can_access') && can_access('leads')) {
        $__lc = $pdo->query("SHOW TABLES LIKE 'leads'")->fetchColumn();
        if ($__lc) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE next_followup_date IS NOT NULL AND next_followup_date <= CURDATE() AND status NOT IN ('converted','rejected','not_interested')");
            $nav_due_leads = (int)$stmt->fetchColumn();
        }
    }
} catch (Exception $e) { $nav_due_leads = 0; }

// Marketing unread badges (green=referral, red=coupon)
$nav_mkt = ['referral' => 0, 'coupon' => 0];
if (function_exists('can_access') && can_access('marketing') && file_exists(__DIR__ . '/peppian_notify.php')) {
    require_once __DIR__ . '/peppian_notify.php';
    try { $nav_mkt = marketing_unread_counts($pdo); } catch (Exception $e) {}
}

// Reminders: load helper, send any due emails (once), and collect due/pending for the bell.
$nav_reminders_due = [];
$nav_reminders_pending = [];
if (file_exists(__DIR__ . '/reminders_helper.php')) {
    require_once __DIR__ . '/reminders_helper.php';
    try {
        reminders_send_due_emails($pdo);
        $nav_reminders_due     = reminders_due($pdo, $admin_username);
        $nav_reminders_pending = reminders_for($pdo, $admin_username, ['pending']);
    } catch (Exception $e) { error_log('nav reminders: ' . $e->getMessage()); }
}

// Automatic session reminders (12h / 4h / 10m / start) - runs lazily on page loads.
if (file_exists(__DIR__ . '/session_cron.php')) {
    require_once __DIR__ . '/session_cron.php';
    try {
        if (function_exists('sessions_dispatch_due')) sessions_dispatch_due($pdo);
        if (function_exists('installments_dispatch_reminders')) installments_dispatch_reminders($pdo);
        if (function_exists('installments_dispatch_whatsapp_reminders')) installments_dispatch_whatsapp_reminders($pdo);
    }
    catch (Exception $e) { error_log('nav session/installment cron: ' . $e->getMessage()); }
}

// Email Campaigns: run due email campaigns and batch delivery queue.
if (file_exists(__DIR__ . '/email_campaigns_helper.php')) {
    require_once __DIR__ . '/email_campaigns_helper.php';
    try {
        email_campaigns_send_due($pdo);
    } catch (Exception $e) {
        error_log('nav email campaigns cron: ' . $e->getMessage());
    }
}
$nav_pending_payments  = 0;
$nav_pending_onboarding = 0;
$nav_due_within_10_days = 0;
$nav_active_forms_count = 0;
$nav_unread_submissions_count = 0;
$nav_unread_inbox_count = 0;
try {
    $nav_pending_approvals  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $nav_pending_payments   = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NOT NULL")->fetchColumn();
    $nav_pending_onboarding = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'approved' AND (onboarding_status IS NULL OR onboarding_status <> 'completed')")->fetchColumn();
    $nav_due_within_10_days = (int)$pdo->query("SELECT COUNT(*) FROM instalment_details WHERE status = 'pending' AND paid_date IS NULL AND rejected_at IS NULL AND due_date <= DATE_ADD(CURDATE(), INTERVAL 10 DAY)")->fetchColumn();
    $nav_active_forms_count = (int)$pdo->query("SELECT COUNT(*) FROM campaign_forms WHERE status = 'published'")->fetchColumn();
    $nav_unread_submissions_count = (int)$pdo->query("SELECT COUNT(*) FROM campaign_form_submissions WHERE is_read = 0 AND is_deleted = 0")->fetchColumn();
    
    // Efficiently sum the unread count from conversations
    $nav_unread_inbox_count = (int)$pdo->query("SELECT IFNULL(SUM(unread_count), 0) FROM whatsapp_conversations")->fetchColumn();
} catch (Exception $navEx) { /* sidebar still renders */ }

function nav_active($key, $active) { return $key === $active ? 'active' : ''; }

// Function to render nav items dynamically based on their keys
function render_nav_item($key, $active_page, $nav_data) {
    global $pdo, $admin_perms, $admin_role;
    if (!function_exists('can_access') || !can_access($key)) return;
    
    // Extract variables from $nav_data
    $nav_pending_approvals = $nav_data['pending_approvals'] ?? 0;
    $nav_pending_onboarding = $nav_data['pending_onboarding'] ?? 0;
    $nav_due_leads = $nav_data['due_leads'] ?? 0;
    $nav_active_forms_count = $nav_data['active_forms_count'] ?? 0;
    $nav_unread_submissions_count = $nav_data['unread_submissions_count'] ?? 0;
    $nav_mkt = $nav_data['mkt'] ?? [];
    $nav_pending_payments = $nav_data['pending_payments'] ?? 0;
    $nav_due_within_10_days = $nav_data['due_within_10_days'] ?? 0;
    $nav_unread_inbox_count = $nav_data['unread_inbox_count'] ?? 0;
    
    switch ($key) {
        case 'dashboard':
            echo '<a class="nav-item ' . nav_active('dashboard', $active_page) . '" href="dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>';
            break;
        case 'approvals':
            echo '<a class="nav-item ' . nav_active('approvals', $active_page) . '" href="student-approval.php"><i class="fas fa-user-check"></i> Approvals';
            if ($nav_pending_approvals > 0) {
                echo '<span class="nav-badge">' . $nav_pending_approvals . '</span>';
            }
            echo '</a>';
            break;
        case 'add-student':
            echo '<a class="nav-item ' . nav_active('add-student', $active_page) . '" href="add-student.php"><i class="fas fa-user-plus"></i> Add Student</a>';
            break;
        case 'students':
            echo '<a class="nav-item ' . nav_active('students', $active_page) . '" href="studentpage.php"><i class="fas fa-users"></i> All Students</a>';
            break;
        case 'onboarding':
            echo '<a class="nav-item ' . nav_active('onboarding', $active_page) . '" href="studentonboarding.php"><i class="fas fa-handshake"></i> Onboarding';
            if ($nav_pending_onboarding > 0) {
                echo '<span class="nav-badge">' . $nav_pending_onboarding . '</span>';
            }
            echo '</a>';
            break;
        case 'sessions':
            echo '<a class="nav-item ' . nav_active('sessions', $active_page) . '" href="sessions.php"><i class="fas fa-video"></i> Sessions</a>';
            break;
        case 'leads':
            echo '<a class="nav-item ' . nav_active('leads', $active_page) . '" href="lead-management.php"><i class="fas fa-user-tag"></i> Lead Management';
            if ($nav_due_leads > 0) {
                echo '<span class="nav-badge">' . $nav_due_leads . '</span>';
            }
            echo '</a>';
            break;
        case 'alumni':
            echo '<a class="nav-item ' . nav_active('alumni', $active_page) . '" href="alumni-database.php"><i class="fas fa-user-graduate"></i> Alumni Database</a>';
            break;
        case 'peppkit':
            echo '<a class="nav-item ' . nav_active('peppkit', $active_page) . '" href="peppkit-report.php"><i class="fas fa-box-open"></i> PEPPKIT Report</a>';
            break;
        case 'cards':
            if (can_access('cards')) {
                echo '<a class="nav-item ' . nav_active('cards', $active_page) . '" href="cards.php"><i class="fas fa-id-card"></i> Generate Custom Cards</a>';
            }
            break;
        case 'card-templates':
            if (can_access('card-templates')) {
                echo '<a class="nav-item ' . nav_active('card-templates', $active_page) . '" href="cards.php?tab=templates"><i class="fas fa-layer-group"></i> Create Card Templates</a>';
            }
            break;
        case 'accounts':
            echo '<a class="nav-item ' . nav_active('accounts', $active_page) . '" href="accounts.php"><i class="fas fa-wallet"></i> Accounts &amp; Expenses</a>';
            break;
        case 'campaigns':
            echo '<a class="nav-item ' . nav_active('campaigns', $active_page) . '" href="campaign-forms.php"><i class="fab fa-wpforms"></i> Custom Forms';
            echo '<span style="margin-left:auto; display:inline-flex; gap:4px; align-items:center;">';
            if ($nav_active_forms_count > 0) {
                echo '<span class="nav-badge" style="background:rgba(34, 197, 94, 0.15); color:#22c55e; border:1px solid rgba(34, 197, 94, 0.3); padding:2px 6px; border-radius:6px; font-size:0.7rem; font-weight:700;" title="Active campaign forms">' . $nav_active_forms_count . ' Active</span>';
            }
            if ($nav_unread_submissions_count > 0) {
                echo '<span class="nav-badge" style="background:rgba(59, 130, 246, 0.15); color:#3b82f6; border:1px solid rgba(59, 130, 246, 0.3); padding:2px 6px; border-radius:6px; font-size:0.7rem; font-weight:700;" title="New unread registrations">' . $nav_unread_submissions_count . ' New</span>';
            }
            echo '</span></a>';
            break;
        case 'marketing':
            echo '<a class="nav-item ' . nav_active('marketing', $active_page) . '" href="marketing.php"><i class="fas fa-bullhorn"></i> Marketing';
            echo '<span style="margin-left:auto; display:inline-flex; gap:4px;">';
            if (!empty($nav_mkt['referral'])) {
                echo '<span class="nav-badge" style="background:#16a34a; color:#fff;" title="New referral updates">' . (int)$nav_mkt['referral'] . '</span>';
            }
            if (!empty($nav_mkt['coupon'])) {
                echo '<span class="nav-badge" style="background:#dc2626; color:#fff;" title="New coupon updates">' . (int)$nav_mkt['coupon'] . '</span>';
            }
            echo '</span></a>';
            break;
        case 'email-campaigns':
            echo '<a class="nav-item ' . nav_active('email-campaigns', $active_page) . '" href="email-campaigns.php"><i class="fas fa-envelope"></i> Email Campaigns</a>';
            break;
        case 'installments':
            echo '<a class="nav-item ' . nav_active('installments', $active_page) . '" href="phpinstalmentpaymentupdate.php"><i class="fas fa-money-bill-wave"></i> Installments';
            echo '<span style="margin-left:auto; display:inline-flex; gap:4px; align-items:center;">';
            if ($nav_pending_payments > 0) {
                echo '<span class="nav-badge" style="background:#f59e0b; color:#fff;" title="Pending review">' . $nav_pending_payments . '</span>';
            }
            if ($nav_due_within_10_days > 0) {
                echo '<span class="nav-badge" style="background:#ef4444; color:#fff;" title="Due within 10 days">' . $nav_due_within_10_days . '</span>';
            }
            echo '</span></a>';
            break;
        case 'invoices':
            echo '<a class="nav-item ' . nav_active('invoices', $active_page) . '" href="invoices.php"><i class="fas fa-file-invoice"></i> Invoices</a>';
            break;
        case 'communication':
            echo '<a class="nav-item ' . nav_active('communication', $active_page) . '" href="communication-dashboard.php"><i class="fas fa-network-wired"></i> Communication Engine</a>';
            break;
        case 'whatsapp':
            echo '<a class="nav-item ' . nav_active('whatsapp', $active_page) . '" href="whatsapp-notification.php"><i class="fab fa-whatsapp"></i> Manual WP Log</a>';
            break;
        case 'whatsapp-inbox':
            echo '<a class="nav-item ' . nav_active('whatsapp-inbox', $active_page) . '" href="whatsapp-inbox.php"><i class="fab fa-whatsapp"></i> WhatsApp Inbox';
            if ($nav_unread_inbox_count > 0) {
                echo '<span class="nav-badge" style="background:#ef4444; color:#fff;">' . $nav_unread_inbox_count . '</span>';
            }
            echo '</a>';
            break;
        case 'courses':
            echo '<a class="nav-item ' . nav_active('courses', $active_page) . '" href="course-management.php"><i class="fas fa-book-open"></i> Courses</a>';
            break;
        case 'faculties':
            echo '<a class="nav-item ' . nav_active('faculties', $active_page) . '" href="faculties.php"><i class="fas fa-chalkboard-user"></i> Faculties</a>';
            break;
        case 'studyplans':
            echo '<a class="nav-item ' . nav_active('studyplans', $active_page) . '" href="studyplans.php"><i class="fas fa-calendar-days"></i> Study Plans</a>';
            break;
        case 'student-study-reports':
            echo '<a class="nav-item ' . nav_active('student-study-reports', $active_page) . '" href="student-study-reports.php"><i class="fas fa-chart-line"></i> Student Reports</a>';
            break;
        case 'assessment-results':
            echo '<a class="nav-item ' . nav_active('assessment-results', $active_page) . '" href="assessment-results.php"><i class="fas fa-chart-column"></i> Assessment Results</a>';
            break;
        case 'task-tracker':
            echo '<a class="nav-item ' . nav_active('task-tracker', $active_page) . '" href="task-tracker.php"><i class="fas fa-list-check"></i> Task Tracker</a>';
            break;
        case 'ld-work-report':
            echo '<a class="nav-item ' . nav_active('ld-work-report', $active_page) . '" href="ld-work-report.php"><i class="fas fa-chart-simple"></i> L&D Work Report</a>';
            break;
        case 'settings':
            echo '<a class="nav-item ' . nav_active('settings', $active_page) . '" href="settings.php"><i class="fas fa-gear"></i> Settings</a>';
            break;
        case 'admin-management':
            if (is_super_admin()) {
                echo '<a class="nav-item ' . nav_active('admin-management', $active_page) . '" href="admin-management.php"><i class="fas fa-user-shield"></i> Admin Management</a>';
            }
            break;
        case 'employee-management':
            if (is_super_admin()) {
                echo '<a class="nav-item ' . nav_active('employee-management', $active_page) . '" href="employee-management.php"><i class="fas fa-id-badge"></i> Employee Management</a>';
            }
            break;
        case 'student-mentoring':
            echo '<a class="nav-item ' . nav_active('student-mentoring', $active_page) . '" href="student-mentoring.php"><i class="fas fa-people-arrows"></i> Student Mentoring</a>';
            break;
        case 'admin-activity':
            if (is_super_admin()) {
                echo '<a class="nav-item ' . nav_active('admin-activity', $active_page) . '" href="admin-activity.php"><i class="fas fa-clock-rotate-left"></i> Activity Log</a>';
            }
            break;
        case 'reports':
            if (is_super_admin()) {
                echo '<a class="nav-item ' . nav_active('reports', $active_page) . '" href="reports.php"><i class="fas fa-chart-pie"></i> Reports &amp; Export</a>';
            }
            break;
        case 'email-reports':
            if (is_super_admin()) {
                echo '<a class="nav-item ' . nav_active('email-reports', $active_page) . '" href="email-reports.php"><i class="fas fa-envelope-open-text"></i> Email Reports</a>';
            }
            break;
    }
}

// Fallback Default Sidebar Layout with category icons
$default_sidebar = [
    [
        'id' => 'overview',
        'title' => 'Overview',
        'icon' => 'fas fa-gauge-high',
        'items' => ['dashboard']
    ],
    [
        'id' => 'registrations',
        'title' => 'Registrations',
        'icon' => 'fas fa-user-plus',
        'items' => ['approvals', 'add-student']
    ],
    [
        'id' => 'students',
        'title' => 'Students',
        'icon' => 'fas fa-user-graduate',
        'items' => ['students', 'onboarding', 'sessions', 'student-mentoring']
    ],
    [
        'id' => 'crm',
        'title' => 'CRM',
        'icon' => 'fas fa-handshake',
        'items' => ['leads', 'alumni', 'peppkit', 'cards', 'card-templates', 'accounts', 'whatsapp-inbox', 'task-tracker', 'ld-work-report']
    ],
    [
        'id' => 'campaigns',
        'title' => 'Campaigns',
        'icon' => 'fas fa-bullhorn',
        'items' => ['campaigns', 'marketing', 'email-campaigns']
    ],
    [
        'id' => 'payments',
        'title' => 'Payments',
        'icon' => 'fas fa-money-bill-wave',
        'items' => ['installments', 'invoices', 'communication', 'whatsapp']
    ],
    [
        'id' => 'academics',
        'title' => 'Academics',
        'icon' => 'fas fa-graduation-cap',
        'items' => ['courses', 'faculties', 'studyplans', 'student-study-reports', 'assessment-results']
    ],
    [
        'id' => 'system',
        'title' => 'System',
        'icon' => 'fas fa-gears',
        'items' => ['settings', 'admin-management', 'employee-management', 'admin-activity', 'email-reports', 'reports']
    ]
];

// Load layout from database with complete self-healing normalization & deduplication
$sidebar_menu = $default_sidebar;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'sidebar_menu_config' LIMIT 1");
    $stmt->execute();
    $config_json = $stmt->fetchColumn();
    if ($config_json) {
        $decoded = json_decode($config_json, true);
        if (is_array($decoded) && !empty($decoded)) {
            // Map sections by unique ID (eliminating duplicate section IDs like duplicate Payments)
            $section_map = [];
            foreach ($decoded as $sec) {
                $sid = $sec['id'] ?? '';
                if ($sid) {
                    if (!isset($section_map[$sid])) {
                        $section_map[$sid] = $sec;
                    } else {
                        // Merge items from duplicate section into the first section entry
                        $section_map[$sid]['items'] = array_values(array_unique(array_merge(
                            $section_map[$sid]['items'] ?? [],
                            $sec['items'] ?? []
                        )));
                    }
                }
            }

            // Build normalized layout while preserving the category order saved in $decoded
            $normalized = [];
            $seen_ids = [];
            foreach ($decoded as $dec_sec) {
                $sid = $dec_sec['id'] ?? '';
                if ($sid && !in_array($sid, $seen_ids, true) && isset($section_map[$sid])) {
                    $sec = $section_map[$sid];
                    if (empty($sec['icon'])) {
                        foreach ($default_sidebar as $def_sec) {
                            if ($def_sec['id'] === $sid) {
                                $sec['icon'] = $def_sec['icon'];
                                break;
                            }
                        }
                    }
                    if (empty($sec['title'])) {
                        foreach ($default_sidebar as $def_sec) {
                            if ($def_sec['id'] === $sid) {
                                $sec['title'] = $def_sec['title'];
                                break;
                            }
                        }
                    }
                    $normalized[] = $sec;
                    $seen_ids[] = $sid;
                    unset($section_map[$sid]);
                }
            }

            // Restore any missing standard categories from $default_sidebar
            foreach ($default_sidebar as $def_sec) {
                if (!in_array($def_sec['id'], $seen_ids, true)) {
                    $normalized[] = $def_sec;
                    $seen_ids[] = $def_sec['id'];
                }
            }

            // Append any remaining custom user-added categories
            foreach ($section_map as $custom_sec) {
                $normalized[] = $custom_sec;
            }

            // Ensure all standard items from $default_sidebar exist in the normalized layout
            $all_current_items = [];
            foreach ($normalized as $sec) {
                foreach ($sec['items'] ?? [] as $it) {
                    $all_current_items[] = $it;
                }
            }

            foreach ($default_sidebar as $def_sec) {
                foreach ($def_sec['items'] as $def_item) {
                    if (!in_array($def_item, $all_current_items, true)) {
                        // Add missing item back to its default category
                        foreach ($normalized as &$norm_sec) {
                            if ($norm_sec['id'] === $def_sec['id']) {
                                $norm_sec['items'][] = $def_item;
                                $all_current_items[] = $def_item;
                                break;
                            }
                        }
                    }
                }
            }

            // Strict Global Item Deduplication across all sections
            $seen_global_items = [];
            foreach ($normalized as &$norm_sec) {
                $clean_items = [];
                foreach ($norm_sec['items'] ?? [] as $it) {
                    if (!in_array($it, $seen_global_items, true)) {
                        $clean_items[] = $it;
                        $seen_global_items[] = $it;
                    }
                }
                $norm_sec['items'] = $clean_items;
            }
            unset($norm_sec);

            // Save the cleaned, normalized version back to database to permanently fix the setting
            $new_config_json = json_encode($normalized);
            if ($new_config_json !== $config_json) {
                try {
                    $save_stmt = $pdo->prepare("UPDATE admin_settings SET setting_value = ? WHERE setting_name = 'sidebar_menu_config'");
                    $save_stmt->execute([$new_config_json]);
                } catch (Exception $ex) {}
            }

            $sidebar_menu = $normalized;
        }
    }
} catch (Exception $e) {}

// Load sidebar auto-collapse setting (default '1')
$sidebar_auto_collapse = '1';
try {
    $st_ac = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'sidebar_auto_collapse' LIMIT 1");
    $st_ac->execute();
    $val_ac = $st_ac->fetchColumn();
    if ($val_ac !== false && $val_ac !== null && $val_ac !== '') {
        $sidebar_auto_collapse = (string)$val_ac;
    }
} catch (Exception $ex) {}

// Gather nav counts
$nav_data = [
    'pending_approvals' => $nav_pending_approvals ?? 0,
    'pending_onboarding' => $nav_pending_onboarding ?? 0,
    'due_leads' => $nav_due_leads ?? 0,
    'active_forms_count' => $nav_active_forms_count ?? 0,
    'unread_submissions_count' => $nav_unread_submissions_count ?? 0,
    'mkt' => $nav_mkt ?? [],
    'pending_payments' => $nav_pending_payments ?? 0,
    'due_within_10_days' => $nav_due_within_10_days ?? 0,
    'unread_inbox_count' => $nav_unread_inbox_count ?? 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?> - PEPP Learning Admin</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/admin-theme.css" rel="stylesheet">
    <style>
        /* Style for copy link button inside menu links */
        .copy-link-btn {
            margin-left: auto;
            padding: 4px 6px;
            cursor: pointer;
            opacity: 0.5;
            transition: all 0.2s ease;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .copy-link-btn:hover {
            opacity: 1 !important;
            background: rgba(148, 163, 184, 0.15);
            transform: scale(1.05);
        }
        html.theme-dark .copy-link-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* Selected/active sub-category menu styles: dark background with white icon & font */
        .nav-item.active {
            background: #0f172a !important;
            color: #ffffff !important;
            font-weight: 600;
        }
        .nav-item.active i {
            color: #ffffff !important;
        }
        html.theme-dark .nav-item.active {
            background: #4f46e5 !important;
            color: #ffffff !important;
        }
        html.theme-dark .nav-item.active i {
            color: #ffffff !important;
        }

        /* Custom sidebar category background and high-visibility text colors */
        .nav-section-label.cat-overview {
            background: rgba(148, 163, 184, 0.12) !important;
            color: #334155 !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-overview:hover {
            background: rgba(148, 163, 184, 0.22) !important;
            color: #0f172a !important;
        }
        html.theme-dark .nav-section-label.cat-overview {
            background: rgba(148, 163, 184, 0.22) !important;
            color: #f1f5f9 !important;
        }
        html.theme-dark .nav-section-label.cat-overview:hover {
            background: rgba(148, 163, 184, 0.32) !important;
            color: #ffffff !important;
        }
        html.theme-sepia .nav-section-label.cat-overview {
            color: #433422 !important;
        }

        .nav-section-label.cat-registrations {
            background: rgba(34, 197, 94, 0.12) !important;
            color: #15803d !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-registrations:hover {
            background: rgba(34, 197, 94, 0.22) !important;
            color: #166534 !important;
        }
        html.theme-dark .nav-section-label.cat-registrations {
            background: rgba(34, 197, 94, 0.22) !important;
            color: #4ade80 !important;
        }
        html.theme-dark .nav-section-label.cat-registrations:hover {
            background: rgba(34, 197, 94, 0.32) !important;
            color: #22c55e !important;
        }
        html.theme-sepia .nav-section-label.cat-registrations {
            color: #14532d !important;
        }

        .nav-section-label.cat-students {
            background: rgba(59, 130, 246, 0.12) !important;
            color: #1d4ed8 !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-students:hover {
            background: rgba(59, 130, 246, 0.22) !important;
            color: #1e40af !important;
        }
        html.theme-dark .nav-section-label.cat-students {
            background: rgba(59, 130, 246, 0.22) !important;
            color: #60a5fa !important;
        }
        html.theme-dark .nav-section-label.cat-students:hover {
            background: rgba(59, 130, 246, 0.32) !important;
            color: #3b82f6 !important;
        }
        html.theme-sepia .nav-section-label.cat-students {
            color: #1e3a8a !important;
        }

        .nav-section-label.cat-crm {
            background: rgba(99, 102, 241, 0.12) !important;
            color: #4f46e5 !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-crm:hover {
            background: rgba(99, 102, 241, 0.22) !important;
            color: #3730a3 !important;
        }
        html.theme-dark .nav-section-label.cat-crm {
            background: rgba(99, 102, 241, 0.22) !important;
            color: #818cf8 !important;
        }
        html.theme-dark .nav-section-label.cat-crm:hover {
            background: rgba(99, 102, 241, 0.32) !important;
            color: #6366f1 !important;
        }
        html.theme-sepia .nav-section-label.cat-crm {
            color: #312e81 !important;
        }

        .nav-section-label.cat-campaigns {
            background: rgba(245, 158, 11, 0.12) !important;
            color: #b45309 !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-campaigns:hover {
            background: rgba(245, 158, 11, 0.22) !important;
            color: #92400e !important;
        }
        html.theme-dark .nav-section-label.cat-campaigns {
            background: rgba(245, 158, 11, 0.22) !important;
            color: #fbbf24 !important;
        }
        html.theme-dark .nav-section-label.cat-campaigns:hover {
            background: rgba(245, 158, 11, 0.32) !important;
            color: #f59e0b !important;
        }
        html.theme-sepia .nav-section-label.cat-campaigns {
            color: #78350f !important;
        }

        .nav-section-label.cat-payments {
            background: rgba(20, 184, 166, 0.12) !important;
            color: #0d9488 !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-payments:hover {
            background: rgba(20, 184, 166, 0.22) !important;
            color: #0f766e !important;
        }
        html.theme-dark .nav-section-label.cat-payments {
            background: rgba(20, 184, 166, 0.22) !important;
            color: #2dd4bf !important;
        }
        html.theme-dark .nav-section-label.cat-payments:hover {
            background: rgba(20, 184, 166, 0.32) !important;
            color: #14b8a6 !important;
        }
        html.theme-sepia .nav-section-label.cat-payments {
            color: #115e59 !important;
        }

        .nav-section-label.cat-academics {
            background: rgba(139, 92, 246, 0.12) !important;
            color: #7c3aed !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-academics:hover {
            background: rgba(139, 92, 246, 0.22) !important;
            color: #5b21b6 !important;
        }
        html.theme-dark .nav-section-label.cat-academics {
            background: rgba(139, 92, 246, 0.22) !important;
            color: #a78bfa !important;
        }
        html.theme-dark .nav-section-label.cat-academics:hover {
            background: rgba(139, 92, 246, 0.32) !important;
            color: #8b5cf6 !important;
        }
        html.theme-sepia .nav-section-label.cat-academics {
            color: #4c1d95 !important;
        }

        .nav-section-label.cat-system {
            background: rgba(244, 63, 94, 0.12) !important;
            color: #e11d48 !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-system:hover {
            background: rgba(244, 63, 94, 0.22) !important;
            color: #9f1239 !important;
        }
        html.theme-dark .nav-section-label.cat-system {
            background: rgba(244, 63, 94, 0.22) !important;
            color: #fda4af !important;
        }
        html.theme-dark .nav-section-label.cat-system:hover {
            background: rgba(244, 63, 94, 0.32) !important;
            color: #f43f5e !important;
        }
        html.theme-sepia .nav-section-label.cat-system {
            color: #881337 !important;
        }
    </style>
    <script>
        (function() {
            var theme = localStorage.getItem('admin-theme') || 'light';
            if (theme !== 'light') {
                document.documentElement.classList.add('theme-' + theme);
            }
        })();
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-expand any sections containing attention-seeking badges
            var sections = document.querySelectorAll('.sidebar .nav-section');
            sections.forEach(function(section) {
                var badges = section.querySelectorAll('.nav-badge');
                var hasActiveBadge = false;
                badges.forEach(function(badge) {
                    var val = badge.textContent.trim();
                    // Match numbers greater than 0 or descriptive text badges (like "Active", "New")
                    if (val !== '' && val !== '0') {
                        hasActiveBadge = true;
                    }
                });
                if (hasActiveBadge) {
                    section.classList.remove('collapsed');
                }
            });

            var labels = document.querySelectorAll('.sidebar .nav-section-label');
            labels.forEach(function(label) {
                var section = label.closest('.nav-section');
                if (!section) return;
                label.addEventListener('click', function(e) {
                    e.preventDefault();
                    section.classList.toggle('collapsed');
                });
            });

            // Action Permissions - Front-end enforcer
            <?php if (!can_admin_delete()): ?>
            document.querySelectorAll('button, a, input[type=button], input[type=submit]').forEach(function(el) {
                var txt = (el.textContent || el.value || '').toLowerCase();
                var onclick = (el.getAttribute('onclick') || '').toLowerCase();
                var href = (el.getAttribute('href') || '').toLowerCase();
                var id = (el.id || '').toLowerCase();
                var cls = (el.className || '').toLowerCase();
                var name = (el.name || '').toLowerCase();
                if (txt.includes('delete') || txt.includes('remove') || txt.includes('reject') || txt.includes('cancel') ||
                    onclick.includes('delete') || onclick.includes('remove') || onclick.includes('reject') || onclick.includes('cancel') ||
                    href.includes('delete') || href.includes('remove') || href.includes('reject') || href.includes('cancel') ||
                    id.includes('delete') || id.includes('remove') || id.includes('reject') ||
                    cls.includes('delete') || cls.includes('remove') || cls.includes('reject') || cls.includes('danger') ||
                    name.includes('delete') || name.includes('remove') || name.includes('reject')) {
                    
                    el.disabled = true;
                    el.style.pointerEvents = 'none';
                    el.style.opacity = '0.4';
                    el.style.cursor = 'not-allowed';
                    if (el.tagName === 'A') {
                        el.removeAttribute('href');
                    }
                }
            });
            <?php endif; ?>

            <?php if (!can_admin_edit()): ?>
            document.querySelectorAll('button, a, input[type=button], input[type=submit]').forEach(function(el) {
                var txt = (el.textContent || el.value || '').toLowerCase();
                var onclick = (el.getAttribute('onclick') || '').toLowerCase();
                var href = (el.getAttribute('href') || '').toLowerCase();
                var id = (el.id || '').toLowerCase();
                var cls = (el.className || '').toLowerCase();
                var name = (el.name || '').toLowerCase();
                // Avoid disabling filters, paging buttons, logout, or modal close buttons
                if (txt.includes('logout') || cls.includes('modal-close') || id.includes('toggle') || cls.includes('toggle') || txt.includes('close') || txt.includes('cancel')) {
                    return;
                }
                if (txt.includes('edit') || txt.includes('update') || txt.includes('save') || txt.includes('create') || txt.includes('add') || txt.includes('convert') || txt.includes('new') ||
                    onclick.includes('edit') || onclick.includes('update') || onclick.includes('save') || onclick.includes('create') || onclick.includes('add') || onclick.includes('convert') ||
                    href.includes('edit') || href.includes('update') || href.includes('save') || href.includes('create') || href.includes('add') || href.includes('convert') ||
                    id.includes('edit') || id.includes('update') || id.includes('save') || id.includes('create') || id.includes('add') || id.includes('convert') ||
                    cls.includes('edit') || cls.includes('update') || cls.includes('save') || cls.includes('create') || cls.includes('add') || cls.includes('convert') ||
                    name.includes('edit') || name.includes('update') || name.includes('save') || name.includes('create') || name.includes('add') || name.includes('convert')) {
                    
                    el.disabled = true;
                    el.style.pointerEvents = 'none';
                    el.style.opacity = '0.4';
                    el.style.cursor = 'not-allowed';
                    if (el.tagName === 'A') {
                        el.removeAttribute('href');
                    }
                }
            });
            document.querySelectorAll('input, select, textarea').forEach(function(el) {
                var name = (el.name || '').toLowerCase();
                var id = (el.id || '').toLowerCase();
                if (name === 'csrf_token' || name === 'search' || name.includes('filter') || id.includes('filter') || id.includes('search')) {
                    return;
                }
                el.disabled = true;
            });
            <?php endif; ?>

            <?php if (!can_admin_export()): ?>
            document.querySelectorAll('button, a, .export-dropdown, .dropdown-item').forEach(function(el) {
                var txt = (el.textContent || el.value || '').toLowerCase();
                var onclick = (el.getAttribute('onclick') || '').toLowerCase();
                var href = (el.getAttribute('href') || '').toLowerCase();
                var id = (el.id || '').toLowerCase();
                var cls = (el.className || '').toLowerCase();
                if (txt.includes('export') || txt.includes('download') || txt.includes('csv') || txt.includes('excel') || txt.includes('report') ||
                    onclick.includes('export') || onclick.includes('download') || onclick.includes('csv') || onclick.includes('excel') || onclick.includes('report') ||
                    href.includes('export') || href.includes('download') || href.includes('csv') || href.includes('excel') || href.includes('report') ||
                    id.includes('export') || id.includes('download') ||
                    cls.includes('export') || cls.includes('download')) {
                    
                    el.disabled = true;
                    el.style.pointerEvents = 'none';
                    el.style.opacity = '0.4';
                    el.style.cursor = 'not-allowed';
                    if (el.tagName === 'A') {
                        el.removeAttribute('href');
                    }
                }
            });
            <?php endif; ?>
        });
    </script>
    <?php if (!empty($extra_head)) echo $extra_head; ?>
</head>
<body>
<div class="admin-shell">

    <div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar(false)"></div>

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="logo.png" alt="PEPP Learning">
            <div>
                <div class="brand-name">PEPP Learning</div>
                <div class="brand-sub">Admin Console</div>
            </div>
        </div>

        <?php foreach ($sidebar_menu as $section): ?>
            <?php
            $has_access = false;
            $is_collapsed = ($sidebar_auto_collapse === '0') ? '' : 'collapsed';
            foreach ($section['items'] as $item) {
                if (can_access($item)) {
                    $has_access = true;
                }
                if ($item === $active_page) {
                    $is_collapsed = '';
                }
            }
            if (!$has_access) continue;
            ?>
            <div class="nav-section <?php echo $is_collapsed; ?>">
                <div class="nav-section-label cat-<?php echo htmlspecialchars($section['id']); ?>">
                    <span>
                        <?php if (!empty($section['icon'])): ?>
                            <i class="<?php echo htmlspecialchars($section['icon']); ?>" style="margin-right:6px; font-size:0.8rem; opacity:0.8;"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($section['title']); ?>
                    </span>
                </div>
                <div class="nav-section-content">
                    <?php 
                    foreach ($section['items'] as $item) {
                        render_nav_item($item, $active_page, $nav_data);
                    }
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <div class="nav-section" style="padding-top:0;">
            <div class="nav-section-content">
                <a class="nav-item" href="register.php" target="_blank" style="display: flex; align-items: center; width: 100%;">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                    <span>Registration Form</span>
                    <span onclick="copyFormLink('register.php', this, event)" class="copy-link-btn" title="Copy Shareable Link">
                        <i class="far fa-copy" style="font-size: 0.85rem; pointer-events: none;"></i>
                    </span>
                </a>
                
                <a class="nav-item" href="studyplan.php" target="_blank" style="display: flex; align-items: center; width: 100%;">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                    <span>Study Plan</span>
                    <span onclick="copyFormLink('studyplan.php', this, event)" class="copy-link-btn" title="Copy Shareable Link">
                        <i class="far fa-copy" style="font-size: 0.85rem; pointer-events: none;"></i>
                    </span>
                </a>
                
                <a class="nav-item" href="installmentpayment.php" target="_blank" style="display: flex; align-items: center; width: 100%;">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                    <span>Installment Payment</span>
                    <span onclick="copyFormLink('installmentpayment.php', this, event)" class="copy-link-btn" title="Copy Shareable Link">
                        <i class="far fa-copy" style="font-size: 0.85rem; pointer-events: none;"></i>
                    </span>
                </a>
                
                <a class="nav-item" href="staff-registration.php" target="_blank" style="display: flex; align-items: center; width: 100%;">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                    <span>Staff Registration</span>
                    <span onclick="copyFormLink('staff-registration.php', this, event)" class="copy-link-btn" title="Copy Shareable Link">
                        <i class="far fa-copy" style="font-size: 0.85rem; pointer-events: none;"></i>
                    </span>
                </a>
            </div>
        </div>

        <script>
        function copyFormLink(path, element, event) {
            event.preventDefault();
            event.stopPropagation();
            
            var link = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')) + '/' + path;
            
            navigator.clipboard.writeText(link).then(function() {
                var icon = element.querySelector('i');
                icon.className = 'fas fa-check';
                icon.style.color = '#22c55e';
                
                setTimeout(function() {
                    icon.className = 'far fa-copy';
                    icon.style.color = '';
                }, 1500);
            }).catch(function(err) {
                console.error('Could not copy link: ', err);
            });
        }
        </script>

        <div class="sidebar-footer">
            <a class="nav-item" href="?logout=1" onclick="return confirm('Log out of the admin console?');">
                <i class="fas fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </aside>

    <!-- ── MAIN ── -->
    <div class="main-area">
        <header class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle menu"><i class="fas fa-bars"></i></button>
            <div>
                <h1><?php echo e($page_title); ?></h1>
                <?php if ($page_sub): ?><div class="page-sub"><?php echo e($page_sub); ?></div><?php endif; ?>
            </div>
            <div class="topbar-right">
                <button type="button" class="reminder-bell" id="theme-toggle-btn" style="margin-right:4px;" title="Switch Theme" aria-label="Switch Theme">
                    <i class="fas fa-sun" id="theme-toggle-icon"></i>
                </button>
                <button type="button" class="reminder-bell <?php echo !empty($nav_reminders_due) ? 'has-due' : ''; ?>" onclick="openModal('reminders-modal')" title="Reminders" aria-label="Reminders">
                    <i class="fas fa-bell"></i>
                    <?php if (!empty($nav_reminders_pending)): ?><span class="reminder-count"><?php echo count($nav_reminders_pending); ?></span><?php endif; ?>
                </button>
                <div class="admin-chip">
                    <span class="avatar"><?php echo strtoupper(substr($admin_username, 0, 1)); ?></span>
                    <span><?php echo e($admin_username); ?>
                        <span style="display:block;font-size:.62rem;color:var(--muted-foreground);font-weight:600;line-height:1.1;">
                            <?php echo is_super_admin() ? 'Super Admin' : 'Admin'; ?>
                        </span>
                    </span>
                </div>
            </div>
        </header>

        <main class="content">
