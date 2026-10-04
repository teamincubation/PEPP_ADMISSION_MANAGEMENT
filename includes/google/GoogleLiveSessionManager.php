<?php
/**
 * PEPP Learning ERP — Google Live Session Orchestrator
 *
 * Coordinates the full scheduling lifecycle for Google-integrated Live Sessions:
 * 1. Active student resolution (canonical users.status = 'approved' AND users.student_status = 'active')
 * 2. Faculty email resolution & validation
 * 3. Unique Calendar event creation with guestsCanSeeOtherGuests = false
 * 4. Google Meet conference generation (conferenceData.createRequest)
 * 5. Google Meet space resolution (spaces/{space})
 * 6. Space settings configuration (OPEN access, moderation, recording, transcripts, smart notes)
 * 7. Faculty COHOST assignment
 * 8. Recording invited attendees into session_attendance
 * 9. Updating sessions table with Google identifiers
 * 10. Resilient retry & error protection
 */

declare(strict_types=1);

require_once __DIR__ . '/GoogleWorkspaceClient.php';
require_once __DIR__ . '/GoogleCalendarService.php';
require_once __DIR__ . '/GoogleMeetService.php';
require_once __DIR__ . '/GoogleAttendanceService.php';
require_once __DIR__ . '/GoogleArtifactService.php';

class GoogleLiveSessionManager {
    protected PDO $pdo;
    protected GoogleWorkspaceClient $client;
    protected GoogleCalendarService $calendarService;
    protected GoogleMeetService $meetService;
    protected GoogleAttendanceService $attendanceService;
    protected GoogleArtifactService $artifactService;

    public function __construct(
        PDO $pdo,
        ?GoogleWorkspaceClient $client = null,
        ?GoogleCalendarService $calendarService = null,
        ?GoogleMeetService $meetService = null,
        ?GoogleAttendanceService $attendanceService = null,
        ?GoogleArtifactService $artifactService = null
    ) {
        $this->pdo = $pdo;
        $this->client = $client ?: new GoogleWorkspaceClient();
        $this->calendarService = $calendarService ?: new GoogleCalendarService($this->client);
        $this->meetService = $meetService ?: new GoogleMeetService($this->client);
        $this->attendanceService = $attendanceService ?: new GoogleAttendanceService($pdo, $this->client);
        $this->artifactService = $artifactService ?: new GoogleArtifactService($pdo, $this->client);
    }

    public function getClient(): GoogleWorkspaceClient {
        return $this->client;
    }

    public function getCalendarService(): GoogleCalendarService {
        return $this->calendarService;
    }

    public function getMeetService(): GoogleMeetService {
        return $this->meetService;
    }

    public function getAttendanceService(): GoogleAttendanceService {
        return $this->attendanceService;
    }

    public function getArtifactService(): GoogleArtifactService {
        return $this->artifactService;
    }

    /**
     * Resolve eligible active students for the given course list.
     *
     * STRICT CANONICAL CRITERIA:
     * - users.status = 'approved'
     * - users.student_status = 'active'
     * - users.pepp_course IN (...)
     * - Non-empty, valid email
     * - Deduplicated by normalized email
     *
     * @param array<string> $courses
     * @return array<int, array{email: string, name: string, user_id: string}>
     */
    public function resolveActiveStudents(array $courses): array {
        $courses = array_filter(array_map('trim', $courses));
        if (empty($courses)) return [];

        try {
            $placeholders = implode(',', array_fill(0, count($courses), '?'));
            $sql = "
                SELECT DISTINCT user_id, name, email
                FROM users
                WHERE status = 'approved'
                  AND student_status = 'active'
                  AND email IS NOT NULL
                  AND email <> ''
                  AND pepp_course IN ({$placeholders})
                ORDER BY user_id ASC, name ASC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($courses));

            $seen = [];
            $students = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $email = strtolower(trim((string)$row['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;
                $students[] = [
                    'email'   => $email,
                    'name'    => (string)($row['name'] ?: 'Learner'),
                    'user_id' => (string)($row['user_id'] ?? ''),
                ];
            }

            return $students;
        } catch (Exception $e) {
            error_log("GoogleLiveSessionManager resolveActiveStudents error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Resolve faculty name and email.
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
            error_log("GoogleLiveSessionManager resolveFaculty error: " . $e->getMessage());
        }

        return ['name' => null, 'email' => null];
    }

    /**
     * Orchestrate Google Calendar + Meet creation for an ERP session.
     *
     * @param int $sessionId
     * @return array{
     *     success: bool,
     *     calendar_event_id: ?string,
     *     meet_uri: ?string,
     *     meet_code: ?string,
     *     meet_space_name: ?string,
     *     invited_count: int,
     *     error: ?string
     * }
     */
    public function provisionGoogleLiveSession(int $sessionId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return [
                'success'           => false,
                'calendar_event_id' => null,
                'meet_uri'          => null,
                'meet_code'         => null,
                'meet_space_name'   => null,
                'invited_count'     => 0,
                'cohost_status'     => 'not_assigned',
                'error'             => "Session #{$sessionId} not found.",
            ];
        }

        // Idempotency: If already synced with event ID, meet space, and meet link, avoid duplicate creation
        if (!empty($session['google_calendar_event_id']) && !empty($session['google_meet_uri']) && !empty($session['google_meet_space_name']) && $session['google_integration_status'] === 'synced') {
            return [
                'success'           => true,
                'calendar_event_id' => $session['google_calendar_event_id'],
                'meet_uri'          => $session['google_meet_uri'],
                'meet_code'         => $session['google_meet_code'],
                'meet_space_name'   => $session['google_meet_space_name'],
                'invited_count'     => 0,
                'cohost_status'     => 'cohost_confirmed',
                'error'             => null,
            ];
        }

        $topic = (string)$session['topic'];
        $dt = (string)$session['session_datetime'];
        $dur = (float)$session['duration_hours'];
        $courses = array_filter(array_map('trim', explode(',', (string)($session['course_csv'] ?? ''))));
        $facultyId = (int)($session['faculty_id'] ?? 0);

        // 1. Resolve Active Students
        $students = $this->resolveActiveStudents($courses);

        // 2. Resolve Faculty
        $facInfo = $this->resolveFaculty($facultyId);
        $facultyEmail = $facInfo['email'];
        $facultyName  = $facInfo['name'];

        // 3. STEP 1: Create or Resolve Native Google Meet Space via POST /v2/spaces
        $spaceName = (string)($session['google_meet_space_name'] ?? '');
        $meetUri   = (string)($session['google_meet_uri'] ?? '');
        $meetCode  = (string)($session['google_meet_code'] ?? '');

        // If not already created on a prior attempt, create a fresh native Meet space with OPEN access
        if (empty($spaceName) || empty($meetUri)) {
            $meetRes = $this->meetService->createSpace('OPEN');
            if (!$meetRes['success'] || empty($meetRes['space_name'])) {
                $errorMsg = "Google Meet space creation failed: " . ($meetRes['error'] ?: 'Unknown error');
                $this->recordFailure($sessionId, $errorMsg);
                return [
                    'success'           => false,
                    'calendar_event_id' => null,
                    'meet_uri'          => null,
                    'meet_code'         => null,
                    'meet_space_name'   => null,
                    'invited_count'     => count($students),
                    'cohost_status'     => 'cohost_pending',
                    'error'             => $errorMsg,
                ];
            }
            $spaceName = (string)($meetRes['space_name'] ?? '');
            $meetUri   = (string)($meetRes['meeting_uri'] ?? $meetRes['meet_uri'] ?? '');
            $meetCode  = (string)($meetRes['meeting_code'] ?? $meetRes['meet_code'] ?? '');
        }

        // 4. STEP 2: Configure Meet Space Settings (moderation=ON, OPEN, auto-recording=ON, transcription=ON, smartNotes=ON)
        $configWarning = null;
        $confRes = $this->meetService->configureSpace($spaceName, 'OPEN');
        if (!$confRes['success'] && !empty($confRes['error'])) {
            $configWarning = $confRes['error'];
            error_log("GoogleLiveSessionManager space config warning for session #{$sessionId}: {$configWarning}");
        }

        // 5. STEP 3 & 4: Add Faculty as COHOST and Verify
        $cohostStatus = 'cohost_pending';
        $cohostError  = null;

        if (!empty($facultyEmail)) {
            $cohostRes = $this->meetService->addFacultyCohost($spaceName, $facultyEmail);
            if (!$cohostRes['success']) {
                $cohostStatus = 'cohost_failed';
                $cohostError  = "Failed to assign faculty co-host: " . ($cohostRes['error'] ?: 'Unknown error');
            } else {
                // Read-back verification: Query space members and confirm role = 'COHOST'
                $verifyRes = $this->meetService->verifyFacultyCohost($spaceName, $facultyEmail);
                if ($verifyRes['success'] && $verifyRes['is_cohost']) {
                    $cohostStatus = 'cohost_confirmed';
                } else {
                    $cohostStatus = 'cohost_failed';
                    $cohostError  = "Faculty co-host verification failed: " . ($verifyRes['error'] ?: "Role is '{$verifyRes['role']}', expected 'COHOST'");
                }
            }
        } else {
            $cohostStatus = 'cohost_pending';
            $cohostError  = "No faculty email configured for co-host assignment";
        }

        // If co-host assignment or verification failed, do NOT mark integration as synced!
        if ($cohostStatus === 'cohost_failed') {
            $errorMsg = "Google Meet Co-Host setup failed: {$cohostError}";
            $this->recordPartialOrFailedState($sessionId, $spaceName, $meetUri, $meetCode, null, 'failed', $errorMsg);
            return [
                'success'           => false,
                'calendar_event_id' => null,
                'meet_uri'          => $meetUri,
                'meet_code'         => $meetCode,
                'meet_space_name'   => $spaceName,
                'invited_count'     => count($students),
                'cohost_status'     => $cohostStatus,
                'error'             => $errorMsg,
            ];
        }

        // 6. STEP 5: Create Google Calendar Event USING THE NATIVE MEET SPACE
        $eventId = null;
        if (!empty($session['google_calendar_event_id'])) {
            $existingEvt = $this->calendarService->getEvent((string)$session['google_calendar_event_id']);
            if ($existingEvt['success'] && !empty($existingEvt['data'])) {
                $eventId = (string)$session['google_calendar_event_id'];
            }
        }

        if (!$eventId) {
            $calRes = $this->calendarService->createLiveSessionEvent(
                $sessionId,
                $topic,
                $dt,
                $dur,
                $students,
                $facultyEmail,
                $facultyName,
                $courses,
                $meetUri,
                $meetCode
            );

            if (!$calRes['success'] || empty($calRes['event_id'])) {
                $errorMsg = "Calendar event creation failed: " . ($calRes['error'] ?: 'Unknown error');
                $this->recordPartialOrFailedState($sessionId, $spaceName, $meetUri, $meetCode, null, 'failed', $errorMsg);
                return [
                    'success'           => false,
                    'calendar_event_id' => null,
                    'meet_uri'          => $meetUri,
                    'meet_code'         => $meetCode,
                    'meet_space_name'   => $spaceName,
                    'invited_count'     => count($students),
                    'cohost_status'     => $cohostStatus,
                    'error'             => $errorMsg,
                ];
            }
            $eventId = $calRes['event_id'];
        }

        // 7. Record invited students into session_attendance
        $this->recordInvitedAttendees($sessionId, $students);

        // 8. Commit Google Metadata to sessions table with 'synced' status
        $updateSql = "
            UPDATE sessions
            SET google_integrated = 1,
                google_calendar_event_id = ?,
                google_calendar_id = 'primary',
                google_meet_space_name = ?,
                google_meet_uri = ?,
                google_meet_code = ?,
                meet_link = COALESCE(?, meet_link),
                google_integration_status = 'synced',
                google_last_sync_at = NOW(),
                google_error_message = ?
            WHERE id = ?
        ";
        $stmt = $this->pdo->prepare($updateSql);
        $stmt->execute([
            $eventId,
            $spaceName,
            $meetUri,
            $meetCode,
            $meetUri,
            $configWarning,
            $sessionId,
        ]);

        try {
            $hasCohostCol = (bool)$this->pdo->query("SHOW COLUMNS FROM sessions LIKE 'google_cohost_status'")->fetchColumn();
            if ($hasCohostCol) {
                $this->pdo->prepare("UPDATE sessions SET google_cohost_status = ? WHERE id = ?")->execute([$cohostStatus, $sessionId]);
            }
        } catch (Exception $e) {}

        return [
            'success'           => true,
            'calendar_event_id' => $eventId,
            'meet_uri'          => $meetUri,
            'meet_code'         => $meetCode,
            'meet_space_name'   => $spaceName,
            'invited_count'     => count($students),
            'cohost_status'     => $cohostStatus,
            'error'             => null,
        ];
    }

    /**
     * Record invited students into session_attendance.
     *
     * @param array<int, array{email: string, name: string, user_id: string}> $students
     */
    protected function recordInvitedAttendees(int $sessionId, array $students): void {
        foreach ($students as $st) {
            $this->attendanceService->upsertAttendanceRecord(
                $sessionId,
                $st['user_id'],
                $st['name'],
                $st['email'],
                null,
                null,
                null,
                0,
                'invited',
                'synced',
                null,
                null
            );
        }
    }

    /**
     * Update an existing Google Live Session:
     * - Preserves existing Google Calendar event ID and Meet room
     * - Updates Calendar event times, topic, description, and attendees with sendUpdates=all
     * - Recalculates active student invitation list if courses changed
     * - Configures new faculty as COHOST if faculty changed
     * - Updates sessions table in database
     *
     * @param int $sessionId
     * @param array{
     *     topic: string,
     *     session_datetime: string,
     *     duration_hours: float,
     *     faculty_id: int,
     *     courses: array<string>,
     *     venue?: ?string,
     *     status?: string,
     *     meet_link?: ?string
     * } $data
     * @return array{
     *     success: bool,
     *     calendar_event_id: ?string,
     *     meet_uri: ?string,
     *     meet_code: ?string,
     *     invited_count: int,
     *     error: ?string
     * }
     */
    public function updateGoogleLiveSession(int $sessionId, array $data): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return [
                'success' => false,
                'calendar_event_id' => null,
                'meet_uri' => null,
                'meet_code' => null,
                'invited_count' => 0,
                'error' => "Session #{$sessionId} not found.",
            ];
        }

        $topic = trim((string)($data['topic'] ?? $session['topic']));
        $dt = trim((string)($data['session_datetime'] ?? $session['session_datetime']));
        $dur = (float)($data['duration_hours'] ?? $session['duration_hours']);
        $facultyId = (int)($data['faculty_id'] ?? $session['faculty_id']);
        $courses = array_filter(array_map('trim', (array)($data['courses'] ?? explode(',', (string)($session['course_csv'] ?? '')))));
        $venue = trim((string)($data['venue'] ?? $session['venue'])) ?: null;
        $status = in_array($data['status'] ?? '', ['scheduled', 'completed', 'cancelled'], true) ? $data['status'] : ($session['status'] ?? 'scheduled');

        $eventId = (string)($session['google_calendar_event_id'] ?? '');

        // If no Calendar event exists yet, provision from scratch
        if ($eventId === '') {
            $updateSql = "UPDATE sessions SET topic=?, faculty_id=?, session_datetime=?, duration_hours=?, course_csv=?, venue=?, status=? WHERE id=?";
            $this->pdo->prepare($updateSql)->execute([$topic, $facultyId ?: null, $dt, $dur, implode(',', $courses) ?: null, $venue, $status, $sessionId]);
            return $this->provisionGoogleLiveSession($sessionId);
        }

        // 1. Resolve Active Students for updated courses
        $students = $this->resolveActiveStudents($courses);

        // 2. Resolve Faculty
        $facInfo = $this->resolveFaculty($facultyId);
        $facultyEmail = $facInfo['email'];
        $facultyName  = $facInfo['name'];

        // 3. If faculty changed, configure new faculty as COHOST on the existing Meet space
        $spaceName = (string)($session['google_meet_space_name'] ?? '');
        if ($facultyEmail && $spaceName !== '' && (int)($session['faculty_id'] ?? 0) !== $facultyId) {
            $cohostRes = $this->meetService->addFacultyCohost($spaceName, $facultyEmail);
            if ($cohostRes['success']) {
                $this->meetService->verifyFacultyCohost($spaceName, $facultyEmail);
            } else {
                error_log("GoogleLiveSessionManager update cohost warning for session #{$sessionId}: " . $cohostRes['error']);
            }
        }

        // 4. Update existing Google Calendar event with sendUpdates=all
        $calRes = $this->calendarService->updateLiveSessionEvent(
            $eventId,
            $sessionId,
            $topic,
            $dt,
            $dur,
            $students,
            $facultyEmail,
            $facultyName,
            $courses
        );

        if (!$calRes['success']) {
            $errorMsg = $calRes['error'] ?: 'Google Calendar event update failed';
            // Compensating update in ERP: record failure status without losing user's form edits
            $upSql = "
                UPDATE sessions
                SET topic = ?, faculty_id = ?, session_datetime = ?, duration_hours = ?,
                    venue = ?, course_csv = ?, status = ?,
                    google_integration_status = 'failed',
                    google_error_message = ?,
                    google_last_sync_at = NOW()
                WHERE id = ?
            ";
            $this->pdo->prepare($upSql)->execute([
                $topic, $facultyId ?: null, $dt, $dur, $venue, implode(',', $courses) ?: null, $status, $errorMsg, $sessionId
            ]);

            return [
                'success'           => false,
                'calendar_event_id' => $eventId,
                'meet_uri'          => $session['google_meet_uri'],
                'meet_code'         => $session['google_meet_code'],
                'invited_count'     => count($students),
                'error'             => $errorMsg,
            ];
        }

        // 5. Reconcile session_attendance for course changes:
        // A. Upsert newly eligible active students
        $this->recordInvitedAttendees($sessionId, $students);

        // B. Remove stale 'invited' records for students who are no longer eligible
        $eligibleEmails = array_values(array_filter(array_map(function($st) {
            return strtolower(trim((string)($st['email'] ?? '')));
        }, $students)));

        if (!empty($eligibleEmails)) {
            $inClause = implode(',', array_fill(0, count($eligibleEmails), '?'));
            $purgeSql = "DELETE FROM session_attendance 
                         WHERE session_id = ? 
                           AND attendance_status = 'invited' 
                           AND LOWER(google_participant_email) NOT IN ({$inClause})";
            $purgeParams = array_merge([$sessionId], $eligibleEmails);
            $this->pdo->prepare($purgeSql)->execute($purgeParams);
        } else {
            $this->pdo->prepare("DELETE FROM session_attendance WHERE session_id = ? AND attendance_status = 'invited'")
                      ->execute([$sessionId]);
        }

        // 6. Update database record with synced status
        $meetUri = $calRes['meet_uri'] ?: ($session['google_meet_uri'] ?? null);
        $meetCode = $calRes['meet_code'] ?: ($session['google_meet_code'] ?? null);

        $upSql = "
            UPDATE sessions
            SET topic = ?,
                faculty_id = ?,
                session_datetime = ?,
                duration_hours = ?,
                session_type = 'live',
                venue = ?,
                course_csv = ?,
                status = ?,
                meet_link = COALESCE(?, meet_link),
                google_meet_uri = COALESCE(?, google_meet_uri),
                google_meet_code = COALESCE(?, google_meet_code),
                google_integration_status = 'synced',
                google_error_message = NULL,
                google_last_sync_at = NOW()
            WHERE id = ?
        ";
        $this->pdo->prepare($upSql)->execute([
            $topic,
            $facultyId ?: null,
            $dt,
            $dur,
            $venue,
            implode(',', $courses) ?: null,
            $status,
            $meetUri,
            $meetUri,
            $meetCode,
            $sessionId
        ]);

        return [
            'success'           => true,
            'calendar_event_id' => $eventId,
            'meet_uri'          => $meetUri,
            'meet_code'         => $meetCode,
            'invited_count'     => count($students),
            'error'             => null,
        ];
    }

    /**
     * Delete an existing Google Live Session:
     * - Cancels/deletes the Google Calendar event with sendUpdates=all
     * - Removes associated session_attendance, session_google_artifacts, and session rows
     *
     * @param int $sessionId
     * @return array{success: bool, error: ?string}
     */
    public function deleteGoogleLiveSession(int $sessionId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return ['success' => true, 'error' => null];
        }

        $eventId = (string)($session['google_calendar_event_id'] ?? '');
        if ($eventId !== '') {
            $delRes = $this->calendarService->deleteEvent($eventId);
            if (!$delRes['success']) {
                $errorMsg = $delRes['error'] ?: "Failed to delete Google Calendar event {$eventId}";
                // Record error in sessions table
                $this->recordFailure($sessionId, $errorMsg);
                return ['success' => false, 'error' => $errorMsg];
            }
        }

        // Clean up database tables
        try {
            $this->pdo->prepare("DELETE FROM session_attendance WHERE session_id = ?")->execute([$sessionId]);
            $this->pdo->prepare("DELETE FROM session_google_artifacts WHERE session_id = ?")->execute([$sessionId]);
            $this->pdo->prepare("DELETE FROM session_notifications WHERE session_id = ?")->execute([$sessionId]);
            $this->pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$sessionId]);
            return ['success' => true, 'error' => null];
        } catch (Exception $e) {
            error_log("Database error during session deletion: " . $e->getMessage());
            return ['success' => false, 'error' => "Database error deleting session: " . $e->getMessage()];
        }
    }

    /**
     * Programmatically end an active Google Meet live session conference.
     * Ejects participants, ends active conference record, updates session status to completed,
     * and triggers automatic attendance synchronization.
     *
     * @param int $sessionId
     * @return array{
     *     success: bool,
     *     error: ?string,
     *     conference_ended: bool,
     *     attendance_synced: int
     * }
     */
    public function endGoogleLiveSession(int $sessionId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return [
                'success'           => false,
                'error'             => "Session #{$sessionId} not found.",
                'conference_ended'  => false,
                'attendance_synced' => 0,
            ];
        }

        if (empty($session['google_integrated'])) {
            return [
                'success'           => false,
                'error'             => "Session #{$sessionId} is not Google-integrated.",
                'conference_ended'  => false,
                'attendance_synced' => 0,
            ];
        }

        $spaceName = (string)($session['google_meet_space_name'] ?? '');
        if (empty($spaceName)) {
            return [
                'success'           => false,
                'error'             => "Session #{$sessionId} does not have a Google Meet space name.",
                'conference_ended'  => false,
                'attendance_synced' => 0,
            ];
        }

        // Call endActiveConference on Google Meet Service
        $endRes = $this->meetService->endActiveConference($spaceName);
        if (!$endRes['success']) {
            return [
                'success'           => false,
                'error'             => $endRes['error'],
                'conference_ended'  => false,
                'attendance_synced' => 0,
            ];
        }

        // Update session status in ERP database
        $nowDt = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            UPDATE sessions
            SET status = 'completed',
                google_last_sync_at = ?
            WHERE id = ?
        ")->execute([$nowDt, $sessionId]);

        // Automatically trigger attendance synchronization if conference record is available
        $participantsSynced = 0;
        try {
            $attRes = $this->attendanceService->syncSessionAttendance($sessionId);
            if ($attRes['success']) {
                $participantsSynced = (int)($attRes['participants_synced'] ?? 0);
            }
        } catch (Exception $attEx) {
            error_log("Attendance sync notice following endActiveConference: " . $attEx->getMessage());
        }

        // Automatically trigger artifact synchronization (recordings, transcripts, Gemini notes)
        $artifactsSynced = 0;
        try {
            $artRes = $this->artifactService->syncSessionArtifacts($sessionId);
            if ($artRes['success']) {
                $artifactsSynced = (int)($artRes['artifacts_synced'] ?? 0);
            }
        } catch (Exception $artEx) {
            error_log("Artifact sync notice following endActiveConference: " . $artEx->getMessage());
        }

        return [
            'success'           => true,
            'error'             => null,
            'conference_ended'  => true,
            'attendance_synced' => $participantsSynced,
            'artifacts_synced'  => $artifactsSynced,
        ];
    }

    /**
     * Retrieve complete session details for the admin view modal:
     * - Section 1: Session Details
     * - Section 2: Faculty Information & Cohost status
     * - Section 3: Courses Information
     * - Section 4: Invited Students (Names, Courses, Status — NO email exposure)
     * - Section 5: Google Integration Details
     * - Attendance Summary (Registered Students, Faculty, Unknown Participants)
     * - Google Meet Artifacts (Recording, Transcript, Gemini Notes)
     *
     * @param int $sessionId
     * @return array<string, mixed>
     */
    public function getSessionDetails(int $sessionId): array {
        $stmt = $this->pdo->prepare("
            SELECT s.*, f.name AS faculty_name, f.email AS faculty_email
            FROM sessions s
            LEFT JOIN faculties f ON f.id = s.faculty_id
            WHERE s.id = ?
        ");
        $stmt->execute([$sessionId]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sess) {
            return ['success' => false, 'error' => "Session #{$sessionId} not found."];
        }

        $startTs = strtotime((string)$sess['session_datetime']);
        $durHours = (float)$sess['duration_hours'];
        $endTs = $startTs ? ($startTs + (int)round($durHours * 3600)) : null;

        $courses = array_filter(array_map('trim', explode(',', (string)($sess['course_csv'] ?? ''))));

        // Fetch invited students from session_attendance joined with users table
        // Strictly displays Name, Course, Attendance status WITHOUT exposing student email addresses
        $studentsStmt = $this->pdo->prepare("
            SELECT 
                a.google_participant_name AS name,
                COALESCE(u.pepp_course, 'Assigned Batch') AS course,
                a.attendance_status,
                a.total_duration_seconds,
                a.first_join_time,
                a.last_leave_time
            FROM session_attendance a
            LEFT JOIN users u ON u.user_id = a.user_id
            WHERE a.session_id = ?
              AND (a.user_id IS NULL OR a.user_id != 'FACULTY')
              AND a.attendance_status != 'unknown/unmatched'
            ORDER BY a.google_participant_name ASC
        ");
        $studentsStmt->execute([$sessionId]);
        $invitedStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch structured attendance summary
        $attSummary = $this->attendanceService->getSessionAttendanceSummary($sessionId, true);

        // Fetch artifacts summary
        $artSummary = $this->artifactService->getSessionArtifactsSummary($sessionId);

        // Faculty cohost status: check database and integration status
        $cohostStatus = 'Not Assigned';
        if (!empty($sess['google_integrated'])) {
            $gStatus = (string)($sess['google_integration_status'] ?? '');
            $gError = (string)($sess['google_error_message'] ?? '');

            if ($gStatus === 'synced') {
                $cohostStatus = 'Co-host Confirmed';
            } elseif ($gStatus === 'failed' || str_contains(strtolower($gError), 'co-host') || str_contains(strtolower($gError), 'cohost')) {
                $cohostStatus = 'Co-host Setup Failed';
            } elseif (!empty($sess['faculty_email'])) {
                $cohostStatus = 'Co-host Pending';
            } elseif (!empty($sess['faculty_name'])) {
                $cohostStatus = 'Pending Email Configuration';
            }
        }

        return [
            'success' => true,
            'session' => [
                'id'            => (int)$sess['id'],
                'topic'         => (string)$sess['topic'],
                'date'          => $startTs ? date('d M Y (D)', $startTs) : '-',
                'start_time'    => $startTs ? date('h:i A', $startTs) : '-',
                'end_time'      => $endTs ? date('h:i A', $endTs) : '-',
                'duration'      => rtrim(rtrim(number_format($durHours, 2), '0'), '.') . ' hr(s)',
                'session_type'  => strtoupper((string)$sess['session_type']),
                'status'        => ucfirst((string)$sess['status']),
                'created_by'    => (string)($sess['created_by'] ?? 'Admin'),
            ],
            'faculty' => [
                'name'          => (string)($sess['faculty_name'] ?? 'Not assigned'),
                'email'         => (string)($sess['faculty_email'] ?? ''),
                'cohost_status' => $cohostStatus,
            ],
            'courses' => [
                'list'          => array_values($courses),
                'count'         => count($courses),
            ],
            'invited_students' => array_map(function($st) {
                return [
                    'name'     => (string)($st['name'] ?: 'Learner'),
                    'course'   => (string)($st['course'] ?: '-'),
                    'status'   => (string)($st['attendance_status'] ?: 'invited'),
                    'duration' => round(((int)($st['total_duration_seconds'] ?? 0)) / 60) . 'm',
                ];
            }, $invitedStudents),
            'attendance_summary'   => $attSummary['summary'],
            'faculty_attendance'   => $attSummary['faculty'],
            'registered_students'  => $attSummary['registered_students'],
            'unknown_participants' => $attSummary['unknown_participants'],
            'artifacts_summary'    => $artSummary,
            'google' => [
                'is_integrated'          => !empty($sess['google_integrated']),
                'meet_uri'               => (string)($sess['google_meet_uri'] ?? $sess['meet_link'] ?? ''),
                'meet_code'              => (string)($sess['google_meet_code'] ?? ''),
                'meet_space_name'        => (string)($sess['google_meet_space_name'] ?? ''),
                'calendar_event_id'      => (string)($sess['google_calendar_event_id'] ?? ''),
                'calendar_id'            => (string)($sess['google_calendar_id'] ?? 'primary'),
                'integration_status'     => (string)($sess['google_integration_status'] ?? 'none'),
                'cohost_status'          => $cohostStatus,
                'meet_space_created'     => !empty($sess['google_meet_space_name']),
                'cohost_confirmed'       => ($cohostStatus === 'Co-host Confirmed'),
                'calendar_event_created' => !empty($sess['google_calendar_event_id']),
                'last_sync_at'           => (string)($sess['google_last_sync_at'] ?? ''),
                'error_message'          => (string)($sess['google_error_message'] ?? ''),
            ],
        ];
    }

    /**
     * Record failure state in sessions table without throwing unhandled exception.
     */
    protected function recordFailure(int $sessionId, string $errorMessage): void {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sessions
                SET google_integration_status = 'failed',
                    google_error_message = ?,
                    google_last_sync_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$errorMessage, $sessionId]);
        } catch (Exception $e) {
            error_log("Failed to record Google session failure: " . $e->getMessage());
        }
    }

    /**
     * Record partial or failed state preserving resolved Meet space and/or Calendar IDs.
     */
    public function recordPartialOrFailedState(
        int $sessionId,
        ?string $spaceName,
        ?string $meetUri,
        ?string $meetCode,
        ?string $eventId,
        string $status,
        string $errorMessage
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sessions
                SET google_integrated = 1,
                    google_meet_space_name = COALESCE(?, google_meet_space_name),
                    google_meet_uri = COALESCE(?, google_meet_uri),
                    google_meet_code = COALESCE(?, google_meet_code),
                    google_calendar_event_id = COALESCE(?, google_calendar_event_id),
                    meet_link = COALESCE(?, meet_link),
                    google_integration_status = ?,
                    google_error_message = ?,
                    google_last_sync_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $spaceName,
                $meetUri,
                $meetCode,
                $eventId,
                $meetUri,
                $status,
                $errorMessage,
                $sessionId
            ]);

            try {
                $hasCohostCol = (bool)$this->pdo->query("SHOW COLUMNS FROM sessions LIKE 'google_cohost_status'")->fetchColumn();
                if ($hasCohostCol) {
                    $coStatus = str_contains(strtolower($errorMessage), 'co-host') ? 'cohost_failed' : 'cohost_pending';
                    $this->pdo->prepare("UPDATE sessions SET google_cohost_status = ? WHERE id = ?")->execute([$coStatus, $sessionId]);
                }
            } catch (Exception $e) {}
        } catch (Exception $e) {
            error_log("Failed to record Google session partial/failed state: " . $e->getMessage());
        }
    }
}
