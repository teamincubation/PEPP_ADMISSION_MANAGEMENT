<?php
/**
 * PEPP Learning ERP — Google Attendance Service
 *
 * Synchronizes participant attendance from Google Meet conference records
 * into the dedicated ERP `session_attendance` table.
 *
 * SPECIFICATION & ARCHITECTURAL INVARIANTS:
 * 1. Authoritative source: Google Meet REST API v2 conference records (never merely Calendar guests).
 * 2. Registered Students: Matched by normalized email against active students in session's enrolled courses.
 *    Marked PRESENT/PARTIAL/JOINED based on duration thresholds, or ABSENT if invited but never joined.
 * 3. Faculty: Separately identified by assigned faculty email / COHOST role.
 *    Recorded with Present/Absent, join/leave times, duration, and Google resource names.
 *    Strictly segregated from student statistics.
 * 4. Unknown / Unregistered Participants: Anyone entering OPEN Meet URL not matching faculty or
 *    registered student is classified as UNKNOWN / UNREGISTERED (`attendance_status = 'unknown/unmatched'`).
 *    Preserves display name, email/synthetic identifier, join/leave times, duration, and resource names.
 * 5. Security & Privacy: No ERP student accounts created for unknown participants.
 *    Unknown participants never exposed in student-facing views (admin/staff/faculty only).
 * 6. Idempotency: Multi-session intervals aggregated per participant. Repeated syncs UPDATE in-place
 *    without duplicate rows across MySQL and SQLite.
 */

declare(strict_types=1);

require_once __DIR__ . '/GoogleWorkspaceClient.php';

class GoogleAttendanceService {
    protected GoogleWorkspaceClient $client;
    protected PDO $pdo;

    public function __construct(PDO $pdo, ?GoogleWorkspaceClient $client = null) {
        $this->pdo = $pdo;
        $this->client = $client ?: new GoogleWorkspaceClient();
    }

    public function getClient(): GoogleWorkspaceClient {
        return $this->client;
    }

    /**
     * Synchronize Google Meet conference attendance for an ERP session.
     *
     * @param int $sessionId
     * @return array{
     *     success: bool,
     *     participants_synced: int,
     *     conference_records_found: int,
     *     error: ?string,
     *     details: array<string, mixed>
     * }
     */
    public function syncSessionAttendance(int $sessionId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return [
                'success' => false,
                'participants_synced' => 0,
                'conference_records_found' => 0,
                'error' => "Session #{$sessionId} not found.",
                'details' => [],
            ];
        }

        $spaceName = (string)($session['google_meet_space_name'] ?? '');
        $meetCode  = (string)($session['google_meet_code'] ?? '');

        if ($spaceName === '' && $meetCode === '') {
            return [
                'success' => false,
                'participants_synced' => 0,
                'conference_records_found' => 0,
                'error' => "Session #{$sessionId} does not have Google Meet space metadata.",
                'details' => [],
            ];
        }

        // 1. Resolve Faculty for this session
        $facultyId = (int)($session['faculty_id'] ?? 0);
        $facInfo = $this->resolveFaculty($facultyId);
        $facultyEmail = strtolower(trim((string)($facInfo['email'] ?? '')));
        $facultyName  = (string)($facInfo['name'] ?? 'Faculty');

        // 2. Resolve Registered Students for this session's courses
        $registeredStudentMap = $this->resolveRegisteredStudentsForSession($sessionId, $session);
        $studentNameMap = [];
        foreach ($registeredStudentMap as $st) {
            $normName = strtolower(trim((string)($st['name'] ?? '')));
            if ($normName !== '' && !isset($studentNameMap[$normName])) {
                $studentNameMap[$normName] = $st;
            }
        }

        // 3. Pre-fetch Space Members to resolve Google user IDs to emails if available
        $spaceMembersMap = $this->fetchSpaceMembersMap($spaceName);

        // 4. Retrieve conference records
        $confRecords = $this->fetchConferenceRecords($spaceName, $meetCode);
        if (empty($confRecords)) {
            return [
                'success' => true,
                'participants_synced' => 0,
                'conference_records_found' => 0,
                'error' => "No conference records found yet. (Meeting may not have started or ended).",
                'details' => ['space_name' => $spaceName, 'meet_code' => $meetCode],
            ];
        }

        $durationHours = (float)($session['duration_hours'] ?? 1.0);
        $totalScheduledSeconds = (int)round($durationHours * 3600);
        $thresholdPercent = $this->getAttendanceThresholdPercent();

        $syncedCount = 0;
        $aggregatedParticipants = [];

        foreach ($confRecords as $cRecord) {
            $recordName = (string)($cRecord['name'] ?? '');
            if ($recordName === '') continue;

            $participants = $this->fetchParticipants($recordName);
            foreach ($participants as $p) {
                $pName = (string)($p['name'] ?? '');
                if ($pName === '') continue;

                $email = $this->extractParticipantEmail($p, $spaceMembersMap);
                $displayName = (string)($p['signedinUser']['displayName'] ?? $p['anonymousUser']['displayName'] ?? $p['phoneUser']['displayName'] ?? '');
                if ($displayName === '' && $email !== '') {
                    $displayName = $email;
                }

                // Stable synthetic email identifier for participants without directory email
                $syntheticEmail = $this->generateSyntheticParticipantEmail($p, $pName);
                $stableKey = $email !== '' ? $email : $syntheticEmail;

                // Fetch participant sessions
                $pSessions = $this->fetchParticipantSessions($pName);

                $firstJoin = null;
                $lastLeave = null;
                $durationSec = 0;

                foreach ($pSessions as $sess) {
                    $startTimeStr = (string)($sess['startTime'] ?? '');
                    $endTimeStr   = (string)($sess['endTime'] ?? '');

                    $sTs = $startTimeStr !== '' ? strtotime($startTimeStr) : false;
                    $eTs = $endTimeStr !== '' ? strtotime($endTimeStr) : false;

                    if ($sTs !== false) {
                        $firstJoin = ($firstJoin === null || $sTs < $firstJoin) ? $sTs : $firstJoin;
                    }
                    if ($eTs !== false) {
                        $lastLeave = ($lastLeave === null || $eTs > $lastLeave) ? $eTs : $lastLeave;
                        if ($sTs !== false && $eTs >= $sTs) {
                            $durationSec += ($eTs - $sTs);
                        }
                    }
                }

                // Fallback duration calculation if sessions was empty but participant had timestamps
                if ($durationSec === 0 && !empty($p['earliestStartTime']) && !empty($p['latestEndTime'])) {
                    $sTs = strtotime((string)$p['earliestStartTime']);
                    $eTs = strtotime((string)$p['latestEndTime']);
                    if ($sTs !== false && $eTs !== false && $eTs >= $sTs) {
                        $firstJoin = $firstJoin ?: $sTs;
                        $lastLeave = $lastLeave ?: $eTs;
                        $durationSec = ($eTs - $sTs);
                    }
                }

                if (!isset($aggregatedParticipants[$stableKey])) {
                    $aggregatedParticipants[$stableKey] = [
                        'email'             => $email,
                        'stable_key'        => $stableKey,
                        'name'              => $displayName ?: 'Guest',
                        'resource'          => $pName,
                        'conference_record' => $recordName,
                        'first_join'        => $firstJoin,
                        'last_leave'        => $lastLeave,
                        'duration'          => $durationSec,
                        'session_count'     => count($pSessions),
                        'raw_sessions'      => $pSessions,
                    ];
                } else {
                    // Aggregate across multiple conference segments
                    $agg = &$aggregatedParticipants[$stableKey];
                    if ($firstJoin !== null && ($agg['first_join'] === null || $firstJoin < $agg['first_join'])) {
                        $agg['first_join'] = $firstJoin;
                    }
                    if ($lastLeave !== null && ($agg['last_leave'] === null || $lastLeave > $agg['last_leave'])) {
                        $agg['last_leave'] = $lastLeave;
                    }
                    $agg['duration'] += $durationSec;
                    $agg['session_count'] += count($pSessions);
                }
            }
        }

        $facultySeen = false;
        $registeredStudentsSeen = [];

        // 5. Upsert aggregated participants into session_attendance
        foreach ($aggregatedParticipants as $p) {
            $email = $p['email'];
            $stableKey = $p['stable_key'];
            $displayName = $p['name'];
            $resource = $p['resource'];
            $firstJoinDt = $p['first_join'] ? date('Y-m-d H:i:s', $p['first_join']) : null;
            $lastLeaveDt = $p['last_leave'] ? date('Y-m-d H:i:s', $p['last_leave']) : null;
            $durationSec = (int)$p['duration'];

            $userId = null;
            $status = 'unknown/unmatched';
            $targetEmail = $email !== '' ? $email : $stableKey;

            // Check A: Is this participant the assigned Faculty?
            $isFaculty = false;
            if ($facultyEmail !== '') {
                if ($email !== '' && $email === $facultyEmail) {
                    $isFaculty = true;
                } elseif ($stableKey === $facultyEmail) {
                    $isFaculty = true;
                } elseif ($displayName !== '' && $facultyName !== '' && strtolower(trim($displayName)) === strtolower(trim($facultyName))) {
                    $isFaculty = true;
                }
            }

            if ($isFaculty) {
                $userId = 'FACULTY';
                $displayName = $displayName ?: $facultyName;
                $targetEmail = $facultyEmail;
                $facultySeen = true;

                if ($durationSec >= ($totalScheduledSeconds * 0.5)) {
                    $status = 'full attendance';
                } elseif ($durationSec > 0) {
                    $status = 'joined';
                } else {
                    $status = 'absent';
                }
            }
            // Check B: Is this participant a Registered Student for this session?
            else {
                $matchedStudent = null;
                if ($email !== '' && isset($registeredStudentMap[$email])) {
                    $matchedStudent = $registeredStudentMap[$email];
                } elseif ($displayName !== '' && isset($studentNameMap[strtolower(trim($displayName))])) {
                    $matchedStudent = $studentNameMap[strtolower(trim($displayName))];
                }

                if ($matchedStudent !== null) {
                    $userId = (string)($matchedStudent['user_id'] ?? '');
                    $displayName = (string)($matchedStudent['name'] ?? $displayName);
                    $targetEmail = (string)$matchedStudent['email'];
                    $registeredStudentsSeen[$targetEmail] = true;

                    if ($durationSec >= ($totalScheduledSeconds * 0.8)) {
                        $status = 'full attendance';
                    } elseif ($durationSec >= ($totalScheduledSeconds * ($thresholdPercent / 100.0))) {
                        $status = 'partial attendance';
                    } elseif ($durationSec > 0) {
                        $status = 'joined';
                    } else {
                        $status = 'absent';
                    }
                }
                // Check C: UNKNOWN / UNREGISTERED PARTICIPANT
                else {
                    $userId = null;
                    $status = 'unknown/unmatched';
                    // Preserve display name & target synthetic email
                    if ($displayName === '' || $displayName === 'Guest') {
                        $displayName = $email !== '' ? $email : 'Unknown Participant';
                    }
                }
            }

            $sessionPayload = json_encode([
                'session_count' => $p['session_count'],
                'first_join' => $firstJoinDt,
                'last_leave' => $lastLeaveDt,
                'stable_key' => $stableKey,
            ]);

            $this->upsertAttendanceRecord(
                $sessionId,
                $userId,
                $displayName,
                $targetEmail,
                $resource,
                $firstJoinDt,
                $lastLeaveDt,
                $durationSec,
                $status,
                'synced',
                (string)$p['conference_record'],
                $sessionPayload
            );
            $syncedCount++;
        }

        // 6. Check if conference has completed or ended
        $hasEndedConference = false;
        if (!empty($confRecords)) {
            foreach ($confRecords as $cr) {
                if (!empty($cr['endTime'])) {
                    $hasEndedConference = true;
                    break;
                }
            }
            if (!$hasEndedConference && count($confRecords) > 0) {
                $hasEndedConference = true;
            }
        }

        $nowTs = time();
        $sessEndTs = strtotime((string)$session['session_datetime']) + $totalScheduledSeconds;
        $nowDt = date('Y-m-d H:i:s');

        // 7. Update invited registered students who never joined to 'absent'
        if ($nowTs > $sessEndTs || $hasEndedConference || ($session['status'] ?? '') === 'completed') {
            foreach ($registeredStudentMap as $stEmail => $stInfo) {
                if (!isset($registeredStudentsSeen[$stEmail])) {
                    $this->upsertAttendanceRecord(
                        $sessionId,
                        $stInfo['user_id'],
                        $stInfo['name'],
                        $stEmail,
                        null,
                        null,
                        null,
                        0,
                        'absent',
                        'synced',
                        $confRecords[0]['name'] ?? null,
                        json_encode(['session_count' => 0, 'attended' => false])
                    );
                }
            }

            // If faculty did not join, record faculty as 'absent'
            if ($facultyEmail !== '' && !$facultySeen) {
                $this->upsertAttendanceRecord(
                    $sessionId,
                    'FACULTY',
                    $facultyName,
                    $facultyEmail,
                    null,
                    null,
                    null,
                    0,
                    'absent',
                    'synced',
                    $confRecords[0]['name'] ?? null,
                    json_encode(['session_count' => 0, 'role' => 'faculty', 'attended' => false])
                );
            }

            // Safety sweep: update any remaining invited records with 0 duration to absent
            $this->pdo->prepare("
                UPDATE session_attendance
                SET attendance_status = 'absent', updated_at = ?
                WHERE session_id = ? AND attendance_status = 'invited' AND total_duration_seconds = 0
            ")->execute([$nowDt, $sessionId]);
        }

        // Update session google_last_sync_at
        $this->pdo->prepare("UPDATE sessions SET google_last_sync_at = ? WHERE id = ?")->execute([$nowDt, $sessionId]);

        return [
            'success' => true,
            'participants_synced' => $syncedCount,
            'conference_records_found' => count($confRecords),
            'error' => null,
            'details' => [
                'session_id' => $sessionId,
                'total_scheduled_seconds' => $totalScheduledSeconds,
                'threshold_percent' => $thresholdPercent,
                'registered_students_evaluated' => count($registeredStudentMap),
                'faculty_evaluated' => $facultyEmail !== '' ? $facultyEmail : 'none',
                'faculty_attended' => $facultySeen,
            ],
        ];
    }

    /**
     * Retrieve structured attendance summary separating Registered Students, Faculty,
     * and Unknown / Unregistered Participants with summary metrics.
     *
     * @param int $sessionId
     * @param bool $includeUnknown Set to false for student callers to prevent data exposure
     * @return array<string, mixed>
     */
    public function getSessionAttendanceSummary(int $sessionId, bool $includeUnknown = true): array {
        $sessStmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $sessStmt->execute([$sessionId]);
        $session = $sessStmt->fetch(PDO::FETCH_ASSOC);

        $facultyId = (int)($session['faculty_id'] ?? 0);
        $facInfo = $this->resolveFaculty($facultyId);
        $facultyEmail = strtolower(trim((string)($facInfo['email'] ?? '')));
        $facultyName  = (string)($facInfo['name'] ?? 'Not assigned');

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.pepp_course, u.name AS user_db_name
            FROM session_attendance a
            LEFT JOIN users u ON u.user_id = a.user_id
            WHERE a.session_id = ?
            ORDER BY 
                CASE 
                    WHEN a.user_id = 'FACULTY' THEN 1
                    WHEN a.attendance_status != 'unknown/unmatched' THEN 2
                    ELSE 3
                END,
                a.google_participant_name ASC
        ");
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $facultyRecord = null;
        $registeredStudents = [];
        $unknownParticipants = [];

        $presentCount = 0;
        $absentCount = 0;
        $unknownCount = 0;

        foreach ($rows as $r) {
            $uId = (string)($r['user_id'] ?? '');
            $pEmail = strtolower(trim((string)($r['google_participant_email'] ?? '')));
            $status = (string)($r['attendance_status'] ?? 'invited');
            $durSec = (int)($r['total_duration_seconds'] ?? 0);
            $durMin = (int)round($durSec / 60);

            $joinTime = !empty($r['first_join_time']) ? date('h:i A', strtotime((string)$r['first_join_time'])) : '-';
            $leaveTime = !empty($r['last_leave_time']) ? date('h:i A', strtotime((string)$r['last_leave_time'])) : '-';

            // 1. Faculty record
            if ($uId === 'FACULTY' || ($facultyEmail !== '' && $pEmail === $facultyEmail)) {
                $isAbsent = ($status === 'absent' || $durSec === 0);
                $facultyRecord = [
                    'name'              => (string)($r['google_participant_name'] ?: $facultyName),
                    'email'             => $facultyEmail ?: $pEmail,
                    'attendance_status' => $isAbsent ? 'Absent' : 'Present',
                    'status_raw'        => $status,
                    'first_join_time'   => $joinTime,
                    'last_leave_time'   => $leaveTime,
                    'duration'          => $durMin . 'm',
                    'duration_seconds'  => $durSec,
                    'resource_name'     => (string)($r['google_participant_resource'] ?? ''),
                ];
                continue;
            }

            // 2. Unknown / Unregistered Participant
            if ($status === 'unknown/unmatched' || ($uId === '' && $status !== 'invited' && $status !== 'absent')) {
                $unknownCount++;
                if ($includeUnknown) {
                    $cleanEmail = $pEmail;
                    if (str_ends_with($cleanEmail, '@meet.google.internal')) {
                        $cleanEmail = 'Not provided';
                    }
                    $unknownParticipants[] = [
                        'name'              => (string)($r['google_participant_name'] ?: 'Unknown Participant'),
                        'email'             => $cleanEmail,
                        'attendance_status' => 'UNKNOWN / UNREGISTERED',
                        'first_join_time'   => $joinTime,
                        'last_leave_time'   => $leaveTime,
                        'duration'          => $durMin . 'm',
                        'duration_seconds'  => $durSec,
                        'resource_name'     => (string)($r['google_participant_resource'] ?? ''),
                    ];
                }
                continue;
            }

            // 3. Registered Student
            if (in_array($status, ['full attendance', 'partial attendance', 'joined'], true)) {
                $presentCount++;
            } else {
                $absentCount++;
            }

            $registeredStudents[] = [
                'user_id'           => $uId,
                'name'              => (string)($r['user_db_name'] ?: $r['google_participant_name'] ?: 'Learner'),
                'email'             => $pEmail,
                'course'            => (string)($r['pepp_course'] ?: '-'),
                'attendance_status' => $status,
                'first_join_time'   => $joinTime,
                'last_leave_time'   => $leaveTime,
                'duration'          => $durMin . 'm',
                'duration_seconds'  => $durSec,
            ];
        }

        // If faculty was assigned but no row in database, default to Absent
        if (!$facultyRecord && $facultyEmail !== '') {
            $facultyRecord = [
                'name'              => $facultyName,
                'email'             => $facultyEmail,
                'attendance_status' => 'Absent',
                'status_raw'        => 'absent',
                'first_join_time'   => '-',
                'last_leave_time'   => '-',
                'duration'          => '0m',
                'duration_seconds'  => 0,
                'resource_name'     => '',
            ];
        }

        return [
            'summary' => [
                'registered_students_count' => count($registeredStudents),
                'present_count'             => $presentCount,
                'absent_count'              => $absentCount,
                'unknown_count'             => $unknownCount,
                'faculty_status'            => $facultyRecord ? $facultyRecord['attendance_status'] : 'Not Assigned',
                'faculty_present'           => ($facultyRecord && $facultyRecord['attendance_status'] === 'Present'),
            ],
            'faculty'               => $facultyRecord,
            'registered_students'    => $registeredStudents,
            'unknown_participants'  => $unknownParticipants,
        ];
    }

    /**
     * Resolve all active students registered for the session's courses.
     *
     * @return array<string, array{user_id: string, name: string, email: string, course: string}>
     */
    public function resolveRegisteredStudentsForSession(int $sessionId, array $session): array {
        $courses = array_filter(array_map('trim', explode(',', (string)($session['course_csv'] ?? ''))));
        if (empty($courses) && !empty($session['course_id'])) {
            try {
                $cStmt = $this->pdo->prepare("SELECT title FROM courses WHERE id = ?");
                $cStmt->execute([(int)$session['course_id']]);
                $cTitle = $cStmt->fetchColumn();
                if ($cTitle) {
                    $courses[] = trim((string)$cTitle);
                }
            } catch (Exception $e) {}
        }

        $studentMap = [];

        // 1. Prioritize existing invited students from session_attendance
        try {
            $attStmt = $this->pdo->prepare("
                SELECT a.user_id, a.google_participant_name, a.google_participant_email, u.pepp_course
                FROM session_attendance a
                LEFT JOIN users u ON u.user_id = a.user_id
                WHERE a.session_id = ? AND a.user_id IS NOT NULL AND a.user_id != 'FACULTY'
            ");
            $attStmt->execute([$sessionId]);
            foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $e = strtolower(trim((string)$row['google_participant_email']));
                if ($e !== '' && !isset($studentMap[$e])) {
                    $studentMap[$e] = [
                        'user_id' => (string)($row['user_id'] ?? ''),
                        'name'    => (string)($row['google_participant_name'] ?: 'Learner'),
                        'email'   => $e,
                        'course'  => (string)($row['pepp_course'] ?: ''),
                    ];
                }
            }
        } catch (Exception $e) {}

        // 2. Supplement with active enrolled students from courses
        if (!empty($courses)) {
            try {
                $placeholders = implode(',', array_fill(0, count($courses), '?'));
                $sql = "
                    SELECT user_id, name, email, pepp_course
                    FROM users
                    WHERE status = 'approved'
                      AND student_status = 'active'
                      AND email IS NOT NULL
                      AND email <> ''
                      AND pepp_course IN ({$placeholders})
                    ORDER BY user_id ASC
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_values($courses));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $e = strtolower(trim((string)$row['email']));
                    if (filter_var($e, FILTER_VALIDATE_EMAIL) && !isset($studentMap[$e])) {
                        $studentMap[$e] = [
                            'user_id' => (string)($row['user_id'] ?? ''),
                            'name'    => (string)($row['name'] ?: 'Learner'),
                            'email'   => $e,
                            'course'  => (string)($row['pepp_course'] ?: ''),
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("GoogleAttendanceService resolveRegisteredStudentsForSession error: " . $e->getMessage());
            }
        }

        return $studentMap;
    }

    /**
     * Resolve faculty details by faculty ID.
     *
     * @return array{name: ?string, email: ?string}
     */
    public function resolveFaculty(int $facultyId): array {
        if ($facultyId <= 0) {
            return ['name' => null, 'email' => null];
        }

        try {
            $stmt = $this->pdo->prepare("SELECT name, email FROM faculties WHERE id = ? LIMIT 1");
            $stmt->execute([$facultyId]);
            $fac = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fac) {
                $email = strtolower(trim((string)($fac['email'] ?? '')));
                return [
                    'name'  => (string)($fac['name'] ?? ''),
                    'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                ];
            }
        } catch (Exception $e) {
            error_log("GoogleAttendanceService resolveFaculty error: " . $e->getMessage());
        }

        return ['name' => null, 'email' => null];
    }

    /**
     * Fetch space members map: user/uid -> email.
     *
     * @return array<string, string>
     */
    protected function fetchSpaceMembersMap(string $spaceName): array {
        if ($spaceName === '') return [];
        $cleanSpace = str_starts_with($spaceName, 'spaces/') ? $spaceName : ('spaces/' . $spaceName);

        $url = 'https://meet.googleapis.com/v2/' . $cleanSpace . '/members';
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_MEET_READONLY]);

        $map = [];
        if ($res['success'] && !empty($res['data']['members'])) {
            foreach ($res['data']['members'] as $m) {
                $email = strtolower(trim((string)($m['email'] ?? '')));
                if ($email !== '') {
                    if (!empty($m['user'])) {
                        $map[(string)$m['user']] = $email;
                    }
                    if (!empty($m['name'])) {
                        $map[(string)$m['name']] = $email;
                    }
                }
            }
        }
        return $map;
    }


    /**
     * Retrieve conference records for a space or meeting code.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchConferenceRecords(string $spaceName, string $meetCode): array {
        $filter = '';
        if ($spaceName !== '') {
            $cleanSpace = str_starts_with($spaceName, 'spaces/') ? $spaceName : ('spaces/' . $spaceName);
            $filter = 'space.name="' . addslashes($cleanSpace) . '"';
        } elseif ($meetCode !== '') {
            $filter = 'space.meeting_code="' . addslashes($meetCode) . '"';
        }

        $url = 'https://meet.googleapis.com/v2/conferenceRecords' . ($filter !== '' ? ('?filter=' . rawurlencode($filter)) : '');
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_MEET_READONLY]);

        if ($res['success'] && !empty($res['data']['conferenceRecords'])) {
            return (array)$res['data']['conferenceRecords'];
        }

        return [];
    }

    /**
     * Fetch participants of a conference record.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchParticipants(string $conferenceRecordName): array {
        $url = 'https://meet.googleapis.com/v2/' . $conferenceRecordName . '/participants';
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_MEET_READONLY]);

        if ($res['success'] && !empty($res['data']['participants'])) {
            return (array)$res['data']['participants'];
        }

        return [];
    }

    /**
     * Fetch participant sessions for a specific participant.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchParticipantSessions(string $participantResourceName): array {
        $url = 'https://meet.googleapis.com/v2/' . $participantResourceName . '/participantSessions';
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_MEET_READONLY]);

        if ($res['success'] && !empty($res['data']['participantSessions'])) {
            return (array)$res['data']['participantSessions'];
        }

        return [];
    }

    /**
     * Extract normalized email from participant payload or space members map.
     */
    protected function extractParticipantEmail(array $participant, array $spaceMembersMap = []): string {
        if (!empty($participant['email']) && filter_var($participant['email'], FILTER_VALIDATE_EMAIL)) {
            return strtolower(trim((string)$participant['email']));
        }
        if (!empty($participant['signedinUser']['user'])) {
            $userRef = (string)$participant['signedinUser']['user'];
            if (filter_var($userRef, FILTER_VALIDATE_EMAIL)) {
                return strtolower(trim($userRef));
            }
            if (isset($spaceMembersMap[$userRef])) {
                return strtolower(trim($spaceMembersMap[$userRef]));
            }
            $cleanUid = str_replace('users/', '', $userRef);
            if (isset($spaceMembersMap[$cleanUid])) {
                return strtolower(trim($spaceMembersMap[$cleanUid]));
            }
        }
        return '';
    }

    /**
     * Generate deterministic synthetic email for participants without directory email.
     */
    protected function generateSyntheticParticipantEmail(array $participant, string $pResource): string {
        if (!empty($participant['signedinUser']['user'])) {
            $userRef = (string)$participant['signedinUser']['user'];
            $cleanUid = preg_replace('/[^0-9a-zA-Z_-]/', '', str_replace('users/', '', $userRef));
            if ($cleanUid !== '') {
                return "uid_{$cleanUid}@meet.google.internal";
            }
        }
        $hash = substr(md5($pResource), 0, 12);
        return "anon_{$hash}@meet.google.internal";
    }

    /**
     * Canonical lookup for an active student by normalized email.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveStudentByEmail(string $email): ?array {
        $norm = strtolower(trim($email));
        if ($norm === '') return null;

        $stmt = $this->pdo->prepare("
            SELECT user_id, name, email, status, student_status, pepp_course
            FROM users
            WHERE LOWER(TRIM(email)) = ? AND status = 'approved' AND student_status = 'active'
            ORDER BY user_id ASC
            LIMIT 1
        ");
        $stmt->execute([$norm]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Configurable attendance threshold percent (defaults to 50%).
     */
    public function getAttendanceThresholdPercent(): int {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'google_attendance_threshold_percent'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && is_numeric($val)) {
                return max(10, min(100, (int)$val));
            }
        } catch (Exception $e) {}
        return 50;
    }

    /**
     * Cross-database idempotent upsert into session_attendance.
     */
    public function upsertAttendanceRecord(
        int $sessionId,
        ?string $userId,
        ?string $participantName,
        ?string $participantEmail,
        ?string $participantResource,
        ?string $firstJoin,
        ?string $lastLeave,
        int $durationSec,
        string $attendanceStatus,
        string $syncStatus,
        ?string $conferenceRecord,
        ?string $sessionDataJson
    ): void {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "
                INSERT INTO session_attendance (
                    session_id, user_id, google_participant_name, google_participant_email,
                    google_participant_resource, first_join_time, last_leave_time, total_duration_seconds,
                    attendance_status, sync_status, google_conference_record, google_participant_session,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ON CONFLICT(session_id, google_participant_email) DO UPDATE SET
                    user_id = COALESCE(excluded.user_id, session_attendance.user_id),
                    google_participant_name = excluded.google_participant_name,
                    google_participant_resource = COALESCE(excluded.google_participant_resource, session_attendance.google_participant_resource),
                    first_join_time = COALESCE(excluded.first_join_time, session_attendance.first_join_time),
                    last_leave_time = COALESCE(excluded.last_leave_time, session_attendance.last_leave_time),
                    total_duration_seconds = excluded.total_duration_seconds,
                    attendance_status = excluded.attendance_status,
                    sync_status = excluded.sync_status,
                    google_conference_record = COALESCE(excluded.google_conference_record, session_attendance.google_conference_record),
                    google_participant_session = COALESCE(excluded.google_participant_session, session_attendance.google_participant_session),
                    updated_at = datetime('now')
            ";
        } else {
            $sql = "
                INSERT INTO session_attendance (
                    session_id, user_id, google_participant_name, google_participant_email,
                    google_participant_resource, first_join_time, last_leave_time, total_duration_seconds,
                    attendance_status, sync_status, google_conference_record, google_participant_session,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    user_id = COALESCE(VALUES(user_id), user_id),
                    google_participant_name = VALUES(google_participant_name),
                    google_participant_resource = COALESCE(VALUES(google_participant_resource), google_participant_resource),
                    first_join_time = COALESCE(VALUES(first_join_time), first_join_time),
                    last_leave_time = COALESCE(VALUES(last_leave_time), last_leave_time),
                    total_duration_seconds = VALUES(total_duration_seconds),
                    attendance_status = VALUES(attendance_status),
                    sync_status = VALUES(sync_status),
                    google_conference_record = COALESCE(VALUES(google_conference_record), google_conference_record),
                    google_participant_session = COALESCE(VALUES(google_participant_session), google_participant_session),
                    updated_at = NOW()
            ";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $sessionId,
            $userId,
            $participantName,
            $participantEmail,
            $participantResource,
            $firstJoin,
            $lastLeave,
            $durationSec,
            $attendanceStatus,
            $syncStatus,
            $conferenceRecord,
            $sessionDataJson,
        ]);
    }
}
