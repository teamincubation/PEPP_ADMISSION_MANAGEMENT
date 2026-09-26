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
    $y = 140;

    // Date & Reference
    $appt_date = isset($d['approved_at']) ? date('d M Y', strtotime($d['approved_at'])) : date('d M Y');
    $pdf->text($lm, $y, 10, 'Date: ' . $appt_date, true);
    $pdf->text($lm, $y + 15, 10, 'Ref. ' . ($d['appointment_ref'] ?? '-'), true);
    $y += 45;

    // Merge nested employee details if provided
    if (isset($d['employee']) && is_array($d['employee'])) {
        $d = array_merge($d['employee'], $d);
    }
    if (empty($d['employee_name']) && !empty($d['full_name'])) {
        $d['employee_name'] = $d['full_name'];
    }

    $app_for = strtolower(trim((string)($d['application_for'] ?? $d['application_type'] ?? 'employee')));

    if ($app_for === 'intern') {
        // ═════════════════════════════════════════════════════════════════════
        // INTERN APPOINTMENT LETTER — Zero payment / stipend exposure
        // ═════════════════════════════════════════════════════════════════════
        $pdf->text($lm, $y, 14, 'INTERNSHIP APPOINTMENT LETTER', true, 'C', $usable);
        $y += 30;

        $pdf->text($lm, $y, 10, 'Dear ' . ($d['employee_name'] ?? 'Candidate') . ',', true);
        $y += 20;

        $joining_date = !empty($d['joining_date']) ? date('d M Y', strtotime($d['joining_date'])) : '-';
        $ends_on = !empty($d['internship_ends_on']) ? date('d M Y', strtotime($d['internship_ends_on'])) : (!empty($d['contract_till']) ? date('d M Y', strtotime($d['contract_till'])) : '-');

        $opening_text = 'We are pleased to offer you an internship position as Project Intern at PEPP Learning, effective from ' . $joining_date . ' through ' . $ends_on . '.';
        draw_paragraph($pdf, $lm, $y, 10, $opening_text, 15, $usable);
        $y += 12;

        $intro_terms = 'Your internship will be subject to the terms and conditions outlined below:';
        draw_paragraph($pdf, $lm, $y, 10, $intro_terms, 15, $usable);
        $y += 12;

        $terms = [
            '1. You will serve as Project Intern and report to the designated mentor or department lead assigned by the management.',
            '2. Your duties, project allocations, and learning milestones will be communicated separately and may be updated based on organisational requirements.',
            '3. Your working hours, attendance, and project deliverables will be governed by the applicable internship policies of PEPP Learning.',
            '4. You are required to maintain strict confidentiality regarding all organisational, student, staff, academic, financial, technical, and other proprietary information accessed during your internship. You are expected to comply with all applicable rules, policies, and professional standards of PEPP Learning.',
            '5. All work products, code, documentation, and materials produced by you during the internship shall remain the exclusive intellectual property of PEPP Learning.',
            '6. Upon successful completion of your internship tenure and fulfillment of assigned responsibilities, you will be eligible to receive an official Internship Completion Certificate.',
            '7. Either party may conclude or terminate the internship engagement prior to the scheduled completion date by providing 7 days of written notice, subject to applicable policies.'
        ];

        foreach ($terms as $t) {
            draw_paragraph($pdf, $lm, $y, 10, $t, 14, $usable);
            $y += 6;
        }
        $y += 10;

        $closing_text = 'This offer is subject to the verification of the information and documents provided by you and adherence to the applicable policies of PEPP Learning.';
        draw_paragraph($pdf, $lm, $y, 10, $closing_text, 14, $usable);
        $y += 12;

        $closing_text2 = 'We welcome you to PEPP Learning and look forward to a productive and mutually rewarding learning experience.';
        draw_paragraph($pdf, $lm, $y, 10, $closing_text2, 14, $usable);
        $y += 24;

    } elseif ($app_for === 'faculty') {
        // ═════════════════════════════════════════════════════════════════════
        // FACULTY APPOINTMENT LETTER — Zero session charges / hourly rate exposure
        // ═════════════════════════════════════════════════════════════════════
        $pdf->text($lm, $y, 14, 'FACULTY APPOINTMENT LETTER', true, 'C', $usable);
        $y += 30;

        $pdf->text($lm, $y, 10, 'Dear ' . ($d['employee_name'] ?? 'Candidate') . ',', true);
        $y += 20;

        $academic_year = !empty($d['academic_year']) ? $d['academic_year'] : 'the designated academic year';

        $opening_text = 'We are pleased to formally appoint you as Faculty at PEPP Learning for the academic year ' . $academic_year . '.';
        draw_paragraph($pdf, $lm, $y, 10, $opening_text, 15, $usable);
        $y += 12;

        $intro_terms = 'Your faculty engagement will be subject to the terms and conditions outlined below:';
        draw_paragraph($pdf, $lm, $y, 10, $intro_terms, 15, $usable);
        $y += 12;

        $terms = [
            '1. You will serve as Faculty and collaborate with the academic coordination team to deliver designated courses, academic sessions, and student mentoring.',
            '2. Your teaching schedules, session delivery formats (live, recorded, question paper discussion, and offline sessions), and subject allocations will be coordinated by the Academic Directorate.',
            '3. You are expected to adhere strictly to the academic calendar, syllabus benchmarks, pedagogical guidelines, and quality standards established by PEPP Learning.',
            '4. You are required to maintain complete confidentiality regarding all question banks, assessment materials, student evaluations, proprietary curriculum, and internal academic documentation.',
            '5. You are expected to maintain the highest standards of professional ethics, student engagement, and institutional decorum at all times.',
            '6. Your engagement is valid for the academic year ' . $academic_year . ' and will be subject to periodic academic review by the management.',
            '7. Either party may conclude the academic engagement by providing 30 days of written notice, ensuring minimal disruption to ongoing student academic schedules.'
        ];

        foreach ($terms as $t) {
            draw_paragraph($pdf, $lm, $y, 10, $t, 14, $usable);
            $y += 6;
        }
        $y += 10;

        $closing_text = 'This appointment is subject to the verification of the information and academic credentials provided by you and the applicable policies of PEPP Learning.';
        draw_paragraph($pdf, $lm, $y, 10, $closing_text, 14, $usable);
        $y += 12;

        $closing_text2 = 'We welcome you to PEPP Learning and look forward to your valuable academic leadership and contribution.';
        draw_paragraph($pdf, $lm, $y, 10, $closing_text2, 14, $usable);
        $y += 24;

    } else {
        // ═════════════════════════════════════════════════════════════════════
        // EMPLOYEE APPOINTMENT LETTER — Unchanged original text and terms
        // ═════════════════════════════════════════════════════════════════════
        $pdf->text($lm, $y, 14, 'APPOINTMENT LETTER', true, 'C', $usable);
        $y += 30;

        $pdf->text($lm, $y, 10, 'Dear ' . ($d['employee_name'] ?? 'Candidate') . ',', true);
        $y += 20;

        $designation = $d['designation'] ?? 'staff';
        $department = $d['department'] ?? 'assigned department';
        $joining_date = isset($d['joining_date']) ? date('d M Y', strtotime($d['joining_date'])) : '-';

        $opening_text = 'We are pleased to formally appoint you as ' . $designation . ' under ' . $department . ' at PEPP Learning, effective from ' . $joining_date . '.';
        draw_paragraph($pdf, $lm, $y, 10, $opening_text, 15, $usable);
        $y += 12;

        $intro_terms = 'Your appointment will be subject to the terms and conditions outlined below:';
        draw_paragraph($pdf, $lm, $y, 10, $intro_terms, 15, $usable);
        $y += 12;

        // Dynamic probation calculation
        $probation_period = '';
        if (!empty($d['probation_till']) && !empty($d['joining_date'])) {
            try {
                $start = new DateTime($d['joining_date']);
                $end = new DateTime($d['probation_till']);
                $diff = $start->diff($end);
                $months = (($diff->y) * 12) + ($diff->m);
                if ($diff->d > 15) $months++;
                if ($months > 0) {
                    $probation_period = $months . ' Month' . ($months > 1 ? 's' : '');
                } else {
                    $probation_period = date('d M Y', strtotime($d['probation_till']));
                }
            } catch (Exception $e) {
                $probation_period = date('d M Y', strtotime($d['probation_till']));
            }
        } elseif (!empty($d['probation_till'])) {
            $probation_period = date('d M Y', strtotime($d['probation_till']));
        } else {
            $probation_period = 'N/A';
        }

        $salary_val = (float)($d['monthly_salary'] ?? 0);
        $salary_formatted = 'Rs. ' . number_format($salary_val) . '/- per month';

        $terms = [
            '1. You will serve as ' . $designation . ' and report to the person/department assigned by the management.',
            '2. Your duties and responsibilities will be communicated separately through the General Duties (GD) document and may be revised based on organisational requirements.',
            '3. Your remuneration will be ' . $salary_formatted . ', along with any applicable incentives or benefits as communicated by management.',
            '4. Your working hours, attendance, holidays, and leave will be governed by the applicable policies of PEPP Learning.',
            '5. You are required to maintain confidentiality regarding all organisational, student, staff, academic, financial, technical, and other proprietary information accessed during your employment. You are expected to comply with all applicable rules, policies, procedures, and professional standards of PEPP Learning.',
            '6. Your appointment will be subject to a probationary period of ' . $probation_period . ', if applicable, after which your employment may be confirmed based on satisfactory performance.',
            '7. Either party may terminate the employment by providing 30 days of notice period or salary in lieu of notice, subject to applicable policies and law.'
        ];

        foreach ($terms as $t) {
            draw_paragraph($pdf, $lm, $y, 10, $t, 14, $usable);
            $y += 6;
        }
        $y += 10;

        $closing_text = 'This appointment is subject to the verification of the information and documents provided by you and the applicable policies of PEPP Learning.';
        draw_paragraph($pdf, $lm, $y, 10, $closing_text, 14, $usable);
        $y += 12;

        $closing_text2 = 'We welcome you to PEPP Learning and look forward to your valuable contribution to the organisation.';
        draw_paragraph($pdf, $lm, $y, 10, $closing_text2, 14, $usable);
        $y += 24;
    }

    // Signatory block
    $pdf->text($lm, $y, 10, 'Authorized Signatory', true);

    // Embed Official PEPP Seal
    $seal = __DIR__ . '/../assets/img/pepp-seal.png';
    if (!file_exists($seal)) {
        $seal = __DIR__ . '/../assets/img/pepp-seal.jpg';
    }
    if (file_exists($seal)) {
        $pdf->image($seal, 440, $y - 45, 65, 65);
    }
    $y += 20;

    // System generated notice
    $pdf->text($lm, $y, 8.5, 'This is a system-generated appointment letter and does not require a physical signature.');

    return $pdf->output();
}
