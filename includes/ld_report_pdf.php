<?php
/**
 * PEPP Learning ERP — L&D Operations Work Report PDF Generator
 *
 * Multi-page vector PDF generator (PDF 1.4) supporting:
 * - Accurate quantity breakdown aggregated strictly by unit (never mixing units)
 * - Safe unit normalization preserving custom/unknown units
 * - Mask Charge mode: complete removal of charge column, rates, and financial amounts
 * - Multi-page pagination, repeating headers, and two-pass page numbering
 */

declare(strict_types=1);

require_once __DIR__ . '/mentor_report_pdf.php';

/**
 * Normalizes a unit string safely.
 * Normalizes only known aliases/case variations.
 * Preserves custom/unknown units as-is.
 * Only null/empty string falls back to 'Units'.
 */
function ld_normalize_unit(?string $unit): string {
    $u = trim((string)$unit);
    if ($u === '') {
        return 'Units';
    }
    $lower = strtolower($u);
    $known_map = [
        'question'  => 'Questions',
        'questions' => 'Questions',
        'page'      => 'Pages',
        'pages'     => 'Pages',
        'material'  => 'Materials',
        'materials' => 'Materials',
        'cover'     => 'Covers',
        'covers'    => 'Covers',
        'file'      => 'Files',
        'files'     => 'Files',
        'unit'      => 'Units',
        'units'     => 'Units',
        'hour'      => 'Hours',
        'hours'     => 'Hours',
        'hr'        => 'Hours',
        'hrs'       => 'Hours',
    ];
    if (isset($known_map[$lower])) {
        return $known_map[$lower];
    }
    // CRITICAL SAFEGUARD: Preserve custom/unknown legitimate units
    if (ctype_lower($u)) {
        return ucfirst($u);
    }
    return $u;
}

/**
 * Formats a numeric quantity cleanly: avoids unnecessary trailing zeros while keeping precision.
 */
function ld_format_quantity(float $qty): string {
    if (floor($qty) == $qty) {
        return number_format($qty, 0);
    }
    $formatted = number_format($qty, 2);
    return rtrim(rtrim($formatted, '0'), '.');
}

/**
 * Formats an associative map of [unit => sum_qty] into a human-readable string.
 * Multiple units are presented separately and NEVER summed into a single number.
 *
 * @param array<string, float> $quantities_by_unit
 * @param bool $compact If true, joins with comma; if false, multiline
 */
function ld_format_quantities_by_unit(array $quantities_by_unit, bool $compact = false): string {
    if (empty($quantities_by_unit)) {
        return '-';
    }
    $parts = [];
    foreach ($quantities_by_unit as $unit => $qty) {
        $parts[] = ld_format_quantity((float)$qty) . ' ' . $unit;
    }
    return implode($compact ? ', ' : "\n", $parts);
}

/**
 * Wraps text into lines fitting within maxWidth points.
 */
function ld_pdf_wrap_text(MentorReportPDFWriter $pdf, string $text, float $maxWidth, float $fontSize, $weight = 400): array {
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
                $currentLine = $word;
            } else {
                // Single very long word
                $lines[] = $word;
                $currentLine = '';
            }
        }
    }
    if ($currentLine !== '') {
        $lines[] = $currentLine;
    }
    return $lines;
}

/**
 * Main report renderer for L&D Operations Work Report PDF.
 *
 * @param array $data Structured data array
 * @param bool $mask_charge When true, eliminates all charge/financial data and rebalances layout
 * @return string Raw PDF bytes
 */
function render_ld_work_report_pdf(array $data, bool $mask_charge = false): string {
    $pdf = new MentorReportPDFWriter();

    $tasks               = $data['tasks'] ?? [];
    $total_tasks         = (int)($data['total_tasks'] ?? count($tasks));
    $total_topics        = (int)($data['total_topics'] ?? 0);
    $active_days         = (int)($data['active_days'] ?? 0);
    $total_charge_sum    = (float)($data['total_charge_sum'] ?? 0.0);
    $course_breakdown    = $data['course_breakdown'] ?? [];
    $mode_breakdown      = $data['mode_breakdown'] ?? [];
    $report_quantities   = $data['report_quantities'] ?? [];
    $generated_at        = $data['generated_at'] ?? date('d-m-Y h:i A');

    // Page Geometry
    $L = 40.0;
    $R = MentorReportPDFWriter::W - 40.0; // 555.28 pt
    $W = $R - $L;                         // 515.28 pt

    // Colors
    $cDarkR   = 15 / 255;  $cDarkG   = 23 / 255;  $cDarkB   = 42 / 255;
    $cSlateR  = 51 / 255;  $cSlateG  = 65 / 255;  $cSlateB  = 85 / 255;
    $cMutedR  = 100 / 255; $cMutedG  = 116 / 255; $cMutedB  = 139 / 255;
    $cBorderR = 226 / 255; $cBorderG = 232 / 255; $cBorderB = 240 / 255;
    $cLineR   = 203 / 255; $cLineG   = 213 / 255; $cLineB   = 225 / 255;
    $cBgRowR  = 248 / 255; $cBgRowG  = 250 / 255; $cBgRowB  = 252 / 255;

    // Logo resolution
    $baseDir = dirname(__DIR__);
    $logo = $baseDir . '/pepp-logo.jpg';
    if (!file_exists($logo)) {
        $logo = $baseDir . '/logo_pepp.jpg';
    }

    // Column Specifications
    if (!$mask_charge) {
        // Normal PDF: 7 columns (optimized for up to 6-digit quantities and large charges)
        $colDateW    = 54.0;
        $colStaffW   = 66.0;
        $colCourseW  = 58.0;
        $colModeW    = 68.0;
        $colDetailsW = 140.0;
        $colQtyW     = 68.0;
        $colChargeW  = 61.28;

        $colDateX    = $L;
        $colStaffX   = $colDateX + $colDateW;
        $colCourseX  = $colStaffX + $colStaffW;
        $colModeX    = $colCourseX + $colCourseW;
        $colDetailsX = $colModeX + $colModeW;
        $colQtyX     = $colDetailsX + $colDetailsW;
        $colChargeX  = $colQtyX + $colQtyW;
    } else {
        // Masked PDF: 6 columns (Charge column completely removed, columns rebalanced)
        $colDateW    = 60.0;
        $colStaffW   = 75.0;
        $colCourseW  = 70.0;
        $colModeW    = 85.0;
        $colDetailsW = 155.28;
        $colQtyW     = 70.0;
        $colChargeW  = 0.0;

        $colDateX    = $L;
        $colStaffX   = $colDateX + $colDateW;
        $colCourseX  = $colStaffX + $colStaffW;
        $colModeX    = $colCourseX + $colCourseW;
        $colDetailsX = $colModeX + $colModeW;
        $colQtyX     = $colDetailsX + $colDetailsW;
        $colChargeX  = 0.0;
    }

    // Helper: Draw Main Document Header (Page 1)
    $drawMainHeader = function() use ($pdf, $logo, $L, $R, $W, $generated_at, $cDarkR, $cDarkG, $cDarkB, $cMutedR, $cMutedG, $cMutedB, $cLineR, $cLineG, $cLineB) {
        if (file_exists($logo)) {
            $pdf->image($logo, $L, 38, 92, 42);
        } else {
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($L, 42, 16, 'PEPP Learning', 700);
        }

        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($L, 44, 8.5, 'L&D Operations Work Report', 600, 'R', $W);
        $pdf->text($L, 56, 8.0, 'Generated: ' . $generated_at, 400, 'R', $W);

        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($L, 95, 13.5, 'L&D OPERATIONS WORK REPORT', 700, 'C', $W);

        $pdf->line($L, 114, $R, 114, 0.8, $cLineR, $cLineG, $cLineB);
    };

    // Helper: Draw Continuation Header (Page 2+)
    $drawContinuationHeader = function(int $pageNum) use ($pdf, $logo, $L, $R, $W, $generated_at, $cDarkR, $cDarkG, $cDarkB, $cMutedR, $cMutedG, $cMutedB, $cLineR, $cLineG, $cLineB) {
        if (file_exists($logo)) {
            $pdf->image($logo, $L, 25, 60, 27);
        } else {
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($L, 28, 12, 'PEPP Learning', 700);
        }

        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($L + 70, 32, 10.5, 'L&D OPERATIONS WORK REPORT — ACTIVITY LOGS (Cont.)', 700);

        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($L, 32, 7.5, 'Generated: ' . $generated_at, 400, 'R', $W);

        $pdf->line($L, 58, $R, 58, 0.8, $cLineR, $cLineG, $cLineB);
    };

    // Helper: Draw Table Header
    $drawTableHeaders = function(float $headerY) use (
        $pdf, $L, $R, $mask_charge, $cDarkR, $cDarkG, $cDarkB, $cLineR, $cLineG, $cLineB,
        $colDateX, $colDateW, $colStaffX, $colStaffW, $colCourseX, $colCourseW,
        $colModeX, $colModeW, $colDetailsX, $colDetailsW, $colQtyX, $colQtyW,
        $colChargeX, $colChargeW
    ) {
        $pdf->line($L, $headerY, $R, $headerY, 0.6, $cLineR, $cLineG, $cLineB);
        $headerY += 4.0;

        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $fontSize = 8.0;

        $pdf->text($colDateX + 2, $headerY, $fontSize, 'Date/Time', 700);
        $pdf->text($colStaffX + 2, $headerY, $fontSize, 'Staff', 700);
        $pdf->text($colCourseX + 2, $headerY, $fontSize, 'Course', 700);
        $pdf->text($colModeX + 2, $headerY, $fontSize, 'Mode', 700);
        $pdf->text($colDetailsX + 2, $headerY, $fontSize, 'Work Details', 700);

        if (!$mask_charge) {
            $pdf->text($colQtyX + 2, $headerY, $fontSize, 'Quantity', 700);
            $pdf->text($colChargeX, $headerY, $fontSize, 'Charge (INR)', 700, 'R', $colChargeW - 2);
        } else {
            // Masked: Quantity on the far right
            $pdf->text($colQtyX, $headerY, $fontSize, 'Quantity', 700, 'R', $colQtyW - 2);
        }

        $headerY += 10.0;
        $pdf->line($L, $headerY, $R, $headerY, 0.6, $cLineR, $cLineG, $cLineB);
        return $headerY + 4.0;
    };

    // Helper: Draw Page Footer
    $drawPageFooter = function(int $pageIndex) use ($pdf, $L, $R, $W, $cMutedR, $cMutedG, $cMutedB, $cLineR, $cLineG, $cLineB) {
        $pdf->setPage($pageIndex);
        $pdf->line($L, 810, $R, 810, 0.5, $cLineR, $cLineG, $cLineB);
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($L, 816, 7.5, 'PEPP Learning Operations · office@pepplearning.com · Confidential Report', 400);
    };

    // ── PAGE 1: RENDER MAIN HEADER & SUMMARIES ──
    $drawMainHeader();
    $y = 124.0;

    // Summary Metrics Section
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($L, $y, 9.5, 'Summary Metrics', 700);
    $y += 14.0;

    $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
    $pdf->text($L, $y, 8.5, 'Total Task Logs: ' . $total_tasks, 500);
    $pdf->text($L + 115, $y, 8.5, 'Total Topics: ' . $total_topics, 500);
    $pdf->text($L + 215, $y, 8.5, 'Active Days: ' . $active_days, 500);

    if (!$mask_charge) {
        $chargeStr = 'Total Charge: INR ' . number_format($total_charge_sum, 2);
        $pdf->text($L + 310, $y, 8.5, $chargeStr, 700);
    }
    $y += 12.0;

    $avgTopics = $active_days > 0 ? number_format($total_topics / $active_days, 1) : '0';
    $pdf->text($L, $y, 8.0, 'Avg Topics/Active Day: ' . $avgTopics, 400);

    // Display Total Quantity breakdown across all tasks if present
    if (!empty($report_quantities)) {
        $qtyBreakdownStr = 'Total Quantity: ' . ld_format_quantities_by_unit($report_quantities, true);
        $pdf->text($L + 150, $y, 8.0, $qtyBreakdownStr, 600);
    }
    $y += 14.0;
    $pdf->line($L, $y, $R, $y, 0.6, $cLineR, $cLineG, $cLineB);
    $y += 12.0;

    // Course Breakdown Section
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($L, $y, 9.5, 'Course Breakdown (Topics completed)', 700);
    $y += 12.0;
    $pdf->line($L, $y, $R, $y, 0.4, $cLineR, $cLineG, $cLineB);
    $y += 6.0;

    foreach ($course_breakdown as $cName => $cInfo) {
        $cnt = is_array($cInfo) ? ($cInfo['cnt'] ?? 0) : (int)$cInfo;
        $cQtyMap = is_array($cInfo) ? ($cInfo['quantities'] ?? []) : [];
        $qtySub = !empty($cQtyMap) ? ' (' . ld_format_quantities_by_unit($cQtyMap, true) . ')' : '';

        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        $pdf->text($L, $y, 8.0, (string)$cName, 400);
        $pdf->text($L, $y, 8.0, $cnt . ' Topics' . $qtySub, 500, 'R', $W);
        $y += 11.0;
    }
    $y += 4.0;
    $pdf->line($L, $y, $R, $y, 0.6, $cLineR, $cLineG, $cLineB);
    $y += 12.0;

    // Work Mode Breakdown Section
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($L, $y, 9.5, 'Work Mode Breakdown (Topics completed)', 700);
    $y += 12.0;
    $pdf->line($L, $y, $R, $y, 0.4, $cLineR, $cLineG, $cLineB);
    $y += 6.0;

    foreach ($mode_breakdown as $mName => $mInfo) {
        $cnt = is_array($mInfo) ? ($mInfo['cnt'] ?? 0) : (int)$mInfo;
        $mQtyMap = is_array($mInfo) ? ($mInfo['quantities'] ?? []) : [];
        $qtySub = !empty($mQtyMap) ? ' (' . ld_format_quantities_by_unit($mQtyMap, true) . ')' : '';

        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        $pdf->text($L, $y, 8.0, (string)$mName, 400);
        $pdf->text($L, $y, 8.0, $cnt . ' Topics' . $qtySub, 500, 'R', $W);
        $y += 11.0;
    }
    $y += 4.0;
    $pdf->line($L, $y, $R, $y, 0.6, $cLineR, $cLineG, $cLineB);
    $y += 16.0;

    // ── RECENT ACTIVITY LOGS TABLE ──
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($L, $y, 9.5, 'Recent Activity Logs', 700);
    $y += 12.0;

    $y = $drawTableHeaders($y);

    $rowIndex = 0;
    foreach ($tasks as $tk) {
        $unitLabel = ld_normalize_unit($tk['quantity_label_snapshot'] ?? null);
        $topics = $tk['topics'] ?? [];

        // Aggregate quantities and calculate charges for this task
        $taskCharge = 0.00;
        $hasIncomplete = false;
        $taskQtyByUnit = [];
        $topicLines = [];

        foreach ($topics as $tp) {
            $tName = trim((string)($tp['topic_name'] ?? 'Topic'));
            $q = $tp['quantity'] !== null ? (float)$tp['quantity'] : null;
            $c = (float)($tp['calculated_charge'] ?? 0.0);
            $taskCharge += $c;

            if ($q !== null) {
                $taskQtyByUnit[$unitLabel] = ($taskQtyByUnit[$unitLabel] ?? 0.0) + $q;
                $qFormatted = ld_format_quantity($q) . ' ' . $unitLabel;

                if (!$mask_charge) {
                    $rateVal = $tk['charge_per_quantity_snapshot'] !== null
                        ? 'INR ' . number_format((float)$tk['charge_per_quantity_snapshot'], 2)
                        : '';
                    $rateSnippet = $rateVal !== '' ? " @ $rateVal" : '';
                    $topicLines[] = sprintf('• %s (%s%s)', $tName, $qFormatted, $rateSnippet);
                } else {
                    // MASK CHARGE: completely omit rate, currency, and charges
                    $topicLines[] = sprintf('• %s — %s', $tName, $qFormatted);
                }
            } else {
                $hasIncomplete = true;
                $topicLines[] = sprintf('• %s (Quantity not added)', $tName);
            }
        }

        // Wrap columns
        $dateStr = date('d-m-Y', strtotime($tk['created_at']));
        $timeStr = date('h:i A', strtotime($tk['created_at']));
        $staffLines = ld_pdf_wrap_text($pdf, (string)$tk['admin_name'], $colStaffW - 4, 7.5, 500);
        $courseLines = ld_pdf_wrap_text($pdf, (string)$tk['course_name'], $colCourseW - 4, 7.5, 400);
        $modeName = !empty($tk['mode_name_snapshot']) ? $tk['mode_name_snapshot'] : ($tk['mode_name'] ?? '');
        $modeLines = ld_pdf_wrap_text($pdf, (string)$modeName, $colModeW - 4, 7.5, 400);

        // Wrap work details
        $wrappedDetailLines = [];
        foreach ($topicLines as $tLine) {
            $wLines = ld_pdf_wrap_text($pdf, $tLine, $colDetailsW - 6, 7.2, 400);
            foreach ($wLines as $wl) {
                $wrappedDetailLines[] = $wl;
            }
        }
        if (empty($wrappedDetailLines)) {
            $wrappedDetailLines = ['-'];
        }

        // Format task total quantity
        $qtyDisplay = ld_format_quantities_by_unit($taskQtyByUnit, false);
        $qtyLines = explode("\n", $qtyDisplay);

        // Compute row height
        $numLines = max(
            2, // Date/time
            count($staffLines),
            count($courseLines),
            count($modeLines),
            count($wrappedDetailLines),
            count($qtyLines)
        );
        $rowH = ($numLines * 9.0) + 6.0;

        // Check page overflow
        if ($y + $rowH > 790.0) {
            // Close table on current page
            $pdf->line($L, $y, $R, $y, 0.4, $cLineR, $cLineG, $cLineB);

            // Add new page
            $pdf->addPage();
            $drawContinuationHeader($pdf->getPageCount());
            $y = 66.0;
            $y = $drawTableHeaders($y);
        }

        // Alternate subtle row background
        if ($rowIndex % 2 === 1) {
            $pdf->fillRect($L, $y - 2, $W, $rowH, $cBgRowR, $cBgRowG, $cBgRowB);
        }

        // Print Date/Time
        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        $pdf->text($colDateX + 2, $y + 1, 7.2, $dateStr, 500);
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($colDateX + 2, $y + 10, 6.8, $timeStr, 400);

        // Print Staff
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $currY = $y + 1;
        foreach ($staffLines as $sl) {
            $pdf->text($colStaffX + 2, $currY, 7.2, $sl, 500);
            $currY += 8.5;
        }

        // Print Course
        $pdf->setTextColor($cSlateR, $cSlateG, $cSlateB);
        $currY = $y + 1;
        foreach ($courseLines as $cl) {
            $pdf->text($colCourseX + 2, $currY, 7.2, $cl, 400);
            $currY += 8.5;
        }

        // Print Mode
        $currY = $y + 1;
        foreach ($modeLines as $ml) {
            $pdf->text($colModeX + 2, $currY, 7.2, $ml, 400);
            $currY += 8.5;
        }

        // Print Work Details
        $currY = $y + 1;
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        foreach ($wrappedDetailLines as $wdl) {
            $pdf->text($colDetailsX + 2, $currY, 7.0, $wdl, 400);
            $currY += 8.5;
        }

        // Print Quantity
        $currY = $y + 1;
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        if (!$mask_charge) {
            foreach ($qtyLines as $ql) {
                $pdf->text($colQtyX + 2, $currY, 7.2, $ql, 600);
                $currY += 8.5;
            }
        } else {
            // Masked PDF: Quantity aligned right
            foreach ($qtyLines as $ql) {
                $pdf->text($colQtyX, $currY, 7.2, $ql, 600, 'R', $colQtyW - 2);
                $currY += 8.5;
            }
        }

        // Print Charge (ONLY when NOT masked)
        if (!$mask_charge) {
            $chargeDisplay = $hasIncomplete ? 'Incomplete' : 'INR ' . number_format($taskCharge, 2);
            $pdf->setTextColor($hasIncomplete ? 220/255 : 22/255, $hasIncomplete ? 38/255 : 101/255, $hasIncomplete ? 38/255 : 52/255);
            $pdf->text($colChargeX, $y + 1, 7.5, $chargeDisplay, 600, 'R', $colChargeW - 2);
        }

        $y += $rowH;
        $pdf->line($L, $y - 1, $R, $y - 1, 0.3, $cBorderR, $cBorderG, $cBorderB);
        $rowIndex++;
    }

    // Closing table line
    $pdf->line($L, $y, $R, $y, 0.7, $cLineR, $cLineG, $cLineB);

    // ── TWO-PASS FOOTER & PAGE NUMBERING ──
    $totalPages = $pdf->getPageCount();
    for ($i = 0; $i < $totalPages; $i++) {
        $drawPageFooter($i);
        $pdf->setPage($i);
        $pageStr = sprintf('Page %d of %d', $i + 1, $totalPages);
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($L, 816, 7.5, $pageStr, 600, 'R', $W);
    }

    return $pdf->output();
}
