<?php
/**
 * PEPP Learning - invoice generation engine.
 *
 * generate_payment_invoice() is called automatically when a payment is
 * approved (registration approval, manual add, installment approval) and by
 * the "Generate missing invoices" backfill on the Invoices page.
 *
 * GST rule: payments received in the configured GST account (AXIS LABINC)
 * are GST-INCLUSIVE at 18% → taxable = gross × 100/118, CGST = SGST = 9%.
 * Numbering:
 *   GST     : {prefix}/{FY}/{seq}   e.g. INV/2627/001  (own sequence,
 *             series validity dates managed in Settings)
 *   Non-GST : {prefix}/{DDMMYY of paid date}/{seq}  e.g. INV/120627/001
 *             (independent running sequence)
 * Number allocation is atomic (row lock on the counter), so two approvals
 * at the same moment can never produce the same invoice number.
 */

// Defensive loading: a missing companion file must never cause a fatal 500.
$__pdf_inc  = __DIR__ . '/pdf_invoice.php';
$__mail_inc = __DIR__ . '/invoice_mailer.php';
if (file_exists($__pdf_inc))  { require_once $__pdf_inc; }
if (file_exists($__mail_inc)) { require_once $__mail_inc; }

function invoice_setting($pdo, $name, $default = '') {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = ?");
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : $v;
    } catch (Exception $e) { return $default; }
}

/** The payment account whose receipts carry 18% GST. */
function gst_account_id($pdo) {
    $id = (int)invoice_setting($pdo, 'inv_gst_account_id', '0');
    if ($id > 0) return $id;
    try { // fallback: detect by name
        $stmt = $pdo->query("SELECT id FROM payment_accounts WHERE account_name LIKE '%AXIS%' AND account_name LIKE '%LABINC%' LIMIT 1");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function invoices_table_exists($pdo) {
    static $exists = null;
    if ($exists === null) {
        try { $exists = (bool)$pdo->query("SHOW TABLES LIKE 'invoices'")->fetchColumn(); }
        catch (Exception $e) { $exists = false; }
    }
    return $exists;
}

/** Atomically allocate the next invoice number. Must NOT be called inside an open transaction. */
function allocate_invoice_no($pdo, $type, $paid_date) {
    $key = $type === 'gst' ? 'inv_gst_seq' : 'inv_nongst_seq';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = ? FOR UPDATE");
        $stmt->execute([$key]);
        $seq = (int)$stmt->fetchColumn();
        if ($seq < 1) $seq = 1;
        $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = NOW() WHERE setting_name = ?")
            ->execute([(string)($seq + 1), $key]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $pad = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    if ($type === 'gst') {
        $prefix = invoice_setting($pdo, 'inv_gst_prefix', 'INV');
        $fy     = invoice_setting($pdo, 'inv_gst_fy', '2627');
        return "{$prefix}/{$fy}/{$pad}";
    }
    $prefix = invoice_setting($pdo, 'inv_nongst_prefix', 'INV');
    $d = $paid_date ? date('dmy', strtotime($paid_date)) : date('dmy');
    return "{$prefix}/{$d}/{$pad}";
}

/**
 * Create the invoice record for one approved payment (and optionally email it).
 *
 * $opts:
 *   source           'registration' | 'installment'        (required)
 *   source_ref       users.id | instalment_details.id      (required)
 *   user_id          PEPP user_id                          (required)
 *   amount           gross amount received                 (required)
 *   account_id       payment_accounts.id or null
 *   payment_mode     string
 *   paid_date        Y-m-d
 *   instalment_number int|null
 *   generated_by     admin username
 *   send_email       bool (default true)
 *
 * @return array [bool ok, string message, ?int invoice_id, ?string invoice_no]
 */
function generate_payment_invoice($pdo, array $opts) {
    if (!invoices_table_exists($pdo)) {
        return [false, 'Invoices table missing - run database-update-3.sql.', null, null];
    }
    try {
        $source = $opts['source'];
        $ref    = (int)$opts['source_ref'];
        $amount = round((float)$opts['amount'], 2);
        if ($amount <= 0) return [false, 'Zero amount - no invoice needed.', null, null];

        // Already invoiced?
        $stmt = $pdo->prepare("SELECT id, invoice_no FROM invoices WHERE source = ? AND source_ref = ? LIMIT 1");
        $stmt->execute([$source, $ref]);
        if ($ex = $stmt->fetch()) {
            return [true, 'Invoice already exists: ' . $ex['invoice_no'], (int)$ex['id'], $ex['invoice_no']];
        }

        // Student details
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$opts['user_id']]);
        $student = $stmt->fetch();
        if (!$student) return [false, 'Student not found for invoice.', null, null];

        $account_id = !empty($opts['account_id']) ? (int)$opts['account_id'] : null;
        $isGst = $account_id && $account_id === gst_account_id($pdo);

        // GST split (amount is inclusive of 18%)
        if ($isGst) {
            $taxable = round($amount * 100 / 118, 2);
            $cgst = $sgst = round(($amount - $taxable) / 2, 2);
            $round_off = round($amount - ($taxable + $cgst + $sgst), 2);
        } else {
            $taxable = $amount; $cgst = $sgst = 0.0; $round_off = 0.0;
        }

        $paid_date = !empty($opts['paid_date']) ? $opts['paid_date'] : date('Y-m-d');
        $invoice_no = allocate_invoice_no($pdo, $isGst ? 'gst' : 'non_gst', $paid_date);

        $stmt = $pdo->prepare("
            INSERT INTO invoices
                (invoice_no, invoice_type, user_id, student_name, email, course, payment_plan,
                 source, source_ref, instalment_number, gross_amount, taxable_value,
                 cgst_amount, sgst_amount, round_off, payment_account_id, payment_mode,
                 paid_date, email_status, generated_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'skipped', ?, NOW())
        ");
        $stmt->execute([
            $invoice_no, $isGst ? 'gst' : 'non_gst', $student['user_id'], $student['name'],
            $student['email'], $student['pepp_course'], $student['payment_plan'] ?: 'One Time',
            $source, $ref, $opts['instalment_number'] ?? null,
            $amount, $taxable, $cgst, $sgst, $round_off,
            $account_id, $opts['payment_mode'] ?? null, $paid_date,
            $opts['generated_by'] ?? 'system'
        ]);
        $invoice_id = (int)$pdo->lastInsertId();

        track_record($pdo, $student['user_id'], 'invoice_generated',
            ($isGst ? 'GST invoice ' : 'Invoice ') . $invoice_no . ' for Rs. ' . number_format($amount, 2)
            . ($source === 'installment' ? ' (installment #' . ($opts['instalment_number'] ?? '?') . ')' : ' (registration)'),
            $opts['generated_by'] ?? 'system');

        // ── Email (never breaks the payment flow) ────────────────
        $send = array_key_exists('send_email', $opts) ? (bool)$opts['send_email'] : true;
        if ($send && !function_exists('render_invoice_pdf')) {
            error_log('Invoice email skipped: includes/pdf_invoice.php or invoice_mailer.php missing on server');
            $send = false;
        }
        if ($send && function_exists('send_invoice_email') && filter_var($student['email'], FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT i.*, pa.account_name FROM invoices i LEFT JOIN payment_accounts pa ON pa.id = i.payment_account_id WHERE i.id = ?");
            $stmt->execute([$invoice_id]);
            $inv = $stmt->fetch();
            $pdfBytes = render_invoice_pdf($inv, $inv['account_name'] ?? '');
            $sent = send_invoice_email($inv, $pdfBytes);
            $pdo->prepare("UPDATE invoices SET email_status = ? WHERE id = ?")
                ->execute([$sent ? 'sent' : 'failed', $invoice_id]);
        }

        return [true, 'Invoice ' . $invoice_no . ' generated.', $invoice_id, $invoice_no];

    } catch (Exception $e) {
        error_log('Invoice generation: ' . $e->getMessage());
        return [false, 'Invoice generation failed (payment itself is saved).', null, null];
    }
}
