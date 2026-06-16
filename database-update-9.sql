-- ============================================================================
-- PEPP LEARNING — DATABASE UPDATE 9 : COLLATION ALIGNMENT (alumni/peppians)
-- Run ONCE in phpMyAdmin (after update 8). Safe to re-run.
--
-- The "Illegal mix of collations (utf8mb4_general_ci, utf8mb4_unicode_ci)"
-- error when adding/importing alumni happens because these tables were
-- created with a different collation than the connection/parameters. This
-- converts them (and the related portal tables) to utf8mb4_unicode_ci so all
-- string comparisons agree.
-- ============================================================================

ALTER TABLE `alumni`              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `peppians`            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `referral_programs`   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `referees`            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `referral_earnings`   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `referral_payouts`    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `coupons`             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `coupon_redemptions`  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- DONE. Adding/importing alumni and all portal queries now use one collation.
-- ============================================================================
