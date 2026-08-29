<?php
/**
 * PEPP Learning - session learner notifications.
 * Emails the approved learners of a session's course(s) about an upcoming or
 * starting Live/Offline session. Used both by the manual "Notify learners"
 * button and the automatic reminder windows (12h / 4h / 10m / start).
 */

/** Learner email list for a session's course(s) - approved, active students only. */
function session_learner_emails($pdo, $course_csv) {
    $courses = array_filter(array_map('trim', explode(',', (string)$course_csv)));
    if (!$courses) return [];
    try {
        $ph = implode(',', array_fill(0, count($courses), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT email, name FROM users
            WHERE status = 'approved' AND student_status = 'active' AND email IS NOT NULL AND email <> '' AND pepp_course IN ($ph)");
        $stmt->execute($courses);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            if (filter_var($r['email'], FILTER_VALIDATE_EMAIL)) $out[$r['email']] = $r['name'];
        }
        return $out;
    } catch (Exception $e) { error_log('session learners: ' . $e->getMessage()); return []; }
}

/**
 * Send the notification for a session.
 * $window: 'manual' | '12h' | '4h' | '10m' | 'start'
 * Returns number of recipients emailed.
 */
function notify_session_learners($pdo, $session, $window, $by = 'system') {
    // Only live/offline get learner reminders
    if (!in_array($session['session_type'], ['live', 'offline'], true)) return 0;
    $learners = session_learner_emails($pdo, $session['course_csv'] ?? '');
    if (!$learners) return 0;

    $when = date('d M Y, h:i A', strtotime($session['session_datetime']));
    $isLive = $session['session_type'] === 'live';
    $join = $isLive && !empty($session['meet_link']) ? $session['meet_link'] : '';
    $venue = !$isLive ? ($session['venue'] ?? '') : '';
    $topic = htmlspecialchars($session['topic']);
    $faculty = htmlspecialchars($session['faculty_name'] ?? '');

    $lead = [
        'manual' => 'Here is a reminder for your upcoming session.',
        '12h'    => 'Your session is coming up in about 12 hours.',
        '4h'     => 'Your session is in about 4 hours.',
        '10m'    => 'Your session starts in about 10 minutes!',
        'start'  => 'Your session is starting now!',
    ][$window] ?? 'Reminder for your upcoming session.';

    $subject = ($window === 'start' ? 'Starting now: ' : 'Reminder: ') . $session['topic'] . ' | PEPP Learning';

    $btn = $join
        ? '<a href="' . htmlspecialchars($join) . '" style="display:inline-block;background:#E8980C;color:#fff;text-decoration:none;font-weight:700;font-size:15px;border-radius:10px;padding:13px 30px;">Join the Session</a>'
        : '';
    $venueRow = $venue ? '<p style="font-size:14px;color:#374151;margin:6px 0;"><b>Venue:</b> ' . htmlspecialchars($venue) . '</p>' : '';

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f5f4;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e7e5e4;">
  <tr><td style="background:#E8980C;padding:22px 30px;">
      <div style="font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px;">pepp <span style="font-weight:400;font-size:12px;letter-spacing:3px;">LEARNING</span></div>
  </td></tr>
  <tr><td style="padding:28px 30px 8px;">
      <div style="display:inline-block;background:#fef3c7;color:#b45309;font-size:12px;font-weight:700;border-radius:50px;padding:5px 14px;">' . ($isLive ? '&#128250; Live Session' : '&#128205; Offline Session') . '</div>
      <h1 style="font-size:20px;color:#1f2937;margin:14px 0 4px;">' . $topic . '</h1>
      <p style="font-size:14px;color:#6b7280;margin:0 0 14px;">' . htmlspecialchars($lead) . '</p>
      <p style="font-size:14px;color:#374151;margin:6px 0;"><b>When:</b> ' . $when . '</p>
      ' . ($faculty ? '<p style="font-size:14px;color:#374151;margin:6px 0;"><b>Faculty:</b> ' . $faculty . '</p>' : '') . '
      ' . $venueRow . '
      ' . ($btn ? '<div style="margin:20px 0 6px;">' . $btn . '</div>' : '') . '
  </td></tr>
  <tr><td style="padding:8px 30px 26px;">
      <p style="font-size:12.5px;color:#9ca3af;line-height:1.6;margin:0;">See you there! For help, contact office@pepplearning.com.<br>This mailbox is not monitored - please do not reply.</p>
  </td></tr>
  <tr><td style="background:#1c1917;padding:14px 30px;text-align:center;">
      <div style="font-size:11px;color:#a8a29e;">&copy; ' . date('Y') . ' PEPP Learning - Labinc Education Pvt. Ltd.</div>
  </td></tr>
</table></td></tr></table></body></html>';

    $text = "PEPP Learning - {$session['topic']}\n" . $lead . "\nWhen: {$when}\n"
          . ($faculty ? "Faculty: " . strip_tags($faculty) . "\n" : '')
          . ($join ? "Join: {$join}\n" : '') . ($venue ? "Venue: " . strip_tags($venue) . "\n" : '');

    require_once __DIR__ . '/mailer.php';
    $sent = 0;
    foreach ($learners as $email => $name) {
        if (pepp_mail($email, $subject, $html, $text, [], 'noreply@pepplearning.in', 'PEPP Learning')) {
            $sent++;
        }
    }

    // Record the window so automatic sends fire only once
    try {
        $stmt = $pdo->prepare("INSERT INTO session_notifications (session_id, window_key, recipients, sent_by, sent_at) VALUES (?,?,?,?,NOW())
                               ON DUPLICATE KEY UPDATE recipients = VALUES(recipients), sent_at = NOW()");
        $stmt->execute([$session['id'], $window, $sent, $by]);
    } catch (Exception $e) { error_log('session_notifications log: ' . $e->getMessage()); }

    return $sent;
}
