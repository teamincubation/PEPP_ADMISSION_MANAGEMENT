<?php
/**
 * PEPP Learning - invoice confirmation email.
 * Sends a branded HTML confirmation with the PDF invoice attached,
 * from payments@pepplearning.in (no-reply).
 * Pure PHP mail() with a hand-built multipart MIME message - no libraries.
 */
function send_invoice_email(array $inv, $pdfBytes) {
    $to = $inv['email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $isGst   = ($inv['invoice_type'] === 'gst');
    $what    = $inv['source'] === 'installment'
        ? 'Installment #' . (int)$inv['instalment_number'] . ' payment'
        : 'Registration payment';
    $amount  = '₹' . number_format((float)$inv['gross_amount'], 2);
    $subject = 'Payment Confirmed - Invoice ' . $inv['invoice_no'] . ' | PEPP Learning';

    /* ── Designed HTML body (inline styles for mail clients) ───── */
    $name   = htmlspecialchars($inv['student_name']);
    $course = htmlspecialchars($inv['course']);
    $invNo  = htmlspecialchars($inv['invoice_no']);
    $paidOn = $inv['paid_date'] ? date('d M Y', strtotime($inv['paid_date'])) : date('d M Y');
    $mode   = htmlspecialchars($inv['payment_mode'] ?: 'Online');

    $taxRow = '';
    if ($isGst) {
        $taxRow = '
        <tr><td style="padding:8px 0;color:#6b7280;font-size:13px;">Taxable value</td>
            <td style="padding:8px 0;text-align:right;font-size:13px;">₹' . number_format((float)$inv['taxable_value'], 2) . '</td></tr>
        <tr><td style="padding:8px 0;color:#6b7280;font-size:13px;">CGST (9%) + SGST (9%)</td>
            <td style="padding:8px 0;text-align:right;font-size:13px;">₹' . number_format((float)$inv['cgst_amount'] + (float)$inv['sgst_amount'], 2) . '</td></tr>';
    }

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f5f4;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e7e5e4;">
  <tr><td style="background:#E8980C;padding:26px 32px;">
      <div style="font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">pepp <span style="font-weight:400;font-size:13px;letter-spacing:3px;">LEARNING</span></div>
      <div style="font-size:12px;color:rgba(255,255,255,.85);margin-top:2px;">Labinc Education Pvt. Ltd.</div>
  </td></tr>
  <tr><td style="padding:30px 32px 10px;">
      <div style="display:inline-block;background:#d1fae5;color:#047857;font-size:12px;font-weight:700;border-radius:50px;padding:5px 14px;">&#10004; Payment Confirmed</div>
      <h1 style="font-size:19px;color:#1f2937;margin:16px 0 6px;">Thank you, ' . $name . '!</h1>
      <p style="font-size:14px;color:#6b7280;line-height:1.6;margin:0;">
        Your ' . strtolower($what) . ' for <strong style="color:#1f2937;">' . $course . '</strong> has been received and approved.
        Your ' . ($isGst ? 'GST invoice' : 'invoice') . ' is attached to this email as a PDF.
      </p>
  </td></tr>
  <tr><td style="padding:18px 32px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafaf9;border:1px solid #e7e5e4;border-radius:12px;">
        <tr><td style="padding:18px 22px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="padding:8px 0;color:#6b7280;font-size:13px;">Invoice number</td>
                <td style="padding:8px 0;text-align:right;font-weight:700;font-size:13px;color:#1f2937;">' . $invNo . '</td></tr>
            <tr><td style="padding:8px 0;color:#6b7280;font-size:13px;">Payment type</td>
                <td style="padding:8px 0;text-align:right;font-size:13px;">' . $what . '</td></tr>
            <tr><td style="padding:8px 0;color:#6b7280;font-size:13px;">Date of payment</td>
                <td style="padding:8px 0;text-align:right;font-size:13px;">' . $paidOn . '</td></tr>
            <tr><td style="padding:8px 0;color:#6b7280;font-size:13px;">Payment mode</td>
                <td style="padding:8px 0;text-align:right;font-size:13px;">' . $mode . '</td></tr>' . $taxRow . '
            <tr><td style="padding:12px 0 4px;color:#1f2937;font-size:15px;font-weight:800;border-top:1px solid #e7e5e4;">Amount paid</td>
                <td style="padding:12px 0 4px;text-align:right;font-size:18px;font-weight:800;color:#b45309;border-top:1px solid #e7e5e4;">' . $amount . '</td></tr>
          </table>
        </td></tr>
      </table>
  </td></tr>
  <tr><td style="padding:6px 32px 28px;">
      <p style="font-size:12.5px;color:#9ca3af;line-height:1.6;margin:0;">
        Need help with this payment? Write to <a href="mailto:office@pepplearning.com" style="color:#b45309;font-weight:600;">office@pepplearning.com</a>
        or call 7025000444.<br>
        This mailbox is not monitored - please do not reply to this email.
      </p>
  </td></tr>
  <tr><td style="background:#1c1917;padding:16px 32px;text-align:center;">
      <div style="font-size:11px;color:#a8a29e;">&copy; ' . date('Y') . ' PEPP Learning - Labinc Education Pvt. Ltd. &middot; www.pepplearning.com</div>
  </td></tr>
</table>
</td></tr></table>
</body></html>';

    $text = "Payment Confirmed - PEPP Learning\n\n"
          . "Dear {$inv['student_name']},\n\n"
          . "{$what} of {$amount} for {$inv['course']} is approved.\n"
          . "Invoice No: {$inv['invoice_no']} (attached as PDF)\n"
          . "Date of payment: {$paidOn} | Mode: {$inv['payment_mode']}\n\n"
          . "PEPP Learning - Labinc Education Pvt. Ltd.\n"
          . "office@pepplearning.com | 7025000444";

    /* ── Multipart MIME: alternative(text+html) + PDF attachment ── */
    $bMix = 'mix_' . md5(uniqid('', true));
    $bAlt = 'alt_' . md5(uniqid('', true));
    $fname = str_replace(['/', '\\'], '-', $inv['invoice_no']) . '.pdf';

    $headers = "From: PEPP Learning Payments <payments@pepplearning.in>\r\n"
             . "Reply-To: noreply@pepplearning.in\r\n"
             . "MIME-Version: 1.0\r\n"
             . "X-Mailer: PEPP-Admin\r\n"
             . "Content-Type: multipart/mixed; boundary=\"{$bMix}\"";

    $body  = "--{$bMix}\r\n";
    $body .= "Content-Type: multipart/alternative; boundary=\"{$bAlt}\"\r\n\r\n";
    $body .= "--{$bAlt}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $text . "\r\n\r\n";
    $body .= "--{$bAlt}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html . "\r\n\r\n";
    $body .= "--{$bAlt}--\r\n\r\n";
    $body .= "--{$bMix}\r\n";
    $body .= "Content-Type: application/pdf; name=\"{$fname}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n";
    $body .= chunk_split(base64_encode($pdfBytes)) . "\r\n";
    $body .= "--{$bMix}--";

    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    try {
        return @mail($to, $subjectEnc, $body, $headers);
    } catch (Exception $e) {
        error_log('Invoice mail: ' . $e->getMessage());
        return false;
    }
}
