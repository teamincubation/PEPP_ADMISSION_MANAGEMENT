<?php
/**
 * One-off, super-admin-only tool: reconcile the five faculty event mappings with live Meta definitions.
 * GET  = read-only dry-run (shows current vs desired).
 * POST = apply (CSRF protected). A backup of the original rows is stored in admin_settings first.
 */
require_once 'includes/auth.php';
require_once 'config/database.php';
require_super_admin();
require_once 'includes/communication/FacultyMappingReconciler.php';

$rec = new FacultyMappingReconciler($pdo);
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    if (($_POST['action'] ?? '') === 'apply') {
        $result = $rec->apply();
    } elseif (($_POST['action'] ?? '') === 'restore') {
        $result = $rec->restore((string)($_POST['backup_key'] ?? ''));
    }
}

$plan = $rec->plan();
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Faculty Event Mapping Reconcile</title>
<style>body{font-family:system-ui,sans-serif;margin:24px}table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:6px 10px;vertical-align:top;font-size:13px}code{font-size:12px}.bad{color:#b00020}.ok{color:#0a7a2f}</style></head>
<body>
<h1>Faculty Event Mapping Reconcile</h1>
<p>Touches only: <?= e(implode(', ', FacultyMappingReconciler::events())) ?>. No Meta calls, no messages, no template changes.</p>
<?php if ($result): ?>
<pre><?= e(json_encode($result, JSON_PRETTY_PRINT)) ?></pre>
<?php endif; ?>
<table>
<tr><th>Event</th><th>Row ID</th><th>Template</th><th>Meta body idx</th><th>Current</th><th>Desired</th><th>Status</th></tr>
<?php foreach ($plan as $p): ?>
<tr>
<td><?= e($p['event']) ?></td><td><?= e($p['row_id']) ?></td><td><?= e($p['template']) ?></td>
<td><?= e($p['meta_indexes'] === null ? '-' : implode(',', $p['meta_indexes'])) ?></td>
<td><code><?= e(json_encode($p['current'])) ?></code></td>
<td><code><?= e(json_encode($p['desired'])) ?></code></td>
<td class="<?= $p['ok'] ? 'ok' : 'bad' ?>"><?= e(!$p['ok'] ? 'BLOCKED: ' . $p['problem'] : ($p['changed'] ? 'will update' : 'already correct')) ?></td>
</tr>
<?php endforeach; ?>
</table>
<form method="post" style="margin-top:16px">
<?= csrf_field() ?>
<input type="hidden" name="action" value="apply">
<button type="submit" onclick="return confirm('Update the five faculty mappings? A backup is saved first.')">Apply (backup + update 5 rows)</button>
</form>
<form method="post" style="margin-top:16px">
<?= csrf_field() ?>
<input type="hidden" name="action" value="restore">
<input name="backup_key" placeholder="faculty_event_mapping_backup_YYYYmmddHHMMSS" size="50">
<button type="submit">Restore backup</button>
</form>
</body></html>
