<?php
/**
 * PEPP Learning - WhatsApp/notification template filler.
 * Replaces {placeholders} in admin-written templates with live student data
 * from the database. Available everywhere templates are used (onboarding,
 * WhatsApp messages, payment review).
 *
 * Supported variables (any users-table column works as {column_name}; the
 * most useful ones, plus computed values):
 *   {name} {user_id} {email} {whatsapp_number} {mobile_number}
 *   {PEPP course} / {pepp_course}     course name
 *   {academic_year}                   PEPP academic year
 *   {course_duration_date} / {access_end}   course access end (12 Jun 2026)
 *   {joined_date} {paid_date} {date_of_birth}
 *   {payment_plan} {payment_mode} {student_status}
 *   {paid_amount}      registration payment      e.g. ₹1,000
 *   {total_fee}        net payable after discount
 *   {discount_amount}  discount given
 *   {collected}        registration + approved installments
 *   {balance}          net payable − collected (never negative)
 */
function fill_student_template($pdo, $template, $student) {
    if ($template === null || $template === '') return '';

    // Accept either a users row (array) or a user_id string
    if (!is_array($student)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$student]);
        $student = $stmt->fetch();
        if (!$student) return $template;
    }

    // Approved installment total for computed money fields
    $inst_paid = 0.0;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(paid_amount, amount)), 0)
            FROM instalment_details WHERE user_id = ? AND status IN ('approved','paid')
        ");
        $stmt->execute([$student['user_id']]);
        $inst_paid = (float)$stmt->fetchColumn();
    } catch (Exception $e) { /* leave at 0 */ }

    $money = function ($v) { return '₹' . number_format((float)$v, ((float)$v == floor((float)$v)) ? 0 : 2); };
    $dmy   = function ($v) {
        if (!$v || $v === '0000-00-00') return '';
        $t = strtotime($v);
        return $t ? date('d M Y', $t) : (string)$v;
    };

    $collected = (float)($student['paid_amount'] ?? 0) + $inst_paid;
    $payable   = (float)($student['total_fee'] ?? 0);
    $balance   = max(0, $payable - $collected);

    $map = [];
    foreach ($student as $col => $val) {
        if (is_array($val)) continue;
        if (preg_match('/(_date|date_of_birth)$/', $col)) {
            $map['{' . $col . '}'] = $dmy($val);
        } elseif (in_array($col, ['paid_amount', 'total_fee', 'discount_amount'], true)) {
            $map['{' . $col . '}'] = $money($val);
        } else {
            $map['{' . $col . '}'] = (string)($val ?? '');
        }
    }
    // Friendly aliases & computed values
    $map['{PEPP course}']   = (string)($student['pepp_course'] ?? '');
    $map['{academic_year}'] = (string)($student['pepp_academic_year'] ?? '');
    $map['{access_end}']    = $dmy($student['course_duration_date'] ?? '');
    $map['{joined}']        = $dmy($student['joined_date'] ?? '');
    $map['{collected}']     = $money($collected);
    $map['{balance}']       = $money($balance);

    return strtr($template, $map);
}
