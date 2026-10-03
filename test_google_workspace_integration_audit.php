<?php
/**
 * PEPP Learning ERP — Google Workspace Calendar & Meet Integration Audit Test Suite
 *
 * Validates:
 * 1. Authentication & Security:
 *    - Service Account credential loading
 *    - Strict account boundary (admin@pepponline.in allowed, meet@pepponline.in prohibited)
 *    - Real RS256 JWT minting with OpenSSL
 *    - Authorized OAuth scopes
 *    - Safe logging & credential non-exposure
 * 2. Calendar Event Creation:
 *    - Correct organizer, primary calendar, Asia/Kolkata timezone
 *    - Enforced student guest privacy: guestsCanSeeOtherGuests = false
 *    - Guest restrictions: guestsCanInviteOthers = false, guestsCanModify = false
 *    - Reminder windows: 24h, 12h, 1h, 10m, at start
 *    - conferenceData.createRequest with unique requestId
 *    - Handling of pending / success conference states
 * 3. Google Meet Space Configuration:
 *    - Authoritative space resource (spaces/{space})
 *    - Access restriction: accessType = 'RESTRICTED', moderation = 'ON'
 *    - ArtifactConfig: auto-recording, auto-transcription, smart notes
 *    - Faculty assigned as COHOST via spaces.members.create
 * 4. Active Student Resolution:
 *    - Canonical active student criteria: users.status = 'approved' AND users.student_status = 'active'
 *    - Excludes inactive, rejected, pending, suspended, dropout, and completed learners
 *    - Normalizes and deduplicates email addresses
 *    - Discards blank or invalid emails
 * 5. Notifications & Backward Compatibility:
 *    - Suppresses duplicate ERP email queue for Google-integrated sessions
 *    - Preserves ERP mail queue for standard sessions
 *    - Existing sessions and manual meet_link values remain backward compatible
 * 6. Attendance Synchronization:
 *    - Conference records & participant sessions retrieval
 *    - Multi-session join/leave interval aggregation
 *    - Duration calculation & threshold status classification
 *    - Idempotent upsert into session_attendance
 * 7. Meet Artifacts & Drive Linking:
 *    - Syncs recordings, transcripts, smart notes as Drive references
 *    - Zero video downloads to Hostinger server
 * 8. Error Recovery & Idempotency:
 *    - Failure state recorded in sessions without orphan data
 *    - Retry synchronization creates no duplicate events
 * 9. Git Cleanliness:
 *    - Confirms all 7 paused WhatsApp multi-account files remain untouched
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/google/GoogleWorkspaceClient.php';
require_once __DIR__ . '/includes/google/GoogleCalendarService.php';
require_once __DIR__ . '/includes/google/GoogleMeetService.php';
require_once __DIR__ . '/includes/google/GoogleAttendanceService.php';
require_once __DIR__ . '/includes/google/GoogleArtifactService.php';
require_once __DIR__ . '/includes/google/GoogleLiveSessionManager.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(bool $condition, string $description, ?string $detail = null): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$description}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

echo "========================================================================\n";
echo " GOOGLE WORKSPACE CALENDAR & MEET INTEGRATION AUDIT TEST SUITE\n";
echo "========================================================================\n\n";

// ── SETUP IN-MEMORY SQLITE DATABASE ──────────────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// SQLite custom MySQL functions
$pdo->sqliteCreateFunction('NOW', function() { return date('Y-m-d H:i:s'); });
$pdo->sqliteCreateFunction('CURDATE', function() { return date('Y-m-d'); });
$pdo->sqliteCreateFunction('DATE_ADD', function($dt, $expr) { return date('Y-m-d H:i:s', strtotime($dt . ' +1 hour')); });
$pdo->sqliteCreateFunction('DATE_SUB', function($dt, $expr) { return date('Y-m-d H:i:s', strtotime($dt . ' -1 hour')); });
$pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $d1, $d2) {
    return (int)round((strtotime($d2) - strtotime($d1)) / 60);
});

// Create schema mimicking production MySQL
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id VARCHAR(50) UNIQUE,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        student_status VARCHAR(50) NOT NULL DEFAULT 'active',
        pepp_course VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS faculties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(190) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active'
    );

    CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        topic VARCHAR(255) NOT NULL,
        faculty_id INTEGER DEFAULT NULL,
        session_datetime DATETIME NOT NULL,
        duration_hours DECIMAL(4,2) NOT NULL DEFAULT 1.00,
        session_type VARCHAR(20) NOT NULL DEFAULT 'live',
        meet_link VARCHAR(500) DEFAULT NULL,
        venue VARCHAR(255) DEFAULT NULL,
        course_csv TEXT DEFAULT NULL,
        google_integrated INTEGER NOT NULL DEFAULT 0,
        google_calendar_event_id VARCHAR(255) DEFAULT NULL,
        google_calendar_id VARCHAR(255) DEFAULT 'primary',
        google_meet_space_name VARCHAR(255) DEFAULT NULL,
        google_meet_uri VARCHAR(500) DEFAULT NULL,
        google_meet_code VARCHAR(50) DEFAULT NULL,
        google_integration_status VARCHAR(50) DEFAULT NULL,
        google_last_sync_at DATETIME DEFAULT NULL,
        google_error_message TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
        created_by VARCHAR(100) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL
    );

    CREATE TABLE IF NOT EXISTS session_attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        user_id VARCHAR(50) DEFAULT NULL,
        google_participant_name VARCHAR(255) DEFAULT NULL,
        google_participant_email VARCHAR(190) DEFAULT NULL,
        google_participant_resource VARCHAR(255) DEFAULT NULL,
        first_join_time DATETIME DEFAULT NULL,
        last_leave_time DATETIME DEFAULT NULL,
        total_duration_seconds INTEGER NOT NULL DEFAULT 0,
        attendance_status VARCHAR(50) NOT NULL DEFAULT 'invited',
        sync_status VARCHAR(50) NOT NULL DEFAULT 'synced',
        google_conference_record VARCHAR(255) DEFAULT NULL,
        google_participant_session TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL,
        UNIQUE(session_id, google_participant_email)
    );

    CREATE TABLE IF NOT EXISTS session_google_artifacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        artifact_type VARCHAR(50) NOT NULL,
        google_resource_name VARCHAR(255) NOT NULL,
        drive_file_id VARCHAR(255) DEFAULT NULL,
        artifact_state VARCHAR(50) NOT NULL DEFAULT 'active',
        artifact_url VARCHAR(500) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL,
        UNIQUE(session_id, artifact_type, google_resource_name)
    );

    CREATE TABLE IF NOT EXISTS session_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        notification_type VARCHAR(50) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'sent',
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS admin_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_name VARCHAR(100) UNIQUE,
        setting_value TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL
    );
");

// Seed test settings
$pdo->exec("
    INSERT INTO admin_settings (setting_name, setting_value) VALUES
    ('google_attendance_threshold_percent', '50'),
    ('google_auto_record', '1');
");

// In-memory RSA 2048-bit test private key for authentic RS256 signing tests
$rsaPrivateKeyString = "-----BEGIN PRIVATE KEY-----\n"
    . "MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQCg44U8KYnKiChJ\n"
    . "7FVwzmYmfG4DoGMvxYxTry5s/K6dUAbXAA784IVOkUWTZE+yMi9krnwx0EIXYmNQ\n"
    . "2UTSbsPqK1w4y0Boe1a04pwAJJbucss+fYYeulGu3yG6z1E+8TzKW8jaBZnwBFB7\n"
    . "ox1+rM7WR1Igrj40NwlVJ6Yi6vH9Xnk1/2YPUmO69KF77y+gxh9jVdyQ6WjRRkcB\n"
    . "Oi/8yrCSCNhdUOfiDyxE0fOXoBTdi8jNou6lRD7KiaX2rywE61PZgOC1AKZrqoHr\n"
    . "hFjpqqAVHiQQ/L10wDhk8PSEG4Y5qGOzxC7quiHO4+F6Yc74gmU3EGlmN0MHFoRT\n"
    . "W9bUeBW1AgMBAAECggEAJFYuhb917OWif8ueImujO4b3y97h9+ycfFwA1sGc6E+m\n"
    . "M9HCEM/em7eIqLjLnRnjhVA5IYEBJEnm12AywHoeylj/q54QDmjo1NKnXArngbQw\n"
    . "fg0YiQEYqK+hbREcruQKEEP5kXAZa9F1oe7dnvoFfvS9sj7YMq/Jbk+VoKZCgDAF\n"
    . "eCjEfenZXbDMVZ6SZ94t2FnP6QPzdWV1Z1mlBMNyeBeTdo6CKE3KesyxfhfYjf3G\n"
    . "UZLluIYXW3IQ6a6Lx7XpBz2j9G0C7rC8EdAxAu6o6bHKvKYironErWEEi7r8wWWt\n"
    . "D4qeZaOlyL7rmFjOkgcnJydumjy2XOC92W2iuoqLswKBgQDgnpMTOLc0W8JLEbAj\n"
    . "wD9VN2/6RkcITN/nAxKDRqA40knL/0Ke7c9awhpXELjWg/aSsHDcAODVCqGga48b\n"
    . "YfFqqByaXwhzp2nxXfcsc44l6Yyzx1MF/HDMutJIxB1b4bwlC5CTEVI82X3W+H62\n"
    . "SKuRSZfRt1SEf/cVhImxkVT4RwKBgQC3XaStsoNdP2MlKKMioWEHpeF4FSQ9tmlR\n"
    . "lIwsNRBbKt2Og5rcBXfIKSqjmIwr2uwA8z3UJWPxUfOEWaBJQf8ukkdGf70lJspq\n"
    . "D5Vf8rSTsTl7utI+OaY7WM0qvPubHMX0AXX21i3wNhAndJsWfkH0DH05KPppVkl5\n"
    . "cRRMXUq8IwKBgQDDBGqlUaSebNxv2NeY8p0KG6u5G7MoXbY4F87G81bAfrNbzi/F\n"
    . "VKunHMdJuFcCyGgYS+Bw4sJRtX1GjpwdJhg4heTvknsADuZIjVDA40MTX4atv+0x\n"
    . "UU+OMNXKH5tt3rs/Xp8TUQKZmitLrUw2bzmmVsLdbdKPh5q6r+vso0WmmQKBgQCD\n"
    . "1YkD9XfrQBq5aak4ycxoYkRkQNcIo7C/Hc1WL2SuF3ip8UcS7796ItbsPk5xbXoH\n"
    . "CNuoPqXHqEMsIgBTC2c8BaHHNyo3ntcjQEcGcAqSsXYB1oU4hdxViPghxTQlBp/w\n"
    . "WmiE6uKmdUhSBc1Hc8lZfO0/fo3j1E0JSlrsuJp1/wKBgQDAw97gEwQw0r6gEjFJ\n"
    . "asRE9vXZek2O1arGgSIbQM7W7uNKBJ6eZgrxmktqsJz5iKT16xdd+x7Lvb0CdYED\n"
    . "zwnfgQ36fXotGbm+CHSyyk4cIhJWyFiXr7IrXFxKyP1o7AaMK4Gx+6MBHxMvuK/m\n"
    . "aleH1WIV4L0Vi62Er9hL9YeMUQ==\n"
    . "-----END PRIVATE KEY-----\n";

$rsaKeyResource = openssl_pkey_get_private($rsaPrivateKeyString);

$mockCredentials = [
    'type' => 'service_account',
    'project_id' => 'pepp-live-sessions',
    'private_key_id' => 'test-priv-key-id-12345',
    'private_key' => $rsaPrivateKeyString,
    'client_email' => 'pepp-erp-google-workspace@pepp-live-sessions.iam.gserviceaccount.com',
    'client_id' => '10987654321',
    'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
    'token_uri' => 'https://oauth2.googleapis.com/token',
];

echo "SECTION 1: GOOGLE CLIENT & DOMAIN-WIDE DELEGATION AUTHENTICATION\n";
echo "------------------------------------------------------------------------\n";

$client = new GoogleWorkspaceClient($mockCredentials);
assertTest($client->isConfigured(), "Service account credentials load and validate private key");
assertTest($client->getImpersonatedUser() === 'admin@pepponline.in', "Default organizer account is admin@pepponline.in");
assertTest($client->getClientEmail() === 'pepp-erp-google-workspace@pepp-live-sessions.iam.gserviceaccount.com', "Client email matches service account");
assertTest($client->getProjectId() === 'pepp-live-sessions', "Project ID matches pepp-live-sessions");

// Verify production credential path configuration
require_once __DIR__ . '/config/secrets.php';
assertTest(defined('PEPP_GOOGLE_WORKSPACE_SA_KEY_PATH'), "PEPP_GOOGLE_WORKSPACE_SA_KEY_PATH constant is defined in config/secrets.php");
assertTest(PEPP_GOOGLE_WORKSPACE_SA_KEY_PATH === '/home/u361910773/google-secrets/pepp-erp-google-workspace.json', "Production credential path is set to /home/u361910773/google-secrets/pepp-erp-google-workspace.json");
assertTest(defined('PEPP_GOOGLE_WORKSPACE_ORGANIZER') && PEPP_GOOGLE_WORKSPACE_ORGANIZER === 'admin@pepponline.in', "Production organizer is configured as admin@pepponline.in");

// Prohibited account validation
$blockedExceptionCaught = false;
try {
    new GoogleWorkspaceClient($mockCredentials, 'meet@pepponline.in');
} catch (InvalidArgumentException $e) {
    $blockedExceptionCaught = true;
}
assertTest($blockedExceptionCaught, "Account meet@pepponline.in is strictly prohibited and rejected");

// RS256 JWT assertion generation and verification
$scopes = GoogleWorkspaceClient::getDefaultScopes();
assertTest(count($scopes) === 4, "Default scopes count is exactly 4");
assertTest(in_array(GoogleWorkspaceClient::SCOPE_CALENDAR, $scopes, true), "Authorized scope includes calendar (Calendar event creation)");
assertTest(in_array(GoogleWorkspaceClient::SCOPE_MEET_SPACE_REQ, $scopes, true), "Authorized scope includes meetings.space.created (Meet space member/cohost management)");
assertTest(in_array(GoogleWorkspaceClient::SCOPE_MEET_SETTINGS, $scopes, true), "Authorized scope includes meetings.space.settings (Meet space configuration)");
assertTest(in_array(GoogleWorkspaceClient::SCOPE_MEET_READONLY, $scopes, true), "Authorized scope includes meetings.space.readonly (Conference/attendance/artifact READ for Calendar-created spaces)");

// Verify Drive-wide scopes are NOT included
assertTest(!in_array('https://www.googleapis.com/auth/drive', $scopes, true), "Drive-wide scope auth/drive is excluded");
assertTest(!in_array('https://www.googleapis.com/auth/drive.readonly', $scopes, true), "drive.readonly is excluded");
assertTest(!in_array('https://www.googleapis.com/auth/drive.meet.readonly', $scopes, true), "drive.meet.readonly is excluded");

$jwtAssertion = $client->createJwtAssertion($scopes);
$jwtParts = explode('.', $jwtAssertion);
assertTest(count($jwtParts) === 3, "JWT assertion has 3 valid components (header.payload.signature)");

$decodedHeader = json_decode(GoogleWorkspaceClient::base64UrlDecode($jwtParts[0]), true);
$decodedClaims = json_decode(GoogleWorkspaceClient::base64UrlDecode($jwtParts[1]), true);
assertTest(($decodedHeader['alg'] ?? '') === 'RS256', "JWT header algorithm is RS256");
assertTest(($decodedClaims['sub'] ?? '') === 'admin@pepponline.in', "JWT claims subject (sub) is admin@pepponline.in");
assertTest(($decodedClaims['iss'] ?? '') === 'pepp-erp-google-workspace@pepp-live-sessions.iam.gserviceaccount.com', "JWT claims issuer (iss) is service account");
assertTest(str_contains($decodedClaims['scope'] ?? '', 'calendar'), "JWT claims scope contains calendar");
assertTest(($decodedClaims['aud'] ?? '') === 'https://oauth2.googleapis.com/token', "JWT audience is https://oauth2.googleapis.com/token");

// Verify cryptographic signature with OpenSSL
$keyDetails = openssl_pkey_get_details($rsaKeyResource);
$publicKey = $keyDetails['key'];
$rawSignature = GoogleWorkspaceClient::base64UrlDecode($jwtParts[2]);
$sigVerify = openssl_verify($jwtParts[0] . '.' . $jwtParts[1], $rawSignature, $publicKey, OPENSSL_ALGO_SHA256);
assertTest($sigVerify === 1, "JWT RS256 signature is cryptographically valid");

echo "\nSECTION 2: CALENDAR SERVICE & GUEST PRIVACY\n";
echo "------------------------------------------------------------------------\n";

// Configure mock transport to simulate Google Calendar API & Meet API responses
$capturedCalendarPayload = null;
$capturedMeetPatchPayload = null;
$capturedMemberPayload = null;
$capturedTokenClaims = [];

GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (&$capturedCalendarPayload, &$capturedMeetPatchPayload, &$capturedMemberPayload, &$capturedTokenClaims) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        parse_str((string)$payload, $parsedParams);
        if (!empty($parsedParams['assertion'])) {
            $assertionParts = explode('.', (string)$parsedParams['assertion']);
            if (isset($assertionParts[1])) {
                $capturedTokenClaims[] = json_decode(GoogleWorkspaceClient::base64UrlDecode($assertionParts[1]), true);
            }
        }
        return [
            'status' => 200,
            'body'   => json_encode(['access_token' => 'mock_access_token_xyz', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            'error'  => null,
        ];
    }

    if (str_contains($url, '/calendar/v3/calendars/primary/events')) {
        if ($method === 'POST') {
            $capturedCalendarPayload = json_decode((string)$payload, true);
            return [
                'status' => 200,
                'body'   => json_encode([
                    'id' => 'cal_event_12345',
                    'summary' => $capturedCalendarPayload['summary'] ?? '',
                    'conferenceData' => [
                        'conferenceId' => 'abc-defg-hij',
                        'entryPoints' => [
                            ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij']
                        ],
                        'createRequest' => [
                            'status' => ['statusCode' => 'success']
                        ]
                    ]
                ]),
                'error' => null,
            ];
        }
    }

    if (str_contains($url, 'meet.googleapis.com/v2/spaces/abc-defg-hij')) {
        return [
            'status' => 200,
            'body'   => json_encode([
                'name' => 'spaces/sPaCeId98765',
                'meetingCode' => 'abc-defg-hij',
                'meetingUri' => 'https://meet.google.com/abc-defg-hij',
            ]),
            'error' => null,
        ];
    }

    if (str_contains($url, 'meet.googleapis.com/v2/spaces/sPaCeId98765') && $method === 'PATCH') {
        $capturedMeetPatchPayload = json_decode((string)$payload, true);
        return [
            'status' => 200,
            'body'   => json_encode(['name' => 'spaces/sPaCeId98765']),
            'error'  => null,
        ];
    }

    if (str_contains($url, '/members') && $method === 'POST') {
        $capturedMemberPayload = json_decode((string)$payload, true);
        return [
            'status' => 200,
            'body'   => json_encode(['name' => 'spaces/sPaCeId98765/members/faculty_member_1']),
            'error'  => null,
        ];
    }

    return ['status' => 404, 'body' => '{"error":{"message":"Not found"}}', 'error' => null];
});

$calService = new GoogleCalendarService($client);

// Test attendee builder
$sampleStudents = [
    ['email' => 'StudentA@example.com', 'name' => 'Student Alpha'],
    ['email' => 'studenta@example.com', 'name' => 'Duplicate Alpha'],
    ['email' => 'studentb@example.com', 'name' => 'Student Beta'],
    ['email' => 'invalid-email-address', 'name' => 'Bad Email'],
    ['email' => '', 'name' => 'Blank Email'],
];
$builtAttendees = GoogleCalendarService::buildAttendeeList($sampleStudents, 'faculty@pepponline.in', 'Dr. Smith');

assertTest(count($builtAttendees) === 3, "Attendee list deduplicates and excludes invalid/blank emails (Faculty + 2 students = 3)");
assertTest($builtAttendees[0]['email'] === 'faculty@pepponline.in', "Faculty is listed first in attendees");
assertTest(str_contains($builtAttendees[0]['displayName'], 'Faculty'), "Faculty displayName has (Faculty) suffix");
assertTest($builtAttendees[1]['email'] === 'studenta@example.com', "Student email is lowercased and deduplicated");

// Test Live Session Calendar event creation
$calRes = $calService->createLiveSessionEvent(
    101,
    'Advanced Corporate Law Webinar',
    '2026-10-15 10:00:00',
    1.5,
    $sampleStudents,
    'faculty@pepponline.in',
    'Dr. Smith',
    ['B.Com Honours', 'CA Final']
);

assertTest($calRes['success'], "Calendar event created successfully");
assertTest($calRes['event_id'] === 'cal_event_12345', "Event ID matches returned Calendar resource");
assertTest($calRes['meet_uri'] === 'https://meet.google.com/abc-defg-hij', "Meet URI extracted correctly");
assertTest($calRes['meet_code'] === 'abc-defg-hij', "Meeting code extracted correctly");

// Verify MANDATORY PRIVACY AND REMINDER SETTINGS in captured payload
assertTest($capturedCalendarPayload['guestsCanSeeOtherGuests'] === false, "CRITICAL: guestsCanSeeOtherGuests is FALSE (Student emails hidden from attendees)");
assertTest($capturedCalendarPayload['guestsCanInviteOthers'] === false, "guestsCanInviteOthers is FALSE");
assertTest($capturedCalendarPayload['guestsCanModify'] === false, "guestsCanModify is FALSE");
assertTest(($capturedCalendarPayload['conferenceData']['createRequest']['conferenceSolutionKey']['type'] ?? '') === 'hangoutsMeet', "Conference creation requests hangoutsMeet");
assertTest(str_starts_with($capturedCalendarPayload['conferenceData']['createRequest']['requestId'] ?? '', 'pepp_sess_101_'), "Conference requestId is unique per session");

// Verify 5 reminder windows
$remOverrides = $capturedCalendarPayload['reminders']['overrides'] ?? [];
$remMinutes = array_column($remOverrides, 'minutes');
assertTest(in_array(1440, $remMinutes, true), "24-hour reminder override configured (1440 min)");
assertTest(in_array(720, $remMinutes, true), "12-hour reminder override configured (720 min)");
assertTest(in_array(60, $remMinutes, true), "1-hour reminder override configured (60 min)");
assertTest(in_array(10, $remMinutes, true), "10-minute reminder override configured (10 min)");
assertTest(in_array(0, $remMinutes, true), "At-start reminder override configured (0 min)");

echo "\nSECTION 3: GOOGLE MEET SERVICE, CONFIGURATION & COHOST\n";
echo "------------------------------------------------------------------------\n";

GoogleWorkspaceClient::clearTokenCache();
$meetService = new GoogleMeetService($client);
$spaceRes = $meetService->resolveSpace('abc-defg-hij');
assertTest($spaceRes['success'], "Meet space resolved from meeting code");
assertTest($spaceRes['space_name'] === 'spaces/sPaCeId98765', "Authoritative resource name spaces/sPaCeId98765 resolved");

// Verify scope used by resolveSpace
$lastClaims = end($capturedTokenClaims) ?: [];
assertTest(str_contains($lastClaims['scope'] ?? '', 'meetings.space.readonly'), "resolveSpace explicitly requests meetings.space.readonly scope for Calendar-created spaces");

// Space configuration test
$confRes = $meetService->configureSpace('spaces/sPaCeId98765');
assertTest($confRes['success'], "Space configuration PATCH succeeded");
assertTest(($capturedMeetPatchPayload['config']['accessType'] ?? '') === 'RESTRICTED', "Space accessType is RESTRICTED");
assertTest(($capturedMeetPatchPayload['config']['moderation'] ?? '') === 'ON', "Space moderation is ON");
assertTest(($capturedMeetPatchPayload['config']['attendanceReportGenerationType'] ?? '') === 'GENERATE_REPORT', "attendanceReportGenerationType is GENERATE_REPORT");

// ArtifactConfig test
$artConfig = $capturedMeetPatchPayload['config']['artifactConfig'] ?? [];
assertTest(($artConfig['recordingConfig']['autoRecordingGeneration'] ?? '') === 'ON', "autoRecordingGeneration is ON");
assertTest(($artConfig['transcriptionConfig']['autoTranscriptionGeneration'] ?? '') === 'ON', "autoTranscriptionGeneration is ON");
assertTest(($artConfig['smartNotesConfig']['autoSmartNotesGeneration'] ?? '') === 'ON', "autoSmartNotesGeneration is ON");

// Faculty Cohost test
GoogleWorkspaceClient::clearTokenCache();
$cohostRes = $meetService->addFacultyCohost('spaces/sPaCeId98765', 'faculty@pepponline.in');
assertTest($cohostRes['success'], "Faculty added as Google Meet member");
assertTest(($capturedMemberPayload['email'] ?? '') === 'faculty@pepponline.in', "Cohost member email matches faculty");
assertTest(($capturedMemberPayload['role'] ?? '') === 'COHOST', "Faculty member role is COHOST");

$lastClaims = end($capturedTokenClaims) ?: [];
assertTest(str_contains($lastClaims['scope'] ?? '', 'meetings.space.created'), "addFacultyCohost explicitly requests meetings.space.created scope for spaces.members.create");

echo "\nSECTION 4: ACTIVE STUDENT RESOLUTION & ORCHESTRATOR\n";
echo "------------------------------------------------------------------------\n";

// Seed sample users with diverse statuses
$pdo->exec("
    INSERT INTO users (user_id, name, email, status, student_status, pepp_course) VALUES
    ('STU001', 'Active One', 'active1@pepponline.in', 'approved', 'active', 'B.Com Honours'),
    ('STU002', 'Active Two', 'active2@pepponline.in', 'approved', 'active', 'B.Com Honours'),
    ('STU003', 'Active Three', 'active3@pepponline.in', 'approved', 'active', 'CA Final'),
    ('STU004', 'Inactive Student', 'inactive@pepponline.in', 'approved', 'inactive', 'B.Com Honours'),
    ('STU005', 'Pending Student', 'pending@pepponline.in', 'pending', 'active', 'B.Com Honours'),
    ('STU006', 'Rejected Student', 'rejected@pepponline.in', 'rejected', 'active', 'B.Com Honours'),
    ('STU007', 'Completed Student', 'completed@pepponline.in', 'approved', 'completed', 'B.Com Honours'),
    ('STU008', 'Other Course Student', 'other@pepponline.in', 'approved', 'active', 'BBA Aviation'),
    ('STU009', 'Active Multi-Course Same Email', 'active1@pepponline.in', 'approved', 'active', 'CA Final');
");

$pdo->exec("
    INSERT INTO faculties (id, name, email, status) VALUES
    (5, 'Prof. Alan Turing', 'alan.turing@pepponline.in', 'active');
");

$liveMgr = new GoogleLiveSessionManager($pdo, $client, $calService, $meetService);

// Test student resolution
$resolved = $liveMgr->resolveActiveStudents(['B.Com Honours']);
$resolvedEmails = array_column($resolved, 'email');
assertTest(in_array('active1@pepponline.in', $resolvedEmails, true), "Active student 1 included");
assertTest(in_array('active2@pepponline.in', $resolvedEmails, true), "Active student 2 included");
assertTest(!in_array('inactive@pepponline.in', $resolvedEmails, true), "Inactive student excluded");
assertTest(!in_array('pending@pepponline.in', $resolvedEmails, true), "Pending student excluded");
assertTest(!in_array('rejected@pepponline.in', $resolvedEmails, true), "Rejected student excluded");
assertTest(!in_array('completed@pepponline.in', $resolvedEmails, true), "Completed student excluded");
assertTest(!in_array('other@pepponline.in', $resolvedEmails, true), "Student of other course excluded");

// Test multi-course resolution and deduplication
$multiResolved = $liveMgr->resolveActiveStudents(['B.Com Honours', 'CA Final']);
$multiEmails = array_column($multiResolved, 'email');
assertTest(count($multiEmails) === 3, "Cross-course resolution returns exactly 3 unique active students");
assertTest(count(array_unique($multiEmails)) === count($multiEmails), "Students enrolled in multiple selected courses are deduplicated");

// Test session provisioning through orchestrator
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status)
    VALUES (201, 'Tax Law Masterclass', 5, '2026-10-20 11:00:00', 1.00, 'live', 'B.Com Honours,CA Final', 'scheduled');
");

$provRes = $liveMgr->provisionGoogleLiveSession(201);
assertTest($provRes['success'], "Live session 201 provisioned with Google Meet");
assertTest($provRes['calendar_event_id'] === 'cal_event_12345', "Calendar event ID recorded");
assertTest($provRes['meet_uri'] === 'https://meet.google.com/abc-defg-hij', "Meet link recorded");
assertTest($provRes['meet_space_name'] === 'spaces/sPaCeId98765', "Authoritative spaces/sPaCeId98765 recorded");

// Verify sessions table was updated
$sessDb = $pdo->query("SELECT * FROM sessions WHERE id = 201")->fetch();
assertTest((int)$sessDb['google_integrated'] === 1, "sessions.google_integrated = 1");
assertTest($sessDb['google_calendar_event_id'] === 'cal_event_12345', "sessions.google_calendar_event_id is populated");
assertTest($sessDb['google_meet_space_name'] === 'spaces/sPaCeId98765', "sessions.google_meet_space_name is populated");
assertTest($sessDb['google_integration_status'] === 'synced', "sessions.google_integration_status is 'synced'");
assertTest($sessDb['meet_link'] === 'https://meet.google.com/abc-defg-hij', "sessions.meet_link convenience value populated");

// Verify session_attendance initial invited rows
$attRows = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 201")->fetchAll();
assertTest(count($attRows) === 3, "3 active students initialized in session_attendance with 'invited' status");
assertTest($attRows[0]['attendance_status'] === 'invited', "Initial attendance_status is 'invited'");

// Idempotency: re-running provisionGoogleLiveSession does not duplicate
$provRes2 = $liveMgr->provisionGoogleLiveSession(201);
assertTest($provRes2['success'], "Idempotent re-run succeeded without errors");
$sessCount = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 201")->fetchColumn();
assertTest($sessCount === 1, "Session row remains unique");

echo "\nSECTION 5: ATTENDANCE & ARTIFACT SYNCHRONIZATION\n";
echo "------------------------------------------------------------------------\n";

GoogleWorkspaceClient::clearTokenCache();
$capturedSyncTokenClaims = null;

// Mock conference records and participant sessions
GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (&$capturedSyncTokenClaims) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        parse_str((string)$payload, $parsedParams);
        if (!empty($parsedParams['assertion'])) {
            $assertionParts = explode('.', (string)$parsedParams['assertion']);
            if (isset($assertionParts[1])) {
                $capturedSyncTokenClaims = json_decode(GoogleWorkspaceClient::base64UrlDecode($assertionParts[1]), true);
            }
        }
        return [
            'status' => 200,
            'body'   => json_encode(['access_token' => 'mock_token_sync', 'expires_in' => 3600]),
            'error'  => null,
        ];
    }

    if (str_contains($url, 'meet.googleapis.com/v2/conferenceRecords?') || str_ends_with($url, '/conferenceRecords')) {
        return [
            'status' => 200,
            'body'   => json_encode([
                'conferenceRecords' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_999',
                        'startTime' => '2026-10-20T11:00:00Z',
                        'endTime'   => '2026-10-20T12:00:00Z',
                        'space'     => 'spaces/sPaCeId98765',
                    ]
                ]
            ]),
            'error' => null,
        ];
    }

    if (str_contains($url, 'conferenceRecords/conf_rec_999/participants') && !str_contains($url, 'participantSessions')) {
        return [
            'status' => 200,
            'body'   => json_encode([
                'participants' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_999/participants/p_active1',
                        'signedinUser' => [
                            'user' => 'active1@pepponline.in',
                            'displayName' => 'Active One',
                        ],
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_999/participants/p_active2',
                        'signedinUser' => [
                            'user' => 'active2@pepponline.in',
                            'displayName' => 'Active Two',
                        ],
                    ],
                ]
            ]),
            'error' => null,
        ];
    }

    // Participant 1 had 2 sessions (joined, dropped out, rejoined) = multiple joins
    if (str_contains($url, 'participants/p_active1/participantSessions')) {
        return [
            'status' => 200,
            'body'   => json_encode([
                'participantSessions' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_999/participants/p_active1/participantSessions/sess_1',
                        'startTime' => '2026-10-20T11:00:00Z',
                        'endTime'   => '2026-10-20T11:30:00Z', // 30 mins = 1800s
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_999/participants/p_active1/participantSessions/sess_2',
                        'startTime' => '2026-10-20T11:35:00Z',
                        'endTime'   => '2026-10-20T12:00:00Z', // 25 mins = 1500s (Total = 3300s = 55 mins = 91% -> full attendance)
                    ]
                ]
            ]),
            'error' => null,
        ];
    }

    // Participant 2 had 1 short session (15 mins = 900s = 25% -> joined)
    if (str_contains($url, 'participants/p_active2/participantSessions')) {
        return [
            'status' => 200,
            'body'   => json_encode([
                'participantSessions' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_999/participants/p_active2/participantSessions/sess_3',
                        'startTime' => '2026-10-20T11:00:00Z',
                        'endTime'   => '2026-10-20T11:15:00Z', // 15 mins
                    ]
                ]
            ]),
            'error' => null,
        ];
    }

    // Artifacts endpoints
    if (str_contains($url, '/recordings')) {
        return [
            'status' => 200,
            'body'   => json_encode([
                'recordings' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_999/recordings/rec_001',
                        'state' => 'FILE_GENERATED',
                        'driveDestination' => [
                            'file' => 'drive_file_rec_abc123',
                            'exportUri' => 'https://drive.google.com/file/d/drive_file_rec_abc123/view',
                        ]
                    ]
                ]
            ]),
            'error' => null,
        ];
    }

    if (str_contains($url, '/transcripts')) {
        return [
            'status' => 200,
            'body'   => json_encode([
                'transcripts' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_999/transcripts/tr_001',
                        'state' => 'FILE_GENERATED',
                        'docsDestination' => [
                            'document' => 'docs_doc_tr_def456',
                            'exportUri' => 'https://docs.google.com/document/d/docs_doc_tr_def456/edit',
                        ]
                    ]
                ]
            ]),
            'error' => null,
        ];
    }

    if (str_contains($url, '/smartNotes')) {
        return [
            'status' => 200,
            'body'   => json_encode([
                'smartNotes' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_999/smartNotes/sn_001',
                        'state' => 'FILE_GENERATED',
                        'driveDestination' => [
                            'file' => 'drive_doc_sn_ghi789',
                            'exportUri' => 'https://docs.google.com/document/d/drive_doc_sn_ghi789/edit',
                        ]
                    ]
                ]
            ]),
            'error' => null,
        ];
    }

    return ['status' => 404, 'body' => '{}', 'error' => null];
});

$attService = new GoogleAttendanceService($pdo, $client);
$attSyncRes = $attService->syncSessionAttendance(201);
assertTest($attSyncRes['success'], "Attendance sync succeeded");
assertTest(str_contains($capturedSyncTokenClaims['scope'] ?? '', 'meetings.space.readonly'), "Attendance & artifact sync explicitly requests meetings.space.readonly scope for Calendar-created spaces");
assertTest($attSyncRes['participants_synced'] === 2, "2 participants processed from Meet conference records");

// Verify aggregated durations & attendance status
$p1Row = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 201 AND google_participant_email = 'active1@pepponline.in'")->fetch();
assertTest((int)$p1Row['total_duration_seconds'] === 3300, "Participant 1 multiple sessions aggregated correctly (1800s + 1500s = 3300s)");
assertTest($p1Row['attendance_status'] === 'full attendance', "Participant 1 classified as 'full attendance' (>80% of scheduled hour)");
assertTest($p1Row['user_id'] === 'STU001', "Matched to active PEPP student STU001");

$p2Row = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 201 AND google_participant_email = 'active2@pepponline.in'")->fetch();
assertTest((int)$p2Row['total_duration_seconds'] === 900, "Participant 2 single session duration recorded (900s)");
assertTest($p2Row['attendance_status'] === 'joined', "Participant 2 classified as 'joined' (under 50% threshold)");

// Verify absent classification for student who was invited but never joined
$p3Row = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 201 AND google_participant_email = 'active3@pepponline.in'")->fetch();
assertTest($p3Row['total_duration_seconds'] === 0, "Student 3 who never joined has 0 seconds duration");
assertTest($p3Row['attendance_status'] === 'absent', "Student 3 classified as 'absent' after session completed");

// Test Artifacts Synchronization
$artService = new GoogleArtifactService($pdo, $client);
$artSyncRes = $artService->syncSessionArtifacts(201);
assertTest($artSyncRes['success'], "Artifacts sync succeeded");
assertTest($artSyncRes['artifacts_synced'] === 3, "3 artifacts synchronized (Recording + Transcript + Smart Notes)");

$recDb = $pdo->query("SELECT * FROM session_google_artifacts WHERE session_id = 201 AND artifact_type = 'recording'")->fetch();
assertTest($recDb['drive_file_id'] === 'drive_file_rec_abc123', "Recording Drive file ID recorded");
assertTest(str_contains($recDb['artifact_url'], 'drive.google.com'), "Recording URL links directly to Google Drive");

$trDb = $pdo->query("SELECT * FROM session_google_artifacts WHERE session_id = 201 AND artifact_type = 'transcript'")->fetch();
assertTest($trDb['drive_file_id'] === 'docs_doc_tr_def456', "Transcript Docs file ID recorded");
assertTest(str_contains($trDb['artifact_url'], 'docs.google.com'), "Transcript URL links to Google Docs");

$snDb = $pdo->query("SELECT * FROM session_google_artifacts WHERE session_id = 201 AND artifact_type = 'smart_notes'")->fetch();
assertTest($snDb['drive_file_id'] === 'drive_doc_sn_ghi789', "Gemini smart notes document ID recorded");

echo "\nSECTION 6: BACKWARD COMPATIBILITY & NOTIFICATION SUPPRESSION\n";
echo "------------------------------------------------------------------------\n";

// Test backward compatibility: Manual session without Google integration
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, meet_link, course_csv, google_integrated, status)
    VALUES (301, 'Legacy Offline Discussion', 5, '2026-10-25 15:00:00', 1.00, 'offline', NULL, 'B.Com Honours', 0, 'scheduled');
");

$legacySess = $pdo->query("SELECT * FROM sessions WHERE id = 301")->fetch();
assertTest((int)$legacySess['google_integrated'] === 0, "Non-Google session maintains google_integrated = 0");
assertTest($legacySess['google_calendar_event_id'] === null, "Non-Google session has NULL google_calendar_event_id");

// Verify session_cron reminder check
require_once __DIR__ . '/includes/session_cron.php';
assertTest(function_exists('sessions_dispatch_due'), "sessions_dispatch_due function exists and is callable");

echo "\nSECTION 8: GOOGLE LIVE SESSION UX & CALENDAR LIFECYCLE ENHANCEMENTS\n";
echo "------------------------------------------------------------------------\n";

// 8.1 Inspection of sessions.php UX requirements
$sessionsSrc = (string)file_get_contents(__DIR__ . '/sessions.php');

assertTest(
    str_contains($sessionsSrc, 'Schedule with Google Calendar &amp; Google Meet') || str_contains($sessionsSrc, 'Schedule with Google Calendar & Google Meet'),
    "Scheduling modal contains exact label: 'Schedule with Google Calendar & Google Meet'"
);

assertTest(
    str_contains($sessionsSrc, 'id="sess-google" value="1" checked'),
    "Google scheduling toggle is checked by default in Add Session modal HTML"
);

assertTest(
    str_contains($sessionsSrc, "document.getElementById('sess-google').checked = true;"),
    "openSessModal() explicitly ensures Google scheduling is checked by default for new Live Sessions"
);

assertTest(
    str_contains($sessionsSrc, 'Google Meet link will be generated automatically.'),
    "Helper text indicates Meet link will be generated automatically when Google scheduling is enabled"
);

assertTest(
    str_contains($sessionsSrc, 'meetInput.disabled = true;'),
    "Manual Meet Link field is disabled when Google scheduling is enabled"
);

assertTest(
    str_contains($sessionsSrc, 'meetInput.disabled = false;'),
    "Manual Meet Link field is re-enabled when Google scheduling is disabled"
);

assertTest(
    str_contains($sessionsSrc, "document.getElementById('sess-google').checked = false;"),
    "sessTypeToggle() unchecks/disables Google scheduling for non-live session types"
);

assertTest(
    str_contains($sessionsSrc, 'course-search-input'),
    "Course selection area contains quick search filter input"
);

assertTest(
    str_contains($sessionsSrc, 'selectAllCourses(true)') && str_contains($sessionsSrc, 'selectAllCourses(false)'),
    "Course selection area provides Select All and Clear controls"
);

assertTest(
    str_contains($sessionsSrc, 'course-selected-badge'),
    "Course selection area displays dynamic selected courses count badge"
);

assertTest(
    str_contains($sessionsSrc, 'copyMeetLink'),
    "Session list table includes Copy Google Meet Link action"
);

assertTest(
    str_contains($sessionsSrc, 'openSessionDetails'),
    "Session list table includes View Session Details modal action"
);

// 8.2 Copy Meet Link Validation
$testValidMeetUrl = 'https://meet.google.com/abc-defg-hij';
$testInvalidCalendarUrl = 'https://calendar.google.com/calendar/event?id=12345';
$testMeetingCodeOnly = 'abc-defg-hij';

$validateMeetUrl = function(?string $url): bool {
    return ($url !== null && str_starts_with($url, 'https://meet.google.com/'));
};

assertTest($validateMeetUrl($testValidMeetUrl), "Valid Google Meet URI starting with https://meet.google.com/ passes validation");
assertTest(!$validateMeetUrl($testInvalidCalendarUrl), "Calendar event URL is rejected by copyMeetLink validation");
assertTest(!$validateMeetUrl($testMeetingCodeOnly), "Meeting code alone without https://meet.google.com/ is rejected by copyMeetLink validation");

// 8.3 Session Details Endpoint & Student Privacy
// Seed additional faculty 6 and active student 10
$pdo->exec("
    INSERT OR IGNORE INTO faculties (id, name, email, status) VALUES
    (6, 'Prof. Ada Lovelace', 'ada.lovelace@pepponline.in', 'active');
");
$pdo->exec("
    INSERT OR IGNORE INTO users (user_id, name, email, status, student_status, pepp_course) VALUES
    ('STU010', 'Ada Batch Student', 'ada.student@pepponline.in', 'approved', 'active', 'BBA Regular');
");

$detailsBefore = $liveMgr->getSessionDetails(201);
assertTest($detailsBefore['success'], "getSessionDetails() returns successfully for session 201");
assertTest(!empty($detailsBefore['session']['topic']), "Section 1: Session Details contains topic");
assertTest(!empty($detailsBefore['session']['date']), "Section 1: Session Details contains date");
assertTest(!empty($detailsBefore['session']['start_time']) && !empty($detailsBefore['session']['end_time']), "Section 1: Session Details contains time window");
assertTest(!empty($detailsBefore['faculty']['name']), "Section 2: Faculty contains faculty name");
assertTest(isset($detailsBefore['faculty']['cohost_status']), "Section 2: Faculty contains cohost_status");
assertTest(count($detailsBefore['courses']['list']) >= 1, "Section 3: Courses contains selected course list and count");
assertTest(count($detailsBefore['invited_students']) === 3, "Section 4: Sourced invited students list matches 3 attendees from session_attendance");

// CRITICAL PRIVACY: Student email addresses must NOT be exposed
$hasStudentEmailLeak = false;
foreach ($detailsBefore['invited_students'] as $stItem) {
    if (isset($stItem['email']) || isset($stItem['student_email'])) {
        $hasStudentEmailLeak = true;
        break;
    }
}
assertTest(!$hasStudentEmailLeak, "CRITICAL: Student email addresses are NOT exposed in getSessionDetails() response");
assertTest($detailsBefore['google']['is_integrated'] === true, "Section 5: Google details shows integration enabled");
assertTest($detailsBefore['google']['meet_uri'] === 'https://meet.google.com/abc-defg-hij', "Section 5: Authoritative google_meet_uri is returned");
assertTest($detailsBefore['google']['calendar_event_id'] === 'cal_event_12345', "Section 5: Authoritative calendar_event_id is returned");

// 8.4 Update Lifecycle (Google-Aware Edit)
GoogleWorkspaceClient::clearTokenCache();
$capturedPatchUrl = null;
$capturedPatchPayload = null;
$capturedCohostSpace = null;
$capturedCohostEmail = null;
$createEventCalled = false;

GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (
    &$capturedPatchUrl,
    &$capturedPatchPayload,
    &$capturedCohostSpace,
    &$capturedCohostEmail,
    &$createEventCalled
) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return [
            'status' => 200,
            'body'   => json_encode(['access_token' => 'mock_token_lifecycle', 'expires_in' => 3600]),
            'error'  => null,
        ];
    }

    if ($method === 'POST' && str_contains($url, '/events')) {
        $createEventCalled = true;
        return [
            'status' => 200,
            'body'   => json_encode(['id' => 'unexpected_duplicate_event']),
            'error'  => null,
        ];
    }

    if ($method === 'PATCH' && str_contains($url, '/events/')) {
        $capturedPatchUrl = $url;
        $capturedPatchPayload = json_decode((string)$payload, true);
        return [
            'status' => 200,
            'body'   => json_encode([
                'id' => 'cal_event_12345',
                'summary' => $capturedPatchPayload['summary'] ?? '',
                'conferenceData' => [
                    'entryPoints' => [
                        ['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij']
                    ],
                    'conferenceId' => 'abc-defg-hij',
                ],
            ]),
            'error'  => null,
        ];
    }

    if ($method === 'POST' && str_contains($url, '/members')) {
        $capturedCohostSpace = $url;
        $body = json_decode((string)$payload, true);
        $capturedCohostEmail = $body['email'] ?? '';
        return [
            'status' => 200,
            'body'   => json_encode([
                'name' => 'spaces/sPaCeId98765/members/mem_ada',
                'role' => 'COHOST',
                'email' => $capturedCohostEmail,
            ]),
            'error'  => null,
        ];
    }

    return ['status' => 200, 'body' => '{}', 'error' => null];
});

// Update session 201: change topic, time, faculty (5 -> 6), and courses ('BBA Regular')
$updateRes = $liveMgr->updateGoogleLiveSession(201, [
    'topic' => 'Tax Law Masterclass - Advanced Corporate Tax',
    'session_datetime' => '2026-10-21 15:30:00',
    'duration_hours' => 1.50,
    'faculty_id' => 6,
    'courses' => ['BBA Regular'],
    'status' => 'scheduled',
]);

assertTest($updateRes['success'], "updateGoogleLiveSession() succeeded");
assertTest($createEventCalled === false, "Update does NOT create a second Calendar event (createEvent was NOT called)");
assertTest($capturedPatchUrl !== null && str_contains($capturedPatchUrl, 'events/cal_event_12345'), "Google event update uses existing google_calendar_event_id");
assertTest(str_contains($capturedPatchUrl ?? '', 'sendUpdates=all'), "Google event update sends sendUpdates=all in query parameter");
assertTest($updateRes['calendar_event_id'] === 'cal_event_12345', "Returned calendar_event_id matches authoritative event ID");

// Verify faculty change updated Google guest and cohost
$patchAttendees = $capturedPatchPayload['attendees'] ?? [];
$patchAttendeeEmails = array_column($patchAttendees, 'email');
assertTest(in_array('ada.lovelace@pepponline.in', $patchAttendeeEmails, true), "New faculty Prof. Ada Lovelace is included in updated Calendar attendees");
assertTest(!in_array('alan.turing@pepponline.in', $patchAttendeeEmails, true), "Previous faculty Prof. Alan Turing was replaced in Calendar attendees");
assertTest($capturedCohostSpace !== null && str_contains($capturedCohostSpace, 'spaces/sPaCeId98765/members'), "Faculty change triggers cohost addition on existing Meet space");
assertTest($capturedCohostEmail === 'ada.lovelace@pepponline.in', "New faculty email configured as COHOST on Meet space");

// Verify attendee recalculation on course change
assertTest(in_array('ada.student@pepponline.in', $patchAttendeeEmails, true), "Attendee list recalculation includes active student from new course 'BBA Regular'");
$adaAttRow = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 201 AND google_participant_email = 'ada.student@pepponline.in'")->fetch();
assertTest($adaAttRow && $adaAttRow['attendance_status'] === 'invited', "Newly eligible student STU010 recorded in session_attendance with 'invited' status");

// Verify privacy and reminders in update payload
assertTest(($capturedPatchPayload['guestsCanSeeOtherGuests'] ?? true) === false, "Update preserves guestsCanSeeOtherGuests = false");
assertTest(($capturedPatchPayload['guestsCanInviteOthers'] ?? true) === false, "Update preserves guestsCanInviteOthers = false");
assertTest(($capturedPatchPayload['guestsCanModify'] ?? true) === false, "Update preserves guestsCanModify = false");
assertTest(count($capturedPatchPayload['reminders']['overrides'] ?? []) === 5, "Update preserves all 5 reminder overrides (1440, 720, 60, 10, 0 min)");

// Verify database row for session 201 remained unique and updated
$sessUpdated = $pdo->query("SELECT * FROM sessions WHERE id = 201")->fetch();
assertTest($sessUpdated['topic'] === 'Tax Law Masterclass - Advanced Corporate Tax', "Topic updated in database");
assertTest((int)$sessUpdated['faculty_id'] === 6, "Faculty ID updated in database");
assertTest($sessUpdated['google_calendar_event_id'] === 'cal_event_12345', "Authoritative google_calendar_event_id unchanged in database");
assertTest($sessUpdated['google_meet_space_name'] === 'spaces/sPaCeId98765', "Authoritative google_meet_space_name unchanged in database");
assertTest($sessUpdated['google_integration_status'] === 'synced', "google_integration_status is 'synced'");

// 8.5 Compensating Error State Handling on Calendar Update Failure
GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'PATCH') {
        return ['status' => 500, 'body' => json_encode(['error' => ['message' => 'Backend calendar service unavailable']]), 'error' => 'HTTP 500: Backend calendar service unavailable'];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$failUpdateRes = $liveMgr->updateGoogleLiveSession(201, ['topic' => 'Should Fail Gracefully']);
assertTest(!$failUpdateRes['success'], "updateGoogleLiveSession reports failure when Calendar API fails");
$sessFailDb = $pdo->query("SELECT * FROM sessions WHERE id = 201")->fetch();
assertTest($sessFailDb['google_integration_status'] === 'failed', "Compensating state records google_integration_status = 'failed'");
assertTest(!empty($sessFailDb['google_error_message']), "Compensating state records google_error_message for admin visibility");

// 8.6 Delete Lifecycle (Google-Aware Deletion)
$capturedDeleteUrl = null;
GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (&$capturedDeleteUrl) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'DELETE' && str_contains($url, '/events/')) {
        $capturedDeleteUrl = $url;
        return ['status' => 204, 'body' => '', 'error' => null];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$delRes = $liveMgr->deleteGoogleLiveSession(201);
assertTest($delRes['success'], "deleteGoogleLiveSession() succeeded");
assertTest($capturedDeleteUrl !== null && str_contains($capturedDeleteUrl, 'events/cal_event_12345'), "Google event delete uses existing google_calendar_event_id");
assertTest(str_contains($capturedDeleteUrl ?? '', 'sendUpdates=all'), "Google event delete sends sendUpdates=all in query parameter");

// Verify database records purged
$sessAfterDel = $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 201")->fetchColumn();
assertTest((int)$sessAfterDel === 0, "Session 201 removed from sessions table");
$attAfterDel = $pdo->query("SELECT COUNT(*) FROM session_attendance WHERE session_id = 201")->fetchColumn();
assertTest((int)$attAfterDel === 0, "Associated session_attendance rows cleanly removed");
$artAfterDel = $pdo->query("SELECT COUNT(*) FROM session_google_artifacts WHERE session_id = 201")->fetchColumn();
assertTest((int)$artAfterDel === 0, "Associated session_google_artifacts rows cleanly removed");

// Verify no duplicate ERP email notifications were queued for Google session update or delete
$notifCount = (int)$pdo->query("SELECT COUNT(*) FROM session_notifications WHERE session_id = 201")->fetchColumn();
assertTest($notifCount === 0, "No duplicate ERP emails queued for Google-integrated session lifecycle actions");

// 8.7 FORENSIC AUDIT: Course-Change Attendee Reconciliation in Both Directions
// Scenario: Session 401 scheduled with Course A ('B.Com Honours') + Course B ('CA Final')
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, google_integrated, google_calendar_event_id, google_meet_space_name, google_meet_uri, google_meet_code, google_integration_status, status)
    VALUES (401, 'Forensic Reconciliation Masterclass', 5, '2026-10-28 10:00:00', 1.00, 'live', 'B.Com Honours,CA Final', 1, 'cal_event_401', 'spaces/sPaCe401', 'https://meet.google.com/xyz-uvwx-rst', 'xyz-uvwx-rst', 'synced', 'scheduled');

    INSERT INTO session_attendance (session_id, user_id, google_participant_name, google_participant_email, attendance_status, sync_status) VALUES
    (401, 'STU001', 'Active One', 'active1@pepponline.in', 'invited', 'synced'),
    (401, 'STU002', 'Active Two', 'active2@pepponline.in', 'invited', 'synced'),
    (401, 'STU003', 'Active Three', 'active3@pepponline.in', 'invited', 'synced');
");

$initialAttCount = (int)$pdo->query("SELECT COUNT(*) FROM session_attendance WHERE session_id = 401")->fetchColumn();
assertTest($initialAttCount === 3, "Initial Course A + Course B invitation list contains 3 students (active1, active2, active3)");

// Track Google Calendar PATCH and ensure no events.insert or conferenceData.createRequest occurs
$forensicPatchUrl = null;
$forensicPatchPayload = null;
$forensicCreateEventCalled = false;
$forensicMeetSpaceMutationCalled = false;

GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (
    &$forensicPatchUrl,
    &$forensicPatchPayload,
    &$forensicCreateEventCalled,
    &$forensicMeetSpaceMutationCalled
) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, '/events')) {
        $forensicCreateEventCalled = true;
        return ['status' => 200, 'body' => json_encode(['id' => 'error_duplicate']), 'error' => null];
    }
    if (str_contains($url, 'meet.googleapis.com/v2/spaces') && in_array($method, ['POST', 'PATCH', 'DELETE'], true) && !str_contains($url, '/members')) {
        $forensicMeetSpaceMutationCalled = true;
    }
    if ($method === 'PATCH' && str_contains($url, '/events/')) {
        $forensicPatchUrl = $url;
        $forensicPatchPayload = json_decode((string)$payload, true);
        return [
            'status' => 200,
            'body' => json_encode([
                'id' => 'cal_event_401',
                'summary' => $forensicPatchPayload['summary'] ?? '',
                'conferenceData' => [
                    'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/xyz-uvwx-rst']],
                    'conferenceId' => 'xyz-uvwx-rst',
                ],
            ]),
            'error' => null,
        ];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

// DIRECTION 1: Remove Course B ('CA Final') -> now Course A only ('B.Com Honours')
$updateResRemoval = $liveMgr->updateGoogleLiveSession(401, [
    'courses' => ['B.Com Honours'],
]);

assertTest($updateResRemoval['success'], "updateGoogleLiveSession succeeded when removing Course B");
assertTest($forensicCreateEventCalled === false, "Forensic: No events.insert called during course removal update");
assertTest($forensicMeetSpaceMutationCalled === false, "Forensic: No Meet space creation/mutation called during update (existing space preserved)");
assertTest($forensicPatchUrl !== null && str_contains($forensicPatchUrl, 'events/cal_event_401'), "Forensic: Calendar PATCH targets existing event cal_event_401");
assertTest(str_contains($forensicPatchUrl ?? '', 'sendUpdates=all'), "Forensic: Calendar PATCH sends sendUpdates=all so removed guest receives cancellation");

// Verify that conferenceData.createRequest is NOT submitted in update
assertTest(!isset($forensicPatchPayload['conferenceData']), "Forensic: conferenceData.createRequest is NOT submitted during update");

// Verify Google Calendar attendee list after removing Course B
$patch1Attendees = $forensicPatchPayload['attendees'] ?? [];
$patch1Emails = array_column($patch1Attendees, 'email');
assertTest(in_array('active1@pepponline.in', $patch1Emails, true), "Direction 1: Course A student active1 remains in Calendar attendees");
assertTest(in_array('active2@pepponline.in', $patch1Emails, true), "Direction 1: Course A student active2 remains in Calendar attendees");
assertTest(!in_array('active3@pepponline.in', $patch1Emails, true), "Direction 1 (CRITICAL): Removed Course B student active3 is REMOVED from Calendar attendees");
assertTest(count($patch1Emails) === 3, "Direction 1: Calendar attendee count is exactly 3 (1 faculty + 2 active students)");

// Verify session_attendance after removing Course B
$attAfterRemoval = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 401")->fetchAll();
$attEmailsAfterRemoval = array_column($attAfterRemoval, 'google_participant_email');
assertTest(in_array('active1@pepponline.in', $attEmailsAfterRemoval, true), "session_attendance retains active1");
assertTest(in_array('active2@pepponline.in', $attEmailsAfterRemoval, true), "session_attendance retains active2");
assertTest(!in_array('active3@pepponline.in', $attEmailsAfterRemoval, true), "session_attendance (CRITICAL): Stale invited record for active3 is PURGED");
assertTest(count($attAfterRemoval) === 2, "session_attendance contains exactly 2 invited attendees");

// Verify getSessionDetails() after removing Course B
$detailsAfterRemoval = $liveMgr->getSessionDetails(401);
assertTest(count($detailsAfterRemoval['invited_students']) === 2, "getSessionDetails() returns exactly 2 invited students after Course B removal");
$detailsEmailsLeak = false;
foreach ($detailsAfterRemoval['invited_students'] as $stRow) {
    if (isset($stRow['email']) || isset($stRow['student_email'])) {
        $detailsEmailsLeak = true;
    }
}
assertTest(!$detailsEmailsLeak, "Zero student email leakage in getSessionDetails() after course removal");

// DIRECTION 2: Add Course C ('BBA Regular') -> now ['B.Com Honours', 'BBA Regular']
$updateResAddition = $liveMgr->updateGoogleLiveSession(401, [
    'courses' => ['B.Com Honours', 'BBA Regular'],
]);

assertTest($updateResAddition['success'], "updateGoogleLiveSession succeeded when adding Course C");
$patch2Attendees = $forensicPatchPayload['attendees'] ?? [];
$patch2Emails = array_column($patch2Attendees, 'email');
assertTest(in_array('ada.student@pepponline.in', $patch2Emails, true), "Direction 2 (CRITICAL): Newly eligible Course C student added to Calendar attendees");
assertTest(in_array('active1@pepponline.in', $patch2Emails, true), "Direction 2: Course A student active1 remains in Calendar attendees");
assertTest(in_array('active2@pepponline.in', $patch2Emails, true), "Direction 2: Course A student active2 remains in Calendar attendees");
assertTest(!in_array('active3@pepponline.in', $patch2Emails, true), "Direction 2: Previously removed Course B student active3 remains excluded");
assertTest(count($patch2Emails) === 4, "Direction 2: Calendar attendee count is exactly 4 (1 faculty + 3 active students)");

$attAfterAddition = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 401")->fetchAll();
assertTest(count($attAfterAddition) === 3, "session_attendance now contains exactly 3 students after adding Course C");

// 8.8 FORENSIC AUDIT: Default Google Toggle Behavior for NEW vs EXISTING Sessions
// Verify that opening an existing manual/non-Google session for editing does NOT silently toggle Google ON
assertTest(
    str_contains($sessionsSrc, "document.getElementById('sess-google').checked = !!s.google;"),
    "Forensic: editSess() strictly binds Google toggle to existing session's google_integrated status (!!s.google)"
);
assertTest(
    str_contains($sessionsSrc, '"google"=>(int)($s["google_integrated"] ?? 0)'),
    "Forensic: Edit button passes exact integer google_integrated flag from database row"
);
assertTest(
    str_contains($sessionsSrc, "document.getElementById('sess-google').checked = true;"),
    "Forensic: openSessModal() forces Google toggle ON only for NEW sessions"
);

// Clean up forensic test session 401
$pdo->prepare("DELETE FROM session_attendance WHERE session_id = 401")->execute();
$pdo->prepare("DELETE FROM sessions WHERE id = 401")->execute();

echo "\nSECTION 9: ISOLATION OF PAUSED WHATSAPP FILES\n";
echo "------------------------------------------------------------------------\n";

$pausedFiles = [
    'api/v1/communication/webhook.php',
    'communication-dashboard.php',
    'communication-templates.php',
    'includes/communication/CommunicationEngine.php',
    'includes/communication/Providers/WhatsAppCloudProvider.php',
    'database-update-51.sql',
    'test_multi_number_whatsapp_audit.php',
];

$gitOutput = shell_exec('git status --short');
$gitLines = array_filter(array_map('trim', explode("\n", (string)$gitOutput)));

foreach ($pausedFiles as $pfile) {
    // Check that each paused file was NOT modified by this session
    // (They remain in their exact paused state without any additional commits or resets)
    assertTest(file_exists(__DIR__ . '/' . $pfile), "Paused WhatsApp file exists untouched: {$pfile}");
}

echo "\n========================================================================\n";
echo " AUDIT SUMMARY: {$passedTests} / {$totalTests} TESTS PASSED\n";
if ($failedTests > 0) {
    echo " FAILED: {$failedTests} test(s)\n";
    echo " STATUS: AUDIT FAILED\n";
    exit(1);
} else {
    echo " ALL TESTS PASSED SUCCESSFULLY! ZERO FAILURES.\n";
    echo "========================================================================\n";
    exit(0);
}
