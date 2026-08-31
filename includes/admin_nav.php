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

// Reminders: load helper, materialize recurring occurrences, and collect due/pending for the bell.
$nav_reminders_due = [];
$nav_reminders_pending = [];
if (file_exists(__DIR__ . '/reminders_helper.php')) {
    require_once __DIR__ . '/reminders_helper.php';
    try {
        // Due emails disabled — in-app notifications used exclusively.
        // reminders_send_due_emails($pdo); // DEPRECATED
        $nav_reminders_due     = reminders_due($pdo, $admin_username);
        $nav_reminders_pending = reminders_for($pdo, $admin_username, ['pending']);
    } catch (Exception $e) { error_log('nav reminders: ' . $e->getMessage()); }
}

// Background Reminders & Campaigns: runs lazily on page loads throttled with a 30s cooldown.
try {
    $now = time();
    $cooldown = 30; // 30 seconds cooldown between lazy background triggers

    // Fetch last check timestamp from admin_settings
    $stmtLazy = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_last_lazy_trigger' LIMIT 1");
    $stmtLazy->execute();
    $lastLazyTime = (int)$stmtLazy->fetchColumn();

    if (($now - $lastLazyTime) >= $cooldown) {
        // Atomically update check timestamp
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('whatsapp_last_lazy_trigger', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")->execute([(string)$now]);
        } else {
            $pdo->prepare("INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('whatsapp_last_lazy_trigger', ?, datetime('now'))")->execute([(string)$now]);
        }

        // Automatic session reminders (12h / 4h / 10m / start)
        if (file_exists(__DIR__ . '/session_cron.php')) {
            require_once __DIR__ . '/session_cron.php';
            try {
                if (function_exists('sessions_dispatch_due')) sessions_dispatch_due($pdo);
                if (function_exists('installments_dispatch_reminders')) installments_dispatch_reminders($pdo);
                if (function_exists('installments_dispatch_whatsapp_reminders')) installments_dispatch_whatsapp_reminders($pdo);
            } catch (Exception $e) { error_log('nav session/installment cron: ' . $e->getMessage()); }
        }

        // Recurring Task Occurrence Materializer (idempotent, concurrency-safe)
        if (function_exists('task_reminders_materialize_occurrences')) {
            try {
                task_reminders_materialize_occurrences($pdo);
            } catch (Exception $e) { error_log('nav materializer: ' . $e->getMessage()); }
        }
        // Email Campaigns: run due email campaigns
        if (file_exists(__DIR__ . '/email_campaigns_helper.php')) {
            require_once __DIR__ . '/email_campaigns_helper.php';
            try {
                email_campaigns_send_due($pdo);
            } catch (Exception $e) { error_log('nav email campaigns cron: ' . $e->getMessage()); }
        }

        // Trigger a tiny processing batch in the background (e.g., maximum 3 queue generation and 3 dispatches)
        if (file_exists(dirname(__DIR__) . '/cron-queue.php')) {
            $schedStmt = $pdo->prepare("
                SELECT * FROM communication_campaigns
                WHERE status IN ('scheduled', 'active')
                  AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                  LIMIT 1
            ");
            $schedStmt->execute();
            $dueCampaign = $schedStmt->fetch();

            if ($dueCampaign) {
                $campId = $dueCampaign['id'];

                $pdo->beginTransaction();

                $isMysql = (strpos($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql') !== false);
                $forUpdate = $isMysql ? ' FOR UPDATE' : '';

                // Fetch at most 3 recipients snapshot to avoid delays in admin browsing
                $stmtRec = $pdo->prepare("
                    SELECT * FROM communication_campaign_recipients
                    WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL
                    LIMIT 3
                    " . $forUpdate
                );
                $stmtRec->execute([$campId]);
                $recipients = $stmtRec->fetchAll();

                if (!empty($recipients)) {
                    if ($dueCampaign['status'] === 'scheduled') {
                        $pdo->prepare("UPDATE communication_campaigns SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$campId]);
                    }

                    $stmtTpl = $pdo->prepare("SELECT * FROM communication_templates WHERE template_name = ? LIMIT 1");
                    $stmtTpl->execute([$dueCampaign['template_name']]);
                    $template = $stmtTpl->fetch();

                    if ($template) {
                        $criteria = json_decode($dueCampaign['segment_criteria'], true) ?: [];
                        $varMappings = $criteria['var_mappings'] ?? [];
                        $staticVals = $criteria['static_vals'] ?? [];
                        $mediaUrl = $criteria['header_media'] ?? '';
                        $meta = json_decode($template['meta_data'], true) ?: [];

                        require_once dirname(__DIR__) . '/includes/communication/CommunicationEngine.php';
                        $engine = CommunicationEngine::getInstance($pdo);

                        foreach ($recipients as $rec) {
                            $resolvedParams = [];
                            if ($dueCampaign['target_audience'] === 'leads') {
                                $stmtLead = $pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
                                $stmtLead->execute([$rec['lead_id']]);
                                $lead = $stmtLead->fetch();
                                if ($lead) {
                                    if ((int)($lead['is_opted_out'] ?? 0) === 1) {
                                        $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = 'Lead opted out before queueing' WHERE id = ?")->execute([$rec['id']]);
                                        continue;
                                    }

                                    $skippedParam = '';
                                    foreach ($varMappings as $idx => $field) {
                                        $val = ($field === 'static') ? ($staticVals[$idx] ?? '') : ($lead[$field] ?? '');
                                        if ($val === null || trim((string)$val) === '') {
                                            $skippedParam = ($field === 'static') ? "static_var_{$idx}" : $field;
                                            break;
                                        }
                                        $resolvedParams[] = trim((string)$val);
                                    }
                                    if ($skippedParam !== '') {
                                        $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = ? WHERE id = ?")->execute(["Skipped: Required template parameter '{$skippedParam}' is empty.", $rec['id']]);
                                        continue;
                                    }
                                }
                            } else {
                                $stmtUser = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
                                $stmtUser->execute([$rec['user_id']]);
                                $user = $stmtUser->fetch();
                                if ($user) {
                                    $skippedParam = '';
                                    foreach ($varMappings as $idx => $field) {
                                        $val = ($field === 'static') ? ($staticVals[$idx] ?? '') : ($user[$field] ?? '');
                                        if ($val === null || trim((string)$val) === '') {
                                            $skippedParam = ($field === 'static') ? "static_var_{$idx}" : $field;
                                            break;
                                        }
                                        $resolvedParams[] = trim((string)$val);
                                    }
                                    if ($skippedParam !== '') {
                                        $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = ? WHERE id = ?")->execute(["Skipped: Required template parameter '{$skippedParam}' is empty.", $rec['id']]);
                                        continue;
                                    }
                                }
                            }

                            $templatePayload = [
                                'name' => $dueCampaign['template_name'],
                                'language' => $template['language'] ?: 'en',
                                'parameters' => $resolvedParams
                            ];

                             $headerType = $meta['header_type'] ?? 'NONE';
                             if ($headerType === 'NONE' && !empty($meta['components'])) {
                                 foreach ($meta['components'] as $c) {
                                     if (($c['type'] ?? '') === 'HEADER') {
                                         $headerType = $c['format'] ?? 'NONE';
                                         break;
                                     }
                                 }
                             }

                             $mediaUrl = $criteria['header_media'] ?? '';
                             if (empty($mediaUrl)) {
                                 $fallbackUrl = $meta['header_media_url'] ?? '';
                                 if (!empty($fallbackUrl) && strpos($fallbackUrl, 'scontent.whatsapp.net') === false && strpos($fallbackUrl, 'fbcdn.net') === false) {
                                     $mediaUrl = $fallbackUrl;
                                 }
                             }

                             if ($headerType !== 'NONE' && $headerType !== 'TEXT' && !empty($mediaUrl)) {
                                 $templatePayload['header_type'] = $headerType;
                                 $templatePayload['header_parameters'] = [$mediaUrl];
                             }

                            $body = "Campaign message: {$dueCampaign['name']}";
                            $queueId = $engine->queueMessage(
                                'whatsapp',
                                $rec['recipient'],
                                $rec['recipient_name'],
                                $dueCampaign['name'],
                                $body,
                                $body,
                                [],
                                $templatePayload,
                                $dueCampaign['created_by'],
                                date('Y-m-d H:i:s'),
                                $rec['user_id']
                            );

                            $pdo->prepare("UPDATE communication_campaign_recipients SET queue_id = ?, status = 'pending' WHERE id = ?")->execute([$queueId, $rec['id']]);
                        }
                        $pdo->commit();
                    } else {
                        $pdo->prepare("UPDATE communication_campaign_recipients SET status = 'failed', error_message = 'Marketing template not found or approved' WHERE campaign_id = ? AND status = 'pending' AND queue_id IS NULL")->execute([$campId]);
                        $pdo->commit();
                    }
                } else {
                    $pdo->commit();

                    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM communication_campaign_recipients WHERE campaign_id = {$campId} AND queue_id IS NULL")->fetchColumn();
                    if ($pendingCount === 0 && $dueCampaign['status'] === 'active') {
                        $pdo->prepare("UPDATE communication_campaigns SET status = 'completed', updated_at = NOW() WHERE id = ?")->execute([$campId]);
                    }
                }
            }

            // Process a tiny batch of 3 pending queue items
            require_once dirname(__DIR__) . '/includes/communication/QueueProcessor.php';
            $processor = new QueueProcessor($pdo, 3);
            $processor->execute();
        }
    }
} catch (Exception $lazyEx) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Lazy campaign trigger failed: " . $lazyEx->getMessage());
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
        case 'whatsapp-marketing-templates':
            echo '<a class="nav-item ' . nav_active('whatsapp-marketing-templates', $active_page) . '" href="whatsapp-marketing-templates.php"><i class="fas fa-layer-group"></i> Marketing Templates</a>';
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
        case 'mentor-reports':
            if (is_super_admin()) {
                echo '<a class="nav-item ' . nav_active('mentor-reports', $active_page) . '" href="mentor-reports.php"><i class="fas fa-user-tie"></i> Mentors Report</a>';
            }
            break;
        case 'assessment-results':
            echo '<a class="nav-item ' . nav_active('assessment-results', $active_page) . '" href="assessment-results.php"><i class="fas fa-chart-column"></i> Mega Test Results</a>';
            break;
        case 'task-reminders':
            echo '<a class="nav-item ' . nav_active('task-reminders', $active_page) . '" href="task-reminders.php"><i class="fas fa-bell"></i> Task Reminders</a>';
            break;
        case 'task-tracker':
            echo '<a class="nav-item ' . nav_active('task-tracker', $active_page) . '" href="task-tracker.php"><i class="fas fa-list-check"></i> Intern Task Tracker</a>';
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
        'items' => ['dashboard', 'task-reminders']
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
        'items' => ['installments', 'invoices', 'communication', 'whatsapp-marketing-templates', 'whatsapp']
    ],
    [
        'id' => 'academics',
        'title' => 'Academics',
        'icon' => 'fas fa-graduation-cap',
        'items' => ['courses', 'faculties', 'studyplans', 'student-study-reports', 'mentor-reports', 'assessment-results']
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

        /* Selected/active sub-category menu styles: violet background with white icon & font */
        .nav-item.active {
            background: #7c3aed !important;
            color: #ffffff !important;
            font-weight: 600;
        }
        .nav-item.active i {
            color: #ffffff !important;
        }
        html.theme-dark .nav-item.active {
            background: #7c3aed !important;
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

        .nav-section-label.cat-public-links {
            background: rgba(56, 189, 248, 0.12) !important;
            color: #0284c7 !important;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .nav-section-label.cat-public-links:hover {
            background: rgba(56, 189, 248, 0.22) !important;
            color: #0369a1 !important;
        }
        html.theme-dark .nav-section-label.cat-public-links {
            background: rgba(56, 189, 248, 0.22) !important;
            color: #38bdf8 !important;
        }
        html.theme-dark .nav-section-label.cat-public-links:hover {
            background: rgba(56, 189, 248, 0.32) !important;
            color: #0ea5e9 !important;
        }
        html.theme-sepia .nav-section-label.cat-public-links {
            color: #0369a1 !important;
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

        <div class="nav-section collapsed">
            <div class="nav-section-label cat-public-links">
                <span>
                    <i class="fas fa-link" style="margin-right:6px; font-size:0.8rem; opacity:0.8;"></i>
                    Public Links
                </span>
            </div>
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

                <a class="nav-item" href="alumni-portal.php" target="_blank" style="display: flex; align-items: center; width: 100%;">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                    <span>Alumni Portal</span>
                    <span onclick="copyFormLink('alumni-portal.php', this, event)" class="copy-link-btn" title="Copy Shareable Link">
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

        // ── NON-BLOCKING GEOLOCATION INITIALIZATION FLOW ──
        function setPeppCookie(name, value, days) {
            var expires = "";
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = name + "=" + encodeURIComponent(value || "") + expires + "; path=/; SameSite=Lax";
        }
        function getPeppCookie(name) {
            var nameEQ = name + "=";
            var ca = document.cookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) == ' ') c = c.substring(1);
                if (c.indexOf(nameEQ) == 0) return decodeURIComponent(c.substring(nameEQ.length));
            }
            return null;
        }

        function showPeppLocationBanner(message, isError) {
            var banner = document.getElementById('pepp-location-banner');
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'pepp-location-banner';
                document.body.appendChild(banner);
            }
            banner.style = "position:fixed; bottom:20px; right:20px; width:350px; background:#1e293b; color:#fff; z-index:999999; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.3); border:1px solid " + (isError ? "#ef4444" : "#7c3aed") + "; padding:16px; font-family:'Space Grotesk',sans-serif; transition:all 0.3s ease;";
            banner.innerHTML = `
                <div style="display:flex; flex-direction:column; gap:12px; text-align:left;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="color:${isError ? '#ef4444' : '#7c3aed'}; font-size:16px;"><i class="fas fa-location-dot"></i></span>
                        <span style="font-weight:600; font-size:14px; color:#f8fafc;">Location Verification</span>
                    </div>
                    <p style="margin:0; font-size:13px; color:#94a3b8; line-height:1.4; font-family:'DM Sans',sans-serif;">
                        ${message}
                    </p>
                    <button onclick="requestPeppLocation()" style="background:${isError ? '#ef4444' : '#7c3aed'}; color:#fff; border:none; padding:8px 16px; font-size:13px; font-weight:600; border-radius:6px; cursor:pointer; transition:all 0.15s; width:100%; display:flex; align-items:center; justify-content:center; gap:6px; font-family:'Space Grotesk',sans-serif;">
                        <i class="fas fa-location-crosshairs"></i> ${isError ? 'Try Again' : 'Enable Location'}
                    </button>
                </div>
            `;
        }

        function removePeppLocationBanner() {
            var banner = document.getElementById('pepp-location-banner');
            if (banner) {
                banner.remove();
            }
        }

        function requestPeppLocation() {
            if (!navigator.geolocation) {
                showPeppLocationBanner("Geolocation is not supported by your browser. Activity location tracking is unavailable.", true);
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    var lat = position.coords.latitude;
                    var lng = position.coords.longitude;
                    var accuracy = position.coords.accuracy;
                    var connectionType = 'unknown';
                    if (navigator.connection && navigator.connection.effectiveType) {
                        connectionType = navigator.connection.effectiveType;
                    }

                    var meta = {
                        user_agent: navigator.userAgent,
                        platform: navigator.platform,
                        screen_width: window.screen.width,
                        screen_height: window.screen.height,
                        viewport_width: window.innerWidth,
                        viewport_height: window.innerHeight,
                        device_pixel_ratio: window.devicePixelRatio,
                        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                        language: navigator.language,
                        accuracy: accuracy,
                        connection: connectionType
                    };

                    setPeppCookie('pepp_lat', lat, 1);
                    setPeppCookie('pepp_lng', lng, 1);
                    setPeppCookie('pepp_meta', JSON.stringify(meta), 1);

                    removePeppLocationBanner();

                    // Send heartbeat immediately to register geolocation in activity logger
                    fetch('api/activity-heartbeat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            page: <?php echo json_encode(basename($_SERVER['SCRIPT_NAME'])); ?>,
                            module: <?php echo json_encode($cur_sec ?? 'Other'); ?>,
                            section: <?php echo json_encode($cur_sec ?? 'Other'); ?>,
                            is_idle: 0,
                            latitude: lat,
                            longitude: lng
                        })
                    }).catch(function(e) {});
                },
                function(error) {
                    var msg = "Location access is required for activity tracking.";
                    if (error.code === error.PERMISSION_DENIED) {
                        msg = "Location access is disabled. Activity location tracking is unavailable.";
                    } else if (error.code === error.POSITION_UNAVAILABLE) {
                        msg = "GPS/Location position is unavailable. Activity location tracking is unavailable.";
                    } else if (error.code === error.TIMEOUT) {
                        msg = "Location request timed out. Activity location tracking is unavailable.";
                    }
                    showPeppLocationBanner(msg, true);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }

        // Run check on page load
        (function() {
            var lat = getPeppCookie('pepp_lat');
            var lng = getPeppCookie('pepp_lng');
            if (!lat || !lng) {
                showPeppLocationBanner("Location access is required for activity tracking.", false);
                requestPeppLocation();
            }
        })();
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
                <div class="task-dropdown-container" id="task-dropdown-container">
                    <button type="button" class="reminder-bell <?php echo !empty($nav_reminders_due) ? 'has-due' : ''; ?>" id="task-reminders-bell-btn" onclick="toggleTaskRemindersDropdown(event)" title="Task Reminders" aria-label="Task Reminders">
                        <i class="fas fa-bell"></i>
                        <span class="reminder-count" id="task-reminders-badge" style="<?php echo !empty($nav_reminders_pending) ? '' : 'display:none;'; ?>">
                            <?php echo count($nav_reminders_pending ?? []); ?>
                        </span>
                    </button>
                    <div id="task-reminders-dropdown" class="task-dropdown-menu" style="display:none;">
                        <div class="task-dropdown-head">
                            <div style="font-weight:700; font-size:0.92rem; color:var(--foreground,#0f172a); display:flex; align-items:center; gap:6px;">
                                <i class="fas fa-bell" style="color:var(--primary,#7c3aed);"></i> Task Reminders
                            </div>
                            <div id="task-dropdown-counts" style="font-size:0.75rem; font-weight:700;"></div>
                        </div>
                        <div id="task-dropdown-list" class="task-dropdown-list">
                            <div style="text-align:center; padding:16px; color:#94a3b8; font-size:0.85rem;"><i class="fas fa-spinner fa-spin"></i> Loading tasks...</div>
                        </div>
                        <div class="task-dropdown-foot" style="display:flex; gap:8px;">
                            <button type="button" class="btn btn-sm btn-outline" style="flex:1;" onclick="openCreateTaskModal(); closeTaskRemindersDropdown();">
                                <i class="fas fa-plus"></i> New Task
                            </button>
                            <a href="task-reminders.php" class="btn btn-sm btn-primary" style="flex:1; text-align:center; justify-content:center;">
                                View All Tasks &rarr;
                            </a>
                        </div>
                    </div>
                </div>
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
