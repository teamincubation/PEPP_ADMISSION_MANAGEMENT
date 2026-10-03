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
 * 6. Space settings configuration (RESTRICTED, moderation, recording, transcripts, smart notes)
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
                ORDER BY name ASC
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
                'success' => false,
                'calendar_event_id' => null,
                'meet_uri' => null,
                'meet_code' => null,
                'meet_space_name' => null,
                'invited_count' => 0,
                'error' => "Session #{$sessionId} not found.",
            ];
        }

        // Idempotency: If already synced with event ID and meet link, avoid duplicate event creation
        if (!empty($session['google_calendar_event_id']) && !empty($session['google_meet_uri']) && $session['google_integration_status'] === 'synced') {
            return [
                'success' => true,
                'calendar_event_id' => $session['google_calendar_event_id'],
                'meet_uri' => $session['google_meet_uri'],
                'meet_code' => $session['google_meet_code'],
                'meet_space_name' => $session['google_meet_space_name'],
                'invited_count' => 0,
                'error' => null,
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

        // 3. Resolve or Create Google Calendar Event with Meet conferenceData
        $eventId  = null;
        $meetUri  = null;
        $meetCode = null;

        // If an event ID is already linked to this session, attempt to retrieve it to avoid duplicate events on retry
        if (!empty($session['google_calendar_event_id'])) {
            $existingEvt = $this->calendarService->getEvent((string)$session['google_calendar_event_id']);
            if ($existingEvt['success'] && !empty($existingEvt['data'])) {
                $eventId = (string)$session['google_calendar_event_id'];
                $confData = $this->calendarService->extractConferenceData($existingEvt['data']);
                $meetUri = $confData['meet_uri'] ?: ($session['google_meet_uri'] ?? null);
                $meetCode = $confData['meet_code'] ?: ($session['google_meet_code'] ?? null);
            }
        }

        // If not already existing or retrieval failed, create a fresh Calendar event
        if (!$eventId) {
            $calRes = $this->calendarService->createLiveSessionEvent(
                $sessionId,
                $topic,
                $dt,
                $dur,
                $students,
                $facultyEmail,
                $facultyName,
                $courses
            );

            if (!$calRes['success'] || empty($calRes['event_id'])) {
                $errorMsg = $calRes['error'] ?: 'Calendar event creation failed';
                $this->recordFailure($sessionId, $errorMsg);
                return [
                    'success' => false,
                    'calendar_event_id' => null,
                    'meet_uri' => null,
                    'meet_code' => null,
                    'meet_space_name' => null,
                    'invited_count' => count($students),
                    'error' => $errorMsg,
                ];
            }

            $eventId  = $calRes['event_id'];
            $meetUri  = $calRes['meet_uri'];
            $meetCode = $calRes['meet_code'];
        }

        $spaceName = null;
        $configWarning = null;

        // 4. Resolve Meet Space Resource
        if ($meetCode || $meetUri) {
            $spaceRes = $this->meetService->resolveSpace($meetCode ?: $meetUri);
            if ($spaceRes['success'] && !empty($spaceRes['space_name'])) {
                $spaceName = $spaceRes['space_name'];
            }
        }

        if (!$spaceName && $meetCode) {
            $spaceName = 'spaces/' . $meetCode;
        }

        // 5. Configure Space Settings (RESTRICTED, moderation, auto-record, transcription, smart notes)
        if ($spaceName) {
            $confRes = $this->meetService->configureSpace($spaceName);
            if (!$confRes['success'] && !empty($confRes['error'])) {
                $configWarning = $confRes['error'];
                error_log("GoogleLiveSessionManager space config warning for session #{$sessionId}: {$configWarning}");
            }

            // 6. Add Faculty as COHOST
            if ($facultyEmail) {
                $cohostRes = $this->meetService->addFacultyCohost($spaceName, $facultyEmail);
                if (!$cohostRes['success'] && !empty($cohostRes['error'])) {
                    error_log("GoogleLiveSessionManager cohost warning for session #{$sessionId}: " . $cohostRes['error']);
                }
            }
        }

        // 7. Record invited students into session_attendance
        $this->recordInvitedAttendees($sessionId, $students);

        // 8. Commit Google Metadata to sessions table
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

        return [
            'success' => true,
            'calendar_event_id' => $eventId,
            'meet_uri' => $meetUri,
            'meet_code' => $meetCode,
            'meet_space_name' => $spaceName,
            'invited_count' => count($students),
            'error' => null,
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
            if (!$cohostRes['success'] && !empty($cohostRes['error'])) {
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
     * Retrieve complete session details for the admin view modal:
     * - Section 1: Session Details
     * - Section 2: Faculty Information & Cohost status
     * - Section 3: Courses Information
     * - Section 4: Invited Students (Names, Courses, Status — NO email exposure)
     * - Section 5: Google Integration Details
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
            ORDER BY a.google_participant_name ASC
        ");
        $studentsStmt->execute([$sessionId]);
        $invitedStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Faculty cohost status: if session is Google integrated and faculty has a valid email
        $cohostStatus = 'Not Assigned';
        if (!empty($sess['google_integrated'])) {
            if (!empty($sess['faculty_email'])) {
                $cohostStatus = 'Co-host Configured';
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
            'google' => [
                'is_integrated'      => !empty($sess['google_integrated']),
                'meet_uri'           => (string)($sess['google_meet_uri'] ?? $sess['meet_link'] ?? ''),
                'meet_code'          => (string)($sess['google_meet_code'] ?? ''),
                'meet_space_name'    => (string)($sess['google_meet_space_name'] ?? ''),
                'calendar_event_id'  => (string)($sess['google_calendar_event_id'] ?? ''),
                'integration_status' => (string)($sess['google_integration_status'] ?? 'none'),
                'last_sync_at'       => (string)($sess['google_last_sync_at'] ?? ''),
                'error_message'      => (string)($sess['google_error_message'] ?? ''),
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
}
