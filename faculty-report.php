<?php
require_once 'includes/auth.php';
require_permission('faculties');
require_once 'includes/pdf_invoice.php';   // MiniPDF + helpers

/* Faculty statement: a simple PDF summary of completed sessions, earnings,
   payments and balance. ?id= required; &email=1 sends it to the faculty. */

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: faculties.php'); exit(); }

$TYPE_RATE = ['live' => 'rate_live', 'qpd' => 'rate_qpd', 'recorded' => 'rate_recorded', 'offline' => 'rate_offline'];
$TYPE_LBL  = ['live' => 'Live', 'qpd' => 'QPD', 'recorded' => 'Recorded', 'offline' => 'Offline'];

try {
    $stmt = $pdo->prepare("SELECT * FROM faculties WHERE id = ?"); $stmt->execute([$id]);
    $f = $stmt->fetch();
} catch (Exception $e) { $f = null; }
if (!$f) { header('Location: faculties.php'); exit(); }

$rows = []; $earned = 0.0; $completed = 0;
try {
    $stmt = $pdo->prepare("SELECT * FROM sessions WHERE faculty_id = ? AND status = 'completed' ORDER BY session_datetime ASC");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $s) {
        $rate = (float)$f[$TYPE_RATE[$s['session_type']] ?? 'rate_live'];
        $amt = $rate * (float)$s['duration_hours'];
        $earned += $amt; $completed++;
        $rows[] = [$s['session_datetime'], $TYPE_LBL[$s['session_type']] ?? $s['session_type'], $s['topic'], (float)$s['duration_hours'], $rate, $amt];
    }
} catch (Exception $e) {}
$paid = 0.0;
try { $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM faculty_payments WHERE faculty_id = ?"); $stmt->execute([$id]); $paid = (float)$stmt->fetchColumn(); } catch (Exception $e) {}
$due = max(0, $earned - $paid);

/* ── Build the PDF ── */
$pdf = new MiniPDF();
$L = 50; $R = MiniPDF::W - 50; $W = $R - $L;
$logo = __DIR__ . '/pepp-logo.jpg';
$pdf->image($logo, $L, 44, 92, 42);
$pdf->text($L, 48, 9, 'Faculty Statement', false, 'R', $W);
$pdf->text($L, 60, 9, date('d-m-Y'), false, 'R', $W);
$pdf->text($L, 95, 14, 'Faculty Payment Statement', true, 'C', $W);
$y = 120;
$pdf->text($L, $y, 10, 'PEPP Learning - Labinc Education Pvt. Ltd.', false, 'C', $W); $y += 18;
$pdf->line($L, $y, $R, $y); $y += 12;
$pdf->text($L, $y, 10, 'Faculty: ' . $f['name'], true); $y += 14;
if ($f['email'])  { $pdf->text($L, $y, 9, 'Email: ' . $f['email']); $y += 12; }
if ($f['mobile']) { $pdf->text($L, $y, 9, 'Mobile: ' . $f['mobile']); $y += 12; }
$y += 6;
$pdf->line($L, $y, $R, $y); $y += 10;

// Table header
$pdf->text($L, $y, 9, 'Date', true);
$pdf->text($L + 90, $y, 9, 'Type', true);
$pdf->text($L + 150, $y, 9, 'Topic', true);
$pdf->text($R - 130, $y, 9, 'Hrs', true);
$pdf->text($R - 95, $y, 9, 'Rate', true);
$pdf->text($R - 50, $y, 9, 'Amount', true);
$y += 6; $pdf->line($L, $y, $R, $y); $y += 10;

foreach ($rows as $r) {
    if ($y > 760) { break; }
    $pdf->text($L, $y, 8.5, date('d-m-y', strtotime($r[0])));
    $pdf->text($L + 90, $y, 8.5, $r[1]);
    $pdf->text($L + 150, $y, 8.5, substr($r[2], 0, 32));
    $pdf->text($R - 135, $y, 8.5, rtrim(rtrim(number_format($r[3], 2), '0'), '.'));
    $pdf->text($R - 100, $y, 8.5, number_format($r[4], 0));
    $pdf->text($R - 55, $y, 8.5, number_format($r[5], 2));
    $y += 13;
}
$y += 4; $pdf->line($L, $y, $R, $y); $y += 12;
$pdf->text($L, $y, 10, 'Completed sessions: ' . $completed, false); $y += 16;
$pdf->text($R - 220, $y, 10, 'Total Earned:', true, 'R', 150); $pdf->text($R - 60, $y, 10, rs($earned), false, 'R', 60); $y += 14;
$pdf->text($R - 220, $y, 10, 'Total Paid:', true, 'R', 150);   $pdf->text($R - 60, $y, 10, rs($paid), false, 'R', 60); $y += 14;
$pdf->text($R - 220, $y, 11, 'Balance Due:', true, 'R', 150);  $pdf->text($R - 60, $y, 11, rs($due), true, 'R', 60); $y += 20;
$pdf->text($L, $y, 9, 'Amount in words: ' . inr_in_words($due)); $y += 24;
$pdf->line($L, $y, $R, $y); $y += 9;
$pdf->text($L, $y, 8, 'PEPP Learning · office@pepplearning.com · 7025000444', false, 'C', $W);

$bytes = $pdf->output();
$fname = 'faculty-statement-' . preg_replace('/[^A-Za-z0-9]/', '-', $f['name']) . '.pdf';

if (isset($_GET['email']) && $f['email'] && filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
    $bAlt = 'a' . md5(uniqid('', true)); $bMix = 'm' . md5(uniqid('', true));
    $subject = '=?UTF-8?B?' . base64_encode('Your Payment Statement | PEPP Learning') . '?=';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#374151;">'
          . '<p>Dear ' . htmlspecialchars($f['name']) . ',</p>'
          . '<p>Please find attached your payment statement.</p>'
          . '<p>Completed sessions: <b>' . $completed . '</b><br>Total earned: <b>Rs. ' . number_format($earned, 2) . '</b>'
          . '<br>Total paid: <b>Rs. ' . number_format($paid, 2) . '</b><br>Balance due: <b>Rs. ' . number_format($due, 2) . '</b></p>'
          . '<p style="color:#9ca3af;font-size:12px;">PEPP Learning - Labinc Education Pvt. Ltd. This mailbox is not monitored.</p></div>';
    $headers = "From: PEPP Learning <noreply@pepplearning.in>\r\nReply-To: noreply@pepplearning.in\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$bMix\"";
    $body  = "--$bMix\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n";
    $body .= "--$bMix\r\nContent-Type: application/pdf; name=\"$fname\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fname\"\r\n\r\n" . chunk_split(base64_encode($bytes)) . "\r\n--$bMix--";
    $ok = @mail($f['email'], $subject, $body, $headers);
    log_admin_activity($pdo, $admin_username, 'faculty_statement_emailed', "Statement emailed to {$f['name']} ({$f['email']})" . ($ok ? '' : ' [FAILED]'));
    header('Location: faculties.php?view=' . $id . '&msg=' . ($ok ? 'mailed' : 'mailfail'));
    exit();
}

log_admin_activity($pdo, $admin_username, 'faculty_statement_downloaded', "Downloaded statement for {$f['name']}");
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
exit();
