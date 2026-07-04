<?php
/**
 * PEPP Learning - PEPPian (alumni) notifications.
 * Emails a PEPPian at their registered email (their username) about key
 * referral events, and CCs the handling administrator. Also records an
 * "update" flag so the admin Marketing tab can show unread-count badges.
 *
 * Sender: noreply@pepplearning.in   Admin copy: adnanmongam@gmail.com
 */

define('PEPP_ADMIN_NOTIFY_EMAIL', 'adnanmongam@gmail.com');

function peppian_notify_table($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `marketing_updates` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `kind` enum('referral','coupon') NOT NULL DEFAULT 'referral',
            `detail` varchar(255) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `seen` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `idx_mu_seen` (`seen`), KEY `idx_mu_kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok = true;
    } catch (Exception $e) { error_log('marketing_updates create: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

/** Record an unread Marketing update for the badge counts. */
function marketing_flag($pdo, $kind, $detail = '') {
    if (!peppian_notify_table($pdo)) return;
    try {
        $pdo->prepare("INSERT INTO marketing_updates (kind, detail, created_at, seen) VALUES (?,?,NOW(),0)")
            ->execute([$kind === 'coupon' ? 'coupon' : 'referral', mb_substr($detail, 0, 255)]);
    } catch (Exception $e) { error_log('marketing_flag: ' . $e->getMessage()); }
}

/** Unread counts for the Marketing nav badge: ['referral'=>n, 'coupon'=>n]. */
function marketing_unread_counts($pdo) {
    $out = ['referral' => 0, 'coupon' => 0];
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'marketing_updates'")->fetchColumn()) return $out;
        foreach ($pdo->query("SELECT kind, COUNT(*) c FROM marketing_updates WHERE seen=0 GROUP BY kind") as $r) {
            $out[$r['kind']] = (int)$r['c'];
        }
    } catch (Exception $e) {}
    return $out;
}

/** Mark a category's updates as seen (called when admin opens that Marketing tab). */
function marketing_mark_seen($pdo, $kind) {
    try {
        if ($pdo->query("SHOW TABLES LIKE 'marketing_updates'")->fetchColumn()) {
            $pdo->prepare("UPDATE marketing_updates SET seen=1 WHERE kind=? AND seen=0")->execute([$kind === 'coupon' ? 'coupon' : 'referral']);
        }
    } catch (Exception $e) {}
}

/** Low-level branded email (HTML + plain text), CC the handling admin. */
function peppian_send_email($to_email, $subject_text, $heading, $body_html, $cc_admin = true) {
    if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        // Still notify the admin even if the PEPPian email is invalid
        $to_email = null;
    }
    $subject = '=?UTF-8?B?' . base64_encode($subject_text . ' | PEPP Learning') . '?=';
    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f6f1e8;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f1e8;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #ece6dc;">
  <tr><td style="background:#E8980C;padding:22px 30px;">
      <div style="font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px;">pepp <span style="font-weight:400;font-size:12px;letter-spacing:3px;">LEARNING</span></div>
      <div style="font-size:12px;color:rgba(255,255,255,.85);margin-top:2px;">PEPPians Alumni Community</div>
  </td></tr>
  <tr><td style="padding:28px 30px 10px;">
      <h1 style="font-size:19px;color:#1f2937;margin:0 0 12px;">' . $heading . '</h1>
      <div style="font-size:14px;color:#374151;line-height:1.65;">' . $body_html . '</div>
  </td></tr>
  <tr><td style="padding:8px 30px 26px;">
      <p style="font-size:12.5px;color:#9ca3af;line-height:1.6;margin:0;">This mailbox is not monitored. For help, contact the PEPP Administration Desk.</p>
  </td></tr>
  <tr><td style="background:#1c1917;padding:14px 30px;text-align:center;">
      <div style="font-size:11px;color:#a8a29e;">&copy; ' . date('Y') . ' PEPP Learning, Labinc Education Pvt. Ltd.</div>
  </td></tr>
</table></td></tr></table></body></html>';

    $text = strip_tags(str_replace(['<br>', '</p>', '</div>'], "\n", $heading . "\n\n" . $body_html));
    $bAlt = 'a' . md5(uniqid('', true));
    $headers = "From: PEPP Learning <noreply@pepplearning.in>\r\nReply-To: noreply@pepplearning.in\r\n";
    if ($cc_admin) $headers .= "Cc: " . PEPP_ADMIN_NOTIFY_EMAIL . "\r\n";
    $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$bAlt\"";
    $body  = "--$bAlt\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n\r\n";
    $body .= "--$bAlt\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n--$bAlt--";

    try {
        if ($to_email) { @mail($to_email, $subject, $body, $headers); }
        else { @mail(PEPP_ADMIN_NOTIFY_EMAIL, $subject, $body, "From: PEPP Learning <noreply@pepplearning.in>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8"); }
    } catch (Exception $e) { error_log('peppian_send_email: ' . $e->getMessage()); }
}

/** General branded HTML email notification sender */
function peppian_send_email_general($to_email, $subject_text, $heading, $body_html, $cc_admin = false) {
    if (!$to_email || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = '=?UTF-8?B?' . base64_encode($subject_text . ' | PEPP Learning') . '?=';
    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f8fafc;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(0,0,0,0.03);">
  <tr><td style="background:#E8980C;padding:22px 30px;">
      <div style="font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px;">pepp <span style="font-weight:400;font-size:12px;letter-spacing:3px;">LEARNING</span></div>
      <div style="font-size:12px;color:rgba(255,255,255,.85);margin-top:2px;">Online Admin Console</div>
  </td></tr>
  <tr><td style="padding:28px 30px 10px;">
      <h1 style="font-size:19px;color:#1f2937;margin:0 0 12px;">' . $heading . '</h1>
      <div style="font-size:14px;color:#374151;line-height:1.65;">' . $body_html . '</div>
  </td></tr>
  <tr><td style="padding:8px 30px 26px;">
      <p style="font-size:12.5px;color:#9ca3af;line-height:1.6;margin:0;">This mailbox is not monitored. For help, contact the PEPP Administration Desk.</p>
  </td></tr>
  <tr><td style="background:#1c1917;padding:14px 30px;text-align:center;">
      <div style="font-size:11px;color:#a8a29e;">&copy; ' . date('Y') . ' PEPP Learning, Labinc Education Pvt. Ltd.</div>
  </td></tr>
</table></td></tr></table></body></html>';

    $text = strip_tags(str_replace(['<br>', '</p>', '</div>'], "\n", $heading . "\n\n" . $body_html));
    $bAlt = 'a' . md5(uniqid('', true));
    $headers = "From: PEPP Learning <noreply@pepplearning.in>\r\nReply-To: noreply@pepplearning.in\r\n";
    if ($cc_admin) $headers .= "Cc: " . PEPP_ADMIN_NOTIFY_EMAIL . "\r\n";
    $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$bAlt\"";
    $body  = "--$bAlt\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n\r\n";
    $body .= "--$bAlt\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n--$bAlt--";

    try {
        return @mail($to_email, $subject, $body, $headers);
    } catch (Exception $e) {
        error_log('peppian_send_email_general: ' . $e->getMessage());
        return false;
    }
}

/* ---- Event-specific notifications ---- */

function notify_peppian_verified($pdo, $peppian) {
    $body = 'Congratulations, <b>' . htmlspecialchars($peppian['full_name']) . '</b>! Your PEPP alumni account has been verified and linked.'
          . ($peppian['linked_courses'] ? '<br><br>Linked courses: <b>' . htmlspecialchars($peppian['linked_courses']) . '</b>' : '')
          . '<br><br>You can now access your PEPPian dashboard and join active referral programs.';
    peppian_send_email($peppian['email'], 'Alumni account verified', 'You are now a verified PEPPian', $body);
}

function notify_referral_joined($pdo, $referee_id, $student_name) {
    try {
        $stmt = $pdo->prepare("SELECT p.full_name, p.email FROM referees r JOIN peppians p ON p.id = r.peppian_id WHERE r.id = ?");
        $stmt->execute([$referee_id]); $p = $stmt->fetch();
        if (!$p) return;
        $body = 'Good news, <b>' . htmlspecialchars($p['full_name']) . '</b>! A new learner' . ($student_name ? ' (<b>' . htmlspecialchars($student_name) . '</b>)' : '')
              . ' registered using your referral code. Your earning will be credited once their admission is approved and onboarding is complete.';
        peppian_send_email($p['email'], 'New referral joined', 'A new learner joined with your code', $body);
    } catch (Exception $e) { error_log('notify_referral_joined: ' . $e->getMessage()); }
}

function notify_referral_credited($pdo, $referee_id, $amount, $student_name = '') {
    try {
        $stmt = $pdo->prepare("SELECT p.full_name, p.email FROM referees r JOIN peppians p ON p.id = r.peppian_id WHERE r.id = ?");
        $stmt->execute([$referee_id]); $p = $stmt->fetch();
        if (!$p) return;
        $w = function_exists('referee_wallet') ? referee_wallet($pdo, $referee_id) : ['balance' => 0];
        $body = 'Hi <b>' . htmlspecialchars($p['full_name']) . '</b>, a referral earning of <b>Rs. ' . number_format($amount, 2) . '</b> has been credited to your PEPP wallet'
              . ($student_name ? ' for <b>' . htmlspecialchars($student_name) . '</b>' : '') . '.'
              . '<br><br>Current wallet balance: <b>Rs. ' . number_format($w['balance'] ?? 0, 2) . '</b>';
        peppian_send_email($p['email'], 'Referral earning credited', 'Earning credited to your wallet', $body);
    } catch (Exception $e) { error_log('notify_referral_credited: ' . $e->getMessage()); }
}

function notify_referral_paid($pdo, $referee_id, $amount) {
    try {
        $stmt = $pdo->prepare("SELECT p.full_name, p.email FROM referees r JOIN peppians p ON p.id = r.peppian_id WHERE r.id = ?");
        $stmt->execute([$referee_id]); $p = $stmt->fetch();
        if (!$p) return;
        $w = function_exists('referee_wallet') ? referee_wallet($pdo, $referee_id) : ['balance' => 0];
        $body = 'Hi <b>' . htmlspecialchars($p['full_name']) . '</b>, a payout of <b>Rs. ' . number_format($amount, 2) . '</b> has been paid to you against your referral earnings.'
              . '<br><br>Remaining wallet balance: <b>Rs. ' . number_format($w['balance'] ?? 0, 2) . '</b>';
        peppian_send_email($p['email'], 'Referral payout sent', 'Your referral payout has been sent', $body);
    } catch (Exception $e) { error_log('notify_referral_paid: ' . $e->getMessage()); }
}
