<?php
/**
 * FacultySessionNotificationService.php
 * Handles scheduled, reminder, start-live, start-now, and cancellation WhatsApp
 * notifications for live session faculty using the existing CommunicationEngine architecture.
 */

declare(strict_types=1);

require_once __DIR__ . '/CommunicationEngine.php';
require_once __DIR__ . '/CommunicationHelper.php';

class FacultySessionNotificationService {
    private PDO $pdo;
    private CommunicationEngine $engine;

    public function __construct(PDO $pdo, ?CommunicationEngine $engine = null) {
        $this->pdo = $pdo;
        $this->engine = $engine ?: CommunicationEngine::getInstance($pdo);
    }

    /**
     * Authoritative canonical variable mapping corresponding to approved Meta WhatsApp templates.
     * This defines the exact semantic ERP variable for each {{N}} placeholder in the approved Meta templates.
     */
    public const CANONICAL_META_VARIABLE_MAP = [
        'faculty_session_scheduled' => [
            1 => 'faculty_name',
            2 => 'session_type',
            3 => 'session_topic',
            4 => 'session_datetime',
            5 => 'session_courses',
            6 => 'session_duration'
        ],
        'faculty_session_reminder' => [
            1 => 'faculty_name',
            2 => 'session_datetime'
        ],
        'faculty_session_start' => [
            1 => 'faculty_name',
            2 => 'session_datetime',
            3 => 'session_topic',
            4 => 'session_courses',
            5 => 'session_duration'
        ],
        'faculty_session_start_now' => [
            1 => 'faculty_name'
        ],
        'faculty_session_cancelled' => [
            1 => 'faculty_name',
            2 => 'session_datetime',
            3 => 'session_topic',
            4 => 'session_courses'
        ]
    ];

    /**
     * Resolves body parameter values for a template.
     * 1. Checks communication_templates.meta_data to derive canonical body indexes from Meta components.
     * 2. Uses CANONICAL_META_VARIABLE_MAP to populate the exact semantic ERP variable values.
     *
     * @param string $templateName Name of the Meta template (e.g. 'faculty_session_scheduled')
     * @param array $contextData Map of ERP variables (e.g. ['faculty_name' => '...', 'session_type' => '...'])
     * @return array Ordered list of string values for template parameters
     */
    public function resolveTemplateParameters(string $templateName, array $contextData): array {
        // Derive indexes from communication_templates.meta_data if available
        try {
            $stmtTpl = $this->pdo->prepare("SELECT meta_data FROM communication_templates WHERE template_name = ? LIMIT 1");
            $stmtTpl->execute([$templateName]);
            $metaData = $stmtTpl->fetchColumn();
            if ($metaData) {
                $paramDef = CommunicationHelper::getTemplateParameterDefinition($metaData);
                $bodyIndexes = $paramDef['body']['indexes'];
                if (!empty($bodyIndexes)) {
                    $canonMap = self::CANONICAL_META_VARIABLE_MAP[$templateName] ?? [];
                    $resolved = [];
                    foreach ($bodyIndexes as $idx) {
                        $varKey = $canonMap[$idx] ?? null;
                        $resolved[] = $varKey ? (string)($contextData[$varKey] ?? '') : '';
                    }
                    return $resolved;
                }
            }
        } catch (Exception $e) {
            // Database not accessible or table missing; continue to canonical map fallback
        }

        // Fallback to authoritative canonical Meta variable map
        $canonMap = self::CANONICAL_META_VARIABLE_MAP[$templateName] ?? [];
        $resolved = [];
        foreach ($canonMap as $idx => $varKey) {
            $resolved[] = (string)($contextData[$varKey] ?? '');
        }
        return $resolved;
    }

    /**
     * Schedules all initial faculty notifications upon session creation.
     *
     * @param int $sessionId
     * @param string $sentBy
     * @return array Result summary with status, queued job keys, and any warnings
     */
    public function scheduleSessionCreation(int $sessionId, string $sentBy = 'system'): array {
        $stmt = $this->pdo->prepare("
            SELECT s.*, f.name AS faculty_name, f.mobile AS faculty_mobile, f.email AS faculty_email
            FROM sessions s
            LEFT JOIN faculties f ON f.id = s.faculty_id
            WHERE s.id = ?
        ");
        $stmt->execute([$sessionId]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sess) {
            return ['success' => false, 'error' => "Session #{$sessionId} not found.", 'queued' => []];
        }

        $facultyId = (int)($sess['faculty_id'] ?? 0);
        if ($facultyId <= 0) {
            return ['success' => false, 'error' => "No faculty assigned to session #{$sessionId}.", 'queued' => []];
        }

        $facultyMobile = trim((string)($sess['faculty_mobile'] ?? ''));
        $cleanPhone = preg_replace('/\D/', '', $facultyMobile);

        if (empty($cleanPhone) || strlen($cleanPhone) < 10) {
            return [
                'success' => false,
                'warning' => 'Selected faculty has no valid WhatsApp number configured. Faculty WhatsApp notifications cannot be sent.',
                'queued'  => []
            ];
        }

        $facultyName = trim((string)($sess['faculty_name'] ?: 'Faculty'));
        $topic = trim((string)($sess['topic'] ?: 'Live Session'));
        $type = ucfirst(trim((string)($sess['session_type'] ?: 'Live')));
        $dtStr = (string)$sess['session_datetime'];
        $timestamp = strtotime($dtStr);
        $formattedDt = $timestamp ? date('d M Y, h:i A', $timestamp) : $dtStr;
        $formattedTime = $timestamp ? date('h:i A', $timestamp) : '';
        $courses = trim((string)($sess['course_csv'] ?: 'PEPP Courses'));
        $durationHours = (float)($sess['duration_hours'] ?: 1.0);
        $durationStr = $durationHours == 1.0 ? '1 hour' : ($durationHours . ' hours');

        $context = [
            'faculty_name'     => $facultyName,
            'session_type'     => $type,
            'session_topic'    => $topic,
            'session_datetime' => $formattedDt,
            'session_courses'  => $courses,
            'session_duration' => $durationStr,
        ];

        $queued = [];

        // ── 1. Immediate Scheduled Notification (Phase 7) ────────────────────
        // Meta template: {{1}} Name, {{2}} Type, {{3}} Topic, {{4}} Datetime, {{5}} Courses, {{6}} Duration
        $scheduledKey = "faculty:{$facultyId}:session:{$sessionId}:scheduled";
        $scheduledParams = $this->resolveTemplateParameters('faculty_session_scheduled', $context);
        $scheduledTpl = [
            'name'       => 'faculty_session_scheduled',
            'language'   => 'en',
            'parameters' => $scheduledParams,
            'buttons'    => [
                'quick_reply' => [
                    ['text' => 'Read Instructions', 'payload' => 'READ_INSTRUCTIONS']
                ]
            ]
        ];

        $qId1 = $this->engine->queueMessage(
            'whatsapp',
            $cleanPhone,
            $facultyName,
            "Live Session Scheduled: {$topic}",
            '',
            "Hi {$facultyName},\n\nConfirm the following schedule.\nThis is a {$type} session.\nTopic: {$topic}\nDate & Time: {$formattedDt}\nCourses: {$courses}\nProposed Duration: {$durationStr}\n\nPlease join on time and be ready before the scheduled start time.\nRead the faculty instructions before your session.",
            [],
            $scheduledTpl,
            $sentBy,
            null,
            null,
            'faculty_session_scheduled',
            null,
            $scheduledKey
        );
        $queued['scheduled'] = ['queue_id' => $qId1, 'idempotency_key' => $scheduledKey];

        // ── 2. 3-Hour Reminder Notification (Phase 8) ────────────────────────
        // Meta template: {{1}} Name, {{2}} Datetime (Exactly 2 parameters; no topic or duration)
        $now = time();
        if ($timestamp && ($timestamp - $now) >= (3 * 3600)) {
            $reminder3hTime = date('Y-m-d H:i:s', $timestamp - (3 * 3600));
            $reminderKey = "faculty:{$facultyId}:session:{$sessionId}:reminder_3h";
            $reminderParams = $this->resolveTemplateParameters('faculty_session_reminder', $context);
            $reminderTpl = [
                'name'       => 'faculty_session_reminder',
                'language'   => 'en',
                'parameters' => $reminderParams
            ];

            $qId2 = $this->engine->queueMessage(
                'whatsapp',
                $cleanPhone,
                $facultyName,
                "Live Session Reminder: {$topic}",
                '',
                "Hi {$facultyName},\n\nThis is a reminder that you have a PEPP live session today at {$formattedDt}.\n\nWe hope you are prepared well for the session.\n\nThank you!",
                [],
                $reminderTpl,
                $sentBy,
                $reminder3hTime,
                null,
                'faculty_session_reminder',
                null,
                $reminderKey
            );
            $queued['reminder_3h'] = ['queue_id' => $qId2, 'idempotency_key' => $reminderKey, 'scheduled_at' => $reminder3hTime];
        }

        // ── 3. 1-Hour Start Live Notification (Phase 9) ──────────────────────
        // Meta template: {{1}} Name, {{2}} Datetime, {{3}} Topic, {{4}} Courses, {{5}} Duration
        // Dynamic CTA URL: Start Live -> https://meet.google.com/{{1}}
        if ($timestamp && $timestamp > $now) {
            $start1hTs = max($now, $timestamp - 3600);
            $start1hTime = date('Y-m-d H:i:s', $start1hTs);
            $start1hKey = "faculty:{$facultyId}:session:{$sessionId}:start_1h";
            $meetUrl = $this->resolveMeetUrl($sess);
            $meetParam = $this->extractMeetButtonParam($meetUrl);

            $start1hParams = $this->resolveTemplateParameters('faculty_session_start', $context);
            $start1hTpl = [
                'name'              => 'faculty_session_start',
                'language'          => 'en',
                'parameters'        => $start1hParams,
                'button_parameters' => [$meetParam]
            ];

            $qId3 = $this->engine->queueMessage(
                'whatsapp',
                $cleanPhone,
                $facultyName,
                "Live Session Starting Soon: {$topic}",
                '',
                "Dear {$facultyName},\n\nYour PEPP live session is scheduled to start at {$formattedDt}.\n\nSession: {$topic}\nCourses: {$courses}\nDuration: {$durationStr}\n\nNote:\n1. Automatic recording and Gemini notes will start when you enter the session.\n2. Please do not enter earlier than 5 minutes before the scheduled start time.",
                [],
                $start1hTpl,
                $sentBy,
                $start1hTime,
                null,
                'faculty_session_start',
                null,
                $start1hKey
            );
            $queued['start_1h'] = ['queue_id' => $qId3, 'idempotency_key' => $start1hKey, 'scheduled_at' => $start1hTime];
        }

        // ── 4. 2-Minute Start Now Notification (Phase 10) ────────────────────
        // Meta template: {{1}} Name (Exactly 1 body parameter; no topic parameter)
        // Dynamic CTA URL: Start Now -> https://meet.google.com/{{1}}
        if ($timestamp && $timestamp > $now) {
            $start2mTs = max($now, $timestamp - 120);
            $start2mTime = date('Y-m-d H:i:s', $start2mTs);
            $start2mKey = "faculty:{$facultyId}:session:{$sessionId}:start_2m";
            $meetUrl = $this->resolveMeetUrl($sess);
            $meetParam = $this->extractMeetButtonParam($meetUrl);

            $start2mParams = $this->resolveTemplateParameters('faculty_session_start_now', $context);
            $start2mTpl = [
                'name'              => 'faculty_session_start_now',
                'language'          => 'en',
                'parameters'        => $start2mParams,
                'button_parameters' => [$meetParam]
            ];

            $qId4 = $this->engine->queueMessage(
                'whatsapp',
                $cleanPhone,
                $facultyName,
                "Live Session Starting Now: {$topic}",
                '',
                "Hi {$facultyName},\n\nYour PEPP live session is starting now.\nPlease join your session now and begin the session as scheduled.",
                [],
                $start2mTpl,
                $sentBy,
                $start2mTime,
                null,
                'faculty_session_start_now',
                null,
                $start2mKey
            );
            $queued['start_2m'] = ['queue_id' => $qId4, 'idempotency_key' => $start2mKey, 'scheduled_at' => $start2mTime];
        }

        return ['success' => true, 'queued' => $queued];
    }

    /**
     * Handles updates to session details, recalculating and invalidating future jobs (Phase 12).
     *
     * @param int $sessionId
     * @param array $oldSessionData
     * @param string $sentBy
     * @return array
     */
    public function handleSessionUpdate(int $sessionId, array $oldSessionData, string $sentBy = 'system'): array {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $newSess = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$newSess) {
            return ['success' => false, 'error' => "Session #{$sessionId} not found."];
        }

        $oldFacultyId = (int)($oldSessionData['faculty_id'] ?? 0);
        $newFacultyId = (int)($newSessionData['faculty_id'] ?? $newSess['faculty_id'] ?? 0);
        $oldDt = (string)($oldSessionData['session_datetime'] ?? '');
        $newDt = (string)($newSess['session_datetime'] ?? '');

        $hasIdemCol = $this->hasIdempotencyKeyColumn();

        // If faculty changed:
        // - Cancel pending/scheduled jobs for old faculty
        // - Invalidate active interaction context for old faculty
        // - Schedule fresh notifications for new faculty
        if ($oldFacultyId > 0 && $newFacultyId > 0 && $oldFacultyId !== $newFacultyId) {
            $this->cancelPendingQueueByPrefix("faculty:{$oldFacultyId}:session:{$sessionId}:", 'Cancelled: Faculty reassigned');
            $this->invalidateInteractions($sessionId, $oldFacultyId);
            return $this->scheduleSessionCreation($sessionId, $sentBy);
        }

        // If session time changed:
        // - Invalidate stale scheduled reminder/start jobs
        // - Recalculate and enqueue new reminder & start jobs based on the new session datetime
        if ($oldDt !== $newDt) {
            // Cancel old 3h, 1h, 2m jobs
            $this->cancelPendingQueueByPrefix("faculty:{$newFacultyId}:session:{$sessionId}:reminder_3h", 'Cancelled: Session rescheduled');
            $this->cancelPendingQueueByPrefix("faculty:{$newFacultyId}:session:{$sessionId}:start_1h", 'Cancelled: Session rescheduled');
            $this->cancelPendingQueueByPrefix("faculty:{$newFacultyId}:session:{$sessionId}:start_2m", 'Cancelled: Session rescheduled');

            // Recalculate future reminders for the new time
            return $this->recalculateFutureReminders($sessionId, $sentBy);
        }

        return ['success' => true, 'message' => 'No communication schedule change required.'];
    }

    /**
     * Handles cancellation of a live session (Phase 11).
     *
     * @param int $sessionId
     * @param string $sentBy
     * @return array
     */
    public function handleSessionCancellation(int $sessionId, string $sentBy = 'system'): array {
        $stmt = $this->pdo->prepare("
            SELECT s.*, f.name AS faculty_name, f.mobile AS faculty_mobile
            FROM sessions s
            LEFT JOIN faculties f ON f.id = s.faculty_id
            WHERE s.id = ?
        ");
        $stmt->execute([$sessionId]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sess) {
            return ['success' => false, 'error' => "Session #{$sessionId} not found."];
        }

        $facultyId = (int)($sess['faculty_id'] ?? 0);
        $facultyMobile = trim((string)($sess['faculty_mobile'] ?? ''));
        $cleanPhone = preg_replace('/\D/', '', $facultyMobile);

        // Invalidate all pending/scheduled communication jobs for this session
        $this->cancelPendingQueueByPrefix("faculty:{$facultyId}:session:{$sessionId}:", 'Cancelled: Session cancelled by admin');
        $this->invalidateInteractions($sessionId, $facultyId);

        // Send faculty_session_cancelled exactly once if phone is valid
        // Meta template: {{1}} Name, {{2}} Datetime, {{3}} Topic, {{4}} Courses
        if ($facultyId > 0 && !empty($cleanPhone) && strlen($cleanPhone) >= 10) {
            $cancelKey = "faculty:{$facultyId}:session:{$sessionId}:cancelled";
            $facultyName = trim((string)($sess['faculty_name'] ?: 'Faculty'));
            $topic = trim((string)($sess['topic'] ?: 'Live Session'));
            $dtStr = (string)$sess['session_datetime'];
            $timestamp = strtotime($dtStr);
            $formattedDt = $timestamp ? date('d M Y, h:i A', $timestamp) : $dtStr;
            $courses = trim((string)($sess['course_csv'] ?: 'PEPP Courses'));

            $cancelContext = [
                'faculty_name'     => $facultyName,
                'session_datetime' => $formattedDt,
                'session_topic'    => $topic,
                'session_courses'  => $courses
            ];

            $cancelParams = $this->resolveTemplateParameters('faculty_session_cancelled', $cancelContext);

            $cancelTpl = [
                'name'       => 'faculty_session_cancelled',
                'language'   => 'en',
                'parameters' => $cancelParams
            ];

            $qId = $this->engine->queueMessage(
                'whatsapp',
                $cleanPhone,
                $facultyName,
                "Live Session Cancelled: {$topic}",
                '',
                "Hi {$facultyName},\n\nYour PEPP live session scheduled for {$formattedDt} has been cancelled.\n\nSession: {$topic}\nCourses: {$courses}\n\nPlease do not join the previously shared session link.",
                [],
                $cancelTpl,
                $sentBy,
                null,
                null,
                'faculty_session_cancelled',
                null,
                $cancelKey
            );

            return ['success' => true, 'cancelled_queue_id' => $qId, 'idempotency_key' => $cancelKey];
        }

        return ['success' => true, 'message' => 'Pending jobs invalidated. No cancellation notification sent (no valid phone).'];
    }

    /**
     * Recalculates and schedules future 3h, 1h, and 2m reminders for a session.
     */
    private function recalculateFutureReminders(int $sessionId, string $sentBy): array {
        $stmt = $this->pdo->prepare("
            SELECT s.*, f.name AS faculty_name, f.mobile AS faculty_mobile
            FROM sessions s
            LEFT JOIN faculties f ON f.id = s.faculty_id
            WHERE s.id = ?
        ");
        $stmt->execute([$sessionId]);
        $sess = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sess) {
            return ['success' => false, 'error' => "Session #{$sessionId} not found."];
        }

        $facultyId = (int)($sess['faculty_id'] ?? 0);
        $cleanPhone = preg_replace('/\D/', '', (string)($sess['faculty_mobile'] ?? ''));

        if ($facultyId <= 0 || empty($cleanPhone) || strlen($cleanPhone) < 10) {
            return ['success' => false, 'warning' => 'No valid phone for recalculated reminders.'];
        }

        $facultyName = trim((string)($sess['faculty_name'] ?: 'Faculty'));
        $topic = trim((string)($sess['topic'] ?: 'Live Session'));
        $dtStr = (string)$sess['session_datetime'];
        $timestamp = strtotime($dtStr);
        $formattedDt = $timestamp ? date('d M Y, h:i A', $timestamp) : $dtStr;
        $courses = trim((string)($sess['course_csv'] ?: 'PEPP Courses'));
        $durationHours = (float)($sess['duration_hours'] ?: 1.0);
        $durationStr = $durationHours == 1.0 ? '1 hour' : ($durationHours . ' hours');

        $context = [
            'faculty_name'     => $facultyName,
            'session_type'     => ucfirst(trim((string)($sess['session_type'] ?: 'Live'))),
            'session_topic'    => $topic,
            'session_datetime' => $formattedDt,
            'session_courses'  => $courses,
            'session_duration' => $durationStr,
        ];

        $now = time();
        $queued = [];

        // 3-Hour Reminder
        // Meta template: {{1}} Name, {{2}} Datetime (Exactly 2 parameters; no topic or duration)
        if ($timestamp && ($timestamp - $now) >= (3 * 3600)) {
            $reminder3hTime = date('Y-m-d H:i:s', $timestamp - (3 * 3600));
            $reminderKey = "faculty:{$facultyId}:session:{$sessionId}:reminder_3h";
            $reminderParams = $this->resolveTemplateParameters('faculty_session_reminder', $context);
            $reminderTpl = [
                'name'       => 'faculty_session_reminder',
                'language'   => 'en',
                'parameters' => $reminderParams
            ];

            $qId = $this->engine->queueMessage(
                'whatsapp',
                $cleanPhone,
                $facultyName,
                "Live Session Reminder: {$topic}",
                '',
                "Hi {$facultyName},\n\nThis is a reminder that you have a PEPP live session today at {$formattedDt}.\n\nWe hope you are prepared well for the session.\n\nThank you!",
                [],
                $reminderTpl,
                $sentBy,
                $reminder3hTime,
                null,
                'faculty_session_reminder',
                null,
                $reminderKey
            );
            $queued['reminder_3h'] = ['queue_id' => $qId, 'idempotency_key' => $reminderKey];
        }

        // 1-Hour Start Live
        // Meta template: {{1}} Name, {{2}} Datetime, {{3}} Topic, {{4}} Courses, {{5}} Duration
        // Dynamic CTA URL: Start Live -> https://meet.google.com/{{1}}
        if ($timestamp && $timestamp > $now) {
            $start1hTs = max($now, $timestamp - 3600);
            $start1hTime = date('Y-m-d H:i:s', $start1hTs);
            $start1hKey = "faculty:{$facultyId}:session:{$sessionId}:start_1h";
            $meetUrl = $this->resolveMeetUrl($sess);
            $meetParam = $this->extractMeetButtonParam($meetUrl);

            $start1hParams = $this->resolveTemplateParameters('faculty_session_start', $context);
            $start1hTpl = [
                'name'              => 'faculty_session_start',
                'language'          => 'en',
                'parameters'        => $start1hParams,
                'button_parameters' => [$meetParam]
            ];

            $qId = $this->engine->queueMessage(
                'whatsapp',
                $cleanPhone,
                $facultyName,
                "Live Session Starting Soon: {$topic}",
                '',
                "Dear {$facultyName},\n\nYour PEPP live session is scheduled to start at {$formattedDt}.\n\nSession: {$topic}\nCourses: {$courses}\nDuration: {$durationStr}\n\nNote:\n1. Automatic recording and Gemini notes will start when you enter the session.\n2. Please do not enter earlier than 5 minutes before the scheduled start time.",
                [],
                $start1hTpl,
                $sentBy,
                $start1hTime,
                null,
                'faculty_session_start',
                null,
                $start1hKey
            );
            $queued['start_1h'] = ['queue_id' => $qId, 'idempotency_key' => $start1hKey];
        }

        // 2-Minute Start Now
        // Meta template: {{1}} Name (Exactly 1 body parameter; no topic parameter)
        // Dynamic CTA URL: Start Now -> https://meet.google.com/{{1}}
        if ($timestamp && $timestamp > $now) {
            $start2mTs = max($now, $timestamp - 120);
            $start2mTime = date('Y-m-d H:i:s', $start2mTs);
            $start2mKey = "faculty:{$facultyId}:session:{$sessionId}:start_2m";
            $meetUrl = $this->resolveMeetUrl($sess);
            $meetParam = $this->extractMeetButtonParam($meetUrl);

            $start2mParams = $this->resolveTemplateParameters('faculty_session_start_now', $context);
            $start2mTpl = [
                'name'              => 'faculty_session_start_now',
                'language'          => 'en',
                'parameters'        => $start2mParams,
                'button_parameters' => [$meetParam]
            ];

            $qId = $this->engine->queueMessage(
                'whatsapp',
                $cleanPhone,
                $facultyName,
                "Live Session Starting Now: {$topic}",
                '',
                "Hi {$facultyName},\n\nYour PEPP live session is starting now.\nPlease join your session now and begin the session as scheduled.",
                [],
                $start2mTpl,
                $sentBy,
                $start2mTime,
                null,
                'faculty_session_start_now',
                null,
                $start2mKey
            );
            $queued['start_2m'] = ['queue_id' => $qId, 'idempotency_key' => $start2mKey];
        }

        return ['success' => true, 'recalculated' => $queued];
    }

    /**
     * Resolves Google Meet URL from session data.
     * Prefers google_meet_uri, falls back to meet_link.
     */
    public function resolveMeetUrl(array $sess): ?string {
        $uri = trim((string)($sess['google_meet_uri'] ?? ''));
        if (!empty($uri) && filter_var($uri, FILTER_VALIDATE_URL)) {
            return $uri;
        }

        $link = trim((string)($sess['meet_link'] ?? ''));
        if (!empty($link) && filter_var($link, FILTER_VALIDATE_URL)) {
            return $link;
        }

        return null;
    }

    /**
     * Alias for resolveMeetUrl
     */
    public function resolveGoogleMeetUrl(array $sess): string {
        return (string)($this->resolveMeetUrl($sess) ?? '');
    }

    /**
     * Extracts button parameter from Google Meet URL.
     * If URL is https://meet.google.com/abc-defg-hij, returns abc-defg-hij for dynamic template URL.
     */
    public function extractMeetButtonParam(?string $url): string {
        if (!$url) return '';
        if (preg_match('#meet\.google\.com/([a-zA-Z0-9\-]+)#', $url, $m)) {
            return $m[1];
        }
        return $url;
    }

    /**
     * Cancels pending queue items matching a prefix.
     */
    private function cancelPendingQueueByPrefix(string $prefix, string $reason): void {
        try {
            $hasIdem = $this->hasIdempotencyKeyColumn();
            if ($hasIdem) {
                $stmt = $this->pdo->prepare("
                    UPDATE communication_queue
                    SET status = 'cancelled', error_message = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE idempotency_key LIKE ? AND status IN ('pending', 'scheduled')
                ");
                $stmt->execute([$reason, $prefix . '%']);
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE communication_queue
                    SET status = 'cancelled', error_message = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE error_message LIKE ? AND status IN ('pending', 'scheduled')
                ");
                $stmt->execute([$reason, '%' . $prefix . '%']);
            }
        } catch (Exception $e) {
            error_log("FacultySessionNotificationService cancelPendingQueueByPrefix error: " . $e->getMessage());
        }
    }

    /**
     * Invalidates any active interaction records for a session / faculty.
     */
    private function invalidateInteractions(int $sessionId, int $facultyId): void {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE faculty_session_interactions
                SET step = 'cancelled', expires_at = CURRENT_TIMESTAMP
                WHERE session_id = ? AND faculty_id = ?
            ");
            $stmt->execute([$sessionId, $facultyId]);
        } catch (Exception $e) {}
    }

    /**
     * Checks if idempotency_key column exists on communication_queue table.
     */
    public function hasIdempotencyKeyColumn(): bool {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(communication_queue)")->fetchAll(PDO::FETCH_COLUMN, 1);
                return in_array('idempotency_key', $cols, true);
            }
            return (bool)$this->pdo->query("SHOW COLUMNS FROM communication_queue LIKE 'idempotency_key'")->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
}
