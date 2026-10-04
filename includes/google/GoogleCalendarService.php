<?php
/**
 * PEPP Learning ERP — Google Calendar Service
 *
 * Manages Google Calendar event creation, attendee management, and Google Meet
 * conference generation for PEPP ERP Live Sessions.
 *
 * CRITICAL REQUIREMENTS:
 * 1. guestsCanSeeOtherGuests = false (Student guest list privacy is strictly enforced)
 * 2. guestsCanInviteOthers = false
 * 3. guestsCanModify = false
 * 4. Unique Google Meet conference created via conferenceData.createRequest
 * 5. Handles asynchronous pending conference creation states.
 * 6. Configures requested organizer reminders (24h, 12h, 1h, 10m, 0m).
 * 7. sendUpdates = 'all' so Google Calendar sends the invitation directly.
 */

declare(strict_types=1);

require_once __DIR__ . '/GoogleWorkspaceClient.php';

class GoogleCalendarService {
    protected GoogleWorkspaceClient $client;
    protected string $calendarId;

    public function __construct(?GoogleWorkspaceClient $client = null, string $calendarId = 'primary') {
        $this->client = $client ?: new GoogleWorkspaceClient();
        $this->calendarId = $calendarId;
    }

    public function getClient(): GoogleWorkspaceClient {
        return $this->client;
    }

    public function getCalendarId(): string {
        return $this->calendarId;
    }

    /**
     * Build the standard attendee list:
     * - Faculty added first with displayName
     * - Active students added with email and displayName
     * - Deduplicated by normalized email
     *
     * @param array<int, array{email: string, name?: string}> $students
     * @param string|null $facultyEmail
     * @param string|null $facultyName
     * @return array<int, array{email: string, displayName?: string, responseStatus?: string}>
     */
    public static function buildAttendeeList(array $students, ?string $facultyEmail = null, ?string $facultyName = null): array {
        $attendees = [];
        $seen = [];

        // Add faculty attendee first if valid
        if ($facultyEmail !== null) {
            $normFacEmail = strtolower(trim($facultyEmail));
            if (filter_var($normFacEmail, FILTER_VALIDATE_EMAIL)) {
                $seen[$normFacEmail] = true;
                $facEntry = ['email' => $normFacEmail];
                if (!empty($facultyName)) {
                    $facEntry['displayName'] = trim($facultyName) . ' (Faculty)';
                }
                $attendees[] = $facEntry;
            }
        }

        // Add active students deduplicated
        foreach ($students as $st) {
            $email = strtolower(trim($st['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $entry = ['email' => $email];
            if (!empty($st['name'])) {
                $entry['displayName'] = trim($st['name']);
            }
            $attendees[] = $entry;
        }

        return $attendees;
    }

    /**
     * Create a Google Calendar event with a unique Google Meet conference.
     *
     * @param int $sessionId PEPP ERP session ID
     * @param string $topic Session topic
     * @param string $startDatetime ISO string or Y-m-d H:i:s
     * @param float $durationHours Duration in hours
     * @param array<int, array{email: string, name?: string}> $students Eligible active students
     * @param string|null $facultyEmail Assigned faculty email
     * @param string|null $facultyName Assigned faculty name
     * @param array<string> $courses Selected course names
     * @return array{
     *     success: bool,
     *     event_id: ?string,
     *     calendar_id: string,
     *     meet_uri: ?string,
     *     meet_code: ?string,
     *     attendees_count: int,
     *     conference_status: string,
     *     error: ?string,
     *     raw: ?array<string, mixed>
     * }
     */
    public function createLiveSessionEvent(
        int $sessionId,
        string $topic,
        string $startDatetime,
        float $durationHours,
        array $students,
        ?string $facultyEmail = null,
        ?string $facultyName = null,
        array $courses = [],
        ?string $existingMeetUri = null,
        ?string $existingMeetCode = null
    ): array {
        $startTime = strtotime($startDatetime);
        if ($startTime === false) {
            return [
                'success' => false,
                'event_id' => null,
                'calendar_id' => $this->calendarId,
                'meet_uri' => null,
                'meet_code' => null,
                'attendees_count' => 0,
                'conference_status' => 'invalid_datetime',
                'error' => "Invalid session datetime: {$startDatetime}",
                'raw' => null,
            ];
        }

        $durationSeconds = (int)round(max(0.25, $durationHours) * 3600);
        $endTime = $startTime + $durationSeconds;

        $startIso = date('Y-m-d\TH:i:s', $startTime);
        $endIso   = date('Y-m-d\TH:i:s', $endTime);
        $timeZone = 'Asia/Kolkata';

        $courseStr = !empty($courses) ? implode(', ', $courses) : 'All Enrolled Batches';
        $facStr    = !empty($facultyName) ? $facultyName : 'Assigned Faculty';

        $descLines = [
            "PEPP Learning Live Class Session",
            "────────────────────────────────────────",
            "Topic: " . $topic,
            "Faculty: " . $facStr,
            "Target Courses: " . $courseStr,
            "Duration: " . rtrim(rtrim(number_format($durationHours, 2), '0'), '.') . " hour(s)",
            "PEPP Session Reference: PEPP-SESS-" . $sessionId,
        ];
        if (!empty($existingMeetUri)) {
            $descLines[] = "Google Meet Link: " . $existingMeetUri;
        }
        $descLines[] = "────────────────────────────────────────";
        $descLines[] = "Please join promptly at the scheduled time. Host moderation and recording are enabled.";
        $description = implode("\n", $descLines);

        $attendees = self::buildAttendeeList($students, $facultyEmail, $facultyName);
        $requestId = 'pepp_sess_' . $sessionId . '_' . bin2hex(random_bytes(6));

        // Event Payload
        $eventPayload = [
            'summary'     => '[PEPP Live Session] ' . $topic,
            'description' => $description,
            'start'       => [
                'dateTime' => $startIso,
                'timeZone' => $timeZone,
            ],
            'end'         => [
                'dateTime' => $endIso,
                'timeZone' => $timeZone,
            ],
            'attendees'   => $attendees,

            // CRITICAL PRIVACY CONTROLS
            'guestsCanSeeOtherGuests' => false,
            'guestsCanInviteOthers'   => false,
            'guestsCanModify'         => false,

            // Organizer Reminders (Note: Applies to organizer calendar; attendees receive per their settings)
            'reminders'   => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'popup', 'minutes' => 1440], // 24 hours
                    ['method' => 'popup', 'minutes' => 720],  // 12 hours
                    ['method' => 'popup', 'minutes' => 60],   // 1 hour
                    ['method' => 'popup', 'minutes' => 10],   // 10 minutes
                    ['method' => 'popup', 'minutes' => 0],    // At session start
                ],
            ],
        ];

        // Conference data handling:
        // When an existing native Meet URI is supplied, attach it directly WITHOUT createRequest.
        if (!empty($existingMeetUri)) {
            $eventPayload['location'] = $existingMeetUri;
            $eventPayload['conferenceData'] = [
                'conferenceSolution' => [
                    'key' => [
                        'type' => 'hangoutsMeet',
                    ],
                    'name' => 'Google Meet',
                ],
                'entryPoints' => [
                    [
                        'entryPointType' => 'video',
                        'uri'            => $existingMeetUri,
                        'label'          => $existingMeetUri,
                    ],
                ],
            ];
        } else {
            // Legacy / fallback: Request Calendar to create a conference
            $eventPayload['conferenceData'] = [
                'createRequest' => [
                    'requestId' => $requestId,
                    'conferenceSolutionKey' => [
                        'type' => 'hangoutsMeet',
                    ],
                ],
            ];
        }

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($this->calendarId) . '/events?conferenceDataVersion=1&sendUpdates=all';
        $res = $this->client->apiRequest('POST', $url, $eventPayload, [], [GoogleWorkspaceClient::SCOPE_CALENDAR]);

        // Resilience fallback: If conferenceData was rejected for pre-existing link, retry without conferenceData
        if (!$res['success'] && !empty($existingMeetUri) && isset($eventPayload['conferenceData'])) {
            unset($eventPayload['conferenceData']);
            $plainUrl = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($this->calendarId) . '/events?sendUpdates=all';
            $retryRes = $this->client->apiRequest('POST', $plainUrl, $eventPayload, [], [GoogleWorkspaceClient::SCOPE_CALENDAR]);
            if ($retryRes['success'] && !empty($retryRes['data'])) {
                $res = $retryRes;
            }
        }

        if (!$res['success'] || empty($res['data'])) {
            return [
                'success' => false,
                'event_id' => null,
                'calendar_id' => $this->calendarId,
                'meet_uri' => null,
                'meet_code' => null,
                'attendees_count' => count($attendees),
                'conference_status' => 'failed',
                'error' => "Google Calendar event creation failed: " . ($res['error'] ?: 'Unknown error'),
                'raw' => $res['data'],
            ];
        }

        $eventData = $res['data'];
        $eventId = (string)($eventData['id'] ?? '');

        // Extract conference data and handle potential asynchronous pending state
        $conferenceExtraction = $this->extractConferenceData($eventData);
        $attempts = 0;
        $maxAttempts = 3;

        while ($conferenceExtraction['status'] === 'pending' && $attempts < $maxAttempts && $eventId !== '') {
            $attempts++;
            usleep(600000); // 600ms backoff
            $pollRes = $this->getEvent($eventId);
            if ($pollRes['success'] && !empty($pollRes['data'])) {
                $eventData = $pollRes['data'];
                $conferenceExtraction = $this->extractConferenceData($eventData);
            }
        }

        $finalMeetUri = $conferenceExtraction['meet_uri'] ?: $existingMeetUri;
        $finalMeetCode = $conferenceExtraction['meet_code'] ?: $existingMeetCode;

        return [
            'success' => true,
            'event_id' => $eventId,
            'calendar_id' => $this->calendarId,
            'meet_uri' => $finalMeetUri,
            'meet_code' => $finalMeetCode,
            'attendees_count' => count($attendees),
            'conference_status' => $conferenceExtraction['status'] ?: 'success',
            'error' => null,
            'raw' => $eventData,
        ];
    }

    /**
     * Extract Meet URI, meeting code, and status from event conferenceData.
     *
     * @param array<string, mixed> $eventData
     * @return array{meet_uri: ?string, meet_code: ?string, status: string}
     */
    public function extractConferenceData(array $eventData): array {
        $confData = $eventData['conferenceData'] ?? [];
        $statusCode = (string)($confData['createRequest']['status']['statusCode'] ?? 'success');
        $meetUri = null;
        $meetCode = null;

        if (isset($confData['entryPoints']) && is_array($confData['entryPoints'])) {
            foreach ($confData['entryPoints'] as $ep) {
                if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) {
                    $meetUri = (string)$ep['uri'];
                    break;
                }
            }
        }

        if (!empty($confData['conferenceId'])) {
            $meetCode = (string)$confData['conferenceId'];
        } elseif ($meetUri !== null && preg_match('/meet\.google\.com\/([a-z0-9\-]+)/i', $meetUri, $m)) {
            $meetCode = $m[1];
        }

        return [
            'meet_uri'  => $meetUri,
            'meet_code' => $meetCode,
            'status'    => $statusCode ?: ($meetUri ? 'success' : 'pending'),
        ];
    }

    /**
     * Retrieve an existing event by ID.
     *
     * @return array{success: bool, data: ?array<string, mixed>, error: ?string}
     */
    public function getEvent(string $eventId): array {
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($this->calendarId) . '/events/' . rawurlencode($eventId) . '?conferenceDataVersion=1';
        $res = $this->client->apiRequest('GET', $url, null, [], [GoogleWorkspaceClient::SCOPE_CALENDAR]);
        return [
            'success' => $res['success'],
            'data'    => $res['data'],
            'error'   => $res['error'],
        ];
    }

    /**
     * Update an existing Google Calendar event.
     * Preserves existing conferenceData (Meet link) while updating topic, times, description, and attendees.
     * Enforces student guest privacy (guestsCanSeeOtherGuests = false, guestsCanInviteOthers = false, guestsCanModify = false).
     * Sends sendUpdates=all to notify invited guests via Google Calendar.
     *
     * @param string $eventId Google Calendar event ID
     * @param int $sessionId PEPP ERP session ID
     * @param string $topic Session topic
     * @param string $startDatetime ISO string or Y-m-d H:i:s
     * @param float $durationHours Duration in hours
     * @param array<int, array{email: string, name?: string}> $students Eligible active students
     * @param string|null $facultyEmail Assigned faculty email
     * @param string|null $facultyName Assigned faculty name
     * @param array<string> $courses Selected course names
     * @return array{
     *     success: bool,
     *     event_id: ?string,
     *     calendar_id: string,
     *     meet_uri: ?string,
     *     meet_code: ?string,
     *     attendees_count: int,
     *     error: ?string,
     *     raw: ?array<string, mixed>
     * }
     */
    public function updateLiveSessionEvent(
        string $eventId,
        int $sessionId,
        string $topic,
        string $startDatetime,
        float $durationHours,
        array $students,
        ?string $facultyEmail = null,
        ?string $facultyName = null,
        array $courses = []
    ): array {
        $startTime = strtotime($startDatetime);
        if ($startTime === false) {
            return [
                'success'         => false,
                'event_id'        => $eventId,
                'calendar_id'     => $this->calendarId,
                'meet_uri'        => null,
                'meet_code'       => null,
                'attendees_count' => 0,
                'error'           => "Invalid session datetime: {$startDatetime}",
                'raw'             => null,
            ];
        }

        $durationSeconds = (int)round(max(0.25, $durationHours) * 3600);
        $endTime = $startTime + $durationSeconds;

        $startIso = date('Y-m-d\TH:i:s', $startTime);
        $endIso   = date('Y-m-d\TH:i:s', $endTime);
        $timeZone = 'Asia/Kolkata';

        $courseStr = !empty($courses) ? implode(', ', $courses) : 'All Enrolled Batches';
        $facStr    = !empty($facultyName) ? $facultyName : 'Assigned Faculty';

        $description = implode("\n", [
            "PEPP Learning Live Class Session",
            "────────────────────────────────────────",
            "Topic: " . $topic,
            "Faculty: " . $facStr,
            "Target Courses: " . $courseStr,
            "Duration: " . rtrim(rtrim(number_format($durationHours, 2), '0'), '.') . " hour(s)",
            "PEPP Session Reference: PEPP-SESS-" . $sessionId,
            "────────────────────────────────────────",
            "Please join promptly at the scheduled time. Host moderation and recording are enabled.",
        ]);

        $attendees = self::buildAttendeeList($students, $facultyEmail, $facultyName);

        // Update Event Payload (using PATCH so existing conferenceData is untouched)
        $eventPayload = [
            'summary'     => '[PEPP Live Session] ' . $topic,
            'description' => $description,
            'start'       => [
                'dateTime' => $startIso,
                'timeZone' => $timeZone,
            ],
            'end'         => [
                'dateTime' => $endIso,
                'timeZone' => $timeZone,
            ],
            'attendees'   => $attendees,

            // CRITICAL PRIVACY CONTROLS
            'guestsCanSeeOtherGuests' => false,
            'guestsCanInviteOthers'   => false,
            'guestsCanModify'         => false,

            // Organizer Reminders (5 overrides max)
            'reminders'   => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'popup', 'minutes' => 1440], // 24 hours
                    ['method' => 'popup', 'minutes' => 720],  // 12 hours
                    ['method' => 'popup', 'minutes' => 60],   // 1 hour
                    ['method' => 'popup', 'minutes' => 10],   // 10 minutes
                    ['method' => 'popup', 'minutes' => 0],    // At session start
                ],
            ],
        ];

        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($this->calendarId) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all';
        $res = $this->client->apiRequest('PATCH', $url, $eventPayload, [], [GoogleWorkspaceClient::SCOPE_CALENDAR]);

        if (!$res['success'] || empty($res['data'])) {
            return [
                'success'         => false,
                'event_id'        => $eventId,
                'calendar_id'     => $this->calendarId,
                'meet_uri'        => null,
                'meet_code'       => null,
                'attendees_count' => count($attendees),
                'error'           => "Google Calendar event update failed: " . ($res['error'] ?: 'Unknown error'),
                'raw'             => $res['data'],
            ];
        }

        $eventData = $res['data'];
        $confData = $this->extractConferenceData($eventData);

        return [
            'success'         => true,
            'event_id'        => (string)($eventData['id'] ?? $eventId),
            'calendar_id'     => $this->calendarId,
            'meet_uri'        => $confData['meet_uri'],
            'meet_code'       => $confData['meet_code'],
            'attendees_count' => count($attendees),
            'error'           => null,
            'raw'             => $eventData,
        ];
    }

    /**
     * Delete an existing Calendar event.
     *
     * @return array{success: bool, error: ?string}
     */
    public function deleteEvent(string $eventId): array {
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($this->calendarId) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all';
        $res = $this->client->apiRequest('DELETE', $url, null, [], [GoogleWorkspaceClient::SCOPE_CALENDAR]);
        return [
            'success' => $res['success'] || $res['status'] === 404 || $res['status'] === 410,
            'error'   => $res['error'],
        ];
    }
}
