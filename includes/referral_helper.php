<?php
/**
 * PEPP Learning - coupon & referral helper.
 * Shared by register.php (apply a code at registration), marketing.php (admin
 * management) and the approval/onboarding hooks (credit referral earnings).
 *
 * A "code" entered at registration may be either:
 *   • a referral code  (referees.referral_code) → discount for the user AND a
 *     pending earning for the referee alumnus, or
 *   • a discount coupon (coupons.code)          → discount only.
 */

function pepp_tables_exist($pdo, $tables) {
    foreach ((array)$tables as $t) {
        try { if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn()) return false; }
        catch (Exception $e) { return false; }
    }
    return true;
}

// Self-healing database check for coupons table new columns
if (isset($pdo) && pepp_tables_exist($pdo, ['coupons'])) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM coupons LIKE 'restrict_alumni'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE coupons ADD COLUMN restrict_alumni TINYINT(1) NOT NULL DEFAULT 0");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM coupons LIKE 'restrict_non_alumni'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE coupons ADD COLUMN restrict_non_alumni TINYINT(1) NOT NULL DEFAULT 0");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM coupons LIKE 'assigned_emails'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE coupons ADD COLUMN assigned_emails TEXT DEFAULT NULL");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM coupons LIKE 'visibility'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE coupons ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT 'public'");
        }
    } catch (Exception $e) {
        error_log("Coupons schema update failed: " . $e->getMessage());
    }
}

/** Net fee for a course in a given academic year (falls back to any year). */
function course_fee($pdo, $course_name, $year = '') {
    try {
        if ($year !== '') {
            $stmt = $pdo->prepare("SELECT total_fee FROM pepp_courses WHERE course_name = ? AND (academic_year = ? OR academic_year = 'All years') ORDER BY CASE WHEN academic_year = ? THEN 0 ELSE 1 END LIMIT 1");
            $stmt->execute([$course_name, $year, $year]);
            $f = $stmt->fetchColumn();
            if ($f !== false && $f !== null) return (float)$f;
        }
        $stmt = $pdo->prepare("SELECT total_fee FROM pepp_courses WHERE course_name = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$course_name]);
        $f = $stmt->fetchColumn();
        return $f !== false ? (float)$f : 0.0;
    } catch (Exception $e) { return 0.0; }
}

/**
 * Validate a code (referral or coupon) for a course/year/fee.
 * Returns an array:
 *   ['ok'=>bool, 'kind'=>'referral'|'coupon'|null, 'discount'=>float,
 *    'message'=>string, 'referral_code'=>?, 'coupon_code'=>?, 'program_id'=>?,
 *    'referee_id'=>?]
 */
function validate_code($pdo, $code, $course_name, $year, $fee, $email = '', $whatsapp = '') {
    $code = strtoupper(trim($code));
    $res = ['ok' => false, 'kind' => null, 'discount' => 0.0, 'message' => '', 'referral_code' => null,
            'coupon_code' => null, 'program_id' => null, 'referee_id' => null];
    if ($code === '') { $res['message'] = 'Enter a code.'; return $res; }

    // 1) Referral code?
    if (pepp_tables_exist($pdo, ['referees', 'referral_programs'])) {
        try {
            $stmt = $pdo->prepare("SELECT r.*, p.user_discount, p.academic_year, p.status AS prog_status, p.start_date, p.end_date, p.once_per_user, p.id AS prog_id
                FROM referees r JOIN referral_programs p ON p.id = r.program_id
                WHERE UPPER(r.referral_code) = ? LIMIT 1");
            $stmt->execute([$code]);
            $ref = $stmt->fetch();
            if ($ref) {
                if ($ref['prog_status'] !== 'active') { $res['message'] = 'This referral program is no longer active.'; return $res; }
                if ($ref['academic_year'] !== $year && $year !== '') { $res['message'] = 'This referral code is for the ' . $ref['academic_year'] . ' batch.'; return $res; }
                $today = date('Y-m-d');
                if ($ref['start_date'] && $today < $ref['start_date']) { $res['message'] = 'This referral code is not active yet.'; return $res; }
                if ($ref['end_date'] && $today > $ref['end_date']) { $res['message'] = 'This referral code has expired.'; return $res; }
                if ($ref['once_per_user'] && $email !== '') {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_code = ? AND (email = ? OR whatsapp = ?)");
                    $stmt->execute([$code, $email, $whatsapp]);
                    if ((int)$stmt->fetchColumn() > 0) { $res['message'] = 'You have already used this referral code.'; return $res; }
                }
                $disc = min((float)$ref['user_discount'], $fee);
                $res = ['ok' => true, 'kind' => 'referral', 'discount' => $disc,
                        'message' => 'Referral code applied - you save ₹' . number_format($disc, 0) . '.',
                        'referral_code' => $ref['referral_code'], 'coupon_code' => null,
                        'program_id' => (int)$ref['prog_id'], 'referee_id' => (int)$ref['id']];
                return $res;
            }
        } catch (Exception $e) { error_log('validate referral: ' . $e->getMessage()); }
    }

    // 2) Discount coupon?
    if (pepp_tables_exist($pdo, ['coupons'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM coupons WHERE UPPER(code) = ? LIMIT 1");
            $stmt->execute([$code]);
            $c = $stmt->fetch();
            if ($c) {
                if ($c['status'] !== 'active') { $res['message'] = 'This coupon is inactive.'; return $res; }
                $today = date('Y-m-d');
                if ($c['start_date'] && $today < $c['start_date']) { $res['message'] = 'This coupon is not active yet.'; return $res; }
                if ($c['end_date'] && $today > $c['end_date']) { $res['message'] = 'This coupon has expired.'; return $res; }
                if ($c['scope_year'] && $year !== '' && $c['scope_year'] !== $year) { $res['message'] = 'This coupon is for the ' . $c['scope_year'] . ' batch.'; return $res; }
                if ($c['scope_course'] && $course_name !== '' && $c['scope_course'] !== $course_name) { $res['message'] = 'This coupon is for a different course.'; return $res; }
                if ($c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit']) { $res['message'] = 'This coupon has reached its usage limit.'; return $res; }
                if ($c['per_user_once'] && $email !== '') {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_code = ? AND (email = ? OR whatsapp = ?)");
                    $stmt->execute([$c['code'], $email, $whatsapp]);
                    if ((int)$stmt->fetchColumn() > 0) { $res['message'] = 'You have already used this coupon.'; return $res; }
                }
                
                // Restrict for Alumni check
                if (isset($c['restrict_alumni']) && $c['restrict_alumni'] == 1) {
                    $is_alumnus = false;
                    if ($email !== '' || $whatsapp !== '') {
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM alumni WHERE (email = ? AND email <> '') OR (secondary_email = ? AND secondary_email <> '') OR (mobile = ? AND mobile <> '') OR (secondary_mobile = ? AND secondary_mobile <> '')");
                        $chk->execute([$email, $email, $whatsapp, $whatsapp]);
                        $is_alumnus = ((int)$chk->fetchColumn() > 0);
                    }
                    if (!$is_alumnus) {
                        $res['message'] = 'This coupon is restricted to Alumni students only.';
                        return $res;
                    }
                }
                
                // Restrict for other than Alumni check
                if (isset($c['restrict_non_alumni']) && $c['restrict_non_alumni'] == 1) {
                    $is_alumnus = false;
                    if ($email !== '' || $whatsapp !== '') {
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM alumni WHERE (email = ? AND email <> '') OR (secondary_email = ? AND secondary_email <> '') OR (mobile = ? AND mobile <> '') OR (secondary_mobile = ? AND secondary_mobile <> '')");
                        $chk->execute([$email, $email, $whatsapp, $whatsapp]);
                        $is_alumnus = ((int)$chk->fetchColumn() > 0);
                    }
                    if ($is_alumnus) {
                        $res['message'] = 'This coupon is restricted to non-alumni students only.';
                        return $res;
                    }
                }
                
                // Assign to email id/s check
                if (!empty($c['assigned_emails'])) {
                    $allowed_emails = preg_split('/[\s,]+/', strtolower(trim($c['assigned_emails'])));
                    if (!in_array(strtolower(trim($email)), $allowed_emails)) {
                        $res['message'] = 'This coupon is restricted to specific email IDs.';
                        return $res;
                    }
                }

                if ($c['discount_type'] === 'percent') {
                    $disc = $fee * (float)$c['discount_value'] / 100.0;
                    if ($c['max_discount'] !== null) $disc = min($disc, (float)$c['max_discount']);
                } else {
                    $disc = (float)$c['discount_value'];
                }
                $disc = min($disc, $fee);
                $res = ['ok' => true, 'kind' => 'coupon', 'discount' => $disc,
                        'message' => 'Coupon applied - you save ₹' . number_format($disc, 0) . '.',
                        'referral_code' => null, 'coupon_code' => $c['code'],
                        'program_id' => null, 'referee_id' => null];
                return $res;
            }
        } catch (Exception $e) { error_log('validate coupon: ' . $e->getMessage()); }
    }

    $res['message'] = 'Invalid or unknown code.';
    return $res;
}

/** Record a redemption + create a pending referral earning (called at registration). */
function record_code_use($pdo, $info, $user_id, $student_name, $email, $whatsapp, $fee) {
    try {
        $code = $info['kind'] === 'referral' ? $info['referral_code'] : $info['coupon_code'];
        if (!$code) return;
        $pdo->prepare("INSERT INTO coupon_redemptions (coupon_code, user_id, email, whatsapp, discount_applied, redeemed_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([$code, $user_id, $email ?: null, $whatsapp ?: null, $info['discount']]);

        if ($info['kind'] === 'coupon') {
            $pdo->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE code = ?")->execute([$code]);
        } elseif ($info['kind'] === 'referral' && $info['referee_id']) {
            // Earning amount comes from the program (alumni_earning)
            $stmt = $pdo->prepare("SELECT alumni_earning, partial_credit FROM referral_programs WHERE id = ?");
            $stmt->execute([$info['program_id']]);
            $prog = $stmt->fetch();
            $earn = $prog ? (float)$prog['alumni_earning'] : 0.0;
            $pdo->prepare("INSERT INTO referral_earnings (referee_id, program_id, user_id, student_name, full_amount, credited_amount, status, created_at) VALUES (?,?,?,?,?,0,'pending',NOW())")
                ->execute([$info['referee_id'], $info['program_id'], $user_id, $student_name, $earn]);
            if (file_exists(__DIR__ . '/peppian_notify.php')) {
                require_once __DIR__ . '/peppian_notify.php';
                try { notify_referral_joined($pdo, $info['referee_id'], $student_name); marketing_flag($pdo, 'referral', 'Referral used by ' . $student_name); } catch (Exception $e) {}
            }
        }
    } catch (Exception $e) { error_log('record_code_use: ' . $e->getMessage()); }
}

/**
 * Credit referral earnings for a student once their registration is approved
 * AND onboarding is completed. Honors the partial-credit setting:
 *   • partial OFF → full amount becomes 'credited' immediately.
 *   • partial ON  → 50% on approval+onboarding ('half'), remaining 50% when
 *     all dues are cleared (onboarding completed + no pending installments).
 * Safe to call repeatedly (idempotent on status transitions).
 */
function credit_referral_for_user($pdo, $user_id) {
    if (!pepp_tables_exist($pdo, ['referral_earnings', 'referral_programs'])) return;
    try {
        $stmt = $pdo->prepare("SELECT * FROM referral_earnings WHERE user_id = ? AND status IN ('pending','half')");
        $stmt->execute([$user_id]);
        $earnings = $stmt->fetchAll();
        if (!$earnings) return;

        // Student state (user_id is the varchar registration id, e.g. PEPP...)
        $stmt = $pdo->prepare("SELECT status, onboarding_status, payment_plan FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        if (!$u) return;
        $approved = ($u['status'] ?? '') === 'approved';
        $onboarded = ($u['onboarding_status'] ?? '') === 'completed';
        if (!$approved || !$onboarded) return;

        // Dues cleared? (no pending/!paid installments)
        $dues_cleared = true;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM instalment_details WHERE user_id = ? AND status NOT IN ('approved','paid')");
            $stmt->execute([$user_id]);
            $dues_cleared = ((int)$stmt->fetchColumn() === 0);
        } catch (Exception $e) { $dues_cleared = true; }

        foreach ($earnings as $e) {
            $prog = $pdo->prepare("SELECT partial_credit FROM referral_programs WHERE id = ?");
            $prog->execute([$e['program_id']]);
            $partial = (bool)$prog->fetchColumn();
            $full = (float)$e['full_amount'];

            $notifyAmt = 0; $notifyRef = (int)$e['referee_id'];
            if (!$partial) {
                $pdo->prepare("UPDATE referral_earnings SET credited_amount = ?, status = 'credited', updated_at = NOW() WHERE id = ?")
                    ->execute([$full, $e['id']]);
                $notifyAmt = $full;
            } else {
                if ($dues_cleared) {
                    $already = (float)$e['credited_amount'];
                    $pdo->prepare("UPDATE referral_earnings SET credited_amount = ?, status = 'credited', updated_at = NOW() WHERE id = ?")
                        ->execute([$full, $e['id']]);
                    $notifyAmt = $full - $already;
                } else {
                    $half = round($full / 2, 2);
                    if ($e['status'] !== 'half') {
                        $pdo->prepare("UPDATE referral_earnings SET credited_amount = ?, status = 'half', updated_at = NOW() WHERE id = ?")
                            ->execute([$half, $e['id']]);
                        $notifyAmt = $half;
                    }
                }
            }
            if ($notifyAmt > 0 && file_exists(__DIR__ . '/peppian_notify.php')) {
                require_once __DIR__ . '/peppian_notify.php';
                try { notify_referral_credited($pdo, $notifyRef, $notifyAmt, $e['student_name'] ?? ''); marketing_flag($pdo, 'referral', 'Earning credited'); } catch (Exception $ex) {}
            }
        }
    } catch (Exception $e) { error_log('credit_referral_for_user: ' . $e->getMessage()); }
}

/** Wallet summary for a referee: earned (credited), paid out, balance, pending. */
function referee_wallet($pdo, $referee_id) {
    $w = ['credited' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'pending' => 0.0, 'joined' => 0];
    try {
        $stmt = $pdo->prepare("SELECT status, full_amount, credited_amount FROM referral_earnings WHERE referee_id = ?");
        $stmt->execute([$referee_id]);
        foreach ($stmt->fetchAll() as $e) {
            $w['joined']++;
            $w['credited'] += (float)$e['credited_amount'];
            if (in_array($e['status'], ['pending', 'half'], true)) {
                $w['pending'] += (float)$e['full_amount'] - (float)$e['credited_amount'];
            }
        }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_payouts WHERE referee_id = ?");
        $stmt->execute([$referee_id]);
        $w['paid'] = (float)$stmt->fetchColumn();
        $w['balance'] = max(0, $w['credited'] - $w['paid']);
    } catch (Exception $e) { error_log('referee_wallet: ' . $e->getMessage()); }
    return $w;
}

/** Clean up coupon redemptions and referral earnings when a student registration is deleted or rejected. */
function cleanup_referral_and_coupon_for_user($pdo, $user_id) {
    if (!pepp_tables_exist($pdo, ['coupon_redemptions', 'referral_earnings'])) return;
    try {
        // 1) Find redemption record
        $stmt = $pdo->prepare("SELECT coupon_code FROM coupon_redemptions WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $redemptions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($redemptions as $code) {
            // Decrement used_count in coupons if it exists
            if (pepp_tables_exist($pdo, ['coupons'])) {
                $pdo->prepare("UPDATE coupons SET used_count = GREATEST(0, used_count - 1) WHERE code = ?")->execute([$code]);
            }
        }
        
        // 2) Delete redemption and earnings records
        $pdo->prepare("DELETE FROM coupon_redemptions WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM referral_earnings WHERE user_id = ?")->execute([$user_id]);
        
    } catch (Exception $e) {
        error_log('cleanup_referral_and_coupon_for_user: ' . $e->getMessage());
    }
}

/** Reset referral earnings to pending when a student's approval is reverted. */
function reset_referral_earning_for_user($pdo, $user_id) {
    if (!pepp_tables_exist($pdo, ['referral_earnings'])) return;
    try {
        $pdo->prepare("UPDATE referral_earnings SET status = 'pending', credited_amount = 0.00, updated_at = NOW() WHERE user_id = ?")
            ->execute([$user_id]);
    } catch (Exception $e) {
        error_log('reset_referral_earning_for_user: ' . $e->getMessage());
    }
}
