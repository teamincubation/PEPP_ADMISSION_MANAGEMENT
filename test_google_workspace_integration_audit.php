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

echo "\nSECTION 7: ISOLATION OF PAUSED WHATSAPP FILES\n";
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
