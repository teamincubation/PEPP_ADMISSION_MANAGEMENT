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
$assigned_by_me_total = count($assigned_by_me_all);

$extra_head = '
<style>
/* Task Reminders Module Styles */
.task-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.task-kpi-card {
    background: var(--card, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 12px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.task-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.task-kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}
.task-kpi-val {
    font-size: 1.6rem;
    font-weight: 700;
    line-height: 1.1;
    font-family: "Space Grotesk", sans-serif;
    color: var(--foreground, #0f172a);
}
.task-kpi-lbl {
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-sub, #64748b);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
}

/* Tab Navigation */
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
    gap: 8px;
}
.task-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    font-family: "Space Grotesk", sans-serif;
    font-weight: 600;
    font-size: 0.92rem;
    color: var(--text, #334155);
    background: var(--card, #ffffff);
    border: 1px solid var(--border, #cbd5e1);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.task-tab-btn:hover {
    background: var(--bg-hover, #f8fafc);
    border-color: #94a3b8;
}
.task-tab-btn.active {
    color: #ffffff !important;
    background: var(--primary, #7c3aed) !important;
    border-color: var(--primary, #7c3aed) !important;
    box-shadow: 0 2px 6px rgba(124, 58, 237, 0.25);
}
.task-tab-pane {
    display: none;
}
.task-tab-pane.active {
    display: block;
}

/* Sub-filter pills */
.task-subfilters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 16px;
}
.subfilter-pill {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 600;
    background: var(--bg, #f1f5f9);
    border: 1px solid var(--border, #e2e8f0);
    color: var(--text-sub, #475569);
    cursor: pointer;
    transition: all 0.15s ease;
}
.subfilter-pill:hover {
    background: #e2e8f0;
    color: #1e293b;
}
.subfilter-pill.active {
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
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

/* Status Badges */
.status-badge-pending {
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
    padding: 4px 10px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.78rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.status-badge-inprogress {
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    padding: 4px 10px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.78rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.status-badge-overdue {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
    padding: 4px 10px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.78rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    animation: overduePulse 2s infinite ease-in-out;
}
.status-badge-completed {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
    padding: 4px 10px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.78rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.status-badge-cancelled {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #cbd5e1;
    padding: 4px 10px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.78rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

@keyframes overduePulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.03); }
    100% { transform: scale(1); }
}

/* Timeline in Task Details */
.task-timeline {
    position: relative;
    padding-left: 28px;
    margin-top: 16px;
}
.task-timeline::before {
    content: "";
    position: absolute;
    left: 10px;
    top: 4px;
    bottom: 4px;
    width: 2px;
    background: var(--border, #cbd5e1);
}
.timeline-item {
    position: relative;
    margin-bottom: 20px;
}
.timeline-dot {
    position: absolute;
    left: -28px;
    top: 2px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--card, #fff);
    border: 3px solid var(--primary, #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
}
.timeline-content {
    background: var(--bg, #f8fafc);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 10px 14px;
}
html.theme-dark .timeline-content {
    background: #1e293b;
    border-color: #334155;
}
.timeline-time {
    font-size: 0.75rem;
    color: var(--text-sub, #64748b);
    font-weight: 600;
}
.timeline-event {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--foreground, #0f172a);
    margin: 2px 0;
}
.timeline-remarks {
    font-size: 0.85rem;
    color: var(--text, #334155);
    margin-top: 4px;
    font-style: italic;
}
</style>
';

include 'includes/admin_nav.php';
?>

<!-- ── KPI METRICS CARDS ── -->
<div class="task-kpi-grid">
    <div class="task-kpi-card">
        <div class="task-kpi-icon" style="background:#fef3c7; color:#d97706;">
            <i class="fas fa-hourglass-half"></i>
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
            <i class="fas fa-binoculars"></i> Assigned by Me (Monitoring)
        </button>
        <button type="button" class="task-tab-btn" onclick="switchTaskTab('task-history')">
            <i class="fas fa-clock-rotate-left"></i> History / All Events
        </button>
    </div>
    <div>
        <button type="button" class="btn btn-primary" onclick="openCreateTaskModal()">
            <i class="fas fa-plus"></i> + New Task Reminder
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1: MY TASKS                                                        -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="pane-my-tasks" class="task-tab-pane active">
    <div class="panel">
        <div class="panel-head" style="justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="head-icon"><i class="fas fa-list-check"></i></span>
                <h2>My Assigned Tasks</h2>
            </div>
            <div class="task-subfilters" style="margin-bottom:0;">
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

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Task Type</th>
                            <th>Task / Activity</th>
                            <th>Due Date &amp; Time</th>
                            <th>Status</th>
                            <th>Assigned By</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="my-tasks-tbody">
                        <tr><td colspan="6" style="text-align:center; padding:32px;"><i class="fas fa-spinner fa-spin"></i> Loading tasks...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2: ASSIGNED BY ME (MONITORING)                                     -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="pane-assigned-by-me" class="task-tab-pane">
    <div class="panel">
        <div class="panel-head" style="justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="head-icon"><i class="fas fa-binoculars"></i></span>
                <h2>Task Delegation &amp; Monitoring</h2>
            </div>
            <div class="task-subfilters" style="margin-bottom:0;">
                <span class="subfilter-pill active" onclick="filterAssignedByMe('all', this)">All</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('pending', this)">Pending</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('in_progress', this)">In Progress</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('overdue', this)">Overdue</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('completed', this)">Completed</span>
                <span class="subfilter-pill" onclick="filterAssignedByMe('cancelled', this)">Cancelled</span>
            </div>
        </div>
        <div class="panel-body">
            <div class="filter-bar" style="margin-bottom:18px;">
                <div class="field" style="flex:1;">
                    <input type="text" id="assigned-search" placeholder="Search delegated tasks..." onkeyup="debounceLoadAssigned()">
                </div>
                <div class="field">
                    <select id="assigned-to-filter" onchange="loadAssignedByMe()">
                        <option value="">All Assignees</option>
                        <?php foreach ($all_admins as $adm): ?>
                            <option value="<?php echo e($adm['username']); ?>"><?php echo e($adm['full_name'] ?: $adm['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <select id="assigned-type-filter" onchange="loadAssignedByMe()">
                        <option value="">All Task Types</option>
                        <?php foreach ($active_task_types as $tt): ?>
                            <option value="<?php echo $tt['id']; ?>"><?php echo e($tt['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Task Type</th>
                            <th>Task Title</th>
                            <th>Assigned To</th>
                            <th>Scheduled Due</th>
                            <th>Status</th>
                            <th>Latest Remarks</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="assigned-by-me-tbody">
                        <tr><td colspan="7" style="text-align:center; padding:32px;"><i class="fas fa-spinner fa-spin"></i> Loading delegated tasks...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3: HISTORY / ALL LIFECYCLE EVENTS                                 -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="pane-task-history" class="task-tab-pane">
    <div class="panel">
        <div class="panel-head">
            <span class="head-icon"><i class="fas fa-clock-rotate-left"></i></span>
            <h2>Permanent Task Lifecycle History</h2>
        </div>
        <div class="panel-body">
            <div class="filter-bar" style="margin-bottom:20px; align-items:flex-end;">
                <div class="field">
                    <label>Event Type</label>
                    <select id="history-event-filter" onchange="loadHistory()">
                        <option value="">All Events</option>
                        <option value="CREATED">CREATED</option>
                        <option value="ASSIGNED">ASSIGNED</option>
                        <option value="REASSIGNED">REASSIGNED</option>
                        <option value="EDITED">EDITED</option>
                        <option value="STARTED">STARTED</option>
                        <option value="POSTPONED">POSTPONED</option>
                        <option value="COMPLETED">COMPLETED</option>
                        <option value="CANCELLED">CANCELLED</option>
                        <option value="REMARK_ADDED">REMARK ADDED</option>
                    </select>
                </div>
                <div class="field">
                    <label>Staff Admin</label>
                    <select id="history-admin-filter" onchange="loadHistory()">
                        <option value="">All Staff</option>
                        <?php foreach ($all_admins as $adm): ?>
                            <option value="<?php echo e($adm['username']); ?>"><?php echo e($adm['full_name'] ?: $adm['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Date From</label>
                    <input type="date" id="history-date-from" onchange="loadHistory()">
                </div>
                <div class="field">
                    <label>Date To</label>
                    <input type="date" id="history-date-to" onchange="loadHistory()">
                </div>
                <button type="button" class="btn btn-outline" onclick="resetHistoryFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Task Title</th>
                            <th>Actor / Changed By</th>
                            <th>Details &amp; Remarks</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="task-history-tbody">
                        <tr><td colspan="6" style="text-align:center; padding:32px;"><i class="fas fa-spinner fa-spin"></i> Loading history...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TASK DETAILS & TIMELINE MODAL                                          -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="task-details-modal" class="modal-backdrop">
    <div class="modal-box" style="max-width:640px;">
        <div class="modal-head">
            <h3><i class="fas fa-circle-info"></i> Task Details &amp; History</h3>
            <button type="button" class="modal-close" onclick="closeModal('task-details-modal')">&times;</button>
        </div>
        <div class="modal-body" id="task-details-modal-body">
            <div style="text-align:center; padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading details...</div>
        </div>
        <div class="modal-foot">
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
            <h3><i class="fas fa-circle-check" style="color:#16a34a;"></i> Complete Task</h3>
            <button type="button" class="modal-close" onclick="closeModal('complete-task-modal')">&times;</button>
        </div>
        <form id="complete-task-form" onsubmit="submitCompleteTask(event)">
            <input type="hidden" id="complete-task-id" name="task_id">
            <div class="modal-body">
                <p id="complete-task-prompt" style="font-size:0.95rem; font-weight:600; margin-bottom:14px; color:var(--foreground,#0f172a);"></p>
                <div class="field">
                    <label>Completion Remarks / Outcome</label>
                    <textarea id="complete-task-remarks" name="remarks" rows="3" placeholder="e.g. Student confirmed installment payment schedule..."></textarea>
                </div>
            </div>
            <div class="modal-foot">
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
            <h3><i class="fas fa-user-gear"></i> Reassign Task</h3>
            <button type="button" class="modal-close" onclick="closeModal('reassign-task-modal')">&times;</button>
        </div>
        <form id="reassign-task-form" onsubmit="submitReassignTask(event)">
            <input type="hidden" id="reassign-task-id" name="task_id">
            <div class="modal-body">
                <p id="reassign-task-title" style="font-size:0.95rem; font-weight:600; margin-bottom:14px;"></p>
                <div class="field">
                    <label>Assign To *</label>
                    <select id="reassign-new-assignee" name="assigned_to" required>
                        <?php foreach ($all_admins as $adm): ?>
                            <option value="<?php echo e($adm['username']); ?>"><?php echo e($adm['full_name'] ?: $adm['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('reassign-task-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Reassign Task</button>
            </div>
        </form>
    </div>
</div>

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
    var tbody = document.getElementById('my-tasks-tbody');
    if (!tbody) return;

    var url = 'api/task-reminders.php?action=list_my_tasks&status=' + encodeURIComponent(currentMyTasksStatusFilter) +
              '&task_type_id=' + encodeURIComponent(typeId) +
              '&search=' + encodeURIComponent(search);

    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.tasks || data.tasks.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:32px; color:#94a3b8;"><i class="fas fa-check-circle" style="font-size:2rem; margin-bottom:8px; display:block; color:#cbd5e1;"></i>No matching tasks found.</td></tr>';
                return;
            }

            var html = '';
            data.tasks.forEach(function(t) {
                var notesPreview = t.notes ? ('<div style="font-size:0.8rem; color:#64748b; margin-top:2px;">' + escapeHtml(t.notes.substring(0, 75)) + (t.notes.length > 75 ? '...' : '') + '</div>') : '';
                var isTerminal = (t.status === 'completed' || t.status === 'cancelled');

                var actionsHtml = '<button type="button" class="btn btn-sm btn-outline" onclick="openTaskDetailsModal(' + t.id + ')" title="View Timeline & Details"><i class="fas fa-circle-info"></i></button> ';

                if (!isTerminal) {
                    if (t.status === 'pending') {
                        actionsHtml += '<button type="button" class="btn btn-sm btn-primary" onclick="startTask(' + t.id + ')" title="Start Task"><i class="fas fa-play"></i> Start</button> ';
                    }
                    actionsHtml += '<button type="button" class="btn btn-sm btn-success" onclick="openCompleteTaskModal(' + t.id + ', \'' + escapeJs(t.title) + '\')" title="Complete Task"><i class="fas fa-check"></i></button> ';
                    actionsHtml += '<button type="button" class="btn btn-sm btn-outline" onclick="openPostponeTaskModal(' + t.id + ', \'' + escapeJs(t.title) + '\', \'' + t.remind_at + '\')" title="Postpone"><i class="fas fa-clock"></i></button>';
                }

                html += '<tr>' +
                    '<td><span class="badge blue">' + escapeHtml(t.task_type_name) + '</span></td>' +
                    '<td class="cell-main"><strong>' + escapeHtml(t.title) + '</strong>' + notesPreview + '</td>' +
                    '<td><span style="font-size:0.86rem; font-weight:600;">' + t.formatted_due + '</span></td>' +
                    '<td>' + renderStatusBadge(t.status, t.is_overdue) + '</td>' +
                    '<td class="cell-sub">' + escapeHtml(t.created_by_username || t.created_by) + '</td>' +
                    '<td style="text-align:right; white-space:nowrap;">' + actionsHtml + '</td>' +
                '</tr>';
            });
            tbody.innerHTML = html;
        })
        .catch(function(err) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:24px; color:#ef4444;">Failed to load tasks.</td></tr>';
        });
}

function loadAssignedByMe() {
    var searchEl = document.getElementById('assigned-search');
    var search = searchEl ? searchEl.value : '';
    var assigneeEl = document.getElementById('assigned-to-filter');
    var assignee = assigneeEl ? assigneeEl.value : '';
    var typeEl = document.getElementById('assigned-type-filter');
    var typeId = typeEl ? typeEl.value : '';
    var tbody = document.getElementById('assigned-by-me-tbody');
    if (!tbody) return;

    var url = 'api/task-reminders.php?action=list_assigned_by_me&status=' + encodeURIComponent(currentAssignedStatusFilter) +
              '&assigned_to_username=' + encodeURIComponent(assignee) +
              '&task_type_id=' + encodeURIComponent(typeId) +
              '&search=' + encodeURIComponent(search);

    fetch(url)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.tasks || data.tasks.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:32px; color:#94a3b8;">No delegated tasks found.</td></tr>';
                return;
            }

            var html = '';
            data.tasks.forEach(function(t) {
                var isTerminal = (t.status === 'completed' || t.status === 'cancelled');
                var remarksHtml = t.latest_remarks ? ('<span style="font-size:0.82rem; font-style:italic; color:#475569;">' + escapeHtml(t.latest_remarks) + '</span>') : '<span style="color:#cbd5e1;">—</span>';

                var actionsHtml = '<button type="button" class="btn btn-sm btn-outline" onclick="openTaskDetailsModal(' + t.id + ')" title="View Timeline"><i class="fas fa-circle-info"></i></button> ';
                if (!isTerminal) {
                    actionsHtml += '<button type="button" class="btn btn-sm btn-outline" onclick="openEditTaskModal(' + t.id + ')" title="Edit Task"><i class="fas fa-pen-to-square"></i></button> ';
                    actionsHtml += '<button type="button" class="btn btn-sm btn-outline" onclick="openReassignModal(' + t.id + ', \'' + escapeJs(t.title) + '\', \'' + escapeJs(t.assigned_to_username || t.assigned_to) + '\')" title="Reassign"><i class="fas fa-user-gear"></i></button> ';
                    actionsHtml += '<button type="button" class="btn btn-sm btn-outline" onclick="openPostponeTaskModal(' + t.id + ', \'' + escapeJs(t.title) + '\', \'' + t.remind_at + '\')" title="Postpone"><i class="fas fa-clock"></i></button>';
                }

                html += '<tr>' +
                    '<td><span class="badge blue">' + escapeHtml(t.task_type_name) + '</span></td>' +
                    '<td class="cell-main"><strong>' + escapeHtml(t.title) + '</strong></td>' +
                    '<td class="cell-sub"><span style="font-weight:600; color:#1e293b;">' + escapeHtml(t.assigned_to_username || t.assigned_to) + '</span></td>' +
                    '<td><span style="font-size:0.86rem; font-weight:600;">' + t.formatted_due + '</span></td>' +
                    '<td>' + renderStatusBadge(t.status, t.is_overdue) + '</td>' +
                    '<td style="max-width:220px;">' + remarksHtml + '</td>' +
                    '<td style="text-align:right; white-space:nowrap;">' + actionsHtml + '</td>' +
                '</tr>';
            });
            tbody.innerHTML = html;
        })
        .catch(function(err) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:24px; color:#ef4444;">Failed to load delegated tasks.</td></tr>';
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
            var assignments = data.details.assignments || [];

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
                '<span class="badge blue">' + escapeHtml(t.task_type_name) + '</span> ' +
                renderStatusBadge(t.status, t.is_overdue) +
                '<h2 style="font-size:1.25rem; font-weight:700; margin:10px 0 6px;">' + escapeHtml(t.title) + '</h2>' +
                (t.notes ? ('<div style="background:var(--bg,#f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:12px; font-size:0.9rem; margin-bottom:12px;">' + escapeHtml(t.notes) + '</div>') : '') +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:0.86rem; color:#475569; margin-top:8px;">' +
                    '<div><strong>Created By:</strong> ' + escapeHtml(t.created_by_username || t.created_by) + '</div>' +
                    '<div><strong>Currently Assigned:</strong> ' + escapeHtml(t.assigned_to_username || t.assigned_to) + '</div>' +
                    '<div><strong>Created Date:</strong> ' + t.formatted_created + '</div>' +
                    '<div><strong>Scheduled Due:</strong> ' + t.formatted_due + '</div>' +
                    (t.formatted_completed ? ('<div style="grid-column:span 2; color:#15803d;"><strong>Completed:</strong> ' + t.formatted_completed + ' by ' + escapeHtml(t.completed_by_username || t.completed_by) + '</div>') : '') +
                '</div>' +
            '</div>' +
            '<h4 style="font-size:0.95rem; font-weight:700; border-top:1px solid var(--border,#e2e8f0); padding-top:14px; margin-top:14px;">Audit &amp; Lifecycle Timeline</h4>' +
            '<div class="task-timeline">' + (timelineHtml || '<div style="color:#94a3b8;">No history recorded yet.</div>') + '</div>';

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

document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash.substring(1);
    if (hash && (hash === 'my-tasks' || hash === 'assigned-by-me' || hash === 'task-history')) {
        switchTaskTab(hash);
    } else {
        loadMyTasks();
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>
