<?php
/**
 * PEPP Learning — Appointment Letter PDF generator.
 * Reads from an immutable appointment snapshot JSON (never live employee data).
 * Uses MiniPDF from pdf_invoice.php (dependency-free PDF 1.4 writer).
 */
require_once __DIR__ . '/pdf_invoice.php';

/**
 * Render an appointment letter PDF from the frozen snapshot.
 *
 * @param  string $snapshot_json  The immutable JSON stored at approval time
 * @return string                 Raw PDF bytes
 */
function render_appointment_pdf(string $snapshot_json): string {
    $d = json_decode($snapshot_json, true);
    if (!$d) throw new RuntimeException('Invalid appointment snapshot');

    $pdf = new MiniPDF('P', 'mm', 'A4');
    $pdf->addPage();
    $w = $pdf->getPageWidth();
    $lm = 20; $rm = $w - 20;
    $usable = $rm - $lm;

    // Header
    $pdf->setFont('Helvetica-Bold', 16);
    $pdf->textAt($lm, 30, $d['brand_name'] ?? 'PEPP Learning');
    $pdf->setFont('Helvetica', 8);
    $pdf->textAt($lm, 36, $d['company_name'] ?? 'Labinc Education Pvt. Ltd.');
    $pdf->textAt($lm, 40, $d['company_address'] ?? '');
    $pdf->line($lm, 44, $rm, 44);

    // Title
    $pdf->setFont('Helvetica-Bold', 14);
    $pdf->textAt($lm, 56, 'APPOINTMENT LETTER');

    // Reference and date
    $pdf->setFont('Helvetica', 9);
    $appt_date = isset($d['approved_at']) ? date('d F Y', strtotime($d['approved_at'])) : date('d F Y');
    $pdf->textAt($lm, 64, 'Date: ' . $appt_date);
    $pdf->textAt($lm, 70, 'Ref: ' . ($d['appointment_ref'] ?? '-'));

    // Salutation
    $y = 82;
    $pdf->setFont('Helvetica', 10);
    $pdf->textAt($lm, $y, 'Dear ' . ($d['employee_name'] ?? 'Candidate') . ',');
    $y += 10;

    // Body paragraphs
    $pdf->setFont('Helvetica', 9.5);
    $lines = [];
    $lines[] = 'We are pleased to inform you that the management of ' . ($d['company_name'] ?? 'Labinc Education Pvt. Ltd.');
    $lines[] = 'has approved your appointment. Please find the details of your employment below:';
    foreach ($lines as $line) {
        $pdf->textAt($lm, $y, $line);
        $y += 5;
    }
    $y += 4;

    // Employment details table
    $details = [
        ['Employee ID', $d['employee_id'] ?? '-'],
        ['Designation', $d['designation'] ?? '-'],
        ['Department', $d['department'] ?? '-'],
        ['Category', ucfirst($d['application_for'] ?? 'employee')],
        ['Date of Joining', isset($d['joining_date']) ? date('d M Y', strtotime($d['joining_date'])) : '-'],
    ];
    if (!empty($d['probation_till'])) {
        $details[] = ['Probation Till', date('d M Y', strtotime($d['probation_till']))];
    }
    $details[] = ['Contract Valid From', isset($d['contract_from']) ? date('d M Y', strtotime($d['contract_from'])) : '-'];
    $details[] = ['Contract Valid Till', isset($d['contract_till']) ? date('d M Y', strtotime($d['contract_till'])) : '-'];

    if (isset($d['monthly_salary'])) {
        $salary_formatted = function_exists('rs') ? rs($d['monthly_salary']) : 'Rs. ' . number_format($d['monthly_salary'], 2);
        $details[] = ['Monthly Salary', $salary_formatted];
        if (function_exists('inr_in_words')) {
            $details[] = ['Salary in Words', 'Rupees ' . inr_in_words((float)$d['monthly_salary']) . ' Only'];
        }
    }

    $col1w = 50;
    foreach ($details as $row) {
        $pdf->setFont('Helvetica-Bold', 9);
        $pdf->textAt($lm, $y, $row[0]);
        $pdf->setFont('Helvetica', 9);
        $pdf->textAt($lm + $col1w, $y, ':  ' . $row[1]);
        $y += 6;
    }
    $y += 6;

    // Terms
    $pdf->setFont('Helvetica', 9);
    $terms = [
        'This appointment is subject to the terms and conditions of ' . ($d['brand_name'] ?? 'PEPP Learning') . '.',
        'You are requested to report on the date of joining mentioned above.',
        'We look forward to a mutually rewarding association.',
    ];
    foreach ($terms as $t) {
        $pdf->textAt($lm, $y, $t);
        $y += 5.5;
    }
    $y += 10;

    // Signature
    $pdf->setFont('Helvetica', 9);
    $pdf->textAt($lm, $y, 'For');
    $y += 5;
    $pdf->setFont('Helvetica-Bold', 10);
    $pdf->textAt($lm, $y, $d['company_name'] ?? 'Labinc Education Pvt. Ltd.');
    $y += 10;
    $pdf->setFont('Helvetica', 9);
    $pdf->textAt($lm, $y, 'Authorized Signatory: ' . ($d['approved_by_name'] ?? 'Super Administrator'));

    // Footer
    $fy = 280;
    $pdf->line($lm, $fy - 4, $rm, $fy - 4);
    $pdf->setFont('Helvetica', 7);
    $pdf->textAt($lm, $fy, 'This is a computer-generated appointment letter issued by ' . ($d['brand_name'] ?? 'PEPP Learning') . '.');
    if (!empty($d['company_email'])) {
        $pdf->textAt($lm, $fy + 4, 'Email: ' . $d['company_email'] . '  |  Phone: ' . ($d['company_phone'] ?? ''));
    }

    return $pdf->output();
}
