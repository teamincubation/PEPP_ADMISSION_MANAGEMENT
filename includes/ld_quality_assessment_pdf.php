<?php
/**
 * PEPP Learning ERP — Lecture Video Quality Assessment Report PDF Generator
 *
 * Generates an executive, print-ready vector PDF report (PDF 1.4) containing:
 * - Official Header & Meta Summary (Reference, Intern, Course, Date, Statistics, Financials)
 * - 1. Executive Summary
 * - 2. Overall Quality Classification
 * - 3. Aspect-wise Analysis (Content, Video, Audio, Slide)
 * - 4. Grade Distribution Breakdown
 * - 5. Faculty / Language / Chapter Pattern Synthesis
 * - 6. High-Priority Issues
 * - 7. Recommended Actions
 * - 8. Lectures Requiring Manual Reverification
 * - 9. AI Limitations & Data Source Disclosure Note
 * - 10. Admin Review & Verification Section
 */

declare(strict_types=1);

require_once __DIR__ . '/mentor_report_pdf.php';
require_once __DIR__ . '/ld_report_pdf.php';
require_once __DIR__ . '/ld_quality_assessment_helper.php';

function generate_ld_quality_assessment_pdf(PDO $pdo, int $reportId): string {
    // 1. Fetch Report
    $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        throw new RuntimeException("Assessment report not found.");
    }

    // 2. Fetch Items
    $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_items WHERE report_id = ? ORDER BY source_row_number ASC");
    $stmt->execute([$reportId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch latest AI Report
    $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_ai_reports WHERE report_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$reportId]);
    $aiReport = $stmt->fetch(PDO::FETCH_ASSOC);

    $aiData = [];
    if ($aiReport && !empty($aiReport['raw_structured_result'])) {
        $aiData = json_decode($aiReport['raw_structured_result'], true) ?: [];
    }

    // PDF Layout setup
    $pdf = new MentorReportPDFWriter('P', 'pt', 'A4');
    $L = 40.0;
    $R = 555.28;
    $W = $R - $L; // 515.28 pt

    // Colors
    $cDarkR   = 30 / 255;  $cDarkG   = 41 / 255;  $cDarkB   = 59 / 255;  // #1E293B
    $cMutedR  = 100 / 255; $cMutedG  = 116 / 255; $cMutedB  = 139 / 255; // #64748B
    $cLineR   = 226 / 255; $cLineG   = 232 / 255; $cLineB   = 240 / 255; // #E2E8F0
    $cBrandR  = 2 / 255;   $cBrandG  = 132 / 255; $cBrandB  = 199 / 255; // #0284C7
    $cGreenR  = 22 / 255;  $cGreenG  = 163 / 255; $cGreenB  = 74 / 255;  // #16A34A
    $cAmberR  = 217 / 255; $cAmberG  = 119 / 255; $cAmberB  = 6 / 255;   // #D97706
    $cRedR    = 220 / 255; $cRedG    = 38 / 255;  $cRedB    = 38 / 255;   // #DC2626
    $cBgRowR  = 248 / 255; $cBgRowG  = 250 / 255; $cBgRowB  = 252 / 255;

    $baseDir = dirname(__DIR__);
    $logo = $baseDir . '/pepp-logo.jpg';
    if (!file_exists($logo)) {
        $logo = $baseDir . '/logo_pepp.jpg';
    }

    $pdf->addPage();
    $y = 40.0;

    // Header: Logo / Branding
    if (file_exists($logo)) {
        $pdf->image($logo, $L, $y, 90, 40);
    } else {
        $pdf->setTextColor($cBrandR, $cBrandG, $cBrandB);
        $pdf->text($L, $y + 14, 16, 'PEPP Learning', 700);
    }

    $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
    $pdf->text($L, $y + 10, 8.5, 'Report Reference: ' . $report['report_reference'], 600, 'R', $W);
    $pdf->text($L, $y + 22, 8.0, 'Uploaded: ' . date('d M Y, h:i A', strtotime($report['created_at'])), 400, 'R', $W);

    $y += 50.0;
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($L, $y, 14, 'LECTURE VIDEO QUALITY ASSESSMENT REPORT', 700);
    $y += 6.0;
    $pdf->line($L, $y, $R, $y, 1.2, $cBrandR, $cBrandG, $cBrandB);
    $y += 14.0;

    // Meta Information Table
    $metaFields = [
        ['Intern Name', $report['admin_name'], 'Course Assessed', $report['course_name_snapshot']],
        ['Lectures Evaluated', (string)$report['row_count'], 'Total Lecture Duration', $report['total_lecture_duration_minutes'] . ' mins'],
        ['Total Assessment Time', $report['total_assessment_minutes'] . ' mins (' . number_format((float)$report['total_assessment_hours'], 2) . ' hrs)', 'Hourly Charge', '₹' . number_format((float)$report['hourly_rate_snapshot'], 2) . ' / Hour'],
        ['Total Intern Payout', '₹' . number_format((float)$report['calculated_charge'], 2), 'AI Evaluation Status', ucfirst($report['ai_status'])],
        ['Admin Review Status', $report['admin_review_status'], 'Admin Final Grade', $report['admin_final_grade'] ? ($report['admin_final_grade'] == 1 ? 'Good' : ($report['admin_final_grade'] == 2 ? 'Okay' : 'To be improved')) : 'Pending Decision']
    ];

    $boxH = count($metaFields) * 16.0 + 8.0;
    $pdf->fillRect($L, $y, $W, $boxH, 248/255, 250/255, 252/255);
    $pdf->rect($L, $y, $W, $boxH, 0.6, $cLineR, $cLineG, $cLineB);

    $curY = $y + 12.0;
    foreach ($metaFields as $row) {
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($L + 10, $curY, 8.0, $row[0] . ':', 600);
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($L + 115, $curY, 8.5, $row[1], 700);

        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($L + 270, $curY, 8.0, $row[2] . ':', 600);
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($L + 380, $curY, 8.5, $row[3], 700);

        $curY += 16.0;
    }

    $y += $boxH + 16.0;

    // Helper: Section Banner
    $drawSectionBanner = function(string $title, string $tag = '') use ($pdf, $L, $R, $W, &$y, $cDarkR, $cDarkG, $cDarkB, $cBrandR, $cBrandG, $cBrandB, $cMutedR, $cMutedG, $cMutedB) {
        if ($y > 730) {
            $pdf->addPage();
            $y = 40.0;
        }
        $pdf->setTextColor($cBrandR, $cBrandG, $cBrandB);
        $pdf->text($L, $y + 10, 11, $title, 700);
        if ($tag !== '') {
            $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
            $pdf->text($L, $y + 10, 8, '[' . $tag . ']', 600, 'R', $W);
        }
        $y += 14.0;
        $pdf->line($L, $y, $R, $y, 0.5, 203/255, 213/255, 225/255);
        $y += 10.0;
    };

    // 1. Executive Summary & Overall Classification
    $drawSectionBanner('1. EXECUTIVE SUMMARY & QUALITY CLASSIFICATION', 'AI-Synthesized');

    $overallGrade = (int)($report['ai_overall_grade'] ?: ($aiData['overall_grade'] ?? 1));
    $gradeLabel = $overallGrade === 1 ? 'Grade 1: GOOD' : ($overallGrade === 2 ? 'Grade 2: OKAY' : 'Grade 3: TO BE IMPROVED');
    $badgeR = $overallGrade === 1 ? $cGreenR : ($overallGrade === 2 ? $cAmberR : $cRedR);
    $badgeG = $overallGrade === 1 ? $cGreenG : ($overallGrade === 2 ? $cAmberG : $cRedG);
    $badgeB = $overallGrade === 1 ? $cGreenB : ($overallGrade === 2 ? $cAmberB : $cRedB);

    $pdf->fillRect($L, $y, 140, 20, $badgeR, $badgeG, $badgeB);
    $pdf->setTextColor(1, 1, 1);
    $pdf->text($L + 12, $y + 14, 9, $gradeLabel, 700);

    $summaryText = $report['ai_summary'] ?: ($aiData['executive_summary'] ?? 'Quality evaluation completed successfully.');
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $wrappedLines = ld_pdf_wrap_text($pdf, $summaryText, $W - 155, 8.5, 400);
    $sumY = $y + 8.0;
    foreach ($wrappedLines as $line) {
        $pdf->text($L + 155, $sumY, 8.5, $line, 400);
        $sumY += 12.0;
    }
    $y = max($y + 26.0, $sumY + 6.0);

    // 2. Aspect-Wise Quality Breakdown
    $drawSectionBanner('2. ASPECT-WISE ANALYSIS & DETERMINISTIC GRADE DISTRIBUTION', 'Deterministic + AI');

    $aspectsInfo = [
        ['Content Quality', 'content', 'Clarity, syllabus alignment & academic depth'],
        ['Video Quality', 'video', 'Camera stability, lighting, clarity & resolution'],
        ['Audio Quality', 'audio', 'Microphone clarity, volume balance & noise reduction'],
        ['Slide Quality', 'slide', 'Typography, contrast, slide formatting & PEPP branding']
    ];

    // Compute distribution counts
    $aspectCounts = [
        'content' => [1 => 0, 2 => 0, 3 => 0],
        'video'   => [1 => 0, 2 => 0, 3 => 0],
        'audio'   => [1 => 0, 2 => 0, 3 => 0],
        'slide'   => [1 => 0, 2 => 0, 3 => 0]
    ];
    foreach ($items as $it) {
        $cg = (int)$it['content_grade']; $vg = (int)$it['video_grade'];
        $ag = (int)$it['audio_grade'];   $sg = (int)$it['slide_grade'];
        if (isset($aspectCounts['content'][$cg])) $aspectCounts['content'][$cg]++;
        if (isset($aspectCounts['video'][$vg])) $aspectCounts['video'][$vg]++;
        if (isset($aspectCounts['audio'][$ag])) $aspectCounts['audio'][$ag]++;
        if (isset($aspectCounts['slide'][$sg])) $aspectCounts['slide'][$sg]++;
    }

    $cardW = ($W - 18.0) / 4.0;
    $cardH = 68.0;

    foreach ($aspectsInfo as $idx => [$title, $key, $desc]) {
        $cX = $L + $idx * ($cardW + 6.0);
        $pdf->fillRect($cX, $y, $cardW, $cardH, 248/255, 250/255, 252/255);
        $pdf->rect($cX, $y, $cardW, $cardH, 0.6, $cLineR, $cLineG, $cLineB);

        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($cX + 8, $y + 12, 8.5, $title, 700);

        $pdf->setTextColor($cGreenR, $cGreenG, $cGreenB);
        $pdf->text($cX + 8, $y + 28, 8.0, 'Grade 1 (Good): ' . $aspectCounts[$key][1], 600);

        $pdf->setTextColor($cAmberR, $cAmberG, $cAmberB);
        $pdf->text($cX + 8, $y + 40, 8.0, 'Grade 2 (Okay): ' . $aspectCounts[$key][2], 600);

        $pdf->setTextColor($cRedR, $cRedG, $cRedB);
        $pdf->text($cX + 8, $y + 52, 8.0, 'Grade 3 (Defect): ' . $aspectCounts[$key][3], 600);
    }
    $y += $cardH + 16.0;

    // 3. High-Priority Issues Identified
    $drawSectionBanner('3. HIGH-PRIORITY QUALITY ISSUES & DEFECTS', 'Action Required');

    $priorityList = [];
    foreach ($items as $it) {
        $rN = $it['source_row_number'];
        $title = $it['lecture_title'];
        if ((int)$it['content_grade'] === 3) $priorityList[] = ['row' => $rN, 'title' => $title, 'aspect' => 'Content', 'grade' => 3, 'remark' => $it['content_remark']];
        if ((int)$it['video_grade'] === 3)   $priorityList[] = ['row' => $rN, 'title' => $title, 'aspect' => 'Video',   'grade' => 3, 'remark' => $it['video_remark']];
        if ((int)$it['audio_grade'] === 3)   $priorityList[] = ['row' => $rN, 'title' => $title, 'aspect' => 'Audio',   'grade' => 3, 'remark' => $it['audio_remark']];
        if ((int)$it['slide_grade'] === 3)   $priorityList[] = ['row' => $rN, 'title' => $title, 'aspect' => 'Slide',   'grade' => 3, 'remark' => $it['slide_remark']];
    }

    if (empty($priorityList)) {
        $pdf->setTextColor($cGreenR, $cGreenG, $cGreenB);
        $pdf->text($L + 10, $y + 10, 8.5, '✓ Zero Grade 3 critical defects identified across evaluated lectures.', 600);
        $y += 20.0;
    } else {
        foreach (array_slice($priorityList, 0, 5) as $pi) {
            $pdf->setTextColor($cRedR, $cRedG, $cRedB);
            $pdf->text($L + 6, $y + 10, 8.0, '● Row ' . $pi['row'] . ' [' . $pi['aspect'] . ' Quality]: ' . $pi['title'], 700);
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($L + 24, $y + 20, 7.5, 'Remark: ' . ($pi['remark'] ?: 'Defect flagged without specific note'), 400);
            $y += 24.0;
        }
    }

    // 4. Recommendations & Reverification Candidates
    $drawSectionBanner('4. AI RECOMMENDATIONS & MANUAL RE-VERIFICATION CANDIDATES', 'Action Plan');

    $recomms = $aiData['recommendations']['immediate_actions'] ?? ['Manual administrator review of flagged remarks.', 'Verify video and slide adjustments before course publishing.'];
    foreach (array_slice($recomms, 0, 3) as $rec) {
        $pdf->setTextColor($cBrandR, $cBrandG, $cBrandB);
        $pdf->text($L + 6, $y + 8, 8.0, '→ ' . $rec, 600);
        $y += 14.0;
    }

    $y += 6.0;

    // 5. Data Disclosure & Limitations
    $drawSectionBanner('5. AI LIMITATIONS & TRUTH-IN-REPORTING DISCLOSURE', 'Compliance');
    $disclosure = "LIMITATION DISCLOSURE: This evaluation is generated by evaluating the structured assessment records submitted by the intern, along with deterministic metrics and statistics computed by the PEPP ERP engine. The AI did not independently watch the raw video files. Administrative review remains mandatory prior to publishing or payment lock.";
    $discLines = ld_pdf_wrap_text($pdf, $disclosure, $W - 20, 7.5, 400);
    foreach ($discLines as $dl) {
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($L + 10, $y + 6, 7.5, $dl, 400);
        $y += 10.0;
    }

    $y += 16.0;

    // 6. Admin Review Section
    $drawSectionBanner('6. ADMINISTRATIVE VERIFICATION & DECISION', 'Official Sign-Off');

    $reviewText = "Review Status: " . $report['admin_review_status'] .
        ($report['admin_final_grade'] ? "  |  Final Grade: " . ($report['admin_final_grade'] == 1 ? 'Good' : ($report['admin_final_grade'] == 2 ? 'Okay' : 'To be improved')) : '') .
        ($report['reviewed_by'] ? "  |  Reviewed By: " . $report['reviewed_by'] . " on " . date('d M Y, h:i A', strtotime($report['reviewed_at'])) : '  |  Awaiting Admin Verification');

    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($L + 10, $y + 10, 8.5, $reviewText, 700);

    if (!empty($report['admin_review_notes'])) {
        $y += 16.0;
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($L + 10, $y + 6, 8.0, 'Review Notes: ' . $report['admin_review_notes'], 400);
    }

    return $pdf->output();
}
