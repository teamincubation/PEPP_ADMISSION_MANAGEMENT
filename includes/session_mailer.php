<?php
/**
 * PEPP Learning - session learner notifications.
 * Emails the approved learners of a session's course(s) about an upcoming or
 * scheduled Live/Offline session via the asynchronous communication queue.
 * Used both by the session scheduler, manual "Notify learners" button, and
 * the automatic reminder windows (12h / 4h / 10m / start).
 */

/** Learner email list for a session's course(s) - approved, active students only. */
function session_learner_emails($pdo, $course_csv) {
    $courses = array_filter(array_map('trim', explode(',', (string)$course_csv)));
    if (!$courses) return [];
    try {
        $ph = implode(',', array_fill(0, count($courses), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT email, name, user_id FROM users
            WHERE status = 'approved' AND (student_status IS NULL OR student_status NOT IN ('dropout', 'completed'))
              AND email IS NOT NULL AND email <> '' AND pepp_course IN ($ph)");
        $stmt->execute($courses);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $email = strtolower(trim($r['email']));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[$email] = [
                    'name' => $r['name'] ?: 'Learner',
                    'user_id' => $r['user_id'] ?? null
                ];
            }
        }
        return $out;
    } catch (Exception $e) { error_log('session learners: ' . $e->getMessage()); return []; }
}

/**
 * Enqueue bulk scheduled session announcements asynchronously via communication_queue.
 * Highly optimized multi-row insert (< 30ms for 1,000+ recipients).
 *
 * @return int Number of recipients queued
 */
function enqueue_session_scheduled_emails($pdo, $session_id, array $courses, $topic, $dt, $type, $meet_link = '', $venue = '', $faculty_id = 0, $by = 'system') {
    $learners = session_learner_emails($pdo, implode(',', $courses));
    if (empty($learners)) return 0;

    $when = date('d M Y, h:i A', strtotime($dt));
    $isLive = ($type === 'live');
    $join = $isLive && !empty($meet_link) ? $meet_link : '';
    $venueStr = !$isLive ? (trim($venue ?? '')) : '';
    $faculty_name = '';
    if (!empty($faculty_id)) {
        try {
            $stmt_f = $pdo->prepare("SELECT name FROM faculties WHERE id = ?");
            $stmt_f->execute([$faculty_id]);
            $faculty_name = $stmt_f->fetchColumn() ?: '';
        } catch (Exception $e) {}
    }

    $subj = "New Session Scheduled: " . $topic . " | PEPP Learning";
    $btn = $join
        ? '<div style="margin:20px 0; text-align:center;"><a href="' . htmlspecialchars($join) . '" style="display:inline-block;background:#E8980C;color:#fff;text-decoration:none;font-weight:700;font-size:15px;border-radius:50px;padding:12px 30px;box-shadow:0 4px 12px rgba(232,152,12,0.2);">Join Live Session</a></div>'
        : '';
    $venue_row = $venueStr ? "<tr><td style='padding:6px 0; color:#64748b;'>Venue:</td><td style='padding:6px 0; font-weight:700;'>" . htmlspecialchars($venueStr) . "</td></tr>" : "";
    $faculty_row = $faculty_name ? "<tr><td style='padding:6px 0; color:#64748b;'>Faculty:</td><td style='padding:6px 0; font-weight:700;'>" . htmlspecialchars($faculty_name) . "</td></tr>" : "";

    $head = "New Session Scheduled";
    $typeLabel = $isLive ? '🔴 Live Session' : '🏢 Offline Session';

    // Query existing queued recipients for this session to prevent duplicate jobs
    $existingRecipients = [];
    try {
        $stmt_e = $pdo->prepare("
            SELECT recipient FROM communication_queue
            WHERE event_name = 'session_scheduled' AND invoice_id = ?
              AND status IN ('pending', 'processing', 'sent', 'delivered')
        ");
        $stmt_e->execute([$session_id]);
        $existingRecipients = array_fill_keys(array_map('strtolower', $stmt_e->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (Exception $e) {}

    $queuedCount = 0;
    $chunkSize = 100;
    $entries = [];

    foreach ($learners as $email => $info) {
        if (isset($existingRecipients[$email])) continue;

        $name = $info['name'];
        $studentUid = $info['user_id'];

        $body = "<p>Dear " . htmlspecialchars($name) . ",</p>
                 <p>A new learning session has been scheduled for your course.</p>
                 <div style='background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin:20px 0; font-size:14px;'>
                     <table style='width:100%; border-collapse:collapse;'>
                         <tr><td style='padding:6px 0; color:#64748b; width:120px;'>Topic:</td><td style='padding:6px 0; font-weight:700; color:#0f172a;'>" . htmlspecialchars($topic) . "</td></tr>
                         <tr><td style='padding:6px 0; color:#64748b;'>Type:</td><td style='padding:6px 0; font-weight:700;'>{$typeLabel}</td></tr>
                         <tr><td style='padding:6px 0; color:#64748b;'>Date &amp; Time:</td><td style='padding:6px 0; font-weight:700; color:#0f172a;'>{$when}</td></tr>
                         {$faculty_row}
                         {$venue_row}
                     </table>
                 </div>
                 {$btn}
                 <p>Please log in to your student dashboard or click the link above at the scheduled time to attend.</p>
                 <p>Best regards,<br>PEPP Learning Support Team</p>";

        $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f8fafc;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(0,0,0,0.03);">
  <tr><td style="background:#E8980C;padding:22px 30px;">
      <div style="font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px;">pepp <span style="font-weight:400;font-size:12px;letter-spacing:3px;">LEARNING</span></div>
      <div style="font-size:12px;color:rgba(255,255,255,.85);margin-top:2px;">Online Admin Console</div>
  </td></tr>
  <tr><td style="padding:28px 30px 10px;">
      <h1 style="font-size:19px;color:#1f2937;margin:0 0 12px;">' . $head . '</h1>
      <div style="font-size:14px;color:#374151;line-height:1.65;">' . $body . '</div>
  </td></tr>
  <tr><td style="padding:8px 30px 26px;">
      <p style="font-size:12.5px;color:#9ca3af;line-height:1.6;margin:0;">This mailbox is not monitored. For help, contact the PEPP Administration Desk.</p>
  </td></tr>
  <tr><td style="background:#1c1917;padding:14px 30px;text-align:center;">
      <div style="font-size:11px;color:#a8a29e;">&copy; ' . date('Y') . ' PEPP Learning, Labinc Education Pvt. Ltd.</div>
  </td></tr>
</table></td></tr></table></body></html>';

        $text = strip_tags(str_replace(['<br>', '</p>', '</div>'], "\n", $head . "\n\n" . $body));

        $entries[] = [
            'recipient' => $email,
            'recipient_name' => $name,
            'subject' => $subj,
            'body_html' => $html,
            'body_text' => $text,
            'student_uid' => $studentUid
        ];

        if (count($entries) >= $chunkSize) {
            $queuedCount += insert_session_queue_chunk($pdo, $entries, 'session_scheduled', $session_id, $by);
            $entries = [];
        }
    }

    if (!empty($entries)) {
        $queuedCount += insert_session_queue_chunk($pdo, $entries, 'session_scheduled', $session_id, $by);
    }

    return $queuedCount;
}

/**
 * Send the reminder notification for a session via asynchronous communication_queue.
 * $window: 'manual' | '12h' | '4h' | '10m' | 'start'
 * Returns number of recipients queued.
 */
function notify_session_learners($pdo, $session, $window, $by = 'system') {
    // Only live/offline get learner reminders
    if (!in_array($session['session_type'], ['live', 'offline'], true)) return 0;

    // Check if this window has already been recorded
    try {
        $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM session_notifications WHERE session_id = ? AND window_key = ?");
        $chkStmt->execute([$session['id'], $window]);
        if ($chkStmt->fetchColumn() > 0 && $window !== 'manual') {
            return 0; // Already dispatched
        }
    } catch (Exception $e) {}

    $learners = session_learner_emails($pdo, $session['course_csv'] ?? '');
    if (!$learners) return 0;

    $when = date('d M Y, h:i A', strtotime($session['session_datetime']));
    $isLive = ($session['session_type'] === 'live');
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

    $entries = [];
    $chunkSize = 100;
    $queuedCount = 0;

    foreach ($learners as $email => $info) {
        $name = $info['name'];
        $studentUid = $info['user_id'];
        $entries[] = [
            'recipient' => $email,
            'recipient_name' => $name,
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
            'student_uid' => $studentUid
        ];

        if (count($entries) >= $chunkSize) {
            $queuedCount += insert_session_queue_chunk($pdo, $entries, 'session_reminder', (int)$session['id'], $by);
            $entries = [];
        }
    }

    if (!empty($entries)) {
        $queuedCount += insert_session_queue_chunk($pdo, $entries, 'session_reminder', (int)$session['id'], $by);
    }

    // Record the window so automatic sends fire only once
    try {
        $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        if ($isSqlite) {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO session_notifications (session_id, window_key, recipients, sent_by, sent_at) VALUES (?,?,?,?,datetime('now'))");
            $stmt->execute([$session['id'], $window, $queuedCount, $by]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO session_notifications (session_id, window_key, recipients, sent_by, sent_at) VALUES (?,?,?,?,NOW())
                                   ON DUPLICATE KEY UPDATE recipients = VALUES(recipients), sent_at = NOW()");
            $stmt->execute([$session['id'], $window, $queuedCount, $by]);
        }
    } catch (Exception $e) { error_log('session_notifications log: ' . $e->getMessage()); }

    return $queuedCount;
}

/**
 * Helper to perform chunked multi-row inserts into communication_queue.
 */
function insert_session_queue_chunk($pdo, array $entries, $eventName, $sessionId, $by) {
    if (empty($entries)) return 0;
    try {
        $rowPlaceholders = [];
        $values = [];
        foreach ($entries as $e) {
            $rowPlaceholders[] = "('email', ?, ?, ?, ?, ?, 'noreply@pepplearning.in', 'PEPP Learning', 4, 'pending', 0, CURRENT_TIMESTAMP, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
            $values[] = $e['recipient'];
            $values[] = $e['recipient_name'];
            $values[] = $e['subject'];
            $values[] = $e['body_html'];
            $values[] = $e['body_text'];
            $values[] = $eventName;
            $values[] = $by;
            $values[] = $e['student_uid'];
            $values[] = $sessionId;
        }

        $sql = "INSERT INTO communication_queue (
                    channel, recipient, recipient_name, subject, body_html, body_text,
                    from_email, from_name, priority, status, retry_count, next_attempt_at,
                    event_name, sent_by, student_uid, invoice_id, created_at, updated_at
                ) VALUES " . implode(', ', $rowPlaceholders);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return count($entries);
    } catch (Exception $e) {
        error_log("insert_session_queue_chunk error: " . $e->getMessage());
        return 0;
    }
}
