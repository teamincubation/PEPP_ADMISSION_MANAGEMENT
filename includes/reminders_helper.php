<?php
/**
 * PEPP Learning - Reminders / tasks.
 * Shared helper included by includes/admin_nav.php so the reminders widget
 * (top-bar bell + urgent alerts) is available on every admin page.
 *
 * A reminder is assigned to one admin, or to ALL admins ('__ALL__'). When the
 * scheduled time arrives the assignee sees an animated urgent alert and is
 * emailed once (no-reply). Admins can postpone, complete or dismiss.
 */

function reminders_table_exists($pdo) {
    static $e = null;
    if ($e === null) { try { $e = (bool)$pdo->query("SHOW TABLES LIKE 'reminders'")->fetchColumn(); } catch (Exception $ex) { $e = false; } }
    return $e;
}

/** Reminders visible to the current admin (their own + everyone's). */
function reminders_for($pdo, $admin, $statuses = ['pending']) {
    if (!reminders_table_exists($pdo)) return [];
    try {
        $in = "'" . implode("','", array_map(function ($s) { return preg_replace('/[^a-z]/', '', $s); }, $statuses)) . "'";
        $stmt = $pdo->prepare("SELECT * FROM reminders
            WHERE status IN ($in) AND (assigned_to = ? OR assigned_to = '__ALL__')
            ORDER BY remind_at ASC");
        $stmt->execute([$admin]);
        return $stmt->fetchAll();
    } catch (Exception $e) { error_log('reminders_for: ' . $e->getMessage()); return []; }
}

/** Reminders due now for this admin: time arrived, still pending, not snoozed
    past now. (Seen-state no longer suppresses the popup - it keeps showing
    until the admin acts, which is what an emergency reminder should do, but we
    DO respect a per-admin "skip 5 minutes" snooze stored in reminder_seen.) */
function reminders_due($pdo, $admin) {
    if (!reminders_table_exists($pdo)) return [];
    try {
        $hasSeen = false;
        try { $hasSeen = (bool)$pdo->query("SHOW TABLES LIKE 'reminder_seen'")->fetchColumn(); } catch (Exception $e) {}
        $hasSnooze = false;
        try { $hasSnooze = (bool)$pdo->query("SHOW COLUMNS FROM reminders LIKE 'snooze_until'")->fetchColumn(); } catch (Exception $e) {}

        $sql = "SELECT r.* FROM reminders r WHERE r.status = 'pending' AND r.remind_at <= NOW()
                AND (r.assigned_to = ? OR r.assigned_to = '__ALL__')";
        if ($hasSnooze) $sql .= " AND (r.snooze_until IS NULL OR r.snooze_until <= NOW())";
        $sql .= " ORDER BY r.remind_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$admin]);
        return $stmt->fetchAll();
    } catch (Exception $e) { error_log('reminders_due: ' . $e->getMessage()); return []; }
}

/**
 * Send due-reminder emails once. Called lazily from the nav on each page load
 * (cheap: only fires for rows where email_sent = 0 and time has arrived).
 * Uses the same no-reply sender domain as invoices.
 */
function reminders_send_due_emails($pdo) {
    if (!reminders_table_exists($pdo)) return;
    try {
        $rows = $pdo->query("SELECT * FROM reminders WHERE status = 'pending' AND email_sent = 0 AND remind_at <= NOW()")->fetchAll();
        if (!$rows) return;

        foreach ($rows as $r) {
            // Resolve recipient email(s)
            $recipients = [];
            if ($r['assigned_to'] === '__ALL__') {
                try {
                    foreach ($pdo->query("SELECT email FROM admins WHERE status = 'active' AND email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_COLUMN) as $em) {
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) $recipients[] = $em;
                    }
                } catch (Exception $e) {}
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT email FROM admins WHERE username = ? LIMIT 1");
                    $stmt->execute([$r['assigned_to']]);
                    $em = $stmt->fetchColumn();
                    if ($em && filter_var($em, FILTER_VALIDATE_EMAIL)) $recipients[] = $em;
                } catch (Exception $e) {}
            }

            if ($recipients) {
                reminders_email($recipients, $r);
            }
            // Mark as emailed regardless (so we don't retry forever if no email on file)
            $pdo->prepare("UPDATE reminders SET email_sent = 1 WHERE id = ?")->execute([$r['id']]);
        }
    } catch (Exception $e) { error_log('reminders_send_due_emails: ' . $e->getMessage()); }
}

/** Branded no-reply reminder email. */
function reminders_email($recipients, $r) {
    $to = implode(', ', $recipients);
    $when = date('d M Y, h:i A', strtotime($r['remind_at']));
    $title = htmlspecialchars($r['title']);
    $notes = nl2br(htmlspecialchars($r['notes'] ?? ''));
    $subject = '=?UTF-8?B?' . base64_encode('Reminder: ' . $r['title'] . ' | PEPP Learning') . '?=';

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f5f4;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e7e5e4;">
  <tr><td style="background:#E8980C;padding:22px 30px;">
      <div style="font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px;">pepp <span style="font-weight:400;font-size:12px;letter-spacing:3px;">LEARNING</span></div>
      <div style="font-size:12px;color:rgba(255,255,255,.85);margin-top:2px;">Admin Reminder</div>
  </td></tr>
  <tr><td style="padding:28px 30px 8px;">
      <div style="display:inline-block;background:#fef3c7;color:#b45309;font-size:12px;font-weight:700;border-radius:50px;padding:5px 14px;">&#9200; Reminder Due</div>
      <h1 style="font-size:19px;color:#1f2937;margin:16px 0 6px;">' . $title . '</h1>
      <p style="font-size:13px;color:#9ca3af;margin:0 0 14px;">Scheduled for ' . $when . '</p>
      ' . ($notes ? '<div style="font-size:14px;color:#374151;line-height:1.6;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;padding:14px 16px;">' . $notes . '</div>' : '') . '
  </td></tr>
  <tr><td style="padding:14px 30px 26px;">
      <p style="font-size:12.5px;color:#9ca3af;line-height:1.6;margin:0;">Open the PEPP admin console to mark this reminder complete, postpone it, or dismiss it.<br>This mailbox is not monitored - please do not reply.</p>
  </td></tr>
  <tr><td style="background:#1c1917;padding:14px 30px;text-align:center;">
      <div style="font-size:11px;color:#a8a29e;">&copy; ' . date('Y') . ' PEPP Learning - Labinc Education Pvt. Ltd.</div>
  </td></tr>
</table></td></tr></table></body></html>';

    $text = "Reminder Due - PEPP Learning\n\n{$r['title']}\nScheduled: {$when}\n\n" . ($r['notes'] ?? '') . "\n\nOpen the admin console to manage this reminder.";

    $bAlt = 'alt_' . md5(uniqid('', true));
    $headers = "From: PEPP Learning <noreply@pepplearning.in>\n"
             . "Reply-To: noreply@pepplearning.in\n"
             . "MIME-Version: 1.0\n"
             . "Content-Type: multipart/alternative; boundary=\"{$bAlt}\"";
    $body  = "--{$bAlt}\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n" . $text . "\n\n";
    $body .= "--{$bAlt}\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n" . $html . "\n\n";
    $body .= "--{$bAlt}--";
    try { @mail($to, $subject, $body, $headers, "-fnoreply@pepplearning.in"); } catch (Exception $e) { error_log('reminder mail: ' . $e->getMessage()); }
}
