<?php
/**
 * PEPP Learning ERP — Google Attendance Service
 *
 * Synchronizes participant attendance from Google Meet conference records
 * into the dedicated ERP `session_attendance` table.
 *
 * SPECIFICATION & CAPABILITIES:
 * 1. Retrieves conference records via Meet API: conferenceRecords?filter=space.name="..."
 * 2. Retrieves participants and participant sessions.
 * 3. Aggregates multiple join/leave sessions per participant into total duration.
 * 4. Matches participants to canonical active PEPP students by normalized email.
 * 5. Classifies attendance status: 'invited', 'joined', 'partial attendance', 'full attendance', 'absent', 'unknown/unmatched'.
 * 6. Completely idempotent upsert preserving raw timestamps and IDs.
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

        // Retrieve conference records
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

                $email = $this->extractParticipantEmail($p);
                $displayName = (string)($p['signedinUser']['displayName'] ?? $p['anonymousUser']['displayName'] ?? $email);

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

                $key = $email !== '' ? strtolower($email) : ('anon_' . md5($pName));
                if (!isset($aggregatedParticipants[$key])) {
                    $aggregatedParticipants[$key] = [
                        'email'             => $email,
                        'name'              => $displayName,
                        'resource'          => $pName,
                        'conference_record' => $recordName,
                        'first_join'        => $firstJoin,
                        'last_leave'        => $lastLeave,
                        'duration'          => $durationSec,
                        'session_count'     => count($pSessions),
                    ];
                } else {
                    // Aggregate across multiple conference segments
                    $agg = &$aggregatedParticipants[$key];
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

        // Upsert aggregated participants into session_attendance
        foreach ($aggregatedParticipants as $p) {
            $email = $p['email'];
            $displayName = $p['name'];
            $resource = $p['resource'];
            $firstJoinDt = $p['first_join'] ? date('Y-m-d H:i:s', $p['first_join']) : null;
            $lastLeaveDt = $p['last_leave'] ? date('Y-m-d H:i:s', $p['last_leave']) : null;
            $durationSec = (int)$p['duration'];

            // Match user in PEPP ERP database
            $userId = null;
            $status = 'unknown/unmatched';

            if ($email !== '') {
                $user = $this->findActiveStudentByEmail($email);
                if ($user) {
                    $userId = (string)($user['user_id'] ?? '');
                    if ($displayName === '' || $displayName === $email) {
                        $displayName = (string)($user['name'] ?? $displayName);
                    }
                    // Calculate attendance status based on duration threshold
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
            }

            $this->upsertAttendanceRecord(
                $sessionId,
                $userId,
                $displayName,
                $email,
                $resource,
                $firstJoinDt,
                $lastLeaveDt,
                $durationSec,
                $status,
                'synced',
                (string)$p['conference_record'],
                json_encode(['session_count' => $p['session_count']])
            );
            $syncedCount++;
        }

        // If meeting has completed or conference records were returned, update remaining invited students who never joined to 'absent'
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
        if ($nowTs > $sessEndTs || $hasEndedConference) {
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
            ],
        ];
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
     * Extract normalized email from participant payload.
     */
    protected function extractParticipantEmail(array $participant): string {
        if (!empty($participant['signedinUser']['user'])) {
            $userRef = (string)$participant['signedinUser']['user'];
            if (filter_var($userRef, FILTER_VALIDATE_EMAIL)) {
                return strtolower(trim($userRef));
            }
        }
        if (!empty($participant['email']) && filter_var($participant['email'], FILTER_VALIDATE_EMAIL)) {
            return strtolower(trim((string)$participant['email']));
        }
        return '';
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
                    google_participant_resource = excluded.google_participant_resource,
                    first_join_time = excluded.first_join_time,
                    last_leave_time = excluded.last_leave_time,
                    total_duration_seconds = excluded.total_duration_seconds,
                    attendance_status = excluded.attendance_status,
                    sync_status = excluded.sync_status,
                    google_conference_record = excluded.google_conference_record,
                    google_participant_session = excluded.google_participant_session,
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
                    google_participant_resource = VALUES(google_participant_resource),
                    first_join_time = VALUES(first_join_time),
                    last_leave_time = VALUES(last_leave_time),
                    total_duration_seconds = VALUES(total_duration_seconds),
                    attendance_status = VALUES(attendance_status),
                    sync_status = VALUES(sync_status),
                    google_conference_record = VALUES(google_conference_record),
                    google_participant_session = VALUES(google_participant_session),
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
