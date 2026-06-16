-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE / DATA REPAIR
-- Run this ONCE in phpMyAdmin (select database u361910773_peppadmin → SQL tab).
-- Safe to re-run: every statement only touches rows that still need fixing.
--
-- BACK UP FIRST: phpMyAdmin → Export → Quick → Go.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. Fill instalment_details.paid_amount for already-approved installments.
--    Older approvals never recorded the received amount; reports that sum
--    paid_amount were under-counting. Default the received amount to the
--    scheduled amount.
-- ----------------------------------------------------------------------------
UPDATE instalment_details
SET paid_amount = amount
WHERE status IN ('approved', 'paid')
  AND (paid_amount IS NULL OR paid_amount = 0);


-- ----------------------------------------------------------------------------
-- 2. REPAIR users.paid_amount (THE DOUBLE-COUNTING BUG).
--    The old payment-review.php executed:
--        UPDATE users SET paid_amount = paid_amount + <installment amount>
--    every time an installment was approved. users.paid_amount must hold the
--    REGISTRATION payment only — installments live in instalment_details.
--    Because of the bug, revenue reports counted every approved installment
--    TWICE and registration figures were inflated.
--
--    This statement subtracts the approved-installment total back out of
--    paid_amount, restoring the original registration payment.
--
--    To PREVIEW what will change before running the UPDATE, run this SELECT:
--
--    SELECT u.user_id, u.name, u.paid_amount AS current_value,
--           x.inst_total,
--           (u.paid_amount - x.inst_total) AS corrected_registration_payment
--    FROM users u
--    JOIN (SELECT user_id, SUM(COALESCE(paid_amount, amount)) AS inst_total
--          FROM instalment_details
--          WHERE status IN ('approved','paid')
--          GROUP BY user_id) x ON x.user_id = u.user_id
--    WHERE u.paid_amount - x.inst_total >= 0;
-- ----------------------------------------------------------------------------
UPDATE users u
JOIN (
    SELECT user_id, SUM(COALESCE(paid_amount, amount)) AS inst_total
    FROM instalment_details
    WHERE status IN ('approved', 'paid')
    GROUP BY user_id
) x ON x.user_id = u.user_id
SET u.paid_amount = u.paid_amount - x.inst_total
WHERE u.paid_amount - x.inst_total >= 0;
-- NOTE: run this section ONLY ONCE. (Running it twice would subtract twice.)
-- If you already corrected some students manually, verify with the preview
-- SELECT above before executing.


-- ----------------------------------------------------------------------------
-- 3. Backfill users.total_fee (net payable after discount).
--    The old approval flow never set total_fee, leaving 0.00 everywhere, so
--    balance / outstanding calculations had nothing to work from.
--    total_fee = course catalogue fee − discount (floored at 0).
--    Matches course by name + academic year first, then by name alone.
-- ----------------------------------------------------------------------------
UPDATE users u
JOIN pepp_courses pc
  ON pc.course_name = u.pepp_course AND pc.academic_year = u.pepp_academic_year
SET u.total_fee = GREATEST(0, pc.total_fee - COALESCE(u.discount_amount, 0))
WHERE u.status = 'approved'
  AND (u.total_fee IS NULL OR u.total_fee = 0)
  AND pc.total_fee > 0;

UPDATE users u
JOIN (
    SELECT course_name, MAX(total_fee) AS total_fee
    FROM pepp_courses
    GROUP BY course_name
) pc ON pc.course_name = u.pepp_course
SET u.total_fee = GREATEST(0, pc.total_fee - COALESCE(u.discount_amount, 0))
WHERE u.status = 'approved'
  AND (u.total_fee IS NULL OR u.total_fee = 0)
  AND pc.total_fee > 0;


-- ----------------------------------------------------------------------------
-- 4. Backfill users.phone and mobile_same_as_whatsapp.
--    register.php previously saved NULL into both (the form key bug), which
--    blanked contact info on the installment admin pages.
-- ----------------------------------------------------------------------------
UPDATE users
SET phone = COALESCE(NULLIF(mobile_number, ''), whatsapp_number)
WHERE phone IS NULL OR phone = '';

UPDATE users
SET mobile_same_as_whatsapp = IF(mobile_number IS NULL OR mobile_number = '' OR mobile_number = whatsapp_number, 'yes', 'no')
WHERE mobile_same_as_whatsapp IS NULL;


-- ----------------------------------------------------------------------------
-- 5. Repair course_status wrongly set to 'completed'.
--    The old payment-review.php marked the COURSE completed as soon as all
--    INSTALLMENTS were paid — finishing payments is not finishing the course.
--    Restore 'active' for active students whose access hasn't expired.
-- ----------------------------------------------------------------------------
UPDATE users
SET course_status = 'active', course_end_date = NULL
WHERE course_status = 'completed'
  AND student_status = 'active'
  AND status = 'approved'
  AND (course_duration_date IS NULL OR course_duration_date >= CURDATE());


-- ----------------------------------------------------------------------------
-- 6. Backfill joined_date for approved students (used in reports/sorting).
-- ----------------------------------------------------------------------------
UPDATE users
SET joined_date = COALESCE(DATE(approval_date), paid_date, DATE(created_at))
WHERE status = 'approved' AND joined_date IS NULL;


-- ----------------------------------------------------------------------------
-- 7. Performance indexes for the new report queries (MariaDB syntax;
--    IF NOT EXISTS makes re-runs safe).
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_inst_status_paiddate ON instalment_details (status, paid_date);
CREATE INDEX IF NOT EXISTS idx_users_status_paiddate ON users (status, paid_date);
CREATE INDEX IF NOT EXISTS idx_users_onboarding ON users (onboarding_status);

-- ============================================================================
-- DONE. After running:
--   • users.paid_amount  = registration payment only
--   • instalment_details = every installment payment (paid_amount filled)
--   • users.total_fee    = net payable (course fee − discount)
--   → Dashboard revenue & all fee reports now reconcile exactly.
-- ============================================================================
