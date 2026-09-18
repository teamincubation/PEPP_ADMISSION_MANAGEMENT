<?php
/**
 * PEPP Learning ERP — Lead Contact Report PDF Generator
 *
 * Dependency-free, print-quality multi-page PDF generator (PDF 1.4).
 * Formats official lead contact and outreach governance records with
 * summary KPI cards, repeated table headers across pages, and remark wrapping.
 */

declare(strict_types=1);

require_once __DIR__ . '/mentor_report_pdf.php';

if (!function_exists('mentor_pdf_wrap_text')) {
    /**
     * Wrap text into multiple lines so each fits within maxWidth.
     */
    function mentor_pdf_wrap_text($pdf, string $text, float $maxWidth, float $fontSize = 6.5, $weight = 400): array {
        $words = preg_split('/\s+/', trim($text));
        if (empty($words) || $words[0] === '') return [];

        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = ($currentLine === '') ? $word : ($currentLine . ' ' . $word);
            $w = $pdf->width($testLine, $fontSize, $weight);
            if ($w <= $maxWidth) {
                $currentLine = $testLine;
            } else {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
            }
        }
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }
        return $lines;
    }
}

/**
 * Main report renderer for Lead Contact Report PDF.
 *
 * @param array $data Structured report data
 * @return string Raw PDF bytes
 */
function render_lead_contact_report_pdf(array $data): string {
    $pdf = new MentorReportPDFWriter();

    $period       = $data['period'] ?? [];
    $generatedAt  = $data['generated_at'] ?? date('d M Y, h:i A');
    $contactedBy  = $data['contacted_by'] ?? 'All Admins';
    $contactType  = $data['contact_type'] ?? 'All Types';
    $summary      = $data['summary'] ?? [];
    $rows         = $data['rows'] ?? [];

    $lm = 32.0;
    $rm = MentorReportPDFWriter::W - 32.0;
    $usableW = $rm - $lm; // 531.28 pt

    // Color palette
    $cOrangeR = 255/255; $cOrangeG = 107/255; $cOrangeB = 0/255; // #ff6b00
    $cDarkR   = 15/255;  $cDarkG   = 23/255;  $cDarkB   = 42/255;  // #0f172a
    $cSlateR  = 30/255;  $cSlateG  = 41/255;  $cSlateB  = 59/255;  // #1e293b
    $cMutedR  = 100/255; $cMutedG  = 116/255; $cMutedB  = 139/255; // #64748b
    $cBgLightR= 248/255; $cBgLightG= 250/255; $cBgLightB= 252/255; // #f8fafc
    $cBorderR = 226/255; $cBorderG = 232/255; $cBorderB = 240/255; // #e2e8f0
    $cGreenR  = 16/255;  $cGreenG  = 185/255; $cGreenB  = 129/255; // #10b981
    $cBlueR   = 59/255;  $cBlueG   = 130/255; $cBlueB   = 246/255; // #3b82f6

    // Helper: Draw Header on Top of Page
    $drawPageHeader = function(int $pageNum) use ($pdf, $lm, $usableW, $cDarkR, $cDarkG, $cDarkB, $cOrangeR, $cOrangeG, $cOrangeB, $cMutedR, $cMutedG, $cMutedB, $generatedAt) {
        // Top orange branding line
        $pdf->fillRect($lm, 20, $usableW, 3.5, $cOrangeR, $cOrangeG, $cOrangeB);

        // Header Title
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($lm, 30, 13, 'PEPP LEARNING — LEAD CONTACT REPORT', 700);

        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($lm, 45, 7.5, 'Instant Outreach & Communication Activity Audit', 400);

        // Right side metadata
        $pdf->text($lm, 32, 7.5, 'Generated: ' . $generatedAt, 500, 'R', $usableW);
        $pdf->text($lm, 44, 7.0, 'Confidential Administration Document', 400, 'R', $usableW);

        $pdf->line($lm, 56, $lm + $usableW, 56, 0.7, 226/255, 232/255, 240/255);
    };

    // Helper: Draw Footer on Bottom of Page
    $drawPageFooter = function(int $pageNum, int $totalPages) use ($pdf, $lm, $usableW, $cMutedR, $cMutedG, $cMutedB) {
        $pdf->line($lm, 808, $lm + $usableW, 808, 0.5, 226/255, 232/255, 240/255);
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($lm, 816, 7.2, 'Generated from PEPP Learning Lead Management • Confidential Record', 400);
        $pageStr = sprintf('Page %d of %d', $pageNum, $totalPages);
        $pdf->text($lm, 816, 7.2, $pageStr, 600, 'R', $usableW);
    };

    // Table Column Definitions
    $cols = [
        ['key' => 'num',         'title' => '#',          'w' => 20,  'align' => 'C'],
        ['key' => 'lead',        'title' => 'Lead Name',  'w' => 85,  'align' => 'L'],
        ['key' => 'phone',       'title' => 'Phone',      'w' => 70,  'align' => 'L'],
        ['key' => 'course',      'title' => 'Course',     'w' => 75,  'align' => 'L'],
        ['key' => 'type',        'title' => 'Type',       'w' => 45,  'align' => 'C'],
        ['key' => 'admin',       'title' => 'Admin',      'w' => 60,  'align' => 'L'],
        ['key' => 'datetime',    'title' => 'Date & Time','w' => 68,  'align' => 'L'],
        ['key' => 'status',      'title' => 'Status',     'w' => 46,  'align' => 'C'],
        ['key' => 'remark',      'title' => 'Remark',     'w' => 62,  'align' => 'L'],
    ];

    // Helper: Draw Table Header Row
    $drawTableHeader = function(float $yPos) use ($pdf, $lm, $usableW, $cols, $cBgLightR, $cBgLightG, $cBgLightB, $cSlateR, $cSlateG, $cSlateB): float {
        $headerH = 18.0;
        $pdf->roundedRect($lm, $yPos, $usableW, $headerH, 3, $cBgLightR, $cBgLightG, $cBgLightB, true, true, 203/255, 213/255, 225/255, 0.7);

        $curX = $lm;
        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        foreach ($cols as $col) {
            $pdf->text($curX + 3, $yPos + 5.5, 7.2, $col['title'], 700, $col['align'], $col['w'] - 6);
            $curX += $col['w'];
        }
        return $yPos + $headerH + 2.0;
    };

    // PAGE 1: Header + Filter Meta + Summary Cards + Table
    $drawPageHeader(1);

    $y = 66.0;

    // ── Filter Metadata Box ──
    $metaH = 34.0;
    $pdf->roundedRect($lm, $y, $usableW, $metaH, 4, 250/255, 250/255, 252/255, true, true, 226/255, 232/255, 240/255, 0.7);

    $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
    $pdf->text($lm + 10, $y + 8, 7.5, 'Report Period:', 700);
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $fromStr = !empty($period['from']) ? $period['from'] : 'All Available';
    $toStr   = !empty($period['to']) ? $period['to'] : 'Present';
    $pdf->text($lm + 75, $y + 8, 7.5, $fromStr . '  to  ' . $toStr, 400);

    $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
    $pdf->text($lm + 280, $y + 8, 7.5, 'Contacted By:', 700);
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($lm + 345, $y + 8, 7.5, (string)$contactedBy, 400);

    $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
    $pdf->text($lm + 10, $y + 20, 7.5, 'Contact Type:', 700);
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($lm + 75, $y + 20, 7.5, (string)$contactType, 400);

    $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
    $pdf->text($lm + 280, $y + 20, 7.5, 'Report Scope:', 700);
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($lm + 345, $y + 20, 7.5, 'Latest Instant Contact per Lead', 400);

    $y += $metaH + 8.0;

    // ── Summary KPI Cards ──
    $cardGap = 8.0;
    $cardW = ($usableW - ($cardGap * 3)) / 4;
    $cardH = 34.0;

    $kpiItems = [
        ['label' => 'LEADS CONTACTED', 'val' => (string)($summary['total'] ?? count($rows)), 'r' => 241/255, 'g' => 245/255, 'b' => 249/255, 'cr' => $cDarkR, 'cg' => $cDarkG, 'cb' => $cDarkB],
        ['label' => 'CALLED',          'val' => (string)($summary['called'] ?? 0),           'r' => 240/255, 'g' => 253/255, 'b' => 244/255, 'cr' => 5/255,   'cg' => 150/255, 'cb' => 105/255],
        ['label' => 'TEXTED',          'val' => (string)($summary['texted'] ?? 0),           'r' => 239/255, 'g' => 246/255, 'b' => 255/255, 'cr' => 37/255,  'cg' => 99/255,  'cb' => 235/255],
        ['label' => 'ADMINS INVOLVED', 'val' => (string)($summary['admins'] ?? 0),           'r' => 255/255, 'g' => 247/255, 'b' => 237/255, 'cr' => $cOrangeR,'cg' => $cOrangeG,'cb' => $cOrangeB],
    ];

    $cx = $lm;
    foreach ($kpiItems as $kpi) {
        $pdf->roundedRect($cx, $y, $cardW, $cardH, 4, $kpi['r'], $kpi['g'], $kpi['b'], true, true, 226/255, 232/255, 240/255, 0.7);
        $pdf->setTextColor(100/255, 116/255, 139/255);
        $pdf->text($cx, $y + 6, 6.0, $kpi['label'], 700, 'C', $cardW);
        $pdf->setTextColor($kpi['cr'], $kpi['cg'], $kpi['cb']);
        $pdf->text($cx, $y + 16, 12.0, $kpi['val'], 700, 'C', $cardW);
        $cx += $cardW + $cardGap;
    }

    $y += $cardH + 12.0;

    // ── Table Header ──
    $y = $drawTableHeader($y);

    $rowNum = 1;
    $currentPage = 1;

    if (empty($rows)) {
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($lm, $y + 16, 8.5, 'No instant contact records found matching the active criteria.', 400, 'C', $usableW);
    } else {
        foreach ($rows as $r) {
            $remarkText = trim((string)($r['remark'] ?? ''));
            // Word-wrap remark to fit column (width 62 - padding 6 = 56pt)
            $wrappedRemark = mentor_pdf_wrap_text($pdf, $remarkText ?: '-', 56.0, 6.5, 400);
            if (empty($wrappedRemark)) {
                $wrappedRemark = ['-'];
            }
            // Limit remark display to 4 lines maximum to prevent infinite page explosion
            if (count($wrappedRemark) > 4) {
                $wrappedRemark = array_slice($wrappedRemark, 0, 3);
                $wrappedRemark[] = '...';
            }

            $lineCount = count($wrappedRemark);
            $rowH = max(16.0, $lineCount * 8.5 + 6.0);

            // Check if row fits on current page (page limit ~790 pt)
            if ($y + $rowH > 790.0) {
                $pdf->addPage();
                $currentPage++;
                $drawPageHeader($currentPage);
                $y = 66.0;
                $y = $drawTableHeader($y);
            }

            // Alternating row background
            if ($rowNum % 2 === 0) {
                $pdf->fillRect($lm, $y, $usableW, $rowH, 252/255, 253/255, 254/255);
            }
            $pdf->line($lm, $y + $rowH, $lm + $usableW, $y + $rowH, 0.4, 241/255, 245/255, 249/255);

            $curX = $lm;

            // 1. # (number)
            $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
            $pdf->text($curX + 2, $y + 4.5, 6.8, (string)$rowNum, 400, 'C', $cols[0]['w'] - 4);
            $curX += $cols[0]['w'];

            // 2. Lead Name
            $leadName = (string)($r['name'] ?? 'Unknown');
            if (strlen($leadName) > 18) $leadName = substr($leadName, 0, 17) . '..';
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($curX + 3, $y + 4.5, 7.0, $leadName, 600, 'L', $cols[1]['w'] - 6);
            $curX += $cols[1]['w'];

            // 3. Phone / WhatsApp
            $phone = (string)($r['phone'] ?? $r['whatsapp_number'] ?? '-');
            $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
            $pdf->text($curX + 3, $y + 4.5, 6.8, $phone, 400, 'L', $cols[2]['w'] - 6);
            $curX += $cols[2]['w'];

            // 4. Course
            $course = (string)($r['course'] ?? $r['interested_course'] ?? '-');
            if (strlen($course) > 16) $course = substr($course, 0, 15) . '..';
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($curX + 3, $y + 4.5, 6.8, $course, 400, 'L', $cols[3]['w'] - 6);
            $curX += $cols[3]['w'];

            // 5. Contact Type (Called vs Texted badge)
            $cType = (string)($r['contact_type'] ?? 'Called');
            $isCalled = (strpos(strtolower($cType), 'called') !== false);
            if ($isCalled) {
                $pdf->roundedRect($curX + 3, $y + 3.0, $cols[4]['w'] - 6, 10.0, 2, 240/255, 253/255, 244/255, true, true, 187/255, 247/255, 208/255, 0.5);
                $pdf->setTextColor(22/255, 101/255, 52/255);
                $pdf->text($curX + 3, $y + 4.5, 6.2, 'Called', 700, 'C', $cols[4]['w'] - 6);
            } else {
                $pdf->roundedRect($curX + 3, $y + 3.0, $cols[4]['w'] - 6, 10.0, 2, 239/255, 246/255, 255/255, true, true, 191/255, 219/255, 254/255, 0.5);
                $pdf->setTextColor(30/255, 64/255, 175/255);
                $pdf->text($curX + 3, $y + 4.5, 6.2, 'Texted', 700, 'C', $cols[4]['w'] - 6);
            }
            $curX += $cols[4]['w'];

            // 6. Contacted By Admin
            $adm = (string)($r['contacted_by'] ?? $r['performed_by'] ?? '-');
            if (strlen($adm) > 13) $adm = substr($adm, 0, 12) . '..';
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($curX + 3, $y + 4.5, 6.8, $adm, 400, 'L', $cols[5]['w'] - 6);
            $curX += $cols[5]['w'];

            // 7. Date & Time
            $pDate = (string)($r['contact_date'] ?? '');
            $pTime = (string)($r['contact_time'] ?? '');
            if (!$pDate && !empty($r['performed_at'])) {
                $ts = strtotime((string)$r['performed_at']);
                $pDate = date('d M Y', $ts);
                $pTime = date('h:i A', $ts);
            }
            $dtStr = trim($pDate . ' ' . $pTime);
            $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
            $pdf->text($curX + 3, $y + 4.5, 6.4, $dtStr ?: '-', 400, 'L', $cols[6]['w'] - 6);
            $curX += $cols[6]['w'];

            // 8. Lead Status
            $st = (string)($r['status'] ?? $r['current_status'] ?? '-');
            if (strlen($st) > 10) $st = substr($st, 0, 9) . '.';
            $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
            $pdf->text($curX + 2, $y + 4.5, 6.5, ucfirst($st), 500, 'C', $cols[7]['w'] - 4);
            $curX += $cols[7]['w'];

            // 9. Remark (wrapped)
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $ry = $y + 4.5;
            foreach ($wrappedRemark as $rLine) {
                $pdf->text($curX + 3, $ry, 6.3, $rLine, 400, 'L', $cols[8]['w'] - 6);
                $ry += 8.2;
            }

            $y += $rowH;
            $rowNum++;
        }
    }

    // Two-pass footer: set exact total pages on every page
    $totalPages = $pdf->getPageCount();
    for ($p = 0; $p < $totalPages; $p++) {
        $pdf->setPage($p);
        $drawPageFooter($p + 1, $totalPages);
    }

    return $pdf->output();
}
