        </main>
    </div><!-- /main-area -->
</div><!-- /admin-shell -->

<?php if (isset($pdo) && function_exists('reminders_table_exists') && reminders_table_exists($pdo)):
    $rem_assignable = ['__ALL__' => 'All Admins'];
    if (admins_table_exists($pdo)) {
        try { foreach ($pdo->query("SELECT username FROM admins WHERE status='active' ORDER BY role='super_admin' DESC, username")->fetchAll(PDO::FETCH_COLUMN) as $u) $rem_assignable[$u] = $u; } catch (Exception $e) {}
    }
?>
<?php
$__rem_msg = $_GET['msg'] ?? '';
$__rem_msgs = [
    'rem_added'     => ['ok',  'Reminder added.'],
    'rem_done'      => ['ok',  'Reminder marked complete.'],
    'rem_dismissed' => ['ok',  'Reminder dismissed.'],
    'rem_postponed' => ['ok',  'Reminder postponed.'],
    'rem_error'     => ['err', 'Could not add the reminder - please check the title and date/time.'],
];
if (isset($__rem_msgs[$__rem_msg])): [$__t, $__m] = $__rem_msgs[$__rem_msg]; ?>
<div id="rem-toast" style="position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:5000;
     background:<?php echo $__t === 'ok' ? '#16a34a' : '#dc2626'; ?>;color:#fff;font-weight:600;font-size:.85rem;
     padding:11px 20px;border-radius:50px;box-shadow:0 8px 28px rgba(0,0,0,.25);">
    <i class="fas fa-<?php echo $__t === 'ok' ? 'circle-check' : 'triangle-exclamation'; ?>"></i> <?php echo e($__m); ?>
</div>
<script>setTimeout(function(){var t=document.getElementById('rem-toast');if(t)t.style.display='none';}, 3500);</script>
<?php endif; ?>

<!-- ── REMINDERS MODAL ── -->
<div class="modal-backdrop" id="reminders-modal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-head"><h3><i class="fas fa-bell" style="color:var(--accent);"></i> Reminders</h3><button class="modal-close" onclick="closeModal('reminders-modal')"><i class="fas fa-xmark"></i></button></div>
        <div class="modal-body">
            <!-- Add reminder -->
            <form method="POST" action="reminders-action.php" style="margin-bottom:18px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="return" value="<?php echo e($_SERVER['REQUEST_URI'] ?? 'dashboard.php'); ?>">
                <div class="form-grid">
                    <div class="field full"><label>Reminder / task <span class="req">*</span></label>
                        <input type="text" name="title" required placeholder="e.g. Call pending-payment students"></div>
                    <div class="field"><label>Date &amp; time <span class="req">*</span></label>
                        <input type="datetime-local" name="remind_at" required value="<?php echo date('Y-m-d\TH:i', strtotime('+1 hour')); ?>"></div>
                    <div class="field"><label>Assign to</label>
                        <select name="assigned_to">
                            <?php foreach ($rem_assignable as $val => $lbl): ?>
                                <option value="<?php echo e($val); ?>" <?php echo $val === $admin_username ? 'selected' : ''; ?>><?php echo e($lbl); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="field full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Optional details"></textarea></div>
                </div>
                <div style="display:flex; justify-content:flex-end; margin-top:12px;"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Reminder</button></div>
            </form>

            <!-- Pending list -->
            <div style="border-top:1px solid var(--border); padding-top:14px;">
                <div class="cell-sub" style="margin-bottom:10px; font-weight:700;">Your pending reminders (<?php echo count($nav_reminders_pending ?? []); ?>)</div>
                <?php if (empty($nav_reminders_pending)): ?>
                    <div class="empty-state" style="padding:20px;"><i class="fas fa-mug-hot"></i><p>No pending reminders.</p></div>
                <?php else: foreach ($nav_reminders_pending as $rm):
                    $due = strtotime($rm['remind_at']) <= time();
                ?>
                    <div class="reminder-row <?php echo $due ? 'due' : ''; ?>">
                        <div style="flex:1;">
                            <div class="cell-main"><?php echo e($rm['title']); ?> <?php echo $due ? '<span class="badge red">due</span>' : ''; ?></div>
                            <div class="cell-sub"><i class="fas fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($rm['remind_at'])); ?>
                                · <?php echo $rm['assigned_to'] === '__ALL__' ? 'All Admins' : e($rm['assigned_to']); ?>
                                <?php if (!empty($rm['notes'])): ?><br><?php echo nl2br(e($rm['notes'])); ?><?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex; gap:5px; flex-wrap:wrap; align-items:flex-start;">
                            <form method="POST" action="reminders-action.php" style="display:inline;">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="complete"><input type="hidden" name="id" value="<?php echo (int)$rm['id']; ?>"><input type="hidden" name="return" value="<?php echo e($_SERVER['REQUEST_URI'] ?? ''); ?>">
                                <button type="submit" class="btn btn-sm btn-soft-green" title="Mark completed"><i class="fas fa-check"></i></button>
                            </form>
                            <button type="button" class="btn btn-sm btn-soft-amber" title="Postpone" onclick="postponeReminder(<?php echo (int)$rm['id']; ?>)"><i class="fas fa-clock-rotate-left"></i></button>
                            <form method="POST" action="reminders-action.php" style="display:inline;" onsubmit="return confirm('Dismiss this reminder?');">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="dismiss"><input type="hidden" name="id" value="<?php echo (int)$rm['id']; ?>"><input type="hidden" name="return" value="<?php echo e($_SERVER['REQUEST_URI'] ?? ''); ?>">
                                <button type="submit" class="btn btn-sm btn-soft-red" title="Dismiss"><i class="fas fa-xmark"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Postpone helper form -->
<form method="POST" action="reminders-action.php" id="postpone-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="postpone">
    <input type="hidden" name="id" id="pp-id">
    <input type="hidden" name="remind_at" id="pp-when">
    <input type="hidden" name="return" value="<?php echo e($_SERVER['REQUEST_URI'] ?? ''); ?>">
</form>

<!-- ── URGENT EMERGENCY POPUP (one-by-one queue + sound) ── -->
<?php if (!empty($nav_reminders_due)): ?>
<div class="urgent-reminder-overlay" id="urgent-reminder">
    <div class="urgent-card" id="urgent-card">
        <div class="urgent-siren"></div>
        <div class="urgent-pulse"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="urgent-badge">URGENT TASK</div>
        <div class="urgent-progress" id="urgent-progress"></div>
        <div id="urgent-slot"><!-- one reminder injected here by JS --></div>
    </div>
</div>

<!-- Hidden action forms reused by the popup buttons -->
<form method="POST" action="reminders-action.php" id="urgent-form" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" id="uf-action">
    <input type="hidden" name="id" id="uf-id">
    <input type="hidden" name="remind_at" id="uf-when">
    <input type="hidden" name="return" value="<?php echo e($_SERVER['REQUEST_URI'] ?? ''); ?>">
</form>

<script>
(function () {
    var queue = <?php
        $out = [];
        foreach ($nav_reminders_due as $rm) {
            $out[] = [
                'id' => (int)$rm['id'],
                'title' => $rm['title'],
                'notes' => $rm['notes'] ?? '',
                'when' => date('d M Y, h:i A', strtotime($rm['remind_at'])),
                'all' => $rm['assigned_to'] === '__ALL__',
            ];
        }
        echo json_encode($out, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    ?>;
    if (!queue.length) return;
    var idx = 0;
    var overlay = document.getElementById('urgent-reminder');
    var slot = document.getElementById('urgent-slot');
    var prog = document.getElementById('urgent-progress');

    function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

    function submitAction(action, id, when) {
        document.getElementById('uf-action').value = action;
        document.getElementById('uf-id').value = id;
        document.getElementById('uf-when').value = when || '';
        document.getElementById('urgent-form').submit();
    }
    window.urgentAct = function (action) {
        var r = queue[idx];
        if (action === 'postpone') {
            var def = new Date(Date.now() + 3600*1000), p=function(n){return(n<10?'0':'')+n;};
            var d = p(def.getDate())+'-'+p(def.getMonth()+1)+'-'+def.getFullYear()+' '+p(def.getHours())+':'+p(def.getMinutes());
            var v = prompt('Postpone until (DD-MM-YYYY HH:MM):', d);
            if (!v) return;
            var m = v.trim().match(/^(\d{2})-(\d{2})-(\d{4})\s+(\d{1,2}):(\d{2})$/);
            if (!m) { alert('Use format DD-MM-YYYY HH:MM'); return; }
            submitAction('postpone', r.id, m[3]+'-'+m[2]+'-'+m[1]+' '+m[4]+':'+m[5]);
            return;
        }
        submitAction(action, r.id);
    };

    function mkBtn(cls, icon, label, action) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'btn ' + cls;
        b.innerHTML = '<i class="fas ' + icon + '"></i> ' + label;
        b.addEventListener('click', function () { window.urgentAct(action); });
        return b;
    }
    function render() {
        var r = queue[idx];
        prog.textContent = queue.length > 1 ? ('Task ' + (idx + 1) + ' of ' + queue.length) : '';
        slot.innerHTML = '';
        var t = document.createElement('div'); t.className = 'urgent-item-title'; t.textContent = r.title; slot.appendChild(t);
        var w = document.createElement('div'); w.className = 'urgent-item-time';
        w.innerHTML = '<i class="fas fa-clock"></i> ' + esc(r.when) + (r.all ? ' · All Admins' : ''); slot.appendChild(w);
        if (r.notes) { var n = document.createElement('div'); n.className = 'urgent-item-notes'; n.innerHTML = esc(r.notes).replace(/\n/g, '<br>'); slot.appendChild(n); }
        var acts = document.createElement('div'); acts.className = 'urgent-item-actions';
        acts.appendChild(mkBtn('btn-success', 'fa-check', 'Completed', 'complete'));
        acts.appendChild(mkBtn('btn-soft-amber', 'fa-clock-rotate-left', 'Skip 5 min', 'skip5'));
        acts.appendChild(mkBtn('btn-soft-blue', 'fa-calendar', 'Postpone', 'postpone'));
        acts.appendChild(mkBtn('btn-soft-red', 'fa-xmark', 'Dismiss', 'dismiss'));
        slot.appendChild(acts);
    }
    // Make sure the overlay is actually visible (in case any CSS set display:none)
    if (overlay) overlay.style.display = 'flex';
    render();

    // ── Attention sound (WebAudio beep, repeated). Starts on first user
    //    interaction if the browser blocks autoplay. ──
    var actx = null, beepTimer = null;
    function beep() {
        try {
            if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)();
            var o = actx.createOscillator(), g = actx.createGain();
            o.connect(g); g.connect(actx.destination);
            o.type = 'sine'; o.frequency.value = 880;
            g.gain.setValueAtTime(0.0001, actx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.25, actx.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, actx.currentTime + 0.4);
            o.start(); o.stop(actx.currentTime + 0.42);
        } catch (e) {}
    }
    function startSound() { beep(); if (!beepTimer) beepTimer = setInterval(beep, 2200); }
    startSound();
    // Resume audio after a click if autoplay was blocked
    document.addEventListener('click', function once() {
        if (actx && actx.state === 'suspended') actx.resume();
        else if (!actx) startSound();
        document.removeEventListener('click', once);
    }, { once: true });
})();
</script>
<?php endif; ?>
<?php endif; /* reminders table exists */ ?>

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
</script>
<?php if (!empty($extra_scripts)) echo $extra_scripts; ?>
</body>
</html>
