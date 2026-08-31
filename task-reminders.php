<?php
/**
 * PEPP Learning — Task Reminders & Accountability Dashboard
 * Dedicated full page for managing personal tasks, monitoring assigned tasks,
 * and reviewing permanent audit history.
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/reminders_helper.php';
require_permission('task-reminders');

$active_page = 'task-reminders';
$page_title  = 'Task Reminders';
$page_sub    = 'Staff accountability, scheduled task tracking & permanent audit history';

$current_username = get_admin_user();
$admin_identity = task_reminder_get_admin_identity($pdo, $current_username);
$current_admin_id = $admin_identity['id'];
$is_super = is_super_admin();

// Load active task types for create/filter dropdowns
$active_task_types = [];
try {
    $active_task_types = task_types_get_all($pdo, true);
} catch (Exception $e) {}

// Load all admin users for assignment dropdown
$all_admins = [];
try {
    if (admins_table_exists($pdo)) {
        $all_admins = $pdo->query("SELECT id, username, full_name, role FROM admins WHERE status = 'active' ORDER BY full_name ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// Load summary metrics for current admin
$summary = task_reminders_get_summary($pdo, $current_admin_id, $current_username);

// KPI Stats
$my_tasks_all = task_reminders_list_my_tasks($pdo, $current_admin_id, $current_username);
$my_completed_count = 0;
$my_overdue_count = 0;
$my_pending_count = 0;
$my_in_progress_count = 0;

foreach ($my_tasks_all as $t) {
    if ($t['status'] === 'completed') {
        $my_completed_count++;
    } elseif ($t['status'] === 'in_progress') {
        $my_in_progress_count++;
        if ($t['is_overdue']) $my_overdue_count++;
    } elseif ($t['status'] === 'pending') {
        if ($t['is_overdue']) {
            $my_overdue_count++;
        } else {
            $my_pending_count++;
        }
    }
}

$assigned_by_me_all = task_reminders_list_assigned_by_me($pdo, $current_admin_id, $current_username, [], $is_super);
$assigned_by_me_total = count($assigned_by_me_all);$extra_head = '
<style>
/* ── Task Reminders Module Modernized Styles ── */
.task-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}
.task-kpi-card {
    background: var(--surface, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 12px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.task-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
}
html.theme-dark .task-kpi-card {
    background: #1e293b;
    border-color: #334155;
}
.task-kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.task-kpi-val {
    font-size: 1.55rem;
    font-weight: 700;
    line-height: 1.1;
    font-family: "Space Grotesk", sans-serif;
    color: var(--foreground, #0f172a);
}
.task-kpi-lbl {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--secondary, #64748b);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

/* ── Tab Navigation ── */
.task-tabs-nav {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid var(--border, #e2e8f0);
    margin-bottom: 20px;
    padding-bottom: 12px;
}
.task-tabs-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.task-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 18px;
    font-family: "Space Grotesk", sans-serif;
    font-weight: 600;
    font-size: 0.88rem;
    color: var(--foreground, #334155);
    background: var(--surface, #ffffff);
    border: 1px solid var(--border, #cbd5e1);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.task-tab-btn:hover {
    background: var(--background, #f8fafc);
    border-color: #94a3b8;
}
.task-tab-btn.active {
    color: #ffffff !important;
    background: var(--primary, #7c3aed) !important;
    border-color: var(--primary, #7c3aed) !important;
    box-shadow: 0 2px 6px rgba(124, 58, 237, 0.25);
}
html.theme-dark .task-tab-btn {
    background: #1e293b;
    border-color: #334155;
    color: #cbd5e1;
}
html.theme-dark .task-tab-btn.active {
    background: var(--primary, #7c3aed) !important;
    border-color: var(--primary, #7c3aed) !important;
    color: #fff !important;
}
.task-tab-pane {
    display: none;
}
.task-tab-pane.active {
    display: block;
}

/* ── Sub-filter pills ── */
.task-subfilters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.subfilter-pill {
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 0.8rem;
    font-weight: 600;
    background: var(--background, #f1f5f9);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--secondary, #475569);
    cursor: pointer;
    transition: all 0.15s ease;
}
.subfilter-pill:hover {
    background: #e2e8f0;
    color: #1e293b;
}
.subfilter-pill.active {
    background: var(--foreground, #0f172a);
    color: #ffffff;
    border-color: var(--foreground, #0f172a);
}
html.theme-dark .subfilter-pill {
    background: #1e293b;
    border-color: #334155;
    color: #94a3b8;
}
html.theme-dark .subfilter-pill.active {
    background: var(--primary, #7c3aed);
    border-color: var(--primary, #7c3aed);
    color: #fff;
}

/* ── Modern Status Badges ── */
.status-badge-pending {
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.76rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.status-badge-inprogress {
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.76rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.status-badge-overdue {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.76rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.status-badge-completed {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.76rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.status-badge-cancelled {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.76rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

/* ── Modern Task Action Card ── */
.task-action-card {
    background: var(--surface, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 10px;
    transition: box-shadow 0.15s ease, border-color 0.15s ease;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.task-action-card:hover {
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
    border-color: #cbd5e1;
}
html.theme-dark .task-action-card {
    background: #1e293b;
    border-color: #334155;
}
.task-action-card.card-overdue {
    border-left: 4px solid #dc2626;
    background: #fffafa;
}
html.theme-dark .task-action-card.card-overdue {
    background: #2a1b1b;
}
.task-action-card.card-pending {
    border-left: 4px solid #f59e0b;
}
.task-action-card.card-inprogress {
    border-left: 4px solid #0284c7;
}
.task-action-card.card-completed {
    border-left: 4px solid #16a34a;
    opacity: 0.92;
}

/* ── Timeline Styles ── */
.task-timeline {
    position: relative;
    padding-left: 24px;
    margin-top: 12px;
}
.task-timeline::before {
    content: "";
    position: absolute;
    left: 8px;
    top: 4px;
    bottom: 4px;
    width: 2px;
    background: var(--border, #e2e8f0);
}
.timeline-item {
    position: relative;
    margin-bottom: 16px;
}
.timeline-dot {
    position: absolute;
    left: -24px;
    top: 3px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--primary, #7c3aed);
    border: 2px solid var(--surface, #ffffff);
    box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.2);
}
.timeline-content {
    background: var(--background, #f8fafc);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.85rem;
}
html.theme-dark .timeline-content {
    background: #0f172a;
    border-color: #334155;
}
.timeline-time {
    font-size: 0.74rem;
    color: var(--secondary, #64748b);
    font-weight: 600;
    margin-bottom: 2px;
}
.timeline-event {
    font-weight: 700;
    color: var(--foreground, #0f172a);
    display: inline-block;
}
.timeline-remarks {
    font-style: italic;
    color: var(--secondary, #475569);
    margin-top: 4px;
    font-size: 0.82rem;
}
</style>
';

include 'includes/header.php';
include 'includes/admin_nav.php';
?>

<!-- ── TOP KPI METRICS BAR ── -->
<div class="task-kpi-grid">
    <div class="task-kpi-card">
        <div class="task-kpi-icon" style="background:#fef3c7; color:#b45309;">
            <i class="fas fa-clock"></i>
        </div>
        <div>
            <div class="task-kpi-val" id="kpi-my-pending"><?php echo $my_pending_count; ?></div>
            <div class="task-kpi-lbl">My Pending</div>
        </div>
    </div>

    <div class="task-kpi-card">
        <div class="task-kpi-icon" style="background:#fee2e2; color:#dc2626;">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <div>
            <div class="task-kpi-val" id="kpi-my-overdue"><?php echo $my_overdue_count; ?></div>
            <div class="task-kpi-lbl">My Overdue</div>
        </div>
    </div>

    <div class="task-kpi-card">
        <div class="task-kpi-icon" style="background:#e0f2fe; color:#0284c7;">
            <i class="fas fa-spinner"></i>
        </div>
        <div>
            <div class="task-kpi-val" id="kpi-my-inprogress"><?php echo $my_in_progress_count; ?></div>
            <div class="task-kpi-lbl">In Progress</div>
        </div>
    </div>

    <div class="task-kpi-card">
        <div class="task-kpi-icon" style="background:#ede9fe; color:#7c3aed;">
            <i class="fas fa-paper-plane"></i>
        </div>
        <div>
            <div class="task-kpi-val" id="kpi-assigned-by-me"><?php echo $assigned_by_me_total; ?></div>
            <div class="task-kpi-lbl">Assigned by Me</div>
        </div>
    </div>

    <div class="task-kpi-card">
        <div class="task-kpi-icon" style="background:#dcfce7; color:#16a34a;">
            <i class="fas fa-circle-check"></i>
        </div>
        <div>
            <div class="task-kpi-val" id="kpi-my-completed"><?php echo $my_completed_count; ?></div>
            <div class="task-kpi-lbl">My Completed</div>
        </div>
    </div>
</div>

<!-- ── TAB CONTROLS & NEW TASK ACTION ── -->
<div class="task-tabs-nav">
    <div class="task-tabs-group">
        <button type="button" class="task-tab-btn active" onclick="switchTaskTab('my-tasks')">
            <i class="fas fa-list-check"></i> My Tasks
        </button>
        <button type="button" class="task-tab-btn" onclick="switchTaskTab('assigned-by-me')">
            <i class="fas fa-binoculars"></i> Assigned by Me
        </button>
        <button type="button" class="task-tab-btn" onclick="switchTaskTab('task-history')">
            <i class="fas fa-clock-rotate-left"></i> History
        </button>
        <button type="button" class="task-tab-btn" onclick="switchTaskTab('recurring-series')">
            <i class="fas fa-repeat"></i> Recurring Series
        </button>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openCreateTaskModal()">
            <i class="fas fa-plus"></i> New Task Reminder
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: MY TASKS                                                        -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="pane-my-tasks" class="task-tab-pane active">
    <div class="panel">
        <div class="panel-head" style="justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="head-icon" style="background:var(--accent-soft,#ede9fe); color:var(--primary,#7c3aed);"><i class="fas fa-list-check"></i></span>
                <h2>My Tasks</h2>
            </div>
            <div class="task-subfilters">
                <span class="subfilter-pill active" onclick="filterMyTasks('all', this)">All</span>
                <span class="subfilter-pill" onclick="filterMyTasks('pending', this)">Pending</span>
                <span class="subfilter-pill" onclick="filterMyTasks('in_progress', this)">In Progress</span>
                <span class="subfilter-pill" onclick="filterMyTasks('overdue', this)">Overdue</span>
                <span class="subfilter-pill" onclick="filterMyTasks('completed', this)">Completed</span>
            </div>
        </div>
        <div class="panel-body">
            <div class="filter-bar" style="margin-bottom:18px;">
                <div class="field" style="flex:1;">
                    <input type="text" id="my-task-search" placeholder="Search tasks by title or notes..." onkeyup="debounceLoadMyTasks()">
                </div>
                <div class="field">
                    <select id="my-task-type-filter" onchange="loadMyTasks()">
                        <option value="">All Task Types</option>
                        <?php foreach ($active_task_types as $tt): ?>
                            <option value="<?php echo $tt['id']; ?>"><?php echo e($tt['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Modern Task Card List Container -->
            <div id="my-tasks-list-container">
                <div style="text-align:center; padding:32px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading tasks...</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2: ASSIGNED BY ME (DELEGATION & MONITORING)                        -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="pane-assigned-by-me" class="task-tab-pane">
    <div class="panel">
        <div class="panel-head" style="justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="head-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fas fa-binoculars"></i></span>
                <h2>Tasks Assigned by Me (Monitoring)</h2>
            </div>
            <div class="task-subfilters">
                <span class="subfilter-pill active" onclick="filterAssignedByMe('all', this)">All</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('pending', this)">Pending</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('in_progress', this)">In Progress</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('overdue', this)">Overdue</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('completed', this)">Completed</span>
            </div>
        </div>
        <div class="panel-body">
            <div class="filter-bar" style="margin-bottom:18px;">
                <div class="field" style="flex:1;">
                    <input type="text" id="assigned-search" placeholder="Search delegated tasks by title..." onkeyup="debounceLoadAssigned()">
                </div>
                <div class="field">
                    <select id="assigned-to-filter" onchange="loadAssignedByMe()">
                        <option value="">All Assignees</option>
                        <?php foreach ($all_admins as $adm): ?>
                            <option value="<?php echo e($adm['username']); ?>"><?php echo e($adm['full_name'] ?: $adm['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Delegated Tasks List Container -->
            <div id="assigned-by-me-list-container">
                <div style="text-align:center; padding:32px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading delegated tasks...</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3: AUDIT HISTORY / ALL LIFECYCLE EVENTS                            -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="pane-task-history" class="task-tab-pane">
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon" style="background:#f1f5f9; color:#475569;"><i class="fas fa-clock-rotate-left"></i></span>
            <h2>Task Reminders Audit History</h2>
        </div>
        <div class="panel-body">
            <div class="filter-bar" style="margin-bottom:18px;">
                <div class="field">
                    <select id="history-event-filter" onchange="loadHistory()">
                        <option value="">All Events</option>
                        <option value="CREATED">Created</option>
                        <option value="ASSIGNED">Assigned</option>
                        <option value="REASSIGNED">Reassigned</option>
                        <option value="STARTED">Started</option>
                        <option value="POSTPONED">Postponed</option>
                        <option value="COMPLETED">Completed</option>
                        <option value="CANCELLED">Cancelled</option>
                    </select>
                </div>
                <div class="field">
                    <select id="history-admin-filter" onchange="loadHistory()">
                        <option value="">All Admins</option>
                        <?php foreach ($all_admins as $adm): ?>
                            <option value="<?php echo e($adm['username']); ?>"><?php echo e($adm['full_name'] ?: $adm['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <input type="date" id="history-date-from" onchange="loadHistory()" placeholder="From Date">
                </div>
                <div class="field">
                    <input type="date" id="history-date-to" onchange="loadHistory()" placeholder="To Date">
                </div>
                <button type="button" class="btn btn-outline" onclick="resetHistoryFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Event</th>
                            <th>Task Title</th>
                            <th>Changed By</th>
                            <th>Remarks</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="task-history-tbody">
                        <tr><td colspan="6" style="text-align:center; padding:32px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading history...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 4: RECURRING SERIES MONITORING                                    -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="pane-recurring-series" class="task-tab-pane">
    <div class="panel">
        <div class="panel-head" style="justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div>
                <h2><i class="fas fa-repeat" style="color:var(--primary,#7c3aed);"></i> Recurring Task Series</h2>
                <p style="font-size:0.8rem; color:#94a3b8; margin-top:2px;">Monitor active and stopped recurring series with occurrence stats</p>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <select id="series-status-filter" onchange="loadRecurringSeries()" style="font-size:0.84rem; padding:6px 12px; border-radius:6px; border:1px solid var(--border,#cbd5e1); font-weight:600;">
                    <option value="active">Active Series</option>
                    <option value="stopped">Stopped Series</option>
                    <option value="all">All Series</option>
                </select>
            </div>
        </div>
        <div id="recurring-series-list" style="padding:16px;">
            <div style="text-align:center; padding:32px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading series...</div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TASK DETAILS & TIMELINE MODAL                                          -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="task-details-modal" class="modal-backdrop">
    <div class="modal-box" style="max-width:640px;">
        <div class="modal-head">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="width:30px; height:30px; border-radius:8px; background:var(--accent-soft, #ede9fe); color:var(--primary, #7c3aed); display:flex; align-items:center; justify-content:center; font-size:0.95rem;">
                    <i class="fas fa-circle-info"></i>
                </span>
                <h3 style="margin:0; font-size:1.1rem; font-weight:700;">Task Details &amp; History</h3>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('task-details-modal')">&times;</button>
        </div>
        <div class="modal-body" id="task-details-modal-body" style="padding:18px 20px;">
            <div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading details...</div>
        </div>
        <div class="modal-foot" style="padding:12px 20px; background:var(--background,#f8fafc); border-top:1px solid var(--border,#e2e8f0); display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-outline" onclick="closeModal('task-details-modal')">Close</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- COMPLETE TASK MODAL WITH REMARKS                                       -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="complete-task-modal" class="modal-backdrop">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="width:28px; height:28px; border-radius:6px; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; font-size:0.9rem;">
                    <i class="fas fa-circle-check"></i>
                </span>
                <h3 style="margin:0; font-size:1.05rem; font-weight:700;">Complete Task</h3>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('complete-task-modal')">&times;</button>
        </div>
        <form id="complete-task-form" onsubmit="submitCompleteTask(event)">
            <input type="hidden" id="complete-task-id" name="task_id">
            <div class="modal-body" style="padding:18px 20px;">
                <p id="complete-task-prompt" style="font-size:0.92rem; font-weight:700; margin-bottom:14px; color:var(--foreground,#0f172a);"></p>
                <div class="field">
                    <label style="font-weight:600; font-size:0.84rem;">Completion Remarks / Outcome</label>
                    <textarea id="complete-task-remarks" name="remarks" rows="3" placeholder="e.g. Student confirmed installment payment schedule..." style="width:100%; font-size:0.88rem;"></textarea>
                </div>
            </div>
            <div class="modal-foot" style="padding:12px 20px; background:var(--background,#f8fafc); border-top:1px solid var(--border,#e2e8f0); display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('complete-task-modal')">Cancel</button>
                <button type="submit" class="btn btn-success" id="complete-task-submit-btn"><i class="fas fa-check"></i> Mark Complete</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- REASSIGN TASK MODAL                                                    -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="reassign-task-modal" class="modal-backdrop">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-head">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="width:28px; height:28px; border-radius:6px; background:#ede9fe; color:#7c3aed; display:flex; align-items:center; justify-content:center; font-size:0.85rem;">
                    <i class="fas fa-user-gear"></i>
                </span>
                <h3 style="margin:0; font-size:1.05rem; font-weight:700;">Reassign Task</h3>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('reassign-task-modal')">&times;</button>
        </div>
        <form id="reassign-task-form" onsubmit="submitReassignTask(event)">
            <input type="hidden" id="reassign-task-id" name="task_id">
            <div class="modal-body" style="padding:18px 20px;">
                <p id="reassign-task-title" style="font-size:0.92rem; font-weight:700; margin-bottom:14px; color:var(--foreground,#0f172a);"></p>
                <div class="field">
                    <label style="font-weight:600; font-size:0.84rem;">Assign To *</label>
                    <select id="reassign-new-assignee" name="assigned_to" required style="width:100%;">
                        <?php foreach ($all_admins as $adm): ?>
                            <option value="<?php echo e($adm['username']); ?>"><?php echo e($adm['full_name'] ? ($adm['full_name'] . ' (' . $adm['username'] . ')') : $adm['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-foot" style="padding:12px 20px; background:var(--background,#f8fafc); border-top:1px solid var(--border,#e2e8f0); display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('reassign-task-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Reassign</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TASK REMINDERS CLIENT JAVASCRIPT                                       -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<script>
var currentMyTasksStatusFilter = 'all';
var currentAssignedStatusFilter = 'all';
var searchTimeout = null;

function switchTaskTab(tabId) {
    document.querySelectorAll('.task-tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.task-tab-pane').forEach(function(p) { p.classList.remove('active'); });

    var btn = document.querySelector('.task-tab-btn[onclick*="' + tabId + '"]');
    if (btn) btn.classList.add('active');

    var pane = document.getElementById('pane-' + tabId);
    if (pane) pane.classList.add('active');

    if (tabId === 'my-tasks') loadMyTasks();
    else if (tabId === 'assigned-by-me') loadAssignedByMe();
    else if (tabId === 'task-history') loadHistory();
    else if (tabId === 'recurring-series') loadRecurringSeries();

    if (history.replaceState) {
        history.replaceState(null, null, '#' + tabId);
    }
}

function filterMyTasks(status, el) {
    currentMyTasksStatusFilter = status;
    el.parentElement.querySelectorAll('.subfilter-pill').forEach(function(p) { p.classList.remove('active'); });
    el.classList.add('active');
    loadMyTasks();
}

function filterAssignedByMe(status, el) {
    currentAssignedStatusFilter = status;
    el.parentElement.querySelectorAll('.subfilter-pill').forEach(function(p) { p.classList.remove('active'); });
    el.classList.add('active');
    loadAssignedByMe();
}

function debounceLoadMyTasks() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadMyTasks, 300);
}

function debounceLoadAssigned() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadAssignedByMe, 300);
}

function renderStatusBadge(status, isOverdue) {
    if (status === 'completed') {
        return '<span class="status-badge-completed"><i class="fas fa-check"></i> Completed</span>';
    } else if (status === 'cancelled') {
        return '<span class="status-badge-cancelled"><i class="fas fa-ban"></i> Cancelled</span>';
    } else if (status === 'in_progress') {
        return '<span class="status-badge-inprogress"><i class="fas fa-spinner fa-spin"></i> In Progress</span>';
    } else if (isOverdue) {
        return '<span class="status-badge-overdue"><i class="fas fa-circle-exclamation"></i> Overdue</span>';
    } else {
        return '<span class="status-badge-pending"><i class="fas fa-clock"></i> Pending</span>';
    }
}

function loadMyTasks() {
    var searchEl = document.getElementById('my-task-search');
    var search = searchEl ? searchEl.value : '';
    var typeEl = document.getElementById('my-task-type-filter');
    var typeId = typeEl ? typeEl.value : '';
    var container = document.getElementById('my-tasks-list-container');
    if (!container) return;

    var url = 'api/task-reminders.php?action=list_my_tasks&status=' + encodeURIComponent(currentMyTasksStatusFilter) +
              '&task_type_id=' + encodeURIComponent(typeId) +
              '&search=' + encodeURIComponent(search);

    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.tasks || data.tasks.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:48px 16px; color:#64748b;">' +
                    '<div style="width:48px; height:48px; border-radius:50%; background:#dcfce7; color:#16a34a; display:inline-flex; align-items:center; justify-content:center; font-size:1.4rem; margin-bottom:10px;"><i class="fas fa-check"></i></div>' +
                    '<div style="font-weight:700; color:var(--foreground,#0f172a); font-size:1rem;">All caught up!</div>' +
                    '<div style="font-size:0.84rem; color:#94a3b8; margin-top:2px;">You have no tasks matching this filter.</div>' +
                '</div>';
                return;
            }

            var html = '';
            data.tasks.forEach(function(t) {
                var isTerminal = (t.status === 'completed' || t.status === 'cancelled');
                var cardCls = 'task-action-card';
                if (t.status === 'completed') cardCls += ' card-completed';
                else if (t.status === 'in_progress') cardCls += ' card-inprogress';
                else if (t.is_overdue) cardCls += ' card-overdue';
                else cardCls += ' card-pending';

                var notesPreview = t.notes ? ('<div style="font-size:0.84rem; color:var(--secondary,#64748b); margin-top:4px; line-height:1.4;">' + escapeHtml(t.notes) + '</div>') : '';

                var actionsHtml = '<button type="button" class="btn btn-sm btn-outline" onclick="openTaskDetailsModal(' + t.id + ')" title="View Details & History"><i class="fas fa-circle-info"></i> Details</button> ';

                if (!isTerminal) {
                    if (t.status === 'pending') {
                        actionsHtml += '<button type="button" class="btn btn-sm btn-primary" onclick="startTask(' + t.id + ')" title="Start Working on Task"><i class="fas fa-play"></i> Start</button> ';
                    }
                    actionsHtml += '<button type="button" class="btn btn-sm btn-success" onclick="openCompleteTaskModal(' + t.id + ', \'' + escapeJs(t.title) + '\')" title="Complete Task"><i class="fas fa-check"></i> Complete</button> ';
                    actionsHtml += '<button type="button" class="btn btn-sm btn-outline" onclick="openPostponeTaskModal(' + t.id + ', \'' + escapeJs(t.title) + '\', \'' + t.remind_at + '\')" title="Postpone Task"><i class="fas fa-clock"></i> Postpone</button>';
                }

                var dueIndicator = t.is_overdue 
                    ? '<span style="color:#dc2626; font-weight:700;"><i class="fas fa-circle-exclamation"></i> Overdue &bull; Due ' + t.formatted_due + '</span>'
                    : '<span style="color:var(--secondary,#64748b); font-weight:600;"><i class="fas fa-clock"></i> Due ' + t.formatted_due + '</span>';

                html += '<div class="' + cardCls + '">' +
                    '<div style="flex:1; min-width:260px;">' +
                        '<div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">' +
                            '<span class="badge blue" style="font-size:0.72rem;">' + escapeHtml(t.task_type_name) + '</span>' +
                            renderStatusBadge(t.status, t.is_overdue) +
                            '<span style="font-size:0.75rem; color:#94a3b8;">&bull; Assigned by <strong>' + escapeHtml(t.created_by_username || t.created_by) + '</strong></span>' +
                        '</div>' +
                        '<div style="font-size:0.98rem; font-weight:700; color:var(--foreground,#0f172a);">' + escapeHtml(t.title) + '</div>' +
                        notesPreview +
                        '<div style="font-size:0.8rem; margin-top:6px;">' + dueIndicator + '</div>' +
                    '</div>' +
                    '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">' +
                        actionsHtml +
                    '</div>' +
                '</div>';
            });

            container.innerHTML = html;
        })
        .catch(function() {
            container.innerHTML = '<div style="text-align:center; padding:24px; color:#ef4444;">Failed to load tasks.</div>';
        });
}

function loadAssignedByMe() {
    var searchEl = document.getElementById('assigned-search');
    var search = searchEl ? searchEl.value : '';
    var assigneeEl = document.getElementById('assigned-to-filter');
    var assignee = assigneeEl ? assigneeEl.value : '';
    var typeEl = document.getElementById('assigned-type-filter');
    var typeId = typeEl ? typeEl.value : '';
    var container = document.getElementById('assigned-by-me-list-container');
    if (!container) return;

    var url = 'api/task-reminders.php?action=list_assigned_by_me&status=' + encodeURIComponent(currentAssignedStatusFilter) +
              '&assigned_to_username=' + encodeURIComponent(assignee) +
              '&task_type_id=' + encodeURIComponent(typeId) +
              '&search=' + encodeURIComponent(search);

    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.tasks || data.tasks.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:48px 16px; color:#94a3b8;"><i class="fas fa-binoculars" style="font-size:2rem; display:block; margin-bottom:8px; opacity:0.4;"></i>No delegated tasks found.</div>';
                return;
            }

            var html = '';
            data.tasks.forEach(function(t) {
                var isTerminal = (t.status === 'completed' || t.status === 'cancelled');
                var remarksHtml = t.latest_remarks ? ('<div style="font-size:0.82rem; font-style:italic; color:#475569; margin-top:4px; background:var(--background,#f8fafc); border-radius:6px; padding:4px 8px; display:inline-block;"><i class="fas fa-comment-dots"></i> "' + escapeHtml(t.latest_remarks) + '"</div>') : '';

                var actionsHtml = '<button type="button" class="btn btn-sm btn-outline" onclick="openTaskDetailsModal(' + t.id + ')" title="View Timeline"><i class="fas fa-circle-info"></i> Details</button> ';
                if (!isTerminal) {
                    actionsHtml += '<button type="button" class="btn btn-sm btn-outline" onclick="openEditTaskModal(' + t.id + ')" title="Edit Task"><i class="fas fa-pen-to-square"></i></button> ';
                    actionsHtml += '<button type="button" class="btn btn-sm btn-outline" onclick="openReassignModal(' + t.id + ', \'' + escapeJs(t.title) + '\', \'' + escapeJs(t.assigned_to_username || t.assigned_to) + '\')" title="Reassign"><i class="fas fa-user-gear"></i></button> ';
                    actionsHtml += '<button type="button" class="btn btn-sm btn-outline" onclick="openPostponeTaskModal(' + t.id + ', \'' + escapeJs(t.title) + '\', \'' + t.remind_at + '\')" title="Postpone"><i class="fas fa-clock"></i></button>';
                }

                html += '<div class="task-action-card">' +
                    '<div style="flex:1; min-width:240px;">' +
                        '<div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">' +
                            '<span class="badge blue" style="font-size:0.72rem;">' + escapeHtml(t.task_type_name) + '</span>' +
                            renderStatusBadge(t.status, t.is_overdue) +
                        '</div>' +
                        '<div style="font-size:0.95rem; font-weight:700; color:var(--foreground,#0f172a);">' + escapeHtml(t.title) + '</div>' +
                        '<div style="font-size:0.8rem; color:#64748b; margin-top:3px;">' +
                            '<i class="fas fa-user-check"></i> Assignee: <strong>' + escapeHtml(t.assigned_to_username || t.assigned_to) + '</strong>' +
                            ' &bull; <i class="fas fa-clock"></i> Due: <strong>' + t.formatted_due + '</strong>' +
                            (t.formatted_completed ? (' &bull; <span style="color:#15803d;"><i class="fas fa-circle-check"></i> Completed ' + t.formatted_completed + '</span>') : '') +
                        '</div>' +
                        remarksHtml +
                    '</div>' +
                    '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">' +
                        actionsHtml +
                    '</div>' +
                '</div>';
            });

            container.innerHTML = html;
        })
        .catch(function() {
            container.innerHTML = '<div style="text-align:center; padding:24px; color:#ef4444;">Failed to load delegated tasks.</div>';
        });
}

function loadHistory() {
    var eventTypeEl = document.getElementById('history-event-filter');
    var eventType = eventTypeEl ? eventTypeEl.value : '';
    var adminEl = document.getElementById('history-admin-filter');
    var admin = adminEl ? adminEl.value : '';
    var dateFromEl = document.getElementById('history-date-from');
    var dateFrom = dateFromEl ? dateFromEl.value : '';
    var dateToEl = document.getElementById('history-date-to');
    var dateTo = dateToEl ? dateToEl.value : '';
    var tbody = document.getElementById('task-history-tbody');
    if (!tbody) return;

    var url = 'api/task-reminders.php?action=list_history&event_type=' + encodeURIComponent(eventType) +
              '&admin=' + encodeURIComponent(admin) +
              '&date_from=' + encodeURIComponent(dateFrom) +
              '&date_to=' + encodeURIComponent(dateTo);

    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.history || data.history.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:32px; color:#94a3b8;">No lifecycle history records found.</td></tr>';
                return;
            }

            var html = '';
            data.history.forEach(function(h) {
                var eventBadgeClass = 'blue';
                if (h.event_type === 'COMPLETED') eventBadgeClass = 'green';
                else if (h.event_type === 'CANCELLED') eventBadgeClass = 'gray';
                else if (h.event_type === 'POSTPONED') eventBadgeClass = 'orange';
                else if (h.event_type === 'REASSIGNED') eventBadgeClass = 'purple';

                html += '<tr>' +
                    '<td class="cell-sub" style="font-size:0.82rem; white-space:nowrap;">' + h.formatted_time + '</td>' +
                    '<td><span class="badge ' + eventBadgeClass + '">' + h.event_type + '</span></td>' +
                    '<td class="cell-main"><strong>' + escapeHtml(h.task_title) + '</strong></td>' +
                    '<td class="cell-sub">' + escapeHtml(h.changed_by_username || 'System') + '</td>' +
                    '<td style="max-width:280px; font-size:0.85rem;">' + escapeHtml(h.remarks || '—') + '</td>' +
                    '<td style="text-align:right;">' +
                        '<button type="button" class="btn btn-sm btn-outline" onclick="openTaskDetailsModal(' + h.task_id + ')"><i class="fas fa-circle-info"></i> Details</button>' +
                    '</td>' +
                '</tr>';
            });
            tbody.innerHTML = html;
        })
        .catch(function(err) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:24px; color:#ef4444;">Failed to load history.</td></tr>';
        });
}

function resetHistoryFilters() {
    document.getElementById('history-event-filter').value = '';
    document.getElementById('history-admin-filter').value = '';
    document.getElementById('history-date-from').value = '';
    document.getElementById('history-date-to').value = '';
    loadHistory();
}

function startTask(taskId) {
    var fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('task_id', taskId);
    fd.append('status', 'in_progress');
    fd.append('remarks', 'Task started by assignee');
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');

    fetch('api/task-reminders.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                loadMyTasks();
                if (typeof updateTaskRemindersSummary === 'function') updateTaskRemindersSummary();
            } else {
                alert(data.message || 'Failed to start task.');
            }
        });
}

function openCompleteTaskModal(taskId, title) {
    document.getElementById('complete-task-id').value = taskId;
    document.getElementById('complete-task-prompt').innerText = 'Mark "' + title + '" as completed?';
    document.getElementById('complete-task-remarks').value = '';
    openModal('complete-task-modal');
}

function submitCompleteTask(e) {
    e.preventDefault();
    var taskId = document.getElementById('complete-task-id').value;
    var remarks = document.getElementById('complete-task-remarks').value;

    var fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('task_id', taskId);
    fd.append('status', 'completed');
    fd.append('remarks', remarks);
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');

    var btn = document.getElementById('complete-task-submit-btn');
    btn.disabled = true;

    fetch('api/task-reminders.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                closeModal('complete-task-modal');
                loadMyTasks();
                if (typeof updateTaskRemindersSummary === 'function') updateTaskRemindersSummary();
            } else {
                alert(data.message || 'Failed to complete task.');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            alert('Error completing task.');
        });
}

function openReassignModal(taskId, title, currentAssignee) {
    document.getElementById('reassign-task-id').value = taskId;
    document.getElementById('reassign-task-title').innerText = 'Reassigning: ' + title;
    document.getElementById('reassign-new-assignee').value = currentAssignee;
    openModal('reassign-task-modal');
}

function submitReassignTask(e) {
    e.preventDefault();
    var taskId = document.getElementById('reassign-task-id').value;
    var newAssignee = document.getElementById('reassign-new-assignee').value;

    var fd = new FormData();
    fd.append('action', 'reassign');
    fd.append('task_id', taskId);
    fd.append('assigned_to', newAssignee);
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');

    fetch('api/task-reminders.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                closeModal('reassign-task-modal');
                loadAssignedByMe();
            } else {
                alert(data.message || 'Failed to reassign task.');
            }
        });
}

function openTaskDetailsModal(taskId) {
    var body = document.getElementById('task-details-modal-body');
    body.innerHTML = '<div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading details...</div>';
    openModal('task-details-modal');

    fetch('api/task-reminders.php?action=get_details&task_id=' + taskId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.details) {
                body.innerHTML = '<div class="alert alert-error">Failed to load task details.</div>';
                return;
            }

            var t = data.details.task;
            var history = data.details.history || [];

            var timelineHtml = '';
            history.forEach(function(h) {
                timelineHtml += '<div class="timeline-item">' +
                    '<div class="timeline-dot"></div>' +
                    '<div class="timeline-content">' +
                        '<div class="timeline-time">' + h.formatted_time + ' &bull; by ' + escapeHtml(h.changed_by_username || 'System') + '</div>' +
                        '<div class="timeline-event">' + h.event_type + '</div>' +
                        (h.remarks ? ('<div class="timeline-remarks">"' + escapeHtml(h.remarks) + '"</div>') : '') +
                    '</div>' +
                '</div>';
            });

            var html = '<div style="margin-bottom:16px;">' +
                '<div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">' +
                    '<span class="badge blue">' + escapeHtml(t.task_type_name) + '</span> ' +
                    renderStatusBadge(t.status, t.is_overdue) +
                '</div>' +
                '<h2 style="font-size:1.2rem; font-weight:700; margin:0 0 8px; color:var(--foreground,#0f172a);">' + escapeHtml(t.title) + '</h2>' +
                (t.notes ? ('<div style="background:var(--background,#f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:10px 12px; font-size:0.88rem; margin-bottom:12px; color:#334155;">' + escapeHtml(t.notes) + '</div>') : '') +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:0.84rem; color:var(--secondary,#64748b); background:var(--background,#f8fafc); padding:12px; border-radius:8px; border:1px solid var(--border,#e2e8f0);">' +
                    '<div><strong>Assigned To:</strong> ' + escapeHtml(t.assigned_to_username || t.assigned_to) + '</div>' +
                    '<div><strong>Assigned By:</strong> ' + escapeHtml(t.created_by_username || t.created_by) + '</div>' +
                    '<div><strong>Created At:</strong> ' + t.formatted_created + '</div>' +
                    '<div><strong>Scheduled Due:</strong> ' + t.formatted_due + '</div>' +
                    (t.formatted_completed ? ('<div style="grid-column:span 2; color:#15803d;"><strong>Completed:</strong> ' + t.formatted_completed + ' by ' + escapeHtml(t.completed_by_username || t.completed_by) + '</div>') : '') +
                '</div>' +
            '</div>' +
            '<h4 style="font-size:0.92rem; font-weight:700; border-top:1px solid var(--border,#e2e8f0); padding-top:14px; margin-top:14px;">Activity &amp; Lifecycle Timeline</h4>' +
            '<div class="task-timeline">' + (timelineHtml || '<div style="color:#94a3b8; font-size:0.85rem;">No history recorded yet.</div>') + '</div>';

            body.innerHTML = html;
        });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function escapeJs(str) {
    if (!str) return '';
    return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
}

// ═══ Recurring Series Tab (Tab 4) ═══
function loadRecurringSeries() {
    var listEl = document.getElementById('recurring-series-list');
    if (!listEl) return;

    var statusFilter = document.getElementById('series-status-filter');
    var status = statusFilter ? statusFilter.value : 'active';

    listEl.innerHTML = '<div style="text-align:center; padding:32px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading series...</div>';

    fetch('api/task-reminders.php?action=list_series&status=' + encodeURIComponent(status))
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.series || data.series.length === 0) {
                listEl.innerHTML = '<div style="text-align:center; padding:40px; color:#94a3b8; font-size:0.9rem;"><i class="fas fa-repeat" style="font-size:2rem; display:block; margin-bottom:8px; opacity:0.4;"></i>No recurring series found.</div>';
                return;
            }

            var html = '';
            data.series.forEach(function(s) {
                var stats = s.occurrence_stats || {};
                var statusBadge = s.is_stopped
                    ? '<span style="font-size:0.72rem; padding:2px 8px; border-radius:4px; font-weight:700; background:#fef2f2; color:#dc2626; border:1px solid #fecaca;">Stopped</span>'
                    : '<span style="font-size:0.72rem; padding:2px 8px; border-radius:4px; font-weight:700; background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0;">Active</span>';

                var recLabel = s.recurrence_label || 'N/A';
                var weekdayText = '';
                if (s.recurrence_type === 'weekly' && s.recurrence_weekdays) {
                    var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    weekdayText = 'Every ' + s.recurrence_weekdays.split(',').map(function(d) { return dayNames[parseInt(d)] || ''; }).join(' &bull; ');
                } else if (s.recurrence_type === 'monthly' && s.recurrence_month_days) {
                    weekdayText = 'Every ' + s.recurrence_month_days.replace('last', 'Last day of month');
                } else if (s.recurrence_type === 'daily') {
                    weekdayText = 'Every day';
                }

                html += '<div class="series-card" style="border:1px solid var(--border,#e2e8f0); border-radius:10px; padding:16px; margin-bottom:12px; background:var(--surface,#fff);">' +
                    '<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">' +
                        '<div style="flex:1; min-width:220px;">' +
                            '<div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">' +
                                '<span class="badge blue" style="font-size:0.72rem;">' + escapeHtml(s.task_type_name) + '</span>' +
                                statusBadge +
                                '<span style="font-size:0.72rem; padding:2px 8px; border-radius:4px; font-weight:700; background:#ede9fe; color:#7c3aed; border:1px solid #ddd6fe;">' + recLabel + '</span>' +
                            '</div>' +
                            '<div style="font-weight:700; font-size:0.98rem; color:var(--foreground,#0f172a);">' + escapeHtml(s.title) + '</div>' +
                            '<div style="font-size:0.8rem; color:#64748b; margin-top:4px;">' +
                                '<i class="fas fa-repeat"></i> ' + (weekdayText || recLabel) +
                                ' &bull; <i class="fas fa-user-tag"></i> Assigned to: <strong>' + escapeHtml(s.assigned_to_username || s.assigned_to) + '</strong>' +
                                ' &bull; <i class="fas fa-calendar-day"></i> ' + s.formatted_start + ' – ' + s.formatted_end +
                            '</div>' +
                        '</div>' +
                        '<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">' +
                            '<span style="font-size:0.75rem; padding:3px 8px; border-radius:4px; background:#dbeafe; color:#1e40af; font-weight:700;" title="Total Occurrences">' + (stats.total || 0) + ' total</span>' +
                            '<span style="font-size:0.75rem; padding:3px 8px; border-radius:4px; background:#fef3c7; color:#92400e; font-weight:700;" title="Pending">' + (stats.pending || 0) + ' pending</span>' +
                            '<span style="font-size:0.75rem; padding:3px 8px; border-radius:4px; background:#fee2e2; color:#b91c1c; font-weight:700;" title="Overdue">' + (stats.overdue || 0) + ' overdue</span>' +
                            '<span style="font-size:0.75rem; padding:3px 8px; border-radius:4px; background:#dcfce7; color:#166534; font-weight:700;" title="Completed">' + (stats.completed || 0) + ' done</span>' +
                        '</div>' +
                    '</div>' +
                    '<div style="display:flex; gap:8px; margin-top:12px; border-top:1px solid var(--border,#f1f5f9); padding-top:10px;">' +
                        '<button type="button" class="btn btn-sm btn-outline" onclick="toggleSeriesOccurrences(' + s.id + ', this)"><i class="fas fa-list"></i> View Occurrence History</button>' +
                        (!s.is_stopped ? '<button type="button" class="btn btn-sm btn-outline" style="color:#dc2626; border-color:#fecaca;" onclick="stopSeries(' + s.id + ', \'' + escapeJs(s.title) + '\')"><i class="fas fa-stop"></i> Stop Series</button>' : '') +
                    '</div>' +
                    '<div id="series-occurrences-' + s.id + '" style="display:none; margin-top:10px;"></div>' +
                '</div>';
            });

            listEl.innerHTML = html;
        })
        .catch(function(err) {
            listEl.innerHTML = '<div style="text-align:center; padding:32px; color:#ef4444;"><i class="fas fa-exclamation-triangle"></i> Failed to load series.</div>';
        });
}

function toggleSeriesOccurrences(seriesId, btn) {
    var container = document.getElementById('series-occurrences-' + seriesId);
    if (!container) return;

    if (container.style.display === 'block') {
        container.style.display = 'none';
        return;
    }

    container.innerHTML = '<div style="text-align:center; padding:12px; color:#94a3b8; font-size:0.82rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    container.style.display = 'block';

    fetch('api/task-reminders.php?action=get_series_info&series_id=' + seriesId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.data || !data.data.occurrences || data.data.occurrences.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:12px; color:#94a3b8; font-size:0.82rem;">No occurrences yet.</div>';
                return;
            }

            var html = '<table class="data-table" style="font-size:0.82rem; margin-top:8px;">' +
                '<thead><tr><th>Date</th><th>Due Time</th><th>Status</th><th>Completed By</th><th style="text-align:right;">Actions</th></tr></thead><tbody>';

            data.data.occurrences.forEach(function(occ) {
                var statusCls = occ.is_overdue ? 'status-badge-overdue' : 'status-badge-' + occ.status.replace('_', '-');
                var statusLabel = occ.is_overdue ? 'Overdue' : occ.status.replace('_', ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });

                html += '<tr>' +
                    '<td><strong>' + occ.formatted_date + '</strong></td>' +
                    '<td>' + occ.formatted_due + '</td>' +
                    '<td><span class="' + statusCls + '" style="font-size:0.72rem; padding:2px 8px; border-radius:4px; font-weight:700;">' + statusLabel + '</span></td>' +
                    '<td>' + (occ.completed_by_username ? escapeHtml(occ.completed_by_username) : '—') + '</td>' +
                    '<td><button type="button" class="btn btn-sm btn-outline" onclick="openTaskDetailsModal(' + occ.id + ')"><i class="fas fa-circle-info"></i></button></td>' +
                '</tr>';
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        })
        .catch(function() {
            container.innerHTML = '<div style="padding:12px; color:#ef4444; font-size:0.82rem;">Failed to load occurrences.</div>';
        });
}

function stopSeries(seriesId, title) {
    if (!confirm('Stop recurring series "' + title + '"?\n\nNo new occurrences will be created. Existing occurrences remain unchanged.')) return;

    var fd = new FormData();
    fd.append('action', 'stop_series');
    fd.append('series_id', seriesId);
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');

    fetch('api/task-reminders.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                loadRecurringSeries();
                if (typeof updateTaskRemindersSummary === 'function') updateTaskRemindersSummary();
            } else {
                alert(data.message || 'Failed to stop series.');
            }
        })
        .catch(function() { alert('Error stopping series.'); });
}

document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash.substring(1);
    if (hash && (hash === 'my-tasks' || hash === 'assigned-by-me' || hash === 'task-history' || hash === 'recurring-series')) {
        switchTaskTab(hash);
    } else {
        loadMyTasks();
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>
