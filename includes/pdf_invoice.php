<?php
/**
 * PEPP Learning - dependency-free PDF invoice renderer.
 * A minimal PDF 1.4 writer (text, lines, rectangles, one embedded JPEG)
 * plus the two invoice layouts:
 *   GST invoice     - matches the Labinc Education Pvt. Ltd. e-Invoice
 *                     format (GSTIN, CGST/SGST split, tax tables)
 *   Non-GST invoice - clean professional receipt, no tax details.
 * Amounts are printed as "Rs." because the built-in PDF fonts (Helvetica)
 * have no rupee glyph.
 */

class MiniPDF {
    const W = 595.28;  // A4 portrait, points
    const H = 841.89;

    private $ops = '';
    private $jpeg = null;   // [bytes, width(px), height(px)]

    private function esc($s) {
        $s = (string)$s;
        // Latin-1 only (built-in font encoding); strip anything else
        $s = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
        if ($s === false) $s = '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
    /** Approximate Helvetica string width in points. */
    public function width($txt, $size) { return strlen((string)$txt) * $size * 0.5; }

    /** Text at top-left based (x,y). align: L | C | R within $w. */
    public function text($x, $y, $size, $txt, $bold = false, $align = 'L', $w = 0) {
        $tw = $this->width($txt, $size);
        if ($align === 'C') $x += max(0, ($w - $tw) / 2);
        if ($align === 'R') $x += max(0, $w - $tw);
        $py = self::H - $y - $size * 0.78;
        $f  = $bold ? 'F2' : 'F1';
        $this->ops .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $f, $size, $x, $py, $this->esc($txt));
    }
    public function line($x1, $y1, $x2, $y2, $wid = 0.7) {
        $this->ops .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $wid, $x1, self::H - $y1, $x2, self::H - $y2);
    }
    public function rect($x, $y, $w, $h, $wid = 0.7) {
        $this->ops .= sprintf("%.2f w %.2f %.2f %.2f %.2f re S\n", $wid, $x, self::H - $y - $h, $w, $h);
    }
    public function fillRect($x, $y, $w, $h, $r, $g, $b) {
        $this->ops .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f 0 0 0 rg\n", $r, $g, $b, $x, self::H - $y - $h, $w, $h);
    }
    /** Embed one baseline JPEG; draw it at top-left (x,y) sized w×h points. */
    public function image($jpegPath, $x, $y, $w, $h) {
        if (!$jpegPath || !is_readable($jpegPath)) return false;
        $bytes = file_get_contents($jpegPath);
        if (substr($bytes, 0, 2) !== "\xFF\xD8") return false;
        // parse SOF for pixel dimensions
        $i = 2; $pw = 0; $ph = 0; $n = strlen($bytes);
        while ($i < $n - 9) {
            if (ord($bytes[$i]) !== 0xFF) { $i++; continue; }
            $m = ord($bytes[$i + 1]);
            if (in_array($m, [0xC0, 0xC1, 0xC2], true)) {
                $ph = (ord($bytes[$i + 5]) << 8) + ord($bytes[$i + 6]);
                $pw = (ord($bytes[$i + 7]) << 8) + ord($bytes[$i + 8]);
                break;
            }
            if ($m === 0xD8 || ($m >= 0xD0 && $m <= 0xD9)) { $i += 2; continue; }
            $len = (ord($bytes[$i + 2]) << 8) + ord($bytes[$i + 3]);
            $i += 2 + $len;
        }
        if (!$pw || !$ph) return false;
        $this->jpeg = [$bytes, $pw, $ph];
        $this->ops .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q\n", $w, $h, $x, self::H - $y - $h);
        return true;
    }

    /** Assemble the final PDF bytes. */
    public function output() {
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $res = "/Font << /F1 5 0 R /F2 6 0 R >>";
        if ($this->jpeg) $res .= " /XObject << /Im1 7 0 R >>";
        $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . " " . self::H . "] /Resources << $res >> /Contents 4 0 R >>";
        $stream  = $this->ops;
        $objs[4] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";
        if ($this->jpeg) {
            [$jb, $jw, $jh] = $this->jpeg;
            $objs[7] = "<< /Type /XObject /Subtype /Image /Width $jw /Height $jh /ColorSpace /DeviceRGB"
                     . " /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jb) . " >>\nstream\n" . $jb . "\nendstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max  = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}

/* ── Indian number-to-words (rupees + paise) ───────────────────────────── */
function inr_in_words($amount) {
    $amount = round((float)$amount, 2);
    $rupees = (int)floor($amount);
    $paise  = (int)round(($amount - $rupees) * 100);

    $u = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $t = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $two = function ($n) use ($u, $t) {
        if ($n < 20) return $u[$n];
        return trim($t[intdiv($n, 10)] . ($n % 10 ? ' ' . $u[$n % 10] : ''));
    };
    $words = function ($n) use (&$words, $two) {
        if ($n == 0) return '';
        $out = '';
        if ($n >= 10000000) { $out .= $words(intdiv($n, 10000000)) . ' Crore ';  $n %= 10000000; }
        if ($n >= 100000)   { $out .= $words(intdiv($n, 100000))   . ' Lakh ';   $n %= 100000; }
        if ($n >= 1000)     { $out .= $words(intdiv($n, 1000))     . ' Thousand '; $n %= 1000; }
        if ($n >= 100)      { $out .= $two(intdiv($n, 100))        . ' Hundred '; $n %= 100; }
        if ($n > 0)         { $out .= $two($n) . ' '; }
        return $out;
    };
    $s = $rupees > 0 ? 'Rupees ' . trim($words($rupees)) : '';
    if ($paise > 0) $s .= ($s ? ' and ' : '') . trim($words($paise)) . ' Paise';
    return trim($s ?: 'Zero Rupees') . ' Only';
}

function rs($v, $dec = 2) { return 'Rs. ' . number_format((float)$v, $dec); }

/* ── Invoice layouts ───────────────────────────────────────────────────── */
/**
 * @param array $inv     invoices table row
 * @param string $account payment account name ('' if none)
 * @return string PDF bytes
 */
function render_invoice_pdf(array $inv, $account = '') {
    $pdf = new MiniPDF();
    $L = 60; $R = MiniPDF::W - 60; $W = $R - $L;
    $logo = __DIR__ . '/../pepp-logo.jpg';
    $isGst = ($inv['invoice_type'] === 'gst');

    // Outer frame
    $pdf->rect($L - 10, 50, $W + 20, $isGst ? 600 : 470, 1);

    // ── Header ──
    $pdf->image($logo, $L, 62, 96, 43.5);
    $pdf->text($L, 66, 9, 'e-Invoice', false, 'R', $W);
    $pdf->text($L, 78, 9, date('d-m-Y, H:i', strtotime($inv['created_at'] ?? 'now')) . ' hrs', false, 'R', $W);
    $pdf->text($L, 70, 15, $isGst ? 'Payment Invoice' : 'Payment Receipt', true, 'C', $W);

    $y = 118;
    $pdf->text($L, $y, 11.5, 'PEPP Learning', true, 'C', $W); $y += 14;
    $pdf->text($L, $y, 9.5, 'Labinc Education Pvt. Ltd.', false, 'C', $W); $y += 12;
    $pdf->text($L, $y, 9, '2nd Floor, MM Ali Rd, Vellariyil Gardens, Palayam, Kozhikode, Kerala-673002', false, 'C', $W); $y += 12;
    if ($isGst) { $pdf->text($L, $y, 9, 'GSTIN/UIN : 32AAFCL3813L1ZL', false, 'C', $W); $y += 12; }
    $y += 4;
    $pdf->line($L - 10, $y, $R + 10, $y); $y += 8;

    // ── Party / invoice meta ──
    $mid = $L + $W / 2;
    $pdf->text($L, $y, 9, 'Name  : ' . $inv['student_name']);
    $pdf->text($mid + 6, $y, 9, 'Invoice No. : ', false);
    $pdf->text($mid + 66, $y, 9, $inv['invoice_no'], true);
    $y += 13;
    $pdf->text($L, $y, 9, 'Email : ' . $inv['email']);
    $pdf->text($mid + 6, $y, 9, 'Date of Payment : ' . ($inv['paid_date'] ? date('d-m-Y', strtotime($inv['paid_date'])) : '-'));
    $y += 13;
    $pdf->text($L, $y, 9, 'Student ID : ' . $inv['user_id']);
    $pdf->text($mid + 6, $y, 9, 'Account : ' . ($account ?: '-'));
    $y += 15;
    $pdf->line($L - 10, $y, $R + 10, $y); $y += 8;

    $modeLine = 'Payment Mode : ' . ($inv['payment_mode'] ?: '-')
              . ($inv['payment_plan'] ? '   |   Course Plan : ' . $inv['payment_plan'] : '')
              . ($inv['source'] === 'installment' ? '   |   Installment #' . $inv['instalment_number'] : '   |   Registration Payment');
    $pdf->text($L, $y, 9, $modeLine, false, 'C', $W);
    $y += 15;

    // ── Items table ──
    $cSl = $L - 10; $cPart = $L + 30; $cHsn = $R - 170; $cAmt = $R - 90;
    $pdf->line($cSl, $y, $R + 10, $y);
    $y += 4;
    $pdf->text($cSl + 6, $y, 9, 'Sl No.', true);
    $pdf->text($cPart + 10, $y, 9, 'Particular', true, 'C', $cHsn - $cPart - 20);
    if ($isGst) $pdf->text($cHsn, $y, 9, 'HSN/SAC', true);
    $pdf->text($cAmt, $y, 9, 'Paid Amount', true, 'R', $R + 4 - $cAmt);
    $y += 14;
    $pdf->line($cSl, $y, $R + 10, $y);
    $y += 12;

    $particular = $inv['course'] . ($inv['source'] === 'installment' ? ' - Installment #' . $inv['instalment_number'] : '');
    $pdf->text($cSl + 12, $y, 9, '1');
    $pdf->text($cPart, $y, 9, substr($particular, 0, 52));
    if ($isGst) $pdf->text($cHsn, $y, 9, '9992');
    $gross = (float)$inv['gross_amount'];
    if ($isGst) {
        $pdf->text($cAmt, $y, 9, rs($inv['taxable_value']), false, 'R', $R + 4 - $cAmt);
        $y += 22;
        $pdf->text($cHsn - 60, $y, 9, 'CGST @ 9%', true, 'R', 60);
        $pdf->text($cAmt, $y, 9, rs($inv['cgst_amount']), false, 'R', $R + 4 - $cAmt); $y += 13;
        $pdf->text($cHsn - 60, $y, 9, 'SGST @ 9%', true, 'R', 60);
        $pdf->text($cAmt, $y, 9, rs($inv['sgst_amount']), false, 'R', $R + 4 - $cAmt); $y += 13;
        $pdf->text($cHsn - 60, $y, 9, 'Round Off', false, 'R', 60);
        $pdf->text($cAmt, $y, 9, rs($inv['round_off']), false, 'R', $R + 4 - $cAmt); $y += 8;
    } else {
        $pdf->text($cAmt, $y, 9, rs($gross), false, 'R', $R + 4 - $cAmt);
        $y += 8;
    }
    $y += 6;
    $pdf->line($cSl, $y, $R + 10, $y); $y += 5;
    $pdf->text($cPart, $y, 10, 'Total', true, 'R', $cHsn - $cPart + 40);
    $pdf->text($cAmt, $y, 10, rs($gross), true, 'R', $R + 4 - $cAmt);
    $y += 16;
    $pdf->line($cSl, $y, $R + 10, $y); $y += 6;

    $pdf->text($L - 4, $y, 9, 'Amount Chargeable (in words)'); $y += 12;
    $pdf->text($L - 4, $y, 9, inr_in_words($gross), true); $y += 14;

    if ($isGst) {
        $pdf->line($cSl, $y, $R + 10, $y); $y += 5;
        // Tax table
        $tx0 = $cSl; $tx1 = $L + 80; $tx2 = $L + 160; $tx3 = $L + 240; $tx4 = $L + 320; $tx5 = $R - 80;
        $pdf->text($tx0 + 6, $y + 4, 8.5, 'Transaction Type', false);
        $pdf->text($tx1, $y + 4, 8.5, 'Taxable Value', true);
        $pdf->text($tx2, $y, 8.5, 'Central Tax', true, 'C', 80);
        $pdf->text($tx4 - 60, $y, 8.5, 'State Tax', true, 'C', 80);
        $pdf->text($tx5, $y + 4, 8.5, 'Total Tax Amt.', true);
        $y += 11;
        $pdf->text($tx2, $y, 8, 'Rate    Amount', false, 'C', 80);
        $pdf->text($tx4 - 60, $y, 8, 'Rate    Amount', false, 'C', 80);
        $y += 12;
        $pdf->line($cSl, $y, $R + 10, $y); $y += 5;
        $totalTax = (float)$inv['cgst_amount'] + (float)$inv['sgst_amount'];
        $pdf->text($tx0 + 6, $y, 8.5, 'Course Fee');
        $pdf->text($tx1, $y, 8.5, rs($inv['taxable_value']));
        $pdf->text($tx2, $y, 8.5, '9%   ' . rs($inv['cgst_amount']), false, 'C', 80);
        $pdf->text($tx4 - 60, $y, 8.5, '9%   ' . rs($inv['sgst_amount']), false, 'C', 80);
        $pdf->text($tx5, $y, 8.5, rs($totalTax));
        $y += 14;
        $pdf->line($cSl, $y, $R + 10, $y); $y += 6;
        $pdf->text($L - 4, $y, 9, 'Tax amount (in words)'); $y += 12;
        $pdf->text($L - 4, $y, 9, inr_in_words($totalTax), true); $y += 14;
    }

    // ── Signature & footer ──
    $y += 6;
    $pdf->text($L, $y, 9, 'For', false, 'R', $W); $y += 13;
    $pdf->text($L, $y, 10, 'Labinc Education Pvt. Ltd.', true, 'R', $W); $y += 24;
    $pdf->line($L - 10, $y, $R + 10, $y); $y += 9;
    $pdf->text($L, $y, 8.5, 'www.pepplearning.com', false, 'C', $W); $y += 11;
    $pdf->text($L, $y, 8.5, 'PEPP Learning, Mail: office@pepplearning.com  Call: 7025000444', false, 'C', $W);
    $y += 11;
    $pdf->text($L, $y, 7.5, 'This is a computer-generated invoice and does not require a signature.', false, 'C', $W);

    return $pdf->output();
}
