        </main>
    </div><!-- /main-area -->
</div><!-- /admin-shell -->

<?php if (isset($pdo) && function_exists('reminders_table_exists') && reminders_table_exists($pdo)):
    $footer_task_types = [];
    try { $footer_task_types = task_types_get_all($pdo, true); } catch (Exception $e) {}
    $footer_admins = [];
    try {
        if (admins_table_exists($pdo)) {
            $footer_admins = $pdo->query("SELECT username, full_name FROM admins WHERE status='active' ORDER BY full_name ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
?>

<!-- ── CREATE TASK REMINDER MODAL ── -->
<div class="modal-backdrop" id="create-task-modal" style="display:none;">
    <div class="modal-box" style="max-width:540px;">
        <div class="modal-head">
            <h3><i class="fas fa-bell" style="color:var(--primary,#7c3aed);"></i> New Task Reminder</h3>
            <button type="button" class="modal-close" onclick="closeModal('create-task-modal')">&times;</button>
        </div>
        <form id="create-task-modal-form" onsubmit="submitCreateTask(event)">
            <div class="modal-body">
                <div class="field" style="margin-bottom:14px;">
                    <label>Task Type <span style="color:#ef4444;">*</span></label>
                    <select id="create-task-type" name="task_type_id" required>
                        <option value="">-- Select Task Type --</option>
                        <?php foreach ($footer_task_types as $tt): ?>
                            <option value="<?php echo $tt['id']; ?>"><?php echo e($tt['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin-bottom:14px;">
                    <label>Task / Activity Title <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="create-task-title" name="title" required placeholder="e.g. Call Rahul regarding fee installment">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                    <div class="field">
                        <label>Due Date &amp; Time <span style="color:#ef4444;">*</span></label>
                        <input type="datetime-local" id="create-task-due" name="remind_at" required value="<?php echo date('Y-m-d\TH:i', strtotime('+1 hour')); ?>">
                    </div>
                    <div class="field">
                        <label>Assign To <span style="color:#ef4444;">*</span></label>
                        <select id="create-task-assignee" name="assigned_to" required>
                            <?php foreach ($footer_admins as $fa): ?>
                                <option value="<?php echo e($fa['username']); ?>" <?php echo $fa['username'] === $admin_username ? 'selected' : ''; ?>>
                                    <?php echo e($fa['full_name'] ?: $fa['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>Notes / Instructions</label>
                    <textarea id="create-task-notes" name="notes" rows="2" placeholder="Optional details, contact info, or instructions..."></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('create-task-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="create-task-submit-btn"><i class="fas fa-plus"></i> Create Task</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EDIT TASK REMINDER MODAL ── -->
<div class="modal-backdrop" id="edit-task-modal" style="display:none;">
    <div class="modal-box" style="max-width:540px;">
        <div class="modal-head">
            <h3><i class="fas fa-pen-to-square" style="color:var(--primary,#7c3aed);"></i> Edit Task Reminder</h3>
            <button type="button" class="modal-close" onclick="closeModal('edit-task-modal')">&times;</button>
        </div>
        <form id="edit-task-modal-form" onsubmit="submitEditTask(event)">
            <input type="hidden" id="edit-task-id" name="task_id">
            <div class="modal-body">
                <div class="field" style="margin-bottom:14px;">
                    <label>Task Type <span style="color:#ef4444;">*</span></label>
                    <select id="edit-task-type" name="task_type_id" required>
                        <?php foreach ($footer_task_types as $tt): ?>
                            <option value="<?php echo $tt['id']; ?>"><?php echo e($tt['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field" style="margin-bottom:14px;">
                    <label>Task / Activity Title <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="edit-task-title" name="title" required>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                    <div class="field">
                        <label>Due Date &amp; Time <span style="color:#ef4444;">*</span></label>
                        <input type="datetime-local" id="edit-task-due" name="remind_at" required>
                    </div>
                    <div class="field">
                        <label>Assign To <span style="color:#ef4444;">*</span></label>
                        <select id="edit-task-assignee" name="assigned_to" required>
                            <?php foreach ($footer_admins as $fa): ?>
                                <option value="<?php echo e($fa['username']); ?>">
                                    <?php echo e($fa['full_name'] ?: $fa['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>Notes / Instructions</label>
                    <textarea id="edit-task-notes" name="notes" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('edit-task-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="edit-task-submit-btn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ── STRICT URGENT DUE TASK REMINDER POPUP (Server Revalidated) ── -->
<div class="modal-backdrop" id="task-due-modal" style="display:none; z-index:9999;">
    <div class="modal-box" style="max-width:500px; border-top:5px solid #d97706; box-shadow:0 12px 40px rgba(0,0,0,0.3);">
        <div class="modal-head" style="background:#fef3c7; border-bottom:1px solid #fde68a;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="background:#d97706; color:#fff; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.9rem;">
                    <i class="fas fa-bell"></i>
                </span>
                <h3 style="color:#92400e; margin:0; font-size:1.1rem;">Task Reminder Due</h3>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('task-due-modal')" title="Close (leaves task pending)">&times;</button>
        </div>
        <div class="modal-body" id="task-due-modal-body" style="padding:20px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <span class="badge blue" id="due-modal-type">Task Type</span>
                <span class="badge orange" id="due-modal-status">Due Now</span>
            </div>
            <h2 id="due-modal-title" style="font-size:1.25rem; font-weight:700; color:var(--foreground,#0f172a); margin:0 0 10px;"></h2>
            <div id="due-modal-time" style="font-size:0.86rem; color:#64748b; margin-bottom:12px;">
                <i class="fas fa-clock"></i> <span id="due-modal-time-val"></span>
            </div>
            <div id="due-modal-notes" style="background:var(--bg,#f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:8px; padding:12px; font-size:0.88rem; line-height:1.5; color:#334155; margin-bottom:16px;"></div>
            <div id="due-modal-assigned-by" style="font-size:0.82rem; color:#64748b;"></div>
        </div>
        <div class="modal-foot" style="background:var(--bg,#f8fafc); display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end;">
            <button type="button" class="btn btn-outline" id="due-btn-postpone" onclick="openPostponeFromDue()"><i class="fas fa-clock-rotate-left"></i> Postpone</button>
            <button type="button" class="btn btn-primary" id="due-btn-start" onclick="startTaskFromDue()"><i class="fas fa-play"></i> Start Task</button>
            <button type="button" class="btn btn-success" id="due-btn-complete" onclick="completeTaskFromDue()"><i class="fas fa-check"></i> Complete</button>
        </div>
    </div>
</div>

<!-- ── POSTPONE MODAL WITH PRESETS & REASON ── -->
<div class="modal-backdrop" id="postpone-task-modal" style="display:none; z-index:10000;">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-head">
            <h3><i class="fas fa-clock-rotate-left" style="color:#d97706;"></i> Postpone Task Reminder</h3>
            <button type="button" class="modal-close" onclick="closeModal('postpone-task-modal')">&times;</button>
        </div>
        <form id="postpone-task-form" onsubmit="submitCustomPostpone(event)">
            <input type="hidden" id="postpone-task-id">
            <div class="modal-body">
                <p id="postpone-task-title" style="font-size:0.95rem; font-weight:600; margin-bottom:14px;"></p>

                <label style="font-size:0.84rem; font-weight:700; color:var(--foreground,#0f172a); margin-bottom:8px; display:block;">Quick Postpone Presets:</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:16px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="quickPostpone('+15m')"><i class="fas fa-plus"></i> 15 Minutes</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="quickPostpone('+30m')"><i class="fas fa-plus"></i> 30 Minutes</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="quickPostpone('+1h')"><i class="fas fa-plus"></i> 1 Hour</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="quickPostpone('tomorrow')"><i class="fas fa-sun"></i> Tomorrow 9 AM</button>
                </div>

                <div class="field" style="margin-bottom:14px;">
                    <label>Or Choose Custom Date &amp; Time</label>
                    <input type="datetime-local" id="postpone-custom-due">
                </div>

                <div class="field">
                    <label>Postpone Reason (Audit Log)</label>
                    <input type="text" id="postpone-reason" placeholder="e.g. Student requested callback at 4:30 PM">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-outline" onclick="closeModal('postpone-task-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Postpone</button>
            </div>
        </form>
    </div>
</div>

<!-- ── CREATOR COMPLETION NOTIFICATION POPUP ── -->
<div class="modal-backdrop" id="creator-completion-alert" style="display:none; z-index:9998;">
    <div class="modal-box" style="max-width:440px; border-top:5px solid #16a34a;">
        <div class="modal-head" style="background:#dcfce7;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="background:#16a34a; color:#fff; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.9rem;">
                    <i class="fas fa-circle-check"></i>
                </span>
                <h3 style="color:#14532d; margin:0;">Task Completed</h3>
            </div>
            <button type="button" class="modal-close" onclick="dismissCreatorNotification()">&times;</button>
        </div>
        <div class="modal-body" style="padding:20px;">
            <div id="creator-notif-msg" style="font-size:0.95rem; font-weight:600; color:var(--foreground,#0f172a); margin-bottom:10px;"></div>
            <div id="creator-notif-time" style="font-size:0.8rem; color:#64748b;"></div>
        </div>
        <div class="modal-foot" style="background:var(--bg,#f8fafc);">
            <button type="button" class="btn btn-primary" style="width:100%;" onclick="dismissCreatorNotification()">Dismiss</button>
        </div>
    </div>
</div>

<!-- ── CLIENT ENGINE JAVASCRIPT FOR TASK REMINDERS ── -->
<script>
var activeDueTask = null;
var scheduledDueTimers = {};
var activeCreatorNotifId = null;

function toggleTaskRemindersDropdown(e) {
    if (e) e.stopPropagation();
    var dd = document.getElementById('task-reminders-dropdown');
    if (!dd) return;
    if (dd.style.display === 'none' || !dd.style.display) {
        dd.style.display = 'block';
        fetchTaskRemindersDropdownList();
    } else {
        dd.style.display = 'none';
    }
}

function closeTaskRemindersDropdown() {
    var dd = document.getElementById('task-reminders-dropdown');
    if (dd) dd.style.display = 'none';
}

document.addEventListener('click', function(e) {
    var container = document.querySelector('.task-dropdown-container');
    if (container && !container.contains(e.target)) {
        closeTaskRemindersDropdown();
    }
});

function fetchTaskRemindersDropdownList() {
    var listEl = document.getElementById('task-dropdown-list');
    var countsEl = document.getElementById('task-dropdown-counts');
    if (!listEl) return;

    fetch('api/task-reminders.php?action=list_my_tasks&status=active')
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.tasks || data.tasks.length === 0) {
                listEl.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8; font-size:0.85rem;"><i class="fas fa-check-circle" style="color:#10b981; font-size:1.4rem; display:block; margin-bottom:4px;"></i>All caught up! No active tasks.</div>';
                if (countsEl) countsEl.innerHTML = '';
                return;
            }

            var overdueCount = 0;
            var html = '';
            data.tasks.slice(0, 4).forEach(function(t) {
                if (t.is_overdue) overdueCount++;
                var badgeHtml = t.is_overdue ? '<span class="status-badge-overdue" style="font-size:0.7rem; padding:2px 6px;">Overdue</span>' : '<span class="status-badge-pending" style="font-size:0.7rem; padding:2px 6px;">Pending</span>';

                html += '<div style="padding:10px 14px; border-bottom:1px solid var(--border,#f1f5f9); display:flex; justify-content:space-between; align-items:center; gap:8px;">' +
                    '<div style="flex:1; min-width:0;">' +
                        '<div style="font-size:0.75rem; font-weight:700; color:var(--primary,#7c3aed);">' + escapeHtml(t.task_type_name) + '</div>' +
                        '<div style="font-size:0.86rem; font-weight:600; color:var(--foreground,#0f172a); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + escapeHtml(t.title) + '</div>' +
                        '<div style="font-size:0.75rem; color:#64748b;"><i class="fas fa-clock"></i> ' + t.formatted_due + '</div>' +
                    '</div>' +
                    '<div>' + badgeHtml + '</div>' +
                '</div>';
            });

            if (countsEl) {
                countsEl.innerHTML = '<span style="color:#d97706;">' + data.tasks.length + ' Pending</span>' + (overdueCount > 0 ? ' &bull; <span style="color:#dc2626;">' + overdueCount + ' Overdue</span>' : '');
            }
            listEl.innerHTML = html;
        })
        .catch(function() {
            listEl.innerHTML = '<div style="padding:16px; color:#ef4444; font-size:0.8rem; text-align:center;">Failed to load.</div>';
        });
}

function openCreateTaskModal() {
    var modal = document.getElementById('create-task-modal');
    if (modal) {
        document.getElementById('create-task-title').value = '';
        document.getElementById('create-task-notes').value = '';
        openModal('create-task-modal');
    }
}

function submitCreateTask(e) {
    e.preventDefault();
    var form = document.getElementById('create-task-modal-form');
    var fd = new FormData(form);
    fd.append('action', 'create_task');
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');

    var btn = document.getElementById('create-task-submit-btn');
    btn.disabled = true;

    fetch('api/task-reminders.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                closeModal('create-task-modal');
                updateTaskRemindersSummary();
                if (typeof loadMyTasks === 'function') loadMyTasks();
                if (typeof loadAssignedByMe === 'function') loadAssignedByMe();
            } else {
                alert(data.message || 'Failed to create task.');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            alert('Error creating task.');
        });
}

function openEditTaskModal(taskId) {
    fetch('api/task-reminders.php?action=get_details&task_id=' + taskId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.details) {
                alert(data.message || 'Failed to load task for editing.');
                return;
            }
            var t = data.details.task;
            document.getElementById('edit-task-id').value = t.id;
            document.getElementById('edit-task-type').value = t.task_type_id;
            document.getElementById('edit-task-title').value = t.title;
            document.getElementById('edit-task-notes').value = t.notes || '';
            document.getElementById('edit-task-assignee').value = t.assigned_to_username || t.assigned_to;
            if (t.remind_at) {
                document.getElementById('edit-task-due').value = t.remind_at.substring(0, 16);
            }
            openModal('edit-task-modal');
        });
}

function submitEditTask(e) {
    e.preventDefault();
    var form = document.getElementById('edit-task-modal-form');
    var fd = new FormData(form);
    fd.append('action', 'edit_task');
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');

    var btn = document.getElementById('edit-task-submit-btn');
    btn.disabled = true;

    fetch('api/task-reminders.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                closeModal('edit-task-modal');
                updateTaskRemindersSummary();
                if (typeof loadAssignedByMe === 'function') loadAssignedByMe();
                if (typeof loadMyTasks === 'function') loadMyTasks();
            } else {
                alert(data.message || 'Failed to update task.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            alert('Error updating task.');
        });
}

function openPostponeTaskModal(taskId, title, currentDue) {
    document.getElementById('postpone-task-id').value = taskId;
    document.getElementById('postpone-task-title').innerText = 'Postponing: ' + title;
    document.getElementById('postpone-reason').value = '';
    if (currentDue) {
        document.getElementById('postpone-custom-due').value = currentDue.substring(0, 16);
    }
    openModal('postpone-task-modal');
}

function quickPostpone(preset) {
    var taskId = document.getElementById('postpone-task-id').value;
    var reason = document.getElementById('postpone-reason').value;

    var fd = new FormData();
    fd.append('action', 'postpone');
    fd.append('task_id', taskId);
    fd.append('preset', preset);
    fd.append('reason', reason);
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');

    fetch('api/task-reminders.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                closeModal('postpone-task-modal');
                closeModal('task-due-modal');
                updateTaskRemindersSummary();
                if (typeof loadMyTasks === 'function') loadMyTasks();
                if (typeof loadAssignedByMe === 'function') loadAssignedByMe();
            } else {
                alert(data.message || 'Failed to postpone task.');
            }
        });
}

function submitCustomPostpone(e) {
    e.preventDefault();
    var taskId = document.getElementById('postpone-task-id').value;
    var customDue = document.getElementById('postpone-custom-due').value;
    var reason = document.getElementById('postpone-reason').value;

    if (!customDue) {
        alert('Please choose a new due date & time.');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'postpone');
    fd.append('task_id', taskId);
    fd.append('remind_at', customDue);
    fd.append('reason', reason);
    fd.append('csrf_token', '<?php echo csrf_token(); ?>');

    fetch('api/task-reminders.php', { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                closeModal('postpone-task-modal');
                closeModal('task-due-modal');
                updateTaskRemindersSummary();
                if (typeof loadMyTasks === 'function') loadMyTasks();
                if (typeof loadAssignedByMe === 'function') loadAssignedByMe();
            } else {
                alert(data.message || 'Failed to postpone task.');
            }
        });
}

function openPostponeFromDue() {
    if (!activeDueTask) return;
    openPostponeTaskModal(activeDueTask.id, activeDueTask.title, activeDueTask.remind_at);
}

function startTaskFromDue() {
    if (!activeDueTask) return;
    var taskId = activeDueTask.id;
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
                closeModal('task-due-modal');
                updateTaskRemindersSummary();
                if (typeof loadMyTasks === 'function') loadMyTasks();
            } else {
                alert(data.message || 'Failed to start task.');
            }
        });
}

function completeTaskFromDue() {
    if (!activeDueTask) return;
    var taskId = activeDueTask.id;
    var title = activeDueTask.title;
    closeModal('task-due-modal');
    if (typeof openCompleteTaskModal === 'function') {
        openCompleteTaskModal(taskId, title);
    } else {
        var remarks = prompt('Enter completion remarks (optional):');
        if (remarks === null) return;
        var fd = new FormData();
        fd.append('action', 'update_status');
        fd.append('task_id', taskId);
        fd.append('status', 'completed');
        fd.append('remarks', remarks);
        fd.append('csrf_token', '<?php echo csrf_token(); ?>');

        fetch('api/task-reminders.php', { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    updateTaskRemindersSummary();
                } else {
                    alert(data.message || 'Failed to complete task.');
                }
            });
    }
}

function showDueTaskPopup(task) {
    activeDueTask = task;
    document.getElementById('due-modal-type').innerText = task.task_type_name || 'Task';
    document.getElementById('due-modal-status').innerText = task.is_overdue ? 'Overdue' : 'Due Now';
    document.getElementById('due-modal-title').innerText = task.title;
    document.getElementById('due-modal-time-val').innerText = task.formatted_due;
    document.getElementById('due-modal-notes').innerText = task.notes || 'No extra notes provided.';
    document.getElementById('due-modal-assigned-by').innerText = 'Assigned by: ' + (task.created_by_username || task.created_by || 'Admin');

    // Play attention sound
    playAttentionBeep();
    openModal('task-due-modal');
}

function verifyAndTriggerDuePopup(taskId) {
    fetch('api/task-reminders.php?action=verify_due_alert&task_id=' + taskId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.valid && data.task) {
                showDueTaskPopup(data.task);
            }
        });
}

function updateTaskRemindersSummary() {
    fetch('api/task-reminders.php?action=get_summary')
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.summary) return;
            var s = data.summary;
            var badge = document.getElementById('task-reminders-badge');
            var bellBtn = document.getElementById('task-reminders-bell-btn');

            var totalPending = (s.pending_count || 0) + (s.in_progress_count || 0);
            if (badge) {
                badge.innerText = totalPending;
                badge.style.display = totalPending > 0 ? 'block' : 'none';
            }

            if (bellBtn) {
                if (s.due_count > 0 || s.overdue_count > 0) {
                    bellBtn.classList.add('has-due');
                } else {
                    bellBtn.classList.remove('has-due');
                }
            }

            // Update KPI cards if on task-reminders.php page
            var kpiPend = document.getElementById('kpi-my-pending');
            if (kpiPend) kpiPend.innerText = s.pending_count || 0;
            var kpiOver = document.getElementById('kpi-my-overdue');
            if (kpiOver) kpiOver.innerText = s.overdue_count || 0;
            var kpiProg = document.getElementById('kpi-my-inprogress');
            if (kpiProg) kpiProg.innerText = s.in_progress_count || 0;
            var kpiAss = document.getElementById('kpi-assigned-by-me');
            if (kpiAss) kpiAss.innerText = s.assigned_by_me_pending || 0;

            // Trigger authoritative due popups if any due
            if (s.due_task_ids && s.due_task_ids.length > 0) {
                var firstDueId = s.due_task_ids[0];
                var modal = document.getElementById('task-due-modal');
                if (modal && !modal.classList.contains('open')) {
                    verifyAndTriggerDuePopup(firstDueId);
                }
            }

            // Check unread completion notifications
            checkUnreadNotifications();
        })
        .catch(function(e) {});
}

function checkUnreadNotifications() {
    fetch('api/task-reminders.php?action=get_unread_notifications')
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.notifications && data.notifications.length > 0) {
                var n = data.notifications[0];
                if (n.notification_type === 'TASK_COMPLETED') {
                    activeCreatorNotifId = n.id;
                    document.getElementById('creator-notif-msg').innerText = n.message || 'Your assigned task has been completed.';
                    document.getElementById('creator-notif-time').innerText = n.formatted_time;
                    var modal = document.getElementById('creator-completion-alert');
                    if (modal && !modal.classList.contains('open')) {
                        openModal('creator-completion-alert');
                    }
                }
            }
        });
}

function dismissCreatorNotification() {
    if (activeCreatorNotifId) {
        var fd = new FormData();
        fd.append('action', 'dismiss_notification');
        fd.append('notification_id', activeCreatorNotifId);
        fd.append('csrf_token', '<?php echo csrf_token(); ?>');
        fetch('api/task-reminders.php', { method: 'POST', body: fd });
        activeCreatorNotifId = null;
    }
    closeModal('creator-completion-alert');
}

function playAttentionBeep() {
    try {
        var actx = new (window.AudioContext || window.webkitAudioContext)();
        var o = actx.createOscillator();
        var g = actx.createGain();
        o.connect(g);
        g.connect(actx.destination);
        o.type = 'sine';
        o.frequency.value = 880;
        g.gain.setValueAtTime(0.001, actx.currentTime);
        g.gain.exponentialRampToValueAtTime(0.2, actx.currentTime + 0.02);
        g.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.35);
        o.start();
        o.stop(actx.currentTime + 0.36);
    } catch(e) {}
}

document.addEventListener('DOMContentLoaded', function() {
    updateTaskRemindersSummary();
    setInterval(updateTaskRemindersSummary, 50000); // 50s lightweight poll
});
</script>
<?php endif; /* reminders_table_exists */ ?>

<script>
function toggleSidebar(force) {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('sidebar-overlay');
    const open = (typeof force === 'boolean') ? force : !sb.classList.contains('open');
    sb.classList.toggle('open', open);
    ov.classList.toggle('show', open);
}
// Generic modal helpers used across admin pages
function openModal(id)  { const m = document.getElementById(id); if (m) m.classList.add('open'); }
function closeModal(id) { const m = document.getElementById(id); if (m) m.classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(function (bd) {
    bd.addEventListener('click', function (e) { if (e.target === bd) bd.classList.remove('open'); });
});
// Reminder postpone: ask for new date/time (default +1 hour) and submit
function postponeReminder(id) {
    var def = new Date(Date.now() + 3600 * 1000);
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var defStr = def.getFullYear() + '-' + pad(def.getMonth()+1) + '-' + pad(def.getDate()) + 'T' + pad(def.getHours()) + ':' + pad(def.getMinutes());
    var val = prompt('Postpone until (YYYY-MM-DD HH:MM):', defStr.replace('T', ' '));
    if (!val) return;
    var iso = val.trim().replace(' ', 'T');
    document.getElementById('pp-id').value = id;
    document.getElementById('pp-when').value = iso;
    document.getElementById('postpone-form').submit();
}

// Light/Dark/Sepia mode switcher
(function() {
    var btn = document.getElementById('theme-toggle-btn');
    var icon = document.getElementById('theme-toggle-icon');
    if (!btn || !icon) return;

    function updateThemeUI(theme) {
        document.documentElement.classList.remove('theme-dark', 'theme-sepia');
        if (theme === 'dark') {
            document.documentElement.classList.add('theme-dark');
            icon.className = 'fas fa-moon';
            btn.title = 'Current: Dark Mode. Click to switch to Light Mode.';
        } else if (theme === 'sepia') {
            document.documentElement.classList.add('theme-sepia');
            icon.className = 'fas fa-palette';
            btn.title = 'Current: Sepia Mode. Click to switch to Dark Mode.';
        } else {
            icon.className = 'fas fa-sun';
            btn.title = 'Current: Light Mode. Click to switch to Sepia Mode.';
        }
    }

    var currentTheme = localStorage.getItem('admin-theme') || 'light';
    updateThemeUI(currentTheme);

    btn.addEventListener('click', function() {
        var theme = localStorage.getItem('admin-theme') || 'light';
        var nextTheme = 'light';
        if (theme === 'light') {
            nextTheme = 'sepia';
        } else if (theme === 'sepia') {
            nextTheme = 'dark';
        } else {
            nextTheme = 'light';
        }
        localStorage.setItem('admin-theme', nextTheme);
        updateThemeUI(nextTheme);
    });
})();
</script>

<!-- Centralized Heartbeat & Active/Idle Tracker -->
<script>
(function() {
    var page = <?php echo json_encode(basename($_SERVER['SCRIPT_NAME'])); ?>;
    var section = <?php echo json_encode($cur_sec ?? 'Other'); ?>;
    var module = section;
    var isIdle = 0;
    var lastInteraction = Date.now();
    var heartbeatInterval = 60000;
    var timer = null;
    var latitude = null;
    var longitude = null;

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        if (match) return match[2];
        return null;
    }

    latitude = getCookie('pepp_lat');
    longitude = getCookie('pepp_lng');

    if (!latitude && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(pos) {
            latitude = pos.coords.latitude;
            longitude = pos.coords.longitude;
            document.cookie = "pepp_lat=" + latitude + "; path=/; max-age=" + (86400 * 30);
            document.cookie = "pepp_lng=" + longitude + "; path=/; max-age=" + (86400 * 30);
        }, null, { timeout: 10000 });
    }

    function sendHeartbeat(beacon) {
        var payload = {
            page: page,
            module: module,
            section: section,
            is_idle: isIdle,
            latitude: latitude,
            longitude: longitude
        };

        if (beacon && navigator.sendBeacon) {
            navigator.sendBeacon('api/activity-heartbeat.php', JSON.stringify(payload));
        } else {
            fetch('api/activity-heartbeat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            }).catch(function(e) {
                // Fail silently
            });
        }
    }

    function resetIdleTimer() {
        if (isIdle === 1) {
            isIdle = 0;
            sendHeartbeat(false);
        }
        lastInteraction = Date.now();
    }

    var events = ['click', 'keydown', 'mousemove', 'scroll', 'touchstart', 'pointerdown'];
    events.forEach(function(evt) {
        document.addEventListener(evt, resetIdleTimer, { passive: true });
    });

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            isIdle = 1;
            sendHeartbeat(true);
        } else {
            resetIdleTimer();
            startHeartbeat();
        }
    });

    function startHeartbeat() {
        if (timer) clearInterval(timer);
        timer = setInterval(function() {
            var timeSinceInteraction = Date.now() - lastInteraction;
            if (timeSinceInteraction >= 60000) {
                isIdle = 1;
            } else {
                isIdle = 0;
            }
            sendHeartbeat(false);
        }, heartbeatInterval);
    }

    sendHeartbeat(false);
    startHeartbeat();

    window.addEventListener('pagehide', function() {
        sendHeartbeat(true);
    });
})();
</script>

<?php if (!empty($extra_scripts)) echo $extra_scripts; ?>
</body>
</html>
