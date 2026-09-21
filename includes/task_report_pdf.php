<?php
/**
 * PEPP Learning ERP — Task Reminders Super Admin Work Report PDF Generator
 *
 * Multi-page dependency-free vector PDF generator (PDF 1.4).
 * Generates an official, structured operational management report for Super Admins.
 *
 * Features:
 * - A4 Portrait layout (595.28 x 841.89 pt) with crisp typography (Google Sans Flex TTF)
 * - Executive KPI summary cards (Total Workload, Completed, Pending, Postponements, Rate)
 * - Task Type analytics table with print-friendly vector progress bars
 * - Admin performance breakdown table (when All Admins is selected)
 * - Day-by-day work log with strict report_activity_date containment
 * - Consolidated same-day task cards with detailed sub-item retention
 * - Occurrence-level postponement counts without audit history clutter
 * - Two-pass repeating headers and footers with accurate 'Page X of Y'
 * - Clean empty state handling for zero-record filter matches
 */

declare(strict_types=1);

require_once __DIR__ . '/mentor_report_pdf.php';

if (!function_exists('task_pdf_wrap_text')) {
    /**
     * Wrap text into multiple lines so each fits within maxWidth.
     */
    function task_pdf_wrap_text($pdf, string $text, float $maxWidth, float $fontSize = 7.0, $weight = 400): array {
        $text = trim($text);
        if ($text === '') return [];
        $words = preg_split('/\s+/', $text);
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
 * Main report renderer for Task Reminders Work Report PDF.
 *
 * @param array $data Structured report data from task_reminders_get_history_report_data()
 * @return string Raw PDF bytes
 */
function render_task_work_report_pdf(array $data): string {
    $pdf = new MentorReportPDFWriter();

    $scope     = $data['scope'] ?? [];
    $summary   = $data['summary'] ?? [];
    $taskTypes = $data['task_types'] ?? [];
    $admins    = $data['admins'] ?? [];
    $days      = $data['days'] ?? [];

    $periodLabel = $scope['period_label'] ?? 'All Time / Complete History';
    $adminLabel  = $scope['admin_label'] ?? 'All Admins';
    $eventLabel  = $scope['event_label'] ?? 'All Events';
    $generatedAt = $scope['generated_at'] ?? date('d M Y, h:i A');
    $generatedBy = $scope['generated_by'] ?? 'Super Admin';

    // Page Geometry
    $lm = 32.0;
    $rm = MentorReportPDFWriter::W - 32.0; // 563.28 pt
    $usableW = $rm - $lm;                 // 531.28 pt

    // Color Palette
    $cOrangeR  = 255 / 255; $cOrangeG  = 107 / 255; $cOrangeB  = 0 / 255;   // #ff6b00
    $cDarkR    = 15 / 255;  $cDarkG    = 23 / 255;  $cDarkB    = 42 / 255;  // #0f172a
    $cSlateR   = 51 / 255;  $cSlateG   = 65 / 255;  $cSlateB   = 85 / 255;  // #334155
    $cMutedR   = 100 / 255; $cMutedG   = 116 / 255; $cMutedB   = 139 / 255; // #64748b
    $cBgLightR = 248 / 255; $cBgLightG = 250 / 255; $cBgLightB = 252 / 255; // #f8fafc
    $cBgMutedR = 241 / 255; $cBgMutedG = 245 / 255; $cBgMutedB = 249 / 255; // #f1f5f9
    $cBorderR  = 226 / 255; $cBorderG  = 232 / 255; $cBorderB  = 240 / 255; // #e2e8f0
    $cGreenR   = 16 / 255;  $cGreenG   = 185 / 255; $cGreenB   = 129 / 255; // #10b981
    $cBlueR    = 59 / 255;  $cBlueG    = 130 / 255; $cBlueB    = 246 / 255; // #3b82f6
    $cAmberR   = 245 / 255; $cAmberG   = 158 / 255; $cAmberB   = 11 / 255;  // #f59e0b
    $cRedR     = 239 / 255; $cRedG     = 68 / 255;  $cRedB     = 68 / 255;  // #ef4444
    $cPurpleR  = 124 / 255; $cPurpleG  = 58 / 255;  $cPurpleB  = 237 / 255; // #7c3aed

    // Header Logo Path
    $baseDir = dirname(__DIR__);
    $logoPath = $baseDir . '/logo_pepp.jpg';
    if (!file_exists($logoPath)) {
        $logoPath = $baseDir . '/pepp-logo.jpg';
    }
    if (!file_exists($logoPath)) {
        $logoPath = null;
    }

    // Helper: Draw Header on Top of Page (Pass 2)
    $drawPageHeader = function(int $pageNum) use (
        $pdf, $lm, $rm, $usableW, $cDarkR, $cDarkG, $cDarkB,
        $cOrangeR, $cOrangeG, $cOrangeB, $cMutedR, $cMutedG, $cMutedB,
        $cSlateR, $cSlateG, $cSlateB, $periodLabel, $adminLabel, $eventLabel,
        $generatedAt, $generatedBy, $logoPath
    ) {
        // Top orange branding line
        $pdf->fillRect($lm, 18, $usableW, 3.5, $cOrangeR, $cOrangeG, $cOrangeB);

        // Document Title & Subtitle
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($lm, 28, 12, 'PEPP LEARNING — TASK REMINDERS WORK REPORT', 700);

        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($lm, 42, 6.8, 'Staff Accountability & Task Performance Audit • Confidential Management Document', 400);

        // Logo on top right
        if ($logoPath && file_exists($logoPath)) {
            $pdf->image($logoPath, $rm - 55, 24, 55, 22);
        }

        // Applied Filter Metadata Banner
        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        $filterMetaText = sprintf('Period: %s  |  Admin: %s  |  Event: %s', $periodLabel, $adminLabel, $eventLabel);
        $pdf->text($lm, 51, 7.2, $filterMetaText, 600);

        // Right side generation info
        $genInfo = sprintf('Generated: %s by %s', $generatedAt, $generatedBy);
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($lm, 51, 6.5, $genInfo, 400, 'R', $usableW - ($logoPath ? 60 : 0));

        // Separator rule
        $pdf->line($lm, 62, $rm, 62, 0.7, 226 / 255, 232 / 255, 240 / 255);
    };

    // Helper: Draw Footer on Bottom of Page (Pass 2)
    $drawPageFooter = function(int $pageNum, int $totalPages) use (
        $pdf, $lm, $rm, $usableW, $cMutedR, $cMutedG, $cMutedB
    ) {
        $pdf->line($lm, 808, $rm, 808, 0.5, 226 / 255, 232 / 255, 240 / 255);
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($lm, 816, 7.0, 'PEPP Learning ERP • Task Reminders Management Report • Strictly Confidential', 400);
        $pageStr = sprintf('Page %d of %d', $pageNum, $totalPages);
        $pdf->text($lm, 816, 7.0, $pageStr, 600, 'R', $usableW);
    };

    // Current content position
    $currentY = 70.0;

    // Helper: Check Page Break and advance if needed
    $checkPageBreak = function(float $neededHeight) use (&$currentY, $pdf): bool {
        if ($currentY + $neededHeight > 792.0) {
            $pdf->addPage();
            $currentY = 70.0;
            return true;
        }
        return false;
    };

    // ── EMPTY STATE REPORT ───────────────────────────────────────────────
    if (($summary['total_tasks'] ?? 0) === 0) {
        $currentY += 40;
        // Empty State Box
        $boxW = 440.0;
        $boxX = $lm + ($usableW - $boxW) / 2;
        $pdf->fillRect($boxX, $currentY, $boxW, 110, $cBgLightR, $cBgLightG, $cBgLightB);
        $pdf->rect($boxX, $currentY, $boxW, 110, 0.8, $cBorderR, $cBorderG, $cBorderB);

        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($boxX, $currentY + 24, 11, 'No Task Records Found', 700, 'C', $boxW);

        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($boxX, $currentY + 44, 8, 'There are no tasks matching the selected filter criteria in this reporting period.', 400, 'C', $boxW);

        $filterSummary = sprintf('Period: %s   |   Admin: %s   |   Event: %s', $periodLabel, $adminLabel, $eventLabel);
        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        $pdf->text($boxX, $currentY + 68, 7.5, $filterSummary, 600, 'C', $boxW);

        // Finalize Page Header & Footer (1 Page)
        $totalPages = $pdf->getPageCount();
        for ($p = 0; $p < $totalPages; $p++) {
            $pdf->setPage($p);
            $drawPageHeader($p + 1);
            $drawPageFooter($p + 1, $totalPages);
        }
        return $pdf->output();
    }

    // ── SECTION 1: EXECUTIVE KPI SUMMARY CARDS ────────────────────────────
    $cardGap = 8.0;
    $numCards = 5;
    $cardW = ($usableW - ($numCards - 1) * $cardGap) / $numCards; // ~99.85 pt
    $cardH = 46.0;

    $checkPageBreak($cardH + 20);

    $cardsData = [
        [
            'label' => 'TOTAL WORKLOAD',
            'value' => (string)($summary['total_tasks'] ?? 0),
            'sub'   => sprintf('%d Working Days', (int)($summary['working_days'] ?? 0)),
            'val_rgb' => [$cDarkR, $cDarkG, $cDarkB],
            'bar_rgb' => [$cSlateR, $cSlateG, $cSlateB]
        ],
        [
            'label' => 'COMPLETED',
            'value' => (string)($summary['completed'] ?? 0),
            'sub'   => sprintf('%s%% of Workload', number_format((float)($summary['completion_rate'] ?? 0), 1)),
            'val_rgb' => [$cGreenR, $cGreenG, $cGreenB],
            'bar_rgb' => [$cGreenR, $cGreenG, $cGreenB]
        ],
        [
            'label' => 'PENDING / OPEN',
            'value' => (string)($summary['open_total'] ?? 0),
            'sub'   => sprintf('Overdue: %d', (int)($summary['overdue'] ?? 0)),
            'val_rgb' => [$cBlueR, $cBlueG, $cBlueB],
            'bar_rgb' => [$cBlueR, $cBlueG, $cBlueB]
        ],
        [
            'label' => 'POSTPONED TASKS',
            'value' => (string)($summary['postponed_tasks'] ?? 0),
            'sub'   => sprintf('%d Total Events', (int)($summary['postponement_events'] ?? 0)),
            'val_rgb' => [$cOrangeR, $cOrangeG, $cOrangeB],
            'bar_rgb' => [$cOrangeR, $cOrangeG, $cOrangeB]
        ],
        [
            'label' => 'COMPLETION RATE',
            'value' => sprintf('%s%%', number_format((float)($summary['completion_rate'] ?? 0), 1)),
            'sub'   => 'Completed / Workload',
            'val_rgb' => [$cPurpleR, $cPurpleG, $cPurpleB],
            'bar_rgb' => [$cPurpleR, $cPurpleG, $cPurpleB]
        ],
    ];

    for ($ci = 0; $ci < $numCards; $ci++) {
        $cx = $lm + $ci * ($cardW + $cardGap);
        $cd = $cardsData[$ci];

        // Card background & border
        $pdf->fillRect($cx, $currentY, $cardW, $cardH, $cBgLightR, $cBgLightG, $cBgLightB);
        $pdf->rect($cx, $currentY, $cardW, $cardH, 0.7, $cBorderR, $cBorderG, $cBorderB);

        // Accent top stripe
        $pdf->fillRect($cx, $currentY, $cardW, 2.5, $cd['bar_rgb'][0], $cd['bar_rgb'][1], $cd['bar_rgb'][2]);

        // Label
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($cx + 6, $currentY + 6, 6.2, $cd['label'], 700);

        // Value
        $pdf->setTextColor($cd['val_rgb'][0], $cd['val_rgb'][1], $cd['val_rgb'][2]);
        $pdf->text($cx + 6, $currentY + 16, 14, $cd['value'], 700);

        // Subtext
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($cx + 6, $currentY + 34, 6.0, $cd['sub'], 500);
    }

    $currentY += $cardH + 16.0;

    // ── SECTION 2: TASK TYPE ANALYTICS TABLE ─────────────────────────────
    if (!empty($taskTypes)) {
        $checkPageBreak(50);

        // Section Title
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($lm, $currentY, 9.5, 'TASK TYPE ANALYTICS', 700);
        $currentY += 13.0;

        // Table Header Setup
        $colTypeW   = 130.0;
        $colTotW    = 38.0;
        $colCompW   = 44.0;
        $colPendW   = 44.0;
        $colPostTW  = 65.0;
        $colPostEW  = 65.0;
        $colRateW   = 55.0;
        $colBarW    = 90.28; // Remaining to equal usableW (531.28)

        $rowH = 14.0;

        // Header Background
        $pdf->fillRect($lm, $currentY, $usableW, $rowH, $cBgMutedR, $cBgMutedG, $cBgMutedB);
        $pdf->rect($lm, $currentY, $usableW, $rowH, 0.6, $cBorderR, $cBorderG, $cBorderB);

        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        $pdf->text($lm + 4, $currentY + 3, 6.8, 'Task Type', 700);
        $pdf->text($lm + $colTypeW, $currentY + 3, 6.8, 'Total', 700, 'R', $colTotW);
        $pdf->text($lm + $colTypeW + $colTotW, $currentY + 3, 6.8, 'Completed', 700, 'R', $colCompW);
        $pdf->text($lm + $colTypeW + $colTotW + $colCompW, $currentY + 3, 6.8, 'Pending', 700, 'R', $colPendW);
        $pdf->text($lm + $colTypeW + $colTotW + $colCompW + $colPendW, $currentY + 3, 6.8, 'Postponed Tasks', 700, 'R', $colPostTW);
        $pdf->text($lm + $colTypeW + $colTotW + $colCompW + $colPendW + $colPostTW, $currentY + 3, 6.8, 'Postpone Events', 700, 'R', $colPostEW);
        $pdf->text($lm + $colTypeW + $colTotW + $colCompW + $colPendW + $colPostTW + $colPostEW, $currentY + 3, 6.8, 'Completion %', 700, 'R', $colRateW);
        $pdf->text($lm + $usableW - $colBarW + 6, $currentY + 3, 6.8, 'Performance', 700);

        $currentY += $rowH;

        // Table Rows
        $rowIndex = 0;
        foreach ($taskTypes as $tt) {
            $checkPageBreak($rowH);

            if ($rowIndex % 2 === 1) {
                $pdf->fillRect($lm, $currentY, $usableW, $rowH, $cBgLightR, $cBgLightG, $cBgLightB);
            }
            $pdf->rect($lm, $currentY, $usableW, $rowH, 0.4, $cBorderR, $cBorderG, $cBorderB);

            // Task Type Name
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($lm + 4, $currentY + 3.2, 7.0, $tt['name'] ?: 'General Task', 500);

            // Numbers
            $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
            $pdf->text($lm + $colTypeW, $currentY + 3.2, 7.0, (string)$tt['total'], 600, 'R', $colTotW);
            $pdf->setTextColor($cGreenR, $cGreenG, $cGreenB);
            $pdf->text($lm + $colTypeW + $colTotW, $currentY + 3.2, 7.0, (string)$tt['completed'], 600, 'R', $colCompW);
            $pdf->setTextColor($cBlueR, $cBlueG, $cBlueB);
            $pdf->text($lm + $colTypeW + $colTotW + $colCompW, $currentY + 3.2, 7.0, (string)$tt['pending'], 600, 'R', $colPendW);
            $pdf->setTextColor($cOrangeR, $cOrangeG, $cOrangeB);
            $pdf->text($lm + $colTypeW + $colTotW + $colCompW + $colPendW, $currentY + 3.2, 7.0, (string)$tt['postponed_tasks'], 600, 'R', $colPostTW);
            $pdf->text($lm + $colTypeW + $colTotW + $colCompW + $colPendW + $colPostTW, $currentY + 3.2, 7.0, (string)$tt['postpone_events'], 600, 'R', $colPostEW);

            // Completion Rate
            $rateStr = sprintf('%.1f%%', (float)$tt['completion_rate']);
            $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
            $pdf->text($lm + $colTypeW + $colTotW + $colCompW + $colPendW + $colPostTW + $colPostEW, $currentY + 3.2, 7.0, $rateStr, 600, 'R', $colRateW);

            // Vector Progress Bar
            $barTrackW = 75.0;
            $barH = 5.0;
            $barX = $lm + $usableW - $colBarW + 6;
            $barY = $currentY + 4.5;
            $rateFraction = max(0.0, min(1.0, (float)$tt['completion_rate'] / 100.0));
            $barFillW = max(1.0, $barTrackW * $rateFraction);

            $pdf->fillRect($barX, $barY, $barTrackW, $barH, 226 / 255, 232 / 255, 240 / 255);
            if ($rateFraction > 0.0) {
                $pdf->fillRect($barX, $barY, $barFillW, $barH, $cGreenR, $cGreenG, $cGreenB);
            }

            $currentY += $rowH;
            $rowIndex++;
        }

        $currentY += 16.0;
    }

    // ── SECTION 3: ADMIN PERFORMANCE BREAKDOWN (ALL ADMINS) ───────────────
    if (empty($scope['admin']) && count($admins) > 1) {
        $checkPageBreak(50);

        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($lm, $currentY, 9.5, 'ADMIN WORKLOAD & PERFORMANCE BREAKDOWN', 700);
        $currentY += 13.0;

        $colAdmNameW = 140.0;
        $colAdmTotW  = 45.0;
        $colAdmCompW = 55.0;
        $colAdmPendW = 55.0;
        $colAdmPostTW= 75.0;
        $colAdmPostEW= 75.0;
        $colAdmRateW = 86.28;

        $rowH = 14.0;

        // Header Background
        $pdf->fillRect($lm, $currentY, $usableW, $rowH, $cBgMutedR, $cBgMutedG, $cBgMutedB);
        $pdf->rect($lm, $currentY, $usableW, $rowH, 0.6, $cBorderR, $cBorderG, $cBorderB);

        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        $pdf->text($lm + 4, $currentY + 3, 6.8, 'Staff / Admin Member', 700);
        $pdf->text($lm + $colAdmNameW, $currentY + 3, 6.8, 'Total Tasks', 700, 'R', $colAdmTotW);
        $pdf->text($lm + $colAdmNameW + $colAdmTotW, $currentY + 3, 6.8, 'Completed', 700, 'R', $colAdmCompW);
        $pdf->text($lm + $colAdmNameW + $colAdmTotW + $colAdmCompW, $currentY + 3, 6.8, 'Pending / Open', 700, 'R', $colAdmPendW);
        $pdf->text($lm + $colAdmNameW + $colAdmTotW + $colAdmCompW + $colAdmPendW, $currentY + 3, 6.8, 'Postponed Tasks', 700, 'R', $colAdmPostTW);
        $pdf->text($lm + $colAdmNameW + $colAdmTotW + $colAdmCompW + $colAdmPendW + $colAdmPostTW, $currentY + 3, 6.8, 'Postpone Events', 700, 'R', $colAdmPostEW);
        $pdf->text($lm + $usableW - $colAdmRateW, $currentY + 3, 6.8, 'Completion %', 700, 'R', $colAdmRateW);

        $currentY += $rowH;

        $rowIndex = 0;
        foreach ($admins as $adm) {
            $checkPageBreak($rowH);

            if ($rowIndex % 2 === 1) {
                $pdf->fillRect($lm, $currentY, $usableW, $rowH, $cBgLightR, $cBgLightG, $cBgLightB);
            }
            $pdf->rect($lm, $currentY, $usableW, $rowH, 0.4, $cBorderR, $cBorderG, $cBorderB);

            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($lm + 4, $currentY + 3.2, 7.0, $adm['username'], 600);

            $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
            $pdf->text($lm + $colAdmNameW, $currentY + 3.2, 7.0, (string)$adm['total'], 600, 'R', $colAdmTotW);
            $pdf->setTextColor($cGreenR, $cGreenG, $cGreenB);
            $pdf->text($lm + $colAdmNameW + $colAdmTotW, $currentY + 3.2, 7.0, (string)$adm['completed'], 600, 'R', $colAdmCompW);
            $pdf->setTextColor($cBlueR, $cBlueG, $cBlueB);
            $pdf->text($lm + $colAdmNameW + $colAdmTotW + $colAdmCompW, $currentY + 3.2, 7.0, (string)$adm['pending'], 600, 'R', $colAdmPendW);
            $pdf->setTextColor($cOrangeR, $cOrangeG, $cOrangeB);
            $pdf->text($lm + $colAdmNameW + $colAdmTotW + $colAdmCompW + $colAdmPendW, $currentY + 3.2, 7.0, (string)$adm['postponed_tasks'], 600, 'R', $colAdmPostTW);
            $pdf->text($lm + $colAdmNameW + $colAdmTotW + $colAdmCompW + $colAdmPendW + $colAdmPostTW, $currentY + 3.2, 7.0, (string)$adm['postpone_events'], 600, 'R', $colAdmPostEW);

            $rateStr = sprintf('%.1f%%', (float)$adm['completion_rate']);
            $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
            $pdf->text($lm + $usableW - $colAdmRateW, $currentY + 3.2, 7.0, $rateStr, 700, 'R', $colAdmRateW);

            $currentY += $rowH;
            $rowIndex++;
        }

        $currentY += 16.0;
    }

    // ── SECTION 4 & 5: DAILY WORK LOG & DETAILED TASK RECORDS ────────────
    $checkPageBreak(40);
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($lm, $currentY, 9.5, 'DAILY WORK LOG & DETAILED TASK EXECUTION', 700);
    $currentY += 14.0;

    foreach ($days as $day) {
        $dayBannerH = 18.0;
        $checkPageBreak($dayBannerH + 55.0);

        // Daily Banner Header
        $pdf->fillRect($lm, $currentY, $usableW, $dayBannerH, $cSlateR, $cSlateG, $cSlateB);
        $pdf->rect($lm, $currentY, $usableW, $dayBannerH, 0.7, $cDarkR, $cDarkG, $cDarkB);

        // Left Date Label
        $pdf->setTextColor(1, 1, 1);
        $dayTitle = sprintf('%s (%s)', strtoupper($day['formatted_date']), strtoupper($day['day_name']));
        $pdf->text($lm + 8, $currentY + 4.5, 7.8, $dayTitle, 700);

        // Right Stats Pill
        $dayStats = sprintf(
            'Total: %d   Completed: %d   Pending: %d   Postponed Tasks: %d (%d Events)   Rate: %.1f%%',
            $day['total'], $day['completed'], $day['pending'], $day['postponed_tasks'], $day['postpone_events'], $day['completion_rate']
        );
        $pdf->setTextColor(241 / 255, 245 / 255, 249 / 255);
        $pdf->text($lm, $currentY + 4.8, 6.8, $dayStats, 500, 'R', $usableW - 8);

        $currentY += $dayBannerH + 8.0;

        // Render each Grouped Task Card for this day
        foreach ($day['groups'] as $grp) {
            $isConsolidated = ($grp['occurrences'] > 1);

            // Calculate estimated group height for safe page breaking
            $baseCardH = 26.0;
            if ($isConsolidated) {
                $subItemsH = count($grp['items']) * 14.0;
                $notesCount = count($grp['notes_list']) + count($grp['remarks_list']);
                $cardEstH = $baseCardH + $subItemsH + ($notesCount * 12.0) + 10.0;
            } else {
                $item = $grp['items'][0] ?? [];
                $notesLines = !empty($item['notes']) ? count(task_pdf_wrap_text($pdf, 'Details: ' . $item['notes'], $usableW - 20, 6.8)) : 0;
                $remarkLines = !empty($item['completion_remarks']) ? count(task_pdf_wrap_text($pdf, 'Remarks: ' . $item['completion_remarks'], $usableW - 20, 6.8)) : 0;
                $cardEstH = $baseCardH + (($notesLines + $remarkLines) * 9.0) + 12.0;
            }

            $checkPageBreak(min($cardEstH, 120.0));

            $cardStartY = $currentY;

            if ($isConsolidated) {
                // ── CONSOLIDATED REPETITIVE TASK CARD ──
                // Card Header Banner
                $pdf->fillRect($lm, $currentY, $usableW, 16.0, $cBgMutedR, $cBgMutedG, $cBgMutedB);
                $pdf->rect($lm, $currentY, $usableW, 16.0, 0.5, $cBorderR, $cBorderG, $cBorderB);

                // Title
                $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
                $pdf->text($lm + 6, $currentY + 3.5, 7.8, $grp['title'], 700);

                // Meta badges on right
                $postponeBadge = ($grp['postpone_events'] > 0) ? sprintf(' • Postponed: %d times', $grp['postpone_events']) : '';
                $groupMeta = sprintf(
                    '%s • Handled by: %s • %d Occurrences (%d Completed, %d Open%s)',
                    $grp['task_type_name'],
                    $grp['assigned_to_username'],
                    $grp['occurrences'],
                    $grp['completed'],
                    $grp['pending'],
                    $postponeBadge
                );
                $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
                $pdf->text($lm, $currentY + 4.0, 6.5, $groupMeta, 600, 'R', $usableW - 6);

                $currentY += 19.0;

                // Sub-items listing
                foreach ($grp['items'] as $sub) {
                    $checkPageBreak(16.0);

                    // Occurrence status indicator
                    $stBadge = strtoupper($sub['period_status']);
                    $stR = $cBlueR; $stG = $cBlueG; $stB = $cBlueB;
                    if ($sub['is_completed']) {
                        $stR = $cGreenR; $stG = $cGreenG; $stB = $cGreenB;
                    } elseif ($sub['period_status'] === 'overdue') {
                        $stR = $cRedR; $stG = $cRedG; $stB = $cRedB;
                    }

                    $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
                    $subTimeStr = sprintf('• %s — ', $sub['scheduled_time']);
                    $pdf->text($lm + 12, $currentY, 6.8, $subTimeStr, 600);
                    $timeW = $pdf->width($subTimeStr, 6.8, 600);

                    $pdf->setTextColor($stR, $stG, $stB);
                    $pdf->text($lm + 12 + $timeW, $currentY, 6.8, '[' . $stBadge . ']', 700);
                    $badgeW = $pdf->width('[' . $stBadge . '] ', 6.8, 700);

                    // Additional sub-item details (completer timestamp or postpone count)
                    $subExtra = '';
                    if ($sub['is_completed'] && !empty($sub['completed_at'])) {
                        $subExtra = sprintf('(Completed at %s)', $sub['completed_at']);
                    } elseif ($sub['postpone_events_count'] > 0) {
                        $subExtra = sprintf('(Postponed: %d times)', $sub['postpone_events_count']);
                    }
                    if ($subExtra !== '') {
                        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
                        $pdf->text($lm + 12 + $timeW + $badgeW, $currentY, 6.2, $subExtra, 500);
                    }

                    // Occurrence remarks if individual
                    $rText = $sub['completion_remarks'] ?: $sub['latest_remarks'];
                    if (!empty($rText)) {
                        $currentY += 8.5;
                        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
                        $remLines = task_pdf_wrap_text($pdf, 'Remarks: ' . $rText, $usableW - 30, 6.2);
                        foreach ($remLines as $rl) {
                            $pdf->text($lm + 24, $currentY, 6.2, $rl, 400);
                            $currentY += 7.5;
                        }
                    } else {
                        $currentY += 10.0;
                    }
                }

                // Consolidated Notes if any
                if (!empty($grp['notes_list'])) {
                    foreach ($grp['notes_list'] as $nl) {
                        $checkPageBreak(12.0);
                        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
                        $lines = task_pdf_wrap_text($pdf, 'Details: ' . $nl, $usableW - 20, 6.2);
                        foreach ($lines as $l) {
                            $pdf->text($lm + 12, $currentY, 6.2, $l, 400);
                            $currentY += 7.5;
                        }
                    }
                }

                $currentY += 4.0;
            } else {
                // ── SINGLE TASK CARD ──
                $item = $grp['items'][0] ?? [];

                // Status Badge Color
                $stBadge = strtoupper($item['period_status']);
                $stR = $cBlueR; $stG = $cBlueG; $stB = $cBlueB;
                if ($item['is_completed']) {
                    $stR = $cGreenR; $stG = $cGreenG; $stB = $cGreenB;
                } elseif ($item['period_status'] === 'overdue') {
                    $stR = $cRedR; $stG = $cRedG; $stB = $cRedB;
                }

                // Title & Type Header Line
                $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
                $pdf->text($lm + 6, $currentY + 2, 7.8, $item['title'], 700);

                // Type Badge
                $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
                $typeBadge = sprintf('[ %s ]', $item['task_type_name']);
                $titleW = $pdf->width($item['title'] . ' ', 7.8, 700);
                $pdf->text($lm + 6 + $titleW, $currentY + 2.5, 6.5, $typeBadge, 500);

                // Right Status & Time Badge
                $rightInfo = sprintf('Time: %s   [%s]', $item['scheduled_time'], $stBadge);
                $pdf->setTextColor($stR, $stG, $stB);
                $pdf->text($lm, $currentY + 2.0, 7.0, $rightInfo, 700, 'R', $usableW - 6);

                $currentY += 12.0;

                // Meta row: Assignee, Postpone count (count strictly, no detailed history)
                $metaParts = [];
                $metaParts[] = 'Assignee: ' . $item['assigned_to_username'];
                if ($item['is_completed'] && !empty($item['completed_at'])) {
                    $metaParts[] = sprintf('Completed: %s by %s', $item['completed_at'], $item['completed_by'] ?: $item['assigned_to_username']);
                }
                if ($item['postpone_events_count'] > 0) {
                    $metaParts[] = sprintf('Postponed: %d time%s', $item['postpone_events_count'], $item['postpone_events_count'] > 1 ? 's' : '');
                }

                $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
                $pdf->text($lm + 12, $currentY, 6.5, implode('   •   ', $metaParts), 500);
                $currentY += 9.0;

                // Notes / Description
                if (!empty($item['notes'])) {
                    $lines = task_pdf_wrap_text($pdf, 'Details: ' . $item['notes'], $usableW - 24, 6.5);
                    $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
                    foreach ($lines as $l) {
                        $checkPageBreak(8.0);
                        $pdf->text($lm + 12, $currentY, 6.5, $l, 400);
                        $currentY += 7.5;
                    }
                }

                // Completion Remarks
                $rText = $item['completion_remarks'] ?: $item['latest_remarks'];
                if (!empty($rText)) {
                    $lines = task_pdf_wrap_text($pdf, 'Remarks: ' . $rText, $usableW - 24, 6.5);
                    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
                    foreach ($lines as $l) {
                        $checkPageBreak(8.0);
                        $pdf->text($lm + 12, $currentY, 6.5, $l, 500);
                        $currentY += 7.5;
                    }
                }

                $currentY += 4.0;
            }

            // Draw bounding subtle card rect
            $cardActualH = $currentY - $cardStartY;
            $pdf->rect($lm, $cardStartY, $usableW, $cardActualH, 0.4, $cBorderR, $cBorderG, $cBorderB);
            $currentY += 6.0;
        }

        $currentY += 8.0;
    }

    // ── TWO-PASS RENDERING: PAGE HEADERS & FOOTERS ─────────────────────────
    $totalPages = $pdf->getPageCount();
    for ($p = 0; $p < $totalPages; $p++) {
        $pdf->setPage($p);
        $drawPageHeader($p + 1);
        $drawPageFooter($p + 1, $totalPages);
    }

    return $pdf->output();
}
