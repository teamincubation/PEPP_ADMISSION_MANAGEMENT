<?php
/**
 * PEPP Learning — Birthday WhatsApp Notification Scheduler & Person Deduplication Engine.
 *
 * Implements person-level deduplication:
 *   - One real person → One birthday greeting → One WhatsApp message → One reward claim
 *   - Deduplication identity: Normalized WhatsApp mobile (primary) or Normalized Email (fallback)
 *   - Canonical record selection: Current active academic year → Active status → Earliest created_at → Deterministic user_id
 *   - Idempotency key: (person_identity, birthday_date) in birthday_notifications_sent
 *
 * Called from cron-queue.php (Task 5).
 */

require_once __DIR__ . '/communication/CommunicationEngine.php';

/**
 * Normalize a birthday's month-day pair, applying the Feb 29 policy:
 *   Feb 29 birthdays are treated as Feb 28 in non-leap years.
 *
 * @param string $dob   Date string (Y-m-d format)
 * @param int    $year  Target year for leap-year evaluation
 * @return array{month: int, day: int}
 */
function get_birthday_month_day(string $dob, int $year): array {
    $time = strtotime($dob);
    $m = (int)date('m', $time);
    $d = (int)date('d', $time);
    // Feb 29 policy: shift to Feb 28 in non-leap years
    if ($m === 2 && $d === 29) {
        $isLeap = (bool)date('L', mktime(0, 0, 0, 1, 1, $year));
        if (!$isLeap) {
            $d = 28;
        }
    }
    return ['month' => $m, 'day' => $d];
}

/**
 * Check if a date of birth matches a target date string, respecting the Feb 29 policy.
 *
 * @param string $dob            Student date of birth (Y-m-d)
 * @param string $targetDateStr  Target date (Y-m-d)
 * @return bool
 */
function birthday_matches_date(string $dob, string $targetDateStr): bool {
    if (empty($dob) || $dob === '0000-00-00') {
        return false;
    }
    $targetTime = strtotime($targetDateStr);
    if ($targetTime === false) {
        return false;
    }
    $targetYear  = (int)date('Y', $targetTime);
    $targetMonth = (int)date('m', $targetTime);
    $targetDay   = (int)date('d', $targetTime);

    $bday = get_birthday_month_day($dob, $targetYear);
    return ($bday['month'] === $targetMonth && $bday['day'] === $targetDay);
}

/**
 * Retrieve the current active academic year string (e.g. '2026-27').
 * Canonical source: academic_years table where status = 'active'.
 *
 * @param PDO $pdo
 * @return string|null
 */
function get_birthday_active_academic_year(PDO $pdo): ?string {
    try {
        $stmt = $pdo->query("SELECT year FROM academic_years WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
        $year = $stmt->fetchColumn();
        return $year ? (string)$year : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Resolve a student's person-level identity string.
 *
 * Priority:
 *   1. Normalized WhatsApp / mobile number (digits-only, >= 10 digits) -> 'phone:{normalized}'
 *   2. Normalized Email fallback (only if mobile/WhatsApp is unavailable or < 10 digits) -> 'email:{email}'
 *   3. If neither available -> null
 *
 * Name is NEVER used as an identity key.
 * Uses CommunicationEngine::normalizePhone().
 *
 * @param array $student Associative array of student row
 * @return string|null
 */
function resolve_person_identity(array $student): ?string {
    // 1. Try WhatsApp number first
    $countryCode = $student['whatsapp_country_code'] ?? '';
    $rawWa = $student['whatsapp_number'] ?? '';
    $waPhone = '';
    if (!empty($rawWa)) {
        $waPhone = CommunicationEngine::normalizePhone($countryCode . $rawWa);
    }

    // If WhatsApp number is empty or invalid (< 10 digits), try mobile_number or phone
    if (empty($waPhone) || strlen($waPhone) < 10) {
        $rawMobile = $student['mobile_number'] ?? ($student['phone'] ?? '');
        if (!empty($rawMobile)) {
            $waPhone = CommunicationEngine::normalizePhone($rawMobile);
        }
    }

    // Valid mobile/WhatsApp (>= 10 digits)
    if (!empty($waPhone) && strlen($waPhone) >= 10) {
        return 'phone:' . $waPhone;
    }

    // 2. Email fallback ONLY when mobile/WhatsApp is unavailable or invalid
    $email = strtolower(trim((string)($student['email'] ?? '')));
    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'email:' . $email;
    }

    return null;
}

/**
 * Compare two student records to determine canonical priority:
 *   Priority 1: Current active academic year (active year first)
 *   Priority 2: Active student_status ('active' first)
 *   Priority 3: Earliest created_at timestamp
 *   Priority 4: Deterministic user_id tiebreaker (lexicographical string comparison)
 *
 * @param array  $a
 * @param array  $b
 * @param string $activeYear
 * @return int Returns negative if $a has higher priority, positive if $b has higher priority, 0 if equal.
 */
function compare_student_canonical_priority(array $a, array $b, string $activeYear): int {
    // 1. Current active year
    $aYear = (($a['pepp_academic_year'] ?? '') === $activeYear) ? 1 : 0;
    $bYear = (($b['pepp_academic_year'] ?? '') === $activeYear) ? 1 : 0;
    if ($aYear !== $bYear) {
        return $bYear <=> $aYear; // 1 before 0
    }

    // 2. Active student_status
    $aStatus = (($a['student_status'] ?? '') === 'active') ? 1 : 0;
    $bStatus = (($b['student_status'] ?? '') === 'active') ? 1 : 0;
    if ($aStatus !== $bStatus) {
        return $bStatus <=> $aStatus; // 1 before 0
    }

    // 3. Earliest created_at timestamp
    $aCreated = !empty($a['created_at']) ? strtotime($a['created_at']) : PHP_INT_MAX;
    $bCreated = !empty($b['created_at']) ? strtotime($b['created_at']) : PHP_INT_MAX;
    if ($aCreated !== $bCreated) {
        return $aCreated <=> $bCreated; // Smaller timestamp (earlier date) first
    }

    // 4. Deterministic user_id tiebreaker (lexicographical string comparison)
    return strcmp((string)($a['user_id'] ?? ''), (string)($b['user_id'] ?? ''));
}

/**
 * Retrieve the single canonical student record for a person identity across the database.
 * Evaluates all matching user records in the system according to the canonical priority chain.
 *
 * @param string $personIdentity
 * @param PDO    $pdo
 * @param string $activeYear
 * @return array|null
 */
function get_canonical_student_record(string $personIdentity, PDO $pdo, string $activeYear): ?array {
    static $cache = [];
    $cacheKey = $personIdentity . '|' . $activeYear;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $candidates = [];
    try {
        if (str_starts_with($personIdentity, 'phone:')) {
            $normPhone = substr($personIdentity, 6);
            $last10 = substr($normPhone, -10);
            $stmt = $pdo->prepare("
                SELECT * FROM users
                WHERE (
                    whatsapp_number LIKE ? OR
                    mobile_number LIKE ?
                )
            ");
            $like = '%' . $last10;
            $stmt->execute([$like, $like]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (str_starts_with($personIdentity, 'email:')) {
            $email = substr($personIdentity, 6);
            $stmt = $pdo->prepare("
                SELECT * FROM users
                WHERE LOWER(TRIM(email)) = ?
            ");
            $stmt->execute([$email]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        return null;
    }

    $matched = [];
    foreach ($candidates as $c) {
        if (resolve_person_identity($c) === $personIdentity) {
            $matched[] = $c;
        }
    }

    if (empty($matched)) {
        return null;
    }

    usort($matched, function($a, $b) use ($activeYear) {
        return compare_student_canonical_priority($a, $b, $activeYear);
    });

    $cache[$cacheKey] = $matched[0];
    return $matched[0];
}

/**
 * Deduplicates student records into unique person identities.
 *
 * For a given list of student records, groups them by their resolved person identity.
 * Within each identity group, the canonical record is chosen according to:
 *   1. Current active academic year
 *   2. Active status ('active' preferred)
 *   3. Earliest created_at timestamp
 *   4. Deterministic user_id tiebreaker
 *
 * If $matchDobDate is provided (e.g. today's date 'Y-m-d'), it additionally verifies
 * against the true canonical database record for that person identity to ensure
 * conflicting/erroneous DOBs on non-canonical records do NOT trigger birthday events.
 *
 * @param array       $students      Array of student associative arrays from users table
 * @param PDO         $pdo           Database connection
 * @param string|null $matchDobDate  Target date ('Y-m-d') to verify canonical DOB against, or null for general dedup
 * @return array                     Array of canonical student records, each containing 'person_identity' and 'duplicate_count'
 */
function resolve_birthday_persons(array $students, PDO $pdo, ?string $matchDobDate = null): array {
    if (empty($students)) {
        return [];
    }

    $activeYear = get_birthday_active_academic_year($pdo) ?? '';

    // Step 1: Group student records by person_identity
    $groups = [];
    foreach ($students as $student) {
        $identity = resolve_person_identity($student);
        if (!$identity) {
            // Cannot resolve phone or email; isolate by user_id to prevent data loss in admin UI
            $identity = 'user:' . ($student['user_id'] ?? uniqid());
        }
        $groups[$identity][] = $student;
    }

    // Step 2: For each group, select the canonical record
    $resolvedPersons = [];
    foreach ($groups as $identity => $groupRecords) {
        if ($matchDobDate !== null && !str_starts_with($identity, 'user:') && !empty($activeYear)) {
            $trueCanonical = get_canonical_student_record($identity, $pdo, $activeYear);
            if ($trueCanonical) {
                // Check if the true canonical record's DOB matches the target date
                if (!birthday_matches_date($trueCanonical['date_of_birth'] ?? '', $matchDobDate)) {
                    // Conflicting DOB: A non-canonical record had DOB today, but the person's true
                    // canonical record has a different birthday. Skip!
                    continue;
                }
                $canonical = $trueCanonical;
            } else {
                usort($groupRecords, function($a, $b) use ($activeYear) {
                    return compare_student_canonical_priority($a, $b, $activeYear);
                });
                $canonical = $groupRecords[0];
            }
        } else {
            usort($groupRecords, function($a, $b) use ($activeYear) {
                return compare_student_canonical_priority($a, $b, $activeYear);
            });
            $canonical = $groupRecords[0];
        }

        $canonical['person_identity'] = $identity;
        $canonical['duplicate_count'] = count($groupRecords);
        $canonical['linked_student_ids'] = array_values(array_unique(array_column($groupRecords, 'user_id')));

        $resolvedPersons[] = $canonical;
    }

    return $resolvedPersons;
}

/**
 * Main birthday notification dispatcher.
 * Dispatches birthday greeting WhatsApp messages to unique active students
 * belonging to the current active academic year whose birthday falls today.
 *
 * Idempotent: safe to call multiple times per day.
 * Uniqueness enforced at (person_identity, birthday_date).
 *
 * @param PDO $pdo
 * @return array
 */
function birthday_dispatch_notifications(PDO $pdo): array {
    static $ran = false;
    if ($ran) return ['skipped' => true, 'reason' => 'already_ran_this_request'];
    $ran = true;

    $result = [
        'dispatched' => 0,
        'skipped'    => 0,
        'errors'     => 0,
        'details'    => []
    ];

    // ── Guard: WhatsApp outbound mode must be meta_api ──────────────────
    $waMode = 'manual';
    try {
        $mStmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'whatsapp_outbound_mode' LIMIT 1");
        $mStmt->execute();
        $waMode = $mStmt->fetchColumn() ?: 'manual';
    } catch (Exception $e) {}
    if ($waMode !== 'meta_api') {
        $result['skipped'] = true;
        $result['reason'] = 'whatsapp_outbound_mode_not_meta_api';
        return $result;
    }

    // ── Guard: Safe daytime hours (08:00 AM - 08:00 PM IST) ─────────────
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $hour = (int)$now->format('H');
    if (($hour < 8 || $hour >= 20) && !defined('FORCE_BIRTHDAY_TEST')) {
        $result['skipped'] = true;
        $result['reason'] = 'outside_safe_hours';
        return $result;
    }

    // ── Guard: Already ran today? (skip on lazy nav calls, allow on cron) ─
    $todayStr = $now->format('Y-m-d');
    if (php_sapi_name() !== 'cli' && !defined('FORCE_BIRTHDAY_TEST')) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'last_birthday_scheduler_run_date' LIMIT 1");
            $stmt->execute();
            $lastRun = $stmt->fetchColumn();
            if ($lastRun === $todayStr) {
                $result['skipped'] = true;
                $result['reason'] = 'already_ran_today';
                return $result;
            }
        } catch (Exception $e) {}
    }

    // ── Guard: Birthday reward system active? ───────────────────────────
    try {
        $activeStmt = $pdo->prepare("SELECT id FROM birthday_reward_settings WHERE is_active = 1 LIMIT 1");
        $activeStmt->execute();
        if (!$activeStmt->fetchColumn()) {
            $result['skipped'] = true;
            $result['reason'] = 'birthday_rewards_inactive';
            return $result;
        }
    } catch (Exception $e) {
        $result['skipped'] = true;
        $result['reason'] = 'birthday_reward_settings_table_missing';
        return $result;
    }

    // ── Guard: Active academic year ──────────────────────────────────────
    $activeYear = get_birthday_active_academic_year($pdo);
    if (!$activeYear) {
        $result['skipped'] = true;
        $result['reason'] = 'no_active_academic_year';
        return $result;
    }

    // ── Find today's birthday candidate students in active academic year ─
    $year = (int)$now->format('Y');
    $month = (int)$now->format('m');
    $day = (int)$now->format('d');

    // Feb 29 policy: on non-leap Feb 28, also include Feb 29 birthdays
    $isLeapYear = (bool)$now->format('L');
    $dobCondition = "MONTH(date_of_birth) = {$month} AND DAY(date_of_birth) = {$day}";
    if ($month === 2 && $day === 28 && !$isLeapYear) {
        $dobCondition = "MONTH(date_of_birth) = 2 AND DAY(date_of_birth) IN (28, 29)";
    }

    try {
        $studentsStmt = $pdo->prepare("
            SELECT user_id, name, date_of_birth, whatsapp_number, whatsapp_country_code,
                   mobile_number, email, pepp_academic_year, status, student_status, created_at
            FROM users
            WHERE status = 'approved'
              AND student_status = 'active'
              AND pepp_academic_year = ?
              AND date_of_birth IS NOT NULL
              AND date_of_birth <> '0000-00-00'
              AND ({$dobCondition})
        ");
        $studentsStmt->execute([$activeYear]);
        $rawStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('birthday_dispatch_notifications: student query failed: ' . $e->getMessage());
        $result['errors']++;
        return $result;
    }

    if (empty($rawStudents)) {
        _birthday_save_run_date($pdo, $todayStr);
        return $result;
    }

    // ── Deduplicate student records into unique persons ─────────────────
    $persons = resolve_birthday_persons($rawStudents, $pdo, $todayStr);
    if (empty($persons)) {
        _birthday_save_run_date($pdo, $todayStr);
        return $result;
    }

    // ── Load CommunicationEngine ────────────────────────────────────────
    $commEngine = CommunicationEngine::getInstance($pdo);
    $insertIgnore = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

    foreach ($persons as $person) {
        $personIdentity = $person['person_identity'];
        $studentId = $person['user_id'];
        $birthdayDate = $todayStr;

        // ── Atomic idempotency: INSERT IGNORE on (person_identity, birthday_date) ──
        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare("
                {$insertIgnore} birthday_notifications_sent (person_identity, student_id, birthday_date, status, created_at)
                VALUES (?, ?, ?, 'queued', NOW())
            ");
            $insertStmt->execute([$personIdentity, $studentId, $birthdayDate]);

            if ($insertStmt->rowCount() === 0) {
                // Already processed today for this person identity
                $pdo->rollBack();
                $result['skipped']++;
                $result['details'][] = ['person_identity' => $personIdentity, 'student_id' => $studentId, 'action' => 'skipped_duplicate'];
                continue;
            }

            // ── Build recipient phone ───────────────────────────────────
            $waPhone = '';
            if (str_starts_with($personIdentity, 'phone:')) {
                $waPhone = substr($personIdentity, 6);
            } else {
                $waPhone = CommunicationEngine::normalizePhone(($person['whatsapp_country_code'] ?? '') . ($person['whatsapp_number'] ?? ''));
                if (empty($waPhone) || strlen($waPhone) < 10) {
                    $waPhone = CommunicationEngine::normalizePhone($person['mobile_number'] ?? ($person['phone'] ?? ''));
                }
            }

            if (empty($waPhone) || strlen($waPhone) < 10) {
                $pdo->prepare("UPDATE birthday_notifications_sent SET status = 'failed' WHERE person_identity = ? AND birthday_date = ?")
                    ->execute([$personIdentity, $birthdayDate]);
                $pdo->commit();
                $result['errors']++;
                $result['details'][] = ['person_identity' => $personIdentity, 'student_id' => $studentId, 'action' => 'failed_no_phone'];
                continue;
            }

            // ── Build template payload ──────────────────────────────────
            $studentName = $person['name'] ?? 'Student';

            $context = [
                'student_uid'  => $studentId,
                'student_name' => $studentName,
            ];

            // Build HMAC claim URL using the canonical student_id
            $hmac = hash_hmac('sha256', $studentId, BIRTHDAY_CLAIM_HMAC_SECRET);
            $claimUrl = "https://pepplearning.in/admissions/birthday-rewards.php/{$studentId}?token={$hmac}";
            $context['claim_url'] = $claimUrl;

            $queueId = $commEngine->sendEventNotification(
                'birthday_greeting',
                $waPhone,
                $context,
                'system_scheduler'
            );

            if ($queueId) {
                $pdo->prepare("UPDATE birthday_notifications_sent SET queue_id = ? WHERE person_identity = ? AND birthday_date = ?")
                    ->execute([$queueId, $personIdentity, $birthdayDate]);
                $pdo->commit();
                $result['dispatched']++;
                $result['details'][] = ['person_identity' => $personIdentity, 'student_id' => $studentId, 'action' => 'queued', 'queue_id' => $queueId];
            } else {
                $pdo->prepare("UPDATE birthday_notifications_sent SET status = 'failed' WHERE person_identity = ? AND birthday_date = ?")
                    ->execute([$personIdentity, $birthdayDate]);
                $pdo->commit();
                $result['errors']++;
                $result['details'][] = ['person_identity' => $personIdentity, 'student_id' => $studentId, 'action' => 'failed_queue'];
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("birthday_dispatch_notifications: Failed for person {$personIdentity}: " . $e->getMessage());
            $result['errors']++;
            $result['details'][] = ['person_identity' => $personIdentity, 'student_id' => $studentId, 'action' => 'exception', 'error' => $e->getMessage()];
        }
    }

    _birthday_save_run_date($pdo, $todayStr);
    return $result;
}

/**
 * Save today's run date to prevent redundant runs on lazy page loads.
 */
function _birthday_save_run_date(PDO $pdo, string $dateStr): void {
    if (php_sapi_name() !== 'cli' && !defined('FORCE_BIRTHDAY_TEST')) {
        try {
            $pdo->prepare("
                INSERT INTO admin_settings (setting_name, setting_value, updated_at)
                VALUES ('last_birthday_scheduler_run_date', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ")->execute([$dateStr]);
        } catch (Exception $e) {}
    }
}
