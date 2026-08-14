<?php
/**
 * PEPP Learning — Appointment Letter PDF generator.
 * Reads from an immutable appointment snapshot JSON (never live employee data).
 * Uses MiniPDF from pdf_invoice.php (dependency-free PDF 1.4 writer).
 */
require_once __DIR__ . '/pdf_invoice.php';

/**
 * Helper to wrap and draw paragraph text.
 *
 * @param MiniPDF $pdf
 * @param float   $x
 * @param float   &$y
 * @param float   $size
 * @param string  $text
 * @param float   $line_height
 * @param float   $usable_width
 * @param bool    $bold
 */
function draw_paragraph($pdf, $x, &$y, $size, $text, $line_height, $usable_width, $bold = false) {
    $words = explode(' ', $text);
    $current_line = '';
    foreach ($words as $word) {
        $test_line = $current_line === '' ? $word : $current_line . ' ' . $word;
        $w = $pdf->width($test_line, $size);
        if ($w > $usable_width) {
            $pdf->text($x, $y, $size, $current_line, $bold);
            $y += $line_height;
            $current_line = $word;
        } else {
            $current_line = $test_line;
        }
    }
    if ($current_line !== '') {
        $pdf->text($x, $y, $size, $current_line, $bold);
        $y += $line_height;
    }
}

/**
 * Render an appointment letter PDF from the frozen snapshot.
 *
 * @param  string $snapshot_json  The immutable JSON stored at approval time
 * @return string                 Raw PDF bytes
 */
function render_appointment_pdf(string $snapshot_json): string {
    $d = json_decode($snapshot_json, true);
    if (!$d) throw new RuntimeException('Invalid appointment snapshot');

    $pdf = new MiniPDF();
    
    // 1. Draw Full Page Background Letterhead first
    $letterhead = __DIR__ . '/../assets/img/pepp-letterhead.jpg';
    if (!file_exists($letterhead)) {
        throw new RuntimeException('Official letterhead template not found.');
    }
    $pdf->image($letterhead, 0, 0, 595.28, 841.89);

    $lm = 54; 
    $rm = 595.28 - 54;
    $usable = $rm - $lm;

    // Start body contents below header area
    $y = 120;

    // Date & Reference
    $appt_date = isset($d['approved_at']) ? date('d F Y', strtotime($d['approved_at'])) : date('d F Y');
    $pdf->text($lm, $y, 9.5, 'Date: ' . $appt_date);
    $pdf->text($lm, $y + 14, 9.5, 'Ref.: ' . ($d['appointment_ref'] ?? '-'));
    $y += 38;

    // Title
    $pdf->text($lm, $y, 14, 'APPOINTMENT LETTER', true, 'C', $usable);
    $y += 24;

    // Salutation
    $pdf->text($lm, $y, 10, 'Dear ' . ($d['employee_name'] ?? 'Candidate') . ',', true);
    $y += 18;

    // Opening Paragraph
    $designation = $d['designation'] ?? 'staff';
    $department = $d['department'] ?? 'assigned department';
    $joining_date = isset($d['joining_date']) ? date('d F Y', strtotime($d['joining_date'])) : '-';
    $opening_text = 'We are pleased to formally appoint you as ' . $designation . ' under the ' . $department . ' department at PEPP Learning, effective from ' . $joining_date . '.';
    draw_paragraph($pdf, $lm, $y, 10, $opening_text, 14, $usable);
    $y += 12;

    // Employment Details Box
    $pdf->text($lm, $y, 10, 'EMPLOYMENT SUMMARY:', true);
    $y += 15;

    $salary_val = (float)($d['monthly_salary'] ?? 0);
    $salary_formatted = 'Rs. ' . number_format($salary_val, 2);

    $details = [
        ['Employee ID', ': ' . ($d['employee_id'] ?? '-')],
        ['Designation', ': ' . $designation],
        ['Department', ': ' . $department],
        ['Application Type', ': ' . (ucfirst($d['application_for'] ?? 'employee'))],
        ['Joining Date', ': ' . $joining_date],
    ];
    if (!empty($d['probation_till'])) {
        $details[] = ['Probation Period', ': Till ' . date('d F Y', strtotime($d['probation_till']))];
    } else {
        $details[] = ['Probation Period', ': N/A'];
    }
    
    $contract_from = isset($d['contract_from']) ? date('d F Y', strtotime($d['contract_from'])) : '-';
    $contract_till = isset($d['contract_till']) ? date('d F Y', strtotime($d['contract_till'])) : '-';
    $details[] = ['Contract Validity', ': ' . $contract_from . ' to ' . $contract_till];
    $details[] = ['Monthly Salary', ': ' . $salary_formatted];

    $col_width = 120;
    foreach ($details as $item) {
        $pdf->text($lm + 12, $y, 9.5, $item[0], true);
        $pdf->text($lm + 12 + $col_width, $y, 9.5, $item[1]);
        $y += 13;
    }
    $y += 12;

    // Terms
    $pdf->text($lm, $y, 10, 'Terms and Conditions of Appointment:', true);
    $y += 16;

    $probation_text = !empty($d['probation_till']) 
        ? 'until ' . date('d F Y', strtotime($d['probation_till'])) 
        : 'as per the PEPP Learning HR policies';

    $terms = [
        '1. Designation and Reporting: You will be appointed as ' . $designation . ' and will report to the designated officer of the department.',
        '2. General Duties: Your duties and responsibilities will be as defined in the General Duties (GD) document provided by the department.',
        '3. Remuneration: You will receive a monthly salary of ' . $salary_formatted . '.',
        '4. Working Hours, Attendance, Holidays and Leave: You will follow the working hours, attendance policies, holidays, and leave rules applicable to PEPP Learning staff.',
        '5. Confidentiality: You shall maintain strict confidentiality regarding all official information, student records, and company proprietary data.',
        '6. Probationary Period: You will be on probation ' . $probation_text . ' from the date of joining.',
        '7. Notice Period: This appointment can be terminated by either party by giving a 30-day written notice or salary in lieu thereof.'
    ];

    foreach ($terms as $t) {
        draw_paragraph($pdf, $lm, $y, 9.5, $t, 13, $usable);
        $y += 5; // spacing between terms
    }
    $y += 10;

    // Closing Paragraph
    $closing_text = 'This appointment is subject to verification of the information/documents provided and applicable PEPP Learning policies. We welcome you to PEPP Learning and wish you a successful career with us.';
    draw_paragraph($pdf, $lm, $y, 9.5, $closing_text, 13, $usable);
    $y += 20;

    // Signatory block
    $pdf->text($lm, $y, 9.5, 'Authorized Signatory', true);
    
    // Embed Official PEPP Seal
    $seal = __DIR__ . '/../assets/img/pepp-seal.jpg';
    if (file_exists($seal)) {
        $pdf->image($seal, 420, $y - 30, 75, 75);
    }

    $y += 20;
    $pdf->text($lm, $y, 9.5, 'Authorized by: ' . ($d['approved_by_name'] ?? 'Super Administrator'));
    $y += 12;
    $pdf->text($lm, $y, 9, 'PEPP Learning - HR Department');
    $y += 20;

    // System generated notice
    $pdf->text($lm, $y, 8, 'This is a system-generated appointment letter and does not require a physical signature.', false, 'C', $usable);

    return $pdf->output();
}
